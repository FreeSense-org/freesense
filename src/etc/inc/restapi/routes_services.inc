<?php
/*
 * restapi/routes_services.inc
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
 * REST API v1 routes for the small services: DNS Forwarder (area
 * services.dns); UPnP IGD & PCP, Wake-on-LAN, IGMP Proxy, DHCP and DHCPv6
 * Relay and SNMP (area services.misc).
 *
 * Writes go through the functions the Services pages call
 * (services_dnsmasq.inc, services_upnp.inc with miniupnpd.inc,
 * services_wol.inc, services_igmpproxy.inc, services_dhcp_relay.inc,
 * services_snmp.inc), so validation is the GUI's. Like the GUI:
 *   - DNS Forwarder and IGMP Proxy changes are staged until
 *     POST .../apply (add ?apply=true to a write to apply at once);
 *   - UPnP, DHCP/DHCPv6 Relay and SNMP settings apply at once;
 *   - Wake-on-LAN devices have nothing to apply.
 * Settings are flat objects of the edit forms' fields (checkboxes as
 * booleans, multi-selects as lists); a PUT changes only the fields it
 * sends. List entries are addressed by their position in config.xml.
 * Shared secrets (the SNMP community and trap strings) read as "(set)".
 *
 * The front controller loads the shared page includes at file scope.
 */

require_once('restapi/framework.inc');

