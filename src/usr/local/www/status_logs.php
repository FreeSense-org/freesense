<?php
/*
 * status_logs.php
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
##|*IDENT=page-diagnostics-logs-system
##|*NAME=Status: Logs: System
##|*DESCR=Allow access to the 'Status: System Logs: General' page.
##|*MATCH=status_logs.php
##|-PRIV

require_once("status_logs_common.inc");


/*
Build a list of allowed log files so we can reject others to prevent the page
from acting on unauthorized files.
*/
$allowed_logs = array(
	"system" => array("name" => gettext("General"),
		    "shortcut" => ""),
	"dhcpd" => array("name" => gettext("DHCP"),
		    "shortcut" => "dhcp"),
	"auth" => array("name" => gettext("General"),
		    "shortcut" => ""),
	"portalauth" => array("name" => gettext("Captive Portal Auth"),
		    "shortcut" => "captiveportal"),
	"ipsec" => array("name" => gettext("IPsec"),
		    "shortcut" => "ipsec"),
	"ppp" => array("name" => gettext("PPP"),
		    "shortcut" => ""),
	"openvpn" => array("name" => gettext("OpenVPN"),
		    "shortcut" => "openvpn"),
	"ntpd" => array("name" => gettext("NTP"),
		    "shortcut" => "ntp"),
	"gateways" => array("name" => gettext("Gateways"),
		    "shortcut" => "gateways"),
	"routing" => array("name" => gettext("Routing"),
		    "shortcut" => "routing"),
	"resolver" => array("name" => gettext("DNS Resolver"),
		    "shortcut" => "resolver"),
	"wireless" => array("name" => gettext("Wireless"),
		    "shortcut" => "wireless"),
	"nginx" => array("name" => gettext("GUI Service"),
		    "shortcut" => ""),
	"dmesg.boot" => array("name" => gettext("OS Boot"),
		    "shortcut" => ""),
	"utx" => array("name" => gettext("OS User Events"),
		    "shortcut" => ""),
	"userlog" => array("name" => gettext("OS Account Changes"),
		    "shortcut" => ""),
);

// The logs to display are specified in a REQUEST argument. Default to 'system' logs
if (!$_REQUEST['logfile']) {
	$logfile = 'system';
} else {
	$logfile = $_REQUEST['logfile'];
	if (!array_key_exists($logfile, $allowed_logs)) {
		/* Do not let someone attempt to load an unauthorized log. */
		$logfile = 'system';
	}
}


// Log Filter Submit - System
log_filter_form_system_submit();


// Manage Log Section - Code
manage_log_code();


// Status Logs Common - Code
status_logs_common_code();


if (in_array($logfile, array('system', 'gateways', 'routing', 'resolver', 'wireless', 'nginx', 'dmesg.boot'))) {
	$pgtitle = array(gettext("Status"), gettext("System Logs"), gettext("System"), $allowed_logs[$logfile]["name"]);
	$pglinks = array("", "status_logs.php", "status_logs.php", "@self");
} elseif (in_array($logfile, array('auth', 'portalauth', 'utx', 'userlog'))) {
	$pgtitle = array(gettext("Status"), gettext("System Logs"), gettext("Authentication"), $allowed_logs[$logfile]["name"]);
	$pglinks = array("", "status_logs.php", "status_logs.php", "@self");
} else {
	$pgtitle = array(gettext("Status"), gettext("System Logs"), $allowed_logs[$logfile]["name"]);
	$pglinks = array("", "status_logs.php", "@self");
}

if (in_array($logfile, array('userlog', 'dmesg.boot'))) {
	$rawfilter = true;
}

if (($logfile == 'resolver') || ($logfile == 'system')) {
	$inverse = array("ppp");
} else {
	$inverse = null;
}

// Read the log (formatted entries or raw lines)
system_log_filter();
$is_raw = $rawfilter && ($logfile != 'utx');

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
// Filter toolbar - System
filter_form_system();
?>
	<div class="panel-body table-responsive">
		<table class="table table-hover fs-logtable" data-sortable>
<?php if ($logfile == 'utx'): ?>
			<thead>
				<tr>
					<th><?=gettext("Login Time")?></th>
					<th><?=gettext("Duration")?></th>
					<th><?=gettext("TTY")?></th>
					<th data-fs-search><?=gettext("User/Message")?></th>
				</tr>
			</thead>
<?php elseif ($is_raw): ?>
			<thead>
				<tr>
					<th><?=gettext("Message")?></th>
				</tr>
			</thead>
<?php else: ?>
			<thead>
				<tr>
					<th><?=gettext("Time")?></th>
					<th data-fs-search><?=gettext("Process")?></th>
					<th data-fs-search><?=gettext("Message")?></th>
				</tr>
			</thead>
<?php endif; ?>
			<tbody>
<?php if ($is_raw):
	status_logs_raw_rows($rawlines);
	$colspan = 1;
elseif ($logfile == 'utx'):
	$colspan = 4;
	foreach ($filterlog as $filterent): ?>
				<tr>
					<?=status_logs_time_cell($filterent['time'])?>
					<td class="fs-mono"><?=htmlspecialchars($filterent['process'] ?? '')?></td>
					<td class="fs-mono"><?=htmlspecialchars($filterent['pid'] ?? '')?></td>
					<td class="fs-log-msg"><?=htmlspecialchars($filterent['message'] ?? '')?><?php if (!empty($filterent['host'])): ?> <span class="fs-muted fs-mono"><?=htmlspecialchars($filterent['host'])?></span><?php endif; ?></td>
				</tr>
<?php	endforeach;
else:
	$colspan = 3;
	foreach ($filterlog as $filterent): ?>
				<tr<?=status_logs_row_attrs($filterent['message'])?>>
					<?=status_logs_time_cell($filterent['time'])?>
					<td><?=status_logs_process_chip($filterent['process'], $filterent['pid'])?></td>
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

include("foot.inc");
