<?php
/*
 * system_routes.php
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
##|*IDENT=page-system-staticroutes
##|*NAME=System: Static Routes
##|*DESCR=Allow access to the 'System: Static Routes' page.
##|*MATCH=system_routes.php*
##|-PRIV

require_once('guiconfig.inc');
require_once('functions.inc');
require_once('filter.inc');
require_once('shaper.inc');
require_once('system_routing.inc');

$a_gateways = get_gateways(GW_CACHE_ALL);

if ($_POST['apply']) {
	$pconfig = $_POST;
	$retval = routing_apply_changes();
}

if ($_POST['act'] === 'del') {
	if (routing_delete_static_route($_POST['id'])) {
		header('Location: system_routes.php');
		exit;
	}
}

if (isset($_POST['del_x'])) {
	/* delete selected routes */
	if (is_array($_POST['route']) && count($_POST['route'])) {
		routing_delete_static_routes($_POST['route']);
		header('Location: system_routes.php');
		exit;
	}
}

if ($_POST['act'] === 'toggle') {
	if (routing_toggle_static_route($_POST['id'], $input_errors)) {
		header('Location: system_routes.php');
		exit;
	}
}

if($_POST['save']) {
	/* yuck - IE won't send value attributes for image buttons, while Mozilla does - so we use .x/.y to find move button clicks instead... */
	unset($movebtn);
	foreach ($_POST as $pn => $pd) {
		if (preg_match("/move_(\d+)_x/", $pn, $matches)) {
			$movebtn = $matches[1];
			break;
		}
	}
	/* move selected routes before this route */
	if (isset($movebtn) && is_array($_POST['route']) && count($_POST['route'])) {
		routing_move_static_routes($movebtn, $_POST['route']);
		header('Location: system_routes.php');
		exit;
	}
}


$pgtitle = [gettext('System'), gettext('Routing'), gettext('Static Routes')];
$pglinks = ['', 'system_gateways.php', '@self'];
$shortcut_section = 'routing';

fs_page_action(gettext('Add route'), 'system_routes_edit.php', 'fa-plus');
include('head.inc');

if ($input_errors) {
	print_input_errors($input_errors);
}
if ($_POST['apply']) {
	print_apply_result_box($retval);
}
if (is_subsystem_dirty('staticroutes')) {
	print_apply_box(gettext('The static route configuration has been changed.') . '<br />' . gettext('The changes must be applied for them to take effect.'));
}

fs_tabs('system-routing', 'system_routes.php');

?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Static Routes'),
	'search' => gettext('Search static routes…'),
	'noun' => gettext('static routes'),
	'noun_one' => gettext('static route'),
]); ?>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-hover table-rowdblclickedit">
				<thead>
					<tr>
						<th data-fs-search></th>
						<th data-fs-search><?=gettext('Network')?></th>
						<th data-fs-search><?=gettext('Gateway')?></th>
						<th data-fs-search><?=gettext('Interface')?></th>
						<th data-fs-search><?=gettext('Description')?></th>
						<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
					</tr>
				</thead>
				<tbody>
<?php
foreach (config_get_path('staticroutes/route', []) as $i => $route):
	if (isset($a_gateways[$route['gateway']]['inactive'])) {
		$icon = 'fa-regular fa-circle-xmark';
		$title = gettext('Route inactive, gateway interface is missing');
	} elseif (isset($route['disabled'])) {
		$icon = 'fa-solid fa-ban';
		$title = gettext('Route disabled');
	} else {
		$icon = 'fa-regular fa-circle-check';
		$title = gettext('Route enabled');
	}
?>
				<tr<?=($icon != 'fa-regular fa-circle-check')? ' class="disabled"' : ''?>>
					<td><?=($icon == 'fa-regular fa-circle-xmark') ? fs_badge('down', gettext('Inactive'), $title) : (($icon == 'fa-solid fa-ban') ? fs_badge('disabled', null, $title) : fs_badge('enabled', null, $title))?></td>
					<td>
						<?=htmlspecialchars(strtolower($route['network']))?>
					</td>
					<td>
						<?=htmlentities($a_gateways[$route['gateway']]['name']) . " - " . htmlentities($a_gateways[$route['gateway']]['gateway'])?>
					</td>
					<td>
						<?=isset($a_gateways[$route['gateway']]['friendlyiface']) ? convert_friendly_interface_to_friendly_descr($a_gateways[$route['gateway']]['friendlyiface']) : ''?>
					</td>
					<td>
						<?=htmlspecialchars($route['descr'])?>
					</td>
					<td class="fs-col-actions">
<?=fs_row_actions([
							['edit', "system_routes_edit.php?id={$i}", $route['network']],
							['copy', "system_routes_edit.php?dup={$i}", $route['network']],
							['toggle', "?act=toggle&id={$i}", $route['network'], ['enabled' => !isset($route['disabled'])]],
							['delete', "system_routes.php?act=del&id={$i}", $route['network'], ['thing' => gettext('static route')]],
						])?>
					</td>
				</tr>
<?php endforeach; ?>
<?php if (empty(config_get_path('staticroutes/route', []))) {
	fs_empty_row(6, gettext('No static routes yet.'), 'system_routes_edit.php', gettext('Add route'));
} ?>
				</tbody>
			</table>
		</div>
	</div>
</div>

<div class="infoblock">
<?php
print_info_box(
	sprintf(gettext('%1$s Route is inactive, gateway interface is missing'), '<br /><strong><i class="fa-regular fa-circle-xmark"></i></strong>') .
	sprintf(gettext('%1$s Route disabled'), '<br /><strong><i class="fa-solid fa-ban"></i></strong>') .
	sprintf(gettext('%1$s Route enabled'), '<br /><strong><i class="fa-regular fa-circle-check"></i></strong>')
	);
?>
</div>
<?php

include('foot.inc');
