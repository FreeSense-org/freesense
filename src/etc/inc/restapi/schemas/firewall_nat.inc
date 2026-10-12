<?php
/*
 * firewall_nat.inc
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
 * Schemas of the four NAT kinds (GET /api/v1/firewall/nat/{kind}/{id}
 * "fields", POST/PUT /api/v1/firewall/nat/{kind}). Field names are the
 * edit pages' form fields, which the save functions (saveNATrule,
 * saveoutNATrule, save1to1NATrule, savenptNATrule) take; choices come from
 * the same globals and functions as the pages. Labels follow the names the
 * save functions use in their messages, and "errorMatch" lists the other
 * phrases of their input_errors, so a 422 lands on the right field.
 *
 *   firewall/nat_port_forwards  firewall_nat_edit.php
 *   firewall/nat_outbound       firewall_nat_out_edit.php
 *   firewall/nat_one_to_one     firewall_nat_1to1_edit.php
 *   firewall/nat_npt            firewall_nat_npt_edit.php
 */

/* get_specialnet() choices for a type selector, or none where it is not loaded (schema smoke test). */
function restapi_schema_nat_specialnet(array $flags) {
	if (!function_exists('get_specialnet')) {
		return array();
	}
	return restapi_schema_choices(get_specialnet('', $flags));
}

/* SPECIALNET_* constants by name; empty when util.inc is not loaded. */
function restapi_schema_nat_flags(array $names) {
	$out = array();
	foreach ($names as $name) {
		if (defined("SPECIALNET_{$name}")) {
			$out[] = constant("SPECIALNET_{$name}");
		}
	}
	return $out;
}

/* The protocols that have ports (get_ipprotocols('portsonly')). */
function restapi_schema_nat_port_protocols() {
	return function_exists('get_ipprotocols') ? array_map('strval', array_keys(get_ipprotocols('portsonly'))) :
	    array('tcp', 'udp', 'tcp/udp', 'sctp');
}

function restapi_schema_nat_interfaces($fn) {
	return function_exists($fn) ? restapi_schema_choices(call_user_func($fn)) : array();
}

function restapi_schema_nat_alias_source() {
	return array('path' => '/v1/firewall/aliases', 'value' => 'name', 'detail' => 'description');
}

/* Well-known ports for the port typeaheads, with "any" first. */
function restapi_schema_nat_port_suggestions() {
	global $wkports;
	$ports = array(array('value' => 'any', 'label' => gettext('any')));
	foreach ((array)$wkports as $port => $label) {
		$ports[] = array('value' => (string)$port, 'label' => "{$label} ({$port})");
	}
	return $ports;
}

/* ------------------------------------------------------------------ */
/* Port forward                                                        */
/* ------------------------------------------------------------------ */

