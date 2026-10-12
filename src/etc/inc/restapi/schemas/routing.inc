<?php
/*
 * routing.inc
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
 * Schemas of System > Routing (POST/PUT /api/v1/routing/...). Field names
 * are the edit pages' form fields, which the API takes (gateway groups:
 * the API's "items" rows of gateway, tier and VIP, as the group edit page
 * lists them); choices come from the same functions as the pages.
 *
 *   routing/gateways          a gateway (system_gateways_edit.php)
 *   routing/gateway_groups    a gateway group (system_gateway_groups_edit.php)
 *   routing/static_routes     a static route (system_routes_edit.php)
 *   routing/default_gateways  the default gateway selectors (system_gateways.php)
 */

/* The dpinger defaults the gateway page shows as placeholders. */
function restapi_schema_dpinger_defaults() {
	if (function_exists('return_dpinger_defaults')) {
		return return_dpinger_defaults();
	}
	return array('latencylow' => '200', 'latencyhigh' => '500', 'losslow' => '10', 'losshigh' => '20', 'interval' => '500',
	    'loss_interval' => '2000', 'time_period' => '60000', 'alert_interval' => '1000', 'data_payload' => '1');
}

function restapi_schema_routing_gateways() {
	global $gateway_state_kill_modes;
	$ifaces = function_exists('get_configured_interface_with_descr') ? (array)get_configured_interface_with_descr(true) : array();
	$kill = is_array($gateway_state_kill_modes ?? null) ? $gateway_state_kill_modes : array('' => gettext('Use global behavior (default)'));
	$d = restapi_schema_dpinger_defaults();
	$monitored = array('field' => 'monitor_disable', 'truthy' => false);
	$ms = gettext('ms');

	return array(
		'title' => gettext('Gateway'),
		'summary' => gettext('{name} on {interface}[ via {gateway}]'),
		'summaryIcon' => 'route',
		'sections' => array(
			array('id' => 'gateway', 'title' => gettext('Gateway'), 'fields' => array(
				array('name' => 'disabled', 'type' => 'switch', 'label' => gettext('Disabled'), 'text' => gettext('Disable this gateway'),
				    'help' => gettext('Keep the gateway without using it. A gateway a group, static route or DNS server uses cannot be disabled.'),
				    'errorMatch' => array('cannot be disabled')),
				array('name' => 'interface', 'type' => 'select', 'label' => gettext('Interface'), 'required' => true, 'width' => 'half',
				    'options' => restapi_schema_choices($ifaces), 'help' => gettext('The interface the gateway is reached through.')),
				array('name' => 'ipprotocol', 'type' => 'segmented', 'label' => gettext('Address family'), 'required' => true, 'default' => 'inet',
				    'width' => 'half', 'options' => restapi_schema_choices(array('inet' => 'IPv4', 'inet6' => 'IPv6'))),
				array('name' => 'name', 'type' => 'text', 'label' => gettext('Name'), 'required' => true, 'mono' => true, 'width' => 'half',
				    'maxLength' => 31, 'help' => gettext('Letters, digits and underscores. The name of an existing gateway cannot change.'),
				    'errorMatch' => array('gateway name', 'changing name')),
				array('name' => 'gateway', 'type' => 'text', 'label' => gettext('Gateway'), 'mono' => true, 'width' => 'half',
				    'placeholder' => '192.0.2.1',
				    'help' => gettext('The gateway\'s IP address; "dynamic" for a gateway the interface learns (DHCP, PPP...).'),
				    'errorMatch' => array('gateway ip address', 'gateway address', 'dynamic gateway values')),
				array('name' => 'descr', 'type' => 'text', 'label' => gettext('Description'),
				    'help' => gettext('For your reference (not parsed).')),
			)),
			array('id' => 'monitoring', 'title' => gettext('Monitoring'), 'fields' => array(
				array('name' => 'monitor_disable', 'type' => 'switch', 'label' => gettext('Gateway monitoring'),
				    'text' => gettext('Disable gateway monitoring'), 'help' => gettext('The gateway is then always considered up.'), 'width' => 'half'),
				array('name' => 'action_disable', 'type' => 'switch', 'label' => gettext('Gateway action'),
				    'text' => gettext('Disable the monitoring action'),
				    'help' => gettext('No action is taken on gateway events; the gateway is always considered up.'), 'width' => 'half'),
				array('name' => 'monitor', 'type' => 'text', 'label' => gettext('Monitor IP'), 'mono' => true, 'width' => 'half',
				    'visibleWhen' => $monitored,
				    'help' => gettext('An address to ping instead of the gateway, e.g. when the gateway does not answer ICMP echo requests. Empty: the gateway itself.'),
				    'errorMatch' => array('monitor address', 'monitor ip address')),
				array('name' => 'force_down', 'type' => 'switch', 'label' => gettext('Force state'), 'text' => gettext('Mark the gateway as down'),
				    'width' => 'half'),
				array('name' => 'dpinger_dont_add_static_route', 'type' => 'switch', 'label' => gettext('Static route'),
				    'text' => gettext('Do not add a static route for the monitor IP'),
				    'help' => gettext('By default traffic to the monitor IP is routed through the chosen interface.')),
				array('name' => 'gw_down_kill_states', 'type' => 'select', 'label' => gettext('State killing on gateway failure'), 'default' => '',
				    'options' => restapi_schema_choices($kill),
				    'help' => gettext('Only states of policy routing rules and reply-to; no effect while monitoring or its action is off or the gateway is forced down.')),
			)),
			array('id' => 'advanced', 'title' => gettext('Advanced'), 'advanced' => true,
			    'description' => gettext('Weight in gateway groups and the monitoring thresholds. The time period must exceed twice the probe interval plus the loss interval; the alert interval must be at least the probe interval and the loss interval at least the high latency threshold.'),
			    'fields' => array(
				array('name' => 'weight', 'type' => 'select', 'label' => gettext('Weight'), 'default' => '1', 'width' => 'third',
				    'options' => restapi_schema_choices(array_combine(range(1, 30), range(1, 30))),
				    'help' => gettext('Share of the connections in a gateway group.')),
				array('name' => 'data_payload', 'type' => 'number', 'label' => gettext('Data payload'), 'min' => 0, 'width' => 'third',
				    'placeholder' => (string)$d['data_payload'], 'unit' => gettext('bytes')),
				array('name' => 'nonlocalgateway', 'type' => 'switch', 'label' => gettext('Use non-local gateway'), 'width' => 'third',
				    'text' => gettext('Through an interface route'),
				    'help' => gettext('Allows a gateway outside the interface\'s subnets; usually a configuration error.')),
				array('name' => 'latencylow', 'type' => 'number', 'label' => gettext('Low latency threshold'), 'min' => 0, 'unit' => $ms,
				    'width' => 'half', 'placeholder' => (string)$d['latencylow']),
				array('name' => 'latencyhigh', 'type' => 'number', 'label' => gettext('High latency threshold'), 'min' => 0, 'unit' => $ms,
				    'width' => 'half', 'placeholder' => (string)$d['latencyhigh']),
				array('name' => 'losslow', 'type' => 'number', 'label' => gettext('Low packet loss threshold'), 'min' => 0, 'max' => 100,
				    'unit' => '%', 'width' => 'half', 'placeholder' => (string)$d['losslow']),
				array('name' => 'losshigh', 'type' => 'number', 'label' => gettext('High packet loss threshold'), 'min' => 0, 'max' => 100,
				    'unit' => '%', 'width' => 'half', 'placeholder' => (string)$d['losshigh']),
				array('name' => 'interval', 'type' => 'number', 'label' => gettext('Probe interval'), 'min' => 1, 'max' => 3600000, 'unit' => $ms,
				    'width' => 'half', 'placeholder' => (string)$d['interval']),
				array('name' => 'loss_interval', 'type' => 'number', 'label' => gettext('Loss interval'), 'min' => 1, 'unit' => $ms,
				    'width' => 'half', 'placeholder' => (string)$d['loss_interval'], 'errorMatch' => array('loss interval needs to be greater')),
				array('name' => 'time_period', 'type' => 'number', 'label' => gettext('Time period'), 'min' => 1, 'unit' => $ms,
				    'width' => 'half', 'placeholder' => (string)$d['time_period'], 'help' => gettext('Results are averaged over this period.'),
				    'errorMatch' => array('time period needs to be greater')),
				array('name' => 'alert_interval', 'type' => 'number', 'label' => gettext('Alert interval'), 'min' => 1, 'unit' => $ms,
				    'width' => 'half', 'placeholder' => (string)$d['alert_interval'], 'errorMatch' => array('alert interval needs to be greater')),
			)),
		),
	);
}

