<?php
/*
 * restapi/routes_update.inc
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
 * REST API v1 routes of the WebUI Update Center:
 *
 *  - ZFS boot environments (System > Boot Environments, area
 *    system.settings), through the page's shared functions
 *    (system_boot_environments.inc) and freesense-be. Changes need an
 *    administrator, like the other operations. Snapshots of boot
 *    environments are listed too; like on the page they cannot be activated
 *    or renamed, only deleted.
 *  - The update preflight (System > Update, area packages): what the update
 *    needs, from the cached version check and local facts; it never starts
 *    anything.
 *  - The release notes of the latest release on the configured channel, from
 *    the release document the download publisher (freesense-os-base
 *    scripts/publish_download.py) writes to pkg.freesense.org and that
 *    System > Update and the website show.
 */

require_once('restapi/framework.inc');

/* Release documents, one per channel (stable, devel); arm64 has its own. */
define('RESTAPI_RELEASES_URL', 'https://pkg.freesense.org/v1/releases/');
/* Where people read the release notes of both channels. */
define('RESTAPI_RELEASE_NOTES_PAGE', 'https://freesense.org/download/#release-channels');
define('RESTAPI_RELEASES_CACHE_TTL', 3600);
define('RESTAPI_RELEASES_FETCH_TIMEOUT', 4);
/* At most this many package lines in the notes (the document has up to 200). */
define('RESTAPI_NOTES_MAX_PACKAGES', 50);
define('RESTAPI_GIB', 1073741824);

function restapi_routes_update() {
	$be_page = 'system_boot_environments.php';
	$be_area = 'system.settings';
	$admin = ' Requires an administrator (user admin or "WebCfg - All pages").';
	$name_body = array('type' => 'object', 'properties' => array(
	    'name' => array('type' => 'string', 'description' => 'Letters, digits, ".", "_" and "-"; up to 64 characters'),
	    'description' => array('type' => 'string', 'description' => 'Up to 256 characters')));
	return array(
		restapi_route('GET', '/v1/system/boot-environments', 'restapi_h_be_list', array(
			'page' => $be_page, 'area' => $be_area,
			'summary' => 'ZFS boot environments and their snapshots, newest first; meta has the pool and its free space. ' .
			    '409 no_boot_environments without ZFS/bectl')),
		restapi_route('POST', '/v1/system/boot-environments', 'restapi_h_be_create', array(
			'page' => $be_page, 'area' => $be_area, 'write' => true, 'body' => $name_body,
			'summary' => 'Save the running system as a new boot environment (like "Create snapshot" on the page); answers 201.' .
			    $admin)),
		restapi_route('POST', '/v1/system/boot-environments/{name}/activate', 'restapi_h_be_activate', array(
			'page' => $be_page, 'area' => $be_area, 'write' => true,
			'body' => array('type' => 'object', 'required' => array('confirm'), 'properties' => array(
			    'confirm' => array('type' => 'boolean', 'description' => 'Must be true'),
			    'once' => array('type' => 'boolean', 'description' => 'Only for the next boot (default false: every boot)'))),
			'summary' => 'Boot this environment from the next boot on. Snapshots and environments of an older release ' .
			    '(one-way rule) answer 409.' . $admin)),
		restapi_route('PATCH', '/v1/system/boot-environments/{name}', 'restapi_h_be_update', array(
			'page' => $be_page, 'area' => $be_area, 'write' => true, 'body' => $name_body,
			'summary' => 'Rename a boot environment and/or change its description.' . $admin)),
		restapi_route('DELETE', '/v1/system/boot-environments/{name}', 'restapi_h_be_delete', array(
			'page' => $be_page, 'area' => $be_area, 'write' => true,
			'summary' => 'Delete a boot environment or snapshot; the running and next-boot environments answer 409.' . $admin)),
		restapi_route('GET', '/v1/system/update/preflight', 'restapi_h_update_preflight', array(
			'page' => 'pkg_mgr_install.php', 'area' => 'packages',
			'summary' => 'Checks before a system update (update available, no operation running, disk space, boot environment, ' .
			    'configuration, repository): [{id, label, state pass|warn|fail, detail}]. Starts nothing.')),
		restapi_route('GET', '/v1/system/update/changelog', 'restapi_h_update_changelog', array(
			'page' => 'pkg_mgr_install.php', 'area' => 'packages',
			'summary' => 'Release notes of the latest release on the configured channel (cached for an hour)')),
	);
}

