<?php
/*
 * restapi/routes_unbound.inc
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
 * REST API v1 routes for the DNS Resolver (Unbound), area services.dns.
 *
 * Writes go through the functions the DNS Resolver pages call
 * (services_unbound.inc), so validation is the GUI's; the general settings
 * are only saved when the generated configuration passes unbound-checkconf.
 * Like the GUI, every change is staged (the "unbound" dirty flag) until
 * POST /v1/services/dns-resolver/apply; add ?apply=true to a write to apply
 * it at once. Settings are flat objects of the edit forms' fields
 * (checkboxes as booleans, multi-selects as lists, a select without a valid
 * stored value reads as the option the form preselects); a PUT changes only
 * the fields it sends. List entries are addressed by their position in
 * config.xml; host overrides are kept sorted by host, so a write returns the
 * entry's new id.
 *
 * For the WebUI 2.0 each list entry also has "fields" (the edit form's
 * fields, a valid PUT body) and "display" (the list page's columns as
 * text); GET /pending tells whether changes wait for apply. The access
 * lists are served at /access-lists (and, as before, at /acls). Writes name
 * their resource schema (schemas/services_dns_resolver.inc), so validation
 * messages are keyed to the form fields.
 *
 * The front controller loads services_unbound.inc at file scope.
 */

require_once('restapi/framework.inc');
require_once('restapi/routes_services.inc');

