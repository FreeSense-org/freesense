<?php
/*
 * system_boot_environments.inc
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2025-2026 The FreeSense Project
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
 * The shared code of System > Boot Environments: the freesense-be control
 * interface (FreeSense-upgrade port), the page's actions and settings, and
 * the parsing behind the REST API's boot environment list (bectl list output,
 * sizes, dates, the one-way release rule). The page and the REST API both
 * use it. Functions marked "pure" touch neither the system nor config.xml.
 */

/* The boot environment control interface; it wraps bectl and keeps the freesense:* metadata. */
define('BOOTENV_TOOL', '/usr/local/sbin/freesense-be');
define('BOOTENV_BECTL', '/sbin/bectl');
/* A description is stored as a ZFS user property; the page and freesense-be allow this much. */
define('BOOTENV_DESCRIPTION_MAX', 256);

function be_command(array $arguments, &$output = null): int {
	$command = BOOTENV_TOOL . ' ' . implode(' ', array_map('escapeshellarg', $arguments));
	$lines = [];
	exec($command . ' 2>&1', $lines, $status);
	$output = implode("\n", $lines);
	return $status;
}

/* pure */
function be_valid_name(string $name): bool {
	return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $name) === 1;
}

/* pure: "<be>@<snapshot>" as bectl names a snapshot (the snapshot part may hold ":" from bectl's timestamps). */
function be_valid_snapshot_name(string $name): bool {
	$parts = explode('@', $name, 2);
	return (count($parts) === 2) && be_valid_name($parts[0]) &&
	    (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $parts[1]) === 1);
}

/* pure */
function be_version_parts(string $version): array {
	if (preg_match('/^(.*?)(\d{8}(?:[._-]\d{4,6})?)$/', $version, $matches)) {
		return [rtrim($matches[1], '._-'), $matches[2]];
	}
	return [$version, ''];
}

/* pure */
function be_created_parts(string $created): array {
	if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2}(?::\d{2})?)(Z)?/', $created, $matches)) {
		return [$matches[1], $matches[2] . (!empty($matches[3]) ? ' UTC' : '')];
	}
	return [$created, ''];
}

/*
 * freesense-be list: array(available, compatible, status, environments).
 * available: the tool exists and answered; compatible: bectl check passed.
 */
function be_load(): array {
	$raw = '';
	$available = is_executable(BOOTENV_TOOL) && be_command(['list'], $raw) === 0;
	$data = $available ? json_decode($raw, true) : null;
	return [
		'available' => $available,
		'compatible' => (bool)($data['status']['compatible'] ?? false),
		'status' => is_array($data['status'] ?? null) ? $data['status'] : [],
		'environments' => is_array($data['environments'] ?? null) ? $data['environments'] : [],
	];
}

/* pure: the running environment first, then the newest (the page's order). */
function be_sort_environments(array $environments): array {
	usort($environments, static function (array $left, array $right): int {
		$current = (int)!empty($right['active_now']) <=> (int)!empty($left['active_now']);
		if ($current !== 0) {
			return $current;
		}
		$left_created = strtotime($left['metadata']['created'] ?? $left['created'] ?? '') ?: 0;
		$right_created = strtotime($right['metadata']['created'] ?? $right['created'] ?? '') ?: 0;
		return $right_created <=> $left_created;
	});
	return $environments;
}

/* Run freesense-be commands in order; the first failure's output, or null when all succeeded. */
function be_run_commands(array $commands): ?string {
	foreach ($commands as $args) {
		if (be_command($args, $result) !== 0) {
			return $result;
		}
	}
	return null;
}

