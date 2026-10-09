<?php
/*
 * restapi.inc
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
 * REST API settings, API tokens, authentication and authorization.
 *
 * Config: <restapi> with enable, allowednetworks, allowhttp and an
 * <apitoken> list (id, username, descr, hash, created, expires, readonly,
 * scopes). "scopes" is a space-separated list such as "status:read
 * firewall.aliases:write"; when present the key is limited to it.
 * Only the SHA-256 of a token secret is stored; the full token is shown
 * once when it is created.
 *
 * A request runs as the token's local user and is allowed when that user
 * may open the GUI page the endpoint mirrors, so the existing user and
 * group privileges apply unchanged.
 */

require_once('globals.inc');
require_once('config.lib.inc');
require_once('util.inc');
require_once('auth.inc');
require_once('priv.inc');
require_once('auth_func.inc');
require_once('restapi/framework.inc');
require_once('restapi_log.inc');

define('RESTAPI_LAST_USED', '/var/db/restapi_last_used.json');
/* API level reported by GET /api/v1/meta (see restapi_h_meta). */
define('RESTAPI_LEVEL', 8);

/* Optional features reported by GET /api/v1/meta. */
function restapi_capabilities() {
	return array('session', 'batch', 'log-cursors', 'jobs', 'me', 'schemas', 'log-webui', 'meta', 'history', 'top-talkers', 'dns-resolver');
}
define('RESTAPI_MAX_TOKENS_PER_USER', 20);
/*
 * Per-user API access is the privilege of the "My API Keys" page
 * (page-system-restapi-keys, "WebCfg - System: REST API access"). A key
 * only works while its owner holds it, directly or through a group.
 */
define('RESTAPI_ACCESS_PAGE', 'system_restapi_keys.php');

function restapi_user_has_access($user) {
	return is_array($user) && restapi_user_may_open($user, RESTAPI_ACCESS_PAGE);
}

function restapi_settings() {
	$s = config_get_path('restapi', []);
	$s = is_array($s) ? $s : [];
	unset($s['apitoken'], $s['apilistener']);
	foreach (array('enable', 'allowhttp', 'noguiapi') as $flag) {
		$s[$flag] = isset($s[$flag]) && ($s[$flag] !== false);
	}
	$s['allowednetworks'] = (string)($s['allowednetworks'] ?? '');
	/* Served on the WebGUI port unless turned off (then only on the listeners). */
	$s['guiapi'] = !$s['noguiapi'];
	$log = restapi_reqlog_settings($s);
	$s['log_level'] = $log['level'];
	$s['log_days'] = $log['days'];
	$s['log_mb'] = $log['mb'];
	return $s;
}

function restapi_enabled() {
	return config_path_enabled('restapi');
}

function restapi_validate_settings($post) {
	$input_errors = array();
	foreach (restapi_parse_networks($post['allowednetworks'] ?? '') as $net) {
		if (!restapi_valid_network($net)) {
			$input_errors[] = sprintf(gettext('"%s" is not a valid address or network.'), htmlspecialchars($net));
		}
	}
	if (!in_array((string)($post['log_level'] ?? RESTAPI_REQLOG_DEFAULT_LEVEL), RESTAPI_REQLOG_LEVELS, true)) {
		$input_errors[] = gettext('Choose what to log.');
	}
	$days = (string)($post['log_days'] ?? RESTAPI_REQLOG_DEFAULT_DAYS);
	if (!ctype_digit($days) || ((int)$days < 1) || ((int)$days > 365)) {
		$input_errors[] = gettext('Keep the log for 1 to 365 days.');
	}
	$mb = (string)($post['log_mb'] ?? RESTAPI_REQLOG_DEFAULT_MB);
	if (!ctype_digit($mb) || ((int)$mb < 1) || ((int)$mb > 500)) {
		$input_errors[] = gettext('The log size limit must be from 1 to 500 MB.');
	}
	return $input_errors;
}

