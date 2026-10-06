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

fs_page_action(gettext('Add user'), 'vpn_l2tp_users_edit.php', 'fa-plus');
include("head.inc");

if (config_path_enabled('l2tp/radius')) {
	print_info_box(gettext("RADIUS is enabled. The local user database will not be used."));
}

fs_tabs('vpn-l2tp', 'vpn_l2tp_users.php');
?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('L2TP Users'),
	'search' => gettext('Search L2TP users…'),
	'noun' => gettext('L2TP users'),
	'noun_one' => gettext('L2TP user'),
]); ?>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-hover table-rowdblclickedit">
				<thead>
					<tr>
						<th data-fs-search><?=gettext("Username")?></th>
						<th data-fs-search><?=gettext("IP address")?></th>
						<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
					</tr>
				</thead>
				<tbody>
<?php $i = 0; foreach (config_get_path('l2tp/user', []) as $secretent):?>
					<tr>
						<td>
							<?=htmlspecialchars($secretent['name'])?>
						</td>
						<td>
							<?php if ($secretent['ip'] == "") $secretent['ip'] = "Dynamic"?>
							<?=htmlspecialchars($secretent['ip'])?>&nbsp;
						</td>
						<td class="fs-col-actions">
<?=fs_row_actions([
								['edit', "vpn_l2tp_users_edit.php?id={$i}", $secretent['name']],
								['delete', "vpn_l2tp_users.php?act=del&id={$i}", $secretent['name'], ['thing' => gettext('L2TP user')]],
							])?>
						</td>
					</tr>
<?php $i++; endforeach?>
<?php if (empty(config_get_path('l2tp/user', []))) {
	fs_empty_row(3, gettext('No L2TP users yet.'), 'vpn_l2tp_users_edit.php', gettext('Add user'));
} ?>
				</tbody>
			</table>
		</div>
	</div>
</div>

<?php include("foot.inc");
