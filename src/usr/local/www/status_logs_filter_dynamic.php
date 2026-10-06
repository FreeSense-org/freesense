<?php
/*
 * status_logs_filter_dynamic.php
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
##|*IDENT=page-diagnostics-logs-firewall-dynamic
##|*NAME=Status: System Logs: Firewall (Dynamic View)
##|*DESCR=Allow access to the 'Status: System Logs: Firewall (Dynamic View)' page
##|*MATCH=status_logs_filter_dynamic.php*
##|-PRIV


/* AJAX related routines */
require_once("guiconfig.inc");
require_once("syslog.inc");
handle_ajax();


require_once("status_logs_common.inc");

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


// Log Filter Submit - Firewall
log_filter_form_firewall_submit();


// Manage Log Section - Code
manage_log_code();


// Status Logs Common - Code
status_logs_common_code();


$pgtitle = array(gettext("Status"), gettext("System Logs"), gettext($allowed_logs[$logfile]["name"]), $view_title);
$pglinks = array("", "status_logs.php", "status_logs_filter.php", "@self");

// Force the formatted mode filter and form.  Raw mode is not applicable in the dynamic view.
$rawfilter = false;

// Read the log
system_log_filter();

// Header actions: Log settings (modal) and Clear log
status_logs_page_actions();

include("head.inc");

status_logs_notices();

// Tab Array
tab_array_logs_common();

status_logs_styles();

# Build query string.
$filter_query_string = '';
if ($filterlogentries_submit) {	# Formatted mode.
	$filter_query_string = "type=formatted&filter=" . urlencode(json_encode($filterfieldsarray));
}
if ($filtersubmit) {	# Raw mode.
	$filter_query_string = "type=raw&filter=" . urlencode(json_encode($filtertext)) . "&interfacefilter=" . urlencode(json_encode($interfacefilter));
}

# First get the "General Logging Options" (global) chronological order setting.  Then apply specific log override if set.
$reverse = config_path_enabled('syslog', 'reverse');
$specific_log = basename($logfile, '.log') . '_settings';
if (config_get_path("syslog/{$specific_log}/cronorder") == 'forward') $reverse = false;
if (config_get_path("syslog/{$specific_log}/cronorder") == 'reverse') $reverse = true;

/* badge markup the live rows reuse (cloned in the browser, never parsed from text) */
$dyn_badge = function ($act) {
	return ($act == 'block') ? fs_badge('block') : fs_badge('pass');
};
?>

<div class="panel panel-default fs-table" data-fs-table="firewall-live">
<?php
// Filter toolbar - Firewall (formatted)
filter_form_firewall();
?>
	<div class="fs-dyn-status">
		<span class="fs-dyn-live" id="fs-dyn-state" data-paused-text="<?=fs_h(gettext('Paused'))?>" data-live-text="<?=fs_h(gettext('Live'))?>">
			<i class="fa-solid fa-circle" aria-hidden="true"></i><span><?=gettext('Live')?></span>
		</span>
		<span class="fs-muted"><?=fs_h(sprintf(gettext('New entries are added every %d seconds.'), 25))?></span>
		<span class="fs-toolbar-spacer"></span>
		<button type="button" class="btn btn-sm btn-outline-secondary" id="fs-dyn-pause" aria-pressed="false"
			data-pause-text="<?=fs_h(gettext('Pause'))?>" data-resume-text="<?=fs_h(gettext('Resume'))?>">
			<i class="fa-solid fa-pause icon-embed-btn" aria-hidden="true"></i><span><?=gettext('Pause')?></span>
		</button>
	</div>
	<div class="panel-body table-responsive">
		<table class="table table-hover fs-fwlog" data-sortable>
			<thead>
				<tr>
					<th><?=gettext("Action")?></th>
					<th><?=gettext("Time")?></th>
					<th data-fs-search><?=gettext("Interface")?></th>
					<th data-fs-search><?=gettext("Source")?></th>
					<th data-fs-search><?=gettext("Destination")?></th>
					<th data-fs-search><?=gettext("Protocol")?></th>
				</tr>
			</thead>
			<tbody id="filter-log-entries">
