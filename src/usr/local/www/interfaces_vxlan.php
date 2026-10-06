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
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

$tab_array = array();
$tab_array[] = array(gettext("Interface Assignments"), false, "interfaces_assign.php");
$tab_array[] = array(gettext("Interface Groups"), false, "interfaces_groups.php");
$tab_array[] = array(gettext("Wireless"), false, "interfaces_wireless.php");
$tab_array[] = array(gettext("VLANs"), false, "interfaces_vlan.php");
$tab_array[] = array(gettext("QinQs"), false, "interfaces_qinq.php");
$tab_array[] = array(gettext("PPPs"), false, "interfaces_ppps.php");
$tab_array[] = array(gettext("GREs"), false, "interfaces_gre.php");
$tab_array[] = array(gettext("GIFs"), false, "interfaces_gif.php");
$tab_array[] = array(gettext("VXLANs"), true, "interfaces_vxlan.php");
$tab_array[] = array(gettext("Bridges"), false, "interfaces_bridge.php");
$tab_array[] = array(gettext("LAGGs"), false, "interfaces_lagg.php");
display_top_tabs($tab_array);
?>
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('VXLAN Interfaces')?></h2></div>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-striped table-hover table-sm table-rowdblclickedit">
				<thead>
					<tr>
						<th><?=gettext("Interface"); ?></th>
						<th><?=gettext("Parent"); ?></th>
						<th><?=gettext("VNI"); ?></th>
						<th><?=gettext("Remote / Group"); ?></th>
						<th><?=gettext("Description"); ?></th>
						<th><?=gettext("Actions"); ?></th>
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
						<td>
							<a class="fa-solid fa-pencil"	title="<?=gettext('Edit VXLAN interface')?>"	href="interfaces_vxlan_edit.php?id=<?=$i?>"></a>
							<a class="fa-solid fa-trash-can"	title="<?=gettext('Delete VXLAN interface')?>"	href="interfaces_vxlan.php?act=del&amp;id=<?=$i?>" usepost></a>
						</td>
					</tr>
<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>

<nav class="action-buttons">
	<a href="interfaces_vxlan_edit.php" class="btn btn-success btn-sm">
		<i class="fa-solid fa-plus icon-embed-btn"></i>
		<?=gettext("Add")?>
	</a>
</nav>

<?php include("foot.inc");
