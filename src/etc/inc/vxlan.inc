<?php
/*
 * vxlan.inc
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
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
 * VXLAN (vxlan(4)) helpers that do not touch the running system or the
 * configuration, so they can be tested outside the appliance. The system-side
 * code lives in interfaces.inc (interface_vxlan_configure()) and filter.inc.
 *
 * Config: vxlans/vxlan[] with
 *   vxlanif     kernel name, e.g. vxlan0
 *   if          parent interface (friendly name or _vip reference)
 *   ipproto     inet | inet6
 *   mode        unicast | multicast
 *   vni         0-16777215
 *   remote-addr unicast peer VTEP
 *   mcastgroup  multicast group ("group" is an XML list tag)
 *   localport, remoteport  UDP ports (default 4789)
 *   ttl         1-255 (default 64)
 *   nolearn     set to disable MAC learning (learning is on by default)
 *   mac         locally administered MAC, fixed at creation
 *   allowrule   set to add a pass rule for the outer UDP traffic
 *   descr
 */

define('VXLAN_DEFAULT_PORT', 4789);
define('VXLAN_DEFAULT_TTL', 64);
define('VXLAN_VNI_MAX', 16777215);
/* outer IPv4 20 + UDP 8 + VXLAN 8 + inner Ethernet 14 */
define('VXLAN_OVERHEAD_INET', 50);
/* outer IPv6 40 + UDP 8 + VXLAN 8 + inner Ethernet 14 */
define('VXLAN_OVERHEAD_INET6', 70);

function vxlan_text($text) {
	return function_exists('gettext') ? gettext($text) : $text;
}

function vxlan_is_ipv4($addr) {
	return is_string($addr) && filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
}

