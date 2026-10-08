<?php
/*
 * status_logs_filter_summary.php
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
##|*IDENT=page-diagnostics-logs-firewall-summary
##|*NAME=Status: System Logs: Firewall Log Summary
##|*DESCR=Allow access to the 'Status: System Logs: Firewall Log Summary' page
##|*MATCH=status_logs_filter_summary.php*
##|-PRIV

require_once("status_logs_common.inc");

$lines = 10000;
$entriesperblock = 5;


/*
Build a list of allowed log files so we can reject others to prevent the page
from acting on unauthorized files.
*/
$allowed_logs = array(
	"filter" => array("name" => "Firewall",
		    "shortcut" => "filter"),
);

// The logs to display are specified in a REQUEST argument. Default to 'system' logs
if (!$_REQUEST['logfile']) {
	$logfile = 'filter';
	$view = 'normal';
} else {
	$logfile = $_REQUEST['logfile'];
	$view = $_REQUEST['view'];
	if (!array_key_exists($logfile, $allowed_logs)) {
		/* Do not let someone attempt to load an unauthorized log. */
		$logfile = 'filter';
		$view = 'normal';
	}
}

if ($view == 'normal')  { $view_title = gettext("Normal View"); }
if ($view == 'dynamic') { $view_title = gettext("Dynamic View"); }
if ($view == 'summary') { $view_title = gettext("Summary View"); }


// Status Logs Common - Code
status_logs_common_code();


$pgtitle = array(gettext("Status"), gettext("System Logs"), gettext($allowed_logs[$logfile]["name"]), $view_title);
$pglinks = array("", "status_logs.php", "status_logs_filter.php", "@self");
include("head.inc");

status_logs_notices();

// Tab Array
tab_array_logs_common();


$filterlog = conv_log_filter($logfile_path, $lines, $lines);
if (!is_array($filterlog)) {
	$filterlog = array();
}
$gotlines = count($filterlog);
$fields = array(
	'act'	   => gettext("Actions"),
	'interface' => gettext("Interfaces"),
	'proto'	 => gettext("Protocols"),
	'srcip'	 => gettext("Source IPs"),
	'dstip'	 => gettext("Destination IPs"),
	'srcport'	=> gettext("Source Ports"),
	'dstport'	=> gettext("Destination Ports"));

$summary = array();
foreach (array_keys($fields) as $f) {
	$summary[$f] = array();
}

foreach ($filterlog as $fe) {
	$specialfields = array('srcport', 'dstport');
	foreach (array_keys($fields) as $field) {
		if (!in_array($field, $specialfields)) {
			$summary[$field][$fe[$field]] = ($summary[$field][$fe[$field]] ?? 0) + 1;
		}
	}
	/* Handle some special cases */
	$key = $fe['srcport'] ? $fe['proto'] . '/' . $fe['srcport'] : (string)$fe['srcport'];
	$summary['srcport'][$key] = ($summary['srcport'][$key] ?? 0) + 1;
	$key = $fe['dstport'] ? $fe['proto'] . '/' . $fe['dstport'] : (string)$fe['dstport'];
	$summary['dstport'][$key] = ($summary['dstport'][$key] ?? 0) + 1;
}

/* top entries per field (empty keys skipped, like before) plus "Other" */
$charts = array();
foreach (array_keys($fields) as $field) {
	arsort($summary[$field], SORT_NUMERIC);
	$items = array();
	$total = 0;
	foreach ($summary[$field] as $label => $count) {
		if (count($items) >= $entriesperblock) {
			break;
		}
		if ((string)$label === '') {
			continue;
		}
		$item = array('label' => (string)$label, 'value' => $count, 'service' => '', 'lookup' => is_ipaddr($label));
		if (substr_count($label, '/') == 1) {
			list($proto, $port) = explode('/', $label);
			$service = getservbyport((int)$port, strtolower($proto));
			if ($service) {
				$item['service'] = $service;
			}
		}
		$items[] = $item;
		$total += $count;
	}
	$leftover = $gotlines - $total;
	$charts[$field] = array('items' => $items, 'other' => max(0, $leftover), 'distinct' => count($summary[$field]));
}

