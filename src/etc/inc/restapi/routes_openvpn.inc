<?php
/*
 * restapi/routes_openvpn.inc
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
 * REST API v1 routes for OpenVPN (area vpn.openvpn): servers, clients, client
 * specific overrides and the connection status.
 *
 * Writes go through the functions the OpenVPN pages call (vpn_openvpn.inc), so
 * validation is the GUI's. Like the pages, every write is applied at once: the
 * instance is restarted (or stopped when disabled), the override files are
 * rewritten and the DNS Resolver is reconfigured. There is nothing to stage
 * and no apply endpoint.
 *
 * Servers (/v1/vpn/openvpn/servers) and clients (/v1/vpn/openvpn/clients) are
 * addressed by vpnid (the responses also carry their position);
 * overrides (/v1/vpn/openvpn/csc) by position or common name. GET of an entry
 * returns its stored fields plus "fields": the edit page's form, the shape a
 * POST/PUT takes (a PUT merges its body over the current "fields"; selects
 * read as the option the page preselects; checkboxes are booleans;
 * authmode, data_ciphers, server_list and remove_options are lists), and
 * "choices" for the selects. The form is posted the way the browser posts it
 * (fields the page disables are left out), so validation is the page's.
 *
 * Secrets are never returned: the TLS key, shared key and the client's
 * authentication and proxy passwords read as "(set)"; sending "(set)" (or
 * leaving the field out) keeps them. "autotls_enable": true (or
 * "autokey_enable": true) generates a new key, which is stored and not
 * returned. Custom options need the page's "Edit Advanced" privilege (as for
 * the GUI user); without it they are kept and a change is refused (422), and
 * deleting an instance that has them is refused (403). Deleting an instance
 * whose interface is assigned is refused (409).
 *
 * The front controller loads vpn_openvpn.inc at file scope.
 */

require_once('restapi/framework.inc');
require_once('restapi/routes_firewall.inc');
require_once('restapi/routes_services.inc');
require_once('restapi/routes_vpn.inc');

