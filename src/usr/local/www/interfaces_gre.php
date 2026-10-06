<?php
/*
 * interfaces_gre.php
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
##|*IDENT=page-interfaces-gre
##|*NAME=Interfaces: GRE
##|*DESCR=Allow access to the 'Interfaces: GRE' page.
##|*MATCH=interfaces_gre.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("functions.inc");
require_once("interfaces_tunnels.inc");

if ($_POST['act'] == "del") {
	if (interfaces_gre_delete($_POST['id'] ?? null, $input_errors)) {
		header("Location: interfaces_gre.php");
		exit;
	}
}

$pgtitle = array(gettext("Interfaces"), gettext("GREs"));
$shortcut_section = "interfaces";
fs_page_action(gettext('Add GRE'), 'interfaces_gre_edit.php', 'fa-plus');
include("head.inc");
if ($input_errors) {
	print_input_errors($input_errors);
}

fs_tabs('interfaces', 'interfaces_gre.php');
?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('GRE Interfaces'),
	'search' => gettext('Search GRE interfaces…'),
	'noun' => gettext('GRE interfaces'),
	'noun_one' => gettext('GRE interface'),
]); ?>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-hover table-rowdblclickedit">
				<thead>
					<tr>
						<th data-fs-search><?=gettext("Interface"); ?></th>
						<th data-fs-search><?=gettext("Tunnel to &hellip;"); ?></th>
						<th data-fs-search><?=gettext("Description"); ?></th>
						<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
					</tr>
				</thead>
				<tbody>
<?php foreach (config_get_path('gres/gre', []) as $i => $gre):
	if (substr($gre['if'], 0, 4) == "_vip") {
		$if = convert_real_interface_to_friendly_descr(get_real_interface($gre['if']));
	} else {
		$if = $gre['if'];
	}
?>
					<tr>
						<td>
							<?=htmlspecialchars(convert_friendly_interface_to_friendly_descr($if))?>
						</td>
						<td>
							<?=htmlspecialchars($gre['remote-addr'])?>
						</td>
						<td>
							<?=htmlspecialchars($gre['descr'])?>
						</td>
						<td class="fs-col-actions">
							<?=fs_row_actions([
								['edit', "interfaces_gre_edit.php?id={$i}", $gre['greif'] ?: $gre['descr']],
								['delete', "interfaces_gre.php?act=del&id={$i}", $gre['greif'] ?: $gre['descr'], ['thing' => gettext('GRE interface')]],
							])?>
						</td>
					</tr>
<?php endforeach; ?>
<?php if (empty(config_get_path('gres/gre', []))) {
	fs_empty_row(4, gettext('No GRE interfaces yet.'), 'interfaces_gre_edit.php', gettext('Add GRE'));
} ?>
				</tbody>
			</table>
		</div>
	</div>
</div>

<?php
include("foot.inc");
