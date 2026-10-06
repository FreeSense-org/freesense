<?php
/*
 * diag_tables.php
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
##|*IDENT=page-diagnostics-tables
##|*NAME=Diagnostics: pf Table IP addresses
##|*DESCR=Allow access to the 'Diagnostics: Tables' page.
##|*MATCH=diag_tables.php*
##|-PRIV

$pgtitle = array(gettext("Diagnostics"), gettext("Tables"));
$shortcut_section = "aliases";

require_once("guiconfig.inc");

exec("/sbin/pfctl -sT", $tables);

// Set default table
$tablename = "sshguard";

if ($_REQUEST['type'] && in_array($_REQUEST['type'], $tables)) {
	$tablename = $_REQUEST['type'];
} else {
	/* Invalid 'type' passed, do not take any actions that use the 'type' field. */
	unset($_REQUEST['type']);
}

// Gather selected alias metadata.
foreach (config_get_path('aliases/alias', []) as $alias) {
	if ( $alias['name'] == $tablename ) {
		$tmp = array();
		$tmp['type'] = $alias['type'];
		$tmp['name'] = $alias['name'];
		$tmp['url']  = $alias['url'];
		$tmp['freq'] = $alias['updatefreq'];
		break;
	}
}

# Determine if selected alias is either a bogons or URL table.
if (($tablename == "bogons") || ($tablename == "bogonsv6")) {
	$bogons = true;
} else if (preg_match('/urltable/i', $tmp['type'])) {
	$urltable = true;
} else {
	$bogons = $urltable = false;
}

if ($_REQUEST['delete']) {
	if (is_ipaddr($_REQUEST['delete']) || is_subnet($_REQUEST['delete'])) {
		exec("/sbin/pfctl -t " . escapeshellarg($_REQUEST['type']) . " -T delete " . escapeshellarg($_REQUEST['delete']), $delete);
		echo htmlentities($_REQUEST['delete']);
	}
	exit;
}

if ($_POST['clearall']) {
	exec("/sbin/pfctl -t " . escapeshellarg($tablename) . " -T flush");
}

if ($_POST['Download'] && ($bogons || $urltable)) {

	if ($bogons) {				// If selected table is either bogons or bogonsv6.
		$mwexec_bg_cmd = '/etc/rc.update_bogons.sh now';
		$table_type = 'bogons';
		$db_name = 'bogons';
	} else if ($urltable) {		//  If selected table is a URL table alias.
		$mwexec_bg_cmd = '/etc/rc.update_urltables now forceupdate ' . $tablename;
		$table_type = 'urltables';
		$db_name = $tablename;
	}

	mwexec_bg($mwexec_bg_cmd);
	$maxtimetowait = 0;
	$loading = true;
	while ($loading == true) {
		$isrunning = shell_exec("/bin/ps awwwux | /usr/bin/grep -v grep | /usr/bin/grep " . escapeshellarg($table_type)) ?? "";
		if ($isrunning == "") {
			$loading = false;
		}
		$maxtimetowait++;
		if ($maxtimetowait > 89) {
			$loading = false;
		}
		sleep(1);
	}
	if ($maxtimetowait < 90) {
		$savemsg = sprintf(gettext("The %s file contents have been updated."), $db_name);
	}
}

$entries = array();
exec("/sbin/pfctl -t " . escapeshellarg($tablename) . " -T show", $entries);
$entries = array_values(array_filter(array_map('trim', $entries), 'strlen'));

/* last update and file comments of bogons / URL tables */
$last_updated = '';
$table_comments = array();
if ($bogons || $urltable) {
	if ($bogons) {
		$table_file = '/etc/' . escapeshellarg($tablename);
	} else {
		$table_file = '/var/db/aliastables/' . escapeshellarg($tablename) . '.txt';
	}

	$datestrregex = '(Mon|Tue|Wed|Thu|Fri|Sat|Sun).* GMT';
	$datelineregex = 'last.*' . $datestrregex;

	$last_updated = exec('/usr/bin/grep -i -m 1 -E "^# ' . $datelineregex . '" ' . $table_file . '|/usr/bin/grep -i -m 1 -E -o "' . $datestrregex . '"');

	# Display up to 10 comment lines (lines that begin with '#').
	exec('/usr/bin/grep -i -m 10 -E "^#" ' . $table_file, $table_comments);
}

$table_query = 'type=' . rawurlencode($tablename);
if ($bogons || $urltable) {
	fs_page_action(gettext('Update now'), 'diag_tables.php?' . $table_query . '&Download=Update', 'fa-arrows-rotate', 'primary', [
		'usepost' => true,
		'title' => gettext('Download the table contents again (can take up to 90 seconds)'),
	]);
} elseif (!empty($entries)) {
	fs_page_action(gettext('Empty table'), 'diag_tables.php?' . $table_query . '&clearall=Empty', 'fa-trash-can', 'danger', [
		'usepost' => true,
		'data-fs-confirm' => sprintf(gettext('Remove all entries from “%s”?'), $tablename),
		'data-fs-confirm-detail' => gettext('The table is filled again when its alias or service reloads it.'),
		'data-fs-confirm-action' => gettext('Empty table'),
	]);
}

include("head.inc");

if ($savemsg) {
	print_info_box($savemsg, 'success');
}

$can_delete = !$bogons && !$urltable;
$large = (count($entries) > 3000);

