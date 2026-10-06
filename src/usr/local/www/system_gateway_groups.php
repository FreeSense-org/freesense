<?php
/*
 * system_gateway_groups.php
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
##|*IDENT=page-system-gatewaygroups
##|*NAME=System: Gateway Groups
##|*DESCR=Allow access to the 'System: Gateway Groups' page.
##|*MATCH=system_gateway_groups.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("functions.inc");
require_once("filter.inc");
require_once("shaper.inc");
require_once("openvpn.inc");
require_once("system_routing.inc");

$a_gateways = config_get_path('gateways/gateway_item', []);


$pconfig = $_REQUEST;

if ($_POST['apply']) {
	$retval = routing_apply_changes();
}

if ($_POST['act'] == "del") {
	if (routing_delete_gateway_group($_POST['id'], $input_errors)) {
		header("Location: system_gateway_groups.php");
		exit;
	}
}

function gateway_exists($gwname) {
	$gateways = get_gateways();

	if (is_array($gateways)) {
		foreach ($gateways as $gw) {
			if ($gw['name'] == $gwname) {
				return(true);
			}
		}
	}

	return(false);
}

$pgtitle = array(gettext("System"), gettext("Routing"), gettext("Gateway Groups"));
$pglinks = array("", "system_gateways.php", "@self");
$shortcut_section = "gateway-groups";

fs_page_action(gettext('Add gateway group'), 'system_gateway_groups_edit.php', 'fa-plus');
include("head.inc");

if ($_POST['apply']) {
	print_apply_result_box($retval);
}

if (is_subsystem_dirty('staticroutes')) {
	print_apply_box(gettext("The gateway configuration has been changed.") . "<br />" . gettext("The changes must be applied for them to take effect."));
}

if ($input_errors) {
	print_input_errors($input_errors);
}

fs_tabs('system-routing', 'system_gateway_groups.php');
?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Gateway Groups'),
	'search' => gettext('Search gateway groups…'),
	'noun' => gettext('gateway groups'),
	'noun_one' => gettext('gateway group'),
]); ?>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-hover table-rowdblclickedit">
				<thead>
					<tr>
						<th data-fs-search><?=gettext("Group Name")?></th>
						<th data-fs-search><?=gettext("Gateways")?></th>
						<th data-fs-search><?=gettext("Priority")?></th>
						<th data-fs-search><?=gettext("Description")?></th>
						<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
					</tr>
				</thead>
				<tbody>
<?php
$i = 0;
foreach (config_get_path('gateways/gateway_group', []) as $gateway_group):
?>
					<tr>
						<td>
						   <?=$gateway_group['name']?>
						</td>
						<td>
<?php
	foreach ($gateway_group['item'] as $item) {
		$itemsplit = explode("|", $item);
		if (gateway_exists($itemsplit[0])) {
			print(htmlspecialchars(strtoupper($itemsplit[0])) . "<br />\n");
		}
	}
?>
						</td>
						<td>
<?php
	foreach ($gateway_group['item'] as $item) {
		$itemsplit = explode("|", $item);
		if (gateway_exists($itemsplit[0])) {
			print("Tier ". htmlspecialchars($itemsplit[1]) . "<br />\n");
		}
	}
?>
						</td>
						<td>
							<?=htmlspecialchars($gateway_group['descr'])?>
						</td>
						<td class="fs-col-actions">
<?=fs_row_actions([
								['edit', "system_gateway_groups_edit.php?id={$i}", $gateway_group['name']],
								['copy', "system_gateway_groups_edit.php?dup={$i}", $gateway_group['name']],
								['delete', "system_gateway_groups.php?act=del&id={$i}", $gateway_group['name'], ['thing' => gettext('gateway group')]],
							])?>
						</td>
					</tr>
<?php
	$i++;
endforeach;
?>
<?php if (empty(config_get_path('gateways/gateway_group', []))) {
	fs_empty_row(5, gettext('No gateway groups yet.'), 'system_gateway_groups_edit.php', gettext('Add gateway group'));
} ?>
				</tbody>
			</table>
		</div>
	</div>
</div>


<div class="infoblock">
	<?php print_info_box(sprintf(gettext('Remember to use these Gateway Groups in firewall rules in order to enable load balancing, failover, ' .
						   'or policy-based routing.%1$s' .
						   'Without rules directing traffic into the Gateway Groups, they will not be used.'), '<br />'), 'info', false); ?>
</div>
<?php
include("foot.inc");
