<?php
/*
 * status_graph.php
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
##|*IDENT=page-status-trafficgraph
##|*NAME=Status: Traffic Graph
##|*DESCR=Allow access to the 'Status: Traffic Graph' page.
##|*MATCH=status_graph.php*
##|*MATCH=bandwidth_by_ip.php*
##|*MATCH=graph.php*
##|*MATCH=ifstats.php*
##|-PRIV

require_once('config.inc');
require_once('config.lib.inc');
require_once('guiconfig.inc');
require_once('ipsec.inc');


$pconfig = config_get_path('traffic_graphs');

// Get configured interface list
$ifdescrs = get_configured_interface_with_descr();
if (ipsec_enabled()) {
	$ifdescrs['enc0'] = gettext("IPsec");
}

foreach (array('server', 'client') as $mode) {
	foreach (config_get_path("openvpn/openvpn-{$mode}", []) as $setting) {
		if (isset($setting['disable'])) {
			continue;
		}
		$ifdescrs['ovpn' . substr($mode, 0, 1) . $setting['vpnid']] =
		    gettext("OpenVPN") . " " . $mode . ": " .
		    htmlspecialchars($setting['description']);
	}
}

$ifdescrs = array_merge($ifdescrs, interface_ipsec_vti_list_all());

if (!empty($_POST)) {
	// update view if settings are changed or saved
	$curif = $_POST['if'];
	$found = false;
	foreach ($ifdescrs as $descr => $ifdescr) {
		if ($descr == $curif) {
			$found = true;
			break;
		}
	}
	if ($found === false) {
		header("Location: status_graph.php");
		exit;
	}
	/* Only accept the values offered by the form. */
	$pick = function ($value, $allowed) {
		return in_array($value, $allowed, true) ? $value : $allowed[0];
	};
	$cursort = $pick($_POST['sort'], ['in', 'out']);
	$curfilter = $pick($_POST['filter'], ['local', 'remote', 'all']);
	$curhostipformat = $pick($_POST['hostipformat'], ['', 'hostname', 'descr', 'fqdn']);
	$curbackgroundupdate = $pick($_POST['backgroundupdate'], ['false', 'true']);
	$curinvert = $pick($_POST['invert'], ['true', 'false']);
	$cursmoothing = $pick($_POST['smoothfactor'], ['0', '1', '2', '3', '4', '5']);
	$curmode = $pick($_POST['mode'], ['rate', 'iftop']);

	// Save data to config
	if (isset($_POST['save'])) {
		$pconfig = array();
		$pconfig["if"] = $curif;
		$pconfig["sort"] = $cursort;
		$pconfig["filter"] = $curfilter;
		$pconfig["hostipformat"] = $curhostipformat;
		$pconfig["backgroundupdate"] = $curbackgroundupdate;
		$pconfig["smoothfactor"] = $cursmoothing;
		$pconfig["invert"] = $curinvert;
		$pconfig["mode"] = $curmode;
		config_set_path("traffic_graphs", $pconfig);
		write_config("Traffic Graphs settings updated");
	}
} else {
	// default settings from config
	if (is_array($pconfig)) {
		$curif = $pconfig['if'];
		$cursort = $pconfig['sort'];
		$curfilter = $pconfig['filter'];
		$curhostipformat = $pconfig['hostipformat'];
		$curbackgroundupdate = $pconfig['backgroundupdate'];
		$cursmoothing = $pconfig['smoothfactor'];
		$curinvert = $pconfig['invert'];
		$curmode = $pconfig['mode'];;
	} else {
		// initialize when no config details are present
		if (empty($ifdescrs["wan"])) {
			/*
			 * Handle the case when WAN has been disabled. Use the
			 * first key in ifdescrs.
			 */
			reset($ifdescrs);
			$curif = key($ifdescrs);
		} else {
			$curif = "wan";
		}
		$cursort = "";
		$curfilter = "";
		$curhostipformat = "";
		$curbackgroundupdate = "";
		$cursmoothing = 0;
		$curinvert = "";
		$curmode = "";
	}
}

function iflist() {
	global $ifdescrs;

	$iflist = array();

	foreach ($ifdescrs as $ifn => $ifd) {
		$iflist[$ifn] = $ifd;
	}

	return($iflist);
}

$pgtitle = array(gettext("Status"), gettext("Traffic Graph"));

