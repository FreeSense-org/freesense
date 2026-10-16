<?php
/*
 * fleet_accounts.inc
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
 * Users and groups a Fleet controller manages on this box.
 *
 * The controller sends the whole set of Fleet users and groups this box
 * should have (format "fleet-users-v1"); the box brings its Fleet-owned
 * accounts (marked <fleet>1</fleet> in config.xml) in line with it:
 *  - local accounts (admin, root, fleet-controller and anything made on the
 *    box) are never changed; a Fleet name that clashes with one is
 *    skipped and reported (E441);
 *  - privileges this version does not know are left out and listed
 *    (E442), so a newer controller can sync an older box;
 *  - passwords travel only as bcrypt hashes; SSH keys as text.
 * Functions marked "pure" touch neither the system nor config.xml.
 */

require_once('fleet_node.inc');

define('FLEET_ACCOUNTS_FORMAT', 'fleet-users-v1');
define('FLEET_ACCOUNTS_RESERVED_USERS', array('root', 'admin', 'fleet-controller', 'fleet-hasync', 'nobody', 'toor', 'daemon', 'operator', 'www', 'sshd', 'uucp', 'unbound', 'dhcpd'));
define('FLEET_ACCOUNTS_RESERVED_GROUPS', array('admins', 'all', 'wheel', 'nobody', 'nogroup', 'operator'));
define('FLEET_ACCOUNTS_MAX', 500);

/* pure: an account name the box accepts (users and groups). */
function fleet_accounts_valid_name($name): bool {
	return is_string($name) && (bool)preg_match('/^[a-z_][a-z0-9._-]{0,31}$/iD', $name);
}

/* pure: a bcrypt hash. */
function fleet_accounts_valid_hash($hash): bool {
	return is_string($hash) && (bool)preg_match('~^\$2[aby]\$\d\d\$[./A-Za-z0-9]{53}$~D', $hash);
}

/* pure: SSH public key lines, or null when one is not a key. */
function fleet_accounts_keys($text): ?array {
	if (!is_string($text) || (strlen($text) > 32768)) {
		return null;
	}
	$keys = array();
	foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
		$line = trim($line);
		if ($line === '') {
			continue;
		}
		if (!preg_match('~^(ssh-(ed25519|rsa)|ecdsa-sha2-nistp(256|384|521)|sk-[a-z0-9@.-]+) [A-Za-z0-9+/=]{20,}( [^\x00-\x1f]{0,200})?$~D', $line)) {
			return null;
		}
		$keys[] = $line;
	}
	return $keys;
}

/*
 * pure: check what the controller sent. Returns the cleaned set
 * {users: name => user, groups: name => group} or throws
 * InvalidArgumentException "<code>: <message>".
 */
