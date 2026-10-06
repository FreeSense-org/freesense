<?php
/*
 * restapi/routes_ddns.inc
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
 * REST API v1 routes for the Dynamic DNS and RFC 2136 clients (area
 * services.ddns) of Services > Dynamic DNS.
 *
 * Writes go through the functions the pages call (services_dyndns.inc), so
 * validation is the GUI's. Like the pages, saving an enabled client updates
 * it at once (a Dynamic DNS client contacts its provider, an RFC 2136 client
 * runs nsupdate), toggling a Dynamic DNS client runs the Dynamic DNS
 * updates, and POST .../update is the edit page's "Save & Force Update".
 * Clients are addressed by their position in config.xml. "enable" is true
 * for an enabled client (the Dynamic DNS form's checkbox is "Disable").
 * The Dynamic DNS password and the RFC 2136 key read as "(set)" and sending
 * "(set)" keeps them. Reads never contact a provider or a check IP service:
 * "cached_ip"/"cached_ipv6" are the addresses of the last update.
 */

require_once('restapi/framework.inc');

function restapi_routes_ddns() {
	$area = 'services.ddns';
	$dyndns_body = array('type' => 'object', 'description' => 'The Dynamic DNS client form: enable, wildcard, proxied, ' .
	    'verboselog, curl_ipresolve_v4, curl_ssl_verifypeer, curl_proxy (booleans); type (from GET .../choices), interface, ' .
	    'requestif (custom types), check_ip_mode (default|always|never), host, domainname, mx, username, password ("(set)" keeps ' .
	    'it), zoneid, updateurl, resultmatch, ttl, maxcacheage, descr. Omitted fields keep their value (a new client starts from ' .
	    'the form\'s defaults: enabled, first interface, verify TLS). Saving an enabled client updates its provider at once.');
	$rfc_body = array('type' => 'object', 'description' => 'The RFC 2136 client form: enable, usetcp, usepublicip (booleans); ' .
	    'interface, host, zone, ttl, keyname, keyalgorithm, keydata ("(set)" keeps it), server ("address" or "address port"), ' .
	    'updatesource, updatesourcefamily, recordtype (A|AAAA|both), descr (choices from GET .../choices). Omitted fields keep ' .
	    'their value (a new client starts disabled). Saving runs the RFC 2136 updates of the enabled clients at once.');
	$r = function ($method, $path, $handler, $page, $summary, $write = false, $body = null) use ($area) {
		return restapi_route($method, $path, $handler, array('page' => $page, 'area' => $area, 'write' => $write,
		    'summary' => $summary, 'body' => $body));
	};

	return array(
		$r('GET', '/v1/services/dyndns/choices', 'restapi_h_dyndns_choices', 'services_dyndns_edit.php',
		    'Choices of the Dynamic DNS client form (service types, interfaces, check IP modes)'),
		$r('GET', '/v1/services/dyndns/clients', 'restapi_h_dyndns_list', 'services_dyndns.php',
		    'List Dynamic DNS clients (passwords read as "(set)")'),
		$r('GET', '/v1/services/dyndns/clients/{id}', 'restapi_h_dyndns_get', 'services_dyndns.php', 'Get a Dynamic DNS client'),
		$r('POST', '/v1/services/dyndns/clients', 'restapi_h_dyndns_create', 'services_dyndns_edit.php',
		    'Create a Dynamic DNS client (an enabled client is updated at once)', true, $dyndns_body),
		$r('PUT', '/v1/services/dyndns/clients/{id}', 'restapi_h_dyndns_update', 'services_dyndns_edit.php',
		    'Update a Dynamic DNS client (omitted fields keep their value; an enabled client is updated at once)', true, $dyndns_body),
		$r('DELETE', '/v1/services/dyndns/clients/{id}', 'restapi_h_dyndns_delete', 'services_dyndns.php',
		    'Delete a Dynamic DNS client and its cached address', true),
		$r('POST', '/v1/services/dyndns/clients/{id}/toggle', 'restapi_h_dyndns_toggle', 'services_dyndns.php',
		    'Enable or disable a Dynamic DNS client (runs the Dynamic DNS updates, like the list page)', true),
		$r('POST', '/v1/services/dyndns/clients/{id}/update', 'restapi_h_dyndns_force', 'services_dyndns_edit.php',
		    'Force an update of a Dynamic DNS client (the edit page\'s "Save & Force Update"; nothing is sent for a disabled client)', true),

		$r('GET', '/v1/services/rfc2136/choices', 'restapi_h_rfc2136_choices', 'services_rfc2136_edit.php',
		    'Choices of the RFC 2136 client form (interfaces, key algorithms, update sources, families, record types)'),
		$r('GET', '/v1/services/rfc2136/clients', 'restapi_h_rfc2136_list', 'services_rfc2136.php',
		    'List RFC 2136 clients (keys read as "(set)")'),
		$r('GET', '/v1/services/rfc2136/clients/{id}', 'restapi_h_rfc2136_get', 'services_rfc2136.php', 'Get an RFC 2136 client'),
		$r('POST', '/v1/services/rfc2136/clients', 'restapi_h_rfc2136_create', 'services_rfc2136_edit.php',
		    'Create an RFC 2136 client (runs the updates of the enabled clients)', true, $rfc_body),
		$r('PUT', '/v1/services/rfc2136/clients/{id}', 'restapi_h_rfc2136_update', 'services_rfc2136_edit.php',
		    'Update an RFC 2136 client (omitted fields keep their value; runs the updates of the enabled clients)', true, $rfc_body),
		$r('DELETE', '/v1/services/rfc2136/clients/{id}', 'restapi_h_rfc2136_delete', 'services_rfc2136.php',
		    'Delete an RFC 2136 client and its cached addresses', true),
		$r('POST', '/v1/services/rfc2136/clients/{id}/toggle', 'restapi_h_rfc2136_toggle', 'services_rfc2136.php',
		    'Enable or disable an RFC 2136 client (like the list page, no update is run)', true),
		$r('POST', '/v1/services/rfc2136/clients/{id}/update', 'restapi_h_rfc2136_force', 'services_rfc2136_edit.php',
		    'Force an update of an RFC 2136 client (the edit page\'s "Save & Force Update"; nothing is sent for a disabled client)', true),
	);
}

