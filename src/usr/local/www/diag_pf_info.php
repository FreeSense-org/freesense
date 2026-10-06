<?php
/*
 * diag_pf_info.php
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
##|*IDENT=page-diagnostics-pf-info
##|*NAME=Diagnostics: pfInfo
##|*DESCR=Allows access to the 'Diagnostics: pfInfo' page
##|*MATCH=diag_pf_info.php*
##|-PRIV

require_once("guiconfig.inc");

$pgtitle = array(gettext("Diagnostics"), gettext("pfInfo"));

if (stristr($_POST['Submit'], gettext("No"))) {
	header("Location: index.php");
	exit;
}

if ($_REQUEST['getactivity']) {
	$text = shell_exec('/sbin/pfctl -vvsi');
	$text .= "<p/>";
	$text .= shell_exec('/sbin/pfctl -vvsm');
	$text .= "<p/>";
	$text .= shell_exec('/sbin/pfctl -vvst');
	$text .= "<p/>";
	$text .= shell_exec('/sbin/pfctl -vvsI');
	echo $text;
	exit;
}

$views = array(
	'counters' => gettext('Counters'),
	'interfaces' => gettext('Interfaces'),
	'limits' => gettext('Limits and timeouts'),
);
$view = fs_view_param(array_keys($views), 'counters');

/*
 * pfctl -vvsi: unindented lines are "Key: value" facts or section headers
 * ("State Table   Total   Rate"); indented lines are counters whose columns
 * are separated by two or more spaces. Deeper indented lines belong to the
 * counter above them ("Packets In" / "Passed").
 */
$facts = array();
$counters = array();
$section = '';
$section_cols = array();
$parent = '';
foreach (explode("\n", (string)shell_exec('/sbin/pfctl -vvsi')) as $line) {
	if (trim($line) === '') {
		continue;
	}
	$indent = strlen($line) - strlen(ltrim($line));
	$parts = preg_split('/\s{2,}/', trim($line));
	if ($indent === 0) {
		if (preg_match_all('/([A-Za-z][A-Za-z ]*?):\s+(.+?)(?=\s{2,}[A-Za-z][A-Za-z ]*?:|$)/', trim($line), $m, PREG_SET_ORDER) && strpos($parts[0], ':') !== false) {
			foreach ($m as $kv) {
				$facts[trim($kv[1])] = trim($kv[2]);
			}
			$section = '';
		} elseif (count($parts) === 2 && !preg_match('/^(Total|IPv4)$/', $parts[1])) {
			$facts[$parts[0]] = $parts[1];
			$section = '';
		} else {
			$section = array_shift($parts);
			$section_cols = $parts;
		}
		$parent = '';
		continue;
	}
	$name = array_shift($parts);
	if (empty($parts)) {
		$parent = $name;
		continue;
	}
	if ($indent > 2 && $parent !== '') {
		$name = "{$parent} / {$name}";
	} else {
		$parent = '';
	}
	if ($section_cols && $section_cols[0] === 'IPv4') {
		/* interface statistics: one row per address family */
		foreach ($section_cols as $i => $col) {
			$counters[] = array('section' => $section, 'name' => "{$name} ({$col})", 'value' => $parts[$i] ?? '', 'rate' => '');
		}
	} else {
		$counters[] = array('section' => $section, 'name' => $name, 'value' => $parts[0] ?? '', 'rate' => $parts[1] ?? '');
	}
}
$sections = array_values(array_unique(array_column($counters, 'section')));

$find = function ($section, $name) use ($counters) {
	foreach ($counters as $c) {
		if ($c['section'] === $section && $c['name'] === $name) {
			return $c;
		}
	}
	return array('value' => '', 'rate' => '');
};

/* pfctl -vvsm / -vvst: "name   value" */
$limits = array();
foreach (explode("\n", (string)shell_exec('/sbin/pfctl -vvsm')) as $line) {
	if (preg_match('/^(\S+)\s+hard limit\s+(\S+)/', trim($line), $m)) {
		$limits[$m[1]] = $m[2];
	}
}
$timeouts = array();
foreach (explode("\n", (string)shell_exec('/sbin/pfctl -vvst')) as $line) {
	if (preg_match('/^(\S+)\s+(.+)$/', trim($line), $m)) {
		$timeouts[$m[1]] = trim($m[2]);
	}
}

/* pfctl -vvsI: interface name, then tab-indented details */
$pf_ifs = array();
$cur = null;
foreach (explode("\n", (string)shell_exec('/sbin/pfctl -vvsI')) as $line) {
	if (trim($line) === '') {
		continue;
	}
	if ($line[0] !== "\t" && $line[0] !== ' ') {
		$cur = trim($line);
		$pf_ifs[$cur] = array('refs' => '', 'cleared' => '', 'stats' => array());
		continue;
	}
	if ($cur === null) {
		continue;
	}
	$line = trim($line);
	if (preg_match('/^References:\s+(\d+)/', $line, $m)) {
		$pf_ifs[$cur]['refs'] = $m[1];
	} elseif (preg_match('/^Cleared:\s+(.+)$/', $line, $m)) {
		$pf_ifs[$cur]['cleared'] = $m[1];
	} elseif (preg_match('/^(In|Out)(4|6)\/(Pass|Block):\s+\[\s*Packets:\s+(\d+)\s+Bytes:\s+(\d+)/', $line, $m)) {
		$pf_ifs[$cur]['stats'][$m[2]][strtolower($m[1] . $m[3])] = array((int)$m[4], (int)$m[5]);
	}
}

