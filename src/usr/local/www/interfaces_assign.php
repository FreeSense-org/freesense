<?php
/*
 * interfaces_assign.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
 * All rights reserved.
 *
 * originally based on m0n0wall (http://m0n0.ch/wall)
 * Copyright (c) 2003-2004 Manuel Kasper <mk@neon1.net>.
 * Written by Jim McBeath based on existing m0n0wall files
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
##|*IDENT=page-interfaces-assignnetworkports
##|*NAME=Interfaces: Interface Assignments
##|*DESCR=Allow access to the 'Interfaces: Interface Assignments' page.
##|*MATCH=interfaces_assign.php*
##|-PRIV

//$timealla = microtime(true);

$pgtitle = array(gettext("Interfaces"), gettext("Interface Assignments"));
$shortcut_section = "interfaces";

require_once("guiconfig.inc");
require_once("functions.inc");
require_once("filter.inc");
require_once("shaper.inc");
require_once("ipsec.inc");
require_once("vpn.inc");
require_once("openvpn.inc");
require_once("captiveportal.inc");
require_once("rrd.inc");
require_once("interfaces_fast.inc");
require_once("firewall_nat.inc");
require_once("interfaces_assign.inc");

global $friendlyifnames;

/*moved most gettext calls to here, we really don't want to be repeatedly calling gettext() within loops if it can be avoided.*/
$gettextArray = array('add'=>gettext('Add'),'addif'=>gettext('Add interface'),'delete'=>gettext('Delete'),'deleteif'=>gettext('Delete interface'),'edit'=>gettext('Edit'),'on'=>gettext('on'));

/*
	In this file, "port" refers to the physical port name,
	while "interface" refers to LAN, WAN, or OPTn.
	The add, save, delete and apply logic is in interfaces_assign.inc.
*/

/*another *_fast function from interfaces_fast.inc. These functions are basically the same as the
ones they're named after, except they (usually) take an array and (always) return an array. This means that they only
need to be called once per script run, the returned array contains all the data necessary for repeated use */
$friendlyifnames = convert_real_interface_to_friendly_interface_name_fast();

$portlist = interfaces_assign_port_list();
$ifdescrs = interface_assign_description_fast($portlist,$friendlyifnames);

if (isset($_POST['add']) && isset($_POST['if_add'])) {
	if (interfaces_assign_add($_POST['if_add'], $portlist, $input_errors) !== null) {
		$action_msg = gettext("Interface has been added.");
		$class = "success";
	}

} else if (isset($_POST['apply'])) {
	$applied = interfaces_assign_apply();
	if ($applied['rebooting']) {
		$rebootingnow = true;
	} else {
		$changes_applied = true;
		$retval = $applied['retval'];
	}

} else if (isset($_POST['Submit'])) {

	unset($input_errors);
	interfaces_assign_save($_POST, $portlist, $input_errors);

} else {
	unset($delbtn);
	if (!empty($_POST['del']) && is_array($_POST['del']) && is_string(key($_POST['del']))) {
		$delbtn = key($_POST['del']);
	}

	if (isset($delbtn)) {
		if (interfaces_assign_delete($delbtn, $input_errors)) {
			$action_msg = gettext("Interface has been deleted.");
			$class = "success";
		}
	}
}

/* Create a list of unused ports */
$unused_portlist = interfaces_assign_unused_ports($portlist);

include("head.inc");

if (file_exists("/var/run/interface_mismatch_reboot_needed")) {
	if ($_POST) {
		if ($rebootingnow) {
			$action_msg = gettext("The system is now rebooting. Please wait.");
			$class = "success";
		} else {
			$applymsg = gettext("Reboot is needed. Please apply the settings in order to reboot.");
			$class = "warning";
		}
	} else {
		$action_msg = gettext("Interface mismatch detected. Please resolve the mismatch, save and then click 'Apply Changes'. The firewall will reboot afterwards.");
		$class = "warning";
	}
}

if (file_exists("/tmp/reload_interfaces")) {
	echo "<p>\n";
	print_apply_box(gettext("The interface configuration has been changed.") . "<br />" . gettext("The changes must be applied for them to take effect."));
	echo "<br /></p>\n";
} elseif ($applymsg) {
	print_apply_box($applymsg);
} elseif ($action_msg) {
	print_info_box($action_msg, $class);
} elseif ($changes_applied) {
	print_apply_result_box($retval);
}

