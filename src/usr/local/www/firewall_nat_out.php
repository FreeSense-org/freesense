<?php
/*
 * firewall_nat_out.php
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
##|*IDENT=page-firewall-nat-outbound
##|*NAME=Firewall: NAT: Outbound
##|*DESCR=Allow access to the 'Firewall: NAT: Outbound' page.
##|*MATCH=firewall_nat_out.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("functions.inc");
require_once("filter.inc");
require_once("shaper.inc");
require_once("firewall_nat_out.inc");

global $FilterIflist;
global $GatewaysList;

$nat_srctype_flags = [SPECIALNET_ANY, SPECIALNET_SELF, SPECIALNET_IFNET, SPECIALNET_GROUP];
$nat_dsttype_flags = [SPECIALNET_ANY, SPECIALNET_IFNET, SPECIALNET_GROUP];
$nat_tgttype_flags = [SPECIALNET_NETAL, SPECIALNET_IFADDR, SPECIALNET_VIPS];

// update rule order, POST[rule] is an array of ordered IDs
// All rule are 'checked' before posting
if (isset($_REQUEST['order-store'])) {
	outNATrulesreorder($_POST);
}

if (config_get_path('nat/outbound/mode') === null) {
	config_set_path('nat/outbound/mode', "automatic");
}

$mode = config_get_path('nat/outbound/mode');

if ($_POST['apply']) {
	$retval = applyoutNATrules();
} elseif ($_POST['save']) {
	saveNAToutMode($_POST);
} elseif ($_POST['act'] == "del") {
	deleteoutNATrule($_POST);
} elseif (isset($_POST['rule']) &&
    !empty($_POST['rule']) &&
    is_array($_POST['rule'])) {
	if (isset($_POST['del_x'])) {
		/* Delete selected rules, but only when given valid data
		 * See upstream issue 12694 */
		deleteMultipleoutNATrules($_POST);
	} elseif (isset($_POST['toggle_x'])) {
		toggleMultipleoutNATrules($_POST);
	}
} elseif ($_POST['act'] == "toggle") {
	toggleoutNATrule($_POST);
}

$pgtitle = array(gettext("Firewall"), gettext("NAT"), gettext("Outbound"));
$pglinks = array("", "firewall_nat.php", "@self");
if (isAllowedPage('firewall_nat_out_edit.php')) {
	fs_page_action(gettext('Add mapping'), 'firewall_nat_out_edit.php', 'fa-plus');
	fs_page_action(gettext('Add mapping to the top'), 'firewall_nat_out_edit.php?after=-1', 'fa-turn-up', 'secondary');
}
include("head.inc");

if ($default_rules_msg) {
	print_info_box($default_rules_msg, 'success');
}

if ($_POST['apply']) {
	print_apply_result_box($retval);
}

if (is_subsystem_dirty('natconf')) {
	print_apply_box(gettext('The NAT configuration has been changed.') . '<br />' .
					gettext('The changes must be applied for them to take effect.'));
}

fs_tabs('firewall-nat', 'firewall_nat_out.php');

$form = new Form();

$section = new Form_Section('Outbound NAT Mode');

$group = new Form_Group('Mode');

$group->add(new Form_Checkbox(
	'mode',
	'Mode',
	null,
	$mode == 'automatic',
	'automatic'
))->displayAsRadio()->setHelp('Automatic outbound NAT rule generation.%s(IPsec passthrough included)', '<br />');

$group->add(new Form_Checkbox(
	'mode',
	null,
	null,
	$mode == 'hybrid',
	'hybrid'
))->displayAsRadio()->setHelp('Hybrid Outbound NAT rule generation.%s(Automatic Outbound NAT + rules below)', '<br />');

$group->add(new Form_Checkbox(
	'mode',
	null,
	null,
	$mode == 'advanced',
	'advanced'
))->displayAsRadio()->setHelp('Manual Outbound NAT rule generation.%s(AON - Advanced Outbound NAT)', '<br />');

$group->add(new Form_Checkbox(
	'mode',
	null,
	null,
	$mode == 'disabled',
	'disabled'
))->displayAsRadio()->setHelp('Disable Outbound NAT rule generation.%s(No Outbound NAT rules)', '<br />');

$section->add($group);

$form->add($section);
print($form);

global $user_settings;
$show_system_alias_popup = (array_key_exists('webgui', $user_settings) && !$user_settings['webgui']['disablealiaspopupdetail']);
$system_alias_specialnet = get_specialnet('', [SPECIALNET_IFNET, SPECIALNET_GROUP]);
$system_aliases_ports = get_reserved_table_names('', 'port,url_ports,urltable_ports');
$system_aliases_hosts = get_reserved_table_names('', 'host,network,url,urltable');
?>