function restapi_routes_unbound() {
	$apply_query = array('apply' => 'true to apply the change at once (default: stage it like the GUI)');
	$dns = 'services.dns';
	$base = '/v1/services/dns-resolver';

	$general_body = array('type' => 'object', 'description' => 'The DNS Resolver general settings form: enable, enablessl, ' .
	    'strictout, dnssec, python, forwarding, forward_tls_upstream, regovpnclients, regdhcp and regdhcpstatic (the last two ' .
	    'only with the ISC DHCP backend) (booleans); port, tlsport (empty: 53/853), sslcertref (from "sslcertref_choices"), ' .
	    'system_domain_local_zone_type (from "local_zone_type_choices"), python_order (pre_validator|post_validator), ' .
	    'python_script (from "python_script_choices"), custom_options (text); active_interface and outgoing_interface (lists ' .
	    'from "interface_choices"; "all" for every address). Omitted fields keep their value. The generated configuration ' .
	    'must pass unbound-checkconf.');
	$advanced_body = array('type' => 'object', 'description' => 'The DNS Resolver advanced settings form: hideidentity, ' .
	    'hideversion, qname-minimisation, qname-minimisation-strict, prefetch, prefetchkey, dnssecstripped, dnsrecordcache, ' .
	    'aggressivensec, infra_keep_probing, disable_auto_added_access_control, disable_auto_added_host_entries, use_caps, ' .
	    'dns64_enable, allow_dns64_for_localhost (booleans); msgcachesize, outgoing_num_tcp, incoming_num_tcp, edns_buffer_size, ' .
	    'num_queries_per_thread, jostle_timeout, infra_host_ttl, infra_cache_numhosts, unwanted_reply_threshold, log_verbosity ' .
	    '(values from "choices"); sock_queue_timeout, cache_max_ttl, cache_min_ttl (non-negative integers); dns64_prefix. ' .
	    'Omitted fields keep their value.');
	$host_body = array('type' => 'object', 'properties' => array(
		'host' => array('type' => 'string', 'description' => 'Host name without the domain; empty for the domain itself'),
		'domain' => array('type' => 'string'),
		'ip' => array('type' => 'string', 'description' => 'IPv4 or IPv6 address, or a comma separated list of them'),
		'descr' => array('type' => 'string'),
		'aliases' => array('type' => 'array', 'description' => 'Additional names for the host',
		    'items' => array('type' => 'object', 'properties' => array(
		    'host' => array('type' => 'string'), 'domain' => array('type' => 'string'), 'description' => array('type' => 'string')))),
	));
	$domain_body = array('type' => 'object', 'properties' => array(
		'domain' => array('type' => 'string'),
		'ip' => array('type' => 'string', 'description' => 'Address of the authoritative server, optionally with @port'),
		'forward_tls_upstream' => array('type' => 'boolean', 'description' => 'Query the servers of this domain over TLS (port 853)'),
		'tls_hostname' => array('type' => 'string', 'description' => 'Host name to verify the server certificate against'),
		'descr' => array('type' => 'string'),
	));
	$acl_body = array('type' => 'object', 'properties' => array(
		'aclname' => array('type' => 'string'),
		'aclaction' => array('type' => 'string', 'enum' => array_keys(restapi_unbound_acl_actions()), 'description' => 'Default: allow'),
		'description' => array('type' => 'string'),
		'networks' => array('type' => 'array', 'description' => 'Up to 50 networks; "networks" replaces the list on update',
		    'items' => array('type' => 'object', 'properties' => array(
		    'network' => array('type' => 'string', 'description' => 'address/bits'),
		    'acl_network' => array('type' => 'string', 'description' => 'Instead of "network": the address ...'),
		    'mask' => array('type' => 'string', 'description' => '... and the prefix length (the edit form\'s columns)'),
		    'description' => array('type' => 'string')))),
	));
	$route = function ($method, $path, $handler, $page, $summary, $write = false, $body = null, $schema = null) use ($dns, $base, $apply_query) {
		$opts = array('page' => $page, 'area' => $dns, 'summary' => $summary);
		if ($write) {
			$opts['write'] = true;
			$opts['body'] = $body;
			if ($path !== '/apply') {
				$opts['query'] = $apply_query;
			}
		}
		if ($schema !== null) {
			$opts['schema'] = $schema;
		}
		return restapi_route($method, $base . $path, $handler, $opts);
	};
	$page = 'services_unbound.php';
	$acl_page = 'services_unbound_acls.php';
	/* The access list routes, at /access-lists and (as in earlier releases) at /acls. */
	$acl_routes = function ($path) use ($route, $acl_page, $acl_body) {
		return array(
			$route('GET', $path, 'restapi_h_unbound_acl_list', $acl_page, 'List access lists (with "fields" and "display")'),
			$route('GET', "{$path}/{id}", 'restapi_h_unbound_acl_get', $acl_page, 'Get an access list (with "fields" and "display")'),
			$route('POST', $path, 'restapi_h_unbound_acl_create', $acl_page,
			    'Create an access list', true, $acl_body, 'services/dns_access_list'),
			$route('PUT', "{$path}/{id}", 'restapi_h_unbound_acl_update', $acl_page,
			    'Update an access list (omitted fields keep their value; "networks" replaces the list)', true, $acl_body, 'services/dns_access_list'),
			$route('DELETE', "{$path}/{id}", 'restapi_h_unbound_acl_delete', $acl_page, 'Delete an access list', true),
		);
	};

	return array_merge(array(
		$route('GET', '', 'restapi_h_unbound_get', $page,
		    'DNS Resolver general settings, their choices and whether changes are pending'),
		$route('PUT', '', 'restapi_h_unbound_set', $page,
		    'Change DNS Resolver general settings (staged like the page\'s Save; omitted fields keep their value)', true, $general_body,
		    'services/dns_resolver'),
		$route('GET', '/pending', 'restapi_h_unbound_pending', $page,
		    'Whether DNS Resolver changes are staged and wait for apply'),
		$route('POST', '/apply', 'restapi_h_unbound_apply', $page,
		    'Apply pending DNS Resolver changes (settings, overrides, access lists)', true),
		$route('GET', '/advanced', 'restapi_h_unbound_adv_get', 'services_unbound_advanced.php',
		    'DNS Resolver advanced settings and their choices'),
		$route('PUT', '/advanced', 'restapi_h_unbound_adv_set', 'services_unbound_advanced.php',
		    'Change DNS Resolver advanced settings (staged; omitted fields keep their value)', true, $advanced_body,
		    'services/dns_resolver_advanced'),

		$route('GET', '/host-overrides', 'restapi_h_unbound_host_list', $page,
		    'List host overrides (sorted by host; with "fields" and "display")'),
		$route('GET', '/host-overrides/{id}', 'restapi_h_unbound_host_get', $page, 'Get a host override (with "fields" and "display")'),
		$route('POST', '/host-overrides', 'restapi_h_unbound_host_create', 'services_unbound_host_edit.php',
		    'Create a host override (the list is sorted by host; the response has its id)', true, $host_body,
		    'services/dns_host_override'),
		$route('PUT', '/host-overrides/{id}', 'restapi_h_unbound_host_update', 'services_unbound_host_edit.php',
		    'Update a host override (omitted fields keep their value; "aliases" replaces the list; the response has its new id)', true, $host_body,
		    'services/dns_host_override'),
		$route('DELETE', '/host-overrides/{id}', 'restapi_h_unbound_host_delete', $page, 'Delete a host override', true),

		$route('GET', '/domain-overrides', 'restapi_h_unbound_domain_list', $page, 'List domain overrides (with "fields" and "display")'),
		$route('GET', '/domain-overrides/{id}', 'restapi_h_unbound_domain_get', $page, 'Get a domain override (with "fields" and "display")'),
		$route('POST', '/domain-overrides', 'restapi_h_unbound_domain_create', 'services_unbound_domainoverride_edit.php',
		    'Create a domain override', true, $domain_body, 'services/dns_domain_override'),
		$route('PUT', '/domain-overrides/{id}', 'restapi_h_unbound_domain_update', 'services_unbound_domainoverride_edit.php',
		    'Update a domain override (omitted fields keep their value)', true, $domain_body, 'services/dns_domain_override'),
		$route('DELETE', '/domain-overrides/{id}', 'restapi_h_unbound_domain_delete', $page, 'Delete a domain override', true),
	), $acl_routes('/access-lists'), $acl_routes('/acls'));
}

