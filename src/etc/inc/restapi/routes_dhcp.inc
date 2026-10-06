<?php
/*
 * restapi/routes_dhcp.inc
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
 * REST API v1 routes for the DHCP and DHCPv6 servers, area services.dhcp:
 * the global (Kea) settings, the interfaces the servers can serve and the
 * static mappings of an interface.
 *
 * Writes go through the functions the DHCP pages call (services_dhcp.inc),
 * so validation is the GUI's, for the Kea and the ISC backend alike. Like
 * the GUI, changes are staged (the "dhcpd" / "dhcpd6" dirty flag) until
 * POST /v1/services/dhcp[v6]/apply; add ?apply=true to a write to apply it
 * at once. Objects carry the edit forms' fields for the running backend
 * (checkboxes as booleans; a select without a valid stored value reads as
 * the option the form preselects); a PUT changes only the fields it sends.
 * Static mappings are addressed by their position on the interface (the
 * list is kept sorted by address, so a write returns the mapping's new id)
 * or by their MAC address (DHCP) / DUID (DHCPv6). The dynamic DNS key of a
 * mapping reads as "(set)"; sending "(set)" keeps it.
 *
 * The front controller loads services_dhcp.inc at file scope.
 */

require_once('restapi/framework.inc');
require_once('restapi/routes_services.inc');
require_once('restapi/routes_ntp.inc');
require_once('restapi/routes_ddns.inc');

function restapi_routes_dhcp() {
	$area = 'services.dhcp';
	$apply_query = array('apply' => 'true to apply the change at once (default: stage it like the GUI)');
	$route = function ($method, $path, $handler, $page, $summary, $write = false, $body = null) use ($area, $apply_query) {
		$opts = array('page' => $page, 'area' => $area, 'summary' => $summary);
		if ($write) {
			$opts['write'] = true;
			$opts['body'] = $body;
			if (!preg_match('#/apply$#', $path)) {
				$opts['query'] = $apply_query;
			}
		}
		return restapi_route($method, $path, $handler, $opts);
	};

	$settings_body = array('type' => 'object', 'description' => 'The Kea settings form: dnsreg, earlydnsreg, ha_enable, ' .
	    'ha_tls, ha_mutualtls (booleans); loglevel (from "loglevel_choices"), ha_role (primary|standby), ha_localname, ' .
	    'ha_localip, ha_localport, ha_remotename, ha_remoteip, ha_remoteport, ha_heartbeatdelay, ha_maxresponsedelay, ' .
	    'ha_maxackdelay, ha_maxunackedclients, ha_maxrejectedleaseupdates, ha_scertref and ha_ccertref (from ' .
	    '"scertref_choices" / "ccertref_choices"), custom_kea_config (JSON text; only users allowed to edit custom Kea ' .
	    'configuration may change it). Omitted fields keep their value. Only with the Kea DHCP backend.');
	$map_body = array('type' => 'object', 'description' => 'The static mapping edit form: mac, cid, ipaddr, hostname, ' .
	    'descr, earlydnsregpolicy (default|enable|disable), gateway, domain, domainsearchlist, tftp, ldap, filename, ' .
	    'filename32, filename64, filename32arm, filename64arm, uefihttpboot, rootpath (strings); arp_table_static_entry, ' .
	    'netboot (booleans); winsserver (at most 2), dnsserver (at most 4), ntpserver (at most 4) (lists of addresses). ' .
	    'Kea backend: custom_kea_config (JSON text). ISC backend: deftime, maxtime, nextserver, ddnsdomain, ' .
	    'ddnsdomainprimary, ddnsdomainsecondary, ddnsdomainkeyname, ddnsdomainkeyalgorithm, ddnsdomainkey ("(set)" keeps ' .
	    'it) (strings); ddnsupdate, ddnsforcehostname (booleans); numberoptions (list of {number, type, value}). ' .
	    'Omitted fields keep their value (create: empty).');
	$map6_body = array('type' => 'object', 'description' => 'The DHCPv6 static mapping edit form: duid (required), ' .
	    'ipaddrv6, hostname, descr, earlydnsregpolicy (default|enable|disable). Kea backend: pdprefix (delegated ' .
	    'prefix/length), custom_kea_config (JSON text). ISC backend with network booting enabled on the interface: ' .
	    'filename, rootpath. Omitted fields keep their value (create: empty).');

	$routes = array(
		$route('GET', '/v1/services/dhcp/interfaces', 'restapi_h_dhcp_interfaces', 'services_dhcp.php',
		    'Interfaces, whether the DHCP and DHCPv6 servers can serve them and their state'),
		$route('GET', '/v1/services/dhcpv6/interfaces', 'restapi_h_dhcp_interfaces', 'services_dhcpv6.php',
		    'Interfaces, whether the DHCP and DHCPv6 servers can serve them and their state'),
	);
	foreach (array('dhcp' => '', 'dhcpv6' => '6') as $svc => $v) {
		$base = "/v1/services/{$svc}";
		$list = "services_{$svc}.php";
		$edit = "services_{$svc}_edit.php";
		$name = $v ? 'DHCPv6' : 'DHCP';
		$key = $v ? 'DUID' : 'MAC address';
		$routes = array_merge($routes, array(
			$route('GET', "{$base}/settings", "restapi_h_dhcp{$v}_settings_get", "services_{$svc}_settings.php",
			    "{$name} server settings (Kea backend), their choices and whether changes are pending"),
			$route('PUT', "{$base}/settings", "restapi_h_dhcp{$v}_settings_set", "services_{$svc}_settings.php",
			    "Change {$name} server settings (Kea backend; omitted fields keep their value)", true, $settings_body),
			$route('POST', "{$base}/apply", "restapi_h_dhcp{$v}_apply", $list,
			    "Apply pending {$name} server changes", true),
			$route('GET', "{$base}/{if}/static-mappings", "restapi_h_dhcp{$v}_map_list", $list,
			    "List the {$name} static mappings of an interface (sorted by address)"),
			$route('GET', "{$base}/{if}/static-mappings/{id}", "restapi_h_dhcp{$v}_map_get", $list,
			    "Get a {$name} static mapping by position or {$key}"),
			$route('POST', "{$base}/{if}/static-mappings", "restapi_h_dhcp{$v}_map_create", $edit,
			    "Create a {$name} static mapping (the response has its id)", true, $v ? $map6_body : $map_body),
			$route('PUT', "{$base}/{if}/static-mappings/{id}", "restapi_h_dhcp{$v}_map_update", $edit,
			    "Update a {$name} static mapping (omitted fields keep their value; the response has its new id)", true,
			    $v ? $map6_body : $map_body),
			$route('DELETE', "{$base}/{if}/static-mappings/{id}", "restapi_h_dhcp{$v}_map_delete", $list,
			    "Delete a {$name} static mapping", true),
		));
	}
	return $routes;
}

