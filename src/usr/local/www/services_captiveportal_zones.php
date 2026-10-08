<?php
/*
 * services_captiveportal_zones.php
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
##|*IDENT=page-services-captiveportal-zones
##|*NAME=Services: Captive Portal Zones
##|*DESCR=Allow access to the 'Services: Captive Portal Zones' page.
##|*MATCH=services_captiveportal_zones.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("functions.inc");
require_once("filter.inc");
require_once("shaper.inc");
require_once("captiveportal.inc");

global $cpzone;
global $cpzoneid;

if ($_POST['act'] == "del" && !empty($_POST['zone'])) {
	$cpzone = strtolower(htmlspecialchars($_POST['zone']));
	if (config_get_path("captiveportal/{$cpzone}")) {
		$cpzoneid = config_get_path("captiveportal/{$cpzone}/zoneid");
		config_del_path("captiveportal/{$cpzone}/enable");
		captiveportal_configure_zone(config_get_path("captiveportal/{$cpzone}", []));
		config_del_path("captiveportal/{$cpzone}");
		config_del_path("voucher/{$cpzone}");
		unlink_if_exists("/var/db/captiveportal{$cpzone}.db");
		unlink_if_exists("/var/db/captiveportal_usedmacs_{$cpzone}.db");
		unlink_if_exists("/var/db/voucher_{$cpzone}_*.db");
		write_config("Captive portal zone deleted");
		filter_configure();
	}
	header("Location: services_captiveportal_zones.php");
	exit;
}

$pgtitle = array(gettext("Services"), gettext("Captive Portal"));
$shortcut_section = "captiveportal";
fs_page_action(gettext('Add zone'), 'services_captiveportal_zones_edit.php', 'fa-plus');
include("head.inc");

if (is_subsystem_dirty('captiveportal')) {
	print_apply_box(gettext("The Captive Portal entry list has been changed.") . "<br />" . gettext("The changes must be applied for them to take effect."));
}
?>
<form action="services_captiveportal_zones.php" method="post">
	<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Captive Portal Zones'),
	'search' => gettext('Search zones…'),
	'noun' => gettext('zones'),
	'noun_one' => gettext('zone'),
]); ?>
		<div class="panel-body table-responsive">
			<table class="table table-hover table-rowdblclickedit" data-sortable>
				<thead>
					<tr>
						<th data-fs-search><?=gettext('Zone')?></th>
						<th data-fs-search><?=gettext('Interfaces')?></th>
						<th data-fs-search><?=gettext('Number of users'); ?></th>
						<th data-fs-search><?=gettext('Description'); ?></th>
						<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
					</tr>
				</thead>
				<tbody>

<?php
	foreach (config_get_path('captiveportal', []) as $cpzone => $cpitem):
		if (!is_array($cpitem)) {
			continue;
		}
?>
					<tr>
						<td><?=htmlspecialchars($cpzone);?></td>
						<td>
<?php
		$cpifaces = array_filter(explode(",", $cpitem['interface']));
		foreach ($cpifaces as $cpiface) {
			echo convert_friendly_interface_to_friendly_descr($cpiface) . " ";
		}
?>
						</td>
						<td><?=count(captiveportal_read_db());?></td>
						<td><?=htmlspecialchars($cpitem['descr']);?>&nbsp;</td>
						<td class="fs-col-actions">
<?=fs_row_actions([
								['edit', "services_captiveportal.php?zone=" . urlencode($cpzone), $cpzone],
								['delete', "services_captiveportal_zones.php?act=del&zone=" . urlencode($cpzone), $cpzone, ['thing' => gettext('zone'),
								    'detail' => gettext('Its users are disconnected and its settings are removed.')]],
							])?>
						</td>
					</tr>
<?php
	endforeach;
?>
<?php if (empty(config_get_path('captiveportal', []))) {
	fs_empty_row(5, gettext('No captive portal zones yet.'), 'services_captiveportal_zones_edit.php', gettext('Add zone'));
} ?>
				</tbody>
			</table>
		</div>
	</div>
</form>


<?php include("foot.inc"); ?>
