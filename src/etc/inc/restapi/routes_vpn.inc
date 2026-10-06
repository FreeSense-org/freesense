<?php
/*
 * restapi/routes_vpn.inc
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
 * REST API v1 routes for the VPNs: the L2TP server (area vpn.l2tp) and IPsec
 * (area vpn.ipsec).
 *
 * Writes go through the functions the VPN pages call (vpn_l2tp.inc,
 * vpn_ipsec.inc), so validation is the GUI's.
 *
 * L2TP: GET/PUT /v1/vpn/l2tp carries the L2TP Configuration page's fields
 * ("enable" is the Enable checkbox, the other checkboxes are booleans; a select
 * without a valid stored value reads as the option the form preselects). Like
 * the page, a PUT is applied at once (the L2TP server restarts). L2TP users
 * (/v1/vpn/l2tp/users) are addressed by position (the list is kept sorted by
 * name, so a write returns the user's new id) or by name; a user change is
 * applied at once like on the page. Passwords and secrets read as "(set)";
 * sending "(set)" keeps them.
 *
 * IPsec: pre-shared keys (/v1/vpn/ipsec/pre-shared-keys, addressed by position
 * or identifier; the key reads as "(set)", sending "(set)" keeps it) and the
 * tunnel list's actions are staged like the GUI (the "ipsec" dirty flag) until
 * POST /v1/vpn/ipsec/apply; add ?apply=true to a write to apply it at once.
 * Tunnels (phase 1 entries, addressed by ikeid, with their phase 2 entries,
 * addressed by uniqid) can be read, enabled/disabled and deleted; editing them
 * is not part of the API yet. A VTI phase 2 whose interface is assigned cannot
 * be disabled or deleted, nor can its phase 1 (409). Secrets of a phase 1
 * (pre-shared key, PKCS#11 PIN, private keys) read as "(set)".
 *
 * The front controller loads vpn_l2tp.inc and vpn_ipsec.inc at file scope.
 */

require_once('restapi/framework.inc');
require_once('restapi/routes_firewall.inc');
require_once('restapi/routes_services.inc');