/* ------------------------------------------------------------------ */
/* Pure helpers (no config access)                                     */
/* ------------------------------------------------------------------ */

/* The access list actions (kept equal to unbound_acl_actions(); the smoke test checks it). */
function restapi_unbound_acl_actions() {
	return array('allow' => 'Allow', 'deny' => 'Deny', 'refuse' => 'Refuse', 'allow snoop' => 'Allow Snoop',
	    'deny nonlocal' => 'Deny Nonlocal', 'refuse nonlocal' => 'Refuse Nonlocal');
}

/* The value a select posts: the stored value when it is an option, else the first option (what a browser preselects). */
function restapi_unbound_preselect($value, array $options) {
	$options = array_map('strval', $options);
	if (in_array((string)$value, $options, true)) {
		return (string)$value;
	}
	return $options[0] ?? (string)$value;
}

/* ACL networks (API list of {network: "address/bits", description}) as the edit form's rows. */
function restapi_unbound_acl_rows($networks) {
	if (!is_array($networks) || !array_is_list($networks)) {
		throw new RestApiError(400, 'invalid_field', '"networks" must be a list of {network, description}.');
	}
	if (count($networks) > 50) {
		throw new RestApiError(400, 'invalid_field', 'An access list has at most 50 networks.');
	}
	$post = array();
	foreach ($networks as $i => $net) {
		if (!is_array($net) || !is_scalar($net['network'] ?? '') || !is_scalar($net['description'] ?? '')) {
			throw new RestApiError(400, 'invalid_field', '"networks" must be a list of {network, description}.');
		}
		if (array_key_exists('acl_network', $net) || array_key_exists('mask', $net)) {
			/* The edit form's columns (the schema's entry grid). */
			foreach (array('acl_network', 'mask') as $key) {
				if (!is_scalar($net[$key] ?? '')) {
					throw new RestApiError(400, 'invalid_field', '"networks" must be a list of {network, description}.');
				}
			}
			$parts = array((string)($net['acl_network'] ?? ''), (string)($net['mask'] ?? ''));
		} else {
			$parts = explode('/', (string)($net['network'] ?? ''), 2);
		}
		$post["acl_network{$i}"] = $parts[0];
		$post["mask{$i}"] = $parts[1] ?? '';
		$post["description{$i}"] = (string)($net['description'] ?? '');
	}
	return $post;
}