/* ------------------------------------------------------------------ */
/* Pure helpers (no config access)                                     */
/* ------------------------------------------------------------------ */

/* Address lists (winsserver, dnsserver, ntpserver) as the edit form's numbered fields. */
function restapi_dhcp_server_rows(array $values) {
	$post = array();
	foreach (array('winsserver' => array('wins', 2), 'dnsserver' => array('dns', 4), 'ntpserver' => array('ntp', 4)) as $key => $row) {
		list($prefix, $max) = $row;
		$list = array_values($values[$key] ?? array());
		if (count($list) > $max) {
			throw new RestApiError(400, 'invalid_field', "Field \"{$key}\" has at most {$max} entries.");
		}
		for ($i = 1; $i <= $max; $i++) {
			$post["{$prefix}{$i}"] = (string)($list[$i - 1] ?? '');
		}
	}
	return $post;
}

/* Custom DHCP options (ISC): API list of {number, type, value} as the edit form's rows. */
function restapi_dhcp_option_rows($options) {
	if (!is_array($options) || !array_is_list($options)) {
		throw new RestApiError(400, 'invalid_field', '"numberoptions" must be a list of {number, type, value}.');
	}
	if (count($options) > 99) {
		throw new RestApiError(400, 'invalid_field', 'A static mapping has at most 99 custom options.');
	}
	$post = array();
	foreach ($options as $i => $opt) {
		if (!is_array($opt) || array_diff(array_keys($opt), array('number', 'type', 'value'))) {
			throw new RestApiError(400, 'invalid_field', '"numberoptions" must be a list of {number, type, value}.');
		}
		foreach ($opt as $v) {
			if (!is_scalar($v)) {
				throw new RestApiError(400, 'invalid_field', '"numberoptions" must be a list of {number, type, value}.');
			}
		}
		$post["number{$i}"] = (string)($opt['number'] ?? '');
		$post["itemtype{$i}"] = (string)($opt['type'] ?? 'text');
		$post["value{$i}"] = (string)($opt['value'] ?? '');
	}
	return $post;
}

