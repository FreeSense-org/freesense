<?php
/*
* diag_arp.php
*
* part of FreeSense (https://www.freesense.org)
* Copyright (c) 2004-2013 BSD Perimeter
* Copyright (c) 2013-2016 Electric Sheep Fencing
* Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
* Copyright (c) 2025-2026 The FreeSense Project
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
##|*IDENT=page-diagnostics-arptable
##|*NAME=Diagnostics: ARP Table
##|*DESCR=Allow access to the 'Diagnostics: ARP Table' page.
##|*MATCH=diag_arp.php*
##|-PRIV

@ini_set('zlib.output_compression', 0);
@ini_set('implicit_flush', 1);

require_once('guiconfig.inc');
require_once('diag_arp.inc');

define('ARP_BIN', '/usr/sbin/arp');

/* delete ARP cache entry */
if (isset($_POST['deleteentry'])) {
	$ip = $_POST['deleteentry'];

	$rc = 0;
	$savemsg = sprintf(gettext('The ARP cache entry for %s has been deleted.'), $ip);
	$savemsgtype = 'success';

	if (is_ipaddrv4($ip)) {
		exec(implode(' ', [ARP_BIN, '-d', escapeshellarg($ip)]), $out, $rc);
		if ($rc !== 0) {
			$savemsg = sprintf(gettext('%s could not be deleted.'), $ip);
			$savemsgtype = 'warning';
		}
	} else {
		$savemsg = sprintf(gettext('%s is not a valid IPv4 address.'), $ip);
		$savemsgtype = 'warning';
	}
}

/* clear ARP table */
if (isset($_POST['cleararptable'])) {
	$rc = 0;
	$savemsg = gettext('ARP table has been cleared.');
	$savemsgtype = 'success';

	exec(implode(' ', [ARP_BIN, '-d', '-a']), $out, $rc);
	if ($rc !== 0) {
		$savemsg = gettext('Unable to clear ARP table.');
		$savemsgtype = 'warning';
	}
}

$arp_table = prepare_ARP_table();

/* interface filter choices and per-entry state */
$arp_ifs = [];
$arp_counts = ['dynamic' => 0, 'permanent' => 0, 'incomplete' => 0];
foreach ($arp_table as &$entry) {
	$arp_ifs[$entry['interface']] = $entry['interface'];
	if (strpos($entry['mac-address'], '(') === 0) {
		$entry['state'] = 'incomplete';
	} elseif ($entry['expires'] === gettext('Permanent')) {
		$entry['state'] = 'permanent';
	} else {
		$entry['state'] = 'dynamic';
	}
	$arp_counts[$entry['state']]++;
}
unset($entry);
natcasesort($arp_ifs);

$pgtitle = [gettext('Diagnostics'), gettext('ARP Table')];
if (!empty($arp_table)) {
	fs_page_action(gettext('Clear ARP table'), 'diag_arp.php?cleararptable=true', 'fa-trash-can', 'danger', [
		'usepost' => true,
		'data-fs-confirm' => gettext('Clear the ARP table?'),
		'data-fs-confirm-detail' => gettext('All entries are removed. Hosts are learned again as soon as they send traffic.'),
		'data-fs-confirm-action' => gettext('Clear table'),
	]);
}
include('head.inc');

if ($savemsg) {
	print_info_box(htmlentities($savemsg), $savemsgtype);
}
?>

<div class="fs-tiles">
<?php
fs_tile(gettext('Entries'), count($arp_table));
fs_tile(gettext('Dynamic'), $arp_counts['dynamic']);
fs_tile(gettext('Permanent'), $arp_counts['permanent']);
fs_tile(gettext('Incomplete'), $arp_counts['incomplete'], $arp_counts['incomplete'] ? 'warn' : null);
?>
</div>