/* Stored ACL rows as the edit form's entry grid {acl_network, mask, description}. */
function restapi_unbound_acl_grid($rows) {
	$out = array();
	foreach ((is_array($rows) ? $rows : array()) as $row) {
		if (is_array($row)) {
			$out[] = array('acl_network' => (string)($row['acl_network'] ?? ''), 'mask' => (string)($row['mask'] ?? ''),
			    'description' => (string)($row['description'] ?? ''));
		}
	}
	return $out;
}

/* "host.domain", or the domain alone for an empty host (as the list page names it). */
function restapi_unbound_fqdn($host, $domain) {
	return ((string)$host !== '') ? "{$host}.{$domain}" : (string)$domain;
}

/* The host override list columns: the name, its addresses, the additional names and the description. */
function restapi_unbound_host_display(array $h) {
	$ip = $h['ip'] ?? '';
	$addresses = array();
	foreach ((is_array($ip) ? $ip : explode(',', (string)$ip)) as $addr) {
		if (is_scalar($addr) && (trim((string)$addr) !== '')) {
			$addresses[] = trim((string)$addr);
		}
	}
	$aliases = array();
	$items = $h['aliases']['item'] ?? array();
	foreach ((is_array($items) ? $items : array()) as $alias) {
		if (is_array($alias) && ((($alias['host'] ?? '') !== '') || (($alias['domain'] ?? '') !== ''))) {
			$aliases[] = restapi_unbound_fqdn($alias['host'] ?? '', $alias['domain'] ?? '');
		}
	}
	return array('host' => restapi_unbound_fqdn($h['host'] ?? '', $h['domain'] ?? ''), 'addresses' => $addresses,
	    'aliases' => $aliases, 'description' => (string)($h['descr'] ?? ''));
}

/* The domain override list columns. */
function restapi_unbound_domain_display(array $d) {
	return array('domain' => (string)($d['domain'] ?? ''), 'server' => (string)($d['ip'] ?? ''),
	    'tls' => isset($d['forward_tls_upstream']) && ($d['forward_tls_upstream'] !== false), 'description' => (string)($d['descr'] ?? ''));
}

/* The access list columns: name, the action's label, the networks as "address/bits" and the description. */
function restapi_unbound_acl_display(array $a, array $actions) {
	$action = strtolower((string)($a['aclaction'] ?? ''));
	return array('name' => (string)($a['aclname'] ?? ''), 'action' => (string)($actions[$action] ?? ($a['aclaction'] ?? '')),
	    'networks' => array_column(restapi_unbound_acl_networks($a['row'] ?? array()), 'network'),
	    'description' => (string)($a['description'] ?? ''));
}

/* Stored ACL rows as the API list. */
function restapi_unbound_acl_networks($rows) {
	$out = array();
	foreach ((is_array($rows) ? $rows : array()) as $row) {
		if (is_array($row)) {
			$out[] = array('network' => ($row['acl_network'] ?? '') . '/' . ($row['mask'] ?? ''),
			    'description' => (string)($row['description'] ?? ''));
		}
	}
	return $out;
}

/* ------------------------------------------------------------------ */
/* Shared                                                              */
/* ------------------------------------------------------------------ */

function restapi_unbound_maybe_apply($req) {
	return restapi_svc_maybe_apply($req, 'unbound', 'unbound_apply_changes');
}

function restapi_h_unbound_pending($req) {
	return array('data' => array('pending' => is_subsystem_dirty('unbound')));
}

function restapi_h_unbound_apply($req) {
	$retval = unbound_apply_changes();
	return array('data' => array('applied' => ($retval == 0), 'pending' => is_subsystem_dirty('unbound')));
}

/* ------------------------------------------------------------------ */
/* General settings                                                    */
/* ------------------------------------------------------------------ */