/* Source or destination of a port forward: the rule endpoint's fields with the port forward's type lists. */
function restapi_schema_natpf_endpoint($type) {
	$name = ($type === 'src') ? gettext('Source') : gettext('Destination');
	$lower = ($type === 'src') ? 'source' : 'destination';
	/* build_srctype_list() / build_dsttype_list() of firewall_nat.inc */
	$types = ($type === 'src') ?
	    restapi_schema_nat_specialnet(restapi_schema_nat_flags(array('CHECKPERM', 'ANY', 'COMPAT_ADDRAL', 'NET', 'CLIENTS', 'IFADDR', 'IFNET'))) :
	    restapi_schema_nat_specialnet(restapi_schema_nat_flags(array('CHECKPERM', 'ANY', 'COMPAT_ADDRAL', 'NET', 'SELF', 'CLIENTS', 'IFADDR', 'IFNET', 'VIPS')));
	$withPorts = array('field' => 'proto', 'in' => restapi_schema_nat_port_protocols());
	$section = array('id' => $lower, 'title' => $name, 'fields' => array(
		array('name' => "{$type}type", 'type' => 'select', 'label' => sprintf(gettext('%s type'), $name), 'width' => 'half',
		    'default' => 'any', 'options' => $types,
		    'errorMatch' => ($type === 'dst') ? array("does not support the 'any' destination type") : array()),
		array('name' => "{$type}not", 'type' => 'switch', 'label' => gettext('Invert match'), 'width' => 'half',
		    'text' => sprintf(gettext('Match everything except this %s'), $lower)),
		array('name' => $type, 'type' => 'typeahead', 'label' => sprintf(gettext('%s address'), $name), 'mono' => true, 'width' => 'half',
		    'placeholder' => gettext('Address or alias'), 'visibleWhen' => array('field' => "{$type}type", 'in' => array('single', 'network')),
		    'source' => restapi_schema_nat_alias_source(),
		    'errorMatch' => array_merge(array("{$lower} ip address", "{$lower} must be"), ($type === 'src') ? array('alias entries') : array())),
		array('name' => "{$type}mask", 'type' => 'number', 'label' => gettext('Prefix length'), 'min' => 1, 'max' => 128, 'width' => 'third',
		    'visibleWhen' => array('field' => "{$type}type", 'equals' => 'network'), 'errorMatch' => array("{$lower} bit count")),
		array('name' => "{$type}beginport", 'type' => 'typeahead', 'label' => sprintf(gettext('%s port from'), $name), 'mono' => true, 'width' => 'third',
		    'placeholder' => 'any', 'suggestions' => restapi_schema_nat_port_suggestions(), 'visibleWhen' => $withPorts,
		    'help' => gettext('A port, a port alias or "any".'),
		    'errorMatch' => array("start {$lower} port", "{$lower} port range")),
		array('name' => "{$type}endport", 'type' => 'typeahead', 'label' => sprintf(gettext('%s port to'), $name), 'mono' => true, 'width' => 'third',
		    'placeholder' => gettext('same'), 'suggestions' => restapi_schema_nat_port_suggestions(), 'visibleWhen' => $withPorts,
		    'help' => gettext('Leave empty for a single port.'),
		    'errorMatch' => array("end {$lower} port")),
	));
	if ($type === 'src') {
		/* firewall_nat_edit.php hides the source behind "Display Advanced". */
		$section['advanced'] = true;
		$section['description'] = gettext('Usually any: clients connect from random ports.');
		$section['fields'][4]['default'] = 'any';
	} else {
		$section['fields'][4]['required'] = true;
		$section['fields'][4]['help'] = gettext('The port or first port of the range on the firewall that is forwarded, a port alias or "any".');
		$section['fields'][4]['errorMatch'][] = 'overlaps with an existing entry';
	}
	return $section;
}

