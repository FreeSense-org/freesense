<?php
/*
 * diag_dump_states_source.php
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
##|*IDENT=page-diagnostics-sourcetracking
##|*NAME=Diagnostics: Show Source Tracking
##|*DESCR=Allow access to the 'Diagnostics: Show Source Tracking' page.
##|*MATCH=diag_dump_states_sources.php*
##|-PRIV

require_once("guiconfig.inc");

/* handle AJAX operations */
if ($_POST['action']) {
	if ($_POST['action'] == "remove") {
		if (is_ipaddr($_POST['srcip']) && is_ipaddr($_POST['dstip'])) {
			$retval = mwexec("/sbin/pfctl -K " . escapeshellarg($_POST['srcip']) . " -K " . escapeshellarg($_POST['dstip']));
			echo htmlentities("|{$_POST['srcip']}|{$_POST['dstip']}|{$retval}|");
		} else {
			echo gettext("invalid input");
		}
		exit;
	}
}

/* get our states */
$cmd = "/sbin/pfctl -s Sources";
if ($_POST['filter']) {
	/* Ensure the user-supplied filter is sane */
	$filtertext = cleanup_regex_pattern($_POST['filter']);
	if (!empty($filtertext)) {
		$cmd .= " | /usr/bin/egrep -- " . escapeshellarg($filtertext);
	}
}
exec($cmd, $sources);

$pgtitle = array(gettext("Diagnostics"), gettext("States"), gettext("Source Tracking"));
$pglinks = array("", "diag_dump_states.php", "@self");
include("head.inc");

fs_tabs('diagnostics-states', 'diag_dump_states_sources.php');

/* parse at most 1000 entries:
 * 192.168.20.2 -> 216.252.56.1 ( states 10, connections 0, rate 0.0/0s ) */
$entries = [];
$total_states = 0;
foreach ($sources as $line) {
	if (count($entries) >= 1000) {
		break;
	}
	$source_split = [];
	preg_match("/(.*)\s\(\sstates\s(.*),\sconnections\s(.*),\srate\s(.*)\s\)/", $line, $source_split);
	list($all, $info, $numstates, $numconnections, $rate) = array_pad($source_split, 5, '');

	$source_split = [];
	preg_match("/(.*)\s\<?-\>?\s(.*)/", $info, $source_split);
	list($all, $srcip, $dstip) = array_pad($source_split, 3, '');

	$entries[] = ['info' => $info, 'src' => trim($srcip), 'dst' => trim($dstip), 'states' => $numstates,
	    'connections' => $numconnections, 'rate' => $rate];
	$total_states += (int)$numstates;
}
$sticky = config_path_enabled('system', 'lb_use_sticky');
$cur_filter = (string)($_POST['filter'] ?? '');

ob_start();
?>
<form method="post" action="diag_dump_states_sources.php" class="fs-sources-filter" id="sources-filter">
	<div class="fs-search"><i class="fa-solid fa-filter" aria-hidden="true"></i>
		<input type="text" class="form-control fs-mono" id="filter" name="filter" value="<?=htmlspecialchars($cur_filter)?>"
		    placeholder="<?=gettext('Regular expression, e.g. ^192\.168\.')?>" aria-label="<?=gettext('Filter expression')?>"
		    title="<?=gettext('Use a regular expression to filter the source tracking table. Invalid or potentially dangerous patterns will be ignored.')?>" autocomplete="off">
	</div>
	<button type="submit" class="btn btn-sm btn-primary" name="Submit" id="Submit" value="Filter">
		<i class="fa-solid fa-filter icon-embed-btn" aria-hidden="true"></i><?=gettext('Filter')?>
	</button>
</form>
<?php
$filterform = ob_get_clean();
?>

<style>
.fs-sources-filter { display: flex; flex-wrap: wrap; align-items: center; gap: var(--fs-sp-2); flex: 1 1 24rem; }
.fs-sources-filter .fs-search { flex: 1 1 14rem; }
#sources-table td.fs-sources-num, #sources-table th.fs-sources-num { text-align: right; white-space: nowrap; }
</style>

<div class="fs-tiles">
<?php
fs_tile(gettext('Sticky connections'), $sticky ? gettext('On') : gettext('Off'));
fs_tile(gettext('Entries'), count($entries), null, ($cur_filter !== '') ? gettext('Filtered') : null);
fs_tile(gettext('States'), $total_states);
?>
</div>

