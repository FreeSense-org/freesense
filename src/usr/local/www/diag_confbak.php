<?php
/*
 * diag_confbak.php
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

##|+PRIV
##|*IDENT=page-diagnostics-configurationhistory
##|*NAME=Diagnostics: Configuration History
##|*DESCR=Allow access to the 'Diagnostics: Configuration History' page.
##|*WARN=standard-warning-root
##|*MATCH=diag_confbak.php*
##|-PRIV

require_once('guiconfig.inc');

if (isset($_POST['backupcount'])) {
	if (!empty($_POST['backupcount']) && (!is_numericint($_POST['backupcount']) || ($_POST['backupcount'] < 0))) {
		$input_errors[] = gettext('Invalid Backup Count specified');
	}

	if (!$input_errors) {
		if (is_numericint($_POST['backupcount'])) {
			config_set_path('system/backupcount', $_POST['backupcount']);
			$changedescr = config_get_path('system/backupcount');
		} elseif (empty($_POST['backupcount'])) {
			config_del_path('system/backupcount');
			$changedescr = gettext('platform default');
		}
		write_config(sprintf(gettext('Changed backup revision count to %s'), $changedescr));
		$savemsg = sprintf(gettext('Keeping up to %s configuration backups.'), htmlspecialchars($changedescr));
	}
}

$confvers = unserialize_data(file_get_contents(g_get('cf_conf_path') . '/backup/backup.cache'), []);

/* backups are named by their timestamp */
if (($_POST['newver'] != "") && is_numericint($_POST['newver'])) {
	if (config_restore(g_get('conf_path') . '/backup/config-' . $_POST['newver'] . '.xml', htmlspecialchars($confvers[$_POST['newver']]['description']))) {
		$savemsg = sprintf(gettext('Successfully reverted configuration to timestamp %1$s with description "%2$s".%3$s%3$sTo activate the changes, manually reboot or apply/reload relevant features.'), date(gettext("n/j/y H:i:s"), $_POST['newver']), htmlspecialchars($confvers[$_POST['newver']]['description']), '<br/>');
	} else {
		$savemsg = gettext("Unable to revert to the selected configuration.");
	}
}

if (($_POST['rmver'] != "") && is_numericint($_POST['rmver'])) {
	unlink_if_exists(g_get('conf_path') . '/backup/config-' . $_POST['rmver'] . '.xml');
	$savemsg = sprintf(gettext('Deleted backup with timestamp %1$s and description "%2$s".'), date(gettext("n/j/y H:i:s"), $_POST['rmver']), htmlspecialchars($confvers[$_POST['rmver']]['description']));
}

if ($_REQUEST['getcfg'] != "") {
	$_REQUEST['getcfg'] = basename($_REQUEST['getcfg']);
	send_user_download('file',
				g_get('conf_path') . '/backup/config-' . $_REQUEST['getcfg'] . '.xml',
				'config-' . config_get_path('system/hostname') . '.' . config_get_path('system/domain') . "-{$_REQUEST['getcfg']}.xml");
}

if (($_REQUEST['compare'] == 'compare') && isset($_REQUEST['oldtime']) && isset($_REQUEST['newtime']) &&
    (is_numeric($_REQUEST['oldtime'])) &&
    (is_numeric($_REQUEST['newtime']) || ($_REQUEST['newtime'] == 'current'))) {
	$diff = "";
	$oldfile = g_get('conf_path') . '/backup/config-' . $_REQUEST['oldtime'] . '.xml';
	$oldtime = (int)$_REQUEST['oldtime'];
	if ($_REQUEST['newtime'] == 'current') {
		$newfile = g_get('conf_path') . '/config.xml';
		$newtime = (int)config_get_path('revision/time');
	} else {
		$newfile = g_get('conf_path') . '/backup/config-' . $_REQUEST['newtime'] . '.xml';
		$newtime = (int)$_REQUEST['newtime'];
	}
	if (file_exists($oldfile) && file_exists($newfile)) {
		exec("/usr/bin/diff -u " . escapeshellarg($oldfile) . " " . escapeshellarg($newfile), $diff);
	}
} elseif ($_REQUEST['compare'] == 'compare') {
	$input_errors[] = gettext('Select an older configuration in the "Old" column and a newer one in the "New" column to compare them.');
}

