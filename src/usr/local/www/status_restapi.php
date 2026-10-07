<?php
/*
 * status_restapi.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
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

/*
 * Status > REST API: activity of the last 24 hours, the listeners' state and
 * the request log (restapi_log.inc). Read-only, except "Clear log", which
 * also needs the System > REST API page.
 */

##|+PRIV
##|*IDENT=page-status-restapi
##|*NAME=Status: REST API
##|*DESCR=Allow access to the 'Status: REST API' page (API activity, the request log and the listener state). Clearing the log also needs the 'System: REST API' page.
##|*MATCH=status_restapi.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("restapi.inc");
require_once("restapi_log.inc");
require_once("restapi_listener.inc");
require_once("restapi_listeners.inc");

define('RESTAPI_REQLOG_PAGE', 100);
/* Log entries hold client-controlled text (paths, user agents): no markup characters in the JSON this page sends. */
define('RESTAPI_STATUS_JSON', JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
define('RESTAPI_REQLOG_PRUNED', '/var/run/restapi_log.pruned');

$can_manage = isAllowedPage('system_restapi.php');

/* Remove expired entries at most once an hour when the page is used (the nightly job does it too). */
if (!is_file(RESTAPI_REQLOG_PRUNED) || (filemtime(RESTAPI_REQLOG_PRUNED) < time() - 3600)) {
	restapi_reqlog_prune();
	@touch(RESTAPI_REQLOG_PRUNED);
}

/* The listener names for the "Via" column and filter: '' (WebGUI port) or the listener ID. */
$listener_names = array('gui' => gettext('WebGUI port'));
foreach (restapi_listeners() as $l) {
	$listener_names[$l['id']] = ($l['descr'] !== '') ? $l['descr'] : sprintf(gettext('Listener :%d'), $l['port']);
}

function restapi_status_filters(array $in) {
	$ranges = array('1h' => 3600, '24h' => 86400, '7d' => 604800, 'all' => 0);
	$range = (string)($in['range'] ?? '24h');
	$range = isset($ranges[$range]) ? $range : '24h';
	return array(
		'since' => $ranges[$range] ? time() - $ranges[$range] : 0,
		'q' => substr((string)($in['q'] ?? ''), 0, 200),
		'status' => preg_match('/^(ok|failed|[1-5]xx|[1-5][0-9]{2})?$/', (string)($in['status'] ?? '')) ? (string)($in['status'] ?? '') : '',
		'method' => preg_match('/^[A-Z]{0,10}$/', strtoupper((string)($in['method'] ?? ''))) ? strtoupper((string)($in['method'] ?? '')) : '',
		'listener' => preg_match('/^(gui|[0-9a-f]{8})?$/', (string)($in['listener'] ?? '')) ? (string)($in['listener'] ?? '') : '',
		'kind' => in_array(($in['kind'] ?? ''), array('read', 'write'), true) ? $in['kind'] : '',
	);
}

/* The log table's data: a page of filtered entries, newest first. */
if (($_GET['json'] ?? '') === 'log') {
	$matches = restapi_reqlog_filter(restapi_reqlog_read(), restapi_status_filters($_GET));
	$offset = max(0, (int)($_GET['offset'] ?? 0));
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: no-store');
	echo json_encode(array('total' => count($matches), 'offset' => $offset,
	    'entries' => array_slice($matches, $offset, RESTAPI_REQLOG_PAGE)), RESTAPI_STATUS_JSON);
	exit;
}

/* The matching entries as a download (JSON lines, newest first). */
if (($_GET['download'] ?? '') === 'log') {
	$matches = restapi_reqlog_filter(restapi_reqlog_read(), restapi_status_filters($_GET));
	header('Content-Type: application/x-ndjson; charset=utf-8');
	header('Content-Disposition: attachment; filename="restapi-log-' . date('Ymd-His') . '.jsonl"');
	header('Cache-Control: no-store');
	foreach ($matches as $e) {
		echo json_encode($e, RESTAPI_STATUS_JSON) . "\n";
	}
	exit;
}

if (($_POST['act'] ?? '') === 'clear') {
	if ($can_manage && restapi_reqlog_clear()) {
		$savemsg = gettext('The REST API request log was cleared.');
	} else {
		$input_errors[] = gettext('The log could not be cleared (it needs access to the System > REST API page).');
	}
}

$entries = restapi_reqlog_read();
$stats = restapi_reqlog_stats($entries);
$settings = restapi_settings();
$listeners = restapi_listeners();
$listener_states = restapi_listener_status();
$log_size = is_file(RESTAPI_REQLOG_FILE) ? (int)@filesize(RESTAPI_REQLOG_FILE) : 0;
$fail_pct = $stats['total'] ? round(100 * $stats['failed'] / $stats['total'], 1) : 0;
$running = count(array_filter($listeners, function ($l) use ($listener_states) {
	return $l['enable'] && (($listener_states[$l['id']]['state'] ?? '') === 'running');
}));
$log_labels = array('off' => gettext('Logging is off'), 'failures' => gettext('Logging failed requests'),
    'changes' => gettext('Logging changes and failed requests'), 'all' => gettext('Logging every request'));

$pgtitle = array(gettext('Status'), gettext('REST API'));
$pglinks = array('', '@self');
fs_page_action(gettext('Download'), 'status_restapi.php?download=log', 'fa-download', 'secondary',
    array('id' => 'rs-download', 'title' => gettext('Download the matching entries (JSON lines)')));
if ($can_manage) {
	fs_page_action(gettext('Clear log'), 'status_restapi.php?act=clear', 'fa-trash-can', 'danger', array('usepost' => true,
	    'data-fs-confirm' => gettext('Clear the REST API request log?'),
	    'data-fs-confirm-detail' => gettext('Every entry is removed. Failed authentications stay in the authentication log.'),
	    'data-fs-confirm-action' => gettext('Clear')));
	fs_page_action(gettext('Log settings'), 'system_restapi.php', 'fa-sliders', 'secondary');
}
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}
if ($savemsg) {
	print_info_box($savemsg, 'success');
}
if (!restapi_enabled()) {
	print_info_box(sprintf(gettext('The REST API is disabled.%1$s'), $can_manage ?
	    ' ' . sprintf(gettext('Enable it in %1$sSystem > REST API%2$s.'), '<a href="system_restapi.php">', '</a>') : ''), 'warning', false);
}
if ($settings['log_level'] === 'off') {
	print_info_box(sprintf(gettext('Request logging is off, so only entries written earlier are shown.%1$s'), $can_manage ?
	    ' ' . sprintf(gettext('Turn it on in %1$sSystem > REST API%2$s.'), '<a href="system_restapi.php">', '</a>') : ''), 'info', false);
}

