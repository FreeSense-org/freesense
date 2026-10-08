<?php
/*
 * diag_routes.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
 * Copyright (c) 2006 Fernando Lamos
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
##|*IDENT=page-diagnostics-routingtables
##|*NAME=Diagnostics: Routing tables
##|*DESCR=Allow access to the 'Diagnostics: Routing tables' page.
##|*MATCH=diag_routes.php*
##|-PRIV

$limit = '100';
$filter = '';

/* Keep above the AJAX code so it gets CSRF protection */
require_once('guiconfig.inc');

if (isset($_POST['isAjax'])) {
	require_once('auth_check.inc');

	$netstat = "/usr/bin/netstat -rW";
	if (isset($_POST['IPv6'])) {
		$netstat .= " -f inet6";
		echo "IPv6\n";
	} else {
		$netstat .= " -f inet";
		echo "IPv4\n";

	}
	if (!isset($_POST['resolve'])) {
		$netstat .= " -n";
	}

	$netstat .= " | /usr/bin/tail -n +5";

	/* Ensure the user-supplied filter is sane */
	$filtertext = cleanup_regex_pattern($_POST['filter']);
	if (!empty($filtertext)) {
		/* Place filter after "--" (bare double-dash) so grep knows not
		 * to interpret the filter as command line parameters.
		 */
		$netstat .= " | /usr/bin/egrep -- " . escapeshellarg($filtertext);
	}

	if (is_numeric($_POST['limit']) && $_POST['limit'] > 0) {
		$netstat .= " | /usr/bin/head -n " . escapeshellarg($_POST['limit']);
	}

	echo htmlspecialchars_decode(shell_exec($netstat));

	exit;
}

$pgtitle = array(gettext("Diagnostics"), gettext("Routes"));
$shortcut_section = "routing";

$view = fs_view_param(['ipv4', 'ipv6'], 'ipv4');
$resolve = isset($_REQUEST['resolve']);
$validLimits = array('10', '50', '100', '200', '500', '1000', 'all');
if (isset($_REQUEST['limit']) && in_array($_REQUEST['limit'], $validLimits, true)) {
	$limit = $_REQUEST['limit'];
}

/* read the routing table: header line, then one route per line */
$netstat = '/usr/bin/netstat -rW -f ' . (($view === 'ipv6') ? 'inet6' : 'inet') . ($resolve ? '' : ' -n');
$lines = array();
exec($netstat, $lines);

$columns = array();
$routes = array();
foreach ($lines as $line) {
	if (empty($columns)) {
		if (strncmp($line, 'Destination', 11) === 0) {
			$columns = preg_split('/\s+/', trim($line));
		}
		continue;
	}
	if (trim($line) === '') {
		continue;
	}
	$fields = preg_split('/\s+/', trim($line));
	$route = array();
	foreach ($columns as $i => $col) {
		$route[$col] = $fields[$i] ?? '';
	}
	$routes[] = $route;
}

$total = count($routes);
$default_gw = '';
$route_ifs = array();
foreach ($routes as $route) {
	if ($route['Destination'] === 'default' && $default_gw === '') {
		$default_gw = $route['Gateway'];
	}
	$route_ifs[$route['Netif'] ?? ''] = true;
}
if ($limit !== 'all') {
	$routes = array_slice($routes, 0, (int)$limit);
}

$friendly = array();
foreach (array_keys($route_ifs) as $netif) {
	$name = convert_real_interface_to_friendly_descr($netif);
	$friendly[$netif] = $name ?: $netif;
}
$if_filter = array();
foreach ($friendly as $netif => $name) {
	$if_filter[$netif] = ($name !== $netif) ? "{$name} ({$netif})" : $netif;
}
natcasesort($if_filter);

$labels = array(
	'Destination' => gettext('Destination'),
	'Gateway' => gettext('Gateway'),
	'Flags' => gettext('Flags'),
	'Nhop#' => gettext('Next hop'),
	'Refs' => gettext('Refs'),
	'Use' => gettext('Uses'),
	'Mtu' => gettext('MTU'),
	'Netif' => gettext('Interface'),
	'Metric' => gettext('Metric'),
	'Expire' => gettext('Expire'),
);
$mono = array('Destination', 'Gateway', 'Flags', 'Nhop#', 'Refs', 'Use', 'Mtu', 'Metric', 'Expire');

fs_page_action(gettext('Refresh'), 'diag_routes.php?' . http_build_query(array_filter([
	'view' => $view,
	'resolve' => $resolve ? 'yes' : null,
	'limit' => ($limit !== '100') ? $limit : null,
])), 'fa-arrows-rotate', 'secondary');

include('head.inc');

fs_view_switch(['ipv4' => gettext('IPv4'), 'ipv6' => gettext('IPv6')], $view);

