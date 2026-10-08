<?php
/*
 * interfaces_assign.php
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

if (!empty($unused_portlist)) {
	fs_page_action(gettext('Add interface'), '#', 'fa-plus', 'primary', ['data-fs-modal' => '#assign-add']);
}

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


/*Generate the port select box only once.
Not indenting the HTML to produce smaller code
and faster page load times */

$portselect='';
foreach ($portlist as $portname => $portinfo) {
	$portselect.='<option value="'.$portname.'"';
	$portselect.=">".$ifdescrs[$portname]."</option>\n";
}

fs_tabs('interfaces', 'interfaces_assign.php');

$type_labels = ['dhcp' => 'DHCP', 'pppoe' => 'PPPoE', 'pptp' => 'PPTP', 'l2tp' => 'L2TP', 'ppp' => 'PPP',
    'dhcp6' => 'DHCPv6', 'slaac' => 'SLAAC', 'track6' => gettext('Track interface'), '6rd' => '6rd', '6to4' => '6to4'];
?>
<form action="interfaces_assign.php" method="post" id="fs-assign-form">
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Interfaces'),
	'search' => gettext('Search interfaces, ports, addresses…'),
	'noun' => gettext('interfaces'),
	'noun_one' => gettext('interface'),
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover">
			<thead>
				<tr>
					<th data-fs-search><?=gettext("Interface")?></th>
					<th data-fs-search><?=gettext("Network port")?></th>
					<th data-fs-search><?=gettext("Addresses")?></th>
					<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
				</tr>
			</thead>
			<tbody>
<?php
	foreach (config_get_path('interfaces', []) as $ifname => $iface):
		$ifdescr = $iface['descr'] ? $iface['descr'] : strtoupper($ifname);
		$addrs = [];
		foreach ([['ipaddr', 'subnet'], ['ipaddrv6', 'subnetv6']] as $af) {
			$addr = $iface[$af[0]] ?? '';
			if ($addr === '' || $addr === 'none') {
				continue;
			}
			$addrs[] = isset($type_labels[$addr]) ? $type_labels[$addr] : $addr . (empty($iface[$af[1]]) ? '' : '/' . $iface[$af[1]]);
		}
		$actions = [['edit', 'interfaces.php?if=' . $ifname, $ifdescr]];
		if ($ifname != 'wan') {
			$actions[] = ['delete', 'interfaces_assign.php?del[' . $ifname . ']=' . $gettextArray['delete'], $ifdescr, [
				'thing' => gettext('interface'),
				'detail' => gettext('Its settings are removed. The network port becomes available again.'),
			]];
		}
?>
				<tr>
					<td>
						<a class="fs-assign-name" href="/interfaces.php?if=<?=$ifname?>"><?=htmlspecialchars($ifdescr)?></a>
						<?php if (!isset($iface['enable'])): ?><?=fs_badge('disabled')?><?php endif; ?>
						<div class="fs-mono fs-muted small"><?=htmlspecialchars($ifname)?></div>
					</td>
					<td>
						<select name="<?=$ifname?>" id="<?=$ifname?>" class="form-control fs-assign-port" aria-label="<?=htmlspecialchars(sprintf(gettext('Network port for %s'), $ifdescr))?>" data-fs-initial="<?=htmlspecialchars($iface['if'])?>">
<?php
/*port select menu generation loop replaced with pre-prepared select menu to reduce page generation time */
echo str_replace('value="'.$iface['if'].'">','value="'.$iface['if'].'" selected>',$portselect);
?>
						</select>
					</td>
					<td class="fs-mono small"><?=$addrs ? implode('<br>', array_map('htmlspecialchars', $addrs)) : '<span class="fs-muted">' . gettext('none') . '</span>'?></td>
					<td class="fs-col-actions"><?=fs_row_actions($actions)?></td>
				</tr>
<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<div class="panel-footer small fs-muted">
		<i class="fa-solid fa-circle-info" aria-hidden="true"></i>
		<?=gettext("Ports that are members of a LAGG are not listed. Wireless interfaces must be created on the Wireless tab before they can be assigned.")?>
	</div>
</div>

<div class="fs-actionbar fs-actionbar--plain">
	<button name="Submit" type="submit" class="btn btn-primary" value="<?=gettext('Save')?>"><i class="fa-solid fa-floppy-disk icon-embed-btn" aria-hidden="true"></i><?=gettext('Save')?></button>
	<span class="fs-assign-dirty fs-muted small" hidden><i class="fa-solid fa-circle-exclamation" aria-hidden="true"></i><?=gettext('Port changes are not saved yet.')?></span>
</div>
</form>

<?php
if (!empty($unused_portlist)) {
	fs_modal_form_begin('assign-add', gettext('Add interface'));
?>
	<div class="mb-3">
		<label class="form-label" for="if_add"><?=gettext('Network port')?></label>
		<select class="form-select" name="if_add" id="if_add">
<?php foreach ($unused_portlist as $portname => $portinfo): ?>
			<option value="<?=htmlspecialchars($portname)?>"><?=htmlspecialchars($ifdescrs[$portname])?></option>
<?php endforeach; ?>
		</select>
		<div class="form-text"><?=gettext('The new interface is named OPTn and starts disabled; edit it afterwards to set addresses.')?></div>
	</div>
<?php
	fs_modal_form_end(gettext('Add'), 'add', 'add interface', 'fa-plus');
}
?>

<style>
.fs-assign-name { font-weight: 600; margin-right: .4rem; }
.fs-assign-port { min-width: 16rem; max-width: 28rem; }
tr.fs-assign-changed > td { background-color: var(--fs-accent-tint); }
tr.fs-assign-changed .fs-assign-port { border-color: var(--fs-coral); }
.fs-assign-dirty { display: inline-flex; align-items: center; gap: .4rem; margin-left: var(--fs-sp-3); }
.fs-assign-dirty > i { color: var(--fs-coral); }
</style>
<script>
//<![CDATA[
(function () {
	var form = document.getElementById('fs-assign-form');
	var note = form.querySelector('.fs-assign-dirty');
	function sync() {
		var dirty = false;
		var selects = form.querySelectorAll('select.fs-assign-port');
		var used = {};
		selects.forEach(function (s) {
			used[s.value] = (used[s.value] || 0) + 1;
		});
		selects.forEach(function (s) {
			var changed = s.value !== s.getAttribute('data-fs-initial');
			s.closest('tr').classList.toggle('fs-assign-changed', changed);
			/* one port per interface; the server rejects duplicates on save */
			s.classList.toggle('is-invalid', used[s.value] > 1);
			dirty = dirty || changed;
		});
		note.hidden = !dirty;
	}
	form.addEventListener('change', sync);
	sync();
})();
//]]>
</script>

<?php include("foot.inc")?>
