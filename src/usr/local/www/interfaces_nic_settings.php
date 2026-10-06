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
			$result[$config['if']] = $config['descr'] ?: strtoupper($logical);
		}
	}
	return $result;
}

function nic_ui_inventory() {
	$devices = get_interface_list('all', 'physical') ?: [];
	$assignments = nic_ui_assignments();
	$result = [];
	foreach ($devices as $device => $basic) {
		$info = get_interface_addresses($device) ?: [];
		$effective = nic_settings_effective($device, $info);
		$result[$device] = array_merge($basic, $info, [
			'device' => $device,
			'driver' => nic_ui_driver($device),
			'assigned' => isset($assignments[$device]),
			'assignment' => $assignments[$device] ?? gettext('Unassigned'),
			'effective' => $effective,
			'id' => $effective['id'],
		]);
	}
	ksort($result, SORT_NATURAL);
	return $result;
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
	];
	foreach ($need[$setting] ?? [] as $cap) {
		if (isset($caps[$cap])) {
			return true;
		}
	}
	return false;
}

function nic_ui_driver_controls($nic) {
	$driver = $nic['driver'];
	$unit = substr($nic['device'], strlen($driver));
	$registry = [
		'em' => ['cur' => ['rx_processing_limit', 'fc'], 'boot' => ['num_queues', 'rxd', 'txd']],
		'igb' => ['cur' => ['rx_processing_limit', 'fc'], 'boot' => ['num_queues', 'rxd', 'txd']],
		'ix' => ['cur' => ['fc'], 'boot' => ['max_interrupt_rate', 'flow_control']],
		'ixl' => ['cur' => ['fw_version'], 'boot' => ['max_queues']],
		're' => ['cur' => [], 'boot' => []],
		'vtnet' => ['cur' => [], 'boot' => ['csum_disable', 'tso_disable', 'lro_disable', 'mq_disable', 'mq_max_pairs']],
		'ena' => ['cur' => [], 'boot' => []],
		'hn' => ['cur' => [], 'boot' => ['altq_disable']],
	];
	if (!isset($registry[$driver])) {
		return [];
	}
	$found = [];
	foreach ($registry[$driver] as $kind => $names) {
		foreach ($names as $name) {
			$candidates = $kind === 'cur'
				? ["dev.{$driver}.{$unit}.{$name}"]
				: ["hw.{$driver}.{$unit}.{$name}", "hw.{$driver}.{$name}"];
			foreach ($candidates as $mib) {
				$value = trim((string)shell_exec('/sbin/sysctl -n ' . escapeshellarg($mib) . ' 2>/dev/null'));
				if ($value !== '') {
					$found[] = ['mib' => $mib, 'value' => $value, 'apply' => $kind === 'cur' ? gettext('Live or link restart') : gettext('Reboot required')];
					break;
				}
			}
		}
	}
	return $found;
}

$settings = ['checksum' => gettext('Checksum offload'), 'tso' => gettext('TCP segmentation offload (TSO)'), 'lro' => gettext('Large receive offload (LRO)'), 'vlan' => gettext('VLAN hardware acceleration'), 'wol' => gettext('Wake-on-LAN magic packet')];
$short = ['checksum' => gettext('Checksum'), 'tso' => 'TSO', 'lro' => 'LRO', 'vlan' => 'VLAN', 'wol' => 'WoL'];
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
if ($action === 'save_nic') {
	$nic = null;
	foreach ($inventory as $candidate) {
		if ($candidate['id'] === ($_POST['nic'] ?? '')) {
			$nic = $candidate;
		}
	}
	if ($nic === null) {
		$input_errors[] = gettext('Unknown network adapter.');
	} else {
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

		if ($input_errors) {
			$reopen_nic = $nic['id'];
		} else {
			$path = "system/nicsettings/adapters/{$nic['id']}";
			/* drop a pre-prefix entry so only one copy remains */
			config_del_path('system/nicsettings/adapters/' . substr($nic['id'], 4));
			config_set_path("{$path}/mac", $nic['hwaddr'] ?? $nic['macaddr'] ?? $nic['mac'] ?? '');
			config_set_path("{$path}/device", $nic['device']);
			config_set_path("{$path}/driver", $nic['driver']);
			foreach (array_keys($settings) as $setting) {
				config_set_path("{$path}/{$setting}", $_POST["{$nic['id']}_{$setting}"] ?? 'inherit');
			}
			if ($mtu === '') {
				config_del_path("{$path}/mtu");
			} else {
				config_set_path("{$path}/mtu", (int)$mtu);
			}
			write_config(sprintf(gettext('Updated NIC hardware settings for %s.'), $nic['device']));
			hardware_offloading_applyflags($nic['device']);
			if ($mtu !== '') {
				FreeSense_interface_mtu($nic['device'], (int)$mtu);
			}
			$inventory = nic_ui_inventory();
			$savemsg = sprintf(gettext('Settings for %s were saved and applied.'), $nic['device']);
		}
	}
} elseif ($action === 'save_profile') {
	$profile = $_POST['profile'] ?? 'firewall';
	if (!isset($profiles[$profile])) {
		$input_errors[] = gettext('Invalid NIC profile.');
	} else {
		$old_altq = config_path_enabled('system', 'hn_altq_enable');
		config_set_path('system/nicsettings/profile', $profile);
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
		$inventory = nic_ui_inventory();
		$savemsg = gettext('The profile was saved and applied to every adapter that inherits it.') .
		    ($reboot ? ' ' . gettext('The ALTQ change takes effect after a reboot.') : '');
	}
}