/* ------------------------------------------------------------------ */
/* Shared                                                              */
/* ------------------------------------------------------------------ */

/* The address in a client's cache file ("address|time"), or null. */
function restapi_ddns_cached($file) {
	if (!is_file($file)) {
		return null;
	}
	$parts = explode('|', (string)@file_get_contents($file));
	return ($parts[0] !== '') ? $parts[0] : null;
}

/* A secret for a form post: "(set)" keeps the stored one. */
function restapi_ddns_secret_body(array $body, $field) {
	if (array_key_exists($field, $body) && ($body[$field] === '(set)')) {
		unset($body[$field]);
	}
	return $body;
}

/* ------------------------------------------------------------------ */
/* Dynamic DNS                                                         */
/* ------------------------------------------------------------------ */

function restapi_dyndns_types() {
	$types = array('id' => 'ro', 'cached_ip' => 'ro', 'cached_ipv6' => 'ro');
	foreach (array('enable', 'wildcard', 'proxied', 'verboselog', 'curl_ipresolve_v4', 'curl_ssl_verifypeer', 'curl_proxy') as $flag) {
		$types[$flag] = 'bool';
	}
	foreach (array('type', 'interface', 'requestif', 'check_ip_mode', 'host', 'domainname', 'mx', 'username', 'password',
	    'zoneid', 'updateurl', 'resultmatch', 'ttl', 'maxcacheage', 'descr') as $field) {
		$types[$field] = 'string';
	}
	return $types;
}

function restapi_dyndns_clients() {
	$list = config_get_path('dyndnses/dyndns', []);
	return is_array($list) ? $list : array();
}

/* A new client as the empty edit form posts it. */
function restapi_dyndns_defaults() {
	$first = (string)(array_key_first(dyndns_build_if_list()) ?? '');
	return array('enable' => true, 'type' => '', 'interface' => $first, 'requestif' => $first, 'check_ip_mode' => 'default',
	    'host' => '', 'domainname' => '', 'mx' => '', 'wildcard' => false, 'proxied' => false, 'verboselog' => false,
	    'curl_ipresolve_v4' => false, 'curl_ssl_verifypeer' => true, 'username' => '', 'password' => '', 'zoneid' => '',
	    'updateurl' => '', 'resultmatch' => '', 'ttl' => '', 'maxcacheage' => '', 'curl_proxy' => false, 'descr' => '');
}

/* Client $id as the edit form shows it, with the stored (base64) password; selects read as the form preselects them. */
function restapi_dyndns_settings($id) {
	$p = dyndns_client_settings($id);
	$iflist = dyndns_build_if_list();
	$out = array();
	foreach (restapi_dyndns_types() as $key => $type) {
		if ($type === 'bool') {
			$out[$key] = !empty($p[$key]);
		} elseif ($type === 'string') {
			$out[$key] = (string)($p[$key] ?? '');
		}
	}
	/* The form's checkbox is "Disable this client". */
	$out['enable'] = empty($p['enable']);
	$out['interface'] = restapi_select_choice($out['interface'], $iflist);
	$out['requestif'] = restapi_select_choice($out['requestif'], $iflist);
	$out['check_ip_mode'] = restapi_select_choice($out['check_ip_mode'], dyndns_check_ip_mode_list());
	return $out;
}

