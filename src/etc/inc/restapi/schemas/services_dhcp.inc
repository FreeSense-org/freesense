<?php
/*
 * services_dhcp.inc
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
 * Schemas of the DHCP and DHCPv6 servers (Network > DHCP server in the
 * WebUI 2.0): the server of an interface, its static mappings, the global
 * (Kea) settings and the DHCP and DHCPv6 relays.
 *
 * Field names are the form fields of the 1.x pages (services_dhcp.php,
 * services_dhcp_edit.php, services_dhcpv6.php, services_dhcpv6_edit.php,
 * services_dhcp[v6]_settings.php, services_dhcp[v6]_relay.php), which the
 * API takes; the address lists (DNS, WINS, NTP and relay servers) are entry
 * grids of {address} rows. The choices come from the functions the pages
 * use. FreeSense runs the Kea backend only (dhcp_get_backend()), so the
 * schemas describe the Kea forms; the ISC-only fields are not offered.
 *
 * errorMatch lists the wording of the save functions' input_errors
 * (services_dhcp.inc, services_dhcp_relay.inc) where it differs from the
 * field label, so a 422 lands on the field.
 *
 * GET /api/v1/schema/services/dhcp_server?interface=lan (and dhcpv6_server)
 * adds the interface's hints (usable range, gateway, domain and DNS
 * placeholders); the server items carry the same hints in
 * display.placeholders.
 */

require_once('restapi/schemas/firewall_rules.inc');

/* The "Deny Unknown Clients" choices of the DHCP and DHCPv6 Server pages. */
function restapi_dhcp_denyunknown_choices() {
	return array(
		'disabled' => gettext('Allow all clients'),
		'enabled' => gettext('Allow known clients from any interface'),
		'class' => gettext('Allow known clients from only this interface'),
	);
}

/* The DHCP backends as the pages name them; FreeSense runs Kea only. */
function restapi_dhcp_backend_labels() {
	return array('kea' => gettext('Kea DHCP'), 'isc' => gettext('ISC DHCP'));
}

/* Options of a GUI choice function, or of $fallback when it is not loaded (tests). */
function restapi_dhcp_schema_opts($fn, array $fallback = array(), array $args = array()) {
	return restapi_schema_choices(function_exists($fn) ? (array)call_user_func_array($fn, $args) : $fallback);
}

/*
 * The DNS servers the DHCP pages show as placeholders: the pool's own list
 * when given, else the interface address when the DNS Resolver or
 * Forwarder runs, else the system DNS servers of the family (at most 4).
 */
function restapi_dhcp_dns_hint(array $system_dns, $resolver, $ifip, $v6, array $subnet_dns = array()) {
	$subnet_dns = array_values(array_filter(array_map('strval', $subnet_dns), 'strlen'));
	if (!empty($subnet_dns)) {
		return $subnet_dns;
	}
	if ($resolver) {
		return ((string)$ifip !== '') ? array((string)$ifip) : array();
	}
	$out = array();
	foreach ($system_dns as $dns) {
		if (filter_var((string)$dns, FILTER_VALIDATE_IP, $v6 ? FILTER_FLAG_IPV6 : FILTER_FLAG_IPV4) !== false) {
			$out[] = (string)$dns;
		}
	}
	return array_slice($out, 0, 4);
}

/* "a – b" for a range, or "" when an end is missing. */
function restapi_dhcp_range_text($from, $to) {
	$from = (string)$from;
	$to = (string)$to;
	return (($from === '') || ($to === '')) ? '' : "{$from} – {$to}";
}

/*
 * What the DHCP Server page shows about interface $if besides the form: its
 * subnet, the usable addresses and the placeholders of gateway, domain and
 * DNS servers. Empty values without a static IPv4 subnet.
 */
function restapi_dhcp_iface_hints($if) {
	$out = array('subnet' => '', 'subnet_range' => '', 'range_from' => '', 'range_to' => '', 'gateway' => '', 'domain' => '',
	    'dnsserver' => array());
	if (($if === null) || !function_exists('config_get_path') || !function_exists('gen_subnetv4')) {
		return $out;
	}
	$out['domain'] = (string)config_get_path('system/domain', '');
	$cfgip = config_get_path("interfaces/{$if}/ipaddr");
	$cfgsn = config_get_path("interfaces/{$if}/subnet");
	if (!is_ipaddrv4($cfgip) || empty($cfgsn)) {
		return $out;
	}
	$out['subnet'] = gen_subnet($cfgip, $cfgsn) . '/' . $cfgsn;
	if ((int)$cfgsn < 31) {
		$out['range_from'] = (string)ip_after(gen_subnetv4($cfgip, $cfgsn));
		$out['range_to'] = (string)ip_before(gen_subnetv4_max($cfgip, $cfgsn));
		$out['subnet_range'] = restapi_dhcp_range_text($out['range_from'], $out['range_to']);
	}
	$ifip = (string)get_interface_ip($if);
	$out['gateway'] = $ifip;
	$out['dnsserver'] = restapi_dhcp_dns_hint((array)config_get_path('system/dnsserver', array()),
	    config_path_enabled('dnsmasq') || config_path_enabled('unbound'), $ifip, false);
	return $out;
}