function restapi_routes_openvpn() {
	$route = function ($method, $path, $handler, $page, $summary, $write = false, $body = null) {
		$opts = array('page' => $page, 'area' => 'vpn.openvpn', 'summary' => $summary);
		if ($write) {
			$opts['write'] = true;
			$opts['body'] = $body;
		}
		return restapi_route($method, $path, $handler, $opts);
	};

	$common = 'Checkboxes are booleans; omitted fields keep their value (create: the page\'s defaults). Applied at once, like the page.';
	$server_body = array('type' => 'object', 'description' => 'A server as its edit page\'s form ("fields" of GET): description, ' .
	    'disable, mode, authmode (list of authentication servers), dev_mode, protocol, interface, local_port, tlsauth_enable, ' .
	    'autotls_enable (generate a new TLS key), tls ("(set)" keeps it), tls_type, tlsauth_keydir, caref, crlref, ocspcheck, ' .
	    'ocspurl, certref, dh_length, ecdh_curve, autokey_enable (generate a new shared key), shared_key ("(set)" keeps it), ' .
	    'data_ciphers (list), data_ciphers_fallback, digest, cert_depth, strictusercn, remote_cert_tls, tunnel_network, ' .
	    'tunnel_networkv6_type, tunnel_networkv6, tunnel_track6_interface, tunnel_track6_prefix_id, serverbridge_*, gwredir, ' .
	    'gwredir6, local_network(v6), remote_network(v6), maxclients, allow_compression, compression, compression_push, passtos, ' .
	    'client2client, duplicate_cn, connlimit, dynamic_ip, topology, inactive_seconds, ping_*, keepalive_*, dns_*, ntp_*, ' .
	    'netbios_*, wins_*, nbdd_*, push_blockoutsidedns, push_register_dns, custom_options (needs the Edit Advanced privilege), ' .
	    'username_as_common_name, udp_fast_io, exit_notify, sndrcvbuf, create_gw (both|v4only|v6only), verbosity_level. ' . $common);
	$client_body = array('type' => 'object', 'description' => 'A client as its edit page\'s form ("fields" of GET): description, ' .
	    'disable, mode, dev_mode, protocol, interface, local_port, server_addr, server_port, proxy_addr, proxy_port, ' .
	    'proxy_authtype, proxy_user, proxy_passwd and auth_pass ("(set)" keeps them), auth_user, auth-retry-none, ' .
	    'tlsauth_enable, autotls_enable, tls, tls_type, tlsauth_keydir, caref, crlref, autokey_enable, shared_key, certref, ' .
	    'data_ciphers (list), data_ciphers_fallback, digest, remote_cert_tls, tunnel_network(v6), remote_network(v6), ' .
	    'use_shaper, allow_compression, compression, topology, passtos, route_no_pull, route_no_exec, dns_add, ' .
	    'inactive_seconds, ping_method, keepalive_*, ping_seconds, ping_action, ping_action_seconds, custom_options, ' .
	    'udp_fast_io, exit_notify, sndrcvbuf, create_gw, verbosity_level. ' . $common);
	$csc_body = array('type' => 'object', 'description' => 'A client specific override as its edit page\'s form ("fields" of ' .
	    'GET): description, disable, common_name, block, server_list (list of server vpnids; empty: all servers), ' .
	    'override_options (default|push_reset|remove_specified), remove_options (list), keep_minimal, tunnel_network(v6), ' .
	    'gateway, gateway6, gwredir, gwredir6, local_network(v6), remote_network(v6), inactive_seconds, ping_seconds, ' .
	    'ping_action, ping_action_seconds, dns_*, push_blockoutsidedns, push_register_dns, ntp_*, netbios_*, wins_*, nbdd_*, ' .
	    'custom_options. ' . $common);

	$srv = '/v1/vpn/openvpn/servers';
	$cli = '/v1/vpn/openvpn/clients';
	$csc = '/v1/vpn/openvpn/csc';
	return array(
		$route('GET', $srv, 'restapi_h_ovpn_server_list', 'vpn_openvpn_server.php', 'List the OpenVPN servers'),
		$route('GET', "{$srv}/{vpnid}", 'restapi_h_ovpn_server_get', 'vpn_openvpn_server.php',
		    'Get an OpenVPN server by vpnid (keys read as "(set)") with its edit form ("fields") and choices'),
		$route('POST', $srv, 'restapi_h_ovpn_server_create', 'vpn_openvpn_server.php',
		    'Create an OpenVPN server (applied at once; the response has its vpnid)', true, $server_body),
		$route('PUT', "{$srv}/{vpnid}", 'restapi_h_ovpn_server_update', 'vpn_openvpn_server.php',
		    'Update an OpenVPN server (applied at once; omitted fields keep their value)', true, $server_body),
		$route('DELETE', "{$srv}/{vpnid}", 'restapi_h_ovpn_server_delete', 'vpn_openvpn_server.php',
		    'Delete an OpenVPN server (stopped at once; 409 while its interface is assigned)', true),
		$route('GET', $cli, 'restapi_h_ovpn_client_list', 'vpn_openvpn_client.php', 'List the OpenVPN clients'),
		$route('GET', "{$cli}/{vpnid}", 'restapi_h_ovpn_client_get', 'vpn_openvpn_client.php',
		    'Get an OpenVPN client by vpnid (keys and passwords read as "(set)") with its edit form ("fields") and choices'),
		$route('POST', $cli, 'restapi_h_ovpn_client_create', 'vpn_openvpn_client.php',
		    'Create an OpenVPN client (applied at once; the response has its vpnid)', true, $client_body),
		$route('PUT', "{$cli}/{vpnid}", 'restapi_h_ovpn_client_update', 'vpn_openvpn_client.php',
		    'Update an OpenVPN client (applied at once; omitted fields keep their value)', true, $client_body),
		$route('DELETE', "{$cli}/{vpnid}", 'restapi_h_ovpn_client_delete', 'vpn_openvpn_client.php',
		    'Delete an OpenVPN client (stopped at once; 409 while its interface is assigned)', true),
		$route('GET', $csc, 'restapi_h_ovpn_csc_list', 'vpn_openvpn_csc.php', 'List the OpenVPN client specific overrides'),
		$route('GET', "{$csc}/{id}", 'restapi_h_ovpn_csc_get', 'vpn_openvpn_csc.php',
		    'Get a client specific override by position or common name with its edit form ("fields") and choices'),
		$route('POST', $csc, 'restapi_h_ovpn_csc_create', 'vpn_openvpn_csc.php',
		    'Create a client specific override (applied at once; the response has its id)', true, $csc_body),
		$route('PUT', "{$csc}/{id}", 'restapi_h_ovpn_csc_update', 'vpn_openvpn_csc.php',
		    'Update a client specific override (applied at once; omitted fields keep their value)', true, $csc_body),
		$route('DELETE', "{$csc}/{id}", 'restapi_h_ovpn_csc_delete', 'vpn_openvpn_csc.php',
		    'Delete a client specific override (applied at once)', true),
		$route('GET', '/v1/vpn/openvpn/status', 'restapi_h_ovpn_status', 'status_openvpn.php',
		    'OpenVPN status: whether each server and client runs, connected clients and routes of the servers, the state of the clients'),
	);
}

/* ------------------------------------------------------------------ */
/* Pure helpers (no config access; choices are passed in)              */
/* ------------------------------------------------------------------ */

/*
 * A select's value as the page's form preselects it: Form_Select marks every
 * option equal (==, integer keys as strings for a string value) to the value
 * and the browser takes the last marked one, or the first option when none is.
 * '' when there are no options.
 */
function restapi_ovpn_select($value, array $choices) {
	$selected = null;
	foreach (array_keys($choices) as $key) {
		$cmp = (is_int($key) && is_string($value)) ? strval($key) : $key;
		if ($value == $cmp) {
			$selected = (string)$key;
		}
	}
	if ($selected === null) {
		$selected = empty($choices) ? '' : (string)array_key_first($choices);
	}
	return $selected;
}

/* A multiple select's posted values: the options (in their order) that are in $values (loose, like Form_Select). */
function restapi_ovpn_multi(array $values, array $choices) {
	$out = array();
	foreach (array_keys($choices) as $key) {
		if (in_array($key, $values)) {
			$out[] = (string)$key;
		}
	}
	return $out;
}

/* The secret fields of a stored entry and of the edit form ($mode: server, client or csc). */
function restapi_ovpn_secrets($mode) {
	if ($mode === 'server') {
		return array('tls', 'shared_key');
	}
	if ($mode === 'client') {
		return array('tls', 'shared_key', 'auth_pass', 'proxy_passwd');
	}
	return array();
}