function restapi_routes_services() {
	$apply_query = array('apply' => 'true to apply the change at once (default: stage it like the GUI)');
	$dns = 'services.dns';
	$misc = 'services.misc';

	$dnsmasq_body = array('type' => 'object', 'description' => 'The DNS Forwarder settings form: enable, regdhcp, ' .
	    'regdhcpstatic, dhcpfirst, no_system_dns, strict_order, domain_needed, no_private_reverse, strictbind ' .
	    '(booleans), port (empty: 53), interface (list of listen addresses from "interface_choices"; empty: all), ' .
	    'custom_options (text). Omitted fields keep their value.');
	$host_body = array('type' => 'object', 'properties' => array(
		'host' => array('type' => 'string', 'description' => 'Host name without the domain; empty for the domain itself'),
		'domain' => array('type' => 'string'),
		'ip' => array('type' => 'string', 'description' => 'IPv4 or IPv6 address'),
		'descr' => array('type' => 'string'),
		'aliases' => array('type' => 'array', 'description' => 'Additional names; like the GUI, aliases are only kept when the first one has a host',
		    'items' => array('type' => 'object', 'properties' => array(
		    'host' => array('type' => 'string'), 'domain' => array('type' => 'string'), 'description' => array('type' => 'string')))),
	));
	$domain_body = array('type' => 'object', 'properties' => array(
		'domain' => array('type' => 'string'),
		'ip' => array('type' => 'string', 'description' => 'Address of the authoritative server, "#" (exclusion) or "!" (do not forward)'),
		'dnssrcip' => array('type' => 'string', 'description' => 'Interface address to send the queries from (optional)'),
		'descr' => array('type' => 'string'),
	));
	$upnp_body = array('type' => 'object', 'description' => 'The UPnP IGD & PCP settings form (pkg_edit.php?xml=miniupnpd.xml): ' .
	    'enable, enable_upnp, enable_natpmp, enable_stun, logpackets, sysuptime, permdefault (booleans), ext_iface, ' .
	    'iface_array (list), stun_host, stun_port, overridewanip, download, upload, upnpqueue, presentationurl, ' .
	    'modelnumber, acl (list of "allow|deny ext-ports address/bits int-ports" entries). Omitted fields keep their value.');
	$wol_body = array('type' => 'object', 'properties' => array(
		'interface' => array('type' => 'string', 'description' => 'Interface the device is connected to (lan, opt1, ...)'),
		'mac' => array('type' => 'string', 'description' => 'xx:xx:xx:xx:xx:xx (hyphens are accepted)'),
		'descr' => array('type' => 'string'),
	));
	$igmp_body = array('type' => 'object', 'properties' => array(
		'ifname' => array('type' => 'string', 'description' => 'An interface with an address'),
		'type' => array('type' => 'string', 'enum' => array('upstream', 'downstream')),
		'threshold' => array('type' => 'string', 'description' => 'TTL threshold, -1 to 256 (empty: 1)'),
		'networks' => array('type' => 'array', 'items' => array('type' => 'string'), 'description' => 'IPv4 networks as address/bits'),
		'descr' => array('type' => 'string'),
	));
	$relay_body = array('type' => 'object', 'description' => 'The relay settings form: enable, agentoption (booleans), ' .
	    'interface (list from "interface_choices"), server (list of upstream server addresses), carpstatusvip ' .
	    '("none" or a VIP from "carp_choices"). Omitted fields keep their value. Saving reconfigures the relay at once.');
	$snmp_body = array('type' => 'object', 'description' => 'The SNMP settings form: enable, trapenable, mibii, netgraph, ' .
	    'pf, hostres, bridge, ucd, regex (booleans), pollport, syslocation, syscontact, rocommunity, trapserver, ' .
	    'trapserverport, trapstring, ipprotocol (inet4|inet6|inet46), bindip (list from "bindip_choices"). Omitted fields ' .
	    'keep their value; rocommunity and trapstring read as "(set)" and sending "(set)" keeps them. Saving restarts ' .
	    'the SNMP daemon at once.');
	$id_write = function ($page, $summary, $body = null, $query = array()) use ($misc) {
		return array('page' => $page, 'area' => $misc, 'write' => true, 'summary' => $summary, 'body' => $body, 'query' => $query);
	};

	return array(
		/* DNS Forwarder */
		restapi_route('GET', '/v1/services/dns-forwarder', 'restapi_h_dnsmasq_get', array(
			'page' => 'services_dnsmasq.php', 'area' => $dns,
			'summary' => 'DNS Forwarder settings, the listen address choices and whether changes are pending')),
		restapi_route('PUT', '/v1/services/dns-forwarder', 'restapi_h_dnsmasq_set', array(
			'page' => 'services_dnsmasq.php', 'area' => $dns, 'write' => true, 'body' => $dnsmasq_body,
			'query' => $apply_query, 'summary' => 'Change DNS Forwarder settings (omitted fields keep their value)')),
		restapi_route('POST', '/v1/services/dns-forwarder/apply', 'restapi_h_dnsmasq_apply', array(
			'page' => 'services_dnsmasq.php', 'area' => $dns, 'write' => true,
			'summary' => 'Apply pending DNS Forwarder changes (settings, host and domain overrides)')),
		restapi_route('GET', '/v1/services/dns-forwarder/host-overrides', 'restapi_h_dnsmasq_host_list', array(
			'page' => 'services_dnsmasq.php', 'area' => $dns, 'summary' => 'List host overrides')),
		restapi_route('GET', '/v1/services/dns-forwarder/host-overrides/{id}', 'restapi_h_dnsmasq_host_get', array(
			'page' => 'services_dnsmasq.php', 'area' => $dns, 'summary' => 'Get a host override')),
		restapi_route('POST', '/v1/services/dns-forwarder/host-overrides', 'restapi_h_dnsmasq_host_create', array(
			'page' => 'services_dnsmasq_edit.php', 'area' => $dns, 'write' => true, 'body' => $host_body,
			'query' => $apply_query, 'summary' => 'Create a host override')),
		restapi_route('PUT', '/v1/services/dns-forwarder/host-overrides/{id}', 'restapi_h_dnsmasq_host_update', array(
			'page' => 'services_dnsmasq_edit.php', 'area' => $dns, 'write' => true, 'body' => $host_body,
			'query' => $apply_query, 'summary' => 'Update a host override (omitted fields keep their value; "aliases" replaces the list)')),
		restapi_route('DELETE', '/v1/services/dns-forwarder/host-overrides/{id}', 'restapi_h_dnsmasq_host_delete', array(
			'page' => 'services_dnsmasq.php', 'area' => $dns, 'write' => true, 'query' => $apply_query,
			'summary' => 'Delete a host override')),
		restapi_route('GET', '/v1/services/dns-forwarder/domain-overrides', 'restapi_h_dnsmasq_domain_list', array(
			'page' => 'services_dnsmasq.php', 'area' => $dns, 'summary' => 'List domain overrides')),
		restapi_route('GET', '/v1/services/dns-forwarder/domain-overrides/{id}', 'restapi_h_dnsmasq_domain_get', array(
			'page' => 'services_dnsmasq.php', 'area' => $dns, 'summary' => 'Get a domain override')),
		restapi_route('POST', '/v1/services/dns-forwarder/domain-overrides', 'restapi_h_dnsmasq_domain_create', array(
			'page' => 'services_dnsmasq_domainoverride_edit.php', 'area' => $dns, 'write' => true, 'body' => $domain_body,
			'query' => $apply_query, 'summary' => 'Create a domain override')),
		restapi_route('PUT', '/v1/services/dns-forwarder/domain-overrides/{id}', 'restapi_h_dnsmasq_domain_update', array(
			'page' => 'services_dnsmasq_domainoverride_edit.php', 'area' => $dns, 'write' => true, 'body' => $domain_body,
			'query' => $apply_query, 'summary' => 'Update a domain override (omitted fields keep their value)')),
		restapi_route('DELETE', '/v1/services/dns-forwarder/domain-overrides/{id}', 'restapi_h_dnsmasq_domain_delete', array(
			'page' => 'services_dnsmasq.php', 'area' => $dns, 'write' => true, 'query' => $apply_query,
			'summary' => 'Delete a domain override')),

		/* UPnP IGD & PCP */
		restapi_route('GET', '/v1/services/upnp', 'restapi_h_upnp_get', array(
			'page' => 'pkg_edit.php?xml=miniupnpd.xml', 'area' => $misc,
			'summary' => 'UPnP IGD & PCP settings and whether miniupnpd runs')),
		restapi_route('PUT', '/v1/services/upnp', 'restapi_h_upnp_set',
			$id_write('pkg_edit.php?xml=miniupnpd.xml', 'Change UPnP IGD & PCP settings and restart miniupnpd at once (omitted fields keep their value)', $upnp_body)),
		restapi_route('GET', '/v1/services/upnp/status', 'restapi_h_upnp_status', array(
			'page' => 'status_upnp.php', 'area' => $misc, 'summary' => 'Whether UPnP runs and its active port maps')),
		restapi_route('DELETE', '/v1/services/upnp/port-maps', 'restapi_h_upnp_flush',
			$id_write('status_upnp.php', 'Delete all port maps (restarts miniupnpd; only while UPnP is enabled)')),

		/* Wake-on-LAN */
		restapi_route('GET', '/v1/services/wol/entries', 'restapi_h_wol_list', array(
			'page' => 'services_wol.php', 'area' => $misc, 'summary' => 'List Wake-on-LAN devices')),
		restapi_route('GET', '/v1/services/wol/entries/{id}', 'restapi_h_wol_get', array(
			'page' => 'services_wol.php', 'area' => $misc, 'summary' => 'Get a Wake-on-LAN device')),
		restapi_route('POST', '/v1/services/wol/entries', 'restapi_h_wol_create',
			$id_write('services_wol_edit.php', 'Add a Wake-on-LAN device (the list is sorted by description, so ids can change)', $wol_body)),
		restapi_route('PUT', '/v1/services/wol/entries/{id}', 'restapi_h_wol_update',
			$id_write('services_wol_edit.php', 'Update a Wake-on-LAN device (omitted fields keep their value; the response has its new id)', $wol_body)),
		restapi_route('DELETE', '/v1/services/wol/entries/{id}', 'restapi_h_wol_delete',
			$id_write('services_wol.php', 'Delete a Wake-on-LAN device')),
		restapi_route('POST', '/v1/services/wol/entries/{id}/wake', 'restapi_h_wol_wake_entry',
			$id_write('services_wol.php', 'Send a magic packet to a stored device')),
		restapi_route('POST', '/v1/services/wol/wake', 'restapi_h_wol_wake',
			$id_write('services_wol.php', 'Send a magic packet to a MAC address on an interface', array('type' => 'object',
			    'required' => array('interface', 'mac'), 'properties' => array('interface' => $wol_body['properties']['interface'],
			    'mac' => $wol_body['properties']['mac'])))),
		restapi_route('POST', '/v1/services/wol/wake-all', 'restapi_h_wol_wake_all',
			$id_write('services_wol.php', 'Send a magic packet to every stored device')),

		/* IGMP Proxy */
		restapi_route('GET', '/v1/services/igmp-proxy', 'restapi_h_igmp_get', array(
			'page' => 'services_igmpproxy.php', 'area' => $misc,
			'summary' => 'IGMP Proxy settings and whether changes are pending')),
		restapi_route('PUT', '/v1/services/igmp-proxy', 'restapi_h_igmp_set',
			$id_write('services_igmpproxy.php', 'Change IGMP Proxy settings (enabling needs an upstream and a downstream entry)',
			    array('type' => 'object', 'properties' => array('enable' => array('type' => 'boolean'),
			    'verbose' => array('type' => 'boolean', 'description' => 'Verbose logging'))), $apply_query)),
		restapi_route('POST', '/v1/services/igmp-proxy/apply', 'restapi_h_igmp_apply',
			$id_write('services_igmpproxy.php', 'Apply pending IGMP Proxy changes')),
		restapi_route('GET', '/v1/services/igmp-proxy/entries', 'restapi_h_igmp_list', array(
			'page' => 'services_igmpproxy.php', 'area' => $misc, 'summary' => 'List IGMP Proxy interfaces')),
		restapi_route('GET', '/v1/services/igmp-proxy/entries/{id}', 'restapi_h_igmp_entry_get', array(
			'page' => 'services_igmpproxy.php', 'area' => $misc, 'summary' => 'Get an IGMP Proxy interface')),
		restapi_route('POST', '/v1/services/igmp-proxy/entries', 'restapi_h_igmp_create',
			$id_write('services_igmpproxy_edit.php', 'Add an IGMP Proxy interface (only one upstream)', $igmp_body, $apply_query)),
		restapi_route('PUT', '/v1/services/igmp-proxy/entries/{id}', 'restapi_h_igmp_update',
			$id_write('services_igmpproxy_edit.php', 'Update an IGMP Proxy interface (omitted fields keep their value)', $igmp_body, $apply_query)),
		restapi_route('DELETE', '/v1/services/igmp-proxy/entries/{id}', 'restapi_h_igmp_delete',
			$id_write('services_igmpproxy.php', 'Delete an IGMP Proxy interface', null, $apply_query)),

		/* DHCP and DHCPv6 Relay */
		restapi_route('GET', '/v1/services/dhcp-relay', 'restapi_h_relay_get', array(
			'page' => 'services_dhcp_relay.php', 'area' => $misc,
			'summary' => 'DHCP Relay settings and choices (the relay cannot run while a DHCP server is enabled)')),
		restapi_route('PUT', '/v1/services/dhcp-relay', 'restapi_h_relay_set',
			$id_write('services_dhcp_relay.php', 'Change DHCP Relay settings (applied at once)', $relay_body)),
		restapi_route('GET', '/v1/services/dhcpv6-relay', 'restapi_h_relay_get', array(
			'page' => 'services_dhcpv6_relay.php', 'area' => $misc,
			'summary' => 'DHCPv6 Relay settings and choices (the relay cannot run while a DHCPv6 server is enabled)')),
		restapi_route('PUT', '/v1/services/dhcpv6-relay', 'restapi_h_relay_set',
			$id_write('services_dhcpv6_relay.php', 'Change DHCPv6 Relay settings (applied at once)', $relay_body)),

		/* SNMP */
		restapi_route('GET', '/v1/services/snmp', 'restapi_h_snmp_get', array(
			'page' => 'services_snmp.php', 'area' => $misc,
			'summary' => 'SNMP settings (community and trap strings read as "(set)")')),
		restapi_route('PUT', '/v1/services/snmp', 'restapi_h_snmp_set',
			$id_write('services_snmp.php', 'Change SNMP settings (applied at once)', $snmp_body)),
	);
}

