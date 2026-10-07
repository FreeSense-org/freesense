<?php
/*
 * diag_gmirror.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
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
##|*IDENT=page-diagnostics-gmirror
##|*NAME=Diagnostics: GEOM Mirrors
##|*DESCR=Allow access to the 'Diagnostics: GEOM Mirrors' page.
##|*MATCH=diag_gmirror.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("config.inc");
require_once("gmirror.inc");

$pgtitle = array(gettext("Diagnostics"), gettext("GEOM Mirrors"));

include("head.inc");

$action_list = array(
	"forget" => gettext("Forget all formerly connected consumers"),
	"clear" => gettext("Remove metadata from disk"),
	"insert" => gettext("Insert consumer into mirror"),
	"remove" => gettext("Remove consumer from mirror"),
	"activate" => gettext("Reactivate consumer on mirror"),
	"deactivate" => gettext("Deactivate consumer from mirror"),
	"rebuild" => gettext("Force rebuild of mirror consumer"),
);

/* User tried to pass a bogus action */
if (!empty($_REQUEST['action']) && !array_key_exists($_REQUEST['action'], $action_list)) {
	header("Location: diag_gmirror.php");
	return;
}

if ($_POST) {
	if (!isset($_POST['confirm']) || ($_POST['confirm'] != gettext("Confirm"))) {
		header("Location: diag_gmirror.php");
		return;
	}

	$input_errors = "";

	if (($_POST['action'] != "clear") && !is_valid_mirror($_POST['mirror'])) {
		$input_errors[] = gettext("A valid mirror name must be supplied.");
	}

	if (!empty($_POST['consumer']) && !is_valid_consumer($_POST['consumer'])) {
		$input_errors[] = gettext("A valid consumer name must be supplied");
	}

	/* Additional action-specific validation that hasn't already been tested */
	switch ($_POST['action']) {
		case "insert":
			if (!is_consumer_unused($_POST['consumer'])) {
				$input_errors[] = gettext("Consumer is already in use and cannot be inserted. Remove consumer from existing mirror first.");
			}
			if (gmirror_consumer_has_metadata($_POST['consumer'])) {
				$input_errors[] = gettext("Consumer has metadata from an existing mirror. Clear metadata before inserting consumer.");
			}
			$mstat = gmirror_get_status_single($_POST['mirror']);
			if (strtoupper($mstat) != "COMPLETE") {
				$input_errors[] = gettext("Mirror is not in a COMPLETE state, cannot insert consumer. Forget disconnected disks or wait for rebuild to finish.");
			}
			break;

		case "clear":
			if (!is_consumer_unused($_POST['consumer'])) {
				$input_errors[] = gettext("Consumer is in use and cannot be cleared. Deactivate disk first.");
			}
			if (!gmirror_consumer_has_metadata($_POST['consumer'])) {
				$input_errors[] = gettext("Consumer has no metadata to clear.");
			}
			break;

		case "activate":
			if (is_consumer_in_mirror($_POST['consumer'], $_POST['mirror'])) {
				$input_errors[] = gettext("Consumer is already present on specified mirror.");
			}
			if (!gmirror_consumer_has_metadata($_POST['consumer'])) {
				$input_errors[] = gettext("Consumer has no metadata and cannot be reactivated.");
			}

			break;

		case "remove":
		case "deactivate":
		case "rebuild":
			if (!is_consumer_in_mirror($_POST['consumer'], $_POST['mirror'])) {
				$input_errors[] = gettext("Consumer must be present on the specified mirror.");
			}
			break;
	}

	$result = 0;
	if (empty($input_errors)) {
		switch ($_POST['action']) {
			case "forget":
				$result = gmirror_forget_disconnected($_POST['mirror']);
				break;
			case "clear":
				$result = gmirror_clear_consumer($_POST['consumer']);
				break;
			case "insert":
				$result = gmirror_insert_consumer($_POST['mirror'], $_POST['consumer']);
				break;
			case "remove":
				$result = gmirror_remove_consumer($_POST['mirror'], $_POST['consumer']);
				break;
			case "activate":
				$result = gmirror_activate_consumer($_POST['mirror'], $_POST['consumer']);
				break;
			case "deactivate":
				$result = gmirror_deactivate_consumer($_POST['mirror'], $_POST['consumer']);
				break;
			case "rebuild":
				$result = gmirror_force_rebuild($_POST['mirror'], $_POST['consumer']);
				break;
		}

		$redir = "Location: diag_gmirror.php";

		if ($result != 0) {
			$redir .= "?error=" . urlencode($result);
		}

		/* If we reload the page too fast, the gmirror information may be missing or not up-to-date. */
		sleep(3);
		header($redir);
		return;
	}
}

