<?php
/*
 * services_dhcpv6_edit.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
 * Copyright (c) 2010 Seth Mos <seth.mos@dds.nl>
 * All rights reserved.
 *
 * originally based on m0n0wall (http://m0n0.ch/wall)
 * Copyright (c) 2003-2004 Manuel Kasper <mk@neon1.net>.
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

##|+PRIV
##|*IDENT=page-services-dhcpserverv6-editstaticmapping
##|*NAME=Services: DHCPv6 Server: Edit static mapping
##|*DESCR=Allow access to the 'Services: DHCPv6 Server : Edit static mapping' page.
##|*MATCH=services_dhcpv6_edit.php*
##|-PRIV

require_once('globals.inc');

if (!g_get('services_dhcp_server_enable')) {
	header("Location: /");
	exit;
}

require_once("guiconfig.inc");
require_once('services_dhcp.inc');

$dnsregpolicy_values = dhcp_staticmap_dnsregpolicy_values();

$if = $_REQUEST['if'];

if (!$if) {
	header("Location: services_dhcpv6.php");
	exit;
}

$netboot_enabled = config_path_enabled("dhcpdv6/{$if}", 'netboot');

$id = is_numericint($_REQUEST['id']) ? $_REQUEST['id'] : null;

$this_map_config = isset($id) ? config_get_path("dhcpdv6/{$if}/staticmap/{$id}") : null;
$pconfig = dhcp6_staticmap_form($if, $id, $_REQUEST);

if ($_POST['save']) {
	unset($input_errors);
	$rv = dhcp6_staticmap_save($if, $id, $_POST);
	$input_errors = $rv['input_errors'];
	$pconfig = $rv['pconfig'];

	if (!$input_errors) {
		header("Location: services_dhcpv6.php?if={$if}&view=mappings");
		exit;
	}
}

$iflist = get_configured_interface_with_descr();
$ifname = '';

if (!empty($if) && isset($iflist[$if])) {
	$ifname = $iflist[$if];
}
$pgtitle = [gettext('Services'), gettext('DHCPv6 Server'), $ifname, gettext('Static Mapping'), gettext('Edit')];
$pglinks = [null, 'services_dhcpv6.php', "services_dhcpv6.php?if={$if}", "services_dhcpv6.php?if={$if}&view=mappings", '@self'];
$shortcut_section = 'dhcp6';
if (dhcp_is_backend('kea')) {
	$shortcut_section = 'kea-dhcp6';
}

include('head.inc');

if ($input_errors) {
	print_input_errors($input_errors);
}

$valid_ra = in_array(config_get_path('dhcpdv6/'.$if.'/ramode', 'disabled'), ['managed', 'assist', 'stateless_dhcp']);
if (config_path_enabled('dhcpdv6/'.$if) && !$valid_ra) {
	print_info_box(sprintf(gettext('DHCPv6 is enabled but not being advertised to clients on %1$s. Router Advertisement must be enabled and Router Mode set to "Managed", "Assisted" or "Stateless DHCP."'), $iflist[$if]), 'danger', false);
}

display_isc_warning();

$form = new Form();

$section = new Form_Section(sprintf(gettext('Static DHCPv6 Mapping on %s'), $ifname));

$section->addInput(new Form_StaticText(
	gettext('DHCP Backend'),
	match (dhcp_get_backend()) {
		'isc' => gettext('ISC DHCP'),
		'kea' => gettext('Kea DHCP'),
		default => gettext('Unknown')
	}
));

$section->addInput(new Form_Input(
	'duid',
	'*'.gettext('DHCP Unique Identifier'),
	'text',
	$pconfig['duid'],
	['placeholder' => 'xx:xx:xx:xx:xx:xx:xx:xx:xx:xx:xx:xx:xx:xx']
))->setHelp('DHCP Unique Identifier (DUID) of a client to match.%1$s%1$s' .
		'Enter a DUID in the following format: %1$s' .
		'DUID-LLT - ETH -- TIME --- ---- address ----%1$s' .
		'xx:xx:xx:xx:xx:xx:xx:xx:xx:xx:xx:xx:xx:xx%1$s' .
		'xx-xx-xx-xx-xx-xx-xx-xx-xx-xx-xx-xx-xx-xx', '<br />');

$section->addInput(new Form_Input(
	'ipaddrv6',
	gettext('IPv6 Address'),
	'text',
	$pconfig['ipaddrv6']
))->setHelp('IPv6 address to assign this client.%1$s%1$s' .
		'Address must be outside of the pool. ' .
		'If no IPv6 address is given, one will be dynamically allocated from the address pool.', '<br />');

if (dhcp_is_backend('kea')):
$section->addInput(new Form_IpAddress(
	'pdprefix',
	gettext('Delegated Prefix'),
	$pconfig['pdprefix'],
	'V6'
))->addClass('trim')
  ->setHelp('Delegated prefix to assign this client.%1$s%1$s' .
		'If no prefix is given, one will be dynamically allocated from the prefix delegation pool.', '<br />');
endif;

$section->addInput(new Form_Input(
	'hostname',
	gettext('Hostname'),
	'text',
	$pconfig['hostname']
))->setHelp(gettext('Name of the client host without the domain part.'));

$section->addInput(new Form_Input(
	'descr',
	gettext('Description'),
	'text',
	$pconfig['descr']
))->setHelp(gettext('A description for administrative reference (not parsed).'));

$section->addInput(new Form_Select(
	'earlydnsregpolicy',
	gettext('Early DNS Registration'),
	array_get_path($pconfig, 'earlydnsregpolicy', 'default'),
	$dnsregpolicy_values
))->setHelp(gettext('Optionally overides the subnet early DNS registration policy to force a specific policy.'));

if (dhcp_is_backend('isc')):
if ($netboot_enabled) {
	$section->addInput(new Form_Input(
		'filename',
		'Netboot filename',
		'text',
		$pconfig['filename']
	))->setHelp('Name of the file that should be loaded when this host boots off of the network, overrides setting on main page.');

	$section->addInput(new Form_Input(
		'rootpath',
		'Root path',
		'text',
		$pconfig['rootpath']
	))->setHelp('Enter the root-path string. This overrides setting on main page.');
}
endif;

$form->add($section);

if (dhcp_is_backend('kea')):
$section = new Form_Section(gettext('Custom Configuration'));
$kea_custom_input = $section->addInput(new Form_Textarea(
	'custom_kea_config',
	gettext('JSON Configuration'),
	array_get_path($pconfig, 'custom_kea_config')
))->setWidth(8)->setHelp(gettext('JSON to be merged into the "%1$s" section of the generated Kea DHCPv6 configuration.%2$sThe input must be a well formed JSON object and should not include the "%1$s" key itself.'), 'reservation', '<br/>');
if (!kea_custom_config_editable()) {
	$kea_custom_input->setReadonly();
}
$form->add($section);
endif;

if ($this_map_config) {
	$form->addGlobal(new Form_Input(
		'id',
		null,
		'hidden',
		$id
	));
}

$form->addGlobal(new Form_Input(
	'if',
	null,
	'hidden',
	$if
));

fs_form_cancel($form, 'services_dhcpv6.php?if=' . urlencode($if) . '&view=mappings');
print($form);

include("foot.inc");