/* ------------------------------------------------------------------ */
/* Pure helpers (no config access)                                     */
/* ------------------------------------------------------------------ */

/*
 * Merge an API body over the current values (both in API shape).
 * $types: field => bool | string | list | ro. Read-only fields (as returned
 * by GET) are ignored; any other unknown field is a 400.
 */
function restapi_svc_merge(array $body, array $current, array $types) {
	$out = $current;
	foreach ($body as $key => $value) {
		$type = $types[$key] ?? null;
		if ($type === null) {
			throw new RestApiError(400, 'unknown_field', "Unknown field \"{$key}\".");
		}
		if ($type === 'ro') {
			continue;
		}
		if ($type === 'bool') {
			if (!is_bool($value)) {
				throw new RestApiError(400, 'invalid_field', "Field \"{$key}\" must be a boolean.");
			}
		} elseif ($type === 'string') {
			if (!is_string($value) && !is_int($value) && !is_float($value) && !is_null($value)) {
				throw new RestApiError(400, 'invalid_field', "Field \"{$key}\" must be a string.");
			}
			$value = (string)$value;
		} elseif ($type === 'list') {
			if (!is_array($value) || !array_is_list($value)) {
				throw new RestApiError(400, 'invalid_field', "Field \"{$key}\" must be a list.");
			}
			foreach ($value as $item) {
				if (!is_string($item) && !is_int($item)) {
					throw new RestApiError(400, 'invalid_field', "Field \"{$key}\" must be a list of strings.");
				}
			}
			$value = array_map('strval', $value);
		} else {
			/* objects and lists of objects are converted by the caller */
			if (!is_array($value)) {
				throw new RestApiError(400, 'invalid_field', "Field \"{$key}\" must be a list.");
			}
		}
		$out[$key] = $value;
	}
	return $out;
}

