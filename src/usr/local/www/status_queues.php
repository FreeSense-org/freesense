<?php
/*
 * status_queues.php
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
##|*IDENT=page-status-trafficshaper-queues
##|*NAME=Status: Traffic Shaper: Queues
##|*DESCR=Allow access to the 'Status: Traffic Shaper: Queues' page.
##|*MATCH=status_queues.php*
##|-PRIV

require_once("guiconfig.inc");
include_once("shaper.inc");

$stats = get_queue_stats();

$pgtitle = array(gettext("Status"), gettext("Queues"));
$shortcut_section = "trafficshaper";

if (isAllowedPage('firewall_shaper.php')) {
	fs_page_action(gettext('Traffic shaper'), 'firewall_shaper.php', 'fa-sliders', 'secondary');
}

include("head.inc");

if (count(config_get_path('shaper/queue', [])) < 1) {
	$can_shape = isAllowedPage('firewall_shaper.php');
?>
<style>
.fs-queue-empty .fs-tool-empty { text-align: center; }
.fs-queue-empty .fs-tool-empty p { max-width: 32rem; margin: 0; }
.fs-queue-empty-actions { display: flex; flex-wrap: wrap; justify-content: center; gap: var(--fs-sp-2); margin-top: var(--fs-sp-2); }
</style>
<div class="panel panel-default fs-queue-empty">
	<div class="fs-tool-empty">
		<i class="fa-solid fa-layer-group" aria-hidden="true"></i>
		<strong><?=gettext("Traffic shaping is not configured.")?></strong>
		<p><?=gettext('This page shows the live traffic of each shaper queue once queues exist on an interface.')?></p>
<?php if ($can_shape): ?>
		<div class="fs-queue-empty-actions">
			<a class="btn btn-primary" href="firewall_shaper_wizards.php"><i class="fa-solid fa-wand-magic-sparkles icon-embed-btn" aria-hidden="true"></i><?=gettext('Run a wizard')?></a>
			<a class="btn btn-outline-secondary" href="firewall_shaper.php"><i class="fa-solid fa-sliders icon-embed-btn" aria-hidden="true"></i><?=gettext('Configure the shaper')?></a>
		</div>
<?php endif; ?>
	</div>
</div>
<?php
	include("foot.inc");
	exit;
}

/*
 * One row per queue, in pfctl order (parents before children). The level and
 * the ancestor keys drive the indentation and the collapse toggles; the key
 * (queue name + real interface) matches the live stats from getqueuestats.php.
 */
$if_queue_list = get_configured_interface_list_by_realif(true);
$groups = [];
$nqueues = 0;
foreach ((is_array($stats['interfacestats'] ?? null) ? $stats['interfacestats'] : []) as $if => $ifq) {
	$if_name = $if_queue_list[$if] ?? '';
	$parents = [];
	$rows = [];
	foreach ($ifq as $qkey => $q) {
		if (isset($q['contains'])) {
			foreach ($q['contains'] as $child) {
				$parents[$child] = $qkey;
			}
		}
		$ancestors = [];
		$find = $qkey;
		while (isset($parents[$find])) {
			$find = $parents[$find];
			$ancestors[] = $find . $q['interface'];
		}
		$qfinterface = convert_real_interface_to_friendly_interface_name($q['interface']);
		$qname = str_replace($q['interface'], $qfinterface, $q['name']);
		$is_root = (strstr($qname, "root_") !== false);
		$rows[] = [
			'key' => $q['name'] . $q['interface'],
			'label' => $is_root ? gettext('Root queue') : $qname,
			'href' => "firewall_shaper.php?interface={$if_name}&queue=" . ($is_root ? $if_name : $qname) . "&action=show",
			'level' => min(count($ancestors), 6),
			'ancestors' => $ancestors,
			'parent' => !empty($q['contains']),
		];
		$nqueues++;
	}
	$groups[$if] = $rows;
}
?>