FreeSense_handle_custom_code("/usr/local/pkg/interfaces_assign/pre_input_errors");

if ($input_errors) {
	print_input_errors($input_errors);
}

$tab_array = array();
$tab_array[] = array(gettext("Interface Assignments"), true, "interfaces_assign.php");
$tab_array[] = array(gettext("Interface Groups"), false, "interfaces_groups.php");
$tab_array[] = array(gettext("Wireless"), false, "interfaces_wireless.php");
$tab_array[] = array(gettext("VLANs"), false, "interfaces_vlan.php");
$tab_array[] = array(gettext("QinQs"), false, "interfaces_qinq.php");
$tab_array[] = array(gettext("PPPs"), false, "interfaces_ppps.php");
$tab_array[] = array(gettext("GREs"), false, "interfaces_gre.php");
$tab_array[] = array(gettext("GIFs"), false, "interfaces_gif.php");
$tab_array[] = array(gettext("VXLANs"), false, "interfaces_vxlan.php");
$tab_array[] = array(gettext("Bridges"), false, "interfaces_bridge.php");
$tab_array[] = array(gettext("LAGGs"), false, "interfaces_lagg.php");
display_top_tabs($tab_array);

/*Generate the port select box only once.
Not indenting the HTML to produce smaller code
and faster page load times */

$portselect='';
foreach ($portlist as $portname => $portinfo) {
	$portselect.='<option value="'.$portname.'"';
	$portselect.=">".$ifdescrs[$portname]."</option>\n";
}

?>
<form action="interfaces_assign.php" method="post">
	<div class="table-responsive">
	<table class="table table-striped table-hover">
	<thead>
		<tr>
			<th><?=gettext("Interface")?></th>
			<th><?=gettext("Network port")?></th>
			<th>&nbsp;</th>
		</tr>
	</thead>
	<tbody>
<?php
	$i=0;
	foreach (config_get_path('interfaces', []) as $ifname => $iface):
		if ($iface['descr']) {
			$ifdescr = $iface['descr'];
		} else {
			$ifdescr = strtoupper($ifname);
		}
?>
		<tr>
			<td><a href="/interfaces.php?if=<?=$ifname?>"><?=$ifdescr?></a></td>
			<td>
				<select name="<?=$ifname?>" id="<?=$ifname?>" class="form-control">
<?php
/*port select menu generation loop replaced with pre-prepared select menu to reduce page generation time */
echo str_replace('value="'.$iface['if'].'">','value="'.$iface['if'].'" selected>',$portselect);
?>
				</select>
			</td>
			<td>
<?php if ($ifname != 'wan'):?>
				<button type="submit" name="del[<?=$ifname?>]" class="btn btn-danger btn-sm" title="<?=$gettextArray['deleteif']?>">
					<i class="fa-solid fa-trash-can icon-embed-btn"></i>
					<?=$gettextArray["delete"]?>
				</button>
<?php endif;?>
			</td>
		</tr>
<?php $i++;
endforeach;
	if (count(config_get_path('interfaces', [])) < count($portlist)):
?>
		<tr>
			<th>
				<?=gettext("Available network ports:")?>
			</th>
			<td>
				<select name="if_add" id="if_add" class="form-control">
<?php
/* HTML not indented to save on transmission/render time */
foreach ($unused_portlist as $portname => $portinfo):?>
<option value="<?=$portname?>" <?=($portname == $iface['if']) ? ' selected': ''?>><?=$ifdescrs[$portname]?></option>
<?php endforeach;
?>
				</select>
			</td>
			<td>
				<button type="submit" name="add" title="<?=gettext("Add selected interface")?>" value="add interface" class="btn btn-success btn-sm" >
					<i class="fa-solid fa-plus icon-embed-btn"></i>
					<?=$gettextArray["add"]?>
				</button>
			</td>
		</tr>
<?php endif;?>
		</tbody>
	</table>
	</div>

	<button name="Submit" type="submit" class="btn btn-primary" value="<?=gettext('Save')?>"><i class="fa-solid fa-save icon-embed-btn"></i><?=gettext('Save')?></button>
</form>
<br />

<?php
print_info_box(gettext("Interfaces that are configured as members of a lagg(4) interface will not be shown.") .
    '<br/><br/>' .
    gettext("Wireless interfaces must be created on the Wireless tab before they can be assigned."), 'info', false);
?>

<?php include("foot.inc")?>