function fleet_accounts_desired(array $body): array {
	if (($body['format'] ?? '') !== FLEET_ACCOUNTS_FORMAT) {
		throw new InvalidArgumentException('unsupported_format: FLEET-E440: this box understands ' . FLEET_ACCOUNTS_FORMAT . ' only');
	}
	$groups = array();
	foreach ((array)($body['groups'] ?? array()) as $g) {
		if (!is_array($g) || !fleet_accounts_valid_name($g['name'] ?? null) || !is_array($g['privs'] ?? null)) {
			throw new InvalidArgumentException('invalid_group: a group needs a valid name and a privs list');
		}
		$groups[strtolower($g['name'])] = array('name' => $g['name'], 'description' => mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', ' ', (string)($g['description'] ?? '')), 0, 200),
		    'privs' => array_values(array_unique(array_filter($g['privs'], 'is_string'))));
	}
	$users = array();
	foreach ((array)($body['users'] ?? array()) as $u) {
		if (!is_array($u) || !fleet_accounts_valid_name($u['name'] ?? null) || !fleet_accounts_valid_hash($u['bcrypt_hash'] ?? null)) {
			throw new InvalidArgumentException('invalid_user: a user needs a valid name and a bcrypt hash');
		}
		$keys = fleet_accounts_keys($u['ssh_keys'] ?? '');
		if ($keys === null) {
			throw new InvalidArgumentException("invalid_user: {$u['name']}: the SSH keys are not public keys, one per line");
		}
		$expires = (string)($u['expires'] ?? '');
		if (($expires !== '') && !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $expires)) {
			throw new InvalidArgumentException("invalid_user: {$u['name']}: expires is YYYY-MM-DD or empty");
		}
		$member_of = array();
		foreach ((array)($u['groups'] ?? array()) as $gn) {
			if (is_string($gn) && isset($groups[strtolower($gn)])) {
				$member_of[] = $groups[strtolower($gn)]['name'];
			}
		}
		$users[strtolower($u['name'])] = array('name' => $u['name'], 'fullname' => mb_substr(preg_replace('/[\x00-\x1f\x7f]/u', ' ', (string)($u['fullname'] ?? '')), 0, 100),
		    'bcrypt_hash' => $u['bcrypt_hash'], 'ssh_keys' => $keys, 'expires' => $expires, 'disabled' => !empty($u['disabled']), 'groups' => $member_of);
	}
	if ((count($users) > FLEET_ACCOUNTS_MAX) || (count($groups) > FLEET_ACCOUNTS_MAX)) {
		throw new InvalidArgumentException('too_many: at most ' . FLEET_ACCOUNTS_MAX . ' users and groups');
	}
	return array('users' => $users, 'groups' => $groups);
}

/* pure: the config.xml form of a Fleet user (without uid). */
function fleet_accounts_user_entry(array $u): array {
	$e = array('name' => $u['name'], 'descr' => $u['fullname'], 'scope' => 'user', 'bcrypt-hash' => $u['bcrypt_hash'],
	    'expires' => ($u['expires'] === '') ? '' : date('m/d/Y', strtotime($u['expires'] . ' 00:00:00 UTC')),
	    'authorizedkeys' => empty($u['ssh_keys']) ? '' : base64_encode(implode("\n", $u['ssh_keys'])), 'ipsecpsk' => '', 'fleet' => '1');
	if ($u['disabled']) {
		$e['disabled'] = '';
	}
	return $e;
}

/*
 * pure: what bringing the box in line takes. $users/$groups: the box's
 * config entries; $known: the privileges this version knows. Returns
 * {users: name => action, groups: name => action, clashes: [names],
 * skipped_privs: group => [privs]}; actions: create | update | same |
 * delete.
 */
function fleet_accounts_plan(array $desired, array $users, array $groups, array $known): array {
	$out = array('users' => array(), 'groups' => array(), 'clashes' => array(), 'skipped_privs' => array());
	$have_u = array();
	foreach ($users as $u) {
		if (is_array($u) && isset($u['name'])) {
			$have_u[strtolower($u['name'])] = $u;
		}
	}
	$have_g = array();
	foreach ($groups as $g) {
		if (is_array($g) && isset($g['name'])) {
			$have_g[strtolower($g['name'])] = $g;
		}
	}
	foreach ($desired['groups'] as $key => $g) {
		$cur = $have_g[$key] ?? null;
		if (in_array($key, FLEET_ACCOUNTS_RESERVED_GROUPS, true) || (($cur !== null) && empty($cur['fleet']))) {
			$out['clashes'][] = 'group ' . $g['name'];
			continue;
		}
		$privs = array_values(array_intersect($g['privs'], $known));
		$skip = array_values(array_diff($g['privs'], $known));
		if (!empty($skip)) {
			$out['skipped_privs'][$g['name']] = $skip;
		}
		if ($cur === null) {
			$out['groups'][$g['name']] = 'create';
		} else {
			$same = ((string)($cur['description'] ?? '') === $g['description']) && (array_values((array)($cur['priv'] ?? array())) === $privs);
			$out['groups'][$g['name']] = $same ? 'same' : 'update';
		}
	}
	foreach ($have_g as $key => $g) {
		if (!empty($g['fleet']) && !isset($desired['groups'][$key])) {
			$out['groups'][$g['name']] = 'delete';
		}
	}
	foreach ($desired['users'] as $key => $u) {
		$cur = $have_u[$key] ?? null;
		if (in_array($key, FLEET_ACCOUNTS_RESERVED_USERS, true) || (($cur !== null) && empty($cur['fleet']))) {
			$out['clashes'][] = 'user ' . $u['name'];
			continue;
		}
		if ($cur === null) {
			$out['users'][$u['name']] = 'create';
		} else {
			$want = fleet_accounts_user_entry($u);
			$same = true;
			foreach ($want as $k => $v) {
				$same = $same && ((string)($cur[$k] ?? '') === (string)$v);
			}
			$same = $same && (isset($cur['disabled']) === isset($want['disabled']));
			$out['users'][$u['name']] = $same ? 'same' : 'update';
		}
	}
	foreach ($have_u as $key => $u) {
		if (!empty($u['fleet']) && !isset($desired['users'][$key])) {
			$out['users'][$u['name']] = 'delete';
		}
	}
	return $out;
}

