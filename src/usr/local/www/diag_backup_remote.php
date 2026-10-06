<?php
/*
 * diag_backup_remote.php
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

##|+PRIV
##|*IDENT=page-diagnostics-backup-restore-remote
##|*NAME=Diagnostics: Backup & Restore: Remote Backup
##|*DESCR=Allow access to the 'Diagnostics: Backup & Restore: Remote Backup' pages.
##|*WARN=standard-warning-root
##|*MATCH=diag_backup_remote.php*
##|*MATCH=diag_backup_remote_edit.php*
##|-PRIV

/* Uploads can take a while on slow links. */
ini_set('max_execution_time', '0');

$omit_nocacheheaders = true;
require_once("guiconfig.inc");
/* backup.inc must be loaded at file scope: rrd_data_xml() uses its globals. */
require_once("backup.inc");
require_once("remote_backup.inc");

$weekdays = array(
	'0' => gettext('Sunday'), '1' => gettext('Monday'), '2' => gettext('Tuesday'),
	'3' => gettext('Wednesday'), '4' => gettext('Thursday'), '5' => gettext('Friday'),
	'6' => gettext('Saturday'),
);

$browse = null;
$browse_target = null;

if (!empty($_GET['saved'])) {
	$savemsg = gettext('The backup target was saved. Use "Test connection" to check it.');
}

if ($_POST['save']) {
	$result = remote_backup_save_settings($_POST);
	$input_errors = $result['input_errors'];
	if (empty($input_errors)) {
		$savemsg = gettext('Remote backup settings saved.');
	}
} elseif (in_array($_POST['act'] ?? '', array('del', 'toggle', 'test', 'run', 'browse', 'download'), true)) {
	list(, $t) = remote_backup_find_target((string)($_POST['id'] ?? ''));
	if ($t === null) {
		$input_errors[] = gettext('The backup target no longer exists.');
	} else {
		$label = htmlspecialchars($t['descr'] ?: $t['id']);
		switch ($_POST['act']) {
		case 'del':
			remote_backup_delete_target($t['id']);
			header("Location: diag_backup_remote.php");
			exit;
		case 'toggle':
			remote_backup_toggle_target($t['id']);
			header("Location: diag_backup_remote.php");
			exit;
		case 'test':
			$result = remote_backup_test_target($t);
			if ($result['ok']) {
				$savemsg = sprintf(gettext('%1$s: %2$s'), $label, htmlspecialchars($result['message']));
			} else {
				$input_errors[] = sprintf(gettext('%1$s: %2$s'), $label, htmlspecialchars($result['message']));
			}
			break;
		case 'run':
			$results = remote_backup_run($t['id'], 'manual', true);
			foreach ($results as $result) {
				if ($result['status'] === 'failed') {
					$input_errors[] = sprintf(gettext('%1$s: %2$s'), $label, htmlspecialchars($result['message']));
				} else {
					$savemsg = sprintf(gettext('%1$s: %2$s'), $label, htmlspecialchars($result['message']));
				}
			}
			break;
		case 'browse':
			$browse = remote_backup_list($t);
			$browse_target = $t;
			if (!is_array($browse)) {
				$input_errors[] = sprintf(gettext('Could not list %1$s: %2$s'), $label, htmlspecialchars($browse));
				$browse = null;
			}
			break;
		case 'download':
			$fetched = remote_backup_fetch($t, (string)($_POST['name'] ?? ''));
			if (isset($fetched['error'])) {
				$input_errors[] = htmlspecialchars($fetched['error']);
			} else {
				send_user_download('data', $fetched['data'], basename((string)$_POST['name']));
				exit;
			}
			break;
		}
	}
}

$settings = remote_backup_settings();
$pconfig = $_POST['save'] ? $_POST : array(
	'enable' => !empty($settings['enable']),
	'passphrase' => empty($settings['passphrase']) ? '' : DMYPWD,
	'frequency' => $settings['frequency'] ?? 'daily',
	'hour' => $settings['hour'] ?? '2',
	'minute' => $settings['minute'] ?? '17',
	'weekday' => $settings['weekday'] ?? '0',
	'onchange' => !empty($settings['onchange']),
	'notify_success' => !empty($settings['notify_success']),
);
$state = remote_backup_state_read();
$types = remote_backup_types();

$pgtitle = array(gettext('Diagnostics'), htmlspecialchars(gettext('Backup & Restore')), gettext('Remote Backup'));
$pglinks = array('', 'diag_backup.php', '@self');
include("head.inc");

$tab_array = array();
$tab_array[] = array(htmlspecialchars(gettext('Backup & Restore')), false, 'diag_backup.php');
$tab_array[] = array(gettext('Configuration History'), false, 'diag_confbak.php');
$tab_array[] = array(gettext('Remote Backup'), true, 'diag_backup_remote.php');
display_top_tabs($tab_array);