function restapi_dyndns_out($id) {
	$out = array('id' => (int)$id) + restapi_dyndns_settings($id);
	$out['password'] = restapi_svc_mask($out['password']);
	list($v4, $v6) = dyndns_client_cache_files(config_get_path("dyndnses/dyndns/{$id}"));
	$out['cached_ip'] = restapi_ddns_cached($v4);
	$out['cached_ipv6'] = restapi_ddns_cached($v6);
	return $out;
}

/*
 * API values as the edit form's post. The password is posted twice
 * (field and confirmation); null keeps the stored one (the form's
 * placeholder). The "Disable" checkbox is the inverse of "enable".
 */
function restapi_dyndns_post(array $values, $password) {
	$types = restapi_dyndns_types();
	$post = restapi_svc_post($values, $types);
	unset($post['enable'], $post['password']);
	if (empty($values['enable'])) {
		$post['enable'] = 'yes';
	}
	$post['passwordfld'] = ($password === null) ? DMYPWD : (string)$password;
	$post['passwordfld_confirm'] = $post['passwordfld'];
	$post['save'] = 'Save';
	return $post;
}

function restapi_dyndns_save($id, array $body, $force, $status) {
	$body = restapi_ddns_secret_body($body, 'password');
	$types = restapi_dyndns_types();
	$current = ($id === null) ? restapi_dyndns_defaults() : restapi_dyndns_settings($id);
	$values = restapi_svc_merge($body, $current, $types);
	$password = array_key_exists('password', $body) ? $values['password'] : (($id === null) ? '' : null);
	$post = restapi_dyndns_post($values, $password);
	if ($force) {
		$post['force'] = 'Save & Force Update';
	}
	$rv = dyndns_save_client($post, $id);
	if (!empty($rv['input_errors'])) {
		throw restapi_validation_error($rv['input_errors']);
	}
	return array('status' => $status, 'data' => restapi_dyndns_out($rv['id']));
}

function restapi_dyndns_id($req) {
	return restapi_index_param($req['params']['id'], restapi_dyndns_clients(), 'Dynamic DNS client');
}

function restapi_h_dyndns_choices($req) {
	return array('data' => array(
		'type' => restapi_svc_choices(dyndns_type_list()),
		'interface' => restapi_svc_choices(dyndns_build_if_list()),
		'check_ip_mode' => dyndns_check_ip_mode_list(),
	));
}

function restapi_h_dyndns_list($req) {
	$out = array();
	foreach (restapi_dyndns_clients() as $id => $conf) {
		if (is_array($conf) && !empty($conf)) {
			$out[] = restapi_dyndns_out($id);
		}
	}
	return array('data' => $out);
}

function restapi_h_dyndns_get($req) {
	return array('data' => restapi_dyndns_out(restapi_dyndns_id($req)));
}

function restapi_h_dyndns_create($req) {
	if (($req['body']['password'] ?? null) === '(set)') {
		throw new RestApiError(400, 'invalid_field', 'A new client has no stored password to keep.');
	}
	return restapi_dyndns_save(null, $req['body'], false, 201);
}

function restapi_h_dyndns_update($req) {
	return restapi_dyndns_save(restapi_dyndns_id($req), $req['body'], false, 200);
}

function restapi_h_dyndns_force($req) {
	$id = restapi_dyndns_id($req);
	$result = restapi_dyndns_save($id, array(), true, 200);
	$result['data']['update_sent'] = $result['data']['enable'];
	return $result;
}

function restapi_h_dyndns_delete($req) {
	$id = restapi_dyndns_id($req);
	dyndns_delete_client((string)$id);
	return array('data' => array('deleted' => $id));
}

function restapi_h_dyndns_toggle($req) {
	$id = restapi_dyndns_id($req);
	dyndns_toggle_client((string)$id);
	return array('data' => restapi_dyndns_out($id));
}

/* ------------------------------------------------------------------ */
/* RFC 2136                                                            */
/* ------------------------------------------------------------------ */

function restapi_rfc2136_types() {
	$types = array('id' => 'ro', 'cached_ip' => 'ro', 'cached_ipv6' => 'ro');
	foreach (array('enable', 'usetcp', 'usepublicip') as $flag) {
		$types[$flag] = 'bool';
	}
	foreach (array('interface', 'host', 'zone', 'ttl', 'keyname', 'keyalgorithm', 'keydata', 'server', 'updatesource',
	    'updatesourcefamily', 'recordtype', 'descr') as $field) {
		$types[$field] = 'string';
	}
	return $types;
}

function restapi_rfc2136_clients() {
	$list = config_get_path('dnsupdates/dnsupdate', []);
	return is_array($list) ? $list : array();
}

