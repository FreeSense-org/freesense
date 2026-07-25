<?php
/*
 * Optional-package configuration restore reconciliation.
 *
 * Restored package inventory and settings are untrusted historical intent,
 * not proof that the corresponding binary is installed or still available.
 * This module separates that intent from active config.xml until pkg(8)
 * verifies and installs the current package.
 */

define('FREESENSE_PACKAGE_RESTORE_SCHEMA', 'freesense.package-restore/v1');
define('FREESENSE_PACKAGE_QUARANTINE_SCHEMA', 'freesense.package-quarantine/v1');

function freesense_package_restore_normalize($value) {
	return strtolower(preg_replace('/[^A-Za-z0-9]/', '', (string)$value));
}

/*
 * config.xml cannot represent numerically indexed arrays below ordinary
 * fields: dump_xml_config() would emit invalid elements such as <0>. A
 * package currently owns one configuration root, so persist that root as a
 * scalar and let restore readers cast it to an array when iterating.
 */
function freesense_package_restore_xml_path($package_name) {
	$root = freesense_package_restore_normalize($package_name);
	return ($root === '') ? '' : "installedpackages/{$root}";
}

function freesense_package_restore_inventory($value) {
	if (!is_array($value) || empty($value)) {
		return array();
	}
	if (isset($value['name']) || isset($value['internal_name'])) {
		return array($value);
	}
	return array_values(array_filter($value, 'is_array'));
}

function freesense_package_restore_inventory_signature($config) {
	$identities = array();
	$packages = freesense_package_restore_inventory(
	    $config['installedpackages']['package'] ?? array());
	foreach ($packages as $package) {
		$identity = freesense_package_restore_identity($package);
		if ($identity !== '') {
			$identities[] = strtolower($identity);
		}
	}
	sort($identities);
	return hash('sha256', json_encode($identities));
}

function freesense_package_restore_identity($package) {
	$internal = trim((string)($package['internal_name'] ?? ''));
	$name = trim((string)($package['name'] ?? ''));
	return ($internal !== '') ? $internal : $name;
}

function freesense_package_restore_candidates($package) {
	$candidates = array();
	foreach ((array)($package['restore_config_paths'] ?? array()) as $path) {
		$path = trim((string)$path, '/');
		if (str_starts_with($path, 'installedpackages/')) {
			$path = substr($path, strlen('installedpackages/'));
		}
		if ($path !== '' && !str_contains($path, '/')) {
			$candidates[] = freesense_package_restore_normalize($path);
		}
	}
	foreach (array(
	    $package['internal_name'] ?? '',
	    $package['name'] ?? '',
	    pathinfo((string)($package['configurationfile'] ?? ''), PATHINFO_FILENAME),
	) as $value) {
		$value = freesense_package_restore_normalize($value);
		if ($value !== '') {
			$candidates[] = $value;
		}
	}
	return array_values(array_unique($candidates));
}

function freesense_package_restore_catalog_lookup($identity, $catalog) {
	if ($catalog === null) {
		return array('status' => 'unknown', 'target' => $identity);
	}
	foreach ((array)$catalog as $candidate) {
		if (strcasecmp((string)$candidate, (string)$identity) === 0) {
			return array('status' => 'available', 'target' => (string)$candidate);
		}
	}
	return array('status' => 'missing', 'target' => $identity);
}

function freesense_package_restore_match_roots($package, $roots) {
	$matches = array();
	$candidates = freesense_package_restore_candidates($package);
	foreach ($roots as $root) {
		$normalized_root = freesense_package_restore_normalize($root);
		$score = 0;
		foreach ($candidates as $candidate) {
			if ($normalized_root === $candidate) {
				$score = max($score, 1000 + strlen($candidate));
			} elseif (str_starts_with($normalized_root, $candidate)) {
				$score = max($score, 100 + strlen($candidate));
			}
		}
		if ($score > 0) {
			$matches[$root] = $score;
		}
	}
	arsort($matches);
	return array_keys($matches);
}

/*
 * Extract optional-package state from a parsed configuration.
 *
 * $catalog is null when repository availability is unknown, otherwise it is
 * the authoritative list of current internal package names. $selected is null
 * for automatic restore or an array of names checked in the WebGUI preview.
 */
