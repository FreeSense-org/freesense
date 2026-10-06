<?php
/*
 * interfaces_gif.php
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
##|*IDENT=page-interfaces-gif
##|*NAME=Interfaces: GIF
##|*DESCR=Allow access to the 'Interfaces: GIF' page.
##|*MATCH=interfaces_gif.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("interfaces_tunnels.inc");

if ($_POST['act'] == "del") {
	if (interfaces_gif_delete($_POST['id'] ?? null, $input_errors)) {
		header("Location: interfaces_gif.php");
		exit;
	}
}

$pgtitle = array(gettext("Interfaces"), gettext("GIFs"));
$shortcut_section = "interfaces";
fs_page_action(gettext('Add GIF'), 'interfaces_gif_edit.php', 'fa-plus');
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

fs_tabs('interfaces', 'interfaces_gif.php');
?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('GIF Interfaces'),
	'search' => gettext('Search GIF interfaces…'),
	'noun' => gettext('GIF interfaces'),
	'noun_one' => gettext('GIF interface'),
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
<?php foreach (config_get_path('gifs/gif', []) as $i => $gif): ?>
					<tr>
						<td>
							<?=htmlspecialchars(convert_friendly_interface_to_friendly_descr($gif['if']))?>
						</td>
						<td>
							<?=htmlspecialchars($gif['remote-addr'])?>
						</td>
						<td>
							<?=htmlspecialchars($gif['descr'])?>
						</td>
						<td class="fs-col-actions">
							<?=fs_row_actions([
								['edit', "interfaces_gif_edit.php?id={$i}", $gif['gifif'] ?: $gif['descr']],
								['delete', "interfaces_gif.php?act=del&id={$i}", $gif['gifif'] ?: $gif['descr'], ['thing' => gettext('GIF interface')]],
							])?>
						</td>
					</tr>
<?php endforeach; ?>
<?php if (empty(config_get_path('gifs/gif', []))) {
	fs_empty_row(4, gettext('No GIF interfaces yet.'), 'interfaces_gif_edit.php', gettext('Add GIF'));
} ?>
				</tbody>
			</table>
		</div>
	</div>
</div>


<?php include("foot.inc");
