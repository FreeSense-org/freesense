<?php
/*
 * status_graph_cpu.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
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
##|*IDENT=page-status-cpuload
##|*NAME=Status: CPU load
##|*DESCR=Allow access to the 'Status: CPU load' page.
##|*MATCH=status_graph_cpu.php*
##|-PRIV

$pgtitle = array(gettext("Status"), gettext("CPU Load Graph"));
require_once("guiconfig.inc");

$ncpu = intval(get_single_sysctl('hw.ncpu'));

include("head.inc");

?>
<style>
.fs-cpu-chart { position: relative; padding: var(--fs-sp-3) var(--fs-sp-4) var(--fs-sp-4); }
.fs-cpu-chart svg { display: block; width: 100%; height: 260px; overflow: visible; }
.fs-cpu-chart .fs-cpu-grid { stroke: var(--fs-border); stroke-width: 1; shape-rendering: crispEdges; }
.fs-cpu-chart .fs-cpu-tick { fill: var(--fs-text-muted) !important; font-size: 11px; font-variant-numeric: tabular-nums; }
.fs-cpu-chart .fs-cpu-line { fill: none; stroke: var(--fs-series-1, #2a78d6); stroke-width: 1.75; stroke-linejoin: round; }
.fs-cpu-chart .fs-cpu-area { fill: var(--fs-series-1, #2a78d6); fill-opacity: .14; stroke: none; }
.fs-cpu-wait { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; gap: var(--fs-sp-2); color: var(--fs-text-muted); font-size: var(--fs-fs-sm); pointer-events: none; }
.fs-cpu-wait[hidden] { display: none; }
.fs-graph-live { color: var(--fs-text-muted); font-size: var(--fs-fs-xs); font-weight: 400; }
.fs-graph-live i { color: var(--fs-pass); font-size: .6em; vertical-align: middle; }
.fs-cpu-error .fs-graph-live i { color: var(--fs-block); }
@media (max-width: 575px) {
	.fs-cpu-chart svg { height: 200px; }
}
</style>

<div class="fs-tiles">
	<div class="fs-tile"><div class="fs-tile-label"><?=gettext('Current')?></div><div class="fs-tile-value" id="cpu-now">–</div></div>
	<div class="fs-tile"><div class="fs-tile-label"><?=gettext('Average')?></div><div class="fs-tile-value" id="cpu-avg">–</div><div class="fs-tile-hint"><?=gettext('Last 2 minutes')?></div></div>
	<div class="fs-tile"><div class="fs-tile-label"><?=gettext('Peak')?></div><div class="fs-tile-value" id="cpu-max">–</div><div class="fs-tile-hint"><?=gettext('Last 2 minutes')?></div></div>
	<div class="fs-tile"><div class="fs-tile-label"><?=gettext('CPU cores')?></div><div class="fs-tile-value"><?=($ncpu > 0) ? $ncpu : '–'?></div></div>
</div>

<div class="panel panel-default" id="cpu-panel">
	<div class="panel-heading">
		<h2 class="panel-title">
			<?=gettext("CPU usage")?>
			<span class="fs-graph-live" id="cpu-live"><i class="fa-solid fa-circle" aria-hidden="true"></i> <span><?=gettext('Live, updates every second')?></span></span>
		</h2>
	</div>
	<div class="fs-cpu-chart">
		<svg id="cpu-graph" role="img" aria-label="<?=gettext('CPU usage over the last 2 minutes')?>"></svg>
		<div class="fs-cpu-wait" id="cpu-wait"><i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i><span><?=gettext('Collecting initial data…')?></span></div>
	</div>
</div>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	var NS = 'http://www.w3.org/2000/svg';
	var POINTS = 120;          // two minutes at one sample per second
	var svg = document.getElementById('cpu-graph');
	var text = {
		error: <?=json_encode(gettext('Cannot get the CPU load'))?>,
		live: <?=json_encode(gettext('Live, updates every second'))?>
	};
	var data = [];
	var last = null;
	var failed = false;

	function el(name, attrs, parent) {
		var e = document.createElementNS(NS, name);
		Object.keys(attrs).forEach(function(k) { e.setAttribute(k, attrs[k]); });
		parent.appendChild(e);
		return e;
	}

	function draw() {
		var w = svg.clientWidth || 600;
		var h = svg.clientHeight || 260;
		var right = 40;            // room for the axis labels
		var pw = Math.max(10, w - right);

		while (svg.firstChild) {
			svg.removeChild(svg.firstChild);
		}

		[0, 25, 50, 75, 100].forEach(function(p) {
			var y = Math.round(h - (p / 100) * h) + 0.5;
			el('line', {'class': 'fs-cpu-grid', x1: 0, x2: pw, y1: y, y2: y}, svg);
			var t = el('text', {'class': 'fs-cpu-tick', x: pw + 6, y: y + 4}, svg);
			t.textContent = p + '%';
		});

		if (data.length < 2) {
			return;
		}

		var step = pw / (POINTS - 1);
		var x0 = pw - (data.length - 1) * step;
		var pts = data.map(function(v, i) {
			return (x0 + i * step).toFixed(1) + ',' + (h - (v / 100) * h).toFixed(1);
		});
		el('path', {'class': 'fs-cpu-area', d: 'M' + x0.toFixed(1) + ',' + h + ' L' + pts.join(' L') + ' L' + pw.toFixed(1) + ',' + h + ' Z'}, svg);
		el('path', {'class': 'fs-cpu-line', d: 'M' + pts.join(' L')}, svg);
	}

	function tiles() {
		if (!data.length) {
			return;
		}
		var sum = data.reduce(function(a, b) { return a + b; }, 0);
		$('#cpu-now').text(Math.round(data[data.length - 1]) + '%');
		$('#cpu-avg').text(Math.round(sum / data.length) + '%');
		$('#cpu-max').text(Math.round(Math.max.apply(null, data)) + '%');
	}

	function status(ok) {
		if (ok === !failed) {
			return;
		}
		failed = !ok;
		$('#cpu-panel').toggleClass('fs-cpu-error', failed);
		$('#cpu-live > span').text(failed ? text.error : text.live);
	}

	/* stats.php?stats=cpu answers "total ticks|idle ticks"; usage is the busy share between two samples */
	function fetchData() {
		$.ajax('stats.php', {type: 'get', data: {stats: 'cpu'}, cache: false, dataType: 'text'})
			.done(function(res) {
				var parts = String(res).trim().split('|');
				var total = parseFloat(parts[0]);
				var idle = parseFloat(parts[1]);
				if (!isFinite(total) || !isFinite(idle)) {
					status(false);
					return;
				}
				status(true);
				if (last && total > last.total) {
					var usage = 100 * (1 - (idle - last.idle) / (total - last.total));
					data.push(Math.min(100, Math.max(0, usage)));
					if (data.length > POINTS) {
						data.shift();
					}
					document.getElementById('cpu-wait').hidden = (data.length > 1);
					tiles();
					draw();
				}
				last = {total: total, idle: idle};
			})
			.fail(function() {
				status(false);
			});
	}

	draw();
	fetchData();
	setInterval(fetchData, 1000);
	var rt = null;
	window.addEventListener('resize', function() {
		clearTimeout(rt);
		rt = setTimeout(draw, 120);
	});
});
//]]>
</script>

<?php
include("foot.inc");
