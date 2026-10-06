<?php
/*
 * status_ntpd.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
 * Copyright (c) 2013 Dagorlad
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
##|*IDENT=page-status-ntp
##|*NAME=Status: NTP
##|*DESCR=Allow access to the 'Status: NTP' page.
##|*MATCH=status_ntpd.php*
##|-PRIV

require_once('config.inc');
require_once('config.lib.inc');
require_once('guiconfig.inc');

$allow_query = !config_path_enabled('ntpd','noquery');
foreach (config_get_path('ntpd/restrictions/row', []) as $v) {
	if (ip_in_subnet('127.0.0.1', "{$v['acl_network']}/{$v['mask']}") || 
		ip_in_subnet('::1', "{$v['acl_network']}/{$v['mask']}")) {
		$allow_query = !isset($v['noquery']);
	}
}

if ($allow_query && (config_get_path('ntpd/enable') != 'disabled')) {
	if (config_path_enabled('system','ipv6allow')) {
		$inet_version = "";
	} else {
		$inet_version = " -4";
	}

	exec('/usr/local/sbin/ntpq -pnw' . $inet_version . ' | /usr/bin/tail +3 | /usr/bin/awk -v RS= \'{gsub(/\n[[:space:]][[:space:]]+/," ")}1\'', $ntpq_output);
	exec('/usr/local/sbin/ntpq -c associations' . $inet_version . ' | /usr/bin/tail +3 | /usr/bin/awk -v RS= \'{gsub(/\n[[:space:]][[:space:]]\n+/," ")}1\'', $ntpq_associations_output);

	$ntpq_servers = array();
	$ntpq_server_responses = array();

	foreach ($ntpq_associations_output as $i => $line) {
		$associations_response = array();
		$peerinfo = preg_split("/[\s\t]+/", $line);
		$server['ind'] = $peerinfo[1];
		$associations_response['assid'] = $peerinfo[2];
		$associations_response['status_word'] = $peerinfo[3];
		$associations_response['conf'] = $peerinfo[4];
		$associations_response['reach'] = $peerinfo[5];
		$associations_response['auth'] = $peerinfo[6];
		$associations_response['condition'] = $peerinfo[7];
		$associations_response['last_event'] = $peerinfo[8];
		$associations_response['cnt'] = $peerinfo[9];
		$ntpq_server_responses[$i] = $associations_response;
	}

	foreach ($ntpq_output as $i => $line) {
		$server = array();
		$status_char = substr($line, 0, 1);
		$line = substr($line, 1);
		$peerinfo = preg_split("/[\s\t]+/", $line);

		$server['server'] = $peerinfo[0];
		$server['refid'] = $peerinfo[1];
		$server['stratum'] = $peerinfo[2];
		$server['type'] = $peerinfo[3];
		$server['when'] = $peerinfo[4];
		$server['poll'] = $peerinfo[5];
		$server['reach'] = $peerinfo[6];
		$server['delay'] = $peerinfo[7];
		$server['offset'] = $peerinfo[8];
		$server['jitter'] = $peerinfo[9];

		$server['ind'] = $ntpq_server_responses[$i]['ind'];
		$server['assid'] = $ntpq_server_responses[$i]['assid'];
		$server['status_word'] = $ntpq_server_responses[$i]['status_word'];
		$server['conf'] = $ntpq_server_responses[$i]['conf'];
		$server['auth'] = $ntpq_server_responses[$i]['auth'];
		$server['condition'] = $ntpq_server_responses[$i]['condition'];
		$server['last_event'] = $ntpq_server_responses[$i]['last_event'];
		$server['cnt'] = $ntpq_server_responses[$i]['cnt'];

		switch ($status_char) {
			case " ":
				if ($server['refid'] == ".POOL.") {
					$server['status'] = gettext("Pool Placeholder");
				} else {
					$server['status'] = gettext("Unreach/Pending");
				}
				break;
			case "*":
				$server['status'] = gettext("Active Peer");
				break;
			case "+":
				$server['status'] = gettext("Candidate");
				break;
			case "o":
				$server['status'] = gettext("PPS Peer");
				break;
			case "#":
				$server['status'] = gettext("Selected");
				break;
			case ".":
				$server['status'] = gettext("Excess Peer");
				break;
			case "x":
				$server['status'] = gettext("False Ticker");
				break;
			case "-":
				$server['status'] = gettext("Outlier");
				break;
		}

		$ntpq_servers[] = $server;
	}

	exec("/usr/local/sbin/ntpq -c clockvar $inet_version", $ntpq_clockvar_output);
	foreach ($ntpq_clockvar_output as $line) {
		if (substr($line, 0, 9) == "timecode=") {
			$tmp = explode('"', $line);
			$tmp = $tmp[1];
			if (substr($tmp, 0, 6) == '$GPRMC') {
				$gps_vars = explode(",", $tmp);
				$gps_ok = ($gps_vars[2] == "A");
				$gps_lat_deg = substr($gps_vars[3], 0, 2);
				$gps_lat_min = substr($gps_vars[3], 2);
				$gps_lon_deg = substr($gps_vars[5], 0, 3);
				$gps_lon_min = substr($gps_vars[5], 3);
				$gps_lat = (float) $gps_lat_deg + (float) $gps_lat_min / 60.0;
				$gps_lat = $gps_lat * (($gps_vars[4] == "N") ? 1 : -1);
				$gps_lon = (float) $gps_lon_deg + (float) $gps_lon_min / 60.0;
				$gps_lon = $gps_lon * (($gps_vars[6] == "E") ? 1 : -1);
				$gps_lat_dir = $gps_vars[4];
				$gps_lon_dir = $gps_vars[6];
			} elseif (substr($tmp, 0, 6) == '$GPGGA') {
				$gps_vars = explode(",", $tmp);
				$gps_ok = $gps_vars[6];
				$gps_lat_deg = substr($gps_vars[2], 0, 2);
				$gps_lat_min = substr($gps_vars[2], 2);
				$gps_lon_deg = substr($gps_vars[4], 0, 3);
				$gps_lon_min = substr($gps_vars[4], 3);
				$gps_lat = (float) $gps_lat_deg + (float) $gps_lat_min / 60.0;
				$gps_lat = $gps_lat * (($gps_vars[3] == "N") ? 1 : -1);
				$gps_lon = (float) $gps_lon_deg + (float) $gps_lon_min / 60.0;
				$gps_lon = $gps_lon * (($gps_vars[5] == "E") ? 1 : -1);
				$gps_alt = $gps_vars[9];
				$gps_alt_unit = $gps_vars[10];
				$gps_sat = (int)$gps_vars[7];
				$gps_lat_dir = $gps_vars[3];
				$gps_lon_dir = $gps_vars[5];
			} elseif (substr($tmp, 0, 6) == '$GPGLL') {
				$gps_vars = preg_split('/[,\*]+/', $tmp);
				$gps_ok = ($gps_vars[6] == "A");
				$gps_lat_deg = substr($gps_vars[1], 0, 2);
				$gps_lat_min = substr($gps_vars[1], 2);
				$gps_lon_deg = substr($gps_vars[3], 0, 3);
				$gps_lon_min = substr($gps_vars[3], 3);
				$gps_lat = (float) $gps_lat_deg + (float) $gps_lat_min / 60.0;
				$gps_lat = $gps_lat * (($gps_vars[2] == "N") ? 1 : -1);
				$gps_lon = (float) $gps_lon_deg + (float) $gps_lon_min / 60.0;
				$gps_lon = $gps_lon * (($gps_vars[4] == "E") ? 1 : -1);
				$gps_lat_dir = $gps_vars[2];
				$gps_lon_dir = $gps_vars[4];
			} elseif (substr($tmp, 0, 6) == '$PGRMF') {
				$gps_vars = preg_split('/[,\*]+/', $tmp);
				$gps_ok = $gps_vars[11];
				$gps_lat_deg = substr($gps_vars[6], 0, 2);
				$gps_lat_min = substr($gps_vars[6], 2);
				$gps_lon_deg = substr($gps_vars[8], 0, 3);
				$gps_lon_min = substr($gps_vars[8], 3);
				$gps_lat = (float) $gps_lat_deg + (float) $gps_lat_min / 60.0;
				$gps_lat = $gps_lat * (($gps_vars[7] == "N") ? 1 : -1);
				$gps_lon = (float) $gps_lon_deg + (float) $gps_lon_min / 60.0;
				$gps_lon = $gps_lon * (($gps_vars[9] == "E") ? 1 : -1);
				$gps_lat_dir = $gps_vars[7];
				$gps_lon_dir = $gps_vars[9];
			}
		}
	}
}

global $showgps;
$showgps = 0;

global $gps_goo_lnk;
$gps_goo_lnk = 1;

// GPS satellite information (if available)
if (($gps_ok) && ($gps_lat) && ($gps_lon)) {
	$gps_goo_lnk = 2;
	$showgps = 1;
}

if (isset($gps_ok) && config_path_enabled('ntpd/gps','extstatus')) {
	$lookfor['GPGSV'] = config_path_enabled('ntpd/gps/nmeaset','gpgsv');
	$lookfor['GPGGA'] = !isset($gps_sat) && config_path_enabled('ntpd/gps/nmeaset','gpgga');
	$gpsport = fopen('/dev/gps0', 'r+');
	while ($gpsport && ($lookfor['GPGSV'] || $lookfor['GPGGA'])) {
		$buffer = fgets($gpsport);
		if ($lookfor['GPGSV'] && substr($buffer, 0, 6) == '$GPGSV') {
			$gpgsv = explode(',', $buffer);
			$gps_satview = (int)$gpgsv[3];
			$lookfor['GPGSV'] = false;
		} elseif ($lookfor['GPGGA'] && substr($buffer, 0, 6) == '$GPGGA') {
			$gpgga = explode(',', $buffer);
			$gps_sat = (int)$gpgga[7];
			$gps_alt = $gpgga[9];
			$gps_alt_unit = $gpgga[10];
			$lookfor['GPGGA'] = false;
		}
	}
}

// Responding to an AJAX call, we return the GPS data or the status data depending on $_REQUEST['dogps']
if ($_REQUEST['ajax']) {

	if ($_REQUEST['dogps'] == "yes") {
		print_gps();
	} else {
		print_status();
	}

	exit;
}

/* peer status label (set while parsing ntpq above) => [filter key, badge state] */
function ntp_peer_state($label) {
	static $map = null;
	if ($map === null) {
		$map = [
			gettext("Active Peer") => ['active', 'online'],
			gettext("PPS Peer") => ['pps', 'online'],
			gettext("Candidate") => ['candidate', 'info'],
			gettext("Selected") => ['selected', 'info'],
			gettext("Excess Peer") => ['excess', 'idle'],
			gettext("Pool Placeholder") => ['pool', 'idle'],
			gettext("Unreach/Pending") => ['pending', 'pending'],
			gettext("Outlier") => ['outlier', 'warn'],
			gettext("False Ticker") => ['falseticker', 'error'],
		];
	}
	return $map[$label] ?? ['unknown', 'unknown'];
}

