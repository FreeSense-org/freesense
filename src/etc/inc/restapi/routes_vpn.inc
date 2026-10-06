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
 * addressed by uniqid) can be read, created, edited, enabled/disabled and
 * deleted. A VTI phase 2 whose interface is assigned cannot be disabled or
 * deleted, nor can its phase 1 (409). Secrets of a phase 1 (pre-shared key,
 * PKCS#11 PIN, private keys) read as "(set)"; sending "(set)" keeps them.
 * GET of a tunnel or phase 2 entry returns the stored fields plus "fields":
 * the edit page's form, the shape a POST/PUT takes (a PUT merges its body over
 * the current "fields"; selects read as the option the page preselects;
 * "encryption" is a list of {algorithm, keylen, hash, prf, dhgroup} for a
 * phase 1 and of {algorithm, keylen} for a phase 2). The form is posted the
 * way the browser posts it (fields the selected options hide on the page are
 * left out), so validation is the page's. The mobile client settings
 * (/v1/vpn/ipsec/mobile) are staged too; ?apply=true applies them like that
 * page's Apply (IPsec is restarted). The advanced settings
 * (/v1/vpn/ipsec/settings) are applied at once, like their page (filter reload,
 * IPsec reconfigured and the system tunables set; the page also clears the
 * "sysctl" pending flag).
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

	$p1_body = array('type' => 'object', 'description' => 'A phase 1 as its edit page\'s form ("fields" of GET): descr, ' .
	    'disabled, iketype (ikev1|ikev2|auto), protocol (inet|inet6|both), interface, remotegw, authentication_method, mode ' .
	    '(main|aggressive, IKEv1), myid_type, myid_data, peerid_type, peerid_data, pskey ("(set)" keeps it), certref, caref, ' .
	    'pkcs11certref, pkcs11pin, encryption (list of {algorithm, keylen, hash, prf, dhgroup}), lifetime, rekey_time, ' .
	    'reauth_time, rand_time, startaction, closeaction, nat_traversal (on|force), mobike (on|off), gw_duplicates, splitconn, ' .
	    'prfselect_enable, dpd_enable (booleans), ikeport, nattport, dpd_delay, dpd_maxfail; create only: mobile (boolean). ' .
	    'Omitted fields keep their value (create: the page\'s defaults).');
	$p2_body = array('type' => 'object', 'description' => 'A phase 2 as its edit page\'s form ("fields" of GET): descr, disabled, ' .
	    'mode (tunnel|tunnel6|transport|vti), localid_type (address|network|<interface>), localid_address, localid_netbits, ' .
	    'natlocalid_type (none|address|network), natlocalid_address, natlocalid_netbits, remoteid_type (address|network), ' .
	    'remoteid_address, remoteid_netbits, proto (esp|ah), encryption (list of {algorithm, keylen}; keylen "auto" or bits), ' .
	    'halgos (list; sent only when AES-CBC is selected or with AH, like the page), pfsgroup, lifetime, rekey_time, rand_time, ' .
	    'pinghost, keepalive. Omitted fields keep their value (create: the page\'s defaults).');
	$mobile_body = array('type' => 'object', 'description' => 'The Mobile Clients page: enable, group_source, radiusaccounting, ' .
	    'pool_enable, pool_enable_v6, radius_ip_priority_enable, radius_advanced, net_list_enable, save_passwd_enable, ' .
	    'dns_domain_enable, dns_split_enable, dns_server_enable, wins_server_enable, pfs_group_enable, login_banner_enable ' .
	    '(booleans); user_source, auth_groups (lists, from the *_choices); pool_address, pool_netbits, pool_address_v6, ' .
	    'pool_netbits_v6, radius_retransmit_base, radius_retransmit_timeout, radius_retransmit_tries, radius_sockets, dns_domain, ' .
	    'dns_split, dns_server1-4, wins_server1-2, pfs_group, login_banner. Omitted fields keep their value.');
	$settings_body = array('type' => 'object', 'description' => 'The Advanced Settings page: logging (object category => level, ' .
	    'from "choices"), uniqueids, filtermode, ikev2_retransmit_tries/_timeout/_base/_jitter/_limit, maxexchange, dns-interval, ' .
	    'port, port_nat_t (strings); ikev2_retransmit_enable, compression, pkcs11support, enableinterfacesuse, ' .
	    'acceptunencryptedmainmode, unityplugin, strictcrlpolicy, makebeforebreak, async_crypto, autoexcludelanaddress, ' .
	    'ipsecbypass (booleans); bypassrules (list of {source, srcmask, destination, dstmask}). Omitted fields keep their ' .
	    'value. Applied at once, like the page.');

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
		    'Get an IPsec tunnel (phase 1, secrets read as "(set)") with its phase 2 entries and its edit form ("fields")'),
		$route('POST', $tun, 'restapi_h_ipsec_tunnel_create', 'vpn_ipsec_phase1.php', $ip,
		    'Create an IPsec tunnel (phase 1; the response has its ikeid)', true, $p1_body, true),
		$route('PUT', "{$tun}/{ikeid}", 'restapi_h_ipsec_tunnel_update', 'vpn_ipsec_phase1.php', $ip,
		    'Update an IPsec tunnel (phase 1; omitted fields keep their value)', true, $p1_body, true),
		$route('POST', "{$tun}/{ikeid}/phase2", 'restapi_h_ipsec_p2_create', 'vpn_ipsec_phase2.php', $ip,
		    'Create a phase 2 entry of an IPsec tunnel (the response has its uniqid)', true, $p2_body, true),
		$route('PUT', "{$tun}/{ikeid}/phase2/{uniqid}", 'restapi_h_ipsec_p2_update', 'vpn_ipsec_phase2.php', $ip,
		    'Update a phase 2 entry (omitted fields keep their value)', true, $p2_body, true),
		$route('POST', "{$tun}/{ikeid}/toggle", 'restapi_h_ipsec_tunnel_toggle', 'vpn_ipsec.php', $ip,
		    'Enable or disable an IPsec tunnel (phase 1)', true, null, true),
		$route('DELETE', "{$tun}/{ikeid}", 'restapi_h_ipsec_tunnel_delete', 'vpn_ipsec.php', $ip,
		    'Delete an IPsec tunnel (phase 1) with its phase 2 entries', true, null, true),
		$route('GET', "{$tun}/{ikeid}/phase2/{uniqid}", 'restapi_h_ipsec_p2_get', 'vpn_ipsec.php', $ip,
		    'Get a phase 2 entry of an IPsec tunnel with its edit form ("fields")'),
		$route('POST', "{$tun}/{ikeid}/phase2/{uniqid}/toggle", 'restapi_h_ipsec_p2_toggle', 'vpn_ipsec.php', $ip,
		    'Enable or disable a phase 2 entry', true, null, true),
		$route('DELETE', "{$tun}/{ikeid}/phase2/{uniqid}", 'restapi_h_ipsec_p2_delete', 'vpn_ipsec.php', $ip,
		    'Delete a phase 2 entry', true, null, true),
		$route('GET', '/v1/vpn/ipsec/mobile', 'restapi_h_ipsec_mobile_get', 'vpn_ipsec_mobile.php', $ip,
		    'IPsec mobile client settings, their choices and the mobile phase 1 (ikeid, or null)'),
		$route('PUT', '/v1/vpn/ipsec/mobile', 'restapi_h_ipsec_mobile_set', 'vpn_ipsec_mobile.php', $ip,
		    'Change the IPsec mobile client settings (omitted fields keep their value; ?apply=true restarts IPsec like the page)',
		    true, $mobile_body, true),
		$route('GET', '/v1/vpn/ipsec/settings', 'restapi_h_ipsec_settings_get', 'vpn_ipsec_settings.php', $ip,
		    'IPsec advanced settings and their choices'),
		$route('PUT', '/v1/vpn/ipsec/settings', 'restapi_h_ipsec_settings_set', 'vpn_ipsec_settings.php', $ip,
		    'Change the IPsec advanced settings (applied at once like the page; omitted fields keep their value)', true,
		    $settings_body),
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
	return array('data' => restapi_ipsec_tunnel_full(restapi_ipsec_p1_pos($req['params']['ikeid'])));
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
	return array('data' => restapi_ipsec_p2_full(restapi_ipsec_p2_pos($req['params']['ikeid'], $req['params']['uniqid'])));
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

