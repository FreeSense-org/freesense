<?php
/*
 * status_dhcpv6_leases.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
 * Copyright (c) 2011 Seth Mos
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
##|*IDENT=page-status-dhcpv6leases
##|*NAME=Status: DHCPv6 leases
##|*DESCR=Allow access to the 'Status: DHCPv6 leases' page.
##|*MATCH=status_dhcpv6_leases.php*
##|-PRIV

require_once('guiconfig.inc');
require_once('config.inc');
require_once('parser_dhcpv6_leases.inc');
require_once('util.inc');

$pgtitle = [gettext('Status'), gettext('DHCPv6 Leases')];
$shortcut_section = 'dhcp6';
if (dhcp_is_backend('kea')) {
	$shortcut_section = 'kea-dhcp6';
}

/* ?all=1 also lists expired and released leases (server-side filter, as before) */
$show_all = (intval($_REQUEST['all'] ?? 0) == 1);
$all_param = $show_all ? 1 : 0;

if (dhcp_is_backend('isc')):
$leasesfile = "{$g['dhcpd_chroot_path']}/var/db/dhcpd6.leases";

if (($_POST['deleteip']) && (is_ipaddr($_POST['deleteip']))) {
	/* Stop DHCPD */
	killbyname("dhcpd");

	/* Read existing leases */
	$leases_contents = explode("\n", file_get_contents($leasesfile));
	$newleases_contents = array();
	$i = 0;
	while ($i < count($leases_contents)) {
		/* Find the lease(s) we want to delete */
		if ($leases_contents[$i] == "  iaaddr {$_POST['deleteip']} {") {
			/* The iaaddr line is two lines down from the start of the lease, so remove those two lines. */
			array_pop($newleases_contents);
			array_pop($newleases_contents);
			/* Skip to the end of the lease declaration */
			do {
				$i++;
			} while ($leases_contents[$i] != "}");
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
	header("Location: status_dhcpv6_leases.php?all={$all_param}");
}

if ($_POST['cleardhcpleases']) {
	killbyname("dhcpd");
	sleep(2);
	unlink_if_exists("{$g['dhcpd_chroot_path']}/var/db/dhcpd6.leases*");

	services_dhcpd_configure();
	header("Location: status_dhcpv6_leases.php?all={$all_param}");
}
endif; /* dhcp_is_backend('isc') */

if (dhcp_is_backend('kea')):
if ($_POST['deleteip'] && is_ipaddrv6($_POST['deleteip'])) {
	system_del_kea6lease($_POST['deleteip']);
	header("Location: status_dhcpv6_leases.php?all={$all_param}");
}

if ($_POST['deletepd']) {
	system_del_kea6lease($_POST['deletepd'], 'IA_PD');
	header("Location: status_dhcpv6_leases.php?all={$all_param}");
}

if ($_POST['cleardhcpleases']) {
	system_clear_all_kea6leases();
	header("Location: status_dhcpv6_leases.php?all={$all_param}");
}
endif; /* dhcp_is_backend('kea') */

$view = fs_view_param(['leases', 'prefixes', 'pools'], 'leases');

// Load MAC-Manufacturer table
$mac_man = load_mac_manufacturer_table();

function leasecmp($a, $b) {
	return strcmp($a[$_REQUEST['order']], $b[$_REQUEST['order']]);
}

function adjust_gmt($dt) {
	$dhcpv6leaseinlocaltime = "no";
	if (is_array(config_get_path('dhcpdv6'))) {
		$dhcpdv6 = config_get_path('dhcpdv6');
		foreach ($dhcpdv6 as $dhcpdv6params) {
			if (empty($dhcpdv6params)) {
				continue;
			}
			$dhcpv6leaseinlocaltime = $dhcpdv6params['dhcpv6leaseinlocaltime'];
			if ($dhcpv6leaseinlocaltime == "yes") {
				break;
			}
		}
	}

	if ($dhcpv6leaseinlocaltime == "yes") {
		$ts = strtotime($dt . " GMT");
		if ($ts !== false) {
			return date("Y/m/d H:i:s", $ts);
		}
	}
	/* If we did not need to convert to local time or the conversion failed, just return the input. */
	return $dt;
}

if (isset($leasesfile) && is_file($leasesfile)) {
	$leases_content = file_get_contents ($leasesfile);
	$leasesfile_found = true;
} else {
	$leases_content = array();
	$leasesfile_found = false;
}

$ndpdata = get_ndpdata();
$pools = array();
$leases = array();
$prefixes = array();
$mappings = array();

// Translate these once so we don't do it over and over in the loops below.
$online_string = gettext("active/online");
$offline_string = gettext("idle/offline");
$active_string = gettext("active");
$expired_string = gettext("expired");
$reserved_string = gettext("reserved");
$released_string = gettext("released");
$dynamic_string = gettext("dynamic");
$static_string = gettext("static");

if (dhcp_is_backend('isc')) {
	$lang_pack = [ 'online' =>  $online_string, 'offline' => $offline_string,
	               'active' =>  $active_string, 'expired' => $expired_string,
	               'reserved' => $reserved_string, 'released' => $released_string,
	               'dynamic' => $dynamic_string, 'static' =>  $static_string];
	// Handle the content of the lease file - parser_dhcpv6_leases.inc
	gui_parse_leases ($pools, $leases, $prefixes, $mappings, $leases_content,
			  $ndpdata, $lang_pack);

	if (count($leases) > 0) {
		$leases = array_remove_duplicate($leases, "ip");
	}

	if (count($prefixes) > 0) {
		$prefixes = array_remove_duplicate($prefixes, "prefix");
	}

	if (count($pools) > 0) {
		$pools = array_remove_duplicate($pools, "name");
		asort($pools);
	}

	foreach (config_get_path('interfaces', []) as $ifname => $ifarr) {
		foreach (config_get_path("dhcpdv6/{$ifname}/staticmap", []) as $static) {
			$slease = array();
			$slease['ip'] = merge_ipv6_delegated_prefix(get_interface_ipv6($ifname), $static['ipaddrv6'], get_interface_subnetv6($ifname));
			$slease['type'] = "static";
			$slease['duid'] = $static['duid'];
			$slease['start'] = "";
			$slease['end'] = "";
			$slease['hostname'] = $static['hostname'];
			$slease['act'] = $static_string;
			if (in_array($slease['ip'], array_keys($ndpdata))) {
				$slease['online'] = $online_string;
			} else {
				$slease['online'] = $offline_string;
			}

			$leases[] = $slease;
		}
	}
} else {
	$kea6leases = system_get_kea6leases();
	$leases = $kea6leases['lease'];
	$prefixes = system_get_kea6prefixes();
}
if (!is_array($leases)) {
	$leases = [];
}
if (!is_array($prefixes)) {
	$prefixes = [];
}

if ($_REQUEST['order']) {
	usort($leases, "leasecmp");
}

$lease_state = function ($data) use ($active_string, $expired_string, $static_string) {
	if ($data['act'] == $active_string) {
		return 'active';
	} elseif ($data['act'] == $expired_string) {
		return 'expired';
	} elseif ($data['act'] == $static_string) {
		return 'static';
	}
	return 'other';
};
$state_badge = function ($state, $data) {
	return match ($state) {
		'active' => fs_badge('active'),
		'expired' => fs_badge('expired'),
		'static' => fs_badge('info', gettext('Static')),
		default => fs_badge('neutral', ucfirst((string)$data['act'])),
	};
};

/* collect first: tiles, the lease list and the pool utilization share one pass */
$counts = ['active' => 0, 'expired' => 0, 'static' => 0, 'total' => 0];
$rows = [];
$dhcp_leases_subnet_counter = array(); //array to sum up # of leases / subnet
$iflist = get_configured_interface_with_descr(); //get interface descr for # of leases

foreach ($leases as $data) {
	$state = $lease_state($data);
	$counts['total']++;
	if (isset($counts[$state])) {
		$counts[$state]++;
	}

	if ($data['act'] !== $static_string) {
		foreach (config_get_path('dhcpdv6', []) as $dhcpif => $dhcpifconf) {
			if (empty($dhcpifconf)) {
				continue;
			}

			if (!is_array($dhcpifconf['range']) || !isset($dhcpifconf['enable'])) {
				continue;
			}

			$data['if'] = convert_real_interface_to_friendly_interface_name(guess_interface_from_ip($data['ip']));

			$range = null;
			if (!empty($data['if']) && is_inrange_v6($data['ip'], $dhcpifconf['range']['from'], $dhcpifconf['range']['to'])) {
				$range = $dhcpifconf['range'];
			} elseif (is_array($dhcpifconf['pool'])) {
				foreach ($dhcpifconf['pool'] as $dhcppool) {
					if (is_array($dhcppool['range']) && !empty($data['if']) &&
					    is_inrange_v6($data['ip'], $dhcppool['range']['from'], $dhcppool['range']['to'])) {
						$range = $dhcppool['range'];
						break;
					}
				}
			}
			if ($range !== null) {
				/* utilization counts the leases in use (active) per range */
				$dlskey = $data['if'] . '-' . $range['from'];
				if (!isset($dhcp_leases_subnet_counter[$dlskey])) {
					$dhcp_leases_subnet_counter[$dlskey] = ['dhcpif' => $data['if'], 'from' => $range['from'], 'to' => $range['to'], 'count' => 0];
				}
				if ($state === 'active') {
					$dhcp_leases_subnet_counter[$dlskey]['count']++;
				}
				break;
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

/* prefix delegation leases */
$prefix_rows = [];
foreach ($prefixes as $data) {
	if ($data['act'] != $active_string && $data['act'] != $static_string && !$show_all) {
		continue;
	}
	if (dhcp_is_backend('isc')) {
		if ($data['act'] == $static_string) {
			foreach (config_get_path('dhcpdv6', []) as $dhcpif => $dhcpifconf) {
				if (empty($dhcpifconf)) {
					continue;
				}
				if (is_array($dhcpifconf['staticmap'])) {
					foreach ($dhcpifconf['staticmap'] as $staticent) {
						if ($data['ip'] == $staticent['ipaddrv6']) {
							$data['if'] = $dhcpif;
							break;
						}
					}
				}
				/* exit as soon as we have an interface */
				if ($data['if'] != "") {
					break;
				}
			}
		} else {
			$data['if'] = convert_real_interface_to_friendly_interface_name(guess_interface_from_ip($data['ip']));
		}
	}
	$data['state'] = $lease_state($data);
	$prefix_rows[] = $data;
}

$ha_servers = [];
if (dhcp_is_backend('kea')) {
	$status = system_get_kea6status();
	if (is_array($status) && is_array($status['arguments'] ?? null) && array_key_exists('high-availability', $status['arguments'])) {
		foreach ($status['arguments']['high-availability'] as $ha_status) {
			foreach ($ha_status['ha-servers'] as $where => $ha_server) {
				$ha_servers[] = [$where, $ha_server];
			}
		}
	}
}

fs_page_action(gettext('Clear all leases'), 'status_dhcpv6_leases.php?cleardhcpleases=true&all=' . $all_param, 'fa-trash-can', 'danger', [
	'usepost' => true,
	'data-fs-confirm' => gettext('Clear all DHCPv6 leases?'),
	'data-fs-confirm-detail' => gettext('Every address and prefix lease is removed. Clients ask for a new lease when they renew.'),
	'data-fs-confirm-action' => gettext('Clear leases'),
]);

include("head.inc");

if (dhcp_is_backend('isc') && !$leasesfile_found) {
	print_info_box(gettext("No leases file found. Is the DHCPv6 server active?"), 'warning', false);
}

display_isc_warning();

$toggle = $show_all
    ? ['status_dhcpv6_leases.php?view=' . $view . '&all=0', 'fa-eye-slash', gettext('Hide expired')]
    : ['status_dhcpv6_leases.php?view=' . $view . '&all=1', 'fa-eye', gettext('Show expired')];
$toggle_html = '<a class="btn btn-sm btn-outline-secondary fs-dhcp-toggle" href="' . fs_h($toggle[0]) . '">'
    . '<i class="fa-solid ' . $toggle[1] . ' icon-embed-btn" aria-hidden="true"></i>' . fs_h($toggle[2]) . '</a>';
$state_filter = [gettext('All leases'), 'active' => gettext('Active'), 'static' => gettext('Static')] +
    ($show_all ? ['expired' => gettext('Expired'), 'other' => gettext('Other')] : []);
$client_filter = [gettext('Online and offline'), 'online' => gettext('Online'), 'offline' => gettext('Offline')];
$time_cell = function ($data, $start_key, $end_key) use ($static_string) {
	if ($data['type'] == $static_string) {
		return '<td class="fs-muted">' . fs_h(gettext('n/a')) . '</td><td class="fs-muted">' . fs_h(gettext('n/a')) . '</td>';
	}
	return '<td class="fs-mono fs-dhcp-time">' . fs_h(adjust_gmt($data[$start_key] ?? '')) . '</td>'
	    . '<td class="fs-mono fs-dhcp-time">' . fs_h(adjust_gmt($data[$end_key] ?? '')) . '</td>';
};
$start_key = dhcp_is_backend('kea') ? 'starts' : 'start';
$end_key = dhcp_is_backend('kea') ? 'ends' : 'end';
?>

<style>
.fs-dhcp-state { display: inline-flex; flex-wrap: wrap; gap: 4px; }
.fs-dhcp-sub { display: block; color: var(--fs-text-muted); font-size: var(--fs-fs-xs); }
.fs-dhcp-dns { color: var(--fs-info); margin-right: .25rem; }
.fs-dhcp-time { white-space: nowrap; font-size: var(--fs-fs-sm); }
.fs-dhcp-toggle { white-space: nowrap; }
.fs-dhcp-duid { display: inline-block; max-width: 20rem; overflow-wrap: anywhere; font-size: var(--fs-fs-sm); }
</style>

<?php fs_view_switch(['leases' => gettext('Address leases'), 'prefixes' => gettext('Prefix delegation'), 'pools' => gettext('Pools')], $view); ?>

<div class="fs-tiles">
<?php
fs_tile(gettext('Active'), $counts['active'], ($counts['active'] > 0) ? 'active' : null);
fs_tile(gettext('Static'), $counts['static']);
fs_tile(gettext('Expired'), $counts['expired'], null, ($counts['expired'] && !$show_all) ? gettext('Not listed') : null);
fs_tile(gettext('Prefixes'), count($prefix_rows), null, gettext('Delegated'));
?>
</div>

<?php if ($view === 'leases'):
	$if_choices = [];
	foreach ($rows as $data) {
		if (!empty($data['if'])) {
			$if_choices[$data['if']] = $iflist[$data['if']] ?? strtoupper($data['if']);
		}
	}
	$filters = ['state' => $state_filter, 'client' => $client_filter];
	if (count($if_choices) > 1) {
		$filters['if'] = [gettext('All interfaces')] + $if_choices;
	}
?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Address leases'),
	'search' => gettext('Search address, DUID, hostname…'),
	'noun' => gettext('leases'),
	'noun_one' => gettext('lease'),
	'filters' => $filters,
	'actions' => $toggle_html,
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover" data-sortable>
			<thead>
				<tr>
					<th class="fs-col-status" data-fs-search><?=gettext('Status')?></th>
					<th data-fs-search><?=gettext('IPv6 address')?></th>
					<th data-fs-search><?=gettext('Client (DUID)')?></th>
					<th data-fs-search><?=gettext('Hostname')?></th>
					<th data-fs-search><?=gettext('Description')?></th>
					<th data-fs-search><?=gettext('Start')?></th>
					<th data-fs-search><?=gettext('End')?></th>
					<th class="fs-col-actions"><span class="visually-hidden"><?=gettext('Actions')?></span></th>
				</tr>
			</thead>
			<tbody>
<?php foreach ($rows as $data):
	$mac = trim($ndpdata[$data['ip']]['mac'] ?? '');
	$mac_hi = (strlen($mac) >= 8) ? strtoupper($mac[0] . $mac[1] . $mac[3] . $mac[4] . $mac[6] . $mac[7]) : '';
	$online = ($data['online'] === $online_string);
	$name = explode('.', (string)$data['hostname'])[0];
	$label = ($name !== '') ? $name : $data['ip'];
	$if_q = 'if=' . urlencode($data['if'] ?? '');

	$actions = [];
	if ($data['type'] == $static_string) {
		$actions[] = ['edit', "services_dhcpv6_edit.php?{$if_q}&id=" . urlencode($data['staticmap_array_index'] ?? ''), $label,
		    ['attrs' => ['title' => gettext('Edit static mapping'), 'aria-label' => sprintf(gettext('Edit static mapping %s'), $label)]]];
	}
	if ($data['type'] == $dynamic_string) {
		$actions[] = ['custom', "services_dhcpv6_edit.php?{$if_q}&duid=" . urlencode($data['duid']) . '&hostname=' . urlencode($data['hostname']), $label,
		    ['icon' => 'fa-thumbtack', 'label' => sprintf(gettext('Add static mapping for %s'), $label)]];
	}
	if ($mac) { /* we can only add a WOL mapping if MAC address is known */
		$actions[] = ['custom', "services_wol_edit.php?{$if_q}&mac=" . urlencode($mac) . '&descr=' . urlencode($data['hostname']), $label,
		    ['icon' => 'fa-bookmark', 'label' => sprintf(gettext('Add Wake-on-LAN mapping for %s'), $label)]];
	}
	if ($data['type'] == $dynamic_string && !$online) {
		$actions[] = ['delete', 'status_dhcpv6_leases.php?deleteip=' . urlencode($data['ip']) . '&all=' . $all_param, $data['ip'],
		    ['thing' => gettext('lease'), 'detail' => gettext('The client asks for a new lease the next time it connects.')]];
	}
?>
				<tr data-fs-filter-state="<?=$data['state']?>" data-fs-filter-client="<?=$online ? 'online' : 'offline'?>" data-fs-filter-if="<?=htmlspecialchars($data['if'] ?? '')?>">
					<td><span class="fs-dhcp-state"><?=$state_badge($data['state'], $data)?><?=$online ? fs_badge('online') : fs_badge('offline')?></span></td>
					<td>
						<span class="fs-mono"><?=htmlspecialchars($data['ip'])?></span>
<?php if (!empty($data['if'])): ?>
						<span class="fs-dhcp-sub"><?=htmlspecialchars($iflist[$data['if']] ?? strtoupper($data['if']))?></span>
<?php endif; ?>
					</td>
					<td>
						<span class="fs-mono fs-dhcp-duid"><?=htmlspecialchars($data['duid'])?></span>
<?php if (!empty($data['iaid'])): ?>
						<span class="fs-dhcp-sub"><?=gettext('IAID')?>: <span class="fs-mono"><?=htmlspecialchars($data['iaid'])?></span></span>
<?php endif; ?>
<?php if ($mac): ?>
						<span class="fs-dhcp-sub"><span class="fs-mono"><?=htmlspecialchars($mac)?></span><?=isset($mac_man[$mac_hi]) ? ' · ' . htmlspecialchars($mac_man[$mac_hi]) : ''?></span>
<?php endif; ?>
					</td>
					<td>
<?php if ($data['hostname'] && $data['dnsreg']): ?>
						<i class="fa-solid fa-globe fs-dhcp-dns" title="<?=gettext('Registered with the DNS Resolver')?>" aria-hidden="true"></i><span class="visually-hidden"><?=gettext('Registered with the DNS Resolver')?></span>
<?php endif; ?>
						<?=htmlspecialchars($name)?>
					</td>
					<td><?=htmlspecialchars($data['descr'] ?? '')?></td>
					<?=$time_cell($data, $start_key, $end_key)?>
					<td class="fs-col-actions"><?=fs_row_actions($actions)?></td>
				</tr>
<?php endforeach; ?>
<?php if (empty($rows)) {
	fs_empty_row(8, $show_all ? gettext('No address leases to display.') : gettext('No active or static address leases.'));
} ?>
			</tbody>
		</table>
	</div>
	<div class="panel-footer small fs-muted">
		<i class="fa-solid fa-globe" aria-hidden="true"></i> <?=gettext('Hostname registered with the DNS Resolver.')?>
		<?=gettext('Offline clients have no entry in the NDP table.')?>
	</div>
</div>

<?php elseif ($view === 'prefixes'): ?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Prefix delegation leases'),
	'search' => gettext('Search prefix, DUID…'),
	'noun' => gettext('prefixes'),
	'noun_one' => gettext('prefix'),
	'filters' => ['state' => $state_filter],
	'actions' => $toggle_html,
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover" data-sortable>
			<thead>
				<tr>
					<th class="fs-col-status" data-fs-search><?=gettext('Status')?></th>
					<th data-fs-search><?=gettext('IPv6 prefix')?></th>
					<th data-fs-search><?=gettext('Client (DUID)')?></th>
					<th data-fs-search><?=gettext('Routed to')?></th>
<?php if (dhcp_is_backend('kea')): ?>
					<th data-fs-search><?=gettext('Description')?></th>
<?php endif; ?>
					<th data-fs-search><?=gettext('Start')?></th>
					<th data-fs-search><?=gettext('End')?></th>
<?php if (dhcp_is_backend('kea')): ?>
					<th class="fs-col-actions"><span class="visually-hidden"><?=gettext('Actions')?></span></th>
<?php endif; ?>
				</tr>
			</thead>
			<tbody>
<?php foreach ($prefix_rows as $data):
	$online = (($data['online'] ?? '') === $online_string);
?>
				<tr data-fs-filter-state="<?=$data['state']?>">
					<td><span class="fs-dhcp-state"><?=$state_badge($data['state'], $data)?><?=dhcp_is_backend('kea') ? ($online ? fs_badge('online') : fs_badge('offline')) : ''?></span></td>
<?php if (dhcp_is_backend('isc')): ?>
					<td class="fs-mono"><?=htmlspecialchars($data['prefix'])?></td>
					<td><span class="fs-mono fs-dhcp-duid"><?=htmlspecialchars($data['duid'])?></span></td>
					<td>
<?php foreach (($mappings[$data['duid']] ?? []) as $iaid => $iproute): ?>
						<span class="fs-mono"><?=htmlspecialchars($iproute)?></span>
						<span class="fs-dhcp-sub"><?=gettext('IAID')?>: <span class="fs-mono"><?=htmlspecialchars($iaid)?></span></span>
<?php endforeach; ?>
					</td>
					<?=$time_cell($data, 'start', 'end')?>
<?php else:
	$mac = trim($ndpdata[$data['routed-to'] ?? '']['mac'] ?? '');
	$mac_hi = (strlen($mac) >= 8) ? strtoupper($mac[0] . $mac[1] . $mac[3] . $mac[4] . $mac[6] . $mac[7]) : '';
	$label = $data['ip'];
	$if_q = 'if=' . urlencode($data['if'] ?? '');
	$actions = [];
	if ($data['type'] == $static_string) {
		$actions[] = ['edit', "services_dhcpv6_edit.php?{$if_q}&id=" . urlencode($data['staticmap_array_index'] ?? ''), $label,
		    ['attrs' => ['title' => gettext('Edit static mapping'), 'aria-label' => sprintf(gettext('Edit static mapping %s'), $label)]]];
	}
	if ($data['type'] == $dynamic_string) {
		$actions[] = ['custom', "services_dhcpv6_edit.php?{$if_q}&duid=" . urlencode($data['duid']) . '&hostname=' . urlencode($data['hostname'] ?? ''), $label,
		    ['icon' => 'fa-thumbtack', 'label' => sprintf(gettext('Add static mapping for %s'), $label)]];
	}
	if ($data['type'] == $dynamic_string && !$online) {
		$actions[] = ['delete', 'status_dhcpv6_leases.php?deletepd=' . urlencode($data['ip']) . '&all=' . $all_param . '&view=prefixes', $data['ip'],
		    ['thing' => gettext('prefix lease'), 'detail' => gettext('The router asks for a new prefix the next time it connects.')]];
	}
?>
					<td class="fs-mono"><?=htmlspecialchars($data['ip'])?></td>
					<td>
						<span class="fs-mono fs-dhcp-duid"><?=htmlspecialchars($data['duid'])?></span>
<?php if (!empty($data['iaid'])): ?>
						<span class="fs-dhcp-sub"><?=gettext('IAID')?>: <span class="fs-mono"><?=htmlspecialchars($data['iaid'])?></span></span>
<?php endif; ?>
<?php if ($mac): ?>
						<span class="fs-dhcp-sub"><span class="fs-mono"><?=htmlspecialchars($mac)?></span><?=isset($mac_man[$mac_hi]) ? ' · ' . htmlspecialchars($mac_man[$mac_hi]) : ''?></span>
<?php endif; ?>
					</td>
					<td class="fs-mono"><?=htmlspecialchars($data['routed-to'] ?? '')?></td>
					<td><?=htmlspecialchars($data['descr'] ?? '')?></td>
					<?=$time_cell($data, 'starts', 'ends')?>
					<td class="fs-col-actions"><?=fs_row_actions($actions)?></td>
<?php endif; ?>
				</tr>
<?php endforeach; ?>
<?php if (empty($prefix_rows)) {
	fs_empty_row(dhcp_is_backend('kea') ? 8 : 6, gettext('No prefix delegation leases to display.'));
} ?>
			</tbody>
		</table>
	</div>
</div>

<?php else: /* pools */ ?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar(['title' => gettext('Address lease utilization'), 'search' => false, 'noun' => gettext('ranges'), 'noun_one' => gettext('range')]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover" data-sortable>
			<thead>
				<tr>
					<th><?=gettext('Interface')?></th>
					<th><?=gettext('Pool start')?></th>
					<th><?=gettext('Pool end')?></th>
					<th><?=gettext('Used')?></th>
				</tr>
			</thead>
			<tbody>
<?php foreach ($dhcp_leases_subnet_counter as $listcounters): ?>
				<tr>
					<td><?=htmlspecialchars($iflist[$listcounters['dhcpif']] ?? $listcounters['dhcpif'])?></td>
					<td class="fs-mono"><?=htmlspecialchars($listcounters['from'])?></td>
					<td class="fs-mono"><?=htmlspecialchars($listcounters['to'])?></td>
					<td class="fs-mono"><?=(int)$listcounters['count']?></td>
				</tr>
<?php endforeach; ?>
<?php if (empty($dhcp_leases_subnet_counter)) {
	fs_empty_row(4, gettext('No leases are in use.'));
} ?>
			</tbody>
		</table>
	</div>
</div>

<?php if (count($pools) > 0): ?>
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
<?php foreach ($pools as $data): ?>
				<tr>
					<td><?=htmlspecialchars($data['name'])?></td>
					<td><?=fs_badge(($data['mystate'] == 'normal') ? 'up' : 'warn', $data['mystate'])?></td>
					<td class="fs-mono fs-dhcp-time"><?=htmlspecialchars(adjust_gmt($data['mydate']))?></td>
					<td><?=fs_badge(($data['peerstate'] == 'normal') ? 'up' : 'warn', $data['peerstate'])?></td>
					<td class="fs-mono fs-dhcp-time"><?=htmlspecialchars(adjust_gmt($data['peerdate']))?></td>
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