function restapi_save_settings($post) {
	$input_errors = restapi_validate_settings($post);
	if (!empty($input_errors)) {
		return array('input_errors' => $input_errors);
	}
	$section = config_get_path('restapi', []);
	$section = is_array($section) ? $section : [];
	$was_enabled = !empty($section['enable']);
	$old_networks = (string)($section['allowednetworks'] ?? '');
	foreach (array('enable', 'allowhttp') as $flag) {
		if (!empty($post[$flag])) {
			$section[$flag] = true;
		} else {
			unset($section[$flag]);
		}
	}
	if (empty($post['guiapi'])) {
		$section['noguiapi'] = true;
	} else {
		unset($section['noguiapi']);
	}
	$section['allowednetworks'] = implode(' ', restapi_parse_networks($post['allowednetworks'] ?? ''));
	$section['log_level'] = (string)($post['log_level'] ?? RESTAPI_REQLOG_DEFAULT_LEVEL);
	$section['log_days'] = (int)($post['log_days'] ?? RESTAPI_REQLOG_DEFAULT_DAYS);
	$section['log_mb'] = (int)($post['log_mb'] ?? RESTAPI_REQLOG_DEFAULT_MB);
	config_set_path('restapi', $section);
	restapi_reqlog_configure_cron();
	write_config(gettext('REST API settings saved.'));
	restapi_reqlog_prune();
	/* Listeners stop when the API is turned off, and their firewall rules use the allowed networks. */
	if (!empty(config_get_path('restapi/apilistener', [])) &&
	    (($was_enabled !== !empty($section['enable'])) || ($old_networks !== $section['allowednetworks']))) {
		require_once('restapi_listener.inc');
		restapi_listeners_apply();
	}
	return array('input_errors' => array());
}

/* ------------------------------------------------------------------ */
/* Tokens                                                              */
/* ------------------------------------------------------------------ */

function restapi_tokens() {
	$tokens = config_get_path('restapi/apitoken', []);
	$tokens = is_array($tokens) ? array_values(array_filter($tokens, 'is_array')) : [];
	foreach ($tokens as &$token) {
		$token['readonly'] = isset($token['readonly']) && ($token['readonly'] !== false);
	}
	return $tokens;
}

/* Return array($index, $token) for a token id, or array(null, null). */
function restapi_find_token($id) {
	foreach (config_get_path('restapi/apitoken', []) as $idx => $token) {
		if (is_array($token) && isset($token['id']) && hash_equals((string)$token['id'], (string)$id)) {
			$token['readonly'] = isset($token['readonly']) && ($token['readonly'] !== false);
			return array($idx, $token);
		}
	}
	return array(null, null);
}

/*
 * API tokens belong to local users only. getUserEntry() also returns a
 * stand-in entry (no position) for a name that is not a local user unless
 * the webConfigurator authenticates locally; a key of a deleted user must
 * not resolve to it.
 */
function restapi_local_user($username) {
	$entry = getUserEntry($username);
	$user = $entry['item'] ?? null;
	if (!isset($entry['idx']) || !is_array($user) || (($user['name'] ?? null) !== $username)) {
		return null;
	}
	/* A disabled user is stored with an empty <disabled/> element (read back as ""), like the GUI tests it: isset(). */
	return (isset($user['disabled']) && ($user['disabled'] !== false)) ? null : $user;
}

/*
 * Create a token. Returns array('input_errors' => [...]) or
 * array('input_errors' => [], 'token' => full token string, 'id' => id).
 */
