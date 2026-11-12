<?php
/*
 * system_advanced_performance.inc
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
 * Logic for System > Advanced > Performance, shared by the page and the REST
 * API. It owns system/netisr_threads and system/nmbclusters (netperf.inc);
 * the CPU, power, crypto and mitigation fields it shows are saved by
 * saveSystemAdvancedMisc(), which also serves the REST misc route.
 */

require_once('config.inc');
require_once('netperf.inc');
require_once('system_advanced_misc.inc');

/* The page's settings: the network ones plus the hardware fields of the Miscellaneous form. */
function getSystemAdvancedPerformance() {
	$pconfig = netperf_settings();
	$misc = getSystemAdvancedMisc();
	foreach (getSystemAdvancedHardwareFields() as $field) {
		$pconfig[$field] = $misc[$field] ?? null;
	}
	return $pconfig;
}

/* Validation of the network settings only; the hardware fields are checked by saveSystemAdvancedMisc(). */
function validateSystemAdvancedPerformance(array $post) {
	$errors = array();
	if (!in_array($post['netisr_threads'] ?? 'auto', array('auto', 'single'), true)) {
		$errors[] = gettext('Choose how received packets are spread over the CPUs.');
	}
	$error = netperf_validate_nmbclusters(trim((string)($post['nmbclusters'] ?? '')));
	if ($error !== null) {
		$errors[] = $error;
	}
	return $errors;
}

/*
 * Saves the page. $post holds the network settings and, when $hardware is
 * true, the hardware fields as the page posts them (a missing checkbox is off).
 * Returns input_errors, the reboot reasons and the apply result.
 */
function saveSystemAdvancedPerformance(array $post, $hardware = true) {
	$rv = array('input_errors' => validateSystemAdvancedPerformance($post), 'reboot' => array(), 'retval' => 0,
	    'changes_applied' => false);
	if ($rv['input_errors']) {
		return $rv;
	}

	if ($hardware) {
		$old_pti = config_path_enabled('system', 'pti_disabled');
		$misc_post = getSystemAdvancedMiscPost();
		foreach (getSystemAdvancedHardwareFields() as $field) {
			unset($misc_post[$field]);
			if (array_key_exists($field, $post)) {
				$misc_post[$field] = $post[$field];
			}
		}
		$misc = saveSystemAdvancedMisc($misc_post);
		if (!empty($misc['input_errors'])) {
			$rv['input_errors'] = $misc['input_errors'];
			return $rv;
		}
		$rv['retval'] = $misc['retval'];
		$rv['changes_applied'] = true;
		if (!empty($misc['reboot-hwpstate'])) {
			$rv['reboot'][] = 'speed_shift';
		}
		if (!empty($misc['reboot-microcode'])) {
			$rv['reboot'][] = 'microcode';
		}
		if ($old_pti !== config_path_enabled('system', 'pti_disabled')) {
			$rv['reboot'][] = 'pti';
		}
	}

	$old_lines = netperf_current_loader_lines();
	if (($post['netisr_threads'] ?? 'auto') === 'single') {
		config_set_path('system/netisr_threads', 'single');
	} else {
		config_del_path('system/netisr_threads');
	}
	$clusters = trim((string)($post['nmbclusters'] ?? ''));
	if ($clusters === '') {
		config_del_path('system/nmbclusters');
	} else {
		config_set_path('system/nmbclusters', $clusters);
	}
	if (netperf_current_loader_lines() !== $old_lines) {
		write_config(gettext('Changed the packet processing and network buffer settings.'));
		setup_loader_settings();
		$rv['reboot'][] = 'network';
		$rv['changes_applied'] = true;
	}
	return $rv;
}
