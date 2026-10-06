<?php
/*
 * status_services.php
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
##|*IDENT=page-status-services
##|*NAME=Status: Services
##|*DESCR=Allow access to the 'Status: Services' page.
##|*MATCH=status_services.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("service-utils.inc");
require_once("shortcuts.inc");
require_once("status_services.inc");

if ($_POST['ajax']) {
	if (isset($_POST['service'])) {
		$service_name = htmlspecialchars($_REQUEST['service']);
	}

	if (!empty($service_name)) {
		$savemsg = status_services_control($_POST['mode'], $service_name, $_REQUEST);
		sleep(5);
	}

	exit;
}

$pgtitle = array(gettext("Status"), gettext("Services"));
include("head.inc");

if ($savemsg) {
	print_info_box($savemsg, 'success');
}

$services = get_services();
uasort($services, "service_name_compare");

/*
 * Collect first so the summary tiles can sit above the table. The control
 * buttons keep the ids of get_service_control_links(): FreeSenseHelpers.js
 * posts them (ajax, mode, service, vpnmode, zone, id) to this page.
 */
$risky = ['unbound', 'dnsmasq', 'dhcpd', 'dhcpd6', 'kea-dhcp4', 'kea-dhcp6', 'ipsec', 'openvpn', 'captiveportal', 'dpinger', 'radvd', 'nginx'];
$rows = [];
$counts = ['running' => 0, 'stopped' => 0, 'disabled' => 0];
foreach ($services as $service) {
	if (empty($service['name'])) {
		continue;
	}
	if (empty($service['description'])) {
		$service['description'] = get_pkg_descr($service['name']);
	}
	$name = $service['name'];
	if (get_service_status($service)) {
		$state = 'running';
	} else {
		$state = is_service_enabled($name) ? 'stopped' : 'disabled';
	}
	$counts[$state]++;

	switch ($name) {
		case 'openvpn':
			$ctl_id = function ($mode) use ($service) {
				return "openvpn-{$mode}-{$service['mode']}-{$service['vpnid']}";
			};
			break;
		case 'captiveportal':
			$ctl_id = function ($mode) use ($service) {
				return "captiveportal-{$mode}-{$service['zone']}";
			};
			break;
		default:
			$ctl_id = function ($mode) use ($name) {
				return "{$mode}-{$name}";
			};
	}

	$label = $service['description'] ?: $name;
	$buttons = [];
	if ($state === 'running') {
		$is_risky = in_array($name, $risky, true);
		$buttons[] = [$ctl_id('restartservice'), 'fa-solid fa-arrow-rotate-right', sprintf(gettext('Restart %s'), $label),
		    $is_risky ? sprintf(gettext('Restart %s?'), $label) : null,
		    $is_risky ? gettext('The service is unavailable for a few seconds while it restarts.') : null,
		    gettext('Restart')];
		$buttons[] = [$ctl_id('stopservice'), 'fa-regular fa-circle-stop', sprintf(gettext('Stop %s'), $label),
		    sprintf(gettext('Stop %s?'), $label),
		    gettext('It stays stopped until it is started again or the system reboots.'),
		    gettext('Stop')];
	} elseif ($name == 'openvpn' || $name == 'captiveportal' || $state === 'stopped') {
		$buttons[] = [$ctl_id('startservice'), 'fa-solid fa-play', sprintf(gettext('Start %s'), $label), null, null, null];
	}

	/* related settings, status and log pages (shortcuts.inc) */
	$links = [];
	$scut = get_shortcut_by_service_name($name);
	if (!empty($scut)) {
		foreach ([
		    [get_shortcut_main_link($scut, false, $service), 'fa-solid fa-sliders', gettext('Settings for %s')],
		    [get_shortcut_status_link($scut, false, $service), 'fa-regular fa-chart-bar', gettext('Status of %s')],
		    [get_shortcut_log_link($scut, false), 'fa-regular fa-rectangle-list', gettext('Log entries of %s')],
		] as list($html, $icon, $fmt)) {
			if (preg_match('/href="([^"]*)"/', $html, $m)) {
				$links[] = [html_entity_decode($m[1], ENT_QUOTES), $icon, sprintf($fmt, $label)];
			}
		}
	}

	$rows[] = ['service' => $service, 'state' => $state, 'buttons' => $buttons, 'links' => $links];
}
?>