function print_status() {
	global $ntpq_servers, $allow_query;

	$message = null;
	if (config_get_path('ntpd/enable') == 'disabled') {
		$message = htmlspecialchars(gettext('NTP Server is disabled'));
	} elseif (!$allow_query) {
		$message = sprintf(htmlspecialchars(gettext('Statistics unavailable because ntpq and ntpdc queries are disabled in the %1$sNTP service settings%2$s')), '<a href="services_ntpd.php">', '</a>');
	} elseif (count($ntpq_servers) == 0) {
		$message = sprintf(htmlspecialchars(gettext('No peers found, %1$sis the ntp service running?%2$s')), '<a href="status_services.php">', '</a>');
	}
	if ($message !== null) {
		print('<tr class="fs-empty" data-fs-static><td colspan="14"><span class="fs-empty-message">' . $message . "</span></td></tr>\n");
		return;
	}

	foreach ($ntpq_servers as $server) {
		list($key, $badge) = ntp_peer_state($server['status']);
		$label = ($key === 'pool') ? gettext('Pool') : $server['status'];
		print('<tr data-fs-filter-state="' . htmlspecialchars($key) . '" data-ntp-stratum="' . htmlspecialchars($server['stratum']) . '">');
		print('<td>' . fs_badge($badge, $label ?: gettext('Unknown')) . '</td>');
		print('<td class="fs-mono">' . htmlspecialchars($server['server']) . '</td>');
		print('<td class="fs-mono">' . htmlspecialchars($server['refid']) . '</td>');
		/* the less used columns are hidden on narrow screens (see the th classes) */
		foreach (['stratum' => '', 'type' => ' d-none d-lg-table-cell', 'when' => ' d-none d-lg-table-cell', 'poll' => ' d-none d-md-table-cell',
		    'reach' => ' d-none d-md-table-cell', 'delay' => '', 'offset' => '', 'jitter' => ' d-none d-md-table-cell',
		    'assid' => ' d-none d-xl-table-cell', 'status_word' => ' d-none d-xl-table-cell', 'auth' => ' d-none d-xl-table-cell'] as $field => $class) {
			print('<td class="fs-mono' . $class . '">' . htmlspecialchars((string)$server[$field]) . '</td>');
		}
		print("</tr>\n");
	}
}