/* The privileges this version knows: name => description. */
function fleet_accounts_catalog(): array {
	require_once('priv.inc');
	global $priv_list;
	$out = array();
	foreach ((array)$priv_list as $name => $p) {
		$out[$name] = (string)($p['name'] ?? $name);
	}
	ksort($out);
	return $out;
}

/*
 * Bring this box's Fleet accounts in line with $body. With $dry_run only
 * the plan is returned. Group membership: a Fleet group holds the Fleet
 * users that list it, plus any local users a local admin added to it.
 */
function fleet_accounts_apply(array $body, bool $dry_run): array {
	$desired = fleet_accounts_desired($body);
	$users = config_get_path('system/user', array());
	$groups = config_get_path('system/group', array());
	$plan = fleet_accounts_plan($desired, $users, $groups, array_keys(fleet_accounts_catalog()));
	if ($dry_run) {
		return $plan;
	}
	require_once('auth.inc');
	$changed_users = array();
	$removed_users = array();
	$removed_groups = array();
	/* Users first (uids are needed for membership). */
	foreach ($plan['users'] as $name => $action) {
		$key = strtolower($name);
		$idx = null;
		foreach ($users as $i => $u) {
			if (is_array($u) && (strtolower((string)($u['name'] ?? '')) === $key)) {
				$idx = $i;
			}
		}
		if ($action === 'create') {
			$entry = fleet_accounts_user_entry($desired['users'][$key]);
			$entry['uid'] = (string)(int)config_get_path('system/nextuid');
			config_set_path('system/nextuid', (string)((int)$entry['uid'] + 1));
			$entry['priv'] = array();
			$users[] = $entry;
			$changed_users[] = $entry['name'];
		} elseif ($action === 'update') {
			$entry = fleet_accounts_user_entry($desired['users'][$key]);
			unset($users[$idx]['disabled']);
			$users[$idx] = array_merge($users[$idx], $entry);
			$changed_users[] = $name;
		} elseif ($action === 'delete') {
			$removed_users[] = $users[$idx];
			unset($users[$idx]);
		}
	}
	$users = array_values($users);
	$uid_of = array();
	foreach ($users as $u) {
		$uid_of[strtolower((string)$u['name'])] = (string)$u['uid'];
	}
	$fleet_uids = array();
	foreach ($users as $u) {
		if (!empty($u['fleet'])) {
			$fleet_uids[] = (string)$u['uid'];
		}
	}
	foreach ($plan['groups'] as $name => $action) {
		$key = strtolower($name);
		$idx = null;
		foreach ($groups as $i => $g) {
			if (is_array($g) && (strtolower((string)($g['name'] ?? '')) === $key)) {
				$idx = $i;
			}
		}
		if ($action === 'delete') {
			$removed_groups[] = $groups[$idx];
			unset($groups[$idx]);
			continue;
		}
		$d = $desired['groups'][$key];
		$members = array();
		foreach ($desired['users'] as $ukey => $u) {
			if (isset($plan['users'][$u['name']]) && in_array($d['name'], $u['groups'], true) && isset($uid_of[$ukey])) {
				$members[] = $uid_of[$ukey];
			}
		}
		$entry = array('name' => $d['name'], 'description' => $d['description'], 'scope' => 'local',
		    'priv' => array_values(array_diff($d['privs'], $plan['skipped_privs'][$d['name']] ?? array())), 'fleet' => '1');
		if ($action === 'create') {
			$entry['gid'] = (string)(int)config_get_path('system/nextgid');
			config_set_path('system/nextgid', (string)((int)$entry['gid'] + 1));
			$entry['member'] = $members;
			$groups[] = $entry;
		} else {
			/* Keep the local users a local admin added. */
			$local = array_values(array_diff((array)($groups[$idx]['member'] ?? array()), $fleet_uids));
			$entry['member'] = array_values(array_unique(array_merge($local, $members)));
			$groups[$idx] = array_merge($groups[$idx], $entry);
		}
	}
	/* Membership can change without the group itself changing. */
	foreach ($groups as $i => $g) {
		if (empty($g['fleet']) || !isset($desired['groups'][strtolower($g['name'])]) || (($plan['groups'][$g['name']] ?? '') !== 'same')) {
			continue;
		}
		$members = array();
		foreach ($desired['users'] as $ukey => $u) {
			if (isset($plan['users'][$u['name']]) && in_array($g['name'], $u['groups'], true) && isset($uid_of[$ukey])) {
				$members[] = $uid_of[$ukey];
			}
		}
		$local = array_values(array_diff((array)($g['member'] ?? array()), $fleet_uids));
		$want = array_values(array_unique(array_merge($local, $members)));
		if ($want !== array_values((array)($g['member'] ?? array()))) {
			$groups[$i]['member'] = $want;
			$plan['groups'][$g['name']] = 'update';
		}
	}
	$groups = array_values($groups);
	$touched = array_filter($plan['users'], function ($a) { return $a !== 'same'; }) + array_filter($plan['groups'], function ($a) { return $a !== 'same'; });
	if (empty($touched)) {
		return $plan;
	}
	config_set_path('system/user', $users);
	config_set_path('system/group', $groups);
	$was = fleet_lock_bypass();
	fleet_lock_bypass(true);
	write_config('Fleet: users and groups synced');
	fleet_lock_bypass($was);
	foreach ($removed_users as $u) {
		local_user_del($u);
	}
	foreach ($removed_groups as $g) {
		local_group_del($g);
	}
	foreach ($groups as $g) {
		if (!empty($g['fleet'])) {
			local_group_set($g);
		}
	}
	foreach ($users as $u) {
		if (in_array($u['name'], $changed_users, true)) {
			local_user_set($u);
		}
	}
	logger(LOG_NOTICE, sprintf('Fleet: users and groups synced (%d changes).', count($touched)));
	return $plan;
}

/* What the controller reads before a sync. */
function fleet_accounts_state(): array {
	$fleet_users = array();
	$local_users = array();
	foreach (config_get_path('system/user', array()) as $u) {
		if (!is_array($u)) {
			continue;
		}
		if (!empty($u['fleet'])) {
			$fleet_users[] = (string)$u['name'];
		} else {
			$local_users[] = (string)$u['name'];
		}
	}
	$fleet_groups = array();
	$local_groups = array();
	foreach (config_get_path('system/group', array()) as $g) {
		if (is_array($g)) {
			if (!empty($g['fleet'])) {
				$fleet_groups[] = (string)$g['name'];
			} else {
				$local_groups[] = (string)$g['name'];
			}
		}
	}
	return array('format' => FLEET_ACCOUNTS_FORMAT, 'privileges' => fleet_accounts_catalog(), 'fleet_users' => $fleet_users, 'fleet_groups' => $fleet_groups,
	    'local_users' => $local_users, 'local_groups' => $local_groups);
}