/* The DHCPv6 Server page's counterpart: the prefix and its range, and the domain and DNS placeholders. */
function restapi_dhcp6_iface_hints($if) {
	$out = array('prefix' => '', 'prefix_range' => '', 'domain' => '', 'dnsserver' => array());
	if (($if === null) || !function_exists('config_get_path') || !function_exists('dhcp6_server_prefix')) {
		return $out;
	}
	$out['domain'] = (string)config_get_path('system/domain', '');
	$prefix = dhcp6_server_prefix($if);
	if (is_ipaddrv6($prefix['ip']) && ((string)$prefix['sn'] !== '')) {
		$out['prefix'] = gen_subnetv6($prefix['ip'], $prefix['sn']) . '/' . $prefix['sn'];
		$out['prefix_range'] = restapi_dhcp_range_text(gen_subnetv6($prefix['ip'], $prefix['sn']), gen_subnetv6_max($prefix['ip'], $prefix['sn']));
	}
	$out['dnsserver'] = restapi_dhcp_dns_hint((array)config_get_path('system/dnsserver', array()),
	    config_path_enabled('dnsmasq') || config_path_enabled('unbound'), (string)get_interface_ipv6($if), true);
	return $out;
}

/* The configured interface named by ?interface= of a schema request, else null. */
function restapi_dhcp_schema_iface($query) {
	$if = is_array($query) ? (string)($query['interface'] ?? '') : '';
	if (($if === '') || !function_exists('get_configured_interface_with_descr')) {
		return null;
	}
	return isset(get_configured_interface_with_descr()[$if]) ? $if : null;
}

/* An entry grid of {address} rows (a list of servers), at most $max rows. */
function restapi_dhcp_schema_addr_grid($name, $label, $max, $help, array $hint = array(), array $extra = array()) {
	$col = array('name' => 'address', 'type' => 'text', 'label' => gettext('Address'), 'mono' => true, 'required' => true, 'width' => 'lg');
	if (!empty($hint)) {
		$col['placeholder'] = (string)$hint[0];
		$help = trim($help . ' ' . sprintf(gettext('Default: %s.'), implode(', ', $hint)));
	}
	$grid = array('name' => $name, 'type' => 'entry-grid', 'label' => $label, 'max' => $max, 'reorder' => true,
	    'addLabel' => gettext('Add server'), 'fields' => array($col));
	if ($help !== '') {
		$grid['help'] = $help;
	}
	return $grid + $extra;
}

/* The custom Kea JSON of a form: read-only for users without the Kea custom configuration privilege. */
function restapi_dhcp_schema_custom($section, $family) {
	$editable = function_exists('kea_custom_config_editable') ? (bool)kea_custom_config_editable() : true;
	$help = sprintf(gettext('JSON merged into the "%1$s" section of the generated Kea %2$s configuration: a well formed JSON object without the "%1$s" key itself.'),
	    $section, $family);
	if (!$editable) {
		$help .= ' ' . gettext('Changing it needs the "Kea DHCP custom configuration" privilege.');
	}
	return array('id' => 'custom', 'title' => gettext('Custom configuration'), 'advanced' => true, 'fields' => array(
		array('name' => 'custom_kea_config', 'type' => 'textarea', 'label' => gettext('JSON configuration'), 'code' => true, 'rows' => 8,
		    'readonly' => !$editable, 'help' => $help, 'errorMatch' => array('custom configuration', 'custom kea configuration')),
	));
}

/* The network booting section of the DHCP server and static mapping forms ($first: fields after the switch). */
function restapi_dhcp_schema_netboot(array $first = array()) {
	$on = array('field' => 'netboot', 'truthy' => true);
	$fields = array(array('name' => 'netboot', 'type' => 'switch', 'label' => gettext('Network booting'), 'text' => gettext('Enable network booting')));
	foreach ($first as $f) {
		$fields[] = $f + array('visibleWhen' => $on);
	}
	foreach (array('filename' => gettext('Default BIOS file name'), 'filename32' => gettext('UEFI 32 bit file name'),
	    'filename64' => gettext('UEFI 64 bit file name'), 'filename32arm' => gettext('ARM 32 bit file name'),
	    'filename64arm' => gettext('ARM 64 bit file name')) as $name => $label) {
		$fields[] = array('name' => $name, 'type' => 'text', 'label' => $label, 'mono' => true, 'width' => 'half', 'visibleWhen' => $on);
	}
	$fields[] = array('name' => 'uefihttpboot', 'type' => 'text', 'label' => gettext('UEFI HTTPBoot URL'), 'mono' => true, 'visibleWhen' => $on,
	    'placeholder' => 'http://server/firmware.efi', 'help' => gettext('Format: http://(servername)/(firmwarepath).'));
	$fields[] = array('name' => 'rootpath', 'type' => 'text', 'label' => gettext('Root path'), 'mono' => true, 'visibleWhen' => $on,
	    'help' => gettext('Format: iscsi:(servername):(protocol):(port):(LUN):targetname.'));
	return array('id' => 'netboot', 'title' => gettext('Network booting'), 'advanced' => true,
	    'description' => gettext('Clients boot from the network only when a file name and a boot server are set.'), 'fields' => $fields);
}

