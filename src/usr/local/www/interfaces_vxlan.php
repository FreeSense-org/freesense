<?php
/*
 * interfaces_vxlan.php
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
##|*IDENT=page-interfaces-vxlan
##|*NAME=Interfaces: VXLAN
##|*DESCR=Allow access to the 'Interfaces: VXLAN' page.
##|*MATCH=interfaces_vxlan.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("interfaces_tunnels.inc");

if ($_POST['act'] == "del") {
	if (interfaces_vxlan_delete($_POST['id'] ?? null, $input_errors)) {
		header("Location: interfaces_vxlan.php");
		exit;
	}
}

$pgtitle = array(gettext("Interfaces"), gettext("VXLANs"));
$shortcut_section = "interfaces";
fs_page_action(gettext('Add VXLAN'), 'interfaces_vxlan_edit.php', 'fa-plus');
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

fs_tabs('interfaces', 'interfaces_vxlan.php');
?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('VXLAN Interfaces'),
	'search' => gettext('Search VXLANs…'),
	'noun' => gettext('VXLANs'),
	'noun_one' => gettext('VXLAN'),
]); ?>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-hover table-rowdblclickedit">
				<thead>
					<tr>
						<th data-fs-search><?=gettext("Interface"); ?></th>
						<th data-fs-search><?=gettext("Parent"); ?></th>
						<th data-fs-search><?=gettext("VNI"); ?></th>
						<th data-fs-search><?=gettext("Remote / Group"); ?></th>
						<th data-fs-search><?=gettext("Description"); ?></th>
						<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
					</tr>
				</thead>
				<tbody>
<?php foreach (config_get_path('vxlans/vxlan', []) as $i => $vxlan): ?>
<?php
	$assigned = empty($vxlan['vxlanif']) ? '' : convert_real_interface_to_friendly_interface_name($vxlan['vxlanif']);
?>
					<tr>
						<td>
							<?=htmlspecialchars($vxlan['vxlanif'])?>
<?php	if (!empty($assigned)): ?>
							(<?=htmlspecialchars(convert_friendly_interface_to_friendly_descr($assigned))?>)
<?php	endif; ?>
<?php	if (empty($vxlan['vxlanif']) || !does_interface_exist($vxlan['vxlanif'])): ?>
							<i class="fa-solid fa-triangle-exclamation text-warning" title="<?=gettext('The interface does not exist. The parent may have no address of the selected family yet; the tunnel is created when it gets one. Check the system log.')?>"></i>
<?php	endif; ?>
						</td>
						<td>
							<?=htmlspecialchars(convert_friendly_interface_to_friendly_descr($vxlan['if']))?>
						</td>
						<td>
							<?=htmlspecialchars($vxlan['vni'])?>
						</td>
						<td>
							<?=htmlspecialchars(($vxlan['mode'] == 'multicast') ? $vxlan['mcastgroup'] : $vxlan['remote-addr'])?>
							<?=($vxlan['mode'] == 'multicast') ? ' (' . gettext('multicast') . ')' : ''?>
						</td>
						<td>
							<?=htmlspecialchars($vxlan['descr'])?>
						</td>
						<td class="fs-col-actions">
							<?=fs_row_actions([
								['edit', "interfaces_vxlan_edit.php?id={$i}", $vxlan['vxlanif'] ?: $vxlan['descr']],
								['delete', "interfaces_vxlan.php?act=del&id={$i}", $vxlan['vxlanif'] ?: $vxlan['descr'], ['thing' => gettext('VXLAN')]],
							])?>
						</td>
					</tr>
<?php endforeach; ?>
<?php if (empty(config_get_path('vxlans/vxlan', []))) {
	fs_empty_row(6, gettext('No VXLANs yet.'), 'interfaces_vxlan_edit.php', gettext('Add VXLAN'));
} ?>
				</tbody>
			</table>
		</div>
	</div>
</div>


<?php include("foot.inc");
