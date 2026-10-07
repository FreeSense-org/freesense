<?php
/*
 * firewall_nat.php
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
##|*IDENT=page-firewall-nat-portforward
##|*NAME=Firewall: NAT: Port Forward
##|*DESCR=Allow access to the 'Firewall: NAT: Port Forward' page.
##|*MATCH=firewall_nat.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("util.inc");
require_once("functions.inc");
require_once("filter.inc");
require_once("shaper.inc");
require_once("itemid.inc");
require_once("firewall_nat.inc");

$rdr_lcltype_flags = [SPECIALNET_IFADDR];
$rdr_srctype_flags = [SPECIALNET_ANY, SPECIALNET_CLIENTS, SPECIALNET_IFADDR, SPECIALNET_IFNET];
$rdr_dsttype_flags = [SPECIALNET_ANY, SPECIALNET_SELF, SPECIALNET_CLIENTS, SPECIALNET_IFADDR, SPECIALNET_IFNET, SPECIALNET_VIPS];

// Process $_POST/$_REQUEST =======================================================================
if ($_REQUEST['savemsg']) {
	$savemsg = $_REQUEST['savemsg'];
}

if (array_key_exists('order-store', $_REQUEST) && have_natpfruleint_access($natent['interface'])) {
	reorderNATrules($_POST);
} else if ($_POST['apply'] && have_natpfruleint_access($natent['interface'])) {
	$retval = applyNATrules();
} else if (($_POST['act'] == "del" || isset($_POST['del_x'])) && have_natpfruleint_access($natent['interface'])) {
	if ((is_numericint($_POST['id']) && config_get_path("nat/rule/{$_POST['id']}")) || (is_array($_POST['rule']) && count($_POST['rule']))) {
		deleteNATrule($_POST);
	}
} elseif (($_POST['act'] == "toggle" || isset($_POST['toggle_x'])) && have_natpfruleint_access($natent['interface'])) {
	if ((is_numericint($_POST['id']) && config_get_path("nat/rule/{$_POST['id']}")) || (is_array($_POST['rule']) && count($_POST['rule']))) {
		toggleNATrule($_POST);
	}
}

// Construct the page =============================================================================
$pgtitle = array(gettext("Firewall"), gettext("NAT"), gettext("Port Forward"));
$pglinks = array("", "@self", "@self");
if (isAllowedPage('firewall_nat_edit.php')) {
	fs_page_action(gettext('Add rule'), 'firewall_nat_edit.php', 'fa-plus');
	fs_page_action(gettext('Add rule to the top'), 'firewall_nat_edit.php?after=-1', 'fa-turn-up', 'secondary');
}
include("head.inc");

if ($_POST['apply']) {
	print_apply_result_box($retval);
}

if (is_subsystem_dirty('natconf') && have_natpfruleint_access($natent['interface'])) {
	print_apply_box(gettext('The NAT configuration has been changed.') . '<br />' .
					gettext('The changes must be applied for them to take effect.'));
}

fs_tabs('firewall-nat', 'firewall_nat.php');

$columns_in_table = 13;
?>
<style>
/* Phones and narrow windows scroll the rule table inside its card. From 992 px
 * the table may overflow as before, so dragging a rule past the bottom of the
 * window scrolls the page (the sortable start hook below does the same for
 * narrow windows). */
#mainarea.table-responsive { clear: both; margin-bottom: 0; }
@media (min-width: 992px) {
	#mainarea.table-responsive { overflow-x: visible; }
}
</style>

<form action="firewall_nat.php" method="post" name="iform">
	<div class="panel panel-default fs-table">