/* The TFTP and LDAP sections of the DHCP server and static mapping forms. */
function restapi_dhcp_schema_tftp_ldap() {
	return array(
		array('id' => 'tftp', 'title' => gettext('TFTP'), 'advanced' => true, 'fields' => array(
			array('name' => 'tftp', 'type' => 'text', 'label' => gettext('TFTP server'), 'mono' => true,
			    'help' => gettext('Leave empty to disable. An IP address, host name or URL of the TFTP server.')),
		)),
		array('id' => 'ldap', 'title' => gettext('LDAP'), 'advanced' => true, 'fields' => array(
			array('name' => 'ldap', 'type' => 'text', 'label' => gettext('LDAP server URI'), 'mono' => true,
			    'placeholder' => 'ldap://ldap.example.com/dc=example,dc=com', 'help' => gettext('Leave empty to disable. The full URI of the LDAP server.')),
		)),
	);
}

/* Early DNS registration policy choices of a static mapping, and of a server ($server). */
function restapi_dhcp_schema_policies($server) {
	return $server ?
	    restapi_dhcp_schema_opts('dhcp_server_dnsregpolicy_values', array('default' => gettext('Track server'), 'enable' => gettext('Enable'), 'disable' => gettext('Disable'))) :
	    restapi_dhcp_schema_opts('dhcp_staticmap_dnsregpolicy_values', array('default' => gettext('Track subnet'), 'enable' => gettext('Enable'), 'disable' => gettext('Disable')));
}

/* Hostname of a static mapping (same rules and messages in DHCP and DHCPv6). */
function restapi_dhcp_schema_hostname() {
	return array('name' => 'hostname', 'type' => 'text', 'label' => gettext('Hostname'), 'mono' => true, 'width' => 'half',
	    'help' => gettext('Name of the client without the domain part.'), 'errorMatch' => array('rfc952', 'the domain name part should be omitted'));
}

/* ------------------------------------------------------------------ */
/* DHCP server of an interface                                         */
/* ------------------------------------------------------------------ */

function restapi_schema_services_dhcp_server($query = array()) {
	$hints = restapi_dhcp_iface_hints(restapi_dhcp_schema_iface($query));
	$policy = restapi_dhcp_schema_policies(true);
	$sections = array(
		array('id' => 'general', 'title' => gettext('General'), 'fields' => array(
			array('name' => 'enable', 'type' => 'switch', 'label' => gettext('DHCP server'), 'text' => gettext('Enable the DHCP server on this interface'),
			    'help' => gettext('Cannot be enabled while the DHCP relay is enabled.'),
			    'errorMatch' => array('dhcp relay on the', 'dhcp registration features', 'requires a static ipv4 subnet')),
			array('name' => 'denyunknown', 'type' => 'select', 'label' => gettext('Deny unknown clients'), 'default' => 'disabled', 'width' => 'half',
			    'options' => restapi_schema_choices(restapi_dhcp_denyunknown_choices()),
			    'help' => gettext('Known clients have a static mapping; "only this interface" accepts the mappings of this interface only.')),
			array('name' => 'ignoreclientuids', 'type' => 'switch', 'label' => gettext('Ignore client identifiers'), 'width' => 'half',
			    'text' => gettext('Do not record client identifiers (UID) in leases'),
			    'help' => gettext('For clients that dual-boot with different identifiers but one MAC address. This violates the DHCP specification.')),
			array('name' => 'dnsregpolicy', 'type' => 'select', 'label' => gettext('DNS registration'), 'default' => 'default', 'width' => 'half',
			    'options' => $policy, 'help' => gettext('Overrides the default DNS registration policy of the DHCP settings.')),
			array('name' => 'earlydnsregpolicy', 'type' => 'select', 'label' => gettext('Early DNS registration'), 'default' => 'default', 'width' => 'half',
			    'options' => $policy, 'help' => gettext('Overrides the default early DNS registration policy of the DHCP settings.')),
		)),
		array('id' => 'pool', 'title' => gettext('Primary address pool'),
		    'description' => ($hints['subnet'] !== '') ? sprintf(gettext('Subnet %1$s, usable addresses %2$s.'), $hints['subnet'], $hints['subnet_range']) :
			gettext('The addresses handed out to clients.'),
		    'fields' => array(
			array('name' => 'range_from', 'type' => 'text', 'label' => gettext('Range from'), 'mono' => true, 'width' => 'half',
			    'placeholder' => ($hints['range_from'] !== '') ? $hints['range_from'] : '192.168.1.100',
			    'help' => gettext('Required while the server is enabled. Inside the interface subnet, outside the additional pools and the static mappings.'),
			    'errorMatch' => array('range begin', 'starting subnet range', 'lies outside of the current subnet', 'range is invalid',
				'overlap any static dhcp mappings', 'overlap with virtual ip address', 'within the range configured on another dhcp pool',
				'within the primary address range')),
			array('name' => 'range_to', 'type' => 'text', 'label' => gettext('Range to'), 'mono' => true, 'width' => 'half',
			    'placeholder' => ($hints['range_to'] !== '') ? $hints['range_to'] : '192.168.1.199',
			    'errorMatch' => array('range end', 'ending subnet range')),
		)),
		array('id' => 'servers', 'title' => gettext('Server options'), 'fields' => array(
			restapi_dhcp_schema_addr_grid('winsserver', gettext('WINS servers'), 2, ''),
			restapi_dhcp_schema_addr_grid('dnsserver', gettext('DNS servers'), 4,
			    gettext('Empty: this interface\'s address while the DNS Resolver or Forwarder runs, else the system DNS servers.'), $hints['dnsserver']),
		)),
		array('id' => 'options', 'title' => gettext('Other DHCP options'), 'fields' => array(
			array('name' => 'gateway', 'type' => 'text', 'label' => gettext('Gateway'), 'mono' => true, 'width' => 'half',
			    'placeholder' => $hints['gateway'], 'help' => gettext('Empty: this interface\'s address. "none" hands out no gateway.')),
			array('name' => 'domain', 'type' => 'text', 'label' => gettext('Domain name'), 'mono' => true, 'width' => 'half',
			    'placeholder' => $hints['domain'], 'help' => gettext('Empty: the domain of this firewall.'), 'errorMatch' => array('for the dns domain')),
			array('name' => 'domainsearchlist', 'type' => 'text', 'label' => gettext('Domain search list'), 'mono' => true,
			    'placeholder' => 'example.com;sub.example.com', 'help' => gettext('Domains separated by semicolons.')),
			array('name' => 'deftime', 'type' => 'number', 'label' => gettext('Default lease time'), 'unit' => gettext('seconds'), 'min' => 60,
			    'width' => 'half', 'placeholder' => '7200', 'help' => gettext('For clients that do not ask for a lease time. Default 7200.'),
			    'errorMatch' => array('the default lease time must', 'than default lease time')),
			array('name' => 'maxtime', 'type' => 'number', 'label' => gettext('Maximum lease time'), 'unit' => gettext('seconds'), 'min' => 60,
			    'width' => 'half', 'placeholder' => '86400', 'help' => gettext('For clients that ask for a lease time. Default 86400.'),
			    'errorMatch' => array('the maximum lease time must')),
			array('name' => 'staticarp', 'type' => 'switch', 'label' => gettext('Static ARP'), 'text' => gettext('Enable static ARP entries'),
			    'help' => gettext('Only hosts with a static mapping (IP and MAC address) can reach the firewall on this interface, even with the DHCP server off.')),
		)),
		array('id' => 'mac', 'title' => gettext('MAC address control'), 'advanced' => true, 'fields' => array(
			array('name' => 'mac_allow', 'type' => 'text', 'label' => gettext('MAC allow'), 'mono' => true,
			    'help' => gettext('Full or partial MAC addresses, comma separated without spaces. Any other MAC is denied.')),
			array('name' => 'mac_deny', 'type' => 'text', 'label' => gettext('MAC deny'), 'mono' => true,
			    'help' => gettext('Full or partial MAC addresses, comma separated without spaces. Any other MAC is allowed.')),
		)),
		array('id' => 'ntp', 'title' => gettext('NTP'), 'advanced' => true, 'fields' => array(
			restapi_dhcp_schema_addr_grid('ntpserver', gettext('NTP servers'), 4, ''),
		)),
	);
	$sections = array_merge($sections, restapi_dhcp_schema_tftp_ldap(), array(
		restapi_dhcp_schema_netboot(array(array('name' => 'nextserver', 'type' => 'text', 'label' => gettext('Next server'), 'mono' => true,
		    'width' => 'half', 'help' => gettext('IPv4 address of the boot server.'), 'errorMatch' => array('network boot server')))),
		restapi_dhcp_schema_custom('subnet', 'DHCPv4'),
	));
	return array(
		'title' => gettext('DHCP server'),
		'summary' => gettext('[Range {range_from} – {range_to}]'),
		'summaryIcon' => 'network-wired',
		'sections' => $sections,
	);
}