function restapi_unbound_types() {
	$types = array('port' => 'string', 'tlsport' => 'string', 'sslcertref' => 'string', 'active_interface' => 'list',
	    'outgoing_interface' => 'list', 'system_domain_local_zone_type' => 'string', 'python_order' => 'string',
	    'python_script' => 'string', 'custom_options' => 'string', 'interface_choices' => 'ro',
	    'local_zone_type_choices' => 'ro', 'python_script_choices' => 'ro', 'sslcertref_choices' => 'ro', 'pending' => 'ro');
	$flags = array('enable', 'enablessl', 'strictout', 'dnssec', 'python', 'forwarding', 'forward_tls_upstream', 'regovpnclients');
	/* The page shows the DHCP registration options only with the ISC backend. */
	if (dhcp_is_backend('isc')) {
		$flags[] = 'regdhcp';
		$flags[] = 'regdhcpstatic';
	}
	foreach ($flags as $flag) {
		$types[$flag] = 'bool';
	}
	return $types;
}

/* The general settings as the page's form posts them unchanged. */
function restapi_unbound_settings() {
	$p = unbound_general_settings();
	$out = array();
	foreach (restapi_unbound_types() as $key => $type) {
		if ($type === 'bool') {
			$out[$key] = !empty($p[$key]);
		} elseif ($type === 'string') {
			$out[$key] = (string)($p[$key] ?? '');
		}
	}
	$out['active_interface'] = unbound_build_if_list($p['active_interface'])['selected'];
	$out['outgoing_interface'] = unbound_build_if_list($p['outgoing_interface'])['selected'];
	$zones = unbound_local_zone_types();
	$out['system_domain_local_zone_type'] = restapi_unbound_preselect($p['system_domain_local_zone_type'], array_keys($zones));
	$out['python_order'] = restapi_unbound_preselect($p['python_order'], array('pre_validator', 'post_validator'));
	$scripts = unbound_python_scripts();
	$out['python_script'] = restapi_unbound_preselect($p['python_script'], array_keys($scripts));
	$certs = unbound_certs_available() ? cert_build_list('cert', 'IPsec') : array();
	$out['sslcertref'] = $certs ? restapi_unbound_preselect($p['sslcertref'], array_keys($certs)) : '';

	$out['interface_choices'] = restapi_svc_choices(unbound_build_if_list(array())['options']);
	$out['local_zone_type_choices'] = restapi_svc_choices($zones);
	$out['python_script_choices'] = restapi_svc_choices(array_filter($scripts, function ($k) {
		return $k !== '';
	}, ARRAY_FILTER_USE_KEY));
	$out['sslcertref_choices'] = restapi_svc_choices($certs);
	$out['pending'] = is_subsystem_dirty('unbound');
	return $out;
}

function restapi_h_unbound_get($req) {
	return array('data' => restapi_unbound_settings());
}

function restapi_h_unbound_set($req) {
	$types = restapi_unbound_types();
	$values = restapi_svc_merge($req['body'], restapi_unbound_settings(), $types);
	$rv = unbound_save_general(restapi_svc_post($values, $types));
	if (!empty($rv['input_errors'])) {
		throw restapi_validation_error($rv['input_errors']);
	}
	$extra = restapi_unbound_maybe_apply($req);
	return array('data' => array_merge(restapi_unbound_settings(), $extra));
}

/* ------------------------------------------------------------------ */
/* Advanced settings                                                   */
/* ------------------------------------------------------------------ */

function restapi_unbound_adv_types() {
	$types = array('choices' => 'ro', 'pending' => 'ro', 'sock_queue_timeout' => 'string', 'cache_max_ttl' => 'string',
	    'cache_min_ttl' => 'string', 'dns64_prefix' => 'string');
	foreach (array_keys(unbound_advanced_choices()) as $select) {
		$types[$select] = 'string';
	}
	foreach (array('hideidentity', 'hideversion', 'qname-minimisation', 'qname-minimisation-strict', 'prefetch', 'prefetchkey',
	    'dnssecstripped', 'dnsrecordcache', 'aggressivensec', 'infra_keep_probing', 'disable_auto_added_access_control',
	    'disable_auto_added_host_entries', 'use_caps', 'dns64_enable', 'allow_dns64_for_localhost') as $flag) {
		$types[$flag] = 'bool';
	}
	return $types;
}

