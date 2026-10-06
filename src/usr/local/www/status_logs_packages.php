<?php
/*
 * status_logs_packages.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
 * Copyright (c) 2005 Colin Smith
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
	To add logging support to a package, add the following to the info.xml
	file for a package under files/usr/local/share/<package port name>/info.xml
	inside the <package>...</package> tag:

	<logging>
		<!-- Name of the logging tab on this page -->
		<logtab>arpwatch</logtab>

		<!-- syslog facility name to use in the syslog configuration.
			Can be multiple values, comma separated (no spaces) -->
		<facilityname>programname</facilityname>

		<!-- Filename for the package log, relative to /var/log -->
		<logfilename>filename.log</logfilename>

		<!-- Add a syslogd log socket directory -->
		<logsocket>/path/to/pkgchroot/var/run/log</logsocket>

		<!-- user:group for the log, set after rotation -->
		<logowner>root:wheel</logowner>

		<!-- File mode for the log, set after rotation -->
		<logmode>600</logmode>

		<!-- Total number of log files to keep during rotation -->
		<rotatecount>7</rotatecount>

		<!-- File size (in bytes) at which to rotate the log, or '*' to
			disable size-based rotation -->
		<logfilesize>512000</logfilesize>

		<!-- newsyslog format time (ISO 8601 restricted time format or
			Day/week/month format) for time-based rotation. Omit or
			'*' for size-based rotation.
			See https://www.freebsd.org/cgi/man.cgi?query=newsyslog.conf&apropos=0&sektion=0&manpath=FreeBSD+12.0-RELEASE+and+Ports&arch=default&format=html
			-->
		<rotatetime>@T00</rotatetime>

		<!-- Extra newsyslog flags for this log. 'C' is always assumed,
			and the compression flag is set globally. -->
		<rotateflags>p</rotateflags>

		<!-- PID to signal, or path to cmd to run after rotation -->
		<pidcmd>/var/run/program.pid</pidcmd>

		<!-- Signal to send the PID found in pidcmd -->
		<signal>30</signal>
	</logging>

*/

##|+PRIV
##|*IDENT=page-status-packagelogs
##|*NAME=Status: Package logs
##|*DESCR=Allow access to the 'Status: Package logs' page.
##|*MATCH=status_logs_packages.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("pkg-utils.inc");
require_once("status_logs_common.inc");

global $g;
if (!($nentries = config_get_path('syslog/nentries'))) {
	$nentries = g_get('default_log_entries');
}

$i = 0;
$pkgwithlogging = false;
$apkg = $_REQUEST['pkg'];
if (!$apkg) { // If we aren't looking for a specific package, locate the first package that handles logging.
	foreach (config_get_path('installedpackages/package', []) as $package) {
		if (isset($package['logging']['logfilename']) && $package['logging']['logfilename'] != '') {
			$pkgwithlogging = true;
			$apkg = $package['name'];
			$apkgid = $i;
			break;
		}
		$i++;
	}
} elseif ($apkg) {
	$apkgid = get_package_id($apkg);
	if ($apkgid != -1) {
		$pkgwithlogging = true;
		$i = $apkgid;
	}
}

// Log Filter Submit - System
log_filter_form_system_submit();

// Package log tabs (one per package with logging) and the selected log file
$allowed_logs = array();
$pkg_tabs = array();
if ($pkgwithlogging) {
	foreach (config_get_path('installedpackages/package', []) as $package) {
		if (is_array($package['logging'])) {
			if (!($logtab = $package['logging']['logtab'])) {
				$logtab = $package['name'];
			}
			$pkg_tabs[] = array($logtab, ($apkg == $package['name']), "status_logs_packages.php?pkg=" . urlencode($package['name']));
			$allowed_logs[$package['logging']['logfilename']] = array(
				"name" => gettext($logtab),
				"shortcut" => $package['name'],
			);
		}
	}
	$logfile = config_get_path("installedpackages/package/{$apkgid}/logging/logfilename");
}

// Status Logs Common - Code
status_logs_common_code();

/* We do not necessarily know the format of package logs, so assume raw. */
$rawfilter = true;

if ($pkgwithlogging) {
	$logfile_path = g_get('varlog_path') . '/' . $logfile;
	$inverse = null;
	system_log_filter();
}

$pgtitle = array(gettext("Status"), gettext("Package Logs"));
$pglinks = array("", "status_logs_packages.php");

if ($pkgwithlogging && !empty($apkg)) {
	$pgtitle[] = htmlspecialchars($apkg);
	$pglinks[] = "@self";
}

/* Packages determine their own log settings, so there is no Log settings or Clear log action. */
include("head.inc");

status_logs_notices();

tab_array_logs_common();

status_logs_styles();

if ($pkgwithlogging == false):
?>
<div class="panel panel-default">
	<div class="panel-body fs-logs-none">
		<i class="fa-solid fa-box-open" aria-hidden="true"></i>
		<p><?=gettext("No packages with logging facilities are currently installed.")?></p>
	</div>
</div>
<style>
.fs-logs-none { padding: var(--fs-sp-6) var(--fs-sp-4); text-align: center; color: var(--fs-text-muted); }
.fs-logs-none > i { font-size: 1.5rem; margin-bottom: var(--fs-sp-2); }
.fs-logs-none > p { margin: 0; }
</style>
<?php
else:
	status_logs_subnav($pkg_tabs, gettext('Package'));
?>

<div class="panel panel-default fs-table" data-fs-table="log">
<?php
// Filter toolbar - System (raw)
filter_form_system();
?>
	<div class="panel-body table-responsive">
		<table class="table table-hover fs-logtable" data-sortable>
			<thead>
				<tr>
					<th><?=gettext("Message")?></th>
				</tr>
			</thead>
			<tbody>
<?php
	status_logs_raw_rows($rawlines);
	if ($rows == 0) {
		fs_empty_row(1, gettext('No log entries to display.'));
	}
?>
			</tbody>
		</table>
	</div>
<?php status_logs_card_footer(); ?>
</div>

<?php endif;

include("foot.inc"); ?>
