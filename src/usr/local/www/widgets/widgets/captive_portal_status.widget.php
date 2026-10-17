<?php
/*
 * captive_portal_status.widget.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2007 Sam Wenham
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
 * All rights reserved.
 *
 * originally part of m0n0wall (http://m0n0.ch/wall)
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

require_once("globals.inc");
require_once("guiconfig.inc");
require_once("freesense-utils.inc");
require_once("functions.inc");
require_once("captiveportal.inc");

$cpzone = $_GET['zone'];
if (isset($_POST['zone'])) {
	$cpzone = $_POST['zone'];
}
$cpzone = strtolower($cpzone);

if (isset($cpzone) && !empty($cpzone)) {
	$cpzoneid = config_get_path("captiveportal/{$cpzone}/zoneid");
}

/* Disconnecting changes state, so it is a CSRF-checked POST, never a link. */
if ((($_POST['act'] ?? '') === "del") && !empty($cpzone) && isset($cpzoneid) && isset($_POST['id'])) {
	captiveportal_disconnect_client($_POST['id'], 6);
}
unset($cpzone);

flush();

if (!function_exists('clientcmp')) {
	function clientcmp($a, $b) {
		global $order;
		return strcmp($a[$order], $b[$order]);
	}
}

$cpdb_all = array();

foreach (config_get_path('captiveportal', []) as $cpzone => $cp) {
	$cpdb = captiveportal_read_db();
	foreach ($cpdb as $cpent) {
		$cpent[10] = $cpzone;
		$cpent[11] = captiveportal_get_last_activity($cpent[2]);
		$cpdb_all[] = $cpent;
	}
}

?>
<div class="table-responsive">
	<table class="table table-sm sortable-theme-bootstrap" data-sortable>
		<thead>
		<tr>
			<th><?=gettext("IP address");?></th>
			<th><?=gettext("MAC address");?></th>
			<th><?=gettext("Username");?></th>
			<th><?=gettext("Session start");?></th>
			<th><?=gettext("Last activity");?></th>
			<th>&nbsp;</th>
		</tr>
		</thead>
		<tbody>
	<?php foreach ($cpdb_all as $cpent): ?>
		<tr>
			<td><?=htmlspecialchars($cpent[2]);?></td>
			<td><?=htmlspecialchars($cpent[3]);?></td>
			<td><?=htmlspecialchars($cpent[4]);?></td>
			<td><?=date("m/d/Y H:i:s", $cpent[0]);?></td>
			<td>
<?php
			if ($cpent[11] && ($cpent[11] > 0)):
				echo date("m/d/Y H:i:s", $cpent[11]);
			else:
				echo "&nbsp;";
			endif;
?>
			</td>
			<td>
				<form method="post" class="d-inline">
					<input type="hidden" name="act" value="del" />
					<input type="hidden" name="zone" value="<?=htmlspecialchars($cpent[10]);?>" />
					<input type="hidden" name="id" value="<?=htmlspecialchars($cpent[5]);?>" />
					<button type="submit" class="btn btn-link p-0" title="<?=gettext("delete");?>">
						<i class="fa-solid fa-trash-can"></i>
					</button>
				</form>
			</td>
		</tr>
	<?php
	endforeach;
	if (empty($cpdb_all)):
	?>
		<tr><td colspan="6" class="p-0"><?=fs_widget_empty('fa-wifi', gettext('Nobody is logged in to the captive portal.'))?></td></tr>
	<?php endif; ?>
		</tbody>
	</table>
</div>
