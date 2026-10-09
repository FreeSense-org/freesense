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
 * the global (Kea) settings, the interfaces the servers can serve, the DHCP
 * server of an interface with its additional address pools and the static
 * mappings of an interface.
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
 * The DHCP server of an interface (GET/PUT /v1/services/dhcp/{if}) and its
 * additional pools (/v1/services/dhcp/{if}/pools, addressed by position) carry
 * the DHCP Server page's fields for the running backend; WINS, DNS and NTP
 * servers and the ISC custom options are lists. Only interfaces the page offers
 * (a static IPv4 subnet smaller than /31) have a DHCP server (409 otherwise).
 * The ISC dynamic DNS and OMAPI keys read as "(set)"; sending "(set)" keeps
 * them.
 *
 * The DHCPv6 server of an interface (GET/PUT /v1/services/dhcpv6/{if}) and its
 * additional pools (/v1/services/dhcpv6/{if}/pools, Kea backend only, like the
 * page) carry the DHCPv6 Server page's fields for the running backend; DNS and
 * NTP servers and the ISC custom options are lists. Only interfaces the page
 * offers (a static IPv6 address, Track Interface or DHCPv6 already enabled)
 * have a DHCPv6 server (409 otherwise). The ISC dynamic DNS key reads as
 * "(set)"; sending "(set)" keeps it.
 *
 * Router advertisements of an interface (GET/PUT
 * /v1/services/router-advertisements/{if}) carry the Router Advertisement
 * page's fields. Like the page, a change is applied at once (the RA daemon is
 * reconfigured; there is nothing to stage). Only interfaces the page offers
 * (a static IPv6 address, Track Interface or no IPv6 configuration, the latter
 * only to disable advertisements) can be changed (409 otherwise).
 * GET /v1/services/router-advertisements lists those interfaces. For the
 * WebUI each carries "fields" (the editor fields of the schema
 * services/router_advertisements, with the RA subnets and DNS servers as
 * rows {subnet} and {server}, which PUT also takes) and "display" (the
 * interface, router mode, priority and DHCPv6 server as text).
 *
 * WebUI 2.0 (capability "dhcp-server"): GET /v1/services/dhcp[v6]/servers
 * lists every interface with its server summary; /servers/{interface} and
 * /servers/{interface}/static-mappings[/{id}] are the same resources as above
 * in the item shape of the WebUI: {interface | id, ...stored..., fields,
 * display}. "fields" are the edit form's fields (the body a PUT takes; the
 * schemas services/dhcp[v6]_server and services/dhcp[v6]_static_mapping,
 * whose address lists are {address} rows) and "display" the 1.x list
 * columns and the interface hints as text. Writes stage like the GUI (GET
 * /v1/services/dhcp[v6]/pending, POST .../apply or ?apply=true) and name
 * their schema, so a 422 keys its messages by field.
 *
 * The front controller loads services_dhcp.inc at file scope.
 */