/* ------------------------------------------------------------------ */
/* IPsec tunnel editing: pure helpers (choices are passed in)          */
/* ------------------------------------------------------------------ */

/* The API types of a phase 1 ("fields"); "encryption" is a list of {algorithm, keylen, hash, prf, dhgroup}. */
function restapi_ipsec_p1_types() {
	$types = array('ikeid' => 'ro', 'mobile' => 'ro', 'encryption' => 'rows');
	foreach (array('disabled', 'gw_duplicates', 'splitconn', 'prfselect_enable', 'dpd_enable') as $flag) {
		$types[$flag] = 'bool';
	}
	foreach (array('descr', 'iketype', 'protocol', 'interface', 'remotegw', 'authentication_method', 'mode', 'myid_type',
	    'myid_data', 'peerid_type', 'peerid_data', 'pskey', 'certref', 'caref', 'pkcs11certref', 'pkcs11pin', 'lifetime',
	    'rekey_time', 'reauth_time', 'rand_time', 'startaction', 'closeaction', 'nat_traversal', 'mobike', 'ikeport',
	    'nattport', 'dpd_delay', 'dpd_maxfail') as $field) {
		$types[$field] = 'string';
	}
	return $types;
}

/* The secret fields of the phase 1 form. */
function restapi_ipsec_p1_form_secrets() {
	return array('pskey', 'pkcs11pin');
}

/*
 * A phase 1 edit form (ipsec_p1_form() shape) in API shape, the way the page
 * shows it: selects read as the option the form preselects ($c: field =>
 * choices; "ealgo", "halgo" and "dhgroup" for the encryption rows), DPD delay
 * and retries as the page fills them in (10, 5) when empty. Secrets in clear.
 */