/* Stored custom options ({item: [{number, type, value (base64)}]}) as the API list. */
function restapi_dhcp_options_out($numberoptions) {
	$out = array();
	foreach ((array)($numberoptions['item'] ?? array()) as $item) {
		if (is_array($item)) {
			$out[] = array('number' => (string)($item['number'] ?? ''), 'type' => (string)($item['type'] ?? ''),
			    'value' => base64_decode((string)($item['value'] ?? '')));
		}
	}
	return $out;
}

/* A stored address list without empty entries. */
function restapi_dhcp_addr_list($list) {
	return array_values(array_filter(array_map('strval', is_array($list) ? $list : array()), 'strlen'));
}

/* A stored checkbox: present and not false (an in-memory false right after a save). */
function restapi_dhcp_flag($entry, $key) {
	return is_array($entry) && isset($entry[$key]) && ($entry[$key] !== false);
}

/* ------------------------------------------------------------------ */
/* Shared                                                              */
/* ------------------------------------------------------------------ */

function restapi_dhcp_available() {
	if (!g_get('services_dhcp_server_enable')) {
		throw new RestApiError(404, 'not_found', 'The DHCP server is not available on this system.');
	}
}

/* The interface of a static mapping route: a configured interface, else 404. */
function restapi_dhcp_iface($req, $v6) {
	restapi_dhcp_available();
	$if = (string)($req['params']['if'] ?? '');
	$ctx = $v6 ? dhcp6_iface_context($if) : dhcp_iface_context($if);
	if ($ctx === null) {
		throw new RestApiError(404, 'not_found', 'No configured interface with that name.');
	}
	return $if;
}

function restapi_dhcp_maps($if, $v6) {
	$maps = config_get_path(($v6 ? 'dhcpdv6' : 'dhcpd') . "/{$if}/staticmap", []);
	return is_array($maps) ? $maps : array();
}

/* A static mapping by position, or by MAC address (DHCP) / DUID (DHCPv6). */
function restapi_dhcp_map_id($if, $param, $v6) {
	$maps = restapi_dhcp_maps($if, $v6);
	$param = (string)$param;
	if (ctype_digit($param)) {
		return restapi_index_param($param, $maps, 'static mapping');
	}
	$needle = strtolower(str_replace('-', ':', $param));
	foreach ($maps as $i => $m) {
		if (is_array($m) && ($needle !== '') && (strtolower(str_replace('-', ':', (string)($m[$v6 ? 'duid' : 'mac'] ?? ''))) === $needle)) {
			return (int)$i;
		}
	}
	throw new RestApiError(404, 'not_found', 'No static mapping with that ' . ($v6 ? 'id or DUID.' : 'id or MAC address.'));
}

function restapi_dhcp_pending($v6) {
	return is_subsystem_dirty($v6 ? 'dhcpd6' : 'dhcpd');
}

function restapi_dhcp_maybe_apply($req, $v6) {
	return restapi_svc_maybe_apply($req, $v6 ? 'dhcpd6' : 'dhcpd', $v6 ? 'dhcp6_apply_changes' : 'dhcp_apply_changes');
}

function restapi_h_dhcp_apply($req) {
	restapi_dhcp_available();
	$retval = dhcp_apply_changes();
	return array('data' => array('applied' => ($retval == 0), 'pending' => restapi_dhcp_pending(false)));
}

function restapi_h_dhcp6_apply($req) {
	restapi_dhcp_available();
	$retval = dhcp6_apply_changes();
	return array('data' => array('applied' => ($retval == 0), 'pending' => restapi_dhcp_pending(true)));
}

/* ------------------------------------------------------------------ */
/* Interfaces                                                          */
/* ------------------------------------------------------------------ */

