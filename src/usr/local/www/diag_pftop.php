<?php
/*
 * diag_pftop.php
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
##|*IDENT=page-diagnostics-system-pftop
##|*NAME=Diagnostics: pfTop
##|*DESCR=Allows access to the 'Diagnostics: pfTop' page
##|*MATCH=diag_pftop.php*
##|-PRIV

require_once("guiconfig.inc");

$pgtitle = array(gettext("Diagnostics"), gettext("pfTop"));
$pftop = "/usr/local/sbin/pftop";

$sorttypes = array('age', 'bytes', 'dest', 'dport', 'exp', 'none', 'pkt', 'sport', 'src');
$viewtypes = array('default', 'label', 'long', 'queue', 'rules', 'size', 'speed', 'state', 'time');
$viewall = array('queue', 'label', 'rules');
$numstates = array('50', '100', '200', '500', '1000', 'all');

if ($_REQUEST['getactivity']) {
	if ($_REQUEST['sorttype'] && in_array($_REQUEST['sorttype'], $sorttypes) &&
	    $_REQUEST['viewtype'] && in_array($_REQUEST['viewtype'], $viewtypes) &&
	    $_REQUEST['states'] && in_array($_REQUEST['states'], $numstates)) {
		$viewtype = escapeshellarg($_REQUEST['viewtype']);
		if (in_array($_REQUEST['viewtype'], $viewall)) {
			$sorttype = "";
			$numstate = "-a";
		} else {
			$sorttype = "-o " . escapeshellarg($_REQUEST['sorttype']);
			$numstate = ($_REQUEST['states'] == "all" ? "-a" : escapeshellarg($_REQUEST['states']));
		}
	} else {
		$sorttype = "bytes";
		$viewtype = "default";
		$numstate = "100";
	}
	if ($_REQUEST['filter'] != "") {
		$filter = "-f " . escapeshellarg($_REQUEST['filter']);
	} else {
		$filter = "";
	}
	$text = shell_exec("$pftop {$filter} -b {$sorttype} -w 135 -v {$viewtype} {$numstate}");
	if (empty($text)) {
		echo "Invalid filter, check syntax";
	} else {
		echo trim(htmlentities($text));
	}
	exit;
}

include("head.inc");

/* The current choices (validated; the defaults of pftop otherwise) */
$cur_view = in_array($_REQUEST['viewtype'] ?? '', $viewtypes) ? $_REQUEST['viewtype'] : 'default';
$cur_sort = in_array($_REQUEST['sorttype'] ?? '', $sorttypes) ? $_REQUEST['sorttype'] : 'bytes';
$cur_states = in_array($_REQUEST['states'] ?? '', $numstates) ? $_REQUEST['states'] : '100';
$cur_filter = (string)($_REQUEST['filter'] ?? '');

if ($input_errors) {
	print_input_errors($input_errors);
}

$validViews = array(
	'default' => gettext('Default'),
	'label' => gettext('Label'),
	'long' => gettext('Long'),
	'queue' => gettext('Queue'),
	'rules' => gettext('Rules'),
	'size' => gettext('Size'),
	'speed' => gettext('Speed'),
	'state' => gettext('State'),
	'time' => gettext('Time'),
);
$validSorts = array(
	'none' => gettext('None'),
	'age' => gettext('Age'),
	'bytes' => gettext('Bytes'),
	'dest' => gettext('Destination address'),
	'dport' => gettext('Destination port'),
	'exp' => gettext('Expiry'),
	'pkt' => gettext('Packets'),
	'sport' => gettext('Source port'),
	'src' => gettext('Source address'),
);
$validStates = array(50, 100, 200, 500, 1000, 'all');
?>

<style>
.fs-pftop-head { display: flex; flex-wrap: wrap; align-items: center; gap: var(--fs-sp-3); }
.fs-pftop-head .form-switch { margin: 0; font-size: var(--fs-fs-sm); }
#xhrOutput { white-space: pre; word-break: normal; }
.fs-pftop-syntax code { font-size: var(--fs-fs-xs); }
.fs-pftop-syntax summary { cursor: pointer; }
</style>

<div class="fs-tool">
	<form method="post" action="diag_pftop.php" class="fs-tool-form" id="pftop-form">
		<input type="hidden" name="getactivity" value="yes">
		<div class="panel panel-default">
			<div class="panel-heading"><h2 class="panel-title"><?=gettext('Options')?></h2></div>
			<div class="panel-body">
				<div>
					<label class="form-label" for="viewtype"><?=gettext('View')?></label>
					<select class="form-select" id="viewtype" name="viewtype">
<?php foreach ($validViews as $k => $v): ?>
						<option value="<?=$k?>"<?=($cur_view == $k) ? ' selected' : ''?>><?=htmlspecialchars($v)?></option>