<?php ob_start(); ?>
<?php if (isAllowedPage('firewall_nat_edit.php')): ?>
		<button id="del_x" name="del_x" data-fs-confirm="<?=gettext('Delete the selected rules?')?>" data-fs-confirm-action="<?=gettext('Delete')?>" type="submit" class="btn btn-sm btn-outline-danger" disabled title="<?=gettext('Delete selected rules')?>">
			<i class="fa-solid fa-trash-can icon-embed-btn"></i>
			<?=gettext("Delete"); ?>
		</button>
		<button id="toggle_x" name="toggle_x" type="submit" class="btn btn-sm btn-outline-secondary" disabled value="<?=gettext("Toggle selected rules"); ?>" title="<?=gettext('Toggle selected rules')?>">
			<i class="fa-solid fa-ban icon-embed-btn"></i>
			<?=gettext("Toggle"); ?>
		</button>
		<button type="submit" id="order-store" name="order-store" class="btn btn-sm btn-outline-secondary" disabled title="<?=gettext('Save rule order')?>">
			<i class="fa-solid fa-floppy-disk icon-embed-btn"></i>
			<?=gettext("Save")?>
		</button>
		<button type="submit" id="addsep" name="addsep" class="btn btn-sm btn-outline-secondary" title="<?=gettext('Add separator')?>">
			<i class="fa-solid fa-plus icon-embed-btn"></i>
			<?=gettext("Separator")?>
		</button>
	<?php endif; ?>
<?php fs_table_toolbar([
	'title' => gettext('Rules'),
	'search' => gettext('Search rules…'),
	'noun' => gettext('rules'),
	'noun_one' => gettext('rule'),
	'actions' => ob_get_clean(),
]); ?>
		<div id="mainarea" class="panel-body table-responsive">
			<table id="ruletable" class="table table-hover table-rowdblclickedit">
				<thead>
					<tr>
						<th><input type="checkbox" id="selectAll" name="selectAll" /></th>
						<th><!-- Icon --></th>
						<th><!-- Rule type --></th>
						<th><?=gettext("Interface")?></th>
						<th><?=gettext("Protocol")?></th>
						<th><?=gettext("Source Address")?></th>
						<th><?=gettext("Source Ports")?></th>
						<th><?=gettext("Dest. Address")?></th>
						<th><?=gettext("Dest. Ports")?></th>
						<th><?=gettext("NAT IP")?></th>
						<th><?=gettext("NAT Ports")?></th>
						<th><?=gettext("Description")?></th>
						<th><?=gettext("Actions")?></th>
					</tr>
				</thead>
<?php if (count(get_anynat_rules_list('rdr')) == 0): ?>
				<tbody class="fs-rules-empty">
<?php	fs_empty_row($columns_in_table, gettext('No port forward rules yet.'), isAllowedPage('firewall_nat_edit.php') ? 'firewall_nat_edit.php' : null, gettext('Add rule')); ?>
				</tbody>
<?php endif; ?>
				<tbody class='user-entries'>
<?php

$nnats = $i = 0;
$separators = config_get_path('nat/separator', []);

// Get a list of separator rows and use it to call the display separator function only for rows which there are separator(s).
// More efficient than looping through the list of separators on every row.
$seprows = separator_rows($separators);

global $user_settings;
$show_system_alias_popup = (array_key_exists('webgui', $user_settings) && !$user_settings['webgui']['disablealiaspopupdetail']);
$system_alias_specialnet = get_specialnet('', [SPECIALNET_IFNET, SPECIALNET_GROUP]);
$system_aliases_ports = get_reserved_table_names('', 'port,url_ports,urltable_ports');
$system_aliases_hosts = get_reserved_table_names('', 'host,network,url,urltable');
foreach (get_anynat_rules_list('rdr') as $natent):

	// Display separator(s) for section beginning at rule n
	if ($seprows[$nnats]) {
		display_separator($separators, $nnats, $columns_in_table);
	}

	$localport = $natent['local-port'];

	list($dstbeginport, $dstendport) = explode("-", $natent['destination']['port']);

	if ($dstendport && is_port($localport)) {
		$localendport = $natent['local-port'] + $dstendport - $dstbeginport;
		$localport	 .= '-' . $localendport;
	}

	$alias = rule_columns_with_alias(
		$natent['source']['address'],
		pprint_port($natent['source']['port']),
		$natent['destination']['address'],
		pprint_port($natent['destination']['port']),
		$natent['target'],
		$localport
	);

	if (isset($natent['disabled'])) {
		$iconfn = "pass_d";
		$trclass = 'class="disabled"';
	} else {
		$iconfn = "pass";
		$trclass = '';
	}

?>

					<tr id="fr<?=$nnats;?>" <?=$trclass?> onClick="fr_toggle(<?=$nnats;?>)">
						<td >