function restapi_schema_firewall_nat_port_forwards() {
	$ports = restapi_schema_nat_port_protocols();
	$rdr = array('field' => 'nordr', 'truthy' => false);
	return array(
		'title' => gettext('Port forward'),
		'summary' => gettext('{proto} on {interface} to {dsttype}[ port {dstbeginport}] → {localtype}[ {localip}][ port {localbeginport}]'),
		'summaryIcon' => 'right-to-bracket',
		'sections' => array(
			array('id' => 'rule', 'title' => gettext('Port forward'), 'fields' => array(
				array('name' => 'disabled', 'type' => 'switch', 'label' => gettext('Disabled'), 'text' => gettext('Keep the rule without using it'), 'width' => 'half'),
				array('name' => 'nordr', 'type' => 'switch', 'label' => gettext('No RDR (NOT)'), 'width' => 'half',
				    'text' => gettext('Disable redirection for traffic matching this rule'),
				    'help' => gettext('This option is rarely needed. Don\'t use this without thorough knowledge of the implications.')),
				array('name' => 'interface', 'type' => 'select', 'label' => gettext('Interface'), 'required' => true, 'default' => 'wan', 'width' => 'half',
				    'options' => restapi_schema_nat_interfaces('filter_get_interface_list'),
				    'help' => gettext('Choose which interface this rule applies to. In most cases "WAN" is specified.'),
				    'errorMatch' => array('submitted interface does not exist')),
				array('name' => 'ipprotocol', 'type' => 'segmented', 'label' => gettext('Address family'), 'required' => true, 'default' => 'inet', 'width' => 'half',
				    'options' => restapi_schema_choices(array('inet' => 'IPv4', 'inet6' => 'IPv6'))),
				array('name' => 'proto', 'type' => 'select', 'label' => gettext('Protocol'), 'required' => true, 'default' => 'tcp', 'width' => 'half',
				    'options' => restapi_schema_choices(function_exists('get_ipprotocols') ? get_ipprotocols('portforward') : array()),
				    'help' => gettext('Choose which protocol this rule should match. In most cases "TCP" is specified.')),
			)),
			restapi_schema_natpf_endpoint('src'),
			restapi_schema_natpf_endpoint('dst'),
			array('id' => 'target', 'title' => gettext('Redirect target'), 'visibleWhen' => $rdr,
			    'description' => gettext('The internal server the ports are forwarded to.'), 'fields' => array(
				array('name' => 'localtype', 'type' => 'select', 'label' => gettext('Redirect target type'), 'default' => 'single', 'width' => 'half',
				    /* build_localtype_list() */
				    'options' => restapi_schema_nat_specialnet(restapi_schema_nat_flags(array('CHECKPERM', 'COMPAT_ADDRAL', 'IFADDR'))),
				    'errorMatch' => array('redirect interface must have')),
				array('name' => 'localip', 'type' => 'typeahead', 'label' => gettext('Redirect target IP'), 'mono' => true, 'width' => 'half',
				    'placeholder' => gettext('Address or alias'), 'visibleWhen' => array('field' => 'localtype', 'equals' => 'single'),
				    'source' => restapi_schema_nat_alias_source(),
				    'help' => gettext('The internal IP address of the server, e.g. 192.168.1.12. An IPv6 target must be in the same scope as the destination.')),
				array('name' => 'localbeginport', 'type' => 'typeahead', 'label' => gettext('Redirect target port'), 'mono' => true, 'width' => 'half',
				    'suggestions' => array_slice(restapi_schema_nat_port_suggestions(), 1),
				    'visibleWhen' => array(array('field' => 'proto', 'in' => $ports), array('field' => 'dstbeginport', 'notEquals' => 'any')),
				    'help' => gettext('The port on the server; for a range, its first port (the end is calculated). Usually the same as the destination port.'),
				    'errorMatch' => array('target port range')),
			)),
			array('id' => 'options', 'title' => gettext('Description and filter rule'), 'fields' => array(
				array('name' => 'descr', 'type' => 'text', 'label' => gettext('Description'),
				    'help' => gettext('A description for administrative reference (not parsed).')),
				array('name' => 'filter-rule-association', 'type' => 'select', 'label' => gettext('Filter rule association'), 'width' => 'half',
				    'default' => 'add-associated', 'visibleWhen' => $rdr,
				    'options' => restapi_schema_choices(array(
				        '' => gettext('None'),
				        'add-associated' => gettext('Add associated filter rule'),
				        'add-unassociated' => gettext('Add unassociated filter rule'),
				        'pass' => gettext('Pass'))),
				    'help' => gettext('An associated rule follows this port forward. "Pass" needs no rule but does not work properly with Multi-WAN: it only works on an interface with the default gateway.')),
				array('name' => 'natreflection', 'type' => 'select', 'label' => gettext('NAT reflection'), 'width' => 'half', 'default' => 'default',
				    'options' => restapi_schema_choices(array(
				        'default' => gettext('Use system default'),
				        'enable' => gettext('Enable (NAT + Proxy)'),
				        'purenat' => gettext('Enable (Pure NAT)'),
				        'disable' => gettext('Disable'))),
				    'errorMatch' => array('nat + proxy reflection')),
			)),
			array('id' => 'advanced', 'title' => gettext('Advanced options'), 'advanced' => true, 'fields' => array(
				array('name' => 'nosync', 'type' => 'switch', 'label' => gettext('No XMLRPC Sync'), 'text' => gettext('Do not automatically sync to other CARP members'),
				    'help' => gettext('This does NOT prevent the rule from being overwritten on the secondary.')),
			)),
		),
	);
}