require_once('restapi/framework.inc');
require_once('restapi/routes_services.inc');
require_once('restapi/routes_ntp.inc');
require_once('restapi/routes_ddns.inc');
require_once('restapi/schemas/services_radvd.inc');
require_once('restapi/schemas/services_dhcp.inc');

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
	$server_body = array('type' => 'object', 'description' => 'The DHCP Server page of the interface: enable, staticarp, ' .
	    'netboot (booleans); range_from, range_to, denyunknown (disabled|enabled|class), gateway, domain, domainsearchlist, ' .
	    'deftime, maxtime, mac_allow, mac_deny, tftp, ldap, nextserver, filename, filename32, filename64, filename32arm, ' .
	    'filename64arm, uefihttpboot, rootpath (strings); winsserver (at most 2), dnsserver (at most 4), ntpserver (at most 4) ' .
	    '(lists of addresses); ignoreclientuids (boolean). Kea backend: dnsregpolicy, earlydnsregpolicy (default|enable|disable), ' .
	    'custom_kea_config (JSON text). ISC backend: ignorebootp, nonak, omapi_gen_key, ddnsupdate, ddnsforcehostname, ' .
	    'dhcpleaseinlocaltime, statsgraph, disablepingcheck (booleans); failover_peerip, omapi_port, omapi_key, ' .
	    'omapi_key_algorithm, ddnsdomain, ddnsdomainprimary, ddnsdomainprimaryport, ddnsdomainsecondary, ' .
	    'ddnsdomainsecondaryport, ddnsdomainkeyname, ddnsdomainkeyalgorithm, ddnsdomainkey, ddnsclientupdates (strings; ' .
	    '"(set)" keeps a key); numberoptions (list of {number, type, value}). Omitted fields keep their value.');
	$pool_body = array('type' => 'object', 'description' => 'An additional address pool: the fields of the DHCP server ' .
	    'of the interface that the pool form has: descr, range_from, range_to (required), denyunknown, the server lists, gateway, ' .
	    'domain, domainsearchlist, mac_allow, mac_deny, tftp, ldap, netboot and the boot file names; Kea: custom_kea_config; ' .
	    'ISC: also ignoreclientuids, deftime, maxtime, nextserver, the OMAPI, dynamic DNS and custom option fields. ' .
	    'Omitted fields keep their value (create: empty).');
	$server6_body = array('type' => 'object', 'description' => 'The DHCPv6 Server page of the interface: enable, dhcp6c-dns ' .
	    '(provide DNS servers), netboot (booleans); range_from, range_to, denyunknown (disabled|enabled|class), domain, ' .
	    'domainsearchlist, deftime, maxtime, bootfile_url (strings); dnsserver, ntpserver (lists of at most 4 IPv6 addresses). ' .
	    'Kea backend: dnsregpolicy, earlydnsregpolicy (default|enable|disable), pdprefix, pdprefixlen, pddellen (delegated ' .
	    'prefixes), custom_kea_config (JSON text). ISC backend: prefixrange_from, prefixrange_to, prefixrange_length, ldap, ' .
	    'ddnsdomain, ddnsdomainprimary, ddnsdomainsecondary, ddnsdomainkeyname, ddnsdomainkeyalgorithm, ddnsdomainkey ("(set)" ' .
	    'keeps it), ddnsclientupdates (strings); dhcpv6leaseinlocaltime, ddnsupdate, ddnsforcehostname, ddnsreverse (booleans); ' .
	    'numberoptions (list of {number, value}). Omitted fields keep their value.');
	$pool6_body = array('type' => 'object', 'description' => 'An additional DHCPv6 address pool (Kea backend): descr, ' .
	    'range_from, range_to (required), denyunknown, domain, domainsearchlist, bootfile_url, custom_kea_config (strings); ' .
	    'dnsserver, ntpserver (lists); netboot (boolean). Omitted fields keep their value (create: empty).');
	$ra_body = array('type' => 'object', 'description' => 'The Router Advertisement page of the interface: ramode, rapriority ' .
	    '(from "choices"), rainterface (the interface or one of its IPv6 CARP VIPs, from "choices"; empty without such a VIP), ' .
	    'ravalidlifetime, rapreferredlifetime, raminrtradvinterval, ramaxrtradvinterval, raadvdefaultlifetime, pref64_lifetime, ' .
	    'pref64_prefix (only when the NAT64 prefix override is allowed), radomainsearchlist (strings); pref64_enable, radvd-dns, ' .
	    'rasamednsasdhcp6 (booleans); subnets (list of "address/bits" or aliases), radnsserver (list of at most 4). Omitted ' .
	    'fields keep their value. Applied at once, like the page.');
	$map6_body = array('type' => 'object', 'description' => 'The DHCPv6 static mapping edit form: duid (required), ' .
	    'ipaddrv6, hostname, descr, earlydnsregpolicy (default|enable|disable). Kea backend: pdprefix (delegated ' .
	    'prefix/length), custom_kea_config (JSON text). ISC backend with network booting enabled on the interface: ' .
	    'filename, rootpath. Omitted fields keep their value (create: empty).');

	$with_schema = function (array $r, $schema) {
		$r['schema'] = $schema;
		return $r;
	};
	$srv_body = array('type' => 'object', 'description' => 'The "fields" of the server (schema services/dhcp_server or ' .
	    'services/dhcpv6_server): the DHCP[v6] Server page\'s fields; the address lists (winsserver, dnsserver, ntpserver) ' .
	    'as lists of addresses or of {address} rows. Omitted fields keep their value; false clears a switch; "(set)" keeps a key.');
	$smap_body = array('type' => 'object', 'description' => 'The "fields" of the static mapping (schema ' .
	    'services/dhcp_static_mapping or services/dhcpv6_static_mapping): the edit page\'s fields; the address lists as ' .
	    'lists of addresses or of {address} rows. Omitted fields keep their value (create: empty).');

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
			$with_schema($route('PUT', "{$base}/settings", "restapi_h_dhcp{$v}_settings_set", "services_{$svc}_settings.php",
			    "Change {$name} server settings (Kea backend; omitted fields keep their value)", true, $settings_body),
			    "services/{$svc}_settings"),
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
	/* WebUI 2.0: servers by interface with "fields" and "display"; before {if}, which would match "servers" and "pending" */
	foreach (array('dhcp' => '', 'dhcpv6' => '6') as $svc => $v) {
		$base = "/v1/services/{$svc}";
		$list = "services_{$svc}.php";
		$edit = "services_{$svc}_edit.php";
		$name = $v ? 'DHCPv6' : 'DHCP';
		$routes = array_merge($routes, array(
			$route('GET', "{$base}/pending", "restapi_h_dhcp{$v}_pending", $list,
			    "Whether {$name} server changes are staged until POST {$base}/apply"),
			$route('GET', "{$base}/servers", "restapi_h_dhcp{$v}_servers", $list,
			    "Every interface with its {$name} server: enabled, range, static mappings and whether the server can run on it"),
			$route('GET', "{$base}/servers/{interface}", "restapi_h_dhcp{$v}_srv_get", $list,
			    "The {$name} server of an interface: stored settings, \"fields\" (the edit form) and \"display\" (texts and hints)"),
			$with_schema($route('PUT', "{$base}/servers/{interface}", "restapi_h_dhcp{$v}_srv_set", $list,
			    "Change the {$name} server of an interface (omitted fields keep their value; staged like the GUI)", true, $srv_body),
			    "services/{$svc}_server"),
			$route('GET', "{$base}/servers/{interface}/static-mappings", "restapi_h_dhcp{$v}_smap_list", $list,
			    "The {$name} static mappings of an interface with \"fields\" and \"display\" (sorted by address)"),
			$route('GET', "{$base}/servers/{interface}/static-mappings/{id}", "restapi_h_dhcp{$v}_smap_get", $list,
			    "A {$name} static mapping by position or " . ($v ? 'DUID' : 'MAC address')),
			$with_schema($route('POST', "{$base}/servers/{interface}/static-mappings", "restapi_h_dhcp{$v}_smap_create", $edit,
			    "Create a {$name} static mapping (the response has its id)", true, $smap_body), "services/{$svc}_static_mapping"),
			$with_schema($route('PUT', "{$base}/servers/{interface}/static-mappings/{id}", "restapi_h_dhcp{$v}_smap_update", $edit,
			    "Update a {$name} static mapping (omitted fields keep their value; the response has its new id)", true, $smap_body),
			    "services/{$svc}_static_mapping"),
			$route('DELETE', "{$base}/servers/{interface}/static-mappings/{id}", "restapi_h_dhcp{$v}_smap_delete", $list,
			    "Delete a {$name} static mapping", true),
		));
	}

	/* after the fixed paths (settings, interfaces, apply, pending, servers), which {if} would match too */
	$base = '/v1/services/dhcp';
	$routes = array_merge($routes, array(
		$route('GET', "{$base}/{if}", 'restapi_h_dhcp_server_get', 'services_dhcp.php',
		    'The DHCP server of an interface: its settings for the running backend, choices and whether changes are pending'),
		$route('PUT', "{$base}/{if}", 'restapi_h_dhcp_server_set', 'services_dhcp.php',
		    'Change the DHCP server of an interface (omitted fields keep their value)', true, $server_body),
		$route('GET', "{$base}/{if}/pools", 'restapi_h_dhcp_pool_list', 'services_dhcp.php',
		    'List the additional address pools of an interface'),
		$route('GET', "{$base}/{if}/pools/{id}", 'restapi_h_dhcp_pool_get', 'services_dhcp.php',
		    'Get an additional address pool by position'),
		$route('POST', "{$base}/{if}/pools", 'restapi_h_dhcp_pool_create', 'services_dhcp.php',
		    'Add an address pool (the response has its id)', true, $pool_body),
		$route('PUT', "{$base}/{if}/pools/{id}", 'restapi_h_dhcp_pool_update', 'services_dhcp.php',
		    'Change an address pool (omitted fields keep their value)', true, $pool_body),
		$route('DELETE', "{$base}/{if}/pools/{id}", 'restapi_h_dhcp_pool_delete', 'services_dhcp.php',
		    'Delete an address pool', true),
	));
	/* likewise after the fixed DHCPv6 paths */
	$base = '/v1/services/dhcpv6';
	$routes = array_merge($routes, array(
		$route('GET', "{$base}/{if}", 'restapi_h_dhcp6_server_get', 'services_dhcpv6.php',
		    'The DHCPv6 server of an interface: its settings for the running backend, choices and whether changes are pending'),
		$route('PUT', "{$base}/{if}", 'restapi_h_dhcp6_server_set', 'services_dhcpv6.php',
		    'Change the DHCPv6 server of an interface (omitted fields keep their value)', true, $server6_body),
		$route('GET', "{$base}/{if}/pools", 'restapi_h_dhcp6_pool_list', 'services_dhcpv6.php',
		    'List the additional DHCPv6 address pools of an interface (Kea backend)'),
		$route('GET', "{$base}/{if}/pools/{id}", 'restapi_h_dhcp6_pool_get', 'services_dhcpv6.php',
		    'Get an additional DHCPv6 address pool by position (Kea backend)'),
		$route('POST', "{$base}/{if}/pools", 'restapi_h_dhcp6_pool_create', 'services_dhcpv6.php',
		    'Add a DHCPv6 address pool (Kea backend; the response has its id)', true, $pool6_body),
		$route('PUT', "{$base}/{if}/pools/{id}", 'restapi_h_dhcp6_pool_update', 'services_dhcpv6.php',
		    'Change a DHCPv6 address pool (Kea backend; omitted fields keep their value)', true, $pool6_body),
		$route('DELETE', "{$base}/{if}/pools/{id}", 'restapi_h_dhcp6_pool_delete', 'services_dhcpv6.php',
		    'Delete a DHCPv6 address pool (Kea backend)', true),
		$route('GET', '/v1/services/router-advertisements', 'restapi_h_radvd_list', 'services_radvd.php',
		    'Interfaces that can send router advertisements, with "fields" and "display" (interface, mode, priority, DHCPv6 server as text)'),
		$route('GET', '/v1/services/router-advertisements/{if}', 'restapi_h_radvd_get', 'services_radvd.php',
		    'Router advertisements of an interface: the settings and their choices (with "fields" and "display")'),
		/* applied at once like the page: no ?apply */
		restapi_route('PUT', '/v1/services/router-advertisements/{if}', 'restapi_h_radvd_set', array('page' => 'services_radvd.php',
		    'area' => $area, 'write' => true, 'body' => $ra_body, 'schema' => 'services/router_advertisements',
		    'summary' => 'Change the router advertisements of an interface (applied at once; omitted fields keep their value)')),
	));
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

/*
 * Custom DHCP options (ISC): API list of {number, type, value} as the edit
 * form's rows; with $typed false (DHCPv6) a list of {number, value}.
 */
function restapi_dhcp_option_rows($options, $typed = true) {
	$shape = $typed ? '{number, type, value}' : '{number, value}';
	$keys = $typed ? array('number', 'type', 'value') : array('number', 'value');
	if (!is_array($options) || !array_is_list($options)) {
		throw new RestApiError(400, 'invalid_field', "\"numberoptions\" must be a list of {$shape}.");
	}
	if (count($options) > 99) {
		throw new RestApiError(400, 'invalid_field', 'At most 99 custom options.');
	}
	$post = array();
	foreach ($options as $i => $opt) {
		if (!is_array($opt) || array_diff(array_keys($opt), $keys)) {
			throw new RestApiError(400, 'invalid_field', "\"numberoptions\" must be a list of {$shape}.");
		}
		foreach ($opt as $v) {
			if (!is_scalar($v)) {
				throw new RestApiError(400, 'invalid_field', "\"numberoptions\" must be a list of {$shape}.");
			}
		}
		$post["number{$i}"] = (string)($opt['number'] ?? '');
		if ($typed) {
			$post["itemtype{$i}"] = (string)($opt['type'] ?? 'text');
		}
		$post["value{$i}"] = (string)($opt['value'] ?? '');
	}
	return $post;
}

/* Stored custom options ({item: [{number, type, value (base64)}]}) as the API list; $typed false: DHCPv6 (no type). */
function restapi_dhcp_options_out($numberoptions, $typed = true) {
	$out = array();
	foreach ((array)($numberoptions['item'] ?? array()) as $item) {
		if (is_array($item)) {
			$out[] = $typed ? array('number' => (string)($item['number'] ?? ''), 'type' => (string)($item['type'] ?? ''),
			    'value' => base64_decode((string)($item['value'] ?? ''))) :
			    array('number' => (string)($item['number'] ?? ''), 'value' => base64_decode((string)($item['value'] ?? '')));
		}
	}
	return $out;
}