/* The 24-hour chart: stacked bars (successful, failed) per hour. */
$max = 0;
foreach ($stats['buckets'] as $b) {
	$max = max($max, $b['ok'] + $b['failed']);
}
$nice = function ($n) {
	if ($n <= 4) {
		return 4;
	}
	$p = pow(10, floor(log10($n)));
	foreach (array(1, 2, 2.5, 5, 10) as $m) {
		if ($m * $p >= $n) {
			return (int)ceil($m * $p);
		}
	}
	return (int)ceil($n);
};
$ymax = $nice($max);
$W = 960; $H = 200; $L = 44; $R = 8; $T = 10; $B = 26;
$slot = ($W - $L - $R) / count($stats['buckets']);
$barw = round($slot * 0.62, 1);
$y = function ($v) use ($ymax, $H, $T, $B) {
	return $H - $B - (($H - $T - $B) * $v / $ymax);
};
/* A bar segment with 4px rounded top corners when it is the top of the stack. */
$seg = function ($x, $y0, $y1, $round) use ($barw) {
	$h = $y0 - $y1;
	if ($h <= 0) {
		return '';
	}
	$r = $round ? min(4, $h, $barw / 2) : 0;
	$x1 = $x + $barw;
	return "M{$x},{$y0} L{$x}," . ($y1 + $r) . ($r ? " Q{$x},{$y1} " . ($x + $r) . ",{$y1}" : '') .
	    " L" . ($x1 - $r) . ",{$y1}" . ($r ? " Q{$x1},{$y1} {$x1}," . ($y1 + $r) : '') . " L{$x1},{$y0} Z";
};
?>
<style>
	:root { --rs-ok: var(--fs-series-1); --rs-failed: var(--fs-block); }
	.rs-pad { padding: var(--fs-sp-4); }
	.rs-chart { width: 100%; height: auto; display: block; }
	.rs-chart .rs-grid { stroke: var(--fs-border); stroke-width: 1; }
	.rs-chart .rs-axis { fill: var(--fs-text-muted); font-size: 11px; font-family: var(--fs-font-ui); }
	.rs-chart .rs-ok { fill: var(--rs-ok); }
	.rs-chart .rs-failed { fill: var(--rs-failed); }
	.rs-chart .rs-hit { fill: transparent; cursor: default; }
	.rs-chart .rs-col.rs-hover .rs-hit { fill: var(--fs-accent-tint); }
	.rs-legend { display: flex; gap: 1rem; font-size: var(--fs-fs-sm); color: var(--fs-text-muted); }
	.rs-legend i { display: inline-block; width: .7rem; height: .7rem; border-radius: 2px; margin-right: .35rem; vertical-align: -1px; }
	.rs-tip { position: absolute; pointer-events: none; z-index: 5; background: var(--fs-surface-raised); color: var(--fs-text);
	    border: 1px solid var(--fs-border); border-radius: var(--fs-r-sm); box-shadow: var(--fs-shadow-overlay);
	    padding: .45rem .6rem; font-size: var(--fs-fs-xs); white-space: nowrap; display: none; }
	.rs-tip b { color: var(--fs-text-strong); }
	.rs-top-title { padding: var(--fs-sp-3) var(--fs-sp-4) .25rem; font-size: var(--fs-fs-xs); font-weight: 600; text-transform: uppercase;
	    letter-spacing: .04em; color: var(--fs-text-muted); }
	.rs-top-none { padding: 0 var(--fs-sp-4) var(--fs-sp-2); font-size: var(--fs-fs-sm); color: var(--fs-text-muted); }
	.rs-top { list-style: none; margin: 0; padding: 0; }
	.rs-top li { display: flex; justify-content: space-between; gap: .75rem; padding: .35rem var(--fs-sp-4); border-top: 1px solid var(--fs-border); font-size: var(--fs-fs-sm); }
	.rs-top li:first-child { border-top: 0; }
	.rs-top .rs-n { font-variant-numeric: tabular-nums; color: var(--fs-text-muted); }
	.rs-filters .form-select { width: auto; flex: 0 1 auto; }
	.rs-log td { font-size: var(--fs-fs-sm); }
	.rs-log .rs-path { font-family: var(--fs-font-mono); font-size: var(--fs-fs-xs); overflow-wrap: anywhere; color: var(--fs-text-strong); }
	.rs-log .rs-time { font-variant-numeric: tabular-nums; white-space: nowrap; }
	.rs-log td:nth-child(3) { min-width: 15rem; }
	.rs-log .rs-m { justify-content: center; min-width: 4.6em; font-family: var(--fs-font-mono); margin-right: .5rem; }
	.rs-log tr.rs-row { cursor: pointer; }
	.rs-log tr.rs-row.rs-failed > td:first-child { box-shadow: inset 3px 0 0 var(--rs-failed); }
	.rs-log tr.rs-detail > td { background: var(--fs-surface-raised) !important; font-size: var(--fs-fs-xs); }
	.rs-log tr.rs-detail dl { display: grid; grid-template-columns: minmax(7rem, 10rem) minmax(0, 1fr); gap: .2rem 1rem; margin: 0; }
	.rs-log tr.rs-detail dt { color: var(--fs-text-muted); }
	.rs-log tr.rs-detail dd { margin: 0; overflow-wrap: anywhere; }
	.rs-live-dot { display: inline-block; width: .55rem; height: .55rem; border-radius: 50%; background: var(--fs-text-muted); margin-right: .4rem; }
	.rs-live-on .rs-live-dot { background: var(--fs-pass); }
	@media (prefers-reduced-motion: no-preference) { .rs-live-on .rs-live-dot { animation: rs-pulse 1.6s ease-in-out infinite; } }
	@keyframes rs-pulse { 50% { opacity: .3; } }
