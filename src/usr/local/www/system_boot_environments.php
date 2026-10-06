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
			/* Store explicit strings: upgrade/health scripts read these nodes as text. */
			config_set_path('system/bootenv/enabled', isset($_POST['enabled']) ? 'true' : 'false');
			config_set_path('system/bootenv/automatic_rollback', isset($_POST['automatic_rollback']) ? 'true' : 'false');
			config_set_path('system/bootenv/retention_auto', $retention);
			config_set_path('system/bootenv/health_timeout', $timeout);
			write_config(gettext('Updated ZFS boot environment settings.'));
			$savemsg = gettext('Boot environment settings saved.');
		}
	} else {
		$name = trim($_POST['name'] ?? '');
		$commands = [];
		if (!be_valid_name($name)) {
			$input_errors[] = gettext('Invalid boot environment name.');
		} elseif ($action === 'create' || $action === 'destroy' || $action === 'activate' || $action === 'activate-once') {
			$commands[] = [$action, $name];
		} elseif ($action === 'clone' || $action === 'rename') {
			$target = trim($_POST['target'] ?? '');
			if (!be_valid_name($target)) $input_errors[] = gettext('Invalid target boot environment name.');
			else $commands[] = [$action, $name, $target];
		} elseif ($action === 'describe') {
			$commands[] = [$action, $name, substr(trim($_POST['description'] ?? ''), 0, 256)];
		} elseif ($action === 'edit') {
			/* the edit modal: description first (under the current name), then the rename if the name changed */
			$target = trim($_POST['target'] ?? '');
			if (!be_valid_name($target)) {
				$input_errors[] = gettext('Invalid target boot environment name.');
			} else {
				$commands[] = ['describe', $name, substr(trim($_POST['description'] ?? ''), 0, 256)];
				if ($target !== $name) {
					$commands[] = ['rename', $name, $target];
				}
			}
		} else {
			$input_errors[] = gettext('Unknown boot environment action.');
		}
		foreach ($input_errors ? [] : $commands as $args) {
			if (be_command($args, $result) !== 0) {
				$input_errors[] = $result;
				break;
			}
		}
		if (!$input_errors) {
			$done = [
				'create' => gettext('Created boot environment %s.'),
				'clone' => gettext('Created boot environment %s as a clone.'),
				'edit' => gettext('Saved boot environment %s.'),
				'rename' => gettext('Renamed boot environment %s.'),
				'describe' => gettext('Saved the description of %s.'),
				'destroy' => gettext('Deleted boot environment %s.'),
				'activate' => gettext('Boot environment %s is used from the next boot on.'),
				'activate-once' => gettext('Boot environment %s is used for the next boot only.'),
			];
			$savemsg = sprintf($done[$action] ?? gettext('Boot environment action completed (%s).'),
			    htmlspecialchars(($action === 'edit') ? trim($_POST['target'] ?? $name) : $name));
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
$bootenv_enabled = !array_key_exists('enabled', $settings) || $settings['enabled'] !== 'false';
$bootenv_automatic_rollback = !array_key_exists('automatic_rollback', $settings) || $settings['automatic_rollback'] !== 'false';
$view = $_GET['view'] ?? 'environments';
if (!in_array($view, ['environments', 'settings'], true)) {
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
if (($view === 'environments') && $available && $compatible) {
	fs_page_action(gettext('Create snapshot'), '#', 'fa-camera', 'primary', ['data-fs-modal' => '#be-create']);
}
include('head.inc');
if ($input_errors) print_input_errors($input_errors);
if ($savemsg) print_info_box($savemsg, 'success');

/* health text -> badge state */
function be_health_badge(string $health): string {
	switch (strtolower($health)) {
	case 'healthy':
	case 'ok':
		return fs_badge('pass', gettext('Healthy'));
	case 'pending':
		return fs_badge('pending', gettext('Pending'));
	case 'failed':
	case 'unhealthy':
		return fs_badge('block', gettext('Failed'));
	case '':
	case '-':
		return '<span class="fs-muted">-</span>';
	default:
		return fs_badge('neutral', $health);
	}
}

$be_name_pattern = '[A-Za-z0-9][A-Za-z0-9._\-]{0,63}';
?>
<style>
.bootenv-name { display: flex; align-items: flex-start; gap: .65rem; }
.bootenv-name > i { margin-top: .2rem; color: var(--fs-text-muted); }
.bootenv-name.is-current > i { color: var(--fs-coral); }
.bootenv-name strong { display: block; color: var(--fs-text-strong); overflow-wrap: anywhere; }
.bootenv-name small { display: block; color: var(--fs-text-muted); overflow-wrap: anywhere; }
.bootenv-cell-lines { display: flex; flex-direction: column; gap: .15rem; line-height: 1.25; }
.bootenv-cell-lines__secondary { color: var(--fs-text-muted); font-size: .9em; }
.bootenv-state { display: flex; flex-wrap: wrap; gap: .25rem; }
.bootenv-actions { margin: 0; }
.bootenv-settings__toggles { display: grid; gap: .65rem; margin-bottom: 1.25rem; }
</style>
<?php

if (!$available || !$compatible): ?>
	<div class="alert alert-info"><?=gettext('ZFS boot environments are unavailable. This feature requires a compatible ZFS root installation and bectl support.')?></div>
<?php else: ?>
<?php
fs_tabs('system-bootenv', 'system_boot_environments.php?view=' . $view);
if ($view === 'environments'):
?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Boot Environments'),
	'search' => gettext('Search boot environments…'),
	'noun' => gettext('boot environments'),
	'noun_one' => gettext('boot environment'),
]); ?>
	<div class="panel-body table-responsive">
	<table class="table table-hover bootenv-table">
		<thead><tr>
			<th data-fs-search><?=gettext('Environment')?></th>
			<th><?=gettext('State')?></th>
			<th data-fs-search><?=gettext('Health')?></th>
			<th data-fs-search><?=gettext('Version')?></th>
			<th><?=gettext('Created')?></th>
			<th><?=gettext('Space')?></th>
			<th class="fs-col-actions"><span class="visually-hidden"><?=gettext('Actions')?></span></th>
		</tr></thead>
		<tbody>
		<?php foreach ($environments as $be):
			$meta = $be['metadata'] ?? [];
			$states = [];
			if ($be['active_now']) $states[] = fs_badge('active', gettext('Current'));
			if ($be['active_reboot']) $states[] = fs_badge('info', gettext('Next'));
			if ($be['active_once']) $states[] = fs_badge('warn', gettext('Once'));
			$version_parts = be_version_parts((string)($meta['version'] ?? '-'));
			$created_parts = be_created_parts((string)($meta['created'] ?? $be['created']));
			$description = (string)($meta['description'] ?? '');
			$deletable = !$be['active_now'] && !$be['active_reboot'] && !$be['active_once'] && ($meta['health'] ?? '') !== 'pending';
		?>
		<tr<?=$be['active_now'] ? ' class="fs-row-current"' : ''?>>
			<td>
				<div class="bootenv-name<?=$be['active_now'] ? ' is-current' : ''?>">
					<i class="fa-solid fa-layer-group" aria-hidden="true"></i>
					<div><strong><?=htmlspecialchars($be['name'])?></strong><?php if ($description !== ''): ?><small><?=htmlspecialchars($description)?></small><?php endif; ?></div>
				</div>
			</td>
			<td><div class="bootenv-state"><?=$states ? implode('', $states) : '<span class="fs-muted">-</span>'?></div></td>
			<td><?=be_health_badge((string)($meta['health'] ?? '-'))?></td>
			<td class="fs-mono"><span class="bootenv-cell-lines"><span><?=htmlspecialchars($version_parts[0])?></span><?php if ($version_parts[1] !== ''): ?><span class="bootenv-cell-lines__secondary"><?=htmlspecialchars($version_parts[1])?></span><?php endif; ?></span></td>
			<td><span class="bootenv-cell-lines"><span><?=htmlspecialchars($created_parts[0])?></span><?php if ($created_parts[1] !== ''): ?><span class="bootenv-cell-lines__secondary"><?=htmlspecialchars($created_parts[1])?></span><?php endif; ?></span></td>
			<td class="fs-mono"><?=htmlspecialchars($be['space'])?></td>
			<td class="fs-col-actions">
				<form method="post" class="bootenv-actions">
					<input type="hidden" name="name" value="<?=htmlspecialchars($be['name'])?>">
					<input type="hidden" name="reboot" value="0">
					<div class="fs-actions">
					<button class="fs-action" name="action" value="activate" title="<?=gettext('Activate persistently')?>" aria-label="<?=htmlspecialchars(sprintf(gettext('Activate %s persistently'), $be['name']))?>"><i class="fa-solid fa-star" aria-hidden="true"></i></button>
					<button class="fs-action" name="action" value="activate-once" title="<?=gettext('Activate once')?>" aria-label="<?=htmlspecialchars(sprintf(gettext('Activate %s once'), $be['name']))?>"><i class="fa-solid fa-play" aria-hidden="true"></i></button>
					<button class="fs-action" name="action" value="activate-once" title="<?=gettext('Activate once and reboot')?>" aria-label="<?=htmlspecialchars(sprintf(gettext('Activate %s once and reboot'), $be['name']))?>"
					    data-fs-confirm="<?=htmlspecialchars(sprintf(gettext('Reboot into boot environment “%s” now?'), $be['name']))?>"
					    data-fs-confirm-detail="<?=gettext('The firewall restarts and is unreachable until it is back up.')?>"
					    data-fs-confirm-action="<?=gettext('Reboot')?>"
					    onclick="this.form.reboot.value='1';"><i class="fa-solid fa-power-off" aria-hidden="true"></i></button>
					<button type="button" class="fs-action" title="<?=gettext('Edit name and description')?>" aria-label="<?=htmlspecialchars(sprintf(gettext('Edit %s'), $be['name']))?>"
					    data-fs-modal="#be-edit" data-fs-modal-title="<?=htmlspecialchars(sprintf(gettext('Edit “%s”'), $be['name']))?>"
					    data-fs-fill="<?=htmlspecialchars(json_encode(['name' => $be['name'], 'target' => $be['name'], 'description' => $description]))?>"><i class="fa-solid fa-pencil" aria-hidden="true"></i></button>
					<button type="button" class="fs-action" title="<?=gettext('Clone')?>" aria-label="<?=htmlspecialchars(sprintf(gettext('Clone %s'), $be['name']))?>"
					    data-fs-modal="#be-clone" data-fs-modal-title="<?=htmlspecialchars(sprintf(gettext('Clone “%s”'), $be['name']))?>"
					    data-fs-fill="<?=htmlspecialchars(json_encode(['target' => $be['name'], 'name' => $be['name'] . '-copy']))?>"><i class="fa-regular fa-clone" aria-hidden="true"></i></button>
					<?php if ($deletable): ?>
					<button class="fs-action fs-action--delete" name="action" value="destroy" title="<?=gettext('Delete')?>" aria-label="<?=htmlspecialchars(sprintf(gettext('Delete %s'), $be['name']))?>"
					    data-fs-confirm="<?=htmlspecialchars(sprintf(gettext('Delete boot environment “%s”?'), $be['name']))?>"
					    data-fs-confirm-action="<?=gettext('Delete')?>"><i class="fa-solid fa-trash-can" aria-hidden="true"></i></button>
					<?php endif; ?>
					</div>
				</form>
			</td>
		</tr>
		<?php endforeach; ?>
		<?php if (empty($environments)) {
			fs_empty_row(7, gettext('No boot environments yet.'));
		} ?>
		</tbody>
	</table>
	</div>
	<div class="panel-footer small fs-muted">
		<i class="fa-solid fa-star" aria-hidden="true"></i> <?=gettext('activate (every boot)')?> &nbsp;·&nbsp;
		<i class="fa-solid fa-play" aria-hidden="true"></i> <?=gettext('activate for the next boot only')?> &nbsp;·&nbsp;
		<i class="fa-solid fa-power-off" aria-hidden="true"></i> <?=gettext('activate once and reboot now')?>
	</div>
</div>

<?php
/* create: snapshot of the running system */
fs_modal_form_begin('be-create', gettext('Create snapshot'));
?>
	<p class="fs-muted"><?=gettext('Saves the currently running system as a new boot environment you can boot back into.')?></p>
	<div class="mb-3">
		<label class="form-label" for="be-create-name"><?=gettext('Environment name')?></label>
		<input class="form-control fs-mono" id="be-create-name" name="name" required pattern="<?=$be_name_pattern?>" placeholder="<?=gettext('before-firewall-change')?>">
		<div class="form-text"><?=gettext('Letters, digits, ".", "_" and "-"; up to 64 characters.')?></div>
	</div>
<?php
fs_modal_form_end(gettext('Create snapshot'), 'action', 'create', 'fa-camera');

/* edit: rename and/or description */
fs_modal_form_begin('be-edit', gettext('Edit boot environment'));
?>
	<input type="hidden" name="name" value="">
	<div class="mb-3">
		<label class="form-label" for="be-edit-target"><?=gettext('Name')?></label>
		<input class="form-control fs-mono" id="be-edit-target" name="target" required pattern="<?=$be_name_pattern?>">
	</div>
	<div class="mb-3">
		<label class="form-label" for="be-edit-description"><?=gettext('Description')?></label>
		<input class="form-control" id="be-edit-description" name="description" maxlength="256" placeholder="<?=gettext('What this environment is for')?>">
	</div>
<?php
fs_modal_form_end(gettext('Save'), 'action', 'edit', 'fa-floppy-disk');

/* clone: copy an environment under a new name (handler: name = new, target = source) */
fs_modal_form_begin('be-clone', gettext('Clone boot environment'));
?>
	<div class="mb-3">
		<label class="form-label" for="be-clone-source"><?=gettext('Source')?></label>
		<select class="form-select" id="be-clone-source" name="target">
		<?php foreach ($environments as $be): ?>
			<option value="<?=htmlspecialchars($be['name'])?>"><?=htmlspecialchars($be['name'])?></option>
		<?php endforeach; ?>
		</select>
	</div>
	<div class="mb-3">
		<label class="form-label" for="be-clone-name"><?=gettext('New environment name')?></label>
		<input class="form-control fs-mono" id="be-clone-name" name="name" required pattern="<?=$be_name_pattern?>">
	</div>
<?php
fs_modal_form_end(gettext('Clone'), 'action', 'clone', 'fa-regular fa-clone');

elseif ($view === 'settings'): ?>
<form method="post" action="?view=settings">
	<div class="panel panel-default">
		<div class="panel-heading"><h2 class="panel-title"><i class="fa-solid fa-shield-halved me-1" aria-hidden="true"></i><?=gettext('Upgrade Protection')?></h2></div>
		<div class="panel-body">
			<div class="content">
			<div class="bootenv-settings__toggles">
				<div class="form-check"><input class="form-check-input" type="checkbox" id="be-enabled" name="enabled" <?=$bootenv_enabled ? 'checked' : ''?>>
					<label class="form-check-label" for="be-enabled"><?=gettext('Create boot environments automatically during upgrades')?></label></div>
				<div class="form-check"><input class="form-check-input" type="checkbox" id="be-rollback" name="automatic_rollback" <?=$bootenv_automatic_rollback ? 'checked' : ''?>>
					<label class="form-check-label" for="be-rollback"><?=gettext('Automatically roll back failed first boots')?></label></div>
			</div>
			<div class="row g-3">
				<div class="col-sm-6">
					<label class="form-label" for="be-retention"><?=gettext('Automatic environments to retain')?></label>
					<input class="form-control" type="number" min="1" max="10" id="be-retention" name="retention_auto" value="<?=htmlspecialchars($settings['retention_auto'] ?? 3)?>">
					<div class="form-text"><?=gettext('Older automatically-created environments are removed after this limit.')?></div>
				</div>
				<div class="col-sm-6">
					<label class="form-label" for="be-timeout"><?=gettext('Health timeout (seconds)')?></label>
					<input class="form-control" type="number" min="60" max="900" id="be-timeout" name="health_timeout" value="<?=htmlspecialchars($settings['health_timeout'] ?? 300)?>">
					<div class="form-text"><?=gettext('Maximum time to wait for a successful first-boot health check.')?></div>
				</div>
			</div>
			</div>
		</div>
	</div>
	<div class="fs-actionbar fs-actionbar--plain">
		<button class="btn btn-primary" name="action" value="settings"><i class="fa-solid fa-floppy-disk icon-embed-btn" aria-hidden="true"></i><?=gettext('Save')?></button>
	</div>
</form>
<?php endif; ?>
<?php endif; include('foot.inc');