/* ------------------------------------------------------------------ */
/* Pure helpers (no config or system access)                           */
/* ------------------------------------------------------------------ */

/* 1610612736 -> "1.5 GiB" */
function restapi_bytes_text($bytes) {
	$units = array('B', 'KiB', 'MiB', 'GiB', 'TiB', 'PiB');
	$bytes = max(0, (float)$bytes);
	$i = 0;
	while (($bytes >= 1024) && ($i < count($units) - 1)) {
		$bytes /= 1024;
		$i++;
	}
	return (($i === 0) ? (string)(int)$bytes : number_format($bytes, 1, '.', '')) . ' ' . $units[$i];
}

/* A 422 for one field of the body. */
function restapi_field_error($field, $message) {
	return new RestApiError(422, 'validation_failed', 'The request failed validation.',
	    array('messages' => array($message), 'fields' => array($field => $message)));
}

/* A boot environment description (freesense-be refuses control characters and more than 256 bytes). */
function restapi_be_check_description($description) {
	if (!is_string($description) || (strlen($description) > BOOTENV_DESCRIPTION_MAX) || preg_match('/[\x00-\x1f\x7f]/', $description)) {
		throw restapi_field_error('description', sprintf(gettext('The description may hold up to %d characters and no control characters.'),
		    BOOTENV_DESCRIPTION_MAX));
	}
	return trim($description);
}

/* A new boot environment name: valid and not taken by another entry. */
function restapi_be_check_new_name($name, array $entries) {
	if (!is_string($name) || !be_valid_name($name)) {
		throw restapi_field_error('name', gettext('Invalid boot environment name.') . ' ' .
		    gettext('Letters, digits, ".", "_" and "-"; up to 64 characters.'));
	}
	foreach ($entries as $e) {
		if (($e['kind'] === 'be') && ($e['name'] === $name)) {
			throw restapi_field_error('name', gettext('Boot environment already exists.'));
		}
	}
	return $name;
}

function restapi_be_find(array $entries, $name) {
	foreach ($entries as $e) {
		if ($e['name'] === $name) {
			return $e;
		}
	}
	throw new RestApiError(404, 'not_found', 'No boot environment or snapshot with that name.');
}

/* Why an entry cannot be activated (an error), or null. */
function restapi_be_activate_refusal(array $entry) {
	if ($entry['kind'] === 'snapshot') {
		return new RestApiError(409, 'snapshot_not_bootable', 'A snapshot cannot be activated; it belongs to boot environment "' .
		    $entry['parent'] . '". Activate that boot environment instead.');
	}
	if ($entry['locked']) {
		return new RestApiError(409, 'locked', 'This boot environment holds an older release (' . $entry['version'] . '). ' .
		    'An installed release cannot go back to an older one.');
	}
	return null;
}

/* Why an entry cannot be deleted (an error), or null. */
function restapi_be_delete_refusal(array $entry) {
	if ($entry['active']) {
		return new RestApiError(409, 'boot_environment_in_use', 'The running boot environment cannot be deleted.');
	}
	if ($entry['next_boot']) {
		return new RestApiError(409, 'boot_environment_in_use', 'The boot environment used for the next boot cannot be deleted; ' .
		    'activate another one first.');
	}
	return null;
}

/*
 * The preflight checks from the facts:
 *   version    array(busy, error (string|null), installed, latest, update_available)
 *   operation_running  bool: a package operation or update is running (or packages are locked)
 *   disk       array(target "zroot" or "/", zfs, size, free) in bytes; size/free null when unknown
 *   boot_env   array(available: ZFS boot environments work, enabled: created during updates)
 *   config_writable  bool
 * Returns [{id, label, state: pass|warn|fail, detail}].
 */
