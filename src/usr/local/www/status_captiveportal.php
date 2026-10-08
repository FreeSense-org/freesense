<?php
/*
 * status_captiveportal.php
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
##|*IDENT=page-status-captiveportal
##|*NAME=Status: Captive Portal
##|*DESCR=Allow access to the 'Status: Captive Portal' page.
##|*MATCH=status_captiveportal.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("functions.inc");
require_once("filter.inc");
require_once("shaper.inc");
require_once("captiveportal.inc");
require_once("voucher.inc");

/*
Return true if multiple servers type are selected in captiveportal config, false otherwise
*/
function multiple_auth_server_type() {
	global $cpzone;

	$auth_types = array();
	$cpzone_config = config_get_path("captiveportal/{$cpzone}", []);
	foreach(explode(",", $cpzone_config['auth_server']) as $authserver) {
		if(strpos($authserver, ' - ') !== false) {
			$authserver = explode(' - ', $authserver);
			$auth_types[array_shift($authserver)] = true;
		}
	}
	foreach(explode(",", $cpzone_config['auth_server2']) as $authserver) {
		if(strpos($authserver, ' - ') !== false) {
			$authserver = explode(' - ', $authserver);
			$auth_types[array_shift($authserver)] = true;
		}
	}
	if(($cpzone_config['auth_method'] === 'authserver') && (count($auth_types) > 1)) {
		return true;
	} else {
		return false;
	}
}

$cpzone = strtolower($_REQUEST['zone']);

if (count(config_get_path('captiveportal', [])) == 1) {
	$cpzone = current(array_keys(config_get_path('captiveportal', [])));
}

/* If the zone does not exist, do not display the invalid zone */
if (!array_key_exists($cpzone, config_get_path('captiveportal', []))) {
	$cpzone = "";
}

if (isset($cpzone) && !empty($cpzone) && config_path_enabled("captiveportal/{$cpzone}", 'zoneid')) {
	$cpzoneid = config_get_path("captiveportal/{$cpzone}/zoneid");
}

if ($_POST['act'] == "del" && !empty($cpzone) && isset($cpzoneid) && isset($_POST['id'])) {
	captiveportal_disconnect_client($_POST['id'], 6, "DISCONNECT - KICKED OUT BY ADMINISTRATOR");
	/* keep displaying last activity times */
	if ($_POST['showact']) {
		header("Location: status_captiveportal.php?zone={$cpzone}&showact=1");
	} else {
		header("Location: status_captiveportal.php?zone={$cpzone}");
	}
	exit;
}

if ($_POST['deleteall'] && !empty($cpzone) && isset($cpzoneid)) {
	captiveportal_disconnect_all();
	header("Location: status_captiveportal.php?zone={$cpzone}");
	exit;
}

$zones = config_get_path('captiveportal', []);
$showact = !empty($_REQUEST['showact']) ? 1 : 0;

$pgtitle = array(gettext("Status"), gettext("Captive Portal"));
$pglinks = array("", "status_captiveportal.php");
$shortcut_section = "captiveportal";

$cpdb = [];
$vouchers_on = false;
$voucher_stats = ['active' => 0, 'used' => 0, 'rolls' => 0, 'tickets' => 0];

if (!empty($cpzone)) {
	$cpdb = captiveportal_read_db();
	$zone_cfg = config_get_path("captiveportal/{$cpzone}", []);
	$vouchers_on = config_path_enabled("voucher/{$cpzone}");

	$pgtitle[] = htmlspecialchars($cpzone);
	$pglinks[] = "status_captiveportal.php?zone=" . $cpzone;
	$pgtitle[] = gettext("Active Users");
	$pglinks[] = "@self";

	if ($vouchers_on) {
		/* same counting as the Voucher Rolls page */
		$voucherlck = lock("voucher{$cpzone}");
		foreach (config_get_path("voucher/{$cpzone}/roll", []) as $rollent) {
			$roll = $rollent['number'];
			$voucher_stats['rolls']++;
			$voucher_stats['tickets'] += intval($rollent['count']);
			$active = count(voucher_read_active_db($roll));
			$voucher_stats['active'] += $active;
			$voucher_stats['used'] += max(0, voucher_used_count($roll) - $active);
		}
		unlock($voucherlck);
	}

	if (count($cpdb) > 0) {
		fs_page_action(gettext('Disconnect all'), 'status_captiveportal.php?zone=' . $cpzone . '&deleteall=1', 'fa-plug-circle-xmark', 'danger', [
			'usepost' => true,
			'data-fs-confirm' => sprintf(gettext('Disconnect all users in zone “%s”?'), $cpzone),
			'data-fs-confirm-detail' => gettext('Every user is logged out and must log in on the portal again.'),
			'data-fs-confirm-action' => gettext('Disconnect all'),
		]);
	}
	if ($showact) {
		fs_page_action(gettext('Hide last activity'), 'status_captiveportal.php?zone=' . $cpzone . '&showact=0', 'fa-eye-slash', 'secondary');
	} else {
		fs_page_action(gettext('Show last activity'), 'status_captiveportal.php?zone=' . $cpzone . '&showact=1', 'fa-clock-rotate-left', 'secondary');
	}
}