function restapi_routes_vpn() {
	$apply_query = array('apply' => 'true to apply the change at once (default: stage it like the GUI)');
	$route = function ($method, $path, $handler, $page, $area, $summary, $write = false, $body = null, $staged = false) use ($apply_query) {
		$opts = array('page' => $page, 'area' => $area, 'summary' => $summary);
		if ($write) {
			$opts['write'] = true;
			$opts['body'] = $body;
			if ($staged) {
				$opts['query'] = $apply_query;
			}
		}
		return restapi_route($method, $path, $handler, $opts);
	};

	$l2tp_body = array('type' => 'object', 'description' => 'The L2TP Configuration page: enable, radiusenable, ' .
	    'radacct_enable, radiusissueips (booleans); interface (from "interface_choices"), localip (server address), ' .
	    'remoteip and l2tp_subnet (first remote address and its mask bits), n_l2tp_units (1-255), secret, paporchap ' .
	    '(chap|chap-msv2|pap), l2tp_dns1, l2tp_dns2, mtu, radiusserver, radiussecret (strings; "(set)" keeps a secret). ' .
	    'Omitted fields keep their value. Applied at once (the L2TP server restarts), like the page.');
	$user_body = array('type' => 'object', 'description' => 'An L2TP user: name (letters, digits, ".", "@", "-", "_"), ' .
	    'password (required for a new user; "(set)" or omitted keeps it), ip (optional fixed address). Omitted fields ' .
	    'keep their value. Applied at once, like the page.');
	$psk_body = array('type' => 'object', 'description' => 'A pre-shared key: ident (identifier: an address, FQDN, ' .
	    'e-mail address or "any"), type (PSK|EAP), psk (the key; "(set)" keeps it), ident_type (EAP identifier type: ' .
	    'none|email|fqdn|userfqdn|keyid|asn1dn), pool_address, pool_netbits (EAP virtual address pool), dns_address ' .
	    '(EAP DNS server). Omitted fields keep their value (create: type PSK, ident_type none, pool_netbits 32).');

	$l2 = 'vpn.l2tp';
	$ip = 'vpn.ipsec';
	$users = '/v1/vpn/l2tp/users';
	$keys = '/v1/vpn/ipsec/pre-shared-keys';
	$tun = '/v1/vpn/ipsec/tunnels';
	return array(
		$route('GET', '/v1/vpn/l2tp', 'restapi_h_l2tp_get', 'vpn_l2tp.php', $l2,
		    'L2TP server settings (secrets read as "(set)"), choices and whether the server runs'),
		$route('PUT', '/v1/vpn/l2tp', 'restapi_h_l2tp_set', 'vpn_l2tp.php', $l2,
		    'Change the L2TP server settings (applied at once; omitted fields keep their value)', true, $l2tp_body),
		$route('GET', $users, 'restapi_h_l2tp_user_list', 'vpn_l2tp_users.php', $l2, 'List the L2TP users (sorted by name)'),
		$route('GET', "{$users}/{id}", 'restapi_h_l2tp_user_get', 'vpn_l2tp_users.php', $l2,
		    'Get an L2TP user by position or name'),
		$route('POST', $users, 'restapi_h_l2tp_user_create', 'vpn_l2tp_users_edit.php', $l2,
		    'Create an L2TP user (applied at once; the response has its id)', true, $user_body),
		$route('PUT', "{$users}/{id}", 'restapi_h_l2tp_user_update', 'vpn_l2tp_users_edit.php', $l2,
		    'Update an L2TP user (applied at once; omitted fields keep their value; the response has its new id)', true, $user_body),
		$route('DELETE', "{$users}/{id}", 'restapi_h_l2tp_user_delete', 'vpn_l2tp_users.php', $l2,
		    'Delete an L2TP user (applied at once)', true),

		$route('POST', '/v1/vpn/ipsec/apply', 'restapi_h_ipsec_apply', 'vpn_ipsec.php', $ip,
		    'Apply pending IPsec changes', true),
		$route('GET', '/v1/vpn/ipsec/status', 'restapi_h_ipsec_status', 'status_ipsec.php', $ip,
		    'IPsec status: for every tunnel whether it is connected, its security associations and its phase 2 entries'),
		$route('GET', $keys, 'restapi_h_ipsec_psk_list', 'vpn_ipsec_keys.php', $ip,
		    'List the IPsec pre-shared keys (keys read as "(set)")'),
		$route('GET', "{$keys}/{id}", 'restapi_h_ipsec_psk_get', 'vpn_ipsec_keys.php', $ip,
		    'Get an IPsec pre-shared key by position or identifier'),
		$route('POST', $keys, 'restapi_h_ipsec_psk_create', 'vpn_ipsec_keys_edit.php', $ip,
		    'Create an IPsec pre-shared key (the response has its id)', true, $psk_body, true),
		$route('PUT', "{$keys}/{id}", 'restapi_h_ipsec_psk_update', 'vpn_ipsec_keys_edit.php', $ip,
		    'Update an IPsec pre-shared key (omitted fields keep their value)', true, $psk_body, true),
		$route('DELETE', "{$keys}/{id}", 'restapi_h_ipsec_psk_delete', 'vpn_ipsec_keys.php', $ip,
		    'Delete an IPsec pre-shared key', true, null, true),
		$route('GET', $tun, 'restapi_h_ipsec_tunnel_list', 'vpn_ipsec.php', $ip,
		    'List the IPsec tunnels (phase 1 entries with a summary of their phase 2 entries) and whether changes are pending'),
		$route('GET', "{$tun}/{ikeid}", 'restapi_h_ipsec_tunnel_get', 'vpn_ipsec.php', $ip,
		    'Get an IPsec tunnel (phase 1, secrets read as "(set)") with its phase 2 entries'),
		$route('POST', "{$tun}/{ikeid}/toggle", 'restapi_h_ipsec_tunnel_toggle', 'vpn_ipsec.php', $ip,
		    'Enable or disable an IPsec tunnel (phase 1)', true, null, true),
		$route('DELETE', "{$tun}/{ikeid}", 'restapi_h_ipsec_tunnel_delete', 'vpn_ipsec.php', $ip,
		    'Delete an IPsec tunnel (phase 1) with its phase 2 entries', true, null, true),
		$route('GET', "{$tun}/{ikeid}/phase2/{uniqid}", 'restapi_h_ipsec_p2_get', 'vpn_ipsec.php', $ip,
		    'Get a phase 2 entry of an IPsec tunnel'),
		$route('POST', "{$tun}/{ikeid}/phase2/{uniqid}/toggle", 'restapi_h_ipsec_p2_toggle', 'vpn_ipsec.php', $ip,
		    'Enable or disable a phase 2 entry', true, null, true),
		$route('DELETE', "{$tun}/{ikeid}/phase2/{uniqid}", 'restapi_h_ipsec_p2_delete', 'vpn_ipsec.php', $ip,
		    'Delete a phase 2 entry', true, null, true),
	);
}

