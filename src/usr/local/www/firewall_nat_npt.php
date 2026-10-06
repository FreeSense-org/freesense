<?php
/*
 * firewall_nat_npt.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
 * Copyright (c) 2011 Seth Mos <seth.mos@dds.nl>
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
##|*IDENT=page-firewall-nat-npt
##|*NAME=Firewall: NAT: NPt
##|*DESCR=Allow access to the 'Firewall: NAT: NPt' page.
##|*MATCH=firewall_nat_npt.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("functions.inc");
require_once("filter.inc");
require_once("shaper.inc");
require_once("firewall_nat_npt.inc");

// Process $_POST/$_REQUEST =======================================================================
if ($_REQUEST['savemsg']) {
	$savemsg = $_REQUEST['savemsg'];
}

if (array_key_exists('order-store', $_REQUEST)) {
	reordernptNATrules($_POST);
} elseif ($_POST['apply']) {
	$retval = applynptNATrules();
} elseif (($_POST['act'] == "del")) {
	if (config_get_path("nat/npt/{$_POST['id']}")) {
		deletenptNATrule($_POST);
	}
} elseif (isset($_POST['del_x'])) {
	/* delete selected rules */
	if (is_array($_POST['rule']) && count($_POST['rule'])) {
		deleteMultiplenptNATrules($_POST);
	}
} elseif (isset($_POST['toggle_x'])) {
	if (is_array($_POST['rule']) && count($_POST['rule'])) {
		toggleMultiplenptNATrules($_POST);
	}
} elseif (($_POST['act'] == "toggle")) {
	if (config_get_path("nat/npt/{$_POST['id']}")) {
		togglenptNATrule($_POST);
	}
}

$pgtitle = array(gettext("Firewall"), gettext("NAT"), gettext("NPt"));
$pglinks = array("", "firewall_nat.php", "@self");
if (isAllowedPage('firewall_nat_npt_edit.php')) {
	fs_page_action(gettext('Add mapping'), 'firewall_nat_npt_edit.php', 'fa-plus');
	fs_page_action(gettext('Add mapping to the top'), 'firewall_nat_npt_edit.php?after=-1', 'fa-turn-up', 'secondary');
}
include("head.inc");

if ($_POST['apply']) {
	print_apply_result_box($retval);
}

if (is_subsystem_dirty('natconf')) {
	print_apply_box(gettext('The NAT configuration has been changed.') . '<br />' .
	   gettext('The changes must be applied for them to take effect.'));
}

fs_tabs('firewall-nat', 'firewall_nat_npt.php');
?>
<form action="firewall_nat_npt.php" method="post">
	<div class="panel panel-default fs-table">
<?php ob_start(); ?>
<?php if (isAllowedPage('firewall_nat_npt_edit.php')): ?>
		<button id="del_x" name="del_x" data-fs-confirm="<?=gettext('Delete the selected mappings?')?>" data-fs-confirm-action="<?=gettext('Delete')?>" type="submit" class="btn btn-sm btn-outline-danger" disabled title="<?=gettext('Delete selected mappings')?>">
			<i class="fa-solid fa-trash-can icon-embed-btn"></i>
			<?=gettext("Delete"); ?>
		</button>
		<button id="toggle_x" name="toggle_x" type="submit" class="btn btn-sm btn-outline-secondary" disabled value="<?=gettext("Toggle selected mappings"); ?>" title="<?=gettext('Toggle selected rules')?>">
			<i class="fa-solid fa-ban icon-embed-btn"></i>
			<?=gettext("Toggle"); ?>
		</button>
		<button type="submit" id="order-store" name="order-store" class="btn btn-sm btn-outline-secondary" disabled title="<?=gettext('Save mapping order')?>">
			<i class="fa-solid fa-floppy-disk icon-embed-btn"></i>
			<?=gettext("Save")?>
		</button>
	<?php endif; ?>
<?php fs_table_toolbar([
	'title' => gettext('NPt Mappings'),
	'search' => gettext('Search mappings…'),
	'noun' => gettext('mappings'),
	'noun_one' => gettext('mapping'),
	'actions' => ob_get_clean(),
]); ?>
		<div id="mainarea" class="table-responsive panel-body">
			<table id="ruletable" class="table table-hover table-rowdblclickedit">
				<thead>
					<tr>
						<th><input type="checkbox" id="selectAll" name="selectAll" /></th>
						<th><!-- icon --></th>
						<th><?=gettext("Interface")?></th>
						<th><?=gettext("External Prefix")?></th>
						<th><?=gettext("Internal prefix")?></th>
						<th><?=gettext("Description")?></th>
						<th><?=gettext("Actions")?></th>
					</tr>
				</thead>
				<tbody class="user-entries">
