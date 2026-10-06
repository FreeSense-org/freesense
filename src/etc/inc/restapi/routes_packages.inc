<?php
/*
 * restapi/routes_packages.inc
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
 * REST API v1 routes for the Package Manager and the system version (area
 * packages, read only for now). The lists come from get_pkg_info() like the
 * Installed and Available Packages pages, the version check from
 * get_system_pkg_version() like System > Update. Both may contact the package
 * repository; while packages are being installed or removed (packagelock) the
 * lists answer 409 like the pages refuse to show them.
 */

require_once('restapi/framework.inc');

function restapi_routes_packages() {
	return array(
		restapi_route('GET', '/v1/packages/installed', 'restapi_h_pkg_installed', array(
			'page' => 'pkg_mgr_installed.php', 'area' => 'packages',
			'query' => array('offline' => 'true: use only local information (no repository access; available versions may be unknown)'),
			'summary' => 'Installed packages with installed and available version and status')),
		restapi_route('GET', '/v1/packages/available', 'restapi_h_pkg_available', array(
			'page' => 'pkg_mgr.php', 'area' => 'packages',
			'summary' => 'Packages that can be installed (from the package repository)')),
		restapi_route('GET', '/v1/system/version', 'restapi_h_system_version', array(
			'page' => 'pkg_mgr_install.php', 'area' => 'packages',
			'query' => array('refresh' => 'true: check the update repository now instead of using the cached result'),
			'summary' => 'Installed and latest system version and whether an update is available')),
	);
}

/* ------------------------------------------------------------------ */
/* Pure helpers (no config access)                                     */
/* ------------------------------------------------------------------ */

function restapi_pkg_flag(array $query, $name) {
	return in_array(strtolower((string)($query[$name] ?? '')), array('1', 'true', 'yes'), true);
}

/* The status the Installed Packages page shows, as a code. */
function restapi_pkg_status(array $pkg, $compare) {
	if (isset($pkg['broken'])) {
		return 'not_installed';
	}
	if (isset($pkg['obsolete'])) {
		return 'not_in_repository';
	}
	if (isset($pkg['installed_version']) && isset($pkg['version'])) {
		switch ($compare) {
			case '>':
				return 'newer_than_available';
			case '<':
				return 'upgrade_available';
			case '=':
				return 'up_to_date';
			default:
				return 'compare_error';
		}
	}
	return 'unknown';
}

/* Plain text of a package description (the pages print it as HTML). */
function restapi_pkg_text($text) {
	return trim(html_entity_decode(strip_tags((string)$text), ENT_QUOTES));
}

/* A get_pkg_info() entry as the API shows it. */
function restapi_pkg_out(array $pkg, $compare = null) {
	$meta = is_array($pkg['freesense'] ?? null) ? $pkg['freesense'] : array();
	$out = array(
		'name' => (string)$pkg['name'],
		'shortname' => (string)($pkg['shortname'] ?? $pkg['name']),
		'display_name' => (string)($meta['display_name'] ?? $pkg['shortname'] ?? $pkg['name']),
		'category' => (string)($meta['category'] ?? ''),
		'resource_profile' => (string)($meta['resource_profile'] ?? ''),
		'capabilities' => array_values(array_map('strval', (array)($meta['capabilities'] ?? array()))),
		'description' => restapi_pkg_text($pkg['desc'] ?? ''),
		'version' => isset($pkg['version']) ? (string)$pkg['version'] : null,
		'www' => (isset($pkg['www']) && ($pkg['www'] !== 'UNKNOWN')) ? (string)$pkg['www'] : null,
		'dependencies' => array_values(array_map('strval', array_keys((array)($pkg['deps'] ?? array())))),
	);
	if ($compare !== false) {
		$out['installed_version'] = isset($pkg['installed_version']) ? (string)$pkg['installed_version'] : null;
		$out['status'] = restapi_pkg_status($pkg, $compare);
	}
	return $out;
}

/* get_system_pkg_version() as the API shows it. */
function restapi_version_out(array $v, $running) {
	$compare = $v['pkg_version_compare'] ?? null;
	$status = array('<' => 'update_available', '=' => 'up_to_date', '>' => 'newer_than_available')[$compare] ?? 'unknown';
	return array(
		'running_version' => (string)$running,
		'installed_version' => (string)$v['installed_version'],
		'latest_version' => (string)$v['version'],
		'update_available' => ($compare === '<'),
		'status' => $status,
	);
}

/* ------------------------------------------------------------------ */
/* Handlers                                                            */
/* ------------------------------------------------------------------ */

function restapi_pkg_not_busy() {
	if (is_subsystem_dirty('packagelock')) {
		throw new RestApiError(409, 'packages_busy', 'Packages are being installed or removed; try again later.');
	}
}

/*
 * get_pkg_info() reports errors through the global $input_errors and
 * update_status() (which flushes an output buffer): give it one and drop
 * what it prints.
 */
function restapi_pkg_info($pkgs, $offline, $installed_only) {
	global $input_errors;

	$input_errors = array();
	ob_start();
	try {
		$info = get_pkg_info($pkgs, $offline, $installed_only);
	} finally {
		ob_end_clean();
	}
	if (empty($info) && !empty($input_errors)) {
		$messages = array_values(array_filter(array_map('trim', array_map('strval', $input_errors))));
		throw new RestApiError(502, 'repository_error', 'The package list could not be read.', array('messages' => $messages));
	}
	return is_array($info) ? $info : array();
}

function restapi_h_pkg_installed($req) {
	restapi_pkg_not_busy();
	$out = array();
	foreach (restapi_pkg_info('all', restapi_pkg_flag($req['query'], 'offline'), true) as $pkg) {
		if (empty($pkg['name'])) {
			continue;
		}
		$compare = (isset($pkg['installed_version']) && isset($pkg['version'])) ?
		    pkg_version_compare($pkg['installed_version'], $pkg['version']) : null;
		$out[] = restapi_pkg_out($pkg, $compare);
	}
	return array('data' => $out);
}

function restapi_h_pkg_available($req) {
	restapi_pkg_not_busy();
	$out = array();
	foreach (restapi_pkg_info('all', false, false) as $pkg) {
		/* Like the page: installed packages and AutoConfigBackup are not offered. */
		if (empty($pkg['name']) || isset($pkg['installed']) || (($pkg['shortname'] ?? '') == 'AutoConfigBackup')) {
			continue;
		}
		$out[] = restapi_pkg_out($pkg, false);
	}
	return array('data' => $out);
}

function restapi_h_system_version($req) {
	ob_start();
	try {
		$v = get_system_pkg_version(!restapi_pkg_flag($req['query'], 'refresh'));
	} finally {
		ob_end_clean();
	}
	if (!empty($v['pkg_busy'])) {
		throw new RestApiError(409, 'update_busy', 'The update system is busy; try again later.');
	}
	if (isset($v['pkg_version_error']) || !isset($v['installed_version'])) {
		throw new RestApiError(502, 'version_check_failed', 'Unable to check for updates.',
		    array('messages' => array((string)($v['pkg_version_error'] ?? 'unknown error'))));
	}
	$out = restapi_version_out($v, trim((string)@file_get_contents('/etc/version')));
	$out['channel'] = (string)pkg_get_repo_name(config_get_path('system/pkg_repo_conf_path'));
	return array('data' => $out);
}
