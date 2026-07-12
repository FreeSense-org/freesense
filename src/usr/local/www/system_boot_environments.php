<?php
/*
 * FreeSense ZFS Boot Environments
 * SPDX-License-Identifier: Apache-2.0
 */

##|+PRIV
##|*IDENT=page-system-boot-environments
##|*NAME=System: Boot Environments
##|*DESCR=Allow viewing and managing ZFS boot environments.
##|*MATCH=system_boot_environments.php*
##|-PRIV

require_once('guiconfig.inc');

const BECTL = '/usr/local/sbin/freesense-be';

function be_command(array $arguments, &$output = null): int {
	$command = BECTL . ' ' . implode(' ', array_map('escapeshellarg', $arguments));
	$lines = [];
	exec($command . ' 2>&1', $lines, $status);
	$output = implode("\n", $lines);
	return $status;
}

function be_valid_name(string $name): bool {
	return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $name) === 1;
}

function be_version_parts(string $version): array {
	if (preg_match('/^(.*?)(\d{8}(?:[._-]\d{4,6})?)$/', $version, $matches)) {
		return [rtrim($matches[1], '._-'), $matches[2]];
	}
	return [$version, ''];
}

function be_created_parts(string $created): array {
	if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}(?::\d{2})?)(Z)?/', $created, $matches)) {
		return [$matches[1], $matches[2] . (!empty($matches[3]) ? ' UTC' : '')];
	}
	return [$created, ''];
}

if ($_POST) {
	$action = $_POST['action'] ?? '';
	if ($action === 'settings') {
		$retention = filter_var($_POST['retention_auto'] ?? 3, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10]]);
		$timeout = filter_var($_POST['health_timeout'] ?? 300, FILTER_VALIDATE_INT, ['options' => ['min_range' => 60, 'max_range' => 900]]);
		if ($retention === false || $timeout === false) {
			$input_errors[] = gettext('Invalid retention or health timeout value.');
		} else {
			config_set_path('system/bootenv/enabled', isset($_POST['enabled']));
			config_set_path('system/bootenv/automatic_rollback', isset($_POST['automatic_rollback']));
			config_set_path('system/bootenv/retention_auto', $retention);
			config_set_path('system/bootenv/health_timeout', $timeout);
			write_config(gettext('Updated ZFS boot environment settings.'));
			$savemsg = gettext('Boot environment settings saved.');
		}
	} else {
		$name = trim($_POST['name'] ?? '');
		$args = [];
		if (!be_valid_name($name)) {
			$input_errors[] = gettext('Invalid boot environment name.');
		} elseif ($action === 'create' || $action === 'destroy' || $action === 'activate' || $action === 'activate-once') {
			$args = [$action, $name];
		} elseif ($action === 'clone' || $action === 'rename') {
			$target = trim($_POST['target'] ?? '');
			if (!be_valid_name($target)) $input_errors[] = gettext('Invalid target boot environment name.');
			else $args = [$action, $name, $target];
		} elseif ($action === 'describe') {
			$args = [$action, $name, substr(trim($_POST['description'] ?? ''), 0, 256)];
		} else {
			$input_errors[] = gettext('Unknown boot environment action.');
		}
		if (!$input_errors && be_command($args, $result) !== 0) {
			$input_errors[] = $result;
		} elseif (!$input_errors) {
			$savemsg = gettext('Boot environment action completed.');
			if ($action === 'activate-once' && ($_POST['reboot'] ?? '') === '1') {
				mwexec_bg('/sbin/shutdown -r now');
			}
		}
	}
}

$raw = '';
$available = is_executable(BECTL) && be_command(['list'], $raw) === 0;
$data = $available ? json_decode($raw, true) : null;
$compatible = (bool)($data['status']['compatible'] ?? false);
$environments = $data['environments'] ?? [];
$settings = config_get_path('system/bootenv', []);
$view = $_GET['view'] ?? 'environments';
if (!in_array($view, ['environments', 'create', 'settings'], true)) {
	$view = 'environments';
}