/* $entry with its secret fields read as "(set)" (or "" when empty). */
function restapi_ovpn_mask(array $entry, array $secrets) {
	foreach ($secrets as $secret) {
		if (array_key_exists($secret, $entry)) {
			$entry[$secret] = restapi_svc_mask(is_scalar($entry[$secret]) ? $entry[$secret] : '');
		}
	}
	return $entry;
}

/* Build an API types map: field => type. */
function restapi_ovpn_typemap(array $ro, array $bools, array $lists, array $strings) {
	$types = array();
	foreach ($ro as $f) {
		$types[$f] = 'ro';
	}
	foreach ($bools as $f) {
		$types[$f] = 'bool';
	}
	foreach ($lists as $f) {
		$types[$f] = 'list';
	}
	foreach ($strings as $f) {
		$types[$f] = 'string';
	}
	return $types;
}

function restapi_ovpn_server_types() {
	return restapi_ovpn_typemap(array('vpnid'),
	    array('disable', 'tlsauth_enable', 'autotls_enable', 'ocspcheck', 'autokey_enable', 'strictusercn', 'remote_cert_tls',
	    'serverbridge_dhcp', 'serverbridge_routegateway', 'gwredir', 'gwredir6', 'compression_push', 'passtos', 'client2client',
	    'duplicate_cn', 'dynamic_ip', 'ping_push', 'ping_action_push', 'dns_domain_enable', 'dns_server_enable',
	    'push_blockoutsidedns', 'push_register_dns', 'ntp_server_enable', 'netbios_enable', 'wins_server_enable',
	    'nbdd_server_enable', 'username_as_common_name', 'udp_fast_io'),
	    array('authmode', 'data_ciphers'),
	    array('description', 'mode', 'dev_mode', 'protocol', 'interface', 'local_port', 'tls', 'tls_type', 'tlsauth_keydir',
	    'caref', 'crlref', 'ocspurl', 'certref', 'dh_length', 'ecdh_curve', 'shared_key', 'data_ciphers_fallback', 'digest',
	    'cert_depth', 'tunnel_network', 'tunnel_networkv6_type', 'tunnel_networkv6', 'tunnel_track6_interface',
	    'tunnel_track6_prefix_id', 'serverbridge_interface', 'serverbridge_dhcp_start', 'serverbridge_dhcp_end', 'local_network',
	    'local_networkv6', 'remote_network', 'remote_networkv6', 'maxclients', 'allow_compression', 'compression', 'connlimit',
	    'topology', 'inactive_seconds', 'ping_method', 'keepalive_interval', 'keepalive_timeout', 'ping_seconds', 'ping_action',
	    'ping_action_seconds', 'dns_domain', 'dns_server1', 'dns_server2', 'dns_server3', 'dns_server4', 'ntp_server1',
	    'ntp_server2', 'netbios_ntype', 'netbios_scope', 'wins_server1', 'wins_server2', 'nbdd_server1', 'nbdd_server2',
	    'custom_options', 'exit_notify', 'sndrcvbuf', 'create_gw', 'verbosity_level'));
}

function restapi_ovpn_client_types() {
	return restapi_ovpn_typemap(array('vpnid'),
	    array('disable', 'auth-retry-none', 'tlsauth_enable', 'autotls_enable', 'autokey_enable', 'remote_cert_tls', 'passtos',
	    'route_no_pull', 'route_no_exec', 'dns_add', 'udp_fast_io'),
	    array('data_ciphers'),
	    array('description', 'mode', 'dev_mode', 'protocol', 'interface', 'local_port', 'server_addr', 'server_port',
	    'proxy_addr', 'proxy_port', 'proxy_authtype', 'proxy_user', 'proxy_passwd', 'auth_user', 'auth_pass', 'tls', 'tls_type',
	    'tlsauth_keydir', 'caref', 'crlref', 'shared_key', 'certref', 'data_ciphers_fallback', 'digest', 'tunnel_network',
	    'tunnel_networkv6', 'remote_network', 'remote_networkv6', 'use_shaper', 'allow_compression', 'compression', 'topology',
	    'inactive_seconds', 'ping_method', 'keepalive_interval', 'keepalive_timeout', 'ping_seconds', 'ping_action',
	    'ping_action_seconds', 'custom_options', 'exit_notify', 'sndrcvbuf', 'create_gw', 'verbosity_level'));
}

function restapi_ovpn_csc_types() {
	return restapi_ovpn_typemap(array(),
	    array('disable', 'block', 'keep_minimal', 'gwredir', 'gwredir6', 'dns_domain_enable', 'dns_server_enable',
	    'push_blockoutsidedns', 'push_register_dns', 'ntp_server_enable', 'netbios_enable', 'wins_server_enable',
	    'nbdd_server_enable'),
	    array('server_list', 'remove_options'),
	    array('description', 'common_name', 'override_options', 'tunnel_network', 'tunnel_networkv6', 'gateway', 'gateway6',
	    'local_network', 'local_networkv6', 'remote_network', 'remote_networkv6', 'inactive_seconds', 'ping_seconds',
	    'ping_action', 'ping_action_seconds', 'dns_domain', 'dns_server1', 'dns_server2', 'dns_server3', 'dns_server4',
	    'ntp_server1', 'ntp_server2', 'netbios_ntype', 'netbios_scope', 'wins_server1', 'wins_server2', 'nbdd_server1',
	    'nbdd_server2', 'custom_options'));
}

