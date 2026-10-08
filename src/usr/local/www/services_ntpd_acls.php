<?php
/*
 * services_ntpd_acls.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
 * Copyright (c) 2013 Dagorlad
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
##|*IDENT=page-services-ntpd-acls
##|*NAME=Services: NTP ACL Settings
##|*DESCR=Allow access to the 'Services: NTP ACL Settings' page.
##|*MATCH=services_ntpd_acls.php*
##|-PRIV

require_once("guiconfig.inc");
require_once('rrd.inc');
require_once("shaper.inc");
require_once("services_ntpd.inc");

$networkacl = ntpd_acl_rows();
$acl_flags = ntpd_acl_flags();
$acl_flag_help = [
	'kod' => gettext('Send Kiss-o\'-death packets'),
	'nomodify' => gettext('Deny run-time configuration by ntpq and ntpdc'),
	'noquery' => gettext('Deny ntpq and ntpdc queries'),
	'noserve' => gettext('Deny time service (ntpq and ntpdc queries still work)'),
	'nopeer' => gettext('Deny peer associations'),
	'notrap' => gettext('Deny the mode 6 trap service'),
];

if ($_POST) {
	/*
	 * ntpd_save_acls() takes the whole former form (defaults and every row).
	 * Rebuild it from the configuration and change only what was posted:
	 * the defaults card, or one network from the list's modal.
	 */
	$pconfig = config_get_path('ntpd', []);
	$acl_post = [];
	/* kod, nomodify, nopeer and notrap are stored inverted (set when the box is cleared) */
	foreach (['kod', 'nomodify', 'nopeer', 'notrap'] as $flag) {
		if (empty($pconfig[$flag])) {
			$acl_post[$flag] = 'yes';
		}
	}
	foreach (['noquery', 'noserve'] as $flag) {
		if (!empty($pconfig[$flag])) {
			$acl_post[$flag] = $pconfig[$flag];
		}
	}

	/* same rows, in the same order, as the list below (rows without a network are dropped) */
	$rows = array_values(array_filter(config_get_path('ntpd/restrictions/row', []), static function ($r) {
		return is_array($r) && (($r['acl_network'] ?? '') !== '');
	}));
	$acl_action = $_POST['acl_action'] ?? '';
	$acl_id = is_numericint($_POST['acl_id'] ?? null) ? (int)$_POST['acl_id'] : null;
	if ($acl_action === 'save') {
		$network = trim((string)($_POST['acl_cidr'] ?? ''));
		$mask = '';
		if (strpos($network, '/') !== false) {
			list($network, $mask) = explode('/', $network, 2);
		}
		$row = ['acl_network' => $network, 'mask' => $mask];
		foreach ($acl_flags as $flag) {
			if (!empty($_POST["acl_{$flag}"])) {
				$row[$flag] = 'yes';
			}
		}
		if ($acl_id !== null && isset($rows[$acl_id])) {
			$rows[$acl_id] = $row;
		} elseif (count($rows) < NUMACLS) {
			$rows[] = $row;
		} else {
			$input_errors[] = sprintf(gettext('At most %d networks can be configured.'), NUMACLS);
		}
		if ($network === '') {
			$input_errors[] = gettext('A network must be entered.');
		}
	} elseif ($acl_action === 'del' && $acl_id !== null) {
		unset($rows[$acl_id]);
		$rows = array_values($rows);
	} elseif ($acl_action === '') {
		/* the defaults card: checkboxes post only when ticked */
		foreach ($acl_flags as $flag) {
			unset($acl_post[$flag]);
			if (!empty($_POST[$flag])) {
				$acl_post[$flag] = $_POST[$flag];
			}
		}
	}

	foreach ($rows as $x => $row) {
		$acl_post["acl_network{$x}"] = $row['acl_network'];
		$acl_post["mask{$x}"] = $row['mask'];
		foreach ($acl_flags as $flag) {
			if (!empty($row[$flag])) {
				$acl_post["{$flag}{$x}"] = 'yes';
			}
		}
	}

	if (empty($input_errors)) {
		unset($input_errors);
		$rv = ntpd_save_acls($acl_post);
		$input_errors = $rv['input_errors'];
		if ($rv['changes_applied']) {
			$changes_applied = true;
			$retval = $rv['retval'];
		}
	}
	$networkacl = ntpd_acl_rows();
}

$pconfig = config_get_path('ntpd', []);

$pgtitle = array(gettext("Services"), gettext("NTP"), gettext("ACLs"));
$pglinks = array("", "services_ntpd.php", "@self");
fs_page_action(gettext('Add network'), '#', 'fa-plus', 'primary', [
	'data-fs-modal' => '#ntp-acl',
	'data-fs-modal-title' => gettext('Add network'),
	'data-fs-fill' => json_encode(['acl_id' => '']),
]);
$shortcut_section = "ntp";
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($changes_applied) {
	print_apply_result_box($retval);
}

fs_tabs('services-ntp', 'services_ntpd_acls.php');

/* custom restrictions: one network per row (the placeholder row of an empty configuration is skipped) */
$acl_rows = array_values(array_filter($networkacl, static function ($r) {
	return is_array($r) && (($r['acl_network'] ?? '') !== '');
}));
?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Custom Access Restrictions'),
	'search' => gettext('Search networks…'),
	'noun' => gettext('networks'),
	'noun_one' => gettext('network'),
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover table-rowdblclickedit">
			<thead>
				<tr>
					<th data-fs-search><?=gettext('Network')?></th>
					<th data-fs-search><?=gettext('Restrictions')?></th>
					<th class="fs-col-actions"><span class="visually-hidden"><?=gettext('Actions')?></span></th>
				</tr>
			</thead>
			<tbody>