include("head.inc");
?>

<style>
.fs-cp-zone { display: flex; flex-wrap: wrap; align-items: center; gap: var(--fs-sp-2); margin-bottom: var(--fs-sp-4); }
.fs-cp-zone label { margin: 0; color: var(--fs-text-muted); font-size: var(--fs-fs-sm); font-weight: 500; }
.fs-cp-zone .form-select { width: auto; min-width: 12rem; max-width: 100%; }
.fs-cp-sub { display: block; color: var(--fs-text-muted); font-size: var(--fs-fs-xs); }
.fs-cp-nowrap { white-space: nowrap; }
.fs-cp-traffic { white-space: nowrap; font-size: var(--fs-fs-sm); font-variant-numeric: tabular-nums; }
.fs-cp-traffic i { width: 1.1em; color: var(--fs-text-muted); font-size: .75em; }
</style>

<?php
if (count($zones) > 1):
?>
<form method="get" action="status_captiveportal.php" class="fs-cp-zone" data-fs-zone-picker>
	<label for="zone"><?=gettext('Zone')?></label>
	<select class="form-select form-select-sm" id="zone" name="zone">
<?php if (empty($cpzone)): ?>
		<option value=""><?=gettext('Select a zone…')?></option>
<?php endif; ?>
<?php foreach ($zones as $cpkey => $cp): ?>
		<option value="<?=htmlspecialchars($cpkey)?>"<?=($cpkey == $cpzone) ? ' selected' : ''?>><?=htmlspecialchars($cpkey . (empty($cp['descr']) ? '' : ' – ' . $cp['descr']))?></option>
<?php endforeach; ?>
	</select>
<?php if ($showact): ?>
	<input type="hidden" name="showact" value="1">
<?php endif; ?>
	<noscript><button type="submit" class="btn btn-sm btn-outline-secondary"><?=gettext('Show')?></button></noscript>
</form>
<?php
endif;

if (!empty($cpzone) && $vouchers_on):
	fs_tabs('status-captiveportal', 'status_captiveportal.php', ['zone' => $cpzone]);
endif;

if (!empty($cpzone)):
	// Load MAC-Manufacturer table
	$mac_man = load_mac_manufacturer_table();
	$show_mac = !config_path_enabled("captiveportal/{$cpzone}", "nomacfilter");
	$multi_auth = multiple_auth_server_type();
	$reverse = config_path_enabled("captiveportal/{$cpzone}", 'reverseacct');
	$auth_labels = [
		'none' => gettext('None (click-through)'),
		'authserver' => gettext('Authentication server'),
		'radmac' => gettext('RADIUS MAC'),
	];
	$minutes_label = function ($min) {
		return empty($min) ? gettext('None') : sprintf(gettext('%d min'), intval($min));
	};
?>
<div class="fs-tiles">
<?php
	fs_tile(gettext('Connected users'), count($cpdb), (count($cpdb) > 0) ? 'online' : null);
	if ($vouchers_on) {
		fs_tile(gettext('Active vouchers'), $voucher_stats['active'], ($voucher_stats['active'] > 0) ? 'active' : null);
		fs_tile(gettext('Used vouchers'), $voucher_stats['used']);
		fs_tile(gettext('Voucher rolls'), $voucher_stats['rolls'], null, sprintf(gettext('%d tickets'), $voucher_stats['tickets']));
	} else {
		fs_tile(gettext('Authentication'), $auth_labels[$zone_cfg['auth_method'] ?? ''] ?? gettext('Unknown'));
		fs_tile(gettext('Idle timeout'), $minutes_label($zone_cfg['idletimeout'] ?? ''));
		fs_tile(gettext('Hard timeout'), $minutes_label($zone_cfg['timeout'] ?? ''));
	}