/*
 * An edit form (the page's $pconfig) in API shape, the way the page shows it:
 * checkboxes ticked when truthy, selects ($c: field => choices) as the page
 * preselects them, the number fields the page fills in ($defaults: field =>
 * value shown when empty), the TLS and shared key generation boxes ticked only
 * when the page shows them ticked (never while a key is set), create_gw as its
 * checked radio button and data_ciphers in the stored order (the page posts the
 * whole list). Secrets in clear.
 */
function restapi_ovpn_form_fields(array $p, array $types, array $c, array $defaults) {
	$out = array();
	foreach ($types as $field => $type) {
		$value = $p[$field] ?? null;
		if ($type === 'bool') {
			$out[$field] = !empty($value);
		} elseif ($type === 'list') {
			if ($field === 'data_ciphers') {
				$out[$field] = array_values(array_unique(array_map('strval', array_filter(is_array($value) ? $value : explode(',', (string)$value)))));
			} else {
				$list = is_array($value) ? $value : array_filter(explode(',', (string)$value));
				$out[$field] = restapi_ovpn_multi($list, $c[$field] ?? array());
			}
		} elseif ($type === 'string') {
			if (array_key_exists($field, $defaults) && empty($value)) {
				$value = $defaults[$field];
			}
			if ($field === 'create_gw') {
				$out[$field] = '';
				foreach (array_keys($c['create_gw'] ?? array()) as $gw) {
					if ($value == $gw) {
						$out[$field] = (string)$gw;
					}
				}
			} elseif (array_key_exists($field, $c)) {
				$out[$field] = restapi_ovpn_select($value, $c[$field]);
			} else {
				$out[$field] = is_scalar($value) ? (string)$value : '';
			}
		} else {
			$out[$field] = is_scalar($value) ? (string)$value : '';
		}
	}
	if (array_key_exists('autotls_enable', $out)) {
		$out['autotls_enable'] = !empty($p['autotls_enable']) && empty($p['tls']);
	}
	if (array_key_exists('autokey_enable', $out)) {
		$out['autokey_enable'] = !empty($p['autokey_enable']) && empty($p['shared_key']);
	}
	return $out;
}

/* The server edit form in API shape ($c: the choices of restapi_ovpn_server_choices()). */
function restapi_ovpn_server_fields(array $p, array $c) {
	/* the authentication backend select preselects the first server when none is set */
	if (empty($p['authmode']) && !empty($c['authmode'])) {
		$p['authmode'] = (string)array_key_first($c['authmode']);
	}
	if (empty($p['dev_mode'])) {
		$p['dev_mode'] = 'tun';
	}
	if (empty($p['tls_type'])) {
		$p['tls_type'] = 'auth';
	}
	if (strlen((string)($p['tlsauth_keydir'] ?? '')) == 0) {
		$p['tlsauth_keydir'] = 'default';
	}
	if ((string)($p['tunnel_track6_prefix_id'] ?? '') === '') {
		$p['tunnel_track6_prefix_id'] = 0;
	}
	$defaults = array('tunnel_networkv6_type' => 'staticv6', 'inactive_seconds' => 0) + restapi_ovpn_ping_defaults($c);
	return restapi_ovpn_form_fields($p, restapi_ovpn_server_types(), $c, $defaults);
}

/* The client edit form in API shape. */
function restapi_ovpn_client_fields(array $p, array $c) {
	if (empty($p['dev_mode'])) {
		$p['dev_mode'] = 'tun';
	}
	if (empty($p['tls_type'])) {
		$p['tls_type'] = 'auth';
	}
	if (strlen((string)($p['tlsauth_keydir'] ?? '')) == 0) {
		$p['tlsauth_keydir'] = 'default';
	}
	$defaults = array('inactive_seconds' => 0) + restapi_ovpn_ping_defaults($c);
	return restapi_ovpn_form_fields($p, restapi_ovpn_client_types(), $c, $defaults);
}

/* The override edit form in API shape. */
function restapi_ovpn_csc_fields(array $p, array $c) {
	if (!isset($p['override_options'])) {
		$p['override_options'] = 'default';
	}
	if (!isset($p['ping_action'])) {
		$p['ping_action'] = 'default';
	}
	return restapi_ovpn_form_fields($p, restapi_ovpn_csc_types(), $c, array());
}

/* The ping fields the server and client pages fill in when empty. */
function restapi_ovpn_ping_defaults(array $c) {
	$interval = (string)($c['keepalive_defaults'][0] ?? 10);
	$timeout = (string)($c['keepalive_defaults'][1] ?? 60);
	return array('keepalive_interval' => $interval, 'keepalive_timeout' => $timeout, 'ping_seconds' => $interval,
	    'ping_action_seconds' => $timeout);
}

/*
 * The form post for $v (API shape) as the browser sends it: ticked checkboxes
 * "yes", lists as multi-selects; the custom options only when the user may
 * edit them or the body sets them (the page disables the field otherwise); no
 * CA or CRL select when the page shows none; no gateway creation radio when
 * none is checked; in tap mode the bridge fields the page disables
 * (all of them in peer-to-peer modes, the interface and DHCP range without
 * "Bridge DHCP"). Passwords the body does not set post the page's placeholder
 * (the save keeps the stored one) when one is stored ($current: the fields
 * before the merge).
 */