$active_profile = config_get_path('system/nicsettings/profile', 'firewall');
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
.fs-nic-chips { display: inline-flex; flex-wrap: wrap; gap: 4px; }
.fs-nic-chip {
	position: relative; display: inline-block; min-width: 2.6rem; padding: .05rem .45rem; border-radius: var(--fs-r-sm);
	border: 1px solid var(--fs-border); font-size: var(--fs-fs-xs); font-weight: 600; line-height: 1.35rem; text-align: center; white-space: nowrap;
}
.fs-nic-chip.is-on { border-color: color-mix(in srgb, var(--fs-pass) 45%, transparent); background: color-mix(in srgb, var(--fs-pass) 12%, transparent); color: var(--fs-pass); }
.fs-nic-chip.is-off { color: var(--fs-text-muted); }
.fs-nic-chip.is-na { color: var(--fs-text-muted); opacity: .45; text-decoration: line-through; }
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
.fs-nic-caps { display: flex; flex-wrap: wrap; gap: 4px; }
.fs-nic-cap { padding: 0 .45rem; border: 1px solid var(--fs-border); border-radius: var(--fs-r-sm); color: var(--fs-text-muted); font-family: var(--fs-font-mono, monospace); font-size: var(--fs-fs-xs); line-height: 1.4rem; }
.fs-nic-cap.is-on { border-color: color-mix(in srgb, var(--fs-pass) 45%, transparent); color: var(--fs-pass); }
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
						<div class="fs-muted small"><?=htmlspecialchars($nic['media'] ?? gettext('Unknown'))?></div>
					</td>
					<td class="fs-mono">
						<?=htmlspecialchars((string)($nic['mtu'] ?? '-'))?>
						<?php if (isset($saved['mtu'])): ?><span class="fs-nic-custom" title="<?=gettext('Set on this adapter')?>"><?=gettext('custom')?></span><?php endif; ?>
					</td>
					<td><div class="fs-nic-chips">
<?php foreach (['checksum', 'tso', 'lro', 'vlan'] as $setting):
	$supported = nic_ui_supported($nic, $setting);
	$on = $supported && ($nic['effective'][$setting] === 'on');
	$custom = isset($saved[$setting]) && $saved[$setting] !== 'inherit';
	$state = !$supported ? gettext('not supported') : ($on ? gettext('on') : gettext('off'));
	$title = $settings[$setting] . ': ' . $state . ($custom ? ' (' . gettext('set on this adapter') . ')' : '');
?>
						<span class="fs-nic-chip <?=!$supported ? 'is-na' : ($on ? 'is-on' : 'is-off')?><?=$custom ? ' is-custom' : ''?>" title="<?=htmlspecialchars($title)?>"><span class="visually-hidden"><?=htmlspecialchars($title)?></span><span aria-hidden="true"><?=htmlspecialchars($short[$setting])?></span></span>
<?php endforeach; ?>
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
		<span><span class="fs-nic-chip is-on" aria-hidden="true">on</span> <?=gettext('enabled')?></span>
		<span><span class="fs-nic-chip is-off" aria-hidden="true">off</span> <?=gettext('disabled')?></span>
		<span><span class="fs-nic-chip is-na" aria-hidden="true">n/a</span> <?=gettext('not supported by the adapter')?></span>
		<span><span class="fs-nic-chip is-on is-custom" aria-hidden="true">set</span> <?=gettext('set on this adapter instead of the profile')?></span>
	</div>
</div>

