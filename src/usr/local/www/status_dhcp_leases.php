<?php
/*
 * status_dhcp_leases.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
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
##|*IDENT=page-status-dhcpleases
##|*NAME=Status: DHCP leases
##|*DESCR=Allow access to the 'Status: DHCP leases' page.
##|*MATCH=status_dhcp_leases.php*
##|-PRIV

require_once('guiconfig.inc');
require_once('config.inc');
require_once('system.inc');
require_once('services_dhcp.inc');

$pgtitle = [gettext('Status'), gettext('DHCP Leases')];
$shortcut_section = 'dhcp';
if (dhcp_is_backend('kea')) {
	$shortcut_section = 'kea-dhcp4';
}

/* ?all=1 also lists expired and released leases (server-side filter, as before) */
$show_all = (intval($_REQUEST['all'] ?? 0) == 1);
$all_param = $show_all ? 1 : 0;

if (dhcp_is_backend('isc')):
if (($_POST['deleteip']) && (is_ipaddr($_POST['deleteip']))) {
	$leasesfile = "{$g['dhcpd_chroot_path']}/var/db/dhcpd.leases";

	/* Stop DHCPD */
	killbyname("dhcpd");

	/* Read existing leases */
	/* $leases_contents has the lines of the file, including the newline char at the end of each line. */
	$leases_contents = file($leasesfile);
	$newleases_contents = array();
	$i = 0;
	while ($i < count($leases_contents)) {
		/* Find the lease(s) we want to delete */
		if ($leases_contents[$i] == "lease {$_POST['deleteip']} {\n") {
			/* Skip to the end of the lease declaration */
			do {
				$i++;
			} while ($leases_contents[$i] != "}\n");
		} else {
			/* It's a line we want to keep, copy it over. */
			$newleases_contents[] = $leases_contents[$i];
		}
		$i++;
	}

	/* Write out the new leases file */
	$fd = fopen($leasesfile, 'w');
	fwrite($fd, implode("\n", $newleases_contents));
	fclose($fd);

	/* Restart DHCP Service */
	services_dhcpd_configure();
	header("Location: status_dhcp_leases.php?all={$all_param}");
}
endif; /* dhcp_is_backend('isc') */

if (dhcp_is_backend('kea')):
if (($_POST['deleteip']) && (is_ipaddr($_POST['deleteip']))) {
	system_del_kea4lease($_POST['deleteip']);
}
endif; /* dhcp_is_backend('kea') */

if (dhcp_is_backend('isc')):
if ($_POST['cleardhcpleases']) {
	killbyname("dhcpd");
	sleep(2);
	unlink_if_exists("{$g['dhcpd_chroot_path']}/var/db/dhcpd.leases*");

	services_dhcpd_configure();
	header("Location: status_dhcp_leases.php?all={$all_param}");
}
endif; /* dhcp_is_backend('isc') */

if (dhcp_is_backend('kea')):
if ($_POST['cleardhcpleases']) {
	system_clear_all_kea4leases();
}
endif; /* dhcp_is_backend('kea') */

$view = fs_view_param(['leases', 'pools'], 'leases');

// Load MAC-Manufacturer table
$mac_man = load_mac_manufacturer_table();

$leases = system_get_dhcpleases();
if (!is_array($leases['lease'] ?? null)) {
	$leases['lease'] = [];
}

/*
 * Translate these once so we don't do it over and over in the loops
 * below.
 */
$online_string = gettext("active/online");
$active_string = gettext("active");
$expired_string = gettext("expired");
$dynamic_string = gettext("dynamic");
$static_string = gettext("static");

if ($_REQUEST['order']) {
	usort($leases['lease'], function($a, $b) {
		return strcmp($a[$_REQUEST['order']], $b[$_REQUEST['order']]);
	});
}

/* collect first: the tiles, the lease list and the pool utilization share one pass */
$counts = ['active' => 0, 'expired' => 0, 'static' => 0, 'total' => 0];
$rows = [];
$dhcp_leases_subnet_counter = array(); //array to sum up # of leases / subnet
$iflist = get_configured_interface_with_descr(); //get interface descr for # of leases