function restapi_ovpn_post($mode, array $v, array $c, $can_edit_advanced, array $body, array $current) {
	$types = ($mode === 'server') ? restapi_ovpn_server_types() : (($mode === 'client') ? restapi_ovpn_client_types() :
	    restapi_ovpn_csc_types());
	$post = restapi_svc_post($v, $types);
	if (!$can_edit_advanced && !array_key_exists('custom_options', $body)) {
		unset($post['custom_options']);
	}
	if ($mode === 'csc') {
		return $post;
	}
	foreach (array('caref', 'crlref') as $field) {
		if (empty($c[$field])) {
			unset($post[$field]);
		}
	}
	if ((string)($v['create_gw'] ?? '') === '') {
		unset($post['create_gw']);
	}
	if (($mode === 'server') && (($v['dev_mode'] ?? '') === 'tap')) {
		if (in_array($v['mode'] ?? '', array('p2p_tls', 'p2p_shared_key'), true)) {
			unset($post['serverbridge_dhcp'], $post['serverbridge_interface'], $post['serverbridge_dhcp_start'],
			    $post['serverbridge_dhcp_end']);
		} elseif (empty($v['serverbridge_dhcp'])) {
			unset($post['serverbridge_interface'], $post['serverbridge_dhcp_start'], $post['serverbridge_dhcp_end']);
		}
	}
	if ($mode === 'client') {
		foreach (array('auth_pass', 'proxy_passwd') as $secret) {
			if (!array_key_exists($secret, $body)) {
				$post[$secret] = ((string)($current[$secret] ?? '') !== '') ? DMYPWD : '';
			}
		}
	}
	return $post;
}

/* An OpenVPN status entry with only the listed keys, as strings. */
function restapi_ovpn_status_pick($entry, array $keys) {
	$out = array();
	foreach ($keys as $key) {
		$out[$key] = is_scalar($entry[$key] ?? null) ? trim((string)$entry[$key]) : '';
	}
	return $out;
}

/* ------------------------------------------------------------------ */
/* Choices (config access)                                             */
/* ------------------------------------------------------------------ */

/* The choices the server and client pages share. */
function restapi_ovpn_common_choices() {
	global $openvpn_dev_mode, $openvpn_prots, $openvpn_tls_modes, $openvpn_allow_compression, $openvpn_compression_modes;
	global $openvpn_topologies, $openvpn_ping_method, $openvpn_ping_action, $openvpn_verbosity_level;
	global $openvpn_default_keepalive_interval, $openvpn_default_keepalive_timeout;
	$ciphers = openvpn_get_cipherlist();
	return array(
		'dev_mode' => $openvpn_dev_mode,
		'protocol' => $openvpn_prots,
		'interface' => openvpn_build_if_list(),
		'tls_type' => $openvpn_tls_modes,
		'tlsauth_keydir' => openvpn_get_keydirlist(),
		'caref' => count(config_get_path('ca', [])) ? cert_build_list('ca', 'OpenVPN') : array(),
		'crlref' => count(config_get_path('crl', [])) ? openvpn_build_crl_list() : array(),
		'data_ciphers' => $ciphers,
		'data_ciphers_fallback' => $ciphers,
		'digest' => openvpn_get_digestlist(),
		'allow_compression' => $openvpn_allow_compression,
		'compression' => $openvpn_compression_modes,
		'topology' => $openvpn_topologies,
		'ping_method' => $openvpn_ping_method,
		'ping_action' => $openvpn_ping_action,
		'sndrcvbuf' => openvpn_get_buffer_values(),
		'create_gw' => openvpn_create_gw_choices(),
		'verbosity_level' => $openvpn_verbosity_level,
		'keepalive_defaults' => array($openvpn_default_keepalive_interval ?? 10, $openvpn_default_keepalive_timeout ?? 60),
	);
}

/*
 * The "IPv6 Interface" choices of the server page: build_ipv6interface_list()'s
 * list (the interfaces with a 6to4, 6rd or DHCPv6 address) without the hidden
 * form fields it adds to the page's global form.
 */
function restapi_ovpn_track6_interfaces() {
	$list = array('' => '');
	foreach (get_configured_interface_with_descr(true) as $iface => $ifacename) {
		if (in_array(config_get_path("interfaces/{$iface}/ipaddrv6"), array('6to4', '6rd', 'dhcp6'), true)) {
			$list[$iface] = $ifacename;
		}
	}
	return $list;
}

function restapi_ovpn_server_choices() {
	global $openvpn_dh_lengths, $openvpn_cert_depths, $openvpn_exit_notify_server;
	$certlist = openvpn_build_cert_list(false, true);
	$auth = array();
	foreach (auth_get_authserver_list() as $key => $server) {
		$auth[$key] = $server['name'];
	}
	return array(
		'mode' => openvpn_build_mode_list(),
		'authmode' => $auth,
		'certref' => $certlist['server'] + $certlist['non-server'],
		'dh_length' => $openvpn_dh_lengths,
		'ecdh_curve' => openvpn_get_curvelist(),
		'cert_depth' => array('' => gettext('Do Not Check')) + $openvpn_cert_depths,
		'tunnel_networkv6_type' => array('staticv6' => gettext('Static IPv6'), 'track6' => gettext('Track Interface')),
		'tunnel_track6_interface' => restapi_ovpn_track6_interfaces(),
		'serverbridge_interface' => openvpn_build_bridge_list(),
		'netbios_ntype' => openvpn_netbios_nodetypes(),
		'exit_notify' => $openvpn_exit_notify_server,
	) + restapi_ovpn_common_choices();
}