function restapi_update_preflight_checks(array $f) {
	$check = function ($id, $label, $state, $detail) {
		return array('id' => $id, 'label' => $label, 'state' => $state, 'detail' => $detail);
	};
	$v = (array)($f['version'] ?? array());
	$out = array();

	if (!empty($v['busy'])) {
		$out[] = $check('update', gettext('Update available'), 'warn', gettext('The update system is busy; try again later.'));
	} elseif (!empty($v['error'])) {
		$out[] = $check('update', gettext('Update available'), 'warn', gettext('Unknown: the version check failed.'));
	} elseif (!empty($v['update_available'])) {
		$out[] = $check('update', gettext('Update available'), 'pass',
		    sprintf(gettext('Version %1$s is available (installed: %2$s).'), $v['latest'], $v['installed']));
	} else {
		$out[] = $check('update', gettext('Update available'), 'warn',
		    sprintf(gettext('The system is already up to date (%s).'), $v['installed'] ?? ''));
	}

	$out[] = empty($f['operation_running']) ?
	    $check('operation', gettext('No operation running'), 'pass', gettext('No package operation or update is running.')) :
	    $check('operation', gettext('No operation running'), 'fail', gettext('A package operation or update is running; wait until it has finished.'));

	$disk = (array)($f['disk'] ?? array());
	$be = (array)($f['boot_env'] ?? array());
	$free = $disk['free'] ?? null;
	if ($free === null) {
		$out[] = $check('disk', gettext('Disk space'), 'warn', gettext('The free disk space could not be determined.'));
	} else {
		$where = (string)($disk['target'] ?? '/');
		$detail = sprintf(gettext('%1$s free on %2$s.'), restapi_bytes_text($free), $where);
		/* With boot environments the update refuses to start below 2 GiB or 15% of the pool (FreeSense-upgrade be_space_check). */
		$needed = 2 * RESTAPI_GIB;
		$be_update = !empty($disk['zfs']) && !empty($be['available']) && !empty($be['enabled']);
		if ($be_update && isset($disk['size'])) {
			$needed = max($needed, intdiv((int)$disk['size'] * 15, 100));
		}
		if ($free < RESTAPI_GIB) {
			$out[] = $check('disk', gettext('Disk space'), 'fail', $detail . ' ' . gettext('At least 1 GiB is needed.'));
		} elseif ($be_update && ($free < $needed)) {
			$out[] = $check('disk', gettext('Disk space'), 'fail', $detail . ' ' .
			    sprintf(gettext('An update with a boot environment needs %s.'), restapi_bytes_text($needed)));
		} elseif ($free < 2 * RESTAPI_GIB) {
			$out[] = $check('disk', gettext('Disk space'), 'warn', $detail . ' ' . gettext('Less than 2 GiB is free.'));
		} else {
			$out[] = $check('disk', gettext('Disk space'), 'pass', $detail);
		}
	}

	if (empty($be['available'])) {
		$out[] = $check('boot_env', gettext('Boot environment'), 'warn',
		    gettext('No ZFS boot environments on this system: the update cannot be rolled back.'));
	} elseif (empty($be['enabled'])) {
		$out[] = $check('boot_env', gettext('Boot environment'), 'warn',
		    gettext('Boot environments are turned off for updates (System > Boot Environments > Settings): the update cannot be rolled back.'));
	} else {
		$out[] = $check('boot_env', gettext('Boot environment'), 'pass', gettext('A boot environment is created before updating.'));
	}

	$out[] = !empty($f['config_writable']) ?
	    $check('config', gettext('Configuration'), 'pass', gettext('The configuration can be saved.')) :
	    $check('config', gettext('Configuration'), 'fail', gettext('The configuration directory is not writable.'));

	if (!empty($v['busy'])) {
		$out[] = $check('repo', gettext('Update repository'), 'warn', gettext('The update system is busy; try again later.'));
	} elseif (!empty($v['error'])) {
		$out[] = $check('repo', gettext('Update repository'), 'fail', (string)$v['error']);
	} else {
		$out[] = $check('repo', gettext('Update repository'), 'pass', gettext('The update repository was reached.'));
	}
	return $out;
}

/* The release document URL of a channel ("stable", "devel"); arm64 systems read their own document. */
function restapi_release_doc_url($channel, $machine) {
	if (!is_string($channel) || !preg_match('/^[A-Za-z0-9_.-]+$/D', $channel)) {
		return null;
	}
	$arch = in_array(strtolower((string)$machine), array('arm64', 'aarch64'), true) ? '.arm64' : '';
	return RESTAPI_RELEASES_URL . rawurlencode($channel) . $arch . '.json';
}

/* One change line: "- Fix: title (System)". */
function restapi_notes_change_line(array $c) {
	$types = array('security' => gettext('Security'), 'fix' => gettext('Fix'), 'feature' => gettext('Feature'),
	    'ui' => gettext('Interface'), 'package' => gettext('Package'), 'documentation' => gettext('Documentation'),
	    'build' => gettext('Build'), 'other' => gettext('Other'));
	$title = trim(preg_replace('/\s+/', ' ', (string)($c['title'] ?? '')));
	$type = $types[$c['type'] ?? 'other'] ?? $types['other'];
	$scope = trim((string)($c['scope'] ?? ''));
	return "- {$type}: {$title}" . (($scope !== '') ? " ({$scope})" : '');
}