<?php foreach ($acl_rows as $x => $row):
	$cidr = $row['acl_network'] . (($row['mask'] !== '') ? '/' . $row['mask'] : '');
	$fill = ['acl_id' => (string)$x, 'acl_cidr' => $cidr];
	$set = [];
	foreach ($acl_flags as $flag) {
		$fill["acl_{$flag}"] = !empty($row[$flag]);
		if (!empty($row[$flag])) {
			$set[] = '<span class="badge fs-acl-flag" title="' . htmlspecialchars($acl_flag_help[$flag]) . '">' . $flag . '</span>';
		}
	}
?>
				<tr>
					<td class="fs-mono"><?=htmlspecialchars($cidr)?></td>
					<td><?=$set ? implode(' ', $set) : '<span class="fs-muted">' . gettext('None (full access)') . '</span>'?></td>
					<td class="fs-col-actions">
						<?=fs_row_actions([
							['edit', '#', $cidr, ['attrs' => [
								'data-fs-modal' => '#ntp-acl',
								'data-fs-modal-title' => sprintf(gettext('Edit %s'), $cidr),
								'data-fs-fill' => json_encode($fill),
							]]],
							['delete', 'services_ntpd_acls.php?acl_action=del&acl_id=' . $x, $cidr, ['thing' => gettext('network')]],
						])?>
					</td>
				</tr>
<?php endforeach; ?>
<?php if (empty($acl_rows)) {
	fs_empty_row(3, gettext('No custom restrictions. Every client gets the defaults below.'));
} ?>
			</tbody>
		</table>
	</div>
	<div class="panel-footer small fs-muted">
		<i class="fa-solid fa-circle-info" aria-hidden="true"></i>
		<?=gettext('Clients in these networks get the listed restrictions instead of the defaults. A network without restrictions has full access.')?>
	</div>
</div>
<style>
.fs-acl-flag { font-family: var(--fs-font-mono); font-weight: 500; background: var(--fs-surface-2, rgba(255, 255, 255, .06)); color: var(--fs-text); border: 1px solid var(--fs-border-color); }
</style>
<?php

$form = new Form;

$section = new Form_Section('Default Access Restrictions');

$section->addInput(new Form_Checkbox(
	'kod',
	'Kiss-o\'-death',
	'Enable KOD packets.',
	!$pconfig['kod']
));

$section->addInput(new Form_Checkbox(
	'nomodify',
	"Modifications",
	'Deny run-time Configuration (nomodify) by ntpq and ntpdc.',
	!$pconfig['nomodify']
));

$section->addInput(new Form_Checkbox(
	'noquery',
	'Queries',
	'Disable ntpq and ntpdc queries (noquery).',
	$pconfig['noquery']
));

$section->addInput(new Form_Checkbox(
	'noserve',
	'Service',
	'Disable all except ntpq and ntpdc queries (noserve).',
	$pconfig['noserve']
));

$section->addInput(new Form_Checkbox(
	'nopeer',
	'Peer Association',
	'Deny packets that attempt a peer association (nopeer).',
	!$pconfig['nopeer']
));

$section->addInput(new Form_Checkbox(
	'notrap',
	'Trap Service',
	'Deny mode 6 control message trap service (notrap).',
	!$pconfig['notrap']
));

$section->addInput(new Form_StaticText(
	null,
	'<span class="fs-muted">' . gettext('These apply to every client that is not in one of the networks above.') . '</span>'
));

$form->add($section);

print($form);

/* add / edit one network */
$reopen = null;
if ($input_errors && (($_POST['acl_action'] ?? '') === 'save')) {
	$reopen = ['acl_id' => (string)($_POST['acl_id'] ?? ''), 'acl_cidr' => $_POST['acl_cidr'] ?? ''];
	foreach ($acl_flags as $flag) {
		$reopen["acl_{$flag}"] = !empty($_POST["acl_{$flag}"]);
	}
}
fs_modal_form_begin('ntp-acl', gettext('Add network'), 'services_ntpd_acls.php', [], $reopen);
?>
	<input type="hidden" name="acl_id" value="">
	<div class="mb-3">
		<label class="form-label" for="ntp-acl-cidr"><?=gettext('Network')?></label>
		<input class="form-control fs-mono" id="ntp-acl-cidr" name="acl_cidr" required placeholder="192.0.2.0/24">
		<div class="form-text"><?=gettext('IPv4 or IPv6 network with its prefix length, e.g. 192.0.2.0/24 or 2001:db8::/32.')?></div>
	</div>
	<fieldset>
		<legend class="form-label fs-6"><?=gettext('Restrictions')?></legend>
<?php foreach ($acl_flags as $flag): ?>
		<div class="form-check">
			<input class="form-check-input" type="checkbox" id="ntp-acl-<?=$flag?>" name="acl_<?=$flag?>" value="yes">
			<label class="form-check-label" for="ntp-acl-<?=$flag?>"><span class="fs-mono"><?=$flag?></span> <span class="fs-muted">· <?=htmlspecialchars($acl_flag_help[$flag])?></span></label>
		</div>
<?php endforeach; ?>
	</fieldset>
<?php
fs_modal_form_end(gettext('Save'), 'acl_action', 'save', 'fa-floppy-disk');

include("foot.inc");
