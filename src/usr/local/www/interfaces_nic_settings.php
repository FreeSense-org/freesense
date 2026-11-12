<?php
/*
 * FreeSense NIC Settings
 * Copyright (c) 2026 The FreeSense Project
 * Licensed under the Apache License, Version 2.0.
 */

##|+PRIV
##|*IDENT=page-interfaces-nicsettings
##|*NAME=Interfaces: NIC Settings
##|*DESCR=Allow access to the Interfaces NIC Settings page.
##|*MATCH=interfaces_nic_settings.php*
##|-PRIV

require_once('guiconfig.inc');
require_once('interfaces.inc');
require_once('util.inc');

$pgtitle = [gettext('Interfaces'), gettext('NIC Settings')];

/* Adapters (list + per-adapter modal) and Profile; the 1.0 views overview / configure /
 * diagnostics land on Adapters, recommendations on Profile. */
$legacy_views = ['overview' => 'adapters', 'configure' => 'adapters', 'diagnostics' => 'adapters', 'recommendations' => 'profile'];
if (isset($_REQUEST['view'], $legacy_views[$_REQUEST['view']])) {
	$_REQUEST['view'] = $legacy_views[$_REQUEST['view']];
}
$view = fs_view_param(['adapters', 'profile'], 'adapters');

function nic_ui_driver($device) {
	return preg_replace('/[0-9]+$/', '', $device);
}

function nic_ui_assignments() {
	$result = [];
	foreach (config_get_path('interfaces', []) as $logical => $config) {
		if (!empty($config['if'])) {
			$result[$config['if']] = ($config['descr'] ?? null) ?: strtoupper($logical);
		}
	}
	return $result;
}

function nic_ui_inventory(bool $refresh = false) {
	$devices = get_interface_list('all', 'physical') ?: [];
	$assignments = nic_ui_assignments();
	$result = [];
	foreach ($devices as $device => $basic) {
		$tuning = nic_tuning_adapter((string)$device, $refresh);
		$info = get_interface_addresses($device) ?: [];
		/* inline IPsec offload is a second-word capability the extension does not report */
		$ipsec = interface_ipsec_offload_state((string)$device);
		if ($ipsec !== null) {
			$info['caps']['ipsec'] = true;
			if ($ipsec) {
				$info['encaps']['ipsec'] = true;
			}
		}
		$effective = nic_settings_effective($device, $info);
		$result[$device] = array_merge($basic, $info, [
			'device' => $device,
			'driver' => $tuning['driver'] ?: nic_ui_driver($device),
			'tuning' => $tuning,
			'assigned' => isset($assignments[$device]),
			'assignment' => $assignments[$device] ?? gettext('Unassigned'),
			'effective' => $effective,
			'id' => $effective['id'],
			'media' => $info['media'] ?? nic_ui_media($device),
		]);
	}
	ksort($result, SORT_NATURAL);
	return $result;
}

/* link speed and duplex from ifconfig, parsed the way get_interface_info() does for the dashboard widget */
function nic_ui_media($device) {
	$lines = [];
	exec('/sbin/ifconfig ' . escapeshellarg($device) . ' 2>/dev/null', $lines);
	foreach ($lines as $line) {
		$line = ltrim($line);
		if (preg_match('/^media: .*? \((.*?)\)/', $line, $m) ||
		    preg_match('/^media: Ethernet (.*)/', $line, $m) ||
		    preg_match('/^media: IEEE 802.11 Wireless Ethernet (.*)/', $line, $m)) {
			return trim($m[1]);
		}
	}
	return null;
}

/* hardware capability behind each setting; a setting the adapter lacks only offers "inherit" */
function nic_ui_supported($nic, $setting) {
	$caps = $nic['caps'] ?? [];
	$need = [
		'checksum' => ['txcsum', 'rxcsum', 'txcsum6', 'rxcsum6'],
		'tso' => ['tso4', 'tso6'],
		'lro' => ['lro'],
		'vlan' => ['vlanhwtag'],
		'wol' => ['wolmagic'],
		'ipsec' => ['ipsec'],
	];
	foreach ($need[$setting] ?? [] as $cap) {
		if (isset($caps[$cap])) {
			return true;
		}
	}
	return false;
}

/* stores the adapter identity next to its settings, so loader.conf and boot know which device it was */
function nic_ui_store_identity(array $nic): string {
	$path = "system/nicsettings/adapters/{$nic['id']}";
	/* drop a pre-prefix entry so only one copy remains */
	config_del_path('system/nicsettings/adapters/' . substr($nic['id'], 4));
	config_set_path("{$path}/mac", $nic['hwaddr'] ?? $nic['macaddr'] ?? $nic['mac'] ?? '');
	config_set_path("{$path}/device", $nic['device']);
	config_set_path("{$path}/driver", $nic['driver']);
	return $path;
}

/* the display text of a tuning value: the choice label, or the number ("0" or empty = driver default) */
function nic_ui_tuning_value(string $setting, string $value, string $driver): string {
	$choices = nic_tuning_choices($setting, $driver);
	if (isset($choices[$value])) {
		return $choices[$value];
	}
	return ($value === '' || $value === '0') ? gettext('driver default') : $value;
}