function restapi_unbound_adv_settings() {
	$p = unbound_advanced_settings();
	$choices = unbound_advanced_choices();
	$out = array();
	foreach (restapi_unbound_adv_types() as $key => $type) {
		if ($type === 'bool') {
			$out[$key] = !empty($p[$key]);
		} elseif (isset($choices[$key])) {
			$out[$key] = restapi_unbound_preselect($p[$key] ?? '', $choices[$key]);
		} elseif ($type === 'string') {
			$out[$key] = (string)($p[$key] ?? '');
		}
	}
	$out['choices'] = $choices;
	$out['pending'] = is_subsystem_dirty('unbound');
	return $out;
}

function restapi_h_unbound_adv_get($req) {
	return array('data' => restapi_unbound_adv_settings());
}

function restapi_h_unbound_adv_set($req) {
	$types = restapi_unbound_adv_types();
	$values = restapi_svc_merge($req['body'], restapi_unbound_adv_settings(), $types);
	$errors = unbound_save_advanced(restapi_svc_post($values, $types));
	if (!empty($errors)) {
		throw restapi_validation_error($errors);
	}
	$extra = restapi_unbound_maybe_apply($req);
	return array('data' => array_merge(restapi_unbound_adv_settings(), $extra));
}

/* ------------------------------------------------------------------ */
/* Host overrides                                                      */
/* ------------------------------------------------------------------ */

function restapi_unbound_hosts() {
	$hosts = config_get_path('unbound/hosts', []);
	return is_array($hosts) ? $hosts : array();
}

function restapi_unbound_host_out($id) {
	$h = (array)config_get_path("unbound/hosts/{$id}", []);
	$aliases = array();
	foreach ((array)array_get_path($h, 'aliases/item', []) as $alias) {
		if (is_array($alias)) {
			$aliases[] = array('host' => (string)($alias['host'] ?? ''), 'domain' => (string)($alias['domain'] ?? ''),
			    'description' => (string)($alias['description'] ?? ''));
		}
	}
	$ip = $h['ip'] ?? '';
	$fields = array('host' => (string)($h['host'] ?? ''), 'domain' => (string)($h['domain'] ?? ''),
	    'ip' => is_array($ip) ? implode(',', $ip) : (string)$ip, 'descr' => (string)($h['descr'] ?? ''), 'aliases' => $aliases);
	return array('id' => (int)$id) + $fields + array('fields' => $fields, 'display' => restapi_unbound_host_display($h));
}

function restapi_unbound_host_types() {
	return array('id' => 'ro', 'host' => 'string', 'domain' => 'string', 'ip' => 'string', 'descr' => 'string', 'aliases' => 'objects',
	    'fields' => 'ro', 'display' => 'ro', 'pending' => 'ro', 'apply' => 'ro');
}

/* Save through the edit page's function; the list is re-sorted, so answer with the entry's new position. */
function restapi_unbound_host_save(array $values, $id, $req, $status = 200) {
	$post = restapi_svc_post($values, restapi_unbound_host_types()) + restapi_dnsmasq_alias_rows($values['aliases'] ?? array());
	$rv = unbound_save_host($post, $id);
	if (!empty($rv['input_errors'])) {
		throw restapi_validation_error($rv['input_errors']);
	}
	if (!isset($rv['id'])) {
		throw new RestApiError(500, 'internal_error', 'The saved host override was not found.');
	}
	$extra = restapi_unbound_maybe_apply($req);
	return array('status' => $status, 'data' => restapi_unbound_host_out($rv['id']) + $extra);
}

function restapi_h_unbound_host_list($req) {
	return array('data' => array_map('restapi_unbound_host_out', array_keys(restapi_unbound_hosts())));
}

