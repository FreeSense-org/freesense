<?php
/*
 * status_interfaces.php
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
##|*IDENT=page-status-interfaces
##|*NAME=Status: Interfaces
##|*DESCR=Allow access to the 'Status: Interfaces' page.
##|*MATCH=status_interfaces.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("interfaces.inc");
require_once("freesense-utils.inc");
require_once("util.inc");

if ($_POST['ifdescr'] && $_POST['submit']) {
	$interface = $_POST['ifdescr'];
	if ($_POST['status'] == "up") {
		if ($_POST['relinquish_lease']) {
			dhcp_relinquish_lease($_POST['if'], $_POST['ifdescr'], $_POST['ipv']);
		}
		interface_bring_down($interface);
		restart_interface_services($interface);
		filter_configure();
		system_routing_configure();
		send_event("service reload packages");
	} else {
		interface_configure($interface);
	}
	header("Location: status_interfaces.php");
	exit;
}

// Relinquish the DHCP lease from the server.
function dhcp_relinquish_lease($if, $ifdescr, $ipv) {
	$leases_db = '/var/db/dhclient.leases.' . $if;
	$conf_file = '/var/etc/dhclient_'.$ifdescr.'.conf';
	$script_file = '/usr/local/sbin/FreeSense-dhclient-script';
	$ipv = ((int) $ipv == 6) ? '-6' : '-4';

	if (file_exists($leases_db) && file_exists($script_file)) {
		mwexec("/usr/local/sbin/dhclient {$ipv} -d -r" .
			' -lf ' . escapeshellarg($leases_db) .
			' -cf ' . escapeshellarg($conf_file) .
			' -sf ' . escapeshellarg($script_file));
	}
}

$pgtitle = array(gettext("Status"), gettext("Interfaces"));
$shortcut_section = "interfaces";
include("head.inc");

$ifdescrs = get_configured_interface_with_descr(true);
$ifinterrupts = interfaces_interrupts();
$switch_config = config_get_path('switches/switch/0/vlangroups/vlangroup', []);
$if_config = config_get_path('interfaces', []);
$mac_man = load_mac_manufacturer_table();
$has_dialup = false;
$modals = [];
?>

<style>
.fs-if-grid { display: grid; grid-template-columns: minmax(0, 1fr); gap: var(--fs-sp-4); align-items: start; }
@media (min-width: 1200px) { .fs-if-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
.fs-if-grid > .panel { margin-bottom: 0; }
.fs-if-head { display: flex; flex-wrap: wrap; align-items: center; gap: var(--fs-sp-2) var(--fs-sp-3); }
.fs-if-head > .panel-title { display: flex; flex-wrap: wrap; align-items: baseline; gap: .15rem var(--fs-sp-2); margin: 0; }
.fs-if-name { color: var(--fs-text-strong); font-weight: 600; }
.fs-if-dev { color: var(--fs-text-muted); font-family: var(--fs-font-mono); font-size: var(--fs-fs-xs); font-weight: 400; }
.fs-if-media { color: var(--fs-text-muted); font-size: var(--fs-fs-xs); }
.fs-if-actions { display: flex; flex-wrap: wrap; gap: var(--fs-sp-2); margin-left: auto; }
.fs-if-actions form { margin: 0; }
.fs-if-body { padding: var(--fs-sp-4); }
.fs-if-facts { display: grid; grid-template-columns: 9.5rem minmax(0, 1fr); gap: .35rem var(--fs-sp-4); margin: 0; }
.fs-if-facts dt { color: var(--fs-text-muted); font-weight: 500; }
.fs-if-facts dd { margin: 0; overflow-wrap: anywhere; }
.fs-if-facts .fs-if-sub { display: block; color: var(--fs-text-muted); font-size: var(--fs-fs-xs); }
@media (max-width: 575.98px) { .fs-if-facts { grid-template-columns: minmax(0, 1fr); gap: 0; } .fs-if-facts dd { margin-bottom: .45rem; } }
.fs-if-traffic { display: grid; grid-template-columns: repeat(auto-fill, minmax(8.5rem, 1fr)); gap: var(--fs-sp-2); margin-top: var(--fs-sp-4); }
.fs-if-stat { padding: var(--fs-sp-2) var(--fs-sp-3); border: 1px solid var(--fs-border); border-radius: var(--fs-r-sm); background: var(--fs-surface-raised); }
.fs-if-stat-label { color: var(--fs-text-muted); font-size: var(--fs-fs-xs); }
.fs-if-stat-value { display: flex; flex-wrap: wrap; gap: 0 var(--fs-sp-3); font-family: var(--fs-font-mono); font-size: var(--fs-fs-sm); font-variant-numeric: tabular-nums; color: var(--fs-text-strong); }
.fs-if-stat-value i { color: var(--fs-text-muted); font-size: .75em; margin-right: .2rem; }
.fs-if-stat-sub { color: var(--fs-text-muted); font-family: var(--fs-font-mono); font-size: var(--fs-fs-xs); }
.fs-if-stat.is-bad .fs-if-stat-label { color: var(--fs-block); }
.fs-if-note { display: flex; gap: var(--fs-sp-2); margin-top: var(--fs-sp-4); color: var(--fs-text-muted); font-size: var(--fs-fs-sm); }
.fs-if-note > i { margin-top: .2rem; color: var(--fs-warn); }
</style>

<div class="fs-if-grid">
<?php
foreach ($ifdescrs as $ifdescr => $ifname):
	$ifinfo = get_interface_info($ifdescr);

	$ifhwinfo = $ifinfo['hwif'];
	$vlan = interface_is_vlan($ifinfo['hwif']);
	if ($vlan) {
		foreach ($switch_config as $vlangroup) {
			if ($vlangroup['vlanid'] == $vlan['tag']) {
				$ifhwinfo .= ', switchports: ' . $vlangroup['members'];
				break;
			}
		}
	}

	/* status badge */
	if (!$ifinfo['enable']) {
		$badge = fs_badge('disabled');
	} elseif ($ifinfo['status'] == 'up' || $ifinfo['status'] == 'associated') {
		$badge = fs_badge('up', ($ifinfo['status'] == 'up') ? gettext('Up') : gettext('Associated'));
	} elseif ($ifinfo['status'] == 'no carrier') {
		$badge = fs_badge('down', gettext('No carrier'));
	} elseif ($ifinfo['status'] == 'down') {
		$badge = fs_badge('down');
	} else {
		$badge = fs_badge('neutral', $ifinfo['status']);
	}

	/*
	 * Link actions: the same form fields as before (ifdescr, status = link
	 * state, submit, and relinquish_lease / if / ipv for a DHCP release).
	 */
	$actions = [];
	foreach ([
	    'dhcplink' => ['DHCP', 4],
	    'dhcp6link' => ['DHCP6', 6],
	    'pppoelink' => ['PPPoE', null],
	    'pptplink' => ['PPTP', null],
	    'l2tplink' => ['L2TP', null],
	    'ppplink' => ['PPP', null],
	] as $key => list($type, $ipv)) {
		if (empty($ifinfo[$key])) {
			continue;
		}
		$up = ($ifinfo[$key] == 'up') && !(($key == 'ppplink') && $ifinfo['nodevice']);
		if ($ipv === null) {
			$has_dialup = true;
			$btnlbl = ($up ? gettext("Disconnect") : gettext("Connect")) . " {$ifname}";
			$text = sprintf($up ? gettext('Disconnect %s') : gettext('Connect %s'), $type);
		} else {
			$btnlbl = (($ifinfo[$key] == "up") ? gettext("Release") : gettext("Renew")) . " {$ifname}";
			$text = sprintf(($ifinfo[$key] == "up") ? gettext('Release %s') : gettext('Renew %s'), $type);
		}
		$actions[] = ['key' => $key, 'type' => $type, 'ipv' => $ipv, 'up' => $up, 'state' => $ifinfo[$key], 'value' => $btnlbl, 'text' => $text];
	}

	/* facts: [label, value, mono, sub] */
	$facts = [];
	$facts[] = [gettext('Device'), $ifhwinfo, true, null];
	if ($ifinfo['macaddr']) {
		$mac = $ifinfo['macaddr'];
		$mac_hi = strtoupper($mac[0] . $mac[1] . $mac[3] . $mac[4] . $mac[6] . $mac[7]);
		$facts[] = [gettext('MAC address'), $mac, true, $mac_man[$mac_hi] ?? null];
	}
	foreach ($actions as $a) {
		$facts[] = [$a['type'], $a['state'], false, null];
	}
	if ($ifinfo['ppp_uptime'] || $ifinfo['ppp_uptime_accumulated']) {
		$facts[] = [$ifinfo['ppp_uptime_accumulated'] ? gettext('Uptime (historical)') : gettext('Uptime'),
		    $ifinfo['ppp_uptime'] . $ifinfo['ppp_uptime_accumulated'], false, null];
	}
	foreach ([
	    'cell_rssi' => gettext("Cell signal (RSSI)"), 'cell_mode' => gettext("Cell mode"), 'cell_simstate' => gettext("Cell SIM state"),
	    'cell_service' => gettext("Cell service"), 'cell_bwupstream' => gettext("Cell upstream"), 'cell_bwdownstream' => gettext("Cell downstream"),
	    'cell_upstream' => gettext("Cell current up"), 'cell_downstream' => gettext("Cell current down"),
	] as $key => $label) {
		if ($ifinfo[$key]) {
			$facts[] = [$label, $ifinfo[$key], false, null];
		}
	}

	$traffic = [];
	if ($ifinfo['status'] != "down") {
		if ($ifinfo['dhcplink'] != "down" && $ifinfo['pppoelink'] != "down" && $ifinfo['pptplink'] != "down") {
			if ($ifinfo['ipaddr']) {
				$prefix = $ifinfo['subnet'];
				if (is_ipaddrv4($prefix)) {
					$prefix = substr_count(decbin(ip2long($prefix)), '1');
				}
				$facts[] = [gettext('IPv4 address'), $ifinfo['ipaddr'] . ($prefix ? '/' . $prefix : ''), true, null];
			}
			if ($ifinfo['gateway']) {
				$facts[] = [gettext('IPv4 gateway'), $ifinfo['gateway'], true, null];
			}
			if ($ifinfo['ipaddrv6']) {
				$facts[] = [gettext('IPv6 address'), $ifinfo['ipaddrv6'] . ($ifinfo['subnetv6'] ? '/' . $ifinfo['subnetv6'] : ''), true, null];
			}
			if ($ifinfo['linklocal']) {
				$facts[] = [gettext('IPv6 link-local'), $ifinfo['linklocal'], true, null];
			}
			if ($ifinfo['gatewayv6']) {
				$facts[] = [gettext("IPv6 gateway"), trim(($if_config[$ifdescr]['gatewayv6'] ?? '') . " " . $ifinfo['gatewayv6']), true, null];
			}
			$dns_servers = get_dynamic_nameservers($ifdescr);
			if (!empty($dns_servers)) {
				$facts[] = [gettext('DNS servers'), implode(', ', $dns_servers), true, null];
			}
		}

		foreach ([
		    'mtu' => [gettext("MTU"), true], 'media' => [gettext("Media"), false], 'plugged' => [gettext("Plugged"), false],
		    'vendor' => [gettext("Vendor"), false], 'temperature' => [gettext("Temperature"), false], 'voltage' => [gettext("Voltage"), false],
		    'rx' => [gettext("RX"), false], 'tx' => [gettext("TX"), false], 'laggproto' => [gettext("LAGG protocol"), false],
		] as $key => list($label, $mono)) {
			if ($ifinfo[$key]) {
				$facts[] = [$label, $ifinfo[$key], $mono, null];
			}
		}
		if ($ifinfo['laggport']) {
			$facts[] = [gettext("LAGG ports"), implode(', ', (array)get_lagg_ports($ifinfo['laggport'])), true, null];
		}
		foreach (['channel' => gettext("Channel"), 'ssid' => gettext("SSID"), 'bssid' => gettext("BSSID"),
		    'rate' => gettext("Rate"), 'rssi' => gettext("RSSI")] as $key => $label) {
			if ($ifinfo[$key]) {
				$facts[] = [$label, $ifinfo[$key], ($key == 'bssid'), null];
			}
		}

		$traffic[] = [gettext('Packets'), $ifinfo['inpkts'], $ifinfo['outpkts'], false];
		$traffic[] = [gettext('Bytes'), format_bytes($ifinfo['inbytes']), format_bytes($ifinfo['outbytes']), false];
		$traffic[] = [gettext('Passed'), $ifinfo['inpktspass'], $ifinfo['outpktspass'], false,
		    format_bytes($ifinfo['inbytespass']) . ' / ' . format_bytes($ifinfo['outbytespass'])];
		$traffic[] = [gettext('Blocked'), $ifinfo['inpktsblock'], $ifinfo['outpktsblock'], false,
		    format_bytes($ifinfo['inbytesblock']) . ' / ' . format_bytes($ifinfo['outbytesblock'])];
		if (isset($ifinfo['inerrs'])) {
			$traffic[] = [gettext('Errors'), $ifinfo['inerrs'], $ifinfo['outerrs'], ($ifinfo['inerrs'] > 0 || $ifinfo['outerrs'] > 0)];
		}
		if (isset($ifinfo['collisions'])) {
			$traffic[] = [gettext('Collisions'), $ifinfo['collisions'], null, ($ifinfo['collisions'] > 0)];
		}
	}

	if ($ifinfo['bridge']) {
		$facts[] = [sprintf(gettext('Bridge (%1$s)'), $ifinfo['bridgeint']), $ifinfo['bridge'], true, null];
	}
	if (is_array($ifinterrupts[$ifinfo['hwif']] ?? null) && $ifinterrupts[$ifinfo['hwif']]['total']) {
		$facts[] = [gettext('Interrupts'), $ifinterrupts[$ifinfo['hwif']]['total'] . " (" . $ifinterrupts[$ifinfo['hwif']]['rate'] . "/s)", true, null];
	}
