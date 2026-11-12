<?php
/*
 * restapi/routes_nicsettings.inc
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
 * REST API v1 routes for Interfaces > NIC Settings (area interfaces): the
 * profile, every physical adapter with its offloads, MTU, driver detection
 * and tuning, and the recommended tuning of the active profile.
 *
 * Writes go through the page's functions (interfaces_nic_settings.inc), so
 * validation is the GUI's and, like the page, changes are applied at once.
 * An adapter is addressed by its id (nic_<mac>) or its device name (igb0).
 * A PUT changes only what it sends; "tuning" values replace one setting
 * each ("" returns it to the driver default).
 */

require_once('restapi/framework.inc');

function restapi_routes_nicsettings() {
	$base = '/v1/interfaces/nic-settings';
	$page = 'interfaces_nic_settings.php';
	$profile_body = array('type' => 'object', 'description' => 'profile (firewall|throughput|latency|capture), hn_altq_enable ' .
	    '(boolean: ALTQ on vtnet/hn, turns multiqueue off, after a reboot), checksum_default_off (boolean: false clears the ' .
	    'checksum-off carried over from an imported configuration; it cannot be set). Omitted fields keep their value. Every ' .
	    'adapter that inherits the profile is reconfigured at once.');
	$adapter_body = array('type' => 'object', 'description' => 'settings: {checksum, tso, lro, vlan, wol, ipsec: inherit|on|off} ' .
	    '(only those the adapter supports may be on or off); mtu ("" for the interface default, 576-16384); tuning: {fc, aim, ' .
	    'tx_abdicate, queues, rxds, txds}, only the controls the adapter lists, "" for the driver default. Omitted fields keep ' .
	    'their value. Applied at once; queue and descriptor changes need a reboot (reboot_required).');
	return array(
		restapi_route('GET', $base, 'restapi_h_nic_list', array('page' => $page, 'area' => 'interfaces',
		    'summary' => 'The NIC profile and every physical adapter with its offloads, driver detection, tuning and recommendations')),
		restapi_route('PUT', $base, 'restapi_h_nic_profile_set', array('page' => $page, 'area' => 'interfaces', 'write' => true,
		    'body' => $profile_body, 'summary' => 'Change the NIC profile or ALTQ on virtual adapters (applied at once)')),
		restapi_route('GET', "{$base}/adapters/{id}", 'restapi_h_nic_get', array('page' => $page, 'area' => 'interfaces',
		    'summary' => 'One adapter by id (nic_<mac>) or device name')),
		restapi_route('PUT', "{$base}/adapters/{id}", 'restapi_h_nic_set', array('page' => $page, 'area' => 'interfaces',
		    'write' => true, 'body' => $adapter_body,
		    'summary' => 'Change one adapter\'s offloads, MTU or driver tuning (applied at once; omitted fields keep their value)')),
		restapi_route('POST', "{$base}/adapters/{id}/apply-recommended", 'restapi_h_nic_recommended', array('page' => $page,
		    'area' => 'interfaces', 'write' => true,
		    'summary' => 'Apply the active profile\'s recommended driver tuning to one adapter (409 when there is none)')),
	);
}

/* ------------------------------------------------------------------ */
/* Pure helpers (no config access)                                     */
/* ------------------------------------------------------------------ */

/*
 * pure: a PUT body for one adapter merged over what is saved. Throws on
 * unknown fields or wrong types; values are checked by nic_ui_save_adapter().
 */
function restapi_nic_merge(array $body, array $current, array $setting_names, array $tuning_names): array {
	$values = $current;
	foreach ($body as $key => $value) {
		if ($key === 'settings' || $key === 'tuning') {
			if (!is_array($value) || ($value !== array() && array_is_list($value))) {
				throw new RestApiError(400, 'invalid_field', "Field \"{$key}\" must be an object.");
			}
			$names = ($key === 'settings') ? $setting_names : $tuning_names;
			foreach ($value as $name => $item) {
				if (!in_array((string)$name, $names, true)) {
					throw new RestApiError(400, 'unknown_field', "Unknown field \"{$key}.{$name}\".");
				}
				if (!is_string($item) && !is_int($item)) {
					throw new RestApiError(400, 'invalid_field', "Field \"{$key}.{$name}\" must be a string.");
				}
				$values[$key][(string)$name] = (string)$item;
			}
		} elseif ($key === 'mtu') {
			if (!is_string($value) && !is_int($value)) {
				throw new RestApiError(400, 'invalid_field', 'Field "mtu" must be a string or a number.');
			}
			$values['mtu'] = (string)$value;
		} else {
			throw new RestApiError(400, 'unknown_field', "Unknown field \"{$key}\".");
		}
	}
	return $values;
}