function restapi_create_token($post) {
	$input_errors = array();
	$username = (string)($post['username'] ?? '');
	$user = restapi_local_user($username);
	if ($user === null) {
		$input_errors[] = gettext('Choose an enabled local user to own the API key.');
	} elseif (!restapi_user_has_access($user)) {
		$input_errors[] = sprintf(gettext('User %s does not have the "WebCfg - System: REST API access" privilege. ' .
		    'Grant it to the user or one of its groups in System > User Manager first.'), htmlspecialchars($username));
	}
	$descr = trim((string)($post['descr'] ?? ''));
	if ($descr === '' || strlen($descr) > 120) {
		$input_errors[] = gettext('A description of up to 120 characters is required.');
	}
	$days = (string)($post['expires_days'] ?? '');
	if ($days !== '' && (!ctype_digit($days) || (int)$days < 1 || (int)$days > 3650)) {
		$input_errors[] = gettext('Expiry must be between 1 and 3650 days, or empty for no expiry.');
	}
	$count = count(array_filter(restapi_tokens(), function ($t) use ($username) {
		return ($t['username'] ?? '') === $username;
	}));
	if ($count >= RESTAPI_MAX_TOKENS_PER_USER) {
		$input_errors[] = sprintf(gettext('A user may have at most %d API keys, revoked keys included; delete keys that are no longer needed.'),
		    RESTAPI_MAX_TOKENS_PER_USER);
	}
	if (!empty($input_errors)) {
		return array('input_errors' => $input_errors);
	}

	do {
		$id = bin2hex(random_bytes(6));
	} while (restapi_find_token($id)[0] !== null);
	$secret = restapi_generate_secret();
	$token = array(
		'id' => $id,
		'username' => $username,
		'descr' => $descr,
		'hash' => restapi_hash_secret($secret),
		'created' => (string)time(),
		'expires' => ($days === '') ? '' : (string)(time() + (int)$days * 86400),
	);
	if (!empty($post['readonly'])) {
		$token['readonly'] = true;
	}
	/* Optional scope limit: only the chosen scopes, and still only what the user holds. */
	if (($post['scope_mode'] ?? '') === 'limited') {
		$scopes = restapi_parse_scopes(implode(' ', (array)($post['scopes'] ?? array())));
		if (empty($scopes)) {
			return array('input_errors' => array(gettext('Choose at least one scope for a limited key.')));
		}
		$token['scopes'] = implode(' ', $scopes);
	}
	config_set_path('restapi/apitoken/', $token);
	write_config(sprintf(gettext('API key %1$s created for user %2$s.'), $id, $username));
	return array('input_errors' => array(), 'id' => $id, 'token' => restapi_format_token($id, $secret));
}

/*
 * Revoke every API key of $username and save ("Revoke all keys", for an
 * account that may be compromised; a password change does not revoke keys).
 * Returns the number of keys removed.
 */
function restapi_revoke_user_tokens($username) {
	$ids = array();
	foreach (restapi_tokens() as $token) {
		if (($token['username'] ?? null) === $username) {
			$ids[] = $token['id'];
		}
	}
	$removed = local_user_revoke_api_keys($username);
	if ($removed > 0) {
		write_config(sprintf(gettext('All %1$d API key(s) of user %2$s revoked.'), $removed, $username));
		$used = restapi_last_used_read();
		foreach ($ids as $id) {
			unset($used[$id]);
		}
		@file_put_contents(RESTAPI_LAST_USED, json_encode($used), LOCK_EX);
	}
	return $removed;
}

/*
 * Revoke the API keys with the IDs in $ids and save once. A revoked key is
 * refused at once and for good (restapi_authenticate()), but stays listed,
 * with its last use, until it is deleted. With $only_user, only that user's
 * keys are revoked. Returns the number of keys newly revoked.
 */