/* The settings tab: array(input_errors, savemsg). */
function be_settings_save(array $post): array {
	$input_errors = [];
	$savemsg = null;
	$retention = filter_var($post['retention_auto'] ?? 3, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10]]);
	$timeout = filter_var($post['health_timeout'] ?? 300, FILTER_VALIDATE_INT, ['options' => ['min_range' => 60, 'max_range' => 900]]);
	if ($retention === false || $timeout === false) {
		$input_errors[] = gettext('Invalid retention or health timeout value.');
	} else {
		/* Store explicit strings: upgrade/health scripts read these nodes as text. */
		config_set_path('system/bootenv/enabled', isset($post['enabled']) ? 'true' : 'false');
		config_set_path('system/bootenv/automatic_rollback', isset($post['automatic_rollback']) ? 'true' : 'false');
		config_set_path('system/bootenv/retention_auto', $retention);
		config_set_path('system/bootenv/health_timeout', $timeout);
		write_config(gettext('Updated ZFS boot environment settings.'));
		$savemsg = gettext('Boot environment settings saved.');
	}
	return [$input_errors, $savemsg];
}

/* pure: the freesense-be commands for a page action: array(commands, input_errors). */
function be_page_commands(string $action, array $post): array {
	$name = trim($post['name'] ?? '');
	$commands = [];
	$input_errors = [];
	if (!be_valid_name($name)) {
		$input_errors[] = gettext('Invalid boot environment name.');
	} elseif ($action === 'create' || $action === 'destroy' || $action === 'activate' || $action === 'activate-once') {
		$commands[] = [$action, $name];
	} elseif ($action === 'clone' || $action === 'rename') {
		$target = trim($post['target'] ?? '');
		if (!be_valid_name($target)) $input_errors[] = gettext('Invalid target boot environment name.');
		else $commands[] = [$action, $name, $target];
	} elseif ($action === 'describe') {
		$commands[] = [$action, $name, substr(trim($post['description'] ?? ''), 0, BOOTENV_DESCRIPTION_MAX)];
	} elseif ($action === 'edit') {
		/* the edit modal: description first (under the current name), then the rename if the name changed */
		$target = trim($post['target'] ?? '');
		if (!be_valid_name($target)) {
			$input_errors[] = gettext('Invalid target boot environment name.');
		} else {
			$commands[] = ['describe', $name, substr(trim($post['description'] ?? ''), 0, BOOTENV_DESCRIPTION_MAX)];
			if ($target !== $name) {
				$commands[] = ['rename', $name, $target];
			}
		}
	} else {
		$input_errors[] = gettext('Unknown boot environment action.');
	}
	return [$commands, $input_errors];
}

/* A page action (create, clone, rename, describe, edit, destroy, activate, activate-once): array(input_errors, savemsg). */
function be_page_action(string $action, array $post): array {
	$name = trim($post['name'] ?? '');
	list($commands, $input_errors) = be_page_commands($action, $post);
	$savemsg = null;
	if (!$input_errors) {
		$error = be_run_commands($commands);
		if ($error !== null) {
			$input_errors[] = $error;
		}
	}
	if (!$input_errors) {
		$done = [
			'create' => gettext('Created boot environment %s.'),
			'clone' => gettext('Created boot environment %s as a clone.'),
			'edit' => gettext('Saved boot environment %s.'),
			'rename' => gettext('Renamed boot environment %s.'),
			'describe' => gettext('Saved the description of %s.'),
			'destroy' => gettext('Deleted boot environment %s.'),
			'activate' => gettext('Boot environment %s is used from the next boot on.'),
			'activate-once' => gettext('Boot environment %s is used for the next boot only.'),
		];
		$savemsg = sprintf($done[$action] ?? gettext('Boot environment action completed (%s).'),
		    htmlspecialchars(($action === 'edit') ? trim($post['target'] ?? $name) : $name));
		if ($action === 'activate-once' && ($post['reboot'] ?? '') === '1') {
			mwexec_bg('/sbin/shutdown -r now');
		}
	}
	return [$input_errors, $savemsg];
}

/* ------------------------------------------------------------------ */
/* The REST API's view (pure helpers first)                            */
/* ------------------------------------------------------------------ */

/* pure: a size as bectl and zfs print it ("1.21G", "120K", "0", "-") in bytes. */
function be_size_bytes(string $size): int {
	$size = trim($size);
	if (!preg_match('/^(\d+(?:\.\d+)?)\s*([BKMGTPE]?)i?B?$/i', $size, $m)) {
		return 0;
	}
	$exp = strpos('BKMGTPE', strtoupper($m[2] === '' ? 'B' : $m[2]));
	return (int)round((float)$m[1] * (1024 ** $exp));
}