include("head.inc");

$realif = get_real_interface($curif);

/* the controls post back to this page (and feed bandwidth_by_ip.php) */
$graph_selects = [
	'if' => [gettext('Interface'), $curif, iflist()],
	'sort' => [gettext('Sort by'), $cursort, [
		'in' => gettext('Bandwidth in'),
		'out' => gettext('Bandwidth out'),
	]],
	'filter' => [gettext('Filter'), $curfilter, [
		'local' => gettext('Local'),
		'remote' => gettext('Remote'),
		'all' => gettext('All'),
	]],
	'hostipformat' => [gettext('Display'), $curhostipformat, [
		'' => gettext('IP address'),
		'hostname' => gettext('Host name'),
		'descr' => gettext('Description'),
		'fqdn' => gettext('FQDN'),
	]],
	'invert' => [gettext('Invert in/out'), $curinvert, [
		'true' => gettext('On'),
		'false' => gettext('Off'),
	]],
];
$graph_more = [
	'mode' => [gettext('Mode'), $curmode, [
		'rate' => gettext('Rate (standard)'),
		'iftop' => gettext('iftop (experimental)'),
	]],
	'backgroundupdate' => [gettext('Background updates'), $curbackgroundupdate, [
		'false' => gettext('Clear graphs when not visible'),
		'true' => gettext('Keep graphs updated on inactive tab (more CPU)'),
	]],
];
$more_open = (($curmode === 'iftop') || ($curbackgroundupdate === 'true') || (intval($cursmoothing) > 0));
$if_label = $ifdescrs[$curif] ?? $curif;
?>

<style>
.fs-graph-controls { display: flex; flex-wrap: wrap; align-items: flex-end; gap: var(--fs-sp-3); padding: var(--fs-sp-3) var(--fs-sp-4); border-bottom: 1px solid var(--fs-border); }
.fs-graph-controls > div { display: flex; flex-direction: column; gap: var(--fs-sp-1); min-width: 0; }
.fs-graph-controls label { margin: 0; color: var(--fs-text-muted); font-size: var(--fs-fs-xs); font-weight: 500; }
.fs-graph-controls .form-select { width: auto; max-width: 100%; }
.fs-graph-controls .fs-toolbar-spacer { flex: 1 1 auto; }
.fs-graph-controls .btn { white-space: nowrap; }
.fs-graph-more { padding: var(--fs-sp-3) var(--fs-sp-4); border-bottom: 1px solid var(--fs-border); background: var(--fs-surface-raised); }
.fs-graph-more .fs-graph-controls { padding: 0; border: 0; }
.fs-graph-range { display: flex; align-items: center; gap: var(--fs-sp-2); min-height: calc(1.5em + .5rem + 2px); }
.fs-graph-range input { width: 9rem; }
.fs-graph-range output { min-width: 1.5em; font-variant-numeric: tabular-nums; }
.fs-graph-title { display: flex; flex-wrap: wrap; align-items: baseline; gap: var(--fs-sp-2); }
.fs-graph-live { color: var(--fs-text-muted); font-size: var(--fs-fs-xs); font-weight: 400; }
.fs-graph-live i { color: var(--fs-pass); font-size: .6em; vertical-align: middle; }
.fs-graph-body { padding: var(--fs-sp-2) var(--fs-sp-3) var(--fs-sp-3); }
/* the card heading names the interface: hide the label nvd3 draws in the svg */
.fs-graph-body .interface-label { display: none; }
.fs-graph-body .traffic-widget-chart, .fs-graph-body .traffic-widget-chart svg { height: 320px; padding: 0; border: 0; }
.fs-graph-body .nvd3 .nv-axis .tick line { stroke: var(--fs-border) !important; stroke-opacity: .6; }
.fs-graph-body .nvd3 .nv-axis .nv-axisMaxMin text, .fs-graph-body .nvd3 .nv-axis .tick text { fill: var(--fs-text-muted) !important; font-size: 11px; }
.fs-graph-body .nvd3 .nv-legend-text { fill: var(--bs-body-color) !important; font-size: 12px; }
.fs-graph-body .nvd3 .nv-guideline line { stroke: var(--fs-text-muted); }
.fs-graph-body .nvd3 .nv-groups path.nv-line { stroke-width: 1.75px; }
#top10-hosts td:not(:first-child), .fs-graph-hosts th:not(:first-child) { text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
@media (max-width: 575px) {
	.fs-graph-controls > div { flex: 1 1 calc(50% - var(--fs-sp-3)); }
	.fs-graph-controls .form-select { width: 100%; }
	.fs-graph-body .traffic-widget-chart, .fs-graph-body .traffic-widget-chart svg { height: 240px; }
}
</style>

