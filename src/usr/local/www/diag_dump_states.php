<?php
/*
 * diag_dump_states.php
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
##|*IDENT=page-diagnostics-showstates
##|*NAME=Diagnostics: Show States
##|*DESCR=Allow access to the 'Diagnostics: Show States' page.
##|*MATCH=diag_dump_states.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("interfaces.inc");
require_once("diag_dump_states.inc");

/* handle AJAX operations */
if (isset($_POST['action']) && $_POST['action'] == "remove") {
	if (isset($_POST['srcip']) && isset($_POST['dstip']) && diag_states_kill_pair($_POST['srcip'], $_POST['dstip'])) {
		echo htmlentities("|{$_POST['srcip']}|{$_POST['dstip']}|0|");
	} else {
		echo gettext("invalid input");
	}

	return;
}

if (isset($_POST['filter']) && isset($_POST['killfilter'])) {
	$retval = diag_states_kill_filter($_POST['filter']);
}

$input_errors = diag_states_filter_errors($_POST);

$pgtitle = array(gettext("Diagnostics"), gettext("States"), gettext("States"));
$pglinks = array("", "@self", "@self");
include("head.inc");

if (!empty($input_errors)) {
	print_input_errors($input_errors);
}

if (!empty($retval)) {
	print_info_box(htmlspecialchars(sprintf(gettext('The states to and from %s were removed.'), $retval)), 'success');
}

fs_tabs('diagnostics-states', 'diag_dump_states.php');

$current_statecount = trim((string)shell_exec('pfctl -si | grep "current entries" | awk \'{ print $3 }\''));

$iflist = diag_states_interfaces();
$ifselect = isset($_POST['interface']) ? $_POST['interface'] : "all";
$cur_filter = (string)($_POST['filter'] ?? '');
$cur_ruleid = (string)($_POST['ruleid'] ?? '');
$can_kill = isset($_POST['filter']) && (is_ipaddr($_POST['filter']) || is_subnet($_POST['filter']));

// Process web request and return an array of filtered states
$statedisp = process_state_req($_POST, $_REQUEST, false, true);
$states = count($statedisp);

$established = 0;
$state_ifs = [];
foreach ($statedisp as $dstate) {
	if (stripos($dstate['state'], 'ESTABLISHED') !== false) {
		$established++;
	}
	$state_ifs[$dstate['interface']] = true;
}

/* server-side filter controls in the list toolbar */
$filterform = '<form method="post" action="diag_dump_states.php" class="fs-states-filter" id="states-filter">'
    . '<label class="visually-hidden" for="interface">' . fs_h(gettext('Interface')) . '</label>'
    . '<select class="form-select form-select-sm" id="interface" name="interface">';
foreach ($iflist as $k => $v) {
	$filterform .= '<option value="' . fs_h($k) . '"' . (($ifselect == $k) ? ' selected' : '') . '>'
	    . fs_h(($k === 'all') ? gettext('All interfaces') : $v) . '</option>';
}
$filterform .= '</select>'
    . '<div class="fs-search"><i class="fa-solid fa-filter" aria-hidden="true"></i>'
    . '<input type="text" class="form-control fs-mono" id="filter" name="filter" value="' . fs_h($cur_filter) . '"'
    . ' placeholder="' . fs_h(gettext('Filter: 192.168, v6, icmp, ESTABLISHED…')) . '" aria-label="' . fs_h(gettext('Filter expression')) . '" autocomplete="off"></div>'
    . '<input type="text" class="form-control form-control-sm fs-mono fs-states-ruleid" id="ruleid" name="ruleid" value="' . fs_h($cur_ruleid) . '"'
    . ' placeholder="' . fs_h(gettext('Rule IDs')) . '" aria-label="' . fs_h(gettext('Rule ID (comma separated list of integer rule IDs)')) . '"'
    . ' title="' . fs_h(gettext('Comma separated list of integer rule IDs')) . '" autocomplete="off">'
    . '<button type="submit" class="btn btn-sm btn-primary" name="filterbtn" id="filterbtn" value="Filter">'
    . '<i class="fa-solid fa-filter icon-embed-btn" aria-hidden="true"></i>' . fs_h(gettext('Filter')) . '</button>';
if ($can_kill) {
	$filterform .= '<button type="submit" class="btn btn-sm btn-danger" name="killfilter" id="killfilter" value="Kill States"'
	    . ' data-fs-confirm="' . fs_h(sprintf(gettext('Kill all states to and from %s?'), $cur_filter)) . '"'
	    . ' data-fs-confirm-detail="' . fs_h(gettext('Open connections of this address or network break and must be set up again.')) . '"'
	    . ' data-fs-confirm-action="' . fs_h(gettext('Kill states')) . '">'
	    . '<i class="fa-solid fa-trash-can icon-embed-btn" aria-hidden="true"></i>' . fs_h(gettext('Kill states')) . '</button>';
}
$filterform .= '</form>';
?>

<style>
.fs-states-filter { display: flex; flex-wrap: wrap; align-items: center; gap: var(--fs-sp-2); flex: 1 1 40rem; }
.fs-states-filter .fs-search { flex: 1 1 14rem; }
.fs-states-ruleid { width: 7.5rem; height: 2rem; font-size: var(--fs-fs-sm); }
.fs-states-flow { display: flex; flex-wrap: wrap; align-items: baseline; gap: 0 var(--fs-sp-2); }
.fs-states-flow > i { color: var(--fs-text-muted); font-size: .75rem; }
.fs-states-orig { color: var(--fs-text-muted); font-size: var(--fs-fs-xs); }
#states-table td.fs-states-num, #states-table th.fs-states-num { text-align: right; white-space: nowrap; }
</style>