/* pure: a creation time as bectl (local "2026-10-08 12:34") or the freesense:created property (UTC ISO) writes it; 0 if unknown. */
function be_created_time(string $created): int {
	$created = trim($created);
	if (($created === '') || ($created === '-')) {
		return 0;
	}
	if (ctype_digit($created)) {
		return (int)$created;
	}
	$t = strtotime($created);
	return ($t === false) ? 0 : $t;
}

/*
 * pure: parse `bectl list -H`, also with -s (snapshots) or -a (all datasets).
 * Rows are tab separated (columns separated by two or more spaces are
 * accepted too): name, active flags (N now, R on reboot, T next boot only),
 * mountpoint, space, created. With -s or -a, bectl may print each boot
 * environment's name on a line of its own followed by its dataset and
 * snapshot rows. Returns array('environments' => name => row,
 * 'snapshots' => list of rows with 'parent' and 'snapshot').
 */
function be_parse_bectl_list(string $raw): array {
	$envs = [];
	$snaps = [];
	$current = null;
	foreach (preg_split('/\R/', $raw) as $line) {
		if (trim($line) === '') {
			continue;
		}
		$line = trim($line);
		$fields = (strpos($line, "\t") !== false) ? explode("\t", $line) : preg_split('/\s{2,}/', $line);
		$fields = array_map('trim', $fields);
		$name = $fields[0];
		if (count($fields) === 1 || (count(array_filter(array_slice($fields, 1), 'strlen')) === 0)) {
			/* A boot environment heading (-s / -a). */
			$current = $name;
			continue;
		}
		$row = [
			'active' => $fields[1] ?? '-',
			'mountpoint' => (($fields[2] ?? '-') === '-') ? null : $fields[2],
			'space' => $fields[3] ?? '-',
			'created' => $fields[4] ?? '',
		];
		if (strpos($name, '@') !== false) {
			list($dataset, $snapshot) = explode('@', $name, 2);
			$parent = ($current !== null) ? $current : basename($dataset);
			/* With -a, snapshots of child datasets also appear; keep those of the boot environment itself. */
			if (($current !== null) && (strpos($dataset, '/') !== false) && (basename($dataset) !== $current)) {
				continue;
			}
			$snaps[] = $row + ['name' => "{$parent}@{$snapshot}", 'parent' => $parent, 'snapshot' => $snapshot];
			continue;
		}
		if (strpos($name, '/') !== false) {
			/* A dataset row (-s / -a): the boot environment's own dataset carries its flags. */
			if (($current !== null) && (basename($name) === $current) && !isset($envs[$current])) {
				$envs[$current] = $row + ['name' => $current];
			}
			continue;
		}
		$current = $name;
		$envs[$name] = $row + ['name' => $name];
	}
	return ['environments' => $envs, 'snapshots' => $snaps];
}

/* pure: the release (major, minor) of a version such as "1.1.0-DEVELOPMENT" or "1.0.1.a.20260101.0100"; null if unknown. */
function be_release(?string $version): ?array {
	if (($version === null) || !preg_match('/^\s*(\d+)\.(\d+)(?:\D|$)/', $version, $m)) {
		return null;
	}
	return [(int)$m[1], (int)$m[2]];
}

/*
 * pure: the one-way rule. An installed release must not go back to an older
 * release (1.1 to 1.0): its configuration has been upgraded. Older builds of
 * the same release stay bootable (that is the rollback after an update).
 * Unknown versions are not locked.
 */
function be_locked(?string $be_version, string $system_version): bool {
	$be = be_release($be_version);
	$sys = be_release($system_version);
	return ($be !== null) && ($sys !== null) && ($be < $sys);
}

/*
 * pure: one boot environment (a freesense-be list entry) as the API shows it.
 * $running_version is the running system's version (/etc/version), used for
 * the running environment when it has no version property.
 */