cleanup_backupcache(false);
$confvers = get_backups();
unset($confvers['versions']);

$pgtitle = [gettext('Diagnostics'), htmlspecialchars(gettext('Backup & Restore')), gettext('Configuration History')];
$pglinks = ['', 'diag_backup.php', '@self'];
fs_page_action(gettext('Settings'), '#', 'fa-gear', 'secondary', ['data-fs-modal' => '#confbak-settings']);
include('head.inc');

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($savemsg) {
	print_info_box($savemsg, 'success');
}

fs_tabs('diagnostics-backup', 'diag_confbak.php');

if ($diff !== null && $diff !== ""):
?>
<div class="panel panel-default">
	<div class="panel-heading">
		<h2 class="panel-title">
			<i class="fa-solid fa-right-left me-1" aria-hidden="true"></i>
			<?=htmlspecialchars(sprintf(gettext('Changes from %1$s to %2$s'), date(gettext("n/j/y H:i:s"), $oldtime), date(gettext("n/j/y H:i:s"), $newtime)))?>
		</h2>
	</div>
	<div class="panel-body">
<?php if (empty($diff)): ?>
		<p class="fs-muted mb-0"><?=gettext('The two configurations are identical.')?></p>
<?php else: ?>
		<pre class="fs-diff"><?php
	foreach ($diff as $line) {
		$kind = ['+' => 'add', '-' => 'del', '@' => 'hunk'][substr($line, 0, 1)] ?? 'ctx';
		echo '<span class="fs-diff-' . $kind . '">' . htmlentities($line) . "</span>";
	}
?></pre>
<?php endif; ?>
	</div>
</div>
<?php
endif;

$current_time = config_get_path('revision/time');
?>
<form id="confbak-compare" action="diag_confbak.php" method="get"></form>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Configuration History'),
	'search' => gettext('Search changes…'),
	'noun' => gettext('backups'),
	'noun_one' => gettext('backup'),
	'actions' => '<button type="submit" form="confbak-compare" name="compare" value="compare" class="btn btn-sm btn-outline-secondary">'
	    . '<i class="fa-solid fa-right-left icon-embed-btn" aria-hidden="true"></i>' . htmlspecialchars(gettext('Compare')) . '</button>',
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover confbak-table">
			<thead>
				<tr>
					<th class="fs-col-icon" title="<?=gettext('Older configuration to compare')?>"><?=gettext('Old')?></th>
					<th class="fs-col-icon" title="<?=gettext('Newer configuration to compare')?>"><?=gettext('New')?></th>
					<th data-fs-search><?=gettext('Date')?></th>
					<th data-fs-search><?=gettext('Configuration Change')?></th>
					<th><?=gettext('Version')?></th>
					<th><?=gettext('Size')?></th>
					<th class="fs-col-actions"><span class="visually-hidden"><?=gettext('Actions')?></span></th>
				</tr>
			</thead>
			<tbody>
				<tr class="fs-row-current" data-fs-static>
					<td class="fs-col-icon"></td>
					<td class="fs-col-icon"><input class="form-check-input" type="radio" name="newtime" value="current" form="confbak-compare" aria-label="<?=gettext('Compare with the current configuration')?>"></td>
					<td><?=date(gettext("n/j/y H:i:s"), $current_time)?></td>
					<td><?=fs_badge('active', gettext('Current'))?> <?=htmlspecialchars(config_get_path('revision/description'))?></td>
					<td class="fs-mono"><?=htmlspecialchars(config_get_path('version'))?></td>
					<td class="fs-mono"><?=format_bytes(filesize("/conf/config.xml"))?></td>
					<td class="fs-col-actions"></td>
				</tr>
<?php
$c = 0;
$versions = is_array($confvers) ? $confvers : [];
foreach ($versions as $version):
	$time = (int)$version['time'];
	$date = ($time != 0) ? date(gettext("n/j/y H:i:s"), $time) : gettext("Unknown");
	$c++;