foreach ($leases['lease'] as $data) {
	$counts['total']++;
	if ($data['act'] == $active_string) {
		$state = 'active';
	} elseif ($data['act'] == $expired_string) {
		$state = 'expired';
	} elseif ($data['act'] == $static_string) {
		$state = 'static';
	} else {
		$state = 'other';
	}
	if (isset($counts[$state])) {
		$counts[$state]++;
	}

	if ($data['act'] != $static_string) {
		$range = null;
		foreach (config_get_path('dhcpd', []) as $dhcpif => $dhcpifconf) {
			if (empty($dhcpifconf)) {
				continue;
			}
			if (!is_array($dhcpifconf['range']) || !isset($dhcpifconf['enable'])) {
				continue;
			}
			if (is_inrange_v4($data['ip'], $dhcpifconf['range']['from'], $dhcpifconf['range']['to'])) {
				$data['if'] = $dhcpif;
				$range = $dhcpifconf['range'];
				break;
			}

			// Check if the IP is in the range of any DHCP pools
			if (is_array($dhcpifconf['pool'])) {
				foreach ($dhcpifconf['pool'] as $dhcppool) {
					if (is_array($dhcppool['range'])) {
						if (is_inrange_v4($data['ip'], $dhcppool['range']['from'], $dhcppool['range']['to'])) {
							$data['if'] = $dhcpif;
							$range = $dhcppool['range'];
							break 2;
						}
					}
				}
			}
		}
		/* utilization counts the leases in use (active) per range */
		if ($range !== null) {
			$dlskey = $data['if'] . '-' . $range['from'];
			if (!isset($dhcp_leases_subnet_counter[$dlskey])) {
				$dhcp_leases_subnet_counter[$dlskey] = ['dhcpif' => $data['if'], 'from' => $range['from'], 'to' => $range['to'], 'count' => 0];
			}
			if ($state === 'active') {
				$dhcp_leases_subnet_counter[$dlskey]['count']++;
			}
		}
	}

	if ($data['act'] != $active_string && $data['act'] != $static_string && !$show_all) {
		continue;
	}

	$data['state'] = $state;
	$rows[] = $data;
}
ksort($dhcp_leases_subnet_counter);

$failover = (dhcp_is_backend('isc') && is_array($leases['failover'] ?? null)) ? $leases['failover'] : [];
$ha_servers = [];
if (dhcp_is_backend('kea')) {
	$status = system_get_kea4status();
	if (is_array($status) && is_array($status['arguments'] ?? null) && array_key_exists('high-availability', $status['arguments'])) {
		foreach ($status['arguments']['high-availability'] as $ha_status) {
			foreach ($ha_status['ha-servers'] as $where => $ha_server) {
				$ha_servers[] = [$where, $ha_server];
			}
		}
	}
}

fs_page_action(gettext('Clear all leases'), 'status_dhcp_leases.php?cleardhcpleases=true&all=' . $all_param, 'fa-trash-can', 'danger', [
	'usepost' => true,
	'data-fs-confirm' => gettext('Clear all DHCP leases?'),
	'data-fs-confirm-detail' => gettext('Every lease is removed. Clients ask for a new lease when they renew.'),
	'data-fs-confirm-action' => gettext('Clear leases'),
]);

include('head.inc');

display_isc_warning();
?>

<style>
.fs-dhcp-state { display: inline-flex; flex-wrap: wrap; gap: 4px; }
.fs-dhcp-sub { display: block; color: var(--fs-text-muted); font-size: var(--fs-fs-xs); }
.fs-dhcp-dns { color: var(--fs-info); margin-right: .25rem; }
.fs-dhcp-time { white-space: nowrap; font-size: var(--fs-fs-sm); }
.fs-dhcp-toggle { white-space: nowrap; }
.fs-dhcp-meter { display: flex; align-items: center; gap: .6rem; min-width: 11rem; }
.fs-dhcp-meter progress { flex: 1 1 auto; height: .5rem; border: 0; border-radius: 999px; overflow: hidden; appearance: none; background: var(--fs-surface-raised); color: var(--fs-pass); }
.fs-dhcp-meter progress::-webkit-progress-bar { background: var(--fs-surface-raised); }
.fs-dhcp-meter progress::-webkit-progress-value { background: var(--fs-pass); }
.fs-dhcp-meter progress::-moz-progress-bar { background: var(--fs-pass); }
.fs-dhcp-meter.is-warn progress::-webkit-progress-value { background: var(--fs-warn); }
.fs-dhcp-meter.is-warn progress::-moz-progress-bar { background: var(--fs-warn); }
.fs-dhcp-meter.is-full progress::-webkit-progress-value { background: var(--fs-block); }
.fs-dhcp-meter.is-full progress::-moz-progress-bar { background: var(--fs-block); }
.fs-dhcp-meter > span { min-width: 3rem; text-align: right; font-variant-numeric: tabular-nums; }
</style>

