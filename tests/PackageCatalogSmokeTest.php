<?php
define('FREESENSE_PACKAGE_CATALOG_PATH', dirname(__DIR__) . '/src/etc/freesense-package-catalog.json');
require_once(dirname(__DIR__) . '/src/etc/inc/package_catalog.inc');

$catalog = freesense_package_catalog();
if (count($catalog) < 30) {
	fwrite(STDERR, "Package catalog is unexpectedly small.\n");
	exit(1);
}

$required = ['display_name', 'category', 'support', 'last_tested_release', 'resource_profile', 'capabilities', 'services'];
foreach ($catalog as $name => $entry) {
	foreach ($required as $field) {
		if (!array_key_exists($field, $entry)) {
			fwrite(STDERR, "{$name} is missing {$field}.\n");
			exit(1);
		}
	}
	if (!in_array($entry['resource_profile'], ['lightweight', 'moderate', 'intensive'], true)) {
		fwrite(STDERR, "{$name} has an invalid resource profile.\n");
		exit(1);
	}
}

$unknown = freesense_package_catalog_entry('not-a-real-package');
if ($unknown['support'] !== 'supported' || $unknown['category'] !== 'Other') {
	fwrite(STDERR, "Package metadata defaults changed unexpectedly.\n");
	exit(1);
}

$packageManager = file_get_contents(dirname(__DIR__) . '/src/usr/local/www/pkg_mgr.php');
if (strpos($packageManager, 'require_once("package_catalog.inc")') === false) {
	fwrite(STDERR, "Package Manager does not explicitly load its catalog helpers.\n");
	exit(1);
}

$updateManager = file_get_contents(dirname(__DIR__) . '/src/usr/local/www/pkg_mgr_install.php');
foreach (['Updates are pulled from this branch', 'Change branch'] as $removedText) {
	if (strpos($updateManager, $removedText) !== false) {
		fwrite(STDERR, "Update page still contains redundant branch controls.\n");
		exit(1);
	}
}
foreach (['/v1/releases/', 'render_update_notes', 'fa-wand-magic-sparkles'] as $requiredText) {
	if (strpos($updateManager, $requiredText) === false) {
		fwrite(STDERR, "Update page is missing classified release-note support.\n");
		exit(1);
	}
}

echo "Package catalog smoke test passed (" . count($catalog) . " entries).\n";