/* ------------------------------------------------------------------ */
/* DHCP static mapping                                                 */
/* ------------------------------------------------------------------ */

function restapi_schema_services_dhcp_static_mapping($query = array()) {
	$sections = array(
		array('id' => 'mapping', 'title' => gettext('Static mapping'), 'fields' => array(
			array('name' => 'mac', 'type' => 'text', 'label' => gettext('MAC address'), 'mono' => true, 'width' => 'half',
			    'placeholder' => 'xx:xx:xx:xx:xx:xx', 'help' => gettext('MAC address of the client (6 hex octets separated by colons).'),
			    'errorMatch' => array('either mac address or client identifier', 'mac address or client identifier already exists')),
			array('name' => 'cid', 'type' => 'text', 'label' => gettext('Client identifier'), 'mono' => true, 'width' => 'half',
			    'help' => gettext('Optional identifier the client sends (RFC 2132). With both set, Kea matches the MAC address.')),
			array('name' => 'ipaddr', 'type' => 'text', 'label' => gettext('IP address'), 'mono' => true, 'width' => 'half',
			    'help' => gettext('IPv4 address for this client, outside every address pool. Empty: an address from a pool.'),
			    'errorMatch' => array('a valid ipv4 address must be specified.', 'ipv4 address must be specified for use with static arp')),
			array('name' => 'arp_table_static_entry', 'type' => 'switch', 'label' => gettext('Static ARP entry'), 'width' => 'half',
			    'text' => gettext('Create a static ARP entry for this MAC and IP address')),
			restapi_dhcp_schema_hostname(),
			array('name' => 'descr', 'type' => 'text', 'label' => gettext('Description'), 'width' => 'half',
			    'help' => gettext('For your reference (not parsed).')),
			array('name' => 'earlydnsregpolicy', 'type' => 'select', 'label' => gettext('Early DNS registration'), 'default' => 'default', 'width' => 'half',
			    'options' => restapi_dhcp_schema_policies(false), 'help' => gettext('Overrides the early DNS registration policy of the subnet.')),
		)),
		array('id' => 'servers', 'title' => gettext('Server options'), 'description' => gettext('Empty lists use the settings of the interface.'), 'fields' => array(
			restapi_dhcp_schema_addr_grid('winsserver', gettext('WINS servers'), 2, ''),
			restapi_dhcp_schema_addr_grid('dnsserver', gettext('DNS servers'), 4, ''),
		)),
		array('id' => 'options', 'title' => gettext('Other DHCP options'), 'fields' => array(
			array('name' => 'gateway', 'type' => 'text', 'label' => gettext('Gateway'), 'mono' => true, 'width' => 'half',
			    'help' => gettext('Empty: the gateway of the interface.')),
			array('name' => 'domain', 'type' => 'text', 'label' => gettext('Domain name'), 'mono' => true, 'width' => 'half',
			    'help' => gettext('Empty: the domain of the interface.'), 'errorMatch' => array('for the dns domain')),
			array('name' => 'domainsearchlist', 'type' => 'text', 'label' => gettext('Domain search list'), 'mono' => true,
			    'placeholder' => 'example.com;sub.example.com', 'help' => gettext('Domains separated by semicolons.')),
		)),
		array('id' => 'ntp', 'title' => gettext('NTP'), 'advanced' => true, 'fields' => array(
			restapi_dhcp_schema_addr_grid('ntpserver', gettext('NTP servers'), 4, ''),
		)),
	);
	$sections = array_merge($sections, restapi_dhcp_schema_tftp_ldap(), array(
		restapi_dhcp_schema_netboot(),
		restapi_dhcp_schema_custom('reservation', 'DHCPv4'),
	));
	return array(
		'title' => gettext('Static mapping'),
		'summary' => gettext('{mac}[ → {ipaddr}][ ({hostname})]'),
		'summaryIcon' => 'thumbtack',
		'sections' => $sections,
	);
}