function restapi_ipsec_p1_fields(array $p, array $c) {
	$mobile = isset($p['mobile']);
	$sel = function ($field, $value = null) use ($p, $c) {
		return restapi_vpn_select(($value === null) ? ($p[$field] ?? '') : $value, $c[$field] ?? array());
	};
	$rows = array();
	foreach ((array)($p['encryption']['item'] ?? array()) as $item) {
		$item = (array)$item;
		$rows[] = array(
			'algorithm' => restapi_vpn_select($item['encryption-algorithm']['name'] ?? '', $c['ealgo'] ?? array()),
			'keylen' => (string)($item['encryption-algorithm']['keylen'] ?? ''),
			'hash' => restapi_vpn_select($item['hash-algorithm'] ?? '', $c['halgo'] ?? array()),
			'prf' => restapi_vpn_select($item['prf-algorithm'] ?? '', $c['halgo'] ?? array()),
			'dhgroup' => restapi_vpn_select($item['dhgroup'] ?? '', $c['dhgroup'] ?? array()),
		);
	}
	$str = function ($field) use ($p) {
		return is_scalar($p[$field] ?? null) ? (string)$p[$field] : '';
	};
	return array(
		'ikeid' => $str('ikeid'),
		'mobile' => $mobile,
		'descr' => $str('descr'),
		'disabled' => !empty($p['disabled']),
		'iketype' => $sel('iketype'),
		'protocol' => $sel('protocol'),
		'interface' => $sel('interface'),
		'remotegw' => $mobile ? '' : $str('remotegw'),
		'authentication_method' => $sel('authentication_method'),
		'mode' => $sel('mode'),
		'myid_type' => $sel('myid_type'),
		'myid_data' => $str('myid_data'),
		'peerid_type' => $sel('peerid_type'),
		'peerid_data' => $str('peerid_data'),
		'pskey' => $str('pskey'),
		'certref' => $sel('certref'),
		'caref' => $sel('caref'),
		'pkcs11certref' => $str('pkcs11certref'),
		'pkcs11pin' => $str('pkcs11pin'),
		'encryption' => $rows,
		'lifetime' => $str('lifetime'),
		'rekey_time' => $str('rekey_time'),
		'reauth_time' => $str('reauth_time'),
		'rand_time' => $str('rand_time'),
		'startaction' => $mobile ? '' : $sel('startaction'),
		'closeaction' => $sel('closeaction'),
		'nat_traversal' => $sel('nat_traversal'),
		'mobike' => $sel('mobike'),
		'gw_duplicates' => !$mobile && !empty($p['gw_duplicates']),
		'splitconn' => !empty($p['splitconn']),
		'prfselect_enable' => !empty($p['prfselect_enable']),
		'ikeport' => $mobile ? '' : $str('ikeport'),
		'nattport' => $mobile ? '' : $str('nattport'),
		'dpd_enable' => !empty($p['dpd_enable']),
		'dpd_delay' => ($str('dpd_delay') !== '') ? $str('dpd_delay') : '10',
		'dpd_maxfail' => ($str('dpd_maxfail') !== '') ? $str('dpd_maxfail') : '5',
	);
}

/* The "encryption" rows of a body: a non-empty list of objects with the row fields (strings), or a 400. */
function restapi_ipsec_p1_rows($rows) {
	$fields = array('algorithm', 'keylen', 'hash', 'prf', 'dhgroup');
	if (!is_array($rows) || !array_is_list($rows)) {
		throw new RestApiError(400, 'invalid_field', '"encryption" must be a list of {' . implode(', ', $fields) . '}.');
	}
	$out = array();
	foreach ($rows as $row) {
		if (!is_array($row) || array_is_list($row) && !empty($row)) {
			throw new RestApiError(400, 'invalid_field', '"encryption" must be a list of {' . implode(', ', $fields) . '}.');
		}
		$item = array();
		foreach ($row as $key => $value) {
			if (!in_array($key, $fields, true)) {
				throw new RestApiError(400, 'unknown_field', "Unknown encryption field \"{$key}\".");
			}
			if (!is_scalar($value) && !is_null($value)) {
				throw new RestApiError(400, 'invalid_field', "Encryption field \"{$key}\" must be a string.");
			}
			$item[$key] = (string)$value;
		}
		foreach ($fields as $field) {
			$item[$field] = $item[$field] ?? '';
		}
		$out[] = $item;
	}
	return $out;
}

/*
 * The phase 1 form post for $v (API shape) as the browser sends it: ticked
 * checkboxes post "yes"; a mobile entry posts mobile=true and has no remote
 * gateway, ports, start action or gateway duplicates; only the certificate,
 * CA and PKCS#11 fields the selected authentication method shows are posted;
 * encryption rows post ealgo_algoN, ealgo_keylenN (only for an algorithm with
 * key lengths, listed in $keylen_algos), halgoN, dhgroupN and prfalgoN.
 */
function restapi_ipsec_p1_post(array $v, array $keylen_algos) {
	$post = array();
	foreach (restapi_ipsec_p1_types() as $field => $type) {
		if ($type === 'string') {
			$post[$field] = (string)$v[$field];
		} elseif (($type === 'bool') && !empty($v[$field])) {
			$post[$field] = 'yes';
		}
	}
	$post['ikeid'] = (string)$v['ikeid'];
	if (!empty($v['mobile'])) {
		$post['mobile'] = 'true';
		unset($post['remotegw'], $post['startaction'], $post['gw_duplicates'], $post['ikeport'], $post['nattport']);
	}
	switch ($v['authentication_method']) {
		case 'eap-mschapv2':
		case 'eap-radius':
		case 'hybrid_cert_server':
			$shown = array('certref');
			break;
		case 'eap-tls':
		case 'xauth_cert_server':
		case 'cert':
			$shown = array('certref', 'caref');
			break;
		case 'pkcs11':
			$shown = array('caref', 'pkcs11certref', 'pkcs11pin');
			break;
		default:
			$shown = array();
	}
	foreach (array('certref', 'caref', 'pkcs11certref', 'pkcs11pin') as $field) {
		if (!in_array($field, $shown, true)) {
			unset($post[$field]);
		}
	}
	foreach (array_values($v['encryption']) as $i => $row) {
		$post["ealgo_algo{$i}"] = (string)$row['algorithm'];
		if (in_array((string)$row['algorithm'], $keylen_algos, true)) {
			$post["ealgo_keylen{$i}"] = (string)$row['keylen'];
		}
		$post["halgo{$i}"] = (string)$row['hash'];
		$post["dhgroup{$i}"] = (string)$row['dhgroup'];
		$post["prfalgo{$i}"] = (string)$row['prf'];
	}
	return $post;
}

