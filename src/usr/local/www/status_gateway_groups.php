<?php
/*
 * status_gateway_groups.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
 * Copyright (c) 2010 Seth Mos <seth.mos@dds.nl>
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
##|*IDENT=page-status-gatewaygroups
##|*NAME=Status: Gateway Groups
##|*DESCR=Allow access to the 'Status: Gateway Groups' page.
##|*MATCH=status_gateway_groups.php*
##|-PRIV

define('COLOR', true);

require_once("guiconfig.inc");

if ($_POST['act'] == 'killgw') {
	if (!empty($_POST['gwname'])) {
		remove_failover_states($_POST['gwname']);
	} elseif (!empty($_POST['gwip']) && is_ipaddr($_POST['gwip'])) {
		list($ipaddr, $scope) = explode('%', $_POST['gwip']);
		mwexec("/sbin/pfctl -k gateway -k " . escapeshellarg($ipaddr));
	}

	header("Location: status_gateways.php");
	exit;
}

$changedesc = gettext("Gateway Groups") . ": ";

$pgtitle = array(gettext("Status"), gettext("Gateways"), gettext("Gateway Groups"));
$pglinks = array("", "status_gateways.php", "@self");
$shortcut_section = "gateway-groups";
include("head.inc");

fs_tabs('status-gateways', 'status_gateway_groups.php');

/* collect first, so the summary tiles can sit above the groups */
$level_state = function ($level) {
	return match ($level) {
		GW_STATUS_LEVEL_SUCCESS => 'online',
		GW_STATUS_LEVEL_WARNING => 'degraded',
		GW_STATUS_LEVEL_FAILURE => 'down',
		default => 'pending',
	};
};
$groups = [];
$counts = ['online' => 0, 'degraded' => 0, 'down' => 0, 'pending' => 0];
foreach (config_get_path('gateways/gateway_group', []) as $gateway_group) {
	$members = [];
	$tiers = [];
	foreach ((array)($gateway_group['item'] ?? []) as $item) {
		list($member, $tier) = explode("|", $item);
		list($gateway_status, $gateway_details) = get_gateway_status($member);
		$status_text = get_gateway_status_text($gateway_status);
		$state = $level_state($status_text['level']);
		$counts[$state]++;
		$tiers[$tier][] = $state;
		$members[] = [
			'tier' => (int)$tier,
			'name' => array_get_path($gateway_details, 'config/name', $member),
			'ip' => array_get_path($gateway_details, 'config/gateway'),
			'descr' => array_get_path($gateway_details, 'config/descr', ''),
			'state' => $state,
			'reason' => $status_text['reason'],
		];
	}
	usort($members, function ($a, $b) {
		return $a['tier'] <=> $b['tier'];
	});
	ksort($tiers);

	/* the group serves from the first tier with an online member */
	$group_state = 'down';
	$active_tier = null;
	foreach ($tiers as $tier => $states) {
		if (in_array('online', $states, true)) {
			$group_state = 'online';
			$active_tier = $tier;
			break;
		}
	}
	if ($group_state === 'down') {
		foreach ($tiers as $tier => $states) {
			foreach (['degraded', 'pending'] as $s) {
				if (in_array($s, $states, true)) {
					$group_state = $s;
					$active_tier = $tier;
					break 3;
				}
			}
		}
	}
	if (empty($tiers)) {
		$group_state = 'pending';
	}
	$groups[] = ['config' => $gateway_group, 'members' => $members, 'state' => $group_state, 'active_tier' => $active_tier];
}
$group_label = [
	'online' => gettext('Online'),
	'degraded' => gettext('Degraded'),
	'down' => gettext('Down'),
	'pending' => gettext('Pending'),
];
?>

<style>
.fs-gwg-head { display: flex; flex-wrap: wrap; align-items: center; gap: var(--fs-sp-2) var(--fs-sp-3); }
.fs-gwg-head > .panel-title { margin: 0; }
.fs-gwg-descr { color: var(--fs-text-muted); font-size: var(--fs-fs-sm); }
.fs-gwg-head > .fs-actions { margin-left: auto; }
.fs-gwg-list { display: grid; grid-template-columns: minmax(0, 1fr); gap: var(--fs-sp-4); }
.fs-gwg-list > .panel { margin-bottom: 0; }
.fs-gwg-tier { display: inline-block; min-width: 3.6rem; padding: 0 .45rem; border: 1px solid var(--fs-border); border-radius: var(--fs-r-sm); color: var(--fs-text-muted); font-size: var(--fs-fs-xs); font-weight: 600; line-height: 1.4rem; text-align: center; white-space: nowrap; }
.fs-gwg-tier.is-active { border-color: color-mix(in srgb, var(--fs-pass) 45%, transparent); color: var(--fs-pass); }
.fs-gwg-empty { padding: var(--fs-sp-5) var(--fs-sp-4); color: var(--fs-text-muted); text-align: center; }
</style>

