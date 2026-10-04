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

/* Returns why the VXLAN cannot be deleted, or false when it is unused. */
function vxlan_inuse($num) {
	$vxlanif = config_get_path("vxlans/vxlan/{$num}/vxlanif");
	if (empty($vxlanif)) {
		return false;
	}

	$friendly = convert_real_interface_to_friendly_interface_name($vxlanif);
	if (!empty($friendly)) {
		if (!empty(link_interface_to_bridge($friendly))) {
			return gettext("This VXLAN cannot be deleted because it is a bridge member.");
		}
		if (!empty(link_interface_to_group($friendly))) {
			return gettext("This VXLAN cannot be deleted because it is a member of an interface group.");
		}
		return gettext("This VXLAN cannot be deleted because it is still being used as an interface.");
	}
	foreach (config_get_path('vlans/vlan', []) as $vlan) {
		if ($vlan['if'] == $vxlanif) {
			return gettext("This VXLAN cannot be deleted because it is the parent of a VLAN.");
		}
	}
	foreach (config_get_path('qinqs/qinqentry', []) as $qinq) {
		if ($qinq['if'] == $vxlanif) {
			return gettext("This VXLAN cannot be deleted because it is the parent of a QinQ.");
		}
	}
	foreach (config_get_path('laggs/lagg', []) as $lagg) {
		if (in_array($vxlanif, explode(',', $lagg['members']))) {
			return gettext("This VXLAN cannot be deleted because it is a LAGG member.");
		}
	}

	return false;
}

if ($_POST['act'] == "del") {
	if (!isset($_POST['id'])) {
		$input_errors[] = gettext("Wrong parameters supplied");
	} else if (empty(config_get_path("vxlans/vxlan/{$_POST['id']}"))) {
		$input_errors[] = gettext("Wrong index supplied");
	/* check if still in use */
	} else if (($inuse = vxlan_inuse($_POST['id'])) !== false) {
		$input_errors[] = $inuse;
	} else {
		FreeSense_interface_destroy(config_get_path("vxlans/vxlan/{$_POST['id']}/vxlanif"));
		config_del_path("vxlans/vxlan/{$_POST['id']}");

		write_config("VXLAN interface deleted");

		/* drop the tunnel's pass rule */
		filter_configure();

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
					<tr>
						<td>
							<?=htmlspecialchars($vxlan['vxlanif'])?>
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
