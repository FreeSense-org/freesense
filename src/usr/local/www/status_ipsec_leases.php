<?php
/*
 * status_ipsec_leases.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
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
##|*IDENT=page-status-ipsec-leases
##|*NAME=Status: IPsec: Leases
##|*DESCR=Allow access to the 'Status: IPsec: Leases' page.
##|*MATCH=status_ipsec_leases.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("ipsec.inc");

$pgtitle = array(gettext("Status"), gettext("IPsec"), gettext("Leases"));
$pglinks = array("", "status_ipsec.php", "@self");
$shortcut_section = "ipsec";

$mobile = ipsec_dump_mobile();
$pools = (isset($mobile['pool']) && is_array($mobile['pool'])) ? $mobile['pool'] : [];

$online = 0;
$leases = 0;
foreach ($pools as $pool) {
	$online += (int)$pool['online'];
	$leases += is_array($pool['lease'] ?? null) ? count($pool['lease']) : 0;
}

if (isAllowedPage('vpn_ipsec_mobile.php')) {
	fs_page_action(gettext('Mobile clients'), 'vpn_ipsec_mobile.php', 'fa-gear', 'secondary');
}

include("head.inc");

fs_tabs('status-ipsec', 'status_ipsec_leases.php');

if (!ipsec_enabled()) {
	print_info_box(sprintf(gettext('IPsec is disabled. %1$sConfigure IPsec%2$s.'), '<a href="vpn_ipsec.php">', '</a>'), 'info', false);
}
?>

<style>
.fs-ipsec-sub { display: block; color: var(--fs-text-muted); font-size: var(--fs-fs-xs); }
</style>

<div class="fs-tiles">
<?php
fs_tile(gettext('Pools'), count($pools));
fs_tile(gettext('Online'), $online, ($online > 0) ? 'online' : null);
fs_tile(gettext('Leases'), $leases);
?>
</div>

<?php if (!empty($pools)): ?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Pools'),
	'search' => false,
	'noun' => gettext('pools'),
	'noun_one' => gettext('pool'),
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover" data-sortable>
			<thead>
				<tr>
					<th><?=gettext("Pool")?></th>
					<th><?=gettext("Base")?></th>
					<th><?=gettext("Online")?></th>
					<th><?=gettext("Total usage")?></th>
				</tr>
			</thead>
			<tbody>
<?php foreach ($pools as $pool): ?>
				<tr>
					<td><?=htmlspecialchars($pool['name'])?></td>
					<td class="fs-mono"><?=htmlspecialchars($pool['base'])?></td>
					<td class="fs-mono"><?=htmlspecialchars($pool['online'])?></td>
					<td class="fs-mono"><?=($pool['size'] > 0) ? htmlspecialchars(($pool['online'] + $pool['offline']) . ' / ' . $pool['size']) : ''?></td>
				</tr>
<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>
<?php endif; ?>

<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Leases'),
	'search' => gettext('Search leases…'),
	'noun' => gettext('leases'),
	'noun_one' => gettext('lease'),
	'filters' => ['status' => [gettext('All leases'), 'online' => gettext('Online'), 'offline' => gettext('Offline')]],
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover" data-sortable>
			<thead>
				<tr>
					<th class="fs-col-status" data-fs-search><?=gettext("Status")?></th>
					<th data-fs-search><?=gettext("ID")?></th>
					<th data-fs-search><?=gettext("Host")?></th>
					<th data-fs-search><?=gettext("Pool")?></th>
				</tr>
			</thead>
			<tbody>
<?php foreach ($pools as $pool):
	foreach ((is_array($pool['lease'] ?? null) ? $pool['lease'] : []) as $lease):
		$is_online = ($lease['status'] == 'online');
?>
				<tr data-fs-filter-status="<?=$is_online ? 'online' : 'offline'?>">
					<td><?=$is_online ? fs_badge('online') : fs_badge('offline', ucfirst((string)$lease['status']))?></td>
					<td><?=htmlspecialchars($lease['id'])?></td>
					<td class="fs-mono"><?=htmlspecialchars($lease['host'])?></td>
					<td>
						<?=htmlspecialchars($pool['name'])?>
						<span class="fs-ipsec-sub fs-mono"><?=htmlspecialchars($pool['base'])?></span>
					</td>
				</tr>
<?php
	endforeach;
endforeach;
if ($leases == 0) {
	fs_empty_row(4, empty($pools) ? gettext('No IPsec pools.') : gettext('No leases from these pools yet.'));
}
?>
			</tbody>
		</table>
	</div>
</div>

<?php
include("foot.inc");