/* ------------------------------------------------------------------ */
/* Pure helpers (no config access)                                     */
/* ------------------------------------------------------------------ */

/* The secret fields of a stored IPsec phase 1 entry. */
function restapi_ipsec_p1_secrets() {
	return array('pre-shared-key', 'pkcs11pin', 'private-key', 'privatekey', 'pskey');
}

/* A stored phase 1 or phase 2 entry for the API: flags as booleans, secrets masked. */
function restapi_ipsec_entry_out(array $entry, array $secrets = array()) {
	foreach ($secrets as $secret) {
		if (array_key_exists($secret, $entry)) {
			$entry[$secret] = restapi_svc_mask(is_scalar($entry[$secret]) ? $entry[$secret] : '');
		}
	}
	$entry['disabled'] = isset($entry['disabled']);
	$entry['mobile'] = isset($entry['mobile']);
	return $entry;
}

/*
 * The form post of the tunnel list for one row button: "del", "delp2",
 * "toggle" or "togglep2" for the entry at position $pos.
 */
function restapi_ipsec_button_post($button, $pos) {
	return array("{$button}_{$pos}" => "{$button}_{$pos}");
}

/* A user or key secret from the body: "(set)" keeps the current one (null). */
function restapi_vpn_secret_body(array $body, $field) {
	if (array_key_exists($field, $body) && ($body[$field] === '(set)')) {
		unset($body[$field]);
	}
	return $body;
}

/* ------------------------------------------------------------------ */
/* L2TP settings                                                       */
/* ------------------------------------------------------------------ */

function restapi_l2tp_types() {
	$types = array('interface_choices' => 'ro', 'paporchap_choices' => 'ro', 'running' => 'ro');
	foreach (array('enable', 'radiusenable', 'radacct_enable', 'radiusissueips') as $flag) {
		$types[$flag] = 'bool';
	}
	foreach (array('interface', 'localip', 'remoteip', 'l2tp_subnet', 'n_l2tp_units', 'secret', 'paporchap', 'l2tp_dns1',
	    'l2tp_dns2', 'mtu', 'radiusserver', 'radiussecret') as $field) {
		$types[$field] = 'string';
	}
	return $types;
}

/* A select's value as the form preselects it: the stored value when it is a choice, else the first choice. */
function restapi_vpn_select($value, array $choices) {
	$value = (string)$value;
	return array_key_exists($value, $choices) ? $value : (string)array_key_first($choices);
}

/* The settings page's fields with the secrets in clear (never returned). */
function restapi_l2tp_settings() {
	$p = l2tp_settings_form();
	$units = array_combine(range(1, 255), range(1, 255));
	$bits = array_combine(range(32, 1), range(32, 1));
	return array(
		'enable' => ($p['mode'] == 'server'),
		'interface' => restapi_vpn_select($p['interface'], get_configured_interface_with_descr()),
		'localip' => (string)$p['localip'],
		'remoteip' => (string)$p['remoteip'],
		'l2tp_subnet' => restapi_vpn_select($p['l2tp_subnet'], $bits),
		'n_l2tp_units' => restapi_vpn_select($p['n_l2tp_units'], $units),
		'secret' => (string)$p['secret'],
		'paporchap' => restapi_vpn_select($p['paporchap'], l2tp_paporchap_values()),
		'l2tp_dns1' => (string)$p['l2tp_dns1'],
		'l2tp_dns2' => (string)$p['l2tp_dns2'],
		'mtu' => (string)$p['mtu'],
		'radiusenable' => !empty($p['radiusenable']),
		'radacct_enable' => !empty($p['radacct_enable']),
		'radiusserver' => (string)$p['radiusserver'],
		'radiussecret' => (string)$p['radiussecret'],
		'radiusissueips' => !empty($p['radiusissueips']),
	);
}