$ncpu = max(1, (int)get_single_sysctl('hw.ncpu'));
$vm_guest = (string)get_single_sysctl('kern.vm_guest');
$tuning_labels = [
	'fc' => gettext('Flow control'),
	'aim' => gettext('Interrupt moderation'),
	'tx_abdicate' => gettext('TX abdicate'),
	'queues' => gettext('Queues'),
	'rxds' => gettext('Receive descriptors'),
	'txds' => gettext('Transmit descriptors'),
];
$tuning_help = [
	'fc' => gettext('Pause frames let a busy link stop its sender. On a router they stall every flow behind one slow port, so off is recommended.'),
	'aim' => gettext('Adapts the interrupt rate to the load. Low latency spends more CPU time for shorter delays.'),
	'tx_abdicate' => gettext('Hands transmit work to the queue thread instead of finishing it on the sending CPU. Measured at about 20% more forwarded packets per second on FreeBSD 16.'),
	'queues' => gettext('Receive and transmit queue pairs. Empty lets the driver choose, usually one per CPU up to the hardware limit.'),
	'rxds' => gettext('Receive ring size per queue. Larger rings absorb bursts but use more memory.'),
	'txds' => gettext('Transmit ring size per queue.'),
];
$counter_labels = [
	'rx_missed' => gettext('Missed (receive FIFO full)'),
	'rx_no_buffers' => gettext('No receive buffers'),
	'rx_overruns' => gettext('Receive overruns'),
	'dropped' => gettext('Dropped by the driver'),
	'tx_ring_drops' => gettext('Transmit ring drops'),
	'tx_no_descriptors' => gettext('Transmit ring full'),
	'tx_watchdog' => gettext('Transmit watchdog resets'),
];

$settings =['checksum' => gettext('Checksum offload'), 'tso' => gettext('TCP segmentation offload (TSO)'), 'lro' => gettext('Large receive offload (LRO)'), 'vlan' => gettext('VLAN hardware acceleration'), 'wol' => gettext('Wake-on-LAN magic packet'), 'ipsec' => gettext('Inline IPsec offload (experimental)')];
$short = ['checksum' => gettext('Checksum'), 'tso' => 'TSO', 'lro' => 'LRO', 'vlan' => 'VLAN', 'wol' => 'WoL', 'ipsec' => 'IPsec'];
$profile_labels = ['firewall' => gettext('Firewall Balanced'), 'throughput' => gettext('Maximum Throughput'), 'latency' => gettext('Low Latency'), 'capture' => gettext('Packet Capture / Troubleshooting')];
$profile_help = [
	'firewall' => gettext('LRO and TSO stay off so the firewall sees every packet as it was sent; checksum and VLAN acceleration stay on where the driver supports them.'),
	'throughput' => gettext('Every offload on. The most throughput per CPU, but merged packets can hide details from the firewall, traffic shaping and packet capture.'),
	'latency' => gettext('Offloads that batch packets stay off for steady per-packet latency; checksum and VLAN acceleration stay on.'),
	'capture' => gettext('Every offload off so packet capture shows exactly what is on the wire. Use it while troubleshooting, then switch back.'),
];
$profiles = nic_settings_profiles();

$inventory = nic_ui_inventory();
$input_errors = [];
$savemsg = null;
$reopen_nic = null;

