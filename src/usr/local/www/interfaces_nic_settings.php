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
$view = $_REQUEST['view'] ?? 'overview';
$valid_views = ['overview', 'configure', 'recommendations', 'diagnostics'];
if (!in_array($view, $valid_views, true)) {
	$view = 'overview';
}

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
			'assignment' => $assignments[$device] ?? gettext('Unassigned'),
			'effective' => $effective,
			'id' => $effective['id'],
		]);
	}
	ksort($result, SORT_NATURAL);
	return $result;
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

$inventory = nic_ui_inventory();
$input_errors = [];
$savemsg = null;
$preview = false;

if ($_POST['action'] ?? null) {
	$action = $_POST['action'];
	if ($action === 'preview' || $action === 'apply') {
		$profiles = nic_settings_profiles();
		$profile = $_POST['profile'] ?? 'firewall';
		if (!isset($profiles[$profile])) {
			$input_errors[] = gettext('Invalid NIC profile.');
		}
		foreach ($inventory as $nic) {
			foreach (['checksum', 'tso', 'lro', 'vlan', 'wol'] as $setting) {
				$value = $_POST["{$nic['id']}_{$setting}"] ?? 'inherit';
				if (!in_array($value, ['inherit', 'on', 'off'], true)) {
					$input_errors[] = sprintf(gettext('Invalid %s setting for %s.'), $setting, $nic['device']);
				}
			}
			$mtu = trim($_POST["{$nic['id']}_mtu"] ?? '');
			if ($mtu !== '' && (!ctype_digit($mtu) || (int)$mtu < 576 || (int)$mtu > 16384)) {
				$input_errors[] = sprintf(gettext('MTU for %s must be between 576 and 16384.'), $nic['device']);
			} elseif ($mtu !== '' && (int)$mtu > 1500 && !isset($nic['caps']['jumbomtu'])) {
				$input_errors[] = sprintf(gettext('%s does not report jumbo MTU capability.'), $nic['device']);
			}
		}
		if (!$input_errors && $action === 'preview') {
			$preview = true;
		}
		if (!$input_errors && $action === 'apply') {
			$old_altq = config_path_enabled('system', 'hn_altq_enable');
			config_set_path('system/nicsettings/profile', $profile);
			if (isset($_POST['hn_altq_enable'])) {
				config_set_path('system/hn_altq_enable', true);
			} else {
				config_del_path('system/hn_altq_enable');
			}
			foreach ($inventory as $nic) {
				$path = "system/nicsettings/adapters/{$nic['id']}";
				config_set_path("{$path}/mac", $nic['hwaddr'] ?? $nic['macaddr'] ?? $nic['mac'] ?? '');
				config_set_path("{$path}/device", $nic['device']);
				config_set_path("{$path}/driver", $nic['driver']);
				foreach (['checksum', 'tso', 'lro', 'vlan', 'wol'] as $setting) {
					config_set_path("{$path}/{$setting}", $_POST["{$nic['id']}_{$setting}"] ?? 'inherit');
				}
				$mtu = trim($_POST["{$nic['id']}_mtu"] ?? '');
				if ($mtu === '') {
					config_del_path("{$path}/mtu");
				} else {
					config_set_path("{$path}/mtu", (int)$mtu);
				}
			}
			write_config(gettext('Updated NIC hardware settings.'));
			if ($old_altq !== config_path_enabled('system', 'hn_altq_enable')) {
				setup_loader_settings();
			}
			foreach (array_keys($inventory) as $device) {
				hardware_offloading_applyflags($device);
				$nic = $inventory[$device];
				$mtu = config_get_path("system/nicsettings/adapters/{$nic['id']}/mtu");
				if (is_numeric($mtu)) {
					FreeSense_interface_mtu($device, (int)$mtu);
				}
			}
			$inventory = nic_ui_inventory();
			$savemsg = gettext('NIC settings were saved and supported live settings were applied.');
		}
	}
}

include('head.inc');
if ($input_errors) {
	print_input_errors($input_errors);
}
if ($savemsg) {
	print_info_box($savemsg, 'success');
}

