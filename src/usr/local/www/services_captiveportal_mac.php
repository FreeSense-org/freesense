<?php
/*
 * services_captiveportal_mac.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
 * Copyright (c) 2004 Dinesh Nair <dinesh@alphaque.com>
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
##|*IDENT=page-services-captiveportal-macaddresses
##|*NAME=Services: Captive Portal: Mac Addresses
##|*DESCR=Allow access to the 'Services: Captive Portal: Mac Addresses' page.
##|*MATCH=services_captiveportal_mac.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("functions.inc");
require_once("filter.inc");
require_once("shaper.inc");
require_once("captiveportal.inc");

global $cpzone;
global $cpzoneid;

$cpzone = strtolower(htmlspecialchars($_REQUEST['zone']));

if (empty($cpzone) || empty(config_get_path("captiveportal/{$cpzone}"))) {
	header("Location: services_captiveportal_zones.php");
	exit;
}

$cpzoneid = config_get_path("captiveportal/{$cpzone}/zoneid");

$pgtitle = array(gettext("Services"), gettext("Captive Portal"), htmlspecialchars($cpzone), gettext("MACs"));
$pglinks = array("", "services_captiveportal_zones.php", "services_captiveportal.php?zone=" . $cpzone, "@self");
$shortcut_section = "captiveportal";

$actsmbl = array('pass' => '<i class="fa-solid fa-check text-success"></i>&nbsp;' . gettext("Pass"),
	'block' => '<i class="fa-solid fa-xmark text-danger"></i>&nbsp;' . gettext("Block"));

if ($_POST['act'] == "del") {
	if (config_get_path("captiveportal/{$cpzone}/passthrumac/{$_POST['id']}")) {
		captiveportal_passthrumac_delete_entry(config_get_path("captiveportal/{$cpzone}/passthrumac/{$_POST['id']}"));
		config_del_path("captiveportal/{$cpzone}/passthrumac/{$_POST['id']}");
		write_config("Captive portal passthrough MAC deleted");
		header("Location: services_captiveportal_mac.php?zone={$cpzone}");
		exit;
	}
}

fs_page_action(gettext('Add MAC address'), 'services_captiveportal_mac_edit.php?zone=' . urlencode($cpzone) . '&act=add', 'fa-plus');
include("head.inc");

if ($_POST['apply']) {
	print_apply_result_box($retval);
}

if (is_subsystem_dirty('passthrumac')) {
	print_apply_box(gettext("The Captive Portal MAC address configuration has been changed.") . "<br />" . gettext("The changes must be applied for them to take effect."));
}

fs_tabs('services-captiveportal', 'services_captiveportal_mac.php', ['zone' => $cpzone]);
?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('MAC addresses'),
	'search' => gettext('Search MAC addresses…'),
	'noun' => gettext('MAC addresses'),
	'noun_one' => gettext('MAC address'),
]); ?>
<div class="panel-body table-responsive">
	<table class="table table-hover table-rowdblclickedit" data-sortable>
		<thead>
			<tr>
				<th data-fs-search><?=gettext('Action')?></th>
				<th data-fs-search><?=gettext("MAC address")?></th>
				<th data-fs-search><?=gettext("Description")?></th>
				<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
			</tr>
		</thead>
		<tbody>
<?php
$i = 0;
foreach (config_get_path("captiveportal/{$cpzone}/passthrumac", []) as $mac): ?>
			<tr>
				<td>
					<?=$actsmbl[$mac['action']]?>
				</td>
				<td>
					<?=htmlspecialchars($mac['mac'])?>
				</td>
				<td >
					<?=htmlspecialchars($mac['descr'])?>
				</td>
				<td class="fs-col-actions">
<?=fs_row_actions([
					['edit', "services_captiveportal_mac_edit.php?zone=" . urlencode($cpzone) . "&id={$i}", $mac['mac']],
					['delete', "services_captiveportal_mac.php?zone=" . urlencode($cpzone) . "&act=del&id={$i}", $mac['mac'], ['thing' => gettext('MAC address')]],
				])?>
				</td>
			</tr>
<?php
$i++;
endforeach; ?>
<?php if ($i == 0) {
	fs_empty_row(4, gettext('No MAC addresses yet.'), 'services_captiveportal_mac_edit.php?zone=' . urlencode($cpzone) . '&act=add', gettext('Add MAC address'));
} ?>
		</tbody>
	</table>
</div>
</div>


<div class="infoblock">
	<?php print_info_box(gettext('Adding MAC addresses as "pass" MACs allows them access through the captive portal automatically without being taken to the portal page.'), 'info', false); ?>
</div>
<?php
include("foot.inc");