function restapi_ovpn_client_choices() {
	global $openvpn_client_modes, $openvpn_exit_notify_client;
	$certlist = openvpn_build_cert_list(true);
	return array(
		'mode' => $openvpn_client_modes,
		'proxy_authtype' => openvpn_client_proxy_auth_types(),
		'certref' => $certlist['server'],
		'exit_notify' => $openvpn_exit_notify_client,
	) + restapi_ovpn_common_choices();
}

function restapi_ovpn_csc_choices() {
	global $openvpn_ping_action;
	return array(
		'server_list' => openvpn_csc_server_list(),
		'override_options' => openvpn_csc_override_options(),
		'remove_options' => openvpn_csc_remove_options(),
		'ping_action' => array_merge(array('default' => 'Don\'t override option (default)'), $openvpn_ping_action),
		'netbios_ntype' => openvpn_netbios_nodetypes(),
	);
}

/* The choices for the API (labels as text; no internal defaults). */
function restapi_ovpn_choices_out(array $c) {
	unset($c['keepalive_defaults']);
	return array_map('restapi_svc_choices', $c);
}

/* ------------------------------------------------------------------ */
/* Servers and clients                                                 */
/* ------------------------------------------------------------------ */

function restapi_ovpn_entries($mode) {
	$entries = config_get_path("openvpn/openvpn-{$mode}", []);
	return is_array($entries) ? $entries : array();
}

/* The position of the server or client with vpnid $vpnid, or a 404. */
function restapi_ovpn_pos($mode, $vpnid) {
	foreach (restapi_ovpn_entries($mode) as $idx => $entry) {
		if (is_array($entry) && ((string)($entry['vpnid'] ?? '') === (string)$vpnid) && ((string)$vpnid !== '')) {
			return $idx;
		}
	}
	throw new RestApiError(404, 'not_found', "No OpenVPN {$mode} with that vpnid.");
}

/* Whether the instance's daemon runs (its pid file names a live process). */
function restapi_ovpn_running($mode, array $entry) {
	if (empty($entry['vpnid'])) {
		return false;
	}
	return isvalidpid(g_get('varrun_path') . "/openvpn_{$mode}{$entry['vpnid']}.pid");
}

/* A server or client: its summary or (full) every stored field (secrets masked) with its edit form and the choices. */
function restapi_ovpn_instance_out($mode, $pos, $full) {
	$entry = (array)config_get_path("openvpn/openvpn-{$mode}/{$pos}", []);
	$out = array('position' => (int)$pos, 'vpnid' => (string)($entry['vpnid'] ?? ''));
	if ($full) {
		$stored = restapi_ovpn_mask($entry, restapi_ovpn_secrets($mode));
		unset($stored['vpnid']);
		$stored['disable'] = isset($entry['disable']);
		$c = ($mode === 'server') ? restapi_ovpn_server_choices() : restapi_ovpn_client_choices();
		$form = ($mode === 'server') ? openvpn_server_form('edit', $entry) : openvpn_client_form('edit', $entry);
		$fields = ($mode === 'server') ? restapi_ovpn_server_fields((array)$form, $c) : restapi_ovpn_client_fields((array)$form, $c);
		$fields['vpnid'] = $out['vpnid'];
		return $out + $stored + array('running' => restapi_ovpn_running($mode, $entry),
		    'fields' => restapi_ovpn_mask($fields, restapi_ovpn_secrets($mode)), 'choices' => restapi_ovpn_choices_out($c));
	}
	foreach (array('description', 'mode', 'protocol', 'dev_mode', 'interface', 'local_port', 'server_addr', 'server_port',
	    'tunnel_network', 'tunnel_networkv6') as $field) {
		if (($mode === 'server') && in_array($field, array('server_addr', 'server_port'), true)) {
			continue;
		}
		$out[$field] = is_scalar($entry[$field] ?? null) ? (string)$entry[$field] : '';
	}
	$out['disable'] = isset($entry['disable']);
	$out['running'] = restapi_ovpn_running($mode, $entry);
	return $out;
}