<?php
foreach ($inventory as $nic):
	$saved = nic_settings_saved($nic['id']);
	$profile_values = $profiles[$active_profile];
	$reopen = null;
	if ($reopen_nic === $nic['id']) {
		$reopen = [];
		foreach (array_keys($settings) as $setting) {
			$reopen["{$nic['id']}_{$setting}"] = $_POST["{$nic['id']}_{$setting}"] ?? 'inherit';
		}
		$reopen["{$nic['id']}_mtu"] = $_POST["{$nic['id']}_mtu"] ?? '';
	}

	/* configure one adapter */
	fs_modal_form_begin('nic-' . $nic['id'], sprintf(gettext('Configure %1$s (%2$s)'), $nic['assignment'], $nic['device']), '', ['nic' => $nic['id']], $reopen);
?>
	<div class="fs-nic-form">
<?php foreach ($settings as $setting => $label):
	$supported = nic_ui_supported($nic, $setting);
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
		</div>
<?php endforeach; ?>
		<label class="form-label" for="<?=htmlspecialchars($nic['id'])?>_mtu"><?=gettext('MTU')?></label>
		<div>
			<input class="form-control fs-mono" type="number" min="576" max="16384" id="<?=htmlspecialchars($nic['id'])?>_mtu" name="<?=htmlspecialchars($nic['id'])?>_mtu" value="<?=htmlspecialchars((string)($saved['mtu'] ?? ''))?>" placeholder="<?=htmlspecialchars((string)($nic['mtu'] ?? 1500))?>">
			<div class="form-text"><?=isset($nic['caps']['jumbomtu']) ? gettext('Empty uses the interface default. Jumbo frames need every device on the path to support them.') : gettext('Empty uses the interface default. This adapter does not support jumbo frames.')?></div>
		</div>
	</div>
	<div class="fs-nic-warning"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i><?=gettext('Applying to an active interface can interrupt traffic for a moment, including this session if it runs over this adapter.')?></div>
<?php
	fs_modal_form_end(gettext('Save and apply'), 'action', 'save_nic', 'fa-check');

	/* read-only details */
	$controls = nic_ui_driver_controls($nic);
	$hints = [];
	if ($nic['driver'] === 're') {
		$hints[] = gettext('Realtek adapter: if you see unexplained corruption, drops or connectivity problems, turn checksum offload off.');
	}
	if (in_array($nic['driver'], ['vtnet', 'ena', 'hn'], true)) {
		$hints[] = gettext('Virtual adapter: FreeSense keeps checksum offload off for this driver; multiqueue changes may need a reboot.');
	}
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
				<dd><div class="fs-nic-caps">
<?php $caps = array_keys($nic['caps'] ?? []); sort($caps); foreach ($caps as $cap): $enabled = isset($nic['encaps'][$cap]); ?>
					<span class="fs-nic-cap<?=$enabled ? ' is-on' : ''?>" title="<?=$enabled ? gettext('enabled') : gettext('supported, disabled')?>"><?=htmlspecialchars($cap)?></span>
<?php endforeach; ?>
<?php if (!$caps): ?><span class="fs-muted"><?=gettext('None reported')?></span><?php endif; ?>
				</div><div class="form-text"><?=gettext('Highlighted capabilities are currently enabled.')?></div></dd>
			</dl>
			<?php if ($controls): ?>
			<h3 class="fs-nic-subtitle"><?=gettext('Driver controls')?></h3>
			<table class="table table-sm">
				<thead><tr><th><?=gettext('Control')?></th><th><?=gettext('Current value')?></th><th><?=gettext('Takes effect')?></th></tr></thead>
				<tbody>
				<?php foreach ($controls as $control): ?>
					<tr><td class="fs-mono"><?=htmlspecialchars($control['mib'])?></td><td class="fs-mono"><?=htmlspecialchars($control['value'])?></td><td><?=htmlspecialchars($control['apply'])?></td></tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="form-text"><?=gettext('Shown for reference; change them under System > Advanced > System Tunables.')?></p>
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
				<span class="fs-nic-chips">
<?php foreach (['checksum', 'tso', 'lro', 'vlan'] as $setting): $on = $profiles[$key][$setting] === 'on'; ?>
					<span class="fs-nic-chip <?=$on ? 'is-on' : 'is-off'?>" title="<?=htmlspecialchars($settings[$setting] . ': ' . ($on ? gettext('on') : gettext('off')))?>"><?=htmlspecialchars($short[$setting])?></span>
<?php endforeach; ?>
				</span>
			</label>
<?php endforeach; ?>
		</div>
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
