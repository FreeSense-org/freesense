<?php
/*
 * status_carp.php
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
##|*IDENT=page-status-carp
##|*NAME=Status: CARP
##|*DESCR=Allow access to the 'Status: CARP' page.
##|*MATCH=status_carp.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("globals.inc");

unset($interface_arr_cache);
unset($interface_ip_arr_cache);


function find_ipalias($carpif) {
	$ips = array();
	foreach (config_get_path('virtualip/vip', []) as $vip) {
		if ($vip['mode'] != "ipalias") {
			continue;
		}
		if ($vip['interface'] != $carpif) {
			continue;
		}
		$ips[] = "{$vip['subnet']}/{$vip['subnet_bits']}";
	}

	return ($ips);
}

$status = get_carp_status();

if ($_POST['carp_maintenancemode'] != "") {
	if (!config_path_enabled('', 'virtualip_carp_maintenancemode')) {
		if ($_POST['carp_maintenancemode'] == "disable"){
			$errmsg = gettext("Persistent CARP Maintenance Mode is already disabled.");
		} else {
			$maintenancemode = true;
			$savemsg = gettext("Entering Persistent CARP Maintenance Mode.");
		}
	} else {
		if ($_POST['carp_maintenancemode'] == "enable"){
			$errmsg = gettext("Persistent CARP Maintenance Mode is already enabled.");
		} else {
			$maintenancemode = false;
			$savemsg = gettext("Leaving Persistent CARP Maintenance Mode.");
		}
	}

	/* allow to switch to Persistent Maintenance Mode if CARP is disabled
	 * see upstream issue 11727 */
	if (!$errmsg) {
		interfaces_carp_set_maintenancemode($maintenancemode);
	}
	if ($status == 0) {
		$_POST['disablecarp'] = "disable";
	}
}

if ($_POST['disablecarp'] != "") {
	$viparr = config_get_path('virtualip/vip', []);
	if ($status != 0) {
		if ($_POST['disablecarp'] == "enable"){
			$errmsg = gettext("CARP is already enabled.");
		} else {
			enable_carp(false);
			$carp_counter = 0;
			foreach ($viparr as $vip) {
				if ($vip['mode'] != "carp" && $vip['mode'] != "ipalias")
					continue;
				if ($vip['mode'] == "ipalias" && substr($vip['interface'], 0, 4) != "_vip")
					continue;
				interface_vip_bring_down($vip);
				$carp_counter++;
			}
			$savemsg = sprintf(gettext("%s IPs have been disabled. Please note that disabling does not survive a reboot and some configuration changes will re-enable."), $carp_counter);
			$status = 0;
		}
	} else {
		if ($_POST['disablecarp'] == "disable"){
			$errmsg = gettext("CARP is already disabled.");
		} else {
			$savemsg .= gettext("CARP has been enabled.");
			foreach ($viparr as $vip) {
				switch ($vip['mode']) {
					case "carp":
						interface_carp_configure($vip);
						break;
					case 'ipalias':
						if (substr($vip['interface'], 0, 4) == "_vip") {
							interface_ipalias_configure($vip);
						}
						break;
				}
			}
			interfaces_sync_setup();
			enable_carp();
			$status = 1;
		}
	}
}

$carp_detected_problems = get_single_sysctl("net.inet.carp.demotion");

if (!empty($_POST['resetdemotion'])) {
	set_single_sysctl("net.inet.carp.demotion", 0 - $carp_detected_problems);
	sleep(1);
	$carp_detected_problems = get_single_sysctl("net.inet.carp.demotion");
}

$pgtitle = array(gettext("Status"), gettext("CARP"));
$shortcut_section = "carp";

$carpcount = 0;
foreach(config_get_path('virtualip/vip', []) as $carp) {
	if ($carp['mode'] == "carp") {
		$carpcount++;
		break;
	}
}

$carp_enabled = ($status != 0);
$maintenance = config_path_enabled('', 'virtualip_carp_maintenancemode');