<?php fs_view_switch(['leases' => gettext('Leases'), 'pools' => gettext('Pools')], $view); ?>

<div class="fs-tiles">
<?php
fs_tile(gettext('Active'), $counts['active'], ($counts['active'] > 0) ? 'active' : null);
fs_tile(gettext('Static'), $counts['static']);
fs_tile(gettext('Expired'), $counts['expired'], null, ($counts['expired'] && !$show_all) ? gettext('Not listed') : null);
fs_tile(gettext('Total'), $counts['total']);
?>
</div>

<?php if ($view === 'leases'):
	$if_choices = [];
	foreach ($rows as $data) {
		if (!empty($data['if'])) {
			$if_choices[$data['if']] = $iflist[$data['if']] ?? strtoupper($data['if']);
		}
	}
	$filters = [
		'state' => [gettext('All leases'), 'active' => gettext('Active'), 'static' => gettext('Static')] +
		    ($show_all ? ['expired' => gettext('Expired'), 'other' => gettext('Other')] : []),
		'client' => [gettext('Online and offline'), 'online' => gettext('Online'), 'offline' => gettext('Offline')],
	];
	if (count($if_choices) > 1) {
		$filters['if'] = [gettext('All interfaces')] + $if_choices;
	}
	$toggle = $show_all
	    ? ['status_dhcp_leases.php?all=0', 'fa-eye-slash', gettext('Hide expired')]
	    : ['status_dhcp_leases.php?all=1', 'fa-eye', gettext('Show expired')];
?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Leases'),
	'search' => gettext('Search IP, MAC, hostname…'),
	'noun' => gettext('leases'),
	'noun_one' => gettext('lease'),
	'filters' => $filters,
	'actions' => '<a class="btn btn-sm btn-outline-secondary fs-dhcp-toggle" href="' . fs_h($toggle[0]) . '">'
	    . '<i class="fa-solid ' . $toggle[1] . ' icon-embed-btn" aria-hidden="true"></i>' . fs_h($toggle[2]) . '</a>',
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover" data-sortable>
			<thead>
				<tr>
					<th class="fs-col-status" data-fs-search><?=gettext('Status')?></th>
					<th data-fs-search><?=gettext('IP address')?></th>
					<th data-fs-search><?=gettext('MAC address')?></th>
					<th data-fs-search><?=gettext('Hostname')?></th>
					<th data-fs-search><?=gettext('Description')?></th>
					<th data-fs-search><?=gettext('Start')?></th>
					<th data-fs-search><?=gettext('End')?></th>
					<th class="fs-col-actions"><span class="visually-hidden"><?=gettext('Actions')?></span></th>
				</tr>
			</thead>
			<tbody>
<?php foreach ($rows as $data):
	$mac = $data['mac'];
	$mac_hi = strtoupper($mac[0] . $mac[1] . $mac[3] . $mac[4] . $mac[6] . $mac[7]);
	$online = ($data['online'] === $online_string);
	$name = explode('.', $data['hostname'])[0];
	$label = ($name !== '') ? $name : $data['ip'];
	$if_q = 'if=' . urlencode($data['if'] ?? '');

	$actions = [];
	if ($data['type'] == $static_string) {
		$actions[] = ['edit', "services_dhcp_edit.php?{$if_q}&id=" . urlencode($data['staticmap_array_index']), $label,
		    ['attrs' => ['title' => gettext('Edit static mapping'), 'aria-label' => sprintf(gettext('Edit static mapping %s'), $label)]]];
	}
	if ($data['type'] == $dynamic_string) {
		$actions[] = ['custom', "services_dhcp_edit.php?{$if_q}&mac=" . urlencode($data['mac']) . '&hostname=' . urlencode($data['hostname']), $label,
		    ['icon' => 'fa-thumbtack', 'label' => sprintf(gettext('Add static mapping for %s'), $label)]];
	}
	$actions[] = ['custom', "services_wol_edit.php?{$if_q}&mac=" . urlencode($data['mac']) . '&descr=' . urlencode($data['hostname']), $label,
	    ['icon' => 'fa-bookmark', 'label' => sprintf(gettext('Add Wake-on-LAN mapping for %s'), $label)]];
	if (!$online) {
		$actions[] = ['custom', "services_wol.php?{$if_q}&mac=" . urlencode($data['mac']), $label,
		    ['icon' => 'fa-power-off', 'label' => sprintf(gettext('Send Wake-on-LAN packet to %s'), $label), 'post' => true]];
	}
	if ($data['type'] == $dynamic_string && !$online) {
		$actions[] = ['delete', 'status_dhcp_leases.php?deleteip=' . urlencode($data['ip']) . '&all=' . $all_param, $data['ip'],
		    ['thing' => gettext('lease'), 'detail' => gettext('The client asks for a new lease the next time it connects.')]];
	}

	$state_badge = match ($data['state']) {
		'active' => fs_badge('active'),
		'expired' => fs_badge('expired'),
		'static' => fs_badge('info', gettext('Static')),
		default => fs_badge('neutral', ucfirst((string)$data['act'])),
	};