/* Whether the L2TP server runs; after a start, wait up to $wait seconds for the daemon to write its pid file. */
function restapi_l2tp_running($wait = 0) {
	$pidfile = g_get('varrun_path') . '/l2tp-vpn.pid';
	for ($i = 0; ($i < $wait * 5) && !isvalidpid($pidfile); $i++) {
		usleep(200000);
	}
	return isvalidpid($pidfile);
}

function restapi_l2tp_out(array $settings, $wait = 0) {
	$settings['secret'] = restapi_svc_mask($settings['secret']);
	$settings['radiussecret'] = restapi_svc_mask($settings['radiussecret']);
	$settings['interface_choices'] = restapi_svc_choices(get_configured_interface_with_descr());
	$settings['paporchap_choices'] = restapi_svc_choices(l2tp_paporchap_values());
	$settings['running'] = restapi_l2tp_running($wait);
	return $settings;
}

/*
 * The settings form post for $values (API shape). The secrets post the page's
 * placeholder (kept by the save) unless $body sets them; the confirmation
 * fields repeat them; unticked checkboxes are null (unset for the save, like
 * a form without them).
 */
function restapi_l2tp_post(array $values, array $current, array $body) {
	$types = restapi_l2tp_types();
	$post = restapi_svc_post($values, $types);
	unset($post['enable']);
	$post['mode'] = $values['enable'] ? 'server' : null;
	foreach (array('radiusenable', 'radacct_enable', 'radiusissueips') as $flag) {
		$post[$flag] = $post[$flag] ?? null;
	}
	foreach (array('secret', 'radiussecret') as $secret) {
		if (!array_key_exists($secret, $body)) {
			$post[$secret] = ($current[$secret] !== '') ? DMYPWD : '';
		}
		$post["{$secret}_confirm"] = $post[$secret];
	}
	return $post;
}

function restapi_h_l2tp_get($req) {
	return array('data' => restapi_l2tp_out(restapi_l2tp_settings()));
}

function restapi_h_l2tp_set($req) {
	$body = $req['body'];
	foreach (array('secret', 'radiussecret') as $secret) {
		$body = restapi_vpn_secret_body($body, $secret);
	}
	$current = restapi_l2tp_settings();
	$values = restapi_svc_merge($body, $current, restapi_l2tp_types());
	$rv = l2tp_settings_save(restapi_l2tp_post($values, $current, $body));
	if (!empty($rv['input_errors'])) {
		throw restapi_validation_error($rv['input_errors']);
	}
	$settings = restapi_l2tp_settings();
	return array('data' => restapi_l2tp_out($settings, $settings['enable'] ? 3 : 0) + array('applied' => ($rv['retval'] == 0)));
}

/* ------------------------------------------------------------------ */
/* L2TP users                                                          */
/* ------------------------------------------------------------------ */

function restapi_l2tp_users() {
	$users = config_get_path('l2tp/user', []);
	return is_array($users) ? $users : array();
}

/* A user's position from its position or name, or a 404. */
function restapi_l2tp_user_id($param) {
	$users = restapi_l2tp_users();
	if (ctype_digit((string)$param)) {
		return restapi_index_param($param, $users, 'L2TP user');
	}
	foreach ($users as $idx => $user) {
		if (($user['name'] ?? null) === (string)$param) {
			return (int)$idx;
		}
	}
	throw new RestApiError(404, 'not_found', 'No L2TP user with that id or name.');
}

function restapi_l2tp_user_out($id) {
	$u = (array)config_get_path("l2tp/user/{$id}", []);
	return array('id' => (int)$id, 'name' => (string)($u['name'] ?? ''), 'ip' => (string)($u['ip'] ?? ''),
	    'password' => restapi_svc_mask($u['password'] ?? ''));
}

function restapi_l2tp_user_types() {
	return array('id' => 'ro', 'name' => 'string', 'password' => 'string', 'ip' => 'string');
}

/* Save through the edit page's function; the list is re-sorted, so find the user again by name. */
function restapi_l2tp_user_save(array $body, $id, $status) {
	$body = restapi_vpn_secret_body($body, 'password');
	$current = ($id === null) ? array('name' => '', 'password' => '', 'ip' => '') : restapi_l2tp_user_out($id);
	$values = restapi_svc_merge($body, $current, restapi_l2tp_user_types());
	/* an empty password keeps the current one, like the page */
	$password = array_key_exists('password', $body) ? $values['password'] : '';
	$errors = l2tp_user_save(array('usernamefld' => $values['name'], 'passwordfld' => $password,
	    'passwordfld_confirm' => $password, 'ip' => $values['ip']), $id);
	if (!empty($errors)) {
		throw restapi_validation_error($errors);
	}
	foreach (restapi_l2tp_users() as $idx => $user) {
		if (($user['name'] ?? null) === $values['name']) {
			return array('status' => $status, 'data' => restapi_l2tp_user_out($idx));
		}
	}
	throw new RestApiError(500, 'internal_error', 'The saved user was not found.');
}