<?php	if (have_natpfruleint_access($natent['interface'])): ?>
							<input type="checkbox" id="frc<?=$nnats;?>" onClick="fr_toggle(<?=$nnats;?>)" name="rule[]" value="<?=$i;?>"/>
<?php	endif; ?>
						</td>
						<td>
<?php	if (have_natpfruleint_access($natent['interface'])): ?>
							<a href="?act=toggle&amp;id=<?=$i?>" usepost>
								<i class="fa-solid fa-check" title="<?=gettext("click to toggle enabled/disabled status")?>"></i>
							</a>
<?php	endif; ?>
<?php 	if (isset($natent['nordr'])) { ?>
								&nbsp;<i class="fa-regular fa-hand text-danger" title="<?=gettext("Negated: This rule excludes NAT from a later rule")?>"></i>
<?php 	} ?>
						</td>
						<td>
<?php
	if ($natent['associated-rule-id'] == "pass"):
?>
							<i class="fa-solid fa-play" title="<?=gettext("All traffic matching this NAT entry is passed")?>"></i>
<?php
	elseif (!empty($natent['associated-rule-id'])):
?>
							<i class="fa-solid fa-shuffle" title="<?=sprintf(gettext("Firewall rule ID %s is managed by this rule"), htmlspecialchars($natent['associated-rule-id']))?>"></i>
<?php
	endif;
?>
						</td>
						<td>
							<?=$textss?>
<?php
	if (!$natent['interface']) {
		echo htmlspecialchars(convert_friendly_interface_to_friendly_descr("wan"));
	} else {
		echo htmlspecialchars(convert_friendly_interface_to_friendly_descr($natent['interface']));
	}