function restapi_revoke_tokens(array $ids, $only_user = null) {
	$wanted = array_flip(array_map('strval', $ids));
	$tokens = config_get_path('restapi/apitoken', []);
	$revoked = array();
	foreach ($tokens as $idx => $token) {
		if (is_array($token) && isset($wanted[(string)($token['id'] ?? '')]) && empty($token['revoked']) &&
		    (($only_user === null) || (($token['username'] ?? null) === $only_user))) {
			$tokens[$idx]['revoked'] = time();
			$revoked[] = $token['id'] . ' (' . ($token['username'] ?? '') . ')';
		}
	}
	if (empty($revoked)) {
		return 0;
	}
	config_set_path('restapi/apitoken', $tokens);
	write_config(sprintf(gettext('API key(s) revoked: %s.'), implode(', ', $revoked)));
	return count($revoked);
}

/*
 * Delete the API keys with the IDs in $ids (revoked or not) and save once.
 * With $only_user, only that user's keys are deleted. Returns the number of
 * keys removed.
 */
function restapi_delete_tokens(array $ids, $only_user = null) {
	$wanted = array_flip(array_map('strval', $ids));
	$keep = array();
	$removed = array();
	foreach (config_get_path('restapi/apitoken', []) as $token) {
		if (is_array($token) && isset($wanted[(string)($token['id'] ?? '')]) &&
		    (($only_user === null) || (($token['username'] ?? null) === $only_user))) {
			$removed[(string)$token['id']] = $token['id'] . ' (' . ($token['username'] ?? '') . ')';
			continue;
		}
		$keep[] = $token;
	}
	if (empty($removed)) {
		return 0;
	}
	config_set_path('restapi/apitoken', $keep);
	write_config(sprintf(gettext('API key(s) deleted: %s.'), implode(', ', $removed)));
	$used = restapi_last_used_read();
	foreach (array_keys($removed) as $id) {
		unset($used[$id]);
	}
	@file_put_contents(RESTAPI_LAST_USED, json_encode($used), LOCK_EX);
	return count($removed);
}

function restapi_last_used_read() {
	$used = @json_decode((string)@file_get_contents(RESTAPI_LAST_USED), true);
	return is_array($used) ? $used : array();
}

/* Kept outside config.xml so API use does not create config revisions. */
function restapi_touch_last_used($id, $ip) {
	$used = restapi_last_used_read();
	$used[$id] = array('time' => time(), 'ip' => $ip);
	@file_put_contents(RESTAPI_LAST_USED, json_encode($used), LOCK_EX);
	@chmod(RESTAPI_LAST_USED, 0600);
}

/* ------------------------------------------------------------------ */
/* Authentication and authorization                                    */
/* ------------------------------------------------------------------ */

/*
 * Resolve the Authorization header to array('user' => ..., 'token' => ...).
 * Failures are logged in the webConfigurator format so login protection
 * (sshguard) blocks clients that keep guessing.
 */
function restapi_authenticate($authorization, $remote_ip) {
	$parsed = restapi_parse_authorization($authorization);
	$fail = function ($who, $reason) use ($remote_ip) {
		log_auth_event(LOG_AUTH_EVENT_ERROR, $who, $remote_ip, "REST API: {$reason}");
		return new RestApiError(401, 'unauthorized', 'A valid API key is required.');
	};
	if ($parsed === null) {
		if (trim((string)$authorization) === '') {
			throw new RestApiError(401, 'unauthorized', 'A valid API key is required.');
		}
		throw $fail('(unknown)', 'malformed API key');
	}
	list($id, $secret) = $parsed;
	list(, $token) = restapi_find_token($id);
	if ($token === null || !restapi_verify_secret($secret, $token['hash'] ?? '')) {
		throw $fail('(unknown)', "invalid API key {$id}");
	}
	if (!empty($token['expires']) && ((int)$token['expires'] < time())) {
		throw $fail($token['username'], "expired API key {$id}");
	}
	if (!empty($token['revoked'])) {
		throw $fail($token['username'], "revoked API key {$id}");
	}
	$user = restapi_local_user($token['username']);
	if ($user === null) {
		throw $fail($token['username'], "API key {$id} belongs to a missing or disabled user");
	}
	if (!empty($user['expires']) && (strtotime($user['expires']) !== false) &&
	    (strtotime($user['expires']) < time())) {
		throw $fail($token['username'], "API key {$id} belongs to an expired account");
	}
	if (!restapi_user_has_access($user)) {
		log_auth_event(LOG_AUTH_EVENT_ERROR, $token['username'], $remote_ip,
		    "REST API: user lacks REST API access (key {$id})");
		throw new RestApiError(403, 'api_access_denied',
		    'This user does not have REST API access. An administrator can grant the ' .
		    '"WebCfg - System: REST API access" privilege in System > User Manager.');
	}
	restapi_touch_last_used($id, $remote_ip);
	return array('user' => $user, 'token' => $token);
}

