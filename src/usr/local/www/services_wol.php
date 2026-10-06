<?php
/*
 * services_wol.php
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
##|*IDENT=page-services-wakeonlan
##|*NAME=Services: Wake-on-LAN
##|*DESCR=Allow access to the 'Services: Wake-on-LAN' page.
##|*MATCH=services_wol.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("services_wol.inc");

$savemsg = "";
$class = "";
/* adding and editing devices is its own privilege (services_wol_edit.php) */
$can_edit = isAllowedPage('services_wol_edit.php');

/* Waking every device changes state, so only accept it via POST. */
if ($_POST['wakeall'] != "") {
	wol_wake_all($savemsg, $class);
}

if (isset($_POST['save_device'])) {
	/* the add / edit device modal (same handler as services_wol_edit.php) */
	$id = is_numericint($_POST['id'] ?? null) ? $_POST['id'] : null;
	if (!$can_edit) {
		$device_errors = [gettext('You do not have permission to add or edit Wake-on-LAN devices.')];
	} else {
		$device_errors = wol_save_entry($_POST, $id);
	}
	if (empty($device_errors)) {
		header("Location: services_wol.php");
		exit;
	}
	$input_errors = $device_errors;
} elseif ($_POST['Submit'] || $_POST['mac']) {
	unset($input_errors);

	if ($_POST['mac']) {
		/* normalize MAC addresses - lowercase and convert Windows-ized hyphenated MACs to colon delimited */
		$mac = wol_normalize_mac($_POST['mac']);
		$if = $_POST['if'];
	}

	$input_errors = wol_wake_device($mac, $if, $savemsg, $class);
}

if (is_numericint($_POST['id']) && $_POST['act'] == "del") {
	if (wol_delete_entry($_POST['id'])) {
		header("Location: services_wol.php");
		exit;
	}
}

$devices = config_get_path('wol/wolentry', []);
$interfaces = get_configured_interface_with_descr();
$selected_if = (empty($if) ? 'lan' : $if);
if (!isset($interfaces[$selected_if])) {
	$selected_if = array_key_first($interfaces);
}

$pgtitle = array(gettext("Services"), gettext("Wake-on-LAN"));
if (!empty($devices)) {
	fs_page_action(gettext('Wake all'), 'services_wol.php?wakeall=true', 'fa-power-off', 'outline-secondary', [
		'usepost' => true,
		'data-fs-confirm' => sprintf(gettext('Send a magic packet to all %d devices?'), count($devices)),
		'data-fs-confirm-action' => gettext('Wake all'),
	]);
}
fs_page_action(gettext('Wake a MAC…'), '#', 'fa-bolt', 'outline-secondary', ['data-fs-modal' => '#wol-wake']);
if ($can_edit) {
	fs_page_action(gettext('Add device'), '#', 'fa-plus', 'primary', [
		'data-fs-modal' => '#wol-device',
		'data-fs-modal-title' => gettext('Add device'),
		'data-fs-fill' => json_encode(['id' => '', 'interface' => $selected_if]),
	]);
}
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($savemsg) {
	print_info_box($savemsg, $class);
}
?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Devices'),
	'search' => gettext('Search devices, MAC addresses…'),
	'noun' => gettext('devices'),
	'noun_one' => gettext('device'),
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover table-rowdblclickedit" data-sortable>
			<thead>
				<tr>
					<th data-fs-search><?=gettext("Device")?></th>
					<th data-fs-search><?=gettext("Interface")?></th>
					<th data-fs-search><?=gettext("MAC address")?></th>
					<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
				</tr>
			</thead>
			<tbody>
<?php foreach ($devices as $i => $wolent):
	$name = ($wolent['descr'] !== '') ? $wolent['descr'] : strtolower($wolent['mac']);
	$wake_url = 'services_wol.php?mac=' . urlencode($wolent['mac']) . '&if=' . urlencode($wolent['interface']);
	$actions = [
		['custom', $wake_url, $name, ['icon' => 'fa-power-off', 'label' => sprintf(gettext('Wake %s'), $name), 'post' => true]],
	];
	if ($can_edit) {
		$actions[] = ['edit', '#', $name, ['attrs' => [
			'data-fs-modal' => '#wol-device',
			'data-fs-modal-title' => sprintf(gettext('Edit “%s”'), $name),
			'data-fs-fill' => json_encode(['id' => (string)$i, 'interface' => $wolent['interface'], 'mac' => $wolent['mac'], 'descr' => $wolent['descr']]),
		]]];
	}
	$actions[] = ['delete', 'services_wol.php?act=del&id=' . $i, $name, ['thing' => gettext('device')]];