$action = $_POST['action'] ?? null;
$nic = null;
if (in_array($action, ['save_nic', 'apply_recommended'], true)) {
	foreach ($inventory as $candidate) {
		if ($candidate['id'] === ($_POST['nic'] ?? '')) {
			$nic = $candidate;
		}
	}
	if ($nic === null) {
		$input_errors[] = gettext('Unknown network adapter.');
		$action = null;
	}
}
if ($action === 'save_nic') {
	foreach (array_keys($settings) as $setting) {
		$value = $_POST["{$nic['id']}_{$setting}"] ?? 'inherit';
		if (!in_array($value, ['inherit', 'on', 'off'], true)) {
			$input_errors[] = sprintf(gettext('Invalid %1$s setting for %2$s.'), $setting, $nic['device']);
		} elseif ($value !== 'inherit' && !nic_ui_supported($nic, $setting)) {
			$input_errors[] = sprintf(gettext('%1$s does not support %2$s.'), $nic['device'], $settings[$setting]);
		}
	}
	$mtu = trim($_POST["{$nic['id']}_mtu"] ?? '');
	if ($mtu !== '' && (!ctype_digit($mtu) || (int)$mtu < 576 || (int)$mtu > 16384)) {
		$input_errors[] = sprintf(gettext('MTU for %s must be between 576 and 16384.'), $nic['device']);
	} elseif ($mtu !== '' && (int)$mtu > 1500 && !isset($nic['caps']['jumbomtu'])) {
		$input_errors[] = sprintf(gettext('%s does not report jumbo MTU capability.'), $nic['device']);
	}
	/* driver tuning: only the controls this adapter has */
	$tuning_new = [];
	foreach (array_keys($nic['tuning']['controls']) as $setting) {
		$value = trim((string)($_POST["{$nic['id']}_t_{$setting}"] ?? ''));
		$error = nic_tuning_validate($setting, $value, $nic['tuning'], $ncpu);
		if ($error !== null) {
			$input_errors[] = sprintf('%1$s: %2$s', $tuning_labels[$setting], $error);
		} elseif ($value !== '') {
			$tuning_new[$setting] = $value;
		}
	}

	if ($input_errors) {
		$reopen_nic = $nic['id'];
	} else {
		$tuning_old = nic_tuning_saved(nic_settings_saved($nic['id']));
		$path = nic_ui_store_identity($nic);
		foreach (array_keys($settings) as $setting) {
			config_set_path("{$path}/{$setting}", $_POST["{$nic['id']}_{$setting}"] ?? 'inherit');
		}
		if ($mtu === '') {
			config_del_path("{$path}/mtu");
		} else {
			config_set_path("{$path}/mtu", (int)$mtu);
		}
		config_del_path("{$path}/tuning");
		if ($tuning_new) {
			config_set_path("{$path}/tuning", $tuning_new);
		}
		write_config(sprintf(gettext('Updated NIC hardware settings for %s.'), $nic['device']));
		hardware_offloading_applyflags($nic['device']);
		if ($mtu !== '') {
			FreeSense_interface_mtu($nic['device'], (int)$mtu);
		}
		$reboot = nic_tuning_boot_changed($tuning_old, $tuning_new);
		if ($reboot) {
			setup_loader_settings();
		}
		$inventory = nic_ui_inventory(true);
		$savemsg = sprintf(gettext('Settings for %s were saved and applied.'), $nic['device']) .
		    ($reboot ? ' ' . gettext('Queue and descriptor changes take effect after a reboot.') : '');
	}
} elseif ($action === 'apply_recommended') {
	$recommended = nic_tuning_recommend($nic['tuning'], config_get_path('system/nicsettings/profile', 'firewall'));
	if (!$recommended) {
		$input_errors[] = sprintf(gettext('There is nothing to recommend for %s under this profile.'), $nic['device']);
	} else {
		$tuning_old = nic_tuning_saved(nic_settings_saved($nic['id']));
		$tuning_new = array_merge($tuning_old, $recommended);
		$path = nic_ui_store_identity($nic);
		config_set_path("{$path}/tuning", $tuning_new);
		write_config(sprintf(gettext('Applied the recommended driver tuning to %s.'), $nic['device']));
		hardware_offloading_applyflags($nic['device']);
		$reboot = nic_tuning_boot_changed($tuning_old, $tuning_new);
		if ($reboot) {
			setup_loader_settings();
		}
		$inventory = nic_ui_inventory(true);
		$savemsg = sprintf(gettext('The recommended tuning was applied to %s.'), $nic['device']) .
		    ($reboot ? ' ' . gettext('Queue and descriptor changes take effect after a reboot.') : '');
	}
} elseif ($action === 'save_profile') {
	$profile = $_POST['profile'] ?? 'firewall';
	if (!isset($profiles[$profile])) {
		$input_errors[] = gettext('Invalid NIC profile.');
	} else {
		$old_altq = config_path_enabled('system', 'hn_altq_enable');
		config_set_path('system/nicsettings/profile', $profile);
		if (!isset($_POST['checksum_default_off'])) {
			config_del_path('system/nicsettings/checksum_default');
		}
		if (isset($_POST['hn_altq_enable'])) {
			config_set_path('system/hn_altq_enable', true);
		} else {
			config_del_path('system/hn_altq_enable');
		}
		write_config(gettext('Updated NIC profile.'));
		$reboot = ($old_altq !== config_path_enabled('system', 'hn_altq_enable'));
		if ($reboot) {
			setup_loader_settings();
		}
		foreach (array_keys($inventory) as $device) {
			hardware_offloading_applyflags($device);
		}
		$inventory = nic_ui_inventory(true);
		$savemsg = gettext('The profile was saved and applied to every adapter that inherits it.') .
		    ($reboot ? ' ' . gettext('The ALTQ change takes effect after a reboot.') : '');
	}
}

$active_profile = config_get_path('system/nicsettings/profile', 'firewall');
$checksum_default_off = (config_get_path('system/nicsettings/checksum_default') === 'off');
if (!isset($profiles[$active_profile])) {
	$active_profile = 'firewall';
}

include('head.inc');
if ($input_errors) {
	print_input_errors($input_errors);
}
if ($savemsg) {
	print_info_box($savemsg, 'success');
}

fs_tabs('interfaces', 'interfaces_nic_settings.php');
fs_view_switch(['adapters' => gettext('Adapters'), 'profile' => gettext('Profile')], $view);
?>

