<?php
/*
 * diag_sockets.php
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
##|*IDENT=page-diagnostics-sockets
##|*NAME=Diagnostics: Sockets
##|*DESCR=Allow access to the 'Diagnostics: Sockets' page.
##|*MATCH=diag_sockets.php*
##|-PRIV

require_once('guiconfig.inc');

$pgtitle = array(gettext("Diagnostics"), gettext("Sockets"));

$showAll = isset($_REQUEST['showAll']);
$view = fs_view_param(['ipv4', 'ipv6'], 'ipv4');

/* sockstat: -4/-6 family, -l listening only, -w full-width addresses */
$output = shell_exec('/usr/bin/sockstat ' . (($view === 'ipv6') ? '-6' : '-4') . ($showAll ? '' : 'l') . 'w') ?? '';

$sockets = array();
$protos = array();
foreach (explode("\n", $output) as $i => $line) {
	if ($i == 0 || trim($line) == '') {
		continue;
	}
	$f = preg_split('/\s+/', trim($line));
	if (count($f) < 7) {
		continue;
	}
	$sockets[] = array(
		'user' => $f[0],
		'command' => $f[1],
		'pid' => $f[2],
		'fd' => $f[3],
		'proto' => $f[4],
		'local' => $f[5],
		'foreign' => $f[6],
		'extra' => implode(' ', array_slice($f, 7)),
	);
	$protos[$f[4]] = ($protos[$f[4]] ?? 0) + 1;
}
ksort($protos);

$tcp = $udp = 0;
foreach ($protos as $proto => $n) {
	if (strpos($proto, 'tcp') === 0) {
		$tcp += $n;
	} elseif (strpos($proto, 'udp') === 0) {
		$udp += $n;
	}
}

include('head.inc');

fs_view_switch(['ipv4' => gettext('IPv4'), 'ipv6' => gettext('IPv6')], $view);

$toggle_query = array('view' => $view);
if (!$showAll) {
	$toggle_query['showAll'] = '';
}
$toggle = '<a class="btn btn-sm btn-outline-secondary" href="diag_sockets.php?' . fs_h(http_build_query($toggle_query)) . '">'
    . '<i class="fa-solid ' . ($showAll ? 'fa-ear-listen' : 'fa-list') . ' icon-embed-btn" aria-hidden="true"></i>'
    . fs_h($showAll ? gettext('Show only listening sockets') : gettext('Show all socket connections')) . '</a>';
?>

<div class="fs-tiles">
<?php
fs_tile($showAll ? gettext('Sockets') : gettext('Listening sockets'), count($sockets));
fs_tile(gettext('TCP'), $tcp);
fs_tile(gettext('UDP'), $udp);
?>
</div>

<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => $showAll ? gettext('All sockets') : gettext('Listening sockets'),
	'search' => gettext('Search command, address, port…'),
	'noun' => gettext('sockets'),
	'noun_one' => gettext('socket'),
	'filters' => ['proto' => [gettext('All protocols')] + array_combine(array_keys($protos), array_keys($protos))],
	'actions' => $toggle,
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover" data-sortable>
			<thead>
				<tr>
					<th data-fs-search><?=gettext('Command')?></th>
					<th data-fs-search><?=gettext('User')?></th>
					<th data-fs-search><?=gettext('PID')?></th>
					<th><?=gettext('FD')?></th>
					<th data-fs-search><?=gettext('Protocol')?></th>
					<th data-fs-search><?=gettext('Local address')?></th>
					<th data-fs-search><?=gettext('Foreign address')?></th>
				</tr>
			</thead>
			<tbody>
<?php foreach ($sockets as $s): ?>
				<tr data-fs-filter-proto="<?=htmlspecialchars($s['proto'])?>">
					<td><strong><?=htmlspecialchars($s['command'])?></strong></td>
					<td><?=htmlspecialchars($s['user'])?></td>
					<td class="fs-mono"><?=htmlspecialchars($s['pid'])?></td>
					<td class="fs-mono fs-muted"><?=htmlspecialchars($s['fd'])?></td>
					<td class="fs-mono"><?=htmlspecialchars($s['proto'])?></td>
					<td class="fs-mono"><?=htmlspecialchars($s['local'])?></td>
					<td class="fs-mono<?=($s['foreign'] === '*:*') ? ' fs-muted' : ''?>"><?=htmlspecialchars($s['foreign'])?><?php if ($s['extra'] !== ''): ?> <span class="fs-muted small"><?=htmlspecialchars($s['extra'])?></span><?php endif; ?></td>
				</tr>
<?php endforeach; ?>
<?php if (empty($sockets)) {
	fs_empty_row(7, gettext('No sockets were found.'));
} ?>
			</tbody>
		</table>
	</div>
	<div class="panel-footer small fs-muted">
		<i class="fa-solid fa-circle-info" aria-hidden="true"></i>
		<?=gettext('By default only listening sockets are shown. "Show all socket connections" adds outbound and established connections. A foreign address of *:* means the socket is not connected.')?>
	</div>
</div>

<?php
include('foot.inc');