/* Create ($pos null) or update the server or client at $pos through the page's save; returns its vpnid. */
function restapi_ovpn_instance_write($mode, array $body, $pos) {
	foreach (restapi_ovpn_secrets($mode) as $secret) {
		$body = restapi_vpn_secret_body($body, $secret);
	}
	$act = ($pos === null) ? 'new' : 'edit';
	$entry = ($pos === null) ? null : config_get_path("openvpn/openvpn-{$mode}/{$pos}");
	if ($mode === 'server') {
		$c = restapi_ovpn_server_choices();
		$current = restapi_ovpn_server_fields((array)openvpn_server_form($act, $entry), $c);
		$types = restapi_ovpn_server_types();
	} else {
		$c = restapi_ovpn_client_choices();
		$current = restapi_ovpn_client_fields((array)openvpn_client_form($act, $entry), $c);
		$types = restapi_ovpn_client_types();
	}
	$values = restapi_svc_merge($body, $current, $types);
	$can_edit_advanced = openvpn_user_can_edit_advanced("page-openvpn-{$mode}-advanced");
	$post = restapi_ovpn_post($mode, $values, $c, $can_edit_advanced, $body, $current);
	if ($mode === 'server') {
		$errors = openvpn_server_save($post, $pos, $act, $can_edit_advanced);
	} else {
		$errors = openvpn_client_save($post, $pos, $act, $can_edit_advanced);
	}
	if (!empty($errors)) {
		throw restapi_validation_error($errors);
	}
	if ($pos === null) {
		$entries = restapi_ovpn_entries($mode);
		$pos = array_key_last($entries);
	}
	return (string)config_get_path("openvpn/openvpn-{$mode}/{$pos}/vpnid", '');
}

/* Delete the server or client with vpnid $vpnid through the list page's function. */
function restapi_ovpn_instance_delete($mode, $vpnid) {
	$pos = restapi_ovpn_pos($mode, $vpnid);
	$can_edit_advanced = openvpn_user_can_edit_advanced("page-openvpn-{$mode}-advanced");
	$rv = openvpn_instance_delete($mode, $pos, $can_edit_advanced);
	if ($rv === null) {
		throw new RestApiError(404, 'not_found', "No OpenVPN {$mode} with that vpnid.");
	}
	if (!empty($rv['input_errors'])) {
		$e = restapi_validation_error($rv['input_errors']);
		if (openvpn_inuse($vpnid, $mode)) {
			throw new RestApiError(409, 'in_use', $e->details['messages'][0], $e->details);
		}
		throw new RestApiError(403, 'forbidden', $e->details['messages'][0], $e->details);
	}
	return array('data' => array('deleted' => (string)$vpnid));
}

function restapi_h_ovpn_server_list($req) {
	return array('data' => array_map(function ($pos) {
		return restapi_ovpn_instance_out('server', $pos, false);
	}, array_keys(restapi_ovpn_entries('server'))));
}

function restapi_h_ovpn_server_get($req) {
	return array('data' => restapi_ovpn_instance_out('server', restapi_ovpn_pos('server', $req['params']['vpnid']), true));
}

function restapi_h_ovpn_server_create($req) {
	$vpnid = restapi_ovpn_instance_write('server', $req['body'], null);
	return array('status' => 201, 'data' => restapi_ovpn_instance_out('server', restapi_ovpn_pos('server', $vpnid), true));
}

function restapi_h_ovpn_server_update($req) {
	$vpnid = restapi_ovpn_instance_write('server', $req['body'], restapi_ovpn_pos('server', $req['params']['vpnid']));
	return array('data' => restapi_ovpn_instance_out('server', restapi_ovpn_pos('server', $vpnid), true));
}

function restapi_h_ovpn_server_delete($req) {
	return restapi_ovpn_instance_delete('server', $req['params']['vpnid']);
}

function restapi_h_ovpn_client_list($req) {
	return array('data' => array_map(function ($pos) {
		return restapi_ovpn_instance_out('client', $pos, false);
	}, array_keys(restapi_ovpn_entries('client'))));
}

function restapi_h_ovpn_client_get($req) {
	return array('data' => restapi_ovpn_instance_out('client', restapi_ovpn_pos('client', $req['params']['vpnid']), true));
}

function restapi_h_ovpn_client_create($req) {
	$vpnid = restapi_ovpn_instance_write('client', $req['body'], null);
	return array('status' => 201, 'data' => restapi_ovpn_instance_out('client', restapi_ovpn_pos('client', $vpnid), true));
}

function restapi_h_ovpn_client_update($req) {
	$vpnid = restapi_ovpn_instance_write('client', $req['body'], restapi_ovpn_pos('client', $req['params']['vpnid']));
	return array('data' => restapi_ovpn_instance_out('client', restapi_ovpn_pos('client', $vpnid), true));
}

function restapi_h_ovpn_client_delete($req) {
	return restapi_ovpn_instance_delete('client', $req['params']['vpnid']);
}

/* ------------------------------------------------------------------ */
/* Client specific overrides                                           */
/* ------------------------------------------------------------------ */

/* An override's position from its position or common name, or a 404. */
function restapi_ovpn_csc_id($param) {
	$entries = restapi_ovpn_entries('csc');
	if (ctype_digit((string)$param)) {
		return restapi_index_param($param, $entries, 'client specific override');
	}
	foreach ($entries as $idx => $entry) {
		if (is_array($entry) && (($entry['common_name'] ?? null) === (string)$param)) {
			return (int)$idx;
		}
	}
	throw new RestApiError(404, 'not_found', 'No client specific override with that id or common name.');
}

function restapi_ovpn_csc_out($pos, $full) {
	$entry = (array)config_get_path("openvpn/openvpn-csc/{$pos}", []);
	if (!$full) {
		return array('id' => (int)$pos, 'common_name' => (string)($entry['common_name'] ?? ''),
		    'description' => (string)($entry['description'] ?? ''), 'disable' => isset($entry['disable']),
		    'server_list' => restapi_svc_split($entry['server_list'] ?? ''));
	}
	$c = restapi_ovpn_csc_choices();
	$stored = $entry;
	$stored['disable'] = isset($entry['disable']);
	return array('id' => (int)$pos) + $stored + array('fields' => restapi_ovpn_csc_fields((array)openvpn_csc_form('edit', $entry), $c),
	    'choices' => restapi_ovpn_choices_out($c));
}

