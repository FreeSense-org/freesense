<?php
/*
 * Standalone CI regression test for the dashboard (index.php,
 * src/usr/local/FreeSense/include/www/dashboard.inc, the widgets in
 * src/usr/local/www/widgets/); run with `php tests/DashboardSmokeTest.php`.
 */

$root = dirname(__DIR__);
$www = "{$root}/src/usr/local/www";
$inc = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/dashboard.inc");

function check($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

function fn_source($code, $name) {
	$start = strpos($code, "function {$name}(");
	check($start !== false, "function {$name}() exists");
	return substr($code, $start, strpos($code, "\n}\n", $start) - $start) . "\n}\n";
}

define('DASHBOARD_SIZES', ['sm', 'md', 'lg', 'xl', 'full']);
define('DASHBOARD_SETTING_TYPES', ['select', 'checkbox', 'number', 'text', 'interface', 'interfaces']);
eval(fn_source($inc, 'dashboard_manifest_parse'));
eval(fn_source($inc, 'dashboard_default_layout'));

/* Manifest parsing */
check(dashboard_manifest_parse('{"title":"X","page":"index.php"}', 'x') !== null, 'a minimal manifest');
check(dashboard_manifest_parse('{"title":"X","page":"index.php"}', '../x') === null, 'the type must be a plain name');
check(dashboard_manifest_parse('{"type":"y","title":"X","page":"index.php"}', 'x') === null, 'the type must match the file');
check(dashboard_manifest_parse('{"title":"X"}', 'x') === null, 'a manifest needs the page whose privilege it uses');
check(dashboard_manifest_parse('{"title":"X","page":"javascript:alert(1)"}', 'x') === null, 'the page is a .php page name');
check(dashboard_manifest_parse('not json', 'x') === null, 'bad JSON');
$m = dashboard_manifest_parse('{"title":"X","page":"index.php","sizes":["huge","lg","md"],"size":"sm","every":99999,"icon":"bad icon",' .
    '"link":"javascript:x","settings":[{"key":"title","type":"text","label":"T"},{"key":"ok","type":"select","label":"S","options":[[1,"a"],["bad"]]},' .
    '{"key":"Bad Key","type":"text","label":"B"},{"key":"e","type":"eval","label":"E"}]}', 'x');
check($m['sizes'] === ['md', 'lg'] && $m['size'] === 'md' && $m['every'] === 3600 && $m['icon'] === 'fa-puzzle-piece' && $m['link'] === '',
    'sizes, size, refresh, icon and link are normalised');
check(count($m['settings']) === 1 && $m['settings'][0]['key'] === 'ok' && $m['settings'][0]['options'] === [[1, 'a']],
    'only valid settings (not title, not unknown types or keys) and options survive');

/* Every core widget: a valid manifest and a script that registers it */
$catalogue = [];
foreach (glob("{$www}/widgets/*.widget.json") as $file) {
	$type = basename($file, '.widget.json');
	$m = dashboard_manifest_parse(file_get_contents($file), $type);
	check($m !== null, "{$type}.widget.json is a valid manifest");
	check(file_exists("{$root}/src/usr/local/www/{$m['page']}"), "{$type} names an existing page ({$m['page']})");
	$js = (string)@file_get_contents("{$www}/widgets/{$type}.js");
	check(strpos($js, "D.define('{$type}', {") !== false, "{$type}.js registers the widget");
	check(strpos($js, '.innerHTML') === false && strpos($js, 'eval(') === false, "{$type}.js builds its DOM without innerHTML or eval");
	$catalogue[$type] = $m;
}
foreach (['system', 'resources', 'interfaces', 'interface-detail', 'traffic', 'gateways', 'services', 'thermal', 'storage', 'zfs', 'notices'] as $type) {
	check(isset($catalogue[$type]), "core widget {$type} exists");
}

/* The default layout follows the 1.x two-column default */
$layout = dashboard_default_layout($catalogue);
check(array_column($layout, 'type') === ['system', 'interfaces', 'resources', 'gateways', 'traffic', 'services'] &&
    array_unique(array_column($layout, 'size')) === ['lg'], 'the default layout: System, Resources, Traffic beside Interfaces, Gateways, Services');
check((array)$layout[4]['settings'] === ['interface' => 'wan'], 'the default traffic widget shows WAN');
check(count(dashboard_default_layout(['services' => $catalogue['services']])) === 1, 'widgets the user may not use are left out');

/* The page shell */
$index = file_get_contents("{$www}/index.php");
check(strpos($index, "require_once('dashboard.inc');") !== false && strpos($index, '$catalogue = dashboard_catalogue();') !== false, 'index.php lists the catalogue');
check(strpos($index, 'JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT') !== false, 'the page config is safe inside a script tag');
check(strpos($index, 'data-fs-dash-reset') !== false && strpos($index, 'fs-dash-editbar') !== false, 'the edit bar has Reset to default');
check(strpos($index, 'register_ajax') === false && strpos($index, 'widgets/sequence') === false && strpos($index, '.widget.php') === false,
    'no trace of the 1.x widget system');
check(strpos($inc, '!isAllowedPage($m[\'page\'])') !== false, 'the catalogue only offers widgets whose page the user may open');
$core = file_get_contents("{$www}/js/fs-core.js");
check(strpos($core, "fetch(url, init)") !== false && strpos($core, "'X-CSRF-Token': csrf()") !== false && strpos($core, "'/api/v1/batch'") !== false,
    'the API client uses fetch with the session token and batches GETs');
$head = file_get_contents("{$www}/head.inc");
$foot = file_get_contents("{$www}/foot.inc");
check(strpos($head, '$fs_page_css') !== false && strpos($foot, '$fs_page_scripts') !== false, 'pages can add stylesheets and scripts');
check(strpos($head, 'dashboardcolumns') === false && strpos($head, 'btnstore') === false, 'head.inc has no dashboard column code');

/* No 1.x widget settings remain */
foreach (['src/etc/inc/auth.inc', 'src/etc/inc/util.inc', 'src/usr/local/www/guiconfig.inc', 'src/usr/local/www/system.php',
    'src/usr/local/www/system_user_settings.php', 'src/usr/local/FreeSense/include/www/system_general.inc'] as $f) {
	$s = file_get_contents("{$root}/{$f}");
	check(strpos($s, 'save_widget_settings') === false && strpos($s, 'is_valid_widgetkey(') === false && strpos($s, 'gen_dashboardcolumns_field') === false,
	    "{$f} has no 1.x widget helpers");
}

/* Config upgrade and the ZFS health job */
$up = file_get_contents("{$root}/src/etc/inc/upgrade_config.inc");
$fn = fn_source($up, 'upgrade_245_to_246');
check(strpos($fn, "config_del_path('widgets');") !== false && strpos($fn, "freesense-zfs-notices") !== false, 'the 24.6 upgrade drops the widget config and installs the ZFS check');
check(strpos(file_get_contents("{$root}/src/etc/inc/globals.inc"), "'latest_config' => '24.6',") !== false, 'config version 24.6');
$zfs = file_get_contents("{$root}/src/usr/local/sbin/freesense-zfs-notices");
function gettext($s) { return $s; }
eval(fn_source($zfs, 'zfs_notices_message'));
check(zfs_notices_message(['name' => 'z', 'health' => 'ONLINE', 'errors' => 'No known data errors', 'devices' => [['read_errors' => 0]]]) === null, 'a healthy pool');
check(strpos((string)zfs_notices_message(['name' => 'z', 'health' => 'DEGRADED', 'devices' => []]), 'health DEGRADED') !== false, 'a degraded pool');
check(strpos((string)zfs_notices_message(['name' => 'z', 'health' => 'ONLINE', 'devices' => [['checksum_errors' => 3]]]), 'device errors 3') !== false, 'device errors');

echo "DashboardSmokeTest: OK\n";
