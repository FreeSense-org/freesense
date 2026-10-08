<?php
/*
 * system_advanced_sysctl.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
 * Copyright (c) 2008 Shrew Soft Inc
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
##|*IDENT=page-system-advanced-sysctl
##|*NAME=System: Advanced: Tunables
##|*DESCR=Allow access to the 'System: Advanced: Tunables' page.
##|*MATCH=system_advanced_sysctl.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("freesense-utils.inc");
require_once("system_advanced_sysctl.inc");

$tunables = getTunables();

if (isset($_REQUEST['id'])) {
	$id = htmlspecialchars_decode($_REQUEST['id']);
}

if (($_POST['act'] == "del")) {
	if (is_numericint($id) && deleteTunable($id)) {
		exit;
	}
}

if ($_POST['apply']) {
	$retval = 0;
	system_setup_sysctl();
	clear_subsystem_dirty('sysctl');
}

if ($_POST['save'] == gettext("Save")) {
	$rv = saveTunable($_POST, $id);

	$input_errors = $rv['input_errors'];
	$pconfig = $rv['pconfig'];

	if (!$input_errors) {
		FreeSenseHeader("system_advanced_sysctl.php");
		exit;
	}
}

$act = $_REQUEST['act'];

if ($act == "edit") {
	sortTunables();
	if (is_numericint($id) && (config_get_path("sysctl/item/{$id}") !== null)) {
		$pconfig['tunable'] = config_get_path('sysctl/item/' . $id . '/tunable');
		$pconfig['value'] = config_get_path('sysctl/item/' . $id . '/value');
		$pconfig['descr'] = config_get_path('sysctl/item/' . $id . '/descr');

	} else if (isset($tunables[$id])) {
		$pconfig['tunable'] = $tunables[$id]['tunable'];
		$pconfig['value'] = $tunables[$id]['value'];
		$pconfig['descr'] = $tunables[$id]['descr'];
	}
}

$pgtitle = array(gettext("System"), gettext("Advanced"), gettext("System Tunables"));
$pglinks = array("", "system_advanced_admin.php", "system_advanced_sysctl.php");

if ($act == "edit") {
	$pgtitle[] = gettext('Edit');
	$pglinks[] = "@self";
}

if ($act != "edit") {
	fs_page_action(gettext('Add tunable'), '#', 'fa-plus', 'primary', [
		'data-fs-modal' => '#sysctl-edit',
		'data-fs-modal-title' => gettext('Add tunable'),
	]);
}

include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($_POST['apply']) {
	print_apply_result_box($retval);
}

if (is_subsystem_dirty('sysctl') && ($act != "edit" )) {
	print_apply_box(gettext("The firewall tunables have changed.") . "<br />" . gettext("The changes must be applied for them to take effect."));
}

fs_tabs('system-advanced', 'system_advanced_sysctl.php');

if ($act != "edit"):
	$tn = static function ($v) { return htmlspecialchars_decode((string)$v); };
?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('System Tunables'),
	'search' => gettext('Search tunables…'),
	'noun' => gettext('tunables'),
	'noun_one' => gettext('tunable'),
	'filters' => ['state' => [gettext('All'), 'custom' => gettext('Custom'), 'default' => gettext('Default')]],
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover table-rowdblclickedit" data-sortable>
			<thead>
				<tr>
					<th data-fs-search><?=gettext("Tunable")?></th>
					<th data-fs-search><?=gettext("Value")?></th>
					<th><?=gettext("State")?></th>
					<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
				</tr>
			</thead>
			<tbody>
<?php
	foreach ($tunables as $i => $tunable):
		$custom = isset($tunable['modified']);
		if (!$custom) {
			$i = $tunable['tunable'];
		}
		$name = $tn($tunable['tunable']);
		$actions = [
			['edit', 'system_advanced_sysctl.php?act=edit&id=' . urlencode($i), $name, ['attrs' => [
				'data-fs-modal' => '#sysctl-edit',
				'data-fs-modal-title' => sprintf(gettext('Edit %s'), $name),
				'data-fs-fill' => json_encode([
					'id' => $custom ? (string)$i : '',
					'tunable' => $name,
					'value' => $tn($tunable['value']),
					'descr' => $tn($tunable['descr']),
				]),
			]]],
		];
		if ($custom) {
			$actions[] = ['delete', 'system_advanced_sysctl.php?act=del&id=' . urlencode($i), $name, [
				'thing' => gettext('custom tunable'),
				'detail' => gettext('A built-in tunable returns to its default value once the change is applied.'),
			]];
		}
?>
				<tr data-fs-filter-state="<?=$custom ? 'custom' : 'default'?>">
					<td>
						<span class="fs-mono"><?=htmlspecialchars($name)?></span>
<?php		if ($tunable['descr'] !== ''): ?>
						<div class="fs-muted small"><?=htmlspecialchars($tn($tunable['descr']))?></div>
<?php		endif; ?>
					</td>
					<td class="fs-mono">
						<?=htmlspecialchars($tn($tunable['value']))?>
<?php		if ($tunable['value'] == "default"): ?>
						<span class="fs-muted">(<?=htmlspecialchars(get_default_sysctl_value($tunable['tunable']))?>)</span>
<?php		endif; ?>
					</td>
					<td><?=$custom ? fs_badge('info', gettext('Custom')) : '<span class="fs-muted">' . gettext('Default') . '</span>'?></td>
					<td class="fs-col-actions"><?=fs_row_actions($actions)?></td>
				</tr>
<?php
	endforeach;
	unset($tunables);
?>
			</tbody>
		</table>
	</div>
	<div class="panel-footer small fs-muted">
		<i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>
		<?=gettext('These options are intended for advanced users. Saved changes take effect after Apply.')?>
	</div>
</div>
<?php
	/* add / edit a tunable (posts to the existing save handler) */
	$reopen = null;
	if ($input_errors && isset($_POST['save'])) {
		$reopen = ['id' => (string)($_POST['id'] ?? ''), 'tunable' => $_POST['tunable'] ?? '', 'value' => $_POST['value'] ?? '', 'descr' => $_POST['descr'] ?? ''];
	}
	fs_modal_form_begin('sysctl-edit', gettext('Add tunable'), 'system_advanced_sysctl.php', [], $reopen);
