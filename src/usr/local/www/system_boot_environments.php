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

require_once('system_boot_environments.inc');

if ($_POST) {
	$action = $_POST['action'] ?? '';
	if ($action === 'settings') {
		list($input_errors, $savemsg) = be_settings_save($_POST);
	} else {
		list($input_errors, $savemsg) = be_page_action($action, $_POST);
	}
}

$bootenv = be_load();
$available = $bootenv['available'];
$compatible = $bootenv['compatible'];
$environments = $bootenv['environments'];
$settings = config_get_path('system/bootenv', []);
$bootenv_enabled = !array_key_exists('enabled', $settings) || $settings['enabled'] !== 'false';
$bootenv_automatic_rollback = !array_key_exists('automatic_rollback', $settings) || $settings['automatic_rollback'] !== 'false';
$view = $_GET['view'] ?? 'environments';
if (!in_array($view, ['environments', 'settings'], true)) {
	$view = 'environments';
}

// Keep the running environment at the top, followed by the newest snapshots.
$environments = be_sort_environments($environments);

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
</style>
<?php

if (!$available || !$compatible): ?>
	<div class="panel panel-default">
		<div class="fs-tool-empty">
			<i class="fa-solid fa-layer-group" aria-hidden="true"></i>
			<span><?=gettext('Boot environments are not available on this system.')?></span>
			<span class="small"><?=gettext('They need a ZFS root installation with bectl support.')?></span>
		</div>
	</div>
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

elseif ($view === 'settings'):
/* standard Form layout with the sticky Save bar; action=settings selects the settings handler */
$form = new Form();
$form->setAction('system_boot_environments.php?view=settings');
$form->addGlobal(new Form_Input('action', null, 'hidden', 'settings'));

$section = new Form_Section(gettext('Upgrade Protection'));
$section->addInput(new Form_Checkbox(
	'enabled',
	gettext('Snapshots'),
	gettext('Create boot environments automatically during upgrades'),
	$bootenv_enabled,
	'on'
));
$section->addInput(new Form_Checkbox(
	'automatic_rollback',
	gettext('Rollback'),
	gettext('Automatically roll back failed first boots'),
	$bootenv_automatic_rollback,
	'on'
));
$section->addInput(new Form_Input(
	'retention_auto',
	gettext('Environments to keep'),
	'number',
	$settings['retention_auto'] ?? 3,
	['min' => 1, 'max' => 10]
))->setHelp(gettext('Automatically created environments beyond this number are removed, oldest first (1-10).'));
$section->addInput(new Form_Input(
	'health_timeout',
	gettext('Health timeout'),
	'number',
	$settings['health_timeout'] ?? 300,
	['min' => 60, 'max' => 900]
))->setHelp(gettext('Seconds to wait for a successful first-boot health check (60-900).'));
$form->add($section);
print($form);
?>
<?php endif; ?>
<?php endif; include('foot.inc');