<style>
@media (max-width: 575.98px) {
	/* up to five actions: wrap them in rows of three instead of scrolling the table */
	.fs-svc-list .fs-actions { display: flex; flex-wrap: wrap; width: calc(3 * var(--fs-hit) + 4px); margin-left: auto; }
	.fs-svc-list td.fs-col-actions { white-space: normal; }
}
</style>

<div class="fs-tiles">
<?php
fs_tile(gettext('Running'), $counts['running']);
fs_tile(gettext('Stopped'), $counts['stopped']);
if ($counts['disabled'] > 0) {
	fs_tile(gettext('Disabled'), $counts['disabled'], null, gettext('Not enabled in their settings'));
}
?>
</div>

<div class="panel panel-default fs-table fs-svc-list">
<?php fs_table_toolbar([
	'title' => gettext('Services'),
	'search' => gettext('Search services…'),
	'noun' => gettext('services'),
	'noun_one' => gettext('service'),
	'filters' => ['state' => [gettext('All states'), 'running' => gettext('Running'), 'stopped' => gettext('Stopped'),
	    'disabled' => gettext('Disabled')]],
]); ?>
	<div class="panel-body table-responsive">
	<table class="table table-hover" data-sortable>
		<thead>
			<tr>
				<th class="fs-col-status d-none d-sm-table-cell"><?=gettext("Status")?></th>
				<th data-fs-search><?=gettext("Service")?></th>
				<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
			</tr>
		</thead>
		<tbody>
<?php foreach ($rows as $row):
	$service = $row['service'];
	$badge = [
		'running' => fs_badge('up', gettext('Running')),
		'stopped' => fs_badge('down', gettext('Stopped')),
		'disabled' => fs_badge('disabled'),
	][$row['state']];
?>
			<tr data-fs-filter-state="<?=$row['state']?>"<?=($row['state'] === 'disabled') ? ' class="fs-row-disabled"' : ''?>>
				<td class="d-none d-sm-table-cell"><?=$badge?></td>
				<td>
					<strong><?=htmlspecialchars($service['description'] ?: $service['name'])?></strong>
					<div class="fs-mono fs-muted small"><?=htmlspecialchars($service['name'])?></div>
					<div class="d-sm-none mt-1"><?=$badge?></div>
				</td>
				<td class="fs-col-actions"><div class="fs-actions">
<?php	foreach ($row['buttons'] as list($id, $icon, $label, $confirm, $detail, $action)): ?>
					<button<?=fs_attrs(['type' => 'button', 'class' => 'fs-action', 'id' => $id, 'title' => $label, 'aria-label' => $label,
					    'data-fs-confirm' => $confirm, 'data-fs-confirm-detail' => $detail, 'data-fs-confirm-action' => $confirm ? $action : null])?>><i class="<?=$icon?>" aria-hidden="true"></i></button>
<?php	endforeach; ?>
<?php	foreach ($row['links'] as list($href, $icon, $label)): ?>
					<a<?=fs_attrs(['class' => 'fs-action', 'href' => $href, 'title' => $label, 'aria-label' => $label])?>><i class="<?=$icon?>" aria-hidden="true"></i></a>
<?php	endforeach; ?>
				</div></td>
			</tr>
<?php endforeach; ?>
<?php if (empty($rows)) {
	fs_empty_row(3, gettext('No services found.'));
} ?>
		</tbody>
	</table>
	</div>
</div>

<?php include("foot.inc");