$state_entries = $find('State Table', 'current entries');
$searches = $find('State Table', 'searches');
$match = $find('Counters', 'match');

include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

$cell = function ($pair) {
	if (!$pair) {
		return '<span class="fs-muted">-</span>';
	}
	return '<span class="fs-mono">' . fs_h(number_format($pair[0])) . '</span>'
	    . '<div class="fs-muted small fs-mono">' . fs_h(format_bytes($pair[1])) . '</div>';
};
?>

<style>
.fs-pfinfo-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: var(--fs-sp-3); margin-bottom: var(--fs-sp-4); }
.fs-pfinfo-bar .fs-viewswitch { margin-bottom: 0; }
.fs-pfinfo-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(20rem, 1fr)); gap: var(--fs-sp-4); }
.fs-pfinfo-grid > .panel { margin-bottom: 0; }
</style>

<div class="fs-tiles" id="pfinfo-tiles" data-fs-live>
<?php
$status = $facts['Status'] ?? '';
fs_tile(gettext('Status'), preg_replace('/\s+for\s+.*$/', '', $status) ?: gettext('Unknown'), (stripos($status, 'Enabled') === 0) ? 'enabled' : 'disabled', preg_match('/for\s+(.+)$/', $status, $m) ? sprintf(gettext('for %s'), $m[1]) : null);
fs_tile(gettext('State entries'), ($state_entries['value'] !== '') ? number_format((float)$state_entries['value']) : '-', null, isset($limits['states']) ? sprintf(gettext('of %s'), number_format((float)$limits['states'])) : null);
fs_tile(gettext('State searches'), $searches['rate'] ?: '-', null, gettext('per second'));
fs_tile(gettext('Rule matches'), ($match['value'] !== '') ? number_format((float)$match['value']) : '-', null, $match['rate'] ? sprintf(gettext('%s now'), $match['rate']) : null);
?>
</div>

<div class="fs-pfinfo-bar">
	<?php fs_view_switch($views, $view); ?>
	<div class="form-check form-switch mb-0">
		<input class="form-check-input" type="checkbox" role="switch" id="refresh" name="refresh" checked>
		<label class="form-check-label" for="refresh"><?=gettext('Refresh automatically')?></label>
	</div>
</div>

<?php if ($view === 'counters'): ?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'search' => gettext('Search counters…'),
	'noun' => gettext('counters'),
	'noun_one' => gettext('counter'),
	'filters' => ['section' => [gettext('All sections')] + array_combine($sections, $sections)],
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover" data-sortable>
			<thead>
				<tr>
					<th data-fs-search><?=gettext('Counter')?></th>
					<th data-fs-search><?=gettext('Section')?></th>
					<th class="text-end"><?=gettext('Total')?></th>
					<th class="text-end"><?=gettext('Rate')?></th>
				</tr>
			</thead>
			<tbody id="pfinfo-counters" data-fs-live>
<?php foreach ($counters as $i => $c): ?>
				<tr data-fs-filter-section="<?=htmlspecialchars($c['section'])?>" data-key="c<?=$i?>">
					<td><?=htmlspecialchars($c['name'])?></td>
					<td class="fs-muted"><?=htmlspecialchars($c['section'])?></td>
					<td class="fs-mono text-end"><?=htmlspecialchars($c['value'])?></td>
					<td class="fs-mono text-end fs-muted"><?=htmlspecialchars($c['rate'])?></td>
				</tr>
<?php endforeach; ?>
<?php if (empty($counters)) {
	fs_empty_row(4, gettext('No counters are available.'));
} ?>
			</tbody>
		</table>
	</div>
<?php if ($facts): ?>
	<div class="panel-footer small fs-muted">
<?php foreach ($facts as $k => $v): if ($k === 'Status') continue; ?>
		<span class="me-3"><?=htmlspecialchars($k)?>: <span class="fs-mono"><?=htmlspecialchars($v)?></span></span>
<?php endforeach; ?>
	</div>
<?php endif; ?>
</div>

<?php elseif ($view === 'interfaces'): ?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'search' => gettext('Search interfaces…'),
	'noun' => gettext('rows'),
	'noun_one' => gettext('row'),
	'filters' => ['family' => [gettext('IPv4 and IPv6'), '4' => 'IPv4', '6' => 'IPv6']],
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover" data-sortable>
			<thead>
				<tr>
					<th data-fs-search><?=gettext('Interface')?></th>
					<th><?=gettext('Family')?></th>
					<th><?=gettext('In passed')?></th>
					<th><?=gettext('In blocked')?></th>
					<th><?=gettext('Out passed')?></th>
					<th><?=gettext('Out blocked')?></th>
					<th><?=gettext('References')?></th>
				</tr>
			</thead>
			<tbody id="pfinfo-ifs" data-fs-live>
