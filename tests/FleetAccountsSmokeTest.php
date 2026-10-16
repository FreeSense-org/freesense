<?php
/* Standalone CI regression test for Fleet users and groups on a box (fleet_accounts.inc); run with `php tests/FleetAccountsSmokeTest.php`. */

$root = dirname(__DIR__);
set_include_path(get_include_path() . PATH_SEPARATOR . realpath($root . '/src/etc/inc'));
if (!function_exists('gettext')) {
	function gettext($t) { return $t; }
}
require_once('fleet_accounts.inc');

function check($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}
function code_of(callable $fn) {
	try {
		$fn();
	} catch (InvalidArgumentException $e) {
		return strtok($e->getMessage(), ':');
	}
	return null;
}

$hash = '$2y$10$' . str_repeat('a', 53);
$key = 'ssh-ed25519 ' . str_repeat('A', 68) . ' ops@laptop';
$body = array('format' => 'fleet-users-v1',
    'groups' => array(array('name' => 'shop-support', 'description' => 'Status only', 'privs' => array('page-dashboard-all', 'page-new-in-1-2'))),
    'users' => array(
	array('name' => 'anna', 'fullname' => 'Anna', 'bcrypt_hash' => $hash, 'ssh_keys' => $key, 'expires' => '2027-01-31', 'groups' => array('shop-support', 'nope')),
	array('name' => 'admin', 'bcrypt_hash' => $hash),
	array('name' => 'bob', 'bcrypt_hash' => $hash, 'disabled' => true)));
$d = fleet_accounts_desired($body);
check($d['users']['anna']['groups'] === array('shop-support') && $d['users']['anna']['ssh_keys'] === array($key), 'users keep only groups that are sent');
check(code_of(function () use ($body) { fleet_accounts_desired(array('format' => 'fleet-users-v2') + $body); }) === 'unsupported_format', 'an unknown format is refused (E440)');
check(code_of(function () use ($body, $hash) { $b = $body; $b['users'][] = array('name' => 'x', 'bcrypt_hash' => 'secret'); fleet_accounts_desired($b); }) === 'invalid_user', 'a password is never accepted, only a bcrypt hash');
check(code_of(function () use ($body, $hash) { $b = $body; $b['users'][] = array('name' => 'x', 'bcrypt_hash' => $hash, 'ssh_keys' => 'not a key'); fleet_accounts_desired($b); }) === 'invalid_user', 'SSH keys must be public keys');
check(code_of(function () use ($body, $hash) { $b = $body; $b['users'][] = array('name' => 'bad name', 'bcrypt_hash' => $hash); fleet_accounts_desired($b); }) === 'invalid_user', 'names are checked');

$box_users = array(
	array('name' => 'admin', 'uid' => '0', 'scope' => 'system'),
	array('name' => 'bob', 'uid' => '2001', 'scope' => 'user'),
	array('name' => 'carl', 'uid' => '2002', 'scope' => 'user', 'fleet' => '1'));
$box_groups = array(array('name' => 'admins', 'gid' => '1999', 'scope' => 'system', 'priv' => array('page-all')),
    array('name' => 'old-fleet', 'gid' => '2000', 'fleet' => '1'));
$plan = fleet_accounts_plan($d, $box_users, $box_groups, array('page-dashboard-all'));
check($plan['users'] === array('anna' => 'create', 'carl' => 'delete'), 'Fleet users are created and removed; local ones are left alone');
check(in_array('user admin', $plan['clashes'], true) && in_array('user bob', $plan['clashes'], true), 'admin and a local user with the same name are clashes (E441)');
check($plan['groups'] === array('shop-support' => 'create', 'old-fleet' => 'delete') && $plan['skipped_privs'] === array('shop-support' => array('page-new-in-1-2')),
    'a privilege this version does not know is left out and listed (E442)');
$bad = fleet_accounts_desired(array('format' => 'fleet-users-v1', 'groups' => array(array('name' => 'admins', 'privs' => array())), 'users' => array()));
check(fleet_accounts_plan($bad, $box_users, $box_groups, array())['clashes'] === array('group admins'), 'the admins group can never be taken over');
$e = fleet_accounts_user_entry($d['users']['anna']);
check($e['expires'] === '01/31/2027' && $e['fleet'] === '1' && base64_decode($e['authorizedkeys']) === $key && !isset($e['disabled']) && !isset($e['password']), 'the config entry');
check(isset(fleet_accounts_user_entry($d['users']['bob'])['disabled']), 'disabled users stay disabled');
$same = fleet_accounts_plan(fleet_accounts_desired(array('format' => 'fleet-users-v1', 'users' => array(array('name' => 'carl', 'bcrypt_hash' => $hash)))),
    array(array('uid' => '2002') + fleet_accounts_user_entry(fleet_accounts_desired(array('format' => 'fleet-users-v1', 'users' => array(array('name' => 'carl', 'bcrypt_hash' => $hash))))['users']['carl'])), array(), array());
check($same['users'] === array('carl' => 'same'), 'an unchanged user is left alone');

echo "FleetAccountsSmokeTest: OK\n";