function freesense_package_restore_extract(&$config, $catalog = null, $selected = null) {
	$result = array(
		'schema_version' => FREESENSE_PACKAGE_RESTORE_SCHEMA,
		'pending' => array(),
		'quarantine' => array(),
		'packages' => array(),
	);
	if (!is_array($config) || !isset($config['installedpackages']) ||
	    !is_array($config['installedpackages'])) {
		return $result;
	}

	$installed = &$config['installedpackages'];
	$packages = freesense_package_restore_inventory($installed['package'] ?? array());

	/* Menus and services are generated state and must never be restored. */
	unset($installed['package'], $installed['menu'], $installed['service']);

	/* These legacy roots are owned by the base system, not optional packages. */
	$core_roots = array('miniupnpd', 'openntpd');
	$roots = array_values(array_diff(array_keys($installed), $core_roots));

	/*
	 * Prefer packages with the most specific identity so WebGateway-AV claims
	 * webgatewayav before WebGateway can match it by prefix.
	 */
	usort($packages, function($left, $right) {
		$left_len = max(array_map('strlen',
		    freesense_package_restore_candidates($left)) ?: array(0));
		$right_len = max(array_map('strlen',
		    freesense_package_restore_candidates($right)) ?: array(0));
		return $right_len <=> $left_len;
	});

	foreach ($packages as $package) {
		$identity = freesense_package_restore_identity($package);
		if ($identity === '') {
			continue;
		}
		$catalog_state = freesense_package_restore_catalog_lookup($identity, $catalog);
		$target = $catalog_state['target'];
		$is_selected = ($selected === null);
		if (is_array($selected)) {
			foreach ($selected as $choice) {
				if (!is_scalar($choice)) {
					continue;
				}
				if (strcasecmp((string)$choice, $target) === 0 ||
				    strcasecmp((string)$choice, $identity) === 0) {
					$is_selected = true;
					break;
				}
			}
		}

		$settings = array();
		foreach (freesense_package_restore_match_roots($package, $roots) as $root) {
			$settings[$root] = $installed[$root];
			unset($installed[$root]);
			$roots = array_values(array_diff($roots, array($root)));
		}

		$record = array(
			'name' => (string)($package['name'] ?? $identity),
			'internal_name' => $identity,
			'target' => $target,
			'status' => $catalog_state['status'],
			'inventory' => $package,
			'settings' => $settings,
		);
		$result['packages'][] = array(
			'name' => $record['name'],
			'internal_name' => $identity,
			'target' => $target,
			'status' => $catalog_state['status'],
			'selected' => $is_selected,
			'setting_roots' => array_keys($settings),
		);

		if ($catalog_state['status'] === 'missing') {
			$record['reason'] = 'missing';
			$result['quarantine'][] = $record;
		} elseif (!$is_selected) {
			$record['reason'] = 'deselected';
			$result['quarantine'][] = $record;
		} else {
			$record['reason'] = 'pending';
			$result['pending'][] = $record;
		}
	}

	/* Unknown non-core roots are safer in quarantine than active config.xml. */
	foreach ($roots as $root) {
		if (!array_key_exists($root, $installed)) {
			continue;
		}
		$result['quarantine'][] = array(
			'name' => $root,
			'internal_name' => $root,
			'target' => '',
			'status' => 'orphan',
			'reason' => 'orphan',
			'inventory' => array(),
			'settings' => array($root => $installed[$root]),
		);
		unset($installed[$root]);
	}

	if (empty($installed)) {
		unset($config['installedpackages']);
	}
	return $result;
}

function freesense_package_restore_conf_path() {
	global $g;
	if (isset($g) && !empty($g['conf_path'])) {
		return $g['conf_path'];
	}
	return '/conf';
}

function freesense_package_restore_pending_path() {
	return freesense_package_restore_conf_path() . '/package_restore_pending.json';
}

function freesense_package_restore_quarantine_dir() {
	return freesense_package_restore_conf_path() . '/restore-quarantine';
}

function freesense_package_restore_atomic_json($path, $value) {
	$directory = dirname($path);
	if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
		return false;
	}
	@chmod($directory, 0700);
	$temporary = tempnam($directory, '.package-restore-');
	if ($temporary === false) {
		return false;
	}
	$encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	if ($encoded === false ||
	    file_put_contents($temporary, $encoded . "\n", LOCK_EX) === false) {
		@unlink($temporary);
		return false;
	}
	@chmod($temporary, 0600);
	if (!rename($temporary, $path)) {
		@unlink($temporary);
		return false;
	}
	@chmod($path, 0600);
	return true;
}

function freesense_package_restore_read_json($path) {
	if (!is_readable($path)) {
		return null;
	}
	$value = json_decode(file_get_contents($path), true);
	return is_array($value) ? $value : null;
}