<form action="firewall_nat_out.php" method="post" name="iform">
	<div class="panel panel-default fs-table">
<?php ob_start(); ?>
<?php if (isAllowedPage('firewall_nat_out_edit.php')): ?>
		<button id="del_x" name="del_x" data-fs-confirm="<?=gettext('Delete the selected mappings?')?>" data-fs-confirm-action="<?=gettext('Delete')?>" type="submit" class="btn btn-sm btn-outline-danger" value="<?=gettext("Delete selected map"); ?>" disabled title="<?=gettext('Delete selected maps')?>">
			<i class="fa-solid fa-trash-can icon-embed-btn"></i>
			<?=gettext("Delete"); ?>
		</button>
		<button id="toggle_x" name="toggle_x" type="submit" class="btn btn-sm btn-outline-secondary" value="<?=gettext("Toggle selected rules"); ?>" disabled title="<?=gettext('Toggle selected rules')?>">
			<i class="fa-solid fa-ban icon-embed-btn"></i>
			<?=gettext("Toggle"); ?>
		</button>
		<button type="submit" id="order-store" class="btn btn-sm btn-outline-secondary" value="Save changes" disabled name="order-store" title="<?=gettext('Save mapping order')?>">
			<i class="fa-solid fa-floppy-disk icon-embed-btn"></i>
			<?=gettext("Save")?>
		</button>
	<?php endif; ?>
<?php fs_table_toolbar([
	'title' => gettext('Mappings'),
	'search' => gettext('Search mappings…'),
	'noun' => gettext('mappings'),
	'noun_one' => gettext('mapping'),
	'actions' => ob_get_clean(),
]); ?>
		<div class="panel-body table-responsive">
			<table id="ruletable" class="table table-hover table-rowdblclickedit">
				<thead>
					<tr>
						<th><input type="checkbox" id="selectAll" name="selectAll" /></th>
						<th><!-- status	  --></th>
						<th><?=gettext("Interface")?></th>
						<th><?=gettext("Source")?></th>
						<th><?=gettext("Source Port")?></th>
						<th><?=gettext("Destination")?></th>
						<th><?=gettext("Destination Port")?></th>
						<th><?=gettext("NAT Address")?></th>
						<th><?=gettext("NAT Port")?></th>
						<th><?=gettext("Static Port")?></th>
						<th><?=gettext("Description")?></th>
						<th><?=gettext("Actions")?></th>
					</tr>
				</thead>
				<tbody class="user-entries">
<?php
			$i = 0;
			foreach (get_anynat_rules_list('nat') as $natent):
				$iconfn = "pass";
				$textss = $textse = "";
				$trclass = '';

				if ($mode == "disabled" || $mode == "automatic" || isset($natent['disabled'])) {
					$iconfn .= "_d";
					$trclass = 'class="disabled"';

				}


				$alias = rule_columns_with_alias(
					$natent['source']['network'],
					pprint_port($natent['sourceport']),
					$natent['destination']['network'],
					pprint_port($natent['dstport']),
					$natent['target']
				);
?>

					<tr id="fr<?=$i;?>" <?=$trclass?> onClick="fr_toggle(<?=$i;?>)">
						<td >
							<input type="checkbox" id="frc<?=$i;?>" onClick="fr_toggle(<?=$i;?>)" name="rule[]" value="<?=$i;?>"/>
						</td>

						<td>
<?php
				if ($mode == "disabled" || $mode == "automatic"):
?>
							<i class="fa-solid <?= ($iconfn == "pass") ? "fa-check":"fa-xmark"?>" title="<?=gettext("This rule is being ignored")?>"></i>
<?php
				else:
?>
							<a href="?act=toggle&amp;id=<?=$i?>" usepost>
								<i class="fa-solid <?= ($iconfn == "pass") ? "fa-check":"fa-xmark"?>" title="<?=gettext("Click to toggle enabled/disabled status")?>"></i>
							</a>

<?php
				endif;
?>
<?php 				if (isset($natent['nonat'])): ?>
							&nbsp;<i class="fa-regular fa-hand text-danger" title="<?=gettext("Negated: Traffic matching this rule is not translated.")?>"></i>