function print_gps() {
	global 	$gps_lat, $gps_lon, $gps_lat_deg, $gps_lon_deg, $gps_lat_min, $gps_lon_min, $gps_lat_dir, $gps_lon_dir,
			$gps_alt, $gps_alt_unit, $gps_sat, $gps_satview, $gps_goo_lnk;

	print("<tr>\n");
	print('<td class="fs-mono">');
	printf("%.5f", $gps_lat);
	print(" (");
	printf("%d%s", $gps_lat_deg, "&deg;");
	printf("%.5f", $gps_lat_min);
	print(htmlspecialchars($gps_lat_dir));
	print(")");
	print("</td>\n");
	print('<td class="fs-mono">');
	printf("%.5f", $gps_lon);
	print(" (");
	printf("%d%s", $gps_lon_deg, "&deg;");
	printf("%.5f", $gps_lon_min);
	print(htmlspecialchars($gps_lon_dir));
	print(")");
	print("</td>\n");

	if (isset($gps_alt)) {
		print('<td class="fs-mono">');
		print(htmlspecialchars($gps_alt . ' ' . $gps_alt_unit));
		print("</td>\n");
	}

	if (isset($gps_sat) || isset($gps_satview)) {
		print('<td>');

		if (isset($gps_satview)) {
			print(gettext('in view ') . intval($gps_satview));
		}

		if (isset($gps_sat) && isset($gps_satview)) {
			print(', ');
		}
		if (isset($gps_sat)) {
			print(gettext('in use ') . intval($gps_sat));
		}

		print("</td>\n");
	}

	print("</tr>\n");
	print("<tr>\n");
	print('<td colspan="' . (int)$gps_goo_lnk . '"><a target="_gmaps" rel="noopener noreferrer" href="https://maps.google.com/?q=' . urlencode($gps_lat . ',' . $gps_lon) . '">' .
	    '<i class="fa-solid fa-map-location-dot icon-embed-btn" aria-hidden="true"></i>' . gettext("Google Maps Link") . '</a></td>');
	print("</tr>\n");
}