?>
							<?=$textse?>
						</td>

						<td>
							<?=$textss?><?=strtoupper($natent['protocol'])?><?=$textse?>
						</td>

						<td>
							<?php if (isset($alias['src'])): ?>
								<a href="/firewall_aliases_edit.php?id=<?=$alias['src']?>" data-bs-toggle="popover" data-bs-trigger="hover focus" title="<?=gettext('Alias details')?>" data-bs-content="<?=alias_info_popup($alias['src'])?>" data-bs-html="true">
									<?=str_replace('_', '_<wbr>', htmlspecialchars(pprint_address($natent['source'], $rdr_srctype_flags)))?>
								</a>
							<?php elseif ($show_system_alias_popup && array_key_exists($natent['source']['network'], $system_alias_specialnet)): ?>
								<a data-bs-toggle="popover" data-bs-trigger="hover focus" title="<?=gettext('System alias details')?>" data-bs-content="<?=alias_info_popup(strtoupper($natent['source']['network']) . '__NETWORK', true)?>" data-bs-html="true">
									<?=str_replace('_', '_<wbr>', htmlspecialchars(pprint_address($natent['source'], $rdr_srctype_flags)))?>
								</a>
							<?php elseif ($show_system_alias_popup && array_key_exists($natent['source']['address'], $system_aliases_hosts)): ?>
								<a data-bs-toggle="popover" data-bs-trigger="hover focus" title="<?=gettext('System alias details')?>" data-bs-content="<?=alias_info_popup(strtolower($natent['source']['address']), true)?>" data-bs-html="true">
									<?=str_replace('_', '_<wbr>', htmlspecialchars(pprint_address($natent['source'])))?>
								</a>
							<?php else: ?>
								<?=htmlspecialchars(pprint_address($natent['source'], $rdr_srctype_flags))?>
							<?php endif; ?>
						</td>
						<td>
						<?php if (isset($alias['srcport'])): ?>
							<a href="/firewall_aliases_edit.php?id=<?=$alias['srcport']?>" data-bs-toggle="popover" data-bs-trigger="hover focus" title="<?=gettext('Alias details')?>" data-bs-content="<?=alias_info_popup($alias['srcport'])?>" data-bs-html="true">
								<?=str_replace('_', '_<wbr>', htmlspecialchars(pprint_port($natent['source']['port'])))?>
							</a>
						<?php elseif ($show_system_alias_popup && array_key_exists($natent['source']['port'], $system_aliases_ports)): ?>
							<a data-bs-toggle="popover" data-bs-trigger="hover focus" title="<?=gettext('System alias details')?>" data-bs-content="<?=alias_info_popup(strtolower($natent['source']['port']), true)?>" data-bs-html="true">
								<?=str_replace('_', '_<wbr>', htmlspecialchars(pprint_port($natent['source']['port'])))?>
							</a>
						<?php else: ?>
							<?=str_replace('_', '_<wbr>', htmlspecialchars(pprint_port($natent['source']['port'])))?>
						<?php endif; ?>
						</td>

						<td>
							<?php if (isset($alias['dst'])): ?>
								<a href="/firewall_aliases_edit.php?id=<?=$alias['dst']?>" data-bs-toggle="popover" data-bs-trigger="hover focus" title="<?=gettext('Alias details')?>" data-bs-content="<?=alias_info_popup($alias['dst'])?>" data-bs-html="true">
									<?=str_replace('_', '_<wbr>', htmlspecialchars(pprint_address($natent['destination'], $rdr_dsttype_flags)))?>
								</a>
							<?php elseif ($show_system_alias_popup && array_key_exists($natent['destination']['network'], $system_alias_specialnet)): ?>
								<a data-bs-toggle="popover" data-bs-trigger="hover focus" title="<?=gettext('System alias details')?>" data-bs-content="<?=alias_info_popup(strtoupper($natent['destination']['network']) . '__NETWORK', true)?>" data-bs-html="true">
									<?=str_replace('_', '_<wbr>', htmlspecialchars(pprint_address($natent['destination'], $rdr_dsttype_flags)))?>
								</a>
							<?php elseif ($show_system_alias_popup && array_key_exists($natent['destination']['address'], $system_aliases_hosts)): ?>
								<a data-bs-toggle="popover" data-bs-trigger="hover focus" title="<?=gettext('System alias details')?>" data-bs-content="<?=alias_info_popup(strtolower($natent['destination']['address']), true)?>" data-bs-html="true">
									<?=str_replace('_', '_<wbr>', htmlspecialchars(pprint_address($natent['destination'])))?>
								</a>
							<?php else: ?>
								<?=htmlspecialchars(pprint_address($natent['destination'], $rdr_dsttype_flags))?>
							<?php endif; ?>
						</td>
						<td>
						<?php if (isset($alias['dstport'])): ?>
							<a href="/firewall_aliases_edit.php?id=<?=$alias['dstport']?>" data-bs-toggle="popover" data-bs-trigger="hover focus" title="<?=gettext('Alias details')?>" data-bs-content="<?=alias_info_popup($alias['dstport'])?>" data-bs-html="true">
								<?=str_replace('_', '_<wbr>', htmlspecialchars(pprint_port($natent['destination']['port'])))?>
							</a>
						<?php elseif ($show_system_alias_popup && array_key_exists($natent['destination']['port'], $system_aliases_ports)): ?>
							<a data-bs-toggle="popover" data-bs-trigger="hover focus" title="<?=gettext('System alias details')?>" data-bs-content="<?=alias_info_popup(strtolower($natent['destination']['port']), true)?>" data-bs-html="true">
								<?=str_replace('_', '_<wbr>', htmlspecialchars(pprint_port($natent['destination']['port'])))?>
							</a>
						<?php else: ?>
							<?=str_replace('_', '_<wbr>', htmlspecialchars(pprint_port($natent['destination']['port'])))?>
						<?php endif; ?>
						</td>
						<td>
							<?php if (isset($alias['target'])): ?>
								<a href="/firewall_aliases_edit.php?id=<?=$alias['target']?>" data-bs-toggle="popover" data-bs-trigger="hover focus" title="<?=gettext('Alias details')?>" data-bs-content="<?=alias_info_popup($alias['target'])?>" data-bs-html="true">
									<?=str_replace('_', '_<wbr>', htmlspecialchars(pprint_address(['network' => $natent['target']], $rdr_lcltype_flags)))?>
								</a>
							<?php elseif ($show_system_alias_popup && array_key_exists($natent['target'], $system_aliases_hosts)): ?>
								<a data-bs-toggle="popover" data-bs-trigger="hover focus" title="<?=gettext('System alias details')?>" data-bs-content="<?=alias_info_popup(strtolower($natent['target']), true)?>" data-bs-html="true">
									<?=str_replace('_', '_<wbr>', htmlspecialchars(pprint_address(['address' => $natent['target']])))?>
								</a>
							<?php else: ?>
								<?=htmlspecialchars(pprint_address(['network' => $natent['target']], $rdr_lcltype_flags))?>
							<?php endif; ?>
						</td>
						<td>
						<?php if (isset($alias['targetport'])): ?>
							<a href="/firewall_aliases_edit.php?id=<?=$alias['targetport']?>" data-bs-toggle="popover" data-bs-trigger="hover focus" title="<?=gettext('Alias details')?>" data-bs-content="<?=alias_info_popup($alias['targetport'])?>" data-bs-html="true">
								<?=str_replace('_', '_<wbr>', htmlspecialchars(pprint_port($localport)))?>
							</a>
						<?php elseif ($show_system_alias_popup && array_key_exists($localport, $system_aliases_ports)): ?>
							<a data-bs-toggle="popover" data-bs-trigger="hover focus" title="<?=gettext('System alias details')?>" data-bs-content="<?=alias_info_popup(strtolower($localport), true)?>" data-bs-html="true">
								<?=str_replace('_', '_<wbr>', htmlspecialchars(pprint_port($localport)))?>
							</a>
						<?php else: ?>
							<?=str_replace('_', '_<wbr>', htmlspecialchars(pprint_port($localport)))?>
						<?php endif; ?>
						</td>

						<td>
							<?=htmlspecialchars($natent['descr'])?>
						</td>
						<td>