</style>

<div class="fs-tiles">
<?php
	fs_tile(gettext('Requests (24 h)'), number_format($stats['total']), null, $log_labels[$settings['log_level']] ?? '');
	fs_tile(gettext('Failed (24 h)'), number_format($stats['failed']), null,
	    $stats['total'] ? sprintf(gettext('%s%% of requests'), $fail_pct) : gettext('No requests'));
	fs_tile(gettext('Clients (24 h)'), number_format($stats['clients']), null, gettext('Distinct addresses'));
	fs_tile(gettext('Avg. response'), number_format($stats['avg_ms']) . ' ms', null, gettext('Server time per request'));
	fs_tile(gettext('Listeners'), empty($listeners) ? gettext('None') : sprintf(gettext('%1$d of %2$d'), $running, count($listeners)), null,
	    $settings['guiapi'] ? gettext('Running, plus the WebGUI port') : gettext('Running (not on the WebGUI port)'));
?>
</div>

<div class="row g-3 mb-3">
	<div class="col-xl-8">
		<div class="panel panel-default h-100 mb-0">
			<div class="panel-heading d-flex flex-wrap justify-content-between align-items-center gap-2">
				<h2 class="panel-title mb-0"><?=gettext('Requests per hour, last 24 hours')?></h2>
				<div class="rs-legend" aria-hidden="true"><span><i style="background: var(--rs-ok)"></i><?=gettext('Successful')?></span>
					<span><i style="background: var(--rs-failed)"></i><?=gettext('Failed')?></span></div>
			</div>
			<div class="panel-body rs-pad position-relative">
				<svg class="rs-chart" viewBox="0 0 <?=$W?> <?=$H?>" role="img"
				    aria-label="<?=htmlspecialchars(sprintf(gettext('%1$d requests in the last 24 hours, %2$d failed. The request log below lists them.'), $stats['total'], $stats['failed']))?>">