/* the buttons post the same fields as before (disablecarp, carp_maintenancemode) */
if ($carpcount > 0) {
	if ($carp_enabled) {
		fs_page_action(gettext('Temporarily disable CARP'), 'status_carp.php?disablecarp=disable', 'fa-ban', 'danger', [
			'usepost' => true,
			'data-fs-confirm' => gettext('Temporarily disable CARP on this node?'),
			'data-fs-confirm-detail' => gettext('Its virtual IPs go down and a peer takes over as master. The change does not survive a reboot, and some configuration changes enable CARP again.'),
			'data-fs-confirm-action' => gettext('Disable CARP'),
		]);
	} else {
		fs_page_action(gettext('Enable CARP'), 'status_carp.php?disablecarp=enable', 'fa-check', 'secondary', ['usepost' => true]);
	}
	if ($maintenance) {
		fs_page_action(gettext('Leave maintenance mode'), 'status_carp.php?carp_maintenancemode=disable', 'fa-wrench', 'secondary', [
			'usepost' => true,
			'data-fs-confirm' => gettext('Leave persistent CARP maintenance mode?'),
			'data-fs-confirm-detail' => gettext('This node advertises normally again and can take over as master.'),
			'data-fs-confirm-action' => gettext('Leave maintenance mode'),
		]);
	} else {
		fs_page_action(gettext('Enter maintenance mode'), 'status_carp.php?carp_maintenancemode=enable', 'fa-wrench', 'secondary', [
			'usepost' => true,
			'data-fs-confirm' => gettext('Enter persistent CARP maintenance mode?'),
			'data-fs-confirm-detail' => gettext('This node demotes itself so a peer becomes master, and stays demoted after a reboot until you leave maintenance mode.'),
			'data-fs-confirm-action' => gettext('Enter maintenance mode'),
		]);
	}
}

include("head.inc");
if ($savemsg) {
	print_info_box($savemsg, 'success');
} else if ($errmsg) {
	print_info_box($errmsg);
}

// If $carpcount > 0 display buttons then display table
// otherwise display error box and quit

if ($carpcount == 0) {
	print_info_box(gettext('No CARP interfaces have been defined.') . '<br />' .
				   '<a href="system_hasync.php" class="alert-link">' .
				   gettext("High availability sync settings can be configured here.") .
				   '</a>');
} else {
	$vips = [];
	$counts = ['master' => 0, 'backup' => 0, 'init' => 0];
	foreach (config_get_path('virtualip/vip', []) as $carp) {
		if ($carp['mode'] != "carp") {
			continue;
		}
		$vip_status = $carp_enabled ? get_carp_interface_status("_vip{$carp['uniqid']}") : 'DISABLED';
		$key = strtolower($vip_status);
		if (isset($counts[$key])) {
			$counts[$key]++;
		}
		$vips[] = [$carp, $vip_status, find_ipalias("_vip{$carp['uniqid']}")];
	}
?>

<style>
.fs-carp-ids { margin: 0; padding: 0; list-style: none; }
.fs-carp-ids > li { display: flex; align-items: center; gap: var(--fs-sp-2); padding: var(--fs-sp-2) var(--fs-sp-4); border-bottom: 1px solid var(--fs-border); }
.fs-carp-ids > li:last-child { border-bottom: 0; }
.fs-carp-empty { padding: var(--fs-sp-3) var(--fs-sp-4); color: var(--fs-text-muted); }
</style>

<?php
	if ($carp_detected_problems != 0) {
		print_info_box(
			gettext("CARP has detected a problem and this unit has a non-zero demotion status.") .
			"<br/>" .
			gettext("Check the link status on all interfaces configured with CARP VIPs and ") .
			sprintf(gettext('search the %1$sSystem Log%2$s for CARP demotion-related events.'), "<a href=\"/status_logs.php?filtertext=carp%3A+demoted+by\">", "</a>") .
			'<form action="status_carp.php" method="post" class="mt-2">' .
			'<button type="submit" class="btn btn-sm btn-warning" name="resetdemotion" id="resetdemotion" value="' .
			gettext("Reset CARP Demotion Status") .
			'"><i class="fa-solid fa-arrow-rotate-left icon-embed-btn" aria-hidden="true"></i>' .
			gettext("Reset CARP Demotion Status") .
			'</button></form>',
			'danger'
		);
	}
?>

<div class="fs-tiles">
<?php
	fs_tile(gettext('CARP'), $carp_enabled ? gettext('Enabled') : gettext('Disabled'), null,
	    $maintenance ? gettext('Persistent maintenance mode is on') : gettext('Maintenance mode is off'));
	fs_tile(gettext('Master'), $counts['master']);
	fs_tile(gettext('Backup'), $counts['backup']);
	if ($counts['init'] > 0) {
		fs_tile(gettext('Init'), $counts['init'], 'warn', gettext('Check the link of these interfaces'));
	} else {
		fs_tile(gettext('Demotion'), (int)$carp_detected_problems, ($carp_detected_problems != 0) ? 'warn' : null);
	}
?>
</div>

<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('CARP virtual IPs'),
	'search' => gettext('Search virtual IPs…'),
	'noun' => gettext('virtual IPs'),
	'noun_one' => gettext('virtual IP'),
	'filters' => ['state' => [gettext('All states'), 'master' => gettext('Master'), 'backup' => gettext('Backup'),
	    'init' => gettext('Init'), 'disabled' => gettext('Disabled')]],
]); ?>
	<div class="panel-body table-responsive">
	<table class="table table-hover" data-sortable>
		<thead>
			<tr>
				<th class="fs-col-status"><?=gettext("Status")?></th>
				<th data-fs-search><?=gettext("Interface and VHID")?></th>
				<th data-fs-search><?=gettext("Virtual IP address")?></th>
				<th data-fs-search class="d-none d-md-table-cell"><?=gettext("Description")?></th>
			</tr>
		</thead>
		<tbody>
