<?php
/*
 * crash_reporter.php
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
##|*IDENT=page-diagnostics-crash-reporter
##|*NAME=Crash Reporter
##|*DESCR=Allow access to view, download, and delete crash reports.
##|*MATCH=crash_reporter.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("functions.inc");
require_once("captiveportal.inc");
require_once("system.inc");
require_once("util.inc");

define("FILE_SIZE", 450000);

function download_crashdata_file($name) {
	if (!file_exists($name)) {
		exit;
	}
	session_cache_limiter('public');
	send_user_download('file', $name);
}

if (!empty($_POST['Download'])) {
	if ($_POST['Download'] == "PHP") {
		/* Send PHP log */
		download_crashdata_file("/tmp/PHP_errors.log");
	} else {
		$filename = "/var/crash/" . basename($_POST['Download']);
		if (file_exists($filename)) {
			download_crashdata_file($filename);
		}
	}
}

if ($_POST['Submit'] == "No") {
	unlink_if_exists("/var/crash/*");
	// Erase the contents of the PHP error log
	fclose(fopen("/tmp/PHP_errors.log", 'w'));
	header("Location: /");
	exit;
}

$crash_report_header = "Crash report begins.  Anonymous machine information:\n\n";
$crash_report_header .= php_uname("m") . "\n";
$crash_report_header .= php_uname("r") . "\n";
$crash_report_header .= php_uname("v") . "\n";
$crash_report_header .= "\nCrash report details:\n";

/* The report text (plain text, escaped once when it is printed) and the files it is built from */
$crash_reports = $crash_report_header;
$report_files = [];
$has_php_errors = system_has_php_errors();
if ($has_php_errors) {
	$php_size = (int)filesize("/tmp/PHP_errors.log");
	$report_files[] = ['type' => 'php', 'name' => 'PHP_errors.log', 'download' => 'PHP', 'size' => $php_size,
	    'mtime' => filemtime("/tmp/PHP_errors.log"), 'included' => ($php_size < FILE_SIZE)];
	if ($php_size < FILE_SIZE) {
		$crash_reports .= "\nPHP Errors:\n";
		$crash_reports .= file_get_contents("/tmp/PHP_errors.log") . "\n\n";
	} else {
		$crash_reports .= "\n/tmp/PHP_errors.log file is too large to display.\n";
	}
} else {
	$crash_reports .= "\nNo PHP errors found.\n";
}

$crash_files = cleanup_crash_file_list();
if (count($crash_files) > 0) {
	foreach ($crash_files as $cf) {
		$size = (int)filesize($cf);
		$report_files[] = ['type' => 'crash', 'name' => basename($cf), 'download' => basename($cf), 'size' => $size,
		    'mtime' => filemtime($cf), 'included' => ($size < FILE_SIZE)];
		if ($size < FILE_SIZE) {
			$crash_reports .= "\nFilename: {$cf}\n";
			$crash_reports .= file_get_contents($cf);
		}
	}
} else {
	$crash_reports .= "\nNo FreeBSD crash data found.\n";
}
$has_data = !empty($report_files);

$pgtitle = array(gettext("Diagnostics"), gettext("Crash Reporter"));
if ($has_data) {
	fs_page_action(gettext('Delete crash data'), 'crash_reporter.php?Submit=No', 'fa-trash-can', 'danger', [
		'usepost' => true,
		'data-fs-confirm' => gettext('Delete the crash report data?'),
		'data-fs-confirm-detail' => gettext('The crash files are removed, the PHP error log is emptied and you return to the dashboard.'),
		'data-fs-confirm-action' => gettext('Delete'),
	]);
}
include('head.inc');

if (!$has_data):
?>
<div class="panel panel-default">
	<div class="fs-tool-empty">
		<i class="fa-solid fa-circle-check" aria-hidden="true"></i>
		<span><?=gettext('No crash data. The firewall has not recorded any PHP errors or kernel crash dumps.')?></span>
		<a class="btn btn-primary btn-sm" href="/"><i class="fa-solid fa-gauge icon-embed-btn" aria-hidden="true"></i><?=gettext('Back to the dashboard')?></a>
	</div>
</div>
<?php
else:
	print_callout(sprintf(gettext("Debugging output can be collected to share with %s developers or others providing support or assistance."), htmlspecialchars(g_get('product_label'))) . ' ' .
	    gettext("Inspect the contents to ensure this information is acceptable to disclose before distributing these files."),
	    'warning', gettext("The firewall has encountered an error"));
?>

<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Debugging files'),
	'search' => false,
	'noun' => gettext('files'),
	'noun_one' => gettext('file'),
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover">
			<thead>
				<tr>
					<th class="fs-col-status"><?=gettext('Type')?></th>
					<th><?=gettext('File')?></th>
					<th><?=gettext('Size')?></th>
					<th><?=gettext('Modified')?></th>
					<th class="fs-col-actions"><span class="visually-hidden"><?=gettext('Actions')?></span></th>
				</tr>
			</thead>
			<tbody>
<?php foreach ($report_files as $f): ?>
				<tr>
					<td><?=($f['type'] === 'php') ? fs_badge('warn', gettext('PHP errors')) : fs_badge('error', gettext('Crash dump'))?></td>
					<td>
						<span class="fs-mono"><?=htmlspecialchars($f['name'])?></span>
<?php if (!$f['included']): ?>
						<div class="fs-muted small"><?=gettext('Too large to show in the report; download it instead.')?></div>
<?php endif; ?>
					</td>
					<td class="fs-mono text-nowrap"><?=htmlspecialchars(format_bytes($f['size']))?></td>
					<td class="text-nowrap"><?=htmlspecialchars(date('Y-m-d H:i:s', (int)$f['mtime']))?></td>
					<td class="fs-col-actions"><?=fs_row_actions([
						['custom', 'crash_reporter.php?Download=' . rawurlencode($f['download']), $f['name'],
						    ['icon' => 'fa-download', 'label' => sprintf(gettext('Download %s'), $f['name']), 'post' => true]],
					])?></td>
				</tr>
<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>

<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title"><?=gettext('Crash report')?></h2>
		<button type="button" class="btn btn-sm btn-outline-secondary" data-fs-copy="#crash-report">
			<i class="fa-regular fa-copy icon-embed-btn" aria-hidden="true"></i><?=gettext('Copy')?>
		</button>
	</div>
	<pre class="fs-console" id="crash-report"><?=htmlspecialchars($crash_reports)?></pre>
</div>
<?php
endif;
?>

<?php include("foot.inc")?>