<?php	foreach (array(0, $ymax / 2, $ymax) as $rs_tick): $gy = round($y($rs_tick), 1); ?>
					<line class="rs-grid" x1="<?=$L?>" x2="<?=$W - $R?>" y1="<?=$gy?>" y2="<?=$gy?>" />
					<text class="rs-axis" x="<?=$L - 8?>" y="<?=$gy + 4?>" text-anchor="end"><?=number_format($rs_tick)?></text>
<?php	endforeach;
	foreach ($stats['buckets'] as $i => $b):
		$x = round($L + ($i * $slot) + (($slot - $barw) / 2), 1);
		$y0 = $y(0);
		$yok = $y($b['ok']);
		$yall = $y($b['ok'] + $b['failed']);
		$label = date('H:i', $b['t']) . '–' . date('H:i', $b['t'] + 3600);
?>
					<g class="rs-col" data-label="<?=htmlspecialchars($label)?>" data-ok="<?=$b['ok']?>" data-failed="<?=$b['failed']?>">
						<rect class="rs-hit" x="<?=round($L + $i * $slot, 1)?>" y="<?=$T?>" width="<?=round($slot, 1)?>" height="<?=$H - $T - $B?>" rx="3" />
						<path class="rs-ok" d="<?=$seg($x, $y0, $yok, $b['failed'] === 0)?>" />
						<path class="rs-failed" d="<?=$seg($x, ($b['ok'] > 0) ? $yok - 2 : $y0, ($b['ok'] > 0) ? min($yall, $yok - 2) : $yall, true)?>" />
					</g>
