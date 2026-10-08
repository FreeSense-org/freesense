<?php
/*
 * diag_system_activity.php
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
##|*IDENT=page-diagnostics-system-activity
##|*NAME=Diagnostics: System Activity
##|*DESCR=Allows access to the 'Diagnostics: System Activity' page
##|*MATCH=diag_system_activity.php*
##|-PRIV

require_once('guiconfig.inc');

if ($_REQUEST['getactivity']) {
	header('Content-Type: text/plain; charset=UTF-8');
	exec('/usr/bin/top -baHS 999 2>/dev/null', $output, $rc);
	echo (($rc === 0) ? implode(PHP_EOL, $output) : sprintf(gettext('Unable to gather system activity (%d)'), $rc));
	exit;
}


$pgtitle = [gettext('Diagnostics'), gettext('System Activity')];

include('head.inc');

if ($input_errors) {
	print_input_errors($input_errors);
}
?>

<style>
.fs-activity-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--fs-sp-2); margin-bottom: var(--fs-sp-3); }
.fs-activity-updated { color: var(--fs-text-muted); font-size: var(--fs-fs-sm); }
#activity-table .fs-activity-num { text-align: right; }
#activity-table th { white-space: nowrap; }
#activity-table td.fs-activity-cmd { white-space: normal; word-break: break-word; }
.fs-activity-raw > summary { padding: var(--fs-sp-3) var(--fs-sp-4); color: var(--fs-text-muted); cursor: pointer; border-top: 1px solid var(--fs-border); }
.fs-activity-raw-tools { display: flex; justify-content: flex-end; padding: 0 var(--fs-sp-4) var(--fs-sp-2); }
</style>

<div class="fs-tiles" id="activity-tiles">
<?php
fs_tile(gettext('Load average'), '…', null, gettext('1, 5 and 15 minutes'));
fs_tile(gettext('CPU in use'), '…');
fs_tile(gettext('Free memory'), '…');
fs_tile(gettext('Threads'), '…');
?>
</div>

<div class="fs-activity-bar">
	<span class="fs-activity-updated" id="activity-updated" aria-live="polite"><?=gettext('Gathering system activity…')?></span>
	<div class="form-check form-switch mb-0">
		<input class="form-check-input" type="checkbox" role="switch" id="refresh" checked>
		<label class="form-check-label" for="refresh"><?=gettext('Refresh automatically')?></label>
	</div>
</div>

<div class="panel panel-default fs-table" id="activity-card">
<?php fs_table_toolbar([
	'search' => gettext('Search command, user, state…'),
	'noun' => gettext('threads'),
	'noun_one' => gettext('thread'),
	'filters' => [
		'busy' => [gettext('All threads'), 'yes' => gettext('Using CPU'), 'no' => gettext('Idle')],
	],
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover table-sm" id="activity-table">
			<thead>
				<tr>
					<th class="fs-activity-num" data-fs-search><?=gettext('PID')?></th>
					<th data-fs-search><?=gettext('User')?></th>
					<th class="fs-activity-num"><?=gettext('Priority')?></th>
					<th class="fs-activity-num"><?=gettext('Nice')?></th>
					<th class="fs-activity-num"><?=gettext('Size')?></th>
					<th class="fs-activity-num"><?=gettext('Resident')?></th>
					<th data-fs-search><?=gettext('State')?></th>
					<th class="fs-activity-num"><?=gettext('CPU time')?></th>
					<th class="fs-activity-num"><?=gettext('CPU %')?></th>
					<th data-fs-search><?=gettext('Command')?></th>
				</tr>
			</thead>
			<tbody>
				<tr class="fs-empty"><td colspan="10"><span class="fs-empty-message"><?=gettext('Gathering system activity…')?></span></td></tr>
			</tbody>
		</table>
	</div>
	<details class="fs-activity-raw">
		<summary><?=gettext('Raw output of top')?></summary>
		<div class="fs-activity-raw-tools">
			<button type="button" class="btn btn-sm btn-outline-secondary" data-fs-copy="#xhrOutput">
				<i class="fa-regular fa-copy icon-embed-btn" aria-hidden="true"></i><?=gettext('Copy')?>
			</button>
		</div>
		<pre class="fs-console" id="xhrOutput"><?=gettext('Gathering CPU activity, please wait...')?></pre>
	</details>
	<div class="panel-footer small fs-muted">
		<i class="fa-solid fa-circle-info" aria-hidden="true"></i>
		<?=gettext('Every thread of every process, busiest first. CPU % is the weighted CPU share of the thread; kernel threads are shown in braces.')?>
	</div>
</div>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	var busy = false;
	var text = {
		updated: <?=json_encode(gettext('Updated %s'))?>,
		paused: <?=json_encode(gettext('Paused'))?>,
		failed: <?=json_encode(gettext('Could not read the system activity.'))?>,
		total: <?=json_encode(gettext('of %s'))?>
	};
	var numeric = [0, 2, 3, 4, 5, 7, 8];

	function setTile(i, value, hint) {
		var tile = document.querySelectorAll('#activity-tiles .fs-tile')[i];
		if (!tile) {
			return;
		}
		tile.querySelector('.fs-tile-value').textContent = value;
		if (hint === undefined) {
			return;
		}
		var h = tile.querySelector('.fs-tile-hint');
		if (!h) {
			h = document.createElement('div');
			h.className = 'fs-tile-hint';
			tile.appendChild(h);
		}
		h.textContent = hint;
	}

	// Summary lines of top -b: load, threads, CPU and memory
	function summary(lines) {
		var units = {K: 1 / 1024, M: 1, G: 1024, T: 1048576};
		lines.forEach(function (l) {
			var m;
			if ((m = l.match(/load averages?:\s*([\d.]+),?\s+([\d.]+),?\s+([\d.]+)/))) {
				setTile(0, m[1] + '  ' + m[2] + '  ' + m[3]);
			} else if ((m = l.match(/^\s*(\d+)\s+threads?:\s*(.*)$/))) {
				setTile(3, m[1], m[2].replace(/\s+/g, ' '));
			} else if ((m = l.match(/^CPU:.*?([\d.]+)% idle/))) {
				setTile(1, (100 - parseFloat(m[1])).toFixed(1) + ' %', l.replace(/^CPU:\s*/, '').replace(/\s+/g, ' '));
			} else if ((m = l.match(/^Mem:\s*(.*)$/))) {
				var free = null;
				var total = 0;
				var size = function (mb) {
					return (mb >= 1024) ? (mb / 1024).toFixed(1) + 'G' : Math.round(mb) + 'M';
				};
				m[1].split(/,\s*/).forEach(function (p) {
					var pm = p.match(/^([\d.]+)([KMGT])\s+(\w+)/);
					if (!pm) {
						return;
					}
					var mb = parseFloat(pm[1]) * units[pm[2]];
					total += mb;
					if (pm[3] === 'Free') {
						free = mb;
					}
				});
				setTile(2, (free === null) ? '–' : size(free), text.total.replace('%s', size(total)));
			}
		});
	}

	function render(output) {
		var lines = output.split('\n');
		var head = -1;
		for (var i = 0; i < lines.length; i++) {
			if (/^\s*PID\s+USERNAME/.test(lines[i])) {
				head = i;
				break;
			}
		}
		summary((head > 0) ? lines.slice(0, head) : lines);
		if (head < 0) {
			return;
		}

		// Columns of top -aHS: PID USERNAME PRI NICE SIZE RES STATE [C] TIME WCPU COMMAND
		var cols = lines[head].trim().split(/\s+/);
		var cpuCol = cols.indexOf('C');
		var n = cols.length;
		var frag = document.createDocumentFragment();

		lines.slice(head + 1).forEach(function (l) {
			var f = l.trim().split(/\s+/);
			if (!l.trim() || f.length < n) {
				return;
			}
			var cmd = f.slice(n - 1).join(' ');
			f = f.slice(0, n - 1);
			if (cpuCol !== -1) {
				f.splice(cpuCol, 1);
			}
			var cells = f.slice(0, 9).concat([cmd]);
			var tr = document.createElement('tr');
			tr.setAttribute('data-fs-filter-busy', (parseFloat(f[8]) > 0) ? 'yes' : 'no');
			cells.forEach(function (v, idx) {
				var td = document.createElement('td');
				td.textContent = v;
				if (numeric.indexOf(idx) !== -1) {
					td.className = 'fs-mono fs-activity-num';
				} else if (idx === 9) {
					td.className = 'fs-mono fs-activity-cmd';
				} else if (idx === 6) {
					td.className = 'fs-mono';
				}
				tr.appendChild(td);
			});
			frag.appendChild(tr);
		});

		document.querySelector('#activity-table tbody').replaceChildren(frag);
		var root = document.getElementById('activity-card');
		if (root && root._fsTable) {
			root._fsTable.apply(false);
		}
	}

	function getcpuactivity() {
		if (busy || document.hidden || !document.getElementById('refresh').checked) {
			return;
		}
		busy = true;

		$.ajax({
			url: '/diag_system_activity.php',
			type: 'post',
			data: {getactivity: 'yes'},
			dataType: 'text'
		}).done(function (response) {
			$('#xhrOutput').text(response);
			render(response);
			$('#activity-updated').text(text.updated.replace('%s', new Date().toLocaleTimeString()));
		}).fail(function () {
			$('#activity-updated').text(text.failed);
		}).always(function () {
			busy = false;
		});
	}

	$('#refresh').on('change', function () {
		if (this.checked) {
			getcpuactivity();
		} else {
			$('#activity-updated').text(text.paused);
		}
	});

	getcpuactivity();
	setInterval(getcpuactivity, 2500);
});
//]]>
</script>

<?php
include('foot.inc');
