<?php
/*
 * status_logs_vpn.php
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
##|*IDENT=page-diagnostics-logs-pptpvpn
##|*NAME=Status: Logs: PPPoE/L2TP Server
##|*DESCR=Allow access to the 'Status: Logs: PPPoE/L2TP Server' page.
##|*MATCH=status_logs_vpn.php*
##|-PRIV


require_once("status_logs_common.inc");
require_once("vpn.inc");

/*
Build a list of allowed log files so we can reject others to prevent the page
from acting on unauthorized files.
*/
$allowed_logs = array(
	"vpn" => array("name" => gettext("PPPoE/L2TP Logins"),
		    "shortcut" => "poes"),
	"poes" => array("name" => gettext("PPPoE Service"),
		    "shortcut" => "pppoes"),
	"l2tps" => array("name" => gettext("L2TP Service"),
		    "shortcut" => "l2tps"),
);

// The logs to display are specified in a REQUEST argument. Default to 'system' logs
if (!$_REQUEST['logfile']) {
	$logfile = 'vpn';
	$vpntype = "poes";
} else {
	$logfile = $_REQUEST['logfile'];
	$vpntype = $_REQUEST['vpntype'];
	if (!array_key_exists($logfile, $allowed_logs)) {
		/* Do not let someone attempt to load an unauthorized log. */
		$logfile = 'vpn';
		$vpntype = "poes";
	}
}

if ($vpntype == 'poes') { $allowed_logs['vpn']['name'] = gettext("PPPoE Logins"); }
if ($vpntype == 'l2tp') { $allowed_logs['vpn']['name'] = gettext("L2TP Logins"); }


// Log Filter Submit - VPN
log_filter_form_vpn_submit();


// Manage Log Section - Code
manage_log_code();


// Status Logs Common - Code
status_logs_common_code();


$pgtitle = array(gettext("Status"), gettext("System Logs"), gettext("PPPoE/L2TP Server"), gettext($allowed_logs[$logfile]["name"]));
$pglinks = array("", "status_logs.php", "status_logs_vpn.php", "@self");

// Read the log
system_log_filter();
if (!$rawfilter && ($logfile == "vpn")) {
	// Remove those not of the selected vpn type (poes / l2tp).
	foreach ($filterlog as $key => $filterent) {
		if (!preg_match('/' . preg_quote((string)$vpntype, '/') . '/', $filterent['type'])) {
			unset($filterlog[$key]);
		}
	}
	$rows = count($filterlog);
}

// Header actions: Log settings (modal) and Clear log
status_logs_page_actions();

include("head.inc");

status_logs_notices();

// Tab Array
tab_array_logs_common();

status_logs_styles();
?>

<div class="panel panel-default fs-table" data-fs-table="log">
<?php
// Filter toolbar - VPN
filter_form_vpn();
?>
	<div class="panel-body table-responsive">
		<table class="table table-hover fs-logtable" data-sortable>
<?php if ($rawfilter):
	$colspan = 1;
?>
			<thead>
				<tr>
					<th><?=gettext("Message")?></th>
				</tr>
			</thead>
			<tbody>
<?php	status_logs_raw_rows($rawlines);
elseif ($logfile == "vpn"):
	$colspan = 4;
?>
			<thead>
				<tr>
					<th><?=gettext("Time")?></th>
					<th data-fs-search><?=gettext("Action")?></th>
					<th data-fs-search><?=gettext("User")?></th>
					<th data-fs-search><?=gettext("IP Address")?></th>
				</tr>
			</thead>
			<tbody>
<?php	foreach ($filterlog as $filterent): ?>
				<tr>
					<?=status_logs_time_cell($filterent['time'])?>
					<td>
<?php		if ($filterent['action'] == "login"): ?>
						<?=fs_badge('pass', gettext('Login'))?>
<?php		elseif ($filterent['action'] == "logout"): ?>
						<?=fs_badge('neutral', gettext('Logout'))?>
<?php		else: ?>
						<?=fs_badge('info', $filterent['action'])?>
<?php		endif; ?>
					</td>
					<td><?=htmlspecialchars($filterent['user'])?></td>
					<td class="fs-mono"><?=htmlspecialchars($filterent['ip_address'])?></td>
				</tr>
<?php	endforeach;
else:
	$colspan = 3;
?>
			<thead>
				<tr>
					<th><?=gettext("Time")?></th>
					<th data-fs-search><?=gettext("Type")?></th>
					<th data-fs-search><?=gettext("Message")?></th>
				</tr>
			</thead>
			<tbody>