/*
 * API values to a form post: booleans become ticked checkboxes ($on, the
 * value the form posts) or are left out, lists stay lists (left out when
 * empty, like an empty multi-select) and everything else is a string.
 */
function restapi_svc_post(array $values, array $types, $on = 'yes') {
	$post = array();
	foreach ($values as $key => $value) {
		$type = $types[$key] ?? 'ro';
		if ($type === 'bool') {
			if ($value) {
				$post[$key] = $on;
			}
		} elseif ($type === 'list') {
			if (!empty($value)) {
				$post[$key] = array_values(array_map('strval', (array)$value));
			}
		} elseif ($type === 'string') {
			$post[$key] = (string)$value;
		}
	}
	return $post;
}

/* A comma separated config list as an API list (empty entries dropped). */
function restapi_svc_split($text) {
	return array_values(array_filter(explode(',', (string)$text), function ($v) {
		return $v !== '';
	}));
}

/* An "(set)" mask for a shared secret. */
function restapi_svc_mask($secret) {
	return ((string)$secret !== '') ? '(set)' : '';
}

/* Select options as an API map (labels without HTML escaping). */
function restapi_svc_choices(array $options) {
	$out = array();
	foreach ($options as $value => $label) {
		$out[(string)$value] = html_entity_decode((string)$label, ENT_QUOTES);
	}
	return $out;
}

/* Host override aliases (API list of {host, domain, description}) as the edit form's rows. */
function restapi_dnsmasq_alias_rows($aliases) {
	if (!is_array($aliases) || !array_is_list($aliases)) {
		throw new RestApiError(400, 'invalid_field', '"aliases" must be a list of {host, domain, description}.');
	}
	$post = array();
	foreach ($aliases as $i => $alias) {
		if (!is_array($alias)) {
			throw new RestApiError(400, 'invalid_field', '"aliases" must be a list of {host, domain, description}.');
		}
		foreach (array('host' => 'aliashost', 'domain' => 'aliasdomain', 'description' => 'aliasdescription') as $field => $prefix) {
			$value = $alias[$field] ?? '';
			if (!is_scalar($value)) {
				throw new RestApiError(400, 'invalid_field', "Alias field \"{$field}\" must be a string.");
			}
			$post["{$prefix}{$i}"] = (string)$value;
		}
	}
	return $post;
}

/* IGMP networks ("address/bits") as the edit form's address/address_subnet rows. */
function restapi_igmp_network_rows($networks) {
	$post = array();
	foreach (array_values($networks) as $i => $net) {
		$parts = explode('/', (string)$net, 2);
		$post["address{$i}"] = $parts[0];
		$post["address_subnet{$i}"] = $parts[1] ?? '';
	}
	return $post;
}

/* A list of server addresses as the relay form's server0, server1, ... rows. */
function restapi_relay_server_rows(array $servers) {
	$post = array();
	foreach (array_values($servers) as $i => $server) {
		$post["server{$i}"] = (string)$server;
	}
	return $post;
}

/* UPnP ACL entries as the package form's permuser0, permuser1, ... rows. */
function restapi_upnp_acl_rows(array $acl) {
	$post = array();
	foreach (array_values($acl) as $i => $entry) {
		$post["permuser{$i}"] = (string)$entry;
	}
	return $post;
}

/* ------------------------------------------------------------------ */
/* Shared                                                              */
/* ------------------------------------------------------------------ */

/* The staged/applied part of a write response for a subsystem with an apply function. */
function restapi_svc_maybe_apply($req, $flag, callable $apply) {
	if (restapi_want_apply($req)) {
		$retval = $apply();
		return array('pending' => is_subsystem_dirty($flag), 'apply' => array('applied' => ($retval == 0)));
	}
	return array('pending' => is_subsystem_dirty($flag));
}

/* ------------------------------------------------------------------ */
/* DNS Forwarder                                                       */
/* ------------------------------------------------------------------ */

function restapi_dnsmasq_types() {
	$types = array('port' => 'string', 'custom_options' => 'string', 'interface' => 'list',
	    'interface_choices' => 'ro', 'pending' => 'ro');
	foreach (array('enable', 'regdhcp', 'regdhcpstatic', 'dhcpfirst', 'no_system_dns', 'strict_order',
	    'domain_needed', 'no_private_reverse', 'strictbind') as $flag) {
		$types[$flag] = 'bool';
	}
	return $types;
}

function restapi_dnsmasq_settings() {
	$rv = getDNSMasqConfig();
	$out = array();
	foreach (restapi_dnsmasq_types() as $key => $type) {
		if ($type === 'bool') {
			$out[$key] = !empty($rv['config'][$key]);
		} elseif ($type === 'list') {
			$out[$key] = array_values(array_filter((array)$rv['config'][$key], function ($v) {
				return $v !== '';
			}));
		} elseif ($type === 'string') {
			$out[$key] = (string)$rv['config'][$key];
		}
	}
	$out['interface_choices'] = restapi_svc_choices($rv['iflist']['options']);
	$out['pending'] = is_subsystem_dirty('hosts');
	return $out;
}

function restapi_dnsmasq_maybe_apply($req) {
	return restapi_svc_maybe_apply($req, 'hosts', 'applyDNSMasqConfig');
}

function restapi_h_dnsmasq_get($req) {
	return array('data' => restapi_dnsmasq_settings());
}

function restapi_h_dnsmasq_set($req) {
	$types = restapi_dnsmasq_types();
	$values = restapi_svc_merge($req['body'], restapi_dnsmasq_settings(), $types);
	$rv = saveDNSMasqConfig(restapi_svc_post($values, $types));
	if (!empty($rv['input_errors'])) {
		throw restapi_validation_error($rv['input_errors']);
	}
	$extra = restapi_dnsmasq_maybe_apply($req);
	return array('data' => array_merge(restapi_dnsmasq_settings(), $extra));
}