/* ------------------------------------------------------------------ */
/* Outbound NAT mapping                                                */
/* ------------------------------------------------------------------ */

function restapi_schema_firewall_nat_outbound() {
	/* The protocol "any" also has ports here (saveoutNATrule()'s $protocol_uses_ports). */
	$ports = array_merge(array('any'), restapi_schema_nat_port_protocols());
	$targets = restapi_schema_nat_specialnet(restapi_schema_nat_flags(array('NETAL', 'IFADDR', 'VIPS')));
	/* Pool options apply to a custom network and to VIP subnets (values with a "/"). */
	$pools = array('network');
	foreach ($targets as $t) {
		if (strpos($t['value'], '/') !== false) {
			$pools[] = $t['value'];
		}
	}
	$nat = array('field' => 'nonat', 'truthy' => false);
	return array(
		'title' => gettext('Outbound NAT mapping'),
		'summary' => gettext('{protocol} on {interface} from {source_type}[ {source}] to {destination_type}[ {destination}] → {target_type}[ {target}]'),
		'summaryIcon' => 'right-from-bracket',
		'sections' => array(
			array('id' => 'mapping', 'title' => gettext('Mapping'), 'fields' => array(
				array('name' => 'disabled', 'type' => 'switch', 'label' => gettext('Disabled'), 'text' => gettext('Keep the mapping without using it'), 'width' => 'half'),
				array('name' => 'nonat', 'type' => 'switch', 'label' => gettext('Do not NAT'), 'width' => 'half',
				    'text' => gettext('Do not translate matching traffic and stop processing outbound NAT rules'),
				    'help' => gettext('In most cases this option is not required.')),
				array('name' => 'interface', 'type' => 'select', 'label' => gettext('Interface'), 'required' => true, 'default' => 'wan', 'width' => 'half',
				    'options' => restapi_schema_nat_interfaces('create_interface_list'),
				    'help' => gettext('The interface on which traffic is matched as it exits the firewall, usually "WAN".')),
				array('name' => 'ipprotocol', 'type' => 'segmented', 'label' => gettext('Address family'), 'default' => '', 'width' => 'half',
				    'options' => restapi_schema_choices(array('inet' => 'IPv4', 'inet6' => 'IPv6', '' => 'IPv4+IPv6'))),
				array('name' => 'protocol', 'type' => 'select', 'label' => gettext('Protocol'), 'required' => true, 'default' => 'any', 'width' => 'half',
				    'options' => restapi_schema_choices(function_exists('get_ipprotocols') ? get_ipprotocols('outboundnat') : array()),
				    'help' => gettext('In most cases "any" is specified.')),
			)),
			array('id' => 'source', 'title' => gettext('Source'), 'fields' => array(
				array('name' => 'source_type', 'type' => 'select', 'label' => gettext('Source type'), 'default' => 'any', 'width' => 'half',
				    'options' => restapi_schema_nat_specialnet(restapi_schema_nat_flags(array('ANY', 'SELF', 'NETAL', 'IFNET', 'GROUP')))),
				array('name' => 'source', 'type' => 'typeahead', 'label' => gettext('Source'), 'mono' => true, 'width' => 'half',
				    'placeholder' => gettext('Network or alias'), 'visibleWhen' => array('field' => 'source_type', 'equals' => 'network'),
				    'source' => restapi_schema_nat_alias_source()),
				array('name' => 'source_subnet', 'type' => 'number', 'label' => gettext('Source bit count'), 'min' => 0, 'max' => 128, 'width' => 'third',
				    'default' => 24, 'visibleWhen' => array('field' => 'source_type', 'equals' => 'network')),
				array('name' => 'sourceport', 'type' => 'text', 'label' => gettext('Source port'), 'mono' => true, 'width' => 'third',
				    'placeholder' => gettext('any'), 'visibleWhen' => array('field' => 'protocol', 'in' => $ports),
				    'help' => gettext('A port, a range or a port alias.'), 'errorMatch' => array('source port entry')),
			)),
			array('id' => 'destination', 'title' => gettext('Destination'), 'fields' => array(
				array('name' => 'destination_type', 'type' => 'select', 'label' => gettext('Destination type'), 'default' => 'any', 'width' => 'half',
				    'options' => restapi_schema_nat_specialnet(restapi_schema_nat_flags(array('ANY', 'NETAL', 'IFNET', 'GROUP')))),
				array('name' => 'destination_not', 'type' => 'switch', 'label' => gettext('Invert match'), 'width' => 'half',
				    'text' => gettext('Match everything except this destination'), 'errorMatch' => array('negating destination address')),
				array('name' => 'destination', 'type' => 'typeahead', 'label' => gettext('Destination'), 'mono' => true, 'width' => 'half',
				    'placeholder' => gettext('Network or alias'), 'visibleWhen' => array('field' => 'destination_type', 'equals' => 'network'),
				    'source' => restapi_schema_nat_alias_source()),
				array('name' => 'destination_subnet', 'type' => 'number', 'label' => gettext('Destination bit count'), 'min' => 0, 'max' => 128, 'width' => 'third',
				    'default' => 24, 'visibleWhen' => array('field' => 'destination_type', 'equals' => 'network')),
				array('name' => 'dstport', 'type' => 'text', 'label' => gettext('Destination port'), 'mono' => true, 'width' => 'third',
				    'placeholder' => gettext('any'), 'visibleWhen' => array('field' => 'protocol', 'in' => $ports),
				    'help' => gettext('A port, a range or a port alias.'), 'errorMatch' => array('destination port entry')),
			)),
			array('id' => 'translation', 'title' => gettext('Translation'), 'visibleWhen' => $nat,
			    'description' => gettext('Connections matching this mapping are translated to this address. A custom network or alias must be routed to the firewall.'),
			    'fields' => array(
				array('name' => 'target_type', 'type' => 'select', 'label' => gettext('Translation address'), 'width' => 'half',
				    'options' => $targets, 'errorMatch' => array('target type')),
				array('name' => 'target', 'type' => 'typeahead', 'label' => gettext('Target address'), 'mono' => true, 'width' => 'third',
				    'placeholder' => gettext('Network or alias'), 'visibleWhen' => array('field' => 'target_type', 'equals' => 'network'),
				    'source' => restapi_schema_nat_alias_source(), 'errorMatch' => array('target ip address or alias')),
				array('name' => 'target_subnet', 'type' => 'number', 'label' => gettext('Target bit count'), 'min' => 0, 'max' => 128, 'width' => 'third',
				    'visibleWhen' => array('field' => 'target_type', 'equals' => 'network')),
				array('name' => 'poolopts', 'type' => 'select', 'label' => gettext('Pool options'), 'width' => 'half',
				    'visibleWhen' => array('field' => 'target_type', 'in' => $pools),
				    'options' => restapi_schema_choices(array(
				        '' => gettext('Default'),
				        'round-robin' => gettext('Round Robin'),
				        'round-robin sticky-address' => gettext('Round Robin with Sticky Address'),
				        'random' => gettext('Random'),
				        'random sticky-address' => gettext('Random with Sticky Address'),
				        'source-hash' => gettext('Source hash'),
				        'bitmask' => gettext('Bit mask'))),
				    'help' => gettext('Only Round Robin types work with host aliases. Any type can be used with a subnet.'),
				    'errorMatch' => array('round robin pool options')),
				array('name' => 'source_hash_key', 'type' => 'text', 'label' => gettext('Source hash key'), 'mono' => true, 'width' => 'half',
				    'visibleWhen' => array(array('field' => 'target_type', 'in' => $pools), array('field' => 'poolopts', 'equals' => 'source-hash')),
				    'help' => gettext('A hex key preceded by "0x", or any string (hashed with md5). Defaults to a random value.'),
				    'errorMatch' => array('source-hash key')),
				array('name' => 'natport', 'type' => 'text', 'label' => gettext('NAT port'), 'mono' => true, 'width' => 'half',
				    'visibleWhen' => array(array('field' => 'protocol', 'in' => $ports), array('field' => 'staticnatport', 'truthy' => false)),
				    'help' => gettext('The external source port or range (low:high) used for remapping. Leave empty for a random port.'),
				    'errorMatch' => array('nat port entry')),
				array('name' => 'staticnatport', 'type' => 'switch', 'label' => gettext('Static port'), 'width' => 'half',
				    'text' => gettext('Keep the source port'), 'visibleWhen' => array('field' => 'protocol', 'in' => $ports)),
				array('name' => 'eimnat', 'type' => 'switch', 'label' => gettext('Endpoint-Independent Mapping'),
				    'text' => gettext('Enable EIM-NAT for UDP connections'),
				    'visibleWhen' => array('field' => 'protocol', 'in' => array_values(array_diff($ports, array('tcp', 'sctp')))),
				    'help' => gettext('Experimental. A consistent external IP:port mapping across destinations for the same client IP:port.')),
			)),
			array('id' => 'misc', 'title' => gettext('Description'), 'fields' => array(
				array('name' => 'descr', 'type' => 'text', 'label' => gettext('Description'),
				    'help' => gettext('A description for administrative reference (not parsed).')),
				array('name' => 'nosync', 'type' => 'switch', 'label' => gettext('No XMLRPC Sync'), 'text' => gettext('Do not automatically sync to other CARP members')),
			)),
		),
	);
}