/* ------------------------------------------------------------------ */
/* DHCPv6 server of an interface                                       */
/* ------------------------------------------------------------------ */

function restapi_schema_services_dhcpv6_server($query = array()) {
	$hints = restapi_dhcp6_iface_hints(restapi_dhcp_schema_iface($query));
	$policy = restapi_dhcp_schema_policies(true);
	$sections = array(
		array('id' => 'general', 'title' => gettext('General'), 'fields' => array(
			array('name' => 'enable', 'type' => 'switch', 'label' => gettext('DHCPv6 server'), 'text' => gettext('Enable the DHCPv6 server on this interface'),
			    'help' => gettext('Cannot be enabled while the DHCPv6 relay is enabled.'),
			    'errorMatch' => array('dhcp relay on the', 'can only be enabled on interfaces configured with a static ipv6')),
			array('name' => 'denyunknown', 'type' => 'select', 'label' => gettext('Deny unknown clients'), 'default' => 'disabled', 'width' => 'half',
			    'options' => restapi_schema_choices(restapi_dhcp_denyunknown_choices())),
			array('name' => 'dnsregpolicy', 'type' => 'select', 'label' => gettext('DNS registration'), 'default' => 'default', 'width' => 'half',
			    'options' => $policy, 'help' => gettext('Overrides the default DNS registration policy of the DHCPv6 settings.')),
			array('name' => 'earlydnsregpolicy', 'type' => 'select', 'label' => gettext('Early DNS registration'), 'default' => 'default', 'width' => 'half',
			    'options' => $policy, 'help' => gettext('Overrides the default early DNS registration policy of the DHCPv6 settings.')),
		)),
		array('id' => 'pool', 'title' => gettext('Primary address pool'),
		    'description' => ($hints['prefix'] !== '') ? sprintf(gettext('Prefix %1$s (%2$s).'), $hints['prefix'], $hints['prefix_range']) :
			gettext('The addresses handed out to clients.'),
		    'fields' => array(
			array('name' => 'range_from', 'type' => 'text', 'label' => gettext('Range from'), 'mono' => true, 'width' => 'half',
			    'placeholder' => '::1000',
			    'help' => gettext('Required unless the router advertisements are in Stateless DHCP mode. With Track Interface only the suffix (the delegated prefix is prepended).'),
			    'errorMatch' => array('a valid range must be specified', 'lies outside of the current subnet', 'range is invalid', 'must be zero',
				'overlap any static dhcp mappings', 'overlap with virtual ipv6 address', 'within the range configured on another dhcpv6 pool',
				'within the primary dhcpv6 address pool', 'range from and range to')),
			array('name' => 'range_to', 'type' => 'text', 'label' => gettext('Range to'), 'mono' => true, 'width' => 'half', 'placeholder' => '::2000'),
		)),
		array('id' => 'pd', 'title' => gettext('Prefix delegation pool'),
		    'description' => gettext('Prefixes delegated to downstream routers.'), 'fields' => array(
			array('name' => 'pdprefix', 'type' => 'text', 'label' => gettext('Delegated prefix'), 'mono' => true, 'width' => 'half',
			    'placeholder' => '2001:db8:1000::', 'help' => gettext('Start of the range the delegated prefixes are taken from.')),
			array('name' => 'pdprefixlen', 'type' => 'number', 'label' => gettext('Prefix length'), 'min' => 48, 'max' => 64, 'width' => 'third',
			    'help' => gettext('The fixed part of the delegated prefix; not longer than the delegated length.'),
			    'errorMatch' => array('delegated prefix length')),
			array('name' => 'pddellen', 'type' => 'number', 'label' => gettext('Delegated length'), 'min' => 48, 'max' => 128, 'width' => 'third',
			    'help' => gettext('The prefix length handed to each client.')),
		)),
		array('id' => 'servers', 'title' => gettext('Server options'), 'fields' => array(
			array('name' => 'dhcp6c-dns', 'type' => 'switch', 'label' => gettext('Provide DNS'), 'text' => gettext('Provide DNS servers to DHCPv6 clients'),
			    'default' => true, 'help' => gettext('Off removes the name-servers option, which may violate RFCs.')),
			restapi_dhcp_schema_addr_grid('dnsserver', gettext('DNS servers'), 4,
			    gettext('Empty: this interface\'s address while the DNS Resolver or Forwarder runs, else the system DNS servers.'), $hints['dnsserver'],
			    array('visibleWhen' => array('field' => 'dhcp6c-dns', 'truthy' => true))),
		)),
		array('id' => 'options', 'title' => gettext('Other DHCPv6 options'), 'fields' => array(
			array('name' => 'domain', 'type' => 'text', 'label' => gettext('Domain name'), 'mono' => true, 'width' => 'half',
			    'placeholder' => $hints['domain'], 'help' => gettext('Empty: the domain of this firewall.'), 'errorMatch' => array('for the dns domain')),
			array('name' => 'domainsearchlist', 'type' => 'text', 'label' => gettext('Domain search list'), 'mono' => true, 'width' => 'half',
			    'placeholder' => 'example.com;sub.example.com', 'help' => gettext('Domains separated by semicolons.')),
			array('name' => 'deftime', 'type' => 'number', 'label' => gettext('Default lease time'), 'unit' => gettext('seconds'), 'min' => 60,
			    'width' => 'half', 'placeholder' => '7200', 'errorMatch' => array('the default lease time must')),
			array('name' => 'maxtime', 'type' => 'number', 'label' => gettext('Maximum lease time'), 'unit' => gettext('seconds'), 'min' => 60,
			    'width' => 'half', 'placeholder' => '86400', 'errorMatch' => array('the maximum lease time must')),
		)),
		array('id' => 'ntp', 'title' => gettext('NTP'), 'advanced' => true, 'fields' => array(
			restapi_dhcp_schema_addr_grid('ntpserver', gettext('NTP servers'), 4, ''),
		)),
		array('id' => 'netboot', 'title' => gettext('Network booting'), 'advanced' => true, 'fields' => array(
			array('name' => 'netboot', 'type' => 'switch', 'label' => gettext('Network booting'), 'text' => gettext('Enable network booting')),
			array('name' => 'bootfile_url', 'type' => 'text', 'label' => gettext('Bootfile URL'), 'mono' => true,
			    'visibleWhen' => array('field' => 'netboot', 'truthy' => true), 'errorMatch' => array('network bootfile')),
		)),
		restapi_dhcp_schema_custom('subnet', 'DHCPv6'),
	);
	return array(
		'title' => gettext('DHCPv6 server'),
		'summary' => gettext('[Range {range_from} – {range_to}]'),
		'summaryIcon' => 'network-wired',
		'sections' => $sections,
	);
}

