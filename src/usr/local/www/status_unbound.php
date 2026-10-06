<?php
/*
 * status_unbound.php
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
##|*IDENT=page-status-dns-resolver
##|*NAME=Status: DNS Resolver
##|*DESCR=Allow access to the 'Status: DNS Resolver' page.
##|*MATCH=status_unbound.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("service-utils.inc");

$pgtitle = array(gettext("Status"), gettext("DNS Resolver"));
$shortcut_section = "resolver";

$infra_cache_entries = array();
$errors = "";
$running = config_path_enabled('unbound') && is_service_running('unbound');

if ($running) {
	exec("/usr/local/sbin/unbound-control -c {$g['unbound_chroot_path']}/unbound.conf dump_infra", $infra_cache_entries, $ubc_ret);
}

$view = fs_view_param(['speed', 'stats'], 'speed');

/*
 * dump_infra: "<server> <zone> ttl <n> ping <n> var <n> rtt <n> rto <n> tA <n> tAAAA <n> tother <n>
 * ednsknown <n> edns <n> delay <n> lame dnssec <n> rec <n> A <n> other <n>"; an expired entry is
 * "<server> <zone> ttl expired rto <n>".
 */
$entries = [];
$zones = [];
$expired = 0;
$rtt_sum = 0;
$rtt_n = 0;
foreach ($infra_cache_entries as $ice) {
	$line = explode(' ', $ice);
	$is_expired = (($line[3] ?? '') == "expired");
	$entries[] = [$line, $is_expired];
	$zones[$line[1] ?? ''] = true;
	if ($is_expired) {
		$expired++;
	} elseif (is_numeric($line[9] ?? null)) {
		$rtt_sum += (int)$line[9];
		$rtt_n++;
	}
}

include("head.inc");

if (!$running) {
	print_info_box(gettext("The DNS Resolver is disabled or stopped."), 'warning', false);
}
?>

<style>
.fs-unbound-num { font-variant-numeric: tabular-nums; white-space: nowrap; }
</style>

<?php fs_view_switch(['speed' => gettext('Infrastructure speed'), 'stats' => gettext('Infrastructure stats')], $view); ?>

<div class="fs-tiles">
<?php
fs_tile(gettext('Servers'), count($entries));
fs_tile(gettext('Zones'), count($zones));
fs_tile(gettext('Average RTT'), $rtt_n ? sprintf(gettext('%d ms'), round($rtt_sum / $rtt_n)) : '–');
fs_tile(gettext('Expired'), $expired);
?>
</div>

<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => ($view === 'speed') ? gettext('Infrastructure cache speed') : gettext('Infrastructure cache stats'),
	'search' => gettext('Search servers, zones…'),
	'noun' => gettext('servers'),
	'noun_one' => gettext('server'),
	'filters' => ['state' => [gettext('All entries'), 'valid' => gettext('Cached'), 'expired' => gettext('Expired')]],
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover" data-sortable>
			<thead>
				<tr>
					<th class="fs-col-status"><?=gettext("Status")?></th>
					<th data-fs-search><?=gettext("Server")?></th>
					<th data-fs-search><?=gettext("Zone")?></th>
<?php if ($view === 'speed'): ?>
					<th><?=gettext("TTL")?></th>
					<th><?=gettext("Ping")?></th>
					<th><?=gettext("Var")?></th>
					<th><?=gettext("RTT")?></th>
					<th><?=gettext("RTO")?></th>
					<th><?=gettext("Timeout A")?></th>
					<th><?=gettext("Timeout AAAA")?></th>
					<th><?=gettext("Timeout other")?></th>
<?php else: ?>
					<th><?=gettext("eDNS lame known")?></th>
					<th><?=gettext("eDNS version")?></th>
					<th><?=gettext("Probe delay")?></th>
					<th><?=gettext("Lame DNSSEC")?></th>
					<th><?=gettext("Lame rec")?></th>
					<th><?=gettext("Lame A")?></th>
					<th><?=gettext("Lame other")?></th>
<?php endif; ?>
				</tr>
			</thead>
			<tbody>
<?php foreach ($entries as list($line, $is_expired)):
	$cols = ($view === 'speed') ? [3, 5, 7, 9, 11, 13, 15, 17] : [19, 21, 23, 26, 28, 30, 32];
?>
				<tr data-fs-filter-state="<?=$is_expired ? 'expired' : 'valid'?>">
					<td><?=$is_expired ? fs_badge('expired') : fs_badge('active', gettext('Cached'))?></td>
					<td class="fs-mono"><?=htmlspecialchars($line[0] ?? '')?></td>
					<td class="fs-mono"><?=htmlspecialchars($line[1] ?? '')?></td>
<?php if ($is_expired):
	/* an expired entry only carries its RTO */
	foreach ($cols as $idx):
		$value = (($view === 'speed') && ($idx == 11)) ? ($line[5] ?? '') : '';
?>
					<td class="fs-mono fs-unbound-num<?=($value === '') ? ' fs-muted' : ''?>"><?=($value === '') ? '–' : htmlspecialchars($value)?></td>
<?php endforeach;
else:
	foreach ($cols as $idx): ?>
					<td class="fs-mono fs-unbound-num"><?=htmlspecialchars($line[$idx] ?? '')?></td>
<?php endforeach;
endif; ?>
				</tr>
<?php endforeach; ?>
<?php if (empty($entries)) {
	fs_empty_row(($view === 'speed') ? 11 : 10, $running ? gettext('The infrastructure cache is empty.') : gettext('No data while the DNS Resolver is stopped.'));
} ?>
			</tbody>
		</table>
	</div>
	<div class="panel-footer small fs-muted">
		<?=gettext('TTL is in seconds, all other times in milliseconds. RTO is the retransmit timeout the resolver uses for the server.')?>
	</div>
</div>

<?php
include("foot.inc");
