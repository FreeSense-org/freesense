<?php
/*
 * diag_ndp.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
 * Copyright (c) 2011 Seth Mos <seth.mos@dds.nl>
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
##|*IDENT=page-diagnostics-ndptable
##|*NAME=Diagnostics: NDP Table
##|*DESCR=Allow access to the 'Diagnostics: NDP Table' page.
##|*MATCH=diag_ndp.php*
##|-PRIV

@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);
define('NDP_BINARY_PATH', '/usr/sbin/ndp');
require_once("guiconfig.inc");
require_once("diag_ndp.inc");

// Delete ndp entry.
if (isset($_POST['deleteentry'])) {
	$ip = $_POST['deleteentry'];
	if (is_ipaddrv6($ip)) {
		$commandReturnValue = mwexec(NDP_BINARY_PATH . " -d " . escapeshellarg($ip), true);
		$deleteSucceededFlag = ($commandReturnValue == 0);
	} else {
		$deleteSucceededFlag = false;
	}

	$deleteResultMessage = ($deleteSucceededFlag)
		? sprintf(gettext("The NDP entry for %s has been deleted."), $ip)
		: sprintf(gettext("%s is not a valid IPv6 address or could not be deleted."), $ip);
	$deleteResultMessageType = ($deleteSucceededFlag)
		? 'success'
		: 'warning';
} elseif (isset($_POST['clearndptable'])) {
	$out = "";
	$ret = exec("/usr/sbin/ndp -c", $out, $ndpTableRetVal);
	if ($ndpTableRetVal == 0) {
		$deleteResultMessage = gettext("NDP Table has been cleared.");
		$deleteResultMessageType = 'success';
	} else {
		$deleteResultMessage = gettext("Unable to clear NDP Table.");
		$deleteResultMessageType = 'warning';
	}
}

/* if list */
$ifdescrs = get_configured_interface_with_descr();

foreach ($ifdescrs as $key =>$interface) {
	$hwif[config_get_path("interfaces/{$key}/if")] = $interface;
}

$data = diag_ndp_table();

// Load MAC-Manufacturer table
$mac_man = load_mac_manufacturer_table();

/* interface names, vendors and per-entry state */
$ndp_ifs = [];
$ndp_counts = ['dynamic' => 0, 'permanent' => 0, 'other' => 0];
foreach ($data as &$entry) {
	$entry['ifname'] = $hwif[$entry['interface']] ?? $entry['interface'];
	$ndp_ifs[$entry['ifname']] = $entry['ifname'];

	$mac = trim($entry['mac']);
	$entry['vendor'] = null;
	if (strlen($mac) >= 8) {
		$mac_hi = strtoupper($mac[0] . $mac[1] . $mac[3] . $mac[4] . $mac[6] . $mac[7]);
		$entry['vendor'] = $mac_man[$mac_hi] ?? null;
	}

	if (stripos($mac, 'incomplete') !== false) {
		$entry['state'] = 'incomplete';
	} elseif ($entry['expiration'] === 'permanent') {
		$entry['state'] = 'permanent';
	} elseif ($entry['expiration'] === 'expired') {
		$entry['state'] = 'expired';
	} else {
		$entry['state'] = 'dynamic';
	}
	$ndp_counts[isset($ndp_counts[$entry['state']]) ? $entry['state'] : 'other']++;
}
unset($entry);
natcasesort($ndp_ifs);

$pgtitle = array(gettext("Diagnostics"), gettext("NDP Table"));
if (!empty($data)) {
	fs_page_action(gettext('Clear NDP table'), 'diag_ndp.php?clearndptable=true', 'fa-trash-can', 'danger', [
		'usepost' => true,
		'data-fs-confirm' => gettext('Clear the NDP table?'),
		'data-fs-confirm-detail' => gettext('All neighbor entries are removed. Neighbors are discovered again as soon as they send traffic.'),
		'data-fs-confirm-action' => gettext('Clear table'),
	]);
}
include("head.inc");

// Show message if defined.
if (isset($deleteResultMessage, $deleteResultMessageType)) {
	print_info_box(htmlentities($deleteResultMessage), $deleteResultMessageType);
}
?>