<style>
.fs-queue-tools { display: inline-flex; flex-wrap: wrap; align-items: center; gap: .5rem; }
.fs-queue-tools label { margin: 0; color: var(--fs-text-muted); font-size: var(--fs-fs-sm); white-space: nowrap; }
.fs-queue-tools .form-select { width: auto; }
tr.fs-queue-if > td { background: var(--fs-surface-raised); color: var(--fs-text-strong); font-weight: 600; }
.fs-queue-name { white-space: nowrap; }
#queue-status td.fs-queue-name[data-level="1"] { padding-left: 2rem; }
#queue-status td.fs-queue-name[data-level="2"] { padding-left: 3.25rem; }
#queue-status td.fs-queue-name[data-level="3"] { padding-left: 4.5rem; }
#queue-status td.fs-queue-name[data-level="4"] { padding-left: 5.75rem; }
#queue-status td.fs-queue-name[data-level="5"] { padding-left: 7rem; }
#queue-status td.fs-queue-name[data-level="6"] { padding-left: 8.25rem; }
.fs-queue-tree { width: 1.5rem; padding: 0; border: 0; background: none; color: var(--fs-text-muted); }
.fs-queue-tree i { transition: transform var(--fs-t-fast) var(--fs-ease); transform: rotate(90deg); }
.fs-queue-tree[aria-expanded="false"] i { transform: none; }
.fs-queue-leaf { display: inline-block; width: 1.5rem; }
.fs-queue-bar { width: 10rem; height: .5rem; border-radius: 999px; background: var(--fs-surface-raised); overflow: hidden; }
.fs-queue-bar > span { display: block; width: 0; height: 100%; border-radius: 999px; background: var(--fs-coral); transition: width var(--fs-t) var(--fs-ease); }
.fs-queue-num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
</style>

<div class="fs-tiles">
<?php
fs_tile(gettext('Interfaces'), count($groups));
fs_tile(gettext('Queues'), $nqueues);
?>
	<div class="fs-tile"><div class="fs-tile-label"><?=gettext('Total bandwidth')?></div><div class="fs-tile-value" data-queue-total="bps">–</div><div class="fs-tile-hint"><?=gettext('Root queues')?></div></div>
	<div class="fs-tile"><div class="fs-tile-label"><?=gettext('Drops')?></div><div class="fs-tile-value" data-queue-total="drops">–</div><div class="fs-tile-hint"><?=gettext('Root queues')?></div></div>
</div>