function restapi_h_l2tp_user_list($req) {
	return array('data' => array_map('restapi_l2tp_user_out', array_keys(restapi_l2tp_users())));
}

function restapi_h_l2tp_user_get($req) {
	return array('data' => restapi_l2tp_user_out(restapi_l2tp_user_id($req['params']['id'])));
}

function restapi_h_l2tp_user_create($req) {
	return restapi_l2tp_user_save($req['body'], null, 201);
}

function restapi_h_l2tp_user_update($req) {
	return restapi_l2tp_user_save($req['body'], restapi_l2tp_user_id($req['params']['id']), 200);
}

function restapi_h_l2tp_user_delete($req) {
	$id = restapi_l2tp_user_id($req['params']['id']);
	$name = restapi_l2tp_user_out($id)['name'];
	l2tp_user_delete($id);
	return array('data' => array('deleted' => $name));
}

/* ------------------------------------------------------------------ */
/* IPsec: shared                                                       */
/* ------------------------------------------------------------------ */

function restapi_ipsec_maybe_apply($req) {
	return restapi_svc_maybe_apply($req, 'ipsec', 'ipsec_apply_changes');
}

function restapi_h_ipsec_apply($req) {
	$retval = ipsec_apply_changes();
	return array('data' => array('applied' => ($retval == 0), 'pending' => is_subsystem_dirty('ipsec')));
}

/* ------------------------------------------------------------------ */
/* IPsec pre-shared keys                                               */
/* ------------------------------------------------------------------ */

function restapi_ipsec_psks() {
	$keys = config_get_path('ipsec/mobilekey', []);
	return is_array($keys) ? $keys : array();
}

/* A key's position from its position or identifier, or a 404. */
function restapi_ipsec_psk_id($param) {
	$keys = restapi_ipsec_psks();
	if (ctype_digit((string)$param)) {
		return restapi_index_param($param, $keys, 'pre-shared key');
	}
	foreach ($keys as $idx => $key) {
		if (($key['ident'] ?? null) === (string)$param) {
			return (int)$idx;
		}
	}
	throw new RestApiError(404, 'not_found', 'No pre-shared key with that id or identifier.');
}

function restapi_ipsec_psk_types() {
	return array('id' => 'ro', 'ident' => 'string', 'type' => 'string', 'psk' => 'string', 'ident_type' => 'string',
	    'pool_address' => 'string', 'pool_netbits' => 'string', 'dns_address' => 'string');
}

/* The key edit form of the key at position $id with the key in clear (never returned). */
function restapi_ipsec_psk_fields($id) {
	$form = ipsec_psk_form($id);
	$out = array('id' => (int)$id);
	foreach (array_keys(restapi_ipsec_psk_types()) as $field) {
		if ($field !== 'id') {
			$out[$field] = (string)($form[$field] ?? '');
		}
	}
	return $out;
}

function restapi_ipsec_psk_out($id) {
	$out = restapi_ipsec_psk_fields($id);
	$out['psk'] = restapi_svc_mask($out['psk']);
	return $out;
}

/* Save through the edit page's function and find the key again by its identifier. */
function restapi_ipsec_psk_save($req, $id, $status) {
	$body = restapi_vpn_secret_body($req['body'], 'psk');
	$current = ($id === null) ? array('ident' => '', 'type' => 'PSK', 'psk' => '', 'ident_type' => 'none',
	    'pool_address' => '', 'pool_netbits' => '32', 'dns_address' => '') : restapi_ipsec_psk_fields($id);
	$types = restapi_ipsec_psk_types();
	$values = restapi_svc_merge($body, $current, $types);
	$errors = ipsec_psk_save(restapi_svc_post($values, $types), $id);
	if (!empty($errors)) {
		throw restapi_validation_error($errors);
	}
	$extra = restapi_ipsec_maybe_apply($req);
	foreach (restapi_ipsec_psks() as $idx => $key) {
		if (($key['ident'] ?? null) === $values['ident']) {
			return array('status' => $status, 'data' => restapi_ipsec_psk_out($idx) + $extra);
		}
	}
	throw new RestApiError(500, 'internal_error', 'The saved key was not found.');
}