/* RA subnets (API list of "address/bits" or an alias) as the Router Advertisement form's rows. */
function restapi_radvd_subnet_rows(array $subnets) {
	if (count($subnets) > 5000) {
		throw new RestApiError(400, 'invalid_field', 'Field "subnets" has at most 5000 entries.');
	}
	$post = array();
	foreach (array_values($subnets) as $i => $subnet) {
		$parts = explode('/', (string)$subnet, 2);
		$post["subnet_address{$i}"] = $parts[0];
		$post["subnet_bits{$i}"] = $parts[1] ?? '';
	}
	return $post;
}

/* RA DNS servers (API list of at most 4) as the form's radns1..radns4 fields. */
function restapi_radvd_dns_rows(array $servers) {
	if (count($servers) > 4) {
		throw new RestApiError(400, 'invalid_field', 'Field "radnsserver" has at most 4 entries.');
	}
	$servers = array_values($servers);
	$post = array();
	for ($i = 1; $i <= 4; $i++) {
		$post["radns{$i}"] = (string)($servers[$i - 1] ?? '');
	}
	return $post;
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
	    'custom_kea_config_editable' => 'ro', 'pending' => 'ro', 'backend' => 'ro', 'fields' => 'ro');
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
	/* WebUI 2.0: the backend (Kea only) and the form of the schema services/dhcp[v6]_settings */
	$out['backend'] = (string)dhcp_get_backend();
	$fields = array('backend' => $out['backend']);
	foreach (restapi_dhcp_settings_types() as $key => $type) {
		if ($type !== 'ro') {
			$fields[$key] = $out[$key];
		}
	}
	$out['fields'] = $fields;
	return $out;
}