/* pure: a map for JSON: an empty PHP array would encode as [] instead of {} */
function restapi_nic_map(array $map) {
	return $map ? $map : new stdClass();
}

/* pure: one adapter as the API shows it, from an inventory entry */
function restapi_nic_out(array $nic, array $saved_values, string $profile, int $ncpu, string $vm_guest): array {
	$detect = $nic['tuning'];
	$supported = array();
	foreach (array_keys(nic_ui_setting_labels()) as $setting) {
		$supported[$setting] = nic_ui_supported($nic, $setting);
	}
	$recommended = nic_tuning_recommend($detect, $profile);
	$choices = array();
	foreach (array_keys($detect['controls']) as $setting) {
		$options = nic_tuning_choices($setting, $detect['driver']);
		if ($options) {
			$choices[$setting] = restapi_svc_choices($options);
		}
	}
	return array(
		'id' => $nic['id'],
		'device' => (string)$nic['device'],
		'driver' => (string)$nic['driver'],
		'assignment' => $nic['assigned'] ? (string)$nic['assignment'] : null,
		'mac' => (string)($nic['hwaddr'] ?? $nic['macaddr'] ?? $nic['mac'] ?? ''),
		'up' => !empty($nic['up']),
		'media' => isset($nic['media']) ? (string)$nic['media'] : null,
		'mtu' => isset($nic['mtu']) ? (int)$nic['mtu'] : null,
		'supported' => $supported,
		'effective' => array_intersect_key($nic['effective'], $supported),
		'saved' => array('settings' => $saved_values['settings'], 'mtu' => $saved_values['mtu'], 'tuning' => restapi_nic_map($saved_values['tuning'])),
		'hardware' => array(
			'description' => $detect['desc'],
			'pci' => restapi_nic_map($detect['pci']),
			'iflib' => $detect['iflib'],
			'rx_queues' => $detect['rxqs'],
			'tx_queues' => $detect['txqs'],
			'queue_cpus' => array_values($detect['queue_cpus']),
			'interrupt_vectors' => $detect['msix'],
			'counters' => restapi_nic_map($detect['counters']),
		),
		'tuning' => array(
			'controls' => restapi_nic_map($detect['controls']),
			'apply' => restapi_nic_map(array_map(function ($control) {
				return $control['apply'];
			}, array_intersect_key(nic_tuning_controls(), $detect['controls']))),
			'choices' => restapi_nic_map($choices),
			'recommended' => restapi_nic_map($recommended),
			'differences' => restapi_nic_map(nic_tuning_differences($detect, $recommended)),
		),
		'notes' => nic_tuning_notes($detect, $ncpu, $vm_guest),
	);
}

/* ------------------------------------------------------------------ */
/* Handlers                                                            */
/* ------------------------------------------------------------------ */

function restapi_nic_context() {
	return array(max(1, (int)get_single_sysctl('hw.ncpu')), (string)get_single_sysctl('kern.vm_guest'),
	    (string)config_get_path('system/nicsettings/profile', 'firewall'));
}

function restapi_nic_lookup(array $inventory, $id) {
	$nic = nic_ui_find($inventory, (string)$id);
	if ($nic === null) {
		throw new RestApiError(404, 'not_found', 'No such network adapter.');
	}
	return $nic;
}

function restapi_nic_one(array $nic) {
	list($ncpu, $vm_guest, $profile) = restapi_nic_context();
	return restapi_nic_out($nic, nic_ui_adapter_values($nic), $profile, $ncpu, $vm_guest);
}

