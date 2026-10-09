<?php
/*
 * firewall_rules.inc
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
 * Schemas of a filter rule (GET /api/v1/firewall/rules/{id} "fields",
 * POST/PUT /api/v1/firewall/rules). Field names are the rule edit page's
 * form fields, which the API takes; choices come from the same globals and
 * functions as the page (firewall_rules.inc), so the form offers exactly
 * what saveFilterRule() accepts. Traffic shaper queues and limiters are not
 * in the schema: a save keeps their stored values.
 *
 *   firewall/rules           a rule on one interface
 *   firewall/floating_rules  a floating rule (several interfaces, direction, quick, match)
 */

/* [value => label] → [{value, label}] */
function restapi_schema_choices($pairs) {
	$out = array();
	foreach ((array)$pairs as $value => $label) {
		$out[] = array('value' => (string)$value, 'label' => html_entity_decode(stripslashes((string)$label), ENT_QUOTES));
	}
	return $out;
}

/* Source or destination: invert, type, address/mask and port range. */
function restapi_schema_rule_endpoint($type, $floating) {
	global $filter_srcdsttype_flags, $wkports;
	$name = ($type === 'src') ? gettext('Source') : gettext('Destination');
	$lower = ($type === 'src') ? 'source' : 'destination';
	$types = array();
	if (function_exists('get_specialnet') && defined('SPECIALNET_CHECKPERM')) {
		$flags = array_merge(array(SPECIALNET_CHECKPERM), (array)$filter_srcdsttype_flags);
		if (($type !== 'dst') && !$floating) {
			$flags = array_diff($flags, array(SPECIALNET_SELF));
		}
		$types = restapi_schema_choices(get_specialnet('', $flags));
	}
	$ports = array(array('value' => 'any', 'label' => gettext('any')));
	foreach ((array)$wkports as $port => $label) {
		$ports[] = array('value' => (string)$port, 'label' => "{$label} ({$port})");
	}
	$withPorts = array('field' => 'proto', 'in' => array('tcp', 'udp', 'tcp/udp'));
	return array('id' => $lower, 'title' => $name, 'fields' => array(
		array('name' => "{$type}type", 'type' => 'select', 'label' => sprintf(gettext('%s type'), $name), 'width' => 'half',
		    'default' => 'any', 'options' => $types),
		array('name' => "{$type}not", 'type' => 'switch', 'label' => gettext('Invert match'), 'width' => 'half',
		    'text' => sprintf(gettext('Match everything except this %s'), $lower),
		    'errorMatch' => array('invert match')),
		array('name' => $type, 'type' => 'typeahead', 'label' => sprintf(gettext('%s address'), $name), 'mono' => true, 'width' => 'half',
		    'placeholder' => gettext('Address or alias'), 'visibleWhen' => array('field' => "{$type}type", 'in' => array('single', 'network')),
		    'source' => array('path' => '/v1/firewall/aliases', 'value' => 'name', 'detail' => 'description'),
		    'errorMatch' => array("{$lower} ip address", 'alias entries', 'same family')),
		array('name' => "{$type}mask", 'type' => 'number', 'label' => gettext('Prefix length'), 'min' => 1, 'max' => 128, 'width' => 'third',
		    'visibleWhen' => array('field' => "{$type}type", 'equals' => 'network'), 'errorMatch' => array("{$lower} bit count")),
		array('name' => "{$type}beginport", 'type' => 'typeahead', 'label' => sprintf(gettext('%s port from'), $name), 'mono' => true, 'width' => 'third',
		    'placeholder' => 'any', 'suggestions' => $ports, 'visibleWhen' => $withPorts,
		    'help' => gettext('A port, a port alias or "any".'),
		    'errorMatch' => array("start {$lower} port", "{$name} port range from")),
		array('name' => "{$type}endport", 'type' => 'typeahead', 'label' => sprintf(gettext('%s port to'), $name), 'mono' => true, 'width' => 'third',
		    'placeholder' => gettext('same'), 'suggestions' => $ports, 'visibleWhen' => $withPorts,
		    'help' => gettext('Leave empty for a single port.'),
		    'errorMatch' => array("end {$lower} port", "{$name} port range to")),
	));
}

