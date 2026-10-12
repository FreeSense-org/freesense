<?php
/*
 * services_radvd.inc
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
 * Schema of the router advertisements of one interface (GET/PUT
 * /api/v1/services/router-advertisements/{if}, "fields"). Field names are
 * the Router Advertisement page's form fields as the API takes them; the
 * RA subnets and DNS servers are entry grids (rows {subnet} and {server}).
 * The RA interface choices depend on the interface: the form reads them
 * from the loaded item ("choices.rainterface"). Choices come from the
 * page's functions (services_dhcp.inc).
 */

/* A router mode's short name: the label up to " - RA Flags ...". */
function restapi_radvd_mode_name($label) {
	$parts = explode(' - ', (string)$label, 2);
	return $parts[0];
}

function restapi_schema_services_router_advertisements() {
	$modes = function_exists('radvd_ramode_values') ? radvd_ramode_values() : array('disabled' => gettext('Disabled'));
	$priorities = function_exists('radvd_rapriority_values') ? radvd_rapriority_values() : array('medium' => gettext('Normal'));
	$prefix_override = function_exists('config_path_enabled') && config_path_enabled('system', 'allow_nat64_prefix_override');
	$on = array('field' => 'ramode', 'notEquals' => 'disabled');
	$s = gettext('seconds');

	$ra = array(
		array('name' => 'ramode', 'type' => 'select', 'label' => gettext('Router mode'), 'required' => true, 'default' => 'disabled',
		    'options' => restapi_schema_choices($modes),
		    'help' => gettext('Router Only advertises this router; Unmanaged adds SLAAC; Managed leaves all configuration to DHCPv6; Assisted offers DHCPv6 and SLAAC; Stateless DHCP offers SLAAC with other information through DHCPv6. The DHCPv6 server may be another host.'),
		    'errorMatch' => array('router advertisements can only be enabled')),
		array('name' => 'rapriority', 'type' => 'segmented', 'label' => gettext('Router priority'), 'required' => true, 'default' => 'medium',
		    'width' => 'half', 'options' => restapi_schema_choices($priorities), 'visibleWhen' => $on),
		array('name' => 'rainterface', 'type' => 'select', 'label' => gettext('RA interface'), 'width' => 'half', 'optionsFrom' => 'choices.rainterface',
		    'visibleWhen' => $on, 'help' => gettext('The interface, or one of its IPv6 CARP addresses (only offered when it has one).')),
	);
	$timing = array(
		array('name' => 'ravalidlifetime', 'type' => 'number', 'label' => gettext('Valid lifetime'), 'min' => 1, 'max' => 655350, 'unit' => $s,
		    'width' => 'half', 'placeholder' => '86400', 'help' => gettext('How long the prefix is valid for on-link determination.'),
		    'errorMatch' => array('valid lifetime below', 'default valid lifetime must be greater')),
		array('name' => 'rapreferredlifetime', 'type' => 'number', 'label' => gettext('Preferred lifetime'), 'min' => 1, 'unit' => $s,
		    'width' => 'half', 'placeholder' => '14400', 'help' => gettext('How long SLAAC addresses from the prefix stay preferred.'),
		    'errorMatch' => array('default preferred lifetime')),
		array('name' => 'raminrtradvinterval', 'type' => 'number', 'label' => gettext('Minimum RA interval'), 'min' => 3, 'max' => 1350, 'unit' => $s,
		    'width' => 'third', 'placeholder' => '200', 'errorMatch' => array('minimum advertisement interval')),
		array('name' => 'ramaxrtradvinterval', 'type' => 'number', 'label' => gettext('Maximum RA interval'), 'min' => 4, 'max' => 1800, 'unit' => $s,
		    'width' => 'third', 'placeholder' => '600', 'errorMatch' => array('maximum advertisement interval')),
		array('name' => 'raadvdefaultlifetime', 'type' => 'number', 'label' => gettext('Router lifetime'), 'min' => 0, 'max' => 9000, 'unit' => $s,
		    'width' => 'third', 'help' => gettext('Empty: 3 × the maximum RA interval.')),
		array('name' => 'pref64_enable', 'type' => 'switch', 'label' => gettext('PREF64'), 'text' => gettext('Advertise a NAT64 prefix'), 'width' => 'third'),
		array('name' => 'pref64_lifetime', 'type' => 'number', 'label' => gettext('NAT64 prefix lifetime'), 'min' => 1, 'max' => 65528, 'unit' => $s,
		    'width' => 'third', 'visibleWhen' => array('field' => 'pref64_enable', 'truthy' => true),
		    'help' => gettext('Empty: 3 × the maximum RA interval.')),
	);
	if ($prefix_override) {
		$timing[] = array('name' => 'pref64_prefix', 'type' => 'text', 'label' => gettext('NAT64 prefix'), 'mono' => true, 'width' => 'third',
		    'placeholder' => defined('NAT64_WELLKNOWN_PREFIX_CIDR') ? NAT64_WELLKNOWN_PREFIX_CIDR : '64:ff9b::/96',
		    'visibleWhen' => array('field' => 'pref64_enable', 'truthy' => true), 'help' => gettext('Overrides the advertised NAT64 prefix.'));
	}
	$subnets = array(
		array('name' => 'subnets', 'type' => 'entry-grid', 'label' => gettext('RA subnets'), 'max' => 5000,
		    'addLabel' => gettext('Add subnet'), 'emptyText' => gettext('The subnet of the interface is advertised.'),
		    'errorMatch' => array('invalid subnet'), 'fields' => array(
			array('name' => 'subnet', 'type' => 'text', 'label' => gettext('Subnet or alias'), 'mono' => true, 'required' => true,
			    'placeholder' => '2001:db8:1::/64'),
		)),
	);
	$dns = array(
		array('name' => 'radvd-dns', 'type' => 'switch', 'label' => gettext('DNS'), 'default' => true,
		    'text' => gettext('Provide DNS configuration (RDNSS/DNSSL)'),
		    'help' => gettext('Turning this off may violate some RFCs.')),
		array('name' => 'rasamednsasdhcp6', 'type' => 'switch', 'label' => gettext('Mirror DHCPv6'),
		    'text' => gettext('Copy the DNS configuration of the DHCPv6 server'), 'visibleWhen' => array('field' => 'radvd-dns', 'truthy' => true)),
		array('name' => 'radnsserver', 'type' => 'entry-grid', 'label' => gettext('DNS servers'), 'max' => 4,
		    'addLabel' => gettext('Add DNS server'),
		    'emptyText' => gettext('This interface\'s address when the DNS Resolver or Forwarder is enabled, else the system\'s DNS servers.'),
		    'visibleWhen' => array('field' => 'radvd-dns', 'truthy' => true),
		    'errorMatch' => array('dns servers'), 'fields' => array(
			array('name' => 'server', 'type' => 'text', 'label' => gettext('IPv6 address'), 'mono' => true, 'required' => true),
		)),
		array('name' => 'radomainsearchlist', 'type' => 'text', 'label' => gettext('Domain search list'), 'mono' => true,
		    'placeholder' => 'example.com;sub.example.com', 'help' => gettext('Domains separated by semicolons.'),
		    'visibleWhen' => array('field' => 'radvd-dns', 'truthy' => true)),
	);

	return array(
		'title' => gettext('Router advertisements'),
		'sections' => array(
			array('id' => 'ra', 'title' => gettext('Router advertisement'), 'fields' => $ra),
			array('id' => 'subnets', 'title' => gettext('Subnets'), 'visibleWhen' => $on, 'fields' => $subnets),
			array('id' => 'dns', 'title' => gettext('DNS'), 'visibleWhen' => $on, 'fields' => $dns),
			array('id' => 'timing', 'title' => gettext('Timing and NAT64'), 'advanced' => true, 'visibleWhen' => $on, 'fields' => $timing),
		),
	);
}
