<?php
/*
 * firewall_vips.inc
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
 * Schema of a virtual IP (GET /api/v1/firewall/virtual-ips/{id} "fields",
 * POST/PUT /api/v1/firewall/virtual-ips). Field names are the virtual IP
 * edit page's form fields, which the API takes; the choices are the page's
 * (firewall_virtual_ip_edit.php), so the form offers what saveVIP() accepts.
 */

/* The modes in the order of the edit page, with the list page's labels. */
function restapi_vip_modes() {
	return array('ipalias' => gettext('IP Alias'), 'carp' => gettext('CARP'), 'proxyarp' => gettext('Proxy ARP'), 'other' => gettext('Other'));
}

/*
 * Interfaces a virtual IP can sit on, as the VIP pages name them: the
 * assigned interfaces, CARP addresses ("192.0.2.1 (descr)" or "(vhid: 5)";
 * every VIP for the list page, CARP only as edit page parents) and Localhost.
 */
function restapi_vip_interfaces($carp_only = true) {
	$list = function_exists('get_configured_interface_with_descr') ? (array)get_configured_interface_with_descr(true) : array();
	if (function_exists('get_configured_vip_list') && defined('VIP_CARP')) {
		foreach ((array)get_configured_vip_list('all', $carp_only ? VIP_CARP : VIP_ALL) as $vipname => $address) {
			$descr = get_vip_descr($address);
			if (!$descr) {
				$vip = get_configured_vip($vipname);
				$descr = 'vhid: ' . ($vip['vhid'] ?? '');
			}
			$list[$vipname] = "{$address} ({$descr})";
		}
	}
	$list['lo0'] = gettext('Localhost');
	return $list;
}

function restapi_schema_firewall_virtual_ips() {
	$carp = array('field' => 'mode', 'equals' => 'carp');
	$ranged = array('field' => 'mode', 'in' => array('proxyarp', 'other'));
	$vhid = 1;
	if (function_exists('find_last_used_vhid')) {
		$vhid = min(255, (int)find_last_used_vhid() + 1);
	}
	$numbers = function ($from, $to) {
		return restapi_schema_choices(array_combine(range($from, $to), range($from, $to)));
	};

	return array(
		'title' => gettext('Virtual IP'),
		'summary' => gettext('{mode} address {subnet}[/{subnet_bits}] on {interface}'),
		'summaryIcon' => 'network-wired',
		'sections' => array(
			array('id' => 'general', 'title' => gettext('Virtual IP'), 'fields' => array(
				array('name' => 'mode', 'type' => 'segmented', 'label' => gettext('Type'), 'required' => true, 'default' => 'ipalias',
				    'options' => restapi_schema_choices(restapi_vip_modes()),
				    'help' => gettext('Only IP Alias and CARP addresses can be used by services on the firewall (IPsec, OpenVPN, ...). Proxy ARP and Other addresses are for NAT.'),
				    'errorMatch' => array('must stay an ip alias or carp address')),
				array('name' => 'interface', 'type' => 'select', 'label' => gettext('Interface'), 'required' => true, 'width' => 'half',
				    'options' => restapi_schema_choices(restapi_vip_interfaces(true)),
				    'help' => gettext('A CARP address can be the parent of IP Alias addresses only.'),
				    'errorMatch' => array('interface chosen', 'localhost is not allowed', 'carp parent', 'carp parent interface')),
				array('name' => 'descr', 'type' => 'text', 'label' => gettext('Description'), 'width' => 'half',
				    'help' => gettext('For your reference (not parsed).')),
			)),
			array('id' => 'address', 'title' => gettext('Address'), 'fields' => array(
				array('name' => 'type', 'type' => 'select', 'label' => gettext('Address type'), 'width' => 'third', 'default' => 'single',
				    'options' => restapi_schema_choices(array('single' => gettext('Single address'), 'network' => gettext('Network'))),
				    'visibleWhen' => $ranged, 'help' => gettext('IP Alias and CARP addresses are always single addresses.')),
				array('name' => 'subnet', 'type' => 'text', 'label' => gettext('Address'), 'required' => true, 'mono' => true, 'width' => 'third',
				    'placeholder' => '192.0.2.10',
				    'help' => gettext('IP Alias and CARP: the prefix length is that of the network, not a range. Proxy ARP and Other: an address or the start of a CIDR block.'),
				    'errorMatch' => array('ip address', 'is being used by another interface', 'network address', 'broadcast address', 'only ipv4 addresses')),
				array('name' => 'subnet_bits', 'type' => 'number', 'label' => gettext('Prefix length'), 'min' => 1, 'max' => 128, 'width' => 'third',
				    'default' => 32,
				    'visibleWhen' => array('any' => array(array('field' => 'mode', 'in' => array('ipalias', 'carp')),
					array($ranged, array('field' => 'type', 'equals' => 'network'))))),
				array('name' => 'noexpand', 'type' => 'switch', 'label' => gettext('Expansion'),
				    'text' => gettext('Do not expand this network into single addresses in NAT lists'),
				    'visibleWhen' => array($ranged, array('field' => 'type', 'equals' => 'network'))),
			)),
			array('id' => 'carp', 'title' => gettext('CARP'), 'visibleWhen' => $carp,
			    'description' => gettext('Members of a high availability cluster share the address through a VHID group.'), 'fields' => array(
				array('name' => 'vhid', 'type' => 'select', 'label' => gettext('VHID group'), 'required' => true, 'width' => 'third',
				    'default' => (string)$vhid, 'options' => $numbers(1, 255), 'visibleWhen' => $carp,
				    'help' => gettext('The VHID group the cluster members share.'),
				    'errorMatch' => array('is already in use on interface')),
				array('name' => 'advbase', 'type' => 'select', 'label' => gettext('Advertising base'), 'required' => true, 'width' => 'third',
				    'default' => '1', 'options' => $numbers(1, 254), 'visibleWhen' => $carp,
				    'help' => gettext('Seconds between advertisements.')),
				array('name' => 'advskew', 'type' => 'select', 'label' => gettext('Advertising skew'), 'width' => 'third',
				    'default' => '0', 'options' => $numbers(0, 254), 'visibleWhen' => $carp,
				    'help' => gettext('0 usually makes this member the master; the lowest base and skew in the cluster wins.')),
				array('name' => 'password', 'type' => 'secret', 'label' => gettext('Virtual IP password'), 'width' => 'half',
				    'visibleWhen' => $carp, 'autocomplete' => 'new-password',
				    'help' => gettext('Shared by the members of the VHID group. Never shown again; leave it empty to keep the current password.'),
				    'errorMatch' => array('carp password', 'confirm password')),
			)),
		),
	);
}