<style>
/* offload chips: fixed width so the four columns line up; a dot marks a per-adapter override */
.fs-nic-chip { position: relative; min-width: 2.6rem; text-align: center; }
.fs-nic-chip.is-custom::after { content: ""; position: absolute; top: -3px; right: -3px; width: 7px; height: 7px; border-radius: 50%; background: var(--fs-coral); }
.fs-nic-custom { margin-left: .35rem; padding: 0 .35rem; border-radius: var(--fs-r-sm); background: var(--fs-accent-tint); color: var(--fs-coral-text); font-family: var(--bs-body-font-family); font-size: var(--fs-fs-xs); font-weight: 600; }
.fs-nic-legend { display: flex; flex-wrap: wrap; gap: .4rem 1.25rem; align-items: center; }
.fs-nic-legend .fs-nic-chip { min-width: 0; margin-right: .2rem; }
.fs-nic-profile { display: inline-flex; align-items: center; gap: .4rem; padding: .25rem .65rem; border: 1px solid var(--fs-border); border-radius: 999px; color: var(--fs-text); font-size: var(--fs-fs-sm); white-space: nowrap; }
.fs-nic-profile:hover, .fs-nic-profile:focus-visible { border-color: var(--fs-coral); color: var(--fs-text-strong); }
.fs-nic-form { display: grid; grid-template-columns: minmax(0, 15rem) minmax(0, 1fr); gap: .65rem 1rem; align-items: start; }
.fs-nic-form > .form-label { margin: .45rem 0 0; }
@media (max-width: 575.98px) { .fs-nic-form { grid-template-columns: 1fr; gap: .25rem; } .fs-nic-form > .form-label { margin-top: .5rem; } }
.fs-nic-warning, .fs-nic-hint { display: flex; gap: .6rem; margin-top: 1rem; padding: .6rem .8rem; border-radius: var(--fs-r-sm); font-size: var(--fs-fs-sm); }
.fs-nic-warning { background: color-mix(in srgb, var(--fs-warn) 12%, transparent); }
.fs-nic-warning > i { color: var(--fs-warn); margin-top: .2rem; }
.fs-nic-hint { background: var(--fs-surface-raised); }
.fs-nic-hint > i { color: var(--fs-info); margin-top: .2rem; }
.fs-nic-facts { display: grid; grid-template-columns: 9rem minmax(0, 1fr); gap: .5rem 1rem; margin: 0 0 1rem; }
.fs-nic-facts dt { color: var(--fs-text-muted); font-weight: 500; }
.fs-nic-facts dd { margin: 0; }
.fs-nic-pad { padding: 1rem; }
.fs-nic-pad > p { margin-bottom: .85rem; }
.fs-nic-subtitle { margin: 1rem 0 .5rem; font-size: 1rem; font-weight: 600; }
.fs-nic-profiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr)); gap: .75rem; }
.fs-nic-profile-card {
	position: relative; display: flex; flex-direction: column; gap: .5rem; padding: .9rem 1rem .9rem 2.6rem; border: 1px solid var(--fs-border);
	border-radius: var(--fs-r-md); cursor: pointer; transition: border-color var(--fs-t-fast) var(--fs-ease), background-color var(--fs-t-fast) var(--fs-ease);
}
.fs-nic-profile-card:hover { border-color: var(--fs-text-muted); }
.fs-nic-profile-card:has(input:checked) { border-color: var(--fs-coral); background: var(--fs-accent-tint); }
.fs-nic-profile-card:has(input:focus-visible) { outline: 2px solid var(--fs-coral); outline-offset: 2px; }
.fs-nic-profile-card > input { position: absolute; top: 1.05rem; left: 1rem; accent-color: var(--fs-coral); }
.fs-nic-profile-name { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; color: var(--fs-text-strong); font-weight: 600; }
.fs-nic-profile-help { color: var(--fs-text-muted); font-size: var(--fs-fs-sm); }
</style>

<?php if ($view === 'adapters'): ?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Network adapters'),
	'search' => gettext('Search adapters, drivers, MAC addresses…'),
	'noun' => gettext('adapters'),
	'noun_one' => gettext('adapter'),
	'filters' => ['assigned' => [gettext('All adapters'), 'yes' => gettext('Assigned'), 'no' => gettext('Unassigned')]],
	'actions' => '<a class="fs-nic-profile" href="interfaces_nic_settings.php?view=profile" title="' . fs_h(gettext('Change the profile')) . '">'
	    . '<i class="fa-solid fa-sliders" aria-hidden="true"></i>' . fs_h(sprintf(gettext('Profile: %s'), $profile_labels[$active_profile])) . '</a>',
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover" data-sortable>
			<thead>
				<tr>
					<th data-fs-search><?=gettext('Adapter')?></th>
					<th data-fs-search><?=gettext('Hardware')?></th>
					<th><?=gettext('Link')?></th>
					<th><?=gettext('MTU')?></th>
					<th><?=gettext('Offloads')?></th>
					<th class="fs-col-actions"><span class="visually-hidden"><?=gettext('Actions')?></span></th>
				</tr>
			</thead>
			<tbody>
<?php foreach ($inventory as $nic):
	$saved = nic_settings_saved($nic['id']);
	$name = $nic['assignment'] . ' (' . $nic['device'] . ')';
	$actions = [
		['custom', '#', $name, ['icon' => 'fa-sliders', 'label' => sprintf(gettext('Configure %s'), $name), 'attrs' => ['data-fs-modal' => '#nic-' . $nic['id']]]],
		['custom', '#', $name, ['icon' => 'fa-circle-info', 'label' => sprintf(gettext('Details for %s'), $name), 'attrs' => ['data-bs-toggle' => 'modal', 'data-bs-target' => '#nic-info-' . $nic['id']]]],
	];
