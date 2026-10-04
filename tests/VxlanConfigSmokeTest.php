<?php
/* Standalone CI regression test; run with `php tests/VxlanConfigSmokeTest.php`. */

set_include_path(get_include_path() . PATH_SEPARATOR . realpath(__DIR__ . '/../src/etc/inc'));
require_once('vxlan.inc');
require_once('xmlparse.inc');

function check_vxlan($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

$unicast = [
	'vxlanif' => 'vxlan0', 'if' => 'wan', 'ipproto' => 'inet', 'mode' => 'unicast',
	'vni' => '100', 'remote-addr' => '198.51.100.2', 'mac' => '02:aa:bb:cc:dd:ee',
];

/* ifconfig arguments */
$args = vxlan_ifconfig_args($unicast, '192.0.2.1', 'em0');
check_vxlan($args === ['vxlanid', '100', 'vxlanlocal', '192.0.2.1', 'vxlanremote', '198.51.100.2', 'ether', '02:aa:bb:cc:dd:ee'],
    'unicast arguments: ' . implode(' ', $args));

$linux = $unicast + ['localport' => '8472', 'remoteport' => '8472', 'ttl' => '32', 'nolearn' => ''];
$args = implode(' ', vxlan_ifconfig_args($linux, '192.0.2.1', 'em0'));
check_vxlan(strpos($args, 'vxlanlocalport 8472 vxlanremoteport 8472 vxlanttl 32 -vxlanlearn') !== false,
    'non-default ports, TTL and learning: ' . $args);

$multicast = ['vxlanif' => 'vxlan1', 'if' => 'opt1', 'ipproto' => 'inet', 'mode' => 'multicast',
	'vni' => '200', 'mcastgroup' => '239.1.1.1'];
$args = vxlan_ifconfig_args($multicast, '10.0.0.1', 'vtnet1.10');
check_vxlan($args === ['vxlanid', '200', 'vxlanlocal', '10.0.0.1', 'vxlangroup', '239.1.1.1', 'vxlandev', 'vtnet1.10'],
    'multicast arguments must set vxlanlocal, vxlangroup and vxlandev: ' . implode(' ', $args));

$v6 = ['vxlanif' => 'vxlan2', 'if' => 'wan', 'ipproto' => 'inet6', 'mode' => 'unicast',
	'vni' => '5', 'remote-addr' => '2001:db8::2'];
$args = vxlan_ifconfig_args($v6, '2001:db8::1', 'em0');
check_vxlan($args === ['vxlanid', '5', 'vxlanlocal', '2001:db8::1', 'vxlanremote', '2001:db8::2'],
    'IPv6 unicast arguments: ' . implode(' ', $args));

/* MTU */
check_vxlan(vxlan_default_mtu(1500, 'inet') === 1450, 'IPv4 MTU must be parent - 50');
check_vxlan(vxlan_default_mtu(1500, 'inet6') === 1430, 'IPv6 MTU must be parent - 70');
check_vxlan(vxlan_default_mtu(1492, 'inet') === 1442, 'PPPoE parent MTU must be honored');
check_vxlan(vxlan_default_mtu(0, 'inet') === 1450, 'unknown parent MTU falls back to 1500');

/* MAC */
for ($i = 0; $i < 32; $i++) {
	$mac = vxlan_generate_mac();
	check_vxlan(vxlan_is_valid_mac($mac), "generated MAC {$mac} must be well-formed");
	$first = hexdec(substr($mac, 0, 2));
	check_vxlan(($first & 0x01) == 0 && ($first & 0x02) == 2, "generated MAC {$mac} must be unicast and locally administered");
}

/* Validation */
check_vxlan(vxlan_validate($unicast, '192.0.2.1') === [], 'a valid unicast tunnel must pass');
check_vxlan(vxlan_validate($multicast, '10.0.0.1') === [], 'a valid multicast tunnel must pass');
check_vxlan(vxlan_validate($v6, '2001:db8::1') === [], 'a valid IPv6 tunnel must pass');
check_vxlan(vxlan_validate($linux, '192.0.2.1') === [], 'Linux port 8472 must pass');

$bad = function ($changes, $localaddr = '192.0.2.1', $base = null) use ($unicast) {
	return vxlan_validate(array_merge($base ?? $unicast, $changes), $localaddr) !== [];
};
check_vxlan($bad(['vni' => '16777216']), 'VNI above 2^24-1 must fail');
check_vxlan($bad(['vni' => '-1']), 'negative VNI must fail');
check_vxlan($bad(['vni' => '1.5']), 'non-integer VNI must fail');
check_vxlan(!$bad(['vni' => '0']), 'VNI 0 must pass');
check_vxlan(!$bad(['vni' => '16777215']), 'VNI 2^24-1 must pass');
check_vxlan($bad(['localport' => '0']), 'port 0 must fail');
check_vxlan($bad(['remoteport' => '65536']), 'port 65536 must fail');
check_vxlan($bad(['ttl' => '0']), 'TTL 0 must fail');
check_vxlan($bad(['ttl' => '256']), 'TTL 256 must fail');
check_vxlan($bad(['mac' => '02:aa:bb:cc:dd']), 'short MAC must fail');
check_vxlan($bad(['remote-addr' => '2001:db8::2']), 'IPv6 remote on an IPv4 tunnel must fail');
check_vxlan($bad(['remote-addr' => '239.1.1.1']), 'multicast remote in unicast mode must fail');
check_vxlan($bad([], ''), 'a parent without an address of the family must fail');
check_vxlan($bad(['if' => '']), 'a missing parent must fail');
check_vxlan($bad(['mcastgroup' => '224.0.0.251'], '10.0.0.1', $multicast), 'link-local IPv4 group must fail');
check_vxlan($bad(['mcastgroup' => '192.0.2.10'], '10.0.0.1', $multicast), 'unicast group must fail');
check_vxlan(!$bad(['mcastgroup' => '224.0.1.0'], '10.0.0.1', $multicast), '224.0.1.0 must pass');
$m6 = ['ipproto' => 'inet6', 'mode' => 'multicast'] + $multicast;
check_vxlan($bad(['mcastgroup' => 'ff02::1:5'], '2001:db8::1', $m6), 'link-scope IPv6 group must fail');
check_vxlan(!$bad(['mcastgroup' => 'ff05::100'], '2001:db8::1', $m6), 'site-scope IPv6 group must pass');
check_vxlan(!$bad(['mcastgroup' => 'ff0e::100'], '2001:db8::1', $m6), 'global-scope IPv6 group must pass');

/* Uniqueness on (local address, local port, VNI) */
$others = [['vxlanif' => 'vxlan5', 'localaddr' => '192.0.2.1', 'localport' => 4789, 'vni' => 100]];
check_vxlan(vxlan_validate($unicast, '192.0.2.1', $others) !== [], 'same address, port and VNI must clash');
check_vxlan(vxlan_validate($unicast, '192.0.2.9', $others) === [], 'a different local address (e.g. a VIP) must not clash');
check_vxlan(vxlan_validate(['localport' => '4790'] + $unicast, '192.0.2.1', $others) === [], 'a different local port must not clash');
check_vxlan(vxlan_validate(['vni' => '101'] + $unicast, '192.0.2.1', $others) === [], 'a different VNI must not clash');
$self = [['vxlanif' => 'vxlan0', 'localaddr' => '192.0.2.1', 'localport' => 4789, 'vni' => 100]];
check_vxlan(vxlan_validate($unicast, '192.0.2.1', $self) === [], 'editing a tunnel must not clash with itself');

/* Multicast tunnels of a family share one wildcard socket per port */
$mc_other = [['vxlanif' => 'vxlan7', 'ipproto' => 'inet', 'mode' => 'multicast', 'localaddr' => '10.9.9.9', 'localport' => 4789, 'vni' => 200]];
check_vxlan(vxlan_validate($multicast, '10.0.0.1', $mc_other) !== [], 'same multicast VNI and port must clash even on another parent');
check_vxlan(vxlan_validate(['vni' => '201'] + $multicast, '10.0.0.1', $mc_other) === [], 'another multicast VNI on the same port must pass');
check_vxlan(vxlan_validate(['localport' => '4790'] + $multicast, '10.0.0.1', $mc_other) === [], 'another multicast port must pass');
$v6_other = [['ipproto' => 'inet6'] + $mc_other[0]];
check_vxlan(vxlan_validate($multicast, '10.0.0.1', $v6_other) === [], 'the other address family must not clash');
check_vxlan(vxlan_validate($unicast, '192.0.2.1', $mc_other) !== [], 'unicast and multicast must not share a port in one family');
check_vxlan(vxlan_validate(['localport' => '4790'] + $unicast, '192.0.2.1', $mc_other) === [], 'unicast on another port than multicast must pass');

/* All IPv6 tunnels share the one zero-checksum port */
$v6_peer = [['vxlanif' => 'vxlan8', 'ipproto' => 'inet6', 'mode' => 'unicast', 'localaddr' => '2001:db8::9', 'localport' => 4789, 'vni' => 9]];
check_vxlan(vxlan_validate($v6, '2001:db8::1', $v6_peer) === [], 'IPv6 tunnels on the same local port must pass');
check_vxlan(vxlan_validate(['localport' => '8472', 'remoteport' => '8472'] + $v6, '2001:db8::1', $v6_peer) !== [],
    'IPv6 tunnels on different local ports must fail');
check_vxlan(vxlan_validate(['localport' => '8472'] + $unicast, '192.0.2.1', $others) === [],
    'IPv4 tunnels may use different local ports');

/* Remote sanity */
foreach (['0.0.0.0', '255.255.255.255', '127.0.0.1', '192.0.2.1'] as $remote) {
	check_vxlan($bad(['remote-addr' => $remote]), "remote {$remote} must fail");
}
foreach (['::', '::1', 'fe80::1', '2001:db8::1'] as $remote) {
	check_vxlan(vxlan_validate(['remote-addr' => $remote] + $v6, '2001:db8::1') !== [], "remote {$remote} must fail");
}

/* Interface names and kernel state */
check_vxlan(vxlan_next_ifname([]) === 'vxlan0', 'first name must be vxlan0');
check_vxlan(vxlan_next_ifname(['vxlan0', 'vxlan2']) === 'vxlan1', 'names must fill the lowest gap');
check_vxlan(vxlan_next_ifname(['vxlan0', 'vxlan1']) === 'vxlan2', 'names must skip configured and existing ones');
check_vxlan(vxlan_ifconfig_is_running("vxlan0: flags=8843<UP,BROADCAST,RUNNING,SIMPLEX,MULTICAST> metric 0 mtu 1450"), 'RUNNING must be detected');
check_vxlan(!vxlan_ifconfig_is_running("vxlan0: flags=8803<UP,BROADCAST,SIMPLEX,MULTICAST> metric 0 mtu 1450"), 'an interface that is up but not running must be detected');
check_vxlan(!vxlan_ifconfig_is_running(""), 'missing output must not count as running');

/* Filter rules */
check_vxlan(vxlan_filter_rules($unicast, '$WAN', '192.0.2.1') === [], 'no rule unless allowrule is set');
$rules = vxlan_filter_rules($unicast + ['allowrule' => ''], '$WAN', '192.0.2.1');
check_vxlan(count($rules) === 1 &&
    $rules[0]['rule'] === 'on $WAN inet proto udp from 198.51.100.2 to 192.0.2.1 port = 4789',
    'unicast rule: ' . ($rules[0]['rule'] ?? ''));
check_vxlan(substr_count($rules[0]['rule'], 'port') === 1, 'the rule must not match a source port');
$rules = vxlan_filter_rules($linux + ['allowrule' => ''], '$WAN', '192.0.2.1');
check_vxlan(strpos($rules[0]['rule'], 'port = 8472') !== false, 'the rule must use the local port');
check_vxlan(vxlan_filter_rules($unicast + ['allowrule' => ''], '$WAN', '') === [], 'no rule without a local address');
$rules = vxlan_filter_rules($multicast + ['allowrule' => ''], '$OPT1', '10.0.0.1');
$text = implode("\n", array_column($rules, 'rule'));
check_vxlan(strpos($text, 'to 239.1.1.1 port = 4789') !== false, 'multicast rule must pass the group');
check_vxlan(strpos($text, 'proto igmp') !== false && strpos($text, 'allow-opts') !== false, 'multicast must pass IGMP with options');
$rules = vxlan_filter_rules(array_merge($m6, ['mcastgroup' => 'ff05::100', 'allowrule' => '']), '$OPT1', '2001:db8::1');
check_vxlan(strpos(implode("\n", array_column($rules, 'rule')), 'icmp6-type { 130, 131, 132, 143 }') !== false,
    'IPv6 multicast must pass MLD');

/* Config round trip */
$config = ['vxlans' => ['vxlan' => [$unicast, $multicast]]];
$xml = dump_xml_config($config, 'freesense');
check_vxlan(substr_count($xml, '<vxlan>') === 2, 'vxlan entries must be written as repeated tags');
$file = tempnam(sys_get_temp_dir(), 'fs-vxlan-');
file_put_contents($file, $xml);
$back = parse_xml_config($file, 'freesense');
unlink($file);
check_vxlan(($back['vxlans']['vxlan'][1]['mcastgroup'] ?? null) === '239.1.1.1', 'vxlan list must survive a round trip');
$single = ['vxlans' => ['vxlan' => [$unicast]]];
file_put_contents($file, dump_xml_config($single, 'freesense'));
$back = parse_xml_config($file, 'freesense');
unlink($file);
check_vxlan(($back['vxlans']['vxlan'][0]['vni'] ?? null) === '100', 'a single vxlan must still parse as a list');

/* Source-level regressions */
$root = dirname(__DIR__);
$newwanipv6 = file_get_contents($root . '/src/etc/rc.newwanipv6');
$recreate = strpos($newwanipv6, 'interface_vxlan_reconfigure_children(');
$bootexit = strpos($newwanipv6, '_dhcp6_complete');
check_vxlan($recreate !== false && $bootexit !== false && $recreate < $bootexit,
    'rc.newwanipv6 must recreate VXLAN tunnels before its boot-time exit');

$fast = file_get_contents($root . '/src/etc/inc/interfaces_fast.inc');
check_vxlan(preg_match("/isvxlan'\\]\\) \\{(.*?)\\} elseif/s", $fast, $branch) === 1 &&
    strpos($branch[1], '$friendlyifnames') === false,
    'the assignments label must not look up a VXLAN parent in the real-interface map');

$interfaces = file_get_contents($root . '/src/etc/inc/interfaces.inc');
check_vxlan(preg_match('/Failed to configure VXLAN %s.*?return -1;/s', $interfaces) === 1,
    'a failed ifconfig must not be reported as a configured tunnel');

/* The allow rule must sort before the parent's quick bogon and private blocks */
$util = file_get_contents($root . '/src/etc/inc/util.inc');
$categories = substr($util, strpos($util, "define('PFCONFIG_CATEGORIES'"));
$order = [];
foreach (['PFCONFIG_FILTER_IF_VXLAN', 'PFCONFIG_FILTER_IF_BOGON_IPV4', 'PFCONFIG_FILTER_IF_BOGON_IPV6', 'PFCONFIG_FILTER_IF_PRIVATE'] as $name) {
	$order[$name] = strpos($categories, "{$name}['id']");
	check_vxlan($order[$name] !== false, "{$name} must be in PFCONFIG_CATEGORIES");
}
check_vxlan($order['PFCONFIG_FILTER_IF_VXLAN'] < min($order['PFCONFIG_FILTER_IF_BOGON_IPV4'],
    $order['PFCONFIG_FILTER_IF_BOGON_IPV6'], $order['PFCONFIG_FILTER_IF_PRIVATE']),
    'the VXLAN allow rule must sort before the bogon and private network blocks');

check_vxlan(strpos($interfaces, 'FreeSense_interface_create2("vxlan")') === false,
    'a new VXLAN must not let the kernel pick a unit that a configured tunnel owns');
check_vxlan(strpos($interfaces, "lock('vxlan', LOCK_EX)") !== false,
    'concurrent configuration of a tunnel must be serialized');
check_vxlan(strpos($interfaces, "set_single_sysctl('net.inet6.udp6.rfc6935_port'") !== false,
    'IPv6 tunnels must set the zero-checksum port explicitly');
check_vxlan(strpos($interfaces, 'vxlan_ifconfig_is_running(') !== false,
    'a tunnel that never reaches RUNNING must be reported');

$vipinc = file_get_contents($root . '/src/usr/local/FreeSense/include/www/firewall_virtual_ip.inc');
$xmlrpc = file_get_contents($root . '/src/usr/local/www/xmlrpc.php');
check_vxlan(strpos($vipinc, 'interface_tunnels_reconfigure_vip(') !== false &&
    strpos($xmlrpc, 'interface_tunnels_reconfigure_vip(') !== false,
    'applying or syncing a VIP must recreate the tunnels sent from it');
check_vxlan(preg_match("/function interface_tunnels_reconfigure_vip.*?array\('gre', 'gif'\).*?interface_vxlan_reconfigure_children/s", $interfaces) === 1,
    'VIP changes must recreate GRE, GIF and VXLAN tunnels');

echo "VXLAN config smoke test passed.\n";