<?php
	foreach ($filterlog as $filterent) {
		if ($filterent['version'] == '6') {
			$srcIP = "[" . $filterent['srcip'] . "]";
			$dstIP = "[" . $filterent['dstip'] . "]";
		} else {
			$srcIP = $filterent['srcip'];
			$dstIP = $filterent['dstip'];
		}
		$srcPort = $filterent['srcport'] ? ":" . $filterent['srcport'] : "";
		$dstPort = $filterent['dstport'] ? ":" . $filterent['dstport'] : "";
		$proto = $filterent['proto'];
		if ($proto == "TCP") {
			$proto .= ":{$filterent['tcpflags']}";
		}
		$rulenum = "{$filterent['rulenum']},{$filterent['subrulenum']},{$filterent['tracker']},{$filterent['act']}";
?>
				<tr>
					<td><button type="button" class="fs-fw-act" data-fs-rule="<?=fs_h($rulenum)?>"
						title="<?=fs_h("{$filterent['act']}/{$filterent['reason']}/{$filterent['tracker']}")?>"
						aria-label="<?=fs_h(sprintf(gettext('Rule details (%s)'), $filterent['act']))?>"><?=$dyn_badge($filterent['act'])?></button></td>
					<?=status_logs_time_cell($filterent['time'])?>
					<td><?=htmlspecialchars($filterent['interface'])?></td>
					<td class="fs-mono fs-fw-addr"><?=htmlspecialchars($srcIP . $srcPort)?></td>
					<td class="fs-mono fs-fw-addr"><?=htmlspecialchars($dstIP . $dstPort)?></td>
					<td class="fs-mono text-nowrap"><?=htmlspecialchars($proto)?></td>
				</tr>
<?php
	}

	if (count($filterlog) == 0) {
		fs_empty_row(6, gettext('No log entries to display yet. New entries appear here as they are logged.'));
	}
?>
			</tbody>
		</table>
	</div>
<?php status_logs_card_footer(); ?>
</div>

<template id="fs-dyn-badge-pass"><?=$dyn_badge('pass')?></template>
<template id="fs-dyn-badge-block"><?=$dyn_badge('block')?></template>

<div class="modal fade" id="fs-dyn-rule" tabindex="-1" aria-labelledby="fs-dyn-rule-title" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
		<div class="modal-header">
			<h2 class="modal-title" id="fs-dyn-rule-title"><?=gettext('Rule details')?></h2>
			<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?=gettext('Close')?>"></button>
		</div>
		<pre class="fs-console" id="fs-dyn-rule-text"></pre>
		<div class="modal-footer">
			<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?=gettext('Close')?></button>
		</div>
	</div></div>
</div>

<style>
.fs-fwlog > tbody > tr > td { height: 2rem; padding-top: .3rem; padding-bottom: .3rem; }
.fs-fw-act { padding: 0; border: 0; background: none; cursor: pointer; }
.fs-fw-act:focus-visible { outline: 2px solid var(--fs-coral); outline-offset: 2px; border-radius: 999px; }
.fs-fw-addr { white-space: nowrap; font-size: var(--fs-fs-sm); }
.fs-dyn-status { display: flex; flex-wrap: wrap; align-items: center; gap: var(--fs-sp-2) var(--fs-sp-3); padding: var(--fs-sp-2) var(--fs-sp-4); border-bottom: 1px solid var(--fs-border); font-size: var(--fs-fs-sm); }
.fs-dyn-live { display: inline-flex; align-items: center; gap: .4rem; font-weight: 600; color: var(--fs-pass); }
.fs-dyn-live > i { font-size: .55rem; }
.fs-dyn-live.is-paused { color: var(--fs-text-muted); }
@media (prefers-reduced-motion: no-preference) {
	.fs-dyn-live:not(.is-paused) > i { animation: fs-dyn-pulse 2s ease-in-out infinite; }
	@keyframes fs-dyn-pulse { 50% { opacity: .35; } }
}
#fs-dyn-rule .fs-console { border-radius: 0; }
</style>