?>
				<tr data-fs-filter-assigned="<?=$nic['assigned'] ? 'yes' : 'no'?>">
					<td>
						<strong><?=htmlspecialchars($nic['assignment'])?></strong>
						<div class="fs-mono fs-muted small"><?=htmlspecialchars($nic['device'])?></div>
					</td>
					<td>
						<?=htmlspecialchars(($nic['dmesg'] ?? '') ?: strtoupper($nic['driver']))?>
						<div class="fs-mono fs-muted small"><?=htmlspecialchars($nic['hwaddr'] ?? $nic['macaddr'] ?? $nic['mac'] ?? '')?></div>
					</td>
					<td>
						<?=fs_badge($nic['up'] ? 'up' : 'down')?>
						<div class="fs-muted small"><?=htmlspecialchars(isset($nic['media']) ? trim(preg_replace('/[<>\s]+/', ' ', $nic['media'])) : gettext('Virtual'))?></div>
					</td>
					<td class="fs-mono">
						<?=htmlspecialchars((string)($nic['mtu'] ?? '-'))?>
						<?php if (isset($saved['mtu'])): ?><span class="fs-nic-custom" title="<?=gettext('Set on this adapter')?>"><?=gettext('custom')?></span><?php endif; ?>
					</td>
					<td><div class="fs-chips">
<?php foreach (array_merge(['checksum', 'tso', 'lro', 'vlan'], nic_ui_supported($nic, 'ipsec') ? ['ipsec'] : []) as $setting):
	$supported = nic_ui_supported($nic, $setting);
	$on = $supported && ($nic['effective'][$setting] === 'on');
	$custom = isset($saved[$setting]) && $saved[$setting] !== 'inherit';
	$state = !$supported ? gettext('not supported') : ($on ? gettext('on') : gettext('off'));
	$title = $settings[$setting] . ': ' . $state . ($custom ? ' (' . gettext('set on this adapter') . ')' : '');
?>
						<span class="fs-chip fs-chip--strong fs-nic-chip <?=!$supported ? 'is-na' : ($on ? 'is-on' : 'is-off')?><?=$custom ? ' is-custom' : ''?>" title="<?=htmlspecialchars($title)?>"><span class="visually-hidden"><?=htmlspecialchars($title)?></span><span aria-hidden="true"><?=htmlspecialchars($short[$setting])?></span></span>
<?php endforeach; ?>
<?php if (nic_tuning_saved($saved)): ?>
						<span class="fs-nic-custom" title="<?=gettext('Driver tuning is set on this adapter')?>"><?=gettext('tuned')?></span>
<?php endif; ?>
					</div></td>
					<td class="fs-col-actions"><?=fs_row_actions($actions)?></td>
				</tr>
<?php endforeach; ?>
<?php if (empty($inventory)) {
	fs_empty_row(6, gettext('No network adapters were found.'));
} ?>
			</tbody>
		</table>
	</div>
	<div class="panel-footer small fs-muted fs-nic-legend">
		<span><span class="fs-chip fs-chip--strong fs-nic-chip is-on" aria-hidden="true">on</span> <?=gettext('enabled')?></span>
		<span><span class="fs-chip fs-chip--strong fs-nic-chip is-off" aria-hidden="true">off</span> <?=gettext('disabled')?></span>
		<span><span class="fs-chip fs-chip--strong fs-nic-chip is-na" aria-hidden="true">n/a</span> <?=gettext('not supported by the adapter')?></span>
		<span><span class="fs-chip fs-chip--strong fs-nic-chip is-on is-custom" aria-hidden="true">set</span> <?=gettext('set on this adapter instead of the profile')?></span>
	</div>
</div>

<?php
foreach ($inventory as $nic):
	$saved = nic_settings_saved($nic['id']);
	/* what "Profile default" resolves to on this adapter */
	$profile_values = $profiles[$active_profile];
	if ($checksum_default_off || nic_settings_checksum_guarded($nic['device'])) {
		$profile_values['checksum'] = 'off';
	}
	$reopen = null;
	if ($reopen_nic === $nic['id']) {
		$reopen = [];
		foreach (array_keys($settings) as $setting) {
			$reopen["{$nic['id']}_{$setting}"] = $_POST["{$nic['id']}_{$setting}"] ?? 'inherit';
		}
		$reopen["{$nic['id']}_mtu"] = $_POST["{$nic['id']}_mtu"] ?? '';
		foreach (array_keys($nic['tuning']['controls']) as $setting) {
			$reopen["{$nic['id']}_t_{$setting}"] = $_POST["{$nic['id']}_t_{$setting}"] ?? '';
		}
	}
	$tuning_saved = nic_tuning_saved($saved);

	/* configure one adapter */
	fs_modal_form_begin('nic-' . $nic['id'], sprintf(gettext('Configure %1$s (%2$s)'), $nic['assignment'], $nic['device']), '', ['nic' => $nic['id']], $reopen);
?>
	<div class="fs-nic-form">
<?php foreach ($settings as $setting => $label):
	$supported = nic_ui_supported($nic, $setting);
	if ($setting === 'ipsec' && !$supported) {
		continue;
	}
	$field = "{$nic['id']}_{$setting}";
	$current = $saved[$setting] ?? 'inherit';
