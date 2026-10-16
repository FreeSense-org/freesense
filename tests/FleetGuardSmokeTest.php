<?php
/* Standalone CI regression test for the Fleet guards (fleet_guard.inc): Lock, break-glass, commit-confirm, banner; run with `php tests/FleetGuardSmokeTest.php`. */

$root = dirname(__DIR__);
set_include_path(get_include_path() . PATH_SEPARATOR . realpath($root . '/src/etc/inc'));
if (!function_exists('gettext')) {
	function gettext($t) { return $t; }
}
require_once('fleet_guard.inc');

function check($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

function invalid_of(callable $fn) {
	try {
		$fn();
	} catch (InvalidArgumentException $e) {
		return $e->getMessage();
	}
	return null;
}

/* A configuration as path => text|null. */
function conf(array $values) {
	return function ($path) use ($values) { return array_key_exists($path, $values) ? $values[$path] : null; };
}

/* ---- rules ---- */
$ssh = array('id' => 'SEC-012', 'title' => 'SSH password login is not allowed', 'mode' => 'lock', 'path' => 'system/ssh/sshdkeyonly', 'op' => 'equals', 'value' => 'both');
check(fleet_rule_valid($ssh), 'a valid rule');
foreach (array(
	array('id' => 'bad') + $ssh, array('mode' => 'delete') + $ssh, array('op' => 'exec') + $ssh, array('path' => '../etc/passwd') + $ssh,
	array('path' => 'system/$(id)') + $ssh, array('op' => 'in', 'value' => 'x') + $ssh, array('op' => 'in', 'value' => array()) + $ssh,
	array('op' => 'min', 'value' => 'abc') + $ssh, array('op' => 'regex', 'value' => '(') + $ssh, array('title' => str_repeat('x', 161)) + $ssh,
	array_diff_key($ssh, array('value' => 1)),
) as $bad) {
	check(!fleet_rule_valid($bad), 'refused rule ' . json_encode($bad));
}
check(!fleet_rule_valid('x') && !fleet_rule_valid(null), 'a rule is a list of fields');
check(fleet_rule_valid(array('op' => 'present') + array_diff_key($ssh, array('value' => 1))), 'present needs no value');

$ops = array(
	array('equals', 'both', 'both', true), array('equals', 'both', 'x', false), array('not_equals', 'x', 'both', true), array('not_equals', 'x', 'x', false),
	array('in', array('a', 'b'), 'b', true), array('in', array('a', 'b'), 'c', false), array('not_in', array('a'), 'c', true), array('not_in', array('a'), 'a', false),
	array('regex', '^TLS1\.[23]$', 'TLS1.3', true), array('regex', '^TLS1\.[23]$', 'TLS1.0', false),
	array('min', 8, '12', true), array('min', 8, '7', false), array('min', 8, 'abc', false), array('max', 90, '30', true), array('max', 90, '91', false),
	array('equals', '1', '1', true),
);
foreach ($ops as list($op, $value, $actual, $want)) {
	$r = array('op' => $op, 'value' => $value) + $ssh;
	check(fleet_rule_compliant($r, conf(array('system/ssh/sshdkeyonly' => $actual))) === $want, "{$op} " . json_encode($value) . " vs {$actual}");
}
$absent = conf(array());
$flag = conf(array('system/ssh/sshdkeyonly' => ''));
check(!fleet_rule_compliant($ssh, $absent), 'equals on a missing value is a violation');
check(fleet_rule_compliant(array('op' => 'not_equals', 'value' => 'x') + $ssh, $absent) && fleet_rule_compliant(array('op' => 'not_in', 'value' => array('x')) + $ssh, $absent),
    'the negative tests accept a missing value');
check(fleet_rule_compliant(array('op' => 'absent') + $ssh, $absent) && !fleet_rule_compliant(array('op' => 'absent') + $ssh, $flag) &&
    fleet_rule_compliant(array('op' => 'present') + $ssh, $flag) && !fleet_rule_compliant(array('op' => 'present') + $ssh, $absent),
    'present and absent (an empty flag element is present)');
check(!fleet_rule_compliant(array('op' => 'min', 'value' => 1) + $ssh, $absent), 'min on a missing value is a violation');

/* ---- Lock: only a newly introduced violation blocks ---- */
$ok = conf(array('system/ssh/sshdkeyonly' => 'both'));
$bad = conf(array('system/ssh/sshdkeyonly' => 'disabled'));
$audit = array('mode' => 'audit', 'id' => 'OPS-001') + $ssh;
$remediate = array('mode' => 'remediate', 'id' => 'OPS-002') + $ssh;
$broken = array('id' => 'xx') + $ssh;
check(count(fleet_lock_new_violations(array($ssh), $bad, $ok)) === 1, 'a save that breaks a lock rule is refused');
check(fleet_lock_new_violations(array($ssh), $ok, $ok) === array(), 'a compliant save passes');
check(fleet_lock_new_violations(array($ssh), $bad, $bad) === array(), 'a box that already breaks the rule can still be saved');
check(fleet_lock_new_violations(array($ssh), $ok, $bad) === array(), 'fixing a violation is always allowed');
check(fleet_lock_new_violations(array($audit, $remediate, $broken), $bad, $ok) === array(), 'audit and remediate rules, and invalid rules, never block');
$two = array($ssh, array('id' => 'SEC-013', 'path' => 'system/webgui/protocol', 'op' => 'equals', 'value' => 'https') + $ssh);
$v = fleet_lock_new_violations($two, conf(array('system/ssh/sshdkeyonly' => 'x', 'system/webgui/protocol' => 'http')),
    conf(array('system/ssh/sshdkeyonly' => 'both', 'system/webgui/protocol' => 'https')));
check(array_column($v, 'id') === array('SEC-012', 'SEC-013'), 'every newly broken lock rule is reported');
$msg = fleet_lock_message($v, 'https://hq.example.net:8443');
check(strpos($msg, 'Blocked by Fleet policy SEC-012: SSH password login is not allowed; SEC-013') === 0 && strpos($msg, 'hq.example.net') !== false &&
    strpos($msg, 'break-glass') !== false, 'the refusal names the rules, the controller and the way out');

/* ---- break-glass ---- */
$now = 1760000000;
$bg = fleet_breakglass_record(4, '  Replacing the WAN uplink  ', $now, 'console (admin)');
check($bg === array('since' => $now, 'until' => $now + 4 * 3600, 'reason' => 'Replacing the WAN uplink', 'by' => 'console (admin)'), 'a break-glass record');
check(fleet_breakglass_active($bg, $now + 3599 * 4) && !fleet_breakglass_active($bg, $now + 4 * 3600) && !fleet_breakglass_active(null, $now),
    'break-glass ends at its time');
foreach (array(array(0, 'reason'), array(25, 'reason'), array(2, 'no'), array(2, str_repeat('x', 201)), array(2, "bad\nreason")) as list($h, $r)) {
	check(invalid_of(function () use ($h, $r, $now) { fleet_breakglass_record($h, $r, $now, 'x'); }) !== null, "refused break-glass {$h} h '{$r}'");
}

/* ---- commit-confirm ---- */
$id = 'c-0123456789abcdef';
check(fleet_confirm_valid_id($id) && !fleet_confirm_valid_id('c-short') && !fleet_confirm_valid_id('../c-0123456789abcdef') && !fleet_confirm_valid_id(null),
    'confirm ids');
$rec = fleet_confirm_record($id, 120, 'Move the LAN', $now, 'fleet-controller');
check($rec === array('id' => $id, 'label' => 'Move the LAN', 'started' => $now, 'deadline' => $now + 120, 'by' => 'fleet-controller'), 'a pending record');
check(!fleet_confirm_due($rec, $now + 119) && fleet_confirm_due($rec, $now + 120), 'due at the deadline');
foreach (array(array('c-bad', 120, ''), array($id, 29, ''), array($id, 3601, ''), array($id, 60, str_repeat('x', 121)), array($id, 60, "a\nb")) as list($i, $s, $l)) {
	check(invalid_of(function () use ($i, $s, $l, $now) { fleet_confirm_record($i, $s, $l, $now, 'x'); }) !== null, "refused confirm {$i} {$s}");
}
check(fleet_confirm_paths($id) === array('xml' => '/cf/conf/fleet/confirm/' . $id . '.xml', 'json' => '/cf/conf/fleet/confirm/' . $id . '.json'), 'snapshot paths');

/* ---- activity and banner ---- */
$act = fleet_activity_record(array('text' => 'Updating, install', 'step' => 4, 'steps' => 6, 'by' => 'admin@HQ', 'seconds' => 600), $now);
check($act === array('text' => 'Updating, install', 'step' => 4, 'steps' => 6, 'by' => 'admin@HQ', 'until' => $now + 600), 'an activity record');
check(fleet_activity_record(array('text' => 'x'), $now)['until'] === $now + 300, 'five minutes by default');
foreach (array(array('text' => ''), array('text' => 'x', 'seconds' => 5), array('text' => 'x', 'step' => 100), array('text' => 'x', 'extra' => 1),
    array('text' => "a\nb"), array('text' => 'x', 'by' => "a\nb")) as $badbody) {
	check(invalid_of(function () use ($badbody, $now) { fleet_activity_record($badbody, $now); }) !== null, 'refused activity ' . json_encode($badbody));
}
$m = array('controller' => array('url' => 'https://hq.example.net:8443'));
$html = fleet_banner_html($m, null, null, 0, $now);
check(strpos($html, 'Managed by FreeSense Fleet') !== false && strpos($html, 'locked rule') === false && strpos($html, 'Fleet is working') === false &&
    strpos($html, 'Break-glass') === false && strpos($html, 'role="status"') !== false, 'the plain banner');
$html = fleet_banner_html($m, $act, $bg + array('until' => $now + 100), 2, $now);
check(strpos($html, '2 locked rules') !== false && strpos($html, 'Updating, install, step 4 of 6') !== false && strpos($html, 'started by admin@HQ') !== false &&
    strpos($html, 'Break-glass is on') !== false && strpos($html, 'Replacing the WAN uplink') !== false, 'the banner shows work, locks and break-glass');
check(strpos(fleet_banner_html($m, $act, null, 1, $now + 601), 'Fleet is working') === false && strpos(fleet_banner_html($m, null, null, 1, $now), '1 locked rule.') !== false,
    'an expired activity disappears; one rule is singular');
$evil = fleet_banner_html(array('controller' => array('url' => '<script>alert(1)</script>')), array('text' => '<b>x</b>', 'by' => '"><img src=x>', 'until' => $now + 5), array('until' => $now + 5, 'reason' => '<i>r</i>'), 0, $now);
check(strpos($evil, '<script>') === false && strpos($evil, '<img') === false && strpos($evil, '<b>x') === false && strpos($evil, '<i>r') === false, 'the banner escapes everything');

/* ---- wiring ---- */
$cfg = file_get_contents("{$root}/src/etc/inc/config.lib.inc");
$hook = strpos($cfg, 'fleet_lock_refusal()');
check($hook !== false && strpos($cfg, "'/cf/conf/fleet/policy-bundle.json'") !== false && $hook < strpos($cfg, 'backup_config();', $hook - 2000) + 5000 &&
    $hook < strpos($cfg, "config_set_path('revision'"), 'write_config checks Lock before it saves');
check(strpos($cfg, "config_read_file(true, false);\n\t\t\treturn false;") !== false, 'a refused save also drops the change from memory');
check(strpos(file_get_contents("{$root}/src/usr/local/www/head.inc"), 'fleet_banner_print();') !== false, 'the WebUI shows the banner');
$api = file_get_contents("{$root}/src/usr/local/www/api/index.php");
check(strpos($api, "'blocked_by_policy'") !== false && strpos($api, "require_once('fleet_guard.inc');") !== false, 'the API answers 409 blocked_by_policy');
$boot = file_get_contents("{$root}/src/etc/rc.bootup");
check(strpos($boot, 'fleet_confirm_boot();') !== false && strpos($boot, 'fleet_confirm_boot();') < strpos($boot, 'fleet_boot();'), 'unconfirmed changes are reverted before the tunnel comes up');
foreach (array("case 'breakglass'", 'fleet_breakglass_set(', 'fleet_breakglass_clear()') as $needle) {
	check(strpos(file_get_contents("{$root}/src/usr/local/sbin/freesense-join"), $needle) !== false, "the CLI has {$needle}");
}
check(strpos(file_get_contents("{$root}/src/usr/local/sbin/freesense-fleet-watchdog"), 'fleet_confirm_watch($id)') !== false, 'the watchdog script');
$agent = file_get_contents("{$root}/src/etc/inc/fleet_agent.inc");
check(substr_count($agent, 'fleet_lock_bypass(true);') === 4 && substr_count($agent, 'fleet_lock_bypass(false);') === 3 && substr_count($agent, 'fleet_lock_bypass($was);') === 1, 'joining, leaving and access updates are not blocked by Lock');
require_once('restapi/routes_v1.inc');
$have = array();
foreach (restapi_routes_management() as $r) {
	$have["{$r['method']} {$r['path']}"] = $r;
}
foreach (array('GET /v1/management/commit-confirm', 'POST /v1/management/commit-confirm', 'POST /v1/management/commit-confirm/{id}/confirm',
    'DELETE /v1/management/commit-confirm/{id}', 'PUT /v1/management/activity', 'DELETE /v1/management/activity') as $key) {
	check(isset($have[$key]) && function_exists($have[$key]['handler']), "route {$key}");
	check($have[$key]['write'] === ($key[0] !== 'G'), "{$key} write flag");
}
check(!isset($have['DELETE /v1/management/breakglass']) && !isset($have['POST /v1/management/breakglass']), 'break-glass cannot be switched on through the API');


/* ---- remediate ---- */
$rm = function (array $r) { return $r + array('id' => 'OPS-001', 'title' => 't', 'mode' => 'remediate'); };
check(fleet_remediate_plan($rm(array('path' => 'system/timezone', 'op' => 'equals', 'value' => 'Europe/Copenhagen')), 'Etc/UTC') === array('set', 'Europe/Copenhagen'),
    'remediate sets the value an equals rule wants');
check(fleet_remediate_plan($rm(array('path' => 'system/timezone', 'op' => 'equals', 'value' => 'Europe/Copenhagen')), 'Europe/Copenhagen') === array('none'), 'a compliant rule needs nothing');
check(fleet_remediate_plan($rm(array('path' => 'system/timezone', 'op' => 'equals', 'value' => 'Mars/Base')), 'Etc/UTC') === null, 'an unknown time zone is never written');
check(fleet_remediate_plan($rm(array('path' => 'system/timeservers', 'op' => 'regex', 'value' => '.', 'fix' => 'pool.ntp.org')), null) === array('set', 'pool.ntp.org') &&
    fleet_remediate_plan($rm(array('path' => 'system/timeservers', 'op' => 'regex', 'value' => '.')), null) === null, '"set and not empty" needs a fix value');
check(fleet_remediate_plan($rm(array('path' => 'system/webgui/noantilockout', 'op' => 'absent')), '') === array('unset'), 'absent rules remove the setting');
check(fleet_remediate_plan($rm(array('path' => 'system/ssh/port', 'op' => 'equals', 'value' => '22')), '2222') === null &&
    fleet_remediate_plan($rm(array('path' => 'system/webgui/protocol', 'op' => 'equals', 'value' => 'https')), 'http') === null, 'paths off the allow-list are never changed');
check(fleet_remediate_plan(array('mode' => 'lock') + $rm(array('path' => 'system/timezone', 'op' => 'equals', 'value' => 'Etc/UTC')), 'Europe/Oslo') === null,
    'only rules in mode remediate are fixed');
check(fleet_remediate_plan($rm(array('path' => 'system/domain', 'op' => 'equals', 'value' => 'bad domain!')), 'x.net') === null &&
    fleet_remediate_plan($rm(array('path' => 'system/hostname', 'op' => 'equals', 'value' => 'fw-01')), 'old') === array('set', 'fw-01'), 'names are checked before they are written');
check(isset($have['POST /v1/management/remediate']) && $have['POST /v1/management/remediate']['write'] && function_exists($have['POST /v1/management/remediate']['handler']),
    'route POST /v1/management/remediate (write)');
echo "FleetGuardSmokeTest: OK\n";
