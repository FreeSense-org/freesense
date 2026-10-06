<?php
/*
 * vpn_ipsec_keys.php
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
##|*IDENT=page-vpn-ipsec-listkeys
##|*NAME=VPN: IPsec: Pre-Shared Keys List
##|*DESCR=Allow access to the 'VPN: IPsec: Pre-Shared Keys List' page.
##|*MATCH=vpn_ipsec_keys.php*
##|-PRIV

require_once("functions.inc");
require_once("guiconfig.inc");
require_once("ipsec.inc");
require_once("vpn.inc");
require_once("filter.inc");
require_once("vpn_ipsec.inc");

$userkeys = array();
foreach (config_get_path('system/user', []) as $id => $user) {
	if (!empty($user['ipsecpsk'])) {
		$userkeys[] = array('ident' => $user['name'], 'type' => 'PSK', 'pre-shared-key' => $user['ipsecpsk'], 'id' => $id);;
	}
}

if (isset($_POST['apply'])) {
	$retval = ipsec_apply_changes();
}

if ($_POST['act'] == "del") {
	if (ipsec_psk_delete($_POST['id'])) {
		header("Location: vpn_ipsec_keys.php");
		exit;
	}
}

$pgtitle = array(gettext("VPN"), gettext("IPsec"), gettext("Pre-Shared Keys"));
$pglinks = array("", "vpn_ipsec.php", "@self");
$shortcut_section = "ipsec";

fs_page_action(gettext('Add key'), 'vpn_ipsec_keys_edit.php', 'fa-plus');
include("head.inc");

if ($_POST['apply']) {
	print_apply_result_box($retval);
}

if (is_subsystem_dirty('ipsec')) {
	print_apply_box(gettext("The IPsec tunnel configuration has been changed.") . "<br />" . gettext("The changes must be applied for them to take effect."));
}

fs_tabs('vpn-ipsec', 'vpn_ipsec_keys.php');
?>

<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Pre-Shared Keys'),
	'search' => gettext('Search pre-shared keys…'),
	'noun' => gettext('pre-shared keys'),
	'noun_one' => gettext('pre-shared key'),
]); ?>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-hover table-rowdblclickedit">
				<thead>
					<tr>
						<th data-fs-search><?=gettext("Identifier"); ?></th>
						<th data-fs-search><?=gettext("Type"); ?></th>
						<th data-fs-search><?=gettext("Pre-Shared Key"); ?></th>
						<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
					</tr>
				</thead>
				<tbody>
<?php $i = 0; foreach ($userkeys as $secretent): ?>
					<tr>
						<td>
							<?php
							if ($secretent['ident'] == 'allusers') {
								echo gettext("ANY USER");
							} else {
								echo htmlspecialchars($secretent['ident']);
							}
							?>
						</td>
						<td>
							<?php
							if (empty($secretent['type'])) {
								echo 'PSK';
							} else {
								echo htmlspecialchars($secretent['type']);
							}
							?>
						</td>
						<td>
							<?=htmlspecialchars($secretent['pre-shared-key'])?>
						</td>
						<td class="fs-col-actions">
<?=fs_row_actions([
								['custom', "system_usermanager.php?act=edit&userid=" . urlencode($secretent['id']), $secretent['ident'],
								    ['icon' => 'fa-solid fa-user-pen', 'label' => sprintf(gettext('Edit user %s'), $secretent['ident'])]],
							])?>
						</td>
					</tr>
<?php $i++; endforeach; ?>

<?php $i = 0; foreach (config_get_path('ipsec/mobilekey', []) as $secretent): ?>
					<tr>
						<td>
							<?=htmlspecialchars($secretent['ident'])?>
						</td>
						<td>
							<?php
							if (empty($secretent['type'])) {
								echo 'PSK';
							} else {
								echo htmlspecialchars($secretent['type']);
							}
							?>
						</td>
						<td>
							<?=htmlspecialchars($secretent['pre-shared-key'])?>
						</td>
						<td class="fs-col-actions">
<?=fs_row_actions([
								['edit', "vpn_ipsec_keys_edit.php?id={$i}", $secretent['ident']],
								['delete', "vpn_ipsec_keys.php?act=del&id={$i}", $secretent['ident'], ['thing' => gettext('pre-shared key')]],
							])?>
						</td>
					</tr>
<?php $i++; endforeach; ?>
<?php if (empty($userkeys) && empty(config_get_path('ipsec/mobilekey', []))) {
	fs_empty_row(4, gettext('No pre-shared keys yet.'), 'vpn_ipsec_keys_edit.php', gettext('Add key'));
} ?>
				</tbody>
			</table>
		</div>
	</div>
</div>

<div class="infoblock">
<?php
print_info_box(gettext("PSK for any user can be set by using an identifier of any."), 'info', false);
?>
</div>
<?php include("foot.inc"); ?>