/*
 * The changelog of a release document (freesense.download/v3+, release notes
 * freesense.release-notes/v2 or the older "changes" list), like System >
 * Update shows it: {version, date, url, notes} with notes as plain text with
 * "#" headings and "-" lists, or null when there is nothing to show.
 */
function restapi_changelog_from_release($doc, $url) {
	if (!is_array($doc) || (strpos((string)($doc['schema_version'] ?? ''), 'freesense.download/') !== 0)) {
		return array('version' => null, 'date' => null, 'url' => $url, 'notes' => null);
	}
	$version = (string)($doc['release_id'] ?? $doc['version'] ?? '');
	$structured = (is_array($doc['release_notes'] ?? null) &&
	    (($doc['release_notes']['schema_version'] ?? '') === 'freesense.release-notes/v2')) ? $doc['release_notes'] : null;
	$changes = is_array($structured['freesense'] ?? null) ? $structured['freesense'] : (is_array($doc['changes'] ?? null) ? $doc['changes'] : array());

	$sections = array();
	$lines = array();
	foreach ($changes as $c) {
		if (is_array($c) && (trim((string)($c['title'] ?? '')) !== '')) {
			$lines[] = restapi_notes_change_line($c);
		}
	}
	if (!empty($lines)) {
		$sections[] = '# ' . gettext('FreeSense changes') . "\n" . implode("\n", $lines);
	}

	$platform = is_array($structured['platform'] ?? null) ? $structured['platform'] : array();
	$lines = array();
	$freebsd = is_array($platform['freebsd'] ?? null) ? $platform['freebsd'] : array();
	$short = function ($c) {
		return (is_string($c) && preg_match('/^[0-9a-f]{40}$/D', $c)) ? substr($c, 0, 12) : 'unknown';
	};
	if (!empty($freebsd['changed'])) {
		$lines[] = '- ' . gettext('FreeBSD source snapshot updated') . ': ' . $short($freebsd['from_commit'] ?? null) . ' → ' .
		    $short($freebsd['to_commit'] ?? null);
	}
	if (!empty($freebsd['ports_changed'])) {
		$lines[] = '- ' . gettext('FreeBSD ports snapshot updated') . ': ' . $short($freebsd['from_ports_commit'] ?? null) . ' → ' .
		    $short($freebsd['to_ports_commit'] ?? null);
	}
	$packages = is_array($platform['packages'] ?? null) ? $platform['packages'] : array();
	$shown = 0;
	$total = 0;
	foreach (array('updated' => gettext('Updated'), 'added' => gettext('Added'), 'removed' => gettext('Removed')) as $kind => $label) {
		foreach ((array)($packages[$kind] ?? array()) as $p) {
			if (!is_array($p) || (($p['name'] ?? '') === '')) {
				continue;
			}
			$total++;
			if ($shown >= RESTAPI_NOTES_MAX_PACKAGES) {
				continue;
			}
			$shown++;
			$ver = ($kind === 'updated') ? ($p['from'] ?? '') . ' → ' . ($p['to'] ?? '') : (string)($p['version'] ?? '');
			$lines[] = "- {$label}: {$p['name']} {$ver}";
		}
	}
	if ($total > $shown) {
		$lines[] = '- ' . sprintf(gettext('… and %d more package changes'), $total - $shown);
	} elseif (!empty($packages['truncated'])) {
		$lines[] = '- ' . gettext('Only the first 200 package changes are listed.');
	}
	if (!empty($lines)) {
		$sections[] = '# ' . gettext('FreeBSD platform and packages') . "\n" . implode("\n", $lines);
	}

	$date = null;
	if (is_string($doc['published_at'] ?? null) && (strtotime($doc['published_at']) !== false)) {
		$date = gmdate('Y-m-d\TH:i:s\Z', strtotime($doc['published_at']));
	}
	return array(
		'version' => ($version === '') ? null : $version,
		'date' => $date,
		'url' => $url,
		'notes' => empty($sections) ? null : implode("\n\n", $sections),
	);
}

/* ------------------------------------------------------------------ */
/* Boot environments                                                   */
/* ------------------------------------------------------------------ */