?>
</div>

<?php
	$cols = 6 + ($show_mac ? 1 : 0) + ($multi_auth ? 1 : 0) + ($showact ? 1 : 0);
	$filters = [];
	if ($multi_auth) {
		$methods = [];
		foreach ($cpdb as $cpent) {
			if (!empty($cpent['authmethod'])) {
				$methods[$cpent['authmethod']] = $cpent['authmethod'];
			}
		}
		if (count($methods) > 1) {
			$filters['auth'] = [gettext('All methods')] + $methods;
		}
	}
?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Users'),
	'search' => gettext('Search users…'),
	'filters' => $filters,
	'noun' => gettext('users'),
	'noun_one' => gettext('user'),
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover" data-sortable>
			<thead>
				<tr>
					<th data-fs-search><?=gettext("Username")?></th>
					<th data-fs-search><?=gettext("IP address")?></th>
<?php if ($show_mac): ?>
					<th data-fs-search><?=gettext("MAC address")?></th>
<?php endif; ?>
<?php if ($multi_auth): ?>
					<th data-fs-search><?=gettext("Authentication method")?></th>
<?php endif; ?>
					<th><?=gettext("Session start")?></th>
					<th><?=gettext("Time left")?></th>
<?php if ($showact): ?>
					<th><?=gettext("Last activity")?></th>
<?php endif; ?>
					<th data-sortable="false"><?=gettext("Traffic")?></th>
					<th class="fs-col-actions" data-sortable="false"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
				</tr>
			</thead>
			<tbody>
<?php
	foreach ($cpdb as $cpent):
		$session_time = time() - $cpent[0];

		/* time left before session timeout or terminate time, or the closer of the two */
		$left = null;
		if (!empty($cpent[7]) && !empty($cpent[9])) {
			$left = min($cpent[0] + $cpent[7] - time(), $cpent[9] - time());
		} elseif (!empty($cpent[7])) {
			$left = $cpent[0] + $cpent[7] - time();
		} elseif (!empty($cpent[9])) {
			$left = $cpent[9] - time();
		}

		if ($showact) {
			$last_act = captiveportal_get_last_activity($cpent[2]);
			/* if the user never sent traffic, set last activity time to the login time */
			$last_act = $last_act ? $last_act : $cpent[0];
			$idle_left = !empty($cpent[8]) ? ($last_act + $cpent[8] - time()) : null;
		}

		/* bytes sent and received, inverted if reverse accounting is enabled */
		$volume = getVolume($cpent[2]);
		$sent = $reverse ? $volume['output_bytes'] : $volume['input_bytes'];
		$received = $reverse ? $volume['input_bytes'] : $volume['output_bytes'];

		$user = (string)$cpent[4];
		$who = ($user !== '') ? $user : (string)$cpent[2];
		$mac = trim((string)$cpent[3]);
		$vendor = '';
		if (strlen($mac) >= 8) {
			$mac_hi = strtoupper($mac[0] . $mac[1] . $mac[3] . $mac[4] . $mac[6] . $mac[7]);
			$vendor = $mac_man[$mac_hi] ?? '';
		}
?>
				<tr data-fs-filter-auth="<?=htmlspecialchars($cpent['authmethod'] ?? '')?>">
					<td><?=($user !== '') ? '<strong>' . htmlspecialchars($user) . '</strong>' : '<span class="fs-muted">' . gettext('No username') . '</span>'?></td>
					<td class="fs-mono"><?=htmlspecialchars($cpent[2])?></td>
<?php if ($show_mac): ?>
					<td>
						<span class="fs-mono"><?=htmlspecialchars($mac)?></span>
<?php if ($vendor !== ''): ?>
						<span class="fs-cp-sub"><?=htmlspecialchars($vendor)?></span>