?>
				<tr data-fs-filter-state="<?=$data['state']?>" data-fs-filter-client="<?=$online ? 'online' : 'offline'?>" data-fs-filter-if="<?=htmlspecialchars($data['if'] ?? '')?>">
					<td><span class="fs-dhcp-state"><?=$state_badge?><?=$online ? fs_badge('online') : fs_badge('offline')?></span></td>
					<td>
						<span class="fs-mono"><?=htmlspecialchars($data['ip'])?></span>
<?php if (!empty($data['if'])): ?>
						<span class="fs-dhcp-sub"><?=htmlspecialchars($iflist[$data['if']] ?? strtoupper($data['if']))?></span>
<?php endif; ?>
					</td>
					<td>
						<span class="fs-mono"><?=htmlspecialchars($mac)?></span>
<?php if (isset($mac_man[$mac_hi])): ?>
						<span class="fs-dhcp-sub"><?=htmlspecialchars($mac_man[$mac_hi])?></span>
<?php endif; ?>
<?php if ($data['cid']): ?>
						<span class="fs-dhcp-sub"><?=gettext('Client ID')?>: <span class="fs-mono"><?=htmlspecialchars($data['cid'])?></span></span>
<?php endif; ?>
					</td>
					<td>
<?php if ($data['hostname'] && $data['dnsreg']): ?>
						<i class="fa-solid fa-globe fs-dhcp-dns" title="<?=gettext('Registered with the DNS Resolver')?>" aria-hidden="true"></i><span class="visually-hidden"><?=gettext('Registered with the DNS Resolver')?></span>
<?php endif; ?>
						<?=htmlspecialchars($name)?>
					</td>
					<td><?=htmlspecialchars($data['descr'])?></td>
<?php if ($data['type'] != $static_string): ?>
					<td class="fs-mono fs-dhcp-time"><?=htmlspecialchars($data['starts'])?></td>
					<td class="fs-mono fs-dhcp-time"><?=htmlspecialchars($data['ends'])?></td>
<?php else: ?>
					<td class="fs-muted"><?=gettext('n/a')?></td>
					<td class="fs-muted"><?=gettext('n/a')?></td>
<?php endif; ?>
					<td class="fs-col-actions"><?=fs_row_actions($actions)?></td>
				</tr>
<?php endforeach; ?>
<?php if (empty($rows)) {
	fs_empty_row(8, $show_all ? gettext('No leases to display.') : gettext('No active or static leases.'));
} ?>
			</tbody>
		</table>
	</div>
	<div class="panel-footer small fs-muted">
		<i class="fa-solid fa-globe" aria-hidden="true"></i> <?=gettext('Hostname registered with the DNS Resolver.')?>
		<?=gettext('Offline clients have no entry in the ARP table.')?>
	</div>
</div>

<?php else: /* pools */ ?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Lease utilization'),
	'search' => false,
	'noun' => gettext('ranges'),
	'noun_one' => gettext('range'),
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover" data-sortable>
			<thead>
				<tr>
					<th><?=gettext('Interface')?></th>
					<th><?=gettext('Pool start')?></th>
					<th><?=gettext('Pool end')?></th>
					<th><?=gettext('Used')?></th>
					<th><?=gettext('Capacity')?></th>
					<th data-sortable="false"><?=gettext('Utilization')?></th>
				</tr>
			</thead>
			<tbody>
