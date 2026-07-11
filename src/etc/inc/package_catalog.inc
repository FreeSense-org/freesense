<?php
/*
 * FreeSense package catalog metadata helpers.
 *
 * Product metadata intentionally lives in the base system instead of being
 * inferred from third-party package descriptions. Runtime versions continue
 * to come from pkg(8), which is the authoritative installed-state source.
 */

function freesense_package_catalog() {
	static $catalog = null;

	if ($catalog !== null) {
		return $catalog;
	}

	$catalog = [];
	$path = defined('FREESENSE_PACKAGE_CATALOG_PATH') ?
	    FREESENSE_PACKAGE_CATALOG_PATH : '/etc/freesense-package-catalog.json';
	if (!is_readable($path)) {
		return $catalog;
	}

	$decoded = json_decode(file_get_contents($path), true);
	if (is_array($decoded) && isset($decoded['packages']) && is_array($decoded['packages'])) {
		$catalog = $decoded['packages'];
	}

	return $catalog;
}

function freesense_package_catalog_entry($shortname) {
	$catalog = freesense_package_catalog();
	$defaults = [
		'display_name' => $shortname,
		'category' => 'Other',
		'support' => 'supported',
		'last_tested_release' => 'unknown',
		'resource_profile' => 'lightweight',
		'capabilities' => [],
		'services' => [],
		'configure_path' => null,
		'status_path' => null,
		'conflicts' => [],
	];

	if (!isset($catalog[$shortname]) || !is_array($catalog[$shortname])) {
		return $defaults;
	}

	return array_replace($defaults, $catalog[$shortname]);
}

function freesense_package_catalog_categories() {
	$categories = [];
	foreach (freesense_package_catalog() as $entry) {
		if (!empty($entry['category'])) {
			$categories[$entry['category']] = $entry['category'];
		}
	}
	natcasesort($categories);
	return array_values($categories);
}
