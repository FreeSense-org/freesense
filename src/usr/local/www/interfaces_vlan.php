<?php
/*
 * interfaces_vlan.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
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
##|*IDENT=page-interfaces-vlan
##|*NAME=Interfaces: VLAN
##|*DESCR=Allow access to the 'Interfaces: VLAN' page.
##|*MATCH=interfaces_vlan.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("interfaces_fast.inc");
require_once("interfaces_tunnels.inc");

global $profile;

if ($_POST['act'] == "del") {
	/*
	 * Check user privileges to test if the user is allowed to make changes.
	 * Otherwise users can end up in an inconsistent state where some changes are
	 * performed and others denied. See upstream issue 15282
	 */
	if (interfaces_vlan_delete($_POST['id'] ?? null, interfaces_gui_read_only(), $input_errors)) {
		header("Location: interfaces_vlan.php");
		exit;
	}
}


$pgtitle = array(gettext("Interfaces"), gettext("VLANs"));
$shortcut_section = "interfaces";
fs_page_action(gettext('Add VLAN'), 'interfaces_vlan_edit.php', 'fa-plus');
include('head.inc');

if ($input_errors) print_input_errors($input_errors);

fs_tabs('interfaces', 'interfaces_vlan.php');

?>
<form action="interfaces_vlan.php" method="post">

	<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('VLAN Interfaces'),
	'search' => gettext('Search VLANs…'),
	'noun' => gettext('VLANs'),
	'noun_one' => gettext('VLAN'),
]); ?>
		<div class="panel-body">
			<div class="table-responsive">
				<table class="table table-hover table-rowdblclickedit" data-sortable>
					<thead>
						<tr>
							<th data-fs-search><?=gettext('Interface');?></th>
							<th data-fs-search><?=gettext('VLAN tag');?></th>
							<th data-fs-search><?=gettext('Priority');?></th>
							<th data-fs-search><?=gettext('Description');?></th>
							<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
						</tr>
					</thead>
					<tbody>
<?php
	$i = 0;
	$ifaces = convert_real_interface_to_friendly_interface_name_fast();
	foreach (config_get_path('vlans/vlan', []) as $vlan) {
?>
						<tr>
							<td>
<?php
	printf("%s", htmlspecialchars($vlan['if']));
	if (isset($ifaces[$vlan['if']]) && strlen($ifaces[$vlan['if']]) > 0)
		printf(" (%s)", htmlspecialchars($ifaces[$vlan['if']]));
?>
							</td>
							<td><?=htmlspecialchars($vlan['tag']);?></td>
							<td><?=htmlspecialchars($vlan['pcp']);?></td>
							<td><?=htmlspecialchars($vlan['descr']);?></td>
							<td class="fs-col-actions">
								<?=fs_row_actions([
									['edit', "interfaces_vlan_edit.php?id={$i}", $vlan['vlanif'] ?: $vlan['descr']],
									['delete', "interfaces_vlan.php?act=del&id={$i}", $vlan['vlanif'] ?: $vlan['descr'], ['thing' => gettext('VLAN')]],
								])?>
							</td>
						</tr>
<?php
			$i++;
	}
?>
<?php if (empty(config_get_path('vlans/vlan', []))) {
	fs_empty_row(5, gettext('No VLANs yet.'), 'interfaces_vlan_edit.php', gettext('Add VLAN'));
} ?>
					</tbody>
				</table>
			</div>
		</div>
	</div>


</form>

<div class="infoblock">
	<?php print_info_box(sprintf(gettext('Not all drivers/NICs support 802.1Q '.
		'VLAN tagging properly. %1$sOn cards that do not explicitly support it, VLAN '.
		'tagging will still work, but the reduced MTU may cause problems.%1$sSee the '.
		'%2$s handbook for information on supported cards.'), '<br />', g_get('product_label')), 'info', false); ?>
</div>

<?php
include("foot.inc");