/* The API types of a phase 2 ("fields"); "encryption" is a list of {algorithm, keylen}, "halgos" a list of hash algorithms. */
function restapi_ipsec_p2_types() {
	$types = array('uniqid' => 'ro', 'ikeid' => 'ro', 'reqid' => 'ro', 'mobile' => 'ro', 'encryption' => 'rows',
	    'halgos' => 'list', 'disabled' => 'bool', 'keepalive' => 'bool');
	foreach (array('descr', 'mode', 'localid_type', 'localid_address', 'localid_netbits', 'natlocalid_type',
	    'natlocalid_address', 'natlocalid_netbits', 'remoteid_type', 'remoteid_address', 'remoteid_netbits', 'proto',
	    'pfsgroup', 'lifetime', 'rekey_time', 'rand_time', 'pinghost') as $field) {
		$types[$field] = 'string';
	}
	return $types;
}

/*
 * A phase 2 edit form (ipsec_p2_form() shape) in API shape, the way the page
 * shows it ($c: field => choices for mode, proto, localid_type,
 * natlocalid_type, pfsgroup (empty when the mobile client settings set the
 * PFS group), and "keylens": algorithm => its key lengths (empty: none)).
 */
function restapi_ipsec_p2_fields(array $p, array $c) {
	$mobile = isset($p['mobile']);
	$str = function ($field) use (&$p) {
		return is_scalar($p[$field] ?? null) ? (string)$p[$field] : '';
	};
	$rows = array();
	foreach ((array)($p['ealgos'] ?? array()) as $algo) {
		$algo = (string)$algo;
		$keylens = $c['keylens'][$algo] ?? array();
		$rows[] = array('algorithm' => $algo, 'keylen' => empty($keylens) ? '' :
		    restapi_vpn_select($p["keylen_{$algo}"] ?? '', array_flip(array_merge(array('auto'), $keylens))));
	}
	/* the page fills in the mask of an empty network: 24, or 64 for IPv6 tunnels */
	$mode = restapi_vpn_select($p['mode'] ?? '', $c['mode']);
	foreach (array('local', 'natlocal', 'remote') as $prefix) {
		if ((($p["{$prefix}id_type"] ?? '') === 'network') && ($str("{$prefix}id_address") === '') && ($str("{$prefix}id_netbits") === '')) {
			$p["{$prefix}id_netbits"] = ($mode === 'tunnel6') ? '64' : '24';
		}
	}
	return array(
		'uniqid' => $str('uniqid'),
		'ikeid' => $str('ikeid'),
		'reqid' => $str('reqid'),
		'mobile' => $mobile,
		'descr' => $str('descr'),
		'disabled' => !empty($p['disabled']),
		'mode' => restapi_vpn_select($p['mode'] ?? '', $c['mode']),
		'localid_type' => restapi_vpn_select($p['localid_type'] ?? '', $c['localid_type']),
		'localid_address' => $str('localid_address'),
		'localid_netbits' => $str('localid_netbits'),
		'natlocalid_type' => restapi_vpn_select($p['natlocalid_type'] ?? '', $c['natlocalid_type']),
		'natlocalid_address' => $str('natlocalid_address'),
		'natlocalid_netbits' => $str('natlocalid_netbits'),
		'remoteid_type' => $mobile ? 'mobile' : restapi_vpn_select($p['remoteid_type'] ?? '', array('address' => 1, 'network' => 1)),
		'remoteid_address' => $mobile ? '' : $str('remoteid_address'),
		'remoteid_netbits' => $mobile ? '' : $str('remoteid_netbits'),
		'proto' => restapi_vpn_select($p['proto'] ?? '', $c['proto']),
		'encryption' => $rows,
		'halgos' => array_values(array_map('strval', array_filter((array)($p['halgos'] ?? array()), 'is_scalar'))),
		'pfsgroup' => empty($c['pfsgroup']) ? '' : restapi_vpn_select($p['pfsgroup'] ?? '', $c['pfsgroup']),
		'lifetime' => $str('lifetime'),
		'rekey_time' => $str('rekey_time'),
		'rand_time' => $str('rand_time'),
		'pinghost' => $mobile ? '' : $str('pinghost'),
		'keepalive' => !$mobile && !empty($p['keepalive']),
	);
}

/* The "encryption" rows of a phase 2 body: a list of {algorithm, keylen} (strings), or a 400. */
function restapi_ipsec_p2_rows($rows) {
	if (!is_array($rows) || !array_is_list($rows)) {
		throw new RestApiError(400, 'invalid_field', '"encryption" must be a list of {algorithm, keylen}.');
	}
	$out = array();
	foreach ($rows as $row) {
		if (is_string($row)) {
			$row = array('algorithm' => $row);
		}
		if (!is_array($row) || (array_is_list($row) && !empty($row))) {
			throw new RestApiError(400, 'invalid_field', '"encryption" must be a list of {algorithm, keylen}.');
		}
		foreach ($row as $key => $value) {
			if (!in_array($key, array('algorithm', 'keylen'), true)) {
				throw new RestApiError(400, 'unknown_field', "Unknown encryption field \"{$key}\".");
			}
			if (!is_scalar($value) && !is_null($value)) {
				throw new RestApiError(400, 'invalid_field', "Encryption field \"{$key}\" must be a string.");
			}
		}
		$out[] = array('algorithm' => (string)($row['algorithm'] ?? ''), 'keylen' => (string)($row['keylen'] ?? ''));
	}
	return $out;
}

/*
 * The phase 2 form post for $v (API shape) as the browser sends it ($c as for
 * restapi_ipsec_p2_fields()): the address and mask of a local, NAT or remote
 * network only as far as its type uses them; ealgos[] and a key length for
 * every algorithm that has them (an unchecked one posts "auto"); the hash
 * algorithms only when the page enables them (AES-CBC checked, or AH); no PFS
 * group when the mobile client settings set it; mobile entries post
 * mobile=true and have no remote network or keep alive.
 */