<?php

	$textse = "</span>";
	$i = 0;
	foreach (get_anynat_rules_list('npt') as $natent):
		if (isset($natent['disabled'])) {
			$textss = "<span class=\"gray\">";
			$iconfn = "pass_d";
			$trclass = 'class="disabled"';
		} else {
			$textss = "<span>";
			$iconfn = "pass";
			$trclass = '';
		}
?>
					<tr id="fr<?=$i;?>" <?=$trclass?> onClick="fr_toggle(<?=$i;?>)">
						<td >
							<input type="checkbox" id="frc<?=$i;?>" onClick="fr_toggle(<?=$i;?>)" name="rule[]" value="<?=$i;?>"/>
						</td>
						<td>
							<a href="?act=toggle&amp;id=<?=$i?>" usepost>
								<i class="fa-solid <?= ($iconfn == "pass") ? "fa-check":"fa-xmark"?>" title="<?=gettext("click to toggle enabled/disabled status")?>"></i>
							</a>
						</td>
						<td>
<?php
		echo $textss;
		if (!$natent['interface']) {
			echo htmlspecialchars(convert_friendly_interface_to_friendly_descr("wan"));
		} else {
			echo htmlspecialchars(convert_friendly_interface_to_friendly_descr($natent['interface']));
		}
		echo $textse;
?>
						</td>
						<td>
<?php
		if (!empty($natent['destination']['network'])) {
			if (str_contains($natent['destination']['network'], '/')) {
				$dst_arr = explode("/", $natent['destination']['network']);
				$natent['destination']['network'] = $dst_arr[0];
			}
			if (config_get_path("interfaces/{$natent['destination']['network']}/ipaddrv6") == 'track6') {
				$track6ip = get_interface_track6ip($natent['destination']['network']);
				$pdsubnet = gen_subnetv6($track6ip[0], $track6ip[1]);
				$dst = config_get_path("interfaces/{$natent['destination']['network']}/descr") . " ({$pdsubnet}/{$track6ip[1]})";
			}
		} else {
			$dst = pprint_address($natent['destination']);
		}
		echo $textss . $dst . $textse;
?>
						</td>
						<td>
<?php
	echo $textss . pprint_address($natent['source']) . $textse;
?>
						</td>
						<td>
<?php
					echo $textss . htmlspecialchars($natent['descr']) . '&nbsp;' . $textse;
?>
						</td>
						<td>
							<?=fs_row_actions([
								['edit', "firewall_nat_npt_edit.php?id={$i}", $natent['descr'] ?: sprintf(gettext('mapping %d'), $i + 1)],
								['copy', "firewall_nat_npt_edit.php?dup={$i}", $natent['descr'] ?: sprintf(gettext('mapping %d'), $i + 1)],
								['delete', "firewall_nat_npt.php?act=del&id={$i}", $natent['descr'] ?: sprintf(gettext('mapping %d'), $i + 1), ['thing' => gettext('mapping')]],
							])?>
						</td>
					</tr>
<?php
	$i++;
endforeach;
?>
<?php if ($i == 0) {
	fs_empty_row(7, gettext('No NPt mappings yet.'), isAllowedPage('firewall_nat_npt_edit.php') ? 'firewall_nat_npt_edit.php' : null, gettext('Add mapping'));
} ?>
				</tbody>
			</table>
		</div>
	</div>

</form>

<script type="text/javascript">
//<![CDATA[
events.push(function() {

<?php if(!config_path_enabled('system/webgui', 'roworderdragging')): ?>
	// Make rules draggable/sortable
	$('table tbody.user-entries').sortable({
		cursor: 'grabbing',
		update: function(event, ui) {
			$('#order-store').removeAttr('disabled');
			dirty = true;
		}
	});
<?php endif; ?>

	// Check all of the rule checkboxes so that their values are posted
	$('#order-store').click(function () {
	   $('[id^=frc]').prop('checked', true);

		// Suppress the "Do you really want to leave the page" message
		saving = true;
	});

	$('[id^=fr]').click(function () {
		buttonsmode('frc', ['del_x', 'toggle_x']);
	});

	// Globals
	saving = false;
	dirty = false;

	// provide a warning message if the user tries to change page before saving
	$(window).bind('beforeunload', function(){
		if (!saving && dirty) {
			return ("<?=gettext('One or more NPt mappings have been moved but have not yet been saved')?>");
		} else {
			return undefined;
		}
	});

	$('#selectAll').click(function() {
		var checkedStatus = this.checked;
		$('#ruletable tbody tr').find('td:first :checkbox').each(function() {
		$(this).prop('checked', checkedStatus);
		});
		buttonsmode('frc', ['del_x', 'toggle_x']);
	});
});
//]]>
</script>

<?php

include("foot.inc");