<?php 				endif; ?>

						</td>

						<td>
							<?=htmlspecialchars(convert_friendly_interface_to_friendly_descr($natent['interface']))?>
						</td>

						<td>
							<?php if (isset($alias['src'])): ?>
								<a href="/firewall_aliases_edit.php?id=<?=$alias['src']?>" data-bs-toggle="popover" data-bs-trigger="hover focus" title="<?=gettext('Alias details')?>" data-bs-content="<?=alias_info_popup($alias['src'])?>" data-bs-html="true">
									<?=str_replace('_', '_<wbr>', htmlspecialchars(pprint_address($natent['source'], $nat_srctype_flags)))?>
								</a>
							<?php elseif ($show_system_alias_popup && array_key_exists($natent['source']['network'], $system_alias_specialnet)): ?>
								<a data-bs-toggle="popover" data-bs-trigger="hover focus" title="<?=gettext('System alias details')?>" data-bs-content="<?=alias_info_popup(strtoupper($natent['source']['network']) . '__NETWORK', true)?>" data-bs-html="true">
									<?=str_replace('_', '_<wbr>', htmlspecialchars(pprint_address($natent['source'], $nat_srctype_flags)))?>
								</a>
							<?php elseif ($show_system_alias_popup && array_key_exists($natent['source']['network'], $system_aliases_hosts)): ?>
								<a data-bs-toggle="popover" data-bs-trigger="hover focus" title="<?=gettext('System alias details')?>" data-bs-content="<?=alias_info_popup(strtolower($natent['source']['network']), true)?>" data-bs-html="true">
									<?=str_replace('_', '_<wbr>', htmlspecialchars(pprint_address($natent['source'])))?>
								</a>
							<?php else: ?>
								<?=htmlspecialchars(pprint_address($natent['source'], $nat_srctype_flags))?>
							<?php endif; ?>
						</td>

						<td>
<?php
						echo ($natent['protocol']) ? $natent['protocol'] . '/' : "" ;
?>
						<?php if (!$natent['sourceport']): ?>
							&ast;
						<?php elseif (isset($alias['srcport'])): ?>
							<a href="/firewall_aliases_edit.php?id=<?=$alias['srcport']?>" data-bs-toggle="popover" data-bs-trigger="hover focus" title="<?=gettext('Alias details')?>" data-bs-content="<?=alias_info_popup($alias['srcport'])?>" data-bs-html="true">
								<?=str_replace('_', '_<wbr>', htmlspecialchars(pprint_port($natent['sourceport'])))?>
							</a>
						<?php elseif ($show_system_alias_popup && array_key_exists($natent['sourceport'], $system_aliases_ports)): ?>
							<a data-bs-toggle="popover" data-bs-trigger="hover focus" title="<?=gettext('System alias details')?>" data-bs-content="<?=alias_info_popup(strtolower($natent['sourceport']), true)?>" data-bs-html="true">
								<?=str_replace('_', '_<wbr>', htmlspecialchars(pprint_port($natent['sourceport'])))?>
							</a>
						<?php else: ?>
							<?=str_replace('_', '_<wbr>', htmlspecialchars(pprint_port($natent['sourceport'])))?>
						<?php endif; ?>
						</td>

						<td>
							<?php if (isset($alias['dst'])): ?>
								<a href="/firewall_aliases_edit.php?id=<?=$alias['dst']?>" data-bs-toggle="popover" data-bs-trigger="hover focus" title="<?=gettext('Alias details')?>" data-bs-content="<?=alias_info_popup($alias['dst'])?>" data-bs-html="true">
									<?=str_replace('_', '_<wbr>', htmlspecialchars(pprint_address($natent['destination'], $nat_dsttype_flags)))?>
								</a>
							<?php elseif ($show_system_alias_popup && array_key_exists($natent['destination']['network'], $system_alias_specialnet)): ?>
								<a data-bs-toggle="popover" data-bs-trigger="hover focus" title="<?=gettext('System alias details')?>" data-bs-content="<?=alias_info_popup(strtoupper($natent['destination']['network']) . '__NETWORK', true)?>" data-bs-html="true">
									<?=str_replace('_', '_<wbr>', htmlspecialchars(pprint_address($natent['destination'], $nat_dsttype_flags)))?>
								</a>
							<?php elseif ($show_system_alias_popup && array_key_exists($natent['destination']['network'], $system_aliases_hosts)): ?>
								<a data-bs-toggle="popover" data-bs-trigger="hover focus" title="<?=gettext('System alias details')?>" data-bs-content="<?=alias_info_popup(strtolower($natent['destination']['network']), true)?>" data-bs-html="true">
									<?=str_replace('_', '_<wbr>', htmlspecialchars(pprint_address($natent['destination'])))?>
								</a>
							<?php else: ?>
								<?=htmlspecialchars(pprint_address($natent['destination'], $nat_dsttype_flags))?>
							<?php endif; ?>
						</td>

						<td>