function restapi_h_dnsmasq_apply($req) {
	$retval = applyDNSMasqConfig();
	return array('data' => array('applied' => ($retval == 0), 'pending' => is_subsystem_dirty('hosts')));
}

/* Host overrides */

function restapi_dnsmasq_hosts() {
	$hosts = config_get_path('dnsmasq/hosts', []);
	return is_array($hosts) ? $hosts : array();
}

function restapi_dnsmasq_host_out($id) {
	$h = (array)config_get_path("dnsmasq/hosts/{$id}", []);
	$aliases = array();
	foreach ((array)array_get_path($h, 'aliases/item', []) as $alias) {
		if (is_array($alias)) {
			$aliases[] = array('host' => (string)($alias['host'] ?? ''), 'domain' => (string)($alias['domain'] ?? ''),
			    'description' => (string)($alias['description'] ?? ''));
		}
	}
	return array('id' => (int)$id, 'host' => (string)($h['host'] ?? ''), 'domain' => (string)($h['domain'] ?? ''),
	    'ip' => (string)($h['ip'] ?? ''), 'descr' => (string)($h['descr'] ?? ''), 'aliases' => $aliases);
}

/* A host override (API shape) as the edit form's post. */
function restapi_dnsmasq_host_post(array $values) {
	$post = restapi_svc_post($values, array('host' => 'string', 'domain' => 'string', 'ip' => 'string', 'descr' => 'string'));
	return $post + restapi_dnsmasq_alias_rows($values['aliases'] ?? array());
}

function restapi_dnsmasq_host_types() {
	return array('id' => 'ro', 'host' => 'string', 'domain' => 'string', 'ip' => 'string', 'descr' => 'string', 'aliases' => 'objects');
}

function restapi_dnsmasq_host_save(array $values, $id, $req) {
	$rv = saveDNSMasqHost(restapi_dnsmasq_host_post($values), $id);
	if (!empty($rv['input_errors'])) {
		throw restapi_validation_error($rv['input_errors']);
	}
	return restapi_dnsmasq_maybe_apply($req);
}

function restapi_h_dnsmasq_host_list($req) {
	return array('data' => array_map('restapi_dnsmasq_host_out', array_keys(restapi_dnsmasq_hosts())));
}

function restapi_h_dnsmasq_host_get($req) {
	return array('data' => restapi_dnsmasq_host_out(restapi_index_param($req['params']['id'], restapi_dnsmasq_hosts(), 'host override')));
}

function restapi_h_dnsmasq_host_create($req) {
	$empty = array('host' => '', 'domain' => '', 'ip' => '', 'descr' => '', 'aliases' => array());
	$values = restapi_svc_merge($req['body'], $empty, restapi_dnsmasq_host_types());
	$extra = restapi_dnsmasq_host_save($values, null, $req);
	return array('status' => 201, 'data' => restapi_dnsmasq_host_out(count(restapi_dnsmasq_hosts()) - 1) + $extra);
}

function restapi_h_dnsmasq_host_update($req) {
	$id = restapi_index_param($req['params']['id'], restapi_dnsmasq_hosts(), 'host override');
	$values = restapi_svc_merge($req['body'], restapi_dnsmasq_host_out($id), restapi_dnsmasq_host_types());
	$extra = restapi_dnsmasq_host_save($values, $id, $req);
	return array('data' => restapi_dnsmasq_host_out($id) + $extra);
}

function restapi_h_dnsmasq_host_delete($req) {
	$id = restapi_index_param($req['params']['id'], restapi_dnsmasq_hosts(), 'host override');
	deleteDNSMasqEntry(array('type' => 'host', 'id' => (string)$id));
	return array('data' => array('deleted' => $id) + restapi_dnsmasq_maybe_apply($req));
}

/* Domain overrides */

function restapi_dnsmasq_domains() {
	$domains = config_get_path('dnsmasq/domainoverrides', []);
	return is_array($domains) ? $domains : array();
}

function restapi_dnsmasq_domain_out($id) {
	$fields = getDomainOverride($id);
	return array('id' => (int)$id, 'domain' => (string)($fields['domain'] ?? ''), 'ip' => (string)($fields['ip'] ?? ''),
	    'dnssrcip' => (string)($fields['dnssrcip'] ?? ''), 'descr' => (string)($fields['descr'] ?? ''));
}

function restapi_dnsmasq_domain_types() {
	return array('id' => 'ro', 'domain' => 'string', 'ip' => 'string', 'dnssrcip' => 'string', 'descr' => 'string');
}

function restapi_dnsmasq_domain_save(array $values, $id, $req) {
	$rv = saveDomainOverride(restapi_svc_post($values, restapi_dnsmasq_domain_types()), $id);
	if (!empty($rv['input_errors'])) {
		throw restapi_validation_error($rv['input_errors']);
	}
	return restapi_dnsmasq_maybe_apply($req);
}

function restapi_h_dnsmasq_domain_list($req) {
	return array('data' => array_map('restapi_dnsmasq_domain_out', array_keys(restapi_dnsmasq_domains())));
}

function restapi_h_dnsmasq_domain_get($req) {
	return array('data' => restapi_dnsmasq_domain_out(restapi_index_param($req['params']['id'], restapi_dnsmasq_domains(), 'domain override')));
}

function restapi_h_dnsmasq_domain_create($req) {
	$empty = array('domain' => '', 'ip' => '', 'dnssrcip' => '', 'descr' => '');
	$values = restapi_svc_merge($req['body'], $empty, restapi_dnsmasq_domain_types());
	$extra = restapi_dnsmasq_domain_save($values, null, $req);
	return array('status' => 201, 'data' => restapi_dnsmasq_domain_out(count(restapi_dnsmasq_domains()) - 1) + $extra);
}