function restapi_ipsec_p2_post(array $v, array $c) {
	$post = array('descr' => (string)$v['descr'], 'mode' => (string)$v['mode'], 'proto' => (string)$v['proto'],
	    'lifetime' => (string)$v['lifetime'], 'rekey_time' => (string)$v['rekey_time'], 'rand_time' => (string)$v['rand_time'],
	    'ikeid' => (string)$v['ikeid'], 'uniqid' => (string)$v['uniqid']);
	if (!empty($v['disabled'])) {
		$post['disabled'] = 'yes';
	}
	if ((string)$v['reqid'] !== '') {
		$post['reqid'] = (string)$v['reqid'];
	}
	$net = function ($prefix) use ($v, &$post) {
		$post["{$prefix}id_type"] = (string)$v["{$prefix}id_type"];
		$type = (string)$v["{$prefix}id_type"];
		if (in_array($type, array('address', 'network'), true)) {
			$post["{$prefix}id_address"] = (string)$v["{$prefix}id_address"];
		}
		if ($type === 'network') {
			$post["{$prefix}id_netbits"] = (string)$v["{$prefix}id_netbits"];
		}
	};
	$net('local');
	$net('natlocal');
	if (!empty($v['mobile'])) {
		$post['mobile'] = 'true';
	} else {
		$net('remote');
		$post['pinghost'] = (string)$v['pinghost'];
		if (!empty($v['keepalive'])) {
			$post['keepalive'] = 'yes';
		}
	}
	$algos = array();
	$keylen = array();
	foreach ($v['encryption'] as $row) {
		$algos[] = (string)$row['algorithm'];
		$keylen[(string)$row['algorithm']] = (string)$row['keylen'];
	}
	if (!empty($algos)) {
		$post['ealgos'] = $algos;
	}
	foreach ($c['keylens'] as $algo => $lens) {
		if (!empty($lens)) {
			$post["keylen_{$algo}"] = (isset($keylen[$algo]) && ($keylen[$algo] !== '')) ? $keylen[$algo] : 'auto';
		}
	}
	if (!empty($v['halgos']) && (($v['proto'] !== 'esp') || in_array('aes', $algos, true))) {
		$post['halgos'] = array_values($v['halgos']);
	}
	if (!empty($c['pfsgroup'])) {
		$post['pfsgroup'] = (string)$v['pfsgroup'];
	}
	return $post;
}

/* The API types of the mobile client settings. */
function restapi_ipsec_mobile_types() {
	$types = array('user_source' => 'list', 'auth_groups' => 'list', 'user_source_choices' => 'ro',
	    'auth_groups_choices' => 'ro', 'pfs_group_choices' => 'ro', 'phase1' => 'ro', 'pending' => 'ro');
	foreach (array('enable', 'group_source', 'radiusaccounting', 'pool_enable', 'pool_enable_v6', 'radius_ip_priority_enable',
	    'radius_advanced', 'net_list_enable', 'save_passwd_enable', 'dns_domain_enable', 'dns_split_enable', 'dns_server_enable',
	    'wins_server_enable', 'pfs_group_enable', 'login_banner_enable') as $flag) {
		$types[$flag] = 'bool';
	}
	foreach (array('pool_address', 'pool_netbits', 'pool_address_v6', 'pool_netbits_v6', 'radius_retransmit_base',
	    'radius_retransmit_timeout', 'radius_retransmit_tries', 'radius_sockets', 'dns_domain', 'dns_split', 'dns_server1',
	    'dns_server2', 'dns_server3', 'dns_server4', 'wins_server1', 'wins_server2', 'pfs_group', 'login_banner') as $field) {
		$types[$field] = 'string';
	}
	return $types;
}

/* The mobile clients form (ipsec_mobile_form() shape) in API shape, the way the page shows it ($pfs_groups: the PFS group choices). */
function restapi_ipsec_mobile_fields(array $p, array $pfs_groups) {
	$out = array();
	foreach (restapi_ipsec_mobile_types() as $field => $type) {
		if ($type === 'bool') {
			$out[$field] = !empty($p[$field]);
		} elseif ($type === 'string') {
			$out[$field] = is_scalar($p[$field] ?? null) ? (string)$p[$field] : '';
		} elseif ($type === 'list') {
			$out[$field] = is_array($p[$field] ?? null) ? array_values(array_map('strval', $p[$field])) : restapi_svc_split($p[$field] ?? '');
		}
	}
	$out['radiusaccounting'] = !empty($p['radiusaccounting']);
	$out['pool_netbits'] = restapi_vpn_select(($out['pool_netbits'] !== '') ? $out['pool_netbits'] : '24', array_flip(range(32, 0)));
	$out['pool_netbits_v6'] = restapi_vpn_select(($out['pool_netbits_v6'] !== '') ? $out['pool_netbits_v6'] : '120', array_flip(range(128, 0)));
	$out['pfs_group'] = restapi_vpn_select($out['pfs_group'], $pfs_groups);
	return $out;
}