function be_api_entry(array $be, string $running_version): array {
	$meta = is_array($be['metadata'] ?? null) ? $be['metadata'] : [];
	$version = isset($meta['version']) && ($meta['version'] !== 'Unknown') ? (string)$meta['version'] : null;
	if (($version === null) && !empty($be['active_now']) && ($running_version !== '')) {
		$version = $running_version;
	}
	$created = be_created_time((string)($meta['created'] ?? ''));
	if ($created === 0) {
		$created = be_created_time((string)($be['created'] ?? ''));
	}
	return [
		'name' => (string)$be['name'],
		'kind' => 'be',
		'active' => !empty($be['active_now']),
		'next_boot' => !empty($be['active_reboot']) || !empty($be['active_once']),
		'version' => $version,
		'created' => $created,
		'size' => be_size_bytes((string)($be['space'] ?? '')),
		'description' => (string)($meta['description'] ?? ''),
		'locked' => empty($be['active_now']) && be_locked($version, $running_version),
		'parent' => null,
	];
}

/* pure: a snapshot row of be_parse_bectl_list() as the API shows it; it has its environment's version. */
function be_api_snapshot_entry(array $snap, ?string $parent_version, string $running_version): array {
	return [
		'name' => (string)$snap['name'],
		'kind' => 'snapshot',
		'active' => false,
		'next_boot' => false,
		'version' => $parent_version,
		'created' => be_created_time((string)($snap['created'] ?? '')),
		'size' => be_size_bytes((string)($snap['space'] ?? '')),
		'description' => '',
		'locked' => be_locked($parent_version, $running_version),
		'parent' => (string)$snap['parent'],
	];
}

/* pure: boot environments and snapshots as the API lists them, newest first (the running environment is not moved up). */
function be_api_list(array $environments, array $snapshots, string $running_version): array {
	$out = [];
	$versions = [];
	foreach ($environments as $be) {
		if (!is_array($be) || !isset($be['name'])) {
			continue;
		}
		$entry = be_api_entry($be, $running_version);
		$versions[$entry['name']] = $entry['version'];
		$out[] = $entry;
	}
	foreach ($snapshots as $snap) {
		$out[] = be_api_snapshot_entry($snap, $versions[$snap['parent']] ?? null, $running_version);
	}
	usort($out, static function (array $a, array $b): int {
		return [$b['created'], $a['name']] <=> [$a['created'], $b['name']];
	});
	return $out;
}

/* pure: the pool of a root dataset ("zroot/ROOT/default" -> "zroot"). */
function be_pool_name(string $root_dataset): ?string {
	$pool = strtok($root_dataset, '/');
	return (($pool === false) || ($pool === '')) ? null : $pool;
}

/* `bectl list -H -s`: the snapshots of the boot environments (empty when bectl fails). */
function be_snapshots(): array {
	$lines = [];
	exec(BOOTENV_BECTL . ' list -H -s 2>/dev/null', $lines, $status);
	return ($status === 0) ? be_parse_bectl_list(implode("\n", $lines))['snapshots'] : [];
}

/* The pool's size and free space in bytes (zpool list -Hp), or null. */
function be_pool_space(?string $pool): ?array {
	if (($pool === null) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/D', $pool)) {
		return null;
	}
	$lines = [];
	exec('/sbin/zpool list -Hp -o size,free ' . escapeshellarg($pool) . ' 2>/dev/null', $lines, $status);
	$f = preg_split('/\s+/', trim($lines[0] ?? ''));
	if (($status !== 0) || (count($f) < 2) || !ctype_digit($f[0]) || !ctype_digit($f[1])) {
		return null;
	}
	return ['size' => (int)$f[0], 'free' => (int)$f[1]];
}

/* Destroy a snapshot "<be>@<snapshot>" (the page has no snapshot list; bectl does it). Null or bectl's error. */
function be_snapshot_destroy(string $name): ?string {
	if (!be_valid_snapshot_name($name)) {
		return gettext('Invalid boot environment name.');
	}
	$lines = [];
	exec(BOOTENV_BECTL . ' destroy ' . escapeshellarg($name) . ' 2>&1', $lines, $status);
	return ($status === 0) ? null : implode("\n", $lines);
}