function restapi_h_dnsmasq_domain_update($req) {
	$id = restapi_index_param($req['params']['id'], restapi_dnsmasq_domains(), 'domain override');
	$values = restapi_svc_merge($req['body'], restapi_dnsmasq_domain_out($id), restapi_dnsmasq_domain_types());
	$extra = restapi_dnsmasq_domain_save($values, $id, $req);
	return array('data' => restapi_dnsmasq_domain_out($id) + $extra);
}

function restapi_h_dnsmasq_domain_delete($req) {
	$id = restapi_index_param($req['params']['id'], restapi_dnsmasq_domains(), 'domain override');
	deleteDNSMasqEntry(array('type' => 'doverride', 'id' => (string)$id));
	return array('data' => array('deleted' => $id) + restapi_dnsmasq_maybe_apply($req));
}

/* ------------------------------------------------------------------ */
/* UPnP IGD & PCP                                                      */
/* ------------------------------------------------------------------ */

/* Field types from the package form: checkbox => bool, multiple interface select => list, else string; plus acl. */
function restapi_upnp_types(array $pkg) {
	$types = array();
	foreach ($pkg['fields']['field'] as $field) {
		$name = $field['fieldname'] ?? '';
		if (($name === '') || ($field['type'] === 'rowhelper')) {
			continue;
		}
		if ($field['type'] === 'checkbox') {
			$types[$name] = 'bool';
		} elseif (($field['type'] === 'interfaces_selection') && isset($field['multiple'])) {
			$types[$name] = 'list';
		} else {
			$types[$name] = 'string';
		}
	}
	$types['acl'] = 'list';
	$types['running'] = 'ro';
	return $types;
}

/* The stored settings in API shape; the form's default values when never saved. */
function restapi_upnp_settings(array $pkg) {
	$cfg = upnp_config();
	$out = array();
	foreach ($pkg['fields']['field'] as $field) {
		$name = $field['fieldname'] ?? '';
		if (($name === '') || ($field['type'] === 'rowhelper')) {
			continue;
		}
		$value = ($cfg === null) ? (string)($field['default_value'] ?? '') : (string)($cfg[$name] ?? '');
		if ($field['type'] === 'checkbox') {
			$out[$name] = ($value === 'on');
		} elseif (($field['type'] === 'interfaces_selection') && isset($field['multiple'])) {
			$out[$name] = restapi_svc_split($value);
		} else {
			$out[$name] = $value;
		}
	}
	$acl = array();
	foreach ((array)($cfg['row'] ?? array()) as $row) {
		if (is_array($row) && (($row['permuser'] ?? '') !== '')) {
			$acl[] = (string)$row['permuser'];
		}
	}
	$out['acl'] = $acl;
	return $out;
}

function restapi_h_upnp_get($req) {
	return array('data' => restapi_upnp_settings(upnp_pkg()) + array('running' => upnp_running()));
}

function restapi_h_upnp_set($req) {
	$pkg = upnp_pkg();
	$types = restapi_upnp_types($pkg);
	$values = restapi_svc_merge($req['body'], restapi_upnp_settings($pkg), $types);
	$acl = $values['acl'];
	unset($values['acl']);
	$errors = upnp_save_settings(restapi_svc_post($values, $types, 'on') + restapi_upnp_acl_rows($acl));
	if (!empty($errors)) {
		throw restapi_validation_error($errors);
	}
	return array('data' => restapi_upnp_settings($pkg) + array('running' => upnp_running()));
}

function restapi_upnp_enabled() {
	return config_get_path('installedpackages/miniupnpd/config/0/enable') == 'on';
}

function restapi_h_upnp_status($req) {
	$maps = array();
	if (restapi_upnp_enabled()) {
		foreach (upnp_port_maps() as $map) {
			$maps[] = array(
				'interface' => $map['iface'],
				'interface_descr' => convert_real_interface_to_friendly_descr($map['iface']),
				'ext_port' => $map['extport'],
				'int_addr' => $map['intaddr'],
				'int_port' => $map['intport'],
				'protocol' => strtoupper($map['proto']),
				'src_addr' => $map['srcaddr'],
				'src_port' => ($map['srcport'] !== '') ? $map['srcport'] : 'any',
				'descr' => $map['descr'],
			);
		}
	}
	return array('data' => array('enabled' => restapi_upnp_enabled(), 'running' => upnp_running(), 'port_maps' => $maps));
}

function restapi_h_upnp_flush($req) {
	if (!restapi_upnp_enabled()) {
		throw new RestApiError(409, 'service_disabled', 'UPnP IGD & PCP is disabled.');
	}
	upnp_action('restart');
	return array('data' => array('deleted' => true, 'running' => upnp_running()));
}

/* ------------------------------------------------------------------ */
/* Wake-on-LAN                                                         */
/* ------------------------------------------------------------------ */

function restapi_wol_entries() {
	$entries = config_get_path('wol/wolentry', []);
	return is_array($entries) ? $entries : array();
}

function restapi_wol_out($id) {
	$e = (array)config_get_path("wol/wolentry/{$id}", []);
	return array('id' => (int)$id, 'interface' => (string)($e['interface'] ?? ''), 'mac' => (string)($e['mac'] ?? ''),
	    'descr' => (string)($e['descr'] ?? ''));
}

function restapi_wol_types() {
	return array('id' => 'ro', 'interface' => 'string', 'mac' => 'string', 'descr' => 'string');
}

/* Save through the edit page's function; the list is re-sorted, so find the device again by interface and MAC. */
function restapi_wol_save(array $values, $id, $status) {
	$errors = wol_save_entry(restapi_svc_post($values, restapi_wol_types()), $id);
	if (!empty($errors)) {
		throw restapi_validation_error($errors);
	}
	$mac = trim(wol_normalize_mac($values['mac']));
	foreach (restapi_wol_entries() as $idx => $e) {
		if ((($e['interface'] ?? '') == $values['interface']) && (($e['mac'] ?? '') == $mac)) {
			return array('status' => $status, 'data' => restapi_wol_out($idx));
		}
	}
	throw new RestApiError(500, 'internal_error', 'The saved device was not found.');
}

