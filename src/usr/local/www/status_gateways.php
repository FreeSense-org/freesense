<?php
/*
 * status_gateways.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
 * Copyright (c) 2010 Seth Mos <seth.mos@dds.nl>
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
##|*IDENT=page-status-gateways
##|*NAME=Status: Gateways
##|*DESCR=Allow access to the 'Status: Gateways' page.
##|*MATCH=status_gateways.php*
##|-PRIV

require_once("guiconfig.inc");

if ($_POST['act'] == 'killgw') {
	if (!empty($_POST['gwname'])) {
		mwexec("/sbin/pfctl -k label -k " . escapeshellarg(make_rule_label_string($_POST['gwname'], RULE_LABEL_KEY_GATEWAY, false)));
	} elseif (!empty($_POST['gwip']) && is_ipaddr($_POST['gwip'])) {
		list($ipaddr, $scope) = explode('%', $_POST['gwip']);
		mwexec("/sbin/pfctl -k gateway -k " . escapeshellarg($ipaddr));
	} elseif (!empty($_POST['gwdef4'])) {
		mwexec("/sbin/pfctl -k gateway -k '0.0.0.0'");
	} elseif (!empty($_POST['gwdef6'])) {
		mwexec("/sbin/pfctl -k gateway -k '::'");
	}

	header("Location: status_gateways.php");
	exit;
}

$pgtitle = array(gettext("Status"), gettext("Gateways"));
$pglinks = array("", "@self");
$shortcut_section = "gateways";
include("head.inc");

/* active tabs */
fs_tabs('status-gateways', 'status_gateways.php');
/* collect first, so the summary tiles can sit above the table */
$rows = [];
$counts = ['online' => 0, 'degraded' => 0, 'down' => 0, 'pending' => 0];
foreach (get_gateways() as $gateway) {
	list($gateway_status, $gateway_details) = get_gateway_status($gateway);
	$status_text = get_gateway_status_text($gateway_status);
	$state = match ($status_text['level']) {
		GW_STATUS_LEVEL_SUCCESS => 'online',
		GW_STATUS_LEVEL_WARNING => 'degraded',
		GW_STATUS_LEVEL_FAILURE => 'down',
		default => 'pending',
	};
	$counts[$state]++;
	$rows[] = [$gateway_status, $gateway_details, $status_text['reason'], $state];
}
?>
<div class="fs-tiles">
<?php
fs_tile(gettext('Online'), $counts['online'], ($counts['online'] > 0) ? 'online' : null);
fs_tile(gettext('Degraded'), $counts['degraded'], ($counts['degraded'] > 0) ? 'degraded' : null);
fs_tile(gettext('Down'), $counts['down'], ($counts['down'] > 0) ? 'down' : null);
if ($counts['pending'] > 0) {
	fs_tile(gettext('Pending'), $counts['pending'], 'pending');
}
?>
</div>

<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Gateways'),
	'search' => gettext('Search gateways…'),
	'noun' => gettext('gateways'),
	'noun_one' => gettext('gateway'),
	'filters' => ['state' => [gettext('All states'), 'online' => gettext('Online'), 'degraded' => gettext('Degraded'),
	    'down' => gettext('Down'), 'pending' => gettext('Pending')]],
]); ?>
	<div class="panel-body table-responsive">
	<table class="table table-hover" data-sortable>
		<thead>
			<tr>
				<th data-fs-search><?=gettext("Name"); ?></th>
				<th data-fs-search><?=gettext("Gateway"); ?></th>
				<th data-fs-search><?=gettext("Monitor"); ?></th>
				<th><?=gettext("RTT"); ?></th>
				<th><?=gettext("RTTsd"); ?></th>
				<th><?=gettext("Loss"); ?></th>
				<th data-fs-search><?=gettext("Status"); ?></th>
				<th data-fs-search><?=gettext("Description"); ?></th>
				<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions"); ?></span></th>
			</tr>
		</thead>
		<tbody>
