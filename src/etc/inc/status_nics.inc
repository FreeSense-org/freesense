<?php
/*
 * status_nics.inc
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
 * All rights reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/*
 * Live state of the assigned interfaces as structured data, for
 * GET /api/v1/status/nics and /status/nics/{name} (the dashboard Interfaces
 * widgets): link state, speed and duplex, MAC and vendor, MTU, addresses,
 * gateways, VLAN parent, LAGG ports, adapter hardware and counters.
 *
 * One `ifconfig -a` run covers every interface; the list never runs the
 * per-interface pfctl or SFP reads that get_interface_info() does. The
 * detail call adds them for one interface. Adapter hardware (driver, chip,
 * PCI ids, queues) and MAC vendors change rarely and are cached in
 * STATUS_NICS_HW_CACHE. Reads only; owns no config.xml section.
 *
 * Command output is parsed by pure functions (status_nics_parse_*) that
 * tests/NicStatusSmokeTest.php covers.
 */

require_once('config.inc');
require_once('util.inc');
require_once('interfaces.inc');
require_once('gwlb.inc');
require_once('nic_tuning.inc');

define('STATUS_NICS_IFCONFIG', '/sbin/ifconfig');
define('STATUS_NICS_HW_CACHE', '/var/run/fs_nics_hw.json');
define('STATUS_NICS_HW_TTL', 300);
define('STATUS_NICS_OUI_FILE', '/usr/local/share/nmap/nmap-mac-prefixes');

/* ------------------------------------------------------------ parsers */

