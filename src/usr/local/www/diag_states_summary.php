<?php
/*
 * diag_states_summary.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
 * Copyright (c) 2005 Colin Smith
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
##|*IDENT=page-diagnostics-statessummary
##|*NAME=Diagnostics: States Summary
##|*DESCR=Allow access to the 'Diagnostics: States Summary' page.
##|*MATCH=diag_states_summary.php*
##|-PRIV

/* authenticate before reading the state table */
require_once("guiconfig.inc");

exec("/sbin/pfctl -s state", $states);

$srcipinfo = array();
$dstipinfo = array();
$allipinfo = array();
$pairipinfo = array();

function addipinfo(&$iparr, $ip, $proto, $srcport, $dstport) {
	$iparr[$ip]['seen']++;
	$iparr[$ip]['protos'][$proto]['seen']++;
	if (!empty($srcport)) {
		$iparr[$ip]['protos'][$proto]['srcports'][$srcport]++;
	}
	if (!empty($dstport)) {
		$iparr[$ip]['protos'][$proto]['dstports'][$dstport]++;
	}
}

$row = 0;
if (count($states) > 0) {
	foreach ($states as $line) {
		$line_split = preg_split("/\s+/", $line);
		$iface = array_shift($line_split);
		$proto = array_shift($line_split);
		$state = array_pop($line_split);
		$info = implode(" ", $line_split);

		/* Handle NAT cases
		   Replaces an external IP + NAT by the internal IP */
		if (strpos($info, ') ->') !== FALSE) {
			/* Outbound NAT */
			$info = preg_replace('/(\S+) \((\S+)\)/U', "$2", $info);
		} elseif (strpos($info, ') <-') !== FALSE) {
			/* Inbound NAT/Port Forward */
			$info = preg_replace('/(\S+) \((\S+)\)/U', "$1", $info);
		}

		/* break up info and extract $srcip and $dstip */
		$ends = preg_split("/\<?-\>?/", $info);

		if (strpos($info, '->') === FALSE) {
			$srcinfo = $ends[count($ends) - 1];
			$dstinfo = $ends[0];
		} else {
			$srcinfo = $ends[0];
			$dstinfo = $ends[count($ends) - 1];
		}

		/* Handle IPv6 */
		$parts = explode(":", $srcinfo);
		$partcount = count($parts);
		if ($partcount <= 2) {
			$srcip = trim($parts[0]);
			$srcport = trim($parts[1]);
		} else {
			preg_match("/([0-9a-f:]+)(\[([0-9]+)\])?/i", $srcinfo, $matches);
			$srcip = $matches[1];
			$srcport = trim($matches[3]);
		}

		$parts = explode(":", $dstinfo);
		$partcount = count($parts);
		if ($partcount <= 2) {
			$dstip = trim($parts[0]);
			$dstport = trim($parts[1]);
		} else {
			preg_match("/([0-9a-f:]+)(\[([0-9]+)\])?/i", $dstinfo, $matches);
			$dstip = $matches[1];
			$dstport = trim($matches[3]);
		}

		addipinfo($srcipinfo, $srcip, $proto, $srcport, $dstport);
		addipinfo($dstipinfo, $dstip, $proto, $srcport, $dstport);
		addipinfo($pairipinfo, "{$srcip} -> {$dstip}", $proto, $srcport, $dstport);

		addipinfo($allipinfo, $srcip, $proto, $srcport, $dstport);
		addipinfo($allipinfo, $dstip, $proto, $srcport, $dstport);
	}
}

function sort_by_ip($a, $b) {
	return ip2ulong($a) < ip2ulong($b) ? -1 : 1;
}

function build_port_info($portarr, $proto) {
	if (!$portarr) {
		return '';
	}
	$ports = array();
	asort($portarr);
	foreach (array_reverse($portarr, TRUE) as $port => $count) {
		$service = getservbyport($port, strtolower($proto));
		$port = "{$proto}/{$port}";
		if ($service) {
			$port = "{$port} ({$service})";
		}
		$ports[] = "{$port}: {$count}";
	}
	return implode(', ', $ports);
}