function restapi_h_wol_list($req) {
	return array('data' => array_map('restapi_wol_out', array_keys(restapi_wol_entries())));
}

function restapi_h_wol_get($req) {
	return array('data' => restapi_wol_out(restapi_index_param($req['params']['id'], restapi_wol_entries(), 'Wake-on-LAN device')));
}

function restapi_h_wol_create($req) {
	$values = restapi_svc_merge($req['body'], array('interface' => '', 'mac' => '', 'descr' => ''), restapi_wol_types());
	return restapi_wol_save($values, null, 201);
}

function restapi_h_wol_update($req) {
	$id = restapi_index_param($req['params']['id'], restapi_wol_entries(), 'Wake-on-LAN device');
	$values = restapi_svc_merge($req['body'], restapi_wol_out($id), restapi_wol_types());
	return restapi_wol_save($values, $id, 200);
}

function restapi_h_wol_delete($req) {
	$id = restapi_index_param($req['params']['id'], restapi_wol_entries(), 'Wake-on-LAN device');
	wol_delete_entry((string)$id);
	return array('data' => array('deleted' => $id));
}

/* Wake one device like the page's "Send": 422 for invalid input, 409 when the interface has no IPv4 address. */
function restapi_wol_wake($mac, $if) {
	$savemsg = '';
	$class = '';
	$errors = wol_wake_device($mac, $if, $savemsg, $class);
	if (!empty($errors)) {
		throw restapi_validation_error($errors);
	}
	if ($class === '') {
		throw new RestApiError(409, 'not_sent', 'The interface has no IPv4 address to send the magic packet from.');
	}
	if ($class !== 'success') {
		throw new RestApiError(502, 'wol_failed', 'The wol command did not complete successfully; see the system log.');
	}
	return array('data' => array('sent' => true, 'interface' => $if, 'mac' => $mac));
}

function restapi_h_wol_wake_entry($req) {
	$id = restapi_index_param($req['params']['id'], restapi_wol_entries(), 'Wake-on-LAN device');
	$entry = restapi_wol_out($id);
	/* The page's per-device link posts the stored MAC and interface. */
	return restapi_wol_wake(wol_normalize_mac($entry['mac']), $entry['interface']);
}

function restapi_h_wol_wake($req) {
	$values = restapi_svc_merge($req['body'], array('interface' => '', 'mac' => ''),
	    array('interface' => 'string', 'mac' => 'string'));
	return restapi_wol_wake(wol_normalize_mac($values['mac']), $values['interface']);
}

function restapi_h_wol_wake_all($req) {
	$savemsg = '';
	$class = '';
	wol_wake_all($savemsg, $class);
	$messages = array_values(array_filter(array_map(function ($m) {
		return html_entity_decode(strip_tags($m), ENT_QUOTES);
	}, explode('<br />', $savemsg)), 'strlen'));
	return array('data' => array('messages' => $messages));
}

/* ------------------------------------------------------------------ */
/* IGMP Proxy                                                          */
/* ------------------------------------------------------------------ */

function restapi_igmp_maybe_apply($req) {
	return restapi_svc_maybe_apply($req, 'igmpproxy', 'igmpproxy_apply');
}

function restapi_igmp_settings() {
	$pconfig = igmpproxy_settings();
	return array('enable' => !empty($pconfig['enable']), 'verbose' => !empty($pconfig['igmpxverbose']),
	    'pending' => is_subsystem_dirty('igmpproxy'));
}

function restapi_h_igmp_get($req) {
	return array('data' => restapi_igmp_settings());
}

function restapi_h_igmp_set($req) {
	$values = restapi_svc_merge($req['body'], restapi_igmp_settings(), array('enable' => 'bool', 'verbose' => 'bool', 'pending' => 'ro'));
	$post = restapi_svc_post(array('enable' => $values['enable'], 'igmpxverbose' => $values['verbose']),
	    array('enable' => 'bool', 'igmpxverbose' => 'bool'));
	$errors = igmpproxy_save_settings($post);
	if (!empty($errors)) {
		throw restapi_validation_error($errors);
	}
	$extra = restapi_igmp_maybe_apply($req);
	return array('data' => array_merge(restapi_igmp_settings(), $extra));
}

function restapi_h_igmp_apply($req) {
	$retval = igmpproxy_apply();
	return array('data' => array('applied' => ($retval == 0), 'pending' => is_subsystem_dirty('igmpproxy')));
}

function restapi_igmp_entries() {
	$entries = config_get_path('igmpproxy/igmpentry', []);
	return is_array($entries) ? $entries : array();
}

function restapi_igmp_out($id) {
	$e = (array)config_get_path("igmpproxy/igmpentry/{$id}", []);
	return array('id' => (int)$id, 'ifname' => (string)($e['ifname'] ?? ''), 'type' => (string)($e['type'] ?? ''),
	    'threshold' => (string)($e['threshold'] ?? ''),
	    'networks' => array_values(array_filter(explode(' ', (string)($e['address'] ?? '')), 'strlen')),
	    'descr' => (string)($e['descr'] ?? ''));
}

function restapi_igmp_types() {
	return array('id' => 'ro', 'ifname' => 'string', 'type' => 'string', 'threshold' => 'string', 'networks' => 'list', 'descr' => 'string');
}

function restapi_igmp_save(array $values, $id, $req) {
	$post = restapi_svc_post($values, array('ifname' => 'string', 'type' => 'string', 'threshold' => 'string', 'descr' => 'string')) +
	    restapi_igmp_network_rows($values['networks']);
	$rv = igmpproxy_save_entry($post, $id);
	if (!empty($rv['input_errors'])) {
		throw restapi_validation_error($rv['input_errors']);
	}
	return restapi_igmp_maybe_apply($req);
}

function restapi_h_igmp_list($req) {
	return array('data' => array_map('restapi_igmp_out', array_keys(restapi_igmp_entries())));
}

