<?php
/*
 * status_wireless.php
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
##|*IDENT=page-diagnostics-wirelessstatus
##|*NAME=Status: Wireless
##|*DESCR=Allow access to the 'Status: Wireless' page.
##|*MATCH=status_wireless.php*
##|-PRIV

require_once("guiconfig.inc");

$pgtitle = array(gettext("Status"), gettext("Wireless"));
$shortcut_section = "wireless";

$if = $_REQUEST['if'];

/* wireless interfaces only; an unknown ?if= falls back to the first one */
$ciflist = get_configured_interface_with_descr();
$wlifs = [];
foreach ($ciflist as $interface => $ifdescr) {
	if (is_interface_wireless(get_real_interface($interface))) {
		$wlifs[$interface] = $ifdescr;
	}
}
if (empty($if) || !isset($wlifs[$if])) {
	$if = array_key_first($wlifs);
}

$tab_array = array();
foreach ($wlifs as $interface => $ifdescr) {
	$tab_array[] = array(htmlspecialchars($ifdescr), ($if == $interface), "status_wireless.php?if={$interface}");
}

$rwlif = ($if !== null) ? get_real_interface($if) : '';

if (($_POST['rescanwifi'] != "") && ($rwlif !== '')) {
	mwexec_bg("/sbin/ifconfig " . escapeshellarg($rwlif) . " scan 2>&1");
	$savemsg = gettext("Rescan has been initiated in the background. Refresh this page in 10 seconds to see the results.");
}

/* nearby access points / ad-hoc peers */
$aps = [];
$stas = [];
if ($rwlif !== '') {
	$states = array();
	exec("/sbin/ifconfig " . escapeshellarg($rwlif) . " list scan 2>&1", $states, $ret);
	/* Skip Header */
	array_shift($states);
	foreach ($states as $state) {
		/* Split by Mac address for the SSID Field */
		$split = preg_split("/([0-9a-f][[0-9a-f]\:[0-9a-f][[0-9a-f]\:[0-9a-f][[0-9a-f]\:[0-9a-f][[0-9a-f]\:[0-9a-f][[0-9a-f]\:[0-9a-f][[0-9a-f])/i", $state);
		if (!preg_match("/([0-9a-f][[0-9a-f]\:[0-9a-f][[0-9a-f]\:[0-9a-f][[0-9a-f]\:[0-9a-f][[0-9a-f]\:[0-9a-f][[0-9a-f]\:[0-9a-f][[0-9a-f])/i", $state, $bssid)) {
			continue;
		}
		/* Split the rest by using spaces for this line using the 2nd part */
		$rest = preg_split("/[ ]+/i", $split[1]);
		$aps[] = [
			'ssid' => trim($split[0]),
			'bssid' => $bssid[0],
			'channel' => $rest[1] ?? '',
			'rate' => $rest[2] ?? '',
			'rssi' => $rest[3] ?? '',
			'int' => $rest[4] ?? '',
			'caps' => trim(implode(' ', array_slice($rest, 5, 7))),
		];
	}

	/* associated stations / ad-hoc peers */
	$states = array();
	exec("/sbin/ifconfig " . escapeshellarg($rwlif) . " list sta 2>&1", $states, $ret);
	array_shift($states);
	foreach ($states as $state) {
		$split = preg_split("/[ ]+/i", trim($state));
		if (count($split) < 2) {
			continue;
		}
		$stas[] = array_pad(array_slice($split, 0, 10), 10, '');
	}
}

if ($rwlif !== '') {
	fs_page_action(gettext('Rescan'), 'status_wireless.php?if=' . urlencode($if) . '&rescanwifi=Rescan', 'fa-arrows-rotate', 'primary', ['usepost' => true]);
}

include("head.inc");

if ($savemsg) {
	print_info_box($savemsg, 'success');
}

if (count($tab_array) > 1) {
	display_top_tabs($tab_array);
}

?>

<style>
.fs-wl-num { font-variant-numeric: tabular-nums; white-space: nowrap; }
.fs-wl-legend { display: flex; flex-wrap: wrap; gap: .25rem 1.5rem; }
</style>

<?php if ($rwlif === ''): ?>
<div class="panel panel-default">
	<div class="fs-tool-empty">
		<i class="fa-solid fa-wifi" aria-hidden="true"></i>
		<span><?=gettext('No wireless interfaces are assigned.')?></span>