<?php		if ($i % 3 === 0): ?>
					<text class="rs-axis" x="<?=round($x + $barw / 2, 1)?>" y="<?=$H - 8?>" text-anchor="middle"><?=date('H:00', $b['t'])?></text>
<?php		endif;
	endforeach; ?>
				</svg>
				<div class="rs-tip" id="rs-tip" role="status"></div>
			</div>
		</div>
	</div>
	<div class="col-xl-4">
		<div class="panel panel-default h-100 mb-0">
			<div class="panel-heading"><h2 class="panel-title"><?=gettext('Last 24 hours')?></h2></div>
			<div class="panel-body pb-2">
<?php
	$top = function ($title, array $items, $fmt) {
		echo '<div class="rs-top-title">' . htmlspecialchars($title) . '</div>';
		if (empty($items)) {
			echo '<div class="rs-top-none">' . gettext('None') . '</div>';
			return;
		}
		echo '<ul class="rs-top">';
		foreach ($items as $k => $n) {
			echo '<li><span class="text-truncate">' . $fmt((string)$k) . '</span><span class="rs-n">' . number_format($n) . '</span></li>';
		}
		echo '</ul>';
	};
	$top(gettext('Busiest keys'), $stats['top_keys'], function ($k) {
		list($id, $user) = array_pad(explode("\t", $k, 2), 2, '');
		return '<span class="fs-mono">' . htmlspecialchars(RESTAPI_TOKEN_PREFIX . '_' . $id) . '</span> <span class="fs-muted">' . htmlspecialchars($user) . '</span>';
	});
	$top(gettext('Busiest clients'), $stats['top_ips'], function ($ip) {
		return '<span class="fs-mono">' . htmlspecialchars($ip) . '</span>';
	});
	$top(gettext('Most failures'), $stats['top_failing'], function ($ip) {
		return '<span class="fs-mono">' . htmlspecialchars($ip) . '</span>';
	});
?>
			</div>
		</div>
	</div>
</div>

<?php if (!empty($listeners)): ?>
<div class="panel panel-default fs-table">
	<div class="panel-heading d-flex justify-content-between align-items-center">
		<h2 class="panel-title mb-0"><?=gettext('Listeners')?></h2>
<?php	if ($can_manage): ?>
		<a class="btn btn-sm btn-outline-secondary" href="system_restapi.php?view=listeners"><i class="fa-solid fa-gear icon-embed-btn" aria-hidden="true"></i><?=gettext('Manage')?></a>
<?php	endif; ?>
	</div>
	<div class="panel-body table-responsive">
		<table class="table">
			<thead><tr>
				<th class="fs-col-status"><?=gettext('State')?></th><th><?=gettext('Listener')?></th><th><?=gettext('Listens on')?></th><th><?=gettext('Access')?></th>
			</tr></thead>
			<tbody>
<?php	foreach ($listeners as $l):
		$s = $listener_states[$l['id']] ?? array();
		list($badge, $badge_label) = restapi_listener_state_badge($l['enable'] ? (string)($s['state'] ?? '') : 'disabled');
?>
				<tr class="<?=$l['enable'] ? '' : 'fs-row-disabled'?>">
					<td><?=fs_badge($badge, $badge_label, empty($s['error']) ? null : $s['error'])?></td>
					<td><?=htmlspecialchars($listener_names[$l['id']])?><?=!empty($s['error']) ?
					    '<div class="small fs-muted">' . htmlspecialchars($s['error']) . '</div>' : ''?></td>
					<td class="fs-mono"><?=htmlspecialchars(empty($s['addresses']) ? '—' : implode(', ', array_map(function ($a) use ($l) {
						return (is_ipaddrv6($a) ? "[{$a}]" : $a) . ':' . $l['port'];
					}, $s['addresses'])))?></td>
					<td><span class="fs-chip fs-chip--strong<?=$l['readonly'] ? '' : ' is-warn'?>"><?=$l['readonly'] ? gettext('Read-only') : gettext('Read/write')?></span></td>
				</tr>
