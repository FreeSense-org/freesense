<?php
/*
 * vpn_l2tp_users_edit.php
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
##|*IDENT=page-vpn-vpnl2tp-users-edit
##|*NAME=VPN: L2TP: Users: Edit
##|*DESCR=Allow access to the 'VPN: L2TP: Users: Edit' page.
##|*MATCH=vpn_l2tp_users_edit.php*
##|-PRIV

$pgtitle = array(gettext("VPN"), gettext("L2TP"), gettext("Users"), gettext("Edit"));
$pglinks = array("", "vpn_l2tp.php", "vpn_l2tp_users.php", "@self");
$shortcut_section = "l2tps";

require_once("guiconfig.inc");
require_once("freesense-utils.inc");
require_once("vpn.inc");
require_once("vpn_l2tp.inc");

if (isset($_REQUEST['id']) && is_numericint($_REQUEST['id'])) {
	$id = $_REQUEST['id'];
}

$this_secret_config = isset($id) ? l2tp_user_conf($id) : null;
if ($this_secret_config) {
	$pconfig = l2tp_user_form($id);
	$pwd_required = "";
} else {
	$pwd_required = "*";
}

if ($_POST['save']) {
	unset($input_errors);
	$pconfig = $_POST;

	$input_errors = l2tp_user_save($_POST, $id ?? null);
	if (!$input_errors) {

		FreeSenseHeader("vpn_l2tp_users.php");

		exit;
	}
}

include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

$form = new Form();

$section = new Form_Section("User");

$section->addInput(new Form_Input(
	'usernamefld',
	'*Username',
	'text',
	$pconfig['usernamefld'],
	['autocomplete' => 'new-password']
));

$pwd = new Form_Input(
	'passwordfld',
	$pwd_required . 'Password',
	'text',
	$pconfig['passwordfld']
);

if ($this_secret_config) {
	$pwd->setHelp('To change the users password, enter it here.');
}

$section->addPassword($pwd);

$section->addInput(new Form_IpAddress(
	'ip',
	'IP Address',
	$pconfig['ip']
))->setHelp('To assign the user a specific IP address, enter it here.');

$form->add($section);

if ($this_secret_config) {
	$form->addGlobal(new Form_Input(
		'id',
		null,
		'hidden',
		$id
	));
}

print($form);

include("foot.inc");