<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'search' => gettext('Search IP, MAC, hostname…'),
	'noun' => gettext('entries'),
	'noun_one' => gettext('entry'),
	'filters' => [
		'if' => [gettext('All interfaces')] + $arp_ifs,
		'state' => [gettext('All states'), 'dynamic' => gettext('Dynamic'), 'permanent' => gettext('Permanent'), 'incomplete' => gettext('Incomplete')],
	],
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover" data-sortable>
			<thead>
				<tr>
					<th class="fs-col-status"><?=gettext('Status')?></th>
					<th data-fs-search><?=gettext('IP address')?></th>
					<th data-fs-search><?=gettext('MAC address')?></th>
					<th data-fs-search><?=gettext('Hostname')?></th>
					<th data-fs-search><?=gettext('Interface')?></th>
					<th data-fs-search><?=gettext('Link type')?></th>
					<th class="fs-col-actions" data-sortable="false"><span class="visually-hidden"><?=gettext('Actions')?></span></th>
				</tr>
			</thead>
			<tbody>
<?php foreach ($arp_table as $entry):
	$ip = $entry['ip-address'];
	$actions = [];
	if ($entry['assigned']) { /* only useful for assigned interfaces */
		$actions[] = ['custom', 'services_wol_edit.php?if=' . urlencode($entry['if']) . '&mac=' . urlencode($entry['mac-address']) . '&descr=' . urlencode($entry['dnsresolve']), $ip,
		    ['icon' => 'fa-bookmark', 'label' => sprintf(gettext('Add a Wake-on-LAN mapping for %s'), $ip)]];
		$actions[] = ['custom', 'services_wol.php?if=' . urlencode($entry['if']) . '&mac=' . urlencode($entry['mac-address']), $ip,
		    ['icon' => 'fa-power-off', 'label' => sprintf(gettext('Send a Wake-on-LAN packet to %s'), $ip), 'post' => true]];
	}
	$actions[] = ['delete', 'diag_arp.php?deleteentry=' . rawurlencode($ip), $ip,
	    ['thing' => gettext('ARP entry'), 'detail' => gettext('The host is learned again as soon as it sends traffic.')]];
	$seconds = preg_match('/(\d+)/', $entry['expires'], $m) ? (int)$m[1] : null;
?>
				<tr data-fs-filter-if="<?=htmlspecialchars($entry['interface'])?>" data-fs-filter-state="<?=$entry['state']?>">
					<td>
<?php if ($entry['state'] === 'incomplete'): ?>
						<?=fs_badge('warn', gettext('Incomplete'), gettext('The host has not replied to an ARP request yet.'))?>
<?php elseif ($entry['state'] === 'permanent'): ?>
						<?=fs_badge('info', gettext('Permanent'), gettext('Local interface or static ARP entry.'))?>
<?php else: ?>
						<?=fs_badge('pass', gettext('Dynamic'), $entry['expires'])?>
<?php if ($seconds !== null): ?>
						<div class="fs-muted small text-nowrap" title="<?=htmlspecialchars($entry['expires'])?>"><?=htmlspecialchars(sprintf(gettext('expires in %s'), sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60)))?></div>
<?php endif; ?>
<?php endif; ?>
					</td>
					<td class="fs-mono"><?=htmlspecialchars($ip)?></td>
					<td>
						<span class="fs-mono"><?=htmlspecialchars($entry['mac-address'])?></span>
<?php if ($entry['mac-man']): ?>
						<div class="fs-muted small"><?=htmlspecialchars($entry['mac-man'])?></div>
<?php endif; ?>
					</td>
					<td><?=htmlspecialchars($entry['dnsresolve'])?></td>
					<td><?=htmlspecialchars($entry['interface'])?></td>
					<td class="fs-muted"><?=htmlspecialchars($entry['type'])?></td>
					<td class="fs-col-actions"><?=fs_row_actions($actions)?></td>
				</tr>
<?php endforeach; ?>
<?php if (empty($arp_table)) {
	fs_empty_row(7, gettext('The ARP table is empty.'));
} ?>
			</tbody>
		</table>
	</div>
	<div class="panel-footer small fs-muted">
		<i class="fa-solid fa-circle-info" aria-hidden="true"></i>
		<?=gettext('Dynamic entries expire and are then checked again. Permanent entries belong to local interfaces or static ARP mappings. Incomplete entries are hosts that have not replied to an ARP request yet.')?>
		<?=sprintf(gettext('Local IPv6 peers use %1$sNDP%2$s instead of ARP.'), '<a href="diag_ndp.php">', '</a>')?>
	</div>
</div>

<?php
include('foot.inc');