function restapi_h_unbound_host_get($req) {
	return array('data' => restapi_unbound_host_out(restapi_index_param($req['params']['id'], restapi_unbound_hosts(), 'host override')));
}

function restapi_h_unbound_host_create($req) {
	$empty = array('host' => '', 'domain' => '', 'ip' => '', 'descr' => '', 'aliases' => array());
	$values = restapi_svc_merge($req['body'], $empty, restapi_unbound_host_types());
	return restapi_unbound_host_save($values, null, $req, 201);
}

function restapi_h_unbound_host_update($req) {
	$id = restapi_index_param($req['params']['id'], restapi_unbound_hosts(), 'host override');
	$values = restapi_svc_merge($req['body'], restapi_unbound_host_out($id), restapi_unbound_host_types());
	return restapi_unbound_host_save($values, $id, $req);
}

function restapi_h_unbound_host_delete($req) {
	$id = restapi_index_param($req['params']['id'], restapi_unbound_hosts(), 'host override');
	unbound_delete_override('host', (string)$id);
	return array('data' => array('deleted' => $id) + restapi_unbound_maybe_apply($req));
}

/* ------------------------------------------------------------------ */
/* Domain overrides                                                    */
/* ------------------------------------------------------------------ */

function restapi_unbound_domains() {
	$domains = config_get_path('unbound/domainoverrides', []);
	return is_array($domains) ? $domains : array();
}

function restapi_unbound_domain_out($id) {
	$d = (array)config_get_path("unbound/domainoverrides/{$id}", []);
	$fields = array('domain' => (string)($d['domain'] ?? ''), 'ip' => (string)($d['ip'] ?? ''),
	    'forward_tls_upstream' => isset($d['forward_tls_upstream']) && ($d['forward_tls_upstream'] !== false),
	    'tls_hostname' => (string)($d['tls_hostname'] ?? ''), 'descr' => (string)($d['descr'] ?? ''));
	return array('id' => (int)$id) + $fields + array('fields' => $fields, 'display' => restapi_unbound_domain_display($d));
}

function restapi_unbound_domain_types() {
	return array('id' => 'ro', 'domain' => 'string', 'ip' => 'string', 'forward_tls_upstream' => 'bool',
	    'tls_hostname' => 'string', 'descr' => 'string', 'fields' => 'ro', 'display' => 'ro', 'pending' => 'ro', 'apply' => 'ro');
}

function restapi_unbound_domain_save(array $values, $id, $req) {
	$errors = unbound_save_domain_override(restapi_svc_post($values, restapi_unbound_domain_types()), $id);
	if (!empty($errors)) {
		throw restapi_validation_error($errors);
	}
	return restapi_unbound_maybe_apply($req);
}

function restapi_h_unbound_domain_list($req) {
	return array('data' => array_map('restapi_unbound_domain_out', array_keys(restapi_unbound_domains())));
}

function restapi_h_unbound_domain_get($req) {
	return array('data' => restapi_unbound_domain_out(restapi_index_param($req['params']['id'], restapi_unbound_domains(), 'domain override')));
}

function restapi_h_unbound_domain_create($req) {
	$empty = array('domain' => '', 'ip' => '', 'forward_tls_upstream' => false, 'tls_hostname' => '', 'descr' => '');
	$values = restapi_svc_merge($req['body'], $empty, restapi_unbound_domain_types());
	$extra = restapi_unbound_domain_save($values, null, $req);
	return array('status' => 201, 'data' => restapi_unbound_domain_out(count(restapi_unbound_domains()) - 1) + $extra);
}

function restapi_h_unbound_domain_update($req) {
	$id = restapi_index_param($req['params']['id'], restapi_unbound_domains(), 'domain override');
	$values = restapi_svc_merge($req['body'], restapi_unbound_domain_out($id), restapi_unbound_domain_types());
	$extra = restapi_unbound_domain_save($values, $id, $req);
	return array('data' => restapi_unbound_domain_out($id) + $extra);
}

