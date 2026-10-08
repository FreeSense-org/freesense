<?php
/*
 * status_captiveportal_vouchers.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
 * Copyright (c) 2007 Marcel Wiget <mwiget@mac.com>
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
##|*IDENT=page-status-captiveportal-vouchers
##|*NAME=Status: Captive Portal Vouchers
##|*DESCR=Allow access to the 'Status: Captive Portal Vouchers' page.
##|*MATCH=status_captiveportal_vouchers.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("functions.inc");
require_once("filter.inc");
require_once("shaper.inc");
require_once("captiveportal.inc");
require_once("voucher.inc");

$cpzone = strtolower($_REQUEST['zone']);

/* If the zone does not exist, do not display the invalid zone */
if (!array_key_exists($cpzone, config_get_path('captiveportal', []))) {
	$cpzone = "";
}

if (empty($cpzone)) {
	header("Location: status_captiveportal.php");
	exit;
}

$pgtitle = array(gettext("Status"), gettext("Captive Portal"), htmlspecialchars($cpzone), gettext("Active Vouchers"));
$pglinks = array("", "status_captiveportal.php", "status_captiveportal.php?zone=" . $cpzone, "@self");
$shortcut_section = "captiveportal-vouchers";

$db = array();

foreach (config_get_path("voucher/{$cpzone}/roll", []) as $rollent) {
	$roll = $rollent['number'];
	$minutes = $rollent['minutes'];

	if (!file_exists("{$g['vardb_path']}/voucher_{$cpzone}_active_$roll.db")) {
		continue;
	}

	$active_vouchers = file("{$g['vardb_path']}/voucher_{$cpzone}_active_$roll.db", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	foreach ($active_vouchers as $voucher => $line) {
		list($voucher, $timestamp, $minutes) = explode(",", $line);
		$remaining = (($timestamp + 60*$minutes) - time());

		if ($remaining > 0) {
			$dbent[0] = $voucher;
			$dbent[1] = $roll;
			$dbent[2] = $timestamp;
			$dbent[3] = intval($remaining/60);
			$dbent[4] = $timestamp + 60*$minutes; // expires at
			$db[] = $dbent;
		}
	}
}

if (isAllowedPage('services_captiveportal_vouchers.php')) {
	fs_page_action(gettext('Manage vouchers'), 'services_captiveportal_vouchers.php?zone=' . $cpzone, 'fa-ticket', 'secondary');
}

include("head.inc");

/* zone picker: only zones that use vouchers */
$voucher_zones = [];
foreach (config_get_path('captiveportal', []) as $cpkey => $cp) {
	if (config_path_enabled("voucher/{$cpkey}") || ($cpkey == $cpzone)) {
		$voucher_zones[$cpkey] = $cpkey . (empty($cp['descr']) ? '' : ' – ' . $cp['descr']);
	}
}
if (count($voucher_zones) > 1):
?>
<style>
.fs-cp-zone { display: flex; flex-wrap: wrap; align-items: center; gap: var(--fs-sp-2); margin-bottom: var(--fs-sp-4); }
.fs-cp-zone label { margin: 0; color: var(--fs-text-muted); font-size: var(--fs-fs-sm); font-weight: 500; }
.fs-cp-zone .form-select { width: auto; min-width: 12rem; max-width: 100%; }
</style>
<form method="get" action="status_captiveportal_vouchers.php" class="fs-cp-zone" data-fs-zone-picker>
	<label for="zone"><?=gettext('Zone')?></label>
	<select class="form-select form-select-sm" id="zone" name="zone">
<?php foreach ($voucher_zones as $cpkey => $label): ?>
		<option value="<?=htmlspecialchars($cpkey)?>"<?=($cpkey == $cpzone) ? ' selected' : ''?>><?=htmlspecialchars($label)?></option>
<?php endforeach; ?>
	</select>
	<noscript><button type="submit" class="btn btn-sm btn-outline-secondary"><?=gettext('Show')?></button></noscript>
</form>
<?php
endif;

fs_tabs('status-captiveportal', 'status_captiveportal_vouchers.php', ['zone' => $cpzone]);

$rolls = [];
foreach ($db as $dbent) {
	$rolls[$dbent[1]] = sprintf(gettext('Roll %s'), $dbent[1]);
}
ksort($rolls);
?>

<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Vouchers in use'),
	'search' => gettext('Search vouchers…'),
	'filters' => (count($rolls) > 1) ? ['roll' => [gettext('All rolls')] + $rolls] : [],
	'noun' => gettext('vouchers'),
	'noun_one' => gettext('voucher'),
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover" data-sortable>
			<thead>
				<tr>
					<th data-fs-search><?=gettext("Voucher"); ?></th>
					<th data-fs-search><?=gettext("Roll"); ?></th>
					<th><?=gettext("Status"); ?></th>
					<th><?=gettext("Activated at"); ?></th>
					<th><?=gettext("Expires in"); ?></th>
					<th><?=gettext("Expires at"); ?></th>
				</tr>
			</thead>
			<tbody>
<?php
foreach ($db as $dbent):
	$soon = ($dbent[3] < 10);
?>
				<tr data-fs-filter-roll="<?=htmlspecialchars($dbent[1])?>">
					<td class="fs-mono"><?=htmlspecialchars($dbent[0])?></td>
					<td><?=htmlspecialchars($dbent[1])?></td>
					<td><?=$soon ? fs_badge('warn', gettext('Expiring')) : fs_badge('active')?></td>
					<td class="text-nowrap" data-value="<?=intval($dbent[2])?>"><?=htmlspecialchars(date("m/d/Y H:i:s", $dbent[2]))?></td>
					<td class="text-nowrap" data-value="<?=intval($dbent[3])?>"><?=htmlspecialchars(convert_seconds_to_dhms($dbent[3] * 60))?></td>
					<td class="text-nowrap" data-value="<?=intval($dbent[4])?>"><?=htmlspecialchars(date("m/d/Y H:i:s", $dbent[4]))?></td>
				</tr>
<?php
endforeach;

if (empty($db)) {
	fs_empty_row(6, gettext('No vouchers are in use.'));
}
?>
			</tbody>
		</table>
	</div>
</div>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	// Show another zone when the picker changes
	$('[data-fs-zone-picker] select').on('change', function() {
		this.form.submit();
	});
});
//]]>
</script>
<?php include("foot.inc");