// Keep the running environment at the top, followed by the newest snapshots.
usort($environments, static function (array $left, array $right): int {
	$current = (int)!empty($right['active_now']) <=> (int)!empty($left['active_now']);
	if ($current !== 0) {
		return $current;
	}
	$left_created = strtotime($left['metadata']['created'] ?? $left['created'] ?? '') ?: 0;
	$right_created = strtotime($right['metadata']['created'] ?? $right['created'] ?? '') ?: 0;
	return $right_created <=> $left_created;
});

$pgtitle = [gettext('System'), gettext('Boot Environments')];
include('head.inc');
if ($input_errors) print_input_errors($input_errors);
if ($savemsg) print_info_box($savemsg, 'success');

?>
<style>
.bootenv-table {
	table-layout: fixed;
	width: 100%;
	margin-bottom: 0;
}
.bootenv-table th,
.bootenv-table td {
	vertical-align: middle;
	overflow-wrap: anywhere;
}
.bootenv-table__name {
	overflow-wrap: anywhere;
	word-break: break-word;
}
.bootenv-table__actions {
	white-space: nowrap;
}
.bootenv-cell-lines {
	display: flex;
	flex-direction: column;
	gap: .15rem;
	line-height: 1.25;
}
.bootenv-cell-lines__secondary {
	color: var(--fs-text-muted, #9aa8ba);
	font-size: .9em;
}
.bootenv-actions {
	display: inline-flex;
	align-items: center;
	gap: .25rem;
	white-space: nowrap;
}
.bootenv-actions .btn {
	margin: 0;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	width: 2rem;
	height: 2rem;
	padding: 0;
	flex: 0 0 2rem;
}
.bootenv-form-grid {
	display: grid;
	gap: 1rem;
}
.bootenv-form-grid .panel {
	margin-bottom: 0;
}
.bootenv-form-grid .panel-body {
	padding: 1.25rem 1.5rem 1.5rem;
}
.bootenv-form-grid .form-control {
	width: 100%;
	min-width: 0;
	max-width: 100%;
}
.bootenv-form-grid .text-muted {
	margin: 0 0 1.25rem;
}
.bootenv-workflow-form {
	display: grid;
	grid-template-columns: minmax(0, 1fr);
	gap: 1rem;
	margin: 0;
}
.bootenv-workflow-form--clone {
	grid-template-columns: repeat(2, minmax(0, 1fr));
}
.bootenv-workflow-form .form-group {
	margin: 0;
	padding: 0;
	border: 0;
	min-width: 0;
}
.bootenv-workflow-form .btn {
	margin: 0;
	white-space: nowrap;
	justify-self: start;
	grid-column: 1 / -1;
}
.bootenv-workflow-form select {
	min-width: 0;
	max-width: 100%;
	text-overflow: ellipsis;
}
.bootenv-settings .panel-body {
	padding: 1.25rem 1.5rem 1.5rem;
}
.bootenv-settings form {
	margin: 0;
}
.bootenv-settings__toggles {
	display: grid;
	gap: .65rem;
	margin-bottom: 1.5rem;
	padding-bottom: 1.5rem;
	border-bottom: 1px solid var(--fs-border-color, #303946);
}
.bootenv-settings__toggles .checkbox {
	margin: 0;
}
.bootenv-settings__fields {
	margin-bottom: 1.25rem;
}
@media (max-width: 900px) {
	.bootenv-workflow-form,
	.bootenv-workflow-form--clone {
		grid-template-columns: 1fr;
		align-items: stretch;
	}
}
@media (max-width: 991px) {
	.bootenv-table {
		min-width: 960px;
	}
}
</style>
<?php

if (!$available || !$compatible): ?>
	<div class="alert alert-info"><?=gettext('ZFS boot environments are unavailable. This feature requires a compatible ZFS root installation and bectl support.')?></div>
<?php else: ?>
<?php
$tab_array = [
	[gettext('Environments'), $view === 'environments', 'system_boot_environments.php?view=environments'],
	[gettext('Create & Clone'), $view === 'create', 'system_boot_environments.php?view=create'],
	[gettext('Settings'), $view === 'settings', 'system_boot_environments.php?view=settings'],
];
display_top_tabs($tab_array);
if ($view === 'environments'):
?>
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('Boot Environments')?></h2></div>
	<div class="panel-body table-responsive">
	<table class="table table-striped table-hover table-sm bootenv-table">
		<colgroup>
			<col style="width: 27%">
			<col style="width: 8%">
			<col style="width: 8%">
			<col style="width: 11%">
			<col style="width: 12%">
			<col style="width: 7%">
			<col style="width: 14%">
			<col style="width: 13%">
		</colgroup>
		<thead><tr><th><?=gettext('Name')?></th><th><?=gettext('State')?></th><th><?=gettext('Health')?></th><th><?=gettext('Version')?></th><th><?=gettext('Created')?></th><th><?=gettext('Space')?></th><th><?=gettext('Description')?></th><th><?=gettext('Actions')?></th></tr></thead>
		<tbody>
		<?php foreach ($environments as $be):
			$meta = $be['metadata'] ?? [];
			$states = [];
			if ($be['active_now']) $states[] = gettext('Current');
			if ($be['active_reboot']) $states[] = gettext('Next');
			if ($be['active_once']) $states[] = gettext('Once');
			$version_parts = be_version_parts((string)($meta['version'] ?? '-'));
			$created_parts = be_created_parts((string)($meta['created'] ?? $be['created']));
		?>
		<tr>
			<td class="bootenv-table__name"><?=htmlspecialchars($be['name'])?></td>
			<td><span class="bootenv-cell-lines"><?php if ($states): foreach ($states as $state): ?><span><?=htmlspecialchars($state)?></span><?php endforeach; else: ?><span>-</span><?php endif; ?></span></td>
			<td><?=htmlspecialchars($meta['health'] ?? '-')?></td>
			<td><span class="bootenv-cell-lines"><span><?=htmlspecialchars($version_parts[0])?></span><?php if ($version_parts[1] !== ''): ?><span class="bootenv-cell-lines__secondary"><?=htmlspecialchars($version_parts[1])?></span><?php endif; ?></span></td>
			<td><span class="bootenv-cell-lines"><span><?=htmlspecialchars($created_parts[0])?></span><?php if ($created_parts[1] !== ''): ?><span class="bootenv-cell-lines__secondary"><?=htmlspecialchars($created_parts[1])?></span><?php endif; ?></span></td>
			<td><?=htmlspecialchars($be['space'])?></td>
			<td><?=htmlspecialchars($meta['description'] ?? '')?></td>
			<td class="bootenv-table__actions">
				<form method="post" class="bootenv-actions">
					<input type="hidden" name="name" value="<?=htmlspecialchars($be['name'])?>">
					<button class="btn btn-xs btn-primary" name="action" value="activate" title="<?=gettext('Activate persistently')?>"><i class="fa-solid fa-star"></i></button>
					<button class="btn btn-xs btn-info" name="action" value="activate-once" title="<?=gettext('Activate once')?>"><i class="fa-solid fa-play"></i></button>
					<button class="btn btn-xs btn-warning" name="action" value="activate-once" title="<?=gettext('Activate once and reboot')?>" onclick="this.form.reboot.value='1'; return confirm('<?=gettext('Reboot into this boot environment now?')?>')"><i class="fa-solid fa-power-off"></i></button>
					<input type="hidden" name="reboot" value="0">
					<?php if (!$be['active_now'] && !$be['active_reboot'] && !$be['active_once'] && ($meta['health'] ?? '') !== 'pending'): ?>
					<button class="btn btn-xs btn-danger" name="action" value="destroy" title="<?=gettext('Delete')?>" onclick="return confirm('<?=gettext('Delete this boot environment?')?>')"><i class="fa-solid fa-trash"></i></button>
					<?php endif; ?>
				</form>
			</td>
		</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	</div>
</div>

<div class="panel panel-default"><div class="panel-heading"><h2 class="panel-title"><?=gettext('Edit Boot Environment')?></h2></div><div class="panel-body">
	<form method="post" class="form-inline">
		<select class="form-control" name="name"><?php foreach ($environments as $be): ?><option value="<?=htmlspecialchars($be['name'])?>"><?=htmlspecialchars($be['name'])?></option><?php endforeach; ?></select>
		<input class="form-control" name="target" placeholder="<?=gettext('New name')?>" pattern="[A-Za-z0-9][A-Za-z0-9._-]{0,63}">
		<button class="btn btn-default" name="action" value="rename"><?=gettext('Rename')?></button>
		<input class="form-control" name="description" maxlength="256" placeholder="<?=gettext('Description')?>">
		<button class="btn btn-default" name="action" value="describe"><?=gettext('Set description')?></button>
	</form>
</div></div>

<?php elseif ($view === 'create'): ?>
	<div class="bootenv-form-grid">
		<div class="panel panel-default"><div class="panel-heading"><h2 class="panel-title"><i class="fa-solid fa-plus-circle me-1"></i> <?=gettext('Create from Current')?></h2></div><div class="panel-body">
			<p class="text-muted"><?=gettext('Create a new boot environment as a snapshot of the currently running system.')?></p>
			<form method="post" action="?view=create" class="bootenv-workflow-form"><div class="form-group"><label><?=gettext('Environment name')?></label><input class="form-control" name="name" required pattern="[A-Za-z0-9][A-Za-z0-9._-]{0,63}" placeholder="<?=gettext('Example: before-firewall-change')?>"></div><button class="btn btn-primary" name="action" value="create"><i class="fa-solid fa-camera me-1"></i> <?=gettext('Create environment')?></button></form>
		</div></div>
		<div class="panel panel-default"><div class="panel-heading"><h2 class="panel-title"><i class="fa-solid fa-clone me-1"></i> <?=gettext('Clone Environment')?></h2></div><div class="panel-body">
			<p class="text-muted"><?=gettext('Copy an existing boot environment under a new name.')?></p>
			<form method="post" action="?view=create" class="bootenv-workflow-form bootenv-workflow-form--clone"><div class="form-group"><label><?=gettext('Source environment')?></label><select class="form-control" name="target"><?php foreach ($environments as $be): ?><option value="<?=htmlspecialchars($be['name'])?>"><?=htmlspecialchars($be['name'])?></option><?php endforeach; ?></select></div><div class="form-group"><label><?=gettext('New environment name')?></label><input class="form-control" name="name" required pattern="[A-Za-z0-9][A-Za-z0-9._-]{0,63}"></div><button class="btn btn-primary" name="action" value="clone"><i class="fa-solid fa-clone me-1"></i> <?=gettext('Clone environment')?></button></form>
		</div></div>
	</div>

<?php elseif ($view === 'settings'): ?>
	<div class="panel panel-default bootenv-settings"><div class="panel-heading"><h2 class="panel-title"><i class="fa-solid fa-shield-halved me-1"></i> <?=gettext('Upgrade Protection')?></h2></div><div class="panel-body">
		<form method="post" action="?view=settings">
			<div class="bootenv-settings__toggles"><div class="checkbox"><label><input type="checkbox" name="enabled" <?=($settings['enabled'] ?? true) ? 'checked' : ''?>> <?=gettext('Create boot environments automatically during upgrades')?></label></div>
			<div class="checkbox"><label><input type="checkbox" name="automatic_rollback" <?=($settings['automatic_rollback'] ?? true) ? 'checked' : ''?>> <?=gettext('Automatically roll back failed first boots')?></label></div></div>
			<div class="row bootenv-settings__fields"><div class="col-sm-6"><div class="form-group"><label><?=gettext('Automatic environments to retain')?></label><input class="form-control" type="number" min="1" max="10" name="retention_auto" value="<?=htmlspecialchars($settings['retention_auto'] ?? 3)?>"><span class="help-block"><?=gettext('Older automatically-created environments are removed after this limit.')?></span></div></div>
			<div class="col-sm-6"><div class="form-group"><label><?=gettext('Health timeout (seconds)')?></label><input class="form-control" type="number" min="60" max="900" name="health_timeout" value="<?=htmlspecialchars($settings['health_timeout'] ?? 300)?>"><span class="help-block"><?=gettext('Maximum time to wait for a successful first-boot health check.')?></span></div></div></div>
			<button class="btn btn-primary" name="action" value="settings"><i class="fa-solid fa-floppy-disk me-1"></i> <?=gettext('Save settings')?></button>
		</form>
	</div></div>
<?php endif; ?>
<?php endif; include('foot.inc');