<div class="fs-tiles">
<?php
fs_tile(gettext('States in table'), ($current_statecount !== '') ? $current_statecount : '–');
fs_tile(gettext('Shown'), $states, null, ($cur_filter !== '' || $cur_ruleid !== '' || $ifselect !== 'all') ? gettext('Filtered') : null);
fs_tile(gettext('Established'), $established);
fs_tile(gettext('Interfaces'), count($state_ifs));
?>
</div>

<div class="panel panel-default fs-table" id="states-card">
<?php fs_table_toolbar([
	'search' => false,
	'noun' => gettext('states'),
	'noun_one' => gettext('state'),
	'custom' => $filterform,
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover table-sm" id="states-table" data-sortable>
			<thead>
				<tr>
					<th><?=gettext("Interface")?></th>
					<th><?=gettext("Protocol")?></th>
					<th><?=gettext("Source → Destination")?></th>
					<th><?=gettext("State")?></th>
					<th class="fs-states-num" data-sortable="false"><?=gettext("Packets in / out")?></th>
					<th class="fs-states-num" data-sortable="false"><?=gettext("Bytes in / out")?></th>
					<th class="fs-col-actions" data-sortable="false"><span class="visually-hidden"><?=gettext('Actions')?></span></th>
				</tr>
			</thead>
			<tbody>
<?php
foreach ($statedisp as $dstate):
	$d = $dstate['details'];
	$kill_label = sprintf(gettext('Remove all state entries from %1$s to %2$s'), $dstate['srcip'], $dstate['dstip']);
?>
				<tr>
					<td>
						<?=htmlspecialchars($dstate['interface'])?>
<?php if (!empty($d['direction'])): ?>
						<div class="fs-muted small"><?=htmlspecialchars($d['direction'])?></div>
<?php endif; ?>
					</td>
					<td><span class="fs-chip fs-chip--mono fs-chip--strong"><?=htmlspecialchars($dstate['proto'])?></span></td>
					<td>
						<div class="fs-states-flow fs-mono">
							<span><?=htmlspecialchars($d['src'])?></span>
							<i class="fa-solid fa-arrow-right" aria-label="<?=gettext('to')?>"></i>
							<span><?=htmlspecialchars($d['dst'])?></span>
						</div>
<?php if (!empty($d['src_orig']) || !empty($d['dst_orig'])): ?>
						<div class="fs-states-orig fs-mono"><?=htmlspecialchars(sprintf(gettext('original: %1$s → %2$s'), $d['src_orig'] ?: $d['src'], $d['dst_orig'] ?: $d['dst']))?></div>
<?php endif; ?>
					</td>
					<td class="fs-mono small"><?=htmlspecialchars($dstate['state'])?></td>
					<td class="fs-mono fs-states-num"><?=htmlspecialchars($dstate['packets'])?></td>
					<td class="fs-mono fs-states-num"><?=htmlspecialchars($dstate['bytes'])?></td>
					<td class="fs-col-actions">
<?php if (is_ipaddr($dstate['srcip']) && is_ipaddr($dstate['dstip'])): ?>
						<div class="fs-actions">
							<button type="button" class="fs-action fs-action--delete" data-entry="<?=htmlspecialchars($dstate['srcip'] . '|' . $dstate['dstip'])?>"
							    title="<?=htmlspecialchars($kill_label)?>" aria-label="<?=htmlspecialchars($kill_label)?>"
							    data-fs-confirm="<?=htmlspecialchars(sprintf(gettext('Kill the states from %1$s to %2$s?'), $dstate['srcip'], $dstate['dstip']))?>"
							    data-fs-confirm-detail="<?=gettext('Every state between these two addresses is removed; their connections must be set up again.')?>"
							    data-fs-confirm-action="<?=gettext('Kill states')?>"><i class="fa-solid fa-trash-can" aria-hidden="true"></i></button>
						</div>
<?php endif; ?>
					</td>
				</tr>
<?php
endforeach;

if ($states == 0) {
	if (isset($_POST['filter']) && !empty($_POST['filter'])) {
		$errmsg = gettext('No states were found that match the current filter.');
	} else if (!isset($_POST['filter']) && !isset($_REQUEST['ruleid']) &&
	    config_path_enabled('system/webgui', 'requirestatefilter')) {
		$errmsg = gettext('State display suppressed without filter submission. '.
		'See System > General Setup, Require State Filter.');
	} else {
		$errmsg = gettext('No states were found.');
	}
	fs_empty_row(7, $errmsg);
}
?>
			</tbody>
		</table>
	</div>
	<div class="panel-footer small fs-muted">
		<i class="fa-solid fa-circle-info" aria-hidden="true"></i>
		<?=gettext('The filter matches addresses, ports, protocols and states (e.g. 192.168, v6, icmp or ESTABLISHED). Filter by a single address or network to kill all of its states at once.')?>
	</div>
</div>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	// Kill the states of one row (confirmed by data-fs-confirm first)
	$('button[data-entry]').on('click', function() {
		var el = $(this);
		var data = el.data('entry').split('|');

		$.ajax('/diag_dump_states.php', {
			type: 'post',
			data: {
				action: 'remove',
				srcip: data[0],
				dstip: data[1]
			},
			success: function() {
				var root = document.getElementById('states-card');
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