<?php
	foreach ($vips as list($carp, $vip_status, $aliases)) {
		$state = strtolower($vip_status);
		$badge = match ($vip_status) {
			'MASTER' => fs_badge('online', gettext('Master')),
			'BACKUP' => fs_badge('info', gettext('Backup')),
			'INIT' => fs_badge('warn', gettext('Init')),
			'DISABLED' => fs_badge('disabled'),
			default => fs_badge('unknown', $vip_status ?: null),
		};
?>
			<tr data-fs-filter-state="<?=htmlspecialchars($state)?>">
				<td><?=$badge?></td>
				<td><?=htmlspecialchars(convert_friendly_interface_to_friendly_descr($carp['interface']))?><span class="fs-muted">@<?=htmlspecialchars($carp['vhid'])?></span>
<?php		if (!empty($carp['descr'])): ?>
					<div class="fs-muted small d-md-none"><?=htmlspecialchars($carp['descr'])?></div>
<?php		endif; ?>
				</td>
				<td class="fs-mono">
					<?=htmlspecialchars("{$carp['subnet']}/{$carp['subnet_bits']}")?>
<?php		foreach ($aliases as $alias): ?>
					<div class="fs-muted small"><?=htmlspecialchars($alias)?></div>
<?php		endforeach; ?>
				</td>
				<td class="d-none d-md-table-cell"><?=htmlspecialchars($carp['descr'])?></td>
			</tr>
<?php
	}
?>
		</tbody>
	</table>
	</div>
</div>

<?php
	$my_id = strtolower(ltrim(filter_get_host_id(), '0'));
	exec("/sbin/pfctl -sc | /usr/bin/tail -n +2 | /usr/bin/sort", $hostids);
	if (!is_array($hostids)) {
		$hostids = array();
	}
?>
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('State synchronization')?> <span class="fs-count"><?=count($hostids)?></span></h2></div>
<?php	if (empty($hostids)): ?>
	<div class="fs-carp-empty"><?=gettext('No state creator host IDs found.')?></div>
<?php	else: ?>
	<ul class="fs-carp-ids" aria-label="<?=gettext('State creator host IDs')?>">
<?php		foreach ($hostids as $hid):
			$hid = strtolower(ltrim($hid, '0')); ?>
		<li><span class="fs-mono"><?=htmlspecialchars($hid)?></span><?php if ($hid == $my_id): ?> <?=fs_badge('info', gettext('This node'))?><?php endif; ?></li>
<?php		endforeach; ?>
	</ul>
<?php	endif; ?>
	<div class="panel-footer small fs-muted">
		<i class="fa-solid fa-circle-info" aria-hidden="true"></i>
		<?=gettext('State creator host IDs. When state synchronization works, every node in the cluster lists the same IDs. Set this node\'s ID under System > High Avail Sync; after a change the old ID stays until all states using it expire or are removed.')?>
	</div>
</div>

<?php
}

include("foot.inc");
