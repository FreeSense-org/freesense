<?php
/* Standalone CI regression test for the WebUI Update Center routes; run with `php tests/RestApiUpdateCenterSmokeTest.php`. */

$root = dirname(__DIR__);
set_include_path(get_include_path() . PATH_SEPARATOR . realpath($root . '/src/etc/inc') .
    PATH_SEPARATOR . realpath($root . '/src/usr/local/FreeSense/include/www'));
if (!function_exists('gettext')) {
	function gettext($t) { return $t; }
}
/* No privilege database here: nobody but uid 0 is an administrator. */
function userHasPrivilege($user, $priv) { return false; }
require_once('restapi/framework.inc');
require_once('restapi/routes_v1.inc');
require_once('system_boot_environments.inc');

function check($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

function error_of(callable $fn) {
	try {
		$fn();
	} catch (RestApiError $e) {
		return $e;
	}
	return null;
}

/* ---- bectl list parsing ---- */
$plain = "default\tNR\t/\t1.21G\t2026-10-01 10:00\nmanual-1\t-\t-\t8K\t2026-10-05 12:30\nold_20261001\tT\t-\t512M\t2026-09-30 08:00\n";
$p = be_parse_bectl_list($plain);
check(array_keys($p['environments']) === array('default', 'manual-1', 'old_20261001') && $p['snapshots'] === array(),
    'bectl list -H: one row per boot environment');
check($p['environments']['default']['active'] === 'NR' && $p['environments']['default']['mountpoint'] === '/' &&
    $p['environments']['manual-1']['mountpoint'] === null, 'flags and mountpoint ("-" is none)');

$snaps = "default\t\t\t\t\n" .
    "zroot/ROOT/default\tNR\t/\t1.21G\t2026-10-01 10:00\n" .
    "default@2026-10-05-12:30:00-0\t-\t-\t120K\t2026-10-05 12:30\n" .
    "manual-1\t\t\t\t\n" .
    "zroot/ROOT/manual-1\t-\t-\t8K\t2026-10-05 12:30\n";
$p = be_parse_bectl_list($snaps);
check(array_keys($p['environments']) === array('default', 'manual-1') && $p['environments']['default']['active'] === 'NR',
    'bectl list -H -s: headings and dataset rows give the boot environments');
check(count($p['snapshots']) === 1 && $p['snapshots'][0]['name'] === 'default@2026-10-05-12:30:00-0' &&
    $p['snapshots'][0]['parent'] === 'default' && $p['snapshots'][0]['space'] === '120K', 'bectl list -H -s: snapshots with their environment');

$all = "BE/Dataset/Snapshot          Active Mountpoint Space Created\n\n" .
    "default\n" .
    "  zroot/ROOT/default        NR     /          1.21G  2026-10-01 10:00\n" .
    "  zroot/ROOT/default/var    -      /var       100M   2026-10-01 10:00\n" .
    "  zroot/ROOT/default/var@x  -      -          1K     2026-10-02 10:00\n" .
    "  default@snap1             -      -          64K    2026-10-03 10:00\n";
$p = be_parse_bectl_list(preg_replace('/^BE.*\n/', '', $all));
check(array_keys($p['environments']) === array('default') && count($p['snapshots']) === 1 &&
    $p['snapshots'][0]['name'] === 'default@snap1', 'bectl list -a / column layout: child datasets and their snapshots are skipped');

check(be_size_bytes('1.21G') === (int)round(1.21 * 1073741824) && be_size_bytes('8K') === 8192 && be_size_bytes('0') === 0 &&
    be_size_bytes('-') === 0 && be_size_bytes('512M') === 536870912, 'sizes in bytes');
check(be_created_time('2026-10-05T12:30:00Z') === gmmktime(12, 30, 0, 10, 5, 2026) && be_created_time('-') === 0 &&
    be_created_time('1760000000') === 1760000000, 'creation times');

/* ---- names ---- */
check(be_valid_name('default') && be_valid_name('before-firewall_change.1') && !be_valid_name('-x') && !be_valid_name('a b') &&
    !be_valid_name('a/b') && !be_valid_name(str_repeat('a', 65)) && !be_valid_name('a@b'), 'boot environment names');
check(be_valid_snapshot_name('default@2026-10-05-12:30:00-0') && !be_valid_snapshot_name('default') &&
    !be_valid_snapshot_name('default@x y') && !be_valid_snapshot_name('-a@b'), 'snapshot names');

/* ---- one-way rule ---- */
check(be_locked('1.0.1-RELEASE', '1.1.0-DEVELOPMENT') && be_locked('1.0.1', '1.1.0-DEVELOPMENT'), 'a 1.0 environment is locked on 1.1');
check(!be_locked('1.1.0', '1.1.0-DEVELOPMENT') && !be_locked('1.1.0_3', '1.1.0-DEVELOPMENT') && !be_locked('1.0.0', '1.0.1-RELEASE'),
    'older builds of the same release stay bootable (rollback)');
check(!be_locked(null, '1.1.0-DEVELOPMENT') && !be_locked('Unknown', '1.1.0-DEVELOPMENT') && !be_locked('1.2.0', '1.1.0-DEVELOPMENT'),
    'unknown and newer versions are not locked');

/* ---- API entries ---- */
$envs = array(
	array('name' => 'default', 'active_now' => true, 'active_reboot' => true, 'active_once' => false, 'space' => '1.21G',
	    'created' => '2026-10-01 10:00', 'metadata' => array()),
	array('name' => 'default_20260930080000', 'active_now' => false, 'active_reboot' => false, 'active_once' => false,
	    'space' => '512M', 'created' => '2026-09-30 08:00',
	    'metadata' => array('version' => '1.0.1', 'created' => '2026-09-30T08:00:00Z', 'description' => 'before 1.1', 'health' => 'healthy')),
	array('name' => 'manual-1', 'active_now' => false, 'active_reboot' => false, 'active_once' => true, 'space' => '8K',
	    'created' => '2026-10-05 12:30', 'metadata' => array('version' => '1.1.0', 'created' => '2026-10-05T12:30:00Z')),
);
$snap_rows = array(array('name' => 'default@snap1', 'parent' => 'default', 'snapshot' => 'snap1', 'space' => '64K',
    'created' => '2026-10-06 09:00'));
$list = be_api_list($envs, $snap_rows, '1.1.0-DEVELOPMENT');
$by = array();
foreach ($list as $e) {
	$by[$e['name']] = $e;
}
$keys = array('name', 'kind', 'active', 'next_boot', 'version', 'created', 'size', 'description', 'locked', 'parent');
foreach ($list as $e) {
	check(array_keys($e) === $keys, "entry {$e['name']} has the contract's fields");
	check(is_int($e['created']) && is_int($e['size']) && is_bool($e['locked']) && is_string($e['description']), "entry {$e['name']} types");
}
check(array_column($list, 'name') === array('default@snap1', 'manual-1', 'default', 'default_20260930080000'), 'newest first');
check($by['default']['active'] && $by['default']['next_boot'] && $by['default']['version'] === '1.1.0-DEVELOPMENT' &&
    !$by['default']['locked'] && $by['default']['kind'] === 'be' && $by['default']['parent'] === null,
    'the running environment: active, next boot, the running version');
check($by['manual-1']['next_boot'] && !$by['manual-1']['active'], 'activate-once counts as next boot');
check($by['default_20260930080000']['locked'] && $by['default_20260930080000']['description'] === 'before 1.1' &&
    $by['default_20260930080000']['size'] === 536870912 && $by['default_20260930080000']['created'] === gmmktime(8, 0, 0, 9, 30, 2026),
    'a 1.0 environment on 1.1 is locked; description, size and the freesense:created time');
check($by['default@snap1']['kind'] === 'snapshot' && $by['default@snap1']['parent'] === 'default' &&
    $by['default@snap1']['version'] === '1.1.0-DEVELOPMENT' && $by['default@snap1']['size'] === 65536, 'a snapshot entry');

check(restapi_be_activate_refusal($by['default@snap1'])->status === 409 &&
    restapi_be_activate_refusal($by['default_20260930080000'])->error_code === 'locked' &&
    restapi_be_activate_refusal($by['manual-1']) === null, 'snapshots and locked environments cannot be activated');
check(restapi_be_delete_refusal($by['default'])->status === 409 && restapi_be_delete_refusal($by['manual-1'])->status === 409 &&
    restapi_be_delete_refusal($by['default_20260930080000']) === null && restapi_be_delete_refusal($by['default@snap1']) === null,
    'the running and next-boot environments cannot be deleted');
$e = error_of(function () use ($list) { restapi_be_check_new_name('bad name', $list); });
check($e && $e->status === 422 && isset($e->payload()['error']['details']['fields']['name']), 'an invalid name is a 422 on fields.name');
$e = error_of(function () use ($list) { restapi_be_check_new_name('manual-1', $list); });
check($e && $e->status === 422 && isset($e->details['fields']['name']), 'a duplicate name is a 422 on fields.name');
check(restapi_be_check_new_name('new-one', $list) === 'new-one', 'a free name passes');
$e = error_of(function () { restapi_be_check_description("a\nb"); });
check($e && isset($e->details['fields']['description']), 'a description with control characters is refused');
check(error_of(function () use ($list) { restapi_be_find($list, 'nope'); })->status === 404, 'unknown names are 404');
check(be_pool_name('zroot/ROOT/default') === 'zroot' && be_pool_name('') === null, 'pool of the root dataset');

/* The 1.x page still sorts the running environment first. */
check(array_column(be_sort_environments($envs), 'name')[0] === 'default', 'page order: the running environment first');
list($cmds, $errs) = be_page_commands('edit', array('name' => 'a', 'target' => 'b', 'description' => 'd'));
check($cmds === array(array('describe', 'a', 'd'), array('rename', 'a', 'b')) && $errs === array(), 'page edit: describe, then rename');
list($cmds, $errs) = be_page_commands('create', array('name' => 'bad name'));
check($cmds === array() && $errs === array('Invalid boot environment name.'), 'page messages are unchanged');

/* ---- preflight ---- */
$facts = array(
	'version' => array('busy' => false, 'error' => null, 'installed' => '1.1.0_1', 'latest' => '1.1.0_2', 'update_available' => true),
	'operation_running' => false,
	'disk' => array('target' => 'zroot', 'zfs' => true, 'size' => 20 * RESTAPI_GIB, 'free' => 10 * RESTAPI_GIB),
	'boot_env' => array('available' => true, 'enabled' => true),
	'config_writable' => true,
);
$states = function ($checks) {
	$out = array();
	foreach ($checks as $c) {
		check(array_keys($c) === array('id', 'label', 'state', 'detail') && in_array($c['state'], array('pass', 'warn', 'fail'), true) &&
		    is_string($c['label']) && is_string($c['detail']), "check {$c['id']} shape");
		$out[$c['id']] = $c['state'];
	}
	return $out;
};
check($states(restapi_update_preflight_checks($facts)) === array('update' => 'pass', 'operation' => 'pass', 'disk' => 'pass',
    'boot_env' => 'pass', 'config' => 'pass', 'repo' => 'pass'), 'every check passes on a healthy system');
$s = $states(restapi_update_preflight_checks(array_replace_recursive($facts, array('version' => array('update_available' => false)))));
check($s['update'] === 'warn' && $s['repo'] === 'pass', 'up to date: a warning');
$s = $states(restapi_update_preflight_checks(array_replace_recursive($facts, array('version' => array('error' => 'FreeSense-upgrade error: 1')))));
check($s['repo'] === 'fail' && $s['update'] === 'warn', 'a failed version check fails the repository check');
$c = restapi_update_preflight_checks(array_replace_recursive($facts, array('version' => array('error' => 'DNS servers not available'))));
check($c[5]['detail'] === 'DNS servers not available', 'the repository check shows the error');
check($states(restapi_update_preflight_checks(array_replace($facts, array('operation_running' => true))))['operation'] === 'fail',
    'a running operation fails');
check($states(restapi_update_preflight_checks(array_replace_recursive($facts, array('disk' => array('free' => RESTAPI_GIB / 2)))))['disk'] === 'fail',
    'under 1 GiB fails');
check($states(restapi_update_preflight_checks(array_replace_recursive($facts, array('disk' => array('size' => 40 * RESTAPI_GIB,
    'free' => 5 * RESTAPI_GIB)))))['disk'] === 'fail', 'with boot environments, under 15% of the pool fails (the update would refuse)');
$ufs = array_replace($facts, array('disk' => array('target' => '/', 'zfs' => false, 'size' => 8 * RESTAPI_GIB, 'free' => (int)(1.5 * RESTAPI_GIB)),
    'boot_env' => array('available' => false, 'enabled' => true)));
$c = restapi_update_preflight_checks($ufs);
$s = $states($c);
check($s['disk'] === 'warn' && strpos($c[2]['detail'], '1.5 GiB free on /') === 0, 'under 2 GiB warns, with the numbers');
check($s['boot_env'] === 'warn', 'without ZFS there is no rollback: a warning');
check($states(restapi_update_preflight_checks(array_replace_recursive($facts, array('boot_env' => array('enabled' => false)))))['boot_env'] === 'warn',
    'boot environments turned off for updates: a warning');
check($states(restapi_update_preflight_checks(array_replace($facts, array('config_writable' => false))))['config'] === 'fail',
    'a read-only configuration fails');
check($states(restapi_update_preflight_checks(array_replace_recursive($facts, array('disk' => array('free' => null)))))['disk'] === 'warn',
    'unknown free space warns');

/* ---- changelog ---- */
check(restapi_release_doc_url('devel', 'amd64') === 'https://pkg.freesense.org/v1/releases/devel.json' &&
    restapi_release_doc_url('stable', 'arm64') === 'https://pkg.freesense.org/v1/releases/stable.arm64.json' &&
    restapi_release_doc_url('../x', 'amd64') === null, 'release document per channel (and arm64)');
$doc = array(
	'schema_version' => 'freesense.download/v4', 'channel' => 'devel', 'version' => '1.1.0', 'generation' => 42,
	'release_id' => '1.1.0-g42', 'published_at' => '2026-10-08T01:00:00Z',
	'changes' => array(array('type' => 'fix', 'title' => 'old list', 'scope' => 'System')),
	'release_notes' => array(
		'schema_version' => 'freesense.release-notes/v2', 'baseline_release_id' => '1.1.0-g41',
		'freesense' => array(
			array('type' => 'feature', 'title' => 'REST API: boot environments (#300)', 'scope' => 'System'),
			array('type' => 'security', 'title' => 'Patch openssl', 'scope' => 'System packages'),
			array('type' => 'weird', 'title' => 'Something'),
		),
		'platform' => array(
			'freebsd' => array('changed' => true, 'ports_changed' => false, 'from_commit' => str_repeat('a', 40), 'to_commit' => str_repeat('b', 40)),
			'packages' => array('available' => true, 'updated' => array(array('name' => 'openssl', 'from' => '3.0.1', 'to' => '3.0.2', 'origin' => 'security/openssl')),
			    'added' => array(array('name' => 'uplot', 'version' => '1.6', 'origin' => 'www/uplot')), 'removed' => array(),
			    'counts' => array('updated' => 1, 'added' => 1, 'removed' => 0), 'truncated' => false),
		),
	),
);
$cl = restapi_changelog_from_release($doc, RESTAPI_RELEASE_NOTES_PAGE);
check(array_keys($cl) === array('version', 'date', 'url', 'notes'), 'changelog shape');
check($cl['version'] === '1.1.0-g42' && $cl['date'] === '2026-10-08T01:00:00Z' && $cl['url'] === RESTAPI_RELEASE_NOTES_PAGE,
    'version (release id), date and the release notes page');
$expected = "# FreeSense changes\n- Feature: REST API: boot environments (#300) (System)\n- Security: Patch openssl (System packages)\n- Other: Something\n\n" .
    "# FreeBSD platform and packages\n- FreeBSD source snapshot updated: aaaaaaaaaaaa → bbbbbbbbbbbb\n- Updated: openssl 3.0.1 → 3.0.2\n- Added: uplot 1.6";
check($cl['notes'] === $expected, 'release notes v2 become "#" headings and "-" lists');
$legacy = $doc;
unset($legacy['release_notes']);
check(restapi_changelog_from_release($legacy, 'u')['notes'] === "# FreeSense changes\n- Fix: old list (System)", 'older documents: the changes list');
$many = $doc;
$many['release_notes']['platform']['packages']['updated'] = array_fill(0, 60, array('name' => 'p', 'from' => '1', 'to' => '2'));
check(substr_count(restapi_changelog_from_release($many, 'u')['notes'], "\n- Updated: p") === RESTAPI_NOTES_MAX_PACKAGES &&
    strpos(restapi_changelog_from_release($many, 'u')['notes'], '… and 11 more package changes') !== false, 'long package lists are capped');
$none = restapi_changelog_from_release(null, RESTAPI_RELEASE_NOTES_PAGE);
check($none === array('version' => null, 'date' => null, 'url' => RESTAPI_RELEASE_NOTES_PAGE, 'notes' => null),
    'no reachable document: notes null and the official page');
check(restapi_changelog_from_release(array('schema_version' => 'other'), 'u')['notes'] === null, 'a foreign document is ignored');
$empty = $doc;
$empty['release_notes']['freesense'] = array();
$empty['release_notes']['platform'] = array('freebsd' => array('changed' => false), 'packages' => array());
check(restapi_changelog_from_release($empty, 'u')['notes'] === null && restapi_changelog_from_release($empty, 'u')['version'] === '1.1.0-g42',
    'a release without changes has no notes');

/* ---- routes and guards ---- */
$routes = array();
foreach (restapi_routes_v1() as $r) {
	$routes["{$r['method']} {$r['path']}"] = $r;
}
$want = array(
	'GET /v1/system/boot-environments' => array('restapi_h_be_list', 'system_boot_environments.php', 'system.settings', false),
	'POST /v1/system/boot-environments' => array('restapi_h_be_create', 'system_boot_environments.php', 'system.settings', true),
	'POST /v1/system/boot-environments/{name}/activate' => array('restapi_h_be_activate', 'system_boot_environments.php', 'system.settings', true),
	'PATCH /v1/system/boot-environments/{name}' => array('restapi_h_be_update', 'system_boot_environments.php', 'system.settings', true),
	'DELETE /v1/system/boot-environments/{name}' => array('restapi_h_be_delete', 'system_boot_environments.php', 'system.settings', true),
	'GET /v1/system/update/preflight' => array('restapi_h_update_preflight', 'pkg_mgr_install.php', 'packages', false),
	'GET /v1/system/update/changelog' => array('restapi_h_update_changelog', 'pkg_mgr_install.php', 'packages', false),
);
foreach ($want as $key => $w) {
	check(isset($routes[$key]), "{$key} is registered");
	$r = $routes[$key];
	check($r['handler'] === $w[0] && $r['page'] === $w[1] && $r['area'] === $w[2] && $r['write'] === $w[3],
	    "{$key}: handler, privilege page, area and write flag");
}
check($routes['POST /v1/system/firmware/update']['page'] === 'pkg_mgr_install.php' &&
    $routes['POST /v1/system/firmware/update']['area'] === 'packages', 'the firmware route keeps its guard');
list($r, $params) = restapi_match(array_values($routes), 'POST', '/v1/system/boot-environments/default%40snap%3A1/activate');
check($r['handler'] === 'restapi_h_be_activate' && $params['name'] === 'default@snap:1', 'snapshot names reach the handlers');

$user = array('name' => 'operator', 'uid' => '2001');
foreach (array('restapi_h_be_create' => array(), 'restapi_h_be_activate' => array('name' => 'default'),
    'restapi_h_be_update' => array('name' => 'default'), 'restapi_h_be_delete' => array('name' => 'default')) as $h => $params) {
	$e = error_of(function () use ($h, $params, $user) {
		$h(array('user' => $user, 'params' => $params, 'query' => array(), 'body' => array('confirm' => true, 'name' => 'x')));
	});
	check($e && $e->status === 403 && $e->error_code === 'admin_required', "{$h} needs an administrator");
}
/* Without freesense-be (no ZFS) the routes answer 409 no_boot_environments. */
if (!is_executable(BOOTENV_TOOL)) {
	$admin = array('name' => 'admin', 'uid' => '0');
	$e = error_of(function () { restapi_h_be_list(array('params' => array(), 'query' => array(), 'body' => array())); });
	check($e && $e->status === 409 && $e->error_code === 'no_boot_environments', 'no boot environments: 409');
	$e = error_of(function () use ($admin) { restapi_h_be_activate(array('user' => $admin, 'params' => array('name' => 'x'),
	    'query' => array(), 'body' => array())); });
	check($e && $e->status === 400 && $e->error_code === 'confirm_required', 'activation needs {"confirm": true}');
}

/* The start responses name the job, keeping their fields. */
$ops = file_get_contents("{$root}/src/etc/inc/restapi/routes_operations.inc");
check(strpos($ops, "array('started' => true, 'id' => 'packages', 'status_url' => '/api/v1/packages/operation')") !== false,
    'package operations and the system update answer with the job id');
check(in_array('update-center', restapi_capabilities_list(), true), 'the update-center capability is reported');

/* The 1.x page uses the shared functions. */
$page = file_get_contents("{$root}/src/usr/local/www/system_boot_environments.php");
check(strpos($page, "require_once('system_boot_environments.inc');") !== false && strpos($page, 'function be_command') === false &&
    strpos($page, 'be_page_action($action, $_POST)') !== false, 'the page calls the shared functions');
check(strpos(file_get_contents("{$root}/src/usr/local/www/api/index.php"), "require_once('system_boot_environments.inc');") !== false,
    'the API front controller loads them');

echo "REST API update center smoke test passed.\n";

function restapi_capabilities_list() {
	$src = file_get_contents(dirname(__DIR__) . '/src/etc/inc/restapi.inc');
	preg_match("/function restapi_capabilities\(\) \{\s*return array\(([^)]*)\)/", $src, $m);
	return array_map(function ($s) { return trim($s, " '\t\n"); }, explode(',', $m[1] ?? ''));
}
