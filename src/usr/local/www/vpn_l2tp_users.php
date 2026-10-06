<?php
/*
 * vpn_l2tp_users.php
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
##|*IDENT=page-vpn-vpnl2tp-users
##|*NAME=VPN: L2TP: Users
##|*DESCR=Allow access to the 'VPN: L2TP: Users' page.
##|*MATCH=vpn_l2tp_users.php*
##|-PRIV

$pgtitle = array(gettext("VPN"), gettext("L2TP"), gettext("Users"));
$pglinks = array("", "vpn_l2tp.php", "@self");
$shortcut_section = "l2tps";

require_once("guiconfig.inc");
require_once("freesense-utils.inc");
require_once("vpn.inc");
require_once("vpn_l2tp.inc");

$pconfig = $_POST;

if ($_POST['act'] == "del") {
	if (l2tp_user_delete($_POST['id'])) {
		FreeSenseHeader("vpn_l2tp_users.php");
		exit;
	}
}

$users = config_get_path('l2tp/user', []);
$settings = l2tp_settings_form();
$server_on = ($settings['mode'] == 'server');
$radius_on = (bool)$settings['radiusenable'];
$auth_types = l2tp_paporchap_values();
$static_count = count(array_filter($users, function ($u) {
	return ($u['ip'] ?? '') !== '';
}));

/*
 * Add / edit open a modal that posts to vpn_l2tp_users_edit.php, so saving keeps
 * that page's privilege, validation and error display (and the REST API mapping).
 */
$can_edit = isAllowedPage('vpn_l2tp_users_edit.php');
if ($can_edit) {
	fs_page_action(gettext('Add user'), 'vpn_l2tp_users_edit.php', 'fa-plus', 'primary', [
		'data-fs-modal' => '#l2tp-user',
		'data-fs-modal-title' => gettext('Add user'),
		'data-fs-fill' => json_encode(['id' => '']),
	]);
}
include("head.inc");

if ($radius_on) {
	print_info_box(gettext("RADIUS is enabled. The local user database will not be used."), 'warning');
}

fs_tabs('vpn-l2tp', 'vpn_l2tp_users.php');
?>
<style>
.fs-l2tp-user { display: inline-flex; align-items: center; gap: .55rem; font-weight: 600; color: var(--fs-text-strong); }
.fs-l2tp-user > i { color: var(--fs-text-muted); font-weight: 400; }
</style>

<div class="fs-tiles">
<?php
fs_tile(gettext('L2TP server'), $server_on ? $settings['localip'] : gettext('Off'), $server_on ? 'enabled' : 'disabled',
    $server_on ? sprintf(gettext('Up to %s clients at once'), $settings['n_l2tp_units']) : gettext('Turn it on under Configuration'));
fs_tile(gettext('Users'), (string)count($users), null,
    sprintf(ngettext('%d with a static address', '%d with a static address', $static_count), $static_count));
fs_tile(gettext('Authentication'), $radius_on ? 'RADIUS' : ($auth_types[$settings['paporchap']] ?? 'CHAP'), null,
    $radius_on ? gettext('Local users are not used') : gettext('Against the users below'));
?>
</div>

<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Users'),
	'search' => gettext('Search users, addresses…'),
	'noun' => gettext('users'),
	'noun_one' => gettext('user'),
	'filters' => ['ip' => [gettext('All addresses'), 'static' => gettext('Static address'), 'dynamic' => gettext('Dynamic address')]],
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover table-rowdblclickedit" data-sortable>
			<thead>
				<tr>
					<th data-fs-search><?=gettext("Username")?></th>
					<th data-fs-search><?=gettext("IP address")?></th>
					<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
				</tr>
			</thead>
			<tbody>
<?php foreach ($users as $i => $secretent):
	$name = (string)$secretent['name'];
	$ip = (string)($secretent['ip'] ?? '');
	$actions = [];
	if ($can_edit) {
		$actions[] = ['edit', 'vpn_l2tp_users_edit.php?id=' . (int)$i, htmlspecialchars($name), ['attrs' => [
			'data-fs-modal' => '#l2tp-user',
			'data-fs-modal-title' => sprintf(gettext('Edit “%s”'), $name),
			'data-fs-fill' => json_encode(['id' => (string)$i, 'usernamefld' => $name, 'ip' => $ip]),
		]]];
	}
	$actions[] = ['delete', 'vpn_l2tp_users.php?act=del&id=' . (int)$i, htmlspecialchars($name), ['thing' => gettext('L2TP user')]];
?>
				<tr data-fs-filter-ip="<?=($ip !== '') ? 'static' : 'dynamic'?>">
					<td><span class="fs-l2tp-user"><i class="fa-solid fa-user" aria-hidden="true"></i><?=htmlspecialchars($name)?></span></td>
					<td><?php if ($ip !== ''): ?><span class="fs-mono"><?=htmlspecialchars($ip)?></span><?php else: ?><span class="fs-muted"><?=gettext('dynamic')?></span><?php endif; ?></td>
					<td class="fs-col-actions"><?=fs_row_actions($actions)?></td>
				</tr>
<?php endforeach; ?>
<?php if (empty($users)) {
	fs_empty_row(3, gettext('No L2TP users yet.'));
} ?>
			</tbody>
		</table>
	</div>
	<div class="panel-footer small fs-muted">
		<i class="fa-solid fa-circle-info" aria-hidden="true"></i>
		<?=gettext('Users without a static address get one from the remote address range. Changes take effect for new connections.')?>
	</div>
</div>
<?php
if ($can_edit) {
	/* add / edit one user; posts to the editor page (same fields, same handler) */
	fs_modal_form_begin('l2tp-user', gettext('Add user'), 'vpn_l2tp_users_edit.php');
?>
	<input type="hidden" name="id" value="">
	<div class="mb-3">
		<label class="form-label" for="l2tp-user-name"><?=gettext('Username')?></label>
		<input class="form-control" id="l2tp-user-name" name="usernamefld" required autocomplete="new-password">
		<div class="form-text"><?=gettext('Letters, digits and . @ - _ only.')?></div>
	</div>
	<div class="mb-3">
		<label class="form-label" for="l2tp-user-pw"><?=gettext('Password')?></label>
		<input class="form-control" type="password" id="l2tp-user-pw" name="passwordfld" autocomplete="new-password">
		<input class="form-control mt-2" type="password" id="l2tp-user-pw2" name="passwordfld_confirm" autocomplete="new-password" aria-label="<?=gettext('Confirm password')?>" placeholder="<?=gettext('Confirm')?>">
		<div class="form-text"><?=gettext('Required for a new user. When editing, leave empty to keep the current password.')?></div>
	</div>
	<div class="mb-3">
		<label class="form-label" for="l2tp-user-ip"><?=gettext('IP address')?></label>
		<input class="form-control fs-mono" id="l2tp-user-ip" name="ip" placeholder="<?=gettext('dynamic')?>">
		<div class="form-text"><?=gettext('Optional. A fixed address for this user; leave empty to use the remote range.')?></div>
	</div>
<?php
	fs_modal_form_end(gettext('Save'), 'save', 'Save', 'fa-floppy-disk');
}

include("foot.inc");