function restapi_h_unbound_domain_delete($req) {
	$id = restapi_index_param($req['params']['id'], restapi_unbound_domains(), 'domain override');
	unbound_delete_override('doverride', (string)$id);
	return array('data' => array('deleted' => $id) + restapi_unbound_maybe_apply($req));
}

/* ------------------------------------------------------------------ */
/* Access lists                                                        */
/* ------------------------------------------------------------------ */

function restapi_unbound_acls() {
	$acls = config_get_path('unbound/acls', []);
	return is_array($acls) ? $acls : array();
}

/* The access list actions with their (translated) labels, as the edit page offers them. */
function restapi_unbound_acl_action_labels() {
	return function_exists('unbound_acl_actions') ? unbound_acl_actions() : restapi_unbound_acl_actions();
}

function restapi_unbound_acl_out($id) {
	$a = (array)config_get_path("unbound/acls/{$id}", []);
	$actions = restapi_unbound_acl_action_labels();
	/* The edit page selects the lower-cased action (the first one when it is unknown). */
	$fields = array('aclname' => (string)($a['aclname'] ?? ''),
	    'aclaction' => restapi_unbound_preselect(strtolower((string)($a['aclaction'] ?? '')), array_keys($actions)),
	    'description' => (string)($a['description'] ?? ''), 'networks' => restapi_unbound_acl_grid($a['row'] ?? array()));
	return array('id' => (int)$id, 'aclid' => (string)($a['aclid'] ?? ''), 'aclname' => (string)($a['aclname'] ?? ''),
	    'aclaction' => (string)($a['aclaction'] ?? ''), 'description' => (string)($a['description'] ?? ''),
	    'networks' => restapi_unbound_acl_networks($a['row'] ?? array()),
	    'fields' => $fields, 'display' => restapi_unbound_acl_display($a, $actions));
}

function restapi_unbound_acl_types() {
	return array('id' => 'ro', 'aclid' => 'ro', 'aclname' => 'string', 'aclaction' => 'string', 'description' => 'string',
	    'networks' => 'objects', 'fields' => 'ro', 'display' => 'ro', 'pending' => 'ro', 'apply' => 'ro');
}

function restapi_unbound_acl_save(array $values, $id, $req) {
	$post = restapi_svc_post($values, restapi_unbound_acl_types()) + restapi_unbound_acl_rows($values['networks'] ?? array());
	$rv = unbound_save_acl($post, $id);
	if (!empty($rv['input_errors'])) {
		throw restapi_validation_error($rv['input_errors']);
	}
	return restapi_unbound_maybe_apply($req);
}

function restapi_h_unbound_acl_list($req) {
	return array('data' => array_map('restapi_unbound_acl_out', array_keys(restapi_unbound_acls())));
}

function restapi_h_unbound_acl_get($req) {
	return array('data' => restapi_unbound_acl_out(restapi_index_param($req['params']['id'], restapi_unbound_acls(), 'access list')));
}

function restapi_h_unbound_acl_create($req) {
	$empty = array('aclname' => '', 'aclaction' => 'allow', 'description' => '', 'networks' => array());
	$values = restapi_svc_merge($req['body'], $empty, restapi_unbound_acl_types());
	$extra = restapi_unbound_acl_save($values, null, $req);
	return array('status' => 201, 'data' => restapi_unbound_acl_out(count(restapi_unbound_acls()) - 1) + $extra);
}

function restapi_h_unbound_acl_update($req) {
	$id = restapi_index_param($req['params']['id'], restapi_unbound_acls(), 'access list');
	$values = restapi_svc_merge($req['body'], restapi_unbound_acl_out($id), restapi_unbound_acl_types());
	$extra = restapi_unbound_acl_save($values, $id, $req);
	return array('data' => restapi_unbound_acl_out($id) + $extra);
}

function restapi_h_unbound_acl_delete($req) {
	$id = restapi_index_param($req['params']['id'], restapi_unbound_acls(), 'access list');
	unbound_delete_acl((string)$id);
	return array('data' => array('deleted' => $id) + restapi_unbound_maybe_apply($req));
}