<?php foreach ($dhcp_leases_subnet_counter as $listcounters):
	$now = $listcounters['count'];
	$max = ip_range_size_v4($listcounters['from'], $listcounters['to']);
	$per = ($max > 0) ? (int)(($now / $max) * 100) : 0;
	$level = ($per > 90) ? ' is-full' : (($per > 75) ? ' is-warn' : '');
?>
				<tr>
					<td><?=htmlspecialchars($iflist[$listcounters['dhcpif']] ?? $listcounters['dhcpif'])?></td>
					<td class="fs-mono"><?=htmlspecialchars($listcounters['from'])?></td>
					<td class="fs-mono"><?=htmlspecialchars($listcounters['to'])?></td>
					<td class="fs-mono"><?=$now?></td>
					<td class="fs-mono"><?=$max?></td>
					<td>
						<div class="fs-dhcp-meter<?=$level?>">
							<progress value="<?=$now?>" max="<?=max(1, $max)?>" aria-label="<?=htmlspecialchars(sprintf(gettext('%1$s of %2$s addresses in use'), $now, $max))?>"></progress>
							<span><?=$per?>%</span>
						</div>
					</td>
				</tr>
<?php endforeach; ?>
<?php if (empty($dhcp_leases_subnet_counter)) {
	fs_empty_row(6, gettext('No leases are in use.'));
} ?>
			</tbody>
		</table>
	</div>
</div>

<?php if (!empty($failover)): ?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar(['title' => gettext('Failover pools'), 'search' => false, 'noun' => gettext('groups'), 'noun_one' => gettext('group')]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover">
			<thead>
				<tr>
					<th><?=gettext('Failover group')?></th>
					<th><?=gettext('My state')?></th>
					<th><?=gettext('Since')?></th>
					<th><?=gettext('Peer state')?></th>
					<th><?=gettext('Since')?></th>
				</tr>
			</thead>
			<tbody>
<?php foreach ($failover as $data): ?>
				<tr>
					<td><?=htmlspecialchars($data['name'])?></td>
					<td><?=fs_badge(($data['mystate'] == 'normal') ? 'up' : 'warn', $data['mystate'])?></td>
					<td class="fs-mono fs-dhcp-time"><?=htmlspecialchars($data['mydate'])?></td>
					<td><?=fs_badge(($data['partnerstate'] == 'normal') ? 'up' : 'warn', $data['partnerstate'])?></td>
					<td class="fs-mono fs-dhcp-time"><?=htmlspecialchars($data['partnerdate'])?></td>
				</tr>
<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>
<?php endif; ?>

<?php if (!empty($ha_servers)):
	$heartbeatdelay = ((int)config_get_path('kea/ha/heartbeatdelay', kea_defaults('heartbeatdelay'))) / 1000 + 2;
?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar(['title' => gettext('High availability'), 'search' => false, 'noun' => gettext('nodes'), 'noun_one' => gettext('node')]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover">
			<thead>
				<tr>
					<th class="fs-col-status"><?=gettext('Status')?></th>
					<th><?=gettext('Node name')?></th>
					<th><?=gettext('Node type')?></th>
					<th><?=gettext('Node role')?></th>
					<th><?=gettext('Latest heartbeat')?></th>
					<th><?=gettext('Node state')?></th>
				</tr>
			</thead>
			<tbody>
<?php foreach ($ha_servers as list($where, $ha_server)):
	/* same rules as dhcp_ha_status_icon(): the local node is always online */
	$ha_badge = fs_badge('online');
	if ($where === 'remote') {
		if (!$ha_server['in-touch'] || $ha_server['communication-interrupted']) {
			$ha_badge = fs_badge('offline');
		} elseif ($ha_server['age'] >= $heartbeatdelay) {
			$ha_badge = fs_badge('degraded', gettext('Interrupted'));
		}
	}
?>
				<tr>
					<td><?=$ha_badge?></td>
					<td><?=htmlspecialchars($ha_server['server-name'])?></td>
					<td><?=htmlspecialchars($where)?></td>
					<td><?=htmlspecialchars($ha_server['role'])?></td>
					<td><?=htmlspecialchars(kea_format_age($ha_server['age']))?></td>
					<td><?=htmlspecialchars($ha_server['state'] ?? $ha_server['last-state'])?></td>
				</tr>
<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>
<?php endif; ?>
<?php endif; /* view */ ?>

<?php
include('foot.inc');