?>
	<input type="hidden" name="id" value="">
	<div class="mb-3">
		<label class="form-label" for="sysctl-tunable"><?=gettext('Tunable')?></label>
		<input class="form-control fs-mono" id="sysctl-tunable" name="tunable" required placeholder="net.inet.tcp.blackhole">
	</div>
	<div class="mb-3">
		<label class="form-label" for="sysctl-value"><?=gettext('Value')?></label>
		<input class="form-control fs-mono" id="sysctl-value" name="value" required>
		<div class="form-text"><?=gettext('Letters, digits, "-", "_", "%" and "/". Use "default" for the built-in value.')?></div>
	</div>
	<div class="mb-3">
		<label class="form-label" for="sysctl-descr"><?=gettext('Description')?></label>
		<input class="form-control" id="sysctl-descr" name="descr">
	</div>
<?php
	fs_modal_form_end(gettext('Save'), 'save', gettext('Save'), 'fa-floppy-disk');
?>

<?php else:
	$form = new Form;
	$section = new Form_Section('Edit Tunable');

	$section->addInput(new Form_Input(
		'tunable',
		'*Tunable',
		'text',
		$pconfig['tunable']
	))->setWidth(4);

	$section->addInput(new Form_Input(
		'value',
		'*Value',
		'text',
		$pconfig['value']
	))->setWidth(4);

	$section->addInput(new Form_Input(
		'descr',
		'Description',
		'text',
		$pconfig['descr']
	))->setWidth(4);

	if (is_numericint($id) && config_get_path('sysctl/item/' . $id)) {
		$form->addGlobal(new Form_Input(
			'id',
			'id',
			'hidden',
			$id
		));
	}

	$form->add($section);

	fs_form_cancel($form, 'system_advanced_sysctl.php');
	print $form;

endif;

include("foot.inc");