?>
		<label class="form-label" for="<?=htmlspecialchars($field)?>"><?=htmlspecialchars($label)?></label>
		<div>
			<select class="form-select" id="<?=htmlspecialchars($field)?>" name="<?=htmlspecialchars($field)?>">
				<option value="inherit"<?=$current === 'inherit' ? ' selected' : ''?>><?=htmlspecialchars(sprintf(gettext('Profile default (%s)'), $supported ? $profile_values[$setting] : gettext('n/a')))?></option>
				<?php if ($supported): ?>
				<option value="on"<?=$current === 'on' ? ' selected' : ''?>><?=gettext('On')?></option>
				<option value="off"<?=$current === 'off' ? ' selected' : ''?>><?=gettext('Off')?></option>
				<?php endif; ?>
			</select>
			<?php if (!$supported): ?><div class="form-text"><?=gettext('Not supported by this adapter.')?></div><?php endif; ?>
			<?php if ($setting === 'checksum' && $supported && nic_settings_checksum_guarded($nic['device'])): ?><div class="form-text"><?=gettext('Off by default for this virtual driver: forwarding breaks with it on some hypervisors. Turn it on only after testing; other hypervisors are faster with it.')?></div><?php endif; ?>
			<?php if ($setting === 'ipsec'): ?><div class="form-text"><?=gettext('The adapter encrypts and decrypts IPsec itself. Only AES-GCM security associations are offloaded; others stay in software. Applies to tunnels that come up after saving. Experimental: not yet tested on this hardware.')?></div><?php endif; ?>
		</div>
<?php endforeach; ?>
		<label class="form-label" for="<?=htmlspecialchars($nic['id'])?>_mtu"><?=gettext('MTU')?></label>
		<div>
			<input class="form-control fs-mono" type="number" min="576" max="16384" id="<?=htmlspecialchars($nic['id'])?>_mtu" name="<?=htmlspecialchars($nic['id'])?>_mtu" value="<?=htmlspecialchars((string)($saved['mtu'] ?? ''))?>" placeholder="<?=htmlspecialchars((string)($nic['mtu'] ?? 1500))?>">
			<div class="form-text"><?=isset($nic['caps']['jumbomtu']) ? gettext('Empty uses the interface default. Jumbo frames need every device on the path to support them.') : gettext('Empty uses the interface default. This adapter does not support jumbo frames.')?></div>
		</div>
	</div>
<?php if ($nic['tuning']['controls']): ?>
	<h3 class="fs-nic-subtitle"><?=gettext('Performance')?></h3>
	<p class="form-text mt-0"><?=gettext('Driver controls this adapter offers. Empty keeps the driver default; Details shows what the profile recommends.')?></p>
	<div class="fs-nic-form">
<?php foreach ($nic['tuning']['controls'] as $setting => $current):
	$control = nic_tuning_controls()[$setting];
	$field = "{$nic['id']}_t_{$setting}";
	$value = $tuning_saved[$setting] ?? '';
?>
		<label class="form-label" for="<?=htmlspecialchars($field)?>"><?=htmlspecialchars($tuning_labels[$setting])?><?php if ($control['apply'] === 'boot'): ?> <?=fs_badge('info', gettext('After reboot'))?><?php endif; ?></label>
		<div>
<?php if ($control['type'] === 'choice'): ?>
			<select class="form-select" id="<?=htmlspecialchars($field)?>" name="<?=htmlspecialchars($field)?>">
				<option value=""<?=$value === '' ? ' selected' : ''?>><?=htmlspecialchars(sprintf(gettext('Not set (now: %s)'), nic_ui_tuning_value($setting, $current, $nic['driver'])))?></option>
<?php foreach (nic_tuning_choices($setting, $nic['driver']) as $choice => $choice_label): ?>
				<option value="<?=htmlspecialchars((string)$choice)?>"<?=$value === (string)$choice ? ' selected' : ''?>><?=htmlspecialchars($choice_label)?></option>
<?php endforeach; ?>
			</select>
<?php else: ?>
			<input class="form-control fs-mono" type="number" id="<?=htmlspecialchars($field)?>" name="<?=htmlspecialchars($field)?>" value="<?=htmlspecialchars($value)?>"
			    <?=$control['type'] === 'count' ? 'min="1" max="' . $ncpu . '"' : 'min="64" max="16384"'?>
			    placeholder="<?=htmlspecialchars($setting === 'queues' ? sprintf(gettext('driver default (now %d)'), $nic['tuning']['rxqs']) : gettext('driver default'))?>">
<?php endif; ?>
			<div class="form-text"><?=htmlspecialchars($tuning_help[$setting])?></div>
		</div>
<?php endforeach; ?>
	</div>
<?php endif; ?>
	<div class="fs-nic-warning">
