<?php
/*
 * services_rfc2136_edit.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
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
##|*IDENT=page-services-rfc2136edit
##|*NAME=Services: RFC 2136 Client: Edit
##|*DESCR=Allow access to the 'Services: RFC 2136 Client: Edit' page.
##|*MATCH=services_rfc2136_edit.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("services_dyndns.inc");

$tsig_key_algos = rfc2136_key_algos();

if (is_numericint($_REQUEST['id'])) {
	$id = $_REQUEST['id'];
}

$dup = false;
if (isset($_REQUEST['dup']) && is_numericint($_REQUEST['dup'])) {
	$id = $_REQUEST['dup'];
	$dup = true;
}

$this_rfc2136_config = isset($id) ? config_get_path("dnsupdates/dnsupdate/{$id}") : null;
$pconfig = rfc2136_client_settings($id, $dup);

if ($_POST['save'] || $_POST['force']) {

	unset($input_errors);
	$pconfig = $_POST;
	$rv = rfc2136_save_client($_POST, $id, $dup);
	$input_errors = $rv['input_errors'];
	if (!$input_errors) {
		header("Location: services_rfc2136.php");
		exit;
	}
}

$pgtitle = array(gettext("Services"), gettext("Dynamic DNS"), gettext("RFC 2136 Clients"), gettext("Edit"));
$pglinks = array("", "services_dyndns.php", "services_rfc2136.php", "@self");
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

$form = new Form;

$section = new Form_Section('RFC 2136 Client');

$section->addInput(new Form_Checkbox(
	'enable',
	'Enable',
	null,
	$pconfig['enable']
));

$iflist = dyndns_build_if_list();

$section->addInput(new Form_Select(
	'interface',
	'*Interface',
	$pconfig['interface'],
	$iflist
))->setHelp('Interface to monitor for updates. The address of this interface will be used in the updated DNS record.');

$section->addInput(new Form_Input(
	'host',
	'*Hostname',
	'text',
	$pconfig['host']
))->setHelp('Fully qualified hostname of the host to be updated.');

$section->addInput(new Form_Input(
	'zone',
	'Zone',
	'text',
	$pconfig['zone']
))->setHelp('Hostname zone (optional).');

$section->addInput(new Form_Input(
	'ttl',
	'*TTL (seconds)',
	'number',
	$pconfig['ttl']
));

$section->addInput(new Form_Input(
	'keyname',
	'*Key name',
	'text',
	$pconfig['keyname']
))->setHelp('This must match the setting on the DNS server.');

$section->addInput(new Form_Select(
	'keyalgorithm',
	'*Key algorithm',
	$pconfig['keyalgorithm'],
	$tsig_key_algos
));

$section->addInput(new Form_Input(
	'keydata',
	'*Key',
	'text',
	$pconfig['keydata']
))->setHelp('Secret TSIG domain key.');

$section->addInput(new Form_Input(
	'server',
	'Server',
	'text',
	$pconfig['server']
));

$section->addInput(new Form_Checkbox(
	'usetcp',
	'Protocol',
	'Use TCP instead of UDP',
	$pconfig['usetcp']
));

$section->addInput(new Form_Checkbox(
	'usepublicip',
	'Use public IP',
	'If the interface IP is private, attempt to fetch and use the public IP instead.',
	$pconfig['usepublicip']
));

$uslist = rfc2136_build_us_list();

$section->addInput(new Form_Select(
	'updatesource',
	'Update Source',
	$pconfig['updatesource'],
	$uslist
))->setHelp('Interface or address from which the firewall will send the DNS update request.');

$section->addInput(new Form_Select(
	'updatesourcefamily',
	'Update Source Family',
	$pconfig['updatesourcefamily'],
	rfc2136_source_families()
))->setHelp('Address family to use for sourcing updates.');

$group = new Form_Group('*Record Type');

$group->add(new Form_Checkbox(
	'recordtype',
	'Record Type',
	'A (IPv4)',
	($pconfig['recordtype'] == 'A'),
	'A'
))->displayAsRadio();

$group->add(new Form_Checkbox(
	'recordtype',
	'Record Type',
	'AAAA (IPv6)',
	($pconfig['recordtype'] == 'AAAA'),
	'AAAA'
))->displayAsRadio();

$group->add(new Form_Checkbox(
	'recordtype',
	'Record Type',
	'Both',
	($pconfig['recordtype'] == 'both'),
	'both'
))->displayAsRadio();

$section->add($group);

$section->addInput(new Form_Input(
	'descr',
	'Description',
	'text',
	$pconfig['descr']
))->setHelp('A description may be entered here for administrative reference (not parsed).');

if ($this_rfc2136_config) {
	$form->addGlobal(new Form_Input(
		'id',
		null,
		'hidden',
		$id
	));

	$form->addGlobal(new Form_Button(
		'force',
		'Save & Force Update',
		null,
		'fa-solid fa-arrows-rotate'
	))->addClass('btn-outline-secondary');
}

$form->add($section);
fs_form_cancel($form, 'services_rfc2136.php');
print($form);

print_info_box(sprintf(gettext('A DNS server must be configured in %1$sSystem: ' .
					'General Setup %2$sor allow the DNS server list to be overridden ' .
					'by DHCP/PPP on WAN for dynamic DNS updates to work.'), '<a href="system.php">', '</a>'));

include("foot.inc");