if ($input_errors) {
	print_input_errors($input_errors);
}
if ($savemsg) {
	print_info_box($savemsg, 'success');
}

if (is_array($browse)):
?>
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=sprintf(gettext('Backups on %s'), htmlspecialchars($browse_target['descr'] ?: $browse_target['id']))?></h2></div>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-striped table-hover table-sm">
				<thead><tr>
					<th><?=gettext('File')?></th>
					<th><?=gettext('Actions')?></th>
				</tr></thead>
				<tbody>
<?php	if (empty($browse)): ?>
					<tr><td colspan="2"><?=gettext('No backups of this firewall were found on the target.')?></td></tr>
<?php	endif;
	foreach ($browse as $name): ?>
					<tr>
						<td><?=htmlspecialchars($name)?></td>
						<td>
							<form method="post" action="diag_backup_remote.php" class="d-inline">
								<input type="hidden" name="act" value="download" />
								<input type="hidden" name="id" value="<?=htmlspecialchars($browse_target['id'])?>" />
								<input type="hidden" name="name" value="<?=htmlspecialchars($name)?>" />
								<button type="submit" class="btn btn-xs btn-primary">
									<i class="fa-solid fa-download"></i> <?=gettext('Download')?>
								</button>
							</form>
							<form method="post" action="diag_backup.php" class="d-inline">
								<input type="hidden" name="remote_restore" value="1" />
								<input type="hidden" name="target" value="<?=htmlspecialchars($browse_target['id'])?>" />
								<input type="hidden" name="name" value="<?=htmlspecialchars($name)?>" />
								<button type="submit" class="btn btn-xs btn-danger">
									<i class="fa-solid fa-undo"></i> <?=gettext('Restore')?>
								</button>
							</form>
						</td>
					</tr>
<?php	endforeach; ?>
				</tbody>
			</table>
		</div>
		<p class="text-muted"><?=gettext('Restore downloads the file, decrypts it with the current passphrase and opens the normal restore review on the Backup & Restore tab. A downloaded file can also be restored there manually with "Configuration file is encrypted" and the passphrase.')?></p>
	</div>
</div>
<?php
endif;

$form = new Form(false);
$section = new Form_Section(gettext('Remote Backup Settings'));
$section->addInput(new Form_Checkbox(
	'enable',
	gettext('Enable'),
	gettext('Upload encrypted configuration backups to the targets below'),
	$pconfig['enable']
));
$section->addPassword(new Form_Input(
	'passphrase',
	'*' . gettext('Encryption passphrase'),
	'password',
	$pconfig['passphrase']
))->setHelp(sprintf(gettext('Every backup is encrypted on the firewall before it is uploaded, in the same format as an encrypted download. ' .
    'At least %d characters. %sStore this passphrase somewhere other than the firewall%s: without it the backups cannot be restored. ' .
    'Changing it only affects new backups.'), REMOTE_BACKUP_MIN_PASSPHRASE, '<strong>', '</strong>'));
$section->addInput(new Form_Select(
	'frequency',
	gettext('Schedule'),
	$pconfig['frequency'],
	array(
		'none' => gettext('No schedule (on change and manual only)'),
		'hourly' => gettext('Hourly'),
		'daily' => gettext('Daily'),
		'weekly' => gettext('Weekly'),
	)
))->setHelp(gettext('Scheduled runs are skipped for a target when the configuration has not changed since its last upload.'));
$group = new Form_Group(gettext('Time'));
$group->add(new Form_Select(
	'weekday',
	gettext('Weekday'),
	$pconfig['weekday'],
	$weekdays
))->setHelp(gettext('Weekday (weekly)'));
$group->add(new Form_Input(
	'hour',
	gettext('Hour'),
	'number',
	$pconfig['hour'],
	array('min' => 0, 'max' => 23)
))->setHelp(gettext('Hour (daily, weekly)'));
$group->add(new Form_Input(
	'minute',
	gettext('Minute'),
	'number',
	$pconfig['minute'],
	array('min' => 0, 'max' => 59)
))->setHelp(gettext('Minute'));
$section->add($group);
$section->addInput(new Form_Checkbox(
	'onchange',
	gettext('On change'),
	gettext('Also back up within five minutes after each configuration change'),
	$pconfig['onchange']
));
$section->addInput(new Form_Checkbox(
	'notify_success',
	gettext('Notify on success'),
	gettext('Send a notification after each successful upload'),
	$pconfig['notify_success']
))->setHelp(sprintf(gettext('Failures always raise a notice and use the channels configured in %sSystem > Advanced > Notifications%s.'),
    '<a href="system_advanced_notifications.php">', '</a>'));
$section->addInput(new Form_Button(
	'save',
	gettext('Save'),
	null,
	'fa-solid fa-save'
))->addClass('btn-primary');
$form->add($section);
print($form);
?>