$tabs = [
	[gettext('Overview'), $view === 'overview', 'interfaces_nic_settings.php?view=overview'],
	[gettext('Configure'), $view === 'configure', 'interfaces_nic_settings.php?view=configure'],
	[gettext('Recommendations'), $view === 'recommendations', 'interfaces_nic_settings.php?view=recommendations'],
	[gettext('Diagnostics'), $view === 'diagnostics', 'interfaces_nic_settings.php?view=diagnostics'],
];
display_top_tabs($tabs);

$profile_labels = ['firewall' => gettext('Firewall Balanced'), 'throughput' => gettext('Maximum Throughput'), 'latency' => gettext('Low Latency'), 'capture' => gettext('Packet Capture / Troubleshooting')];
$active_profile = config_get_path('system/nicsettings/profile', 'firewall');
$choices = ['inherit' => gettext('Inherit profile'), 'on' => gettext('Enabled'), 'off' => gettext('Disabled')];
?>

<?php if ($view === 'overview'): ?>
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><i class="fa fa-microchip"></i> <?=gettext('Network Interface Hardware')?></h2></div>
	<div class="panel-body"><p><?=sprintf(gettext('Active profile: %s. Settings are capability-aware and unsupported controls are never applied.'), htmlspecialchars($profile_labels[$active_profile] ?? $active_profile))?></p></div>
	<div class="table-responsive"><table class="table table-striped table-hover">
	<thead><tr><th><?=gettext('Interface')?></th><th><?=gettext('Hardware')?></th><th><?=gettext('Link')?></th><th><?=gettext('MTU')?></th><th><?=gettext('Offloads')?></th><th><?=gettext('Status')?></th></tr></thead><tbody>
	<?php foreach ($inventory as $nic): $caps = $nic['caps'] ?? []; $enabled = $nic['encaps'] ?? []; ?>
	<tr>
		<td><strong><?=htmlspecialchars($nic['assignment'])?></strong><br><span class="text-muted"><?=htmlspecialchars($nic['device'])?></span></td>
		<td><?=htmlspecialchars(($nic['dmesg'] ?? '') ?: strtoupper($nic['driver']))?><br><small><?=htmlspecialchars($nic['hwaddr'] ?? $nic['macaddr'] ?? $nic['mac'] ?? '')?></small></td>
		<td><span class="label label-<?=$nic['up'] ? 'success' : 'default'?>"><?=$nic['up'] ? gettext('Up') : gettext('Down')?></span><br><?=htmlspecialchars($nic['media'] ?? gettext('Unknown'))?></td>
		<td><?=htmlspecialchars((string)($nic['mtu'] ?? '-'))?></td>
		<td><small><?=gettext('Checksum')?>: <strong><?=htmlspecialchars($nic['effective']['checksum'])?></strong> &middot; TSO: <strong><?=htmlspecialchars($nic['effective']['tso'])?></strong> &middot; LRO: <strong><?=htmlspecialchars($nic['effective']['lro'])?></strong></small></td>
		<td><span class="label label-info"><?=count($caps)?> <?=gettext('capabilities')?></span></td>
	</tr>
	<?php endforeach; ?>
	</tbody></table></div>
</div>

<?php elseif ($view === 'configure'): ?>
<?php if ($preview): ?>
<div class="panel panel-warning"><div class="panel-heading"><h2 class="panel-title"><i class="fa fa-exclamation-triangle"></i> <?=gettext('Confirm NIC changes')?></h2></div><div class="panel-body">
	<p><?=gettext('Review the effective profile and overrides below. Applying changes to an active interface can briefly interrupt traffic.')?></p>
	<table class="table table-condensed"><thead><tr><th><?=gettext('Interface')?></th><th><?=gettext('Checksum')?></th><th>TSO</th><th>LRO</th><th><?=gettext('VLAN acceleration')?></th></tr></thead><tbody>
	<?php foreach ($inventory as $nic): ?><tr><td><?=htmlspecialchars($nic['assignment'])?> (<?=htmlspecialchars($nic['device'])?>)</td><?php foreach (['checksum', 'tso', 'lro', 'vlan'] as $setting): ?><td><?=htmlspecialchars($_POST["{$nic['id']}_{$setting}"] ?? 'inherit')?></td><?php endforeach; ?></tr><?php endforeach; ?>
	</tbody></table>
	<form method="post"><?php foreach ($_POST as $name => $value): if ($name === 'action' || is_array($value)) continue; ?><input type="hidden" name="<?=htmlspecialchars($name)?>" value="<?=htmlspecialchars($value)?>"><?php endforeach; ?><button class="btn btn-warning" name="action" value="apply"><i class="fa fa-check"></i> <?=gettext('Confirm and apply')?></button> <a class="btn btn-default" href="interfaces_nic_settings.php?view=configure"><?=gettext('Cancel')?></a></form>
