<?php
/*
 * interfaces_nic_settings.inc
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
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

/*
 * Logic of Interfaces > NIC Settings, shared by the page and the REST API
 * (restapi/routes_nicsettings.inc): the adapter inventory and the three
 * saves (one adapter, the recommended tuning, the profile).
 *
 * config.xml: system/nicsettings (profile, checksum_default, adapters/<id>
 * with the offload settings, mtu and tuning) and system/hn_altq_enable.
 */

require_once('config.inc');
require_once('interfaces.inc');
require_once('util.inc');

/* The offload settings with their labels, in page order. */
function nic_ui_setting_labels(): array {
	return [
		'checksum' => gettext('Checksum offload'),
		'tso' => gettext('TCP segmentation offload (TSO)'),
		'lro' => gettext('Large receive offload (LRO)'),
		'vlan' => gettext('VLAN hardware acceleration'),
		'wol' => gettext('Wake-on-LAN magic packet'),
		'ipsec' => gettext('Inline IPsec offload (experimental)'),
	];
}

/* The driver tuning settings with their labels. */
function nic_ui_tuning_labels(): array {
	return [
		'fc' => gettext('Flow control'),
		'aim' => gettext('Interrupt moderation'),
		'tx_abdicate' => gettext('TX abdicate'),
		'queues' => gettext('Queues'),
		'rxds' => gettext('Receive descriptors'),
		'txds' => gettext('Transmit descriptors'),
	];
}

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

/* Every physical adapter with its capabilities, effective settings and driver detection, by device name. */
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

/* The adapter with this id (nic_<mac>) or device name, or null. */
function nic_ui_find(array $inventory, string $id): ?array {
	foreach ($inventory as $nic) {
		if ($nic['id'] === $id || (string)$nic['device'] === $id) {
			return $nic;
		}
	}
	return null;
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

/* What is saved for one adapter, in the shape nic_ui_save_adapter() takes. */
function nic_ui_adapter_values(array $nic): array {
	$saved = nic_settings_saved($nic['id']);
	$settings = [];
	foreach (array_keys(nic_ui_setting_labels()) as $setting) {
		$value = $saved[$setting] ?? 'inherit';
		$settings[$setting] = in_array($value, ['inherit', 'on', 'off'], true) ? $value : 'inherit';
	}
	return [
		'settings' => $settings,
		'mtu' => isset($saved['mtu']) ? (string)$saved['mtu'] : '',
		'tuning' => nic_tuning_saved($saved),
	];
}

/*
 * Validates and saves one adapter, then applies it. $values holds every
 * setting: settings (inherit|on|off each), mtu ("" for the interface
 * default) and tuning (setting => value; missing or "" keeps the driver
 * default). Returns input_errors, reboot (a boot setting changed) and message.
 */
function nic_ui_save_adapter(array $nic, array $values, int $ncpu): array {
	$labels = nic_ui_setting_labels();
	$tuning_labels = nic_ui_tuning_labels();
	$errors = [];
	foreach (array_keys($labels) as $setting) {
		$value = $values['settings'][$setting] ?? 'inherit';
		if (!in_array($value, ['inherit', 'on', 'off'], true)) {
			$errors[] = sprintf(gettext('Invalid %1$s setting for %2$s.'), $setting, $nic['device']);
		} elseif ($value !== 'inherit' && !nic_ui_supported($nic, $setting)) {
			$errors[] = sprintf(gettext('%1$s does not support %2$s.'), $nic['device'], $labels[$setting]);
		}
	}
	$mtu = trim((string)($values['mtu'] ?? ''));
	if ($mtu !== '' && (!ctype_digit($mtu) || (int)$mtu < 576 || (int)$mtu > 16384)) {
		$errors[] = sprintf(gettext('MTU for %s must be between 576 and 16384.'), $nic['device']);
	} elseif ($mtu !== '' && (int)$mtu > 1500 && !isset($nic['caps']['jumbomtu'])) {
		$errors[] = sprintf(gettext('%s does not report jumbo MTU capability.'), $nic['device']);
	}
	$tuning_new = [];
	foreach ((array)($values['tuning'] ?? []) as $setting => $value) {
		$value = trim((string)$value);
		if (!isset($tuning_labels[$setting])) {
			$errors[] = sprintf(gettext('Unknown tuning setting %s.'), (string)$setting);
			continue;
		}
		$error = nic_tuning_validate($setting, $value, $nic['tuning'], $ncpu);
		if ($error !== null) {
			$errors[] = sprintf('%1$s: %2$s', $tuning_labels[$setting], $error);
		} elseif ($value !== '') {
			$tuning_new[$setting] = $value;
		}
	}
	if ($errors) {
		return ['input_errors' => $errors, 'reboot' => false, 'message' => null];
	}

	$tuning_old = nic_tuning_saved(nic_settings_saved($nic['id']));
	$path = nic_ui_store_identity($nic);
	foreach (array_keys($labels) as $setting) {
		config_set_path("{$path}/{$setting}", $values['settings'][$setting] ?? 'inherit');
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
	return ['input_errors' => [], 'reboot' => $reboot,
	    'message' => sprintf(gettext('Settings for %s were saved and applied.'), $nic['device']) .
	    ($reboot ? ' ' . gettext('Queue and descriptor changes take effect after a reboot.') : '')];
}

/* Adds the active profile's recommended tuning to one adapter and applies it. */
function nic_ui_apply_recommended(array $nic): array {
	$recommended = nic_tuning_recommend($nic['tuning'], config_get_path('system/nicsettings/profile', 'firewall'));
	if (!$recommended) {
		return ['input_errors' => [sprintf(gettext('There is nothing to recommend for %s under this profile.'), $nic['device'])],
		    'reboot' => false, 'message' => null, 'applied' => []];
	}
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
	return ['input_errors' => [], 'reboot' => $reboot, 'applied' => $recommended,
	    'message' => sprintf(gettext('The recommended tuning was applied to %s.'), $nic['device']) .
	    ($reboot ? ' ' . gettext('Queue and descriptor changes take effect after a reboot.') : '')];
}

/*
 * Saves the profile, the imported checksum override (only ever cleared: it is
 * set by a config import) and ALTQ on vtnet/hn, then applies every adapter.
 */
function nic_ui_save_profile(array $values, array $inventory): array {
	$profile = (string)($values['profile'] ?? 'firewall');
	if (!isset(nic_settings_profiles()[$profile])) {
		return ['input_errors' => [gettext('Invalid NIC profile.')], 'reboot' => false, 'message' => null];
	}
	$old_altq = config_path_enabled('system', 'hn_altq_enable');
	config_set_path('system/nicsettings/profile', $profile);
	if (empty($values['checksum_default_off'])) {
		config_del_path('system/nicsettings/checksum_default');
	}
	if (!empty($values['hn_altq_enable'])) {
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
	return ['input_errors' => [], 'reboot' => $reboot,
	    'message' => gettext('The profile was saved and applied to every adapter that inherits it.') .
	    ($reboot ? ' ' . gettext('The ALTQ change takes effect after a reboot.') : '')];
}