function restapi_dhcp_settings_save($req, $v6) {
	restapi_dhcp_settings_section($v6);
	/* the backend is read-only: Kea is the only one */
	if (isset($req['body']['backend']) && ((string)$req['body']['backend'] !== (string)dhcp_get_backend())) {
		throw restapi_validation_error(array(gettext('Only the Kea DHCP backend is available in FreeSense.')));
	}
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

/* Save through the edit page's save; the response is restapi_dhcp_map_out(), or $shape($if, $id) (the WebUI item). */
function restapi_dhcp_map_save($req, $if, array $values, $id, $status = 200, ?callable $shape = null) {
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
	if ($shape !== null) {
		return array('status' => $status, 'data' => $shape($if, $rv['id']) + array('warnings' => $warnings) + $extra);
	}
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
/* DHCP server of an interface and its address pools                  */
/* ------------------------------------------------------------------ */

/* The "DDNS Client Updates" choices of the DHCP Server page (ISC). */
function restapi_dhcp_ddnsclientupdates_choices() {
	return array('allow' => gettext('Allow'), 'deny' => gettext('Deny'), 'ignore' => gettext('Ignore'));
}

/* The OMAPI key algorithms of the DHCP Server page (ISC). */
function restapi_dhcp_omapi_algorithms() {
	return array(
		'hmac-md5' => 'HMAC-MD5 (legacy default)',
		'hmac-sha1' => 'HMAC-SHA1',
		'hmac-sha224' => 'HMAC-SHA224',
		'hmac-sha256' => 'HMAC-SHA256 (current bind9 default)',
		'hmac-sha384' => 'HMAC-SHA384',
		'hmac-sha512' => 'HMAC-SHA512 (most secure)',
	);
}

/*
 * The DHCP Server page's fields for the running backend: the interface's
 * form, or with $pool an additional pool's form.
 */
function restapi_dhcp_server_types($pool) {
	$kea = dhcp_is_backend('kea');
	$types = $pool ? array('id' => 'ro', 'descr' => 'string') :
	    array('interface' => 'ro', 'backend' => 'ro', 'subnet' => 'ro', 'pending' => 'ro', 'pools' => 'ro', 'choices' => 'ro',
	    'enable' => 'bool', 'staticarp' => 'bool');
	foreach (array('range_from', 'range_to', 'denyunknown', 'gateway', 'domain', 'domainsearchlist', 'mac_allow', 'mac_deny',
	    'tftp', 'ldap', 'filename', 'filename32', 'filename64', 'filename32arm', 'filename64arm', 'uefihttpboot', 'rootpath') as $field) {
		$types[$field] = 'string';
	}
	$types += array('winsserver' => 'list', 'dnsserver' => 'list', 'ntpserver' => 'list', 'netboot' => 'bool');
	if (!$kea || !$pool) {
		$types += array('ignoreclientuids' => 'bool', 'deftime' => 'string', 'maxtime' => 'string', 'nextserver' => 'string');
	}
	if ($kea) {
		if (!$pool) {
			$types += array('dnsregpolicy' => 'string', 'earlydnsregpolicy' => 'string');
		}
		$types['custom_kea_config'] = 'string';
	} else {
		$types += array('ignorebootp' => 'bool', 'nonak' => 'bool', 'omapi_port' => 'string', 'omapi_key' => 'string',
		    'omapi_gen_key' => 'bool', 'omapi_key_algorithm' => 'string');
		if (!$pool) {
			$types += array('failover_peerip' => 'string', 'dhcpleaseinlocaltime' => 'bool', 'statsgraph' => 'bool',
			    'disablepingcheck' => 'bool');
		}
		foreach (array('ddnsupdate', 'ddnsforcehostname') as $flag) {
			$types[$flag] = 'bool';
		}
		foreach (array('ddnsdomain', 'ddnsdomainprimary', 'ddnsdomainprimaryport', 'ddnsdomainsecondary', 'ddnsdomainsecondaryport',
		    'ddnsdomainkeyname', 'ddnsdomainkeyalgorithm', 'ddnsdomainkey', 'ddnsclientupdates') as $field) {
			$types[$field] = 'string';
		}
		$types['numberoptions'] = 'objects';
	}
	return $types;
}

/* The interface of a DHCP server route: a configured interface (else 404) the page offers (else 409). */
function restapi_dhcp_server_iface($req) {
	$if = restapi_dhcp_iface($req, false);
	$ctx = dhcp_iface_context($if);
	if (empty($ctx['eligible'])) {
		throw new RestApiError(409, 'not_available',
		    'The DHCP server can only serve an interface with a static IPv4 subnet smaller than /31.');
	}
	return $if;
}

function restapi_dhcp_pools($if) {
	$pools = config_get_path("dhcpd/{$if}/pool", []);
	return is_array($pools) ? $pools : array();
}

/*
 * The form values the DHCP Server page shows for the interface ($id null),
 * pool $id, or with $act "newpool" a new pool (keys unmasked). Checkboxes the
 * page reads with isset() come from the stored settings (a flag held as false
 * right after a save reads as unticked); selects read as the form preselects
 * them.
 */
function restapi_dhcp_server_values($if, $id, $act = '') {
	global $ddnsdomainkeyalgorithms;
	$pool = (($id !== null) || ($act === 'newpool'));
	$conf = dhcp_server_conf($if, $id, $act);
	$p = dhcp_server_form($conf, $pool);
	$stored = is_array($conf) ? $conf : array();
	$out = array();
	foreach (restapi_dhcp_server_types($pool) as $key => $type) {
		if ($type === 'bool') {
			$out[$key] = in_array($key, array('dhcpleaseinlocaltime', 'statsgraph', 'disablepingcheck', 'omapi_gen_key'), true) ?
			    !empty($p[$key]) : restapi_dhcp_flag($stored, $key);
		} elseif ($type === 'string') {
			$out[$key] = (string)($p[$key] ?? '');
		}
	}
	$out['winsserver'] = restapi_dhcp_addr_list($stored['winsserver'] ?? array());
	$out['dnsserver'] = restapi_dhcp_addr_list($stored['dnsserver'] ?? array());
	$out['ntpserver'] = restapi_dhcp_addr_list($stored['ntpserver'] ?? array());
	$out['denyunknown'] = restapi_select_choice($p['denyunknown'] ?? 'disabled', restapi_dhcp_denyunknown_choices());
	if (array_key_exists('dnsregpolicy', $out)) {
		$out['dnsregpolicy'] = restapi_select_choice($p['dnsregpolicy'] ?? 'default', dhcp_server_dnsregpolicy_values());
		$out['earlydnsregpolicy'] = restapi_select_choice($p['earlydnsregpolicy'] ?? 'default', dhcp_server_dnsregpolicy_values());
	}
	if (!dhcp_is_backend('kea')) {
		$out['omapi_key_algorithm'] = empty($p['omapi_key_algorithm']) ? 'hmac-sha256' :
		    restapi_select_choice($p['omapi_key_algorithm'], restapi_dhcp_omapi_algorithms());
		$out['ddnsdomainkeyalgorithm'] = restapi_select_choice($p['ddnsdomainkeyalgorithm'] ?? '', (array)$ddnsdomainkeyalgorithms);
		$out['ddnsclientupdates'] = restapi_select_choice($p['ddnsclientupdates'] ?? '', restapi_dhcp_ddnsclientupdates_choices());
		$out['numberoptions'] = restapi_dhcp_options_out($stored['numberoptions'] ?? array());
	}
	return $out;
}

/* The keys of a DHCP server or pool read as "(set)". */
function restapi_dhcp_server_mask(array $out) {
	foreach (array('ddnsdomainkey', 'omapi_key') as $key) {
		if (array_key_exists($key, $out)) {
			$out[$key] = restapi_svc_mask($out[$key]);
		}
	}
	return $out;
}

/* A body without the "(set)" placeholders of the keys (they keep the stored key). */
function restapi_dhcp_server_body(array $body) {
	return restapi_ddns_secret_body(restapi_ddns_secret_body($body, 'ddnsdomainkey'), 'omapi_key');
}

function restapi_dhcp_server_out($if) {
	global $ddnsdomainkeyalgorithms;
	$choices = array('denyunknown' => restapi_svc_choices(restapi_dhcp_denyunknown_choices()));
	if (dhcp_is_backend('kea')) {
		$choices['dnsregpolicy'] = restapi_svc_choices(dhcp_server_dnsregpolicy_values());
	} else {
		$choices['omapi_key_algorithm'] = restapi_svc_choices(restapi_dhcp_omapi_algorithms());
		$choices['ddnsdomainkeyalgorithm'] = restapi_svc_choices((array)$ddnsdomainkeyalgorithms);
		$choices['ddnsclientupdates'] = restapi_svc_choices(restapi_dhcp_ddnsclientupdates_choices());
	}
	$cfgip = config_get_path("interfaces/{$if}/ipaddr");
	$cfgsn = config_get_path("interfaces/{$if}/subnet");
	return array('interface' => $if, 'backend' => (string)dhcp_get_backend(), 'subnet' => gen_subnet($cfgip, $cfgsn) . '/' . $cfgsn,
	    'pending' => restapi_dhcp_pending(false), 'pools' => count(restapi_dhcp_pools($if))) +
	    restapi_dhcp_server_mask(restapi_dhcp_server_values($if, null)) + array('choices' => $choices);
}

function restapi_dhcp_pool_out($if, $id) {
	return array('id' => (int)$id) + restapi_dhcp_server_mask(restapi_dhcp_server_values($if, (int)$id));
}

/* The page's form for $values as the browser posts it. */
function restapi_dhcp_server_post($if, array $values, $pool) {
	$types = restapi_dhcp_server_types($pool);
	$post = restapi_svc_post($values, $types);
	unset($post['winsserver'], $post['dnsserver'], $post['ntpserver']);
	$post += restapi_dhcp_server_rows($values);
	if (array_key_exists('numberoptions', $types)) {
		$post += restapi_dhcp_option_rows($values['numberoptions'] ?? array());
	}
	$post['if'] = $if;
	$post['save'] = 'Save';
	return $post;
}

/*
 * Save the interface ($id null), pool $id or a new pool ($act "newpool")
 * through the page's save; $shape($if) builds the response of an interface
 * save instead of restapi_dhcp_server_out() (the WebUI item).
 */
function restapi_dhcp_server_write($req, $if, array $values, $id, $act = '', $status = 200, ?callable $shape = null) {
	$pool = (($id !== null) || ($act === 'newpool'));
	$post = restapi_dhcp_server_post($if, $values, $pool);
	if ($act === 'newpool') {
		$post['act'] = 'newpool';
	} elseif ($id !== null) {
		$post['pool'] = (string)$id;
	}
	$rv = dhcp_server_save($if, $id, $act, $post);
	if ($rv['missing_pool']) {
		throw new RestApiError(404, 'not_found', 'No pool with that id.');
	}
	if (!empty($rv['input_errors'])) {
		throw restapi_validation_error($rv['input_errors']);
	}
	if (!$rv['saved'] || ($pool && !isset($rv['id']))) {
		throw new RestApiError(500, 'internal_error', 'The DHCP server settings were not saved.');
	}
	$extra = restapi_dhcp_maybe_apply($req, false);
	$data = $pool ? restapi_dhcp_pool_out($if, $rv['id']) : (($shape !== null) ? $shape($if) : restapi_dhcp_server_out($if));
	return array('status' => $status, 'data' => array_merge($data, $extra));
}

function restapi_h_dhcp_server_get($req) {
	$if = restapi_dhcp_server_iface($req);
	return array('data' => restapi_dhcp_server_out($if));
}

function restapi_h_dhcp_server_set($req) {
	$if = restapi_dhcp_server_iface($req);
	$values = restapi_svc_merge(restapi_dhcp_server_body($req['body']), restapi_dhcp_server_values($if, null),
	    restapi_dhcp_server_types(false));
	return restapi_dhcp_server_write($req, $if, $values, null);
}

function restapi_h_dhcp_pool_list($req) {
	$if = restapi_dhcp_server_iface($req);
	return array('data' => array_map(function ($id) use ($if) {
		return restapi_dhcp_pool_out($if, $id);
	}, array_keys(restapi_dhcp_pools($if))));
}

function restapi_h_dhcp_pool_get($req) {
	$if = restapi_dhcp_server_iface($req);
	return array('data' => restapi_dhcp_pool_out($if, restapi_index_param($req['params']['id'], restapi_dhcp_pools($if), 'pool')));
}

function restapi_h_dhcp_pool_create($req) {
	$if = restapi_dhcp_server_iface($req);
	$values = restapi_svc_merge(restapi_dhcp_server_body($req['body']), restapi_dhcp_server_values($if, null, 'newpool'),
	    restapi_dhcp_server_types(true));
	return restapi_dhcp_server_write($req, $if, $values, null, 'newpool', 201);
}

function restapi_h_dhcp_pool_update($req) {
	$if = restapi_dhcp_server_iface($req);
	$id = restapi_index_param($req['params']['id'], restapi_dhcp_pools($if), 'pool');
	$values = restapi_svc_merge(restapi_dhcp_server_body($req['body']), restapi_dhcp_server_values($if, $id),
	    restapi_dhcp_server_types(true));
	return restapi_dhcp_server_write($req, $if, $values, $id);
}

function restapi_h_dhcp_pool_delete($req) {
	$if = restapi_dhcp_server_iface($req);
	$id = restapi_index_param($req['params']['id'], restapi_dhcp_pools($if), 'pool');
	if (!dhcp_pool_delete($if, $id)) {
		throw new RestApiError(404, 'not_found', 'No pool with that id.');
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

/* Save through the edit page's save; the response is restapi_dhcp6_map_out(), or $shape($if, $id) (the WebUI item). */
function restapi_dhcp6_map_save($req, $if, array $values, $id, $status = 200, ?callable $shape = null) {
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
	return array('status' => $status, 'data' => (($shape !== null) ? $shape($if, $rv['id']) : restapi_dhcp6_map_out($if, $rv['id'])) + $extra);
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

/* A new DHCPv6 mapping: what the empty edit form posts. */
function restapi_dhcp6_map_empty($if) {
	$empty = array_map(function ($t) {
		return '';
	}, array_filter(restapi_dhcp6_map_types($if), function ($t) {
		return $t === 'string';
	}));
	$empty['earlydnsregpolicy'] = 'default';
	return $empty;
}

function restapi_h_dhcp6_map_create($req) {
	$if = restapi_dhcp_iface($req, true);
	$values = restapi_svc_merge($req['body'], restapi_dhcp6_map_empty($if), restapi_dhcp6_map_types($if));
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

/* ------------------------------------------------------------------ */
/* DHCPv6 server of an interface and its address pools                */
/* ------------------------------------------------------------------ */

/* The "Prefix Delegation Size" choices of the DHCPv6 Server page (ISC). */
function restapi_dhcp6_prefixrange_lengths() {
	$out = array();
	foreach (array('48', '52', '56', '59', '60', '61', '62', '63', '64') as $len) {
		$out[$len] = $len;
	}
	return $out;
}

/*
 * The DHCPv6 Server page's fields for the running backend: the interface's
 * form, or with $pool an additional pool's form (pools: Kea backend only).
 */
function restapi_dhcp6_server_types($pool) {
	$kea = dhcp_is_backend('kea');
	$types = $pool ? array('id' => 'ro', 'descr' => 'string') :
	    array('interface' => 'ro', 'backend' => 'ro', 'prefix' => 'ro', 'track6' => 'ro', 'ramode' => 'ro', 'pending' => 'ro',
	    'pools' => 'ro', 'choices' => 'ro', 'enable' => 'bool');
	$types += array('denyunknown' => 'string', 'range_from' => 'string', 'range_to' => 'string');
	if (!$pool) {
		if ($kea) {
			foreach (array('dnsregpolicy', 'earlydnsregpolicy', 'pdprefix', 'pdprefixlen', 'pddellen') as $field) {
				$types[$field] = 'string';
			}
		} else {
			foreach (array('prefixrange_from', 'prefixrange_to', 'prefixrange_length') as $field) {
				$types[$field] = 'string';
			}
		}
		$types['dhcp6c-dns'] = 'bool';
	}
	$types += array('dnsserver' => 'list', 'domain' => 'string', 'domainsearchlist' => 'string');
	if (!$kea || !$pool) {
		$types += array('deftime' => 'string', 'maxtime' => 'string');
	}
	if (!$kea) {
		$types += array('dhcpv6leaseinlocaltime' => 'bool', 'ddnsupdate' => 'bool', 'ddnsforcehostname' => 'bool', 'ddnsreverse' => 'bool');
		foreach (array('ddnsdomain', 'ddnsdomainprimary', 'ddnsdomainsecondary', 'ddnsdomainkeyname', 'ddnsdomainkeyalgorithm',
		    'ddnsdomainkey', 'ddnsclientupdates', 'ldap') as $field) {
			$types[$field] = 'string';
		}
	}
	$types += array('ntpserver' => 'list', 'netboot' => 'bool', 'bootfile_url' => 'string');
	if ($kea) {
		$types['custom_kea_config'] = 'string';
	} else {
		$types['numberoptions'] = 'objects';
	}
	return $types;
}

/*
 * The interface of a DHCPv6 server route: a configured interface (else 404)
 * the page offers (else 409); pools need the Kea backend (else 409).
 */
function restapi_dhcp6_server_iface($req, $pools = false) {
	$if = restapi_dhcp_iface($req, true);
	if (!dhcp6_server_offered($if)) {
		throw new RestApiError(409, 'not_available',
		    'The DHCPv6 server can only serve an interface with a static IPv6 address or Track Interface.');
	}
	if ($pools && !dhcp_is_backend('kea')) {
		throw new RestApiError(409, 'not_available', 'Additional DHCPv6 address pools exist only with the Kea DHCP backend.');
	}
	return $if;
}

function restapi_dhcp6_pools($if) {
	$pools = config_get_path("dhcpdv6/{$if}/pool", []);
	return is_array($pools) ? $pools : array();
}

/*
 * The form values the DHCPv6 Server page shows for the interface ($id null),
 * pool $id, or with $act "newpool" a new pool (the key unmasked). Checkboxes
 * the page reads with isset() come from the stored settings; selects read as
 * the form preselects them.
 */
function restapi_dhcp6_server_values($if, $id, $act = '') {
	$pool = (($id !== null) || ($act === 'newpool'));
	$conf = dhcp6_server_conf($if, $id, $act);
	$p = dhcp6_server_form($conf, $pool);
	$stored = is_array($conf) ? $conf : array();
	$out = array();
	foreach (restapi_dhcp6_server_types($pool) as $key => $type) {
		if ($type === 'bool') {
			if ($key === 'dhcp6c-dns') {
				$out[$key] = (($p[$key] ?? '') === 'enabled');
			} elseif ($key === 'dhcpv6leaseinlocaltime') {
				$out[$key] = (($p[$key] ?? '') == 'yes');
			} else {
				$out[$key] = restapi_dhcp_flag($stored, $key);
			}
		} elseif ($type === 'string') {
			$out[$key] = (string)($p[$key] ?? '');
		}
	}
	$out['dnsserver'] = restapi_dhcp_addr_list($stored['dnsserver'] ?? array());
	$out['ntpserver'] = restapi_dhcp_addr_list($stored['ntpserver'] ?? array());
	$out['denyunknown'] = restapi_select_choice($p['denyunknown'] ?? 'disabled', restapi_dhcp_denyunknown_choices());
	if (array_key_exists('dnsregpolicy', $out)) {
		$out['dnsregpolicy'] = restapi_select_choice($p['dnsregpolicy'] ?? 'default', dhcp_server_dnsregpolicy_values());
		$out['earlydnsregpolicy'] = restapi_select_choice($p['earlydnsregpolicy'] ?? 'default', dhcp_server_dnsregpolicy_values());
	}
	if (array_key_exists('prefixrange_length', $out)) {
		$out['prefixrange_length'] = restapi_select_choice($p['prefixrange_length'] ?? '', restapi_dhcp6_prefixrange_lengths());
	}
	if (!dhcp_is_backend('kea')) {
		$out['ddnsdomainkeyalgorithm'] = restapi_select_choice($p['ddnsdomainkeyalgorithm'] ?? '', restapi_dhcp_omapi_algorithms());
		$out['ddnsclientupdates'] = restapi_select_choice($p['ddnsclientupdates'] ?? '', restapi_dhcp_ddnsclientupdates_choices());
		$out['numberoptions'] = restapi_dhcp_options_out($stored['numberoptions'] ?? array(), false);
	}
	return $out;
}

/* The dynamic DNS key reads as "(set)". */
function restapi_dhcp6_server_mask(array $out) {
	if (array_key_exists('ddnsdomainkey', $out)) {
		$out['ddnsdomainkey'] = restapi_svc_mask($out['ddnsdomainkey']);
	}
	return $out;
}

function restapi_dhcp6_server_out($if) {
	$choices = array('denyunknown' => restapi_svc_choices(restapi_dhcp_denyunknown_choices()));
	if (dhcp_is_backend('kea')) {
		$choices['dnsregpolicy'] = restapi_svc_choices(dhcp_server_dnsregpolicy_values());
	} else {
		$choices['prefixrange_length'] = restapi_svc_choices(restapi_dhcp6_prefixrange_lengths());
		$choices['ddnsdomainkeyalgorithm'] = restapi_svc_choices(restapi_dhcp_omapi_algorithms());
		$choices['ddnsclientupdates'] = restapi_svc_choices(restapi_dhcp_ddnsclientupdates_choices());
	}
	$prefix = dhcp6_server_prefix($if);
	return array('interface' => $if, 'backend' => (string)dhcp_get_backend(),
	    'prefix' => is_ipaddrv6($prefix['ip']) ? gen_subnetv6($prefix['ip'], $prefix['sn']) . '/' . $prefix['sn'] : '',
	    'track6' => (config_get_path("interfaces/{$if}/ipaddrv6") == 'track6'),
	    'ramode' => (string)config_get_path("dhcpdv6/{$if}/ramode", 'disabled'),
	    'pending' => restapi_dhcp_pending(true), 'pools' => count(restapi_dhcp6_pools($if))) +
	    restapi_dhcp6_server_mask(restapi_dhcp6_server_values($if, null)) + array('choices' => $choices);
}

function restapi_dhcp6_pool_out($if, $id) {
	return array('id' => (int)$id) + restapi_dhcp6_server_values($if, (int)$id);
}

/* The page's form for $values as the browser posts it. */
function restapi_dhcp6_server_post($if, array $values, $pool) {
	$types = restapi_dhcp6_server_types($pool);
	$post = restapi_svc_post($values, $types);
	unset($post['dnsserver'], $post['ntpserver']);
	$rows = restapi_dhcp_server_rows(array('dnsserver' => $values['dnsserver'] ?? array(), 'ntpserver' => $values['ntpserver'] ?? array()));
	unset($rows['wins1'], $rows['wins2']);
	$post += $rows;
	if (array_key_exists('numberoptions', $types)) {
		$post += restapi_dhcp_option_rows($values['numberoptions'] ?? array(), false);
	}
	$post['if'] = $if;
	$post['save'] = 'Save';
	return $post;
}

/*
 * Save the interface ($id null), pool $id or a new pool ($act "newpool")
 * through the page's save; $shape($if) builds the response of an interface
 * save instead of restapi_dhcp6_server_out() (the WebUI item).
 */
function restapi_dhcp6_server_write($req, $if, array $values, $id, $act = '', $status = 200, ?callable $shape = null) {
	$pool = (($id !== null) || ($act === 'newpool'));
	$post = restapi_dhcp6_server_post($if, $values, $pool);
	if ($act === 'newpool') {
		$post['act'] = 'newpool';
	} elseif ($id !== null) {
		$post['pool'] = (string)$id;
	}
	$rv = dhcp6_server_save($if, $id, $act, $post);
	if ($rv['missing_pool']) {
		throw new RestApiError(404, 'not_found', 'No pool with that id.');
	}
	if (!empty($rv['input_errors'])) {
		throw restapi_validation_error($rv['input_errors']);
	}
	if (!$rv['saved'] || ($pool && !isset($rv['id']))) {
		throw new RestApiError(500, 'internal_error', 'The DHCPv6 server settings were not saved.');
	}
	$extra = restapi_dhcp_maybe_apply($req, true);
	$data = $pool ? restapi_dhcp6_pool_out($if, $rv['id']) : (($shape !== null) ? $shape($if) : restapi_dhcp6_server_out($if));
	return array('status' => $status, 'data' => array_merge($data, $extra));
}

function restapi_h_dhcp6_server_get($req) {
	$if = restapi_dhcp6_server_iface($req);
	return array('data' => restapi_dhcp6_server_out($if));
}

function restapi_h_dhcp6_server_set($req) {
	$if = restapi_dhcp6_server_iface($req);
	$values = restapi_svc_merge(restapi_ddns_secret_body($req['body'], 'ddnsdomainkey'), restapi_dhcp6_server_values($if, null),
	    restapi_dhcp6_server_types(false));
	return restapi_dhcp6_server_write($req, $if, $values, null);
}

function restapi_h_dhcp6_pool_list($req) {
	$if = restapi_dhcp6_server_iface($req, true);
	return array('data' => array_map(function ($id) use ($if) {
		return restapi_dhcp6_pool_out($if, $id);
	}, array_keys(restapi_dhcp6_pools($if))));
}

function restapi_h_dhcp6_pool_get($req) {
	$if = restapi_dhcp6_server_iface($req, true);
	return array('data' => restapi_dhcp6_pool_out($if, restapi_index_param($req['params']['id'], restapi_dhcp6_pools($if), 'pool')));
}

function restapi_h_dhcp6_pool_create($req) {
	$if = restapi_dhcp6_server_iface($req, true);
	$values = restapi_svc_merge($req['body'], restapi_dhcp6_server_values($if, null, 'newpool'), restapi_dhcp6_server_types(true));
	return restapi_dhcp6_server_write($req, $if, $values, null, 'newpool', 201);
}

function restapi_h_dhcp6_pool_update($req) {
	$if = restapi_dhcp6_server_iface($req, true);
	$id = restapi_index_param($req['params']['id'], restapi_dhcp6_pools($if), 'pool');
	$values = restapi_svc_merge($req['body'], restapi_dhcp6_server_values($if, $id), restapi_dhcp6_server_types(true));
	return restapi_dhcp6_server_write($req, $if, $values, $id);
}

function restapi_h_dhcp6_pool_delete($req) {
	$if = restapi_dhcp6_server_iface($req, true);
	$id = restapi_index_param($req['params']['id'], restapi_dhcp6_pools($if), 'pool');
	if (!dhcp6_pool_delete($if, $id)) {
		throw new RestApiError(404, 'not_found', 'No pool with that id.');
	}
	return array('data' => array('deleted' => $id) + restapi_dhcp_maybe_apply($req, true));
}

/* ------------------------------------------------------------------ */
/* Router advertisements                                               */
/* ------------------------------------------------------------------ */

/* The Router Advertisement page's fields on interface $if. */
function restapi_radvd_types($if) {
	$types = array('interface' => 'ro', 'choices' => 'ro', 'fields' => 'ro', 'display' => 'ro', 'ramode' => 'string', 'rapriority' => 'string',
	    'rainterface' => 'string');
	foreach (array('ravalidlifetime', 'rapreferredlifetime', 'raminrtradvinterval', 'ramaxrtradvinterval', 'raadvdefaultlifetime') as $field) {
		$types[$field] = 'string';
	}
	$types += array('pref64_enable' => 'bool', 'pref64_lifetime' => 'string');
	if (config_path_enabled('system', 'allow_nat64_prefix_override')) {
		$types['pref64_prefix'] = 'string';
	}
	$types += array('subnets' => 'list', 'radvd-dns' => 'bool', 'rasamednsasdhcp6' => 'bool', 'radnsserver' => 'list',
	    'radomainsearchlist' => 'string');
	return $types;
}

/* The interface of a router advertisement route: a configured interface (else 404) the page offers (else 409). */
function restapi_radvd_iface($req) {
	restapi_dhcp_available();
	$if = (string)($req['params']['if'] ?? '');
	if (($if === '') || !isset(get_configured_interface_with_descr()[$if])) {
		throw new RestApiError(404, 'not_found', 'No configured interface with that name.');
	}
	if (!radvd_offered($if)) {
		throw new RestApiError(409, 'not_available',
		    'Router advertisements can only be configured on interfaces with a static IPv6 address, Track Interface or no IPv6 configuration.');
	}
	return $if;
}

/* The form values the Router Advertisement page shows (selects as the form preselects them). */
function restapi_radvd_values($if) {
	$p = radvd_form($if);
	$stored = config_get_path("dhcpdv6/{$if}", []);
	$stored = is_array($stored) ? $stored : array();
	$rainterfaces = radvd_rainterface_values($if);
	$out = array();
	foreach (restapi_radvd_types($if) as $key => $type) {
		if ($type === 'string') {
			$out[$key] = (string)($p[$key] ?? '');
		}
	}
	$out['ramode'] = restapi_select_choice($p['ramode'] ?? '', radvd_ramode_values());
	$out['rapriority'] = restapi_select_choice($p['rapriority'] ?? '', radvd_rapriority_values());
	/* the page has no RA Interface field without an IPv6 CARP VIP on the interface (nothing is posted) */
	$out['rainterface'] = $rainterfaces ? restapi_select_choice($p['rainterface'] ?? '', $rainterfaces) : '';
	$out['pref64_enable'] = !empty($p['pref64_enable']);
	$out['subnets'] = array_values(array_map('strval', (array)$p['subnets']));
	$out['radvd-dns'] = !empty($p['radvd-dns']);
	$out['rasamednsasdhcp6'] = restapi_dhcp_flag($stored, 'rasamednsasdhcp6');
	$out['radnsserver'] = restapi_dhcp_addr_list($stored['radnsserver'] ?? array());
	return $out;
}

function restapi_radvd_out($if) {
	$choices = array('ramode' => restapi_svc_choices(radvd_ramode_values()), 'rapriority' => restapi_svc_choices(radvd_rapriority_values()),
	    'rainterface' => restapi_svc_choices(radvd_rainterface_values($if)));
	$values = restapi_radvd_values($if);
	$labels = get_configured_interface_with_descr();
	return array('interface' => $if) + $values + array('choices' => $choices, 'fields' => restapi_radvd_fields($values),
	    'display' => restapi_radvd_display((string)($labels[$if] ?? $if), $values, radvd_ramode_values(), radvd_rapriority_values(),
	    config_path_enabled("dhcpdv6/{$if}")));
}

/* The editor fields: the API values with the RA subnets and DNS servers as entry-grid rows. */
function restapi_radvd_fields(array $values) {
	$fields = $values;
	$fields['subnets'] = array_map(function ($v) { return array('subnet' => (string)$v); }, array_values((array)($values['subnets'] ?? array())));
	$fields['radnsserver'] = array_map(function ($v) { return array('server' => (string)$v); }, array_values((array)($values['radnsserver'] ?? array())));
	return $fields;
}

/*
 * A request body with the entry-grid rows of "fields" ({subnet}, {server})
 * turned into the API's lists of strings; plain lists pass unchanged.
 */
function restapi_radvd_body(array $body) {
	foreach (array('subnets' => 'subnet', 'radnsserver' => 'server') as $key => $cell) {
		if (isset($body[$key]) && is_array($body[$key]) && array_is_list($body[$key])) {
			$body[$key] = array_map(function ($row) use ($cell) {
				return is_array($row) ? (string)($row[$cell] ?? '') : $row;
			}, $body[$key]);
			$body[$key] = array_values(array_filter($body[$key], function ($v) { return !is_string($v) || (trim($v) !== ''); }));
		}
	}
	return $body;
}

/*
 * One interface of the Router Advertisement list as text: the interface,
 * the router mode's short name, the priority, whether advertisements are
 * sent, and the DHCPv6 server ("Enabled, not advertised" when it runs but
 * the mode does not point clients to it, which the page warns about).
 */
function restapi_radvd_display($label, array $values, array $modes, array $priorities, $dhcpv6) {
	$mode = (string)($values['ramode'] ?? 'disabled');
	if (!$dhcpv6) {
		$dhcp = gettext('Disabled');
	} elseif (in_array($mode, array('managed', 'assist', 'stateless_dhcp'), true)) {
		$dhcp = gettext('Enabled');
	} else {
		$dhcp = gettext('Enabled, not advertised');
	}
	return array(
		'interface' => (string)$label,
		'mode' => html_entity_decode(restapi_radvd_mode_name($modes[$mode] ?? $mode), ENT_QUOTES),
		'priority' => html_entity_decode((string)($priorities[$values['rapriority'] ?? ''] ?? ($values['rapriority'] ?? '')), ENT_QUOTES),
		'dhcpv6' => $dhcp,
		'enabled' => ($mode !== 'disabled'),
	);
}

function restapi_h_radvd_list($req) {
	restapi_dhcp_available();
	$out = array();
	foreach (array_keys(get_configured_interface_with_descr()) as $if) {
		if (radvd_offered((string)$if)) {
			$out[] = restapi_radvd_out((string)$if);
		}
	}
	return array('data' => $out);
}

/* The page's form for $values as the browser posts it. */
function restapi_radvd_post($if, array $values) {
	$post = restapi_svc_post($values, restapi_radvd_types($if));
	unset($post['subnets'], $post['radnsserver']);
	$post += restapi_radvd_subnet_rows($values['subnets'] ?? array());
	$post += restapi_radvd_dns_rows($values['radnsserver'] ?? array());
	if (($post['rainterface'] ?? '') === '') {
		unset($post['rainterface']);
	}
	$post['if'] = $if;
	$post['save'] = 'Save';
	return $post;
}

function restapi_h_radvd_get($req) {
	$if = restapi_radvd_iface($req);
	return array('data' => restapi_radvd_out($if));
}

function restapi_h_radvd_set($req) {
	$if = restapi_radvd_iface($req);
	$values = restapi_svc_merge(restapi_radvd_body($req['body']), restapi_radvd_values($if), restapi_radvd_types($if));
	$rv = radvd_save($if, restapi_radvd_post($if, $values));
	if (!empty($rv['input_errors'])) {
		throw restapi_validation_error($rv['input_errors']);
	}
	if (!$rv['saved']) {
		throw new RestApiError(500, 'internal_error', 'The router advertisement settings were not saved.');
	}
	return array('data' => restapi_radvd_out($if) + array('apply' => array('applied' => ($rv['retval'] == 0))));
}

/* ------------------------------------------------------------------ */
/* WebUI 2.0: servers by interface, items with "fields" and "display"  */
/* ------------------------------------------------------------------ */

/* The address lists of the DHCP and DHCPv6 forms (entry grids of {address} rows in "fields"). */
function restapi_dhcp_list_keys($v6) {
	return $v6 ? array('dnsserver', 'ntpserver') : array('winsserver', 'dnsserver', 'ntpserver');
}

/* A request of the /servers/{interface} routes as the {if} handlers read it. */
function restapi_dhcp_req_if(array $req) {
	$req['params']['if'] = (string)($req['params']['interface'] ?? ($req['params']['if'] ?? ''));
	return $req;
}

/*
 * The stored part of an item: the config entry without the lists that are
 * resources of their own ($drop) and with its keys ($secrets) as "(set)".
 */
function restapi_dhcp_stored($conf, array $drop, array $secrets) {
	$out = is_array($conf) ? $conf : array();
	foreach (array_merge($drop, array('interface', 'id', 'fields', 'display')) as $key) {
		unset($out[$key]);
	}
	foreach ($secrets as $key) {
		if (array_key_exists($key, $out)) {
			$out[$key] = restapi_svc_mask(is_scalar($out[$key]) ? $out[$key] : '');
		}
	}
	return $out;
}

/*
 * A row of GET /v1/services/dhcp[v6]/servers from an interface context
 * (dhcp_iface_context() / dhcp6_iface_context()).
 */
function restapi_dhcp_summary(array $ctx, $subnet, $static_count, $available) {
	return array(
		'interface' => (string)$ctx['if'],
		'description' => (string)$ctx['name'],
		'enabled' => (bool)$ctx['enabled'],
		'range' => restapi_dhcp_range_text($ctx['range']['from'] ?? '', $ctx['range']['to'] ?? ''),
		'static_count' => (int)$static_count,
		'subnet' => (string)$subnet,
		'available' => (bool)$available,
	);
}

/* "display" of a DHCP server: the page's texts besides the form and the interface hints (restapi_dhcp_iface_hints()). */
function restapi_dhcp_srv_display(array $ctx, array $hints, $static_count, $relay_enabled) {
	return array(
		'interface' => (string)$ctx['name'],
		'backend' => (string)(restapi_dhcp_backend_labels()[function_exists('dhcp_get_backend') ? dhcp_get_backend() : 'kea'] ?? ''),
		'enabled' => (bool)$ctx['enabled'],
		'subnet' => (string)$hints['subnet'],
		'subnet_range' => (string)$hints['subnet_range'],
		'range' => restapi_dhcp_range_text($ctx['range']['from'] ?? '', $ctx['range']['to'] ?? ''),
		'pools' => count((array)($ctx['pools'] ?? array())),
		'static_count' => (int)$static_count,
		'relay_enabled' => (bool)$relay_enabled,
		'placeholders' => array('range_from' => (string)$hints['range_from'], 'range_to' => (string)$hints['range_to'],
		    'gateway' => (string)$hints['gateway'], 'domain' => (string)$hints['domain'], 'dnsserver' => array_values((array)$hints['dnsserver'])),
	);
}

/* "display" of a DHCPv6 server (hints: restapi_dhcp6_iface_hints()). */
function restapi_dhcp6_srv_display(array $ctx, array $hints, $static_count, $pools, $relay_enabled) {
	return array(
		'interface' => (string)$ctx['name'],
		'backend' => (string)(restapi_dhcp_backend_labels()[function_exists('dhcp_get_backend') ? dhcp_get_backend() : 'kea'] ?? ''),
		'enabled' => (bool)$ctx['enabled'],
		'prefix' => (string)$hints['prefix'],
		'prefix_range' => (string)$hints['prefix_range'],
		'range' => restapi_dhcp_range_text($ctx['range']['from'] ?? '', $ctx['range']['to'] ?? ''),
		'pools' => (int)$pools,
		'static_count' => (int)$static_count,
		'track6' => (bool)$ctx['track6'],
		'ramode' => (string)$ctx['ramode'],
		'relay_enabled' => (bool)$relay_enabled,
		'placeholders' => array('domain' => (string)$hints['domain'], 'dnsserver' => array_values((array)$hints['dnsserver'])),
	);
}

/* "display" of a DHCP static mapping: the list page's columns and flags as text. */
function restapi_dhcp_map_display(array $m, $early_dns = false) {
	$mac = (string)($m['mac'] ?? '');
	$ip = (string)($m['ipaddr'] ?? '');
	$hostname = (string)($m['hostname'] ?? '');
	return array(
		'mac' => $mac,
		'ip' => $ip,
		'hostname' => $hostname,
		'description' => (string)($m['descr'] ?? ''),
		'client_id' => (string)($m['cid'] ?? ''),
		'name' => ($hostname !== '') ? $hostname : (($ip !== '') ? $ip : $mac),
		'static_arp' => restapi_dhcp_flag($m, 'arp_table_static_entry'),
		'early_dns' => (bool)$early_dns,
	);
}

/* "display" of a DHCPv6 static mapping. */
function restapi_dhcp6_map_display(array $m, $early_dns = false) {
	$duid = (string)($m['duid'] ?? '');
	$ip = (string)($m['ipaddrv6'] ?? '');
	$hostname = (string)($m['hostname'] ?? '');
	return array(
		'duid' => $duid,
		'ip' => $ip,
		'prefix' => (string)($m['pdprefix'] ?? ''),
		'hostname' => $hostname,
		'description' => (string)($m['descr'] ?? ''),
		'name' => ($hostname !== '') ? $hostname : (($ip !== '') ? $ip : $duid),
		'early_dns' => (bool)$early_dns,
	);
}

/* Early DNS registration of a mapping as the list page's icon tells it. */
function restapi_dhcp_map_early_dns($if, array $m, $v6) {
	$fn = $v6 ? 'kea6_earlydnsreg_enabled' : 'kea_earlydnsreg_enabled';
	if (!dhcp_is_backend('kea') || !function_exists($fn)) {
		return false;
	}
	$conf = config_get_path(($v6 ? 'dhcpdv6' : 'dhcpd') . "/{$if}", []);
	return (bool)$fn(is_array($conf) ? $conf : array(), $m);
}

function restapi_h_dhcp_pending($req) {
	restapi_dhcp_available();
	return array('data' => array('pending' => restapi_dhcp_pending(false)));
}

function restapi_h_dhcp6_pending($req) {
	restapi_dhcp_available();
	return array('data' => array('pending' => restapi_dhcp_pending(true)));
}

/* --- DHCP --- */

function restapi_h_dhcp_servers($req) {
	restapi_dhcp_available();
	$out = array();
	foreach (array_keys(get_configured_interface_with_descr()) as $if) {
		$ctx = dhcp_iface_context($if);
		if ($ctx === null) {
			continue;
		}
		$cfgip = config_get_path("interfaces/{$if}/ipaddr");
		$cfgsn = config_get_path("interfaces/{$if}/subnet");
		$subnet = (is_ipaddrv4($cfgip) && !empty($cfgsn)) ? gen_subnet($cfgip, $cfgsn) . '/' . $cfgsn : '';
		$out[] = restapi_dhcp_summary($ctx, $subnet, count(restapi_dhcp_maps($if, false)), $ctx['eligible']) +
		    array('pools' => count($ctx['pools']));
	}
	return array('data' => $out);
}

/* The DHCP server of $if as the WebUI item. */
function restapi_dhcp_srv_item($if) {
	$fields = restapi_svc_rows_out(restapi_dhcp_server_mask(restapi_dhcp_server_values($if, null)), restapi_dhcp_list_keys(false));
	$static_count = count(restapi_dhcp_maps($if, false));
	return array('interface' => $if) +
	    restapi_dhcp_stored(config_get_path("dhcpd/{$if}", []), array('staticmap', 'pool'), array('ddnsdomainkey', 'omapi_key')) +
	    array('pending' => restapi_dhcp_pending(false), 'fields' => $fields,
	    'display' => restapi_dhcp_srv_display(dhcp_iface_context($if), restapi_dhcp_iface_hints($if), $static_count,
	    config_path_enabled('dhcrelay')));
}

function restapi_h_dhcp_srv_get($req) {
	$if = restapi_dhcp_server_iface(restapi_dhcp_req_if($req));
	return array('data' => restapi_dhcp_srv_item($if));
}

function restapi_h_dhcp_srv_set($req) {
	$req = restapi_dhcp_req_if($req);
	$if = restapi_dhcp_server_iface($req);
	$body = restapi_svc_rows_in(restapi_dhcp_server_body($req['body']), restapi_dhcp_list_keys(false));
	$values = restapi_svc_merge($body, restapi_dhcp_server_values($if, null), restapi_dhcp_server_types(false));
	return restapi_dhcp_server_write($req, $if, $values, null, '', 200, 'restapi_dhcp_srv_item');
}

/* DHCP static mapping $id of $if as the WebUI item. */
function restapi_dhcp_smap_item($if, $id) {
	$m = config_get_path("dhcpd/{$if}/staticmap/{$id}", []);
	$m = is_array($m) ? $m : array();
	$fields = restapi_dhcp_map_out($if, $id);
	unset($fields['id']);
	return array('id' => (int)$id) + restapi_dhcp_stored($m, array(), array('ddnsdomainkey')) +
	    array('fields' => restapi_svc_rows_out($fields, restapi_dhcp_list_keys(false)),
	    'display' => restapi_dhcp_map_display($m, restapi_dhcp_map_early_dns($if, $m, false)));
}

function restapi_h_dhcp_smap_list($req) {
	$if = restapi_dhcp_iface(restapi_dhcp_req_if($req), false);
	return array('data' => array_map(function ($id) use ($if) {
		return restapi_dhcp_smap_item($if, $id);
	}, array_keys(restapi_dhcp_maps($if, false))));
}

function restapi_h_dhcp_smap_get($req) {
	$if = restapi_dhcp_iface(restapi_dhcp_req_if($req), false);
	return array('data' => restapi_dhcp_smap_item($if, restapi_dhcp_map_id($if, $req['params']['id'], false)));
}

function restapi_h_dhcp_smap_create($req) {
	$req = restapi_dhcp_req_if($req);
	$if = restapi_dhcp_iface($req, false);
	$body = restapi_svc_rows_in($req['body'], restapi_dhcp_list_keys(false));
	$values = restapi_svc_merge($body, restapi_dhcp_map_empty(), restapi_dhcp_map_types());
	return restapi_dhcp_map_save($req, $if, $values, null, 201, 'restapi_dhcp_smap_item');
}

function restapi_h_dhcp_smap_update($req) {
	$req = restapi_dhcp_req_if($req);
	$if = restapi_dhcp_iface($req, false);
	$id = restapi_dhcp_map_id($if, $req['params']['id'], false);
	$body = restapi_svc_rows_in(restapi_ddns_secret_body($req['body'], 'ddnsdomainkey'), restapi_dhcp_list_keys(false));
	$values = restapi_svc_merge($body, restapi_dhcp_map_values($if, $id), restapi_dhcp_map_types());
	return restapi_dhcp_map_save($req, $if, $values, $id, 200, 'restapi_dhcp_smap_item');
}

function restapi_h_dhcp_smap_delete($req) {
	return restapi_h_dhcp_map_delete(restapi_dhcp_req_if($req));
}

/* --- DHCPv6 --- */

function restapi_h_dhcp6_servers($req) {
	restapi_dhcp_available();
	$out = array();
	foreach (array_keys(get_configured_interface_with_descr()) as $if) {
		$ctx = dhcp6_iface_context($if);
		if ($ctx === null) {
			continue;
		}
		$available = dhcp6_server_offered($if);
		$prefix = $available ? restapi_dhcp6_iface_hints($if)['prefix'] : '';
		$out[] = restapi_dhcp_summary($ctx, $prefix, count(restapi_dhcp_maps($if, true)), $available) +
		    array('track6' => (bool)$ctx['track6'], 'ramode' => (string)$ctx['ramode']);
	}
	return array('data' => $out);
}

/* The DHCPv6 server of $if as the WebUI item. */
function restapi_dhcp6_srv_item($if) {
	$fields = restapi_svc_rows_out(restapi_dhcp6_server_mask(restapi_dhcp6_server_values($if, null)), restapi_dhcp_list_keys(true));
	return array('interface' => $if) +
	    restapi_dhcp_stored(config_get_path("dhcpdv6/{$if}", []), array('staticmap', 'pool'), array('ddnsdomainkey')) +
	    array('pending' => restapi_dhcp_pending(true), 'fields' => $fields,
	    'display' => restapi_dhcp6_srv_display(dhcp6_iface_context($if), restapi_dhcp6_iface_hints($if), count(restapi_dhcp_maps($if, true)),
	    count(restapi_dhcp6_pools($if)), dhcp6_server_relay_enabled(dhcp6_server_iflist())));
}

function restapi_h_dhcp6_srv_get($req) {
	$if = restapi_dhcp6_server_iface(restapi_dhcp_req_if($req));
	return array('data' => restapi_dhcp6_srv_item($if));
}

function restapi_h_dhcp6_srv_set($req) {
	$req = restapi_dhcp_req_if($req);
	$if = restapi_dhcp6_server_iface($req);
	$body = restapi_svc_rows_in(restapi_ddns_secret_body($req['body'], 'ddnsdomainkey'), restapi_dhcp_list_keys(true));
	$values = restapi_svc_merge($body, restapi_dhcp6_server_values($if, null), restapi_dhcp6_server_types(false));
	return restapi_dhcp6_server_write($req, $if, $values, null, '', 200, 'restapi_dhcp6_srv_item');
}

/* DHCPv6 static mapping $id of $if as the WebUI item. */
function restapi_dhcp6_smap_item($if, $id) {
	$m = config_get_path("dhcpdv6/{$if}/staticmap/{$id}", []);
	$m = is_array($m) ? $m : array();
	$fields = restapi_dhcp6_map_out($if, $id);
	unset($fields['id']);
	return array('id' => (int)$id) + restapi_dhcp_stored($m, array(), array()) +
	    array('fields' => $fields, 'display' => restapi_dhcp6_map_display($m, restapi_dhcp_map_early_dns($if, $m, true)));
}

function restapi_h_dhcp6_smap_list($req) {
	$if = restapi_dhcp_iface(restapi_dhcp_req_if($req), true);
	return array('data' => array_map(function ($id) use ($if) {
		return restapi_dhcp6_smap_item($if, $id);
	}, array_keys(restapi_dhcp_maps($if, true))));
}

function restapi_h_dhcp6_smap_get($req) {
	$if = restapi_dhcp_iface(restapi_dhcp_req_if($req), true);
	return array('data' => restapi_dhcp6_smap_item($if, restapi_dhcp_map_id($if, $req['params']['id'], true)));
}

function restapi_h_dhcp6_smap_create($req) {
	$req = restapi_dhcp_req_if($req);
	$if = restapi_dhcp_iface($req, true);
	$values = restapi_svc_merge($req['body'], restapi_dhcp6_map_empty($if), restapi_dhcp6_map_types($if));
	return restapi_dhcp6_map_save($req, $if, $values, null, 201, 'restapi_dhcp6_smap_item');
}

function restapi_h_dhcp6_smap_update($req) {
	$req = restapi_dhcp_req_if($req);
	$if = restapi_dhcp_iface($req, true);
	$id = restapi_dhcp_map_id($if, $req['params']['id'], true);
	$values = restapi_svc_merge($req['body'], restapi_dhcp6_map_out($if, $id), restapi_dhcp6_map_types($if));
	return restapi_dhcp6_map_save($req, $if, $values, $id, 200, 'restapi_dhcp6_smap_item');
}

function restapi_h_dhcp6_smap_delete($req) {
	return restapi_h_dhcp6_map_delete(restapi_dhcp_req_if($req));
}