/* ------------------------------------------------------------------ */
/* 1:1 NAT mapping                                                     */
/* ------------------------------------------------------------------ */

function restapi_schema_firewall_nat_one_to_one() {
	return array(
		'title' => gettext('1:1 NAT mapping'),
		'summary' => gettext('{exttype}[ {external}] ↔ {srctype}[ {src}] on {interface}'),
		'summaryIcon' => 'arrows-left-right',
		'sections' => array(
			array('id' => 'mapping', 'title' => gettext('Mapping'), 'fields' => array(
				array('name' => 'disabled', 'type' => 'switch', 'label' => gettext('Disabled'), 'text' => gettext('Keep the mapping without using it'), 'width' => 'half'),
				array('name' => 'nobinat', 'type' => 'switch', 'label' => gettext('No BINAT (NOT)'), 'width' => 'half',
				    'text' => gettext('Do not perform binat for the specified address'),
				    'help' => gettext('Excludes the address from a later, more general, rule.')),
				array('name' => 'interface', 'type' => 'select', 'label' => gettext('Interface'), 'required' => true, 'default' => 'wan', 'width' => 'half',
				    'options' => restapi_schema_nat_interfaces('filter_get_interface_list'),
				    'help' => gettext('Choose which interface this rule applies to. In most cases "WAN" is specified.'),
				    'errorMatch' => array('interface does not have an address')),
				array('name' => 'ipprotocol', 'type' => 'segmented', 'label' => gettext('Address family'), 'required' => true, 'default' => 'inet', 'width' => 'half',
				    'options' => restapi_schema_choices(array('inet' => 'IPv4', 'inet6' => 'IPv6'))),
			)),
			array('id' => 'external', 'title' => gettext('External'), 'fields' => array(
				array('name' => 'exttype', 'type' => 'select', 'label' => gettext('External type'), 'default' => 'single', 'width' => 'half',
				    /* build_exttype_list() */
				    'options' => restapi_schema_nat_specialnet(restapi_schema_nat_flags(array('CHECKPERM', 'COMPAT_ADDR', 'IFADDR')))),
				array('name' => 'external', 'type' => 'text', 'label' => gettext('External subnet IP'), 'mono' => true, 'width' => 'half',
				    'visibleWhen' => array('field' => 'exttype', 'equals' => 'single'), 'errorMatch' => array('external subnet'),
				    'help' => gettext('The starting address of the external (usually WAN) subnet.')),
			)),
			array('id' => 'internal', 'title' => gettext('Internal'),
			    'description' => gettext('The internal (LAN) subnet. Its size also applies to the external subnet.'), 'fields' => array(
				array('name' => 'srctype', 'type' => 'select', 'label' => gettext('Internal type'), 'default' => 'single', 'width' => 'half',
				    /* binat_build_srctype_list() */
				    'options' => restapi_schema_nat_specialnet(restapi_schema_nat_flags(array('CHECKPERM', 'ANY', 'COMPAT_ADDR', 'NET', 'CLIENTS', 'IFADDR', 'IFSUB')))),
				array('name' => 'srcnot', 'type' => 'switch', 'label' => gettext('Invert match'), 'width' => 'half',
				    'text' => gettext('Match everything except this address')),
				array('name' => 'src', 'type' => 'text', 'label' => gettext('Internal IP'), 'mono' => true, 'width' => 'half',
				    'visibleWhen' => array('field' => 'srctype', 'in' => array('single', 'network')),
				    'errorMatch' => array('source address', 'internal ip is not from')),
				array('name' => 'srcmask', 'type' => 'number', 'label' => gettext('Internal prefix length'), 'min' => 1, 'max' => 128, 'width' => 'third',
				    'visibleWhen' => array('field' => 'srctype', 'equals' => 'network'), 'errorMatch' => array('internal bit count')),
			)),
			array('id' => 'destination', 'title' => gettext('Destination'),
			    'description' => gettext('The mapping is only used for connections to or from this destination, usually any.'), 'fields' => array(
				array('name' => 'dsttype', 'type' => 'select', 'label' => gettext('Destination type'), 'default' => 'any', 'width' => 'half',
				    /* binat_build_dsttype_list() */
				    'options' => restapi_schema_nat_specialnet(restapi_schema_nat_flags(array('CHECKPERM', 'ANY', 'COMPAT_ADDRAL', 'NET', 'CLIENTS', 'IFADDR', 'IFSUB', 'VIPS')))),
				array('name' => 'dstnot', 'type' => 'switch', 'label' => gettext('Invert match'), 'width' => 'half',
				    'text' => gettext('Match everything except this destination')),
				array('name' => 'dst', 'type' => 'typeahead', 'label' => gettext('Destination address'), 'mono' => true, 'width' => 'half',
				    'placeholder' => gettext('Address or alias'), 'visibleWhen' => array('field' => 'dsttype', 'in' => array('single', 'network')),
				    'source' => restapi_schema_nat_alias_source(), 'errorMatch' => array('alias entries')),
				array('name' => 'dstmask', 'type' => 'number', 'label' => gettext('Destination prefix length'), 'min' => 1, 'max' => 128, 'width' => 'third',
				    'visibleWhen' => array('field' => 'dsttype', 'equals' => 'network'), 'errorMatch' => array('destination bit count')),
			)),
			array('id' => 'options', 'title' => gettext('Description'), 'fields' => array(
				array('name' => 'descr', 'type' => 'text', 'label' => gettext('Description'),
				    'help' => gettext('A description for administrative reference (not parsed).')),
				array('name' => 'natreflection', 'type' => 'select', 'label' => gettext('NAT reflection'), 'width' => 'half', 'default' => 'default',
				    'options' => restapi_schema_choices(array(
				        'default' => gettext('Use system default'),
				        'enable' => gettext('Enable'),
				        'disable' => gettext('Disable')))),
			)),
		),
	);
}