function restapi_schema_firewall_rule($floating) {
	global $icmptypes4, $statetype_values, $statepolicy_values, $vlanprio, $firewall_rules_dscp_types, $ostypes;

	$actions = array('pass' => gettext('Pass'), 'block' => gettext('Block'), 'reject' => gettext('Reject'));
	if ($floating) {
		$actions['match'] = gettext('Match');
	}
	$ifaces = function_exists('filter_get_interface_list') ? filter_get_interface_list() : array();
	$gateways = array('' => gettext('Default'));
	if (function_exists('get_gateways')) {
		foreach (get_gateways() as $gwname => $gw) {
			$gateways[$gwname] = $gw['name'] . (empty($gw['gateway']) ? '' : ' - ' . $gw['gateway']) . (empty($gw['descr']) ? '' : ' - ' . $gw['descr']);
		}
	}
	if (function_exists('return_gateway_groups_array')) {
		foreach ((array)return_gateway_groups_array() as $gwg_name => $gwg) {
			$gateways[$gwg_name] = $gwg_name . (empty($gwg['descr']) ? '' : ' - ' . $gwg['descr']);
		}
	}
	$schedules = array('' => gettext('none'));
	foreach ((function_exists('config_get_path') ? (array)config_get_path('schedules/schedule', array()) : array()) as $s) {
		if (is_array($s) && !empty($s['name'])) {
			$schedules[$s['name']] = $s['name'];
		}
	}
	$os = array('' => gettext('Any'));
	foreach ((array)($ostypes ?? array()) as $o) {
		$os[$o] = $o;
	}
	$dscp = array('' => gettext('Any'));
	foreach ((array)($firewall_rules_dscp_types ?? array()) as $d) {
		$dscp[$d] = $d;
	}

	$rule = array(
		array('name' => 'type', 'type' => 'segmented', 'label' => gettext('Action'), 'required' => true, 'default' => 'pass',
		    'options' => array_map(function ($o) { return $o + array('tone' => $o['value']); }, restapi_schema_choices($actions)), 'errorMatch' => array('rule type'),
		    'help' => gettext('Block drops packets silently; reject also tells the sender (TCP reset or ICMP unreachable).')),
		array('name' => 'disabled', 'type' => 'switch', 'label' => gettext('Disabled'), 'text' => gettext('Keep the rule without using it')),
	);
	if ($floating) {
		$rule[] = array('name' => 'quick', 'type' => 'switch', 'label' => gettext('Quick'), 'text' => gettext('Apply the action at once on match'));
		$rule[] = array('name' => 'interface', 'type' => 'checklist', 'label' => gettext('Interfaces'), 'required' => true,
		    'options' => restapi_schema_choices(array_merge(array('any' => gettext('Any')), $ifaces)));
		$rule[] = array('name' => 'direction', 'type' => 'segmented', 'label' => gettext('Direction'), 'default' => 'any',
		    'options' => restapi_schema_choices(array('any' => gettext('any'), 'in' => gettext('in'), 'out' => gettext('out'))),
		    'errorMatch' => array('without choosing a direction'));
	} else {
		$rule[] = array('name' => 'interface', 'type' => 'select', 'label' => gettext('Interface'), 'required' => true, 'width' => 'half',
		    'options' => restapi_schema_choices($ifaces), 'help' => gettext('Packets arriving on this interface are matched.'));
	}
	$rule[] = array('name' => 'ipprotocol', 'type' => 'segmented', 'label' => gettext('Address family'), 'required' => true, 'default' => 'inet',
	    'options' => restapi_schema_choices(array('inet' => 'IPv4', 'inet6' => 'IPv6', 'inet46' => 'IPv4+IPv6')),
	    'errorMatch' => array('ip protocol', 'ipv4 rules', 'ipv6 rules', 'both ipv4 and ipv6'));
	$rule[] = array('name' => 'proto', 'type' => 'select', 'label' => gettext('Protocol'), 'required' => true, 'default' => 'tcp', 'width' => 'half',
	    'options' => restapi_schema_choices(function_exists('get_ipprotocols') ? get_ipprotocols() : array()),
	    'errorMatch' => array('only valid with protocol tcp', 'only be specified for tcp protocol'));
	$rule[] = array('name' => 'icmptype', 'type' => 'checklist', 'label' => gettext('ICMP subtypes'),
	    'options' => restapi_schema_choices((array)$icmptypes4), 'visibleWhen' => array('field' => 'proto', 'equals' => 'icmp'),
	    'help' => gettext('None or "any" matches every subtype.'), 'errorMatch' => array('icmp subtype', 'icmp types'));

	$sections = array(
		array('id' => 'rule', 'title' => gettext('Rule'), 'fields' => $rule),
		restapi_schema_rule_endpoint('src', $floating),
		restapi_schema_rule_endpoint('dst', $floating),
		array('id' => 'extra', 'title' => gettext('Description and logging'), 'fields' => array(
			array('name' => 'descr', 'type' => 'text', 'label' => gettext('Description'), 'maxLength' => 52,
			    'help' => gettext('Shown in the rule list and in the firewall log.')),
			array('name' => 'log', 'type' => 'switch', 'label' => gettext('Log'), 'text' => gettext('Log packets matched by this rule')),
		)),
		array('id' => 'routing', 'title' => gettext('Routing and schedule'), 'advanced' => true, 'fields' => array(
			array('name' => 'gateway', 'type' => 'select', 'label' => gettext('Gateway'), 'width' => 'half', 'options' => restapi_schema_choices($gateways),
			    'help' => gettext('Default uses the routing table; a gateway or group routes matching traffic by policy (not for IPv4+IPv6).'),
			    'errorMatch' => array('gateways can not', 'gateway group', 'an ipv4 gateway', 'an ipv6 gateway')),
			array('name' => 'sched', 'type' => 'select', 'label' => gettext('Schedule'), 'width' => 'half', 'options' => restapi_schema_choices($schedules),
			    'help' => gettext('With a schedule the rule only applies in its time ranges.')),
		)),
		array('id' => 'advanced', 'title' => gettext('Advanced options'), 'advanced' => true,
		    'description' => gettext('State tracking, limits and tags. The defaults suit almost every rule.'), 'fields' => array(
			array('name' => 'statetype', 'type' => 'select', 'label' => gettext('State type'), 'width' => 'half', 'default' => 'keep state',
			    'options' => restapi_schema_choices((array)$statetype_values), 'errorMatch' => array('is only valid with protocol', 'is only valid if the gateway')),
			array('name' => 'statepolicy', 'type' => 'select', 'label' => gettext('State policy'), 'width' => 'half',
			    'options' => restapi_schema_choices((array)$statepolicy_values)),
			array('name' => 'max', 'type' => 'number', 'label' => gettext('Max. states'), 'min' => 1, 'width' => 'third',
			    'errorMatch' => array('maximum state entries (advanced')),
			array('name' => 'max-src-nodes', 'type' => 'number', 'label' => gettext('Max. source hosts'), 'min' => 1, 'width' => 'third',
			    'errorMatch' => array('unique source hosts')),
			array('name' => 'max-src-states', 'type' => 'number', 'label' => gettext('Max. states per host'), 'min' => 1, 'width' => 'third',
			    'errorMatch' => array('state entries per host')),
			array('name' => 'max-src-conn', 'type' => 'number', 'label' => gettext('Max. connections per host'), 'min' => 1, 'width' => 'third',
			    'errorMatch' => array('established connections per host')),
			array('name' => 'max-src-conn-rate', 'type' => 'number', 'label' => gettext('New connections'), 'min' => 1, 'width' => 'third',
			    'errorMatch' => array('new connections per host')),
			array('name' => 'max-src-conn-rates', 'type' => 'number', 'label' => gettext('Per seconds'), 'min' => 1, 'width' => 'third'),
			array('name' => 'statetimeout', 'type' => 'number', 'label' => gettext('State timeout'), 'unit' => gettext('seconds'), 'min' => 1, 'width' => 'third'),
			array('name' => 'tag', 'type' => 'text', 'label' => gettext('Tag'), 'mono' => true, 'width' => 'third'),
			array('name' => 'tagged', 'type' => 'text', 'label' => gettext('Tagged'), 'mono' => true, 'width' => 'third'),
			array('name' => 'nottagged', 'type' => 'switch', 'label' => gettext('Invert tagged'), 'width' => 'third',
			    'errorMatch' => array('invert tagged')),
			array('name' => 'os', 'type' => 'select', 'label' => gettext('Source OS'), 'width' => 'third', 'options' => restapi_schema_choices($os),
			    'visibleWhen' => array('field' => 'proto', 'equals' => 'tcp'), 'errorMatch' => array('os detection')),
			array('name' => 'dscp', 'type' => 'select', 'label' => gettext('Diffserv code point'), 'width' => 'third', 'options' => restapi_schema_choices($dscp)),
			array('name' => 'vlanprio', 'type' => 'select', 'label' => gettext('VLAN priority'), 'width' => 'third',
			    'options' => restapi_schema_choices(array('' => gettext('none')) + (array)$vlanprio)),
			array('name' => 'vlanprioset', 'type' => 'select', 'label' => gettext('Set VLAN priority'), 'width' => 'third',
			    'options' => restapi_schema_choices(array('' => gettext('none')) + (array)$vlanprio)),
			array('name' => 'allowopts', 'type' => 'switch', 'label' => gettext('IP options'), 'text' => gettext('Allow packets with IP options'), 'width' => 'half'),
			array('name' => 'disablereplyto', 'type' => 'switch', 'label' => gettext('Reply-to'), 'text' => gettext('Disable the automatic reply-to'), 'width' => 'half'),
			array('name' => 'nopfsync', 'type' => 'switch', 'label' => gettext('No pfsync'), 'text' => gettext('Do not sync this rule\'s states over pfsync'), 'width' => 'half'),
			array('name' => 'nosync', 'type' => 'switch', 'label' => gettext('No XMLRPC sync'), 'text' => gettext('Do not copy this rule to HA peers'), 'width' => 'half'),
		)),
	);
	return array(
		'title' => $floating ? gettext('Floating rule') : gettext('Firewall rule'),
		'summary' => gettext('{type} {proto} from {srctype} to {dsttype}'),
		'summaryIcon' => 'shield-halved',
		'sections' => $sections,
		'readonlyWhen' => array('field' => 'associated-rule-id', 'truthy' => true),
		'readonlyText' => gettext('This rule belongs to a port forward. Edit the NAT rule instead.'),
	);
}

function restapi_schema_firewall_rules() {
	return restapi_schema_firewall_rule(false);
}

function restapi_schema_firewall_floating_rules() {
	return restapi_schema_firewall_rule(true);
}