<?php endif; ?>
					</td>
<?php endif; ?>
<?php if ($multi_auth): ?>
					<td><?=htmlspecialchars($cpent['authmethod'] ?? '')?></td>
<?php endif; ?>
					<td class="fs-cp-nowrap" data-value="<?=intval($cpent[0])?>">
						<?=htmlspecialchars(date("m/d/Y H:i:s", $cpent[0]))?>
						<span class="fs-cp-sub"><?=htmlspecialchars(sprintf(gettext('Connected for %s'), convert_seconds_to_dhms($session_time)))?></span>
					</td>
					<td class="fs-cp-nowrap" data-value="<?=($left === null) ? PHP_INT_MAX : intval($left)?>">
<?php if ($left === null): ?>
						<span class="fs-muted"><?=gettext('No limit')?></span>
<?php elseif ($left < 600): ?>
						<?=fs_badge('warn', convert_seconds_to_dhms(max(0, $left)))?>
<?php else: ?>
						<?=htmlspecialchars(convert_seconds_to_dhms($left))?>
<?php endif; ?>
					</td>
<?php if ($showact): ?>
					<td class="fs-cp-nowrap" data-value="<?=intval($last_act)?>">
						<?=htmlspecialchars(date("m/d/Y H:i:s", $last_act))?>
						<span class="fs-cp-sub"><?=htmlspecialchars(sprintf(gettext('Idle for %s'), convert_seconds_to_dhms((int)(time() - $last_act))))?><?=($idle_left !== null) ? htmlspecialchars(' · ' . sprintf(gettext('%s left'), convert_seconds_to_dhms((int)$idle_left))) : ''?></span>
					</td>
<?php endif; ?>
					<td class="fs-cp-traffic">
						<span title="<?=gettext('Bytes sent')?>"><i class="fa-solid fa-arrow-up" aria-hidden="true"></i><span class="visually-hidden"><?=gettext('Sent')?> </span><?=htmlspecialchars(format_bytes($sent))?></span><br>
						<span title="<?=gettext('Bytes received')?>"><i class="fa-solid fa-arrow-down" aria-hidden="true"></i><span class="visually-hidden"><?=gettext('Received')?> </span><?=htmlspecialchars(format_bytes($received))?></span>
					</td>
					<td><?=fs_row_actions([
						['custom', 'status_captiveportal.php?zone=' . htmlspecialchars($cpzone) . '&showact=' . $showact . '&act=del&id=' . htmlspecialchars($cpent[5]), $who, [
							'icon' => 'fa-plug-circle-xmark',
							'label' => sprintf(gettext('Disconnect %s'), $who),
							'post' => true,
							'confirm' => sprintf(gettext('Disconnect “%s”?'), $who),
							'detail' => gettext('The user is logged out and must log in on the portal again.'),
							'confirm_action' => gettext('Disconnect'),
						]],
					])?></td>
				</tr>
<?php
	endforeach;

	if (empty($cpdb)) {
		fs_empty_row($cols, gettext('No users are logged in to this zone.'));
	}
?>
			</tbody>
		</table>
	</div>
</div>
<?php
elseif (empty($zones)):
?>
<div class="panel panel-default">
	<div class="fs-tool-empty">
		<i class="fa-solid fa-wifi" aria-hidden="true"></i>
		<span><?=gettext('No captive portal zones yet. Add a zone to require users to log in before they get network access.')?></span>
<?php	if (isAllowedPage('services_captiveportal_zones_edit.php')): ?>
		<a class="btn btn-sm btn-primary" href="services_captiveportal_zones_edit.php"><i class="fa-solid fa-plus icon-embed-btn" aria-hidden="true"></i><?=gettext('Add zone')?></a>
<?php	endif; ?>
	</div>
</div>
<?php
else:
?>
<div class="panel panel-default">
	<div class="fs-tool-empty">
		<i class="fa-solid fa-wifi" aria-hidden="true"></i>
		<span><?=gettext('Select a zone to see its logged in users.')?></span>
	</div>
</div>
<?php
endif;
?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	// Show another zone when the picker changes
	$('[data-fs-zone-picker] select').on('change', function() {
		this.form.submit();
	});
});
//]]>
</script>
<?php include("foot.inc");