<form method="post" action="status_graph.php" id="traffic-graph-form" class="auto-submit">
<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title fs-graph-title">
			<?=htmlspecialchars($if_label)?>
			<span class="fs-graph-live"><i class="fa-solid fa-circle" aria-hidden="true"></i> <?=gettext('Live, updates every second')?></span>
		</h2>
	</div>
	<div class="fs-graph-controls">
<?php foreach ($graph_selects as $name => list($label, $value, $choices)): ?>
		<div>
			<label for="<?=htmlspecialchars($name)?>"><?=htmlspecialchars($label)?></label>
			<select class="form-select form-select-sm" id="<?=htmlspecialchars($name)?>" name="<?=htmlspecialchars($name)?>">
<?php foreach ($choices as $k => $v): ?>
				<option value="<?=htmlspecialchars($k)?>"<?=((string)$k === (string)$value) ? ' selected' : ''?>><?=htmlspecialchars($v)?></option>
<?php endforeach; ?>
			</select>
		</div>
<?php endforeach; ?>
		<span class="fs-toolbar-spacer"></span>
		<div>
			<button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#graph-more" aria-expanded="<?=$more_open ? 'true' : 'false'?>" aria-controls="graph-more">
				<i class="fa-solid fa-sliders icon-embed-btn" aria-hidden="true"></i><?=gettext('More options')?>
			</button>
		</div>
		<div>
			<button type="submit" class="btn btn-sm btn-primary" name="save" value="Save" title="<?=gettext('Use these settings every time the page opens')?>">
				<i class="fa-solid fa-floppy-disk icon-embed-btn" aria-hidden="true"></i><?=gettext('Save as default')?>
			</button>
		</div>
	</div>
	<div class="collapse<?=$more_open ? ' show' : ''?>" id="graph-more">
		<div class="fs-graph-more">
			<div class="fs-graph-controls">
<?php foreach ($graph_more as $name => list($label, $value, $choices)): ?>
				<div>
					<label for="<?=htmlspecialchars($name)?>"><?=htmlspecialchars($label)?></label>
					<select class="form-select form-select-sm" id="<?=htmlspecialchars($name)?>" name="<?=htmlspecialchars($name)?>">
<?php foreach ($choices as $k => $v): ?>
						<option value="<?=htmlspecialchars($k)?>"<?=((string)$k === (string)$value) ? ' selected' : ''?>><?=htmlspecialchars($v)?></option>
<?php endforeach; ?>
					</select>
				</div>
<?php endforeach; ?>
				<div>
					<label for="smoothfactor"><?=gettext('Graph smoothing')?></label>
					<div class="fs-graph-range">
						<input type="range" class="form-range" id="smoothfactor" name="smoothfactor" min="0" max="5" step="1" value="<?=htmlspecialchars((string)intval($cursmoothing))?>">
						<output for="smoothfactor" id="smoothfactor-value"><?=htmlspecialchars((string)intval($cursmoothing))?></output>
					</div>
				</div>
			</div>
		</div>
	</div>
	<div class="fs-graph-body">
		<div id="traffic-chart-error" class="alert alert-danger" style="display: none;" role="alert"></div>
		<div id="traffic-chart-<?=htmlspecialchars($curif)?>" class="d3-chart traffic-widget-chart" data-if="<?=htmlspecialchars($curif)?>" data-realif="<?=htmlspecialchars($realif)?>">
			<svg role="img" aria-label="<?=htmlspecialchars(sprintf(gettext('Traffic on %s'), $if_label))?>"></svg>
		</div>
	</div>
</div>
</form>

