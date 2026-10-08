<?php
/*
 * vpn_l2tp_users_edit.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
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

/*
 * The users list opens the same fields in a modal that posts here, so this page
 * handles every user save; it is shown on its own for direct links and to show
 * validation errors with the entered values.
 */

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

$radius_on = config_path_enabled('l2tp/radius');
$auth_types = l2tp_paporchap_values();
$auth_label = $radius_on ? 'RADIUS' : ($auth_types[config_get_path('l2tp/paporchap')] ?? 'CHAP');

include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($radius_on) {
	print_info_box(gettext("RADIUS is enabled. The local user database will not be used."), 'warning');
}

fs_summary_card([
	'icon' => $this_secret_config ? 'fa-user' : 'fa-user-plus',
	'title' => $this_secret_config ? $this_secret_config['name'] : gettext('New L2TP user'),
	'subtitle' => $this_secret_config ? gettext('L2TP user') : gettext('Users sign in to the L2TP server with this name and password.'),
	'facts' => $this_secret_config ? [
		[gettext('IP address'), (string)($this_secret_config['ip'] ?? ''), 'mono' => true, 'empty' => gettext('dynamic')],
		[gettext('Authentication'), $auth_label],
	] : [],
	'label' => gettext('L2TP user summary'),
]);
$form = new Form();

$section = new Form_Section("Account", 'l2tp-user-account');

$section->addInput(new Form_Input(
	'usernamefld',
	'*Username',
	'text',
	$pconfig['usernamefld'],
	['autocomplete' => 'new-password']
))->setHelp('Letters, digits and . @ - _ only.');

$pwd = new Form_Input(
	'passwordfld',
	$pwd_required . 'Password',
	'text',
	$pconfig['passwordfld']
);

if ($this_secret_config) {
	$pwd->setHelp('Leave empty to keep the current password.');
}

$section->addPassword($pwd);

$form->add($section);

$section = new Form_Section("Address", 'l2tp-user-address');

$section->addInput(new Form_IpAddress(
	'ip',
	'IP Address',
	$pconfig['ip']
))->setHelp('Optional. A fixed address for this user; leave empty to use the remote address range.');

$form->add($section);

if ($this_secret_config) {
	$form->addGlobal(new Form_Input(
		'id',
		null,
		'hidden',
		$id
	));
}

fs_form_cancel($form, 'vpn_l2tp_users.php');
print($form);

include("foot.inc");