function restapi_h_dhcp_interfaces($req) {
	restapi_dhcp_available();
	$out = array();
	foreach (array_keys(get_configured_interface_with_descr()) as $if) {
		$c4 = dhcp_iface_context($if);
		$c6 = dhcp6_iface_context($if);
		if (($c4 === null) || ($c6 === null)) {
			continue;
		}
		$out[] = array(
			'interface' => $if,
			'descr' => (string)$c4['name'],
			'dhcp' => array('eligible' => $c4['eligible'], 'enabled' => $c4['enabled'], 'ipaddr' => (string)$c4['ipaddr'],
			    'subnet' => (string)$c4['subnet'], 'range' => $c4['range'], 'pools' => $c4['pools'],
			    'staticarp' => $c4['staticarp'], 'static_mappings' => count(restapi_dhcp_maps($if, false))),
			'dhcpv6' => array('eligible' => $c6['eligible'], 'enabled' => $c6['enabled'], 'ipaddrv6' => (string)$c6['ipaddrv6'],
			    'subnetv6' => (string)$c6['subnetv6'], 'track6' => $c6['track6'], 'ramode' => $c6['ramode'],
			    'range' => $c6['range'], 'static_mappings' => count(restapi_dhcp_maps($if, true))),
		);
	}
	return array('data' => array('backend' => (string)dhcp_get_backend(), 'interfaces' => $out,
	    'pending' => array('dhcp' => restapi_dhcp_pending(false), 'dhcpv6' => restapi_dhcp_pending(true))));
}

/* ------------------------------------------------------------------ */
/* Settings (Kea)                                                      */
/* ------------------------------------------------------------------ */

function restapi_dhcp_settings_section($v6) {
	restapi_dhcp_available();
	if (!dhcp_is_backend('kea')) {
		throw new RestApiError(409, 'not_available', 'The DHCP server settings exist only with the Kea DHCP backend.');
	}
	return $v6 ? 'kea6' : 'kea';
}

function restapi_dhcp_settings_types() {
	$types = array('loglevel_choices' => 'ro', 'role_choices' => 'ro', 'scertref_choices' => 'ro', 'ccertref_choices' => 'ro',
	    'custom_kea_config_editable' => 'ro', 'pending' => 'ro');
	foreach (array('dnsreg', 'earlydnsreg', 'ha_enable', 'ha_tls', 'ha_mutualtls') as $flag) {
		$types[$flag] = 'bool';
	}
	foreach (array('loglevel', 'ha_role', 'ha_localname', 'ha_localip', 'ha_localport', 'ha_remotename', 'ha_remoteip',
	    'ha_remoteport', 'ha_heartbeatdelay', 'ha_maxresponsedelay', 'ha_maxackdelay', 'ha_maxunackedclients',
	    'ha_maxrejectedleaseupdates', 'ha_scertref', 'ha_ccertref', 'custom_kea_config') as $field) {
		$types[$field] = 'string';
	}
	return $types;
}

/* The settings as the page's form posts them unchanged. */
function restapi_dhcp_settings($v6) {
	$section = restapi_dhcp_settings_section($v6);
	$p = config_get_path($section, []);
	$p = is_array($p) ? $p : array();
	$scerts = kea_server_cert_list();
	$ccerts = kea_client_cert_list();
	$role = (string)array_get_path($p, 'ha/role', 'primary');
	$out = array(
		'dnsreg' => array_path_enabled($p, '', 'dnsreg'),
		'earlydnsreg' => array_path_enabled($p, '', 'earlydnsreg'),
		'loglevel' => restapi_select_choice(array_get_path($p, 'loglevel', 'WARN'), kea_log_levels()),
		'ha_enable' => array_path_enabled($p, 'ha'),
		/* a radio pair: nothing is ticked for an unknown stored role */
		'ha_role' => array_key_exists($role, kea_ha_roles()) ? $role : '',
	);
	foreach (array('localname', 'localip', 'localport', 'remotename', 'remoteip', 'remoteport', 'heartbeatdelay',
	    'maxresponsedelay', 'maxackdelay', 'maxunackedclients', 'maxrejectedleaseupdates') as $key) {
		$out["ha_{$key}"] = (string)array_get_path($p, "ha/{$key}", '');
	}
	$out['ha_tls'] = array_path_enabled($p, 'ha', 'tls');
	$out['ha_scertref'] = $scerts ? restapi_select_choice(array_get_path($p, 'ha/scertref'), $scerts) : '';
	$out['ha_mutualtls'] = array_path_enabled($p, 'ha', 'mutualtls');
	$out['ha_ccertref'] = $ccerts ? restapi_select_choice(array_get_path($p, 'ha/ccertref'), $ccerts) : '';
	$out['custom_kea_config'] = base64_decode((string)array_get_path($p, 'custom_kea_config', ''));
	$out['custom_kea_config_editable'] = kea_custom_config_editable();
	$out['loglevel_choices'] = restapi_svc_choices(kea_log_levels());
	$out['role_choices'] = restapi_svc_choices(kea_ha_roles());
	$out['scertref_choices'] = restapi_svc_choices($scerts);
	$out['ccertref_choices'] = restapi_svc_choices($ccerts);
	$out['pending'] = restapi_dhcp_pending($v6);
	return $out;
}