$acts = $summary['act'];
?>

<div class="fs-tiles">
<?php
fs_tile(gettext('Entries summarized'), number_format($gotlines), null, sprintf(gettext('Latest %s lines of the firewall log'), number_format($lines)));
fs_tile(gettext('Blocked'), number_format($acts['block'] ?? 0), (($acts['block'] ?? 0) > 0) ? 'block' : null);
fs_tile(gettext('Passed'), number_format($acts['pass'] ?? 0), (($acts['pass'] ?? 0) > 0) ? 'pass' : null);
fs_tile(gettext('Source addresses'), number_format(count($summary['srcip'])), null, gettext('Distinct sources seen'));
?>
</div>

<?php if ($gotlines == 0): ?>
<div class="panel panel-default">
	<div class="panel-body fs-sum-empty"><?=gettext('The firewall log has no entries to summarize yet.')?></div>
</div>
<?php else: ?>
<div class="fs-sum-grid">
<?php
$chartnum = 0;
foreach ($charts as $field => $chart):
	$share = function ($v) use ($gotlines) {
		return $gotlines ? round(100 * $v / $gotlines, 1) : 0;
	};
?>
	<section class="panel panel-default fs-sum-card" aria-labelledby="fs-sum-title-<?=$chartnum?>">
		<div class="panel-heading">
			<h2 class="panel-title" id="fs-sum-title-<?=$chartnum?>"><?=fs_h($fields[$field])?></h2>
			<span class="fs-muted small"><?=fs_h(sprintf(gettext('%s distinct'), number_format($chart['distinct'])))?></span>
		</div>
		<div class="panel-body fs-sum-body">
			<div class="fs-sum-chart" id="pieChart<?=$chartnum?>" role="img" aria-label="<?=fs_h(sprintf(gettext('Share of entries by %s'), $fields[$field]))?>"></div>
			<table class="table table-sm fs-sum-table">
				<thead>
					<tr><th><?=fs_h($fields[$field])?></th><th class="text-end"><?=gettext('Entries')?></th><th class="text-end"><?=gettext('Share')?></th><th><span class="visually-hidden"><?=gettext('Actions')?></span></th></tr>
				</thead>
				<tbody>
<?php	foreach ($chart['items'] as $i => $item): ?>
					<tr>
						<td class="fs-sum-label"><span class="fs-sum-swatch" data-fs-slot="<?=$i?>" aria-hidden="true"></span><span class="fs-mono"><?=fs_h($item['label'])?></span><?php if ($item['service'] !== ''): ?> <span class="fs-muted small"><?=fs_h($item['service'])?></span><?php endif; ?></td>
						<td class="text-end fs-mono"><?=number_format($item['value'])?></td>
						<td class="text-end fs-mono"><?=$share($item['value'])?>%</td>
						<td class="fs-col-actions"><?php if ($item['lookup']): ?><?=fs_row_actions([['custom', 'diag_dns.php?host=' . urlencode($item['label']), $item['label'],
							['icon' => 'fa-solid fa-magnifying-glass', 'label' => sprintf(gettext('Reverse resolve %s with DNS'), $item['label'])]]])?><?php endif; ?></td>
					</tr>
<?php	endforeach; ?>
<?php	if ($chart['other'] > 0): ?>
					<tr>
						<td class="fs-sum-label"><span class="fs-sum-swatch fs-sum-swatch--other" aria-hidden="true"></span><?=gettext('Other')?></td>
						<td class="text-end fs-mono"><?=number_format($chart['other'])?></td>
						<td class="text-end fs-mono"><?=$share($chart['other'])?>%</td>
						<td></td>
					</tr>
<?php	endif; ?>
				</tbody>
			</table>
		</div>
	</section>
<?php
	$chartnum++;
endforeach;
?>
</div>
<?php endif; ?>