<?php endforeach; ?>
					</select>
					<div class="form-text"><?=gettext('Queue, label and rules views always show every entry.')?></div>
				</div>
				<div data-pftop-states-only>
					<label class="form-label" for="filter"><?=gettext('Filter expression')?></label>
					<input class="form-control fs-mono" type="text" id="filter" name="filter" value="<?=htmlspecialchars($cur_filter)?>" placeholder="<?=gettext('e.g. tcp, ip6 or dst net 192.0.2.0/24')?>" autocomplete="off">
					<details class="form-text fs-pftop-syntax">
						<summary><?=gettext('Filter syntax')?></summary>
						<code>[proto &lt;ip|ip6|ah|carp|esp|icmp|ipv6-icmp|pfsync|tcp|udp&gt;]</code><br>
						<code>[src|dst|gw] [host|net|port] &lt;host/network/port&gt;</code><br>
						<code>[in|out]</code><br>
						<?=sprintf(gettext('Combine expressions with "and" / "or". See %s for the full syntax.'), '<a target="_blank" rel="noopener" href="https://www.freebsd.org/cgi/man.cgi?query=pftop#STATE_FILTERING">pftop(8)</a>')?>
					</details>
				</div>
				<div class="fs-tool-row" data-pftop-states-only>
					<div>
						<label class="form-label" for="sorttype"><?=gettext('Sort by')?></label>
						<select class="form-select" id="sorttype" name="sorttype">
<?php foreach ($validSorts as $k => $v): ?>
							<option value="<?=$k?>"<?=($cur_sort == $k) ? ' selected' : ''?>><?=htmlspecialchars($v)?></option>
<?php endforeach; ?>
						</select>
					</div>
					<div>
						<label class="form-label" for="states"><?=gettext('Maximum states')?></label>
						<select class="form-select" id="states" name="states">
<?php foreach ($validStates as $n): ?>
							<option value="<?=$n?>"<?=($cur_states == $n) ? ' selected' : ''?>><?=($n === 'all') ? gettext('All') : $n?></option>
<?php endforeach; ?>
						</select>
					</div>
				</div>
			</div>
			<div class="panel-footer small fs-muted">
				<i class="fa-solid fa-circle-info" aria-hidden="true"></i>
				<?=gettext('The output refreshes every 2.5 seconds and follows these options right away.')?>
			</div>
		</div>
	</form>

	<div class="panel panel-default">
		<div class="panel-heading">
			<h2 class="panel-title"><?=gettext('Output')?></h2>
			<div class="fs-pftop-head">
				<div class="form-check form-switch">
					<input class="form-check-input" type="checkbox" role="switch" id="refresh" checked>
					<label class="form-check-label" for="refresh"><?=gettext('Refresh automatically')?></label>
				</div>
				<button type="button" class="btn btn-sm btn-outline-secondary" data-fs-copy="#xhrOutput">
					<i class="fa-regular fa-copy icon-embed-btn" aria-hidden="true"></i><?=gettext('Copy')?>
				</button>
			</div>
		</div>
		<pre class="fs-console" id="xhrOutput" aria-live="off"><?=gettext("Gathering pfTOP activity, please wait...")?></pre>
	</div>
</div>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	var busy = false;
	var form = document.getElementById('pftop-form');

	// The page returns the pftop text HTML-escaped; show it as plain text.
	function show(data) {
		var entities = {'&amp;': '&', '&lt;': '<', '&gt;': '>', '&quot;': '"', '&#039;': "'"};
		document.getElementById('xhrOutput').textContent = String(data).replace(/&(amp|lt|gt|quot|#039);/g, function (m) {
			return entities[m];
		});
	}

	function getpftopactivity(force) {
		if (busy || (!force && (document.hidden || !document.getElementById('refresh').checked))) {
			return;
		}
		busy = true;
		$.ajax('/diag_pftop.php', {
			method: 'post',
			data: $(form).serialize(),
			dataType: 'text'
		}).done(show).always(function () {
			busy = false;
		});
	}

	// Sort, filter and state limit do not apply to the queue, label and rules views
	function toggleOptions() {
		var all = ['queue', 'label', 'rules'].indexOf($('#viewtype').val()) > -1;
		$('[data-pftop-states-only]').prop('hidden', all);
	}

	$('#viewtype').on('change', toggleOptions);
	$('#viewtype, #sorttype, #states').on('change', function () {
		getpftopactivity(true);
	});
	$('#filter').on('keydown', function (event) {
		if (event.key === 'Enter') {
			event.preventDefault();
			getpftopactivity(true);
		}
	});
	$(form).on('submit', function (event) {
		event.preventDefault();
		getpftopactivity(true);
	});
	$('#refresh').on('change', function () {
		if (this.checked) {
			getpftopactivity(true);
		}
	});

	toggleOptions();
	getpftopactivity(true);
	setInterval(getpftopactivity, 2500);
});
//]]>
</script>
<?php include("foot.inc");
