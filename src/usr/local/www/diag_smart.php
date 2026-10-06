<?php
/*
 * diag_smart.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
 * Copyright (c) 2006 Eric Friesen
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
##|*IDENT=page-diagnostics-smart
##|*NAME=Diagnostics: S.M.A.R.T. Status
##|*DESCR=Allow access to the 'Diagnostics: S.M.A.R.T. Status' page.
##|*MATCH=diag_smart.php*
##|-PRIV

require_once("guiconfig.inc");

// What page, aka. action is being wanted
// If they "get" a page but don't pass all arguments, smartctl will throw an error
$action = $_POST['action'];

$pgtitle = array(gettext("Diagnostics"), gettext("S.M.A.R.T. Status"));

$smartctl = "/usr/local/sbin/smartctl";

$test_types = array(
	'offline' => gettext('Offline Test'),
	'short' => gettext('Short Test'),
	'long' => gettext('Long Test'),
	'conveyance' => gettext('Conveyance Test')
);
$info_types = array(
	'x' => gettext('All SMART and Non-SMART Information'),
	'a' => gettext('All SMART Information'),
	'i' => gettext('Device Information'),
	'H' => gettext('Device Health'),
	'c' => gettext('SMART Capabilities'),
	'A' => gettext('SMART Attributes'),
);
$log_types = array(
	'error' => gettext('Summary Error Log'),
	'xerror' => gettext('Extended Error Log'),
	'selftest' => gettext('SMART Self-Test Log'),
	'xselftest' => gettext('Extended Self-Test Log'),
	'selective' => gettext('Selective Self-Test Log'),
	'directory' => gettext('Log Directory'),
	'scttemp' => gettext('Device Temperature Log (ATA Only)'),
	'devstat' => gettext('Device Statistics (ATA Only)'),
	'sataphy' => gettext('SATA PHY Events (SATA Only)'),
	'sasphy' => gettext('SAS PHY Events (SAS Only)'),
	'nvmelog' => gettext('NVMe Log (NVMe Only)'),
	'ssd' => gettext('SSD Device Statistics (ATA/SCSI)'),
);

/* the form card follows the posted action, else ?view= */
$views = ['info' => gettext('Information'), 'logs' => gettext('Logs'), 'test' => gettext('Self-tests')];
$view = in_array($action, ['info', 'logs'], true) ? $action : (in_array($action, ['test', 'abort'], true) ? 'test' : fs_view_param(array_keys($views), 'info'));

include("head.inc");

/* words highlighted in smartctl output */
$smart_marks = array('PASSED' => 'fs-smart-pass', 'FAILED' => 'fs-smart-fail', 'Warning' => 'fs-smart-warn');

$targetdev = basename($_POST['device']);

if (!file_exists('/dev/' . $targetdev)) {
	print_info_box(gettext("Device does not exist, bailing."), 'danger');
	include("foot.inc");
	exit;
}

$specplatform = system_identify_specific_platform();
if (($specplatform['name'] == "Hyper-V") || ($specplatform['name'] == "uFW")) {
	print_info_box(htmlspecialchars(sprintf(gettext("S.M.A.R.T. is not supported on this system (%s)."), $specplatform['descr'])), 'warning', false);
	include("foot.inc");
	exit;
}

$output = null;
$error = null;
$result_title = gettext('Output');

switch ($action) {
	// Testing devices
	case 'test':
		$test = $_POST['type'];
		if (!in_array($test, array_keys($test_types))) {
			$error = gettext("Invalid test type, bailing.");
			break;
		}
		$output = (string)shell_exec($smartctl . " -t " . escapeshellarg($test) . " /dev/" . escapeshellarg($targetdev));
		$result_title = sprintf(gettext('%1$s on %2$s'), $test_types[$test], $targetdev);
		break;

	// Info on devices
	case 'info':
		$type = $_POST['type'];
		if (!in_array($type, array_keys($info_types))) {
			$error = gettext("Invalid info type, bailing.");
			break;
		}
		$output = (string)shell_exec($smartctl . " -" . escapeshellarg($type) . " /dev/" . escapeshellarg($targetdev));
		$result_title = sprintf(gettext('%1$s of %2$s'), $info_types[$type], $targetdev);
		break;

	// View logs
	case 'logs':
		$type = $_POST['type'];
		if (!in_array($type, array_keys($log_types))) {
			$error = gettext("Invalid log type, bailing.");
			break;
		}
		$output = (string)shell_exec($smartctl . " -l " . escapeshellarg($type) . " /dev/" . escapeshellarg($targetdev));
		$result_title = sprintf(gettext('%1$s of %2$s'), $log_types[$type], $targetdev);
		break;

	// Abort tests
	case 'abort':
		$output = (string)shell_exec($smartctl . " -X /dev/" . escapeshellarg($targetdev));
		$result_title = sprintf(gettext('Abort tests on %s'), $targetdev);
		break;
}

$devs = get_drive_list();
$type_lists = ['info' => $info_types, 'logs' => $log_types, 'test' => $test_types];
$type_labels = ['info' => gettext('Information type'), 'logs' => gettext('Log'), 'test' => gettext('Test type')];
$posted_type = $_POST['type'] ?? null;

fs_view_switch($views, $view);
?>