<?php foreach ($pf_ifs as $ifname => $info): foreach (['4', '6'] as $fam): $st = $info['stats'][$fam] ?? array(); ?>
				<tr data-fs-filter-family="<?=$fam?>" data-key="<?=htmlspecialchars($ifname . '-' . $fam)?>">
					<td><strong><?=htmlspecialchars($ifname)?></strong></td>
					<td><?=fs_badge('neutral', 'IPv' . $fam)?></td>
					<td><?=$cell($st['inpass'] ?? null)?></td>
					<td><?=$cell($st['inblock'] ?? null)?></td>
					<td><?=$cell($st['outpass'] ?? null)?></td>
					<td><?=$cell($st['outblock'] ?? null)?></td>
					<td class="fs-mono fs-muted"><?=htmlspecialchars($info['refs'])?></td>
				</tr>
<?php endforeach; endforeach; ?>
<?php if (empty($pf_ifs)) {
	fs_empty_row(7, gettext('No interfaces are known to pf.'));
} ?>
			</tbody>
		</table>
	</div>
	<div class="panel-footer small fs-muted">
		<i class="fa-solid fa-circle-info" aria-hidden="true"></i>
		<?=gettext('Packets, with bytes below. Interface groups such as "all" add up their members.')?>
	</div>
</div>

<?php else: ?>
<div class="fs-pfinfo-grid">
	<div class="panel panel-default fs-table">
		<div class="panel-heading"><h2 class="panel-title"><?=gettext('Memory limits')?></h2></div>
		<div class="panel-body table-responsive">
			<table class="table table-hover">
				<thead><tr><th><?=gettext('Pool')?></th><th class="text-end"><?=gettext('Hard limit')?></th></tr></thead>
				<tbody>
<?php foreach ($limits as $k => $v): ?>
					<tr><td class="fs-mono"><?=htmlspecialchars($k)?></td><td class="fs-mono text-end"><?=htmlspecialchars(is_numeric($v) ? number_format((float)$v) : $v)?></td></tr>
<?php endforeach; ?>
<?php if (empty($limits)) {
	fs_empty_row(2, gettext('No limits are available.'));
} ?>
				</tbody>
			</table>
		</div>
	</div>
	<div class="panel panel-default fs-table">
		<div class="panel-heading"><h2 class="panel-title"><?=gettext('Timeouts')?></h2></div>
		<div class="panel-body table-responsive">
			<table class="table table-hover">
				<thead><tr><th><?=gettext('Timeout')?></th><th class="text-end"><?=gettext('Value')?></th></tr></thead>
				<tbody>
<?php foreach ($timeouts as $k => $v): ?>
					<tr><td class="fs-mono"><?=htmlspecialchars($k)?></td><td class="fs-mono text-end"><?=htmlspecialchars($v)?></td></tr>
<?php endforeach; ?>
<?php if (empty($timeouts)) {
	fs_empty_row(2, gettext('No timeouts are available.'));
} ?>
				</tbody>
			</table>
		</div>
	</div>
</div>
<?php endif; ?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	// Copy the children of one element into another without parsing HTML strings.
	function copyNodes(from, to) {
		var nodes = Array.prototype.map.call(from.childNodes, function (n) {
			return document.importNode(n, true);
		});
		to.replaceChildren.apply(to, nodes);
	}

	// Refresh the live parts of the page in place: tiles, and table cells
	// matched by row key so search, filters and sorting are kept.
	function refresh() {
		if (!document.getElementById('refresh').checked || document.hidden) {
			return;
		}
		fetch(window.location.href, {credentials: 'same-origin'}).then(function (r) {
			return r.ok ? r.text() : null;
		}).then(function (text) {
			if (!text) {
				return;
			}
			var doc = new DOMParser().parseFromString(text, 'text/html');
			document.querySelectorAll('[data-fs-live][id]').forEach(function (el) {
				var fresh = doc.getElementById(el.id);
				if (!fresh) {
					return;
				}
				if (el.tagName !== 'TBODY') {
					copyNodes(fresh, el);
					return;
				}
				fresh.querySelectorAll('tr[data-key]').forEach(function (tr) {
					var old = el.querySelector('tr[data-key="' + CSS.escape(tr.getAttribute('data-key')) + '"]');
					if (!old || old.cells.length !== tr.cells.length) {
						return;
					}
					for (var i = 0; i < tr.cells.length; i++) {
						copyNodes(tr.cells[i], old.cells[i]);
					}
				});
			});
		}).catch(function () {});
	}

	setInterval(refresh, 2500);
});
//]]>
</script>

<?php include("foot.inc");
