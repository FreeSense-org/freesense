<?php
/* Standalone CI regression test; run with `php tests/ConfigUpgradeSmokeTest.php`. */

function dump_rrd_to_xml() {}
function read_altq_config() {}
function console_configure() {}
function get_specialnet() {}
function add_filter_rules() {}

$test_config = array();
$account_reset_count = 0;

function config_get_path($path, $default = null) {
	global $test_config;
	return $test_config[$path] ?? $default;
}

function config_set_path($path, $value) {
	global $test_config;
	$test_config[$path] = $value;
	return $value;
}

function local_reset_accounts() {
	global $account_reset_count;
	$account_reset_count++;
}

function map_page_privname($page) {
	return $page === 'legacy-page' ? 'page-legacy' : false;
}

function check_upgrade($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

require_once(__DIR__ . '/../src/etc/inc/upgrade_config.inc');

$test_config['system/user'] = array(
	array('name' => 'legacy', 'priv' => array(
		array('id' => 'hasshell'),
		array('id' => 'copyfiles'),
		array('id' => 'unknown'),
	)),
	array('name' => 'mixed', 'priv' => array(
		'user-shell-access',
		'page-all',
		array('id' => 'hasshell'),
		null,
		17,
	)),
	array('name' => 'scalar', 'priv' => 'user-copy-files'),
	array('name' => 'missing'),
	'malformed-user',
);
$test_config['system/group'] = array(
	array('name' => 'legacy', 'pages' => array('legacy-page', null)),
	array('name' => 'mixed', 'pages' => 'legacy-page',
	    'priv' => array('page-existing', 'page-legacy')),
	array('name' => 'current', 'priv' => 'page-all'),
	array('name' => 'missing'),
	'malformed-group',
);

upgrade_049_to_050();

$users = $test_config['system/user'];
check_upgrade($users[0]['priv'] === array('user-shell-access', 'user-copy-files'),
    'legacy user privileges were not mapped');
check_upgrade($users[1]['priv'] === array('user-shell-access', 'page-all'),
    'mixed modern privileges were not preserved and deduplicated');
check_upgrade($users[2]['priv'] === array('user-copy-files'),
    'scalar modern user privilege was not preserved');
check_upgrade(!isset($users[3]['priv']), 'missing user privileges were invented');
check_upgrade(count($users) === 4, 'malformed user entry was not discarded safely');

$groups = $test_config['system/group'];
check_upgrade($groups[0]['priv'] === array('page-legacy') && !isset($groups[0]['pages']),
    'legacy group pages were not mapped');
check_upgrade($groups[1]['priv'] === array('page-existing', 'page-legacy') &&
    !isset($groups[1]['pages']), 'mixed group privileges were not preserved');
check_upgrade($groups[2]['priv'] === array('page-all'),
    'scalar modern group privilege was not preserved');
check_upgrade(!isset($groups[3]['priv']) && !isset($groups[3]['pages']),
    'missing group privileges were invented');
check_upgrade(count($groups) === 4, 'malformed group entry was not discarded safely');
check_upgrade($account_reset_count === 1, 'local accounts were not synchronized once');

echo "Configuration upgrade compatibility: valid\n";
