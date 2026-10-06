<?php
/*
 * vpn_l2tp.php
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
##|*IDENT=page-vpn-vpnl2tp
##|*NAME=VPN: L2TP
##|*DESCR=Allow access to the 'VPN: L2TP' page.
##|*MATCH=vpn_l2tp.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("vpn.inc");
require_once("vpn_l2tp.inc");

$pconfig = l2tp_settings_form();

if ($_POST['save']) {

	unset($input_errors);
	$rv = l2tp_settings_save($_POST);
	$input_errors = $rv['input_errors'];
	$pconfig = $rv['pconfig'];
	if ($rv['changes_applied']) {
		$changes_applied = true;
		$retval = $rv['retval'];
	}
}

$pgtitle = array(gettext("VPN"), gettext("L2TP"), gettext("Configuration"));
$pglinks = array("", "@self", "@self");
$shortcut_section = "l2tps";
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($changes_applied) {
	print_apply_result_box($retval);
}

$tab_array = array();
$tab_array[] = array(gettext("Configuration"), true, "vpn_l2tp.php");
$tab_array[] = array(gettext("Users"), false, "vpn_l2tp_users.php");
display_top_tabs($tab_array);

$form = new Form();

$section = new Form_Section("Enable L2TP");

$section->addInput(new Form_Checkbox(
	'mode',
	'Enable',
	'Enable L2TP server',
	($pconfig['mode'] == "server"),
	'server'
));

$form->add($section);

$iflist = array();
$interfaces = get_configured_interface_with_descr();
foreach ($interfaces as $iface => $ifacename) {
	$iflist[$iface] = $ifacename;
}

$section = new Form_Section("Configuration");
$section->addClass('toggle-l2tp-enable');

$section->addInput(new Form_Select(
	'interface',
	'*Interface',
	$pconfig['interface'],
	$iflist
));

$section->addInput(new Form_Input(
	'localip',
	'*Server address',
	'text',
	$pconfig['localip']
))->setHelp('Enter the IP address the L2TP server should give to clients for use as their "gateway". %1$s' .
			'Typically this is set to an unused IP just outside of the client range.%1$s%1$s' .
			'NOTE: This should NOT be set to any IP address currently in use on this firewall.', '<br />');

$section->addInput(new Form_IpAddress(
        'remoteip',
        '*Remote address range',
        $pconfig['remoteip']
))->addMask('l2tp_subnet', $pconfig['l2tp_subnet'], 32)->setWidth(5)
  ->setHelp('Specify the starting address for the client IP address subnet.');

$section->addInput(new Form_Select(
	'n_l2tp_units',
	'*Number of L2TP users',
	$pconfig['n_l2tp_units'],
	array_combine(range(1, 255, 1), range(1, 255, 1))
));

$section->addPassword(new Form_Input(
	'secret',
	'Secret',
	'password',
	$pconfig['secret']
))->setHelp('Specify optional secret shared between peers. Required on some devices/setups.');

$section->addInput(new Form_Select(
	'paporchap',
	'*Authentication type',
	$pconfig['paporchap'],
	l2tp_paporchap_values()
))->setHelp('Specifies the protocol to use for authentication.');

$section->addInput(new Form_Input(
	'l2tp_dns1',
	'Primary L2TP DNS server',
	'text',
	$pconfig['l2tp_dns1']
));

$section->addInput(new Form_Input(
	'l2tp_dns2',
	'Secondary L2TP DNS server',
	'text',
	$pconfig['l2tp_dns2']
));

$section->addInput(new Form_Input(
	'mtu',
	'VPN MTU',
	'number',
	$pconfig['mtu']
))->setHelp('If this field is blank, the adapter\'s default MTU will be used. ' .
			'This is typically 1500 bytes but can vary in some circumstances.');

$form->add($section);

$section = new Form_Section("RADIUS");
$section->addClass('toggle-l2tp-enable');

$section->addInput(new Form_Checkbox(
	'radiusenable',
	'Enable',
	'Use a RADIUS server for authentication',
	$pconfig['radiusenable']
))->setHelp('When set, all users will be authenticated using the RADIUS server specified below. The local user database will not be used.');

$section->addInput(new Form_Checkbox(
	'radacct_enable',
	'Accounting',
	'Enable RADIUS accounting',
	$pconfig['radacct_enable']
))->setHelp('Sends accounting packets to the RADIUS server.');

$section->addInput(new Form_IpAddress(
	'radiusserver',
	'*Server',
	$pconfig['radiusserver']
))->setHelp('Enter the IP address of the RADIUS server.');

$section->addPassword(new Form_Input(
	'radiussecret',
	'*Secret',
	'password',
	$pconfig['radiussecret']
))->setHelp('Enter the shared secret that will be used to authenticate to the RADIUS server.');

$section->addInput(new Form_Checkbox(
	'radiusissueips',
	'RADIUS issued IPs',
	'Issue IP Addresses via RADIUS server.',
	$pconfig['radiusissueips']
));

$form->add($section);

print($form);
?>
<div class="infoblock blockopen">
<?php
	print_info_box(gettext("Don't forget to add a firewall rule to permit traffic from L2TP clients."), 'info', false);
?>
</div>

<script type="text/javascript">
//<![CDATA[
events.push(function() {

	function setL2TP () {
		hide = ! $('#mode').prop('checked');

		hideClass('toggle-l2tp-enable', hide);
	}

	function setRADIUS () {
		hide = ! $('#radiusenable').prop('checked');

		hideCheckbox('radacct_enable', hide);
		hideInput('radiusserver', hide);
		hideInput('radiussecret', hide);
		hideCheckbox('radiusissueips', hide);
	}

	// on-click
	$('#mode').click(function () {
		setL2TP();
	});

	$('#radiusenable').click(function () {
		setRADIUS();
	});

	// on-page-load
	setRADIUS();
	setL2TP();

});
//]]>
</script>

<?php include("foot.inc")?>
