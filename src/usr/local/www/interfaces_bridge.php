<?php
/*
 * interfaces_bridge.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
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
##|*IDENT=page-interfaces-bridge
##|*NAME=Interfaces: Bridge
##|*DESCR=Allow access to the 'Interfaces: Bridge' page.
##|*MATCH=interfaces_bridge.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("interfaces_l2.inc");

if ($_POST['act'] == "del") {
	if (interfaces_bridge_delete($_POST['id'] ?? null, $input_errors)) {
		header("Location: interfaces_bridge.php");
		exit;
	}
}

$pgtitle = array(gettext("Interfaces"), gettext("Bridges"));
$shortcut_section = "interfaces";
fs_page_action(gettext('Add bridge'), 'interfaces_bridge_edit.php', 'fa-plus');
include("head.inc");
if ($input_errors) {
	print_input_errors($input_errors);
}

fs_tabs('interfaces', 'interfaces_bridge.php');
?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Bridge Interfaces'),
	'search' => gettext('Search bridges…'),
	'noun' => gettext('bridges'),
	'noun_one' => gettext('bridge'),
]); ?>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-hover table-rowdblclickedit">
				<thead>
					<tr>
						<th data-fs-search><?=gettext("Interface"); ?></th>
						<th data-fs-search><?=gettext("Members"); ?></th>
						<th data-fs-search><?=gettext("Description"); ?></th>
						<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
					</tr>
				</thead>
				<tbody>
<?php

$i = 0;
$ifdescrs = get_configured_interface_with_descr();

foreach (config_get_path('bridges/bridged', []) as $bridge) {
?>
					<tr>
						<td>
							<?=htmlspecialchars(strtoupper($bridge['bridgeif']))?>
						</td>
						<td>
<?php
	$members = explode(',', $bridge['members']);
	$j = 0;
	foreach ($members as $member) {
		if (isset($ifdescrs[$member])) {
			echo $ifdescrs[$member];
			$j++;
		}
		if ($j > 0 && $j < count($members)) {
			echo ", ";
		}
	}
?>
						</td>
						<td>
							<?=htmlspecialchars($bridge['descr'])?>
						</td>
						<td class="fs-col-actions">
							<?=fs_row_actions([
								['edit', "interfaces_bridge_edit.php?id={$i}", $bridge['bridgeif'] ?: $bridge['descr']],
								['delete', "interfaces_bridge.php?act=del&id={$i}", $bridge['bridgeif'] ?: $bridge['descr'], ['thing' => gettext('bridge')]],
							])?>
						</td>
					</tr>
<?php
	$i++;
}
?>
<?php if (empty(config_get_path('bridges/bridged', []))) {
	fs_empty_row(4, gettext('No bridges yet.'), 'interfaces_bridge_edit.php', gettext('Add bridge'));
} ?>
				</tbody>
			</table>
		</div>
	</div>
</div>


<?php include("foot.inc");