/* The GUI page patterns a local user may open (no PHP session needed). */
function restapi_user_allowed_pages($user) {
	/* A remote (LDAP/RADIUS) user of the WebUI session bridge: pages computed by the GUI at sign-in. */
	if (isset($user['__pages']) && is_array($user['__pages'])) {
		return $user['__pages'];
	}
	$pages = array();
	getPrivPages($user, $pages);
	$groups = local_user_get_groups($user);
	$groups[] = 'all';
	foreach (config_get_path('system/group', []) as $group) {
		if (is_array($group) && in_array($group['name'] ?? '', $groups, true)) {
			getPrivPages($group, $pages);
		}
	}
	return $pages;
}

function restapi_user_may_open($user, $page) {
	if (isset($user['uid']) && ((string)$user['uid'] === '0')) {
		return true;
	}
	$pages = restapi_user_allowed_pages($user);
	return cmp_page_matches($page, $pages);
}

/*
 * True when $user holds the scope $route needs: by GUI access to the page
 * the route mirrors, or by the "REST API - <area>" privilege (write
 * implies read). See restapi/areas.inc.
 */
function restapi_user_holds_route($user, $route) {
	if ($route['page'] === '@authenticated') {
		return true;
	}
	if (restapi_user_may_open($user, $route['page'])) {
		return true;
	}
	if (userHasPrivilege($user, restapi_area_privilege($route['area'], 'write'))) {
		return true;
	}
	return !$route['write'] && userHasPrivilege($user, restapi_area_privilege($route['area'], 'read'));
}

/* The scopes a key is limited to; empty means not limited. */
function restapi_token_scopes($token) {
	return restapi_parse_scopes($token['scopes'] ?? '');
}

/* Throws 403 unless the authenticated context may call $route. */
function restapi_authorize($ctx, $route) {
	if (!restapi_user_holds_route($ctx['user'], $route)) {
		throw new RestApiError(403, 'forbidden', sprintf(
		    'User %1$s lacks the %2$s scope: it needs GUI access to %3$s or the "%4$s" privilege.',
		    $ctx['user']['name'], $route['scope'], $route['page'],
		    restapi_area_privilege($route['area'], $route['write'] ? 'write' : 'read')));
	}
	if (($route['page'] !== '@authenticated') &&
	    !restapi_scopes_allow(restapi_token_scopes($ctx['token']), $route['area'], $route['write'] ? 'write' : 'read')) {
		throw new RestApiError(403, 'scope_not_granted', sprintf(
		    'This API key is limited to other scopes and does not include %s.', $route['scope']));
	}
	if ($route['write']) {
		if (!empty($ctx['token']['readonly'])) {
			throw new RestApiError(403, 'read_only_key', 'This API key is read-only.');
		}
		if (userHasPrivilege($ctx['user'], 'user-config-readonly')) {
			throw new RestApiError(403, 'read_only_user', 'This user may not change the configuration.');
		}
	}
}

/* An ETag for the running configuration, used for If-Match on writes. */
function restapi_config_etag() {
	return '"' . substr(hash('sha256', (string)config_get_path('revision/time', '') . ':' .
	    (string)@filemtime(g_get('conf_path') . '/config.xml')), 0, 16) . '"';
}