</div></div>
<?php else: ?>
<form method="post">
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><i class="fa fa-sliders"></i> <?=gettext('Profile and per-NIC overrides')?></h2></div>
	<div class="panel-body">
		<div class="form-group"><label for="profile"><?=gettext('Default profile for new and inherited settings')?></label>
		<select class="form-control" id="profile" name="profile"><?php foreach ($profile_labels as $value => $label): ?><option value="<?=$value?>" <?=$active_profile === $value ? 'selected' : ''?>><?=htmlspecialchars($label)?></option><?php endforeach; ?></select></div>
		<div class="checkbox"><label><input type="checkbox" name="hn_altq_enable" value="yes" <?=config_path_enabled('system', 'hn_altq_enable') ? 'checked' : ''?>> <?=gettext('Enable ALTQ support for vtnet/hn adapters (driver-wide; disables multiqueue; reboot required)')?></label></div>
		<div class="alert alert-warning"><i class="fa fa-exclamation-triangle"></i> <?=gettext('Applying settings to the management interface may briefly interrupt connectivity. Reboot-only driver tunables are shown in Diagnostics and are not changed silently.')?></div>
	</div>
</div>
<?php foreach ($inventory as $nic): $saved = config_get_path("system/nicsettings/adapters/{$nic['id']}", []); ?>
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=htmlspecialchars($nic['assignment'])?> <small><?=htmlspecialchars($nic['device'])?> &middot; <?=htmlspecialchars($nic['driver'])?></small></h2></div>
	<div class="panel-body"><div class="row">
	<?php foreach (['checksum' => gettext('Checksum offload'), 'tso' => gettext('TCP segmentation offload'), 'lro' => gettext('Large receive offload'), 'vlan' => gettext('VLAN hardware acceleration')] as $setting => $label): ?>
		<div class="col-sm-3"><div class="form-group"><label><?=htmlspecialchars($label)?></label><select class="form-control" name="<?=$nic['id']?>_<?=$setting?>"><?php foreach ($choices as $value => $choice): ?><option value="<?=$value?>" <?=($saved[$setting] ?? 'inherit') === $value ? 'selected' : ''?>><?=htmlspecialchars($choice)?></option><?php endforeach; ?></select><small class="text-muted"><?=gettext('Effective')?>: <?=htmlspecialchars($nic['effective'][$setting])?></small></div></div>
	<?php endforeach; ?>
	</div></div>
	<div class="panel-body"><div class="row"><div class="col-sm-4"><div class="form-group"><label><?=gettext('MTU override')?></label><input class="form-control" type="number" min="576" max="16384" name="<?=$nic['id']?>_mtu" value="<?=htmlspecialchars((string)($saved['mtu'] ?? ''))?>" placeholder="<?=htmlspecialchars((string)($nic['mtu'] ?? 1500))?>"><small class="text-muted"><?=gettext('Leave empty to use the interface default. Jumbo MTU is accepted only when the driver reports support.')?></small></div></div><div class="col-sm-4"><div class="form-group"><label><?=gettext('Wake-on-LAN magic packet')?></label><select class="form-control" name="<?=$nic['id']?>_wol"><?php foreach ($choices as $value => $choice): ?><option value="<?=$value?>" <?=($saved['wol'] ?? 'inherit') === $value ? 'selected' : ''?> <?=!isset($nic['caps']['wolmagic']) && $value !== 'inherit' ? 'disabled' : ''?>><?=htmlspecialchars($choice)?></option><?php endforeach; ?></select><small class="text-muted"><?=isset($nic['caps']['wolmagic']) ? gettext('Supported by this adapter') : gettext('Fixed or unsupported')?></small></div></div></div></div>