/* Every VIP a gateway group member may use, by value: the union of build_vip_list() over the gateways, grouped by gateway. */
function restapi_schema_gwgroup_vips(array $gateways) {
	$out = array(array('value' => 'address', 'label' => gettext('Interface Address')));
	$seen = array('address' => true);
	if (!function_exists('build_vip_list')) {
		return $out;
	}
	foreach ($gateways as $name => $gw) {
		foreach ((array)build_vip_list($gw['friendlyiface'] ?? '', $gw['ipprotocol'] ?? 'all') as $value => $label) {
			if (!isset($seen[$value])) {
				$seen[$value] = true;
				$out[] = array('value' => (string)$value, 'label' => (string)$label, 'group' => (string)$name);
			}
		}
	}
	return $out;
}

function restapi_schema_routing_gateway_groups() {
	$gateways = function_exists('get_gateways') ? (array)get_gateways() : array();
	$members = array();
	foreach ($gateways as $name => $gw) {
		$members[] = array('value' => (string)$name, 'label' => (string)$name,
		    'detail' => trim((string)($gw['gateway'] ?? '') . ' ' . (string)($gw['descr'] ?? '')),
		    'group' => (($gw['ipprotocol'] ?? '') === 'inet6') ? 'IPv6' : 'IPv4');
	}
	$tiers = array();
	for ($t = 1; $t <= 5; $t++) {
		$tiers[$t] = sprintf(gettext('Tier %s'), $t);
	}
	$triggers = function_exists('routing_gateway_group_triggers') ? routing_gateway_group_triggers() : array('down' => gettext('Member Down'));
	$keep = function_exists('routing_gateway_group_keep_states') ? routing_gateway_group_keep_states() : array('' => 'Use global behavior (default)');

	return array(
		'title' => gettext('Gateway group'),
		'summary' => gettext('{name}: {items}'),
		'summaryIcon' => 'route',
		'sections' => array(
			array('id' => 'group', 'title' => gettext('Gateway group'), 'fields' => array(
				array('name' => 'name', 'type' => 'text', 'label' => gettext('Group name'), 'required' => true, 'mono' => true, 'width' => 'half',
				    'maxLength' => 31, 'help' => gettext('Letters, digits and underscores; not the name of a gateway. It cannot change later.'),
				    'errorMatch' => array('field name', 'changing name', 'with this name', 'same name as a gateway')),
				array('name' => 'descr', 'type' => 'text', 'label' => gettext('Description'), 'width' => 'half'),
			)),
			array('id' => 'members', 'title' => gettext('Gateway priority'),
			    'description' => gettext('Members of the same tier share the load; a lower tier is used only when every member of the tiers above is down. All members have the same address family. The virtual IP is the source when the group is the interface of a local Dynamic DNS, IPsec or OpenVPN endpoint.'),
			    'fields' => array(
				array('name' => 'items', 'type' => 'entry-grid', 'label' => gettext('Members'), 'required' => true, 'min' => 1,
				    'addLabel' => gettext('Add gateway'), 'emptyText' => gettext('No gateways in this group yet.'),
				    'errorMatch' => array('gateway(s)'), 'fields' => array(
					array('name' => 'gateway', 'type' => 'select', 'label' => gettext('Gateway'), 'required' => true, 'width' => 'lg',
					    'options' => $members),
					array('name' => 'tier', 'type' => 'select', 'label' => gettext('Tier'), 'required' => true, 'default' => '1', 'width' => 'sm',
					    'options' => restapi_schema_choices($tiers)),
					array('name' => 'vip', 'type' => 'select', 'label' => gettext('Virtual IP'), 'default' => 'address', 'width' => 'md',
					    'strict' => false, 'options' => restapi_schema_gwgroup_vips($gateways)),
				)),
			)),
			array('id' => 'behaviour', 'title' => gettext('Failover'), 'fields' => array(
				array('name' => 'trigger', 'type' => 'select', 'label' => gettext('Trigger level'), 'required' => true, 'default' => 'down',
				    'width' => 'half', 'options' => restapi_schema_choices($triggers), 'help' => gettext('When a member is excluded.')),
				array('name' => 'keep_failover_states', 'type' => 'select', 'label' => gettext('Keep failover states'), 'default' => '',
				    'width' => 'half', 'options' => restapi_schema_choices($keep),
				    'help' => gettext('Kill: when a gateway recovers, states policy routing created on lower-priority gateways are killed.')),
			)),
		),
	);
}