function restapi_h_igmp_entry_get($req) {
	return array('data' => restapi_igmp_out(restapi_index_param($req['params']['id'], restapi_igmp_entries(), 'IGMP Proxy entry')));
}

function restapi_h_igmp_create($req) {
	/* The edit page's type select starts at "Upstream Interface". */
	$empty = array('ifname' => '', 'type' => 'upstream', 'threshold' => '', 'networks' => array(), 'descr' => '');
	$values = restapi_svc_merge($req['body'], $empty, restapi_igmp_types());
	$extra = restapi_igmp_save($values, null, $req);
	return array('status' => 201, 'data' => restapi_igmp_out(count(restapi_igmp_entries()) - 1) + $extra);
}

function restapi_h_igmp_update($req) {
	$id = restapi_index_param($req['params']['id'], restapi_igmp_entries(), 'IGMP Proxy entry');
	$values = restapi_svc_merge($req['body'], restapi_igmp_out($id), restapi_igmp_types());
	$extra = restapi_igmp_save($values, $id, $req);
	return array('data' => restapi_igmp_out($id) + $extra);
}

function restapi_h_igmp_delete($req) {
	$id = restapi_index_param($req['params']['id'], restapi_igmp_entries(), 'IGMP Proxy entry');
	igmpproxy_delete_entry((string)$id);
	return array('data' => array('deleted' => $id) + restapi_igmp_maybe_apply($req));
}

/* ------------------------------------------------------------------ */
/* DHCP and DHCPv6 Relay                                               */
/* ------------------------------------------------------------------ */

function restapi_relay_v6($req) {
	return strpos($req['path'], '/v1/services/dhcpv6-relay') === 0;
}

function restapi_relay_types() {
	return array('enable' => 'bool', 'agentoption' => 'bool', 'interface' => 'list', 'server' => 'list',
	    'carpstatusvip' => 'string', 'dhcp_server_enabled' => 'ro', 'interface_choices' => 'ro', 'carp_choices' => 'ro');
}

function restapi_relay_settings($v6) {
	$pconfig = dhcp_relay_settings($v6);
	return array(
		'enable' => !empty($pconfig['enable']),
		'interface' => array_values(array_map('strval', (array)$pconfig['interface'])),
		'agentoption' => !empty($pconfig['agentoption']),
		'server' => restapi_svc_split($pconfig['server']),
		'carpstatusvip' => (string)$pconfig['carpstatusvip'],
		'dhcp_server_enabled' => dhcp_relay_dhcpd_enabled($v6),
		'interface_choices' => restapi_svc_choices(dhcp_relay_interface_list($v6)),
		'carp_choices' => restapi_svc_choices(dhcp_relay_carp_list($v6)),
	);
}

function restapi_h_relay_get($req) {
	return array('data' => restapi_relay_settings(restapi_relay_v6($req)));
}

function restapi_h_relay_set($req) {
	$v6 = restapi_relay_v6($req);
	$types = restapi_relay_types();
	$values = restapi_svc_merge($req['body'], restapi_relay_settings($v6), $types);
	$servers = $values['server'];
	unset($values['server']);
	$rv = dhcp_relay_save(restapi_svc_post($values, $types) + restapi_relay_server_rows($servers), $v6);
	if (!empty($rv['input_errors'])) {
		throw restapi_validation_error($rv['input_errors']);
	}
	return array('data' => restapi_relay_settings($v6) + array('applied' => ($rv['retval'] == 0)));
}

/* ------------------------------------------------------------------ */
/* SNMP                                                                */
/* ------------------------------------------------------------------ */

function restapi_snmp_types() {
	$types = array('bindip_choices' => 'ro', 'bindip' => 'list');
	foreach (array('enable', 'trapenable', 'mibii', 'netgraph', 'pf', 'hostres', 'bridge', 'ucd', 'regex') as $flag) {
		$types[$flag] = 'bool';
	}
	foreach (array('pollport', 'syslocation', 'syscontact', 'rocommunity', 'trapserver', 'trapserverport', 'trapstring', 'ipprotocol') as $field) {
		$types[$field] = 'string';
	}
	return $types;
}

/*
 * The settings with the secrets in clear (never returned). Empty ports and
 * protocol take what the page's form shows and posts for them.
 */
function restapi_snmp_settings() {
	snmp_init_config();
	$pconfig = snmp_settings();
	$out = array();
	foreach (restapi_snmp_types() as $key => $type) {
		if ($type === 'bool') {
			$out[$key] = !empty($pconfig[$key]);
		} elseif ($type === 'list') {
			$out[$key] = array_values(array_map('strval', (array)$pconfig[$key]));
		} elseif ($type === 'string') {
			$out[$key] = (string)$pconfig[$key];
		}
	}
	foreach (array('pollport' => '161', 'trapserverport' => '162', 'ipprotocol' => 'inet4') as $key => $default) {
		if ($out[$key] === '') {
			$out[$key] = $default;
		}
	}
	return $out;
}

function restapi_snmp_out(array $settings) {
	$settings['rocommunity'] = restapi_svc_mask($settings['rocommunity']);
	$settings['trapstring'] = restapi_svc_mask($settings['trapstring']);
	$settings['bindip_choices'] = restapi_svc_choices(snmp_build_if_list(array())['options']);
	return $settings;
}

function restapi_h_snmp_get($req) {
	return array('data' => restapi_snmp_out(restapi_snmp_settings()));
}

function restapi_h_snmp_set($req) {
	$body = $req['body'];
	foreach (array('rocommunity', 'trapstring') as $secret) {
		if (($body[$secret] ?? null) === '(set)') {
			unset($body[$secret]);
		}
	}
	$types = restapi_snmp_types();
	$values = restapi_svc_merge($body, restapi_snmp_settings(), $types);
	$rv = snmp_save(restapi_svc_post($values, $types));
	if (!empty($rv['input_errors'])) {
		throw restapi_validation_error($rv['input_errors']);
	}
	return array('data' => restapi_snmp_out(restapi_snmp_settings()) + array('applied' => ($rv['retval'] == 0)));
}