?>
				<tr>
					<td>
						<span class="fs-device"><i class="fa-solid fa-desktop" aria-hidden="true"></i>
						<?php if ($wolent['descr'] !== ''): ?><strong><?=htmlspecialchars($wolent['descr'])?></strong><?php else: ?><span class="fs-muted"><?=gettext('(no description)')?></span><?php endif; ?></span>
					</td>
					<td><?=htmlspecialchars(convert_friendly_interface_to_friendly_descr($wolent['interface']))?></td>
					<td class="fs-mono"><?=htmlspecialchars(strtolower($wolent['mac']))?></td>
					<td class="fs-col-actions"><?=fs_row_actions($actions)?></td>
				</tr>
<?php endforeach; ?>
<?php if (empty($devices)) {
	fs_empty_row(4, gettext('No devices saved yet. Save the machines you wake regularly, or wake any MAC address directly.'));
} ?>
			</tbody>
		</table>
	</div>
	<div class="panel-footer small fs-muted">
		<i class="fa-solid fa-circle-info" aria-hidden="true"></i>
		<?=gettext('Wake-on-LAN powers on a computer by sending it a "magic packet". Its network card must support Wake-on-LAN and have it enabled (BIOS/UEFI settings).')?>
	</div>
</div>

<style>
.fs-device { display: inline-flex; align-items: center; gap: .55rem; }
.fs-device > i { color: var(--fs-text-muted); }
</style>
<?php
/* wake any MAC address once (not saved) */
fs_modal_form_begin('wol-wake', gettext('Wake a MAC address'));
?>
	<div class="mb-3">
		<label class="form-label" for="wol-wake-if"><?=gettext('Interface')?></label>
		<select class="form-select" id="wol-wake-if" name="if">
		<?php foreach ($interfaces as $ifname => $ifdescr): ?>
			<option value="<?=htmlspecialchars($ifname)?>"<?=($ifname === $selected_if) ? ' selected' : ''?>><?=htmlspecialchars($ifdescr)?></option>
		<?php endforeach; ?>
		</select>
		<div class="form-text"><?=gettext('The interface the computer is connected to.')?></div>
	</div>
	<div class="mb-3">
		<label class="form-label" for="wol-wake-mac"><?=gettext('MAC address')?></label>
		<input class="form-control fs-mono" id="wol-wake-mac" name="mac" required placeholder="xx:xx:xx:xx:xx:xx">
	</div>
<?php
fs_modal_form_end(gettext('Wake'), 'Submit', 'Send', 'fa-power-off');

if ($can_edit) {
	/* add / edit a saved device */
	fs_modal_form_begin('wol-device', gettext('Add device'));
?>
	<input type="hidden" name="id" value="">
	<div class="mb-3">
		<label class="form-label" for="wol-device-descr"><?=gettext('Description')?></label>
		<input class="form-control" id="wol-device-descr" name="descr" placeholder="<?=gettext('Office PC')?>">
	</div>
	<div class="mb-3">
		<label class="form-label" for="wol-device-if"><?=gettext('Interface')?></label>
		<select class="form-select" id="wol-device-if" name="interface">
		<?php foreach ($interfaces as $ifname => $ifdescr): ?>
			<option value="<?=htmlspecialchars($ifname)?>"<?=($ifname === $selected_if) ? ' selected' : ''?>><?=htmlspecialchars($ifdescr)?></option>
		<?php endforeach; ?>
		</select>
	</div>
	<div class="mb-3">
		<label class="form-label" for="wol-device-mac"><?=gettext('MAC address')?></label>
		<input class="form-control fs-mono" id="wol-device-mac" name="mac" required placeholder="xx:xx:xx:xx:xx:xx">
	</div>
<?php
	fs_modal_form_end(gettext('Save'), 'save_device', '1', 'fa-floppy-disk');
}

include("foot.inc");