function restapi_schema_routing_static_routes() {
	$gateways = array();
	if (function_exists('get_gateways') && defined('GW_CACHE_DISABLED')) {
		foreach ((array)get_gateways(GW_CACHE_DISABLED | GW_CACHE_LOCALHOST) as $gw) {
			$gateways[$gw['name']] = $gw['name'] . ' - ' . ($gw['gateway'] ?? '');
		}
	}
	return array(
		'title' => gettext('Static route'),
		'summary' => gettext('{network}[/{network_subnet}] via {gateway}'),
		'summaryIcon' => 'route',
		'sections' => array(
			array('id' => 'route', 'title' => gettext('Static route'), 'fields' => array(
				array('name' => 'network', 'type' => 'typeahead', 'label' => gettext('Destination network'), 'required' => true, 'mono' => true,
				    'width' => 'two-thirds', 'placeholder' => gettext('Address or alias'),
				    'source' => array('path' => '/v1/firewall/aliases', 'value' => 'name', 'detail' => 'description'),
				    'help' => gettext('A network address, or a host, network or URL alias.'),
				    'errorMatch' => array('destination networks', 'network conflicts')),
				array('name' => 'network_subnet', 'type' => 'number', 'label' => gettext('Prefix length'), 'min' => 0, 'max' => 128, 'width' => 'third',
				    'errorMatch' => array('destination network bit count', 'subnet can not be over')),
				array('name' => 'gateway', 'type' => 'select', 'label' => gettext('Gateway'), 'required' => true,
				    'options' => restapi_schema_choices($gateways),
				    'help' => gettext('A disabled gateway can only be chosen for a disabled route.'),
				    'errorMatch' => array('gateway is disabled', 'different address family')),
				array('name' => 'disabled', 'type' => 'switch', 'label' => gettext('Disabled'), 'text' => gettext('Disable this static route')),
				array('name' => 'descr', 'type' => 'text', 'label' => gettext('Description'), 'help' => gettext('For your reference (not parsed).')),
			)),
		),
	);
}

function restapi_schema_routing_default_gateways() {
	$d = function_exists('available_default_gateways') ? available_default_gateways() :
	    array('v4' => array('' => 'Automatic', '-' => 'None'), 'v6' => array('' => 'Automatic', '-' => 'None'));
	$help = gettext('A gateway or failover gateway group to use as the default gateway. Automatic lets the system choose; None sets no default route.');
	return array(
		'title' => gettext('Default gateway'),
		'sections' => array(
			array('id' => 'default', 'title' => gettext('Default gateway'), 'fields' => array(
				array('name' => 'defaultgw4', 'type' => 'select', 'label' => gettext('Default gateway IPv4'), 'default' => '', 'width' => 'half',
				    'options' => restapi_schema_choices($d['v4']), 'help' => $help, 'errorMatch' => array('defaultgw4')),
				array('name' => 'defaultgw6', 'type' => 'select', 'label' => gettext('Default gateway IPv6'), 'default' => '', 'width' => 'half',
				    'options' => restapi_schema_choices($d['v6']), 'help' => $help, 'errorMatch' => array('defaultgw6')),
			)),
		),
	);
}