function restapi_h_nic_list($req) {
	list($ncpu, $vm_guest, $profile) = restapi_nic_context();
	$adapters = array();
	foreach (nic_ui_inventory() as $nic) {
		$adapters[] = restapi_nic_out($nic, nic_ui_adapter_values($nic), $profile, $ncpu, $vm_guest);
	}
	return array('data' => array(
		'profile' => $profile,
		'profiles' => nic_settings_profiles(),
		'checksum_default_off' => (config_get_path('system/nicsettings/checksum_default') === 'off'),
		'hn_altq_enable' => config_path_enabled('system', 'hn_altq_enable'),
		'adapters' => $adapters,
	));
}

function restapi_h_nic_profile_set($req) {
	$current = array(
		'profile' => (string)config_get_path('system/nicsettings/profile', 'firewall'),
		'checksum_default_off' => (config_get_path('system/nicsettings/checksum_default') === 'off'),
		'hn_altq_enable' => config_path_enabled('system', 'hn_altq_enable'),
	);
	$values = $current;
	foreach ($req['body'] as $key => $value) {
		if ($key === 'profile') {
			if (!is_string($value)) {
				throw new RestApiError(400, 'invalid_field', 'Field "profile" must be a string.');
			}
			$values['profile'] = $value;
		} elseif ($key === 'hn_altq_enable' || $key === 'checksum_default_off') {
			if (!is_bool($value)) {
				throw new RestApiError(400, 'invalid_field', "Field \"{$key}\" must be a boolean.");
			}
			if ($key === 'checksum_default_off' && $value && !$current['checksum_default_off']) {
				throw new RestApiError(422, 'not_settable', 'checksum_default_off can only be cleared; turn checksum offload off per adapter instead.');
			}
			$values[$key] = $value;
		} else {
			throw new RestApiError(400, 'unknown_field', "Unknown field \"{$key}\".");
		}
	}
	$rv = nic_ui_save_profile($values, nic_ui_inventory());
	if ($rv['input_errors']) {
		throw restapi_validation_error($rv['input_errors']);
	}
	$out = restapi_h_nic_list($req);
	$out['data']['reboot_required'] = $rv['reboot'];
	return $out;
}

function restapi_h_nic_get($req) {
	return array('data' => restapi_nic_one(restapi_nic_lookup(nic_ui_inventory(), $req['params']['id'])));
}

function restapi_h_nic_set($req) {
	$inventory = nic_ui_inventory();
	$nic = restapi_nic_lookup($inventory, $req['params']['id']);
	/* like the page: drop saved values the adapter no longer supports, so they do not block the save */
	$current = nic_ui_adapter_values($nic);
	$current['tuning'] = array_intersect_key($current['tuning'], $nic['tuning']['controls']);
	foreach (array_keys($current['settings']) as $setting) {
		if (!nic_ui_supported($nic, $setting)) {
			$current['settings'][$setting] = 'inherit';
		}
	}
	$values = restapi_nic_merge($req['body'], $current, array_keys(nic_ui_setting_labels()), array_keys(nic_ui_tuning_labels()));
	list($ncpu) = restapi_nic_context();
	$rv = nic_ui_save_adapter($nic, $values, $ncpu);
	if ($rv['input_errors']) {
		throw restapi_validation_error($rv['input_errors']);
	}
	$nic = restapi_nic_lookup(nic_ui_inventory(true), $nic['id']);
	return array('data' => restapi_nic_one($nic) + array('reboot_required' => $rv['reboot']));
}

function restapi_h_nic_recommended($req) {
	$nic = restapi_nic_lookup(nic_ui_inventory(), $req['params']['id']);
	$rv = nic_ui_apply_recommended($nic);
	if ($rv['input_errors']) {
		throw new RestApiError(409, 'nothing_to_apply', html_entity_decode(strip_tags($rv['input_errors'][0]), ENT_QUOTES));
	}
	$nic = restapi_nic_lookup(nic_ui_inventory(true), $nic['id']);
	return array('data' => restapi_nic_one($nic) + array('applied' => restapi_nic_map($rv['applied']), 'reboot_required' => $rv['reboot']));
}