<div class="panel panel-default fs-table fs-graph-hosts">
	<div class="fs-toolbar">
		<div class="fs-toolbar-default">
			<h2 class="fs-toolbar-title"><?=gettext('Top hosts')?></h2>
			<span class="fs-toolbar-spacer"></span>
			<span class="fs-toolbar-count" id="top10-count" aria-live="polite"></span>
		</div>
	</div>
	<div class="panel-body table-responsive">
		<table class="table table-hover">
			<thead>
				<tr>
					<th><?=(($curhostipformat == "") ? gettext("Host IP") : gettext("Host Name or IP")); ?></th>
					<th><?=gettext("Bandwidth In"); ?></th>
					<th><?=gettext("Bandwidth Out"); ?></th>
				</tr>
			</thead>
			<tbody id="top10-hosts">
				<tr class="fs-empty"><td colspan="3"><span class="fs-empty-message"><?=gettext('Collecting data…')?></span></td></tr>
			</tbody>
		</table>
	</div>
</div>

<script src="/vendor/d3/d3.min.js?v=<?=filemtime('/usr/local/www/vendor/d3/d3.min.js')?>"></script>
<script src="/vendor/nvd3/nv.d3.min.js?v=<?=filemtime('/usr/local/www/vendor/nvd3/nv.d3.min.js')?>"></script>
<script src="/vendor/visibility/visibility-2.0.2.js?v=<?=filemtime('/usr/local/www/vendor/visibility/visibility-2.0.2.js')?>"></script>

<link href="/vendor/nvd3/nv.d3.min.css" media="screen, projection" rel="stylesheet" type="text/css">

<script type="text/javascript">
//<![CDATA[
events.push(function() {

	var chartEl = $('.fs-graph-body .traffic-widget-chart');
	var InterfaceString = String(chartEl.attr('data-if') || '');
	var RealInterfaceString = String(chartEl.attr('data-realif') || '');
	window.graph_backgroundupdate = $('#backgroundupdate').val() === "true";
	window.smoothing = $('#smoothfactor').val();
	window.interval = 1;
	window.invert = $('#invert').val() === "true";
	window.size = 8;
	window.interfaces = InterfaceString.split("|").filter(function(entry) { return entry.trim() != ''; });
	window.realinterfaces = RealInterfaceString.split("|").filter(function(entry) { return entry.trim() != ''; });

	graph_init();
	graph_visibilitycheck();

});
//]]>
</script>

<script src="/js/traffic-graphs.js?v=<?=filemtime('/usr/local/www/js/traffic-graphs.js')?>"></script>

<script type="text/javascript">
//<![CDATA[

var graph_interfacenames = <?php
	$iflist = array();
	foreach ($ifdescrs as $ifname => $ifdescr) {
		$iflist[$ifname] = $ifdescr;
	}
	echo json_encode($iflist, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>;

var topHostsText = {
	unit: <?=json_encode(gettext("Bits/sec"))?>,
	none: <?=json_encode(gettext('No traffic on this interface right now.'))?>,
	one: <?=json_encode(gettext('%s host'))?>,
	many: <?=json_encode(gettext('%s hosts'))?>
};

function updateBandwidth() {
	$.ajax(
		'/bandwidth_by_ip.php',
		{
			type: 'post',
			data: $('#traffic-graph-form').serialize(),
			success: function (data) {
				var hosts_split = data.split("|");
				var body = $('#top10-hosts');
				var shown = 0;

				body.empty();

				//parse top ten bandwidth abuser hosts
				for (var y=0; y<10; y++) {
					if ((y < hosts_split.length) && (hosts_split[y] != "") && (hosts_split[y] != "no info")) {
						var hostinfo = hosts_split[y].split(";");

						// Host names and descriptions come from DNS and DHCP data; insert them as text.
						body.append($('<tr>').append(
							$('<td class="fs-mono">').text(hostinfo[0]),
							$('<td>').text(hostinfo[1] + ' ' + topHostsText.unit),
							$('<td>').text(hostinfo[2] + ' ' + topHostsText.unit)
						));
						shown++;
					}
				}

				if (shown === 0) {
					body.append($('<tr class="fs-empty">').append(
						$('<td colspan="3">').append($('<span class="fs-empty-message">').text(topHostsText.none))
					));
				}
				$('#top10-count').text((shown === 1 ? topHostsText.one : topHostsText.many).replace('%s', shown));
			},
	});
}

events.push(function() {
	$('form.auto-submit').on('change', function() {
		$(this).submit();
	});

	$('#smoothfactor').on('input', function() {
		$('#smoothfactor-value').text(this.value);
	});

	setInterval(updateBandwidth, 3000);

	updateBandwidth();
});
//]]>
</script>
<?php include("foot.inc");