<?php
// Log settings modal
manage_log_section();
?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	var lastsawtime = '<?=time()?>';
	var updateDelay = 25500;
	var isBusy = false;
	var isPaused = false;
	var nentries = <?=(int)$nentries?>;
	var isReverse = <?=$reverse ? 'true' : 'false'?>;
	var filter_query_string = <?=json_encode($filter_query_string . '&logfile=' . $logfile_path . '&nentries=' . $nentries)?>;
	var tbody = document.getElementById('filter-log-entries');
	var search = document.querySelector('[data-fs-table="firewall-live"] [data-fs-search-input]');

	function cell(text, cls) {
		var td = document.createElement('td');
		if (cls) {
			td.className = cls;
		}
		td.textContent = text;
		return td;
	}

	/* One row from the AJAX line "button||time||if||src||sport||dst||dport||proto||ver||now||".
	 * The first field is legacy markup: only the action and the rule lookup are read from it. */
	function buildRow(row) {
		var title = (/title="([^"]*)"/.exec(row[0]) || [])[1] || '';
		var rule = (/getrulenum=([^']*)'/.exec(row[0]) || [])[1] || '';
		var act = title.split('/')[0] === 'block' ? 'block' : 'pass';
		var v6 = (row[8] === '6');
		var src = (v6 ? '[' + row[3] + ']' : row[3]) + (row[4] ? ':' + row[4] : '');
		var dst = (v6 ? '[' + row[5] + ']' : row[5]) + (row[6] ? ':' + row[6] : '');

		var tr = document.createElement('tr');
		var td = document.createElement('td');
		var btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'fs-fw-act';
		btn.title = title;
		btn.setAttribute('data-fs-rule', rule);
		btn.setAttribute('aria-label', <?=json_encode(gettext('Rule details'))?> + ' (' + act + ')');
		btn.appendChild(document.getElementById('fs-dyn-badge-' + act).content.cloneNode(true));
		td.appendChild(btn);
		tr.appendChild(td);
		tr.appendChild(cell(row[1].replace('T', ' '), 'fs-log-time'));
		tr.appendChild(cell(row[2]));
		tr.appendChild(cell(src, 'fs-mono fs-fw-addr'));
		tr.appendChild(cell(dst, 'fs-mono fs-fw-addr'));
		tr.appendChild(cell(row[7], 'fs-mono text-nowrap'));
		return tr;
	}

	function addRows(rows) {
		if (!rows.length) {
			return;
		}
		var empty = tbody.querySelector('tr.fs-empty');
		if (empty) {
			empty.remove();
		}
		rows = rows.slice(Math.max(0, rows.length - nentries));
		rows.forEach(function (tr) {
			if (isReverse) {
				tbody.insertBefore(tr, tbody.firstChild);
			} else {
				tbody.appendChild(tr);
			}
		});
		while (tbody.rows.length > nentries) {
			tbody.removeChild(isReverse ? tbody.lastElementChild : tbody.firstElementChild);
		}
		/* let the table enhancer re-apply the quick search and recount */
		if (search) {
			search.dispatchEvent(new Event('input'));
		}
	}

	function fetchNewRules() {
		if (isPaused || isBusy) {
			return;
		}
		isBusy = true;
		fetch('status_logs_filter_dynamic.php?' + filter_query_string + '&lastsawtime=' + encodeURIComponent(lastsawtime), {credentials: 'same-origin'})
		    .then(function (r) { return r.text(); })
		    .then(function (data) {
			var rows = [];
			data.split('\n').forEach(function (line) {
				if (line === '') {
					return;
				}
				var parts = line.split('||');
				if (parts.length < 10) {
					return;
				}
				lastsawtime = parts[9];
				rows.push(buildRow(parts));
			});
			if (!isPaused) {
				addRows(rows);
			}
		    })
		    .catch(function () {})
		    .then(function () { isBusy = false; });
	}

	setInterval(fetchNewRules, updateDelay);

	/* Pause / resume */
	var pause = document.getElementById('fs-dyn-pause');
	var state = document.getElementById('fs-dyn-state');
	pause.addEventListener('click', function () {
		isPaused = !isPaused;
		pause.setAttribute('aria-pressed', isPaused ? 'true' : 'false');
		pause.querySelector('i').className = 'fa-solid ' + (isPaused ? 'fa-play' : 'fa-pause') + ' icon-embed-btn';
		pause.querySelector('span').textContent = pause.getAttribute(isPaused ? 'data-resume-text' : 'data-pause-text');
		state.classList.toggle('is-paused', isPaused);
		state.querySelector('span').textContent = state.getAttribute(isPaused ? 'data-paused-text' : 'data-live-text');
		if (!isPaused) {
			fetchNewRules();
		}
	});

	/* Rule details for an entry */
	var ruleModal = document.getElementById('fs-dyn-rule');
	var ruleText = document.getElementById('fs-dyn-rule-text');
	tbody.addEventListener('click', function (e) {
		var btn = e.target.closest('[data-fs-rule]');
		if (!btn) {
			return;
		}
		ruleText.textContent = <?=json_encode(gettext('Loading…'))?>;
		bootstrap.Modal.getOrCreateInstance(ruleModal).show();
		fetch('status_logs_filter.php?getrulenum=' + encodeURIComponent(btn.getAttribute('data-fs-rule')), {credentials: 'same-origin'})
		    .then(function (r) { return r.text(); })
		    .then(function (text) { ruleText.textContent = text; })
		    .catch(function () { ruleText.textContent = <?=json_encode(gettext('The rule could not be loaded.'))?>; });
	});
});
//]]>
</script>

<?php include("foot.inc");
