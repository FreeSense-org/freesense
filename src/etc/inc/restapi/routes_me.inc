<?php
/*
 * routes_me.inc
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
 * The caller's own WebUI data: profile, preferences, GUI sessions and
 * dashboard layout (webui_prefs.inc, loaded by the front controller). Every
 * route is about the authenticated user only, so it needs no page
 * privilege ("@authenticated"); changes still need a key that is not
 * read-only and a user without "user-config-readonly".
 */

function restapi_routes_me() {
	return array(
		restapi_route('POST', '/v1/me/signout', 'restapi_h_me_signout', array(
			'page' => '@authenticated', 'write' => true, 'summary' => 'Sign out of this WebUI session')),
		restapi_route('GET', '/v1/me/profile', 'restapi_h_me_profile', array(
			'page' => '@authenticated', 'summary' => 'Your profile: name, email, groups, initials')),
		restapi_route('PUT', '/v1/me/profile', 'restapi_h_me_profile_put', array(
			'page' => '@authenticated', 'write' => true,
			'body' => array('name' => array('type' => 'string'), 'email' => array('type' => 'string')),
			'summary' => 'Change your display name and email (local users)')),
		restapi_route('GET', '/v1/me/preferences', 'restapi_h_me_prefs', array(
			'page' => '@authenticated', 'summary' => 'Your WebUI preferences: theme, mode, accent, density, start page')),
		restapi_route('PUT', '/v1/me/preferences', 'restapi_h_me_prefs_put', array(
			'page' => '@authenticated', 'write' => true, 'summary' => 'Change some of your WebUI preferences')),
		restapi_route('GET', '/v1/me/sessions', 'restapi_h_me_sessions', array(
			'page' => '@authenticated', 'summary' => 'Your signed-in WebGUI sessions')),
		restapi_route('DELETE', '/v1/me/sessions/{id}', 'restapi_h_me_session_delete', array(
			'page' => '@authenticated', 'write' => true, 'summary' => 'Sign out one of your other WebGUI sessions')),
		restapi_route('GET', '/v1/dashboard/layout', 'restapi_h_layout', array(
			'page' => '@authenticated', 'summary' => 'Your dashboard layout (null: the default layout)')),
		restapi_route('PUT', '/v1/dashboard/layout', 'restapi_h_layout_put', array(
			'page' => '@authenticated', 'write' => true, 'summary' => 'Save your dashboard layout')),
		restapi_route('DELETE', '/v1/dashboard/layout', 'restapi_h_layout_delete', array(
			'page' => '@authenticated', 'write' => true, 'summary' => 'Reset your dashboard to the default layout')),
	);
}

function restapi_me_validation($errors) {
	return new RestApiError(422, 'validation_failed', 'Fix the highlighted fields.', array('fields' => $errors));
}

function restapi_h_me_profile($req) {
	$user = $req['user'];
	$local = isset($user['uid']);
	$name = (string)($user['descr'] ?? '');
	return array('data' => array(
		'username' => $user['name'],
		'name' => $name,
		'email' => (string)($user['email'] ?? ''),
		'initials' => webui_initials(($name !== '') ? $name : $user['name']),
		'local' => $local,
		'groups' => $local ? array_values(local_user_get_groups($user)) : array(),
		'admin' => $local && (((string)$user['uid'] === '0') || userHasPrivilege($user, 'page-all')),
		'signed_in' => isset($req['login_time']) ? gmdate('c', (int)$req['login_time']) : null,
	));
}

function restapi_h_me_profile_put($req) {
	if (!isset($req['user']['uid'])) {
		throw new RestApiError(409, 'remote_user', 'The profile of a user from an authentication server is managed on that server.');
	}
	list($values, $errors) = webui_profile_validate($req['body']);
	if (!empty($errors)) {
		throw restapi_me_validation($errors);
	}
	$entry = getUserEntry($req['user']['name']);
	if (!isset($entry['idx'])) {
		throw new RestApiError(404, 'not_found', 'User not found.');
	}
	$path = "system/user/{$entry['idx']}";
	if (array_key_exists('name', $values)) {
		config_set_path("{$path}/descr", $values['name']);
	}
	if (array_key_exists('email', $values)) {
		if ($values['email'] === '') {
			config_del_path("{$path}/email");
		} else {
			config_set_path("{$path}/email", $values['email']);
		}
	}
	write_config(sprintf(gettext('Profile of %s changed in the WebUI.'), $req['user']['name']));
	local_user_set(config_get_path($path));
	$req['user'] = config_get_path($path);
	return restapi_h_me_profile($req);
}

function restapi_h_me_prefs($req) {
	return array('data' => webui_prefs_get($req['user']['name']));
}

function restapi_h_me_prefs_put($req) {
	list($values, $errors) = webui_pref_validate($req['body']);
	if (!empty($errors)) {
		throw restapi_me_validation($errors);
	}
	$prefs = webui_prefs_put($req['user']['name'], $values);
	if ($prefs === null) {
		throw new RestApiError(500, 'save_failed', 'The preferences could not be saved.');
	}
	return array('data' => $prefs);
}

/* Sign out of the WebUI session this request came from (WebUI 2.0 profile menu). */
function restapi_h_me_signout($req) {
	if (empty($req['session'])) {
		throw new RestApiError(400, 'not_a_session', 'Only a WebUI session can sign out; API keys are revoked in System > REST API.');
	}
	webgui_session_signout(false);
	return array('data' => array('signed_out' => true));
}

function restapi_h_me_sessions($req) {
	return array('data' => webui_sessions_list($req['user']['name'], $req['session_id'] ?? null));
}

function restapi_h_me_session_delete($req) {
	$r = webui_session_revoke($req['user']['name'], (string)$req['params']['id'], $req['session_id'] ?? null);
	if ($r === 'current') {
		throw new RestApiError(409, 'current_session', 'This is the session you are using; sign out instead.');
	}
	if ($r !== 'ok') {
		throw new RestApiError(404, 'not_found', 'No such session.');
	}
	return array('data' => null);
}

function restapi_h_layout($req) {
	return array('data' => webui_layout_get($req['user']['name']));
}

function restapi_h_layout_put($req) {
	list($widgets, $error) = webui_layout_validate($req['body']['widgets'] ?? null);
	if ($error !== null) {
		throw restapi_me_validation(array('widgets' => $error));
	}
	if (!webui_layout_put($req['user']['name'], $widgets)) {
		throw new RestApiError(500, 'save_failed', 'The layout could not be saved.');
	}
	return array('data' => $widgets);
}

function restapi_h_layout_delete($req) {
	webui_layout_delete($req['user']['name']);
	return array('data' => null);
}