/* ------------------------------------------------------------------ */
/* DHCPv6 static mapping                                               */
/* ------------------------------------------------------------------ */

function restapi_schema_services_dhcpv6_static_mapping($query = array()) {
	return array(
		'title' => gettext('DHCPv6 static mapping'),
		'summary' => gettext('{duid}[ → {ipaddrv6}][ ({hostname})]'),
		'summaryIcon' => 'thumbtack',
		'sections' => array(
			array('id' => 'mapping', 'title' => gettext('Static mapping'), 'fields' => array(
				array('name' => 'duid', 'type' => 'text', 'label' => gettext('DUID'), 'required' => true, 'mono' => true,
				    'placeholder' => 'xx:xx:xx:xx:xx:xx:xx:xx:xx:xx',
				    'help' => gettext('DHCP Unique Identifier of the client: hex octets separated by colons.'),
				    'errorMatch' => array('or duid already exists')),
				array('name' => 'ipaddrv6', 'type' => 'text', 'label' => gettext('IPv6 address'), 'mono' => true, 'width' => 'half',
				    'help' => gettext('IPv6 address for this client. With Track Interface only the suffix (::1:2:3:4).'),
				    'errorMatch' => array('must be zero')),
				array('name' => 'pdprefix', 'type' => 'text', 'label' => gettext('Delegated prefix'), 'mono' => true, 'width' => 'half',
				    'placeholder' => '2001:db8:1000::/56', 'help' => gettext('A prefix (address/length) delegated to this client.')),
				restapi_dhcp_schema_hostname(),
				array('name' => 'descr', 'type' => 'text', 'label' => gettext('Description'), 'width' => 'half',
				    'help' => gettext('For your reference (not parsed).')),
				array('name' => 'earlydnsregpolicy', 'type' => 'select', 'label' => gettext('Early DNS registration'), 'default' => 'default', 'width' => 'half',
				    'options' => restapi_dhcp_schema_policies(false), 'help' => gettext('Overrides the early DNS registration policy of the subnet.')),
			)),
			restapi_dhcp_schema_custom('reservation', 'DHCPv6'),
		),
	);
}