function vxlan_is_ipv6($addr) {
	return is_string($addr) && filter_var($addr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false;
}

function vxlan_is_family($addr, $ipproto) {
	return ($ipproto == 'inet6') ? vxlan_is_ipv6($addr) : vxlan_is_ipv4($addr);
}

function vxlan_is_multicast($addr) {
	if (vxlan_is_ipv4($addr)) {
		$n = ip2long($addr);
		return $n >= ip2long('224.0.0.0') && $n <= ip2long('239.255.255.255');
	}
	if (vxlan_is_ipv6($addr)) {
		return ord(inet_pton($addr)[0]) == 0xff;
	}
	return false;
}

/*
 * A group usable for VXLAN must cross a router: IPv4 224.0.1.0 and above
 * (224.0.0.0/24 is link-local), IPv6 site scope or wider.
 */
function vxlan_is_valid_group($addr, $ipproto) {
	if ($ipproto == 'inet6') {
		if (!vxlan_is_ipv6($addr)) {
			return false;
		}
		$bin = inet_pton($addr);
		return ord($bin[0]) == 0xff && (ord($bin[1]) & 0x0f) >= 0x5;
	}
	if (!vxlan_is_ipv4($addr)) {
		return false;
	}
	$n = ip2long($addr);
	return $n >= ip2long('224.0.1.0') && $n <= ip2long('239.255.255.255');
}

function vxlan_is_numeric_in_range($value, $min, $max) {
	if (!is_numeric($value) || (string)(int)$value !== (string)$value) {
		return false;
	}
	return (int)$value >= $min && (int)$value <= $max;
}

function vxlan_localport($vxlan) {
	return !empty($vxlan['localport']) ? (int)$vxlan['localport'] : VXLAN_DEFAULT_PORT;
}

function vxlan_remoteport($vxlan) {
	return !empty($vxlan['remoteport']) ? (int)$vxlan['remoteport'] : VXLAN_DEFAULT_PORT;
}

function vxlan_ttl($vxlan) {
	return !empty($vxlan['ttl']) ? (int)$vxlan['ttl'] : VXLAN_DEFAULT_TTL;
}

function vxlan_is_valid_mac($mac) {
	return is_string($mac) && preg_match('/^([0-9a-f]{2}:){5}[0-9a-f]{2}$/i', $mac) === 1;
}

/* Random unicast, locally administered MAC address. */
function vxlan_generate_mac() {
	$bytes = random_bytes(6);
	$bytes[0] = chr((ord($bytes[0]) & 0xfc) | 0x02);
	return implode(':', str_split(bin2hex($bytes), 2));
}

/* Lowest vxlanN that is in neither $used (configured or existing names). */
function vxlan_next_ifname($used) {
	for ($i = 0; ; $i++) {
		if (!in_array("vxlan{$i}", $used, true)) {
			return "vxlan{$i}";
		}
	}
}

/*
 * Whether ifconfig(8) output shows the interface RUNNING. vxlan(4) ignores
 * socket and multicast errors when it is brought up, so this is the only
 * sign that the tunnel actually works.
 */
function vxlan_ifconfig_is_running($output) {
	return preg_match('/flags=[0-9a-f]+<([^>]*,)?RUNNING[,>]/', (string)$output) === 1;
}

/* A peer address that cannot be a remote VTEP. */
function vxlan_is_unusable_remote($addr, $localaddr) {
	if ($addr === $localaddr) {
		return true;
	}
	if (vxlan_is_ipv4($addr)) {
		$n = ip2long($addr);
		return ($n == 0) || ($n == ip2long('255.255.255.255')) ||
		    (($n & 0xff000000) == (127 << 24));
	}
	if (vxlan_is_ipv6($addr)) {
		$bin = inet_pton($addr);
		return ($bin === inet_pton('::')) || ($bin === inet_pton('::1')) ||
		    /* link-local needs a scope, which a tunnel cannot carry */
		    ((ord($bin[0]) == 0xfe) && ((ord($bin[1]) & 0xc0) == 0x80));
	}
	return false;
}

function vxlan_default_mtu($parentmtu, $ipproto) {
	$parentmtu = (int)$parentmtu;
	if ($parentmtu <= 0) {
		$parentmtu = 1500;
	}
	return $parentmtu - (($ipproto == 'inet6') ? VXLAN_OVERHEAD_INET6 : VXLAN_OVERHEAD_INET);
}

/*
 * ifconfig(8) arguments that configure an existing, down vxlan interface.
 * The caller escapes each element and brings the interface up afterwards.
 */
function vxlan_ifconfig_args($vxlan, $localaddr, $parentreal) {
	$args = ['vxlanid', (string)(int)$vxlan['vni'], 'vxlanlocal', $localaddr];
	if (($vxlan['mode'] ?? 'unicast') == 'multicast') {
		array_push($args, 'vxlangroup', $vxlan['mcastgroup'], 'vxlandev', $parentreal);
	} else {
		array_push($args, 'vxlanremote', $vxlan['remote-addr']);
	}
	if (vxlan_localport($vxlan) != VXLAN_DEFAULT_PORT) {
		array_push($args, 'vxlanlocalport', (string)vxlan_localport($vxlan));
	}
	if (vxlan_remoteport($vxlan) != VXLAN_DEFAULT_PORT) {
		array_push($args, 'vxlanremoteport', (string)vxlan_remoteport($vxlan));
	}
	if (vxlan_ttl($vxlan) != VXLAN_DEFAULT_TTL) {
		array_push($args, 'vxlanttl', (string)vxlan_ttl($vxlan));
	}
	if (isset($vxlan['nolearn'])) {
		$args[] = '-vxlanlearn';
	}
	if (vxlan_is_valid_mac($vxlan['mac'] ?? null)) {
		array_push($args, 'ether', strtolower($vxlan['mac']));
	}
	return $args;
}

/*
 * Validate a VXLAN entry. $localaddr is the parent's current address of the
 * selected family ('' when it has none). $others lists the other tunnels as
 * ['vxlanif', 'ipproto', 'mode', 'localaddr', 'localport', 'vni'].
 *
 * vxlan(4) binds a unicast tunnel to its local address and port, but every
 * multicast tunnel of a family to the wildcard address on its port, and it
 * accepts each VNI only once per socket. So two tunnels clash on (local
 * address, port, VNI) in unicast mode and on (family, port, VNI) in multicast
 * mode, and unicast and multicast tunnels cannot share a port in one family.
 * The kernel reports none of this, so it is checked here.
 * Returns a list of error messages.
 */
function vxlan_validate($vxlan, $localaddr, $others = []) {
	$errors = [];
	$ipproto = $vxlan['ipproto'] ?? '';
	$mode = $vxlan['mode'] ?? '';

	if (empty($vxlan['if'])) {
		$errors[] = vxlan_text('A parent interface must be selected.');
	}
	if (!in_array($ipproto, ['inet', 'inet6'], true)) {
		$errors[] = vxlan_text('The address family must be IPv4 or IPv6.');
	}
	if (!in_array($mode, ['unicast', 'multicast'], true)) {
		$errors[] = vxlan_text('The mode must be unicast or multicast.');
	}
	if (!vxlan_is_numeric_in_range($vxlan['vni'] ?? '', 0, VXLAN_VNI_MAX)) {
		$errors[] = sprintf(vxlan_text('The VNI must be a number between 0 and %d.'), VXLAN_VNI_MAX);
	}
	foreach (['localport' => vxlan_text('local port'), 'remoteport' => vxlan_text('remote port')] as $key => $label) {
		if (isset($vxlan[$key]) && $vxlan[$key] !== '' &&
		    !vxlan_is_numeric_in_range($vxlan[$key], 1, 65535)) {
			$errors[] = sprintf(vxlan_text('The %s must be between 1 and 65535.'), $label);
		}
	}
	if (isset($vxlan['ttl']) && $vxlan['ttl'] !== '' && !vxlan_is_numeric_in_range($vxlan['ttl'], 1, 255)) {
		$errors[] = vxlan_text('The TTL must be between 1 and 255.');
	}
	if (isset($vxlan['mac']) && $vxlan['mac'] !== '' && !vxlan_is_valid_mac($vxlan['mac'])) {
		$errors[] = vxlan_text('The MAC address is not valid.');
	}

	if ($mode == 'unicast') {
		$remote = $vxlan['remote-addr'] ?? '';
		if (!vxlan_is_family($remote, $ipproto)) {
			$errors[] = vxlan_text('The remote address must be an IP address of the selected address family.');
		} elseif (vxlan_is_multicast($remote)) {
			$errors[] = vxlan_text('The remote address must not be a multicast address. Use multicast mode instead.');
		} elseif (vxlan_is_unusable_remote($remote, $localaddr)) {
			$errors[] = vxlan_text('The remote address must be the address of another VTEP, not an unspecified, loopback, broadcast, link-local or local address.');
		}
	} elseif ($mode == 'multicast') {
		if (!vxlan_is_valid_group($vxlan['mcastgroup'] ?? '', $ipproto)) {
			$errors[] = ($ipproto == 'inet6') ?
			    vxlan_text('The multicast group must be an IPv6 multicast address with site scope or wider (ff05::/16, ff08::/16 or ff0e::/16).') :
			    vxlan_text('The multicast group must be between 224.0.1.0 and 239.255.255.255.');
		}
	}

	if (in_array($ipproto, ['inet', 'inet6'], true) && !empty($vxlan['if']) &&
	    !vxlan_is_family($localaddr, $ipproto)) {
		$errors[] = ($ipproto == 'inet6') ?
		    vxlan_text('The parent interface has no IPv6 address.') :
		    vxlan_text('The parent interface has no IPv4 address.');
	}

	if (empty($errors)) {
		foreach ($others as $other) {
			if (($other['vxlanif'] ?? '') !== '' && ($other['vxlanif'] ?? '') === ($vxlan['vxlanif'] ?? null)) {
				continue;
			}
			if (($other['ipproto'] ?? 'inet') !== $ipproto) {
				continue;
			}
			/* FreeBSD accepts zero-checksum UDP over IPv6 on a single port */
			if (($ipproto == 'inet6') && ((int)$other['localport'] !== vxlan_localport($vxlan))) {
				$errors[] = sprintf(vxlan_text('%1$s uses local port %2$d. All IPv6 VXLAN tunnels must use the same local port, because FreeBSD accepts zero-checksum IPv6 UDP on one port only.'),
				    $other['vxlanif'], (int)$other['localport']);
				break;
			}
			if ((int)$other['localport'] !== vxlan_localport($vxlan)) {
				continue;
			}
			$othermode = $other['mode'] ?? 'unicast';
			if ($othermode !== $mode) {
				$errors[] = sprintf(vxlan_text('%1$s is a %2$s tunnel on local port %3$d. Unicast and multicast tunnels of one address family need different local ports.'),
				    $other['vxlanif'], $othermode, vxlan_localport($vxlan));
				break;
			}
			if ((int)$other['vni'] !== (int)$vxlan['vni']) {
				continue;
			}
			if ($mode == 'multicast') {
				$errors[] = sprintf(vxlan_text('%1$s already uses VNI %2$d on multicast port %3$d. Multicast tunnels of one address family share one socket per port, so the VNI or the port must differ.'),
				    $other['vxlanif'], (int)$vxlan['vni'], vxlan_localport($vxlan));
				break;
			}
			if ($other['localaddr'] === $localaddr) {
				$errors[] = sprintf(vxlan_text('%1$s already uses VNI %2$d on local address %3$s port %4$d.'),
				    $other['vxlanif'], (int)$vxlan['vni'], $localaddr, vxlan_localport($vxlan));
				break;
			}
		}
	}

	return $errors;
}

/*
 * Bodies of the pass rules for the outer VXLAN traffic of one tunnel, to be
 * prefixed with "pass in <log> quick" and suffixed with a ridentifier and
 * label by the caller. $ifmacro is the pf macro of the parent (e.g. "$WAN").
 * Only the destination port is matched: FreeBSD sends from an ephemeral
 * source port range (vxlanportrange), not from the listening port.
 * Returns [['rule' => ..., 'descr' => ...], ...].
 */
function vxlan_filter_rules($vxlan, $ifmacro, $localaddr) {
	if (!isset($vxlan['allowrule'])) {
		return [];
	}
	$ipproto = (($vxlan['ipproto'] ?? '') == 'inet6') ? 'inet6' : 'inet';
	$name = $vxlan['vxlanif'] ?? 'vxlan';
	$port = vxlan_localport($vxlan);
	$rules = [];

	if (($vxlan['mode'] ?? 'unicast') == 'multicast') {
		if (!vxlan_is_valid_group($vxlan['mcastgroup'] ?? '', $ipproto)) {
			return [];
		}
		$rules[] = [
			'rule' => "on {$ifmacro} {$ipproto} proto udp from any to {$vxlan['mcastgroup']} port = {$port}",
			'descr' => "Allow VXLAN multicast {$name}",
		];
		if (vxlan_is_family($localaddr, $ipproto)) {
			/* Learned peers send unicast to the local address. */
			$rules[] = [
				'rule' => "on {$ifmacro} {$ipproto} proto udp from any to {$localaddr} port = {$port}",
				'descr' => "Allow VXLAN {$name}",
			];
		}
		if ($ipproto == 'inet6') {
			$rules[] = [
				'rule' => "on {$ifmacro} inet6 proto ipv6-icmp from fe80::/10 to ff00::/8 icmp6-type { 130, 131, 132, 143 } allow-opts",
				'descr' => "Allow MLD for VXLAN {$name}",
			];
		} else {
			$rules[] = [
				'rule' => "on {$ifmacro} inet proto igmp from any to 224.0.0.0/4 allow-opts",
				'descr' => "Allow IGMP for VXLAN {$name}",
			];
		}
		return $rules;
	}

	$remote = $vxlan['remote-addr'] ?? '';
	if (!vxlan_is_family($remote, $ipproto) || !vxlan_is_family($localaddr, $ipproto)) {
		return [];
	}
	$rules[] = [
		'rule' => "on {$ifmacro} {$ipproto} proto udp from {$remote} to {$localaddr} port = {$port}",
		'descr' => "Allow VXLAN {$name}",
	];
	return $rules;
}