<?php	foreach ($filterlog as $filterent): ?>
				<tr<?=status_logs_row_attrs($filterent['message'])?>>
					<?=status_logs_time_cell($filterent['time'])?>
					<td><?=status_logs_process_chip($filterent['type'], $filterent['pid'])?></td>
					<td class="fs-log-msg"><?=htmlspecialchars($filterent['message'])?></td>
				</tr>
<?php	endforeach;
endif;

if ($rows == 0) {
	fs_empty_row($colspan, gettext('No log entries to display.'));
}
?>
			</tbody>
		</table>
	</div>
<?php status_logs_card_footer(); ?>
</div>
<?php

// Log settings modal
manage_log_section();

// Log Filter Submit - VPN
function log_filter_form_vpn_submit() {

	global $filtersubmit, $interfacefilter, $filtertext;
	global $filterlogentries_submit, $filterfieldsarray, $actpass, $actblock;
	global $filter_active, $filterlogentries_qty;

	$filtersubmit = getGETPOSTsettingvalue('filtersubmit', null);

	if ($filtersubmit) {
		$filter_active = true;
		$filtertext = getGETPOSTsettingvalue('filtertext', "");
		$filterlogentries_qty = getGETPOSTsettingvalue('filterlogentries_qty', null);
	}

	$filterlogentries_submit = getGETPOSTsettingvalue('filterlogentries_submit', null);

	if ($filterlogentries_submit) {
		$filter_active = true;
		$filterfieldsarray = array();

		$filterfieldsarray['time'] = getGETPOSTsettingvalue('filterlogentries_time', null);
		$filterfieldsarray['type'] = getGETPOSTsettingvalue('filterlogentries_type', null);
		$filterfieldsarray['pid'] = getGETPOSTsettingvalue('filterlogentries_pid', null);
		$filterfieldsarray['message'] = getGETPOSTsettingvalue('filterlogentries_message', null);
		$filterfieldsarray['action'] = getGETPOSTsettingvalue('filterlogentries_action', null);
		$filterfieldsarray['user'] = getGETPOSTsettingvalue('filterlogentries_user', null);
		$filterfieldsarray['ip_address'] = getGETPOSTsettingvalue('filterlogentries_ip_address', null);
		$filterlogentries_qty = getGETPOSTsettingvalue('filterlogentries_qty', null);
	}
}

// Filter toolbar - VPN
function filter_form_vpn() {

	global $rawfilter, $filterfieldsarray, $filtertext, $filterlogentries_qty, $nentries;
	global $logfile;

	$qty = ['name' => 'filterlogentries_qty', 'label' => gettext('Entries'), 'type' => 'number', 'value' => $filterlogentries_qty, 'placeholder' => $nentries];
	if (!$rawfilter) { // Advanced (field) log filter
		if ($logfile == "vpn") {
			$quick = [
				['name' => 'filterlogentries_user', 'label' => gettext('User'), 'value' => $filterfieldsarray['user'] ?? ''],
				['name' => 'filterlogentries_ip_address', 'label' => gettext('IP address'), 'value' => $filterfieldsarray['ip_address'] ?? ''],
			];
			$advanced = [
				['name' => 'filterlogentries_time', 'label' => gettext('Time'), 'value' => $filterfieldsarray['time'] ?? ''],
				['name' => 'filterlogentries_action', 'label' => gettext('Action'), 'value' => $filterfieldsarray['action'] ?? ''],
				$qty,
			];
			status_logs_filter_toolbar($quick, $advanced, 'filterlogentries_submit', false);
		} else {
			$quick = [
				['name' => 'filterlogentries_message', 'label' => gettext('Message'), 'value' => $filterfieldsarray['message'] ?? '',
				    'placeholder' => gettext('Message filter')],
				['name' => 'filterlogentries_type', 'label' => gettext('Type'), 'value' => $filterfieldsarray['type'] ?? '', 'small' => true],
			];
			$advanced = [
				['name' => 'filterlogentries_time', 'label' => gettext('Time'), 'value' => $filterfieldsarray['time'] ?? ''],
				['name' => 'filterlogentries_pid', 'label' => gettext('PID'), 'value' => $filterfieldsarray['pid'] ?? ''],
				$qty,
			];
			status_logs_filter_toolbar($quick, $advanced, 'filterlogentries_submit');
		}
	} else { // Simple (expression) log filter
		$qty['small'] = true;
		$quick = [
			['name' => 'filtertext', 'label' => gettext('Filter expression'), 'value' => $filtertext],
			$qty,
		];
		status_logs_filter_toolbar($quick, [], 'filtersubmit');
	}
}
?>

<?php include("foot.inc"); ?>