function restapi_be_running_version() {
	return trim((string)@file_get_contents('/etc/version'));
}

/* array(entries, meta) from freesense-be and bectl; 409 without boot environments. */
function restapi_be_collect() {
	$bootenv = be_load();
	if (!$bootenv['available'] || !$bootenv['compatible']) {
		throw new RestApiError(409, 'no_boot_environments', gettext('Boot environments are not available on this system.') . ' ' .
		    gettext('They need a ZFS root installation with bectl support.'));
	}
	$entries = be_api_list($bootenv['environments'], be_snapshots(), restapi_be_running_version());
	$pool = be_pool_name((string)($bootenv['status']['root_dataset'] ?? ''));
	$space = be_pool_space($pool);
	return array($entries, array('pool' => $pool, 'free' => ($space === null) ? null : $space['free']));
}

/* Run freesense-be commands; its message becomes a 409 (it refuses protected or unknown environments). */
function restapi_be_run(array $commands) {
	$error = be_run_commands($commands);
	if ($error !== null) {
		throw new RestApiError(409, 'boot_environment_refused', trim($error) ?: 'The boot environment action failed.');
	}
}

function restapi_h_be_list($req) {
	list($entries, $meta) = restapi_be_collect();
	return array('data' => $entries, 'meta' => $meta);
}

function restapi_h_be_create($req) {
	restapi_ops_require_admin($req, 'Creating a boot environment');
	$body = restapi_ops_body($req['body'], array('name' => 'string', 'description' => 'string'));
	list($entries) = restapi_be_collect();
	$name = restapi_be_check_new_name($body['name'] ?? ('manual-' . date('Ymd-His')), $entries);
	$description = isset($body['description']) ? restapi_be_check_description($body['description']) : '';
	$commands = array(array('create', $name));
	if ($description !== '') {
		$commands[] = array('describe', $name, $description);
	}
	restapi_be_run($commands);
	list($entries) = restapi_be_collect();
	return array('status' => 201, 'data' => restapi_be_find($entries, $name));
}

function restapi_h_be_activate($req) {
	restapi_ops_require_admin($req, 'Activating a boot environment');
	$body = restapi_ops_body($req['body'], array('confirm' => 'bool', 'once' => 'bool'));
	restapi_ops_confirm($body, 'Activating a boot environment');
	list($entries) = restapi_be_collect();
	$entry = restapi_be_find($entries, $req['params']['name']);
	$refusal = restapi_be_activate_refusal($entry);
	if ($refusal !== null) {
		throw $refusal;
	}
	restapi_be_run(array(array(empty($body['once']) ? 'activate' : 'activate-once', $entry['name'])));
	list($entries) = restapi_be_collect();
	return array('data' => restapi_be_find($entries, $entry['name']));
}

function restapi_h_be_update($req) {
	restapi_ops_require_admin($req, 'Changing a boot environment');
	$body = restapi_ops_body($req['body'], array('name' => 'string', 'description' => 'string'));
	if (!isset($body['name']) && !isset($body['description'])) {
		throw new RestApiError(400, 'missing_field', 'Send "name" and/or "description".');
	}
	list($entries) = restapi_be_collect();
	$entry = restapi_be_find($entries, $req['params']['name']);
	if ($entry['kind'] === 'snapshot') {
		throw new RestApiError(409, 'snapshot_read_only', 'A snapshot cannot be renamed or described.');
	}
	$target = $entry['name'];
	if (isset($body['name']) && ($body['name'] !== $entry['name'])) {
		$target = restapi_be_check_new_name($body['name'], $entries);
		if ($entry['active']) {
			throw new RestApiError(409, 'boot_environment_in_use', 'The running boot environment cannot be renamed.');
		}
	}
	/* Like the page's edit dialog: the description first (under the current name), then the rename. */
	$commands = array();
	if (isset($body['description'])) {
		$commands[] = array('describe', $entry['name'], restapi_be_check_description($body['description']));
	}
	if ($target !== $entry['name']) {
		$commands[] = array('rename', $entry['name'], $target);
	}
	restapi_be_run($commands);
	list($entries) = restapi_be_collect();
	return array('data' => restapi_be_find($entries, $target));
}

