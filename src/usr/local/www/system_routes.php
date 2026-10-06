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

$tab_array = [];
$tab_array[0] = [gettext('Gateways'), false, 'system_gateways.php'];
$tab_array[1] = [gettext('Static Routes'), true, 'system_routes.php'];
$tab_array[2] = [gettext('Gateway Groups'), false, 'system_gateway_groups.php'];
display_top_tabs($tab_array);

?>
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('Static Routes')?></h2></div>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-striped table-hover table-sm table-rowdblclickedit">
				<thead>
					<tr>
						<th></th>
						<th><?=gettext('Network')?></th>
						<th><?=gettext('Gateway')?></th>
						<th><?=gettext('Interface')?></th>
						<th><?=gettext('Description')?></th>
						<th><?=gettext('Actions')?></th>
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
					<td title="<?=$title?>"><i class="<?=$icon?>"></i></td>
					<td>
						<?=strtolower($route['network'])?>
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
					<td>
						<a href="system_routes_edit.php?id=<?=$i?>" class="fa-solid fa-pencil" title="<?=gettext('Edit route')?>"></a>

						<a href="system_routes_edit.php?dup=<?=$i?>" class="fa-regular fa-clone" title="<?=gettext('Copy route')?>"></a>

				<?php if (isset($route['disabled'])) {
				?>
						<a href="?act=toggle&amp;id=<?=$i?>" class="fa-regular fa-square-check" title="<?=gettext('Enable route')?>" usepost></a>
				<?php } else {
				?>
						<a href="?act=toggle&amp;id=<?=$i?>" class="fa-solid fa-ban" title="<?=gettext('Disable route')?>" usepost></a>
				<?php }
				?>
						<a href="system_routes.php?act=del&amp;id=<?=$i?>" class="fa-solid fa-trash-can" title="<?=gettext('Delete route')?>" usepost></a>

					</td>
				</tr>
<?php endforeach; ?>
			</table>
		</div>
	</div>
</div>

<nav class="action-buttons">
	<a href="system_routes_edit.php" role="button" class="btn btn-success btn-sm">
		<i class="fa-solid fa-plus icon-embed-btn"></i>
		<?=gettext('Add')?>
	</a>
</nav>
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
