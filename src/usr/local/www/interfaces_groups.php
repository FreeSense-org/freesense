<?php
/*
 * interfaces_groups.php
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
##|*IDENT=page-interfaces-groups
##|*NAME=Interfaces: Groups
##|*DESCR=Create interface groups
##|*MATCH=interfaces_groups.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("functions.inc");
require_once("interfaces_l2.inc");

if ($_POST['act'] == "del") {
	if (interfaces_group_delete($_POST['id'] ?? null, $input_errors)) {
		header("Location: interfaces_groups.php");
		exit;
	}
}

$pgtitle = array(gettext("Interfaces"), gettext("Interface Groups"));
$shortcut_section = "interfaces";

fs_page_action(gettext('Add group'), 'interfaces_groups_edit.php', 'fa-plus');
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

fs_tabs('interfaces', 'interfaces_groups.php');
?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Interface Groups'),
	'search' => gettext('Search interface groups…'),
	'noun' => gettext('interface groups'),
	'noun_one' => gettext('interface group'),
]); ?>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-hover table-rowdblclickedit">
				<thead>
					<tr>
						<th data-fs-search><?=gettext('Name');?></th>
						<th data-fs-search><?=gettext('Members');?></th>
						<th data-fs-search><?=gettext('Description');?></th>
						<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
					</tr>
				</thead>
				<tbody>
<?php foreach (config_get_path('ifgroups/ifgroupentry', []) as $i => $ifgroupentry): ?>
					<tr>
						<td>
							<?=htmlspecialchars($ifgroupentry['ifname']); ?>
						</td>
						<td>
<?php
		$members_arr = explode(" ", $ifgroupentry['members']);
		$iflist = get_configured_interface_with_descr(true);
		$memberses_arr = array();
		foreach ($members_arr as $memb) {
			$memberses_arr[] = $iflist[$memb] ? $iflist[$memb] : $memb;
		}

		unset($iflist);
		$memberses = implode(", ", $memberses_arr);
		echo htmlspecialchars($memberses);
		if (count($members_arr) >= 10) {
			echo '&hellip;';
		}
?>
						</td>
						<td>
							<?=htmlspecialchars($ifgroupentry['descr']);?>
						</td>
						<td class="fs-col-actions">
							<?=fs_row_actions([
								['edit', "interfaces_groups_edit.php?id={$i}", $ifgroupentry['ifname'] ?: $ifgroupentry['descr']],
								['delete', "interfaces_groups.php?act=del&id={$i}", $ifgroupentry['ifname'] ?: $ifgroupentry['descr'], ['thing' => gettext('interface group')]],
							])?>
						</td>
					</tr>
<?php endforeach; ?>
<?php if (empty(config_get_path('ifgroups/ifgroupentry', []))) {
	fs_empty_row(4, gettext('No interface groups yet.'), 'interfaces_groups_edit.php', gettext('Add group'));
} ?>
				</tbody>
			</table>
		</div>
	</div>
</div>


<div class="infoblock">
	<?php print_info_box(sprintf(gettext('Interface Groups allow setting up rules for multiple interfaces without duplicating the rules.%s' .
					   'If members are removed from an interface group, the group rules are no longer applicable to that interface.'), '<br />'), 'info', false); ?>

</div>
<?php

include("foot.inc");
