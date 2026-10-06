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

define('RESTAPI_REQLOG_PAGE', 100);
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
	    'entries' => array_slice($matches, $offset, RESTAPI_REQLOG_PAGE)), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
	exit;
}

/* The matching entries as a download (JSON lines, newest first). */
if (($_GET['download'] ?? '') === 'log') {
	$matches = restapi_reqlog_filter(restapi_reqlog_read(), restapi_status_filters($_GET));
	header('Content-Type: application/x-ndjson; charset=utf-8');
	header('Content-Disposition: attachment; filename="restapi-log-' . date('Ymd-His') . '.jsonl"');
	header('Cache-Control: no-store');
	foreach ($matches as $e) {
		echo json_encode($e, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
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
	.rs-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(10.5rem, 1fr)); gap: .75rem; margin-bottom: 1rem; }
	.rs-tile { background: var(--bs-body-bg); border: 1px solid var(--bs-border-color); border-radius: var(--bs-border-radius);
	    padding: .85rem 1rem; box-shadow: 0 1px 3px rgba(0, 0, 0, .07); }
	.rs-tile .rs-label { font-size: .78rem; text-transform: uppercase; letter-spacing: .04em; color: var(--bs-secondary-color); font-weight: 600; }
	.rs-tile .rs-value { font-size: 1.65rem; font-weight: 700; line-height: 1.15; color: var(--bs-emphasis-color); font-variant-numeric: tabular-nums; }
	.rs-tile .rs-sub { font-size: .8rem; color: var(--bs-secondary-color); }
	.rs-tile .rs-bad { color: var(--bs-danger-text-emphasis); }
	.rs-pad { padding: 1rem; }
	:root { --rs-ok: #2a78d6; --rs-failed: #d03b3b; }
	html[data-bs-theme="dark"] { --rs-ok: #3987e5; }
	.rs-chart { width: 100%; height: auto; display: block; }
	.rs-chart .rs-grid { stroke: var(--bs-border-color); stroke-width: 1; }
	.rs-chart .rs-axis { fill: var(--bs-secondary-color); font-size: 11px; font-family: var(--bs-body-font-family); }
	.rs-chart .rs-ok { fill: var(--rs-ok); }
	.rs-chart .rs-failed { fill: var(--rs-failed); }
	.rs-chart .rs-hit { fill: transparent; cursor: default; }
	.rs-chart .rs-col.rs-hover .rs-hit { fill: var(--bs-tertiary-bg); }
	.rs-legend { display: flex; gap: 1rem; font-size: .85rem; color: var(--bs-secondary-color); }
	.rs-legend i { display: inline-block; width: .7rem; height: .7rem; border-radius: 2px; margin-right: .35rem; vertical-align: -1px; }
	.rs-tip { position: absolute; pointer-events: none; z-index: 5; background: var(--bs-body-bg); color: var(--bs-body-color);
	    border: 1px solid var(--bs-border-color); border-radius: var(--bs-border-radius-sm); box-shadow: 0 .5rem 1.25rem rgba(0, 0, 0, .25);
	    padding: .45rem .6rem; font-size: .8rem; white-space: nowrap; display: none; }
	.rs-tip b { color: var(--bs-emphasis-color); }
	.rs-top { list-style: none; margin: 0; padding: 0; }
	.rs-top li { display: flex; justify-content: space-between; gap: .75rem; padding: .35rem 1rem; border-top: 1px solid var(--bs-border-color); font-size: .875rem; }
	.rs-top li:first-child { border-top: 0; }
	.rs-top .rs-n { font-variant-numeric: tabular-nums; color: var(--bs-secondary-color); }
	.rs-toolbar { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
	.rs-toolbar .form-select, .rs-toolbar .form-control { width: auto; }
	.rs-toolbar .rs-search { flex: 1 1 16rem; min-width: 12rem; }
	.rs-log td { font-size: .85rem; }
	.rs-log .rs-path { font-family: var(--bs-font-monospace); font-size: .82rem; overflow-wrap: anywhere; color: var(--bs-emphasis-color); }
	.rs-log .rs-time { font-variant-numeric: tabular-nums; white-space: nowrap; }
	.rs-log .rs-m { display: inline-block; min-width: 4em; text-align: center; font-family: var(--bs-font-monospace); }
	.rs-log tr.rs-row { cursor: pointer; }
	.rs-log tr.rs-row.rs-failed > td:first-child { box-shadow: inset 3px 0 0 var(--rs-failed); }
	.rs-log tr.rs-detail > td { background: var(--bs-tertiary-bg) !important; font-size: .82rem; }
	.rs-log tr.rs-detail dl { margin: 0; }
	.rs-live-dot { display: inline-block; width: .55rem; height: .55rem; border-radius: 50%; background: var(--bs-success); margin-right: .35rem; }
	@media (prefers-reduced-motion: no-preference) { .rs-live-on .rs-live-dot { animation: rs-pulse 1.6s ease-in-out infinite; } }
	@keyframes rs-pulse { 50% { opacity: .3; } }
</style>

<div class="rs-tiles" role="list">
	<div class="rs-tile" role="listitem"><div class="rs-label"><?=gettext('Requests (24 h)')?></div>
		<div class="rs-value"><?=number_format($stats['total'])?></div>
		<div class="rs-sub"><?=htmlspecialchars($log_labels[$settings['log_level']])?></div></div>
	<div class="rs-tile" role="listitem"><div class="rs-label"><?=gettext('Failed (24 h)')?></div>
		<div class="rs-value<?=$stats['failed'] ? ' rs-bad' : ''?>"><?=number_format($stats['failed'])?></div>
		<div class="rs-sub"><?=$stats['total'] ? htmlspecialchars(sprintf(gettext('%s%% of requests'), $fail_pct)) : gettext('no requests')?></div></div>
	<div class="rs-tile" role="listitem"><div class="rs-label"><?=gettext('Clients (24 h)')?></div>
		<div class="rs-value"><?=number_format($stats['clients'])?></div>
		<div class="rs-sub"><?=gettext('distinct addresses')?></div></div>
	<div class="rs-tile" role="listitem"><div class="rs-label"><?=gettext('Avg. response')?></div>
		<div class="rs-value"><?=number_format($stats['avg_ms'])?><span class="fs-6 fw-normal text-muted"> ms</span></div>
		<div class="rs-sub"><?=gettext('server time per request')?></div></div>
	<div class="rs-tile" role="listitem"><div class="rs-label"><?=gettext('Listeners')?></div>
		<div class="rs-value"><?=$running?><span class="fs-6 fw-normal text-muted"> / <?=count($listeners)?></span></div>
		<div class="rs-sub"><?=$settings['guiapi'] ? gettext('running, plus the WebGUI port') : gettext('running (not on the WebGUI port)')?></div></div>
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
			<div class="panel-body">
<?php
	$top = function ($title, array $items, $fmt) {
		echo '<div class="small fw-semibold text-muted px-3 pt-3 pb-1 text-uppercase">' . htmlspecialchars($title) . '</div>';
		if (empty($items)) {
			echo '<div class="small text-muted px-3 pb-2">' . gettext('none') . '</div>';
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
		return '<code>' . htmlspecialchars(RESTAPI_TOKEN_PREFIX . '_' . $id) . '</code> <span class="text-muted">' . htmlspecialchars($user) . '</span>';
	});
	$top(gettext('Busiest clients'), $stats['top_ips'], function ($ip) {
		return '<span class="font-monospace">' . htmlspecialchars($ip) . '</span>';
	});
	$top(gettext('Most failures'), $stats['top_failing'], function ($ip) {
		return '<span class="font-monospace text-danger-emphasis">' . htmlspecialchars($ip) . '</span>';
	});
?>
			</div>
		</div>
	</div>
</div>

<?php if (!empty($listeners)): ?>
<div class="panel panel-default">
	<div class="panel-heading d-flex justify-content-between align-items-center">
		<h2 class="panel-title mb-0"><?=gettext('Listeners')?></h2>
<?php	if ($can_manage): ?>
		<a class="btn btn-sm btn-outline-secondary" href="system_restapi.php?view=listeners"><i class="fa-solid fa-gear icon-embed-btn"></i><?=gettext('Manage')?></a>
<?php	endif; ?>
	</div>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-sm table-striped align-middle mb-0">
				<tbody>
<?php	foreach ($listeners as $l):
		$s = $listener_states[$l['id']] ?? array();
		$state = $l['enable'] ? (string)($s['state'] ?? '') : 'disabled';
		$badge = array('running' => array('text-bg-success', gettext('Running')), 'no_address' => array('text-bg-warning', gettext('Waiting for address')),
		    'error' => array('text-bg-danger', gettext('Error')), 'stopped' => array('text-bg-danger', gettext('Stopped')),
		    'no_certificate' => array('text-bg-danger', gettext('No certificate')), 'disabled' => array('text-bg-secondary', gettext('Disabled')),
		    'api_disabled' => array('text-bg-secondary', gettext('API disabled')))[$state] ?? array('text-bg-secondary', gettext('Not started'));
?>
					<tr>
						<td style="width: 11rem"><span class="badge <?=$badge[0]?>"><?=$badge[1]?></span></td>
						<td><?=htmlspecialchars($listener_names[$l['id']])?></td>
						<td class="font-monospace small"><?=htmlspecialchars(empty($s['addresses']) ? '—' : implode(', ', array_map(function ($a) use ($l) {
							return (is_ipaddrv6($a) ? "[{$a}]" : $a) . ':' . $l['port'];
						}, $s['addresses'])))?></td>
						<td class="small text-muted"><?=$l['readonly'] ? gettext('read-only') : gettext('read/write')?><?=!empty($s['error']) ?
						    ' · <span class="text-danger">' . htmlspecialchars($s['error']) . '</span>' : ''?></td>
					</tr>
<?php	endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>
<?php endif; ?>

<div class="panel panel-default">
	<div class="panel-heading d-flex flex-wrap justify-content-between align-items-center gap-2">
		<h2 class="panel-title mb-0"><?=gettext('Request Log')?> <span class="badge text-bg-secondary ms-1" id="rs-total">…</span></h2>
		<div class="d-flex flex-wrap gap-2">
			<button type="button" class="btn btn-sm btn-outline-secondary" id="rs-live" aria-pressed="false" title="<?=gettext('Refresh the log every 10 seconds')?>">
				<span class="rs-live-dot" aria-hidden="true"></span><?=gettext('Live')?></button>
			<a class="btn btn-sm btn-outline-secondary" id="rs-download" href="status_restapi.php?download=log"><i class="fa-solid fa-download icon-embed-btn"></i><?=gettext('Download')?></a>
<?php	if ($can_manage): ?>
			<a class="btn btn-sm btn-outline-danger do-confirm" href="status_restapi.php?act=clear" usepost title="<?=gettext('Clear the REST API request log')?>">
				<i class="fa-solid fa-trash-can icon-embed-btn"></i><?=gettext('Clear')?></a>
			<a class="btn btn-sm btn-outline-secondary" href="system_restapi.php"><i class="fa-solid fa-sliders icon-embed-btn"></i><?=gettext('Log settings')?></a>
<?php	endif; ?>
		</div>
	</div>
	<div class="panel-body rs-pad pb-2">
		<div class="rs-toolbar" id="rs-filters">
			<div class="input-group input-group-sm rs-search">
				<span class="input-group-text"><i class="fa-solid fa-magnifying-glass"></i></span>
				<input type="search" class="form-control" name="q" placeholder="<?=gettext('Search path, key, user, address or error')?>" aria-label="<?=gettext('Search the log')?>" />
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
		</div>
	</div>
	<div class="table-responsive">
		<table class="table table-sm table-hover align-middle mb-0 rs-log">
			<thead><tr>
				<th><?=gettext('Time')?></th><th><?=gettext('Status')?></th><th><?=gettext('Request')?></th>
				<th><?=gettext('Key / user')?></th><th><?=gettext('Client')?></th><th><?=gettext('Via')?></th><th class="text-end"><?=gettext('Time taken')?></th>
			</tr></thead>
			<tbody id="rs-rows"></tbody>
		</table>
	</div>
	<div class="panel-footer d-flex flex-wrap align-items-center gap-2">
		<span class="small text-muted me-auto" id="rs-shown"></span>
		<span class="small text-muted"><?=htmlspecialchars(sprintf(gettext('%1$s · kept %2$d days, up to %3$d MB'), format_bytes($log_size), $settings['log_days'], $settings['log_mb']))?></span>
		<button type="button" class="btn btn-sm btn-outline-secondary d-none" id="rs-more"><?=gettext('Show older')?></button>
	</div>
</div>

<script type="application/json" id="rs-i18n"><?=json_encode(array(
	'none' => gettext('No request matches the filters.'),
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
	var mclass = {GET: 'text-bg-info', POST: 'text-bg-success', PUT: 'text-bg-warning', PATCH: 'text-bg-secondary', DELETE: 'text-bg-danger'};
	function row(e) {
		var bad = e.s >= 400;
		var tr = el('tr', 'rs-row' + (bad ? ' rs-failed' : ''));
		tr.tabIndex = 0;
		tr.appendChild(el('td', 'rs-time', when(e.t)));
		var st = el('td');
		st.appendChild(el('span', 'badge ' + (e.s >= 500 ? 'text-bg-danger' : (bad ? 'text-bg-warning' : 'text-bg-success')), String(e.s)));
		tr.appendChild(st);
		var req = el('td');
		req.appendChild(el('span', 'badge rs-m me-2 ' + (mclass[e.m] || 'text-bg-secondary'), e.m));
		req.appendChild(el('span', 'rs-path', e.p));
		tr.appendChild(req);
		var who = el('td');
		if (e.k) {
			who.appendChild(el('code', '', T.prefix + '_' + e.k));
			if (e.u) { who.appendChild(el('div', 'small text-muted', e.u)); }
		} else {
			who.appendChild(el('span', 'text-muted', '—'));
		}
		tr.appendChild(who);
		tr.appendChild(el('td', 'font-monospace small', e.ip));
		tr.appendChild(el('td', 'small text-muted', T.listeners[e.l || 'gui'] || e.l));
		tr.appendChild(el('td', 'text-end small text-muted', e.ms + ' ms'));
		function toggle() {
			var next = tr.nextElementSibling;
			if (next && next.classList.contains('rs-detail')) { next.remove(); return; }
			var d = el('tr', 'rs-detail'), td = el('td');
			td.colSpan = 7;
			var dl = el('dl', 'row');
			[[T.code, e.c || '—'], [T.kind, e.w ? T.write : T.read], [T.ua, e.ua || '—']].forEach(function(p) {
				dl.appendChild(el('dt', 'col-sm-2', p[0]));
				dl.appendChild(el('dd', 'col-sm-10 mb-1', p[1]));
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
				var tr = el('tr'), td = el('td', 'text-center text-muted py-4', T.none);
				td.colSpan = 7;
				tr.appendChild(td);
				rows.appendChild(tr);
			}
			total.textContent = data.total;
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
		liveBtn.classList.toggle('btn-outline-success', on);
		liveBtn.classList.toggle('btn-outline-secondary', !on);
		clearInterval(live);
		live = on ? setInterval(function() { load(false); }, 10000) : null;
	});
	load(false);
});
//]]>
</script>
<?php
include("foot.inc");