function restapi_h_ipsec_psk_list($req) {
	return array('data' => array_map('restapi_ipsec_psk_out', array_keys(restapi_ipsec_psks())));
}

function restapi_h_ipsec_psk_get($req) {
	return array('data' => restapi_ipsec_psk_out(restapi_ipsec_psk_id($req['params']['id'])));
}

function restapi_h_ipsec_psk_create($req) {
	return restapi_ipsec_psk_save($req, null, 201);
}

function restapi_h_ipsec_psk_update($req) {
	return restapi_ipsec_psk_save($req, restapi_ipsec_psk_id($req['params']['id']), 200);
}

function restapi_h_ipsec_psk_delete($req) {
	$id = restapi_ipsec_psk_id($req['params']['id']);
	$ident = restapi_ipsec_psk_fields($id)['ident'];
	ipsec_psk_delete($id);
	return array('data' => array('deleted' => $ident) + restapi_ipsec_maybe_apply($req));
}

/* ------------------------------------------------------------------ */
/* IPsec tunnels                                                       */
/* ------------------------------------------------------------------ */

/* The position of the phase 1 entry with ikeid $ikeid, or a 404. */
function restapi_ipsec_p1_pos($ikeid) {
	foreach (config_get_path('ipsec/phase1', []) as $idx => $p1) {
		if (is_array($p1) && ((string)($p1['ikeid'] ?? '') === (string)$ikeid)) {
			return $idx;
		}
	}
	throw new RestApiError(404, 'not_found', 'No IPsec tunnel with that ikeid.');
}

/* The position of the phase 2 entry $uniqid of tunnel $ikeid, or a 404. */
function restapi_ipsec_p2_pos($ikeid, $uniqid) {
	restapi_ipsec_p1_pos($ikeid);
	foreach (config_get_path('ipsec/phase2', []) as $idx => $p2) {
		if (is_array($p2) && ((string)($p2['uniqid'] ?? '') === (string)$uniqid) &&
		    ((string)($p2['ikeid'] ?? '') === (string)$ikeid)) {
			return $idx;
		}
	}
	throw new RestApiError(404, 'not_found', 'The tunnel has no phase 2 entry with that uniqid.');
}

/* A phase 2 entry: the stored fields (full) or a summary, with its local and remote network as the list shows them. */
function restapi_ipsec_p2_out($idx, $full) {
	$p2 = (array)config_get_path("ipsec/phase2/{$idx}", []);
	$local = $remote = '';
	if (in_array($p2['mode'] ?? '', array('tunnel', 'tunnel6', 'vti'), true)) {
		$localid = (array)($p2['localid'] ?? array());
		$remoteid = (array)($p2['remoteid'] ?? array());
		$local = (string)ipsec_idinfo_to_text($localid);
		$remote = (string)ipsec_idinfo_to_text($remoteid);
	}
	$entry = restapi_ipsec_entry_out($p2);
	if (!$full) {
		$entry = array_intersect_key($entry, array_flip(array('uniqid', 'reqid', 'ikeid', 'mode', 'protocol', 'descr',
		    'disabled', 'mobile')));
	}
	return array('position' => (int)$idx) + $entry + array('local' => $local, 'remote' => $remote);
}

/* A tunnel: its phase 1 entry (full: every stored field, secrets masked; else a summary) and its phase 2 entries. */
function restapi_ipsec_p1_out($pos, $full) {
	$p1 = (array)config_get_path("ipsec/phase1/{$pos}", []);
	$entry = restapi_ipsec_entry_out($p1, restapi_ipsec_p1_secrets());
	if (!$full) {
		$entry = array_intersect_key($entry, array_flip(array('ikeid', 'iketype', 'interface', 'remote-gateway', 'protocol',
		    'authentication_method', 'mode', 'descr', 'disabled', 'mobile')));
	}
	$p2s = array();
	foreach (config_get_path('ipsec/phase2', []) as $idx => $p2) {
		if (is_array($p2) && ((string)($p2['ikeid'] ?? '') === (string)($p1['ikeid'] ?? ''))) {
			$p2s[] = restapi_ipsec_p2_out($idx, $full);
		}
	}
	return array('position' => (int)$pos) + $entry + array('phase2' => $p2s);
}