/* Create ($pos null) or update an override through the page's save; returns its position. */
function restapi_ovpn_csc_write(array $body, $pos) {
	$act = ($pos === null) ? 'new' : 'edit';
	$c = restapi_ovpn_csc_choices();
	$current = restapi_ovpn_csc_fields((array)openvpn_csc_form($act, ($pos === null) ? null : config_get_path("openvpn/openvpn-csc/{$pos}")), $c);
	$values = restapi_svc_merge($body, $current, restapi_ovpn_csc_types());
	$can_edit_advanced = openvpn_user_can_edit_advanced('page-openvpn-csc-advanced');
	$post = restapi_ovpn_post('csc', $values, $c, $can_edit_advanced, $body, $current);
	$errors = openvpn_csc_save($post, $pos, $act, $can_edit_advanced);
	if (!empty($errors)) {
		throw restapi_validation_error($errors);
	}
	return ($pos === null) ? array_key_last(restapi_ovpn_entries('csc')) : $pos;
}

function restapi_h_ovpn_csc_list($req) {
	return array('data' => array_map(function ($pos) {
		return restapi_ovpn_csc_out($pos, false);
	}, array_keys(restapi_ovpn_entries('csc'))));
}

function restapi_h_ovpn_csc_get($req) {
	return array('data' => restapi_ovpn_csc_out(restapi_ovpn_csc_id($req['params']['id']), true));
}

function restapi_h_ovpn_csc_create($req) {
	return array('status' => 201, 'data' => restapi_ovpn_csc_out(restapi_ovpn_csc_write($req['body'], null), true));
}

function restapi_h_ovpn_csc_update($req) {
	return array('data' => restapi_ovpn_csc_out(restapi_ovpn_csc_write($req['body'], restapi_ovpn_csc_id($req['params']['id'])), true));
}

function restapi_h_ovpn_csc_delete($req) {
	$pos = restapi_ovpn_csc_id($req['params']['id']);
	$cn = (string)config_get_path("openvpn/openvpn-csc/{$pos}/common_name", '');
	$rv = openvpn_csc_delete($pos, openvpn_user_can_edit_advanced('page-openvpn-csc-advanced'));
	if ($rv === null) {
		throw new RestApiError(404, 'not_found', 'No client specific override with that id or common name.');
	}
	if (!empty($rv['input_errors'])) {
		$e = restapi_validation_error($rv['input_errors']);
		throw new RestApiError(403, 'forbidden', $e->details['messages'][0], $e->details);
	}
	return array('data' => array('deleted' => $cn));
}

/* ------------------------------------------------------------------ */
/* Status                                                              */
/* ------------------------------------------------------------------ */

function restapi_h_ovpn_status($req) {
	$conn_keys = array('common_name', 'remote_host', 'virtual_addr', 'virtual_addr6', 'bytes_recv', 'bytes_sent', 'connect_time',
	    'connect_time_unix', 'user_name', 'client_id', 'peer_id', 'cipher');
	$route_keys = array('virtual_addr', 'common_name', 'remote_host', 'last_time');
	$p2p_keys = array('status', 'state', 'state_detail', 'connect_time', 'virtual_addr', 'virtual_addr6', 'remote_host',
	    'remote_port', 'local_host', 'local_port');
	$running = function ($mode, $vpnid) {
		return restapi_ovpn_running($mode, array('vpnid' => $vpnid));
	};
	$servers = array();
	foreach (openvpn_get_active_servers() as $server) {
		$conns = array();
		$error = '';
		foreach ((array)($server['conns'] ?? array()) as $conn) {
			if (($conn['common_name'] ?? '') === '[error]') {
				$error = (string)($conn['remote_host'] ?? '');
				continue;
			}
			$conns[] = restapi_ovpn_status_pick($conn, $conn_keys);
		}
		$routes = array();
		foreach ((array)($server['routes'] ?? array()) as $r) {
			$routes[] = restapi_ovpn_status_pick($r, $route_keys);
		}
		$servers[] = array('vpnid' => (string)$server['vpnid'], 'name' => (string)$server['name'], 'mode' => (string)$server['mode'],
		    'port' => (string)$server['port'], 'running' => $running('server', $server['vpnid']), 'error' => $error,
		    'connections' => $conns, 'routes' => $routes);
	}
	$p2p = array();
	foreach (openvpn_get_active_servers('p2p') as $server) {
		$p2p[] = array('vpnid' => (string)$server['vpnid'], 'name' => (string)$server['name'], 'mode' => (string)$server['mode'],
		    'running' => $running('server', $server['vpnid'])) + restapi_ovpn_status_pick($server, $p2p_keys);
	}
	$clients = array();
	foreach (openvpn_get_active_clients() as $client) {
		$clients[] = array('vpnid' => (string)$client['vpnid'], 'name' => (string)$client['name'],
		    'running' => $running('client', $client['vpnid'])) + restapi_ovpn_status_pick($client, $p2p_keys);
	}
	return array('data' => array('servers' => $servers, 'p2p_servers' => $p2p, 'clients' => $clients));
}
