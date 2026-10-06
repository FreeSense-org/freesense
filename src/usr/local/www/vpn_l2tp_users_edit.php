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
?>
<style>
.fs-l2tp-summary .panel-body { display: flex; flex-direction: column; gap: var(--fs-sp-4); padding: var(--fs-sp-4); }
.fs-l2tp-head { display: flex; flex-wrap: wrap; align-items: center; gap: var(--fs-sp-3); }
.fs-l2tp-icon { display: inline-flex; flex: none; align-items: center; justify-content: center; width: 2.5rem; height: 2.5rem; border-radius: var(--fs-r-md); background: var(--fs-accent-tint); color: var(--fs-coral-text); font-size: var(--fs-fs-lg); }
.fs-l2tp-name { flex: 1 1 12rem; min-width: 0; }
.fs-l2tp-title { margin: 0; color: var(--fs-text-strong); font-size: var(--fs-fs-lg); font-weight: 600; overflow-wrap: anywhere; }
.fs-l2tp-sub { color: var(--fs-text-muted); font-size: var(--fs-fs-sm); }
.fs-l2tp-facts { display: grid; grid-template-columns: repeat(auto-fit, minmax(10.5rem, 1fr)); gap: var(--fs-sp-3) var(--fs-sp-4); margin: 0; }
.fs-l2tp-facts dt { color: var(--fs-text-muted); font-size: var(--fs-fs-xs); font-weight: 500; text-transform: uppercase; letter-spacing: .03em; }
.fs-l2tp-facts dd { margin: .15rem 0 0; color: var(--fs-text-strong); overflow-wrap: anywhere; }
@media (max-width: 575.98px) { .fs-l2tp-facts { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>

<div class="panel panel-default fs-l2tp-summary">
	<div class="panel-body">
		<div class="fs-l2tp-head">
			<span class="fs-l2tp-icon"><i class="fa-solid <?=$this_secret_config ? 'fa-user' : 'fa-user-plus'?>" aria-hidden="true"></i></span>
			<div class="fs-l2tp-name">
				<h2 class="fs-l2tp-title"><?=$this_secret_config ? htmlspecialchars($this_secret_config['name']) : gettext('New L2TP user')?></h2>
				<div class="fs-l2tp-sub"><?=$this_secret_config ? gettext('L2TP user') : gettext('Users sign in to the L2TP server with this name and password.')?></div>
			</div>
		</div>
<?php if ($this_secret_config): ?>
		<dl class="fs-l2tp-facts">
			<div><dt><?=gettext('IP address')?></dt><dd><?php if (($this_secret_config['ip'] ?? '') !== ''): ?><span class="fs-mono"><?=htmlspecialchars($this_secret_config['ip'])?></span><?php else: ?><span class="fs-muted"><?=gettext('dynamic')?></span><?php endif; ?></dd></div>
			<div><dt><?=gettext('Authentication')?></dt><dd><?=htmlspecialchars($auth_label)?></dd></div>
		</dl>
<?php endif; ?>
	</div>
</div>
<?php
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