<?php if (isAllowedPage('interfaces_wireless.php')): ?>
		<a class="btn btn-primary" href="interfaces_wireless.php"><i class="fa-solid fa-plus icon-embed-btn" aria-hidden="true"></i><?=gettext('Add a wireless interface')?></a>
<?php endif; ?>
	</div>
</div>
<?php else: ?>
<div class="fs-tiles">
<?php
fs_tile(gettext('Interface'), $if ? ($wlifs[$if] ?? $if) : '–', null, $rwlif ?: null);
fs_tile(gettext('Access points nearby'), count($aps));
fs_tile(gettext('Associated peers'), count($stas), count($stas) ? 'online' : null);
?>
</div>

<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Nearby access points and ad-hoc peers'),
	'search' => gettext('Search SSID, BSSID…'),
	'noun' => gettext('access points'),
	'noun_one' => gettext('access point'),
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover" data-sortable>
			<thead>
				<tr>
					<th data-fs-search><?=gettext('SSID')?></th>
					<th data-fs-search><?=gettext('BSSID')?></th>
					<th data-fs-search><?=gettext('Channel')?></th>
					<th><?=gettext('Rate')?></th>
					<th><?=gettext('RSSI')?></th>
					<th><?=gettext('Interval')?></th>
					<th data-fs-search><?=gettext('Capabilities')?></th>
				</tr>
			</thead>
			<tbody>
<?php foreach ($aps as $ap): ?>
				<tr>
					<td><?=($ap['ssid'] !== '') ? htmlspecialchars($ap['ssid']) : '<span class="fs-muted">' . gettext('(hidden)') . '</span>'?></td>
					<td class="fs-mono"><?=htmlspecialchars($ap['bssid'])?></td>
					<td class="fs-mono fs-wl-num"><?=htmlspecialchars($ap['channel'])?></td>
					<td class="fs-mono fs-wl-num"><?=htmlspecialchars($ap['rate'])?></td>
					<td class="fs-mono fs-wl-num"><?=htmlspecialchars($ap['rssi'])?></td>
					<td class="fs-mono fs-wl-num"><?=htmlspecialchars($ap['int'])?></td>
					<td class="fs-mono"><?=htmlspecialchars($ap['caps'])?></td>
				</tr>
<?php endforeach; ?>
<?php if (empty($aps)) {
	fs_empty_row(7, gettext('No access points found. Rescan, then refresh in a few seconds.'));
} ?>
			</tbody>
		</table>
	</div>
</div>

<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Associated or ad-hoc peers'),
	'search' => gettext('Search addresses…'),
	'noun' => gettext('peers'),
	'noun_one' => gettext('peer'),
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover" data-sortable>
			<thead>
				<tr>
					<th data-fs-search><?=gettext('Address')?></th>
					<th><?=gettext('AID')?></th>
					<th><?=gettext('Channel')?></th>
					<th><?=gettext('Rate')?></th>
					<th><?=gettext('RSSI')?></th>
					<th><?=gettext('Idle')?></th>
					<th><?=gettext('TX seq')?></th>
					<th><?=gettext('RX seq')?></th>
					<th data-fs-search><?=gettext('Capabilities')?></th>
					<th data-fs-search><?=gettext('ERP')?></th>
				</tr>
			</thead>
			<tbody>
<?php foreach ($stas as $sta): ?>
				<tr>
<?php foreach ($sta as $idx => $value): ?>
					<td class="fs-mono<?=(($idx > 0) && ($idx < 8)) ? ' fs-wl-num' : ''?>"><?=htmlspecialchars($value)?></td>
<?php endforeach; ?>
				</tr>
<?php endforeach; ?>
<?php if (empty($stas)) {
	fs_empty_row(10, gettext('No associated peers.'));
} ?>
			</tbody>
		</table>
	</div>
	<div class="panel-footer small fs-muted fs-wl-legend">
		<span><strong><?=gettext('Flags')?>:</strong> <?=gettext('A = authorized, E = Extended Rate (802.11g), P = Power saving mode.')?></span>
		<span><strong><?=gettext('Capabilities')?>:</strong> <?=gettext('E = ESS (infrastructure mode), I = IBSS (ad-hoc mode), P = privacy (WEP/TKIP/AES), S = Short preamble, s = Short slot time.')?></span>
	</div>
</div>
<?php endif; ?>

<?php
include("foot.inc");
