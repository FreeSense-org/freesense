<?php
/*
 * status_captiveportal_voucher_rolls.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
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
##|*IDENT=page-status-captiveportal-voucher-rolls
##|*NAME=Status: Captive Portal Voucher Rolls
##|*DESCR=Allow access to the 'Status: Captive Portal Voucher Rolls' page.
##|*MATCH=status_captiveportal_voucher_rolls.php*
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
	header("Location: services_captiveportal_zones.php");
	exit;
}

$pgtitle = array(gettext("Status"), gettext("Captive Portal"), htmlspecialchars($cpzone), gettext("Voucher Rolls"));
$pglinks = array("", "status_captiveportal.php", "status_captiveportal.php?zone=" . $cpzone, "@self");
$shortcut_section = "captiveportal-vouchers";

/* collect the roll counters first: the tiles and the list share them */
$rolls = [];
$totals = ['count' => 0, 'used' => 0, 'active' => 0, 'ready' => 0];
$voucherlck = lock("voucher{$cpzone}");
foreach (config_get_path("voucher/{$cpzone}/roll", []) as $rollent) {
	$used = voucher_used_count($rollent['number']);
	$active = count(voucher_read_active_db($rollent['number']));
	$ready = $rollent['count'] - $used;
	/* used also count active vouchers, remove them */
	$used = $used - $active;
	$rolls[] = [$rollent, $used, $active, $ready];
	$totals['count'] += intval($rollent['count']);
	$totals['used'] += $used;
	$totals['active'] += $active;
	$totals['ready'] += $ready;
}
unlock($voucherlck);

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
?>
<style>
.fs-cp-zone { display: flex; flex-wrap: wrap; align-items: center; gap: var(--fs-sp-2); margin-bottom: var(--fs-sp-4); }
.fs-cp-zone label { margin: 0; color: var(--fs-text-muted); font-size: var(--fs-fs-sm); font-weight: 500; }
.fs-cp-zone .form-select { width: auto; min-width: 12rem; max-width: 100%; }
.fs-cp-meter { display: flex; align-items: center; gap: .6rem; min-width: 10rem; }
.fs-cp-meter progress { flex: 1 1 auto; height: .5rem; border: 0; border-radius: 999px; overflow: hidden; appearance: none; background: var(--fs-surface-raised); color: var(--fs-pass); }
.fs-cp-meter progress::-webkit-progress-bar { background: var(--fs-surface-raised); }
.fs-cp-meter progress::-webkit-progress-value { background: var(--fs-pass); }
.fs-cp-meter progress::-moz-progress-bar { background: var(--fs-pass); }
.fs-cp-meter.is-warn progress::-webkit-progress-value { background: var(--fs-warn); }
.fs-cp-meter.is-warn progress::-moz-progress-bar { background: var(--fs-warn); }
.fs-cp-meter.is-full progress::-webkit-progress-value { background: var(--fs-block); }
.fs-cp-meter.is-full progress::-moz-progress-bar { background: var(--fs-block); }
.fs-cp-meter > span { min-width: 3rem; text-align: right; font-variant-numeric: tabular-nums; }
.fs-cp-num { text-align: right; font-variant-numeric: tabular-nums; }
</style>
<?php if (count($voucher_zones) > 1): ?>
<form method="get" action="status_captiveportal_voucher_rolls.php" class="fs-cp-zone" data-fs-zone-picker>
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

fs_tabs('status-captiveportal', 'status_captiveportal_voucher_rolls.php', ['zone' => $cpzone]);
?>

<div class="fs-tiles">
<?php
fs_tile(gettext('Rolls'), count($rolls), null, sprintf(gettext('%d tickets'), $totals['count']));
fs_tile(gettext('Ready'), $totals['ready']);
fs_tile(gettext('Active'), $totals['active'], ($totals['active'] > 0) ? 'active' : null);
fs_tile(gettext('Used'), $totals['used']);
?>
</div>

<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Voucher rolls'),
	'search' => gettext('Search rolls…'),
	'filters' => ['state' => [gettext('All rolls'), 'available' => gettext('Available'), 'empty' => gettext('Used up')]],
	'noun' => gettext('rolls'),
	'noun_one' => gettext('roll'),
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover" data-sortable>
			<thead>
				<tr>
					<th data-fs-search><?=gettext("Roll"); ?></th>
					<th><?=gettext("Status"); ?></th>
					<th class="fs-cp-num"><?=gettext("Minutes per ticket"); ?></th>
					<th class="fs-cp-num"><?=gettext("Tickets"); ?></th>
					<th class="fs-cp-num"><?=gettext("Active"); ?></th>
					<th class="fs-cp-num"><?=gettext("Used"); ?></th>
					<th class="fs-cp-num"><?=gettext("Ready"); ?></th>
					<th data-sortable="false"><?=gettext("Usage"); ?></th>
					<th data-fs-search><?=gettext("Comment"); ?></th>
				</tr>
			</thead>
			<tbody>
<?php
foreach ($rolls as $r):
	list($rollent, $used, $active, $ready) = $r;
	$count = max(0, intval($rollent['count']));
	$pct = ($count > 0) ? min(100, (int)round(100 * ($count - max(0, $ready)) / $count)) : 0;
	$meter = ($pct >= 100) ? ' is-full' : (($pct >= 80) ? ' is-warn' : '');
	$available = ($ready > 0);
?>
				<tr data-fs-filter-state="<?=$available ? 'available' : 'empty'?>">
					<td><strong><?=htmlspecialchars($rollent['number'])?></strong></td>
					<td><?=$available ? fs_badge('active', gettext('Available')) : fs_badge('expired', gettext('Used up'))?></td>
					<td class="fs-cp-num"><?=htmlspecialchars($rollent['minutes'])?></td>
					<td class="fs-cp-num"><?=htmlspecialchars($rollent['count'])?></td>
					<td class="fs-cp-num"><?=htmlspecialchars($active)?></td>
					<td class="fs-cp-num"><?=htmlspecialchars($used)?></td>
					<td class="fs-cp-num"><?=htmlspecialchars($ready)?></td>
					<td>
						<div class="fs-cp-meter<?=$meter?>">
							<progress max="100" value="<?=$pct?>" aria-label="<?=htmlspecialchars(sprintf(gettext('Roll %s usage'), $rollent['number']))?>"><?=$pct?>%</progress>
							<span><?=$pct?>%</span>
						</div>
					</td>
					<td><?=htmlspecialchars($rollent['comment'])?></td>
				</tr>
<?php
endforeach;

if (empty($rolls)) {
	fs_empty_row(9, gettext('No voucher rolls yet.'),
	    isAllowedPage('services_captiveportal_vouchers_edit.php') ? 'services_captiveportal_vouchers_edit.php?zone=' . urlencode($cpzone) : null,
	    gettext('Add roll'));
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
