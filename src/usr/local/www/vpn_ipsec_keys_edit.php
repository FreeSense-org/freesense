<?php
/*
 * vpn_ipsec_keys_edit.php
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
##|*IDENT=page-vpn-ipsec-editkeys
##|*NAME=VPN: IPsec: Edit Pre-Shared Keys
##|*DESCR=Allow access to the 'VPN: IPsec: Edit Pre-Shared Keys' page.
##|*MATCH=vpn_ipsec_keys_edit.php*
##|-PRIV

require_once("functions.inc");
require_once("guiconfig.inc");
require_once("ipsec.inc");
require_once("vpn.inc");
require_once("vpn_ipsec.inc");

if (is_numericint($_REQUEST['id'])) {
	$id = $_REQUEST['id'];
}

if (isset($id) && config_get_path('ipsec/mobilekey/' . $id)) {
	$pconfig = ipsec_psk_form($id);
}

if ($_POST['save']) {
	unset($input_errors);
	$pconfig = $_POST;

	$input_errors = ipsec_psk_save($_POST, $id ?? null);
	if (!$input_errors) {
		header("Location: vpn_ipsec_keys.php");
		exit;
	}
}

$editing = (isset($id) && config_get_path('ipsec/mobilekey/' . $id));
$stored = $editing ? config_get_path('ipsec/mobilekey/' . $id) : array();
if ($editing) {
	$pgtitle = array(gettext("VPN"), gettext("IPsec"), gettext("Pre-Shared Keys"), htmlspecialchars($stored['ident']), gettext("Edit key"));
	$pglinks = array("", "vpn_ipsec.php", "vpn_ipsec_keys.php", "", "@self");
} else {
	$pgtitle = array(gettext("VPN"), gettext("IPsec"), gettext("Pre-Shared Keys"), gettext("Add key"));
	$pglinks = array("", "vpn_ipsec.php", "vpn_ipsec_keys.php", "@self");
}
$shortcut_section = "ipsec";

include("head.inc");

if ($input_errors)
	print_input_errors($input_errors);

if ($editing):
	$stored_type = empty($stored['type']) ? 'PSK' : $stored['type'];
	$ident_types = ipsec_psk_ident_type_list();

	$stored_facts = [[gettext('Secret type'), $stored_type]];
	if ($stored_type == 'EAP') {
		$stored_facts[] = [gettext('Identifier type'), !empty($stored['ident_type']) ? ($ident_types[$stored['ident_type']] ?? $stored['ident_type']) : gettext('Not set')];
		$stored_facts[] = [gettext('Address pool'), !empty($stored['pool_address']) ? $stored['pool_address'] . '/' . $stored['pool_netbits'] : gettext('Mobile clients pool'), 'mono' => !empty($stored['pool_address'])];
		$stored_facts[] = [gettext('DNS server'), !empty($stored['dns_address']) ? $stored['dns_address'] : gettext('Mobile clients DNS'), 'mono' => !empty($stored['dns_address'])];
	}
	fs_summary_card([
		'icon' => 'fa-key',
		'title' => $stored['ident'],
		'subtitle' => gettext('Mobile pre-shared key'),
		'facts' => $stored_facts,
		'label' => gettext('Pre-shared key summary'),
	]);
endif;

$form = new Form;

$section = new Form_Section('Pre-shared key');

$section->addInput(new Form_Input(
	'ident',
	'*Identifier',
	'text',
	$pconfig['ident']
))->setHelp('An IP address, fully qualified domain name or e-mail address. Use "any" for any user.');

$section->addInput(new Form_Select(
	'type',
	'*Secret type',
	$pconfig['type'],
	$ipsec_preshared_key_type
))->setWidth(2);

$section->addInput(new Form_Input(
	'psk',
	'*Pre-Shared Key',
	'text',
	$pconfig['psk']
));

$form->add($section);

$section = new Form_Section('EAP options');
$section->addClass('fs-psk-eap');

$section->addInput(new Form_Select(
	'ident_type',
	'Identifier type',
	$pconfig['ident_type'],
	ipsec_psk_ident_type_list()
))->setWidth(4)->setHelp('Optional: specify identifier type for EAP authentication');

$group = new Form_Group('Virtual Address Pool');
$group->addClass('virtualip');
$group->add(new Form_IpAddress(
	'pool_address',
	'Virtual Address Pool',
	$pconfig['pool_address']
))->setWidth(4)->setHelp('Optional IPv4 network. Blank uses the "Virtual Address Pool" of Mobile Clients.')->addMask('pool_netbits', $pconfig['pool_netbits'], 32, 0);
$section->add($group);

$section->addInput(new Form_IpAddress(
	'dns_address',
	'DNS Server',
	$pconfig['dns_address']
))->setWidth(4)->setHelp('Optional IPv4 DNS server for this user only. Blank uses the "DNS Servers" of Mobile Clients.');

if (isset($id) && config_get_path('ipsec/mobilekey/' . $id)) {
	$form->addGlobal(new Form_Input(
		'id',
		false,
		'hidden',
		$id
	));
}

$form->add($section);

fs_form_cancel($form, 'vpn_ipsec_keys.php');
print $form;
?>
<script type="text/javascript">
//<![CDATA[
events.push(function() {
	function change_type() {
		hide = $('#type').val() != 'EAP';
		hideInput('ident_type', hide);
		hideClass('virtualip', hide);
		hideInput('dns_address', hide);
		hideClass('fs-psk-eap', hide);
	}

	$('#type').change(function () {
		change_type();
	});

	// ---------- On initial page load ------------------------------------------------------------

	change_type();
});
//]]>
</script>
<?php
include("foot.inc");