<?php
						echo ($natent['protocol']) ? $natent['protocol'] . '/' : "" ;
?>
						<?php if (!$natent['dstport']): ?>
							&ast;
						<?php elseif (isset($alias['dstport'])): ?>
							<a href="/firewall_aliases_edit.php?id=<?=$alias['dstport']?>" data-bs-toggle="popover" data-bs-trigger="hover focus" title="<?=gettext('Alias details')?>" data-bs-content="<?=alias_info_popup($alias['dstport'])?>" data-bs-html="true">
								<?=str_replace('_', '_<wbr>', htmlspecialchars(pprint_port($natent['dstport'])))?>
							</a>
						<?php elseif ($show_system_alias_popup && array_key_exists($natent['dstport'], $system_aliases_ports)): ?>
							<a data-bs-toggle="popover" data-bs-trigger="hover focus" title="<?=gettext('System alias details')?>" data-bs-content="<?=alias_info_popup(strtolower($natent['dstport']), true)?>" data-bs-html="true">
								<?=str_replace('_', '_<wbr>', htmlspecialchars(pprint_port($natent['dstport'])))?>
							</a>
						<?php else: ?>
							<?=str_replace('_', '_<wbr>', htmlspecialchars(pprint_port($natent['dstport'])))?>
						<?php endif; ?>
						</td>
						<td>
							<?php if (isset($natent['nonat'])): ?>
								<i>NO NAT</i>
							<?php elseif (isset($alias['target'])): ?>
								<a href="/firewall_aliases_edit.php?id=<?=$alias['target']?>" data-bs-toggle="popover" data-bs-trigger="hover focus" title="<?=gettext('Alias details')?>" data-bs-content="<?=alias_info_popup($alias['target'])?>" data-bs-html="true">
									<?=str_replace('_', '_<wbr>', htmlspecialchars(pprint_address(['network' => $natent['target']], $nat_tgttype_flags)))?>
								</a>
							<?php elseif ($show_system_alias_popup && array_key_exists($natent['target'], $system_aliases_hosts)): ?>
								<a data-bs-toggle="popover" data-bs-trigger="hover focus" title="<?=gettext('System alias details')?>" data-bs-content="<?=alias_info_popup(strtolower($natent['target']), true)?>" data-bs-html="true">
									<?=str_replace('_', '_<wbr>', htmlspecialchars(pprint_address(['address' => $natent['target']])))?>
								</a>
							<?php elseif (empty($natent['target_subnet'])): ?>
								<?=htmlspecialchars(pprint_address(['network' => $natent['target']], $nat_tgttype_flags))?>
							<?php elseif (!empty($natent['target'])): ?>
								<?=htmlspecialchars($natent['target'] . '/' . $natent['target_subnet'])?>
							<?php endif; ?>
						</td>
						<td>
<?php
						if (!$natent['natport']) {
							echo "*";
						} else {
							echo $natent['natport'];
						}
?>
						</td>

						<td>
<?php						if (isset($natent['staticnatport'])) { ?>
							<i class="fa-solid fa-check" title="<?=gettext('Keep Source Port Static')?>"></i>
<?php						} else { ?>
							<i class="fa-solid fa-shuffle" title="<?=gettext('Randomize Source Port')?>"></i>
<?php						} ?>
<?php						if (isset($natent['eimnat'])) { ?>
							<i class="fa-solid fa-arrows-to-circle" title="<?=gettext('Endpoint-Independent Mapping (UDP Only)')?>"></i>
<?php						} ?>
						</td>

						<td>
							<?=htmlspecialchars($natent['descr'])?>
						</td>

						<!-- Action	 icons -->
						<td>
							<?=fs_row_actions([
								['edit', "firewall_nat_out_edit.php?id={$i}", $natent['descr'] ?: sprintf(gettext('mapping %d'), $i + 1)],
								['copy', "firewall_nat_out_edit.php?dup={$i}", $natent['descr'] ?: sprintf(gettext('mapping %d'), $i + 1)],
								['delete', "firewall_nat_out.php?act=del&id={$i}", $natent['descr'] ?: sprintf(gettext('mapping %d'), $i + 1), ['thing' => gettext('mapping')]],
							])?>
						</td>
					</tr>
<?php
				$i++;
			endforeach;
?>
<?php if ($i == 0) {
	fs_empty_row(12, gettext('No outbound mappings yet.'), isAllowedPage('firewall_nat_out_edit.php') ? 'firewall_nat_out_edit.php' : null, gettext('Add mapping'));
} ?>
				</tbody>
			</table>
		</div>
	</div>