<?php	endforeach; ?>
			</tbody>
		</table>
	</div>
</div>
<?php endif; ?>

<div class="panel panel-default fs-table" id="rs-log-card">
	<div class="fs-toolbar">
		<div class="fs-toolbar-default rs-filters" id="rs-filters">
			<h2 class="fs-toolbar-title"><?=gettext('Request log')?></h2>
			<div class="fs-search" role="search">
				<i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
				<input type="search" class="form-control" name="q" autocomplete="off" placeholder="<?=gettext('Search path, key, user, address or error…')?>"
				    aria-label="<?=gettext('Search the log')?>" />
			</div>
			<select class="form-select form-select-sm" name="status" aria-label="<?=gettext('Status')?>">
				<option value=""><?=gettext('Any status')?></option>
				<option value="ok"><?=gettext('Successful')?></option>
				<option value="failed"><?=gettext('Failed')?></option>
				<option value="4xx">4xx</option>
				<option value="5xx">5xx</option>
				<option value="401"><?=gettext('401 Unauthorized')?></option>
				<option value="403"><?=gettext('403 Forbidden')?></option>
				<option value="429"><?=gettext('429 Rate limited')?></option>
			</select>
			<select class="form-select form-select-sm" name="method" aria-label="<?=gettext('Method')?>">
				<option value=""><?=gettext('Any method')?></option>
<?php	foreach (array('GET', 'POST', 'PUT', 'PATCH', 'DELETE') as $m): ?>
				<option value="<?=$m?>"><?=$m?></option>
<?php	endforeach; ?>
			</select>
			<select class="form-select form-select-sm" name="kind" aria-label="<?=gettext('Reads or changes')?>">
				<option value=""><?=gettext('Reads and changes')?></option>
				<option value="read"><?=gettext('Reads')?></option>
				<option value="write"><?=gettext('Changes')?></option>
			</select>
			<select class="form-select form-select-sm" name="listener" aria-label="<?=gettext('Received on')?>">
				<option value=""><?=gettext('Any port')?></option>
<?php	foreach ($listener_names as $id => $name): ?>
				<option value="<?=htmlspecialchars($id)?>"><?=htmlspecialchars($name)?></option>
<?php	endforeach; ?>
			</select>
			<select class="form-select form-select-sm" name="range" aria-label="<?=gettext('Time range')?>">
				<option value="1h"><?=gettext('Last hour')?></option>
				<option value="24h" selected><?=gettext('Last 24 hours')?></option>
				<option value="7d"><?=gettext('Last 7 days')?></option>
				<option value="all"><?=gettext('Everything kept')?></option>
			</select>
			<span class="fs-toolbar-spacer"></span>
			<span class="fs-toolbar-count" id="rs-total" aria-live="polite">…</span>
			<button type="button" class="btn btn-sm btn-outline-secondary" id="rs-live" aria-pressed="false" title="<?=gettext('Refresh the log every 10 seconds')?>">
				<span class="rs-live-dot" aria-hidden="true"></span><?=gettext('Live')?></button>
		</div>
	</div>
	<div class="panel-body table-responsive">
		<table class="table table-hover rs-log">
			<thead><tr>
				<th><?=gettext('Time')?></th><th><?=gettext('Status')?></th><th><?=gettext('Request')?></th>
				<th><?=gettext('Key / user')?></th><th><?=gettext('Client')?></th><th><?=gettext('Via')?></th><th class="text-end"><?=gettext('Time taken')?></th>
			</tr></thead>
			<tbody id="rs-rows"></tbody>
		</table>
	</div>
	<div class="panel-footer d-flex flex-wrap align-items-center gap-2">
		<span class="small fs-muted me-auto" id="rs-shown"></span>
		<span class="small fs-muted"><?=htmlspecialchars(sprintf(gettext('%1$s · kept %2$d days, up to %3$d MB'), format_bytes($log_size), $settings['log_days'], $settings['log_mb']))?></span>
		<button type="button" class="btn btn-sm btn-outline-secondary d-none" id="rs-more"><?=gettext('Show older')?></button>
	</div>