<div class="panel panel-default fs-table" id="sources-card">
<?php fs_table_toolbar([
	'search' => false,
	'noun' => gettext('entries'),
	'noun_one' => gettext('entry'),
	'custom' => $filterform,
]); ?>
<?php if (empty($entries) && ($cur_filter === '')): ?>
	<div class="fs-tool-empty">
		<i class="fa-solid fa-route" aria-hidden="true"></i>
		<span><?=gettext('No source tracking entries were found.')?></span>
<?php if (!$sticky): ?>
		<span><?=gettext('Sticky connections are off, so only rules with source tracking options add entries here.')?></span>
<?php if (isAllowedPage('system_advanced_misc.php')): ?>
		<a class="btn btn-sm btn-primary" href="system_advanced_misc.php"><i class="fa-solid fa-sliders icon-embed-btn" aria-hidden="true"></i><?=gettext('Sticky connection settings')?></a>
<?php endif; ?>
<?php endif; ?>
	</div>
<?php else: ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover" id="sources-table" data-sortable>
			<thead>
				<tr>
					<th><?=gettext("Source")?></th>
					<th><?=gettext("Destination")?></th>
					<th class="fs-sources-num"><?=gettext("States")?></th>
					<th class="fs-sources-num"><?=gettext("Connections")?></th>
					<th class="fs-sources-num"><?=gettext("Rate")?></th>
					<th class="fs-col-actions" data-sortable="false"><span class="visually-hidden"><?=gettext('Actions')?></span></th>
				</tr>
			</thead>
			<tbody>
<?php foreach ($entries as $e):
	$label = sprintf(gettext('Remove all source tracking entries from %1$s to %2$s'), $e['src'], $e['dst']);
?>
				<tr>
					<td class="fs-mono"><?=htmlspecialchars(($e['src'] !== '') ? $e['src'] : $e['info'])?></td>
					<td class="fs-mono"><?=htmlspecialchars($e['dst'])?></td>
					<td class="fs-mono fs-sources-num"><?=htmlspecialchars($e['states'])?></td>
					<td class="fs-mono fs-sources-num"><?=htmlspecialchars($e['connections'])?></td>
					<td class="fs-mono fs-sources-num"><?=htmlspecialchars($e['rate'])?></td>
					<td class="fs-col-actions">
<?php if (is_ipaddr($e['src']) && is_ipaddr($e['dst'])): ?>
						<div class="fs-actions">
							<button type="button" class="fs-action fs-action--delete" data-entry="<?=htmlspecialchars($e['src'] . '|' . $e['dst'])?>"
							    title="<?=htmlspecialchars($label)?>" aria-label="<?=htmlspecialchars($label)?>"
							    data-fs-confirm="<?=htmlspecialchars(sprintf(gettext('Remove the source tracking entries from %1$s to %2$s?'), $e['src'], $e['dst']))?>"
							    data-fs-confirm-detail="<?=gettext('The sticky association is forgotten; the next connection may go to another destination.')?>"
							    data-fs-confirm-action="<?=gettext('Remove')?>"><i class="fa-solid fa-trash-can" aria-hidden="true"></i></button>
						</div>
<?php endif; ?>
					</td>
				</tr>
<?php endforeach; ?>
<?php if (empty($entries)) {
	fs_empty_row(6, gettext('No source tracking entries match the filter.'));
} ?>
			</tbody>
		</table>
	</div>
<?php endif; ?>
<?php if (count($sources) > 1000): ?>
	<div class="panel-footer small fs-muted">
		<i class="fa-solid fa-circle-info" aria-hidden="true"></i>
		<?=sprintf(gettext('Only the first 1000 of %s entries are shown. Use a filter to narrow the list.'), count($sources))?>
	</div>
<?php endif; ?>
</div>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	// Remove one entry (confirmed by data-fs-confirm first)
	$('button[data-entry]').on('click', function() {
		var el = $(this);
		var data = el.data('entry').split('|');

		$.ajax('/diag_dump_states_sources.php', {
			type: 'post',
			data: {
				action: 'remove',
				srcip: data[0],
				dstip: data[1]
			},
			success: function() {
				var root = document.getElementById('sources-card');
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