$mirror_status = gmirror_get_status();
$mirror_list = gmirror_get_mirrors();
$unused_disks = gmirror_get_disks();
$unused_consumers = array();

foreach ($unused_disks as $disk) {
	if (is_consumer_unused($disk)) {
		$unused_consumers = array_merge($unused_consumers, gmirror_get_all_unused_consumer_sizes_on_disk($disk));
	}
}

if ($input_errors) {
	print_input_errors($input_errors);
}
if ($_REQUEST["error"] && ($_REQUEST["error"] != 0)) {
	print_info_box(gettext("There was an error performing the chosen mirror operation. Check the System Log for details."), 'danger');
}

/* What each action does, shown on the confirmation step */
$action_detail = array(
	"forget" => gettext("The mirror forgets every consumer that is no longer connected. Do this before inserting a replacement disk."),
	"clear" => gettext("The GEOM mirror metadata is erased from the consumer. It can no longer be reactivated on its old mirror."),
	"insert" => gettext("The consumer is added to the mirror and is overwritten by a full rebuild from the other disks."),
	"remove" => gettext("The consumer is removed from the mirror and its metadata is cleared. The mirror runs with fewer copies."),
	"activate" => gettext("The consumer rejoins its former mirror and is synchronized again."),
	"deactivate" => gettext("The consumer is detached from the mirror but keeps its metadata, so it can be reactivated later."),
	"rebuild" => gettext("The consumer is rebuilt from the other disks of the mirror. Disk activity is high until it finishes."),
);

function diag_gmirror_badge($status) {
	switch (strtoupper($status)) {
		case 'COMPLETE':
			return fs_badge('pass', gettext('Complete'));
		case 'DEGRADED':
			return fs_badge('degraded');
		case 'ACTIVE':
			return fs_badge('active');
		case 'SYNCHRONIZING':
			return fs_badge('pending', gettext('Synchronizing'));
		default:
			return fs_badge('neutral', $status);
	}
}

function diag_gmirror_action_url($action, $mirror = '', $consumer = '') {
	$q = ['action' => $action];
	if ($consumer !== '') {
		$q['consumer'] = $consumer;
	}
	if ($mirror !== '') {
		$q['mirror'] = $mirror;
	}
	return 'diag_gmirror.php?' . http_build_query($q);
}
?>

<style>
.fs-gmirror-component { display: flex; flex-wrap: wrap; align-items: center; gap: var(--fs-sp-2); }
.fs-gmirror-size { color: var(--fs-text-muted); font-size: var(--fs-fs-sm); }
.fs-gmirror-facts { display: grid; grid-template-columns: max-content 1fr; gap: var(--fs-sp-1) var(--fs-sp-4); margin: 0 0 var(--fs-sp-3); }
.fs-gmirror-facts dt { color: var(--fs-text-muted); font-weight: 400; }
.fs-gmirror-facts dd { margin: 0; }
</style>

<form action="diag_gmirror.php" method="POST" id="gmirror_form" name="gmirror_form">
<?php
if ($_REQUEST["action"]):
	$act = $_REQUEST["action"];
?>
	<div class="panel panel-default fs-danger-card">
		<div class="panel-heading">
			<h2 class="panel-title"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><?=htmlspecialchars($action_list[$act])?></h2>
		</div>
		<div class="panel-body fs-danger-body">
			<p><?=gettext('Please confirm the selected action.')?> <?=htmlspecialchars($action_detail[$act])?></p>
			<input type="hidden" name="action" value="<?=htmlspecialchars($act)?>" />
			<dl class="fs-gmirror-facts">
<?php if (!empty($_REQUEST["mirror"])): ?>
				<dt><?=gettext("Mirror")?></dt>
				<dd class="fs-mono"><?=htmlspecialchars($_REQUEST['mirror'])?></dd>