<style>
.fs-tool { display: grid; grid-template-columns: minmax(0, 22rem) minmax(0, 1fr); gap: var(--fs-sp-4); align-items: start; margin-bottom: var(--fs-sp-5); }
.fs-tool .panel { margin-bottom: 0; }
.fs-tool-form .panel-body { display: flex; flex-direction: column; gap: var(--fs-sp-3); padding: var(--fs-sp-4); }
.fs-tool-form .form-label { margin-bottom: var(--fs-sp-1); font-weight: 500; }
.fs-tool-form .form-text { margin-top: var(--fs-sp-1); }
.fs-tool-form .panel-footer { display: flex; flex-wrap: wrap; gap: var(--fs-sp-2); padding: var(--fs-sp-3) var(--fs-sp-4); }
.fs-tool-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: var(--fs-sp-2); min-height: 16rem; padding: var(--fs-sp-5); color: var(--fs-text-muted); text-align: center; }
.fs-tool-empty > i { font-size: var(--fs-fs-xl); opacity: .6; }
.fs-smart-pass { color: var(--fs-pass); font-weight: 600; }
.fs-smart-fail { color: var(--fs-block); font-weight: 600; }
.fs-smart-warn { color: var(--fs-warn); font-weight: 600; }
@media (max-width: 991.98px) { .fs-tool { grid-template-columns: minmax(0, 1fr); } }
</style>

<div class="fs-tool">
	<form method="post" action="diag_smart.php?view=<?=htmlspecialchars($view)?>" class="fs-tool-form">
		<div class="panel panel-default">
			<div class="panel-heading"><h2 class="panel-title"><?=htmlspecialchars($views[$view])?></h2></div>
<?php if (empty($devs)): ?>
			<div class="fs-tool-empty">
				<i class="fa-solid fa-hard-drive" aria-hidden="true"></i>
				<span><?=gettext('No drives were found.')?></span>
			</div>
<?php else: ?>
			<div class="panel-body">
				<div>
					<label class="form-label" for="device"><?=gettext('Drive')?></label>
					<select class="form-select fs-mono" id="device" name="device">
<?php foreach ($devs as $dev): ?>
						<option value="<?=htmlspecialchars($dev)?>"<?=($dev === $targetdev) ? ' selected' : ''?>>/dev/<?=htmlspecialchars($dev)?></option>
<?php endforeach; ?>
					</select>
				</div>
				<div>
					<label class="form-label" for="type"><?=htmlspecialchars($type_labels[$view])?></label>
					<select class="form-select" id="type" name="type">
<?php foreach ($type_lists[$view] as $k => $v): ?>
						<option value="<?=htmlspecialchars($k)?>"<?=($posted_type === (string)$k) ? ' selected' : ''?>><?=htmlspecialchars($v)?></option>
<?php endforeach; ?>
					</select>
<?php if ($view === 'test'): ?>
					<div class="form-text"><?=gettext('Conveyance tests are for ATA disks only. Tests run in the background on the drive; check the self-test log for the result.')?></div>
<?php endif; ?>
				</div>
			</div>
			<div class="panel-footer">
<?php if ($view === 'test'): ?>
				<button type="submit" class="btn btn-primary" name="action" value="test" data-fs-busy="true">
					<i class="fa-solid fa-stethoscope icon-embed-btn" aria-hidden="true"></i><?=gettext('Start test')?>
				</button>
				<button type="submit" class="btn btn-outline-danger" name="action" value="abort"
				    data-fs-confirm="<?=gettext('Abort all self-tests on the selected drive?')?>" data-fs-confirm-action="<?=gettext('Abort tests')?>">
					<i class="fa-solid fa-xmark icon-embed-btn" aria-hidden="true"></i><?=gettext('Abort tests')?>
				</button>
<?php else: ?>
				<button type="submit" class="btn btn-primary" name="action" value="<?=htmlspecialchars($view)?>" data-fs-busy="true">
					<i class="fa-regular fa-file-lines icon-embed-btn" aria-hidden="true"></i><?=gettext('View')?>
				</button>
<?php endif; ?>
			</div>
<?php endif; ?>
		</div>
	</form>

	<div class="panel panel-default">
		<div class="panel-heading">
			<h2 class="panel-title"><?=htmlspecialchars($result_title)?></h2>
<?php if ($output !== null): ?>
			<button type="button" class="btn btn-sm btn-outline-secondary" data-fs-copy="#smart-output">
				<i class="fa-regular fa-copy icon-embed-btn" aria-hidden="true"></i><?=gettext('Copy')?>
			</button>
<?php endif; ?>
		</div>
<?php if ($error !== null): ?>
		<div class="fs-tool-empty">
			<?=fs_badge('error', $error)?>
		</div>
<?php elseif ($output !== null): ?>
		<pre class="fs-console" id="smart-output"><?php foreach (preg_split('/(PASSED|FAILED|Warning)/', $output, -1, PREG_SPLIT_DELIM_CAPTURE) as $part): ?><?php if (isset($smart_marks[$part])): ?><span class="<?=htmlspecialchars($smart_marks[$part])?>"><?=htmlspecialchars($part)?></span><?php else: ?><?=htmlspecialchars($part)?><?php endif; ?><?php endforeach; ?></pre>
<?php else: ?>
		<div class="fs-tool-empty">
			<i class="fa-solid fa-hard-drive" aria-hidden="true"></i>
			<span><?=gettext('Pick a drive to read its S.M.A.R.T. health, attributes and logs, or to run a self-test.')?></span>
		</div>
<?php endif; ?>
	</div>
</div>

<?php
include("foot.inc");
