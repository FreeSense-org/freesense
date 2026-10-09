<?php
/*
 * restapi_session.inc
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
 * Session bridge: the WebUI calls /api/v1 as the signed-in GUI user.
 *
 * A request uses the session bridge when it has no Authorization header but
 * carries the WebGUI session cookie and an X-CSRF-Token header. It is served
 * only on the WebGUI port (never on an API listener) and does not depend on
 * the REST API being enabled for API keys: it is the GUI itself, with the
 * GUI's own rules.
 *
 *  - The session must be signed in, use the same protocol, the same address
 *    when roaming is disabled, and not be stale (System > Advanced > Admin
 *    access > session timeout). Checks mirror session_auth() in auth.inc,
 *    but the session is only read, never extended: API polling must not keep
 *    an idle session alive.
 *  - X-CSRF-Token must equal the session's WebUI token, on every method.
 *    The WebUI page frame gets the token from restapi_session_token().
 *  - Authorization uses the GUI privileges of the user, like an API key
 *    without scope limits; no "REST API access" privilege is needed.
 */

require_once('auth.inc');
require_once('restapi.inc');

define('RESTAPI_SESSION_TOKEN_KEY', 'fs_webui_csrf');

/* True when the request should be authenticated through the session bridge. */
function restapi_session_requested($authorization) {
	return (trim((string)$authorization) === '') &&
	    (restapi_session_cookie_id() !== null) &&
	    (trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) !== '');
}

/* The WebGUI session id from the request cookie, or null. */
function restapi_session_cookie_id() {
	$id = (string)($_COOKIE[session_name()] ?? '');
	return preg_match('/^[A-Za-z0-9,-]{22,256}$/', $id) ? $id : null;
}

/*
 * Read a session without locking it for the rest of the request, without
 * extending it and without sending a cookie. Returns the session array, or
 * an empty array when the session does not exist.
 */
function restapi_session_read($id) {
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_write_close();
	}
	session_id($id);
	$data = array();
	if (@session_start(array('read_and_close' => true, 'use_cookies' => 0, 'use_strict_mode' => 1))) {
		$data = is_array($_SESSION) ? $_SESSION : array();
	}
	$_SESSION = array();
	return $data;
}

/* Why a session is not usable, or null when it is. */
function restapi_session_problem(array $s, $remote_ip, $now = null) {
	$now = ($now === null) ? time() : (int)$now;
	if (empty($s['Logged_In']) || empty($s['Username'])) {
		return 'not signed in';
	}
	if (($s['protocol'] ?? '') !== webgui_request_protocol()) {
		return 'protocol changed';
	}
	if ((config_get_path('system/webgui/roaming') === 'disabled') &&
	    !empty($s['REMOTE_ADDR']) && ($s['REMOTE_ADDR'] !== $remote_ip)) {
		return 'address changed';
	}
	$timeout = (int)config_get_path('system/webgui/session_timeout', 240);
	if (($timeout > 0) && ((int)($s['last_access'] ?? 0) < ($now - ($timeout * 60)))) {
		return 'session expired';
	}
	return null;
}

/*
 * Resolve the session to array('user' => ..., 'token' => ..., 'session' => true,
 * 'authsource' => ...). Throws 401 when there is no usable session and 403
 * when the CSRF token does not match.
 */
function restapi_session_authenticate($remote_ip) {
	$id = restapi_session_cookie_id();
	$s = ($id === null) ? array() : restapi_session_read($id);
	$problem = restapi_session_problem($s, $remote_ip);
	if ($problem !== null) {
		throw new RestApiError(401, 'session_required', 'Sign in to the WebGUI again (' . $problem . ').');
	}
	$expected = (string)($s[RESTAPI_SESSION_TOKEN_KEY] ?? '');
	$given = trim((string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
	if (($expected === '') || !hash_equals($expected, $given)) {
		log_auth_event(LOG_AUTH_EVENT_ERROR, (string)$s['Username'], $remote_ip, 'WebUI API: CSRF token mismatch');
		throw new RestApiError(403, 'csrf_failed', 'The request was not made by the WebUI (CSRF token mismatch). Reload the page.');
	}

	return array(
		'user' => restapi_session_user($s),
		'token' => array('id' => 'session', 'descr' => 'WebUI session', 'readonly' => false, 'scopes' => ''),
		'session' => true,
		'session_id' => $id,
		'login_time' => isset($s['login_time']) ? (int)$s['login_time'] : null,
		'authsource' => (string)($s['authsource'] ?? 'Local Database'),
	);
}

/*
 * The user of a signed-in session: the local user entry, or for a remote
 * (LDAP/RADIUS) user a stand-in with the pages the GUI computed at sign-in.
 * Throws 401 when a local user was disabled, removed or expired after
 * signing in. Also used by the WebUI 2.0 page frame.
 */
function restapi_session_user(array $s) {
	$name = (string)$s['Username'];
	$user = restapi_local_user($name);
	if ($user === null) {
		/*
		 * Decide by how the session signed in, not by getUserEntry(): that
		 * returns a stand-in for any name when system/webgui/authmode is
		 * unset (the default), so a removed local user would pass as remote.
		 */
		$local = (strncmp((string)($s['authsource'] ?? 'Local Database'), 'Local Database', 14) === 0);
		$entry = $local ? null : getUserEntry($name);
		if (!is_array($entry) || isset($entry['idx'])) {
			/* A local user that was disabled or removed after signing in. */
			throw new RestApiError(401, 'session_required', 'This account is no longer active.');
		}
		/* A remote (LDAP/RADIUS) user: use the pages the GUI computed at sign-in. */
		return array('name' => $name, '__pages' => is_array($s['page-match'] ?? null) ? $s['page-match'] : array());
	}
	if (!empty($user['expires']) && (strtotime($user['expires']) !== false) && (strtotime($user['expires']) < time())) {
		throw new RestApiError(401, 'session_required', 'This account has expired.');
	}
	return $user;
}

/*
 * The WebUI CSRF token of the current session, created on first use. Called
 * by the WebUI page frame (which runs inside the signed-in GUI session) to
 * embed the token in <meta name="fs-csrf">. Returns '' without a session.
 */
function restapi_session_token() {
	phpsession_begin();
	if (empty($_SESSION['Logged_In'])) {
		phpsession_end();
		return '';
	}
	if (empty($_SESSION[RESTAPI_SESSION_TOKEN_KEY]) || !is_string($_SESSION[RESTAPI_SESSION_TOKEN_KEY])) {
		$_SESSION[RESTAPI_SESSION_TOKEN_KEY] = bin2hex(random_bytes(32));
		$token = $_SESSION[RESTAPI_SESSION_TOKEN_KEY];
		phpsession_end(true);
		return $token;
	}
	$token = $_SESSION[RESTAPI_SESSION_TOKEN_KEY];
	phpsession_end();
	return $token;
}