/* ------------------------------------------------------------------ */
/* Settings (Kea)                                                      */
/* ------------------------------------------------------------------ */

function restapi_schema_services_dhcp_settings_form($v6) {
	$name = $v6 ? gettext('DHCPv6 server') : gettext('DHCP server');
	$ha = array('field' => 'ha_enable', 'truthy' => true);
	$tls = array($ha, array('field' => 'ha_tls', 'truthy' => true));
	$number = function ($name, $label, $placeholder, $help, $min = 1) use ($ha) {
		return array('name' => $name, 'type' => 'number', 'label' => $label, 'min' => $min, 'width' => 'half',
		    'placeholder' => (string)$placeholder, 'help' => $help, 'visibleWhen' => $ha);
	};
	$default = function ($key, $fallback) {
		if (!function_exists('kea_defaults')) {
			return $fallback;
		}
		try {
			return kea_defaults($key);
		} catch (Throwable $e) {
			return $fallback;
		}
	};
	$certs = function ($fn) {
		return restapi_schema_choices(function_exists($fn) ? (array)call_user_func($fn) : array());
	};
	$ms = function ($f) {
		return $f + array('unit' => gettext('ms'));
	};
	return array(
		'title' => sprintf(gettext('%s settings'), $name),
		'sections' => array(
			array('id' => 'general', 'title' => gettext('General settings'), 'fields' => array(
				array('name' => 'backend', 'type' => 'select', 'label' => gettext('DHCP backend'), 'readonly' => true, 'default' => 'kea', 'width' => 'half',
				    'options' => restapi_schema_choices(array('kea' => restapi_dhcp_backend_labels()['kea'])),
				    'help' => gettext('FreeSense runs Kea DHCP. ISC DHCP has reached end of life and is not available.'),
				    'errorMatch' => array('kea dhcp backend')),
				array('name' => 'loglevel', 'type' => 'select', 'label' => gettext('Log level'), 'default' => 'WARN', 'width' => 'half',
				    'options' => restapi_dhcp_schema_opts('kea_log_levels', array('FATAL' => 'Fatal', 'ERROR' => 'Error', 'WARN' => 'Warning (default)',
					'INFO' => 'Informational', 'DEBUG' => 'Debug')),
				    'help' => gettext('Lowest severity of the Kea messages sent to the DHCP log.')),
				array('name' => 'dnsreg', 'type' => 'switch', 'label' => gettext('DNS registration'), 'text' => gettext('Enable DNS registration'), 'width' => 'half',
				    'help' => gettext('Default DNS registration policy; an interface can override it. Clients are registered with the DNS Resolver.')),
				array('name' => 'earlydnsreg', 'type' => 'switch', 'label' => gettext('Early DNS registration'), 'text' => gettext('Enable early DNS registration'),
				    'width' => 'half', 'help' => gettext('Default early DNS registration policy for static mappings; an interface or mapping can override it.')),
			)),
			array('id' => 'ha', 'title' => gettext('High availability'), 'fields' => array(
				array('name' => 'ha_enable', 'type' => 'switch', 'label' => gettext('High availability'), 'text' => gettext('Enable hot-standby high availability')),
				array('name' => 'ha_role', 'type' => 'segmented', 'label' => gettext('Node role'), 'default' => 'primary', 'visibleWhen' => $ha,
				    'options' => restapi_dhcp_schema_opts('kea_ha_roles', array('primary' => 'Primary', 'standby' => 'Standby')),
				    'help' => gettext('Only one node is the primary.')),
				array('name' => 'ha_localname', 'type' => 'text', 'label' => gettext('Local name'), 'mono' => true, 'width' => 'half', 'visibleWhen' => $ha,
				    'placeholder' => (string)$default('name', ''), 'help' => gettext('Name of this instance (hostname-like).')),
				array('name' => 'ha_remotename', 'type' => 'text', 'label' => gettext('Remote name'), 'mono' => true, 'width' => 'half', 'visibleWhen' => $ha,
				    'help' => gettext('Name of the partner instance (hostname-like).')),
				array('name' => 'ha_localip', 'type' => 'text', 'label' => gettext('Local address'), 'mono' => true, 'width' => 'third', 'visibleWhen' => $ha,
				    'help' => gettext('Listening address of this instance.')),
				array('name' => 'ha_localport', 'type' => 'number', 'label' => gettext('Local port'), 'min' => 1, 'max' => 65535, 'width' => 'third',
				    'visibleWhen' => $ha, 'placeholder' => (string)$default('listenport', 8765)),
				array('name' => 'ha_remoteip', 'type' => 'text', 'label' => gettext('Remote address'), 'mono' => true, 'width' => 'third', 'visibleWhen' => $ha,
				    'help' => gettext('Listening address of the partner instance.')),
				array('name' => 'ha_remoteport', 'type' => 'number', 'label' => gettext('Remote port'), 'min' => 1, 'max' => 65535, 'width' => 'third',
				    'visibleWhen' => $ha, 'placeholder' => (string)$default('listenport', 8765)),
			)),
			array('id' => 'ha_advanced', 'title' => gettext('High availability tuning'), 'advanced' => true, 'visibleWhen' => $ha, 'fields' => array(
				$ms($number('ha_heartbeatdelay', gettext('Heartbeat Delay'), $default('heartbeatdelay', 10000),
				    gettext('Time between two heartbeats to the partner.'))),
				$ms($number('ha_maxresponsedelay', gettext('Max Response Delay'), $default('maxresponsedelay', 60000),
				    gettext('Time without contact after which the partner counts as unreachable; longer than the heartbeat delay.'))),
				$ms($number('ha_maxackdelay', gettext('Max Ack Delay'), $default('maxackdelay', 60000),
				    gettext('Time a client may try to reach the partner before it counts as "unacked".'))),
				$number('ha_maxunackedclients', gettext('Max Unacked Clients'), $default('maxunackedclients', 0),
				    gettext('"Unacked" clients allowed before the partner counts as down.'), 0),
				$number('ha_maxrejectedleaseupdates', gettext('Max Rejected Updates'), $default('maxrejectedleaseupdates', 10),
				    gettext('Failed lease updates for distinct clients before the server stops synchronizing.'), 0),
			)),
			array('id' => 'ha_tls', 'title' => gettext('TLS transport'), 'advanced' => true, 'visibleWhen' => $ha,
			    'description' => gettext('Not synchronized: configure TLS on both instances.'), 'fields' => array(
				array('name' => 'ha_tls', 'type' => 'switch', 'label' => gettext('TLS transport'), 'text' => gettext('Secure heartbeats and lease updates with TLS'),
				    'visibleWhen' => $ha),
				array('name' => 'ha_scertref', 'type' => 'select', 'label' => gettext('Server certificate'), 'width' => 'half', 'visibleWhen' => $tls,
				    'options' => $certs('kea_server_cert_list')),
				array('name' => 'ha_mutualtls', 'type' => 'switch', 'label' => gettext('Mutual TLS'), 'text' => gettext('Offer a client certificate to the partner'),
				    'width' => 'half', 'visibleWhen' => $tls),
				array('name' => 'ha_ccertref', 'type' => 'select', 'label' => gettext('Client certificate'), 'width' => 'half',
				    'visibleWhen' => array_merge($tls, array(array('field' => 'ha_mutualtls', 'truthy' => true))), 'options' => $certs('kea_client_cert_list')),
			)),
			restapi_dhcp_schema_custom($v6 ? 'Dhcp6' : 'Dhcp4', $v6 ? 'DHCPv6' : 'DHCPv4'),
		),
	);
}

