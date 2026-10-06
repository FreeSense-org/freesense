<?php
/*
 * interfaces_qinq.php
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
##|*IDENT=page-interfaces-qinq
##|*NAME=Interfaces: QinQ
##|*DESCR=Allow access to the 'Interfaces: QinQ' page.
##|*MATCH=interfaces_qinq.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("functions.inc");
require_once("interfaces_l2.inc");

if ($_POST['act'] == "del") {
	$id = is_numericint($_POST['id']) ? $_POST['id'] : null;

	/*
	 * Check user privileges to test if the user is allowed to make changes.
	 * Otherwise users can end up in an inconsistent state where some changes are
	 * performed and others denied. See upstream issue 15318
	 */
	if (interfaces_qinq_delete($id, interfaces_gui_read_only(), $input_errors)) {
		header("Location: interfaces_qinq.php");
		exit;
	}
}

$pgtitle = array(gettext("Interfaces"), gettext("QinQs"));
$shortcut_section = "interfaces";
fs_page_action(gettext('Add QinQ'), 'interfaces_qinq_edit.php', 'fa-plus');
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

fs_tabs('interfaces', 'interfaces_qinq.php');

?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('QinQ Interfaces'),
	'search' => gettext('Search QinQs…'),
	'noun' => gettext('QinQs'),
	'noun_one' => gettext('QinQ'),
]); ?>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-hover table-rowdblclickedit">
				<thead>
					<tr>
						<th data-fs-search><?=gettext("Interface"); ?></th>
						<th data-fs-search><?=gettext("Tag");?></th>
						<th data-fs-search><?=gettext("QinQ members"); ?></th>
						<th data-fs-search><?=gettext("Description"); ?></th>
						<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
					</tr>
				</thead>
				<tbody>
<?php foreach (config_get_path('qinqs/qinqentry', []) as $i => $qinq):?>
					<tr>
						<td>
							<?=htmlspecialchars($qinq['if'])?>
						</td>
						<td>
							<?=htmlspecialchars($qinq['tag'])?>
						</td>
						<td>
<?php if (strlen($qinq['members']) > 20):?>
							<?=substr(htmlspecialchars($qinq['members']), 0, 20)?>&hellip;
<?php else:?>
							<?=htmlspecialchars($qinq['members'])?>
<?php endif; ?>
						</td>
						<td>
							<?=htmlspecialchars($qinq['descr'])?>&nbsp;
						</td>
						<td class="fs-col-actions">
							<?=fs_row_actions([
								['edit', "interfaces_qinq_edit.php?id={$i}", $qinq['vlanif'] ?: $qinq['descr']],
								['delete', "interfaces_qinq.php?act=del&id={$i}", $qinq['vlanif'] ?: $qinq['descr'], ['thing' => gettext('QinQ')]],
							])?>
						</td>
					</tr>
<?php
endforeach;
?>
<?php if (empty(config_get_path('qinqs/qinqentry', []))) {
	fs_empty_row(5, gettext('No QinQs yet.'), 'interfaces_qinq_edit.php', gettext('Add QinQ'));
} ?>
				</tbody>
			</table>
		</div>
	</div>
</div>


<div class="infoblock">
	<?php print_info_box(sprintf(gettext('Not all drivers/NICs support 802.1Q QinQ tagging properly. %1$sOn cards that do not explicitly support it, ' .
		'QinQ tagging will still work, but the reduced MTU may cause problems.%1$s' .
		'See the %2$s handbook for information on supported cards.'), '<br />', g_get('product_label')), 'info', false); ?>
</div>

<?php
include("foot.inc");
