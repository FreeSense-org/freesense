<?php
/* Standalone CI regression test; run with `php tests/RestApiSmokeTest.php`. */

$root = dirname(__DIR__);
set_include_path(get_include_path() . PATH_SEPARATOR . realpath($root . '/src/etc/inc'));
require_once('restapi/framework.inc');
require_once('restapi/routes_v1.inc');

function check_api($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

function api_error_status(callable $fn) {
	try {
		$fn();
	} catch (RestApiError $e) {
		return $e->status;
	}
	return null;
}

/* Routing */
$routes = array(
	restapi_route('GET', '/v1/things', 'h_list', array('page' => 'things.php', 'area' => 'status')),
	restapi_route('POST', '/v1/things/{id}/run', 'h_run', array('page' => 'things.php', 'area' => 'status', 'write' => true)),
	restapi_route('GET', '/v1/things/{id}', 'h_get', array('page' => 'things.php', 'area' => 'status')),
);
list($route, $params) = restapi_match($routes, 'GET', '/v1/things/abc123');
check_api($route['handler'] === 'h_get' && $params === array('id' => 'abc123'), 'path parameter extraction');
list($route, $params) = restapi_match($routes, 'post', '/v1/things/9d1b238f04e4/run');
check_api($route['handler'] === 'h_run' && $route['write'] && $params['id'] === '9d1b238f04e4', 'method is case-insensitive');
check_api(api_error_status(function () use ($routes) { restapi_match($routes, 'DELETE', '/v1/things'); }) === 405,
    'known path with another method is 405');
check_api(api_error_status(function () use ($routes) { restapi_match($routes, 'GET', '/v1/nothing'); }) === 404,
    'unknown path is 404');
check_api(api_error_status(function () use ($routes) { restapi_match($routes, 'GET', '/v1/things/a/b'); }) === 404,
    'parameters never span path segments');
check_api(api_error_status(function () use ($routes) { restapi_match($routes, 'GET', '/v1/things/..%2f'); }) === 404,
    'encoded traversal is not a valid parameter');
$rejected = false;
try {
	restapi_route('GET', '/v1/x', 'h', array());
} catch (InvalidArgumentException $e) {
	$rejected = true;
}
check_api($rejected, 'a route without a privilege page is rejected');

check_api($route['scope'] === 'status:write' && $routes[0]['scope'] === 'status:read', 'route scope is <area>:<action>');
$rejected = false;
try {
	restapi_route('GET', '/v1/x', 'h', array('page' => 'x.php', 'area' => 'nope'));
} catch (InvalidArgumentException $e) {
	$rejected = true;
}
check_api($rejected, 'a route with an unknown permission area is rejected');

/* RBAC scopes */
check_api(restapi_area_privilege('firewall.aliases', 'write') === 'api-firewall-aliases-write', 'area privilege naming');
check_api(restapi_parse_scopes('status:read bogus:write, firewall.nat:write status:read') === array('status:read', 'firewall.nat:write'),
    'scope parsing drops unknown and duplicate scopes');
check_api(restapi_scopes_allow(array(), 'firewall.rules', 'write'), 'an unlimited key allows every scope');
check_api(restapi_scopes_allow(array('firewall.rules:write'), 'firewall.rules', 'read'), 'a write scope includes read');
check_api(!restapi_scopes_allow(array('firewall.rules:read'), 'firewall.rules', 'write'), 'a read scope does not allow writes');
check_api(!restapi_scopes_allow(array('status:read'), 'firewall.rules', 'read'), 'scopes of another area do not apply');
check_api(count(restapi_all_scopes()) === 2 * count(restapi_areas()), 'every area has read and write scopes');
if (!function_exists('gettext')) {
	/* The CI image has no gettext extension. */
	function gettext($text) {
		return $text;
	}
}
$priv_list = array();
require("{$root}/src/etc/inc/priv/restapi.priv.inc");
foreach (array_keys(restapi_areas()) as $area) {
	foreach (restapi_actions() as $action) {
		$p = restapi_area_privilege($area, $action);
		check_api(isset($priv_list[$p]['name']) && strpos($priv_list[$p]['name'], 'REST API - ') === 0, "privilege {$p} is defined");
		check_api(!isset($priv_list[$p]['match']), "privilege {$p} grants no GUI page");
	}
}

/* JSON bodies */
check_api(restapi_decode_body('') === array(), 'empty body is an empty object');
check_api(restapi_decode_body('{"force":true}') === array('force' => true), 'object body');
check_api(api_error_status(function () { restapi_decode_body('[1,2]'); }) === 400, 'array body is rejected');
check_api(api_error_status(function () { restapi_decode_body('{oops'); }) === 400, 'invalid JSON is rejected');

/* Tokens */
$secret = restapi_generate_secret();
check_api(strlen($secret) === 43 && preg_match('/^[A-Za-z0-9_-]+$/D', $secret), 'secret is 32 random bytes, base64url');
$token = restapi_format_token('0123456789ab', $secret);
check_api(restapi_parse_authorization("Bearer {$token}") === array('0123456789ab', $secret), 'token round trip');
check_api(restapi_parse_authorization("bearer  {$token} ") === array('0123456789ab', $secret), 'scheme is case-insensitive');
check_api(restapi_parse_authorization($token) === null, 'Bearer scheme is required');
check_api(restapi_parse_authorization("Bearer fsk_0123456789ab_short") === null, 'short secret is rejected');
check_api(restapi_parse_authorization("Basic YWRtaW46ZnJlZXNlbnNl") === null, 'basic auth is not accepted');
$hash = restapi_hash_secret($secret);
check_api(strlen($hash) === 64 && restapi_verify_secret($secret, $hash), 'secret verifies against its hash');
check_api(!restapi_verify_secret($secret . 'x', $hash) && !restapi_verify_secret($secret, ''), 'wrong secret or empty hash fails');

/* Networks */
check_api(restapi_ip_in_network('192.168.1.77', '192.168.1.0/24'), 'IPv4 CIDR match');
check_api(!restapi_ip_in_network('192.168.2.1', '192.168.1.0/24'), 'IPv4 CIDR miss');
check_api(restapi_ip_in_network('10.1.2.3', '10.0.0.0/9') && !restapi_ip_in_network('10.128.0.1', '10.0.0.0/9'), 'non-octet prefix');
check_api(restapi_ip_in_network('2001:db8::5', '2001:db8::/48') && !restapi_ip_in_network('2001:db9::5', '2001:db8::/48'), 'IPv6 CIDR');
check_api(!restapi_ip_in_network('192.168.1.1', '2001:db8::/32'), 'address families never match each other');
check_api(restapi_ip_in_network('203.0.113.9', '203.0.113.9') && !restapi_ip_in_network('203.0.113.8', '203.0.113.9'), 'single address');
check_api(restapi_ip_allowed('198.51.100.1', array()), 'an empty allow list allows any address');
check_api(restapi_parse_networks("10.0.0.0/8, 192.168.1.0/24\n2001:db8::/32") === array('10.0.0.0/8', '192.168.1.0/24', '2001:db8::/32'),
    'network list parsing');
check_api(restapi_valid_network('10.0.0.0/8') && !restapi_valid_network('10.0.0.0/33') && !restapi_valid_network('host.example'),
    'network validation');

/* Validation errors from GUI save functions */
$e = restapi_validation_error(array('The &quot;name&quot; is <b>invalid</b>.'));
check_api($e->status === 422 && $e->payload()['error']['details']['messages'] === array('The "name" is invalid.'),
    'input_errors become a 422 with plain-text messages');

/* The v1 route table */
$v1 = restapi_routes_v1();
$seen = array();
foreach ($v1 as $r) {
	check_api(!empty($r['page']), "{$r['method']} {$r['path']} declares a privilege page");
	check_api(function_exists($r['handler']), "{$r['method']} {$r['path']} handler {$r['handler']} exists");
	check_api(($r['method'] === 'GET') || $r['write'], "{$r['method']} {$r['path']} that changes state is marked write");
	check_api(($r['page'] === '@authenticated') || array_key_exists($r['area'], restapi_areas()), "{$r['method']} {$r['path']} has a permission area");
	$key = "{$r['method']} {$r['path']}";
	check_api(!isset($seen[$key]), "duplicate route {$key}");
	$seen[$key] = true;
	if ($r['page'] !== '@authenticated') {
		check_api(is_file("{$root}/src/usr/local/www/{$r['page']}"), "{$key} privilege page {$r['page']} exists");
	}
}
$spec = restapi_openapi($v1, 'test');
check_api(json_encode($spec) !== false && $spec['openapi'] === '3.0.3', 'OpenAPI document encodes');
check_api(isset($spec['paths']['/api/v1/backup/remote/targets/{id}/test']['post']), 'OpenAPI has templated paths under /api');
check_api(count($spec['paths']) === count(array_unique(array_map(function ($r) { return $r['path']; }, $v1))),
    'every route path is in the OpenAPI document');

/* Firewall: API bodies become the GUI forms' fields */
$alias_post = restapi_alias_post(array('name' => 'lab', 'type' => 'network', 'description' => 'd',
    'entries' => array(array('address' => '10.0.0.0/8', 'detail' => 'a'), array('address' => '192.0.2.1', 'detail' => ''))), 'old');
check_api($alias_post['address0'] === '10.0.0.0' && $alias_post['address_subnet0'] === '8' &&
    $alias_post['address1'] === '192.0.2.1' && $alias_post['address_subnet1'] === '' &&
    $alias_post['detail0'] === 'a' && $alias_post['origname'] === 'old' && $alias_post['descr'] === 'd',
    'network alias entries split into address/address_subnet rows');
$host_post = restapi_alias_post(array('name' => 'h', 'type' => 'host', 'entries' => array(array('address' => '10.0.0.0/8'))), '');
check_api($host_post['address0'] === '10.0.0.0/8' && $host_post['address_subnet0'] === '',
    'host aliases are passed through for the GUI validation to judge');
$url_post = restapi_alias_post(array('name' => 'u', 'type' => 'urltable', 'update_frequency' => 3,
    'entries' => array(array('address' => 'https://example.org/list.txt'))), '');
check_api($url_post['address_subnet0'] === '3', 'urltable update frequency goes in address_subnet0');
check_api(api_error_status(function () { restapi_alias_post(array('type' => 'host', 'entries' => 'x'), ''); }) === 400,
    'entries must be a list');

$sched_post = restapi_sched_post(array('name' => 's', 'ranges' => array(
    array('weekdays' => array(1, 5), 'start' => '08:00', 'stop' => '17:00', 'description' => 'w'),
    array('dates' => array('12-24', '2026-1-5'), 'start' => '9:00', 'stop' => '10:00'))));
check_api($sched_post['schedule0'] === '1,5' && $sched_post['starttime0'] === '08:00' && $sched_post['timedescr0'] === 'w',
    'weekday ranges become a position list');
check_api($sched_post['schedule1'] === 'w1p1-m12d24,w1p1-m1d5',
    'dates become the GUI month/day tokens that saveSchedule() parses');
check_api(api_error_status(function () { restapi_sched_post(array('ranges' => array(array('dates' => array('Dec 24'))))); }) === 400,
    'invalid dates are rejected');

check_api(restapi_body_as_post(array('a' => true, 'b' => false, 'c' => 5, 'd' => array('x', 'y'))) === array('a' => 'yes', 'c' => '5', 'd' => array('x', 'y')),
    'booleans become checkbox fields like a form post');
check_api(api_error_status(function () { restapi_body_as_post(array('o' => array('k' => 'v'))); }) === 400, 'objects are not form fields');
check_api(restapi_json_result('{"input_errors":["x"]}') === array('input_errors' => array('x')) &&
    restapi_json_result(array('k' => 1)) === array('k' => 1), 'JSON-mode GUI results are decoded');

/* Refactors that let the GUI and the API share one code path */
$guiconfig = file_get_contents("{$root}/src/usr/local/www/guiconfig.inc");
$util = file_get_contents("{$root}/src/etc/inc/util.inc");
check_api(strpos($guiconfig, 'function do_input_validation(') === false && strpos($util, 'function do_input_validation(') !== false,
    'do_input_validation() lives in util.inc');
$sched_page = file_get_contents("{$root}/src/usr/local/www/firewall_schedule_edit.php");
check_api(strpos($sched_page, 'saveSchedule($_POST') !== false && strpos($sched_page, "write_config(") === false,
    'the schedule edit page saves through saveSchedule()');
$routes_fw = file_get_contents("{$root}/src/etc/inc/restapi/routes_firewall.inc");
check_api(preg_match('/save(Alias|NATrule|VIP|Schedule)\(/', $routes_fw) === 1 &&
    substr_count($routes_fw, 'write_config(') === 0, 'firewall API writes only through the GUI save functions');

/* Static guards */
$front = file_get_contents("{$root}/src/usr/local/www/api/index.php");
check_api(strpos($front, 'guiconfig.inc') === false, 'the API front controller must not load the GUI session/CSRF layer');
check_api(strpos($front, 'restapi_authenticate(') < strpos($front, 'call_user_func($route[\'handler\']'),
    'authentication happens before any handler runs');
check_api(strpos($front, 'restapi_authorize(') < strpos($front, 'call_user_func($route[\'handler\']'),
    'authorization happens before any handler runs');
$system = file_get_contents("{$root}/src/etc/inc/system.inc");
check_api(strpos($system, 'location ^~ /api/ {') !== false &&
    strpos($system, 'fastcgi_param  SCRIPT_FILENAME  \$document_root/api/index.php;') !== false,
    'nginx sends every /api/ request to the front controller');
$restapi = file_get_contents("{$root}/src/etc/inc/restapi.inc");
check_api(strpos($restapi, "'hash' => restapi_hash_secret(\$secret)") !== false && strpos($restapi, "'secret' =>") === false,
    'only the hash of a token secret is stored');
check_api(strpos($restapi, 'LOG_AUTH_EVENT_ERROR') !== false, 'failed API authentication is logged for login protection');

/* Per-user access: a key works only while its owner holds the access privilege. */
check_api(preg_match("/define\('RESTAPI_ACCESS_PAGE', '([a-z_]+\.php)'\)/", $restapi, $m) === 1, 'access page is defined');
$access_page = file_get_contents("{$root}/src/usr/local/www/{$m[1]}");
check_api(strpos($access_page, '##|*IDENT=page-system-restapi-keys') !== false &&
    strpos($access_page, "##|*MATCH={$m[1]}*") !== false, 'the access privilege matches the access page');
$auth_fn = substr($restapi, strpos($restapi, 'function restapi_authenticate('));
$auth_fn = substr($auth_fn, 0, strpos($auth_fn, "\n}\n"));
check_api(strpos($auth_fn, 'restapi_user_has_access($user)') !== false, 'authentication requires per-user API access');
check_api(strpos($auth_fn, 'restapi_user_has_access($user)') < strpos($auth_fn, 'restapi_touch_last_used('),
    'a key of a user without access is refused before it is marked used');
$create_fn = substr($restapi, strpos($restapi, 'function restapi_create_token('));
check_api(strpos(substr($create_fn, 0, strpos($create_fn, "\n}\n")), 'restapi_user_has_access($user)') !== false,
    'keys can only be created for users with API access');
$defs = file_get_contents("{$root}/src/etc/inc/priv.defs.inc");
foreach (array('page-system-restapi', 'page-system-restapi-keys') as $priv) {
	check_api(strpos($defs, "\$priv_list['{$priv}'] = array();") !== false, "{$priv} is in priv.defs.inc");
}
$self = file_get_contents("{$root}/src/usr/local/www/system_restapi_keys.php");
check_api(strpos($self, "\$post['username'] = \$me;") !== false && strpos($self, "restapi_revoke_token((string)(\$_POST['id'] ?? ''), \$me)") !== false,
    'My API Keys only creates and revokes the signed-in user\'s own keys');

echo "REST API smoke test passed.\n";