/* Run one row button of the tunnel list through the page's function; a refusal (VTI interface assigned) is a 409. */
function restapi_ipsec_list_button($button, $pos) {
	$errors = ipsec_tunnels_action(restapi_ipsec_button_post($button, $pos));
	if (!empty($errors)) {
		$e = restapi_validation_error($errors);
		throw new RestApiError(409, 'in_use', $e->details['messages'][0], $e->details);
	}
}

function restapi_h_ipsec_tunnel_list($req) {
	$out = array();
	foreach (array_keys(config_get_path('ipsec/phase1', [])) as $pos) {
		$out[] = restapi_ipsec_p1_out($pos, false);
	}
	return array('data' => array('tunnels' => $out, 'pending' => is_subsystem_dirty('ipsec')));
}

function restapi_h_ipsec_tunnel_get($req) {
	return array('data' => restapi_ipsec_p1_out(restapi_ipsec_p1_pos($req['params']['ikeid']), true));
}

function restapi_h_ipsec_tunnel_toggle($req) {
	$ikeid = $req['params']['ikeid'];
	restapi_ipsec_list_button('toggle', restapi_ipsec_p1_pos($ikeid));
	$extra = restapi_ipsec_maybe_apply($req);
	return array('data' => restapi_ipsec_p1_out(restapi_ipsec_p1_pos($ikeid), false) + $extra);
}

function restapi_h_ipsec_tunnel_delete($req) {
	$ikeid = $req['params']['ikeid'];
	restapi_ipsec_list_button('del', restapi_ipsec_p1_pos($ikeid));
	return array('data' => array('deleted' => (string)$ikeid) + restapi_ipsec_maybe_apply($req));
}

function restapi_h_ipsec_p2_get($req) {
	return array('data' => restapi_ipsec_p2_out(restapi_ipsec_p2_pos($req['params']['ikeid'], $req['params']['uniqid']), true));
}

function restapi_h_ipsec_p2_toggle($req) {
	$ikeid = $req['params']['ikeid'];
	$uniqid = $req['params']['uniqid'];
	restapi_ipsec_list_button('togglep2', restapi_ipsec_p2_pos($ikeid, $uniqid));
	$extra = restapi_ipsec_maybe_apply($req);
	return array('data' => restapi_ipsec_p2_out(restapi_ipsec_p2_pos($ikeid, $uniqid), false) + $extra);
}

function restapi_h_ipsec_p2_delete($req) {
	$ikeid = $req['params']['ikeid'];
	$uniqid = $req['params']['uniqid'];
	restapi_ipsec_list_button('delp2', restapi_ipsec_p2_pos($ikeid, $uniqid));
	return array('data' => array('deleted' => (string)$uniqid) + restapi_ipsec_maybe_apply($req));
}

/* ------------------------------------------------------------------ */
/* IPsec status                                                        */
/* ------------------------------------------------------------------ */

function restapi_h_ipsec_status($req) {
	$cmap = ipsec_status();
	$tunnels = array();
	foreach (config_get_path('ipsec/phase1', []) as $p1) {
		if (!is_array($p1) || !isset($p1['ikeid'])) {
			continue;
		}
		$t = $cmap[$p1['ikeid']] ?? array();
		$p2s = array();
		foreach ((array)($t['p2'] ?? array()) as $reqid => $p2) {
			$p2s[] = array('uniqid' => (string)($p2['uniqid'] ?? ''), 'reqid' => (string)$reqid,
			    'descr' => (string)($p2['descr'] ?? ''), 'disabled' => isset($p2['disabled']),
			    'connected' => !empty($p2['connected']));
		}
		$tunnels[] = array(
			'ikeid' => (string)$p1['ikeid'],
			'descr' => (string)($p1['descr'] ?? ''),
			'disabled' => isset($p1['disabled']),
			'mobile' => isset($p1['mobile']),
			'connected' => !empty($t['p1']['connected']),
			'sas' => array_values((array)($t['status'] ?? array())),
			'phase2' => $p2s,
		);
	}
	return array('data' => array('enabled' => ipsec_enabled(), 'tunnels' => $tunnels));
}