function restapi_dhcp_settings_save($req, $v6) {
	$types = restapi_dhcp_settings_types();
	$values = restapi_svc_merge($req['body'], restapi_dhcp_settings($v6), $types);
	$post = restapi_svc_post($values, $types);
	$post['act'] = 'save';
	$input_errors = ($v6 ? dhcp6_do_settings_post($post) : dhcp_do_settings_post($post))[0];
	if (!empty($input_errors)) {
		throw restapi_validation_error($input_errors);
	}
	$extra = restapi_dhcp_maybe_apply($req, $v6);
	return array('data' => array_merge(restapi_dhcp_settings($v6), $extra));
}

function restapi_h_dhcp_settings_get($req) {
	return array('data' => restapi_dhcp_settings(false));
}

function restapi_h_dhcp_settings_set($req) {
	return restapi_dhcp_settings_save($req, false);
}

function restapi_h_dhcp6_settings_get($req) {
	return array('data' => restapi_dhcp_settings(true));
}

function restapi_h_dhcp6_settings_set($req) {
	return restapi_dhcp_settings_save($req, true);
}

/* ------------------------------------------------------------------ */
/* DHCP static mappings                                                */
/* ------------------------------------------------------------------ */

/* The edit form's fields for the running backend. */
function restapi_dhcp_map_types() {
	$types = array('id' => 'ro', 'warnings' => 'ro', 'winsserver' => 'list', 'dnsserver' => 'list', 'ntpserver' => 'list',
	    'arp_table_static_entry' => 'bool', 'netboot' => 'bool');
	foreach (array('mac', 'cid', 'ipaddr', 'hostname', 'descr', 'earlydnsregpolicy', 'gateway', 'domain', 'domainsearchlist',
	    'tftp', 'ldap', 'filename', 'filename32', 'filename64', 'filename32arm', 'filename64arm', 'uefihttpboot', 'rootpath') as $field) {
		$types[$field] = 'string';
	}
	if (dhcp_is_backend('kea')) {
		$types['custom_kea_config'] = 'string';
	} else {
		foreach (array('deftime', 'maxtime', 'nextserver', 'ddnsdomain', 'ddnsdomainprimary', 'ddnsdomainsecondary',
		    'ddnsdomainkeyname', 'ddnsdomainkeyalgorithm', 'ddnsdomainkey') as $field) {
			$types[$field] = 'string';
		}
		$types['ddnsupdate'] = 'bool';
		$types['ddnsforcehostname'] = 'bool';
		$types['numberoptions'] = 'objects';
	}
	return $types;
}

/* A mapping's form values as the edit page shows them (the dynamic DNS key unmasked). */
function restapi_dhcp_map_values($if, $id) {
	global $ddnsdomainkeyalgorithms;
	$m = config_get_path("dhcpd/{$if}/staticmap/{$id}", []);
	$p = dhcp_staticmap_form($if, $id);
	$out = array();
	foreach (restapi_dhcp_map_types() as $key => $type) {
		if ($type === 'bool') {
			$out[$key] = restapi_dhcp_flag($m, $key);
		} elseif ($type === 'string') {
			$out[$key] = (string)($p[$key] ?? '');
		}
	}
	$out['winsserver'] = restapi_dhcp_addr_list($m['winsserver'] ?? array());
	$out['dnsserver'] = restapi_dhcp_addr_list($m['dnsserver'] ?? array());
	$out['ntpserver'] = restapi_dhcp_addr_list($m['ntpserver'] ?? array());
	$out['earlydnsregpolicy'] = restapi_select_choice($p['earlydnsregpolicy'] ?? 'default', dhcp_staticmap_dnsregpolicy_values());
	if (!dhcp_is_backend('kea')) {
		$out['ddnsdomainkeyalgorithm'] = restapi_select_choice($p['ddnsdomainkeyalgorithm'] ?? '', (array)$ddnsdomainkeyalgorithms);
		$out['numberoptions'] = restapi_dhcp_options_out($m['numberoptions'] ?? array());
	}
	return $out;
}

