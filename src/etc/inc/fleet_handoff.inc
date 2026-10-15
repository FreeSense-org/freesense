<?php
/*
 * fleet_handoff.inc
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
 * "Open WebUI" from the Fleet controller: the controller asks for a
 * one-time link (POST /api/v1/auth/handoff) and the operator's browser opens
 * /?fleet_handoff=<token>, which signs in as the chosen local user.
 *
 * A stolen controller key must not become an admin login, so a handoff is
 * only possible as a local user that a local administrator listed in
 * <fleet><handoff_users> (empty by default: no handoff at all). A token is
 * random, used once, valid for at most two minutes, and only its hash is
 * stored (in /var/run, gone at reboot). Functions marked "pure" touch
 * neither the system nor config.xml.
 */

define('FLEET_HANDOFF_DIR', '/var/run/fleet-handoff');
define('FLEET_HANDOFF_MAX_SECONDS', 120);

/* pure: the users a handoff may sign in as, from the <fleet> setting. */
function fleet_handoff_allowed_users(string $setting): array {
	$out = array();
	foreach (preg_split('/[\s,]+/', trim($setting), -1, PREG_SPLIT_NO_EMPTY) as $u) {
		if (preg_match('/^[A-Za-z0-9._-]{1,32}$/D', $u)) {
			$out[$u] = $u;
		}
	}
	return array_values($out);
}

/* pure: a handoff request {user, operator, seconds}, or an InvalidArgumentException naming the field. */
function fleet_handoff_request(array $body, array $allowed): array {
	foreach (array_keys($body) as $f) {
		if (!in_array($f, array('user', 'operator', 'seconds'), true)) {
			throw new InvalidArgumentException("{$f}: unknown field");
		}
	}
	$user = (string)($body['user'] ?? '');
	if (empty($allowed)) {
		throw new DomainException('Handoff is off on this firewall. A local administrator can allow it for chosen users with: freesense-join handoff-users <user>.');
	}
	if (!in_array($user, $allowed, true)) {
		throw new InvalidArgumentException('user: not one of the users this firewall allows a handoff for');
	}
	$operator = (string)($body['operator'] ?? '');
	if (($operator === '') || (strlen($operator) > 80) || preg_match('/[\x00-\x1f\x7f]/', $operator)) {
		throw new InvalidArgumentException('operator: who opens the WebUI (shown in the log), 1 to 80 characters');
	}
	$seconds = $body['seconds'] ?? 60;
	if (!is_int($seconds) || ($seconds < 10) || ($seconds > FLEET_HANDOFF_MAX_SECONDS)) {
		throw new InvalidArgumentException('seconds: from 10 to ' . FLEET_HANDOFF_MAX_SECONDS);
	}
	return array('user' => $user, 'operator' => $operator, 'seconds' => $seconds);
}

/* pure: a token as it appears in the link. */
function fleet_handoff_token_valid($token): bool {
	return is_string($token) && (bool)preg_match('/^[A-Za-z0-9_-]{43}$/D', $token);
}

/* pure: where a token's record lives (by hash, so the directory never holds a usable token). */
function fleet_handoff_path(string $token): string {
	return FLEET_HANDOFF_DIR . '/' . hash('sha256', $token) . '.json';
}

/* Issue a one-time token. Returns array(token, expires). */
function fleet_handoff_issue(array $request): array {
	if (!is_dir(FLEET_HANDOFF_DIR) && !@mkdir(FLEET_HANDOFF_DIR, 0700, true)) {
		throw new RuntimeException('The handoff could not be stored.');
	}
	$token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
	$expires = time() + $request['seconds'];
	if (@file_put_contents(fleet_handoff_path($token), json_encode($request + array('expires' => $expires)), LOCK_EX) === false) {
		throw new RuntimeException('The handoff could not be stored.');
	}
	@chmod(fleet_handoff_path($token), 0600);
	return array($token, $expires);
}

/*
 * Use a token: returns its record once, then never again, or null. The file is
 * renamed before it is read, so two requests with the same token cannot both win.
 */
function fleet_handoff_consume(string $token): ?array {
	if (!fleet_handoff_token_valid($token)) {
		return null;
	}
	$path = fleet_handoff_path($token);
	$claimed = $path . '.used' . getmypid();
	if (!@rename($path, $claimed)) {
		return null;
	}
	$record = @json_decode((string)@file_get_contents($claimed), true);
	@unlink($claimed);
	if (!is_array($record) || ((int)($record['expires'] ?? 0) < time())) {
		return null;
	}
	/* The user must still be allowed and still exist. */
	if (!in_array((string)$record['user'], fleet_handoff_allowed_users((string)config_get_path('fleet/handoff_users', '')), true)) {
		return null;
	}
	return $record;
}

/*
 * Called by session_auth() for /?fleet_handoff=<token>: sign in and return
 * true, or false (the normal login page follows).
 */
function fleet_handoff_signin(string $token): bool {
	$record = fleet_handoff_consume($token);
	$user = ($record === null) ? null : getUserEntry($record['user'])['item'] ?? null;
	if (!is_array($user) || (isset($user['disabled']) && ($user['disabled'] !== false))) {
		log_auth_event(LOG_AUTH_EVENT_ERROR, '(fleet handoff)', get_user_remote_address(false));
		return false;
	}
	session_regenerate_id();
	$_SESSION['Logged_In'] = "True";
	$_SESSION['remoteauth'] = false;
	$_SESSION['authsource'] = 'FreeSense Fleet (' . $record['operator'] . ')';
	$_SESSION['Username'] = $user['name'];
	$_SESSION['last_access'] = time();
	$_SESSION['protocol'] = webgui_request_protocol();
	$_SESSION['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'];
	$_SESSION['login_time'] = time();
	$_SESSION['user_agent'] = substr(preg_replace('/[\x00-\x1f\x7f]/', '', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 200);
	phpsession_end(true);
	log_auth_event(LOG_AUTH_EVENT_LOGIN, $user['name'], get_user_remote_address() . get_user_remote_authsource());
	return true;
}