<div class="fs-tiles">
<?php
fs_tile(gettext('Groups'), count($groups));
fs_tile(gettext('Members online'), $counts['online']);
fs_tile(gettext('Members degraded'), $counts['degraded'], ($counts['degraded'] > 0) ? 'warn' : null);
fs_tile(gettext('Members down'), $counts['down'], ($counts['down'] > 0) ? 'error' : null);
?>
</div>

<div class="fs-gwg-list">
<?php foreach ($groups as $g_idx => $group):
	$name = $group['config']['name'];
	$kill_group = fs_row_actions([['custom', '?act=killgw&gwname=' . urlencode($name), $name, [
		'icon' => 'fa-solid fa-circle-xmark', 'post' => true,
		'label' => sprintf(gettext('Kill states using group %s'), $name),
		'confirm' => sprintf(gettext('Kill firewall states created by policy routing rules using gateway group “%s”?'), $name),
		'confirm_action' => gettext('Kill states')]]]);
?>
	<section class="panel panel-default fs-table" aria-labelledby="gwg-<?=$g_idx?>-title">
		<div class="panel-heading fs-gwg-head">
			<h2 class="panel-title" id="gwg-<?=$g_idx?>-title"><?=htmlspecialchars($name)?></h2>
			<?=fs_badge($group['state'], $group_label[$group['state']])?>
<?php	if (!empty($group['config']['descr'])): ?>
			<span class="fs-gwg-descr"><?=htmlspecialchars($group['config']['descr'])?></span>
<?php	endif; ?>
			<?=$kill_group?>
		</div>
		<div class="panel-body table-responsive">
		<table class="table table-hover">
			<thead>
				<tr>
					<th class="fs-col-status"><?=gettext('Tier')?></th>
					<th><?=gettext('Gateway')?></th>
					<th class="d-none d-md-table-cell"><?=gettext('Address')?></th>
					<th><?=gettext('Status')?></th>
					<th class="fs-col-actions"><span class="visually-hidden"><?=gettext('Actions')?></span></th>
				</tr>
			</thead>
			<tbody>
<?php	foreach ($group['members'] as $m):
		$actions = [];
		if (!empty($m['ip']) && is_ipaddr($m['ip'])) {
			$actions[] = ['custom', '?act=killgw&gwip=' . urlencode($m['ip']), $m['ip'], [
				'icon' => 'fa-regular fa-circle-xmark', 'post' => true,
				'label' => sprintf(gettext('Kill states using gateway IP %s'), $m['ip']),
				'confirm' => sprintf(gettext('Kill all firewall states using gateway IP %s via policy routing and reply-to?'), $m['ip']),
				'confirm_action' => gettext('Kill states')]];
		}
?>
				<tr>
					<td><span class="fs-gwg-tier<?=($m['tier'] === $group['active_tier']) ? ' is-active' : ''?>"><?=htmlspecialchars(sprintf(gettext('Tier %s'), $m['tier']))?></span></td>
					<td>
						<strong><?=htmlspecialchars($m['name'])?></strong>
<?php		if (!empty($m['ip'])): ?>
						<div class="fs-mono small d-md-none"><?=htmlspecialchars($m['ip'])?></div>
<?php		endif; ?>
<?php		if ($m['descr'] !== ''): ?>
						<div class="fs-muted small"><?=htmlspecialchars($m['descr'])?></div>
<?php		endif; ?>
					</td>
					<td class="fs-mono d-none d-md-table-cell"><?=htmlspecialchars((string)$m['ip'])?></td>
					<td><?=fs_badge($m['state'], $m['reason'])?></td>
					<td class="fs-col-actions"><?=fs_row_actions($actions)?></td>
				</tr>
<?php	endforeach; ?>
<?php	if (empty($group['members'])) {
		fs_empty_row(5, gettext('This group has no gateways.'));
	} ?>
			</tbody>
		</table>
		</div>
	</section>
<?php endforeach; ?>
<?php if (empty($groups)): ?>
	<div class="panel panel-default">
		<div class="fs-gwg-empty">
			<p><?=gettext('No gateway groups are configured.')?></p>
<?php	if (isAllowedPage('system_gateway_groups_edit.php')): ?>
			<a class="btn btn-sm btn-primary" href="system_gateway_groups_edit.php"><i class="fa-solid fa-plus icon-embed-btn" aria-hidden="true"></i><?=gettext('Add gateway group')?></a>
<?php	endif; ?>
		</div>
	</div>
<?php endif; ?>
</div>

<?php include("foot.inc");