if ($tablename == "sshguard") {
	$table_kind = gettext('Lockout');
} elseif ($bogons) {
	$table_kind = gettext('Bogons');
} elseif (!empty($tmp['type'])) {
	$table_kind = sprintf(gettext('Alias (%s)'), $tmp['type']);
} else {
	$table_kind = gettext('System');
}

/* table picker, kept as the "type" GET parameter */
$picker = '<form method="get" action="diag_tables.php" class="fs-tables-pick">'
    . '<label class="visually-hidden" for="type">' . fs_h(gettext('Table')) . '</label>'
    . '<select class="form-select form-select-sm" name="type" id="type">';
foreach ($tables as $table) {
	$label = ($table === 'sshguard') ? sprintf(gettext('%s (SSH and GUI lockout)'), $table) : $table;
	$picker .= '<option value="' . fs_h($table) . '"' . (($table === $tablename) ? ' selected' : '') . '>' . fs_h($label) . '</option>';
}
$picker .= '</select><noscript><button type="submit" class="btn btn-sm btn-outline-secondary">' . fs_h(gettext('Show')) . '</button></noscript></form>';
?>

<style>
.fs-tables-pick { display: flex; gap: var(--fs-sp-2); }
.fs-tables-pick .form-select { max-width: 18rem; }
.fs-tables-comments summary { cursor: pointer; }
.fs-tables-comments pre { margin: var(--fs-sp-2) 0 0; white-space: pre-wrap; }
</style>

<div class="fs-tiles">
<?php
fs_tile(gettext('Entries'), number_format(count($entries)));
fs_tile(gettext('Table type'), $table_kind);
if ($bogons || $urltable) {
	fs_tile(gettext('Last update'), ($last_updated != '') ? $last_updated : gettext('Unknown'));
}
if ($urltable && !empty($tmp['freq'])) {
	fs_tile(gettext('Update frequency'), sprintf(gettext('%s days'), $tmp['freq']));
}
?>
</div>

<div class="panel panel-default fs-table" id="table-entries" data-table="<?=htmlspecialchars($tablename)?>">
<?php fs_table_toolbar([
	'search' => $large ? false : gettext('Search entries…'),
	'noun' => gettext('entries'),
	'noun_one' => gettext('entry'),
	'custom' => $picker,
]); ?>
<?php if ($large): ?>
	<div class="panel-heading">
		<h2 class="panel-title"><?=htmlspecialchars(sprintf(gettext('%s entries'), number_format(count($entries))))?></h2>
		<button type="button" class="btn btn-sm btn-outline-secondary" data-fs-copy="#table-dump">
			<i class="fa-regular fa-copy icon-embed-btn" aria-hidden="true"></i><?=gettext('Copy')?>
		</button>
	</div>
	<pre class="fs-console" id="table-dump"><?=htmlspecialchars(implode("\n", $entries))?></pre>
<?php else: ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover" data-sortable>
			<thead>
				<tr>
					<th data-fs-search><?=gettext('Address')?></th>
					<th><?=gettext('Kind')?></th>
					<th class="fs-col-actions" data-sortable="false"><span class="visually-hidden"><?=gettext('Actions')?></span></th>
				</tr>
			</thead>
			<tbody>
<?php foreach ($entries as $entry): ?>
				<tr>
					<td class="fs-mono"><?=htmlspecialchars($entry)?></td>
					<td class="fs-muted"><?=(strpos($entry, '/') !== false) ? gettext('Network') : gettext('Host')?></td>
					<td class="fs-col-actions">
<?php if ($can_delete): ?>
						<div class="fs-actions">
							<button type="button" class="fs-action fs-action--delete" data-entry="<?=htmlspecialchars($entry)?>"
							    title="<?=htmlspecialchars(sprintf(gettext('Remove %s'), $entry))?>" aria-label="<?=htmlspecialchars(sprintf(gettext('Remove %s'), $entry))?>"
							    data-fs-confirm="<?=htmlspecialchars(sprintf(gettext('Remove “%1$s” from %2$s?'), $entry, $tablename))?>"
							    data-fs-confirm-action="<?=gettext('Remove')?>"><i class="fa-solid fa-trash-can" aria-hidden="true"></i></button>
						</div>
<?php endif; ?>
					</td>
				</tr>
<?php endforeach; ?>
<?php if (empty($entries)) {
	fs_empty_row(3, gettext('No entries exist in this table.'));
} ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>
	<div class="panel-footer small fs-muted">
		<i class="fa-solid fa-circle-info" aria-hidden="true"></i>
		<?=gettext('Aliases become tables when they are loaded into the active firewall ruleset. This page shows the addresses the firewall is using right now.')?>
<?php if (!empty($table_comments)): ?>
		<details class="fs-tables-comments">
			<summary><?=gettext('Table file comments')?></summary>
			<pre class="fs-mono"><?=htmlspecialchars(implode("\n", $table_comments))?></pre>
		</details>
<?php endif; ?>
	</div>
</div>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	// Show another table when the picker changes
	$('#type').on('change', function() {
		this.form.submit();
	});

	// Remove one entry (confirmed by data-fs-confirm first)
	$('button[data-entry]').on('click', function() {
		var el = $(this);

		$.ajax(
			'/diag_tables.php',
			{
				type: 'post',
				data: {
					type: $('#table-entries').data('table'),
					delete: el.data('entry')
				},
				success: function() {
					var root = el.closest('.fs-table').get(0);
					el.closest('tr').remove();
					if (root && root._fsTable) {
						root._fsTable.apply(false);
					}
				}
		});
	});
});
//]]>
</script>

<?php
include("foot.inc");