?>
	<section class="panel panel-default" aria-labelledby="if-<?=htmlspecialchars($ifdescr)?>-title">
		<div class="panel-heading fs-if-head">
			<h2 class="panel-title" id="if-<?=htmlspecialchars($ifdescr)?>-title">
				<span class="fs-if-name"><?=htmlspecialchars($ifname)?></span>
				<span class="fs-if-dev"><?=htmlspecialchars($ifdescr)?> · <?=htmlspecialchars($ifinfo['if'] ?: $ifinfo['hwif'])?></span>
			</h2>
			<?=$badge?>
<?php	if ($ifinfo['media'] && $ifinfo['status'] != "down"): ?>
			<span class="fs-if-media"><?=htmlspecialchars($ifinfo['media'])?></span>
<?php	endif; ?>
<?php	if (!empty($actions)): ?>
			<div class="fs-if-actions">
<?php		foreach ($actions as $a):
			$icon = ($a['ipv'] === null) ? ($a['up'] ? 'fa-plug-circle-xmark' : 'fa-plug') : ($a['up'] ? 'fa-arrow-right-from-bracket' : 'fa-arrows-rotate');
			if ($a['ipv'] !== null && $a['state'] == 'up'):
				/* DHCP release: a modal asks first and offers to relinquish the lease */
				$modal_id = 'if-release-' . $ifdescr . '-' . $a['ipv'];
				$modals[] = [$modal_id, $ifdescr, $ifname, $a, $ifinfo['if']];