/* A new client as the empty edit form posts it (disabled, first interface and algorithm). */
function restapi_rfc2136_defaults() {
	return array('enable' => false, 'interface' => (string)(array_key_first(dyndns_build_if_list()) ?? ''), 'host' => '',
	    'zone' => '', 'ttl' => '', 'keyname' => '', 'keyalgorithm' => (string)array_key_first(rfc2136_key_algos()),
	    'keydata' => '', 'server' => '', 'usetcp' => false, 'usepublicip' => false, 'updatesource' => '',
	    'updatesourcefamily' => '', 'recordtype' => 'both', 'descr' => '');
}

/* Client $id as the edit form shows it, with the key in clear; selects read as the form preselects them. */
function restapi_rfc2136_settings($id) {
	$p = rfc2136_client_settings($id);
	$out = array();
	foreach (restapi_rfc2136_types() as $key => $type) {
		if ($type === 'bool') {
			$out[$key] = !empty($p[$key]);
		} elseif ($type === 'string') {
			$out[$key] = (string)($p[$key] ?? '');
		}
	}
	$out['interface'] = restapi_select_choice($out['interface'], dyndns_build_if_list());
	$out['keyalgorithm'] = restapi_select_choice($out['keyalgorithm'], rfc2136_key_algos());
	$out['updatesource'] = restapi_select_choice($out['updatesource'], rfc2136_build_us_list());
	$out['updatesourcefamily'] = restapi_select_choice($out['updatesourcefamily'], rfc2136_source_families());
	return $out;
}

function restapi_rfc2136_out($id) {
	$out = array('id' => (int)$id) + restapi_rfc2136_settings($id);
	$out['keydata'] = restapi_svc_mask($out['keydata']);
	list($v4, $v6) = rfc2136_client_cache_files(config_get_path("dnsupdates/dnsupdate/{$id}"));
	$out['cached_ip'] = restapi_ddns_cached($v4);
	$out['cached_ipv6'] = restapi_ddns_cached($v6);
	return $out;
}

function restapi_rfc2136_save($id, array $body, $force, $status) {
	$body = restapi_ddns_secret_body($body, 'keydata');
	$types = restapi_rfc2136_types();
	$current = ($id === null) ? restapi_rfc2136_defaults() : restapi_rfc2136_settings($id);
	$values = restapi_svc_merge($body, $current, $types);
	$post = restapi_svc_post($values, $types);
	$post['save'] = 'Save';
	if ($force) {
		$post['force'] = 'Save & Force Update';
	}
	$rv = rfc2136_save_client($post, $id);
	if (!empty($rv['input_errors'])) {
		throw restapi_validation_error($rv['input_errors']);
	}
	return array('status' => $status, 'data' => restapi_rfc2136_out($rv['id']));
}

function restapi_rfc2136_id($req) {
	return restapi_index_param($req['params']['id'], restapi_rfc2136_clients(), 'RFC 2136 client');
}

function restapi_h_rfc2136_choices($req) {
	return array('data' => array(
		'interface' => restapi_svc_choices(dyndns_build_if_list()),
		'keyalgorithm' => rfc2136_key_algos(),
		'updatesource' => restapi_svc_choices(rfc2136_build_us_list()),
		'updatesourcefamily' => rfc2136_source_families(),
		'recordtype' => rfc2136_record_types(),
	));
}

function restapi_h_rfc2136_list($req) {
	$out = array();
	foreach (restapi_rfc2136_clients() as $id => $conf) {
		if (is_array($conf) && !empty($conf)) {
			$out[] = restapi_rfc2136_out($id);
		}
	}
	return array('data' => $out);
}

function restapi_h_rfc2136_get($req) {
	return array('data' => restapi_rfc2136_out(restapi_rfc2136_id($req)));
}

function restapi_h_rfc2136_create($req) {
	if (($req['body']['keydata'] ?? null) === '(set)') {
		throw new RestApiError(400, 'invalid_field', 'A new client has no stored key to keep.');
	}
	return restapi_rfc2136_save(null, $req['body'], false, 201);
}

function restapi_h_rfc2136_update($req) {
	return restapi_rfc2136_save(restapi_rfc2136_id($req), $req['body'], false, 200);
}

function restapi_h_rfc2136_force($req) {
	$id = restapi_rfc2136_id($req);
	$result = restapi_rfc2136_save($id, array(), true, 200);
	$result['data']['update_sent'] = $result['data']['enable'];
	return $result;
}

function restapi_h_rfc2136_delete($req) {
	$id = restapi_rfc2136_id($req);
	rfc2136_delete_client((string)$id);
	return array('data' => array('deleted' => $id));
}

function restapi_h_rfc2136_toggle($req) {
	$id = restapi_rfc2136_id($req);
	rfc2136_toggle_client((string)$id);
	return array('data' => restapi_rfc2136_out($id));
}