/* display options, kept as GET parameters */
$options = '<form method="get" action="diag_routes.php" class="fs-routes-options">'
    . '<input type="hidden" name="view" value="' . fs_h($view) . '">'
    . '<div class="form-check form-check-inline mb-0" title="' . fs_h(gettext('Name resolution can make the page slower.')) . '">'
    . '<input class="form-check-input" type="checkbox" id="resolve" name="resolve" value="yes"' . ($resolve ? ' checked' : '') . '>'
    . '<label class="form-check-label" for="resolve">' . fs_h(gettext('Resolve names')) . '</label></div>'
    . '<label class="visually-hidden" for="limit">' . fs_h(gettext('Rows to display')) . '</label>'
    . '<select class="form-select form-select-sm" id="limit" name="limit">';
foreach ($validLimits as $l) {
	$options .= '<option value="' . fs_h($l) . '"' . (($l === $limit) ? ' selected' : '') . '>'
	    . fs_h(($l === 'all') ? gettext('All rows') : sprintf(gettext('%s rows'), $l)) . '</option>';
}
$options .= '</select><noscript><button type="submit" class="btn btn-sm btn-outline-secondary">' . fs_h(gettext('Apply')) . '</button></noscript></form>';
?>

<style>
.fs-routes-options { display: flex; flex-wrap: wrap; align-items: center; gap: var(--fs-sp-3); }
.fs-routes-flags { display: flex; flex-wrap: wrap; gap: var(--fs-sp-1) var(--fs-sp-4); }
</style>

<div class="fs-tiles">
<?php
fs_tile(gettext('Routes'), $total);
fs_tile(gettext('Default gateway'), ($default_gw !== '') ? $default_gw : gettext('None'), ($default_gw !== '') ? null : 'warn');
fs_tile(gettext('Interfaces'), count($route_ifs));
?>
</div>

<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'search' => gettext('Search destination, gateway…'),
	'noun' => gettext('routes'),
	'noun_one' => gettext('route'),
	'filters' => ['if' => [gettext('All interfaces')] + $if_filter],
	'custom' => $options,
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover" data-sortable>
			<thead>
				<tr>
<?php foreach ($columns as $col): ?>
					<th<?=in_array($col, ['Destination', 'Gateway', 'Netif'], true) ? ' data-fs-search' : ''?>><?=htmlspecialchars($labels[$col] ?? $col)?></th>
<?php endforeach; ?>
				</tr>
			</thead>
			<tbody>
<?php foreach ($routes as $route): ?>
				<tr data-fs-filter-if="<?=htmlspecialchars($route['Netif'] ?? '')?>">
<?php foreach ($columns as $col): $value = $route[$col]; ?>
<?php if ($col === 'Netif'): ?>
					<td>
						<?=htmlspecialchars($friendly[$value] ?? $value)?>
<?php if (($friendly[$value] ?? $value) !== $value): ?>
						<div class="fs-muted small fs-mono"><?=htmlspecialchars($value)?></div>
<?php endif; ?>
					</td>
<?php elseif ($col === 'Destination' && $value === 'default'): ?>
					<td><?=fs_badge('info', gettext('default'))?></td>
<?php else: ?>
					<td class="<?=in_array($col, $mono, true) ? 'fs-mono' : ''?>"><?=htmlspecialchars($value)?></td>
<?php endif; ?>
<?php endforeach; ?>
				</tr>
<?php endforeach; ?>
<?php if (empty($routes)) {
	fs_empty_row(max(count($columns), 1), gettext('No routes were found.'));
} ?>
			</tbody>
		</table>
	</div>
	<div class="panel-footer small fs-muted">
<?php if ($limit !== 'all' && $total > count($routes)): ?>
		<p class="mb-1"><i class="fa-solid fa-circle-info" aria-hidden="true"></i> <?=htmlspecialchars(sprintf(gettext('Showing the first %1$s of %2$s routes.'), count($routes), $total))?></p>
<?php endif; ?>
		<div class="fs-routes-flags">
			<span><span class="fs-mono">U</span> <?=gettext('up')?></span>
			<span><span class="fs-mono">G</span> <?=gettext('via a gateway')?></span>
			<span><span class="fs-mono">H</span> <?=gettext('host route')?></span>
			<span><span class="fs-mono">S</span> <?=gettext('static')?></span>
			<span><span class="fs-mono">B</span> <?=gettext('blackhole')?></span>
			<span><span class="fs-mono">R</span> <?=gettext('reject')?></span>
			<span><span class="fs-mono">1</span> <?=gettext('protocol specific')?></span>
		</div>
	</div>
</div>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	// apply display options right away
	$('.fs-routes-options').on('change', 'input, select', function() {
		this.form.submit();
	});
});
//]]>
</script>

<?php include("foot.inc");
