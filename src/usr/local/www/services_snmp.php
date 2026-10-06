<?php
/*
 * services_snmp.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
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
##|*IDENT=page-services-snmp
##|*NAME=Services: SNMP
##|*DESCR=Allow access to the 'Services: SNMP' page.
##|*MATCH=services_snmp.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("functions.inc");
require_once("services_snmp.inc");

snmp_init_config();

$pconfig = snmp_settings();

if ($_POST) {

	unset($input_errors);
	$pconfig = $_POST;

	$rv = snmp_save($_POST);
	$input_errors = $rv['input_errors'];

	if (!$input_errors) {
		$changes_applied = true;
		$retval = $rv['retval'];
	}
}

$pgtitle = array(gettext("Services"), gettext("SNMP"));
$shortcut_section = "snmp";

include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($changes_applied) {
	print_apply_result_box($retval);
}

$form = new Form();

$section = new Form_Section('SNMP Daemon');

$section->addInput(new Form_Checkbox(
	'enable',
	'Enable',
	'Enable the SNMP Daemon and its controls',
	$pconfig['enable']
));

$form->add($section);

$section = new Form_Section('SNMP Daemon Settings');

$section->addInput(new Form_Input(
	'pollport',
	'Polling Port',
	'text',
	($pconfig['pollport'] ? $pconfig['pollport']:'161')
))->setHelp('Enter the port to accept polling events on (default 161).');

$section->addInput(new Form_Input(
	'syslocation',
	'System Location',
	'text',
	$pconfig['syslocation']
));

$section->addInput(new Form_Input(
	'syscontact',
	'System Contact',
	'text',
	$pconfig['syscontact']
));

$section->addInput(new Form_Input(
	'rocommunity',
	'Read Community String',
	'text',
	$pconfig['rocommunity']
))->setHelp('The community string is like a password, restricting access to querying SNMP to hosts knowing the community string. Use a strong value here to protect from unauthorized information disclosure.');

$form->add($section);

$section = new Form_Section('SNMP Traps Enable');

$section->addInput(new Form_Checkbox(
	'trapenable',
	'Enable',
	'Enable the SNMP Trap and its controls',
	$pconfig['trapenable']
))->toggles('.toggle-traps');

$form->add($section);

$section = new Form_Section('SNMP Trap Settings');

if ($pconfig['trapenable']) {
	$section->addClass('toggle-traps', 'in');
} else {
	$section->addClass('toggle-traps', 'collapse');
}

$section->addInput(new Form_Input(
	'trapserver',
	'Trap server',
	'text',
	$pconfig['trapserver']
))->setHelp('Enter the trap server name');

$section->addInput(new Form_Input(
	'trapserverport',
	'Trap Server Port',
	'text',
	($pconfig['trapserverport'] ? $pconfig['trapserverport']:'162')
))->setHelp('Enter the port to send the traps to (default 162)');

$section->addInput(new Form_Input(
	'trapstring',
	'SNMP Trap String',
	'text',
	$pconfig['trapstring']
));

$form->add($section);

$section = new Form_Section('SNMP Modules');

$group = new Form_MultiCheckboxGroup('SNMP modules');

$group->add(new Form_MultiCheckbox(
	'mibii',
	null,
	'MibII',
	$pconfig['mibii']
));

$group->add(new Form_MultiCheckbox(
	'netgraph',
	null,
	'Netgraph',
	$pconfig['netgraph']
));

$group->add(new Form_MultiCheckbox(
	'pf',
	null,
	'PF',
	$pconfig['pf']
));

$group->add(new Form_MultiCheckbox(
	'hostres',
	null,
	'Host Resources',
	$pconfig['hostres']
));

$group->add(new Form_MultiCheckbox(
	'ucd',
	null,
	'UCD',
	$pconfig['ucd']
));

$group->add(new Form_MultiCheckbox(
	'regex',
	null,
	'Regex',
	$pconfig['regex']
));

$section->add($group);
$form->add($section);

$section = new Form_Section('Interface Binding');

$section->addInput(new Form_Select(
	'ipprotocol',
	'Internet Protocol',
	$pconfig['ipprotocol'],
	snmp_ip_protocols()
));

$iflist = snmp_build_if_list($pconfig['bindip']);

$section->addInput(new Form_Select(
	'bindip',
	'Bind Interfaces',
	$iflist['selected'],
	$iflist['options'],
	true
));

$form->add($section);

print($form);
?>

<script type="text/javascript">
//<![CDATA[

// hostres requires mibii so we force that here
events.push(function() {

	noMibii = false;

	$('#junk').hide();
	enableChange();
	trapenableChange();
	hostresChange();

	function enableChange() {
		setRequired('pollport', $('#enable').prop('checked'));
		setRequired('rocommunity', $('#enable').prop('checked'));
	}

	function trapenableChange() {
		setRequired('trapserver', $('#trapenable').prop('checked'));
		setRequired('trapserverport', $('#trapenable').prop('checked'));
		setRequired('trapstring', $('#trapenable').prop('checked'));
	}

	function hostresChange() {
		if ($('#hostres').prop('checked')) {
			$('#mibii').prop('checked', true);
			noMibii = true;
		} else {
			noMibii = false;
		}
	}

	$('#enable').change(function() {
		enableChange();
	});

	$('#trapenable').change(function() {
		trapenableChange();
	});

	$('#hostres').change(function() {
		hostresChange();
	});

	$('#mibii').change(function() {
		if (noMibii) {
			$('#mibii').prop('checked', 'true');
		}
	});

	$('[name=btntoggleall]').hide();
});
//]]>
</script>

<?php include("foot.inc");