?>
				<button type="button" class="btn btn-sm btn-outline-secondary" data-fs-modal="#<?=htmlspecialchars($modal_id)?>"><i class="fa-solid <?=$icon?> icon-embed-btn" aria-hidden="true"></i><?=htmlspecialchars($a['text'])?></button>
<?php		else: ?>
				<form action="status_interfaces.php" method="post">
					<input type="hidden" name="ifdescr" value="<?=htmlspecialchars($ifdescr)?>">
					<input type="hidden" name="status" value="<?=htmlspecialchars($a['state'])?>">
					<button<?=fs_attrs([
					    'type' => 'submit', 'name' => 'submit', 'value' => $a['value'], 'class' => 'btn btn-sm btn-outline-secondary',
					    'data-fs-confirm' => $a['up'] ? sprintf(gettext('Disconnect %1$s on %2$s?'), $a['type'], $ifname) : null,
					    'data-fs-confirm-detail' => $a['up'] ? gettext('Traffic through this interface stops until it is connected again.') : null,
					    'data-fs-confirm-action' => $a['up'] ? gettext('Disconnect') : null,
					])?>><i class="fa-solid <?=$icon?> icon-embed-btn" aria-hidden="true"></i><?=htmlspecialchars($a['text'])?></button>
				</form>
<?php		endif;
		endforeach; ?>
			</div>