/* ------------------------------------------------------------------ */
/* NPt mapping                                                         */
/* ------------------------------------------------------------------ */

/* firewall_nat_npt_edit.php's build_dsttype_list(): a prefix, or a tracking interface's delegated prefix. */
function restapi_schema_npt_dsttypes() {
	$list = array('network' => gettext('Prefix'));
	if (function_exists('get_configured_interface_with_descr') && function_exists('get_interface_track6ip')) {
		$iflist = get_configured_interface_with_descr();
		foreach ($iflist as $if => $ifdesc) {
			if ((config_get_path("interfaces/{$if}/ipaddrv6") == 'track6') && get_interface_track6ip($if)) {
				$track6ip = get_interface_track6ip($if);
				$pdsubnet = gen_subnetv6($track6ip[0], $track6ip[1]);
				$list[$if] = "{$ifdesc} " . gettext('Delegated Prefix') . ': ' .
				    ($iflist[config_get_path("interfaces/{$if}/track6-interface")] ?? '') . '/' .
				    config_get_path("interfaces/{$if}/track6-prefix-id") . " ({$pdsubnet}/{$track6ip[1]})";
			}
		}
	}
	return restapi_schema_choices($list);
}

function restapi_schema_firewall_nat_npt() {
	return array(
		'title' => gettext('NPt mapping'),
		'summary' => gettext('{src}/{srcmask} ↔ {dsttype}[ {dst}/{dstmask}] on {interface}'),
		'summaryIcon' => 'arrows-left-right',
		'sections' => array(
			array('id' => 'mapping', 'title' => gettext('Mapping'), 'fields' => array(
				array('name' => 'disabled', 'type' => 'switch', 'label' => gettext('Disabled'), 'text' => gettext('Keep the mapping without using it')),
				array('name' => 'interface', 'type' => 'select', 'label' => gettext('Interface'), 'required' => true, 'default' => 'wan', 'width' => 'half',
				    'options' => restapi_schema_nat_interfaces('create_interface_list'),
				    'help' => gettext('Typically the "WAN" is used here.')),
			)),
			array('id' => 'internal', 'title' => gettext('Internal IPv6 prefix'),
			    'description' => gettext('The internal (LAN) ULA prefix. Its size also applies to the external prefix.'), 'fields' => array(
				array('name' => 'srcnot', 'type' => 'switch', 'label' => gettext('Invert match'), 'text' => gettext('Match everything except this prefix')),
				array('name' => 'src', 'type' => 'text', 'label' => gettext('Source prefix'), 'required' => true, 'mono' => true, 'width' => 'two-thirds',
				    'errorMatch' => array('source address')),
				array('name' => 'srcmask', 'type' => 'number', 'label' => gettext('Source prefix length'), 'min' => 1, 'max' => 128, 'default' => 64, 'width' => 'third',
				    'errorMatch' => array('source prefix size must be equal')),
			)),
			array('id' => 'external', 'title' => gettext('Destination IPv6 prefix'),
			    'description' => gettext('The global unicast routable IPv6 prefix.'), 'fields' => array(
				array('name' => 'dstnot', 'type' => 'switch', 'label' => gettext('Invert match'), 'width' => 'half',
				    'text' => gettext('Match everything except this prefix')),
				array('name' => 'dsttype', 'type' => 'select', 'label' => gettext('Destination type'), 'default' => 'network', 'width' => 'half',
				    'options' => restapi_schema_npt_dsttypes()),
				array('name' => 'dst', 'type' => 'text', 'label' => gettext('Destination prefix'), 'mono' => true, 'width' => 'two-thirds',
				    'visibleWhen' => array('field' => 'dsttype', 'equals' => 'network'), 'errorMatch' => array('destination address')),
				array('name' => 'dstmask', 'type' => 'number', 'label' => gettext('Destination prefix length'), 'min' => 1, 'max' => 128, 'default' => 64, 'width' => 'third',
				    'visibleWhen' => array('field' => 'dsttype', 'equals' => 'network')),
			)),
			array('id' => 'options', 'title' => gettext('Description'), 'fields' => array(
				array('name' => 'descr', 'type' => 'text', 'label' => gettext('Description'),
				    'help' => gettext('A description for administrative reference (not parsed).')),
			)),
		),
	);
}