</div>

<script type="application/json" id="rs-i18n"><?=json_encode(array(
	'none' => gettext('No request matches the filters.'),
	'entries' => gettext('%d entries'),
	'shown' => gettext('Showing %1$d of %2$d'),
	'requests' => gettext('requests'),
	'failed' => gettext('failed'),
	'ua' => gettext('User agent'),
	'code' => gettext('Error code'),
	'key' => gettext('Key'),
	'kind' => gettext('Kind'),
	'read' => gettext('read'),
	'write' => gettext('change'),
	'loading' => gettext('Loading…'),
	'error' => gettext('The log could not be loaded.'),
	'listeners' => $listener_names,
	'prefix' => RESTAPI_TOKEN_PREFIX,
	'tz' => (int)date('Z'),
), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT)?></script>
<script type="text/javascript">
//<![CDATA[
events.push(function() {
	'use strict';
	var T = JSON.parse(document.getElementById('rs-i18n').textContent);

	/* ---- chart tooltip ---- */
	var tip = document.getElementById('rs-tip');
	document.querySelectorAll('.rs-col').forEach(function(col) {
		col.addEventListener('mouseenter', function() {
			col.classList.add('rs-hover');
			var ok = +col.dataset.ok, bad = +col.dataset.failed;
			tip.textContent = '';
			var b = document.createElement('b');
			b.textContent = col.dataset.label;
			tip.appendChild(b);
			tip.appendChild(document.createElement('br'));
			tip.appendChild(document.createTextNode((ok + bad) + ' ' + T.requests + ' · ' + bad + ' ' + T.failed));
			tip.style.display = 'block';
			var box = col.closest('.panel-body').getBoundingClientRect(), r = col.getBoundingClientRect();
			var left = r.left - box.left + r.width / 2 - tip.offsetWidth / 2;
			tip.style.left = Math.max(4, Math.min(left, box.width - tip.offsetWidth - 4)) + 'px';
			tip.style.top = '0.5rem';
		});
		col.addEventListener('mouseleave', function() { col.classList.remove('rs-hover'); tip.style.display = 'none'; });
	});

	/* ---- the log ---- */
	var rows = document.getElementById('rs-rows'), filters = document.getElementById('rs-filters');
	var more = document.getElementById('rs-more'), shown = document.getElementById('rs-shown');
	var total = document.getElementById('rs-total'), download = document.getElementById('rs-download');
	var loaded = 0, timer = null, live = null;

	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) { n.className = cls; }
		if (text !== undefined) { n.textContent = text; }
		return n;
	}
	function pad(n) { return (n < 10 ? '0' : '') + n; }
	/* Firewall local time, like the rest of the GUI. */
	function when(t) {
		var d = new Date((t + T.tz) * 1000);
		return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate()) + ' ' +
		    pad(d.getUTCHours()) + ':' + pad(d.getUTCMinutes()) + ':' + pad(d.getUTCSeconds());
	}
	function query() {
		var p = new URLSearchParams();
		filters.querySelectorAll('[name]').forEach(function(f) { if (f.value !== '') { p.set(f.name, f.value); } });
		return p;
	}
	var mclass = {GET: 'info', POST: 'pass', PUT: 'warn', PATCH: 'warn', DELETE: 'block'};
	function row(e) {
		var bad = e.s >= 400;
		var tr = el('tr', 'rs-row' + (bad ? ' rs-failed' : ''));
		tr.tabIndex = 0;
		tr.appendChild(el('td', 'rs-time', when(e.t)));
		var st = el('td');
		st.appendChild(el('span', 'fs-badge fs-badge--' + (e.s >= 500 ? 'block' : (bad ? 'warn' : 'pass')), String(e.s)));
		tr.appendChild(st);
		var req = el('td');
		req.appendChild(el('span', 'fs-badge rs-m fs-badge--' + (mclass[e.m] || 'neutral'), e.m));
		req.appendChild(el('span', 'rs-path', e.p));
		tr.appendChild(req);
		var who = el('td');
		if (e.k) {
			who.appendChild(el('span', 'fs-mono', T.prefix + '_' + e.k));
			if (e.u) { who.appendChild(el('div', 'small fs-muted', e.u)); }
		} else {
			who.appendChild(el('span', 'fs-muted', '—'));
		}
		tr.appendChild(who);
		tr.appendChild(el('td', 'fs-mono', e.ip));
		tr.appendChild(el('td', 'fs-muted', T.listeners[e.l || 'gui'] || e.l));
		tr.appendChild(el('td', 'text-end fs-muted text-nowrap', e.ms + ' ms'));
		function toggle() {
			var next = tr.nextElementSibling;
			if (next && next.classList.contains('rs-detail')) { next.remove(); return; }
			var d = el('tr', 'rs-detail'), td = el('td');
			td.colSpan = 7;
			var dl = el('dl');
			[[T.code, e.c || '—'], [T.kind, e.w ? T.write : T.read], [T.ua, e.ua || '—']].forEach(function(p) {
				dl.appendChild(el('dt', '', p[0]));
				dl.appendChild(el('dd', '', p[1]));
			});
			td.appendChild(dl);
			d.appendChild(td);
			tr.after(d);
		}
		tr.addEventListener('click', toggle);
		tr.addEventListener('keydown', function(ev) { if (ev.key === 'Enter' || ev.key === ' ') { ev.preventDefault(); toggle(); } });
		return tr;
	}
	function load(append) {
		var p = query();
		p.set('json', 'log');
		p.set('offset', append ? loaded : 0);
		download.href = 'status_restapi.php?download=log&' + query().toString();
		fetch('status_restapi.php?' + p.toString(), {credentials: 'same-origin', cache: 'no-store'})
		.then(function(r) { if (!r.ok) { throw new Error(r.status); } return r.json(); })
		.then(function(data) {
			if (!append) {
				rows.textContent = '';
				loaded = 0;
			}
			data.entries.forEach(function(e) { rows.appendChild(row(e)); });
			loaded += data.entries.length;
			if (data.total === 0) {
				var tr = el('tr', 'fs-empty'), td = el('td', '', '');
				td.appendChild(el('span', 'fs-empty-message', T.none));
				td.colSpan = 7;
				tr.appendChild(td);
				rows.appendChild(tr);
			}
			total.textContent = T.entries.replace('%d', data.total);
			shown.textContent = T.shown.replace('%1$d', loaded).replace('%2$d', data.total);
			more.classList.toggle('d-none', loaded >= data.total);
		})
		.catch(function() { shown.textContent = T.error; });
	}
	filters.addEventListener('input', function() { clearTimeout(timer); timer = setTimeout(function() { load(false); }, 250); });
	filters.addEventListener('change', function() { load(false); });
	filters.addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); } });
	more.addEventListener('click', function() { load(true); });
	var liveBtn = document.getElementById('rs-live');
	liveBtn.addEventListener('click', function() {
		var on = liveBtn.getAttribute('aria-pressed') !== 'true';
		liveBtn.setAttribute('aria-pressed', on ? 'true' : 'false');
		liveBtn.classList.toggle('rs-live-on', on);
		clearInterval(live);
		live = on ? setInterval(function() { load(false); }, 10000) : null;
	});
	load(false);
});
//]]>
</script>
<?php
include("foot.inc");