/* pure: a hex netmask ("0xffffff00") or dotted one as a prefix length, or null */
function status_nics_mask_bits(string $mask): ?int {
	if (preg_match('/^0x([0-9a-f]{8})$/i', $mask, $m)) {
		$n = hexdec($m[1]);
	} elseif (filter_var($mask, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
		$n = ip2long($mask);
	} else {
		return null;
	}
	$bits = substr_count(decbin((int)$n), '1');
	return ($bits <= 32) ? $bits : null;
}

/* pure: the scope of an IPv6 address: link, ula, loopback or global */
function status_nics_v6_scope(string $address): string {
	$a = strtolower($address);
	if (strpos($a, 'fe80:') === 0) {
		return 'link';
	}
	if (preg_match('/^f[cd][0-9a-f]{2}:/', $a)) {
		return 'ula';
	}
	if ($a === '::1') {
		return 'loopback';
	}
	return 'global';
}

/*
 * pure: `ifconfig -a [-v] [-f inet:cidr,inet6:cidr]` output → array keyed by
 * device: flags[], mtu, mac, hwaddr, inet[{address, prefix}],
 * inet6[{address, prefix, scope, flags[]}], media (text after "media: "),
 * status, vlan{tag, proto, pcp, parent}, lagg{proto, hash, ports[{name,
 * flags[]}]}, groups[], ssid, channel, sfp{plugged, vendor, temperature,
 * voltage, rx_power, tx_bias}. Keys a device does not have are absent.
 */
function status_nics_parse_ifconfig(string $text): array {
	$out = [];
	$dev = null;
	foreach (preg_split('/\r?\n/', $text) as $line) {
		if (preg_match('/^([A-Za-z0-9_.\-]+): flags=[0-9a-f]+<([^>]*)>(?: metric \d+)? mtu (\d+)/', $line, $m)) {
			$dev = $m[1];
			$out[$dev] = ['flags' => ($m[2] === '') ? [] : explode(',', $m[2]), 'mtu' => (int)$m[3], 'inet' => [], 'inet6' => []];
			continue;
		}
		if (($dev === null) || ($line === '') || (($line[0] !== "\t") && ($line[0] !== ' '))) {
			continue;
		}
		$l = trim($line);
		$e = &$out[$dev];
		if (preg_match('/^ether ([0-9a-f:]{17})/i', $l, $m)) {
			$e['mac'] = strtolower($m[1]);
		} elseif (preg_match('/^hwaddr ([0-9a-f:]{17})/i', $l, $m)) {
			$e['hwaddr'] = strtolower($m[1]);
		} elseif (preg_match('/^inet (\d{1,3}(?:\.\d{1,3}){3})(?:\/(\d{1,2}))?(?: --> \S+)?(?: netmask (\S+))?/', $l, $m)) {
			$prefix = isset($m[2]) && ($m[2] !== '') ? (int)$m[2] : (isset($m[3]) ? status_nics_mask_bits($m[3]) : null);
			$e['inet'][] = ['address' => $m[1], 'prefix' => $prefix];
		} elseif (preg_match('/^inet6 ([0-9a-f:.]+)(?:%[^\s\/]+)?(?:\/(\d{1,3}))?(?: --> \S+)?(?: prefixlen (\d{1,3}))?(.*)$/i', $l, $m)) {
			$prefix = ($m[2] !== '') ? (int)$m[2] : (($m[3] ?? '') !== '' ? (int)$m[3] : null);
			$flags = [];
			foreach (['deprecated', 'tentative', 'detached', 'duplicated', 'autoconf', 'temporary'] as $flag) {
				if (preg_match('/\b' . $flag . '\b/', $m[4] ?? '')) {
					$flags[] = $flag;
				}
			}
			$e['inet6'][] = ['address' => strtolower($m[1]), 'prefix' => $prefix, 'scope' => status_nics_v6_scope($m[1]), 'flags' => $flags];
		} elseif (preg_match('/^media: (.*)$/', $l, $m)) {
			$e['media'] = trim($m[1]);
		} elseif (preg_match('/^status: (.*)$/', $l, $m)) {
			$e['status'] = trim($m[1]);
		} elseif (preg_match('/^vlan: (\d+)(?: vlanproto: (\S+))?(?: vlanpcp: (\d+))?.*?parent interface: (\S+)/', $l, $m)) {
			$e['vlan'] = ['tag' => (int)$m[1], 'proto' => (string)$m[2], 'pcp' => ($m[3] !== '') ? (int)$m[3] : null, 'parent' => $m[4]];
		} elseif (preg_match('/^laggproto (\S+)(?: lagghash (\S+))?/', $l, $m)) {
			$e['lagg'] = ['proto' => $m[1], 'hash' => $m[2] ?? '', 'ports' => $e['lagg']['ports'] ?? []];
		} elseif (preg_match('/^laggport: (\S+)(?: flags=[0-9a-f]+<([^>]*)>)?/', $l, $m)) {
			$e['lagg']['ports'][] = ['name' => $m[1], 'flags' => empty($m[2]) ? [] : explode(',', $m[2])];
		} elseif (preg_match('/^groups: (.*)$/', $l, $m)) {
			$e['groups'] = preg_split('/\s+/', trim($m[1]));
		} elseif (preg_match('/^ssid ("(?:[^"]*)"|\S+)(?: channel (\d+))?/', $l, $m)) {
			$e['ssid'] = trim($m[1], '"');
			if (!empty($m[2])) {
				$e['channel'] = (int)$m[2];
			}
		} elseif (preg_match('/^plugged: (.*)$/', $l, $m)) {
			$e['sfp']['plugged'] = trim($m[1]);
		} elseif (preg_match('/^vendor: (.*)$/', $l, $m)) {
			$e['sfp']['vendor'] = trim($m[1]);
		} elseif (preg_match('/^module temperature: (\S+)\s*C? voltage: (\S+)/', $l, $m)) {
			$e['sfp']['temperature'] = is_numeric($m[1]) ? (float)$m[1] : null;
			$e['sfp']['voltage'] = is_numeric($m[2]) ? (float)$m[2] : null;
		} elseif (preg_match('/RX power: .*?\((-?[0-9.]+|-inf) dBm\).*?TX bias: ([0-9.]+) mA/', $l, $m)) {
			$e['sfp']['rx_power'][] = is_numeric($m[1]) ? (float)$m[1] : null;
			$e['sfp']['tx_bias'][] = (float)$m[2];
		}
		unset($e);
	}
	return $out;
}

/* pure: an Ethernet subtype ("1000baseT", "2500Base-T", "10Gbase-SR", "25GBase-CR") as Mbit/s, or null */
function status_nics_subtype_speed(string $subtype): ?int {
	if (!preg_match('/^(\d+(?:\.\d+)?)(G)?base/i', $subtype, $m)) {
		return null;
	}
	return (int)round((float)$m[1] * (empty($m[2]) ? 1 : 1000));
}

/*
 * pure: the text after "media: " → kind (ethernet, wireless, other),
 * selected (what is configured: autoselect or a fixed subtype), active (what
 * the link negotiated, empty without carrier), autoneg, speed_mbps, duplex
 * (full, half or null) and flowcontrol{rx, tx} or null.
 *   "Ethernet autoselect (1000baseT <full-duplex>)"
 *   "Ethernet 10Gbase-T <full-duplex>"
 *   "Ethernet autoselect (2500Base-T <full-duplex,rxpause,txpause>)"
 *   "IEEE 802.11 Wireless Ethernet autoselect mode 11ng <hostap>"
 */
function status_nics_parse_media(string $media): array {
	$out = ['kind' => 'other', 'selected' => '', 'active' => '', 'autoneg' => false, 'speed_mbps' => null, 'duplex' => null, 'flowcontrol' => null];
	$media = trim($media);
	if ($media === '') {
		return $out;
	}
	if (preg_match('/^IEEE 802\.11 Wireless Ethernet\s*(.*)$/', $media, $m)) {
		$out['kind'] = 'wireless';
		$rest = $m[1];
	} elseif (preg_match('/^Ethernet\s*(.*)$/', $media, $m)) {
		$out['kind'] = 'ethernet';
		$rest = $m[1];
	} else {
		$rest = $media;
	}
	if (preg_match('/^(.*?)\s*\((.*)\)\s*$/', $rest, $m)) {
		$selected = trim($m[1]);
		$active = trim($m[2]);
	} else {
		$selected = trim($rest);
		$active = (stripos($selected, 'autoselect') === 0) ? '' : $selected;
	}
	$out['selected'] = $selected;
	$out['active'] = $active;
	$out['autoneg'] = (stripos($selected, 'autoselect') === 0);
	if ($out['kind'] === 'wireless') {
		return $out;
	}
	$subtype = (string)strtok($active, " <");
	$out['speed_mbps'] = status_nics_subtype_speed($subtype);
	$opts = preg_match('/<([^>]*)>/', $active, $m) ? array_map('trim', explode(',', strtolower($m[1]))) : [];
	if (in_array('full-duplex', $opts, true)) {
		$out['duplex'] = 'full';
	} elseif (in_array('half-duplex', $opts, true)) {
		$out['duplex'] = 'half';
	}
	if (in_array('flowcontrol', $opts, true) || in_array('rxpause', $opts, true) || in_array('txpause', $opts, true)) {
		$both = in_array('flowcontrol', $opts, true);
		$out['flowcontrol'] = ['rx' => $both || in_array('rxpause', $opts, true), 'tx' => $both || in_array('txpause', $opts, true)];
	}
	return $out;
}

/* pure: up, down (no carrier) or admin_down from a parsed ifconfig block */
function status_nics_link_state(array $block): string {
	$flags = $block['flags'] ?? [];
	if (!in_array('UP', $flags, true)) {
		return 'admin_down';
	}
	$status = strtolower((string)($block['status'] ?? ''));
	if ($status === '') {
		return in_array('RUNNING', $flags, true) ? 'up' : 'down';
	}
	return in_array($status, ['active', 'associated', 'running'], true) ? 'up' : 'down';
}

/* pure: the interface kind from its device name and parsed ifconfig block */
function status_nics_kind(string $device, array $block): string {
	if (isset($block['vlan'])) {
		return 'vlan';
	}
	if (isset($block['lagg'])) {
		return 'lagg';
	}
	if (isset($block['ssid']) || (strpos((string)($block['media'] ?? ''), 'IEEE 802.11') === 0)) {
		return 'wireless';
	}
	if (preg_match('/^bridge\d+$/', $device)) {
		return 'bridge';
	}
	if (preg_match('/^(pppoe|pptp|l2tp|ppp)\d+$/', $device)) {
		return 'ppp';
	}
	if (preg_match('/^(ovpn[cs]|tun_wg|wg|gif|gre|ipsec|tun|tap|vxlan)\d+$/', $device)) {
		return 'tunnel';
	}
	if (preg_match('/^lo\d+$/', $device)) {
		return 'loopback';
	}
	return isset($block['mac']) ? 'ethernet' : 'other';
}

/* pure: how an interface gets its addresses, from its config.xml entry */
function status_nics_addressing(array $ifcfg): array {
	$v4 = (string)($ifcfg['ipaddr'] ?? '');
	$v6 = (string)($ifcfg['ipaddrv6'] ?? '');
	$known4 = ['dhcp', 'pppoe', 'pptp', 'l2tp', 'ppp'];
	$known6 = ['dhcp6', 'slaac', 'track6', '6rd', '6to4'];
	return [
		'ipv4' => in_array($v4, $known4, true) ? $v4 : (($v4 === '' || $v4 === 'none') ? 'none' : 'static'),
		'ipv6' => in_array($v6, $known6, true) ? $v6 : (($v6 === '' || $v6 === 'none') ? 'none' : 'static'),
	];
}

/* pure: the six-hex-digit OUI of a MAC address ("00:0c:29:..." → "000C29"), or null */
function status_nics_oui(string $mac): ?string {
	$hex = strtoupper(str_replace([':', '-', '.'], '', $mac));
	return preg_match('/^[0-9A-F]{12}$/', $hex) ? substr($hex, 0, 6) : null;
}

/* pure: nmap-mac-prefixes lines → vendors for the wanted OUIs (array OUI => vendor) */
function status_nics_parse_oui(iterable $lines, array $wanted): array {
	$want = array_fill_keys($wanted, true);
	$out = [];
	foreach ($lines as $line) {
		if ((strlen($line) < 8) || ($line[6] !== ' ')) {
			continue;
		}
		$oui = strtoupper(substr($line, 0, 6));
		if (isset($want[$oui]) && !isset($out[$oui])) {
			$out[$oui] = trim(substr($line, 7));
			if (count($out) === count($want)) {
				break;
			}
		}
	}
	return $out;
}

/* ------------------------------------------------------------- system */

/* Parsed `ifconfig -a`; $verbose adds SFP module data (slow on some adapters), $device limits it to one. */
function status_nics_ifconfig(bool $verbose = false, string $device = ''): array {
	$cmd = STATUS_NICS_IFCONFIG . ($verbose ? ' -v' : '') . ' -f inet:cidr,inet6:cidr ' .
	    (($device === '') ? '-a' : escapeshellarg($device)) . ' 2>/dev/null';
	return status_nics_parse_ifconfig((string)shell_exec($cmd));
}

function status_nics_cache_read(): array {
	$data = @json_decode((string)@file_get_contents(STATUS_NICS_HW_CACHE), true);
	return is_array($data) ? $data : [];
}

function status_nics_cache_write(array $data): void {
	@file_put_contents(STATUS_NICS_HW_CACHE, json_encode($data), LOCK_EX);
	@chmod(STATUS_NICS_HW_CACHE, 0600);
}

/*
 * Adapter hardware for physical devices (array device => {driver, desc, pci,
 * location, msix, rx_queues, tx_queues}) and vendors for MAC addresses
 * (array mac => vendor), from the cache while it is fresh.
 */
function status_nics_hardware(array $devices, array $macs): array {
	$cache = status_nics_cache_read();
	$fresh = isset($cache['t']) && ((time() - (int)$cache['t']) < STATUS_NICS_HW_TTL);
	$hw = $fresh ? (array)($cache['hw'] ?? []) : [];
	$vendors = $fresh ? (array)($cache['vendors'] ?? []) : [];
	$changed = !$fresh;

	foreach ($devices as $device) {
		if (array_key_exists($device, $hw) || (nic_tuning_split($device) === null)) {
			continue;
		}
		$d = nic_tuning_adapter($device);
		$hw[$device] = empty($d['found']) ? null : [
			'driver' => $d['driver'], 'desc' => $d['desc'], 'pci' => $d['pci'], 'location' => $d['location'],
			'msix' => $d['msix'], 'rx_queues' => $d['rxqs'], 'tx_queues' => $d['txqs'],
		];
		$changed = true;
	}

	$missing = [];
	foreach ($macs as $mac) {
		$oui = status_nics_oui($mac);
		if (($oui !== null) && !array_key_exists($oui, $vendors)) {
			$missing[] = $oui;
		}
	}
	if (!empty($missing) && is_readable(STATUS_NICS_OUI_FILE)) {
		$found = status_nics_parse_oui(new SplFileObject(STATUS_NICS_OUI_FILE), array_unique($missing));
		foreach (array_unique($missing) as $oui) {
			$vendors[$oui] = $found[$oui] ?? '';
		}
		$changed = true;
	}

	if ($changed) {
		status_nics_cache_write(['t' => $fresh ? (int)$cache['t'] : time(), 'hw' => $hw, 'vendors' => $vendors]);
	}
	return ['hw' => $hw, 'vendors' => $vendors];
}

/* One interface's entry from its parsed block(s), stats, hardware and config. */
function status_nics_entry(string $if, string $descr, array $parsed, array $hwinfo): array {
	$ifcfg = (array)config_get_path("interfaces/{$if}", []);
	$real = (string)get_real_interface($if);
	$port = (string)($ifcfg['if'] ?? $real);
	$block = $parsed[$real] ?? [];
	/* PPPoE and other PPP links: the link (media, MAC, hardware) is the port underneath. */
	$link = (!isset($block['mac']) && isset($parsed[$port])) ? $parsed[$port] : $block;
	$linkdev = (!isset($block['mac']) && isset($parsed[$port])) ? $port : $real;

	$media = status_nics_parse_media((string)($link['media'] ?? ''));
	$stats = ($real !== '') ? FreeSense_get_interface_stats($real) : null;
	$stats = is_array($stats) ? $stats : [];
	if (($media['speed_mbps'] === null) && !empty($stats['baudrate']) && ($media['kind'] !== 'wireless')) {
		$media['speed_mbps'] = (int)round((float)$stats['baudrate'] / 1000000);
	}
	$mac = (string)($link['mac'] ?? '');
	$oui = status_nics_oui($mac);

	$lagg = null;
	if (isset($block['lagg'])) {
		$lagg = ['proto' => $block['lagg']['proto'], 'hash' => $block['lagg']['hash'], 'ports' => array_map(function ($p) {
			return ['name' => $p['name'], 'active' => in_array('ACTIVE', $p['flags'], true), 'flags' => $p['flags']];
		}, $block['lagg']['ports'] ?? [])];
	}

	$counter = function (string $key) use ($stats) {
		return isset($stats[$key]) ? (int)$stats[$key] : null;
	};
	return [
		'name' => $if,
		'description' => $descr,
		'device' => $real,
		'port' => $port,
		'kind' => status_nics_kind($real, $block),
		'enabled' => isset($ifcfg['enable']),
		'link' => [
			'state' => empty($block) ? 'down' : status_nics_link_state($block),
			'status' => (string)($link['status'] ?? ''),
			'media' => (string)($link['media'] ?? ''),
		] + $media,
		'mac' => $mac,
		'vendor' => ($oui !== null) ? (string)($hwinfo['vendors'][$oui] ?? '') : '',
		'mtu' => isset($block['mtu']) ? (int)$block['mtu'] : null,
		'ipv4' => $block['inet'] ?? [],
		'ipv6' => array_values(array_filter($block['inet6'] ?? [], function ($a) {
			return !in_array('detached', $a['flags'], true);
		})),
		'addressing' => status_nics_addressing($ifcfg),
		'gateway' => ['ipv4' => (string)get_interface_gateway($if), 'ipv6' => (string)get_interface_gateway_v6($if)],
		'vlan' => $block['vlan'] ?? null,
		'lagg' => $lagg,
		'groups' => $block['groups'] ?? [],
		'hardware' => $hwinfo['hw'][$linkdev] ?? null,
		'counters' => [
			'in_bytes' => $counter('inbytes'), 'out_bytes' => $counter('outbytes'),
			'in_packets' => $counter('inpkts'), 'out_packets' => $counter('outpkts'),
			'in_errors' => $counter('inerrs'), 'out_errors' => $counter('outerrs'),
			'in_drops' => $counter('iqdrops'), 'out_drops' => $counter('oqdrops'),
			'collisions' => $counter('collisions'),
		],
	];
}

/* Every assigned interface (or the named ones), in assignment order. */
function status_nics_list(array $only = []): array {
	$all = get_configured_interface_with_descr();
	if (!empty($only)) {
		$all = array_intersect_key($all, array_flip($only));
	}
	$parsed = status_nics_ifconfig();
	$devices = [];
	$macs = [];
	foreach (array_keys($all) as $if) {
		$real = (string)get_real_interface($if);
		$port = (string)config_get_path("interfaces/{$if}/if", $real);
		foreach (array_unique([$real, $port]) as $dev) {
			if (isset($parsed[$dev])) {
				$devices[] = $dev;
				if (!empty($parsed[$dev]['mac'])) {
					$macs[] = $parsed[$dev]['mac'];
				}
			}
		}
	}
	$hwinfo = status_nics_hardware(array_unique($devices), array_unique($macs));
	$out = [];
	foreach ($all as $if => $descr) {
		$out[] = status_nics_entry($if, (string)$descr, $parsed, $hwinfo);
	}
	return $out;
}

/*
 * One interface with everything the list leaves out: SFP module data,
 * wireless station data, pf pass/block counters, adapter drop counters,
 * PPP uptime and cellular data. Null when the interface is not assigned.
 */
function status_nics_detail(string $if): ?array {
	$all = get_configured_interface_with_descr();
	if (!array_key_exists($if, $all)) {
		return null;
	}
	$entry = status_nics_list([$if])[0];
	$linkdev = (string)($entry['port'] ?: $entry['device']);
	$verbose = ($linkdev !== '') ? status_nics_ifconfig(true, $linkdev) : [];
	$info = get_interface_info($if);
	$info = is_array($info) ? $info : [];

	$pf = [];
	foreach (['inbytespass', 'outbytespass', 'inpktspass', 'outpktspass', 'inbytesblock', 'outbytesblock', 'inpktsblock', 'outpktsblock'] as $k) {
		$pf[$k] = isset($info[$k]) ? (int)$info[$k] : null;
	}
	$nic = (nic_tuning_split($linkdev) !== null) ? nic_tuning_adapter($linkdev) : [];
	$entry['sfp'] = $verbose[$linkdev]['sfp'] ?? null;
	$entry['wireless'] = ($entry['kind'] === 'wireless') ? [
		'ssid' => (string)($verbose[$linkdev]['ssid'] ?? ($info['ssid'] ?? '')),
		'channel' => $verbose[$linkdev]['channel'] ?? (isset($info['channel']) ? (int)$info['channel'] : null),
		'bssid' => (string)($info['bssid'] ?? ''), 'rate' => (string)($info['rate'] ?? ''), 'rssi' => (string)($info['rssi'] ?? ''),
	] : null;
	$entry['pf'] = [
		'in_bytes_pass' => $pf['inbytespass'], 'out_bytes_pass' => $pf['outbytespass'],
		'in_packets_pass' => $pf['inpktspass'], 'out_packets_pass' => $pf['outpktspass'],
		'in_bytes_block' => $pf['inbytesblock'], 'out_bytes_block' => $pf['outbytesblock'],
		'in_packets_block' => $pf['inpktsblock'], 'out_packets_block' => $pf['outpktsblock'],
	];
	$entry['adapter_counters'] = $nic['counters'] ?? [];
	$entry['ppp'] = isset($info['ppp_uptime']) || isset($info['pppoelink']) ? [
		'link' => (string)($info['pppoelink'] ?? ($info['pptplink'] ?? ($info['l2tplink'] ?? ($info['ppplink'] ?? '')))),
		'uptime' => (string)($info['ppp_uptime'] ?? ''),
	] : null;
	$cell = [];
	foreach ($info as $k => $v) {
		if (strpos($k, 'cell_') === 0) {
			$cell[substr($k, 5)] = (string)$v;
		}
	}
	$entry['cellular'] = empty($cell) ? null : $cell;
	$entry['dhcp_running'] = isset($info['dhcplink']) ? ($info['dhcplink'] === 'up') : null;
	return $entry;
}
