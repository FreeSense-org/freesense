<?php
/*
 * interfaces_config.inc
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
 * Schema of one interface's configuration (GET/PUT
 * /api/v1/interfaces/config/{name}). Field names match the API. The
 * configuration types and prefix delegation sizes are the page's fixed
 * lists; the gateway, track interface and media choices depend on the
 * interface, so those selects name the GET field that holds them in
 * "optionsFrom" (an object of value => label) and accept other values.
 *
 * Labels follow the page's validation messages so restapi_errors_to_fields()
 * keys them; "errorMatch" adds the page's other wordings. Messages about PPP
 * and wireless settings (read-only here) stay in the error summary.
 */
function restapi_schema_interfaces_config() {
	$choices = function ($pairs) {
		$out = array();
		foreach ((array)$pairs as $value => $label) {
			$out[] = array('value' => (string)$value, 'label' => (string)$label);
		}
		return $out;
	};
	$types4 = $choices(array('none' => gettext('None'), 'staticv4' => gettext('Static IPv4'), 'dhcp' => gettext('DHCP'),
	    'ppp' => gettext('PPP'), 'pppoe' => gettext('PPPoE'), 'pptp' => gettext('PPTP'), 'l2tp' => gettext('L2TP')));
	$types6 = $choices(array('none' => gettext('None'), 'staticv6' => gettext('Static IPv6'), 'dhcp6' => gettext('DHCP6'),
	    'slaac' => gettext('SLAAC'), '6rd' => gettext('6rd Tunnel'), '6to4' => gettext('6to4 Tunnel'), 'track6' => gettext('Track Interface')));
	$pd_len = $choices(array('none' => 'None', 16 => '48', 15 => '49', 14 => '50', 13 => '51', 12 => '52', 11 => '53', 10 => '54',
	    9 => '55', 8 => '56', 7 => '57', 6 => '58', 5 => '59', 4 => '60', 3 => '61', 2 => '62', 1 => '63', 0 => '64'));
	$v4len = $choices(array_combine(range(0, 32), range(0, 32)));
	$from = function ($field) {
		return sprintf(gettext('The choices are "%s" of the interface (GET /api/v1/interfaces/config/{name}).'), $field);
	};
	$type4 = function ($value) {
		return array('field' => 'ipv4_type', 'equals' => $value);
	};
	$type6 = function ($value) {
		return array('field' => 'ipv6_type', 'equals' => $value);
	};

	return array(
		'title' => gettext('Interface'),
		'order' => array('general', 'ipv4', 'ipv6', 'dhcp', 'reserved'),
		'sections' => array(
			array('id' => 'general', 'title' => gettext('General'), 'fields' => array(
				array('name' => 'enable', 'type' => 'switch', 'label' => gettext('Enable'), 'text' => gettext('Enable interface')),
				array('name' => 'description', 'type' => 'text', 'label' => gettext('Description'), 'required' => true,
				    'errorMatch' => array('interface name', 'interface descriptions', 'interface description', 'alias with the name',
				    'interface group with the name'),
				    'help' => gettext('A name for the interface: letters, digits and underscores (other characters are removed).')),
				array('name' => 'ipv4_type', 'type' => 'select', 'label' => gettext('IPv4 configuration type'), 'options' => $types4, 'width' => 'half',
				    'errorMatch' => array('static IP configuration', 'IPv4 VIPs'),
				    'help' => gettext('PPP, PPPoE, PPTP and L2TP are configured on the Interfaces page.')),
				array('name' => 'ipv6_type', 'type' => 'select', 'label' => gettext('IPv6 configuration type'), 'options' => $types6, 'width' => 'half',
				    'errorMatch' => array('static IPv6 configuration', 'IPv6 VIPs', 'configured as 6to4', 'must be reassigned')),
				array('name' => 'spoof_mac', 'type' => 'text', 'label' => gettext('MAC address'), 'mono' => true, 'placeholder' => 'xx:xx:xx:xx:xx:xx',
				    'help' => gettext('Change ("spoof") the MAC address of this interface; leave empty to use the hardware address.')),
				array('name' => 'mtu', 'type' => 'number', 'label' => gettext('MTU'), 'min' => 576, 'max' => 9000, 'width' => 'third',
				    'help' => gettext('Empty: the adapter\'s default (usually 1500).')),
				array('name' => 'mss', 'type' => 'number', 'label' => gettext('MSS'), 'min' => 576, 'max' => 65535, 'width' => 'third',
				    'help' => gettext('Clamp the TCP MSS to this value minus 40 (IPv4) or 60 (IPv6).')),
				array('name' => 'media', 'type' => 'select', 'label' => gettext('Speed and duplex'), 'width' => 'third', 'strict' => false,
				    'options' => array(array('value' => '', 'label' => gettext('Default (autoselect)'))), 'optionsFrom' => 'media_choices',
				    'help' => $from('media_choices')),
			)),
			array('id' => 'ipv4', 'title' => gettext('IPv4'),
			    'visibleWhen' => array('field' => 'ipv4_type', 'in' => array('staticv4')), 'fields' => array(
				array('name' => 'ipv4_address', 'type' => 'text', 'label' => gettext('IPv4 address'), 'mono' => true, 'required' => true,
				    'width' => 'two-thirds', 'visibleWhen' => $type4('staticv4')),
				array('name' => 'ipv4_subnet', 'type' => 'number', 'label' => gettext('Subnet bit count'), 'min' => 1, 'max' => 32,
				    'width' => 'third', 'required' => true, 'visibleWhen' => $type4('staticv4'), 'errorMatch' => array('IPv4 subnet')),
				array('name' => 'gateway', 'type' => 'select', 'label' => gettext('IPv4 upstream gateway'), 'strict' => false,
				    'options' => array(array('value' => 'none', 'label' => gettext('None'))), 'optionsFrom' => 'gateway_choices',
				    'visibleWhen' => $type4('staticv4'), 'errorMatch' => array('Gateway', 'IPv4 gateway'),
				    'help' => gettext('An Internet connection needs its gateway; on a local network interface use "None".') . ' ' . $from('gateway_choices')),
			)),
			array('id' => 'ipv6', 'title' => gettext('IPv6'),
			    'visibleWhen' => array('field' => 'ipv6_type', 'in' => array('staticv6', 'dhcp6', 'slaac', '6rd', 'track6')), 'fields' => array(
				array('name' => 'ipv6_address', 'type' => 'text', 'label' => gettext('IPv6 address'), 'mono' => true, 'required' => true,
				    'width' => 'two-thirds', 'visibleWhen' => $type6('staticv6'), 'errorMatch' => array('IPv6 link local addresses')),
				array('name' => 'ipv6_subnet', 'type' => 'number', 'label' => gettext('IPv6 prefix length'), 'min' => 1, 'max' => 128,
				    'width' => 'third', 'required' => true, 'visibleWhen' => $type6('staticv6')),
				array('name' => 'gatewayv6', 'type' => 'select', 'label' => gettext('IPv6 upstream gateway'), 'strict' => false,
				    'options' => array(array('value' => 'none', 'label' => gettext('None'))), 'optionsFrom' => 'gatewayv6_choices',
				    'visibleWhen' => $type6('staticv6'), 'errorMatch' => array('IPv6 gateway'), 'help' => $from('gatewayv6_choices')),
				array('name' => 'ipv6_use_ipv4_link', 'type' => 'switch', 'label' => gettext('Use IPv4 connectivity as parent interface'),
				    'text' => gettext('IPv6 uses the IPv4 link (PPPoE)'), 'visibleWhen' => $type6('staticv6')),
				array('name' => 'dhcp6_prefix_delegation_size', 'type' => 'select', 'label' => gettext('DHCPv6 prefix delegation size'),
				    'options' => $pd_len, 'width' => 'half', 'visibleWhen' => $type6('dhcp6'),
				    'help' => gettext('The delegated prefix length the ISP provides (the value is the number of bits below /64).')),
				array('name' => 'dhcp6_send_prefix_hint', 'type' => 'switch', 'label' => gettext('Send IPv6 prefix hint'),
				    'text' => gettext('Ask for the prefix delegation size above'), 'visibleWhen' => $type6('dhcp6')),
				array('name' => 'dhcp6_use_ipv4', 'type' => 'switch', 'label' => gettext('Request over IPv4 connectivity'),
				    'text' => gettext('Request a prefix and information through the IPv4 link'), 'visibleWhen' => $type6('dhcp6')),
				array('name' => 'dhcp6_prefix_only', 'type' => 'switch', 'label' => gettext('Request only an IPv6 prefix'),
				    'text' => gettext('Do not request an IPv6 address'), 'visibleWhen' => $type6('dhcp6')),
				array('name' => 'dhcp6_without_ra', 'type' => 'switch', 'label' => gettext('Do not wait for a router advertisement'),
				    'text' => gettext('Required by some ISPs, especially without PPPoE'), 'visibleWhen' => $type6('dhcp6')),
				array('name' => 'slaac_use_ipv4', 'type' => 'switch', 'label' => gettext('Use IPv4 link for SLAAC'),
				    'text' => gettext('IPv6 uses the IPv4 link (PPPoE)'), 'visibleWhen' => $type6('slaac')),
				array('name' => 'prefix_6rd', 'type' => 'text', 'label' => gettext('6RD prefix'), 'mono' => true, 'placeholder' => '2001:db8::/32',
				    'visibleWhen' => $type6('6rd')),
				array('name' => 'gateway_6rd', 'type' => 'text', 'label' => gettext('6RD border relay'), 'mono' => true, 'required' => true,
				    'width' => 'two-thirds', 'visibleWhen' => $type6('6rd')),
				array('name' => 'prefix_6rd_v4_length', 'type' => 'select', 'label' => gettext('6RD IPv4 prefix length'), 'options' => $v4len,
				    'width' => 'third', 'visibleWhen' => $type6('6rd')),
				array('name' => 'track6_interface', 'type' => 'select', 'label' => gettext('Track IPv6 interface'), 'strict' => false,
				    'options' => array(), 'optionsFrom' => 'track6_interface_choices', 'required' => true, 'width' => 'half',
				    'visibleWhen' => $type6('track6'), 'errorMatch' => array('interface to track'),
				    'help' => gettext('The dynamic IPv6 interface (DHCP6, 6rd, 6to4) whose delegated prefix this interface uses.') . ' ' .
				    $from('track6_interface_choices')),
				array('name' => 'track6_prefix_id', 'type' => 'text', 'label' => gettext('IPv6 prefix ID'), 'mono' => true, 'width' => 'half',
				    'visibleWhen' => $type6('track6'), 'errorMatch' => array('track6 prefix ID'),
				    'help' => gettext('Hexadecimal, from 0 to "track6_prefix_id_max" of the tracked interface.')),
			)),
			array('id' => 'dhcp', 'title' => gettext('DHCP client'), 'advanced' => true,
			    'visibleWhen' => $type4('dhcp'), 'fields' => array(
				array('name' => 'dhcp_hostname', 'type' => 'text', 'label' => gettext('Hostname'), 'width' => 'half',
				    'help' => gettext('Sent as the DHCP client identifier and hostname (some ISPs require it).')),
				array('name' => 'dhcp_alias_address', 'type' => 'text', 'label' => gettext('Alias IPv4 address'), 'mono' => true,
				    'width' => 'third', 'errorMatch' => array('alias IP address')),
				array('name' => 'dhcp_alias_subnet', 'type' => 'number', 'label' => gettext('Alias subnet bit count'), 'min' => 1, 'max' => 32,
				    'width' => 'third', 'errorMatch' => array('alias subnet bit count')),
				array('name' => 'dhcp_reject_from', 'type' => 'text', 'label' => gettext('Reject leases from'), 'mono' => true,
				    'help' => gettext('DHCP servers whose offers are rejected, separated by commas (e.g. a cable modem).')),
			)),
			array('id' => 'reserved', 'title' => gettext('Reserved networks'), 'fields' => array(
				array('name' => 'block_private', 'type' => 'switch', 'label' => gettext('Block private networks'),
				    'text' => gettext('Block RFC 1918, RFC 4193 and loopback sources (WAN interfaces)')),
				array('name' => 'block_bogons', 'type' => 'switch', 'label' => gettext('Block bogon networks'),
				    'text' => gettext('Block reserved and unassigned sources (WAN interfaces)')),
			)),
		),
	);
}