<?php	foreach ($rows as list($gateway_status, $gateway_details, $status_reason, $state)):
		$gwname = array_get_path($gateway_details, 'config/name', '');
		$gwip = array_get_path($gateway_details, 'config/gateway');
		$monitored = ($gateway_status != GW_STATUS_UNKNOWN) &&
		    ($gateway_status != GW_STATUS_ONLINE_FORCED) && ($gateway_status != GW_STATUS_OFFLINE_FORCED);
		$metric = function ($path) use ($gateway_status, $gateway_details, $monitored) {
			if ($gateway_status == GW_STATUS_UNKNOWN) {
				return gettext("Pending");
			}
			return $monitored ? htmlspecialchars(array_get_path($gateway_details, $path, '')) : '';
		};

		$actions = [['custom', '?act=killgw&gwname=' . urlencode($gwname), $gwname, [
			'icon' => 'fa-solid fa-circle-xmark', 'post' => true,
			'label' => sprintf(gettext('Kill states routed via %s'), $gwname),
			'confirm' => sprintf(gettext('Kill all firewall states created by policy routing rules using gateway “%s”?'), $gwname),
			'confirm_action' => gettext('Kill states')]]];
		if (!empty($gwip) && is_ipaddr($gwip)) {
			$actions[] = ['custom', '?act=killgw&gwip=' . urlencode($gwip), $gwip, [
			    'icon' => 'fa-regular fa-circle-xmark', 'post' => true,
			    'label' => sprintf(gettext('Kill states using gateway IP %s'), $gwip),
			    'confirm' => sprintf(gettext('Kill all firewall states using gateway IP %s via policy routing and reply-to?'), $gwip),
			    'confirm_action' => gettext('Kill states')]];
		}
		if (!is_null(array_get_path($gateway_details, 'config/isdefaultgw'))) {
			$v6 = (array_get_path($gateway_details, 'config/ipprotocol') == 'inet6');
			$actions[] = ['custom', $v6 ? '?act=killgw&gwdef6=true' : '?act=killgw&gwdef4=true', '', [
			    'icon' => 'fa-solid fa-xmark', 'post' => true,
			    'label' => $v6 ? gettext('Kill default IPv6 gateway states') : gettext('Kill default IPv4 gateway states'),
			    'confirm' => $v6
			        ? gettext('Kill all firewall states which use the default IPv6 gateway (::) and not policy routing or reply-to rules?')
			        : gettext('Kill all firewall states which use the default IPv4 gateway (0.0.0.0) and not policy routing or reply-to rules?'),
			    'confirm_action' => gettext('Kill states')]];
		}
?>
			<tr data-fs-filter-state="<?=$state?>">
				<td>
					<?=htmlspecialchars($gwname)?>
<?php				if (!is_null(array_get_path($gateway_details, 'config/isdefaultgw'))): ?>
					<?=fs_badge('info', gettext('Default'))?>
<?php				endif; ?>
				</td>
				<td class="fs-mono"><?=htmlspecialchars($gwip ?? '')?></td>
				<td class="fs-mono">
<?php
				if ($gateway_status != GW_STATUS_UNKNOWN) {
					echo $monitored ? htmlspecialchars(array_get_path($gateway_details, 'status/monitorip', '')) : gettext("(unmonitored)");
				}
?>
				</td>
				<td><?=$metric('status/delay')?></td>
				<td><?=$metric('status/stddev')?></td>
				<td><?=$metric('status/loss')?></td>
				<td><?=fs_badge($state, $status_reason)?></td>
				<td><?=htmlspecialchars(array_get_path($gateway_details, 'config/descr', '')); ?></td>
				<td class="fs-col-actions"><?=fs_row_actions($actions)?></td>
			</tr>
<?php	endforeach;

	if (empty($rows)) {
		fs_empty_row(9, gettext('No gateways are configured.'));
	}
?>
		</tbody>
	</table>
	</div>
</div>

<?php include("foot.inc"); ?>