<?php
if ($mode == "automatic" || $mode == "hybrid"):
	$automatic_rules = getAutoRules();
?>
	<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Automatic rules'),
	'search' => gettext('Search automatic rules…'),
	'noun' => gettext('rules'),
	'noun_one' => gettext('rule'),
	'custom' => '<span class="fs-muted small">' . htmlspecialchars(gettext('Generated by the system, read only')) . '</span>',
]); ?>
		<div class="panel-body table-responsive">
			<table class="table table-hover">
				<thead>
					<tr>
						<th data-fs-search><?=gettext("Interface")?></th>
						<th data-fs-search><?=gettext("Source")?></th>
						<th data-fs-search><?=gettext("Source Port")?></th>
						<th data-fs-search><?=gettext("Destination")?></th>
						<th data-fs-search><?=gettext("Destination Port")?></th>
						<th data-fs-search><?=gettext("NAT Address")?></th>
						<th data-fs-search><?=gettext("NAT Port")?></th>
						<th><?=gettext("Static Port")?></th>
						<th data-fs-search><?=gettext("Description")?></th>
					</tr>
				</thead>
				<tbody>
<?php
	foreach ($automatic_rules as $natent):
		$proto = $natent['protocol'] ? $natent['protocol'] . '/' : '';
		if (isset($natent['destination']['any'])) {
			$auto_dst = '*';
		} else {
			$auto_dst = (isset($natent['destination']['not']) ? '! ' : '') . $natent['destination']['network'];
		}
?>
					<tr>
						<td><?=htmlspecialchars(convert_friendly_interface_to_friendly_descr($natent['interface']))?></td>
						<td class="fs-mono"><?=htmlspecialchars($natent['source']['network'])?></td>
						<td class="fs-mono"><?=htmlspecialchars($proto . ($natent['sourceport'] ?: '*'))?></td>
						<td class="fs-mono"><?=htmlspecialchars($auto_dst)?></td>
						<td class="fs-mono"><?=htmlspecialchars($proto . ($natent['dstport'] ?: '*'))?></td>
						<td class="fs-mono">
							<?php if (isset($natent['nonat'])): ?>
								<?=fs_badge('neutral', gettext('No NAT'))?>
							<?php elseif (empty($natent['target_subnet'])): ?>
								<?=htmlspecialchars(pprint_address(['network' => $natent['target']], $nat_tgttype_flags))?>
							<?php elseif (!empty($natent['target'])): ?>
								<?=htmlspecialchars($natent['target'] . '/' . $natent['target_subnet'])?>
							<?php endif; ?>
						</td>
						<td class="fs-mono"><?=htmlspecialchars($natent['natport'] ?: '*')?></td>
						<td><?=isset($natent['staticnatport']) ? fs_badge('info', gettext('Static'), gettext('Keep Source Port Static')) : fs_badge('neutral', gettext('Random'), gettext('Randomize Source Port'))?></td>
						<td><?=htmlspecialchars($natent['descr'])?></td>
					</tr>
<?php endforeach; ?>
<?php if (empty($automatic_rules)) {
	fs_empty_row(9, gettext('No automatic rules. They are generated for interface subnets that need outbound NAT.'));
} ?>
				</tbody>
			</table>
		</div>
	</div>
<?php endif; ?>
</form>

<div class="infoblock">
<?php
	print_info_box(
		gettext('If automatic outbound NAT is selected, a mapping is automatically generated for each interface\'s subnet (except WAN-type connections) and the rules on the "Mappings" section of this page are ignored.') .
			'<br />' .
			gettext('If manual outbound NAT is selected, outbound NAT rules will not be automatically generated and only the mappings specified on this page will be used.') .
			'<br />' .
			gettext('If hybrid outbound NAT is selected, mappings specified on this page will be used, followed by the automatically generated ones.') .
			'<br />' .
			gettext('If disable outbound NAT is selected, no rules will be used.') .
			'<br />' .
			sprintf(
				gettext('If a target address other than an interface\'s IP address is used, then depending on the way the WAN connection is setup, a %1$sVirtual IP%2$s may also be required.'),
				'<a href="firewall_virtual_ip.php">',
				'</a>'),
		'info',
		false);
?>
</div>

<script type="text/javascript">
//<![CDATA[
events.push(function() {

<?php if(!config_path_enabled('system/webgui', 'roworderdragging')): ?>
	// Make rules sortable
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
			return ("<?=gettext('One or more NAT outbound mappings have been moved but have not yet been saved')?>");
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

<?php include("foot.inc");