/* The API types of the advanced settings; "logging" is an object (category => level), "bypassrules" a list of {source, srcmask, destination, dstmask}. */
function restapi_ipsec_settings_types() {
	$types = array('logging' => 'object', 'bypassrules' => 'rows', 'choices' => 'ro');
	foreach (array('ikev2_retransmit_enable', 'compression', 'pkcs11support', 'enableinterfacesuse', 'acceptunencryptedmainmode',
	    'unityplugin', 'strictcrlpolicy', 'makebeforebreak', 'async_crypto', 'autoexcludelanaddress', 'ipsecbypass') as $flag) {
		$types[$flag] = 'bool';
	}
	foreach (array('uniqueids', 'filtermode', 'ikev2_retransmit_tries', 'ikev2_retransmit_timeout', 'ikev2_retransmit_base',
	    'ikev2_retransmit_jitter', 'ikev2_retransmit_limit', 'maxexchange', 'dns-interval', 'port', 'port_nat_t') as $field) {
		$types[$field] = 'string';
	}
	return $types;
}

/*
 * The advanced settings form (ipsec_settings_form() shape plus async_crypto)
 * in API shape, the way the page shows it ($c: "logging" => the levels,
 * "categories" => the log categories, "uniqueids", "filtermode" => choices).
 */
function restapi_ipsec_settings_fields(array $p, array $c) {
	$out = array();
	foreach (restapi_ipsec_settings_types() as $field => $type) {
		if ($type === 'bool') {
			$out[$field] = !empty($p[$field]);
		} elseif ($type === 'string') {
			$out[$field] = is_scalar($p[$field] ?? null) ? (string)$p[$field] : '';
		}
	}
	$out['logging'] = array();
	foreach (array_keys($c['categories']) as $cat) {
		$out['logging'][$cat] = restapi_vpn_select($p['logging'][$cat] ?? '', $c['logging']);
	}
	$out['uniqueids'] = restapi_vpn_select($out['uniqueids'], $c['uniqueids']);
	$out['filtermode'] = restapi_vpn_select($out['filtermode'], $c['filtermode']);
	$out['async_crypto'] = (($p['async_crypto'] ?? '') === 'enabled');
	$out['autoexcludelanaddress'] = empty($p['noshuntlaninterfaces']);
	$out['bypassrules'] = array();
	foreach ((array)($p['bypassrules']['rule'] ?? array()) as $rule) {
		$out['bypassrules'][] = array('source' => (string)($rule['source'] ?? ''), 'srcmask' => (string)($rule['srcmask'] ?? ''),
		    'destination' => (string)($rule['destination'] ?? ''), 'dstmask' => (string)($rule['dstmask'] ?? ''));
	}
	return $out;
}

/* The "bypassrules" of a body: a list of {source, srcmask, destination, dstmask} (strings), or a 400. */
function restapi_ipsec_bypass_rows($rows) {
	$fields = array('source', 'srcmask', 'destination', 'dstmask');
	if (!is_array($rows) || !array_is_list($rows)) {
		throw new RestApiError(400, 'invalid_field', '"bypassrules" must be a list of {source, srcmask, destination, dstmask}.');
	}
	$out = array();
	foreach ($rows as $row) {
		if (!is_array($row) || (array_is_list($row) && !empty($row))) {
			throw new RestApiError(400, 'invalid_field', '"bypassrules" must be a list of {source, srcmask, destination, dstmask}.');
		}
		$item = array();
		foreach ($row as $key => $value) {
			if (!in_array($key, $fields, true)) {
				throw new RestApiError(400, 'unknown_field', "Unknown bypass rule field \"{$key}\".");
			}
			if (!is_scalar($value) && !is_null($value)) {
				throw new RestApiError(400, 'invalid_field', "Bypass rule field \"{$key}\" must be a string.");
			}
			$item[$key] = (string)$value;
		}
		foreach ($fields as $field) {
			$item[$field] = $item[$field] ?? '';
		}
		$out[] = $item;
	}
	return $out;
}

/* The "logging" of a body merged over the current levels; an unknown category is a 400. */
function restapi_ipsec_logging_body($logging, array $current) {
	if (!is_array($logging) || (array_is_list($logging) && !empty($logging))) {
		throw new RestApiError(400, 'invalid_field', '"logging" must be an object of category => level.');
	}
	foreach ($logging as $cat => $level) {
		if (!array_key_exists($cat, $current)) {
			throw new RestApiError(400, 'unknown_field', "Unknown log category \"{$cat}\".");
		}
		if (!is_scalar($level)) {
			throw new RestApiError(400, 'invalid_field', "Log level of \"{$cat}\" must be a string.");
		}
		$current[$cat] = (string)$level;
	}
	return $current;
}

/* The advanced settings form post for $v (API shape) as the browser sends it. */
function restapi_ipsec_settings_post(array $v) {
	$post = restapi_svc_post($v, restapi_ipsec_settings_types());
	foreach ($v['logging'] as $cat => $level) {
		$post["logging_{$cat}"] = (string)$level;
	}
	foreach (array_values($v['bypassrules']) as $i => $rule) {
		foreach (array('source', 'srcmask', 'destination', 'dstmask') as $field) {
			$post["{$field}{$i}"] = (string)$rule[$field];
		}
	}
	return $post;
}

/* ------------------------------------------------------------------ */
/* IPsec tunnel editing                                                */
/* ------------------------------------------------------------------ */

/* The choices of the phase 1 page's selects for a mobile or site-to-site entry. */
function restapi_ipsec_p1_choices($mobile) {
	global $p1_ealgos, $p1_halgos, $p1_dhgroups, $ipsec_startactions, $ipsec_closeactions;
	return array(
		'iketype' => array('ikev1' => 'IKEv1', 'ikev2' => 'IKEv2', 'auto' => 'Auto'),
		'protocol' => array('inet' => 'IPv4', 'inet6' => 'IPv6', 'both' => 'Both (Dual Stack)'),
		'interface' => ipsec_p1_interface_list(),
		'authentication_method' => ipsec_p1_auth_method_list($mobile),
		'mode' => array('main' => 'Main', 'aggressive' => 'Aggressive'),
		'myid_type' => ipsec_p1_myid_list(),
		'peerid_type' => ipsec_p1_peerid_list(),
		'certref' => cert_build_list('cert', 'IPsec'),
		'caref' => cert_build_list('ca', 'IPsec'),
		'startaction' => $ipsec_startactions,
		'closeaction' => $ipsec_closeactions,
		'nat_traversal' => array('on' => 'Auto', 'force' => 'Force'),
		'mobike' => array('on' => 'Enable', 'off' => 'Disable'),
		'ealgo' => ipsec_p1_eal_list(),
		'halgo' => $p1_halgos,
		'dhgroup' => $p1_dhgroups,
	);
}