function restapi_dhcp_map_out($if, $id) {
	$out = array('id' => (int)$id) + restapi_dhcp_map_values($if, $id);
	if (array_key_exists('ddnsdomainkey', $out)) {
		$out['ddnsdomainkey'] = restapi_svc_mask($out['ddnsdomainkey']);
	}
	return $out;
}

/* A new mapping: what the empty edit form posts. */
function restapi_dhcp_map_empty() {
	global $ddnsdomainkeyalgorithms;
	$out = array();
	foreach (restapi_dhcp_map_types() as $key => $type) {
		if ($type === 'bool') {
			$out[$key] = false;
		} elseif ($type === 'string') {
			$out[$key] = '';
		} elseif ($type !== 'ro') {
			$out[$key] = array();
		}
	}
	$out['earlydnsregpolicy'] = 'default';
	if (array_key_exists('ddnsdomainkeyalgorithm', $out)) {
		$out['ddnsdomainkeyalgorithm'] = (string)(array_keys((array)$ddnsdomainkeyalgorithms)[0] ?? '');
	}
	return $out;
}

function restapi_dhcp_map_post(array $values) {
	$types = restapi_dhcp_map_types();
	$post = restapi_svc_post($values, $types);
	unset($post['winsserver'], $post['dnsserver'], $post['ntpserver']);
	$post += restapi_dhcp_server_rows($values);
	if (array_key_exists('numberoptions', $types)) {
		$post += restapi_dhcp_option_rows($values['numberoptions'] ?? array());
	}
	$post['save'] = 'Save';
	return $post;
}

function restapi_dhcp_map_save($req, $if, array $values, $id, $status = 200) {
	$rv = dhcp_staticmap_save($if, $id, restapi_dhcp_map_post($values));
	if (!empty($rv['input_errors'])) {
		throw restapi_validation_error($rv['input_errors']);
	}
	if (!isset($rv['id'])) {
		throw new RestApiError(500, 'internal_error', 'The saved static mapping was not found.');
	}
	$warnings = array_values(array_map(function ($m) {
		return html_entity_decode(strip_tags((string)$m), ENT_QUOTES);
	}, $rv['warnings']));
	$extra = restapi_dhcp_maybe_apply($req, false);
	return array('status' => $status, 'data' => restapi_dhcp_map_out($if, $rv['id']) + array('warnings' => $warnings) + $extra);
}

function restapi_h_dhcp_map_list($req) {
	$if = restapi_dhcp_iface($req, false);
	return array('data' => array_map(function ($id) use ($if) {
		return restapi_dhcp_map_out($if, $id);
	}, array_keys(restapi_dhcp_maps($if, false))));
}

function restapi_h_dhcp_map_get($req) {
	$if = restapi_dhcp_iface($req, false);
	return array('data' => restapi_dhcp_map_out($if, restapi_dhcp_map_id($if, $req['params']['id'], false)));
}

function restapi_h_dhcp_map_create($req) {
	$if = restapi_dhcp_iface($req, false);
	$values = restapi_svc_merge($req['body'], restapi_dhcp_map_empty(), restapi_dhcp_map_types());
	return restapi_dhcp_map_save($req, $if, $values, null, 201);
}

function restapi_h_dhcp_map_update($req) {
	$if = restapi_dhcp_iface($req, false);
	$id = restapi_dhcp_map_id($if, $req['params']['id'], false);
	$body = restapi_ddns_secret_body($req['body'], 'ddnsdomainkey');
	$values = restapi_svc_merge($body, restapi_dhcp_map_values($if, $id), restapi_dhcp_map_types());
	return restapi_dhcp_map_save($req, $if, $values, $id);
}