<?php	endif; ?>
		</div>
		<div class="panel-body fs-if-body">
			<dl class="fs-if-facts">
<?php	foreach ($facts as list($label, $value, $mono, $sub)): ?>
				<dt><?=htmlspecialchars($label)?></dt>
				<dd<?=$mono ? ' class="fs-mono"' : ''?>><?=htmlspecialchars((string)$value)?><?php if ($sub): ?><span class="fs-if-sub"><?=htmlspecialchars($sub)?></span><?php endif; ?></dd>
<?php	endforeach; ?>
			</dl>
<?php	if (!empty($traffic)): ?>
			<div class="fs-if-traffic">
<?php		foreach ($traffic as $t): list($label, $in, $out, $bad) = $t; ?>
				<div class="fs-if-stat<?=$bad ? ' is-bad' : ''?>">
					<div class="fs-if-stat-label"><?=htmlspecialchars($label)?></div>
					<div class="fs-if-stat-value">
<?php			if ($out === null): ?>
						<span><?=htmlspecialchars((string)$in)?></span>
<?php			else: ?>
						<span title="<?=gettext('In')?>"><i class="fa-solid fa-arrow-down" aria-hidden="true"></i><span class="visually-hidden"><?=gettext('In')?> </span><?=htmlspecialchars((string)$in)?></span>
						<span title="<?=gettext('Out')?>"><i class="fa-solid fa-arrow-up" aria-hidden="true"></i><span class="visually-hidden"><?=gettext('Out')?> </span><?=htmlspecialchars((string)$out)?></span>