<?php endif; ?>
<?php if (!empty($_REQUEST["consumer"])): ?>
				<dt><?=gettext("Consumer")?></dt>
				<dd class="fs-mono"><?=htmlspecialchars($_REQUEST["consumer"])?></dd>
<?php endif; ?>
			</dl>
<?php if (!empty($_REQUEST["mirror"])): ?>
			<input type="hidden" name="mirror" value="<?=htmlspecialchars($_REQUEST['mirror'])?>" />
<?php endif; ?>
<?php if (!empty($_REQUEST["consumer"])): ?>
			<input type="hidden" name="consumer" value="<?=htmlspecialchars($_REQUEST["consumer"])?>" />
<?php endif; ?>
		</div>
		<div class="panel-footer">
			<button type="submit" name="confirm" class="btn btn-danger no-confirm" value="<?=gettext("Confirm")?>" data-fs-busy="true">
				<i class="fa-solid fa-check icon-embed-btn" aria-hidden="true"></i><?=gettext("Confirm")?>
			</button>
			<a class="btn btn-outline-secondary" href="diag_gmirror.php"><?=gettext('Cancel')?></a>
		</div>
	</div>
<?php
else:
	$degraded = 0;
	$components = 0;
	foreach ($mirror_status as $m) {
		$degraded += (strtoupper($m['status']) == 'DEGRADED') ? 1 : 0;
		$components += count($m['components']);
	}
?>
	<div class="fs-tiles">
<?php
	fs_tile(gettext('Mirrors'), count($mirror_status));
	fs_tile(gettext('Degraded'), $degraded, $degraded ? 'degraded' : null);
	fs_tile(gettext('Consumers in mirrors'), $components);
	fs_tile(gettext('Unused consumers'), count($unused_consumers));
?>
	</div>

	<!-- GEOM mirror table -->
	<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Mirrors'),
	'search' => false,
	'noun' => gettext('consumers'),
	'noun_one' => gettext('consumer'),
]); ?>
		<div class="panel-body table-responsive">
			<table class="table table-hover">
				<thead>
					<tr>
						<th class="fs-col-status"><?=gettext("Status")?></th>
						<th><?=gettext("Mirror")?></th>
						<th><?=gettext("Component")?></th>
						<th class="fs-col-actions"><span class="visually-hidden"><?=gettext('Actions')?></span></th>
					</tr>
				</thead>
				<tbody>
<?php
	foreach ($mirror_status as $name):
		$mname = $name['name'];
		$can_manage = (strtoupper($name['status']) == "COMPLETE") && (count($name["components"]) > 1);
		foreach ($name['components'] as $idx => $component):
			list($cname, $cstatus) = array_pad(explode(" ", $component, 2), 2, '');
			$cstatus = trim($cstatus, " ()");
			$actions = [];
			if ($can_manage) {
				$actions[] = ['custom', diag_gmirror_action_url('rebuild', $mname, $cname), $cname,
				    ['icon' => 'fa-arrows-rotate', 'label' => sprintf(gettext('Rebuild %s'), $cname)]];
				$actions[] = ['custom', diag_gmirror_action_url('deactivate', $mname, $cname), $cname,
				    ['icon' => 'fa-link-slash', 'label' => sprintf(gettext('Deactivate %s'), $cname)]];
				$actions[] = ['custom', diag_gmirror_action_url('remove', $mname, $cname), $cname,
				    ['icon' => 'fa-circle-minus', 'label' => sprintf(gettext('Remove %s from the mirror'), $cname), 'attrs' => ['class' => 'fs-action fs-action--delete']]];
			}
?>
					<tr>
						<td><?=diag_gmirror_badge($name['status'])?></td>
						<td>
							<span class="fs-mono"><?=htmlspecialchars($mname)?></span>
							<div class="fs-gmirror-size"><?=htmlspecialchars(sprintf(gettext('Size: %s'), gmirror_get_mirror_size($mname)))?></div>
<?php if (($idx === 0) && (strtoupper($name['status']) == "DEGRADED")): ?>
							<a class="btn btn-sm btn-outline-danger mt-1" href="<?=htmlspecialchars(diag_gmirror_action_url('forget', $mname))?>"><i class="fa-solid fa-broom icon-embed-btn" aria-hidden="true"></i><?=gettext("Forget disconnected disks")?></a>
<?php endif; ?>
						</td>
						<td>
							<div class="fs-gmirror-component">
								<span class="fs-mono"><?=htmlspecialchars($cname)?></span>