function freesense_package_restore_quarantine($records, $source = 'restore') {
	if (empty($records)) {
		return null;
	}
	$id = gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4));
	$document = array(
		'schema_version' => FREESENSE_PACKAGE_QUARANTINE_SCHEMA,
		'id' => $id,
		'created_at' => gmdate('c'),
		'source' => $source,
		'packages' => array_values($records),
	);
	$path = freesense_package_restore_quarantine_dir() . "/{$id}.json";
	return freesense_package_restore_atomic_json($path, $document) ? $path : false;
}

function freesense_package_restore_save($result, $source = 'restore') {
	$pending_path = freesense_package_restore_pending_path();
	$quarantine_path = freesense_package_restore_quarantine(
	    $result['quarantine'] ?? array(), $source);
	if ($quarantine_path === false) {
		return false;
	}
	if (!empty($result['pending'])) {
		$pending = array(
			'schema_version' => FREESENSE_PACKAGE_RESTORE_SCHEMA,
			'created_at' => gmdate('c'),
			'source' => $source,
			'packages' => array_values($result['pending']),
		);
		if (!freesense_package_restore_atomic_json($pending_path, $pending)) {
			if (is_string($quarantine_path)) {
				@unlink($quarantine_path);
			}
			return false;
		}
	} else {
		@unlink($pending_path);
	}
	return array(
		'pending' => count($result['pending'] ?? array()),
		'quarantined' => count($result['quarantine'] ?? array()),
		'quarantine_path' => $quarantine_path,
	);
}

function freesense_package_restore_prepare_active(
    $catalog = null, $source = 'restore', $replace_existing = false) {
	global $config;

	$result = freesense_package_restore_extract($config, $catalog);
	if (empty($result['pending']) && empty($result['quarantine'])) {
		if ($replace_existing) {
			@unlink(freesense_package_restore_pending_path());
		}
		return array(
			'pending' => 0,
			'quarantined' => 0,
			'existing' => !$replace_existing &&
			    is_readable(freesense_package_restore_pending_path()),
		);
	}
	$saved = freesense_package_restore_save($result, $source);
	if ($saved === false) {
		return false;
	}
	if (function_exists('write_config')) {
		write_config(gettext('Separated restored optional package state pending reconciliation.'));
	}
	@touch(freesense_package_restore_conf_path() . '/needs_package_sync');
	return $saved;
}

function freesense_package_restore_merge_settings($settings) {
	foreach ((array)$settings as $root => $value) {
		if (!is_string($root) || $root === '' || str_contains($root, '/')) {
			continue;
		}
		if (function_exists('config_set_path')) {
			config_set_path("installedpackages/{$root}", $value);
		}
	}
}

function freesense_package_restore_update_pending($state, $packages) {
	$path = freesense_package_restore_pending_path();
	if (empty($packages)) {
		@unlink($path);
		return true;
	}
	$state['packages'] = array_values($packages);
	$state['updated_at'] = gmdate('c');
	return freesense_package_restore_atomic_json($path, $state);
}

function freesense_package_restore_list_quarantine() {
	$records = array();
	$directory = freesense_package_restore_quarantine_dir();
	foreach (glob($directory . '/*.json') ?: array() as $path) {
		$value = freesense_package_restore_read_json($path);
		if (($value['schema_version'] ?? '') !== FREESENSE_PACKAGE_QUARANTINE_SCHEMA) {
			continue;
		}
		$records[] = array(
			'id' => basename($path, '.json'),
			'created_at' => (string)($value['created_at'] ?? ''),
			'source' => (string)($value['source'] ?? ''),
			'packages' => array_values(array_filter(array_map(
			    function($package) {
				    return is_array($package) ?
				        (string)($package['name'] ?? $package['internal_name'] ?? '') : '';
			    },
			    $value['packages'] ?? array()
			))),
		);
	}
	usort($records, fn($left, $right) => strcmp($right['created_at'], $left['created_at']));
	return $records;
}

function freesense_package_restore_quarantine_path($id) {
	if (!is_string($id) || !preg_match('/^[0-9]{8}T[0-9]{6}Z-[0-9a-f]{8}$/D', $id)) {
		return null;
	}
	$directory = realpath(freesense_package_restore_quarantine_dir());
	$path = realpath(freesense_package_restore_quarantine_dir() . "/{$id}.json");
	if ($directory === false || $path === false ||
	    dirname($path) !== $directory) {
		return null;
	}
	return $path;
}

function freesense_package_restore_delete_quarantine($id) {
	$path = freesense_package_restore_quarantine_path($id);
	return ($path !== null) ? @unlink($path) : false;
}