$pgtitle = array(gettext("Status"), gettext("NTP"));
$shortcut_section = "ntp";

include("head.inc");

/* summary from the peers; the script below updates it with every refresh */
$ntp_peers = 0;
$ntp_reach = 0;
$ntp_active = null;
foreach ((array)$ntpq_servers as $server) {
	list($key) = ntp_peer_state($server['status']);
	if ($key === 'pool') {
		continue;
	}
	$ntp_peers++;
	if ($key !== 'pending') {
		$ntp_reach++;
	}
	if (($key === 'active' || $key === 'pps') && $ntp_active === null) {
		$ntp_active = $server;
	}
}
$ntp_stratum = ($ntp_active !== null && is_numeric($ntp_active['stratum'])) ? (int)$ntp_active['stratum'] + 1 : null;
?>

<style>
.fs-ntp-sync[hidden] { display: none; }
.fs-ntp-peers thead th { vertical-align: bottom; white-space: nowrap; }
#ntp-tiles .fs-tile-label { flex-wrap: wrap; }
.fs-ntp-server { font-size: var(--fs-fs-md); overflow-wrap: anywhere; }
</style>

<div class="fs-tiles" id="ntp-tiles">
	<div class="fs-tile">
		<div class="fs-tile-label"><?=gettext('Synchronization')?>
			<span class="fs-ntp-sync" data-ntp-sync="yes"<?=($ntp_active === null) ? ' hidden' : ''?>><?=fs_badge('online', gettext('Synced'))?></span>
			<span class="fs-ntp-sync" data-ntp-sync="no"<?=($ntp_active !== null) ? ' hidden' : ''?>><?=fs_badge('warn', gettext('Not synced'))?></span>
		</div>
		<div class="fs-tile-value fs-mono fs-ntp-server" data-ntp-tile="server"><?=htmlspecialchars($ntp_active['server'] ?? '–')?></div>
		<div class="fs-tile-hint"><?=gettext('Active peer')?></div>
	</div>
	<div class="fs-tile">
		<div class="fs-tile-label"><?=gettext('Stratum')?></div>
		<div class="fs-tile-value" data-ntp-tile="stratum"><?=htmlspecialchars($ntp_stratum ?? '–')?></div>
		<div class="fs-tile-hint"><?=gettext('Of this server')?></div>
	</div>
	<div class="fs-tile">
		<div class="fs-tile-label"><?=gettext('Peers')?></div>
		<div class="fs-tile-value" data-ntp-tile="peers"><?=$ntp_reach?> / <?=$ntp_peers?></div>
		<div class="fs-tile-hint"><?=gettext('Reachable of configured')?></div>
	</div>
