<?php
/*
 * interfaces_ppps.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
 * All rights reserved.
 *
 * originally based on m0n0wall (http://m0n0.ch/wall)
 * Copyright (c) 2003-2004 Manuel Kasper <mk@neon1.net>.
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
##|*IDENT=page-interfaces-ppps
##|*NAME=Interfaces: PPPs
##|*DESCR=Allow access to the 'Interfaces: PPPs' page.
##|*MATCH=interfaces_ppps.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("functions.inc");

function ppp_inuse($num) {
	global $g;

	$ppp_config = config_get_path('ppps/ppp');
	$if_config = config_get_path('interfaces', []);
	$iflist = get_configured_interface_list(true);
	if (!is_array($ppp_config)) {
		return false;
	}

	foreach ($iflist as $if) {
		if ($if_config[$if]['if'] == $ppp_config[$num]['if']) {
			return true;
		}
	}

	return false;
}

if ($_POST['act'] == "del") {
	$this_ppp_config = config_get_path("ppps/ppp/{$_POST['id']}");
	/* check if still in use */
	if (ppp_inuse($_POST['id'])) {
		$input_errors[] = gettext("This point-to-point link cannot be deleted because it is still being used as an interface.");
	} elseif (is_array($this_ppp_config)) {

		config_del_path("ppps/ppp/{$_POST['id']}/pppoe-reset-type");
		handle_pppoe_reset($this_ppp_config);
		config_del_path("ppps/ppp/{$_POST['id']}");
		write_config("PPP interface deleted");
		header("Location: interfaces_ppps.php");
		exit;
	}
}

$pgtitle = array(gettext("Interfaces"), gettext("PPPs"));
$shortcut_section = "interfaces";
fs_page_action(gettext('Add PPP'), 'interfaces_ppps_edit.php', 'fa-plus');
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

fs_tabs('interfaces', 'interfaces_ppps.php');
?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('PPP Interfaces'),
	'search' => gettext('Search PPPs…'),
	'noun' => gettext('PPPs'),
	'noun_one' => gettext('PPP'),
]); ?>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-hover table-rowdblclickedit">
				<thead>
					<tr>
						<th data-fs-search><?=gettext("Interface"); ?></th>
						<th data-fs-search><?=gettext("Interface(s)/Port(s)"); ?></th>
						<th data-fs-search><?=gettext("Description"); ?></th>
						<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
					</tr>
				</thead>
				<tbody>
<?php

$i = 0;


foreach (config_get_path('ppps/ppp', []) as $ppp) {
?>
					<tr>
						<td>
							<?=htmlspecialchars($ppp['if'])?>
						</td>
						<td>
<?php
	$portlist = array_filter(explode(",", $ppp['ports']));
	foreach ($portlist as $portid => $port) {
		if (($ppp['type'] != "ppp") && ($port != get_real_interface($port))) {
			$portlist[$portid] = convert_friendly_interface_to_friendly_descr($port);
		}
	}
							echo htmlspecialchars(implode(",", $portlist));
?>
						</td>
						<td>
							<?=htmlspecialchars($ppp['descr'])?>
						</td>
						<td class="fs-col-actions">
							<?=fs_row_actions([
								['edit', "interfaces_ppps_edit.php?id={$i}", $ppp['if'] ?: $ppp['descr']],
								['delete', "interfaces_ppps.php?act=del&id={$i}", $ppp['if'] ?: $ppp['descr'], ['thing' => gettext('PPP')]],
							])?>
						</td>
					</tr>
<?php
	$i++;
}
?>
<?php if (empty(config_get_path('ppps/ppp', []))) {
	fs_empty_row(4, gettext('No PPPs yet.'), 'interfaces_ppps_edit.php', gettext('Add PPP'));
} ?>
				</tbody>
			</table>
		</div>
	</div>
</div>


<?php
include("foot.inc");
