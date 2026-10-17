<?php
/* Standalone CI regression test for the Fleet WebUI handoff and join seeds (fleet_handoff.inc, fleet_seed.inc); run with `php tests/FleetHandoffSeedSmokeTest.php`. */

$root = dirname(__DIR__);
set_include_path(get_include_path() . PATH_SEPARATOR . realpath($root . '/src/etc/inc'));
if (!function_exists('gettext')) {
	function gettext($t) { return $t; }
}
require_once('fleet_handoff.inc');
require_once('fleet_seed.inc');

function check($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

function thrown(callable $fn) {
	try {
		$fn();
	} catch (Throwable $e) {
		return array(get_class($e), $e->getMessage(), ($e instanceof FleetJoinError) ? $e->fleet_code : null);
	}
	return null;
}

/* ---- handoff ---- */
check(fleet_handoff_allowed_users('') === array() && fleet_handoff_allowed_users(' ops, noc ops ') === array('ops', 'noc') &&
    fleet_handoff_allowed_users('ok bad;user ' . str_repeat('x', 33)) === array('ok'), 'allowed users: a clean list, junk dropped');
$req = fleet_handoff_request(array('user' => 'ops', 'operator' => 'admin@HQ'), array('ops'));
check($req === array('user' => 'ops', 'operator' => 'admin@HQ', 'seconds' => 60), 'a handoff request (60 s by default)');
check(thrown(function () { fleet_handoff_request(array('user' => 'admin', 'operator' => 'x'), array()); })[0] === 'DomainException',
    'with no allowed users, handoff is off');
check(strpos(thrown(function () { fleet_handoff_request(array('user' => 'admin', 'operator' => 'x'), array('ops')); })[1], 'user:') === 0,
    'only a listed user (not admin by default)');
foreach (array(array('operator' => ''), array('operator' => "a\nb"), array('seconds' => 121), array('seconds' => 5), array('seconds' => '60'), array('extra' => 1)) as $bad) {
	check(thrown(function () use ($bad) { fleet_handoff_request($bad + array('user' => 'ops', 'operator' => 'x'), array('ops')); })[0] === 'InvalidArgumentException',
	    'refused handoff ' . json_encode($bad));
}
check(fleet_handoff_token_valid(str_repeat('a', 43)) && !fleet_handoff_token_valid('short') && !fleet_handoff_token_valid('../' . str_repeat('a', 40)) &&
    !fleet_handoff_token_valid(array()), 'token format');
$p = fleet_handoff_path(str_repeat('a', 43));
check(strpos($p, '/var/run/fleet-handoff/') === 0 && strpos($p, str_repeat('a', 43)) === false, 'records are stored by hash, never by token');
$auth = file_get_contents("{$root}/src/etc/inc/auth.inc");
$hook = strpos($auth, 'fleet_handoff_signin(');
check($hook !== false && $hook < strpos($auth, "/* Show login page if they aren't logged in */") &&
    strpos($auth, "config_get_path('fleet/handoff_users', '') !== ''") !== false, 'session_auth uses a handoff only when it is allowed');
$src = file_get_contents("{$root}/src/etc/inc/fleet_handoff.inc");
check(strpos($src, '@rename($path, $claimed)') !== false && strpos($src, 'session_regenerate_id();') !== false, 'one use only, and a fresh session id');
require_once('restapi/routes_v1.inc');
$found = false;
foreach (restapi_routes_management() as $r) {
	if (($r['method'] === 'POST') && ($r['path'] === '/v1/auth/handoff')) {
		$found = $r['write'] && ($r['area'] === 'management') && function_exists($r['handler']);
	}
}
check($found, 'POST /v1/auth/handoff');
check(strpos(file_get_contents("{$root}/src/usr/local/sbin/freesense-join"), "case 'handoff-users'") !== false, 'handoff users are set locally');

/* ---- seeds ---- */
$link = fleet_join_link_build('hq.example.net:8443', 'a1b2c3d4e5f6', str_repeat('A', 43), str_repeat('b', 64));
check(fleet_seed_parse(json_encode(array('version' => 1, 'link' => $link, 'mode' => 'attended')))['mode'] === 'attended', 'an attended seed');
check(fleet_seed_parse(json_encode(array('version' => 1, 'link' => $link, 'mode' => 'unattended', 'key' => 'FLT-K7Q2-M9XH-4T1R-Z8VB-3NCS')))['mode'] === 'unattended',
    'an unattended seed');
foreach (array(
	'not json', json_encode(array('version' => 2, 'link' => $link, 'mode' => 'attended')), json_encode(array('version' => 1, 'link' => 'https://x', 'mode' => 'attended')),
	json_encode(array('version' => 1, 'link' => $link, 'mode' => 'magic')), json_encode(array('version' => 1, 'link' => $link, 'mode' => 'unattended', 'key' => 'FLT-WRONG')),
	json_encode(array('version' => 1, 'link' => $link, 'mode' => 'pin')),
	json_encode(array('version' => 1, 'link' => $link, 'mode' => 'attended', 'key' => 'FLT-K7Q2-M9XH-4T1R-Z8VB-3NCS')),
) as $bad) {
	$t = thrown(function () use ($bad) { fleet_seed_parse($bad); });
	check(($t !== null) && ($t[2] === 'E122' || $t[2] === 'E102'), 'refused seed ' . substr($bad, 0, 60));
}

check(fleet_seed_pin_valid('123456') && fleet_seed_pin_valid('12345678') && !fleet_seed_pin_valid('12345') && !fleet_seed_pin_valid('12a456'), 'PIN format');
$sealed = fleet_seed_seal_key('FLT-K7Q2-M9XH-4T1R-Z8VB-3NCS', '482913');
check(fleet_seed_open_key($sealed, '482913') === 'FLT-K7Q2-M9XH-4T1R-Z8VB-3NCS', 'the right PIN opens the key');
check(fleet_seed_open_key($sealed, '482914') === null, 'a wrong PIN does not');
check(strpos(json_encode($sealed), 'K7Q2') === false, 'the sealed seed does not show the key');
$tampered = $sealed;
$tampered['data'] = base64_encode(strrev(base64_decode($sealed['data'])));
check(fleet_seed_open_key($tampered, '482913') === null && fleet_seed_open_key(array('salt' => 'x'), '482913') === null, 'a damaged seed opens nothing');
check(fleet_seed_seal_key('x', '482913')['salt'] !== fleet_seed_seal_key('x', '482913')['salt'], 'every seal is salted');
check(fleet_seed_parse(json_encode(array('version' => 1, 'link' => $link, 'mode' => 'pin', 'sealed_key' => $sealed)))['mode'] === 'pin', 'a PIN seed');

$tmp = sys_get_temp_dir() . '/fleetseed' . getmypid();
mkdir("{$tmp}/config", 0700, true);
file_put_contents("{$tmp}/config/fleet-join.conf", '{"version":1}');
check(strpos(file_get_contents("{$root}/src/etc/rc.ecl"), "fleet_seed_capture('/tmp/mnt/cf');") !== false, 'rc.ecl looks for a seed on every medium it mounts');
$boot = file_get_contents("{$root}/src/etc/rc.bootup");
check(strpos($boot, 'fleet_seed_boot();') > strpos($boot, 'restapi_listeners_configure();'), 'the seed is used at the end of boot, when the network is up');
$cli = file_get_contents("{$root}/src/usr/local/sbin/freesense-join");
$agent = file_get_contents("{$root}/src/etc/inc/fleet_agent.inc");
check(strpos($cli, "case 'seed'") !== false && strpos($cli, 'fleet_seed_open_key(') !== false && strpos($agent, 'fleet_seed_clear();') !== false,
    'the CLI joins from a seed, asks for a PIN, and forgets the seed afterwards');
ob_start();
$captured = fleet_seed_capture($tmp);
ob_end_clean();
check($captured === false, 'an invalid seed on a medium is ignored');
unlink("{$tmp}/config/fleet-join.conf");
rmdir("{$tmp}/config");
rmdir($tmp);

/* ---- where the controller can open the WebGUI ---- */
$ips = array(array('name' => 'WAN', 'ip' => '203.0.113.7'), array('name' => 'LAN', 'ip' => '192.168.1.1'),
    array('name' => 'OPT1', 'ip' => ''), array('name' => 'V6', 'ip' => '2001:db8::1'));
check(fleet_webgui_endpoints(array('protocol' => 'https'), $ips) === array(array('name' => 'WAN', 'url' => 'https://203.0.113.7'),
    array('name' => 'LAN', 'url' => 'https://192.168.1.1'), array('name' => 'V6', 'url' => 'https://[2001:db8::1]')), 'one URL per address; no address, no URL');
check(fleet_webgui_endpoints(array('protocol' => 'https', 'port' => '8443'), array($ips[1]))[0]['url'] === 'https://192.168.1.1:8443' &&
    fleet_webgui_endpoints(array('protocol' => 'http', 'port' => '80'), array($ips[1]))[0]['url'] === 'http://192.168.1.1', 'protocol and port');
check(fleet_webgui_endpoints(array(), array(array('name' => 'X', 'ip' => 'evil.example/'))) === array(), 'only IP addresses become URLs');


/* A seed may carry a one-time Tailscale key: plain only when unattended, otherwise sealed to the PIN or the join key. */
list($tshown, $tdata) = fleet_join_key_generate();
$tlink = 'https://100.64.0.1:8443/fleet/join#abcdef012345.' . str_repeat('A', 43) . '.' . str_repeat('a', 64) . '.s';
$tsk = 'tskey-auth-kTEST123CNTRL-abcdefghijkl';
$tseed = function (array $s) {
	try {
		return fleet_seed_parse(json_encode($s));
	} catch (FleetJoinError $e) {
		return null;
	}
};
$tu = $tseed(array('version' => 1, 'link' => $tlink, 'mode' => 'unattended', 'key' => $tshown, 'tailscale' => array('key' => $tsk)));
$ta = $tseed(array('version' => 1, 'link' => $tlink, 'mode' => 'attended', 'tailscale' => array('sealed' => fleet_seed_seal_key($tsk, $tdata))));
$tp = $tseed(array('version' => 1, 'link' => $tlink, 'mode' => 'pin', 'sealed_key' => fleet_seed_seal_key($tshown, '123456'), 'tailscale' => array('sealed' => fleet_seed_seal_key($tsk, '123456'))));
check(($tu !== null) && ($ta !== null) && ($tp !== null) &&
    (fleet_seed_tailscale_key($tu, '') === $tsk) && (fleet_seed_tailscale_key($ta, fleet_join_key_normalize($tshown)) === $tsk) &&
    (fleet_seed_tailscale_key($ta, 'WRONGWRONGWRONGWRON') === null) && (fleet_seed_tailscale_key($tp, '123456') === $tsk) && (fleet_seed_tailscale_key($tp, '654321') === null),
    'the Tailscale key opens with the join key (attended) or the PIN, and is plain only in unattended seeds');
check(($tseed(array('version' => 1, 'link' => $tlink, 'mode' => 'attended', 'tailscale' => array('key' => $tsk))) === null) &&
    ($tseed(array('version' => 1, 'link' => $tlink, 'mode' => 'unattended', 'key' => $tshown, 'tailscale' => array('key' => 'tskey-api-x1234567890'))) === null) &&
    ($tseed(array('version' => 1, 'link' => $tlink, 'mode' => 'unattended', 'key' => $tshown, 'tailscale' => 'x')) === null),
    'a plain Tailscale key outside an unattended seed, or a wrong one, makes the seed invalid');
echo "FleetHandoffSeedSmokeTest: OK\n";