<i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><?=gettext('Applying to an active interface can interrupt traffic for a moment, including this session if it runs over this adapter.')?></div>
<?php
	fs_modal_form_end(gettext('Save and apply'), 'action', 'save_nic', 'fa-check');

	/* read-only details and diagnostics */
	$detect = $nic['tuning'];
	$recommended = nic_tuning_recommend($detect, $active_profile);
	$differences = nic_tuning_differences($detect, $recommended);
	$hints = [];
	if (nic_settings_checksum_guarded($nic['device'])) {
		$hints[] = gettext('Virtual adapter: checksum offload stays off unless you turn it on for this adapter; multiqueue changes need a reboot.');
	}
	$hints = array_merge($hints, nic_tuning_notes($detect, $ncpu, $vm_guest));
	$pci = $detect['pci'] ? sprintf('%s:%s', $detect['pci']['vendor'] ?? '?', $detect['pci']['device'] ?? '?') : '';
?>
<div class="modal fade" id="nic-info-<?=htmlspecialchars($nic['id'])?>" tabindex="-1" aria-labelledby="nic-info-<?=htmlspecialchars($nic['id'])?>-title" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
		<div class="modal-header">
			<h2 class="modal-title" id="nic-info-<?=htmlspecialchars($nic['id'])?>-title"><?=htmlspecialchars(sprintf(gettext('%1$s (%2$s)'), $nic['assignment'], $nic['device']))?></h2>
			<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?=gettext('Close')?>"></button>
		</div>
		<div class="modal-body">
			<dl class="fs-nic-facts">
				<dt><?=gettext('Hardware')?></dt><dd><?=htmlspecialchars(($nic['dmesg'] ?? '') ?: strtoupper($nic['driver']))?></dd>
				<dt><?=gettext('Driver')?></dt><dd class="fs-mono"><?=htmlspecialchars($nic['driver'])?></dd>
				<dt><?=gettext('MAC address')?></dt><dd class="fs-mono"><?=htmlspecialchars($nic['hwaddr'] ?? $nic['macaddr'] ?? $nic['mac'] ?? '-')?></dd>
				<dt><?=gettext('Capabilities')?></dt>
				<dd><div class="fs-chips">
<?php $caps = array_keys($nic['caps'] ?? []); sort($caps); foreach ($caps as $cap): $enabled = isset($nic['encaps'][$cap]); ?>
					<span class="fs-chip fs-chip--mono <?=$enabled ? 'is-on' : 'is-off'?>" title="<?=$enabled ? gettext('enabled') : gettext('supported, disabled')?>"><?=htmlspecialchars($cap)?></span>
<?php endforeach; ?>
<?php if (!$caps): ?><span class="fs-muted"><?=gettext('None reported')?></span><?php endif; ?>
				</div><div class="form-text"><?=gettext('Highlighted capabilities are currently enabled.')?></div></dd>
<?php if ($detect['found']): ?>
				<dt><?=gettext('Device')?></dt><dd><?=htmlspecialchars($detect['desc'] ?: '-')?><?php if ($pci !== ''): ?> <span class="fs-mono fs-muted small"><?=htmlspecialchars(sprintf(gettext('PCI %s'), $pci))?></span><?php endif; ?></dd>
<?php if ($detect['iflib']): ?>
				<dt><?=gettext('Queues')?></dt><dd><?=htmlspecialchars(sprintf(gettext('%1$d receive, %2$d transmit on %3$d CPUs'), $detect['rxqs'], $detect['txqs'], $ncpu))?><?php if ($detect['msix'] !== null): ?> <span class="fs-muted small"><?=htmlspecialchars(sprintf(gettext('(%d interrupt vectors)'), $detect['msix']))?></span><?php endif; ?>
<?php if ($detect['queue_cpus']): ?><div class="fs-muted small"><?=htmlspecialchars(sprintf(gettext('Receive queues run on CPU %s'), implode(', ', $detect['queue_cpus'])))?></div><?php endif; ?></dd>
<?php endif; ?>
<?php endif; ?>
			</dl>
<?php if ($detect['counters']): ?>
			<h3 class="fs-nic-subtitle"><?=gettext('Drops and errors since boot')?></h3>
			<dl class="fs-nic-facts">
<?php foreach ($counter_labels as $counter => $counter_label): if (!isset($detect['counters'][$counter])) { continue; } ?>
				<dt><?=htmlspecialchars($counter_label)?></dt><dd class="fs-mono<?=$detect['counters'][$counter] > 0 ? ' text-warning-emphasis' : ''?>"><?=htmlspecialchars(number_format($detect['counters'][$counter]))?></dd>
<?php endforeach; ?>
			</dl>
<?php endif; ?>
<?php if ($detect['controls']): ?>
			<h3 class="fs-nic-subtitle"><?=htmlspecialchars(sprintf(gettext('Tuning for the %s profile'), $profile_labels[$active_profile]))?></h3>
<?php if (!$recommended): ?>
			<p class="fs-muted"><?=gettext('This profile recommends no driver tuning.')?></p>
<?php elseif (!$differences): ?>
			<p class="fs-muted"><i class="fa-solid fa-check" aria-hidden="true"></i> <?=gettext('The adapter already runs with the recommended values.')?></p>
<?php else: ?>
			<table class="table table-sm">
				<thead><tr><th><?=gettext('Setting')?></th><th><?=gettext('Now')?></th><th><?=gettext('Recommended')?></th></tr></thead>
				<tbody>