/* The algorithms of the phase 1 page that have key lengths. */
function restapi_ipsec_p1_keylen_algos() {
	global $p1_ealgos;
	return array_keys(array_filter($p1_ealgos, function ($a) {
		return is_array($a['keysel'] ?? null);
	}));
}

function restapi_ipsec_p1_form_fields(array $form) {
	return restapi_ipsec_p1_fields($form, restapi_ipsec_p1_choices(isset($form['mobile'])));
}

function restapi_ipsec_mask_fields(array $fields, array $secrets) {
	foreach ($secrets as $secret) {
		if (array_key_exists($secret, $fields)) {
			$fields[$secret] = restapi_svc_mask($fields[$secret]);
		}
	}
	return $fields;
}

/* Create ($pos null) or update a phase 1 through the page's save; returns the tunnel's ikeid. */
function restapi_ipsec_p1_write(array $body, $pos) {
	foreach (restapi_ipsec_p1_form_secrets() as $secret) {
		$body = restapi_vpn_secret_body($body, $secret);
	}
	if ($pos === null) {
		$mobile = false;
		if (array_key_exists('mobile', $body)) {
			if (!is_bool($body['mobile'])) {
				throw new RestApiError(400, 'invalid_field', 'Field "mobile" must be a boolean.');
			}
			$mobile = $body['mobile'];
			unset($body['mobile']);
		}
		$old = null;
		$form = ipsec_p1_form(null, false, $mobile);
	} else {
		$old = config_get_path("ipsec/phase1/{$pos}");
		$form = ipsec_p1_form($old);
	}
	$current = restapi_ipsec_p1_form_fields($form);
	$rows = null;
	if (array_key_exists('encryption', $body)) {
		$rows = restapi_ipsec_p1_rows($body['encryption']);
	}
	$values = restapi_svc_merge($body, $current, restapi_ipsec_p1_types());
	if ($rows !== null) {
		$values['encryption'] = $rows;
	}
	$post = restapi_ipsec_p1_post($values, restapi_ipsec_p1_keylen_algos());
	$errors = ipsec_p1_save($post, $pos, $old);
	if (!empty($errors)) {
		throw restapi_validation_error($errors);
	}
	return (string)$post['ikeid'];
}

/* A tunnel for the API: its stored entry (secrets masked), its phase 2 entries and its edit form ("fields", secrets masked). */
function restapi_ipsec_tunnel_full($pos) {
	$form = ipsec_p1_form(config_get_path("ipsec/phase1/{$pos}"));
	return restapi_ipsec_p1_out($pos, true) + array('fields' =>
	    restapi_ipsec_mask_fields(restapi_ipsec_p1_form_fields($form), restapi_ipsec_p1_form_secrets()));
}

function restapi_h_ipsec_tunnel_create($req) {
	$ikeid = restapi_ipsec_p1_write($req['body'], null);
	$extra = restapi_ipsec_maybe_apply($req);
	return array('status' => 201, 'data' => restapi_ipsec_tunnel_full(restapi_ipsec_p1_pos($ikeid)) + $extra);
}

function restapi_h_ipsec_tunnel_update($req) {
	$ikeid = restapi_ipsec_p1_write($req['body'], restapi_ipsec_p1_pos($req['params']['ikeid']));
	$extra = restapi_ipsec_maybe_apply($req);
	return array('data' => restapi_ipsec_tunnel_full(restapi_ipsec_p1_pos($ikeid)) + $extra);
}

/* The choices of the phase 2 page for an entry (mobile: the PFS group may be set by the mobile client settings). */
function restapi_ipsec_p2_choices($mobile) {
	global $p2_modes, $p2_protos, $p2_ealgos, $p2_pfskeygroups;
	$keylens = array();
	foreach ($p2_ealgos as $algo => $data) {
		$keylens[$algo] = ipsec_ealgo_keylens($data);
	}
	return array(
		'mode' => $p2_modes,
		'proto' => $p2_protos,
		'localid_type' => ipsec_p2_localid_types(),
		'natlocalid_type' => ipsec_p2_natlocalid_types(),
		'keylens' => $keylens,
		'pfsgroup' => (!$mobile || empty(config_get_path('ipsec/client/pfs_group'))) ? $p2_pfskeygroups : array(),
	);
}

/* Create (the tunnel's phase 1 at $p1pos, $pos null) or update a phase 2 through the page's save; returns its uniqid. */
function restapi_ipsec_p2_write(array $body, $p1pos, $pos) {
	$p1 = config_get_path("ipsec/phase1/{$p1pos}");
	if ($pos === null) {
		$form = ipsec_p2_form(null, $p1['ikeid'], isset($p1['mobile']));
	} else {
		$form = ipsec_p2_form(config_get_path("ipsec/phase2/{$pos}"));
	}
	$c = restapi_ipsec_p2_choices(isset($form['mobile']));
	$current = restapi_ipsec_p2_fields($form, $c);
	$rows = null;
	if (array_key_exists('encryption', $body)) {
		$rows = restapi_ipsec_p2_rows($body['encryption']);
	}
	$values = restapi_svc_merge($body, $current, restapi_ipsec_p2_types());
	if ($rows !== null) {
		$values['encryption'] = $rows;
	}
	$post = restapi_ipsec_p2_post($values, $c);
	$errors = ipsec_p2_save($post, $pos);
	if (!empty($errors)) {
		throw restapi_validation_error($errors);
	}
	return (string)$post['uniqid'];
}