function restapi_schema_services_dhcp_settings($query = array()) {
	return restapi_schema_services_dhcp_settings_form(false);
}

function restapi_schema_services_dhcpv6_settings($query = array()) {
	return restapi_schema_services_dhcp_settings_form(true);
}

/* ------------------------------------------------------------------ */
/* DHCP and DHCPv6 relay                                               */
/* ------------------------------------------------------------------ */

function restapi_schema_services_dhcp_relay_form($v6) {
	$ifaces = restapi_dhcp_schema_opts('dhcp_relay_interface_list', array(), array($v6));
	$vips = restapi_dhcp_schema_opts('dhcp_relay_carp_list', array('none' => 'none'), array($v6));
	$on = array('field' => 'enable', 'truthy' => true);
	return array(
		'title' => $v6 ? gettext('DHCPv6 relay') : gettext('DHCP relay'),
		'sections' => array(
			array('id' => 'relay', 'title' => $v6 ? gettext('DHCPv6 relay') : gettext('DHCP relay'), 'fields' => array(
				array('name' => 'enable', 'type' => 'switch', 'label' => gettext('Relay'), 'text' => $v6 ? gettext('Enable DHCPv6 relay') : gettext('Enable DHCP relay'),
				    'help' => $v6 ? gettext('Cannot be enabled while the DHCPv6 server runs on any interface.') :
					gettext('Cannot be enabled while the DHCP server runs on any interface.'),
				    'errorMatch' => array('cannot be enabled while')),
				array('name' => 'interface', 'type' => 'checklist', 'label' => gettext('Downstream interfaces'), 'options' => $ifaces, 'visibleWhen' => $on,
				    'help' => $v6 ? gettext('Interfaces without an IPv6 address are not listed.') : gettext('Interfaces without an IPv4 address are not listed.'),
				    'errorMatch' => array('field interface is required', 'is not an interface the relay can use')),
				array('name' => 'carpstatusvip', 'type' => 'select', 'label' => gettext('CARP status VIP'), 'default' => 'none', 'width' => 'half',
				    'options' => $vips, 'visibleWhen' => $on,
				    'help' => gettext('The relay stops while this CARP VIP is in backup state (high availability).')),
				array('name' => 'agentoption', 'type' => 'switch', 'label' => gettext('Agent information'), 'width' => 'half', 'visibleWhen' => $on,
				    'text' => gettext('Append the circuit ID and agent ID to requests')),
				restapi_dhcp_schema_addr_grid('server', gettext('Upstream servers'), 64,
				    $v6 ? gettext('IPv6 addresses of the servers the requests are relayed to.') : gettext('IPv4 addresses of the servers the requests are relayed to.'),
				    array(), array('visibleWhen' => $on, 'addLabel' => gettext('Add upstream server'), 'errorMatch' => array('upstream server'))),
			)),
		),
	);
}

function restapi_schema_services_dhcp_relay($query = array()) {
	return restapi_schema_services_dhcp_relay_form(false);
}

function restapi_schema_services_dhcpv6_relay($query = array()) {
	return restapi_schema_services_dhcp_relay_form(true);
}