$pgtitle = array(gettext("Diagnostics"), gettext("States Summary"));

$views = array(
	'source' => gettext('By source IP'),
	'destination' => gettext('By destination IP'),
	'total' => gettext('Total per IP'),
	'pair' => gettext('By IP pair'),
);
$view = fs_view_param(array_keys($views), 'source');
$data = array(
	'source' => $srcipinfo,
	'destination' => $dstipinfo,
	'total' => $allipinfo,
	'pair' => $pairipinfo,
);
$iparr = $data[$view];
if ($view !== 'pair') {
	uksort($iparr, "sort_by_ip");
}

include("head.inc");
?>

<style>
.fs-ss-protos { display: flex; flex-direction: column; gap: 2px; }
.fs-ss-proto { display: flex; gap: var(--fs-sp-2); white-space: nowrap; }
.fs-ss-proto > b { min-width: 3.5rem; font-weight: 600; }
.fs-ss-ports { cursor: help; text-decoration: underline dotted var(--fs-text-muted); text-underline-offset: 3px; }
</style>

<div class="fs-tiles">
<?php
fs_tile(gettext('States'), count($states));
fs_tile(gettext('Source IPs'), count($srcipinfo));
fs_tile(gettext('Destination IPs'), count($dstipinfo));
fs_tile(gettext('IP pairs'), count($pairipinfo));
?>
</div>

<?php fs_view_switch($views, $view); ?>

<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => $views[$view],
	'search' => gettext('Search IP addresses, protocols…'),
	'noun' => ($view === 'pair') ? gettext('pairs') : gettext('addresses'),
	'noun_one' => ($view === 'pair') ? gettext('pair') : gettext('address'),
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover" data-sortable>
			<thead>
				<tr>
					<th data-fs-search><?=($view === 'pair') ? gettext('Source → destination') : gettext('IP address')?></th>
					<th><?=gettext('States')?></th>
					<th data-fs-search><?=gettext('Protocols')?></th>
					<th data-sortable="false"><?=gettext('Source ports')?></th>
					<th data-sortable="false"><?=gettext('Destination ports')?></th>
				</tr>
			</thead>
			<tbody>
<?php foreach ($iparr as $ip => $ipinfo): ?>
				<tr>
					<td class="fs-mono"><?=htmlspecialchars(str_replace(' -> ', ' → ', $ip))?></td>
					<td class="fs-mono"><?=(int)$ipinfo['seen']?></td>
					<td><div class="fs-ss-protos">
<?php foreach ($ipinfo['protos'] as $proto => $protoinfo): ?>
						<span class="fs-ss-proto"><b><?=htmlspecialchars($proto)?></b><span class="fs-mono"><?=(int)$protoinfo['seen']?></span></span>
<?php endforeach; ?>
					</div></td>
<?php foreach (['srcports', 'dstports'] as $key): ?>
					<td><div class="fs-ss-protos">
<?php foreach ($ipinfo['protos'] as $proto => $protoinfo):
	$cnt = is_array($protoinfo[$key]) ? count($protoinfo[$key]) : 0;
	$title = build_port_info($protoinfo[$key], $proto);
?>
						<span class="fs-ss-proto fs-mono"><?php if ($cnt): ?><span class="fs-ss-ports" title="<?=htmlspecialchars($title)?>"><?=$cnt?></span><?php else: ?><span class="fs-muted">0</span><?php endif; ?></span>
<?php endforeach; ?>
					</div></td>
<?php endforeach; ?>
				</tr>
<?php endforeach; ?>
<?php if (empty($iparr)) {
	fs_empty_row(5, gettext('There are no states.'));
} ?>
			</tbody>
		</table>
	</div>
	<div class="panel-footer small fs-muted">
		<i class="fa-solid fa-circle-info" aria-hidden="true"></i>
		<?=gettext('Port columns count the distinct ports per protocol. Point at a number to see the ports and how many states use each. NAT states are counted by their internal address.')?>
	</div>
</div>

<?php
include("foot.inc");