<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('Backup Targets')?></h2></div>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-striped table-hover table-sm table-rowdblclickedit">
				<thead><tr>
					<th><?=gettext('Status')?></th>
					<th><?=gettext('Description')?></th>
					<th><?=gettext('Type')?></th>
					<th><?=gettext('Last success')?></th>
					<th><?=gettext('Last result')?></th>
					<th><?=gettext('Actions')?></th>
				</tr></thead>
				<tbody>
<?php
$targets = remote_backup_targets();
if (empty($targets)): ?>
					<tr><td colspan="6"><?=gettext('No backup targets are configured.')?></td></tr>
<?php
endif;
foreach ($targets as $t):
	$tid = $t['id'];
	$st = $state[$tid] ?? array();
	if (!empty($st['last_error'])) {
		$icon = 'fa-solid fa-times-circle text-danger';
		$icon_title = gettext('Failed');
	} elseif (!empty($st['last_success'])) {
		$icon = 'fa-solid fa-check-circle text-success';
		$icon_title = gettext('OK');
	} else {
		$icon = 'fa-regular fa-circle text-muted';
		$icon_title = gettext('Not run yet');
	}
	$type_label = explode(' (', $types[$t['type']] ?? $t['type'])[0];
?>
					<tr<?=empty($t['enable']) ? ' class="disabled"' : ''?> ondblclick="document.location='diag_backup_remote_edit.php?id=<?=htmlspecialchars($tid)?>';">
						<td><i class="<?=$icon?>" title="<?=$icon_title?>"></i></td>
						<td><?=htmlspecialchars($t['descr'] ?: $tid)?></td>
						<td><?=htmlspecialchars($type_label)?></td>
						<td><?=!empty($st['last_success']) ? htmlspecialchars(date('Y-m-d H:i', $st['last_success'])) .
						    '<br /><small>' . htmlspecialchars($st['last_file'] ?? '') . '</small>' : gettext('Never')?></td>
						<td><?=!empty($st['last_error']) ? '<span class="text-danger">' . htmlspecialchars($st['last_error']) . '</span>' :
						    (!empty($st['last_attempt']) ? gettext('OK') : '')?></td>
						<td>
							<a class="fa-solid fa-pencil" title="<?=gettext('Edit target')?>" href="diag_backup_remote_edit.php?id=<?=htmlspecialchars($tid)?>"></a>
<?php	if (!empty($t['enable'])): ?>
							<a class="fa-solid fa-ban" title="<?=gettext('Disable target')?>" href="?act=toggle&amp;id=<?=htmlspecialchars($tid)?>" usepost></a>
<?php	else: ?>
							<a class="fa-regular fa-square-check" title="<?=gettext('Enable target')?>" href="?act=toggle&amp;id=<?=htmlspecialchars($tid)?>" usepost></a>
<?php	endif; ?>
							<a class="fa-solid fa-plug" title="<?=gettext('Test connection')?>" href="?act=test&amp;id=<?=htmlspecialchars($tid)?>" usepost></a>
							<a class="fa-solid fa-cloud-arrow-up" title="<?=gettext('Back up now')?>" href="?act=run&amp;id=<?=htmlspecialchars($tid)?>" usepost></a>
							<a class="fa-solid fa-folder-open" title="<?=gettext('Browse and restore')?>" href="?act=browse&amp;id=<?=htmlspecialchars($tid)?>" usepost></a>
							<a class="fa-solid fa-trash-can" title="<?=gettext('Delete target')?>" href="?act=del&amp;id=<?=htmlspecialchars($tid)?>" usepost></a>
						</td>
					</tr>
<?php
endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>

<nav class="action-buttons">
	<a href="diag_backup_remote_edit.php" class="btn btn-sm btn-success">
		<i class="fa-solid fa-plus icon-embed-btn"></i>
		<?=gettext('Add')?>
	</a>
</nav>

<?php
print_info_box(gettext('Backups are encrypted with AES-256 (OpenSSL, PBKDF2) before they leave the firewall. ' .
    'A .sha256 checksum file is uploaded beside each backup to detect corruption; it does not prove who wrote the file, ' .
    'so restrict write access to the storage location. Deleting a target here does not delete its uploaded backups.'), 'info', false);
?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	function showSchedule() {
		var frequency = $('#frequency').val();
		$('#weekday').prop('disabled', frequency !== 'weekly');
		$('#hour').prop('disabled', frequency !== 'daily' && frequency !== 'weekly');
		$('#minute').prop('disabled', frequency === 'none');
	}
	$('#frequency').on('change', showSchedule);
	showSchedule();
	/* Disabled inputs are not posted; re-enable them before submit. */
	$('form').on('submit', function() {
		$('#weekday, #hour, #minute').prop('disabled', false);
	});
});
//]]>
</script>

<?php
include("foot.inc");
