<?php
/*
 * interfaces_lagg.php
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
##|*IDENT=page-interfaces-lagg
##|*NAME=Interfaces: LAGG:
##|*DESCR=Allow access to the 'Interfaces: LAGG' page.
##|*MATCH=interfaces_lagg.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("interfaces_l2.inc");

if ($_POST['act'] == "del") {
	if (interfaces_lagg_delete($_POST['id'] ?? null, $input_errors)) {
		header("Location: interfaces_lagg.php");
		exit;
	}
}

$pgtitle = array(gettext("Interfaces"), gettext("LAGGs"));
$shortcut_section = "interfaces";
fs_page_action(gettext('Add LAGG'), 'interfaces_lagg_edit.php', 'fa-plus');
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

fs_tabs('interfaces', 'interfaces_lagg.php');
?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('LAGG Interfaces'),
	'search' => gettext('Search LAGGs…'),
	'noun' => gettext('LAGGs'),
	'noun_one' => gettext('LAGG'),
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

foreach (config_get_path('laggs/lagg', []) as $lagg) {
?>
					<tr>
						<td>
							<?=htmlspecialchars(strtoupper($lagg['laggif']))?>
						</td>
						<td>
							<?=htmlspecialchars($lagg['members'])?>
						</td>
						<td>
							<?=htmlspecialchars($lagg['descr'])?>
						</td>
						<td class="fs-col-actions">
							<?=fs_row_actions([
								['edit', "interfaces_lagg_edit.php?id={$i}", $lagg['laggif'] ?: $lagg['descr']],
								['delete', "interfaces_lagg.php?act=del&id={$i}", $lagg['laggif'] ?: $lagg['descr'], ['thing' => gettext('LAGG')]],
							])?>
						</td>
					</tr>
<?php
	$i++;
}
?>
<?php if (empty(config_get_path('laggs/lagg', []))) {
	fs_empty_row(4, gettext('No LAGGs yet.'), 'interfaces_lagg_edit.php', gettext('Add LAGG'));
} ?>
				</tbody>
			</table>
		</div>
	</div>
</div>


<?php
include("foot.inc");
