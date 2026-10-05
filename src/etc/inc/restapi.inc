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
 * <apitoken> list (id, username, descr, hash, created, expires, readonly).
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

define('RESTAPI_LAST_USED', '/var/db/restapi_last_used.json');
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
	unset($s['apitoken']);
	foreach (array('enable', 'allowhttp') as $flag) {
		$s[$flag] = isset($s[$flag]) && ($s[$flag] !== false);
	}
	$s['allowednetworks'] = (string)($s['allowednetworks'] ?? '');
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
	return $input_errors;
}

function restapi_save_settings($post) {
	$input_errors = restapi_validate_settings($post);
	if (!empty($input_errors)) {
		return array('input_errors' => $input_errors);
	}
	$section = config_get_path('restapi', []);
	$section = is_array($section) ? $section : [];
	foreach (array('enable', 'allowhttp') as $flag) {
		if (!empty($post[$flag])) {
			$section[$flag] = true;
		} else {
			unset($section[$flag]);
		}
	}
	$section['allowednetworks'] = implode(' ', restapi_parse_networks($post['allowednetworks'] ?? ''));
	config_set_path('restapi', $section);
	write_config(gettext('REST API settings saved.'));
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

/* API tokens belong to local users only. */
function restapi_local_user($username) {
	$entry = getUserEntry($username);
	$user = $entry['item'] ?? null;
	return (is_array($user) && empty($user['disabled'])) ? $user : null;
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
		$input_errors[] = sprintf(gettext('A user may have at most %d API keys.'), RESTAPI_MAX_TOKENS_PER_USER);
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
	config_set_path('restapi/apitoken/', $token);
	write_config(sprintf(gettext('API key %1$s created for user %2$s.'), $id, $username));
	return array('input_errors' => array(), 'id' => $id, 'token' => restapi_format_token($id, $secret));
}

/* Revoke a key; with $only_user set, only a key that user owns. */
function restapi_revoke_token($id, $only_user = null) {
	list($idx, $token) = restapi_find_token($id);
	if ($idx === null || (($only_user !== null) && ($token['username'] !== $only_user))) {
		return false;
	}
	config_del_path("restapi/apitoken/{$idx}");
	config_set_path('restapi/apitoken', array_values(config_get_path('restapi/apitoken', [])));
	write_config(sprintf(gettext('API key %1$s of user %2$s revoked.'), $token['id'], $token['username']));
	$used = restapi_last_used_read();
	unset($used[$id]);
	@file_put_contents(RESTAPI_LAST_USED, json_encode($used), LOCK_EX);
	return true;
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

/* Throws 403 unless the authenticated context may call $route. */
function restapi_authorize($ctx, $route) {
	if (($route['page'] !== '@authenticated') && !restapi_user_may_open($ctx['user'], $route['page'])) {
		throw new RestApiError(403, 'forbidden', sprintf(
		    'User %s lacks the privilege for %s, which this endpoint requires.',
		    $ctx['user']['name'], $route['page']));
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