</div>

<div class="panel panel-default fs-table fs-ntp-peers">
<?php fs_table_toolbar([
	'title' => gettext('Peers'),
	'search' => gettext('Search peers…'),
	'noun' => gettext('peers'),
	'noun_one' => gettext('peer'),
	'filters' => ['state' => [gettext('All states'), 'active' => gettext('Active Peer'), 'pps' => gettext('PPS Peer'),
	    'candidate' => gettext('Candidate'), 'selected' => gettext('Selected'), 'excess' => gettext('Excess Peer'),
	    'outlier' => gettext('Outlier'), 'falseticker' => gettext('False Ticker'), 'pending' => gettext('Unreach/Pending'),
	    'pool' => gettext('Pool')]],
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover" data-sortable>
			<thead>
				<tr>
					<th class="fs-col-status"><?=gettext("Status")?></th>
					<th data-fs-search><?=gettext("Server")?></th>
					<th data-fs-search><?=gettext("Ref ID")?></th>
					<th><?=gettext("Stratum")?></th>
					<th class="d-none d-lg-table-cell"><?=gettext("Type")?></th>
					<th class="d-none d-lg-table-cell"><?=gettext("When")?></th>
					<th class="d-none d-md-table-cell"><?=gettext("Poll (s)")?></th>
					<th class="d-none d-md-table-cell"><?=gettext("Reach")?></th>
					<th><?=gettext("Delay (ms)")?></th>
					<th><?=gettext("Offset (ms)")?></th>
					<th class="d-none d-md-table-cell"><?=gettext("Jitter (ms)")?></th>
					<th class="d-none d-xl-table-cell"><?=gettext("AssocID")?></th>
					<th class="d-none d-xl-table-cell"><?=gettext("Status Word")?></th>
					<th class="d-none d-xl-table-cell"><?=gettext("Auth")?></th>
				</tr>
			</thead>
			<tbody id="ntpbody">
				<?=print_status()?>
			</tbody>
		</table>
	</div>
</div>


<?php

// GPS satellite information (if available)
if (($gps_ok) && ($gps_lat) && ($gps_lon)):
	$gps_goo_lnk = 2;
	$showgps = 1;
?>

<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext("GPS information");?></h2></div>
	<div class="panel-body table-responsive">
		<table class="table table-sm">
			<thead>
				<tr>
					<th><?=gettext("Clock latitude")?></th>
					<th><?=gettext("Clock longitude")?></th>
<?php
	if (isset($gps_alt)) {
?>
					<th><?=gettext("Clock altitude")?></th>
<?php
		$gps_goo_lnk++;
	}

	if (isset($gps_sat) || isset($gps_satview)) {
?>
					<th><?=gettext("Satellites")?></th>
<?php
		$gps_goo_lnk++;
	}
?>
				</tr>
			</thead>

			<tbody id="gpsbody">
				<?=print_gps()?>
			</tbody>
		</table>
	</div>
</div>

<?php
endif;
?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	ajax_lock = false;		// Mutex so we don't make a call until the previous call is finished
	do_gps = "no";

	// Recount the summary tiles from the refreshed peer rows
	function update_tiles() {
		var rows = document.querySelectorAll('#ntpbody tr[data-fs-filter-state]');
		var peers = 0, reach = 0, active = null;
		rows.forEach(function (tr) {
			var state = tr.getAttribute('data-fs-filter-state');
			if (state === 'pool') {
				return;
			}
			peers++;
			if (state !== 'pending') {
				reach++;
			}
			if (!active && (state === 'active' || state === 'pps')) {
				active = tr;
			}
		});
		var stratum = active ? parseInt(active.getAttribute('data-ntp-stratum'), 10) : NaN;
		var tiles = document.getElementById('ntp-tiles');
		tiles.querySelector('[data-ntp-sync="yes"]').hidden = !active;
		tiles.querySelector('[data-ntp-sync="no"]').hidden = !!active;
		tiles.querySelector('[data-ntp-tile="server"]').textContent = active ? active.cells[1].textContent : '–';
		tiles.querySelector('[data-ntp-tile="stratum"]').textContent = isNaN(stratum) ? '–' : String(stratum + 1);
		tiles.querySelector('[data-ntp-tile="peers"]').textContent = reach + ' / ' + peers;
	}

	// Fetch the tbody contents from the server
	function update_tables() {

		if (ajax_lock) {
			return;
		}

		ajax_lock = true;

		ajaxRequest = $.ajax(
			{
				url: "/status_ntpd.php",
				type: "post",
				data: {
					ajax: 	"ajax",
					dogps:  do_gps
				}
			}
		);

		// Deal with the results of the above ajax call
		ajaxRequest.done(function (response, textStatus, jqXHR) {
			if (do_gps == "yes") {
				$('#gpsbody').html(response);
			} else {
				$('#ntpbody').html(response);
				update_tiles();
				// re-apply the list search and filter to the new rows
				var list = document.getElementById('ntpbody').closest('.fs-table');
				if (list && list._fsTable) {
					list._fsTable.apply(false);
				}
			}

			ajax_lock = false;

			// Alternate updating the status table and the gps table (if enabled)
			if ((do_gps == "yes") || ("<?=$showgps?>" != 1)) {
				do_gps = "no";
			} else {
				do_gps = "yes";
			}

			// and do it again
			setTimeout(update_tables, 5000);
		});


	}

	// Populate the tbody on page load
	update_tables();
});
//]]>
</script>

<?php
include("foot.inc");
?>