function restapi_h_dhcp_map_delete($req) {
	$if = restapi_dhcp_iface($req, false);
	$id = restapi_dhcp_map_id($if, $req['params']['id'], false);
	if (!dhcp_staticmap_delete($if, $id)) {
		throw new RestApiError(404, 'not_found', 'No static mapping with that id.');
	}
	return array('data' => array('deleted' => $id) + restapi_dhcp_maybe_apply($req, false));
}

/* ------------------------------------------------------------------ */
/* DHCPv6 static mappings                                              */
/* ------------------------------------------------------------------ */

/* The edit form's fields for the running backend (ISC: the boot fields only with network booting on). */
function restapi_dhcp6_map_types($if) {
	$types = array('id' => 'ro', 'duid' => 'string', 'ipaddrv6' => 'string', 'hostname' => 'string', 'descr' => 'string',
	    'earlydnsregpolicy' => 'string');
	if (dhcp_is_backend('kea')) {
		$types['pdprefix'] = 'string';
		$types['custom_kea_config'] = 'string';
	} elseif (config_path_enabled("dhcpdv6/{$if}", 'netboot')) {
		$types['filename'] = 'string';
		$types['rootpath'] = 'string';
	}
	return $types;
}

function restapi_dhcp6_map_out($if, $id) {
	$p = dhcp6_staticmap_form($if, $id);
	$out = array('id' => (int)$id);
	foreach (restapi_dhcp6_map_types($if) as $key => $type) {
		if ($type === 'string') {
			$out[$key] = (string)($p[$key] ?? '');
		}
	}
	$out['earlydnsregpolicy'] = restapi_select_choice($p['earlydnsregpolicy'] ?? 'default', dhcp_staticmap_dnsregpolicy_values());
	return $out;
}

function restapi_dhcp6_map_save($req, $if, array $values, $id, $status = 200) {
	$post = restapi_svc_post($values, restapi_dhcp6_map_types($if));
	$post['save'] = 'Save';
	$rv = dhcp6_staticmap_save($if, $id, $post);
	if (!empty($rv['input_errors'])) {
		throw restapi_validation_error($rv['input_errors']);
	}
	if (!isset($rv['id'])) {
		throw new RestApiError(500, 'internal_error', 'The saved static mapping was not found.');
	}
	$extra = restapi_dhcp_maybe_apply($req, true);
	return array('status' => $status, 'data' => restapi_dhcp6_map_out($if, $rv['id']) + $extra);
}

function restapi_h_dhcp6_map_list($req) {
	$if = restapi_dhcp_iface($req, true);
	return array('data' => array_map(function ($id) use ($if) {
		return restapi_dhcp6_map_out($if, $id);
	}, array_keys(restapi_dhcp_maps($if, true))));
}

function restapi_h_dhcp6_map_get($req) {
	$if = restapi_dhcp_iface($req, true);
	return array('data' => restapi_dhcp6_map_out($if, restapi_dhcp_map_id($if, $req['params']['id'], true)));
}

function restapi_h_dhcp6_map_create($req) {
	$if = restapi_dhcp_iface($req, true);
	$types = restapi_dhcp6_map_types($if);
	$empty = array_map(function ($t) {
		return '';
	}, array_filter($types, function ($t) {
		return $t === 'string';
	}));
	$empty['earlydnsregpolicy'] = 'default';
	$values = restapi_svc_merge($req['body'], $empty, $types);
	return restapi_dhcp6_map_save($req, $if, $values, null, 201);
}

function restapi_h_dhcp6_map_update($req) {
	$if = restapi_dhcp_iface($req, true);
	$id = restapi_dhcp_map_id($if, $req['params']['id'], true);
	$values = restapi_svc_merge($req['body'], restapi_dhcp6_map_out($if, $id), restapi_dhcp6_map_types($if));
	return restapi_dhcp6_map_save($req, $if, $values, $id);
}

function restapi_h_dhcp6_map_delete($req) {
	$if = restapi_dhcp_iface($req, true);
	$id = restapi_dhcp_map_id($if, $req['params']['id'], true);
	if (!dhcp6_staticmap_delete($if, $id)) {
		throw new RestApiError(404, 'not_found', 'No static mapping with that id.');
	}
	return array('data' => array('deleted' => $id) + restapi_dhcp_maybe_apply($req, true));
}