function restapi_h_be_delete($req) {
	restapi_ops_require_admin($req, 'Deleting a boot environment');
	list($entries) = restapi_be_collect();
	$entry = restapi_be_find($entries, $req['params']['name']);
	$refusal = restapi_be_delete_refusal($entry);
	if ($refusal !== null) {
		throw $refusal;
	}
	if ($entry['kind'] === 'snapshot') {
		$error = be_snapshot_destroy($entry['name']);
		if ($error !== null) {
			throw new RestApiError(409, 'boot_environment_refused', trim($error) ?: 'The snapshot could not be deleted.');
		}
	} else {
		restapi_be_run(array(array('destroy', $entry['name'])));
	}
	return array('data' => array('name' => $entry['name'], 'kind' => $entry['kind'], 'deleted' => true));
}

/* ------------------------------------------------------------------ */
/* Update preflight and changelog                                      */
/* ------------------------------------------------------------------ */

function restapi_h_update_preflight($req) {
	/* The version check as System > Update does it (cached; FreeSense-upgrade -c only checks). */
	ob_start();
	try {
		$v = get_system_pkg_version(true);
	} finally {
		ob_end_clean();
	}
	$version = array(
		'busy' => !empty($v['pkg_busy']),
		'error' => (isset($v['pkg_version_error']) || !isset($v['installed_version'])) ?
		    (string)($v['pkg_version_error'] ?? 'unknown error') : null,
		'installed' => isset($v['installed_version']) ? (string)$v['installed_version'] : null,
		'latest' => isset($v['version']) ? (string)$v['version'] : null,
		'update_available' => (($v['pkg_version_compare'] ?? null) === '<'),
	);

	$bootenv = be_load();
	$be_available = $bootenv['available'] && $bootenv['compatible'];
	$pool = $be_available ? be_pool_name((string)($bootenv['status']['root_dataset'] ?? '')) : null;
	$space = be_pool_space($pool);
	if ($space !== null) {
		$disk = array('target' => $pool, 'zfs' => true, 'size' => $space['size'], 'free' => $space['free']);
	} else {
		$free = @disk_free_space('/');
		$size = @disk_total_space('/');
		$disk = array('target' => '/', 'zfs' => false, 'size' => ($size === false) ? null : (int)$size,
		    'free' => ($free === false) ? null : (int)$free);
	}
	$conf = g_get('conf_path');

	return array('data' => restapi_update_preflight_checks(array(
		'version' => $version,
		'operation_running' => is_subsystem_dirty('packagelock') || isvalidpid(pkg_mgr_install_paths()['gui_pidfile']),
		'disk' => $disk,
		'boot_env' => array('available' => $be_available,
		    'enabled' => (config_get_path('system/bootenv/enabled', 'true') !== 'false')),
		'config_writable' => is_dir($conf) && is_writable($conf) && (!file_exists("{$conf}/config.xml") || is_writable("{$conf}/config.xml")),
	)));
}

/* The release document of a channel: from the cache (an hour) or fetched with a short timeout; null when unreachable. */
function restapi_release_doc($url) {
	$cache = g_get('tmp_path') . '/restapi-release-' . md5($url) . '.json';
	if (is_file($cache) && (time() - filemtime($cache) < RESTAPI_RELEASES_CACHE_TTL)) {
		$doc = json_decode((string)@file_get_contents($cache), true);
		if (is_array($doc)) {
			return $doc;
		}
	}
	$lines = array();
	exec('/usr/bin/timeout -k 1 ' . RESTAPI_RELEASES_FETCH_TIMEOUT . ' /usr/bin/fetch -qo - -T ' .
	    RESTAPI_RELEASES_FETCH_TIMEOUT . ' ' . escapeshellarg($url) . ' 2>/dev/null', $lines, $rc);
	$raw = implode("\n", $lines);
	$doc = ($rc === 0) ? json_decode($raw, true) : null;
	if (!is_array($doc)) {
		/* Better an older document than none. */
		$old = is_file($cache) ? json_decode((string)@file_get_contents($cache), true) : null;
		return is_array($old) ? $old : null;
	}
	@file_put_contents($cache, $raw, LOCK_EX);
	return $doc;
}

function restapi_h_update_changelog($req) {
	$channel = (string)pkg_get_repo_name(config_get_path('system/pkg_repo_conf_path'));
	$url = restapi_release_doc_url($channel, php_uname('m'));
	$doc = ($url === null) ? null : restapi_release_doc($url);
	return array('data' => restapi_changelog_from_release($doc, RESTAPI_RELEASE_NOTES_PAGE));
}