<style>
.fs-sum-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 34rem), 1fr)); gap: 0 var(--fs-sp-5); }
.fs-sum-body { display: flex; flex-wrap: wrap; align-items: center; gap: var(--fs-sp-4); padding: var(--fs-sp-4); }
.fs-sum-chart { flex: 0 0 13rem; width: 13rem; height: 13rem; margin: 0 auto; }
.fs-sum-table { flex: 1 1 16rem; min-width: 0; margin: 0; }
.panel .fs-sum-table > :not(caption) > tr > :first-child { padding-left: 0; }
.panel .fs-sum-table > :not(caption) > tr > :last-child { padding-right: 0; }
.fs-sum-table > tbody > tr > td { height: 2rem; padding-top: .25rem; padding-bottom: .25rem; vertical-align: middle; }
.fs-sum-label { overflow-wrap: anywhere; }
.fs-sum-swatch { display: inline-block; width: .7rem; height: .7rem; margin-right: .5rem; border-radius: 2px; vertical-align: -.05rem; background: var(--fs-neutral); }
.fs-sum-empty { padding: var(--fs-sp-6) var(--fs-sp-4); text-align: center; color: var(--fs-text-muted); }
.fs-sum-chart .p0_tooltip text, .fs-sum-chart [class$="_tooltip"] text { font-family: var(--fs-font-ui); }
</style>

<script src="/vendor/d3/d3.min.js"></script>
<script src="/vendor/d3pie/d3pie.min.js"></script>
<script type="text/javascript">
//<![CDATA[
events.push(function() {
	var charts = <?=json_encode(array_values($charts))?>;
	/* categorical series tokens (css/_freesense-tokens.css), in order; "Other" stays neutral grey */
	var styles = getComputedStyle(document.body);
	var token = function (name) { return styles.getPropertyValue(name).trim(); };
	var slots = [1, 2, 3, 4, 5, 6, 7, 8].map(function (n) { return token('--fs-series-' + n); });
	var other = token('--fs-series-other');
	var surface = getComputedStyle(document.querySelector('.fs-sum-card') || document.body).backgroundColor;

	document.querySelectorAll('.fs-sum-swatch[data-fs-slot]').forEach(function (el) {
		el.style.backgroundColor = slots[parseInt(el.getAttribute('data-fs-slot'), 10) % slots.length];
	});
	document.querySelectorAll('.fs-sum-swatch--other').forEach(function (el) {
		el.style.backgroundColor = other;
	});

	if (typeof d3pie === 'undefined') {
		return;
	}
	charts.forEach(function (chart, n) {
		var el = document.getElementById('pieChart' + n);
		var content = chart.items.map(function (item, i) {
			return { label: item.label, value: item.value, color: slots[i % slots.length] };
		});
		if (chart.other > 0) {
			content.push({ label: <?=json_encode(gettext('Other'))?>, value: chart.other, color: other });
		}
		if (!el || !content.length) {
			return;
		}
		var size = el.clientWidth || 208;
		new d3pie('pieChart' + n, {
			size: { canvasHeight: size, canvasWidth: size, pieInnerRadius: '58%', pieOuterRadius: '96%' },
			data: { sortOrder: 'none', content: content },
			labels: {
				outer: { format: 'none' },
				inner: { format: 'none' },
				lines: { enabled: false }
			},
			tooltips: {
				enabled: true,
				type: 'placeholder',
				string: '{label}: {value} ({percentage}%)',
				styles: { fadeInSpeed: 120, backgroundColor: token('--fs-text-strong'), backgroundOpacity: 0.95, color: token('--fs-surface'), borderRadius: 4, fontSize: 12, padding: 6 }
			},
			effects: { load: { effect: 'none' }, pullOutSegmentOnClick: { effect: 'none' }, highlightSegmentOnMouseover: true, highlightLuminosity: 0.15 },
			misc: { colors: { segmentStroke: surface }, canvasPadding: { top: 2, right: 2, bottom: 2, left: 2 } }
		});
	});
});
//]]>
</script>

<?php
include("foot.inc");
?>