<div class="panel panel-default fs-table" id="queue-status">
<?php fs_table_toolbar([
	'title' => gettext('Queues'),
	'search' => false,
	'noun' => gettext('queues'),
	'noun_one' => gettext('queue'),
	'custom' => '<span class="fs-queue-tools">'
	    . '<label for="selStatistic">' . fs_h(gettext('Bar shows')) . '</label>'
	    . '<select id="selStatistic" class="form-select form-select-sm"><option value="0">' . fs_h(gettext('Packets/s')) . '</option><option value="1">' . fs_h(gettext('Bandwidth')) . '</option></select>'
	    . '<label for="updatespeed">' . fs_h(gettext('Refresh')) . '</label>'
	    . '<select id="updatespeed" class="form-select form-select-sm">'
	    . '<option value="500">' . fs_h(gettext('0.5 s')) . '</option><option value="1000" selected>' . fs_h(gettext('1 s')) . '</option>'
	    . '<option value="2000">' . fs_h(gettext('2 s')) . '</option><option value="5000">' . fs_h(gettext('5 s')) . '</option></select>'
	    . '</span>',
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover">
			<thead>
				<tr>
					<th><?=gettext("Queue")?></th>
					<th><?=gettext("Activity")?></th>
					<th class="fs-queue-num"><?=gettext("PPS")?></th>
					<th class="fs-queue-num"><?=gettext("Bandwidth")?></th>
					<th class="fs-queue-num"><?=gettext("Borrows")?></th>
					<th class="fs-queue-num"><?=gettext("Suspends")?></th>
					<th class="fs-queue-num"><?=gettext("Drops")?></th>
					<th class="fs-queue-num"><?=gettext("Length")?></th>
				</tr>
			</thead>
			<tbody>
<?php foreach ($groups as $if => $rows): ?>
				<tr class="fs-queue-if" data-fs-static><td colspan="8"><?=htmlspecialchars(sprintf(gettext('Interface %s'), convert_real_interface_to_friendly_descr($if)))?></td></tr>
<?php foreach ($rows as $r): ?>
				<tr data-queue="<?=htmlspecialchars($r['key'])?>" data-ancestors="<?=htmlspecialchars(implode(' ', $r['ancestors']))?>"<?=$r['level'] ? '' : ' data-queue-root'?>>
					<td class="fs-queue-name" data-level="<?=$r['level']?>">
<?php if ($r['parent']): ?>
						<button type="button" class="fs-queue-tree" aria-expanded="true" aria-label="<?=htmlspecialchars(sprintf(gettext('Show or hide the queues under %s'), $r['label']))?>"><i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button>
<?php else: ?>
						<span class="fs-queue-leaf"></span>
<?php endif; ?>
						<a href="<?=htmlspecialchars($r['href'])?>"><?=htmlspecialchars($r['label'])?></a>
					</td>
					<td><div class="fs-queue-bar" role="presentation"><span data-stat="bar"></span></div></td>
					<td class="fs-mono fs-queue-num" data-stat="pps"><span class="fs-muted"><?=gettext('Loading')?></span></td>
					<td class="fs-mono fs-queue-num" data-stat="bps"></td>
					<td class="fs-mono fs-queue-num" data-stat="borrows"></td>
					<td class="fs-mono fs-queue-num" data-stat="suspends"></td>
					<td class="fs-mono fs-queue-num" data-stat="drops"></td>
					<td class="fs-mono fs-queue-num" data-stat="length"></td>
				</tr>
<?php endforeach; ?>
<?php endforeach; ?>
<?php if (empty($groups)) {
	fs_empty_row(8, gettext('No queue data available.'));
} ?>
			</tbody>
		</table>
	</div>
	<div class="panel-footer small fs-muted">
		<?=gettext("Queue graphs sample data on a regular interval.")?>
	</div>
</div>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	var refreshrate = 1000;
	var previous = {};
	var timestampprevious;
	var graphstatmax = 0;
	var rows = {};

	document.querySelectorAll('#queue-status tr[data-queue]').forEach(function (tr) {
		rows[tr.getAttribute('data-queue')] = tr;
	});

	$('#updatespeed').on('change', function() {
		refreshrate = parseInt(this.value, 10) || 1000;
	});

	/* collapse / expand the queues under a parent */
	document.getElementById('queue-status').addEventListener('click', function (e) {
		var btn = e.target.closest('.fs-queue-tree');
		if (!btn) {
			return;
		}
		var tr = btn.closest('tr');
		var key = tr.getAttribute('data-queue');
		var open = btn.getAttribute('aria-expanded') !== 'true';
		btn.setAttribute('aria-expanded', open ? 'true' : 'false');
		document.querySelectorAll('#queue-status tr[data-ancestors]').forEach(function (row) {
			var anc = row.getAttribute('data-ancestors').split(' ');
			if (anc.indexOf(key) === -1) {
				return;
			}
			/* reopening shows a row only if no other ancestor is still collapsed */
			row.hidden = !open || anc.some(function (a) {
				var b = rows[a] ? rows[a].querySelector('.fs-queue-tree') : null;
				return b && b.getAttribute('aria-expanded') === 'false';
			});
		});
	});

	function formatSpeedBits(speed) {
		// format speed in bits/sec, input: bytes/sec
		if (speed < 125000) {
			return Math.round(speed / 125) + " <?=gettext("Kbps"); ?>";
		}
		if (speed < 125000000) {
			return Math.round(speed / 1250)/100 + " <?=gettext("Mbps"); ?>";
		}
		return Math.round(speed / 1250000)/100 + " <?=gettext("Gbps"); ?>";
	}

	function set(tr, stat, text) {
		var el = tr.querySelector('[data-stat="' + stat + '"]');
		if (el) {
			el.textContent = text;
		}
	}

	function getqueueactivity() {
		$.ajax('/getqueuestats.php', {type: 'post', data: 'format=json', complete: activitycallback});
	}

	function activitycallback(transport) {
		setTimeout(getqueueactivity, refreshrate);
		var json = transport.responseJSON;
		if (!json || !json.interfacestats) {
			return;
		}
		var timestamp = json.timestamp;
		var timestampdiff = timestamp - timestampprevious;
		var bytesmode = $('#selStatistic').val() !== '0';
		var stats = {};

		Object.keys(json.interfacestats).forEach(function (ifname) {
			var ifq = json.interfacestats[ifname];
			var queueparents = {};
			stats[ifname] = {};
			Object.keys(ifq).forEach(function (queuename) {
				var queue = ifq[queuename];
				var key = queue.name + queue.interface;
				(queue.contains || []).forEach(function (child) {
					queueparents[child] = queuename;
				});
				if (previous[key]) {
					var s = {
						pkts_ps: (queue.pkts - previous[key].pkts) / timestampdiff,
						bytes_ps: (queue.bytes - previous[key].bytes) / timestampdiff,
						borrows: parseFloat(queue.borrows),
						suspends: parseFloat(queue.suspends),
						droppedpkts: parseFloat(queue.droppedpkts),
						qlengthitems: queue.qlengthitems,
						qlengthsize: queue.qlengthsize
					};
					stats[ifname][key] = s;
					// add diff values also to parent queues
					var find = queuename;
					while (queueparents[find]) {
						var parentname = queueparents[find];
						var p = stats[ifname][parentname + ifname];
						if (p) {
							if (parentname.indexOf('root_') !== 0) {
								p.pkts_ps += s.pkts_ps;
								p.bytes_ps += s.bytes_ps;
							}
							p.borrows += s.borrows;
							p.suspends += s.suspends;
							p.droppedpkts += s.droppedpkts;
						}
						find = parentname;
					}
				}
				previous[key] = queue;
			});
		});

		// a slowly sliding scale that always fits the largest value
		var statmax = 0;
		Object.keys(stats).forEach(function (ifname) {
			Object.keys(stats[ifname]).forEach(function (key) {
				var v = bytesmode ? stats[ifname][key].bytes_ps : stats[ifname][key].pkts_ps;
				statmax = Math.max(statmax, v);
			});
		});
		graphstatmax = (graphstatmax < statmax) ? statmax * 1.1 : (graphstatmax * 20 + statmax * 1.5) / 21;

		var totalbps = 0;
		var totaldrops = 0;
		var haveroot = false;
		Object.keys(stats).forEach(function (ifname) {
			Object.keys(stats[ifname]).forEach(function (key) {
				var q = stats[ifname][key];
				var tr = rows[key];
				if (!tr) {
					return;
				}
				var v = bytesmode ? q.bytes_ps : q.pkts_ps;
				var bar = tr.querySelector('[data-stat="bar"]');
				if (bar) {
					bar.style.width = (graphstatmax > 0 ? Math.min(100, v * 100 / graphstatmax) : 0).toFixed(0) + '%';
				}
				set(tr, 'pps', q.pkts_ps.toFixed(1));
				set(tr, 'bps', formatSpeedBits(q.bytes_ps));
				set(tr, 'borrows', String(q.borrows));
				set(tr, 'suspends', String(q.suspends));
				set(tr, 'drops', String(q.droppedpkts));
				set(tr, 'length', q.qlengthitems + '/' + q.qlengthsize);
				if (tr.hasAttribute('data-queue-root')) {
					haveroot = true;
					totalbps += q.bytes_ps;
					totaldrops += q.droppedpkts;
				}
			});
		});
		if (haveroot) {
			document.querySelector('[data-queue-total="bps"]').textContent = formatSpeedBits(totalbps);
			document.querySelector('[data-queue-total="drops"]').textContent = String(totaldrops);
		}
		timestampprevious = timestamp;
	}

	setTimeout(getqueueactivity, 150);
});
//]]>
</script>

<?php
include("foot.inc");