<?php	if (have_natpfruleint_access($natent['interface'])): ?>
							<?=fs_row_actions([
								['edit', "firewall_nat_edit.php?id={$i}", $natent['descr'] ?: sprintf(gettext('rule %d'), $i + 1)],
								['copy', "firewall_nat_edit.php?dup={$i}", $natent['descr'] ?: sprintf(gettext('rule %d'), $i + 1)],
								['delete', "firewall_nat.php?act=del&id={$i}", $natent['descr'] ?: sprintf(gettext('rule %d'), $i + 1), ['thing' => gettext('rule')]],
							])?>
<?php	else: ?>
							-
<?php	endif; ?>
						</td>
					</tr>
<?php
	$i++;
	$nnats++;

endforeach;

// There can be separator(s) after the last rule listed.
if ($seprows[$nnats]) {
	display_separator($separators, $nnats, $columns_in_table);
}
?>
				</tbody>
			</table>
		</div>
	</div>

</form>

<script type="text/javascript">
//<![CDATA[
//Need to create some variables here so that jquery/FreeSenseHelpers.js can read them
iface = "<?=strtolower($if)?>";
cncltxt = '<?=gettext("Cancel")?>';
svtxt = '<?=gettext("Save")?>';
svbtnplaceholder = '<?=gettext("Enter a description, Save, then drag to final location.")?>';
configsection = "nat";
dirty = false;

events.push(function() {

<?php if(!config_path_enabled('system/webgui', 'roworderdragging')): ?>
	// Make rules sortable
	$('table tbody.user-entries').sortable({
		cursor: 'grabbing',
		start: function(event, ui) {
			// Below 992 px the table scrolls inside its card; scroll the page while dragging
			$(this).sortable('instance').scrollParent = $(document);
		},
		update: function(event, ui) {
			$('#order-store').removeAttr('disabled');
			dirty = true;
			reindex_rules(ui.item.parent('tbody'));
			dirty = true;
		}
	});
<?php endif; ?>

	// Check all of the rule checkboxes so that their values are posted
	$('#order-store').click(function () {
	   $('[id^=frc]').prop('checked', true);

		// Save the separator bar configuration
		save_separators();

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
	// Unfortunately the custom message is not supported in modern browsers, but he user wil lat
	// least see a generic warning message
	$(window).bind('beforeunload', function(){
		if (!saving && dirty) {
			return ("<?=gettext('One or more Port Forward rules have been moved but have not yet been saved')?>");
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

if (count(get_anynat_rules_list('rdr')) > 0) {
?>
<p class="fs-muted small">
	<i class="fa-solid fa-play" aria-hidden="true"></i> <?=gettext('Pass')?>
	&nbsp;&nbsp;<i class="fa-solid fa-shuffle" aria-hidden="true"></i> <?=gettext('Linked rule')?>
</p>

<?php
}

include("foot.inc");
