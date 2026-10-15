<?php
/* Standalone CI regression test for the Fleet agent builders (fleet_agent.inc) and its wiring; run with `php tests/FleetAgentSmokeTest.php`. */

$root = dirname(__DIR__);
set_include_path(get_include_path() . PATH_SEPARATOR . realpath($root . '/src/etc/inc'));
if (!function_exists('gettext')) {
	function gettext($t) { return $t; }
}
require_once('fleet_agent.inc');

function check($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

$pub = base64_encode(str_repeat("\x07", 32));
$peer = array('public_key' => $pub, 'endpoint' => 'hq.example.net:51830', 'allowed_ips' => array('fd12:3456:789a:f1::/64'));

/* ---- privileges of the fleet-controller user ---- */
$mon = fleet_access_privs('monitor');
$man = fleet_access_privs('managed');
check(in_array('page-system-restapi-keys', $mon, true) && in_array('api-management-read', $mon, true) &&
    in_array('api-config-read', $mon, true) && in_array('api-status-read', $mon, true), 'a monitor reads what the heartbeat and backups need');
check(count(preg_grep('/-write$/', $mon)) === 0, 'a monitor holds no write privilege');
check(in_array('api-management-write', $man, true) && in_array('api-config-write', $man, true) &&
    in_array('api-packages-write', $man, true) && in_array('api-operations-write', $man, true), 'a managed box may be changed');
check(!in_array('api-users-write', $man, true) && !in_array('api-pki-write', $man, true) && !in_array('page-all', $man, true) &&
    !in_array('user-shell-access', $man, true), 'the controller never gets users, certificates, a shell or all pages');
$all_areas = array_keys(restapi_areas());
foreach ($man as $p) {
	check($p === 'page-system-restapi-keys' || preg_match('/^api-[a-z-]+-(read|write)$/D', $p) === 1, "{$p} is a known kind of privilege");
}
$entry = fleet_access_user_entry(2001, '$2y$10$hash', 'monitor');
check($entry['name'] === 'fleet-controller' && $entry['uid'] === '2001' && $entry['bcrypt-hash'] === '$2y$10$hash' &&
    $entry['priv'] === $mon && !isset($entry['password']) && !isset($entry['disabled']), 'the user entry: API only, hash only');

/* ---- peers and networks ---- */
check(fleet_valid_peer($peer) && fleet_valid_peer(array('endpoint' => '') + $peer) && fleet_valid_peer(array('endpoint' => '[2001:db8::1]:443') + $peer),
    'valid peers');
foreach (array(array('public_key' => 'short') + $peer, array('endpoint' => 'bad host:1') + $peer, array('endpoint' => 'hq;rm:1') + $peer,
    array('allowed_ips' => array()) + $peer, array('allowed_ips' => array('fd12::1')) + $peer, array('allowed_ips' => array('fd12::/200')) + $peer,
    array('allowed_ips' => 'fd12::/64') + $peer) as $bad) {
	check(!fleet_valid_peer($bad), 'refused peer ' . json_encode($bad));
}
check(!fleet_valid_peer('x') && !fleet_valid_peer(null), 'a peer is a list of fields');
check(fleet_networks_overlap('10.0.0.0/24', '10.0.0.128/25') && fleet_networks_overlap('10.0.0.5/32', '10.0.0.0/24') &&
    !fleet_networks_overlap('10.0.0.0/24', '10.0.1.0/24') && fleet_networks_overlap('fd12:3456:789a:f1::/64', 'fd12:3456:789a:f1::10/128') &&
    !fleet_networks_overlap('fd12:3456:789a:f1::/64', '10.0.0.0/8'), 'overlapping networks');
check(fleet_overlay_conflict(array('10.100.0.0/16'), array('192.168.1.1/24', '10.100.4.1/24')) === '10.100.4.1/24' &&
    fleet_overlay_conflict(array('fd12:3456:789a:f1::/64'), array('192.168.1.1/24', '10.0.0.1/8')) === null, 'a conflicting local subnet is found (E107)');
check(fleet_controller_networks(array($peer, $peer)) === array('fd12:3456:789a:f1::/64'), 'controller networks, once each');

/* ---- the tunnel commands ---- */
$cmds = fleet_tunnel_commands('fd12:3456:789a:f1::10', array($peer));
check($cmds[0] === array('/sbin/ifconfig', 'wg', 'create', 'name', 'wgfleet0'), 'the interface is created first');
check($cmds[1] === array('/usr/bin/wg', 'set', 'wgfleet0', 'private-key', '/cf/conf/fleet/wg.key'), 'the key comes from a file, never the command line');
check($cmds[2] === array('/usr/bin/wg', 'set', 'wgfleet0', 'peer', $pub, 'endpoint', 'hq.example.net:51830', 'persistent-keepalive', '25',
    'allowed-ips', 'fd12:3456:789a:f1::/64'), 'the node starts the connection with a keepalive');
check($cmds[3] === array('/sbin/ifconfig', 'wgfleet0', 'inet6', 'fd12:3456:789a:f1::10', 'prefixlen', '128'), 'a single overlay address');
check($cmds[4] === array('/sbin/ifconfig', 'wgfleet0', 'mtu', '1380', 'up'), 'MTU 1380');
check($cmds[5] === array('/sbin/route', '-n', 'add', '-inet6', 'fd12:3456:789a:f1::/64', '-interface', 'wgfleet0'), 'a route to the controller\'s network');
foreach ($cmds as $argv) {
	foreach ($argv as $arg) {
		check(is_string($arg) && (strpos($arg, "\n") === false), 'plain arguments, no shell');
	}
}
$v4 = fleet_tunnel_commands('10.99.0.7', array(array('endpoint' => '', 'allowed_ips' => array('10.99.0.1/32')) + $peer));
check($v4[2][3] === 'peer' && !in_array('endpoint', $v4[2], true) && $v4[3] === array('/sbin/ifconfig', 'wgfleet0', 'inet', '10.99.0.7', 'netmask', '255.255.255.255') &&
    $v4[5] === array('/sbin/route', '-n', 'add', '-inet', '10.99.0.1/32', '-interface', 'wgfleet0'), 'an IPv4 overlay and a peer without endpoint');

/* ---- config.xml section and listener ---- */
$m = array('node_id' => 'node-0123456789', 'controller_url' => 'https://hq.example.net:8443', 'controller_fingerprint' => str_repeat('a', 64),
    'controller_signing_key' => 'PEM', 'method' => 'wireguard', 'overlay_address' => 'fd12:3456:789a:f1::10', 'role' => 'managed',
    'hostname' => 'dk-cph-edge01', 'peers' => array($peer), 'wg_private' => 'SECRET-KEY', 'listener_certref' => 'abc123');
$section = fleet_membership_section($m, 1760000000, array());
check($section['enable'] === true && $section['joined'] === '1760000000' && $section['restapi_was_enabled'] === 'no' &&
    $section['peer'][0]['allowed_ips'] === 'fd12:3456:789a:f1::/64', 'the <fleet> section');
check(strpos(json_encode($section), 'SECRET-KEY') === false, 'the WireGuard private key is never written to config.xml');
check(fleet_membership_section($m, 1, array('enable' => true))['restapi_was_enabled'] === 'yes' &&
    fleet_membership_section($m, 1, array('enable' => true, 'noguiapi' => true))['restapi_was_guiapi'] === 'no', 'the REST API setting is remembered');
$mem = fleet_membership($section);
check($mem['enrolled'] && $mem['role'] === 'managed' && $mem['overlay_address'] === 'fd12:3456:789a:f1::10', 'the node reads its own section back');
$listener = fleet_listener_post($m);
check($listener['address'] === 'fleet' && $listener['port'] === 8444 && $listener['allowednetworks'] === 'fd12:3456:789a:f1::/64' &&
    $listener['certref'] === 'abc123' && $listener['firewall'] === true, 'the listener: tunnel only, controller networks only');
$prog = fleet_progress_record(fleet_progress_record(array(), 'connect', 'done', '', 5), 'approval', 'wait', '634403', 6);
check($prog['connect']['state'] === 'done' && $prog['approval']['text'] === '634403', 'progress is kept per step');

/* ---- wiring ---- */
$listener_src = file_get_contents("{$root}/src/etc/inc/restapi_listener.inc");
check(strpos($listener_src, "\$choices['fleet']") !== false && strpos($listener_src, "if (\$key === 'fleet')") !== false &&
    strpos($listener_src, "'realif' => 'wgfleet0'") !== false && strpos("wgfleet0", FLEET_WG_IF) === 0, 'the listener understands the fleet tunnel address');
$boot = file_get_contents("{$root}/src/etc/rc.bootup");
check(strpos($boot, 'fleet_boot();') !== false && strpos($boot, 'fleet_boot();') < strpos($boot, 'restapi_listeners_configure();'),
    'the tunnel comes up before the listeners at boot');
check(strpos(file_get_contents("{$root}/src/etc/rc.initial"), '17)') !== false && strpos(file_get_contents("{$root}/src/etc/rc.initial"), 'freesense-join menu') !== false,
    'console option 17');
$cli = file_get_contents("{$root}/src/usr/local/sbin/freesense-join");
foreach (array("case 'join'", "case 'status'", "case 'leave'", "case 'detach'", "case 'menu'") as $c) {
	check(strpos($cli, $c) !== false, "the CLI has {$c}");
}
check(strpos($cli, 'stty -echo') !== false, 'the CLI asks for the join key without echo');
require_once('restapi/routes_v1.inc');
$leave = null;
foreach (restapi_routes_management() as $r) {
	if ($r['path'] === '/v1/management/leave') {
		$leave = $r;
	}
}
check($leave !== null && $leave['method'] === 'POST' && $leave['write'] && function_exists($leave['handler']), 'POST /v1/management/leave exists');

echo "FleetAgentSmokeTest: OK\n";
