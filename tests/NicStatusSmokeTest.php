<?php
/*
 * Standalone CI regression test for the interface status library
 * (src/etc/inc/status_nics.inc) and its routes in
 * src/etc/inc/restapi/routes_status.inc; run with
 * `php tests/NicStatusSmokeTest.php`.
 *
 * The pure parsing functions are extracted and run on sample ifconfig output
 * from em, igc, ix, vtnet and vmx adapters, a VLAN, a LAGG, PPPoE, a port
 * without carrier, an SFP+ module and a wireless access point.
 */

$root = dirname(__DIR__);
$src = file_get_contents("{$root}/src/etc/inc/status_nics.inc");
$routes = file_get_contents("{$root}/src/etc/inc/restapi/routes_status.inc");
$api = file_get_contents("{$root}/src/usr/local/www/api/index.php");

function check($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

function fn_source($code, $name) {
	$start = strpos($code, "function {$name}(");
	check($start !== false, "function {$name}() exists");
	return substr($code, $start, strpos($code, "\n}\n", $start) - $start) . "\n}\n";
}

foreach (array('status_nics_mask_bits', 'status_nics_v6_scope', 'status_nics_parse_ifconfig', 'status_nics_subtype_speed',
    'status_nics_parse_media', 'status_nics_link_state', 'status_nics_kind', 'status_nics_addressing', 'status_nics_oui',
    'status_nics_parse_oui') as $fn) {
	$before = substr($src, 0, strpos($src, "function {$fn}("));
	check(strpos(substr($before, strrpos($before, '/*')), 'pure:') !== false, "{$fn}() is marked pure");
	eval(fn_source($src, $fn));
}

/* Media: speed, duplex, autonegotiation, flow control */
$m = status_nics_parse_media('Ethernet autoselect (1000baseT <full-duplex>)');
check($m['kind'] === 'ethernet' && $m['autoneg'] && $m['speed_mbps'] === 1000 && $m['duplex'] === 'full' && $m['flowcontrol'] === null &&
    $m['selected'] === 'autoselect' && $m['active'] === '1000baseT <full-duplex>', 'em/igb 1 Gbit/s full duplex, autonegotiated');
$m = status_nics_parse_media('Ethernet autoselect (2500Base-T <full-duplex,rxpause,txpause>)');
check($m['speed_mbps'] === 2500 && $m['duplex'] === 'full' && $m['flowcontrol'] === array('rx' => true, 'tx' => true), 'igc 2.5 Gbit/s with pause frames');
$m = status_nics_parse_media('Ethernet autoselect (10Gbase-SR <full-duplex,rxpause>)');
check($m['speed_mbps'] === 10000 && $m['flowcontrol'] === array('rx' => true, 'tx' => false), 'ix 10 Gbit/s SR, RX pause only');
$m = status_nics_parse_media('Ethernet 10Gbase-T <full-duplex>');
check(!$m['autoneg'] && $m['speed_mbps'] === 10000 && $m['selected'] === '10Gbase-T <full-duplex>', 'vtnet/vmx fixed 10Gbase-T');
$m = status_nics_parse_media('Ethernet 100baseTX <half-duplex>');
check($m['speed_mbps'] === 100 && $m['duplex'] === 'half' && !$m['autoneg'], 'forced 100 Mbit/s half duplex');
$m = status_nics_parse_media('Ethernet autoselect');
check($m['autoneg'] && $m['active'] === '' && $m['speed_mbps'] === null && $m['duplex'] === null, 'no carrier: autoselect, no speed');
$m = status_nics_parse_media('Ethernet autoselect (25GBase-CR <full-duplex,flowcontrol>)');
check($m['speed_mbps'] === 25000 && $m['flowcontrol'] === array('rx' => true, 'tx' => true), 'mlx5 25 Gbit/s with flow control');
$m = status_nics_parse_media('IEEE 802.11 Wireless Ethernet autoselect mode 11ng <hostap>');
check($m['kind'] === 'wireless' && $m['speed_mbps'] === null && $m['autoneg'], 'wireless media has no wired speed');
check(status_nics_parse_media('')['kind'] === 'other', 'empty media');
check(status_nics_subtype_speed('100GBase-SR4') === 100000 && status_nics_subtype_speed('10baseT/UTP') === 10 &&
    status_nics_subtype_speed('2.5GBase-T') === 2500 && status_nics_subtype_speed('none') === null, 'subtype speeds');

/* Netmasks and IPv6 scopes */
check(status_nics_mask_bits('0xffffff00') === 24 && status_nics_mask_bits('255.255.255.252') === 30 && status_nics_mask_bits('bogus') === null, 'netmask to prefix length');
check(status_nics_v6_scope('fe80::1') === 'link' && status_nics_v6_scope('fd12:3456::1') === 'ula' && status_nics_v6_scope('2001:db8::1') === 'global', 'IPv6 scopes');

/* ifconfig -a -f inet:cidr,inet6:cidr */
$parsed = status_nics_parse_ifconfig(<<<'TXT'
igc0: flags=1008843<UP,BROADCAST,RUNNING,SIMPLEX,MULTICAST,LOWER_UP> metric 0 mtu 1500
	description: WAN
	options=4e527bb<RXCSUM,TXCSUM,VLAN_MTU,VLAN_HWTAGGING,JUMBO_MTU,VLAN_HWCSUM,TSO4,TSO6,LRO,WOL_MAGIC,VLAN_HWFILTER,VLAN_HWTSO,RXCSUM_IPV6,TXCSUM_IPV6,HWSTATS,MEXTPG>
	ether 00:0c:29:aa:bb:01
	inet 203.0.113.10/24 broadcast 203.0.113.255
	inet6 fe80::20c:29ff:feaa:bb01%igc0/64 scopeid 0x1
	inet6 2001:db8:10::10/64
	inet6 2001:db8:10::99/64 deprecated autoconf pltime 0 vltime 3600
	inet6 2001:db8:10::77/64 detached
	media: Ethernet autoselect (2500Base-T <full-duplex>)
	status: active
	nd6 options=21<PERFORMNUD,AUTO_LINKLOCAL>
igc1: flags=1008843<UP,BROADCAST,RUNNING,SIMPLEX,MULTICAST,LOWER_UP> metric 0 mtu 9000
	options=4e527bb<RXCSUM,TXCSUM>
	ether 00:0c:29:aa:bb:02
	inet 192.168.1.1 netmask 0xffffff00 broadcast 192.168.1.255
	media: Ethernet autoselect (1000baseT <full-duplex>)
	status: active
igc2: flags=8802<BROADCAST,SIMPLEX,MULTICAST> metric 0 mtu 1500
	ether 00:0c:29:aa:bb:03
	media: Ethernet autoselect
	status: no carrier
igc3: flags=8843<UP,BROADCAST,RUNNING,SIMPLEX,MULTICAST> metric 0 mtu 1500
	ether 00:0c:29:aa:bb:04
	hwaddr 00:0c:29:aa:bb:04
	media: Ethernet autoselect
	status: no carrier
igc1.10: flags=1008843<UP,BROADCAST,RUNNING,SIMPLEX,MULTICAST,LOWER_UP> metric 0 mtu 1500
	ether 00:0c:29:aa:bb:02
	inet 10.10.0.1/24 broadcast 10.10.0.255
	groups: vlan
	vlan: 10 vlanproto: 802.1q vlanpcp: 0 parent interface: igc1
	media: Ethernet autoselect (1000baseT <full-duplex>)
	status: active
lagg0: flags=1008843<UP,BROADCAST,RUNNING,SIMPLEX,MULTICAST,LOWER_UP> metric 0 mtu 1500
	ether 3c:ec:ef:00:00:10
	inet 10.20.0.1/24 broadcast 10.20.0.255
	laggproto lacp lagghash l2,l3,l4
	laggport: ix0 flags=1c<ACTIVE,COLLECTING,DISTRIBUTING>
	laggport: ix1 flags=0<>
	groups: lagg
	media: Ethernet autoselect
	status: active
ix0: flags=1008843<UP,BROADCAST,RUNNING,SIMPLEX,MULTICAST,LOWER_UP> metric 0 mtu 1500
	ether 3c:ec:ef:00:00:10
	media: Ethernet autoselect (10Gbase-SR <full-duplex,rxpause,txpause>)
	status: active
	plugged: SFP/SFP+/SFP28 10G Base-SR (LC)
	vendor: FINISAR CORP. PN: FTLX8571D3BCL SN: AAA0001 DATE: 2019-04-01
	module temperature: 31.48 C voltage: 3.32 Volts
	RX: 0.52 mW (-2.83 dBm) TX: 0.60 mW (-2.21 dBm)
	lane 1: RX power: 0.52 mW (-2.83 dBm) TX bias: 6.53 mA
pppoe0: flags=1008851<UP,POINTOPOINT,RUNNING,SIMPLEX,MULTICAST,LOWER_UP> metric 0 mtu 1492
	inet 198.51.100.7 --> 198.51.100.1 netmask 0xffffffff
	nd6 options=21<PERFORMNUD,AUTO_LINKLOCAL>
vtnet0: flags=1008843<UP,BROADCAST,RUNNING,SIMPLEX,MULTICAST,LOWER_UP> metric 0 mtu 1500
	ether 52:54:00:12:34:56
	media: Ethernet autoselect (10Gbase-T <full-duplex>)
	status: active
ath0_wlan0: flags=8843<UP,BROADCAST,RUNNING,SIMPLEX,MULTICAST> metric 0 mtu 1500
	ether 04:f0:21:00:00:01
	groups: wlan
	ssid "Office WiFi" channel 6 (2437 MHz 11g ht/20) bssid 04:f0:21:00:00:01
	media: IEEE 802.11 Wireless Ethernet autoselect mode 11ng <hostap>
	status: running
wg0: flags=10080c1<UP,RUNNING,NOARP,MULTICAST,LOWER_UP> metric 0 mtu 1420
	inet 10.99.0.1/24 netmask 0xffffff00
	groups: wg WireGuard
lo0: flags=1008049<UP,LOOPBACK,RUNNING,MULTICAST,LOWER_UP> metric 0 mtu 16384
	inet 127.0.0.1/8
	inet6 ::1/128
TXT);

$w = $parsed['igc0'];
check($w['mtu'] === 1500 && $w['mac'] === '00:0c:29:aa:bb:01' && in_array('UP', $w['flags'], true), 'igc0 flags, MTU and MAC');
check($w['inet'] === array(array('address' => '203.0.113.10', 'prefix' => 24)), 'igc0 IPv4 address in CIDR form');
check(count($w['inet6']) === 4 && $w['inet6'][0]['address'] === 'fe80::20c:29ff:feaa:bb01' && $w['inet6'][0]['prefix'] === 64 &&
    $w['inet6'][0]['scope'] === 'link' && $w['inet6'][1]['scope'] === 'global', 'igc0 IPv6 link-local (zone stripped) and global');
check($w['inet6'][2]['flags'] === array('deprecated', 'autoconf') && $w['inet6'][3]['flags'] === array('detached'), 'IPv6 address flags');
check($w['media'] === 'Ethernet autoselect (2500Base-T <full-duplex>)' && $w['status'] === 'active', 'igc0 media and status');
check(status_nics_link_state($w) === 'up' && status_nics_kind('igc0', $w) === 'ethernet', 'igc0 is an Ethernet port that is up');

check($parsed['igc1']['inet'][0] === array('address' => '192.168.1.1', 'prefix' => 24) && $parsed['igc1']['mtu'] === 9000, 'hex netmask fallback and jumbo MTU');
check(status_nics_link_state($parsed['igc2']) === 'admin_down', 'a port that is not UP is administratively down');
check(status_nics_link_state($parsed['igc3']) === 'down' && $parsed['igc3']['hwaddr'] === '00:0c:29:aa:bb:04', 'UP without carrier is down; hwaddr kept');

$v = $parsed['igc1.10'];
check($v['vlan'] === array('tag' => 10, 'proto' => '802.1q', 'pcp' => 0, 'parent' => 'igc1') && status_nics_kind('igc1.10', $v) === 'vlan', 'VLAN tag and parent');

$l = $parsed['lagg0'];
check($l['lagg']['proto'] === 'lacp' && $l['lagg']['hash'] === 'l2,l3,l4' && count($l['lagg']['ports']) === 2 &&
    $l['lagg']['ports'][0] === array('name' => 'ix0', 'flags' => array('ACTIVE', 'COLLECTING', 'DISTRIBUTING')) &&
    $l['lagg']['ports'][1]['flags'] === array() && status_nics_kind('lagg0', $l) === 'lagg', 'LAGG protocol, hash and ports');

$s = $parsed['ix0']['sfp'];
check($s['plugged'] === 'SFP/SFP+/SFP28 10G Base-SR (LC)' && strpos($s['vendor'], 'FINISAR') === 0 && $s['temperature'] === 31.48 &&
    $s['voltage'] === 3.32 && $s['rx_power'] === array(-2.83) && $s['tx_bias'] === array(6.53), 'SFP module data');

$p = $parsed['pppoe0'];
check($p['inet'][0] === array('address' => '198.51.100.7', 'prefix' => 32) && !isset($p['mac']) && status_nics_kind('pppoe0', $p) === 'ppp' &&
    status_nics_link_state($p) === 'up', 'PPPoE point-to-point address, no MAC, up without a status line');

check(status_nics_kind('vtnet0', $parsed['vtnet0']) === 'ethernet' && status_nics_parse_media($parsed['vtnet0']['media'])['speed_mbps'] === 10000, 'vtnet speed');
$a = $parsed['ath0_wlan0'];
check($a['ssid'] === 'Office WiFi' && $a['channel'] === 6 && status_nics_kind('ath0_wlan0', $a) === 'wireless' && status_nics_link_state($a) === 'up', 'wireless SSID, channel and state');
check(status_nics_kind('wg0', $parsed['wg0']) === 'tunnel' && $parsed['wg0']['groups'] === array('wg', 'WireGuard'), 'WireGuard tunnel and groups');
check(status_nics_kind('lo0', $parsed['lo0']) === 'loopback' && $parsed['lo0']['inet6'][0]['scope'] === 'loopback', 'loopback');
check(status_nics_parse_ifconfig('') === array() && status_nics_parse_ifconfig("garbage\n\tether 00:11:22:33:44:55\n") === array(), 'no output, no interfaces');

/* Addressing and vendors */
check(status_nics_addressing(array('ipaddr' => 'dhcp', 'ipaddrv6' => 'dhcp6')) === array('ipv4' => 'dhcp', 'ipv6' => 'dhcp6'), 'DHCP addressing');
check(status_nics_addressing(array('ipaddr' => '192.168.1.1', 'ipaddrv6' => 'track6')) === array('ipv4' => 'static', 'ipv6' => 'track6'), 'static and track6');
check(status_nics_addressing(array()) === array('ipv4' => 'none', 'ipv6' => 'none'), 'no addressing');
check(status_nics_oui('00:0c:29:aa:bb:01') === '000C29' && status_nics_oui('nope') === null, 'OUI from a MAC address');
check(status_nics_parse_oui(array("000C29 VMware\n", "3CECEF Super Micro Computer\n", "bad line\n"), array('000C29', '3CECEF', 'FFFFFF')) ===
    array('000C29' => 'VMware', '3CECEF' => 'Super Micro Computer'), 'vendors for wanted OUIs');

/* Wiring */
check(strpos($routes, "restapi_route('GET', '/v1/status/nics', 'restapi_h_status_nics'") !== false &&
    strpos($routes, "restapi_route('GET', '/v1/status/nics/{name}', 'restapi_h_status_nic'") !== false, 'routes are registered');
check(substr_count($routes, "'page' => 'status_interfaces.php', 'area' => 'status'") >= 2, 'routes mirror the Status > Interfaces privilege');
check(strpos($api, "require_once('status_nics.inc');") !== false, 'the front controller loads status_nics.inc');
check(strpos($src, "'-a' : escapeshellarg(\$device)") !== false, 'a single device name is shell-escaped');
check(strpos(fn_source($src, 'status_nics_list'), 'get_interface_info') === false, 'the list never runs the per-interface pfctl reads');

echo "NicStatusSmokeTest: OK\n";