/* A phase 2 entry for the API: its stored fields and its edit form ("fields"). */
function restapi_ipsec_p2_full($pos) {
	$form = ipsec_p2_form(config_get_path("ipsec/phase2/{$pos}"));
	return restapi_ipsec_p2_out($pos, true) + array('fields' => restapi_ipsec_p2_fields($form,
	    restapi_ipsec_p2_choices(isset($form['mobile']))));
}

function restapi_h_ipsec_p2_create($req) {
	$ikeid = $req['params']['ikeid'];
	$uniqid = restapi_ipsec_p2_write($req['body'], restapi_ipsec_p1_pos($ikeid), null);
	$extra = restapi_ipsec_maybe_apply($req);
	return array('status' => 201, 'data' => restapi_ipsec_p2_full(restapi_ipsec_p2_pos($ikeid, $uniqid)) + $extra);
}

function restapi_h_ipsec_p2_update($req) {
	$ikeid = $req['params']['ikeid'];
	$uniqid = restapi_ipsec_p2_write($req['body'], restapi_ipsec_p1_pos($ikeid),
	    restapi_ipsec_p2_pos($ikeid, $req['params']['uniqid']));
	$extra = restapi_ipsec_maybe_apply($req);
	return array('data' => restapi_ipsec_p2_full(restapi_ipsec_p2_pos($ikeid, $uniqid)) + $extra);
}

/* ------------------------------------------------------------------ */
/* IPsec mobile clients                                                */
/* ------------------------------------------------------------------ */

function restapi_ipsec_mobile_out() {
	global $p2_pfskeygroups;
	$out = restapi_ipsec_mobile_fields(ipsec_mobile_form(), $p2_pfskeygroups);
	$out['user_source_choices'] = restapi_svc_choices(ipsec_mobile_user_sources());
	$out['auth_groups_choices'] = restapi_svc_choices(ipsec_mobile_auth_groups());
	$out['pfs_group_choices'] = restapi_svc_choices($p2_pfskeygroups);
	$out['phase1'] = null;
	foreach (config_get_path('ipsec/phase1', []) as $p1) {
		if (is_array($p1) && isset($p1['mobile'])) {
			$out['phase1'] = (string)($p1['ikeid'] ?? '');
		}
	}
	$out['pending'] = is_subsystem_dirty('ipsec');
	return $out;
}

function restapi_h_ipsec_mobile_get($req) {
	return array('data' => restapi_ipsec_mobile_out());
}

function restapi_h_ipsec_mobile_set($req) {
	$types = restapi_ipsec_mobile_types();
	$current = restapi_ipsec_mobile_out();
	$values = restapi_svc_merge($req['body'], $current, $types);
	$post = restapi_svc_post($values, $types);
	$errors = ipsec_mobile_save($post);
	if (!empty($errors)) {
		throw restapi_validation_error($errors);
	}
	$extra = array();
	if (restapi_want_apply($req)) {
		$extra = array('apply' => array('applied' => (ipsec_mobile_apply() == 0)));
	}
	return array('data' => restapi_ipsec_mobile_out() + $extra);
}

/* ------------------------------------------------------------------ */
/* IPsec advanced settings                                             */
/* ------------------------------------------------------------------ */

function restapi_ipsec_settings_out() {
	global $ipsec_log_cats, $ipsec_log_sevs, $ipsec_idhandling, $ipsec_filtermodes;
	$form = ipsec_settings_form();
	$form['async_crypto'] = config_get_path('ipsec/async_crypto', 'disabled');
	$c = array('categories' => $ipsec_log_cats, 'logging' => $ipsec_log_sevs, 'uniqueids' => $ipsec_idhandling,
	    'filtermode' => $ipsec_filtermodes);
	$out = restapi_ipsec_settings_fields($form, $c);
	$out['choices'] = array('logging' => restapi_svc_choices($ipsec_log_sevs), 'categories' => restapi_svc_choices($ipsec_log_cats),
	    'uniqueids' => restapi_svc_choices($ipsec_idhandling), 'filtermode' => restapi_svc_choices($ipsec_filtermodes));
	return $out;
}

function restapi_h_ipsec_settings_get($req) {
	return array('data' => restapi_ipsec_settings_out());
}

function restapi_h_ipsec_settings_set($req) {
	$body = $req['body'];
	$current = restapi_ipsec_settings_out();
	$logging = $current['logging'];
	$rules = $current['bypassrules'];
	if (array_key_exists('logging', $body)) {
		$logging = restapi_ipsec_logging_body($body['logging'], $current['logging']);
	}
	if (array_key_exists('bypassrules', $body)) {
		$rules = restapi_ipsec_bypass_rows($body['bypassrules']);
	}
	$values = restapi_svc_merge($body, $current, restapi_ipsec_settings_types());
	$values['logging'] = $logging;
	$values['bypassrules'] = $rules;
	$rv = ipsec_settings_save(restapi_ipsec_settings_post($values));
	if (!empty($rv['input_errors'])) {
		throw restapi_validation_error($rv['input_errors']);
	}
	return array('data' => restapi_ipsec_settings_out() + array('applied' => ($rv['retval'] == 0)));
}
