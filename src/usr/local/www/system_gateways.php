<?php
/*
 * system_gateways.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
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

$tab_array = array();
$tab_array[0] = array(gettext("Gateways"), true, "system_gateways.php");
$tab_array[1] = array(gettext("Static Routes"), false, "system_routes.php");
$tab_array[2] = array(gettext("Gateway Groups"), false, "system_gateway_groups.php");
display_top_tabs($tab_array);

?>
<form method="post">
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('Gateways')?></h2></div>
	<div class="panel-body">
		<div class="table-responsive">
			<table id="gateways" class="table table-striped table-hover table-sm table-rowdblclickedit">
				<thead>
					<tr>
						<th></th>
						<th><?=gettext("Name")?></th>
						<th><?=gettext("Default")?></th>
						<th><?=gettext("Interface")?></th>
						<th><?=gettext("Gateway")?></th>
						<th><?=gettext("Monitor IP")?></th>
						<th><?=gettext("Description")?></th>
						<th><?=gettext("Actions")?></th>
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
					<tr<?=($icon != 'fa-regular fa-circle-check')? ' class="disabled"' : ''?> onClick="fr_toggle(<?=$id;?>)" id="fr<?=$id;?>">
						<td title="<?=$title?>"><i class="<?=$icon?>"></i></td>
						<td title="<?=$gtitle?>">
						<?=htmlspecialchars($gateway['name'])?>
<?php
							if (isset($gateway['isdefaultgw'])) {
								echo ' <i class="fa-solid fa-globe"></i>';
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
						<td style="white-space: nowrap;">
							<a href="system_gateways_edit.php?id=<?=$i?>" class="fa-solid fa-pencil" title="<?=gettext('Edit gateway');?>"></a>
							<a href="system_gateways_edit.php?dup=<?=$i?>" class="fa-regular fa-clone" title="<?=gettext('Copy gateway')?>"></a>

<?php if (is_numeric($gateway['attribute'])): ?>
	<?php if (isset($gateway['disabled'])) {
	?>
							<a href="?act=toggle&amp;id=<?=$i?>" class="fa-regular fa-square-check" title="<?=gettext('Enable gateway')?>" usepost></a>
	<?php } else {
	?>
							<a href="?act=toggle&amp;id=<?=$i?>" class="fa-solid fa-ban" title="<?=gettext('Disable gateway')?>" usepost></a>
	<?php }
	?>
							<a href="system_gateways.php?act=del&amp;id=<?=$i?>" class="fa-solid fa-trash-can" title="<?=gettext('Delete gateway')?>" usepost></a>

<?php endif; ?>
						</td>
					</tr>
<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>

<nav class="action-buttons">
	<a href="system_gateways_edit.php" role="button" class="btn btn-success">
		<i class="fa-solid fa-plus icon-embed-btn"></i>
		<?=gettext("Add");?>
	</a>
</nav>
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