</div>
<?php endforeach; ?>
<button class="btn btn-primary" name="action" value="preview"><i class="fa fa-eye"></i> <?=gettext('Review changes')?></button>
</form>
<?php endif; ?>

<?php elseif ($view === 'recommendations'): ?>
<div class="panel panel-default"><div class="panel-heading"><h2 class="panel-title"><i class="fa fa-lightbulb-o"></i> <?=gettext('Firewall-aware recommendations')?></h2></div><div class="panel-body">
	<div class="alert alert-info"><strong><?=gettext('Firewall Balanced is recommended.')?></strong> <?=gettext('It disables LRO and TSO to preserve firewall visibility and predictable forwarding while retaining checksum and VLAN acceleration where the driver supports them.')?></div>
	<?php foreach ($inventory as $nic): ?>
	<h3><?=htmlspecialchars($nic['assignment'])?> <small><?=htmlspecialchars($nic['device'])?></small></h3><ul>
		<?php if (in_array($nic['driver'], ['re'], true)): ?><li><?=gettext('Realtek adapter detected: disable checksum offload if unexplained corruption, drops, or connectivity problems occur.')?></li><?php endif; ?>
		<?php if (in_array($nic['driver'], ['vtnet', 'ena', 'hn'], true)): ?><li><?=gettext('Virtual adapter detected: FreeSense retains driver-specific safeguards; multiqueue changes may require rebooting.')?></li><?php endif; ?>
		<li><?=gettext('Use Packet Capture mode temporarily when validating traffic because hardware offloads can change what capture tools observe.')?></li>
		<li><?=gettext('Do not use jumbo MTU unless every device across the relevant path supports it.')?></li>
	</ul>
	<?php endforeach; ?>
	<a class="btn btn-primary" href="interfaces_nic_settings.php?view=configure"><i class="fa fa-eye"></i> <?=gettext('Preview configuration')?></a>
</div></div>

<?php else: ?>
<?php foreach ($inventory as $nic): $controls = nic_ui_driver_controls($nic); ?>
<div class="panel panel-default"><div class="panel-heading"><h2 class="panel-title"><i class="fa fa-stethoscope"></i> <?=htmlspecialchars($nic['assignment'])?> &middot; <?=htmlspecialchars($nic['device'])?></h2></div>
<div class="panel-body"><div class="row"><div class="col-sm-4"><strong><?=gettext('Driver')?></strong><br><?=htmlspecialchars($nic['driver'])?></div><div class="col-sm-4"><strong><?=gettext('Supported capabilities')?></strong><br><?=htmlspecialchars(implode(', ', array_keys($nic['caps'] ?? [])))?></div><div class="col-sm-4"><strong><?=gettext('Enabled capabilities')?></strong><br><?=htmlspecialchars(implode(', ', array_keys($nic['encaps'] ?? [])))?></div></div></div>
<?php if ($controls): ?><table class="table table-striped"><thead><tr><th><?=gettext('Validated driver control')?></th><th><?=gettext('Current value')?></th><th><?=gettext('Apply behavior')?></th></tr></thead><tbody><?php foreach ($controls as $control): ?><tr><td><code><?=htmlspecialchars($control['mib'])?></code></td><td><?=htmlspecialchars($control['value'])?></td><td><?=htmlspecialchars($control['apply'])?></td></tr><?php endforeach; ?></tbody></table><?php else: ?><div class="panel-body text-muted"><?=gettext('No curated driver-specific controls were detected. Portable settings remain available.')?></div><?php endif; ?>
</div>
<?php endforeach; ?>
<?php endif; ?>

<?php include('foot.inc'); ?>