<?php			endif; ?>
					</div>
<?php			if (!empty($t[4])): ?>
					<div class="fs-if-stat-sub" title="<?=gettext('Bytes in / out')?>"><?=htmlspecialchars($t[4])?></div>
<?php			endif; ?>
				</div>
<?php		endforeach; ?>
			</div>
<?php	endif; ?>
		</div>
	</section>
<?php endforeach; ?>
</div>

<?php if (empty($ifdescrs)): ?>
<div class="panel panel-default"><div class="panel-body fs-if-body fs-muted"><?=gettext('No interfaces are assigned.')?></div></div>
<?php endif; ?>

<?php if ($has_dialup): ?>
<p class="fs-if-note"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><span><?=gettext('With dial-on-demand, any packet that triggers it brings the connection up again: disconnecting manually does not prevent it. Do not use dial-on-demand if the line must stay disconnected.')?></span></p>
<?php endif; ?>

<?php
foreach ($modals as list($modal_id, $ifdescr, $ifname, $a, $realif)):
	fs_modal_form_begin($modal_id, sprintf(gettext('Release the %1$s lease on %2$s'), $a['type'], $ifname), 'status_interfaces.php',
	    ['ifdescr' => $ifdescr, 'status' => $a['state'], 'if' => $realif, 'ipv' => $a['ipv']]);
?>
	<p><?=gettext('The interface loses its address until the lease is renewed.')?></p>
	<div class="form-check">
		<input class="form-check-input" type="checkbox" name="relinquish_lease" value="true" id="<?=htmlspecialchars($modal_id)?>-relinquish">
		<label class="form-check-label" for="<?=htmlspecialchars($modal_id)?>-relinquish"><?=gettext('Relinquish lease')?></label>
		<div class="form-text"><?=gettext('Send a gratuitous DHCP release packet to the server.')?></div>
	</div>
<?php
	fs_modal_form_end(gettext('Release'), 'submit', $a['value'], 'fa-arrow-right-from-bracket');
endforeach;

include("foot.inc");
?>
