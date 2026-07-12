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

$pgtitle = [gettext('System'), gettext('Boot Environments')];
include('head.inc');
if ($input_errors) print_input_errors($input_errors);
if ($savemsg) print_info_box($savemsg, 'success');

if (!$available || !$compatible): ?>
	<div class="alert alert-info"><?=gettext('ZFS boot environments are unavailable. This feature requires a compatible ZFS root installation and bectl support.')?></div>
<?php else: ?>
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('Boot Environments')?></h2></div>
	<div class="panel-body table-responsive">
	<table class="table table-striped table-hover table-sm">
		<thead><tr><th><?=gettext('Name')?></th><th><?=gettext('State')?></th><th><?=gettext('Health')?></th><th><?=gettext('Version')?></th><th><?=gettext('Created')?></th><th><?=gettext('Space')?></th><th><?=gettext('Description')?></th><th><?=gettext('Actions')?></th></tr></thead>
		<tbody>
		<?php foreach ($environments as $be): $meta = $be['metadata'] ?? []; ?>
		<tr>
			<td><?=htmlspecialchars($be['name'])?></td>
			<td><?php $states=[]; if($be['active_now'])$states[]=gettext('Current'); if($be['active_reboot'])$states[]=gettext('Next'); if($be['active_once'])$states[]=gettext('Once'); echo htmlspecialchars(implode(', ', $states) ?: '-'); ?></td>
			<td><?=htmlspecialchars($meta['health'] ?? '-')?></td>
			<td><?=htmlspecialchars($meta['version'] ?? '-')?></td>
			<td><?=htmlspecialchars($meta['created'] ?? $be['created'])?></td>
			<td><?=htmlspecialchars($be['space'])?></td>
			<td><?=htmlspecialchars($meta['description'] ?? '')?></td>
			<td>
				<form method="post" class="d-inline">
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

<div class="row">
	<div class="col-sm-6"><div class="panel panel-default"><div class="panel-heading"><h2 class="panel-title"><?=gettext('Create')?></h2></div><div class="panel-body">
		<form method="post"><div class="form-group"><label><?=gettext('Name')?></label><input class="form-control" name="name" required pattern="[A-Za-z0-9][A-Za-z0-9._-]{0,63}"></div><button class="btn btn-primary" name="action" value="create"><?=gettext('Create from current')?></button></form>
		<hr>
		<form method="post"><div class="form-group"><label><?=gettext('Clone existing environment')?></label><select class="form-control" name="target"><?php foreach ($environments as $be): ?><option value="<?=htmlspecialchars($be['name'])?>"><?=htmlspecialchars($be['name'])?></option><?php endforeach; ?></select></div><div class="form-group"><label><?=gettext('New name')?></label><input class="form-control" name="name" required pattern="[A-Za-z0-9][A-Za-z0-9._-]{0,63}"></div><button class="btn btn-default" name="action" value="clone"><?=gettext('Clone')?></button></form>
	</div></div></div>
	<div class="col-sm-6"><div class="panel panel-default"><div class="panel-heading"><h2 class="panel-title"><?=gettext('Settings')?></h2></div><div class="panel-body">
		<form method="post">
			<div class="checkbox"><label><input type="checkbox" name="enabled" <?=($settings['enabled'] ?? true) ? 'checked' : ''?>> <?=gettext('Create boot environments automatically during upgrades')?></label></div>
			<div class="checkbox"><label><input type="checkbox" name="automatic_rollback" <?=($settings['automatic_rollback'] ?? true) ? 'checked' : ''?>> <?=gettext('Automatically roll back failed first boots')?></label></div>
			<div class="form-group"><label><?=gettext('Automatic environments to retain')?></label><input class="form-control" type="number" min="1" max="10" name="retention_auto" value="<?=htmlspecialchars($settings['retention_auto'] ?? 3)?>"></div>
			<div class="form-group"><label><?=gettext('Health timeout (seconds)')?></label><input class="form-control" type="number" min="60" max="900" name="health_timeout" value="<?=htmlspecialchars($settings['health_timeout'] ?? 300)?>"></div>
			<button class="btn btn-primary" name="action" value="settings"><?=gettext('Save')?></button>
		</form>
	</div></div></div>
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
<?php endif; include('foot.inc');
