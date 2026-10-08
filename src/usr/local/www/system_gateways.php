<?php
/*
 * system_gateways.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
 * Copyright (c) 2010 Seth Mos <seth.mos@dds.nl>
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
##|*IDENT=page-system-gateways
##|*NAME=System: Gateways
##|*DESCR=Allow access to the 'System: Gateways' page.
##|*MATCH=system_gateways.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("functions.inc");
require_once("filter.inc");
require_once("shaper.inc");
require_once("gwlb.inc");
require_once("system_routing.inc");

$simplefields = array('defaultgw4', 'defaultgw6');

refresh_gateways(); // make sure we're working on a current gateway list
unset($input_errors);

$pconfig = $_REQUEST;

if ($_POST['save']) {
	$pconfig = $_POST;
	routing_save_default_gateways($pconfig);
}

if ($_POST['apply']) {
	$retval = routing_apply_changes();
}

$a_gateways = get_gateways(GW_CACHE_INDEXED);

if ($_REQUEST['act'] == "del") {
	if (routing_delete_gateway($a_gateways, $_REQUEST['id'], $input_errors)) {
		header("Location: system_gateways.php");
		exit;
	}
}

if (isset($_REQUEST['del_x'])) {
	/* delete selected items */
	if (is_array($_REQUEST['rule']) && count($_REQUEST['rule'])) {
		if (routing_delete_gateways($a_gateways, $_REQUEST['rule'], $input_errors)) {
			header("Location: system_gateways.php");
			exit;
		}
	}

} else if ($_REQUEST['act'] == "toggle" && $a_gateways[$_REQUEST['id']]) {
	if (routing_toggle_gateway($a_gateways, $_REQUEST['id'], $input_errors)) {
		header("Location: system_gateways.php");
		exit;
	}
}

foreach($simplefields as $field) {
	$pconfig[$field] = config_get_path("gateways/{$field}");
}

$pgtitle = array(gettext("System"), gettext("Routing"), gettext("Gateways"));
$pglinks = array("", "@self", "@self");
$shortcut_section = "gateways";

fs_page_action(gettext('Add gateway'), 'system_gateways_edit.php', 'fa-plus');
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($_POST['apply']) {
	print_apply_result_box($retval);
}

if (is_subsystem_dirty('staticroutes')) {
	print_apply_box(gettext("The gateway configuration has been changed.") . "<br />" . gettext("The changes must be applied for them to take effect."));
}

fs_tabs('system-routing', 'system_gateways.php');

?>
<form method="post">
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Gateways'),
	'search' => gettext('Search gateways…'),
	'noun' => gettext('gateways'),
	'noun_one' => gettext('gateway'),
]); ?>
	<div class="panel-body">
		<div class="table-responsive">
			<table id="gateways" class="table table-hover table-rowdblclickedit">
				<thead>
					<tr>
						<th data-fs-search></th>
						<th data-fs-search><?=gettext("Name")?></th>
						<th data-fs-search><?=gettext("Default")?></th>
						<th data-fs-search><?=gettext("Interface")?></th>
						<th data-fs-search><?=gettext("Gateway")?></th>
						<th data-fs-search><?=gettext("Monitor IP")?></th>
						<th data-fs-search><?=gettext("Description")?></th>
						<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
					</tr>
				</thead>
				<tbody>
<?php
foreach ($a_gateways as $i => $gateway):
	if (isset($gateway['inactive'])) {
		$title = gettext("Gateway inactive, interface is missing");
		$icon = 'fa-regular fa-circle-xmark';
	} elseif (isset($gateway['disabled'])) {
		$icon = 'fa-solid fa-ban';
		$title = gettext("Gateway disabled");
	} else {
		$icon = 'fa-regular fa-circle-check';
		$title = gettext("Gateway enabled");
	}

	$gtitle = "";
	if (isset($gateway['isdefaultgw'])) {
		$gtitle = gettext("Default gateway");
	}

	$id = $gateway['attribute'];
?>
					<tr<?=($icon != 'fa-regular fa-circle-check')? ' class="disabled"' : ''?> id="fr<?=$id;?>">
						<td><?=($icon == 'fa-regular fa-circle-xmark') ? fs_badge('down', gettext('Inactive'), $title) : (($icon == 'fa-solid fa-ban') ? fs_badge('disabled', null, $title) : fs_badge('enabled', null, $title))?></td>
						<td title="<?=$gtitle?>">
						<?=htmlspecialchars($gateway['name'])?>
<?php
							if (isset($gateway['isdefaultgw'])) {
								echo ' ' . fs_badge('info', gettext('Default'));
							}
?>
						</td>
						<td>
							<?=htmlspecialchars($gateway['tiername'])?>
						</td>
						<td>
							<?=htmlspecialchars($gateway['friendlyifdescr'])?>
						</td>
						<td>
							<?=htmlspecialchars($gateway['gateway'])?>
						</td>
						<td>
							<?=htmlspecialchars($gateway['monitor'])?>
						</td>
						<td>
							<?=htmlspecialchars($gateway['descr'])?>
						</td>
						<td class="fs-col-actions">
<?php
	$gw_actions = [
		['edit', "system_gateways_edit.php?id={$i}", $gateway['name']],
		['copy', "system_gateways_edit.php?dup={$i}", $gateway['name']],
	];
	/* dynamic gateways (non-numeric attribute) cannot be toggled or deleted here */
	if (is_numeric($gateway['attribute'])) {
		$gw_actions[] = ['toggle', "?act=toggle&id={$i}", $gateway['name'], ['enabled' => !isset($gateway['disabled'])]];
		$gw_actions[] = ['delete', "system_gateways.php?act=del&id={$i}", $gateway['name'], ['thing' => gettext('gateway')]];
	}
?>
							<?=fs_row_actions($gw_actions)?>
						</td>
					</tr>
<?php endforeach; ?>
<?php if (empty($a_gateways)) {
	fs_empty_row(8, gettext('No gateways yet.'), 'system_gateways_edit.php', gettext('Add gateway'));
} ?>
				</tbody>
			</table>
		</div>
	</div>
</div>

</form>
<?php

$form = new Form;
$section = new Form_Section('Default gateway');

$dflts = available_default_gateways();

$section->addInput(new Form_Select(
	'defaultgw4',
	'Default gateway IPv4',
	$pconfig['defaultgw4'],
	$dflts['v4']
))->setHelp('Select a gateway or failover gateway group to use as the default gateway.');

$section->addInput(new Form_Select(
	'defaultgw6',
	'Default gateway IPv6',
	$pconfig['defaultgw6'],
	$dflts['v6']
))->setHelp('Select a gateway or failover gateway group to use as the default gateway.');

$form->add($section);
print $form;

?>
<div class="infoblock">
<?php
print_info_box(
	sprintf(gettext('%1$s The current default route as present in the current routing table of the operating system'), '<strong><i class="fa-solid fa-globe"></i></strong>') .
	sprintf(gettext('%1$s Gateway is inactive, interface is missing'), '<br /><strong><i class="fa-regular fa-circle-xmark"></i></strong>') .
	sprintf(gettext('%1$s Gateway disabled'), '<br /><strong><i class="fa-solid fa-ban"></i></strong>') .
	sprintf(gettext('%1$s Gateway enabled'), '<br /><strong><i class="fa-regular fa-circle-check"></i></strong>')
	);
?>
</div>

<?php include("foot.inc");