<div class="fs-tiles">
<?php
fs_tile(gettext('Neighbors'), count($data));
fs_tile(gettext('Dynamic'), $ndp_counts['dynamic']);
fs_tile(gettext('Permanent'), $ndp_counts['permanent']);
fs_tile(gettext('Incomplete or expired'), $ndp_counts['other'], $ndp_counts['other'] ? 'warn' : null);
?>
</div>

<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'search' => gettext('Search IPv6, MAC, hostname…'),
	'noun' => gettext('neighbors'),
	'noun_one' => gettext('neighbor'),
	'filters' => [
		'if' => [gettext('All interfaces')] + $ndp_ifs,
		'state' => [gettext('All states'), 'dynamic' => gettext('Dynamic'), 'permanent' => gettext('Permanent'), 'incomplete' => gettext('Incomplete'), 'expired' => gettext('Expired')],
	],
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover" data-sortable>
			<thead>
				<tr>
					<th class="fs-col-status"><?=gettext('Status')?></th>
					<th data-fs-search><?=gettext('IPv6 address')?></th>
					<th data-fs-search><?=gettext('MAC address')?></th>
					<th data-fs-search><?=gettext('Hostname')?></th>
					<th data-fs-search><?=gettext('Interface')?></th>
					<th class="fs-col-actions" data-sortable="false"><span class="visually-hidden"><?=gettext('Actions')?></span></th>
				</tr>
			</thead>
			<tbody>
<?php foreach ($data as $entry):
	$ip = $entry['ipv6'];
?>
				<tr data-fs-filter-if="<?=htmlspecialchars($entry['ifname'])?>" data-fs-filter-state="<?=$entry['state']?>">
					<td>
<?php if ($entry['state'] === 'incomplete'): ?>
						<?=fs_badge('warn', gettext('Incomplete'))?>
<?php elseif ($entry['state'] === 'permanent'): ?>
						<?=fs_badge('info', gettext('Permanent'))?>
<?php elseif ($entry['state'] === 'expired'): ?>
						<?=fs_badge('neutral', gettext('Expired'))?>
<?php else: ?>
						<?=fs_badge('pass', gettext('Dynamic'))?>
						<div class="fs-muted small fs-mono text-nowrap" title="<?=gettext('Expires in')?>"><?=htmlspecialchars($entry['expiration'])?></div>
<?php endif; ?>
					</td>
					<td class="fs-mono"><?=htmlspecialchars($ip)?></td>
					<td>
						<span class="fs-mono"><?=htmlspecialchars(trim($entry['mac']))?></span>
<?php if ($entry['vendor']): ?>
						<div class="fs-muted small"><?=htmlspecialchars($entry['vendor'])?></div>
<?php endif; ?>
					</td>
					<td><?=htmlspecialchars(str_replace("Z_ ", "", $entry['dnsresolve']))?></td>
					<td>
						<?=htmlspecialchars($entry['ifname'])?>
<?php if ($entry['ifname'] !== $entry['interface']): ?>
						<div class="fs-muted small fs-mono"><?=htmlspecialchars($entry['interface'])?></div>
<?php endif; ?>
					</td>
					<td class="fs-col-actions"><?=fs_row_actions([
						['delete', 'diag_ndp.php?deleteentry=' . rawurlencode($ip), $ip,
						    ['thing' => gettext('NDP entry'), 'detail' => gettext('The neighbor is discovered again as soon as it sends traffic.')]],
					])?></td>
				</tr>
<?php endforeach; ?>
<?php if (empty($data)) {
	fs_empty_row(6, gettext('The NDP table is empty.'));
} ?>
			</tbody>
		</table>
	</div>
	<div class="panel-footer small fs-muted">
		<i class="fa-solid fa-circle-info" aria-hidden="true"></i>
		<?=gettext('The Neighbor Discovery Protocol (NDP) table lists IPv6 neighbors, the IPv6 counterpart of the ARP table.')?>
		<?=sprintf(gettext('IPv4 hosts are listed in the %1$sARP table%2$s.'), '<a href="diag_arp.php">', '</a>')?>
	</div>
</div>

<?php
include('foot.inc');