<?php if ($cstatus !== ''): ?>
								<span class="fs-chip fs-chip--mono"><?=htmlspecialchars($cstatus)?></span>
<?php endif; ?>
							</div>
						</td>
						<td class="fs-col-actions"><?=fs_row_actions($actions)?></td>
					</tr>
<?php
		endforeach;
	endforeach;
	if (count($mirror_status) == 0) {
		fs_empty_row(4, gettext("No Mirrors Found"));
	}
?>
				</tbody>
			</table>
		</div>
		<div class="panel-footer small fs-muted">
			<i class="fa-solid fa-circle-info" aria-hidden="true"></i>
			<?=gettext("Some disk operations may only be performed when there are multiple consumers present in a mirror.")?>
		</div>
	</div>

	<!-- Consumer information table -->
	<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Available consumers'),
	'search' => false,
	'noun' => gettext('consumers'),
	'noun_one' => gettext('consumer'),
]); ?>
		<div class="panel-body table-responsive">
			<table class="table table-hover">
				<thead>
					<tr>
						<th><?=gettext("Name")?></th>
						<th><?=gettext("Size")?></th>
						<th><?=gettext("Add to mirror")?></th>
					</tr>
				</thead>
				<tbody>
<?php
	foreach ($unused_consumers as $consumer):
		$oldmirror = gmirror_get_consumer_metadata_mirror($consumer['name']);
?>
					<tr>
						<td class="fs-mono"><?=htmlspecialchars($consumer['name'])?></td>
						<td>
							<?=htmlspecialchars(trim($consumer['humansize'], ' ()'))?>
							<div class="fs-mono fs-muted small"><?=htmlspecialchars($consumer['size'])?></div>
						</td>
						<td>
							<div class="fs-gmirror-component">
<?php if ($oldmirror): ?>
								<a class="btn btn-sm btn-outline-secondary" href="<?=htmlspecialchars(diag_gmirror_action_url('activate', $oldmirror, $consumer['name']))?>">
									<i class="fa-solid fa-link icon-embed-btn" aria-hidden="true"></i><?=htmlspecialchars(sprintf(gettext("Reactivate on %s"), $oldmirror))?>
								</a>
								<a class="btn btn-sm btn-outline-danger" href="<?=htmlspecialchars(diag_gmirror_action_url('clear', '', $consumer['name']))?>">
									<i class="fa-solid fa-eraser icon-embed-btn" aria-hidden="true"></i><?=gettext("Clear metadata")?>
								</a>
<?php
		else:
			$offered = 0;
			foreach ($mirror_list as $mirror):
				$mirror_size = gmirror_get_mirror_size($mirror);
				$consumer_size = gmirror_get_unused_consumer_size($consumer['name']);
				if ($consumer_size > $mirror_size):
					$offered++;
?>
								<a class="btn btn-sm btn-outline-secondary" href="<?=htmlspecialchars(diag_gmirror_action_url('insert', $mirror, $consumer['name']))?>">
									<i class="fa-solid fa-plus icon-embed-btn" aria-hidden="true"></i><?=htmlspecialchars(sprintf(gettext('Insert into %s'), $mirror))?>
								</a>
<?php
				endif;
			endforeach;
			if (!$offered):
?>
								<span class="fs-muted small"><?=empty($mirror_list) ? gettext('No mirror to add it to') : gettext('Too small for every mirror')?></span>
<?php
			endif;
		endif;
?>
							</div>
						</td>
					</tr>
<?php
	endforeach;
	if (count($unused_consumers) == 0) {
		fs_empty_row(3, gettext("No unused consumers found"));
	}
?>
				</tbody>
			</table>
		</div>
		<div class="panel-footer small fs-muted">
			<i class="fa-solid fa-circle-info" aria-hidden="true"></i>
			<?=gettext("Consumers may only be added to a mirror if they are larger than the size of the mirror.")?>
			<?=gettext("To repair a failed mirror, first perform a 'Forget' command on the mirror, followed by an 'insert' action on the new consumer.")?>
		</div>
	</div>
<?php
	print_callout(gettext("The options on this page are intended for use by advanced users only. This page is for managing existing mirrors, not creating new mirrors."), 'info');
endif; ?>
</form>

<?php
require_once("foot.inc");