<?php foreach ($differences as $setting => $difference): ?>
					<tr><td><?=htmlspecialchars($tuning_labels[$setting])?><?php if (nic_tuning_controls()[$setting]['apply'] === 'boot'): ?> <?=fs_badge('info', gettext('After reboot'))?><?php endif; ?></td>
					<td><?=htmlspecialchars(nic_ui_tuning_value($setting, $difference['current'], $nic['driver']))?></td>
					<td><?=htmlspecialchars(nic_ui_tuning_value($setting, $difference['recommended'], $nic['driver']))?></td></tr>
<?php endforeach; ?>
				</tbody>
			</table>
			<form method="post" class="d-flex justify-content-end">
				<input type="hidden" name="nic" value="<?=htmlspecialchars($nic['id'])?>">
				<button class="btn btn-sm btn-primary" name="action" value="apply_recommended"><i class="fa-solid fa-wand-magic-sparkles icon-embed-btn" aria-hidden="true"></i><?=gettext('Apply recommended')?></button>
			</form>
<?php endif; ?>
<?php endif; ?>
			<?php foreach ($hints as $hint): ?>
			<div class="fs-nic-hint"><i class="fa-solid fa-lightbulb" aria-hidden="true"></i><?=htmlspecialchars($hint)?></div>
			<?php endforeach; ?>
		</div>
		<div class="modal-footer">
			<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?=gettext('Close')?></button>
			<button type="button" class="btn btn-primary" data-bs-dismiss="modal" data-fs-modal="#nic-<?=htmlspecialchars($nic['id'])?>"><i class="fa-solid fa-sliders icon-embed-btn" aria-hidden="true"></i><?=gettext('Configure')?></button>
		</div>
	</div></div>
</div>
<?php endforeach; ?>

<?php else: ?>
<form method="post">
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('Default profile')?></h2></div>
	<div class="panel-body fs-nic-pad">
		<p class="fs-muted"><?=gettext('Every adapter setting left at "Profile default" follows this profile. Settings the adapter does not support are never applied.')?></p>
		<div class="fs-nic-profiles" role="radiogroup" aria-label="<?=gettext('Default profile')?>">
<?php foreach ($profile_labels as $key => $label): ?>
			<label class="fs-nic-profile-card">
				<input type="radio" name="profile" value="<?=$key?>"<?=$active_profile === $key ? ' checked' : ''?>>
				<span class="fs-nic-profile-name"><?=htmlspecialchars($label)?><?php if ($key === 'firewall'): ?> <?=fs_badge('info', gettext('Recommended'))?><?php endif; ?></span>
				<span class="fs-nic-profile-help"><?=htmlspecialchars($profile_help[$key])?></span>
				<span class="fs-chips">
<?php foreach (['checksum', 'tso', 'lro', 'vlan'] as $setting): $on = $profiles[$key][$setting] === 'on'; ?>
					<span class="fs-chip fs-chip--strong fs-nic-chip <?=$on ? 'is-on' : 'is-off'?>" title="<?=htmlspecialchars($settings[$setting] . ': ' . ($on ? gettext('on') : gettext('off')))?>"><?=htmlspecialchars($short[$setting])?></span>
<?php endforeach; ?>
				</span>
			</label>
<?php endforeach; ?>
		</div>
<?php if ($checksum_default_off): ?>
		<div class="form-check mt-3">
			<input class="form-check-input" type="checkbox" id="checksum_default_off" name="checksum_default_off" value="yes" checked>
			<label class="form-check-label" for="checksum_default_off"><?=gettext('Keep checksum offload off on every adapter')?></label>
			<div class="form-text"><?=gettext('Carried over from an imported configuration that disabled hardware checksum offload. Clear it to let the profile decide; an adapter setting still wins.')?></div>
		</div>
<?php endif; ?>
	</div>
</div>

<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('Virtual adapters')?></h2></div>
	<div class="panel-body fs-nic-pad">
		<div class="form-check">
			<input class="form-check-input" type="checkbox" id="hn_altq_enable" name="hn_altq_enable" value="yes"<?=config_path_enabled('system', 'hn_altq_enable') ? ' checked' : ''?>>
			<label class="form-check-label" for="hn_altq_enable"><?=gettext('Enable ALTQ traffic shaping on vtnet and hn adapters')?></label>
			<div class="form-text"><?=gettext('Applies to the whole driver and turns off multiqueue. Takes effect after a reboot.')?></div>
		</div>
	</div>
	<div class="panel-footer small fs-muted">
		<i class="fa-solid fa-circle-info" aria-hidden="true"></i>
		<?=gettext('Hardware offloads change what packet capture tools see. Use the Packet Capture profile while validating traffic, and only raise the MTU when every device on the path supports jumbo frames.')?>
	</div>
</div>

<div class="fs-actionbar fs-actionbar--plain">
	<button class="btn btn-primary" name="action" value="save_profile" data-fs-confirm="<?=gettext('Apply the profile to every adapter that inherits it?')?>" data-fs-confirm-detail="<?=gettext('Active interfaces can drop traffic for a moment.')?>" data-fs-confirm-action="<?=gettext('Save and apply')?>"><i class="fa-solid fa-check icon-embed-btn" aria-hidden="true"></i><?=gettext('Save and apply')?></button>
</div>
</form>
<?php endif; ?>

<?php include('foot.inc'); ?>
