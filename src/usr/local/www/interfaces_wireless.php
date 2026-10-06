<?php
/*
 * interfaces_wireless.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
 * Copyright (c) 2010 Erik Fonnesbeck
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
##|*IDENT=page-interfaces-wireless
##|*NAME=Interfaces: Wireless
##|*DESCR=Allow access to the 'Interfaces: Wireless' page.
##|*MATCH=interfaces_wireless.php*
##|-PRIV

require_once("guiconfig.inc");

function clone_inuse($num) {
	$a_clones = config_get_path('wireless/clone', []);
	$iflist = get_configured_interface_list(true);
	$if_config = config_get_path('interfaces', []);
	foreach ($iflist as $if) {
		if ($if_config[$if]['if'] == $a_clones[$num]['cloneif']) {
			return true;
		}
	}

	return false;
}

if ($_POST['act'] == "del") {
	/* check if still in use */
	if (clone_inuse($_POST['id'])) {
		$input_errors[] = gettext("This wireless clone cannot be deleted because it is assigned as an interface.");
	} else {
		FreeSense_interface_destroy(config_get_path("wireless/clone/{$_POST['id']}/cloneif"));
		config_del_path("wireless/clone/{$_POST['id']}");

		write_config("Wireless interface deleted");

		header("Location: interfaces_wireless.php");
		exit;
	}
}


$pgtitle = array(gettext("Interfaces"), gettext("Wireless"));
$shortcut_section = "wireless";
fs_page_action(gettext('Add wireless interface'), 'interfaces_wireless_edit.php', 'fa-plus');
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

fs_tabs('interfaces', 'interfaces_wireless.php');
?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Wireless Interfaces'),
	'search' => gettext('Search wireless interfaces…'),
	'noun' => gettext('wireless interfaces'),
	'noun_one' => gettext('wireless interface'),
]); ?>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-hover table-rowdblclickedit">
				<thead>
					<tr>
						<th data-fs-search><?=gettext("Interface"); ?></th>
						<th data-fs-search><?=gettext("Mode"); ?></th>
						<th data-fs-search><?=gettext("Description"); ?></th>
						<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
					</tr>
				</thead>
				<tbody>
<?php

$i = 0;

foreach (config_get_path('wireless/clone', []) as $clone) {
?>
					<tr>
						<td>
							<?=htmlspecialchars($clone['cloneif'])?>
						</td>
						<td>
							<?= $wlan_modes[$clone['mode']]; ?>
						</td>
						<td>
							<?=htmlspecialchars($clone['descr'])?>
						</td>
						<td class="fs-col-actions">
							<?=fs_row_actions([
								['edit', "interfaces_wireless_edit.php?id={$i}", $clone['cloneif'] ?: $clone['descr']],
								['delete', "interfaces_wireless.php?act=del&id={$i}", $clone['cloneif'] ?: $clone['descr'], ['thing' => gettext('wireless interface')]],
							])?>
						</td>
					</tr>
<?php
	$i++;
}
?>
<?php if (empty(config_get_path('wireless/clone', []))) {
	fs_empty_row(4, gettext('No wireless interfaces yet.'), 'interfaces_wireless_edit.php', gettext('Add wireless interface'));
} ?>
				</tbody>
			</table>
		</div>
	</div>
</div>

<?php
include("foot.inc");