?>
				<tr>
					<td class="fs-col-icon"><input class="form-check-input" type="radio" name="oldtime" value="<?=$time?>" form="confbak-compare" aria-label="<?=htmlspecialchars(sprintf(gettext('Compare from %s'), $date))?>"></td>
					<td class="fs-col-icon">
<?php	if ($c < count($versions)): ?>
						<input class="form-check-input" type="radio" name="newtime" value="<?=$time?>" form="confbak-compare" aria-label="<?=htmlspecialchars(sprintf(gettext('Compare to %s'), $date))?>">
<?php	endif; ?>
					</td>
					<td class="text-nowrap"><?=$date?></td>
					<td><?=htmlspecialchars($version['description'])?></td>
					<td class="fs-mono"><?=htmlspecialchars($version['version'])?></td>
					<td class="fs-mono text-nowrap"><?=format_bytes($version['filesize'])?></td>
					<td class="fs-col-actions">
						<?=fs_row_actions([
							['custom', 'diag_confbak.php?newver=' . $time, $date, [
								'icon' => 'fa-arrow-rotate-left',
								'label' => sprintf(gettext('Restore the configuration from %s'), $date),
								'post' => true,
								'confirm' => sprintf(gettext('Restore the configuration from %s?'), $date),
								'detail' => gettext('It replaces the current configuration. Reboot, or apply the affected settings, to activate it.'),
								'confirm_action' => gettext('Restore'),
							]],
							['custom', 'diag_confbak.php?getcfg=' . $time, $date, [
								'icon' => 'fa-download',
								'label' => sprintf(gettext('Download the configuration from %s'), $date),
							]],
							['delete', 'diag_confbak.php?rmver=' . $time, $date, ['thing' => gettext('backup')]],
						])?>
					</td>
				</tr>
<?php
endforeach;

if (empty($versions)) {
	fs_empty_row(7, gettext('No backups yet. A backup is kept each time the configuration changes.'));
}
?>
			</tbody>
		</table>
	</div>
	<div class="panel-footer small fs-muted">
		<i class="fa-solid fa-circle-info" aria-hidden="true"></i>
		<?=gettext('To compare two configurations, pick the older one under "Old" and the newer one under "New", then press Compare.')?>
	</div>
</div>

<style>
.fs-diff { margin: 0; max-height: 70vh; overflow: auto; font-size: .8rem; line-height: 1.45; white-space: pre-wrap; }
.fs-diff span { display: block; padding: 0 .5rem; }
.fs-diff-add { background: color-mix(in srgb, var(--fs-success, #2fb36c) 18%, transparent); }
.fs-diff-del { background: color-mix(in srgb, var(--fs-danger, #e5484d) 18%, transparent); }
.fs-diff-hunk { color: var(--fs-text-muted); }
</style>
<?php
/* retention settings */
fs_modal_form_begin('confbak-settings', gettext('Configuration history settings'), 'diag_confbak.php', [],
    ($input_errors && isset($_POST['backupcount'])) ? ['backupcount' => $_POST['backupcount']] : null);
$space = exec("/usr/bin/du -sh /conf/backup | /usr/bin/awk '{print $1;}'");
?>
	<div class="mb-3">
		<label class="form-label" for="confbak-count"><?=gettext('Maximum backups')?></label>
		<input class="form-control" type="number" min="0" id="confbak-count" name="backupcount" value="<?=htmlspecialchars(config_get_path('system/backupcount'))?>" placeholder="<?=htmlspecialchars(g_get('default_config_backup_count'))?>">
		<div class="form-text"><?=gettext('Older backups are removed beyond this number. 0 keeps none; leave empty for the default.')?></div>
	</div>
	<p class="fs-muted mb-0"><i class="fa-solid fa-hard-drive me-1" aria-hidden="true"></i><?=sprintf(gettext('Backups currently use %s.'), htmlspecialchars($space))?></p>
<?php
fs_modal_form_end(gettext('Save'), 'Submit', gettext('Save'), 'fa-floppy-disk');

include("foot.inc");
