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
check_api(api_error_status(function () use ($routes) { restapi_match($routes, 'GET', '/v1/things/a%2Fb'); }) === 404 &&
    api_error_status(function () use ($routes) { restapi_match($routes, 'GET', '/v1/things/a%0Ab'); }) === 404 &&
    api_error_status(function () use ($routes) { restapi_match($routes, 'GET', '/v1/things/%00'); }) === 404,
    'an encoded slash or control character is never a parameter');
list(, $params) = restapi_match($routes, 'GET', '/v1/things/vpn%40example.org');
check_api($params === array('id' => 'vpn@example.org'), 'URL-encoded parameters are decoded (e.g. an @ in an identifier)');
list(, $params) = restapi_match($routes, 'GET', '/v1/things/user@example.org');
check_api($params === array('id' => 'user@example.org'), 'a literal @ is accepted');
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
	check_api(($r['method'] === 'GET') || $r['write'] || ($r['safe'] && ($r['method'] === 'POST')),
	    "{$r['method']} {$r['path']} that changes state is marked write (only a POST lookup may be marked safe)");
	check_api(($r['page'] === '@authenticated') || array_key_exists($r['area'], restapi_areas()), "{$r['method']} {$r['path']} has a permission area");
	$key = "{$r['method']} {$r['path']}";
	check_api(!isset($seen[$key]), "duplicate route {$key}");
	$seen[$key] = true;
	if ($r['page'] !== '@authenticated') {
		check_api(is_file("{$root}/src/usr/local/www/" . strtok($r['page'], '?')), "{$key} privilege page {$r['page']} exists");
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

/* The API front controller loads every shared page include at once, so no
 * function may be declared in two of them. */
$declared = array();
foreach (glob("{$root}/src/usr/local/FreeSense/include/www/*.inc") as $inc) {
	preg_match_all('/^function\s+([A-Za-z0-9_]+)\s*\(/m', file_get_contents($inc), $m);
	foreach ($m[1] as $fn) {
		$key = strtolower($fn);
		check_api(!isset($declared[$key]), "function {$fn}() is declared in both " . ($declared[$key] ?? "") . " and " . basename($inc));
		$declared[$key] = basename($inc);
	}
}
foreach (array('alias-utils.inc', 'firewall_nat.inc', 'firewall_nat_1to1.inc', 'firewall_nat_out.inc',
    'firewall_nat_npt.inc', 'firewall_virtual_ip.inc', 'firewall_schedule.inc', 'firewall_rules.inc') as $inc) {
	check_api(strpos(file_get_contents("{$root}/src/usr/local/www/api/index.php"), "require_once('{$inc}');") !== false,
	    "the API front controller loads {$inc}");
}

/* The rule pages are thin wrappers around firewall_rules.inc */
$rules_edit = file_get_contents("{$root}/src/usr/local/www/firewall_rules_edit.php");
check_api(strpos($rules_edit, 'saveFilterRule($_POST') !== false && strpos($rules_edit, 'write_config(') === false &&
    strpos($rules_edit, 'add_filter_rules(') === false, 'firewall_rules_edit.php saves through saveFilterRule()');
$rules_list = file_get_contents("{$root}/src/usr/local/www/firewall_rules.php");
foreach (array('applyFilterRules(', 'deleteFilterRule(', 'deleteFilterRules(', 'toggleFilterRule(', 'toggleFilterRules(',
    'reorderFilterRules(') as $call) {
	check_api(strpos($rules_list, $call) !== false, "firewall_rules.php uses {$call})");
}
check_api(strpos($rules_list, 'set_filter_rules_order(') === false, 'firewall_rules.php no longer reorders inline');
$rules_inc = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/firewall_rules.inc");
check_api(strpos($rules_inc, '$_POST[') === false, 'firewall_rules.inc reads its form fields from $post, not $_POST');
check_api(substr_count($rules_inc, 'firewall_rule_run_hook("/usr/local/pkg/firewall_rules/') === 2,
    'both firewall_rules custom code hooks still run');
check_api(strpos(file_get_contents("{$root}/src/usr/local/www/guiconfig.inc"), '$firewall_rules_dscp_types = array(') === false &&
    strpos($rules_inc, '$firewall_rules_dscp_types = array(') !== false, 'the DSCP list moved with the rule editor');

/* Routing: stored entries become the edit forms' fields */
check_api(isset(restapi_areas()['routing']), 'the routing permission area exists');
$gw_fields = restapi_routing_gateway_fields(array('name' => 'LAN_GW', 'friendlyiface' => 'lan', 'interface' => 'em1',
    'gateway' => '192.0.2.1', 'monitor' => '192.0.2.1', 'ipprotocol' => 'inet', 'weight' => '1', 'descr' => 'd',
    'monitor_disable' => true, 'force_down' => false, 'attribute' => 3, 'isdefaultgw' => true));
check_api($gw_fields['interface'] === 'lan' && $gw_fields['friendlyiface'] === 'lan',
    'the gateway interface field is the friendly name the edit page preselects');
check_api($gw_fields['monitor'] === '' && $gw_fields['gateway'] === '192.0.2.1',
    'a monitor IP equal to the gateway shows empty, like the edit page');
check_api(($gw_fields['monitor_disable'] ?? null) === 'yes' && !isset($gw_fields['force_down']) && !isset($gw_fields['disabled']),
    'gateway checkboxes are "yes" when ticked and absent otherwise');
check_api($gw_fields['attribute'] === '3' && !isset($gw_fields['isdefaultgw']), 'only form fields are returned');
$dyn_fields = restapi_routing_gateway_fields(array('name' => 'WAN_DHCP', 'friendlyiface' => 'wan', 'gateway' => '198.51.100.1',
    'monitor' => '198.51.100.9', 'dynamic' => true, 'attribute' => 0));
check_api($dyn_fields['gateway'] === 'dynamic' && $dyn_fields['monitor'] === '198.51.100.9', 'a dynamic gateway posts "dynamic"');

$group = restapi_routing_group_out(array('name' => 'Failover', 'descr' => 'f', 'trigger' => 'down',
    'item' => array('WAN_DHCP|1|address', 'LTE|2|')));
check_api($group['items'] === array(array('gateway' => 'WAN_DHCP', 'tier' => 1, 'vip' => 'address'),
    array('gateway' => 'LTE', 'tier' => 2, 'vip' => '')) && $group['keep_failover_states'] === '',
    'gateway group members parse from "gateway|tier|vip"');
$group_post = restapi_routing_group_post($group, array('WAN_DHCP', 'LTE', 'OTHER'));
check_api($group_post['WAN_DHCP'] === '1' && $group_post['WAN_DHCP_vip'] === 'address' && $group_post['LTE'] === '2' &&
    !isset($group_post['OTHER']) && $group_post['name'] === 'Failover' && $group_post['trigger'] === 'down',
    'gateway group members become the per-gateway tier selects of the edit form');
check_api(api_error_status(function () { restapi_routing_group_post(array('items' => array(array('gateway' => 'NOPE', 'tier' => 1))), array('WAN')); }) === 422,
    'an unknown group member is rejected');
check_api(api_error_status(function () { restapi_routing_group_post(array('items' => array(array('gateway' => 'WAN', 'tier' => 7))), array('WAN')); }) === 422,
    'a tier outside 1-5 is rejected');
check_api(api_error_status(function () { restapi_routing_group_post(array('items' => 'WAN'), array('WAN')); }) === 400,
    'group items must be a list');

$route_fields = restapi_routing_route_fields(array('network' => '10.250.0.0/24', 'gateway' => 'GW', 'descr' => 'r', 'disabled' => true));
check_api($route_fields === array('network' => '10.250.0.0', 'network_subnet' => '24', 'gateway' => 'GW', 'descr' => 'r', 'disabled' => 'yes'),
    'a static route splits into network and network_subnet like the edit page');
check_api(restapi_routing_route_fields(array('network' => 'MyAlias', 'gateway' => 'GW'))['network_subnet'] === '',
    'an alias destination has no bit count');
check_api(restapi_routing_split_network(array('network' => '10.1.0.0/16')) === array('network' => '10.1.0.0', 'network_subnet' => '16') &&
    restapi_routing_split_network(array('network' => '10.1.0.0/16', 'network_subnet' => '24'))['network_subnet'] === '24',
    '"network" may carry the bit count');
check_api(restapi_routing_merge(array('descr' => 'new', 'disabled' => false), array('descr' => 'old', 'disabled' => 'yes', 'gateway' => 'GW')) ===
    array('descr' => 'new', 'gateway' => 'GW'), 'an update keeps omitted fields and unticks checkboxes sent as false');

/* The routing pages are thin wrappers around system_routing.inc */
$routing_inc = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/system_routing.inc");
check_api(strpos($routing_inc, '$_POST') === false && strpos($routing_inc, '$_REQUEST') === false,
    'system_routing.inc takes its form fields as parameters');
check_api(strpos($routing_inc, "\$route[0]['gateway']") === false && strpos($routing_inc, "\$rgateway = \$route['gateway'];") !== false,
    'a static route edit compares the new gateway with the old one');
foreach (array('system_gateways.php', 'system_gateway_groups.php', 'system_routes.php') as $page) {
	$src = file_get_contents("{$root}/src/usr/local/www/{$page}");
	check_api(strpos($src, 'routing_apply_changes()') !== false && strpos($src, '.system_routes.apply') === false &&
	    strpos($src, 'system_routing_configure(') === false, "{$page} applies through routing_apply_changes()");
	check_api(strpos($src, 'write_config(') === false && strpos($src, 'config_del_path(') === false,
	    "{$page} changes the configuration only through system_routing.inc");
}
foreach (array('system_gateways_edit.php' => 'routing_save_gateway($_POST', 'system_gateway_groups_edit.php' => 'routing_save_gateway_group($_POST',
    'system_routes_edit.php' => 'routing_save_static_route($_POST') as $page => $call) {
	$src = file_get_contents("{$root}/src/usr/local/www/{$page}");
	check_api(strpos($src, $call) !== false && strpos($src, 'write_config(') === false && strpos($src, 'config_set_path(') === false,
	    "{$page} saves through system_routing.inc");
}
$gwpage = file_get_contents("{$root}/src/usr/local/www/system_gateways.php");
foreach (array('routing_save_default_gateways(', 'routing_delete_gateway(', 'routing_delete_gateways(', 'routing_toggle_gateway(') as $call) {
	check_api(strpos($gwpage, $call) !== false, "system_gateways.php uses {$call})");
}
$rtpage = file_get_contents("{$root}/src/usr/local/www/system_routes.php");
foreach (array('routing_delete_static_route(', 'routing_delete_static_routes(', 'routing_toggle_static_route(', 'routing_move_static_routes(') as $call) {
	check_api(strpos($rtpage, $call) !== false, "system_routes.php uses {$call})");
}
check_api(strpos(file_get_contents("{$root}/src/usr/local/www/system_gateway_groups.php"), 'routing_delete_gateway_group(') !== false,
    'system_gateway_groups.php deletes through routing_delete_gateway_group()');
$routes_rt = file_get_contents("{$root}/src/etc/inc/restapi/routes_routing.inc");
check_api(substr_count($routes_rt, 'write_config(') === 0 && substr_count($routes_rt, 'config_set_path(') === 0,
    'routing API writes only through the GUI functions');
check_api(strpos(file_get_contents("{$root}/src/usr/local/www/api/index.php"), "require_once('system_routing.inc');") !== false,
    'the API front controller loads system_routing.inc');

/* Interfaces: VLAN, VXLAN, GIF, GRE */
check_api(isset(restapi_areas()['interfaces']), 'the interfaces permission area exists');
foreach (array('vlans', 'vxlans', 'gifs', 'gres') as $res) {
	foreach (array('GET /v1/interfaces/' . $res, 'GET /v1/interfaces/' . $res . '/{id}', 'POST /v1/interfaces/' . $res,
	    'PUT /v1/interfaces/' . $res . '/{id}', 'DELETE /v1/interfaces/' . $res . '/{id}') as $key) {
		check_api(isset($seen[$key]), "route {$key} exists");
	}
	check_api(restapi_iftun_resource("/v1/interfaces/{$res}/em1.100") === $res, "{$res} is resolved from the path");
}
check_api(api_error_status(function () { restapi_iftun_resource('/v1/interfaces/bogus'); }) === 404, 'unknown interface resource is 404');
list($route, $params) = restapi_match($v1, 'GET', '/v1/interfaces/vlans/em1.3999');
check_api($params['id'] === 'em1.3999', 'a VLAN can be addressed by its interface name');

check_api(restapi_iftun_ifname('vlans', array('if' => 'em1', 'tag' => '100')) === 'em1.100' &&
    restapi_iftun_ifname('vlans', array('if' => 'em1', 'tag' => '100', 'vlanif' => 'em1.100')) === 'em1.100' &&
    restapi_iftun_ifname('gres', array('greif' => 'gre0')) === 'gre0', 'interface names of stored entries');
check_api(restapi_iftun_fields('vlans', array('if' => 'em1', 'tag' => '100', 'tag_type' => '', 'pcp' => '', 'descr' => 'd', 'vlanif' => 'em1.100')) ===
    array('if' => 'em1', 'tag_type' => 'ctag', 'tag' => '100', 'pcp' => '', 'descr' => 'd'),
    'VLAN fields: an empty tag type preselects C-Tag');
$vx = restapi_iftun_fields('vxlans', array('if' => 'lan', 'ipproto' => 'inet', 'mode' => 'unicast', 'vni' => '5',
    'remote-addr' => '192.0.2.9', 'nolearn' => '', 'allowrule' => '', 'vxlanif' => 'vxlan0', 'mac' => '02:00:00:00:00:01'));
check_api(!isset($vx['learn']) && $vx['allowrule'] === 'yes' && $vx['mcastgroup'] === '' && !isset($vx['mac']) && !isset($vx['vxlanif']),
    'VXLAN fields: nolearn unticks learning, allowrule ticks, no interface name or MAC');
check_api(restapi_iftun_fields('vxlans', array('if' => 'lan'))['learn'] === 'yes', 'VXLAN learning is ticked unless nolearn is set');
$gif = restapi_iftun_fields('gifs', array('if' => 'wan', 'ipaddr' => '198.51.100.5', 'link2' => '', 'tunnel-remote-net' => '30'));
check_api($gif['if'] === 'wan|198.51.100.5' && $gif['link2'] === 'yes' && !isset($gif['link1']) && $gif['tunnel-remote-net'] === '30',
    'GIF fields: an address parent is "parent|address" like the edit page');
$gre = restapi_iftun_fields('gres', array('if' => 'lan', 'tunnel-remote-net' => '30', 'link1' => ''));
check_api($gre['tunnel-remote-net'] === '30' && $gre['tunnel-remote-net6'] === '128' && $gre['link1'] === 'yes',
    'GRE fields: an empty subnet select posts its first choice');

$new = restapi_iftun_post('vxlans', array('if' => 'lan', 'vni' => 7, 'remote-addr' => '192.0.2.1'));
check_api($new['learn'] === 'yes' && $new['ipproto'] === 'inet' && $new['mode'] === 'unicast' && $new['vni'] === '7' &&
    $new['localport'] === '' && $new['mcastgroup'] === '', 'a new VXLAN posts what the new form posts');
check_api(!isset(restapi_iftun_post('vxlans', array('learn' => false))['learn']), 'learn sent as false is unticked on create');
$upd = restapi_iftun_post('gres', array('descr' => 'n', 'link1' => false), $gre);
check_api($upd['descr'] === 'n' && $upd['tunnel-remote-net'] === '30' && !isset($upd['link1']) && $upd['tunnel-local-addr6'] === '',
    'an update keeps omitted fields, unticks false checkboxes and posts every text field');
check_api(restapi_iftun_post('vlans', array('if' => 'em1', 'tag' => 5))['tag_type'] === 'ctag', 'a new VLAN defaults to C-Tag');
check_api(api_error_status(function () { restapi_iftun_post('vlans', array('tag' => array(1, 2))); }) === 400, 'a list is not a text field');

/* The Interfaces pages are thin wrappers around interfaces_tunnels.inc */
$tun_inc = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/interfaces_tunnels.inc");
check_api(strpos($tun_inc, '$_POST') === false && strpos($tun_inc, '$_REQUEST') === false,
    'interfaces_tunnels.inc takes its form fields as parameters');
check_api(strpos($tun_inc, '$_SESSION') === strrpos($tun_inc, '$_SESSION') && strpos($tun_inc, 'function interfaces_gui_read_only(') !== false,
    'only interfaces_gui_read_only() reads the GUI session');
check_api(strpos($tun_inc, "(\$gif['if'] == \$parent)") !== false && strpos($tun_inc, '$interface)') === false,
    'the GIF duplicate check compares the parent (it used an undefined variable)');
check_api(strpos($tun_inc, "\$gifif = \$this_gif_config['gifif'] ?? '';") !== false && strpos($tun_inc, "\$greif = \$this_gre_config['greif'] ?? '';") !== false,
    'GIF and GRE keep the interface name of an existing tunnel instead of taking it from the form');
check_api(strpos($tun_inc, '"/sbin/route -q delete -inet "') !== false && strpos($tun_inc, '"/sbin/route -q delete -inet6 "') !== false,
    'deleting a GRE tunnel removes the route "Add Static Route" added');
foreach (array('vlan', 'vxlan', 'gif', 'gre') as $type) {
	$list = file_get_contents("{$root}/src/usr/local/www/interfaces_{$type}.php");
	$edit = file_get_contents("{$root}/src/usr/local/www/interfaces_{$type}_edit.php");
	check_api(strpos($list, "interfaces_{$type}_delete(") !== false && strpos($list, 'write_config(') === false &&
	    strpos($list, 'FreeSense_interface_destroy(') === false && strpos($list, 'function ') === false,
	    "interfaces_{$type}.php deletes through interfaces_{$type}_delete()");
	check_api(strpos($edit, "interfaces_{$type}_save(\$_POST") !== false && strpos($edit, 'write_config(') === false &&
	    strpos($edit, 'config_set_path(') === false && strpos($edit, "interface_{$type}_configure(") === false,
	    "interfaces_{$type}_edit.php saves through interfaces_{$type}_save()");
	check_api(strpos($edit, 'function build_parent_list(') === false, "interfaces_{$type}_edit.php has no build_parent_list()");
}
foreach (array('gif', 'gre', 'vxlan') as $type) {
	check_api(strpos(file_get_contents("{$root}/src/usr/local/www/interfaces_{$type}_edit.php"), "interfaces_tunnel_parent_list('{$type}')") !== false,
	    "interfaces_{$type}_edit.php offers interfaces_tunnel_parent_list('{$type}')");
}
check_api(strpos(file_get_contents("{$root}/src/usr/local/www/interfaces_vlan_edit.php"), 'interfaces_vlan_tag_types()') !== false,
    'the VLAN edit page uses interfaces_vlan_tag_types()');
$routes_if = file_get_contents("{$root}/src/etc/inc/restapi/routes_interfaces.inc");
check_api(substr_count($routes_if, 'write_config(') === 0 && substr_count($routes_if, 'config_set_path(') === 0 &&
    substr_count($routes_if, '_configure(') === 0, 'interfaces API writes only through the GUI functions');
check_api(strpos(file_get_contents("{$root}/src/usr/local/www/api/index.php"), "require_once('interfaces_tunnels.inc');") !== false,
    'the API front controller loads interfaces_tunnels.inc');

/* Interfaces: LAGG, QinQ, interface groups, bridges */
foreach (array('laggs', 'qinqs', 'groups', 'bridges') as $res) {
	foreach (array('GET /v1/interfaces/' . $res, 'GET /v1/interfaces/' . $res . '/{id}', 'POST /v1/interfaces/' . $res,
	    'PUT /v1/interfaces/' . $res . '/{id}', 'DELETE /v1/interfaces/' . $res . '/{id}') as $key) {
		check_api(isset($seen[$key]), "route {$key} exists");
	}
	check_api(restapi_iftun_resource("/v1/interfaces/{$res}/x") === $res, "{$res} is resolved from the path");
}
check_api(restapi_iftun_ifname('qinqs', array('if' => 'em1', 'tag' => '3998')) === 'em1.3998' &&
    restapi_iftun_ifname('laggs', array('laggif' => 'lagg0')) === 'lagg0' &&
    restapi_iftun_ifname('groups', array('ifname' => 'LANS')) === 'LANS' &&
    restapi_iftun_ifname('bridges', array('bridgeif' => 'bridge1')) === 'bridge1', 'LAGG, QinQ, group and bridge names');

$lg = restapi_iftun_fields('laggs', array('members' => 'igb2,igb3', 'proto' => 'lacp', 'laggif' => 'lagg0', 'descr' => 'd'));
check_api($lg['members'] === array('igb2', 'igb3') && $lg['proto'] === 'lacp' && $lg['failovermaster'] === 'auto' &&
    $lg['lacptimeout'] === 'slow' && $lg['lagghash'] === 'l2,l3,l4' && !isset($lg['laggif']),
    'LAGG fields: members as a list, unset selects post their first choice, no interface name');
$new = restapi_iftun_post('laggs', array('members' => 'igb2, igb3'));
check_api($new['members'] === array('igb2', 'igb3') && $new['proto'] === 'none' && $new['descr'] === '',
    'a new LAGG posts what the new form posts; a list may be a separated string');
check_api(!isset(restapi_iftun_post('laggs', array('members' => array()), $lg)['members']), 'an empty list is not posted (like an empty multi-select)');
check_api(restapi_iftun_post('laggs', array('descr' => 'x'), $lg)['members'] === array('igb2', 'igb3'), 'an update keeps the members');

$qq = restapi_iftun_fields('qinqs', array('if' => 'em1', 'tag_type' => 'ctag', 'tag' => '3998', 'members' => '10 11',
    'autogroup' => false, 'vlanif' => 'em1.3998', 'descr' => ''));
check_api($qq['tag_type'] === 'ctag' && $qq['members'] === array('10', '11') && !isset($qq['autogroup']),
    'QinQ fields: the stored tag type, members as a list, autogroup false is unticked');
check_api(restapi_iftun_fields('qinqs', array('if' => 'em1', 'tag' => '5'))['tag_type'] === 'stag' &&
    restapi_iftun_fields('qinqs', array('autogroup' => true))['autogroup'] === 'yes', 'QinQ tag type defaults to S-Tag; autogroup ticks');
$qp = restapi_iftun_post('qinqs', array('if' => 'em1', 'tag' => 3998, 'members' => array(10, '20-21'), 'member7' => '99'));
check_api($qp['member0'] === '10' && $qp['member1'] === '20-21' && !isset($qp['member2']) && !isset($qp['member7']) &&
    !isset($qp['members']) && $qp['tag_type'] === 'stag' && $qp['tag'] === '3998',
    'QinQ members become the member0, member1, ... rows (no others)');
check_api(!isset(restapi_iftun_post('qinqs', array('autogroup' => false), $qq + array('autogroup' => 'yes'))['autogroup']),
    'QinQ autogroup sent as false is unticked');

$gr = restapi_iftun_fields('groups', array('ifname' => 'LANS', 'members' => 'lan opt1', 'descr' => 'g'));
check_api($gr === array('ifname' => 'LANS', 'descr' => 'g', 'members' => array('lan', 'opt1')), 'group fields');
$gp = restapi_iftun_post('groups', array('ifname' => 'NEWNAME'), $gr);
check_api($gp['ifname'] === 'NEWNAME' && $gp['members'] === array('lan', 'opt1'), 'a group rename keeps the members');

$br = restapi_iftun_fields('bridges', array('members' => 'lan,opt1', 'enablestp' => false, 'ip6linklocal' => '', 'proto' => '',
    'stp' => 'opt1', 'ifpriority' => 'lan:128,opt1:64', 'ifpathcost' => '', 'maxage' => '20', 'bridgeif' => 'bridge0'));
check_api($br['members'] === array('lan', 'opt1') && $br['stp'] === array('opt1') && $br['span'] === array() &&
    !isset($br['enablestp']) && $br['ip6linklocal'] === 'yes' && $br['proto'] === 'rstp' && $br['maxage'] === '20' &&
    $br['ifpriority'] === array('lan' => '128', 'opt1' => '64') && $br['ifpathcost'] === array() && !isset($br['bridgeif']),
    'bridge fields: lists, flags (false is unticked), proto default, per-interface values as objects');
$bp = restapi_iftun_post('bridges', array('ifpriority' => array('opt1' => 32, 'opt2' => ''), 'ifpathcost' => array('lan' => 5),
    'enablestp' => true, 'stp' => array()), $br);
check_api($bp['lan'] === '128' && $bp['opt1'] === '32' && $bp['opt2'] === '' && $bp['lan0'] === '5' &&
    !isset($bp['ifpriority']) && !isset($bp['ifpathcost']) && $bp['enablestp'] === 'yes' && !isset($bp['stp']) &&
    $bp['members'] === array('lan', 'opt1') && $bp['descr'] === '',
    'bridge per-interface values merge over the current ones and become the "lan"/"lan0" form fields');
check_api(restapi_iftun_post('bridges', array('members' => array('lan')))['proto'] === 'rstp', 'a new bridge posts RSTP');
check_api(api_error_status(function () { restapi_iftun_post('bridges', array('ifpriority' => array('members' => '1'))); }) === 400,
    'a per-interface value cannot overwrite another form field');
check_api(api_error_status(function () { restapi_iftun_post('bridges', array('ifpriority' => array('1', '2'))); }) === 400 &&
    api_error_status(function () { restapi_iftun_post('bridges', array('ifpathcost' => array('lan' => array(1)))); }) === 400,
    'per-interface values must be an object of scalars');

/* The LAGG, QinQ, group and bridge pages are thin wrappers around interfaces_l2.inc */
$l2_inc = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/interfaces_l2.inc");
check_api(strpos($l2_inc, '$_POST') === false && strpos($l2_inc, '$_REQUEST') === false && strpos($l2_inc, '$_SESSION') === false,
    'interfaces_l2.inc takes its form fields as parameters');
foreach (array('lagg' => 'lagg', 'qinq' => 'qinq', 'groups' => 'group', 'bridge' => 'bridge') as $page => $fn) {
	$list = file_get_contents("{$root}/src/usr/local/www/interfaces_{$page}.php");
	$edit = file_get_contents("{$root}/src/usr/local/www/interfaces_{$page}_edit.php");
	check_api(strpos($list, "interfaces_{$fn}_delete(") !== false && strpos($list, 'write_config(') === false &&
	    strpos($list, 'config_del_path(') === false && preg_match('/^function\s/m', $list) === 0,
	    "interfaces_{$page}.php deletes through interfaces_{$fn}_delete()");
	check_api(strpos($edit, "interfaces_{$fn}_save(\$_POST") !== false && strpos($edit, 'write_config(') === false &&
	    strpos($edit, 'config_set_path(') === false && preg_match('/interface_[a-z0-9]+_configure\(/', $edit) === 0,
	    "interfaces_{$page}_edit.php saves through interfaces_{$fn}_save()");
}
$lagg_inuse = substr($l2_inc, strpos($l2_inc, 'function lagg_inuse('));
$lagg_inuse = substr($lagg_inuse, 0, strpos($lagg_inuse, "\n}\n"));
check_api(strpos($lagg_inuse, "config_get_path('qinqs/qinqentry'") !== false && strpos($lagg_inuse, 'link_interface_to_bridge(') !== false,
    'a LAGG that is a QinQ parent or a bridge member is in use');
check_api(strpos($l2_inc, "\$lagg['laggif'] = \$this_lagg_config['laggif'] ?? '';") !== false &&
    strpos($l2_inc, "\$bridgeif = \$this_bridge_config['bridgeif'] ?? '';") !== false,
    'LAGGs and bridges keep the stored interface name instead of taking it from the form');
check_api(strpos($l2_inc, 'array_key_exists($member, $ports)') !== false,
    'LAGG members must be ports the edit page offers (never an assigned interface)');
check_api(strpos($l2_inc, 'array_key_exists($post[\'if\'], interfaces_vlan_parent_list())') !== false,
    'a new QinQ needs a parent the edit page offers');
$grp_del = substr($l2_inc, strpos($l2_inc, 'function interfaces_group_delete('));
check_api(strpos(substr($grp_del, 0, strpos($grp_del, "\n}\n")), 'interfaces_group_users(') !== false,
    'an interface group used by rules cannot be deleted');
$grp_users = substr($l2_inc, strpos($l2_inc, 'function interfaces_group_users('));
$grp_users = substr($grp_users, 0, strpos($grp_users, "\n}\n"));
foreach (array("'filter/rule'", "'nat/rule'", "'nat/onetoone'", "'nat/outbound/rule'", "'nat/npt'", 'explode(","') as $needle) {
	check_api(strpos($grp_users, $needle) !== false, "interface group users include {$needle}");
}
check_api(strpos($l2_inc, '$post["{$ifn}0"]') !== false && strpos($l2_inc, '{$ifn}{$i}') === false,
    'the bridge path cost check looks at the posted path cost fields');
$qinq_edit = file_get_contents("{$root}/src/usr/local/www/interfaces_qinq_edit.php");
check_api(strpos($qinq_edit, "\$pconfig['tag_type'] = \$this_qinq_config['tag_type'];") !== false &&
    strpos($qinq_edit, '$this_vlan_config') === false && strpos($qinq_edit, 'interfaces_vlan_tag_types()') !== false &&
    preg_match('/^function\s/m', $qinq_edit) === 0, 'the QinQ edit page preselects the stored tag type');
$bridge_edit = file_get_contents("{$root}/src/usr/local/www/interfaces_bridge_edit.php");
check_api(strpos($bridge_edit, 'is_aoadv_used(') === false && strpos($bridge_edit, 'bridge_advanced_used($pconfig)') !== false &&
    strpos($bridge_edit, 'build_port_list(') === false && preg_match('/^function\s/m', $bridge_edit) === 0,
    'the bridge page uses bridge_advanced_used() and interfaces_bridge_port_list() (no clash with firewall_rules.inc)');
check_api(strpos(file_get_contents("{$root}/src/usr/local/www/interfaces_groups.php"), 'print_input_errors($input_errors)') !== false,
    'the interface group list shows why a delete was refused');
check_api(strpos(file_get_contents("{$root}/src/usr/local/www/api/index.php"), "require_once('interfaces_l2.inc');") !== false,
    'the API front controller loads interfaces_l2.inc');

/* Interfaces: assignments */
foreach (array('GET /v1/interfaces/assignments', 'GET /v1/interfaces/assignments/{name}', 'POST /v1/interfaces/assignments',
    'PUT /v1/interfaces/assignments/{name}', 'DELETE /v1/interfaces/assignments/{name}') as $key) {
	check_api(isset($seen[$key]), "route {$key} exists");
}
foreach ($v1 as $r) {
	if (strpos($r['path'], '/v1/interfaces/assignments') === 0) {
		check_api($r['page'] === 'interfaces_assign.php' && $r['area'] === 'interfaces', "{$r['method']} {$r['path']} is guarded by interfaces_assign.php");
		if ($r['write']) {
			check_api(in_array('confirm', $r['body']['required'] ?? array(), true), "{$r['method']} {$r['path']} documents the confirm flag");
		}
	}
}
check_api(api_error_status(function () { restapi_ifassign_confirm(array()); }) === 400 &&
    api_error_status(function () { restapi_ifassign_confirm(array('confirm' => 'true')); }) === 400 &&
    api_error_status(function () { restapi_ifassign_confirm(array('confirm' => 1)); }) === 400 &&
    api_error_status(function () { restapi_ifassign_confirm(array('confirm' => true)); }) === null,
    'assignment changes require {"confirm": true} (exactly true)');
try {
	restapi_ifassign_confirm(array('confirm' => false));
	check_api(false, 'confirm false is refused');
} catch (RestApiError $e) {
	check_api($e->payload()['error']['code'] === 'confirmation_required', 'the missing confirmation is "confirmation_required"');
}
check_api(restapi_ifassign_protected('wan') && restapi_ifassign_protected('lan') && !restapi_ifassign_protected('opt1'),
    'WAN and LAN are protected from API deletes');
check_api(restapi_ifassign_port(array('port' => 'em1.3997')) === 'em1.3997' &&
    api_error_status(function () { restapi_ifassign_port(array()); }) === 400 &&
    api_error_status(function () { restapi_ifassign_port(array('port' => array('em1'))); }) === 400, 'the port must be a name');
$ifs = array('wan' => array('if' => 'em0', 'enable' => ''), 'lan' => array('if' => 'em1'), 'opt1' => array('if' => 'em1.3997'));
check_api(restapi_ifassign_name('opt1', $ifs) === 'opt1' && api_error_status(function () use ($ifs) { restapi_ifassign_name('opt2', $ifs); }) === 404 &&
    api_error_status(function () use ($ifs) { restapi_ifassign_name('../wan', $ifs); }) === 404, 'assignments are addressed by an assigned interface name');
check_api(restapi_ifassign_remap_post($ifs, 'opt1', 'em1.3996') === array('wan' => 'em0', 'lan' => 'em1', 'opt1' => 'em1.3996', 'Submit' => 'Save'),
    'a remap posts what the page posts: every interface with its port, one of them moved');

/* The assignment page is a thin wrapper around interfaces_assign.inc */
$assign_inc = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/interfaces_assign.inc");
$assign_page = file_get_contents("{$root}/src/usr/local/www/interfaces_assign.php");
check_api(strpos($assign_inc, '$_POST') === false && strpos($assign_inc, '$_REQUEST') === false && strpos($assign_inc, '$_SESSION') === false,
    'interfaces_assign.inc takes its form fields as parameters');
check_api(strpos($assign_page, 'interfaces_assign_add($_POST[\'if_add\']') !== false && strpos($assign_page, 'interfaces_assign_save($_POST') !== false &&
    strpos($assign_page, 'interfaces_assign_delete($delbtn') !== false && strpos($assign_page, 'interfaces_assign_apply()') !== false &&
    strpos($assign_page, 'interfaces_assign_port_list()') !== false, 'interfaces_assign.php adds, saves, deletes and applies through interfaces_assign.inc');
check_api(strpos($assign_page, 'write_config(') === false && strpos($assign_page, 'config_set_path(') === false &&
    strpos($assign_page, 'config_del_path(') === false && strpos($assign_page, 'interface_bring_down(') === false &&
    strpos($assign_page, '$_REQUEST') === false && preg_match('/^function\s/m', $assign_page) === 0,
    'interfaces_assign.php changes nothing itself and adds only on a POST');
$assign_inuse = substr($assign_inc, strpos($assign_inc, 'function interfaces_assign_in_use('));
$assign_inuse = substr($assign_inuse, 0, strpos($assign_inuse, "\n}\n"));
foreach (array('link_interface_to_group(', 'link_interface_to_bridge(', "link_interface_to_tunnelif(\$id, 'gre')",
    "link_interface_to_tunnelif(\$id, 'gif')", "link_interface_to_tunnelif(\$id, 'vxlan')", 'interface_has_queue(',
    "'gateways/gateway_item'", "'staticroutes/route'", "'gateways/gateway_group'", "'virtualip/vip'", 'defaultgw4') as $needle) {
	check_api(strpos($assign_inuse, $needle) !== false, "an interface in use by {$needle} cannot be deleted");
}
$assign_del = substr($assign_inc, strpos($assign_inc, 'function interfaces_assign_delete('));
$assign_del = substr($assign_del, 0, strpos($assign_del, "\n}\n"));
check_api(strpos($assign_del, 'interfaces_assign_in_use($id)') < strpos($assign_del, 'interface_bring_down(') &&
    strpos($assign_del, "(\$id === 'wan')") !== false && strpos($assign_del, 'empty(config_get_path("interfaces/{$id}"))') !== false,
    'a delete checks the interface exists, is not WAN and is not in use before bringing it down');
$assign_add = substr($assign_inc, strpos($assign_inc, 'function interfaces_assign_add('));
$assign_add = substr($assign_add, 0, strpos($assign_add, "\n}\n"));
check_api(strpos($assign_add, 'array_key_exists($port, $portlist)') !== false && strpos($assign_add, "'enable'") === false,
    'only listed ports can be added, and a new interface is not enabled');
$assign_save = substr($assign_inc, strpos($assign_inc, 'function interfaces_assign_save('));
$assign_save = substr($assign_save, 0, strpos($assign_save, "\n}\n"));
check_api(strpos($assign_save, 'if ($input_errors) {') < strpos($assign_save, 'interface_bring_down(') &&
    strpos($assign_save, 'empty(config_get_path("interfaces/{$ifname}"))') !== false,
    'a remap validates (only assigned interfaces) before bringing anything down');
$routes_if = file_get_contents("{$root}/src/etc/inc/restapi/routes_interfaces.inc");
check_api(strpos($routes_if, 'system_reboot(') === false && strpos($routes_if, 'interfaces_assign_apply(') === false,
    'the API never reboots for an interface mismatch (it reports reboot_needed)');
foreach (array('restapi_h_ifassign_create', 'restapi_h_ifassign_update', 'restapi_h_ifassign_delete') as $fn) {
	$body = substr($routes_if, strpos($routes_if, "function {$fn}("));
	$body = substr($body, 0, strpos($body, "\n}\n"));
	check_api(strpos($body, 'restapi_ifassign_confirm(') !== false &&
	    strpos($body, 'restapi_ifassign_confirm(') < strpos($body, 'interfaces_assign_'), "{$fn} checks the confirmation before changing anything");
}
$del_fn = substr($routes_if, strpos($routes_if, 'function restapi_h_ifassign_delete('));
check_api(strpos($del_fn, 'restapi_ifassign_protected($name)') < strpos($del_fn, 'interfaces_assign_delete('), 'the API refuses WAN/LAN deletes');
check_api(strpos(file_get_contents("{$root}/src/usr/local/www/api/index.php"), "require_once('interfaces_assign.inc');") !== false,
    'the API front controller loads interfaces_assign.inc');

/* Services: DNS Forwarder, UPnP, Wake-on-LAN, IGMP Proxy, DHCP/DHCPv6 Relay, SNMP */
check_api(isset(restapi_areas()['services.dns']) && isset(restapi_areas()['services.misc']), 'the services permission areas exist');
foreach (array('GET /v1/services/dns-forwarder', 'PUT /v1/services/dns-forwarder', 'POST /v1/services/dns-forwarder/apply',
    'GET /v1/services/upnp', 'PUT /v1/services/upnp', 'GET /v1/services/upnp/status', 'DELETE /v1/services/upnp/port-maps',
    'POST /v1/services/wol/wake', 'POST /v1/services/wol/entries/{id}/wake', 'POST /v1/services/wol/wake-all',
    'GET /v1/services/igmp-proxy', 'PUT /v1/services/igmp-proxy', 'POST /v1/services/igmp-proxy/apply',
    'GET /v1/services/dhcp-relay', 'PUT /v1/services/dhcp-relay', 'GET /v1/services/dhcpv6-relay', 'PUT /v1/services/dhcpv6-relay',
    'GET /v1/services/snmp', 'PUT /v1/services/snmp') as $key) {
	check_api(isset($seen[$key]), "route {$key} exists");
}
foreach (array('dns-forwarder/host-overrides', 'dns-forwarder/domain-overrides', 'wol/entries', 'igmp-proxy/entries') as $res) {
	foreach (array("GET /v1/services/{$res}", "GET /v1/services/{$res}/{id}", "POST /v1/services/{$res}",
	    "PUT /v1/services/{$res}/{id}", "DELETE /v1/services/{$res}/{id}") as $key) {
		check_api(isset($seen[$key]), "route {$key} exists");
	}
}
foreach ($v1 as $r) {
	if ((strpos($r['path'], '/v1/services/') === 0) && !preg_match('#^/v1/services/\{name\}/(start|stop|restart)$#', $r['path'])) {
		$want = preg_match('#^/v1/services/dns-(forwarder|resolver)(/|$)#', $r['path']) ? 'services.dns' :
		    (preg_match('#^/v1/services/ntp(/|$)#', $r['path']) ? 'services.time' :
		    (preg_match('#^/v1/services/(dyndns|rfc2136)/#', $r['path']) ? 'services.ddns' :
		    (preg_match('#^/v1/services/(dhcp(v6)?|router-advertisements)/#', $r['path']) ? 'services.dhcp' : 'services.misc')));
		check_api($r['area'] === $want, "{$r['method']} {$r['path']} is in area {$want}");
		check_api(($r['method'] === 'GET') xor $r['write'], "{$r['method']} {$r['path']}: only GET is a read");
		if ($r['path'] === '/v1/services/upnp') {
			check_api($r['page'] === 'pkg_edit.php?xml=miniupnpd.xml', 'UPnP settings are guarded by the package page privilege');
		}
	}
}

$types = array('on' => 'bool', 'port' => 'string', 'ifs' => 'list', 'id' => 'ro');
$merged = restapi_svc_merge(array('on' => false, 'port' => 5353, 'ifs' => array('lan', 2), 'id' => 9),
    array('on' => true, 'port' => '', 'ifs' => array(), 'id' => 1, 'x' => 'k'), $types);
check_api($merged === array('on' => false, 'port' => '5353', 'ifs' => array('lan', '2'), 'id' => 1, 'x' => 'k'),
    'a settings update merges over the current values; read-only fields are ignored');
check_api(api_error_status(function () use ($types) { restapi_svc_merge(array('bogus' => 1), array(), $types); }) === 400, 'unknown fields are 400');
check_api(api_error_status(function () use ($types) { restapi_svc_merge(array('on' => 'yes'), array(), $types); }) === 400, 'a checkbox must be a boolean');
check_api(api_error_status(function () use ($types) { restapi_svc_merge(array('ifs' => 'lan'), array(), $types); }) === 400 &&
    api_error_status(function () use ($types) { restapi_svc_merge(array('ifs' => array(array('a'))), array(), $types); }) === 400,
    'a multi-select must be a list of strings');
check_api(api_error_status(function () use ($types) { restapi_svc_merge(array('port' => array(1)), array(), $types); }) === 400, 'a text field must be a string');
check_api(restapi_svc_post(array('on' => true, 'off' => false, 'port' => '53', 'ifs' => array(), 'more' => array('a'), 'id' => 3),
    array('on' => 'bool', 'off' => 'bool', 'port' => 'string', 'ifs' => 'list', 'more' => 'list', 'id' => 'ro')) ===
    array('on' => 'yes', 'port' => '53', 'more' => array('a')), 'ticked checkboxes post "yes", unticked ones and empty lists are left out like a form');
check_api(restapi_svc_post(array('enable' => true), array('enable' => 'bool'), 'on') === array('enable' => 'on'), 'package forms tick checkboxes with "on"');
check_api(restapi_svc_split('a,,b') === array('a', 'b') && restapi_svc_split('') === array(), 'comma lists split without empty entries');
check_api(restapi_svc_mask('public') === '(set)' && restapi_svc_mask('') === '', 'shared secrets are masked as "(set)"');
check_api(restapi_dnsmasq_alias_rows(array(array('host' => 'a', 'domain' => 'example.org'), array('host' => 'b', 'domain' => 'x.org', 'description' => 'd'))) ===
    array('aliashost0' => 'a', 'aliasdomain0' => 'example.org', 'aliasdescription0' => '', 'aliashost1' => 'b', 'aliasdomain1' => 'x.org', 'aliasdescription1' => 'd'),
    'host override aliases become the edit form rows');
check_api(api_error_status(function () { restapi_dnsmasq_alias_rows('a'); }) === 400 && api_error_status(function () { restapi_dnsmasq_alias_rows(array('a')); }) === 400,
    'aliases must be a list of objects');
check_api(restapi_igmp_network_rows(array('239.0.0.0/8', '10.0.0.1')) ===
    array('address0' => '239.0.0.0', 'address_subnet0' => '8', 'address1' => '10.0.0.1', 'address_subnet1' => ''), 'IGMP networks become address/address_subnet rows');
check_api(restapi_relay_server_rows(array('192.0.2.1', '192.0.2.2')) === array('server0' => '192.0.2.1', 'server1' => '192.0.2.2'), 'relay servers become server rows');
check_api(restapi_upnp_acl_rows(array('allow 1-65535 10.0.0.0/8 1-65535')) === array('permuser0' => 'allow 1-65535 10.0.0.0/8 1-65535'),
    'UPnP ACL entries become permuser rows');

/* The DNS Forwarder functions validate in every mode, stage every change and never redirect */
$fn_body = function ($src, $fn) {
	$body = substr($src, strpos($src, "function {$fn}("));
	return substr($body, 0, strpos($body, "\n}\n"));
};
$dnsmasq_inc = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/services_dnsmasq.inc");
check_api(strpos($dnsmasq_inc, 'header(') === false && strpos($dnsmasq_inc, 'exit;') === false, 'services_dnsmasq.inc never redirects (the pages do)');
check_api(strpos($dnsmasq_inc, 'if (!$json) {') === false, 'services_dnsmasq.inc validates the same way in JSON mode');
check_api(substr_count($dnsmasq_inc, "mark_subsystem_dirty('hosts');") === 4, 'settings, host and domain override saves and deletes mark the forwarder dirty');
$save_dom = $fn_body($dnsmasq_inc, 'saveDomainOverride');
check_api(strpos($save_dom, 'services_dnsmasq_configure(') === false && strpos($save_dom, "mark_subsystem_dirty('hosts')") !== false,
    'a domain override is staged like the other changes (it reconfigured dnsmasq at once)');
check_api(strpos($save_dom, "\$post['dnssrcip'] && ((\$post['ip'] == '#') || (\$post['ip'] == '!'))") !== false,
    'an exclusion (# or !) cannot have a source address (dnsmasq refused to start)');
$save_cfg = $fn_body($dnsmasq_inc, 'saveDNSMasqConfig');
check_api(strpos($save_cfg, 'config_set_path(') > strpos($save_cfg, 'if (!$input_errors) {') &&
    strpos($save_cfg, 'config_del_path(') > strpos($save_cfg, 'if (!$input_errors) {'), 'DNS Forwarder settings are validated before the configuration changes');
check_api(strpos($fn_body($dnsmasq_inc, 'getDNSMasqConfig'), 'config_set_path(') === false, 'reading the forwarder settings does not change the configuration');
check_api(strpos($fn_body($dnsmasq_inc, 'applyDNSMasqConfig'), "clear_subsystem_dirty('hosts')") !== false, 'apply clears the pending flag');
foreach (array('services_dnsmasq.php' => 'deleteDNSMasqEntry($_POST);', 'services_dnsmasq_edit.php' => 'saveDNSMasqHost($_POST, $id);',
    'services_dnsmasq_domainoverride_edit.php' => 'saveDomainOverride($_POST, $id);') as $page => $call) {
	$src = file_get_contents("{$root}/src/usr/local/www/{$page}");
	check_api(strpos($src, $call) !== false && strpos($src, 'header("Location: services_dnsmasq.php");') > strpos($src, $call),
	    "{$page} redirects after {$call}");
}

/* The other service pages are thin wrappers around their includes */
foreach (array('services_dnsmasq.inc', 'services_wol.inc', 'services_igmpproxy.inc', 'services_dhcp_relay.inc', 'services_snmp.inc', 'services_upnp.inc') as $inc) {
	$src = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/{$inc}");
	check_api(strpos($src, '$_POST') === false && strpos($src, '$_REQUEST') === false && strpos($src, '$_SESSION') === false &&
	    strpos($src, 'header(') === false, "{$inc} takes its form fields as parameters and never redirects");
	check_api(strpos(file_get_contents("{$root}/src/usr/local/www/api/index.php"), "require_once('{$inc}');") !== false,
	    "the API front controller loads {$inc}");
}
foreach (array('services_wol.php' => array('wol_wake_all(', 'wol_wake_device(', 'wol_delete_entry('),
    'services_wol_edit.php' => array('wol_save_entry($_POST, $id)'),
    'services_igmpproxy.php' => array('igmpproxy_apply()', 'igmpproxy_save_settings($_POST)', 'igmpproxy_delete_entry('),
    'services_igmpproxy_edit.php' => array('igmpproxy_save_entry($_POST, $id)', 'igmpproxy_interface_list()'),
    'services_dhcp_relay.php' => array('dhcp_relay_save($_POST, false)'), 'services_dhcpv6_relay.php' => array('dhcp_relay_save($_POST, true)'),
    'services_snmp.php' => array('snmp_save($_POST)', 'snmp_build_if_list('), 'status_upnp.php' => array('upnp_port_maps()')) as $page => $calls) {
	$src = file_get_contents("{$root}/src/usr/local/www/{$page}");
	foreach ($calls as $call) {
		check_api(strpos($src, $call) !== false, "{$page} uses {$call}");
	}
	check_api(strpos($src, 'write_config(') === false && strpos($src, 'config_set_path(') === false &&
	    strpos($src, 'config_del_path(') === false && preg_match('/^function\s/m', $src) === 0 && strpos($src, '_configure(') === false,
	    "{$page} changes nothing itself and declares no functions");
}
$snmp_inc = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/services_snmp.inc");
check_api(strpos($snmp_inc, 'function build_if_list(') === false && strpos($snmp_inc, 'function snmp_build_if_list(') !== false,
    'the SNMP page build_if_list() is renamed (no clash with services_dnsmasq.inc)');
$snmp_save = $fn_body($snmp_inc, 'snmp_save');
foreach (array("!is_port(\$post['pollport'])", "!is_port(\$post['trapserverport'])", 'snmp_ip_protocols()', '$bindoptions') as $needle) {
	check_api(strpos($snmp_save, $needle) !== false, "SNMP settings validate {$needle}");
}
check_api(strpos($snmp_save, 'config_set_path(') > strpos($snmp_save, 'if (!$input_errors) {'), 'SNMP settings are validated before the configuration changes');
$relay_save = $fn_body(file_get_contents("{$root}/src/usr/local/FreeSense/include/www/services_dhcp_relay.inc"), 'dhcp_relay_save');
check_api(strpos($relay_save, 'dhcp_relay_dhcpd_enabled($v6)') !== false && strpos($relay_save, 'dhcp_relay_interface_list($v6)') !== false &&
    strpos($relay_save, 'dhcp_relay_carp_list($v6)') !== false, 'the relay refuses a forged enable next to a DHCP server and unknown interfaces or VIPs');
$igmp_save = $fn_body(file_get_contents("{$root}/src/usr/local/FreeSense/include/www/services_igmpproxy.inc"), 'igmpproxy_save_entry');
check_api(strpos($igmp_save, 'igmpproxy_interface_list()') !== false && strpos($igmp_save, 'igmpproxy_types()') !== false &&
    strpos($igmp_save, "['interface']") === false, 'an IGMP entry needs an offered interface and a valid type');
$upnp_validate = file_get_contents("{$root}/src/usr/local/pkg/miniupnpd.inc");
$upnp_validate = substr($upnp_validate, strpos($upnp_validate, 'function validate_form_miniupnpd('));
$upnp_validate = substr($upnp_validate, 0, strpos($upnp_validate, 'function sync_package_miniupnpd('));
check_api(strpos($upnp_validate, '$enabled_ifaces') !== false && strpos($upnp_validate, '[\r\n]') !== false,
    'UPnP settings need enabled interfaces and no line breaks');
$upnp_inc = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/services_upnp.inc");
check_api(strpos($upnp_inc, 'validate_form_miniupnpd($post, $input_errors);') !== false && strpos($upnp_inc, 'sync_package_miniupnpd();') !== false &&
    strpos($upnp_inc, 'do_input_validation($post, $reqfields') !== false, 'UPnP settings save like pkg_edit.php (required fields, package validation, resync)');
$routes_svc = file_get_contents("{$root}/src/etc/inc/restapi/routes_services.inc");
check_api(substr_count($routes_svc, 'write_config(') === 0 && substr_count($routes_svc, 'config_set_path(') === 0 &&
    substr_count($routes_svc, 'config_del_path(') === 0 && substr_count($routes_svc, '_configure(') === 0,
    'services API writes only through the GUI functions');
$snmp_out = $fn_body($routes_svc, 'restapi_snmp_out');
check_api(strpos($snmp_out, "restapi_svc_mask(\$settings['rocommunity'])") !== false && strpos($snmp_out, "restapi_svc_mask(\$settings['trapstring'])") !== false &&
    strpos($fn_body($routes_svc, 'restapi_h_snmp_get'), 'restapi_snmp_out(') !== false && strpos($fn_body($routes_svc, 'restapi_h_snmp_set'), 'restapi_snmp_out(') !== false,
    'SNMP community and trap strings are never returned');

/* DNS Resolver (Unbound) */
foreach (array('GET /v1/services/dns-resolver', 'PUT /v1/services/dns-resolver', 'POST /v1/services/dns-resolver/apply',
    'GET /v1/services/dns-resolver/advanced', 'PUT /v1/services/dns-resolver/advanced') as $key) {
	check_api(isset($seen[$key]), "route {$key} exists");
}
foreach (array('host-overrides', 'domain-overrides', 'acls') as $res) {
	foreach (array("GET /v1/services/dns-resolver/{$res}", "GET /v1/services/dns-resolver/{$res}/{id}", "POST /v1/services/dns-resolver/{$res}",
	    "PUT /v1/services/dns-resolver/{$res}/{id}", "DELETE /v1/services/dns-resolver/{$res}/{id}") as $key) {
		check_api(isset($seen[$key]), "route {$key} exists");
	}
}
$unbound_pages = array('POST /v1/services/dns-resolver/host-overrides' => 'services_unbound_host_edit.php',
    'PUT /v1/services/dns-resolver/host-overrides/{id}' => 'services_unbound_host_edit.php',
    'DELETE /v1/services/dns-resolver/host-overrides/{id}' => 'services_unbound.php',
    'POST /v1/services/dns-resolver/domain-overrides' => 'services_unbound_domainoverride_edit.php',
    'PUT /v1/services/dns-resolver/advanced' => 'services_unbound_advanced.php',
    'POST /v1/services/dns-resolver/acls' => 'services_unbound_acls.php', 'POST /v1/services/dns-resolver/apply' => 'services_unbound.php');
foreach ($v1 as $r) {
	$key = "{$r['method']} {$r['path']}";
	if (isset($unbound_pages[$key])) {
		check_api($r['page'] === $unbound_pages[$key], "{$key} is guarded by {$unbound_pages[$key]}");
	}
	if ((strpos($r['path'], '/v1/services/dns-resolver') === 0) && $r['write'] && ($r['path'] !== '/v1/services/dns-resolver/apply')) {
		check_api(isset($r['query']['apply']), "{$key} accepts ?apply=true");
	}
}
check_api(restapi_unbound_preselect('transparent', array('deny', 'transparent')) === 'transparent' &&
    restapi_unbound_preselect(null, array('512', '1024')) === '512' && restapi_unbound_preselect(10, array(0, 10)) === '10' &&
    restapi_unbound_preselect('x', array()) === 'x', 'a select reads as the stored value or the option the form preselects');
check_api(restapi_unbound_acl_rows(array(array('network' => '192.0.2.0/24', 'description' => 'a'), array('network' => '2001:db8::'))) ===
    array('acl_network0' => '192.0.2.0', 'mask0' => '24', 'description0' => 'a', 'acl_network1' => '2001:db8::', 'mask1' => '', 'description1' => ''),
    'ACL networks become the edit form rows');
check_api(api_error_status(function () { restapi_unbound_acl_rows('192.0.2.0/24'); }) === 400 &&
    api_error_status(function () { restapi_unbound_acl_rows(array('192.0.2.0/24')); }) === 400 &&
    api_error_status(function () { restapi_unbound_acl_rows(array(array('network' => array('x')))); }) === 400,
    'ACL networks must be a list of objects');
check_api(api_error_status(function () { restapi_unbound_acl_rows(array_fill(0, 51, array('network' => '192.0.2.0/24'))); }) === 400 &&
    restapi_unbound_acl_rows(array_fill(0, 50, array('network' => '192.0.2.0/24')))['acl_network49'] === '192.0.2.0',
    'an access list has at most 50 networks (the form ignored the rest)');
check_api(restapi_unbound_acl_networks(array(array('acl_network' => '10.0.0.0', 'mask' => '8', 'description' => 'd'))) ===
    array(array('network' => '10.0.0.0/8', 'description' => 'd')) && restapi_unbound_acl_networks('') === array(), 'stored ACL rows read as networks');

$unbound_inc = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/services_unbound.inc");
check_api(strpos($unbound_inc, '$_POST') === false && strpos($unbound_inc, '$_REQUEST') === false && strpos($unbound_inc, '$_SESSION') === false &&
    strpos($unbound_inc, 'header(') === false && strpos($unbound_inc, 'exit;') === false, 'services_unbound.inc takes its form fields as parameters and never redirects');
check_api(strpos(file_get_contents("{$root}/src/usr/local/www/api/index.php"), "require_once('services_unbound.inc');") !== false,
    'the API front controller loads services_unbound.inc');
foreach (array('hostcmp', 'hosts_sort', 'build_if_list') as $fn) {
	check_api(strpos($unbound_inc, "function {$fn}(") === false && strpos($unbound_inc, "function unbound_{$fn}(") !== false,
	    "the Unbound {$fn}() is renamed unbound_{$fn}() (it clashed with services_dnsmasq.inc)");
}
preg_match_all("/'([a-z ]+)' => gettext\\('/", $fn_body($unbound_inc, 'unbound_acl_actions'), $m);
check_api($m[1] === array_keys(restapi_unbound_acl_actions()), 'the API lists the same access list actions as the page');
$apply_fn = $fn_body($unbound_inc, 'unbound_apply_changes');
foreach (array('services_unbound_configure()', "clear_subsystem_dirty('unbound')", 'system_resolvconf_generate()',
    'system_dhcpleases_configure()', '.unbound_kea_resync', 'services_dhcpd_configure()') as $needle) {
	check_api(strpos($apply_fn, $needle) !== false, "the shared Unbound apply runs {$needle}");
}
$save_gen = $fn_body($unbound_inc, 'unbound_save_general');
check_api(strpos($save_gen, 'test_unbound_config(array_merge(config_get_path(\'unbound\', []), $pconfig), $test_output)') !== false &&
    strpos($save_gen, 'test_unbound_config(') < strpos($save_gen, 'if (!$input_errors) {') &&
    strpos($save_gen, 'config_set_path(') > strpos($save_gen, 'if (!$input_errors) {'),
    'general settings are only saved when unbound-checkconf accepts the generated configuration');
check_api(strpos($save_gen, "unbound_build_if_list(array())['options']") !== false, 'listen and outgoing interfaces must be ones the page offers');
foreach (array('unbound_delete_override', 'unbound_delete_acl', 'unbound_save_host', 'unbound_save_domain_override', 'unbound_save_acl') as $fn) {
	check_api(strpos($fn_body($unbound_inc, $fn), 'is_numericint($id)') !== false, "{$fn}() only takes a numeric position");
}
$save_acl = $fn_body($unbound_inc, 'unbound_save_acl');
check_api(strpos($save_acl, 'unbound_acl_actions()') !== false && strpos($save_acl, "preg_match('/[\\r\\n]/'") !== false,
    'an access list needs a valid action and a name without line breaks (both are written into access_lists.conf)');
check_api(strpos($save_acl, "\$pconfig['aclid']") === false && strpos($save_acl, 'unbound_get_next_id()') !== false &&
    strpos($save_acl, "config_set_path('unbound/acls/', \$acl_entry)") !== false,
    'a new access list is appended (it overwrote the list at the position of its id) and an edited one keeps its id');
$save_adv = $fn_body($unbound_inc, 'unbound_save_advanced');
check_api(strpos($save_adv, 'unbound_advanced_choices()') !== false && strpos($save_adv, ', array(') === false,
    'advanced settings validate against the choices the API reports');
check_api(strpos($save_adv, "is_numericint(\$post['sock_queue_timeout'])") !== false, 'the UDP query timeout must be an integer (it is written into unbound.conf)');
check_api(strpos($fn_body($unbound_inc, 'unbound_save_host'), 'unbound_hosts_sort()') !== false, 'host overrides stay sorted by host');

foreach (array('services_unbound.php' => array('unbound_apply_changes()', 'unbound_save_general($_POST)', 'unbound_delete_override($_POST[\'type\'], $_POST[\'id\'])', 'unbound_build_if_list('),
    'services_unbound_host_edit.php' => array('unbound_save_host($_POST, $id)', 'unbound_host_settings($id)'),
    'services_unbound_domainoverride_edit.php' => array('unbound_save_domain_override($_POST, $id)', 'unbound_domain_override_settings($id)'),
    'services_unbound_acls.php' => array('unbound_apply_changes()', 'unbound_save_acl($_POST, ($act == "edit") ? $id : null)', 'unbound_delete_acl($id)', 'unbound_acl_actions()'),
    'services_unbound_advanced.php' => array('unbound_apply_changes()', 'unbound_save_advanced($_POST)', 'unbound_advanced_settings()', 'unbound_edns_sizes()')) as $page => $calls) {
	$src = file_get_contents("{$root}/src/usr/local/www/{$page}");
	foreach ($calls as $call) {
		check_api(strpos($src, $call) !== false, "{$page} uses {$call}");
	}
	check_api(strpos($src, 'write_config(') === false && strpos($src, 'config_set_path(') === false &&
	    strpos($src, 'config_del_path(') === false && preg_match('/^function\s/m', $src) === 0 && strpos($src, '_configure(') === false,
	    "{$page} changes nothing itself and declares no functions");
}
$routes_ub = file_get_contents("{$root}/src/etc/inc/restapi/routes_unbound.inc");
check_api(substr_count($routes_ub, 'write_config(') === 0 && substr_count($routes_ub, 'config_set_path(') === 0 &&
    substr_count($routes_ub, 'config_del_path(') === 0 && substr_count($routes_ub, '_configure(') === 0 &&
    substr_count($routes_ub, 'mark_subsystem_dirty(') === 0, 'DNS Resolver API writes only through the GUI functions');

/* NTP, Dynamic DNS and RFC 2136 */
check_api(isset(restapi_areas()['services.time']) && isset(restapi_areas()['services.ddns']), 'the NTP and Dynamic DNS permission areas exist');
foreach (array('GET /v1/services/ntp', 'PUT /v1/services/ntp', 'GET /v1/services/ntp/acls', 'PUT /v1/services/ntp/acls',
    'GET /v1/services/dyndns/choices', 'GET /v1/services/rfc2136/choices') as $key) {
	check_api(isset($seen[$key]), "route {$key} exists");
}
foreach (array('dyndns', 'rfc2136') as $res) {
	foreach (array("GET /v1/services/{$res}/clients", "GET /v1/services/{$res}/clients/{id}", "POST /v1/services/{$res}/clients",
	    "PUT /v1/services/{$res}/clients/{id}", "DELETE /v1/services/{$res}/clients/{id}", "POST /v1/services/{$res}/clients/{id}/toggle",
	    "POST /v1/services/{$res}/clients/{id}/update") as $key) {
		check_api(isset($seen[$key]), "route {$key} exists");
	}
}
$d3_pages = array('PUT /v1/services/ntp' => 'services_ntpd.php', 'PUT /v1/services/ntp/acls' => 'services_ntpd_acls.php',
    'GET /v1/services/dyndns/clients' => 'services_dyndns.php', 'POST /v1/services/dyndns/clients' => 'services_dyndns_edit.php',
    'PUT /v1/services/dyndns/clients/{id}' => 'services_dyndns_edit.php', 'DELETE /v1/services/dyndns/clients/{id}' => 'services_dyndns.php',
    'POST /v1/services/dyndns/clients/{id}/toggle' => 'services_dyndns.php', 'POST /v1/services/dyndns/clients/{id}/update' => 'services_dyndns_edit.php',
    'GET /v1/services/rfc2136/clients' => 'services_rfc2136.php', 'POST /v1/services/rfc2136/clients' => 'services_rfc2136_edit.php',
    'PUT /v1/services/rfc2136/clients/{id}' => 'services_rfc2136_edit.php', 'DELETE /v1/services/rfc2136/clients/{id}' => 'services_rfc2136.php',
    'POST /v1/services/rfc2136/clients/{id}/toggle' => 'services_rfc2136.php', 'POST /v1/services/rfc2136/clients/{id}/update' => 'services_rfc2136_edit.php');
foreach ($v1 as $r) {
	$key = "{$r['method']} {$r['path']}";
	if (isset($d3_pages[$key])) {
		check_api($r['page'] === $d3_pages[$key], "{$key} is guarded by {$d3_pages[$key]}");
	}
}

check_api(restapi_ntp_server_rows(array(array('server' => '0.pool.ntp.org', 'type' => 'pool'),
    array('server' => '192.0.2.1', 'prefer' => true, 'noselect' => false, 'auth' => true, 'type' => 'peer'), 'ntp.example.org')) ===
    array('server0' => '0.pool.ntp.org', 'servistype0' => 'pool', 'server1' => '192.0.2.1', 'servprefer1' => 'yes', 'servauth1' => 'yes',
    'servistype1' => 'peer', 'server2' => 'ntp.example.org', 'servistype2' => 'server'), 'time servers become the settings form rows');
check_api(api_error_status(function () { restapi_ntp_server_rows('pool.ntp.org'); }) === 400 &&
    api_error_status(function () { restapi_ntp_server_rows(array(array('server' => 'a', 'type' => 'x'))); }) === 400 &&
    api_error_status(function () { restapi_ntp_server_rows(array(array('server' => 'a', 'prefer' => 'yes'))); }) === 400 &&
    api_error_status(function () { restapi_ntp_server_rows(array(array('server' => 'a', 'bogus' => 1))); }) === 400 &&
    api_error_status(function () { restapi_ntp_server_rows(array_fill(0, 11, 'a')); }) === 400, 'time servers are validated (at most 10, like the form)');
check_api(restapi_ntp_acl_rows(array(array('network' => '192.0.2.0/24', 'kod' => true, 'notrap' => false), array('network' => '2001:db8::/64'))) ===
    array('acl_network0' => '192.0.2.0', 'mask0' => '24', 'kod0' => 'yes', 'acl_network1' => '2001:db8::', 'mask1' => '64'),
    'NTP restriction networks become the ACLs form rows');
check_api(restapi_ntp_acl_rows(array()) === array('acl_network0' => '', 'mask0' => '128'), 'no networks post the empty row the form posts');
check_api(api_error_status(function () { restapi_ntp_acl_rows(array('192.0.2.0/24')); }) === 400 &&
    api_error_status(function () { restapi_ntp_acl_rows(array(array('network' => '192.0.2.0/24', 'kod' => 'yes'))); }) === 400 &&
    api_error_status(function () { restapi_ntp_acl_rows(array_fill(0, 51, array('network' => '192.0.2.0/24'))); }) === 400,
    'NTP restriction networks are validated (at most 50)');
check_api(restapi_ntp_acl_networks(array(array('acl_network' => '', 'mask' => '128'), array('acl_network' => '10.0.0.0', 'mask' => '8', 'nopeer' => 'yes'))) ===
    array(array('network' => '10.0.0.0/8', 'kod' => false, 'nomodify' => false, 'noquery' => false, 'noserve' => false, 'nopeer' => true, 'notrap' => false)),
    'stored restriction rows read as networks (the empty row is left out)');
check_api(restapi_select_choice('inet', array('auto' => 1, 'inet' => 2)) === 'inet' && restapi_select_choice(null, array('auto' => 1)) === 'auto' &&
    restapi_select_choice(6, array('' => 0, 6 => 1)) === '6' && restapi_select_choice('', array('' => 0, 3 => 1)) === '', 'a select reads as the form preselects it');
check_api(restapi_ddns_secret_body(array('password' => '(set)', 'host' => 'h'), 'password') === array('host' => 'h') &&
    restapi_ddns_secret_body(array('password' => 'p'), 'password') === array('password' => 'p'), '"(set)" keeps a stored secret');
if (!defined('DMYPWD')) {
	define('DMYPWD', '********');
}
$dd_post = restapi_dyndns_post(array('enable' => false, 'type' => 'custom', 'wildcard' => true, 'proxied' => false, 'password' => 'x', 'id' => 3), null);
check_api($dd_post === array('type' => 'custom', 'wildcard' => 'yes', 'enable' => 'yes', 'passwordfld' => DMYPWD, 'passwordfld_confirm' => DMYPWD, 'save' => 'Save'),
    'a disabled Dynamic DNS client posts the "Disable" checkbox; an unchanged password posts the placeholder');
$dd_post = restapi_dyndns_post(array('enable' => true), 'secret');
check_api(!isset($dd_post['enable']) && $dd_post['passwordfld'] === 'secret' && $dd_post['passwordfld_confirm'] === 'secret',
    'an enabled client leaves "Disable" unticked; a new password is posted with its confirmation');

$ntpd_inc = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/services_ntpd.inc");
$ddns_inc = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/services_dyndns.inc");
foreach (array('services_ntpd.inc' => $ntpd_inc, 'services_dyndns.inc' => $ddns_inc) as $inc => $src) {
	check_api(strpos($src, '$_POST') === false && strpos($src, '$_REQUEST') === false && strpos($src, '$_FILES') === false &&
	    strpos($src, 'header(') === false && strpos($src, 'exit;') === false, "{$inc} takes its form fields as parameters and never redirects");
	check_api(strpos(file_get_contents("{$root}/src/usr/local/www/api/index.php"), "require_once('{$inc}');") !== false,
	    "the API front controller loads {$inc}");
}
check_api(strpos($ntpd_inc, 'function build_interface_list(') === false && strpos($ntpd_inc, 'function ntpd_build_interface_list(') !== false,
    'the NTP build_interface_list() is renamed ntpd_build_interface_list() (it clashed with the PPPoE and IPsec pages)');
foreach (array('build_if_list' => 'dyndns_build_if_list', 'build_type_list' => 'dyndns_type_list',
    'build_check_ip_mode_list' => 'dyndns_check_ip_mode_list', 'build_us_list' => 'rfc2136_build_us_list') as $old => $new) {
	check_api(strpos($ddns_inc, "function {$old}(") === false && strpos($ddns_inc, "function {$new}(") !== false, "the page's {$old}() is {$new}()");
}
$ntp_save = $fn_body($ntpd_inc, 'ntpd_save_settings');
check_api(strpos($ntp_save, "ntpd_build_interface_list(array())['options']") !== false, 'NTP interfaces must be ones the page offers (they are written into ntpd.conf)');
check_api(strpos($ntp_save, "array_key_exists(\$pconfig['serverauthalgo'], \$ntp_auth_halgos)") !== false &&
    strpos($ntp_save, 'ntpd_dnsresolv_choices()') !== false, 'the NTP digest algorithm and DNS resolution must be listed ones');
check_api(strpos($ntp_save, 'system_ntp_configure()') !== false && strpos($ntp_save, '$leapfile !== null') !== false,
    'NTP settings apply at once; an uploaded leap seconds file replaces the text');
$acl_save = $fn_body($ntpd_inc, 'ntpd_save_acls');
check_api(strpos($acl_save, "|| (strlen(\$networkacl[\$x]['acl_network']) > 0)) {") !== false && strpos($acl_save, 'system_ntp_configure()') !== false,
    'an NTP restriction row with a network is validated even without flags');
$dd_save = $fn_body($ddns_inc, 'dyndns_save_client');
check_api(strpos($dd_save, 'dyndns_type_list()') !== false && strpos($dd_save, '$iflist = dyndns_build_if_list();') !== false &&
    strpos($dd_save, 'services_dyndns_configure_client($dyndns)') !== false,
    'Dynamic DNS service types and interfaces must be listed ones (they name the cache file)');
$rfc_save = $fn_body($ddns_inc, 'rfc2136_save_client');
foreach (array("(string)\$post['keydata'])", 'is_hostname($m[1])', 'dyndns_build_if_list()', 'rfc2136_build_us_list()',
    'rfc2136_source_families()', 'rfc2136_record_types()', 'services_dnsupdate_process(') as $needle) {
	check_api(strpos($rfc_save, $needle) !== false, "rfc2136_save_client() checks {$needle}");
}
foreach (array('dyndns_delete_client', 'dyndns_toggle_client', 'rfc2136_delete_client', 'rfc2136_toggle_client') as $fn) {
	check_api(strpos($fn_body($ddns_inc, $fn), 'is_numericint($id)') !== false, "{$fn}() only takes the position of an existing client");
}
check_api(strpos($fn_body($ddns_inc, 'dyndns_client_cache_files'), '_v6.cache') !== false &&
    strpos($fn_body($ddns_inc, 'dyndns_delete_client'), 'dyndns_client_cache_files(') !== false &&
    strpos($fn_body($ddns_inc, 'rfc2136_delete_client'), 'rfc2136_client_cache_files(') !== false, 'deleting a client removes its IPv4 and IPv6 cache files');

foreach (array('services_ntpd.php' => array('ntpd_save_settings($_POST, $leapfile)', 'ntpd_settings()', 'ntpd_timeserver_rows()', 'ntpd_build_interface_list('),
    'services_ntpd_acls.php' => array('ntpd_save_acls($_POST)', 'ntpd_acl_rows()'),
    'services_dyndns.php' => array('dyndns_delete_client($_POST[\'id\'])', 'dyndns_toggle_client($_POST[\'id\'])'),
    'services_dyndns_edit.php' => array('dyndns_save_client($_POST, $id, $dup)', 'dyndns_client_settings($id, $dup)', 'dyndns_build_if_list()'),
    'services_rfc2136.php' => array('rfc2136_delete_client($_POST[\'id\'])', 'rfc2136_toggle_client($_POST[\'id\'])'),
    'services_rfc2136_edit.php' => array('rfc2136_save_client($_POST, $id, $dup)', 'rfc2136_client_settings($id, $dup)', 'rfc2136_build_us_list()')) as $page => $calls) {
	$src = file_get_contents("{$root}/src/usr/local/www/{$page}");
	foreach ($calls as $call) {
		check_api(strpos($src, $call) !== false, "{$page} uses {$call}");
	}
	check_api(strpos($src, 'write_config(') === false && strpos($src, 'config_del_path(') === false &&
	    preg_match('/^function\s/m', $src) === 0 && strpos($src, '_configure(') === false && strpos($src, 'services_dnsupdate_process(') === false,
	    "{$page} changes nothing itself and declares no functions");
}
$routes_ntp = file_get_contents("{$root}/src/etc/inc/restapi/routes_ntp.inc");
$routes_ddns = file_get_contents("{$root}/src/etc/inc/restapi/routes_ddns.inc");
foreach (array('NTP' => $routes_ntp, 'Dynamic DNS' => $routes_ddns) as $what => $src) {
	check_api(substr_count($src, 'write_config(') === 0 && substr_count($src, 'config_set_path(') === 0 && substr_count($src, 'config_del_path(') === 0 &&
	    substr_count($src, '_configure(') === 0 && substr_count($src, 'services_dnsupdate_process(') === 0 && substr_count($src, 'dyndnsCheckIP(') === 0,
	    "{$what} API writes only through the GUI functions and never runs a check IP query");
}
check_api(strpos($fn_body($routes_ntp, 'restapi_ntp_out'), "restapi_svc_mask(\$settings['serverauthkey'])") !== false &&
    strpos($fn_body($routes_ntp, 'restapi_h_ntp_get'), 'restapi_ntp_out(') !== false && strpos($fn_body($routes_ntp, 'restapi_h_ntp_set'), 'restapi_ntp_out(') !== false,
    'the NTP authentication key is never returned');
check_api(strpos($fn_body($routes_ddns, 'restapi_dyndns_out'), "restapi_svc_mask(\$out['password'])") !== false &&
    strpos($fn_body($routes_ddns, 'restapi_rfc2136_out'), "restapi_svc_mask(\$out['keydata'])") !== false,
    'Dynamic DNS passwords and RFC 2136 keys are never returned');
foreach (array('restapi_h_dyndns_list', 'restapi_h_dyndns_get', 'restapi_h_dyndns_toggle', 'restapi_h_rfc2136_list', 'restapi_h_rfc2136_get',
    'restapi_h_rfc2136_toggle') as $fn) {
	check_api(preg_match('/restapi_(dyndns|rfc2136)_out\(/', $fn_body($routes_ddns, $fn)) === 1, "{$fn}() returns the masked client");
}
check_api(strpos($fn_body($routes_ddns, 'restapi_dyndns_save'), "'data' => restapi_dyndns_out(") !== false &&
    strpos($fn_body($routes_ddns, 'restapi_rfc2136_save'), "'data' => restapi_rfc2136_out(") !== false, 'saves return the masked client');

/* DHCP and DHCPv6 servers: settings, interfaces, static mappings */
check_api(isset(restapi_areas()['services.dhcp']), 'the DHCP permission area exists');
$d4_pages = array('GET /v1/services/dhcp/interfaces' => 'services_dhcp.php', 'GET /v1/services/dhcpv6/interfaces' => 'services_dhcpv6.php');
foreach (array('dhcp' => '', 'dhcpv6' => '6') as $svc => $v) {
	$d4_pages += array("GET /v1/services/{$svc}/settings" => "services_{$svc}_settings.php", "PUT /v1/services/{$svc}/settings" => "services_{$svc}_settings.php",
	    "POST /v1/services/{$svc}/apply" => "services_{$svc}.php", "GET /v1/services/{$svc}/{if}/static-mappings" => "services_{$svc}.php",
	    "GET /v1/services/{$svc}/{if}/static-mappings/{id}" => "services_{$svc}.php",
	    "POST /v1/services/{$svc}/{if}/static-mappings" => "services_{$svc}_edit.php",
	    "PUT /v1/services/{$svc}/{if}/static-mappings/{id}" => "services_{$svc}_edit.php",
	    "DELETE /v1/services/{$svc}/{if}/static-mappings/{id}" => "services_{$svc}.php");
}
foreach ($d4_pages as $key => $page) {
	check_api(isset($seen[$key]), "route {$key} exists");
}
foreach ($v1 as $r) {
	$key = "{$r['method']} {$r['path']}";
	if (isset($d4_pages[$key])) {
		check_api($r['page'] === $d4_pages[$key], "{$key} is guarded by {$d4_pages[$key]}");
		if ($r['write'] && !preg_match('#/apply$#', $r['path'])) {
			check_api(isset($r['query']['apply']), "{$key} takes ?apply=true");
		}
	}
}
check_api(restapi_dhcp_server_rows(array('winsserver' => array('192.0.2.1'), 'dnsserver' => array('192.0.2.2', '192.0.2.3'), 'ntpserver' => array())) ===
    array('wins1' => '192.0.2.1', 'wins2' => '', 'dns1' => '192.0.2.2', 'dns2' => '192.0.2.3', 'dns3' => '', 'dns4' => '',
    'ntp1' => '', 'ntp2' => '', 'ntp3' => '', 'ntp4' => ''), 'WINS, DNS and NTP server lists become the numbered form fields');
check_api(api_error_status(function () { restapi_dhcp_server_rows(array('winsserver' => array('a', 'b', 'c'))); }) === 400 &&
    api_error_status(function () { restapi_dhcp_server_rows(array('ntpserver' => array_fill(0, 5, 'a'))); }) === 400,
    'at most 2 WINS and 4 DNS/NTP servers (the form fields)');
check_api(restapi_dhcp_option_rows(array(array('number' => 66, 'type' => 'text', 'value' => 'tftp'), array('number' => '67', 'value' => 'x'))) ===
    array('number0' => '66', 'itemtype0' => 'text', 'value0' => 'tftp', 'number1' => '67', 'itemtype1' => 'text', 'value1' => 'x'),
    'custom DHCP options become the form rows');
check_api(api_error_status(function () { restapi_dhcp_option_rows('66'); }) === 400 &&
    api_error_status(function () { restapi_dhcp_option_rows(array(array('number' => 1, 'bogus' => 1))); }) === 400 &&
    api_error_status(function () { restapi_dhcp_option_rows(array(array('value' => array('x')))); }) === 400 &&
    api_error_status(function () { restapi_dhcp_option_rows(array_fill(0, 100, array('number' => 1))); }) === 400,
    'custom DHCP options are validated (at most 99)');
check_api(restapi_dhcp_options_out(array('item' => array(array('number' => '66', 'type' => 'text', 'value' => base64_encode('t'))))) ===
    array(array('number' => '66', 'type' => 'text', 'value' => 't')) && restapi_dhcp_options_out('') === array(),
    'stored custom options read with their decoded value');
check_api(restapi_dhcp_addr_list(array('192.0.2.1', '', '192.0.2.2')) === array('192.0.2.1', '192.0.2.2') && restapi_dhcp_addr_list(null) === array(),
    'stored address lists read without empty entries');
check_api(restapi_dhcp_flag(array('a' => ''), 'a') && !restapi_dhcp_flag(array('a' => false), 'a') && !restapi_dhcp_flag(array(), 'a'),
    'a checkbox held as false right after a save reads as unticked');

$dhcp_inc = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/services_dhcp.inc");
check_api(strpos($dhcp_inc, '$_POST[') === false && strpos($dhcp_inc, '$_REQUEST') === false && strpos($dhcp_inc, 'header(') === false &&
    strpos($dhcp_inc, 'exit;') === false, 'services_dhcp.inc takes its form fields as parameters and never redirects');
check_api(strpos(file_get_contents("{$root}/src/usr/local/www/api/index.php"), "require_once('services_dhcp.inc');") !== false, 'the API front controller loads services_dhcp.inc');
check_api(strpos($guiconfig, 'function kea_custom_config_') === false && strpos($dhcp_inc, 'function kea_custom_config_editable(') !== false &&
    strpos($dhcp_inc, 'function kea_custom_config_enforce($stored, &$input_errors, ?array &$post = null)') !== false,
    'the Kea custom configuration guard lives in services_dhcp.inc and takes the form');
foreach (array('dhcp_staticmap_save', 'dhcp6_staticmap_save', 'kea_do_settings_post') as $fn) {
	check_api(preg_match('/kea_custom_config_enforce\([^;]*, \$post\);/', $fn_body($dhcp_inc, $fn)) === 1, "{$fn}() enforces the Kea custom configuration privilege on its form");
}
foreach (array('dhcp_iface_context', 'dhcp6_iface_context') as $fn) {
	check_api(strpos($fn_body($dhcp_inc, $fn), 'get_configured_interface_with_descr()') !== false && strpos($fn_body($dhcp_inc, $fn), 'return (null);') !== false,
	    "{$fn}() knows only configured interfaces");
}
$d4_save = $fn_body($dhcp_inc, 'dhcp_staticmap_save');
check_api(strpos($d4_save, '$ctx = dhcp_iface_context($if);') !== false && strpos($d4_save, "\$mapent['ntpserver'][] = \$post['ntp4'];") !== false &&
    strpos($d4_save, "\$warnings[] = sprintf(gettext('The IP address %1\$s is in use") !== false && strpos($d4_save, 'dhcp_staticmap_mark_dirty($if);') !== false,
    'DHCP mappings need a configured interface, keep the fourth NTP server and return the duplicate address warning');
check_api(strpos($d4_save, '$newid = array_search(') < strpos($d4_save, 'write_config("DHCP Server settings saved")'), 'the saved mapping is found before write_config() normalises it');
$d4_save6 = $fn_body($dhcp_inc, 'dhcp6_staticmap_save');
check_api(strpos($d4_save6, "} elseif (\$ctx['track6']) {") !== false && strpos($d4_save6, '"dhcpdv6/{$if}/ipaddrv6"') === false,
    'the DHCPv6 track6 suffix check looks at the interface (it read a setting that never exists)');
check_api(strpos($d4_save6, "(\$mapent['duid'] == str_replace(\"-\", \":\", \$post['duid']))") !== false, 'a DUID written with hyphens is a duplicate of the stored one');
foreach (array('dhcp_staticmap_delete', 'dhcp6_staticmap_delete') as $fn) {
	check_api(strpos($fn_body($dhcp_inc, $fn), 'is_numericint($id)') !== false && strpos($fn_body($dhcp_inc, $fn), 'dhcp_staticmap_mark_dirty($if') !== false,
	    "{$fn}() needs the position of an existing mapping and stages like a save");
}
check_api(substr_count($fn_body($dhcp_inc, 'dhcp_staticmap_mark_dirty'), 'mark_subsystem_dirty(') === 3 &&
    strpos($fn_body($dhcp_inc, 'dhcp_staticmap_mark_dirty'), "config_path_enabled('unbound', 'regdhcpstatic')") !== false,
    'static mapping saves and deletes stage the DHCP server, DNS Forwarder and DNS Resolver alike');
$d4_valid = $fn_body($dhcp_inc, 'dhcp_validate_settings_post');
foreach (array('kea_ha_roles()', 'kea_server_cert_list()', 'kea_client_cert_list()', "(ctype_digit(\$port['val']) && is_port(\$port['val']))",
    "'maxrejectedleaseupdates' => gettext('Max Rejected Updates')") as $needle) {
	check_api(strpos($d4_valid, $needle) !== false, "the Kea settings validation checks {$needle}");
}
check_api(strpos($fn_body($dhcp_inc, 'dhcp_do_settings_post'), "kea_do_settings_post('kea', \$post") !== false &&
    strpos($fn_body($dhcp_inc, 'dhcp6_do_settings_post'), "kea_do_settings_post('kea6', \$post") !== false, 'DHCP and DHCPv6 settings share one save');
foreach (array('kea_earlydnsreg_mappings', 'kea6_earlydnsreg_mappings') as $fn) {
	check_api(strpos($fn_body($dhcp_inc, $fn), 'if (!is_array($conf) ||') !== false, "{$fn}() skips an interface without settings (Apply failed after the last mapping was deleted)");
}
foreach (array('services_dhcp_edit.php' => array('dhcp_staticmap_form($if, $id, $_REQUEST)', 'dhcp_staticmap_save($if, $id, $_POST)', "set_flash_message('alert-info', \$warning)"),
    'services_dhcpv6_edit.php' => array('dhcp6_staticmap_form($if, $id, $_REQUEST)', 'dhcp6_staticmap_save($if, $id, $_POST)'),
    'services_dhcp_settings.php' => array('dhcp_do_settings_post($_POST)'), 'services_dhcpv6_settings.php' => array('dhcp6_do_settings_post($_POST)')) as $page => $calls) {
	$src = file_get_contents("{$root}/src/usr/local/www/{$page}");
	foreach ($calls as $call) {
		check_api(strpos($src, $call) !== false, "{$page} uses {$call}");
	}
	check_api(strpos($src, 'write_config(') === false && strpos($src, 'config_set_path(') === false && preg_match('/^function\s/m', $src) === 0,
	    "{$page} changes nothing itself and declares no functions");
}
check_api(strpos(file_get_contents("{$root}/src/usr/local/www/services_dhcp.php"), 'dhcp_staticmap_delete($if, $_POST[\'id\'])') !== false &&
    strpos(file_get_contents("{$root}/src/usr/local/www/services_dhcpv6.php"), 'dhcp6_staticmap_delete($if, $_POST[\'id\'])') !== false,
    'the DHCP pages delete static mappings through services_dhcp.inc');
$routes_dhcp = file_get_contents("{$root}/src/etc/inc/restapi/routes_dhcp.inc");
check_api(substr_count($routes_dhcp, 'write_config(') === 0 && substr_count($routes_dhcp, 'config_set_path(') === 0 &&
    substr_count($routes_dhcp, 'config_del_path(') === 0 && substr_count($routes_dhcp, 'mark_subsystem_dirty(') === 0,
    'DHCP API writes only through the GUI functions');
check_api(strpos($fn_body($routes_dhcp, 'restapi_dhcp_map_out'), "restapi_svc_mask(\$out['ddnsdomainkey'])") !== false &&
    strpos($fn_body($routes_dhcp, 'restapi_h_dhcp_map_update'), "restapi_ddns_secret_body(\$req['body'], 'ddnsdomainkey')") !== false &&
    strpos($fn_body($routes_dhcp, 'restapi_dhcp_map_save'), "'data' => restapi_dhcp_map_out(") !== false,
    'the dynamic DNS key of a mapping reads as "(set)" and "(set)" keeps it');
check_api(strpos($fn_body($routes_dhcp, 'restapi_dhcp_settings_section'), "dhcp_is_backend('kea')") !== false, 'the settings endpoints need the Kea backend (like the pages)');
check_api(strpos($fn_body($routes_dhcp, 'restapi_dhcp_iface'), 'dhcp6_iface_context($if) : dhcp_iface_context($if)') !== false,
    'static mapping routes take only configured interfaces');

/* DHCP server of an interface and its address pools */
$d5_routes = array('GET /v1/services/dhcp/{if}' => 'restapi_h_dhcp_server_get', 'PUT /v1/services/dhcp/{if}' => 'restapi_h_dhcp_server_set',
    'GET /v1/services/dhcp/{if}/pools' => 'restapi_h_dhcp_pool_list', 'GET /v1/services/dhcp/{if}/pools/{id}' => 'restapi_h_dhcp_pool_get',
    'POST /v1/services/dhcp/{if}/pools' => 'restapi_h_dhcp_pool_create', 'PUT /v1/services/dhcp/{if}/pools/{id}' => 'restapi_h_dhcp_pool_update',
    'DELETE /v1/services/dhcp/{if}/pools/{id}' => 'restapi_h_dhcp_pool_delete');
foreach ($v1 as $r) {
	$key = "{$r['method']} {$r['path']}";
	if (isset($d5_routes[$key])) {
		check_api($r['handler'] === $d5_routes[$key] && $r['page'] === 'services_dhcp.php' && $r['area'] === 'services.dhcp',
		    "{$key} is handled by {$d5_routes[$key]} and guarded by services_dhcp.php");
		if ($r['write']) {
			check_api(isset($r['query']['apply']), "{$key} takes ?apply=true");
		}
		unset($d5_routes[$key]);
	}
}
check_api(empty($d5_routes), 'every DHCP server and pool route exists');
foreach (array('GET /v1/services/dhcp/settings' => 'restapi_h_dhcp_settings_get', 'PUT /v1/services/dhcp/settings' => 'restapi_h_dhcp_settings_set',
    'GET /v1/services/dhcp/interfaces' => 'restapi_h_dhcp_interfaces', 'POST /v1/services/dhcp/apply' => 'restapi_h_dhcp_apply',
    'GET /v1/services/dhcp/lan' => 'restapi_h_dhcp_server_get', 'GET /v1/services/dhcp/opt1/pools/2' => 'restapi_h_dhcp_pool_get') as $key => $handler) {
	list($m, $p) = explode(' ', $key);
	check_api(restapi_match($v1, $m, $p)[0]['handler'] === $handler, "{$key} reaches {$handler} (the fixed paths come before {if})");
}
check_api(restapi_dhcp_server_mask(array('ddnsdomainkey' => 'k', 'omapi_key' => '', 'domain' => 'd')) ===
    array('ddnsdomainkey' => '(set)', 'omapi_key' => '', 'domain' => 'd') && restapi_dhcp_server_mask(array('descr' => 'x')) === array('descr' => 'x'),
    'the ISC dynamic DNS and OMAPI keys read as "(set)"');
check_api(restapi_dhcp_server_body(array('ddnsdomainkey' => '(set)', 'omapi_key' => '(set)', 'domain' => 'd')) === array('domain' => 'd') &&
    restapi_dhcp_server_body(array('omapi_key' => 'new')) === array('omapi_key' => 'new'), '"(set)" keeps the stored keys');
check_api(array_keys(restapi_dhcp_denyunknown_choices()) === array('disabled', 'enabled', 'class'), 'the Deny Unknown Clients choices are the page\'s');
check_api(strpos($fn_body($routes_dhcp, 'restapi_dhcp_server_iface'), "\$ctx['eligible']") !== false &&
    strpos($fn_body($routes_dhcp, 'restapi_dhcp_server_write'), 'dhcp_server_save($if, $id, $act, $post)') !== false &&
    strpos($fn_body($routes_dhcp, 'restapi_dhcp_server_out'), 'restapi_dhcp_server_mask(') !== false &&
    strpos($fn_body($routes_dhcp, 'restapi_dhcp_pool_out'), 'restapi_dhcp_server_mask(') !== false &&
    strpos($fn_body($routes_dhcp, 'restapi_h_dhcp_pool_delete'), 'dhcp_pool_delete($if, $id)') !== false,
    'DHCP server routes need an interface the page offers, save and delete through the page functions and mask the keys');
foreach (array('restapi_h_dhcp_server_set', 'restapi_h_dhcp_pool_create', 'restapi_h_dhcp_pool_update') as $fn) {
	check_api(strpos($fn_body($routes_dhcp, $fn), 'restapi_dhcp_server_body($req[\'body\'])') !== false, "{$fn}() keeps \"(set)\" keys");
}
$d5_save = $fn_body($dhcp_inc, 'dhcp_server_save');
check_api(strpos($d5_save, '$ctx = dhcp_iface_context($if);') !== false && strpos($d5_save, "!\$ctx['eligible']") !== false,
    'the DHCP server is saved only for an interface the page offers');
check_api(strpos($d5_save, "if (\$post['dnsregpolicy'] && !array_key_exists(\$post['dnsregpolicy'], \$dnsregpolicy_values))") !== false &&
    substr_count($d5_save, "\$post['earlydnsregpolicy'] && !array_key_exists(") === 1, 'both DNS registration policies are validated (the early one was checked twice)');
check_api(strpos($d5_save, "\$post['if']") === false && strpos($d5_save, '$parent_ip = get_interface_ip($if);') !== false,
    'the gateway check uses the page\'s interface, not the posted if field');
check_api(preg_match('/kea_custom_config_enforce\([^;]*custom_kea_config[^;]*, \$input_errors, \$post\);/', $d5_save) === 1,
    'dhcp_server_save() enforces the Kea custom configuration privilege on its form');
check_api(strpos($d5_save, "dhcp_is_backend('isc')") !== false && strpos($d5_save, "\$post['omapi_gen_key'] == \"yes\"") !== false &&
    strpos($d5_save, "\$ret['missing_pool'] = true;") !== false && strpos($d5_save, "mark_subsystem_dirty('dhcpd');") !== false,
    'dhcp_server_save() keeps the ISC branch, reports a missing pool and stages the DHCP server');
check_api(strpos($fn_body($dhcp_inc, 'dhcp_pool_delete'), 'is_numericint($id)') !== false, 'deleting a pool needs the position of an existing pool');
check_api(strpos($fn_body($dhcp_inc, 'dhcp_build_pooltable'), 'global $if') === false, 'the pool table takes the interface as a parameter');
$dhcp_page = file_get_contents("{$root}/src/usr/local/www/services_dhcp.php");
foreach (array('dhcp_server_conf($if, $pool, $act)', 'dhcp_server_form($dhcpdconf ?? null,', 'dhcp_server_save($if, $pool ?? null, $act, $_POST)',
    'dhcp_pool_delete($if, $_POST[\'id\'])', 'dhcp_build_pooltable($if)', 'dhcp_server_dnsregpolicy_values()') as $call) {
	check_api(strpos($dhcp_page, $call) !== false, "services_dhcp.php uses {$call}");
}
check_api(strpos($dhcp_page, 'write_config(') === false && strpos($dhcp_page, 'config_set_path(') === false && strpos($dhcp_page, 'config_del_path(') === false &&
    preg_match('/^function\s/m', $dhcp_page) === 0, 'services_dhcp.php changes nothing itself and declares no functions');
check_api(preg_match("/header\('Location: \/services_dhcp.php\?if='\.\\\$if\);\n\t\t}\n\t}\n}/", $dhcp_page) === 1,
    'a saved pool redirects to the interface without exit (the page goes on like before)');
check_api(preg_match('/\$rv\[\'missing_pool\'\]\) \{\n[^\n]*\n\t\theader\("Location: services_dhcp.php"\);\n\t\texit;/', $dhcp_page) === 1,
    'saving a missing pool goes back to the start');

/* DHCPv6 server of an interface, its address pools and router advertisements */
$d6_routes = array('GET /v1/services/dhcpv6/{if}' => 'restapi_h_dhcp6_server_get', 'PUT /v1/services/dhcpv6/{if}' => 'restapi_h_dhcp6_server_set',
    'GET /v1/services/dhcpv6/{if}/pools' => 'restapi_h_dhcp6_pool_list', 'GET /v1/services/dhcpv6/{if}/pools/{id}' => 'restapi_h_dhcp6_pool_get',
    'POST /v1/services/dhcpv6/{if}/pools' => 'restapi_h_dhcp6_pool_create', 'PUT /v1/services/dhcpv6/{if}/pools/{id}' => 'restapi_h_dhcp6_pool_update',
    'DELETE /v1/services/dhcpv6/{if}/pools/{id}' => 'restapi_h_dhcp6_pool_delete');
$d6_ra = array('GET /v1/services/router-advertisements/{if}' => 'restapi_h_radvd_get', 'PUT /v1/services/router-advertisements/{if}' => 'restapi_h_radvd_set');
foreach ($v1 as $r) {
	$key = "{$r['method']} {$r['path']}";
	if (isset($d6_routes[$key])) {
		check_api($r['handler'] === $d6_routes[$key] && $r['page'] === 'services_dhcpv6.php' && $r['area'] === 'services.dhcp',
		    "{$key} is handled by {$d6_routes[$key]} and guarded by services_dhcpv6.php");
		if ($r['write']) {
			check_api(isset($r['query']['apply']), "{$key} takes ?apply=true");
		}
		unset($d6_routes[$key]);
	} elseif (isset($d6_ra[$key])) {
		check_api($r['handler'] === $d6_ra[$key] && $r['page'] === 'services_radvd.php' && $r['area'] === 'services.dhcp',
		    "{$key} is handled by {$d6_ra[$key]} and guarded by services_radvd.php");
		check_api(!isset($r['query']['apply']), "{$key} has no ?apply (the page applies at once)");
		unset($d6_ra[$key]);
	}
}
check_api(empty($d6_routes) && empty($d6_ra), 'every DHCPv6 server, pool and router advertisement route exists');
foreach (array('GET /v1/services/dhcpv6/settings' => 'restapi_h_dhcp6_settings_get', 'GET /v1/services/dhcpv6/interfaces' => 'restapi_h_dhcp_interfaces',
    'POST /v1/services/dhcpv6/apply' => 'restapi_h_dhcp6_apply', 'GET /v1/services/dhcpv6/opt1' => 'restapi_h_dhcp6_server_get',
    'GET /v1/services/dhcpv6/opt1/pools/2' => 'restapi_h_dhcp6_pool_get', 'GET /v1/services/dhcpv6/opt1/static-mappings' => 'restapi_h_dhcp6_map_list',
    'GET /v1/services/dhcp/lan' => 'restapi_h_dhcp_server_get', 'PUT /v1/services/router-advertisements/lan' => 'restapi_h_radvd_set') as $key => $handler) {
	list($m, $p) = explode(' ', $key);
	check_api(restapi_match($v1, $m, $p)[0]['handler'] === $handler, "{$key} reaches {$handler} (the fixed paths come before {if})");
}
check_api(restapi_dhcp_option_rows(array(array('number' => 23, 'value' => 'x')), false) === array('number0' => '23', 'value0' => 'x') &&
    api_error_status(function () { restapi_dhcp_option_rows(array(array('number' => 23, 'type' => 'text', 'value' => 'x')), false); }) === 400,
    'DHCPv6 custom options are {number, value} rows (no type)');
check_api(restapi_dhcp_options_out(array('item' => array(array('number' => '23', 'value' => base64_encode('v')))), false) ===
    array(array('number' => '23', 'value' => 'v')), 'stored DHCPv6 custom options read without a type');
check_api(restapi_radvd_subnet_rows(array('2001:db8::/64', 'lanhosts')) ===
    array('subnet_address0' => '2001:db8::', 'subnet_bits0' => '64', 'subnet_address1' => 'lanhosts', 'subnet_bits1' => ''),
    'RA subnets become the form rows (an alias has no bits)');
check_api(restapi_radvd_dns_rows(array('2001:db8::53')) === array('radns1' => '2001:db8::53', 'radns2' => '', 'radns3' => '', 'radns4' => '') &&
    api_error_status(function () { restapi_radvd_dns_rows(array_fill(0, 5, '::1')); }) === 400, 'at most 4 RA DNS servers (the form fields)');
check_api(restapi_dhcp6_server_mask(array('ddnsdomainkey' => 'k', 'domain' => 'd')) === array('ddnsdomainkey' => '(set)', 'domain' => 'd') &&
    restapi_dhcp6_server_mask(array('descr' => 'x')) === array('descr' => 'x'), 'the DHCPv6 dynamic DNS key reads as "(set)"');
check_api(array_keys(restapi_dhcp6_prefixrange_lengths()) === array(48, 52, 56, 59, 60, 61, 62, 63, 64), 'the prefix delegation sizes are the page\'s');
check_api(strpos($fn_body($routes_dhcp, 'restapi_dhcp6_server_iface'), 'dhcp6_server_offered($if)') !== false &&
    strpos($fn_body($routes_dhcp, 'restapi_dhcp6_server_iface'), "dhcp_is_backend('kea')") !== false &&
    strpos($fn_body($routes_dhcp, 'restapi_dhcp6_server_write'), 'dhcp6_server_save($if, $id, $act, $post)') !== false &&
    strpos($fn_body($routes_dhcp, 'restapi_dhcp6_server_out'), 'restapi_dhcp6_server_mask(') !== false &&
    strpos($fn_body($routes_dhcp, 'restapi_h_dhcp6_server_set'), "restapi_ddns_secret_body(\$req['body'], 'ddnsdomainkey')") !== false &&
    strpos($fn_body($routes_dhcp, 'restapi_h_dhcp6_pool_delete'), 'dhcp6_pool_delete($if, $id)') !== false,
    'DHCPv6 server routes need an interface the page offers (pools: Kea), save and delete through the page functions and mask the key');
check_api(strpos($fn_body($routes_dhcp, 'restapi_radvd_iface'), 'radvd_offered($if)') !== false &&
    strpos($fn_body($routes_dhcp, 'restapi_h_radvd_set'), 'radvd_save($if, restapi_radvd_post($if, $values))') !== false,
    'router advertisement routes need an interface the page offers and save through the page function');
$d6_save = $fn_body($dhcp_inc, 'dhcp6_server_save');
check_api(strpos($d6_save, 'if (!dhcp6_server_offered($if)) {') !== false, 'the DHCPv6 server is saved only for an interface the page offers');
check_api(strpos($d6_save, "kea_custom_config_enforce(is_array(\$dhcpdconf) ? array_get_path(\$dhcpdconf, 'custom_kea_config') : null, \$input_errors, \$post);") !== false,
    'the first DHCPv6 save of an interface works (array_get_path() on null was a TypeError) and the Kea custom configuration privilege applies to the form');
check_api(strpos($d6_save, "(inet_pton(\$map['ipaddrv6']) >= \$dynsubnet_start) &&") !== false &&
    strpos($d6_save, "(inet_pton(\$map['ipaddrv6']) <= \$dynsubnet_end)") !== false, 'a static mapping at either end of the DHCPv6 range overlaps it');
check_api(strpos($d6_save, "\$ret['missing_pool'] = true;") !== false && strpos($d6_save, "mark_subsystem_dirty('dhcpd6');") !== false &&
    strpos($d6_save, "dhcp_is_backend('kea')") !== false && strpos($d6_save, "\$dhcpdconf['prefixrange']['prefixlength'] = \$post['prefixrange_length'];") !== false,
    'dhcp6_server_save() keeps the ISC and Kea fields, reports a missing pool and stages the DHCPv6 server');
check_api(strpos($fn_body($dhcp_inc, 'dhcp6_pool_delete'), 'is_numericint($id)') !== false && strpos($fn_body($dhcp_inc, 'dhcp6_build_pooltable'), 'global $if') === false,
    'deleting a DHCPv6 pool needs the position of an existing pool; the pool table takes the interface as a parameter');
$ra_save = $fn_body($dhcp_inc, 'radvd_save');
check_api(strpos($ra_save, 'if (!radvd_offered($if)) {') !== false && strpos($ra_save, 'radvd_ramode_values()') !== false &&
    strpos($ra_save, 'radvd_rapriority_values()') !== false && strpos($ra_save, 'radvd_rainterface_values($if)') !== false,
    'router advertisements are saved only for an interface the page offers, with a mode, priority and RA interface the page offers');
check_api(strpos($ra_save, '$retval |= services_radvd_configure();') !== false && strpos($ra_save, 'mark_subsystem_dirty(') === false,
    'router advertisements apply at once like the page (nothing staged)');
$dhcp6_page = file_get_contents("{$root}/src/usr/local/www/services_dhcpv6.php");
foreach (array('dhcp6_server_iflist()', 'dhcp6_server_conf($if, $pool, $act)', 'dhcp6_server_form($dhcpdconf ?? null,', 'dhcp6_server_prefix((string)$if)',
    'dhcp6_server_relay_enabled($iflist)', 'dhcp6_server_save((string)$if, $pool ?? null, $act, $_POST)', 'dhcp6_pool_delete((string)$if, $_POST[\'id\'])',
    'dhcp6_build_pooltable($if)', 'dhcp_server_dnsregpolicy_values()') as $call) {
	check_api(strpos($dhcp6_page, $call) !== false, "services_dhcpv6.php uses {$call}");
}
$radvd_page = file_get_contents("{$root}/src/usr/local/www/services_radvd.php");
foreach (array("require_once('services_dhcp.inc');", 'radvd_form((string)$if)', 'radvd_save((string)$if, $_POST)', 'radvd_ramode_values()',
    'radvd_rapriority_values()', 'radvd_rainterface_values((string)$if)') as $call) {
	check_api(strpos($radvd_page, $call) !== false, "services_radvd.php uses {$call}");
}
foreach (array('services_dhcpv6.php' => $dhcp6_page, 'services_radvd.php' => $radvd_page) as $page => $src) {
	check_api(strpos($src, 'write_config(') === false && strpos($src, 'config_set_path(') === false && strpos($src, 'config_del_path(') === false &&
	    strpos($src, 'services_radvd_configure(') === false && preg_match('/^function\s/m', $src) === 0, "{$page} changes nothing itself and declares no functions");
}
check_api(preg_match("/header\('Location: \/services_dhcpv6.php\?if='\.\\\$if\);\n\t\t}\n\t}\n}/", $dhcp6_page) === 1,
    'a saved DHCPv6 pool redirects to the interface without exit (the page goes on like before)');
check_api(preg_match('/\$rv\[\'missing_pool\'\]\) \{\n\t\theader\("Location: services_dhcpv6.php"\);\n\t\texit;/', $dhcp6_page) === 1,
    'saving a missing DHCPv6 pool goes back to the start');

/* VPN: L2TP server and users, IPsec pre-shared keys, tunnel list actions, apply and status */
check_api(isset(restapi_areas()['vpn.ipsec']) && isset(restapi_areas()['vpn.l2tp']), 'the VPN permission areas exist');
$e1_routes = array(
	'GET /v1/vpn/l2tp' => array('restapi_h_l2tp_get', 'vpn_l2tp.php', 'vpn.l2tp', false),
	'PUT /v1/vpn/l2tp' => array('restapi_h_l2tp_set', 'vpn_l2tp.php', 'vpn.l2tp', false),
	'GET /v1/vpn/l2tp/users' => array('restapi_h_l2tp_user_list', 'vpn_l2tp_users.php', 'vpn.l2tp', false),
	'GET /v1/vpn/l2tp/users/{id}' => array('restapi_h_l2tp_user_get', 'vpn_l2tp_users.php', 'vpn.l2tp', false),
	'POST /v1/vpn/l2tp/users' => array('restapi_h_l2tp_user_create', 'vpn_l2tp_users_edit.php', 'vpn.l2tp', false),
	'PUT /v1/vpn/l2tp/users/{id}' => array('restapi_h_l2tp_user_update', 'vpn_l2tp_users_edit.php', 'vpn.l2tp', false),
	'DELETE /v1/vpn/l2tp/users/{id}' => array('restapi_h_l2tp_user_delete', 'vpn_l2tp_users.php', 'vpn.l2tp', false),
	'POST /v1/vpn/ipsec/apply' => array('restapi_h_ipsec_apply', 'vpn_ipsec.php', 'vpn.ipsec', false),
	'GET /v1/vpn/ipsec/status' => array('restapi_h_ipsec_status', 'status_ipsec.php', 'vpn.ipsec', false),
	'GET /v1/vpn/ipsec/pre-shared-keys' => array('restapi_h_ipsec_psk_list', 'vpn_ipsec_keys.php', 'vpn.ipsec', false),
	'GET /v1/vpn/ipsec/pre-shared-keys/{id}' => array('restapi_h_ipsec_psk_get', 'vpn_ipsec_keys.php', 'vpn.ipsec', false),
	'POST /v1/vpn/ipsec/pre-shared-keys' => array('restapi_h_ipsec_psk_create', 'vpn_ipsec_keys_edit.php', 'vpn.ipsec', true),
	'PUT /v1/vpn/ipsec/pre-shared-keys/{id}' => array('restapi_h_ipsec_psk_update', 'vpn_ipsec_keys_edit.php', 'vpn.ipsec', true),
	'DELETE /v1/vpn/ipsec/pre-shared-keys/{id}' => array('restapi_h_ipsec_psk_delete', 'vpn_ipsec_keys.php', 'vpn.ipsec', true),
	'GET /v1/vpn/ipsec/tunnels' => array('restapi_h_ipsec_tunnel_list', 'vpn_ipsec.php', 'vpn.ipsec', false),
	'GET /v1/vpn/ipsec/tunnels/{ikeid}' => array('restapi_h_ipsec_tunnel_get', 'vpn_ipsec.php', 'vpn.ipsec', false),
	'POST /v1/vpn/ipsec/tunnels/{ikeid}/toggle' => array('restapi_h_ipsec_tunnel_toggle', 'vpn_ipsec.php', 'vpn.ipsec', true),
	'DELETE /v1/vpn/ipsec/tunnels/{ikeid}' => array('restapi_h_ipsec_tunnel_delete', 'vpn_ipsec.php', 'vpn.ipsec', true),
	'GET /v1/vpn/ipsec/tunnels/{ikeid}/phase2/{uniqid}' => array('restapi_h_ipsec_p2_get', 'vpn_ipsec.php', 'vpn.ipsec', false),
	'POST /v1/vpn/ipsec/tunnels/{ikeid}/phase2/{uniqid}/toggle' => array('restapi_h_ipsec_p2_toggle', 'vpn_ipsec.php', 'vpn.ipsec', true),
	'DELETE /v1/vpn/ipsec/tunnels/{ikeid}/phase2/{uniqid}' => array('restapi_h_ipsec_p2_delete', 'vpn_ipsec.php', 'vpn.ipsec', true),
	'POST /v1/vpn/ipsec/tunnels' => array('restapi_h_ipsec_tunnel_create', 'vpn_ipsec_phase1.php', 'vpn.ipsec', true),
	'PUT /v1/vpn/ipsec/tunnels/{ikeid}' => array('restapi_h_ipsec_tunnel_update', 'vpn_ipsec_phase1.php', 'vpn.ipsec', true),
	'POST /v1/vpn/ipsec/tunnels/{ikeid}/phase2' => array('restapi_h_ipsec_p2_create', 'vpn_ipsec_phase2.php', 'vpn.ipsec', true),
	'PUT /v1/vpn/ipsec/tunnels/{ikeid}/phase2/{uniqid}' => array('restapi_h_ipsec_p2_update', 'vpn_ipsec_phase2.php', 'vpn.ipsec', true),
	'GET /v1/vpn/ipsec/mobile' => array('restapi_h_ipsec_mobile_get', 'vpn_ipsec_mobile.php', 'vpn.ipsec', false),
	'PUT /v1/vpn/ipsec/mobile' => array('restapi_h_ipsec_mobile_set', 'vpn_ipsec_mobile.php', 'vpn.ipsec', true),
	'GET /v1/vpn/ipsec/settings' => array('restapi_h_ipsec_settings_get', 'vpn_ipsec_settings.php', 'vpn.ipsec', false),
	'PUT /v1/vpn/ipsec/settings' => array('restapi_h_ipsec_settings_set', 'vpn_ipsec_settings.php', 'vpn.ipsec', false),
	'GET /v1/vpn/openvpn/servers' => array('restapi_h_ovpn_server_list', 'vpn_openvpn_server.php', 'vpn.openvpn', false),
	'GET /v1/vpn/openvpn/servers/{vpnid}' => array('restapi_h_ovpn_server_get', 'vpn_openvpn_server.php', 'vpn.openvpn', false),
	'POST /v1/vpn/openvpn/servers' => array('restapi_h_ovpn_server_create', 'vpn_openvpn_server.php', 'vpn.openvpn', false),
	'PUT /v1/vpn/openvpn/servers/{vpnid}' => array('restapi_h_ovpn_server_update', 'vpn_openvpn_server.php', 'vpn.openvpn', false),
	'DELETE /v1/vpn/openvpn/servers/{vpnid}' => array('restapi_h_ovpn_server_delete', 'vpn_openvpn_server.php', 'vpn.openvpn', false),
	'GET /v1/vpn/openvpn/clients' => array('restapi_h_ovpn_client_list', 'vpn_openvpn_client.php', 'vpn.openvpn', false),
	'GET /v1/vpn/openvpn/clients/{vpnid}' => array('restapi_h_ovpn_client_get', 'vpn_openvpn_client.php', 'vpn.openvpn', false),
	'POST /v1/vpn/openvpn/clients' => array('restapi_h_ovpn_client_create', 'vpn_openvpn_client.php', 'vpn.openvpn', false),
	'PUT /v1/vpn/openvpn/clients/{vpnid}' => array('restapi_h_ovpn_client_update', 'vpn_openvpn_client.php', 'vpn.openvpn', false),
	'DELETE /v1/vpn/openvpn/clients/{vpnid}' => array('restapi_h_ovpn_client_delete', 'vpn_openvpn_client.php', 'vpn.openvpn', false),
	'GET /v1/vpn/openvpn/csc' => array('restapi_h_ovpn_csc_list', 'vpn_openvpn_csc.php', 'vpn.openvpn', false),
	'GET /v1/vpn/openvpn/csc/{id}' => array('restapi_h_ovpn_csc_get', 'vpn_openvpn_csc.php', 'vpn.openvpn', false),
	'POST /v1/vpn/openvpn/csc' => array('restapi_h_ovpn_csc_create', 'vpn_openvpn_csc.php', 'vpn.openvpn', false),
	'PUT /v1/vpn/openvpn/csc/{id}' => array('restapi_h_ovpn_csc_update', 'vpn_openvpn_csc.php', 'vpn.openvpn', false),
	'DELETE /v1/vpn/openvpn/csc/{id}' => array('restapi_h_ovpn_csc_delete', 'vpn_openvpn_csc.php', 'vpn.openvpn', false),
	'GET /v1/vpn/openvpn/status' => array('restapi_h_ovpn_status', 'status_openvpn.php', 'vpn.openvpn', false),
);
foreach ($v1 as $r) {
	$key = "{$r['method']} {$r['path']}";
	if (strpos($r['path'], '/v1/vpn/') === 0) {
		check_api(isset($e1_routes[$key]), "{$key} is a known VPN route");
		list($handler, $page, $area, $staged) = $e1_routes[$key];
		check_api($r['handler'] === $handler && $r['page'] === $page && $r['area'] === $area,
		    "{$key} is handled by {$handler}, guarded by {$page} in area {$area}");
		check_api(($r['method'] === 'GET') xor $r['write'], "{$key}: only GET is a read");
		check_api(isset($r['query']['apply']) === $staged, "{$key} " . ($staged ? 'takes ?apply (the GUI stages it)' :
		    'has no ?apply (applied at once like the page, or nothing to stage)'));
		unset($e1_routes[$key]);
	}
}
check_api(empty($e1_routes), 'every VPN route exists: ' . implode(', ', array_keys($e1_routes)));
foreach (array('GET /v1/vpn/l2tp/users/zed' => 'restapi_h_l2tp_user_get', 'GET /v1/vpn/ipsec/status' => 'restapi_h_ipsec_status',
    'GET /v1/vpn/ipsec/tunnels/901/phase2/6ac45d53a2b50' => 'restapi_h_ipsec_p2_get', 'POST /v1/vpn/ipsec/tunnels/901/toggle' => 'restapi_h_ipsec_tunnel_toggle',
    'DELETE /v1/vpn/ipsec/pre-shared-keys/e1eap' => 'restapi_h_ipsec_psk_delete') as $key => $handler) {
	list($m, $p) = explode(' ', $key);
	check_api(restapi_match($v1, $m, $p)[0]['handler'] === $handler, "{$key} reaches {$handler}");
}
$p1_out = restapi_ipsec_entry_out(array('ikeid' => '3', 'pre-shared-key' => 'k', 'pkcs11pin' => '1234', 'disabled' => '', 'descr' => 'd'),
    restapi_ipsec_p1_secrets());
check_api($p1_out === array('ikeid' => '3', 'pre-shared-key' => '(set)', 'pkcs11pin' => '(set)', 'disabled' => true, 'descr' => 'd', 'mobile' => false),
    'a phase 1 entry reads with its secrets as "(set)" and its flags as booleans');
check_api(restapi_ipsec_entry_out(array('uniqid' => 'u', 'mobile' => '')) === array('uniqid' => 'u', 'mobile' => true, 'disabled' => false),
    'a phase 2 entry reads with its flags as booleans');
check_api(restapi_ipsec_entry_out(array('pre-shared-key' => ''), restapi_ipsec_p1_secrets())['pre-shared-key'] === '', 'an empty secret reads as empty');
check_api(restapi_ipsec_button_post('togglep2', 4) === array('togglep2_4' => 'togglep2_4') && restapi_ipsec_button_post('del', 0) === array('del_0' => 'del_0'),
    'tunnel actions post the list page\'s row button');
check_api(restapi_vpn_secret_body(array('psk' => '(set)', 'ident' => 'x'), 'psk') === array('ident' => 'x') &&
    restapi_vpn_secret_body(array('psk' => 'new'), 'psk') === array('psk' => 'new'), '"(set)" keeps a secret');
check_api(restapi_vpn_select('', array('chap' => 'CHAP', 'pap' => 'PAP')) === 'chap' && restapi_vpn_select('pap', array('chap' => 'CHAP', 'pap' => 'PAP')) === 'pap' &&
    restapi_vpn_select('7', array_combine(range(32, 1), range(32, 1))) === '7', 'a select reads as the option the form preselects');
$l2_cur = array('enable' => true, 'interface' => 'lan', 'localip' => '10.0.0.1', 'remoteip' => '10.0.0.16', 'l2tp_subnet' => '28', 'n_l2tp_units' => '4',
    'secret' => 'old', 'paporchap' => 'chap', 'l2tp_dns1' => '', 'l2tp_dns2' => '', 'mtu' => '', 'radiusenable' => false, 'radacct_enable' => true,
    'radiusserver' => '', 'radiussecret' => '', 'radiusissueips' => false);
$l2_post = restapi_l2tp_post($l2_cur, $l2_cur, array());
check_api($l2_post['mode'] === 'server' && !array_key_exists('enable', $l2_post) && $l2_post['secret'] === DMYPWD && $l2_post['secret_confirm'] === DMYPWD &&
    $l2_post['radiussecret'] === '' && $l2_post['radiussecret_confirm'] === '' && $l2_post['radacct_enable'] === 'yes' && $l2_post['radiusenable'] === null,
    'the L2TP form post: Enable posts mode=server, a stored secret posts the placeholder (kept), confirmations repeat, unticked boxes are null');
$l2_post = restapi_l2tp_post(array('enable' => false, 'secret' => 'new') + $l2_cur, $l2_cur, array('enable' => false, 'secret' => 'new'));
check_api($l2_post['mode'] === null && $l2_post['secret'] === 'new' && $l2_post['secret_confirm'] === 'new', 'a new L2TP secret is posted with its confirmation');

$l2tp_inc = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/vpn_l2tp.inc");
$ipsec_inc = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/vpn_ipsec.inc");
$routes_vpn = file_get_contents("{$root}/src/etc/inc/restapi/routes_vpn.inc");
foreach (array($l2tp_inc, $ipsec_inc) as $src) {
	check_api(strpos($src, '$_POST') === false && strpos($src, '$_REQUEST') === false && strpos($src, 'header(') === false &&
	    strpos($src, 'exit;') === false, 'the VPN page includes take their form fields as parameters and never redirect');
}
foreach (array('vpn_l2tp.php' => array('l2tp_settings_save($_POST)', 'l2tp_settings_form()'), 'vpn_l2tp_users.php' => array('l2tp_user_delete($_POST[\'id\'])'),
    'vpn_l2tp_users_edit.php' => array('l2tp_user_save($_POST, $id ?? null)', 'l2tp_user_form($id)'),
    'vpn_ipsec.php' => array('ipsec_apply_changes()', 'ipsec_tunnels_action($_POST)'),
    'vpn_ipsec_keys.php' => array('ipsec_apply_changes()', 'ipsec_psk_delete($_POST[\'id\'])'),
    'vpn_ipsec_keys_edit.php' => array('ipsec_psk_save($_POST, $id ?? null)', 'ipsec_psk_form($id)', 'ipsec_psk_ident_type_list()')) as $page => $calls) {
	$src = file_get_contents("{$root}/src/usr/local/www/{$page}");
	foreach ($calls as $call) {
		check_api(strpos($src, $call) !== false, "{$page} uses {$call}");
	}
	check_api(strpos($src, 'write_config(') === false && strpos($src, 'config_set_path(') === false && strpos($src, 'config_del_path(') === false &&
	    strpos($src, 'mark_subsystem_dirty(') === false && strpos($src, 'filter_configure(') === false, "{$page} changes the configuration only through the shared include");
}
check_api(strpos(file_get_contents("{$root}/src/usr/local/www/vpn_ipsec_keys_edit.php"), 'function build_ipsecid_list(') === false &&
    strpos($ipsec_inc, 'function ipsec_psk_ident_type_list(') !== false, 'the key page\'s build_ipsecid_list() is ipsec_psk_ident_type_list()');
$tun_action = $fn_body($ipsec_inc, 'ipsec_tunnels_action');
check_api(strpos($tun_action, "config_set_path('ipsec/phase2/' . \$dp2idx . '/disabled', true);") !== false &&
    substr_count($tun_action, "config_set_path('ipsec/phase2/' . \$togglebtnp2 . '/disabled', true);") === 1,
    'disabling a phase 1 with a VTI phase 2 disables its phase 2 entries (it used an unset index)');
check_api(substr_count($tun_action, '/* no such entry */') === 4 && strpos($fn_body($ipsec_inc, 'ipsec_delete_p1_entries'), "is_array(config_get_path('ipsec/phase1/' . \$idx))") !== false,
    'toggling or deleting a missing entry changes nothing (a toggle added an empty entry)');
check_api(substr_count($tun_action, 'is_interface_ipsec_vti_assigned(') === 4 && strpos($ipsec_inc, 'delete_p1_and_children($p1list)') !== false,
    'an assigned VTI phase 2 still blocks deleting and disabling');
check_api(strpos(file_get_contents("{$root}/src/usr/local/www/vpn_ipsec.php"), 'name="movep2_<?=$ph2index?>" value="movep2_<?=$ph2index?>"') !== false,
    'the phase 2 move button carries the entry\'s position in ipsec/phase2 (it used the row number within the tunnel)');
$apply_fn = $fn_body($ipsec_inc, 'ipsec_apply_changes');
check_api(strpos($apply_fn, 'ipsec_configure()') < strpos($apply_fn, 'ipsec_reload_package_hook()') &&
    strpos($apply_fn, 'ipsec_reload_package_hook()') < strpos($apply_fn, 'filter_configure()') && strpos($apply_fn, "clear_subsystem_dirty('ipsec')") !== false,
    'IPsec apply reconfigures IPsec, runs the package hooks and reloads the filter');
$psk_save = $fn_body($ipsec_inc, 'ipsec_psk_save');
foreach (array('A valid secret type must be selected.', 'A valid identifier type must be selected.', "A valid mask for 'Virtual Address Pool' must be specified.",
    "strlen(\$post['dns_address']) > 0", "if (\$existing && ((string)\$idx === (string)\$id)) {") as $needle) {
	check_api(strpos($psk_save, $needle) !== false, "pre-shared key save checks: {$needle}");
}
$l2_save = $fn_body($l2tp_inc, 'l2tp_settings_save');
foreach (array('A valid interface must be selected.', 'A valid authentication type must be selected.', "(\$post['n_l2tp_units'] < 1)",
    'The secret cannot contain control characters.', 'The RADIUS secret cannot contain control characters.') as $needle) {
	check_api(strpos($l2_save, $needle) !== false, "L2TP settings save checks: {$needle}");
}
$user_save = $fn_body($l2tp_inc, 'l2tp_user_save');
check_api(strpos($user_save, 'The password cannot contain control characters.') !== false && strpos($user_save, "if (\$this_secret_config && ((string)\$idx === (string)\$id)) {") !== false,
    'an L2TP user password has no line breaks; a rename to an existing name is refused');
$vpn_inc = file_get_contents("{$root}/src/etc/inc/vpn.inc");
$l2_conf = $fn_body($vpn_inc, 'vpn_l2tp_configure');
check_api(strpos($l2_conf, "\$radiussecret = str_replace('\"', '\\\"', array_get_path(\$l2tpcfg, 'radius/secret', ''));") !== false,
    'the RADIUS secret is escaped in mpd.conf like the L2TP secret');
check_api(strpos($l2_conf, "\t\treturn true;\n") === false, 'stopping a disabled L2TP server reports success (the page showed a failure)');
check_api(substr_count($routes_vpn, 'write_config(') === 0 && substr_count($routes_vpn, 'config_set_path(') === 0 && substr_count($routes_vpn, 'config_del_path(') === 0,
    'VPN API writes only through the GUI functions');
check_api(strpos($fn_body($routes_vpn, 'restapi_ipsec_list_button'), 'ipsec_tunnels_action(restapi_ipsec_button_post($button, $pos))') !== false &&
    strpos($fn_body($routes_vpn, 'restapi_ipsec_list_button'), "new RestApiError(409, 'in_use'") !== false,
    'tunnel actions run the list page\'s action function; a refusal is 409');
check_api(strpos($fn_body($routes_vpn, 'restapi_l2tp_out'), "restapi_svc_mask(\$settings['secret'])") !== false &&
    strpos($fn_body($routes_vpn, 'restapi_l2tp_out'), "restapi_svc_mask(\$settings['radiussecret'])") !== false &&
    strpos($fn_body($routes_vpn, 'restapi_l2tp_user_out'), "restapi_svc_mask(\$u['password'] ?? '')") !== false &&
    strpos($fn_body($routes_vpn, 'restapi_ipsec_psk_out'), "restapi_svc_mask(\$out['psk'])") !== false &&
    strpos($fn_body($routes_vpn, 'restapi_ipsec_p1_out'), 'restapi_ipsec_p1_secrets()') !== false,
    'L2TP secrets, user passwords, pre-shared keys and phase 1 secrets read as "(set)"');
check_api(strpos($fn_body($routes_vpn, 'restapi_h_ipsec_status'), "'pre-shared-key'") === false && strpos($fn_body($routes_vpn, 'restapi_h_ipsec_status'), "\$p1['ikeid']") !== false,
    'the IPsec status lists connection state only (no phase 1 fields)');
foreach (array('vpn_l2tp.inc', 'vpn_ipsec.inc') as $inc) {
	check_api(strpos(file_get_contents("{$root}/src/usr/local/www/api/index.php"), "require_once('{$inc}');") !== false, "the API front controller loads {$inc}");
}


/* VPN: IPsec phase 1/2 editing, mobile clients and advanced settings */
$p1c = array('iketype' => array('ikev1' => 1, 'ikev2' => 1, 'auto' => 1), 'protocol' => array('inet' => 1, 'inet6' => 1, 'both' => 1),
    'interface' => array('wan' => 'WAN', 'lan' => 'LAN'), 'authentication_method' => array('cert' => 1, 'pre_shared_key' => 1),
    'mode' => array('main' => 1, 'aggressive' => 1), 'myid_type' => array('myaddress' => 1, 'fqdn' => 1), 'peerid_type' => array('any' => 1, 'peeraddress' => 1),
    'certref' => array('c1' => 'Cert'), 'caref' => array(), 'startaction' => array('' => 'Default', 'none' => 1), 'closeaction' => array('' => 'Default'),
    'nat_traversal' => array('on' => 1, 'force' => 1), 'mobike' => array('on' => 1, 'off' => 1), 'ealgo' => array('aes' => 1, 'chacha20poly1305' => 1),
    'halgo' => array('sha1' => 1, 'sha256' => 1), 'dhgroup' => array(1 => 1, 14 => 1));
$p1form = array('ikeid' => '5', 'interface' => 'lan', 'remotegw' => '192.0.2.1', 'iketype' => 'ikev2', 'protocol' => 'inet', 'authentication_method' => 'pre_shared_key',
    'myid_type' => 'myaddress', 'peerid_type' => 'peeraddress', 'pskey' => 'k', 'encryption' => array('item' => array(array('encryption-algorithm' =>
    array('name' => 'aes', 'keylen' => '256'), 'hash-algorithm' => 'sha256', 'prf-algorithm' => 'md5', 'dhgroup' => '14'))), 'dpd_enable' => true,
    'nat_traversal' => null, 'startaction' => 'none', 'prfselect_enable' => 'yes');
$f = restapi_ipsec_p1_fields($p1form, $p1c);
check_api($f['ikeid'] === '5' && $f['mobile'] === false && $f['interface'] === 'lan' && $f['mode'] === 'main' && $f['certref'] === 'c1' && $f['caref'] === '' &&
    $f['nat_traversal'] === 'on' && $f['closeaction'] === '' && $f['startaction'] === 'none' && $f['pskey'] === 'k' && $f['dpd_enable'] === true &&
    $f['dpd_delay'] === '10' && $f['dpd_maxfail'] === '5' && $f['prfselect_enable'] === true && $f['gw_duplicates'] === false &&
    $f['encryption'] === array(array('algorithm' => 'aes', 'keylen' => '256', 'hash' => 'sha256', 'prf' => 'sha1', 'dhgroup' => '14')),
    'a phase 1 reads as its form: selects as the page preselects them, DPD delay/retries as the page fills them in, encryption rows');
$fm = restapi_ipsec_p1_fields(array('mobile' => 'true', 'remotegw' => 'x', 'startaction' => 'none', 'gw_duplicates' => true) + $p1form, $p1c);
check_api($fm['mobile'] === true && $fm['remotegw'] === '' && $fm['startaction'] === '' && $fm['gw_duplicates'] === false, 'a mobile phase 1 has no remote gateway, start action or duplicates');
check_api(count(array_diff(array_keys($f), array_keys(restapi_ipsec_p1_types()))) === 0 && count(array_diff(array_keys(restapi_ipsec_p1_types()), array_keys($f))) === 0,
    'the phase 1 fields and their types match');
$masked = restapi_ipsec_mask_fields(array_merge($f, array('pkcs11pin' => '1234')), restapi_ipsec_p1_form_secrets());
check_api($masked['pskey'] === '(set)' && $masked['pkcs11pin'] === '(set)' && restapi_ipsec_mask_fields(array('pskey' => ''), array('pskey'))['pskey'] === '',
    'the phase 1 form\'s key and PKCS#11 PIN read as "(set)"');
$f['encryption'][] = array('algorithm' => 'chacha20poly1305', 'keylen' => '', 'hash' => 'sha256', 'prf' => 'sha256', 'dhgroup' => '14');
$f['certref'] = 'c1'; $f['caref'] = 'ca1'; $f['pkcs11certref'] = 'p'; $f['pkcs11pin'] = '1';
$post = restapi_ipsec_p1_post($f, array('aes'));
check_api($post['ealgo_algo0'] === 'aes' && $post['ealgo_keylen0'] === '256' && $post['halgo0'] === 'sha256' && $post['prfalgo0'] === 'sha1' &&
    $post['ealgo_algo1'] === 'chacha20poly1305' && !isset($post['ealgo_keylen1']) && $post['dpd_enable'] === 'yes' && !isset($post['disabled']) &&
    !isset($post['certref']) && !isset($post['caref']) && !isset($post['pkcs11pin']) && $post['pskey'] === 'k' && $post['ikeid'] === '5' && !isset($post['mobile']) &&
    $post['remotegw'] === '192.0.2.1' && $post['startaction'] === 'none',
    'the phase 1 post: encryption rows as numbered fields (no key length for ChaCha20), ticked boxes "yes", no certificate fields for a PSK');
$post = restapi_ipsec_p1_post(array('authentication_method' => 'cert') + $f, array('aes'));
check_api($post['certref'] === 'c1' && $post['caref'] === 'ca1' && !isset($post['pkcs11certref']), 'certificate methods post the certificate and CA');
$post = restapi_ipsec_p1_post(array('authentication_method' => 'pkcs11') + $f, array('aes'));
check_api(!isset($post['certref']) && $post['caref'] === 'ca1' && $post['pkcs11certref'] === 'p' && $post['pkcs11pin'] === '1', 'PKCS#11 posts the token fields and the CA');
$post = restapi_ipsec_p1_post(array('mobile' => true, 'gw_duplicates' => true, 'authentication_method' => 'eap-radius') + $f, array('aes'));
check_api($post['mobile'] === 'true' && !isset($post['remotegw']) && !isset($post['startaction']) && !isset($post['gw_duplicates']) && $post['certref'] === 'c1' &&
    !isset($post['caref']), 'a mobile phase 1 posts mobile=true and none of the fields its page lacks');
check_api(api_error_status(function () { restapi_ipsec_p1_rows('aes'); }) === 400 && api_error_status(function () { restapi_ipsec_p1_rows(array(array('bits' => 1))); }) === 400 &&
    api_error_status(function () { restapi_ipsec_p1_rows(array(array('algorithm' => array()))); }) === 400 &&
    restapi_ipsec_p1_rows(array(array('algorithm' => 'aes'))) === array(array('algorithm' => 'aes', 'keylen' => '', 'hash' => '', 'prf' => '', 'dhgroup' => '')),
    'phase 1 encryption rows: a list of objects with known string fields');

$p2c = array('mode' => array('tunnel' => 1, 'tunnel6' => 1, 'transport' => 1, 'vti' => 1), 'proto' => array('esp' => 1, 'ah' => 1),
    'localid_type' => array('address' => 1, 'network' => 1, 'lan' => 1), 'natlocalid_type' => array('none' => 1, 'address' => 1, 'network' => 1),
    'keylens' => array('aes' => array('256', '192', '128'), 'aes128gcm' => array('128', '96', '64'), 'chacha20poly1305' => array()),
    'pfsgroup' => array(0 => 'off', 14 => 1));
$p2form = array('ikeid' => '5', 'uniqid' => 'u1', 'localid_type' => 'lan', 'remoteid_type' => 'network', 'proto' => 'esp', 'ealgos' => array('aes', 'aes128gcm'),
    'keylen_aes' => 128, 'halgos' => array('hmac_sha256'), 'pfsgroup' => '14', 'lifetime' => '3600');
$f2 = restapi_ipsec_p2_fields($p2form, $p2c);
check_api($f2['mode'] === 'tunnel' && $f2['natlocalid_type'] === 'none' && $f2['remoteid_netbits'] === '24' && $f2['localid_netbits'] === '' && $f2['reqid'] === '' &&
    $f2['encryption'] === array(array('algorithm' => 'aes', 'keylen' => '128'), array('algorithm' => 'aes128gcm', 'keylen' => 'auto')) &&
    $f2['halgos'] === array('hmac_sha256') && $f2['pfsgroup'] === '14' && $f2['keepalive'] === false && $f2['mobile'] === false,
    'a phase 2 reads as its form (an empty network\'s mask as the page fills it in, key lengths as preselected)');
check_api(restapi_ipsec_p2_fields(array('mode' => 'tunnel6') + $p2form, $p2c)['remoteid_netbits'] === '64', 'an empty IPv6 network\'s mask is 64');
$f2m = restapi_ipsec_p2_fields(array('mobile' => true, 'remoteid_type' => 'mobile', 'pinghost' => 'x', 'keepalive' => true) + $p2form, array('pfsgroup' => array()) + $p2c);
check_api($f2m['remoteid_type'] === 'mobile' && $f2m['pinghost'] === '' && $f2m['keepalive'] === false && $f2m['pfsgroup'] === '',
    'a mobile phase 2: no remote network or keep alive; no PFS group when the mobile client settings set it');
check_api(count(array_diff(array_keys($f2), array_keys(restapi_ipsec_p2_types()))) === 0 && count(array_diff(array_keys(restapi_ipsec_p2_types()), array_keys($f2))) === 0,
    'the phase 2 fields and their types match');
$p = restapi_ipsec_p2_post(array('localid_type' => 'address', 'localid_address' => '10.0.0.1', 'localid_netbits' => '24', 'remoteid_address' => '10.1.0.0',
    'disabled' => true) + $f2, $p2c);
check_api($p['localid_address'] === '10.0.0.1' && !isset($p['localid_netbits']) && $p['remoteid_address'] === '10.1.0.0' && $p['remoteid_netbits'] === '24' &&
    !isset($p['natlocalid_address']) && $p['ealgos'] === array('aes', 'aes128gcm') && $p['keylen_aes'] === '128' && $p['keylen_aes128gcm'] === 'auto' &&
    !isset($p['keylen_chacha20poly1305']) && $p['halgos'] === array('hmac_sha256') && $p['pfsgroup'] === '14' && $p['disabled'] === 'yes' && !isset($p['reqid']) &&
    $p['pinghost'] === '' && !isset($p['keepalive']) && !isset($p['mobile']) && $p['uniqid'] === 'u1' && $p['ikeid'] === '5',
    'the phase 2 post: addresses and masks only as their types use them, a key length per algorithm, hashes with AES-CBC');
$p = restapi_ipsec_p2_post(array('encryption' => array(array('algorithm' => 'aes128gcm', 'keylen' => '96')), 'reqid' => '7') + $f2, $p2c);
check_api(!isset($p['halgos']) && $p['keylen_aes128gcm'] === '96' && $p['keylen_aes'] === 'auto' && $p['reqid'] === '7' && !isset($p['localid_address']),
    'no hashes with only AEAD algorithms (the page disables them); an interface network posts no address');
check_api(restapi_ipsec_p2_post(array('proto' => 'ah', 'encryption' => array()) + $f2, $p2c)['halgos'] === array('hmac_sha256') &&
    !isset(restapi_ipsec_p2_post(array('proto' => 'ah', 'encryption' => array()) + $f2, $p2c)['ealgos']), 'AH posts its hashes and no encryption');
$p = restapi_ipsec_p2_post($f2m, array('pfsgroup' => array()) + $p2c);
check_api($p['mobile'] === 'true' && !isset($p['remoteid_type']) && !isset($p['pinghost']) && !isset($p['pfsgroup']), 'a mobile phase 2 posts mobile=true, no remote network, no PFS group when set globally');
check_api(restapi_ipsec_p2_rows(array('aes128gcm', array('algorithm' => 'aes', 'keylen' => 256))) === array(array('algorithm' => 'aes128gcm', 'keylen' => ''),
    array('algorithm' => 'aes', 'keylen' => '256')) && api_error_status(function () { restapi_ipsec_p2_rows(array(array('name' => 'aes'))); }) === 400,
    'phase 2 encryption rows: algorithm names or {algorithm, keylen}');

$mf = restapi_ipsec_mobile_fields(array('user_source' => 'Local Database,radius1', 'auth_groups' => 'admins', 'group_source' => true, 'radiusaccounting' => true,
    'pool_netbits' => 24, 'pfs_group' => '99', 'pool_netbits_v6' => ''), array(0 => 'off', 14 => '14'));
check_api($mf['user_source'] === array('Local Database', 'radius1') && $mf['auth_groups'] === array('admins') && $mf['group_source'] === true &&
    $mf['radiusaccounting'] === true && $mf['pool_netbits'] === '24' && $mf['pool_netbits_v6'] === '120' && $mf['pfs_group'] === '0' && $mf['enable'] === false,
    'the mobile client settings read as the page shows them');
$mp = restapi_svc_post($mf, restapi_ipsec_mobile_types());
check_api($mp['user_source'] === array('Local Database', 'radius1') && $mp['group_source'] === 'yes' && $mp['radiusaccounting'] === 'yes' && !isset($mp['enable']) &&
    !isset($mp['user_source_choices']) && !isset($mp['phase1']), 'the mobile post: lists as multi-selects, ticked boxes "yes" (the page compares with "yes")');

$sc = array('categories' => array('dmn' => 'Daemon', 'ike' => 'IKE SA'), 'logging' => array('-1' => 'Silent', '1' => 'Control', '2' => 'Diag'),
    'uniqueids' => array('replace' => 1, 'keep' => 1), 'filtermode' => array('enc' => 1));
$sf = restapi_ipsec_settings_fields(array('logging' => array('dmn' => '-1', 'ike' => '9'), 'noshuntlaninterfaces' => true, 'async_crypto' => 'enabled',
    'compression' => true, 'bypassrules' => array('rule' => array(array('source' => '10.0.0.0', 'srcmask' => '8', 'destination' => '10.1.0.0', 'dstmask' => '16')))), $sc);
check_api($sf['logging'] === array('dmn' => '-1', 'ike' => '-1') && $sf['uniqueids'] === 'replace' && $sf['filtermode'] === 'enc' && $sf['async_crypto'] === true &&
    $sf['autoexcludelanaddress'] === false && $sf['compression'] === true && $sf['bypassrules'][0]['dstmask'] === '16',
    'the advanced settings read as the page shows them (Auto-exclude LAN is the inverse of noshuntlaninterfaces)');
$sp = restapi_ipsec_settings_post($sf);
check_api($sp['logging_dmn'] === '-1' && $sp['logging_ike'] === '-1' && $sp['async_crypto'] === 'yes' && !isset($sp['autoexcludelanaddress']) && $sp['compression'] === 'yes' &&
    $sp['source0'] === '10.0.0.0' && $sp['dstmask0'] === '16' && !isset($sp['logging']) && !isset($sp['bypassrules']) && !isset($sp['choices']),
    'the advanced settings post: logging_<category>, numbered bypass rule rows');
check_api(restapi_ipsec_logging_body(array('ike' => 2), array('dmn' => '1', 'ike' => '1')) === array('dmn' => '1', 'ike' => '2') &&
    api_error_status(function () { restapi_ipsec_logging_body(array('xyz' => '1'), array('ike' => '1')); }) === 400 &&
    api_error_status(function () { restapi_ipsec_bypass_rows(array(array('src' => 'x'))); }) === 400, 'log levels merge per category; unknown categories and rule fields are 400');

/* the pages are thin wrappers around the shared include */
foreach (array('vpn_ipsec_phase1.php' => array('ipsec_p1_form($p1,', 'ipsec_p1_save($pconfig,', 'ipsec_p1_interface_list()', 'ipsec_p1_auth_method_list(isset($pconfig[\'mobile\']))',
    'ipsec_p1_myid_list()', 'ipsec_p1_peerid_list()', 'ipsec_p1_pkcs11cert_list()', 'ipsec_p1_eal_list()'),
    'vpn_ipsec_phase2.php' => array('ipsec_p2_index($uindex)', 'ipsec_p2_form(', 'ipsec_p2_save($pconfig, $p2index)'),
    'vpn_ipsec_mobile.php' => array('ipsec_mobile_auth_groups()', 'ipsec_mobile_form()', 'ipsec_mobile_apply()', 'ipsec_mobile_save($pconfig)', 'ipsec_mobile_user_sources()'),
    'vpn_ipsec_settings.php' => array('ipsec_settings_form()', 'ipsec_settings_save($_POST)')) as $page => $calls) {
	$src = file_get_contents("{$root}/src/usr/local/www/{$page}");
	foreach ($calls as $call) {
		check_api(strpos($src, $call) !== false, "{$page} uses {$call}");
	}
	check_api(strpos($src, 'write_config(') === false && strpos($src, 'config_set_path(') === false && strpos($src, 'config_del_path(') === false &&
	    strpos($src, 'mark_subsystem_dirty(') === false && strpos($src, 'ipsec_configure(') === false && !preg_match('/^function /m', $src),
	    "{$page} changes the configuration only through the shared include and defines no functions");
}
foreach (array('vpn_ipsec_phase1.php' => 4, 'vpn_ipsec_phase2.php' => 3) as $page => $n) {
	check_api(substr_count(file_get_contents("{$root}/src/usr/local/www/{$page}"), '(ipsec_timer_entry($pconfig))') === $n,
	    "{$page}: the timer placeholders are computed from ipsec_timer_entry() (a Life Time that is not a number crashed the page)");
}
$p1_save = $fn_body($ipsec_inc, 'ipsec_p1_save');
check_api(strpos($p1_save, '$_POST') === false && strpos($p1_save, "if (\$pconfig['peerid_type'] == \"peeraddress\") {") !== false &&
    strpos($p1_save, "if (\$pconfig['myid_type'] == \"peeraddress\")") === false, 'the peer address identifier clears the peer identifier data (it tested myid_type)');
foreach (array('A valid authentication method must be selected.', 'A valid Internet Protocol must be selected.', 'A valid negotiation mode must be selected.',
    "A valid type must be selected for 'My identifier'.", "A valid type must be selected for 'Peer identifier'.", 'A valid NAT Traversal option must be selected.',
    'A valid MOBIKE option must be selected.', 'At least one encryption algorithm must be selected.', 'A valid encryption algorithm must be selected.',
    'A valid hash and PRF algorithm must be selected.', 'A valid DH group must be selected.', 'ipsec_ealgo_keylens($p1_ealgos[$algo])') as $needle) {
	check_api(strpos($p1_save, $needle) !== false, "phase 1 save checks: {$needle}");
}
check_api(strpos($p1_save, "config_set_path('ipsec/phase1/' . \$p1index, \$ph1ent);") !== false && strpos($p1_save, "mark_subsystem_dirty('ipsec');") !== false &&
    strpos($p1_save, 'route_del($old_ph1ent[\'remote-gateway\']);') !== false, 'phase 1 save stores in place or appends, stages IPsec, removes the old gateway route');
$p2_save = $fn_body($ipsec_inc, 'ipsec_p2_save');
check_api(strpos($p2_save, '$uindex') === false && strpos($p2_save, "is_interface_ipsec_vti_assigned(config_get_path('ipsec/phase2/' . \$p2index))") !== false,
    'switching away from VTI checks the edited entry\'s interface (it looked up the uniqid as a position)');
check_api(strpos($p2_save, "!is_array(ipsec_get_phase1(\$pconfig['ikeid']))") !== false, 'a phase 2 needs an existing phase 1 (an orphan was stored)');
foreach (array('A valid mode must be selected.', 'A valid protocol must be selected.', 'A valid local network type must be selected.',
    'A valid NAT/BINAT translation type must be selected.', 'A valid remote network type must be selected.', 'A valid hash algorithm must be selected.',
    'A valid PFS key group must be selected.', "array_merge(array('auto'), \$keylens)", "\$ealgos = ipsec_p2_pconfig_to_ealgos(\$pconfig);") as $needle) {
	check_api(strpos($p2_save, $needle) !== false, "phase 2 save checks: {$needle}");
}
check_api(strpos($fn_body($ipsec_inc, 'ipsec_p2_pconfig_to_ealgos'), "\$pconfig[\"keylen_\".\$algo_name]") !== false, 'the key lengths come from the form passed in');
$m_save = $fn_body($ipsec_inc, 'ipsec_mobile_save');
check_api(strpos($m_save, "unset(\$pconfig['radius_sockets']);") !== false && strpos($m_save, 'radius_retransmit_sockets') === false,
    'RADIUS sockets are dropped with the other advanced RADIUS parameters (it unset a field that does not exist)');
check_api(substr_count($m_save, "if (is_array(\$pconfig['user_source'])) {") === 2 && strpos($m_save, "(array)\$pconfig['user_source']") !== false,
    'the user sources are imploded at most once (implode() of the text or of nothing crashed the page)');
foreach (array('A valid User Authentication Source must be selected.', 'A valid Authentication Group must be selected.',
    "A valid mask for 'Virtual Address Pool Network' must be selected.", "A valid mask for 'Virtual IPv6 Address Pool Network' must be selected.",
    'A valid Phase2 PFS Group must be selected.') as $needle) {
	check_api(strpos($m_save, $needle) !== false, "mobile save checks: {$needle}");
}
check_api(strpos($fn_body($ipsec_inc, 'ipsec_mobile_apply'), 'ipsec_configure(true)') !== false, 'the mobile clients Apply restarts IPsec (#4353)');
$s_save = $fn_body($ipsec_inc, 'ipsec_settings_save');
check_api(strpos($s_save, "config_set_path('ipsec/ikev2_retransmit_jitter', \$post['ikev2_retransmit_jitter']);") !== false &&
    strpos($s_save, "config_get_path('ipsec/ikev2_retransmit_jitter', \$post") === false, 'the retransmit jitter is saved (it was config_get_path())');
check_api(strpos($s_save, "} elseif (config_path_enabled('ipsec', 'acceptunencryptedmainmode')) {") !== false, 'unencrypted IKEv1 main mode payloads can be turned off again');
check_api(substr_count($s_save, '$needsrestart = false;') === 1, 'the retransmission parameters no longer cancel a restart other changes need');
check_api(strpos($s_save, 'Unable to disable PKCS#11 support') < strpos($s_save, 'if (!$input_errors) {'),
    'PKCS#11 support in use is refused before anything is saved (the rest was saved and applied)');
check_api(strpos($s_save, 'A valid Unique IDs setting must be selected.') !== false && strpos($s_save, 'A valid IPsec Filter Mode must be selected.') !== false,
    'unique IDs and filter mode must be one of the page\'s choices');
check_api(strpos($s_save, 'ipsec_configure($needsrestart, $needsfilterdnsrestart);') !== false && strpos($s_save, 'system_setup_sysctl();') !== false &&
    strpos($s_save, "clear_subsystem_dirty('sysctl');") !== false && strpos($s_save, 'filter_configure()') !== false,
    'the advanced settings are applied at once like the page (filter, IPsec, tunables; the sysctl pending flag is cleared)');
foreach (array('restapi_ipsec_p1_write' => 'ipsec_p1_save($post, $pos, $old)', 'restapi_ipsec_p2_write' => 'ipsec_p2_save($post, $pos)',
    'restapi_h_ipsec_mobile_set' => 'ipsec_mobile_save($post)', 'restapi_h_ipsec_settings_set' => 'ipsec_settings_save(restapi_ipsec_settings_post($values))') as $fn => $call) {
	check_api(strpos($fn_body($routes_vpn, $fn), $call) !== false, "{$fn} goes through the page's {$call}");
}
check_api(strpos($fn_body($routes_vpn, 'restapi_ipsec_tunnel_full'), 'restapi_ipsec_mask_fields(restapi_ipsec_p1_form_fields($form), restapi_ipsec_p1_form_secrets())') !== false &&
    strpos($fn_body($routes_vpn, 'restapi_h_ipsec_tunnel_get'), 'restapi_ipsec_tunnel_full(') !== false &&
    strpos($fn_body($routes_vpn, 'restapi_ipsec_p1_write'), 'restapi_vpn_secret_body($body, $secret)') !== false,
    'a tunnel\'s form reads with its secrets as "(set)"; sending "(set)" keeps them');
check_api(strpos($fn_body($routes_vpn, 'restapi_h_ipsec_mobile_set'), 'ipsec_mobile_apply()') !== false &&
    strpos($fn_body($routes_vpn, 'restapi_h_ipsec_settings_set'), 'restapi_want_apply') === false, 'mobile ?apply restarts IPsec like its page; settings apply at once');

/* VPN: OpenVPN servers, clients, client specific overrides and status */
check_api(isset(restapi_areas()['vpn.openvpn']), 'the OpenVPN permission area exists');
check_api(restapi_ovpn_select(null, array(0 => 'none', 1 => 'default')) === '0' && restapi_ovpn_select('1', array(0 => 'none', 1 => 'default')) === '1' &&
    restapi_ovpn_select('x', array('a' => 1, 'b' => 2)) === 'a' && restapi_ovpn_select('', array('' => 'Default', 64 => '64')) === '' &&
    restapi_ovpn_select('b', array()) === '' && restapi_ovpn_select(2048, array(2048 => '2048 bit', 'none' => 'ECDH')) === '2048',
    'OpenVPN selects read as Form_Select preselects them (loose match, else the first option; nothing without options)');
check_api(restapi_ovpn_multi(array('2', 'x', '1'), array(1 => 'a', 2 => 'b', 3 => 'c')) === array('1', '2'), 'multiple selects post the selected options in option order');
$osc = array('mode' => array('p2p_tls' => 1, 'p2p_shared_key' => 1, 'server_tls' => 1, 'server_tls_user' => 1), 'authmode' => array('Local Database' => 'Local Database', 'radius1' => 'r'),
    'dev_mode' => array('tun' => 1, 'tap' => 1), 'protocol' => array('UDP4' => 1, 'TCP4' => 1), 'interface' => array('wan' => 'WAN', 'lan' => 'LAN', 'lan|10.0.0.9' => 'vip'),
    'tls_type' => array('auth' => 1, 'crypt' => 1), 'tlsauth_keydir' => array('default' => 1, '0' => 1, '1' => 1, '2' => 1), 'caref' => array('ca1' => 'CA'), 'crlref' => array(),
    'certref' => array(' ' => '== Server ==', 'c1' => 'Cert', '  ' => '== Non-Server =='), 'dh_length' => array(2048 => 1, 'none' => 1), 'ecdh_curve' => array('none' => 1, 'prime256v1' => 1),
    'data_ciphers' => array('AES-256-GCM' => 1, 'AES-256-CBC' => 1), 'data_ciphers_fallback' => array('AES-256-CBC' => 1, 'AES-256-GCM' => 1), 'digest' => array('SHA256' => 1, 'SHA1' => 1),
    'cert_depth' => array('' => 'Do Not Check', 1 => 'One', 2 => 'Two'), 'tunnel_networkv6_type' => array('staticv6' => 1, 'track6' => 1), 'tunnel_track6_interface' => array(),
    'serverbridge_interface' => array('none' => 'none', 'lan' => 'LAN'), 'allow_compression' => array('asym' => 1, 'no' => 1, 'yes' => 1), 'compression' => array('' => 'off', 'lz4' => 1),
    'topology' => array('subnet' => 1, 'net30' => 1), 'ping_method' => array('keepalive' => 1, 'ping' => 1), 'ping_action' => array('ping_restart' => 1, 'ping_exit' => 1),
    'netbios_ntype' => array(0 => 'none', 1 => 'b', 8 => 'h'), 'exit_notify' => array(0 => 'off', 1 => 'once', 2 => 'twice'), 'sndrcvbuf' => array('' => 'Default', 65536 => '64 KiB'),
    'create_gw' => array('both' => 1, 'v4only' => 1, 'v6only' => 1), 'verbosity_level' => array(0 => 'none', 1 => 'default', 3 => '3'), 'keepalive_defaults' => array(10, 60));
$sp = array('mode' => 'server_tls', 'protocol' => 'UDP4', 'interface' => 'lan', 'local_port' => '51194', 'description' => 'd', 'tlsauth_enable' => 'yes', 'tls' => "-----BEGIN OpenVPN Static key V1-----\nk",
    'tls_type' => 'crypt', 'tlsauth_keydir' => '', 'caref' => 'ca1', 'certref' => 'c1', 'dh_length' => '2048', 'data_ciphers' => 'AES-256-GCM,AES-256-CBC', 'data_ciphers_fallback' => 'AES-256-CBC',
    'cert_depth' => 1, 'remote_cert_tls' => true, 'tunnel_network' => '10.250.250.0/24', 'create_gw' => 'v4only', 'verbosity_level' => 1, 'autokey_enable' => 'yes', 'autotls_enable' => 'yes',
    'username_as_common_name' => true, 'exit_notify' => 1, 'inactive_seconds' => 0, 'keepalive_interval' => '', 'ping_push' => '', 'custom_options' => 'push "x"', 'disable' => false);
$sf = restapi_ovpn_server_fields($sp, $osc);
check_api($sf['mode'] === 'server_tls' && $sf['authmode'] === array('Local Database') && $sf['dev_mode'] === 'tun' && $sf['tlsauth_keydir'] === 'default' && $sf['tls_type'] === 'crypt' &&
    $sf['autotls_enable'] === false && $sf['autokey_enable'] === true && $sf['tlsauth_enable'] === true && $sf['remote_cert_tls'] === true && $sf['username_as_common_name'] === true &&
    $sf['data_ciphers'] === array('AES-256-GCM', 'AES-256-CBC') && $sf['cert_depth'] === '1' && $sf['create_gw'] === 'v4only' && $sf['crlref'] === '' && $sf['caref'] === 'ca1' &&
    $sf['keepalive_interval'] === '10' && $sf['keepalive_timeout'] === '60' && $sf['ping_seconds'] === '10' && $sf['ping_action_seconds'] === '60' && $sf['inactive_seconds'] === '0' &&
    $sf['tunnel_networkv6_type'] === 'staticv6' && $sf['tunnel_track6_prefix_id'] === '0' && $sf['exit_notify'] === '1' && $sf['verbosity_level'] === '1' && $sf['netbios_ntype'] === '0' &&
    $sf['compression'] === '' && $sf['sndrcvbuf'] === '' && $sf['disable'] === false && $sf['tls'] === $sp['tls'],
    'a server reads as its edit form: selects and number fields as the page fills them in, no TLS key generation while a key is set, the first authentication backend preselected');
check_api(count(array_diff(array_keys($sf), array_keys(restapi_ovpn_server_types()))) === 0 && count(array_diff(array_keys(restapi_ovpn_server_types()), array_keys($sf))) === 0,
    'the server fields and their types match');
check_api(restapi_ovpn_server_fields(array('create_gw' => 'bogus') + $sp, $osc)['create_gw'] === '' &&
    restapi_ovpn_server_fields(array('authmode' => 'radius1,Local Database') + $sp, $osc)['authmode'] === array('Local Database', 'radius1') &&
    restapi_ovpn_server_fields(array('tls' => '', 'autotls_enable' => 'yes') + $sp, $osc)['autotls_enable'] === true &&
    restapi_ovpn_server_fields(array('shared_key' => 'k') + $sp, $osc)['autokey_enable'] === false,
    'no gateway radio for an unknown value; authentication backends in option order; key generation offered only without a key');
$masked = restapi_ovpn_mask(array('tls' => 'secret', 'shared_key' => '', 'auth_pass' => 'p', 'proxy_passwd' => 'q', 'description' => 'd'), restapi_ovpn_secrets('client'));
check_api($masked === array('tls' => '(set)', 'shared_key' => '', 'auth_pass' => '(set)', 'proxy_passwd' => '(set)', 'description' => 'd') &&
    restapi_ovpn_secrets('server') === array('tls', 'shared_key') && restapi_ovpn_secrets('csc') === array(), 'OpenVPN keys and passwords read as "(set)"');
$post = restapi_ovpn_post('server', $sf, $osc, false, array(), $sf);
check_api(!isset($post['custom_options']) && !isset($post['crlref']) && $post['caref'] === 'ca1' && $post['tlsauth_enable'] === 'yes' && !isset($post['autotls_enable']) &&
    $post['authmode'] === array('Local Database') && $post['data_ciphers'] === array('AES-256-GCM', 'AES-256-CBC') && $post['create_gw'] === 'v4only' && $post['tls'] === $sp['tls'] &&
    !isset($post['disable']) && !isset($post['vpnid']) && $post['local_port'] === '51194' && $post['username_as_common_name'] === 'yes',
    'the server post: ticked boxes "yes", lists as multi-selects, no custom options without the advanced privilege, no CRL select when the page has none');
check_api(restapi_ovpn_post('server', $sf, $osc, false, array('custom_options' => 'x'), $sf)['custom_options'] === 'push "x"' &&
    restapi_ovpn_post('server', $sf, $osc, true, array(), $sf)['custom_options'] === 'push "x"' && !isset(restapi_ovpn_post('server', array('create_gw' => '') + $sf, $osc, true, array(), $sf)['create_gw']),
    'custom options are posted for users with the advanced privilege or when the body sets them (the save refuses the change)');
$tap = array('dev_mode' => 'tap', 'serverbridge_dhcp' => false, 'serverbridge_interface' => 'lan', 'serverbridge_dhcp_start' => '10.0.0.10', 'serverbridge_dhcp_end' => '10.0.0.20') + $sf;
$p1 = restapi_ovpn_post('server', $tap, $osc, true, array(), $tap);
$p2 = restapi_ovpn_post('server', array('serverbridge_dhcp' => true) + $tap, $osc, true, array(), $tap);
$p3 = restapi_ovpn_post('server', array('mode' => 'p2p_tls', 'serverbridge_dhcp' => true) + $tap, $osc, true, array(), $tap);
$p4 = restapi_ovpn_post('server', array('dev_mode' => 'tun') + $tap, $osc, true, array(), $tap);
check_api(!isset($p1['serverbridge_interface']) && !isset($p1['serverbridge_dhcp_start']) && $p2['serverbridge_interface'] === 'lan' && $p2['serverbridge_dhcp'] === 'yes' &&
    !isset($p3['serverbridge_dhcp']) && !isset($p3['serverbridge_interface']) && $p4['serverbridge_interface'] === 'lan',
    'tap bridge fields are posted only as far as the page enables them');

$occ = array('mode' => array('p2p_tls' => 1, 'p2p_shared_key' => 1), 'proxy_authtype' => array('none' => 1, 'basic' => 1, 'ntlm' => 1), 'certref' => array('' => 'None', 'c1' => 'C')) + $osc;
unset($occ['authmode']);
$cp = array('mode' => 'p2p_tls', 'protocol' => 'UDP4', 'interface' => 'wan', 'server_addr' => '192.0.2.50', 'server_port' => '51194', 'auth_user' => 'u', 'auth_pass' => 'secret',
    'proxy_passwd' => '', 'certref' => '', 'caref' => 'ca1', 'shared_key' => '', 'autokey_enable' => 'yes', 'tls' => '', 'autotls_enable' => 'yes', 'tlsauth_enable' => 'yes',
    'data_ciphers' => 'AES-256-GCM', 'data_ciphers_fallback' => 'AES-256-CBC', 'create_gw' => 'both', 'auth-retry-none' => 'yes', 'use_shaper' => '');
$cf = restapi_ovpn_client_fields($cp, $occ);
check_api($cf['auth_pass'] === 'secret' && $cf['certref'] === '' && $cf['autokey_enable'] === true && $cf['autotls_enable'] === true && $cf['auth-retry-none'] === true &&
    $cf['proxy_authtype'] === 'none' && $cf['tlsauth_keydir'] === 'default' && $cf['ping_action_seconds'] === '60' &&
    count(array_diff(array_keys($cf), array_keys(restapi_ovpn_client_types()))) === 0 && count(array_diff(array_keys(restapi_ovpn_client_types()), array_keys($cf))) === 0,
    'a client reads as its edit form (fields and types match)');
$cpost = restapi_ovpn_post('client', $cf, $occ, true, array(), $cf);
check_api($cpost['auth_pass'] === DMYPWD && $cpost['proxy_passwd'] === '' && $cpost['autotls_enable'] === 'yes' && $cpost['auth-retry-none'] === 'yes' && $cpost['certref'] === '',
    'a stored client password the body leaves out posts the page\'s placeholder (the save keeps it); none stored posts empty');
check_api(restapi_ovpn_post('client', array('auth_pass' => 'new') + $cf, $occ, true, array('auth_pass' => 'new'), $cf)['auth_pass'] === 'new' &&
    restapi_vpn_secret_body(array('auth_pass' => '(set)', 'tls' => 'x'), 'auth_pass') === array('tls' => 'x'), 'a password the body sets is posted; "(set)" keeps it');

$ocsc = array('server_list' => array(1 => 'OpenVPN Server 1: x', 3 => 'OpenVPN Server 3: y'), 'override_options' => array('default' => 1, 'push_reset' => 1, 'remove_specified' => 1),
    'remove_options' => array('remove_route' => 1, 'remove_iroute' => 1, 'remove_ping' => 1), 'ping_action' => array('default' => 1, 'ping_restart' => 1, 'ping_exit' => 1),
    'netbios_ntype' => array(0 => 'none', 1 => 'b'));
$xf = restapi_ovpn_csc_fields(array('common_name' => 'cn1', 'server_list' => array('3', '9'), 'remove_options' => array('remove_ping', 'remove_route'), 'override_options' => 'remove_specified',
    'block' => 'yes', 'custom_options' => 'c'), $ocsc);
check_api($xf['common_name'] === 'cn1' && $xf['server_list'] === array('3') && $xf['remove_options'] === array('remove_route', 'remove_ping') && $xf['override_options'] === 'remove_specified' &&
    $xf['ping_action'] === 'default' && $xf['block'] === true && $xf['disable'] === false && $xf['netbios_ntype'] === '0' &&
    count(array_diff(array_keys($xf), array_keys(restapi_ovpn_csc_types()))) === 0 && count(array_diff(array_keys(restapi_ovpn_csc_types()), array_keys($xf))) === 0,
    'an override reads as its edit form (reset options default to "keep", ping action to "don\'t override")');
$xp = restapi_ovpn_post('csc', $xf, $ocsc, false, array(), $xf);
check_api($xp['server_list'] === array('3') && $xp['block'] === 'yes' && !isset($xp['custom_options']) && $xp['override_options'] === 'remove_specified' && !isset($xp['disable']),
    'the override post (no custom options without the advanced privilege)');
check_api(restapi_ovpn_status_pick(array('common_name' => ' cn ', 'bytes_recv' => 5, 'x' => 'y'), array('common_name', 'bytes_recv', 'peer_id')) ===
    array('common_name' => 'cn', 'bytes_recv' => '5', 'peer_id' => ''), 'status entries carry only the listed fields');

$ovpn_inc = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/vpn_openvpn.inc");
$routes_ovpn = file_get_contents("{$root}/src/etc/inc/restapi/routes_openvpn.inc");
foreach (array('vpn_openvpn_server.php' => array('openvpn_user_can_edit_advanced("page-openvpn-server-advanced")', 'openvpn_instance_delete(\'server\', $id ?? null, $user_can_edit_advanced)',
    'openvpn_server_form($act, $this_server_config)', 'openvpn_server_save($pconfig, $id ?? null, $act, $user_can_edit_advanced)', "config_del_path(\"crl/{\$cid}\");"),
    'vpn_openvpn_client.php' => array('openvpn_user_can_edit_advanced("page-openvpn-client-advanced")', 'openvpn_instance_delete(\'client\', $id ?? null, $user_can_edit_advanced)',
    'openvpn_client_form($act, $this_client_config)', 'openvpn_client_save($pconfig, $id ?? null, $act, $user_can_edit_advanced)', 'openvpn_client_proxy_auth_types()', '$parentid = $id;'),
    'vpn_openvpn_csc.php' => array('openvpn_user_can_edit_advanced("page-openvpn-csc-advanced")', 'openvpn_csc_delete($id ?? null, $user_can_edit_advanced)', 'openvpn_csc_server_list()',
    'openvpn_csc_form($act, $this_csc_config)', 'openvpn_csc_save($pconfig, $id ?? null, $act, $user_can_edit_advanced)', 'openvpn_csc_override_options()', 'openvpn_csc_remove_options()')) as $page => $calls) {
	$src = file_get_contents("{$root}/src/usr/local/www/{$page}");
	foreach ($calls as $call) {
		check_api(strpos($src, $call) !== false, "{$page} uses {$call}");
	}
	check_api(strpos($src, 'write_config(') === false && strpos($src, 'config_set_path(') === false && strpos($src, 'openvpn_resync') === false &&
	    strpos($src, 'openvpn_delete') === false && strpos($src, 'services_unbound_configure(') === false && strpos($src, 'userHasPrivilege(') === false &&
	    !preg_match('/^function /m', $src) && strpos($src, "require_once(\"vpn_openvpn.inc\");") !== false,
	    "{$page} changes the configuration only through vpn_openvpn.inc and defines no functions");
}
check_api(substr_count(file_get_contents("{$root}/src/usr/local/www/vpn_openvpn_server.php"), "is_array(\$pconfig['") === 2 &&
    substr_count(file_get_contents("{$root}/src/usr/local/www/vpn_openvpn_client.php"), "is_array(\$pconfig['data_ciphers'])") === 1,
    'a refused form re-joins only lists (a crafted text list crashed the page)');
foreach (array('openvpn_server_save', 'openvpn_client_save', 'openvpn_csc_save') as $fn) {
	$body = $fn_body($ovpn_inc, $fn);
	check_api(strpos($body, '$_POST') === false && strpos($body, '$_SESSION') === false && strpos($body, 'do_input_validation($post,') !== false &&
	    strpos($body, "\$post['disable'] == \"yes\"") !== false, "{$fn}() reads the form passed in (not \$_POST) and validates it like the page");
}
$srv_save = $fn_body($ovpn_inc, 'openvpn_server_save');
check_api(strpos($srv_save, "!empty(\$pconfig['data_ciphers']) && is_array(\$pconfig['data_ciphers']) &&\n\t    (strlen(") !== false &&
    strpos($srv_save, 'The Backend for Authentication list is not valid.') !== false && strpos($srv_save, 'A valid Backend for Authentication must be selected.') !== false &&
    strpos($srv_save, "!empty(\$pconfig['authmode']) && is_array(\$pconfig['authmode']) && is_port(") !== false,
    'server save: a text cipher or backend list is refused (implode() crashed the page); backends must exist (any name was stored)');
check_api(strpos($fn_body($ovpn_inc, 'openvpn_client_save'), "!empty(\$pconfig['data_ciphers']) && is_array(\$pconfig['data_ciphers']) &&\n\t    (strlen(") !== false,
    'client save: a text cipher list is refused (implode() crashed the page)');
foreach (array('openvpn_server_save', 'openvpn_client_save') as $fn) {
	check_api(strpos($fn_body($ovpn_inc, $fn), 'The selected Gateway creation option is not valid.') !== false, "{$fn}(): the gateway creation option must be a choice (any value was stored)");
	check_api(strpos($fn_body($ovpn_inc, $fn), 'openvpn_resync(') !== false && strpos($fn_body($ovpn_inc, $fn), 'services_unbound_configure(false);') !== false,
	    "{$fn}() applies at once like the page");
}
check_api(strpos($fn_body($ovpn_inc, 'openvpn_server_save'), 'openvpn_resync_csc_all();') !== false, 'a saved server rewrites the override files like the page');
$cli_save = $fn_body($ovpn_inc, 'openvpn_client_save');
check_api(strpos($cli_save, 'update_if_changed($stat') === false && strpos($cli_save, "} elseif (\$post[\$stat] != null) {") !== false &&
    strpos($cli_save, "is_numeric(\$post['parentid'])") !== false, 'client passwords: the placeholder keeps the stored one (of the copied client for a copy); no guiconfig.inc helper');
$csc_save = $fn_body($ovpn_inc, 'openvpn_csc_save');
check_api(strpos($csc_save, 'The selected Reset Server Options value is not valid.') !== false && strpos($csc_save, 'The Remove Options list contains an invalid entry.') !== false &&
    strpos($csc_save, 'openvpn_resync_csc($csc);') !== false, 'override save: reset and remove options must be the page\'s choices (anything was stored); applied at once');
$adv = $fn_body($ovpn_inc, 'openvpn_user_can_edit_advanced');
check_api(strpos($adv, "isAdminUID(\$_SESSION['Username'])") !== false && strpos($adv, 'userHasPrivilege($user_entry, $privilege)') !== false &&
    strpos($adv, 'userHasPrivilege($user_entry, "page-all")') !== false, 'the advanced options privilege is checked for the signed-in user (the API key\'s user)');
foreach (array("openvpn_inuse(\$this_config['vpnid'], \$mode)", "!\$user_can_edit_advanced && !empty(\$this_config['custom_options'])") as $needle) {
	check_api(strpos($fn_body($ovpn_inc, 'openvpn_instance_delete'), $needle) !== false, "deleting an instance checks {$needle}");
}
$guiconfig_src = file_get_contents("{$root}/src/usr/local/www/guiconfig.inc");
foreach (array("'0' => \"none\"", "'1' => \"b-node\"", "'2' => \"p-node\"", "'4' => \"m-node\"", "'8' => \"h-node\"") as $nt) {
	check_api(strpos($guiconfig_src, $nt) !== false && strpos($fn_body($ovpn_inc, 'openvpn_netbios_nodetypes'), $nt) !== false, "the NetBIOS node types match guiconfig.inc: {$nt}");
}
check_api(substr_count($routes_ovpn, 'write_config(') === 0 && substr_count($routes_ovpn, 'config_set_path(') === 0 && substr_count($routes_ovpn, 'config_del_path(') === 0 &&
    substr_count($routes_ovpn, 'openvpn_create_key(') === 0, 'OpenVPN API writes only through the GUI functions and never creates keys itself');
check_api(strpos($fn_body($routes_ovpn, 'restapi_ovpn_instance_write'), 'openvpn_server_save($post, $pos, $act, $can_edit_advanced)') !== false &&
    strpos($fn_body($routes_ovpn, 'restapi_ovpn_instance_write'), 'openvpn_client_save($post, $pos, $act, $can_edit_advanced)') !== false &&
    strpos($fn_body($routes_ovpn, 'restapi_ovpn_csc_write'), 'openvpn_csc_save($post, $pos, $act, $can_edit_advanced)') !== false &&
    strpos($fn_body($routes_ovpn, 'restapi_ovpn_instance_delete'), 'openvpn_instance_delete($mode, $pos, $can_edit_advanced)') !== false &&
    strpos($fn_body($routes_ovpn, 'restapi_h_ovpn_csc_delete'), 'openvpn_csc_delete($pos,') !== false, 'OpenVPN writes and deletes go through the pages\' functions');
check_api(strpos($fn_body($routes_ovpn, 'restapi_ovpn_instance_out'), "restapi_ovpn_mask(\$entry, restapi_ovpn_secrets(\$mode))") !== false &&
    strpos($fn_body($routes_ovpn, 'restapi_ovpn_instance_out'), "restapi_ovpn_mask(\$fields, restapi_ovpn_secrets(\$mode))") !== false &&
    strpos($fn_body($routes_ovpn, 'restapi_ovpn_instance_write'), 'restapi_vpn_secret_body($body, $secret)') !== false,
    'stored entries and forms read with keys and passwords as "(set)"; sending "(set)" keeps them');
check_api(strpos($fn_body($routes_ovpn, 'restapi_ovpn_instance_write'), 'openvpn_user_can_edit_advanced("page-openvpn-{$mode}-advanced")') !== false &&
    strpos($fn_body($routes_ovpn, 'restapi_ovpn_csc_write'), "openvpn_user_can_edit_advanced('page-openvpn-csc-advanced')") !== false,
    'the API checks the advanced options privilege of the key\'s user like the pages');
check_api(strpos($fn_body($routes_ovpn, 'restapi_ovpn_instance_delete'), "new RestApiError(409, 'in_use'") !== false &&
    strpos($fn_body($routes_ovpn, 'restapi_ovpn_instance_delete'), "new RestApiError(403, 'forbidden'") !== false, 'a refused delete is 409 (assigned) or 403 (advanced options)');
$status_fn = $fn_body($routes_ovpn, 'restapi_h_ovpn_status');
check_api(strpos($status_fn, 'openvpn_get_active_servers()') !== false && strpos($status_fn, 'openvpn_get_active_clients()') !== false && strpos($status_fn, "'tls'") === false &&
    strpos($status_fn, 'shared_key') === false, 'the OpenVPN status reads the status helpers only (no configuration fields)');
check_api(strpos(file_get_contents("{$root}/src/usr/local/www/api/index.php"), "require_once('vpn_openvpn.inc');") !== false, 'the API front controller loads vpn_openvpn.inc');

/* Certificate manager: certificate authorities and revocation lists */
check_api(isset(restapi_areas()['pki']), 'the certificate manager permission area exists');
$pki_routes = array(
	'GET /v1/pki/cas' => array('restapi_h_pki_ca_list', 'system_camanager.php'),
	'GET /v1/pki/cas/{refid}' => array('restapi_h_pki_ca_get', 'system_camanager.php'),
	'GET /v1/pki/cas/{refid}/certificate' => array('restapi_h_pki_ca_pem', 'system_camanager.php'),
	'POST /v1/pki/cas' => array('restapi_h_pki_ca_create', 'system_camanager.php'),
	'PUT /v1/pki/cas/{refid}' => array('restapi_h_pki_ca_update', 'system_camanager.php'),
	'DELETE /v1/pki/cas/{refid}' => array('restapi_h_pki_ca_delete', 'system_camanager.php'),
	'GET /v1/pki/certificates' => array('restapi_h_pki_cert_list', 'system_certmanager.php'),
	'GET /v1/pki/certificates/{refid}' => array('restapi_h_pki_cert_get', 'system_certmanager.php'),
	'GET /v1/pki/certificates/{refid}/certificate' => array('restapi_h_pki_cert_pem', 'system_certmanager.php'),
	'GET /v1/pki/certificates/{refid}/csr' => array('restapi_h_pki_cert_csr', 'system_certmanager.php'),
	'POST /v1/pki/certificates' => array('restapi_h_pki_cert_create', 'system_certmanager.php'),
	'PUT /v1/pki/certificates/{refid}' => array('restapi_h_pki_cert_update', 'system_certmanager.php'),
	'POST /v1/pki/certificates/{refid}/complete' => array('restapi_h_pki_cert_complete', 'system_certmanager.php'),
	'DELETE /v1/pki/certificates/{refid}' => array('restapi_h_pki_cert_delete', 'system_certmanager.php'),
	'GET /v1/pki/crls' => array('restapi_h_pki_crl_list', 'system_crlmanager.php'),
	'GET /v1/pki/crls/{refid}' => array('restapi_h_pki_crl_get', 'system_crlmanager.php'),
	'GET /v1/pki/crls/{refid}/crl' => array('restapi_h_pki_crl_pem', 'system_crlmanager.php'),
	'POST /v1/pki/crls' => array('restapi_h_pki_crl_create', 'system_crlmanager.php'),
	'PUT /v1/pki/crls/{refid}' => array('restapi_h_pki_crl_update', 'system_crlmanager.php'),
	'DELETE /v1/pki/crls/{refid}' => array('restapi_h_pki_crl_delete', 'system_crlmanager.php'),
	'POST /v1/pki/crls/{refid}/revoke' => array('restapi_h_pki_crl_revoke', 'system_crlmanager.php'),
	'DELETE /v1/pki/crls/{refid}/revoked/{cert}' => array('restapi_h_pki_crl_unrevoke', 'system_crlmanager.php'),
);
foreach ($v1 as $r) {
	$key = "{$r['method']} {$r['path']}";
	if (strpos($r['path'], '/v1/pki/') === 0) {
		check_api(isset($pki_routes[$key]), "{$key} is a known certificate manager route");
		check_api($r['handler'] === $pki_routes[$key][0] && $r['page'] === $pki_routes[$key][1] && $r['area'] === 'pki',
		    "{$key} is handled by {$pki_routes[$key][0]}, guarded by {$pki_routes[$key][1]} in area pki");
		check_api(($r['method'] === 'GET') xor $r['write'], "{$key}: only GET is a read");
		check_api(!isset($r['query']['apply']), "{$key} has no ?apply (applied at once like the pages)");
		check_api(!preg_match('/key|p12|pkcs/i', $r['path'] . ' ' . $r['handler']), "{$key} exports no private key");
		unset($pki_routes[$key]);
	}
}
check_api(empty($pki_routes), 'every certificate manager route exists: ' . implode(', ', array_keys($pki_routes)));
foreach (array('GET /v1/pki/cas/6ac0b56a1c325/certificate' => array('restapi_h_pki_ca_pem', 'text/plain'),
    'GET /v1/pki/crls/6ac0b56a1c325/crl' => array('restapi_h_pki_crl_pem', 'text/plain'),
    'GET /v1/pki/cas/6ac0b56a1c325' => array('restapi_h_pki_ca_get', 'application/json'),
    'DELETE /v1/pki/crls/6ac0b56a1c325/revoked/6ac0b56aa7f4a' => array('restapi_h_pki_crl_unrevoke', 'application/json'),
    'GET /v1/pki/certificates/6ac0b56aa7f4a/certificate' => array('restapi_h_pki_cert_pem', 'text/plain'),
    'GET /v1/pki/certificates/6ac0b56aa7f4a/csr' => array('restapi_h_pki_cert_csr', 'text/plain'),
    'POST /v1/pki/certificates/6ac0b56aa7f4a/complete' => array('restapi_h_pki_cert_complete', 'application/json')) as $key => $want) {
	list($m, $p) = explode(' ', $key);
	$r = restapi_match($v1, $m, $p)[0];
	check_api($r['handler'] === $want[0] && $r['produces'] === $want[1], "{$key} reaches {$want[0]} ({$want[1]})");
}

$smoke_ca_pem = "-----BEGIN CERTIFICATE-----\nMIIBmzCCAUGgAwIBAgICEjQwCgYIKoZIzj0EAwIwLDEWMBQGA1UEAwwNU21va2Ug\nVGVzdCBDQTESMBAGA1UECgwJRnJlZVNlbnNlMB4XDTI2MTAwNjA0MjcyMVoXDTM2\n" .
    "MTAwMzA0MjcyMVowLDEWMBQGA1UEAwwNU21va2UgVGVzdCBDQTESMBAGA1UECgwJ\nRnJlZVNlbnNlMFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAESu825UEBzdJygwsC\n" .
    "v8yGr8Ncx+mRMgBiMHVC8RxiHuEfGGgQiVwQuWPxNW3KRGJ3kbVzV9BiI8sfCFIV\nYJqNJ6NTMFEwHQYDVR0OBBYEFG8WaLMBy9jEIqQudCQS/7zZ06m7MB8GA1UdIwQY\n" .
    "MBaAFG8WaLMBy9jEIqQudCQS/7zZ06m7MA8GA1UdEwEB/wQFMAMBAf8wCgYIKoZI\nzj0EAwIDSAAwRQIgW1MaTugqbpMAq9IDC7vQlBuyAOZwZwd1XuSVPTSlhvUCIQDr\n" .
    "HA3XZ8bGYJEliPAT6gw7MEbs+4Ton3bzlSQ3e+03oA==\n-----END CERTIFICATE-----\n";
$cd = restapi_pki_cert_details($smoke_ca_pem);
check_api($cd === array('subject' => 'CN=Smoke Test CA, O=FreeSense', 'issuer' => 'CN=Smoke Test CA, O=FreeSense', 'serial' => '4660',
    'valid_from' => '2026-10-06T04:27:21Z', 'valid_until' => '2036-10-03T04:27:21Z'), 'certificate details: subject, issuer, serial and validity (ISO 8601 UTC)');
check_api(restapi_pki_cert_details('not a certificate') === array('subject' => '', 'issuer' => '', 'serial' => '', 'valid_from' => '', 'valid_until' => '') &&
    restapi_pki_time(0) === '' && restapi_pki_time('1760000000') === '2025-10-09T08:53:20Z', 'unparsable certificates and unknown times read as empty');
$fake_prv = base64_encode("-----BEGIN PRIVATE KEY-----\nSMOKEPRIVATEKEYDATA\n-----END PRIVATE KEY-----\n");
check_api(restapi_pki_has_key(array('prv' => $fake_prv)) === true && restapi_pki_has_key(array('prv' => '')) === false && restapi_pki_has_key(array()) === false,
    'has_private_key says whether a key is stored');
$caform = array('method' => 'existing', 'descr' => 'CA "1"', 'refid' => 'r1', 'cert' => $smoke_ca_pem, 'serial' => '7', 'trust' => true, 'randomserial' => false,
    'key' => base64_decode($fake_prv));
$caf = restapi_pki_form_fields($caform, restapi_pki_ca_edit_types(), array());
check_api($caf['key'] === base64_decode($fake_prv) && restapi_pki_mask_key($caf)['key'] === '(set)' && restapi_pki_mask_key(array('key' => ''))['key'] === '' &&
    $caf['trust'] === true && $caf['randomserial'] === false && $caf['serial'] === '7' && $caf['refid'] === 'r1' && $caf['cert'] === $smoke_ca_pem &&
    array_keys(restapi_pki_ca_edit_types()) === array_keys($caf), 'the CA edit form: flags as booleans, the private key read as "(set)"');
check_api(restapi_pki_ca_edit_types()['refid'] === 'ro' && restapi_pki_ca_edit_types()['method'] === 'ro' && restapi_pki_ca_edit_types()['key'] === 'string' &&
    !isset(restapi_pki_ca_edit_types()['keytype']), 'a CA PUT changes the edit form\'s fields only (refid and method read-only)');
$cac = array('method' => array('internal' => 'Create', 'existing' => 'Import', 'intermediate' => 'Intermediate'), 'caref' => array('r1' => 'One', 'r2' => 'Two'),
    'keytype' => array_combine(array('RSA', 'ECDSA'), array('RSA', 'ECDSA')), 'keylen' => array_combine(array('1024', '2048', '4096'), array('1024', '2048', '4096')),
    'ecname' => array('secp384r1' => 'secp384r1', 'prime256v1' => 'prime256v1 [HTTPS]'), 'digest_alg' => array('sha1' => 'sha1', 'sha256' => 'sha256'),
    'dn_country' => array('' => 'None', 'US' => 'US'));
$cnew = restapi_pki_form_fields(array('method' => null, 'keytype' => 'RSA', 'keylen' => '2048', 'ecname' => 'prime256v1', 'digest_alg' => 'sha256', 'lifetime' => 3650,
    'dn_commonname' => 'internal-ca'), restapi_pki_ca_create_types(), $cac);
check_api($cnew['method'] === 'internal' && $cnew['caref'] === 'r1' && $cnew['keylen'] === '2048' && $cnew['ecname'] === 'prime256v1' && $cnew['digest_alg'] === 'sha256' &&
    $cnew['dn_country'] === '' && $cnew['lifetime'] === '3650' && $cnew['trust'] === false && $cnew['key'] === '' && $cnew['dn_commonname'] === 'internal-ca',
    'a new CA starts as the page\'s new form (internal, first signing CA, RSA 2048, sha256)');
$cpost = restapi_svc_post(array('trust' => true) + $cnew, restapi_pki_ca_create_types());
check_api($cpost['trust'] === 'yes' && !isset($cpost['randomserial']) && $cpost['keylen'] === '2048', 'a CA post: ticked boxes "yes", unticked left out');

$rev = restapi_pki_revoked_out(array('refid' => 'c9', 'descr' => 'old cert', 'crt' => base64_encode($smoke_ca_pem), 'prv' => $fake_prv, 'caref' => 'r1',
    'reason' => '1', 'revoke_time' => '1760000000', 'serial' => '4660'), '4660', array(-1 => 'No Status (default)', 1 => 'Key Compromise'));
check_api($rev === array('refid' => 'c9', 'descr' => 'old cert', 'serial' => '4660', 'reason' => 1, 'reason_text' => 'Key Compromise', 'revoke_time' => 1760000000,
    'revoked_at' => '2025-10-09T08:53:20Z'), 'a revoked certificate reads without its stored certificate copy (no key)');
check_api(strpos(json_encode($rev), 'SMOKEPRIVATEKEY') === false && strpos(json_encode($rev), $fake_prv) === false &&
    strpos(json_encode(restapi_pki_mask_key($caf)), 'PRIVATE KEY') === false, 'no private key in the CA form or revoked entry output');
check_api(restapi_pki_revoked_out(array(), null, array())['serial'] === '' && restapi_pki_revoked_out(array(), null, array(-1 => 'x'))['reason_text'] === 'x',
    'an entry without serial or reason reads as empty / no status');
check_api(array_keys(restapi_pki_crl_edit_types(true)) === array('refid', 'descr', 'lifetime', 'serial') &&
    array_keys(restapi_pki_crl_edit_types(false)) === array('refid', 'descr', 'crltext') &&
    array_keys(restapi_pki_crl_create_types()) === array('caref', 'method', 'descr', 'crltext', 'lifetime', 'serial'),
    'CRL forms: internal (name, lifetime, serial), imported (name, data), new');
$rp = restapi_pki_revoke_post(array('certref' => 'c1', 'serials' => ' 12  0x1F ', 'reason' => 4), 'crl1', array('descr' => 'L', 'lifetime' => '730', 'serial' => '3'));
check_api($rp === array('descr' => 'L', 'lifetime' => '730', 'serial' => '3', 'crlreason' => '4', 'revokeserial' => '12 0x1F', 'id' => 'crl1', 'act' => 'addcert',
    'crlref' => 'crl1', 'certref' => array('c1')), 'a revocation posts the CRL edit form\'s Add (no Save): certificates, serials, reason, the CRL\'s current fields');
$rp2 = restapi_pki_revoke_post(array('serials' => array('5', 6)), 'crl1', array('descr' => 'L'));
check_api($rp2['revokeserial'] === '5 6' && $rp2['crlreason'] === '-1' && !isset($rp2['certref']) && !isset($rp2['save']), 'serial lists; the reason defaults to no status');
check_api(api_error_status(function () { restapi_pki_revoke_post(array('bogus' => 1), 'c', array()); }) === 400 &&
    api_error_status(function () { restapi_pki_revoke_post(array('certref' => array('k' => 'v')), 'c', array()); }) === 400 &&
    api_error_status(function () { restapi_pki_revoke_post(array('reason' => 'x'), 'c', array()); }) === 400 &&
    api_error_status(function () { restapi_pki_revoke_post(array('serials' => array(array())), 'c', array()); }) === 400, 'malformed revocations are 400');

/* No API route returns or exports a private key. */
foreach (array_merge(glob("{$root}/src/etc/inc/restapi/*.inc"), array("{$root}/src/etc/inc/restapi.inc", "{$root}/src/usr/local/www/api/index.php")) as $f) {
	$src = file_get_contents($f);
	$expected = (basename($f) === 'routes_pki.inc') ? 1 : 0;
	check_api(substr_count($src, "'prv'") === $expected && substr_count($src, '"prv"') === 0, basename($f) . ' reads no stored private key' .
	    ($expected ? ' except to say whether there is one' : ''));
	check_api(stripos($src, 'cert_pkcs12_export') === false && stripos($src, 'expkey') === false, basename($f) . ' has no private key export');
}
$routes_pki = file_get_contents("{$root}/src/etc/inc/restapi/routes_pki.inc");
check_api(strpos($fn_body($routes_pki, 'restapi_pki_has_key'), "return !empty(\$entry['prv']);") !== false, 'the stored key is only tested for presence');
foreach (array('restapi_pki_ca_out', 'restapi_pki_crl_out', 'restapi_pki_crl_fields', 'restapi_pki_cert_out') as $fn) {
	$body = $fn_body($routes_pki, $fn);
	check_api(strpos($routes_pki, "function {$fn}(") !== false && !preg_match('/return \$(ca|crl|cert)\b|\$out = \$(ca|crl|cert);|\+ \$(ca|crl|cert)\b|array_merge\(\$(ca|crl|cert)\b/', $body),
	    "{$fn}() copies fields one by one (never the stored entry)");
}
check_api(strpos($fn_body($routes_pki, 'restapi_pki_ca_out'), "restapi_pki_mask_key(\$fields)") !== false, 'the CA edit form is returned with the key masked');
check_api(strpos($fn_body($routes_pki, 'restapi_h_pki_ca_update'), "restapi_vpn_secret_body(\$req['body'], 'key')") !== false, 'a CA PUT keeps the key for "(set)"');
check_api(strpos($fn_body($routes_pki, 'restapi_h_pki_ca_delete'), "new RestApiError(409, 'in_use'") !== false &&
    strpos($fn_body($routes_pki, 'restapi_h_pki_crl_delete'), "new RestApiError(409, 'in_use'") !== false, 'deleting a CA or CRL in use is 409');

$pki_inc = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/system_certificates.inc");
foreach (array('system_camanager.php' => array('pki_ca_methods()', 'pki_ca_delete($id)', "pki_ca_form('edit', \$thisca)", "pki_ca_form('new', null, \$_POST['method'])",
    'pki_ca_save($pconfig, $id ?? null, $act, $savemsg)', 'pki_ca_signing_list()', 'pki_key_lengths()', 'pki_key_types()'),
    'system_crlmanager.php' => array('pki_crl_methods()', 'pki_crl_cleanup()', 'pki_crl_delete($id)', "pki_crl_new_form(\$_REQUEST['method'], \$_REQUEST['caref'])",
    'pki_crl_revoke($pconfig, $_POST)', "pki_crl_unrevoke(\$crl_item_config, \$_REQUEST['certref'])", 'pki_crl_export($crl_item_config)',
    'pki_crl_save($pconfig, $crl_item_config, $act)', 'pki_crl_method_list(', 'pki_crl_cert_list($crl, $id)', 'pki_crl_ca_list()')) as $page => $calls) {
	$src = file_get_contents("{$root}/src/usr/local/www/{$page}");
	foreach ($calls as $call) {
		check_api(strpos($src, $call) !== false, "{$page} uses {$call}");
	}
	check_api(strpos($src, 'write_config(') === false && strpos($src, 'config_set_path(') === false && strpos($src, 'config_del_path(') === false &&
	    strpos($src, 'openvpn_refresh_crls') === false && strpos($src, 'ipsec_configure') === false && strpos($src, 'ca_setup_trust_store') === false &&
	    strpos($src, 'cert_revoke') === false && strpos($src, 'require_once("system_certificates.inc");') !== false,
	    "{$page} changes the configuration only through system_certificates.inc");
	preg_match_all('/^function (\w+)\(/m', $src, $m);
	check_api(array_diff($m[1], array('method_change')) === array(), "{$page} defines no PHP functions (only its JavaScript method_change())");
}
foreach (array('pki_ca_save', 'pki_ca_delete', 'pki_crl_revoke', 'pki_crl_save', 'pki_crl_unrevoke', 'pki_crl_delete', 'pki_crl_new_form') as $fn) {
	$body = $fn_body($pki_inc, $fn);
	check_api(strpos($pki_inc, "function {$fn}(") !== false && strpos($body, '$_POST') === false && strpos($body, '$_REQUEST') === false &&
	    strpos($body, '$_SESSION') === false, "{$fn}() reads the form passed in");
}
check_api(strpos($fn_body($pki_inc, 'pki_ca_delete'), 'if (ca_in_use($id)) {') !== false && strpos($fn_body($pki_inc, 'pki_ca_delete'), 'cert_in_use(') === false,
    'deleting a CA checks ca_in_use() (the page checked cert_in_use() with a CA refid, so a CA in use was deleted)');
check_api(strpos($fn_body($pki_inc, 'pki_ca_delete'), 'ca_setup_trust_store();') !== false && strpos($fn_body($pki_inc, 'pki_ca_save'), 'ca_setup_trust_store();') !== false,
    'saving or deleting a CA rebuilds the trust store like the page');
$ca_save = $fn_body($pki_inc, 'pki_ca_save');
check_api(strpos($ca_save, 'Please select a valid Method.') !== false && strpos($ca_save, 'Please select a valid Signing Certificate Authority.') !== false &&
    strpos($ca_save, "do_input_validation(\$pconfig, \$reqdfields, \$reqdfieldsn, \$input_errors);") !== false,
    'CA save: the method and signing CA must be the page\'s choices (an empty or certificate-less CA was stored)');
$crl_revoke = $fn_body($pki_inc, 'pki_crl_revoke');
check_api(strpos($crl_revoke, "config_set_path(\"crl/{\$crl_item_config['idx']}\", \$crl);") !== false && strpos($crl_revoke, 'Please select a valid revocation reason.') !== false &&
    strpos($crl_revoke, 'openvpn_refresh_crls();') !== false && strpos($crl_revoke, 'ipsec_configure();') !== false,
    'CRL edit: Save stores the name, lifetime and serial (they were lost without a revocation); the reason must be a choice; revocations refresh OpenVPN and IPsec');
check_api(strpos($fn_body($pki_inc, 'pki_crl_save'), 'Please select a valid Method.') !== false && strpos($fn_body($pki_inc, 'pki_crl_save'), 'openvpn_refresh_crls();') !== false,
    'new CRL: the method must be the page\'s choice (an internal CRL for a CA without a key was stored)');
$certs_inc = file_get_contents("{$root}/src/etc/inc/certs.inc");
check_api(strpos($fn_body($certs_inc, 'cert_unrevoke'), "(!empty(\$cert['refid']) && (\$rcert['refid'] == \$cert['refid'])) ||") !== false &&
    strpos($fn_body($certs_inc, 'cert_unrevoke'), "(!empty(\$cert['descr']) && (\$rcert['descr'] == \$cert['descr'])) ||") !== false,
    'removing an entry revoked by serial matches its serial (it matched the first entry without a refid)');
check_api(strpos(file_get_contents("{$root}/src/usr/local/www/api/index.php"), "require_once('system_certificates.inc');") !== false,
    'the API front controller loads system_certificates.inc');
foreach (array('pki_key_lengths', 'pki_key_types', 'pki_descr_is_invalid', 'pki_dn_validate', 'pki_dn_from_form', 'pki_openssl_errors', 'pki_package_usage') as $fn) {
	check_api(strpos($pki_inc, "function {$fn}(") !== false, "the shared certificate manager helper {$fn}() exists");
}

/* Certificate manager: certificates */
$smoke_cert_pem = "-----BEGIN CERTIFICATE-----\nMIIB+TCCAZ+gAwIBAgICWhcwCgYIKoZIzj0EAwIwLDEWMBQGA1UEAwwNc21va2Uu\nZXhhbXBsZTESMBAGA1UECgwJRnJlZVNlbnNlMB4XDTI2MTAwNjA1MDQzOFoXDTM2\n" .
    "MTAwMzA1MDQzOFowLDEWMBQGA1UEAwwNc21va2UuZXhhbXBsZTESMBAGA1UECgwJ\nRnJlZVNlbnNlMFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE32D5ANNjYI1nIuww\n" .
    "yirxCfSKGehbse6V+ghZJpYLnooiAXXV69GG8e1sxtxxtz2KLnClfvSBEMLQQ3jq\nl3eQtaOBsDCBrTAdBgNVHQ4EFgQUsCIH5FWff+AtundzDf3YO8fRdjUwHwYDVR0j\n" .
    "BBgwFoAUsCIH5FWff+AtundzDf3YO8fRdjUwDwYDVR0TAQH/BAUwAwEB/zBaBgNV\nHREEUzBRgg1zbW9rZS5leGFtcGxlhwTAAAIHhxAgAQ24AAAAAAAAAAAAAAAHgQ9v\n" .
    "cHNAZXhhbXBsZS5vcmeGF2h0dHBzOi8vc21va2UuZXhhbXBsZS94MAoGCCqGSM49\nBAMCA0gAMEUCIFZvcc7w9Dhed06c4YJ02ZCso4AbFCRYKTwOEL1Hjr+kAiEAwsQZ\n" .
    "H35T5T/mWzRpQ3LjVhs4B5tf4foqWUk4JYMw9w4=\n-----END CERTIFICATE-----\n";
$smoke_csr_pem = "-----BEGIN CERTIFICATE REQUEST-----\nMIHkMIGMAgEAMCoxFDASBgNVBAMMC2Nzci5leGFtcGxlMRIwEAYDVQQKDAlGcmVl\n" .
    "U2Vuc2UwWTATBgcqhkjOPQIBBggqhkjOPQMBBwNCAATfYPkA02NgjWci7DDKKvEJ\n9IoZ6Fux7pX6CFkmlgueiiIBddXr0Ybx7WzG3HG3PYoucKV+9IEQwtBDeOqXd5C1\n" .
    "oAAwCgYIKoZIzj0EAwIDRwAwRAIgEOPSB4Oaq5dVyvt+6YPN7QE2iYJKhrfb3cdx\n5u+kn00CIDOkn02LwVdS5pvYVt5kNaLVkHq4UEFsVLOfu6UH5zz7\n-----END CERTIFICATE REQUEST-----\n";
$smoke_key_pem = base64_decode($fake_prv);

/* Returned certificates and requests hold only their PEM blocks (never a key pasted with them). */
$bundle = "junk before\n{$smoke_cert_pem}{$smoke_key_pem}-----BEGIN EC PRIVATE KEY-----\nAAAA\n-----END EC PRIVATE KEY-----\n{$smoke_ca_pem}";
$only = restapi_pki_pem_only($bundle, restapi_pki_cert_labels());
check_api($only === rtrim($smoke_cert_pem, "\n") . "\n" . $smoke_ca_pem && strpos($only, 'PRIVATE') === false,
    'a stored certificate text is returned as its certificate blocks only (a pasted private key is left out)');
check_api(restapi_pki_pem_only($smoke_cert_pem . $smoke_csr_pem, restapi_pki_csr_labels()) === $smoke_csr_pem &&
    restapi_pki_pem_only(str_replace('CERTIFICATE REQUEST', 'NEW CERTIFICATE REQUEST', $smoke_csr_pem), restapi_pki_csr_labels()) ===
    str_replace('CERTIFICATE REQUEST', 'NEW CERTIFICATE REQUEST', $smoke_csr_pem), 'signing requests (also "NEW CERTIFICATE REQUEST") are returned as requests only');
check_api(restapi_pki_pem_only('', restapi_pki_cert_labels()) === '' && restapi_pki_pem_only(null, restapi_pki_cert_labels()) === '' &&
    restapi_pki_pem_only($smoke_key_pem, restapi_pki_cert_labels()) === '' &&
    restapi_pki_pem_only("-----BEGIN CERTIFICATE-----\nAA\n-----BEGIN PRIVATE KEY-----\nBB\n-----END CERTIFICATE-----\n", restapi_pki_cert_labels()) === '',
    'a key alone, or a key inside a certificate block, is never returned');

$sd = openssl_x509_parse($smoke_cert_pem);
$sans = restapi_pki_san_list(explode(',', $sd['extensions']['subjectAltName']));
check_api($sans === array(array('type' => 'DNS', 'value' => 'smoke.example'), array('type' => 'IP', 'value' => '192.0.2.7'),
    array('type' => 'IP', 'value' => '2001:DB8:0:0:0:0:0:7'), array('type' => 'email', 'value' => 'ops@example.org'),
    array('type' => 'URI', 'value' => 'https://smoke.example/x')), 'SANs as {type, value} with the page\'s types (DNS, IP, email, URI)');
check_api(restapi_pki_san_list(array('', 'nocolon', ' othername:x')) === array(array('type' => 'othername', 'value' => 'x')), 'unknown SAN types are kept, junk skipped');
check_api(restapi_pki_cert_details($smoke_cert_pem)['serial'] === '23063' && restapi_pki_cert_details($smoke_cert_pem)['subject'] === 'CN=smoke.example, O=FreeSense',
    'certificate details of a certificate');
check_api(restapi_pki_csr_subject($smoke_csr_pem) === 'CN=csr.example, O=FreeSense' && restapi_pki_csr_subject('junk') === '' && restapi_pki_csr_subject(null) === '',
    'the subject of a signing request');

check_api(restapi_pki_altname_post(array(array('type' => 'DNS', 'value' => 'a.example'), array('type' => 'IP', 'value' => '192.0.2.1'))) ===
    array('altname_type0' => 'DNS', 'altname_value0' => 'a.example', 'altname_type1' => 'IP', 'altname_value1' => '192.0.2.1') &&
    restapi_pki_altname_post(array()) === array(), 'altnames become the page\'s rows (altname_type0/altname_value0, ...)');
foreach (array('x', array('a' => array('type' => 'DNS')), array('DNS:a'), array(array('type' => 'DNS', 'value' => 1)), array(array('type' => 'DNS', 'host' => 'a')),
    array(array('DNS', 'a'))) as $badsan) {
	check_api(api_error_status(function () use ($badsan) { restapi_pki_altname_post($badsan); }) === 400, 'malformed altnames are 400: ' . json_encode($badsan));
}

$cc = array('method' => array('internal' => 'i', 'import' => 'm', 'external' => 'e', 'sign' => 's'), 'type' => array('server' => 'S', 'user' => 'U'),
    'caref' => array('ca1' => 'One'), 'catosignwith' => array('ca1' => 'One'), 'csrtosign' => array('new' => 'New', 'csr1' => 'Pending'),
    'keytype' => array('RSA' => 'RSA', 'ECDSA' => 'ECDSA'), 'csr_keytype' => array('RSA' => 'RSA', 'ECDSA' => 'ECDSA'),
    'keylen' => array('1024' => '1024', '2048' => '2048'), 'csr_keylen' => array('1024' => '1024', '2048' => '2048'),
    'ecname' => array('secp384r1' => 'x', 'prime256v1' => 'y'), 'csr_ecname' => array('secp384r1' => 'x', 'prime256v1' => 'y'),
    'digest_alg' => array('sha1' => 'sha1', 'sha256' => 'sha256'), 'csr_digest_alg' => array('sha1' => 'sha1', 'sha256' => 'sha256'),
    'csrsign_digest_alg' => array('sha1' => 'sha1', 'sha256' => 'sha256'), 'dn_country' => array('' => 'None', 'US' => 'US'),
    'csr_dn_country' => array('' => 'None', 'US' => 'US'), 'import_type' => array('x509' => 'X', 'pkcs12' => 'P'));
$newdefaults = array('method' => null, 'keytype' => 'RSA', 'keylen' => '2048', 'ecname' => 'prime256v1', 'digest_alg' => 'sha256', 'csr_keytype' => 'RSA',
    'csr_keylen' => '2048', 'csr_ecname' => 'prime256v1', 'csr_digest_alg' => 'sha256', 'csrsign_digest_alg' => 'sha256', 'type' => 'user', 'lifetime' => 3650);
$certcur = restapi_pki_form_fields(restapi_pki_cert_new_form($newdefaults, 3650), restapi_pki_cert_create_types(), $cc);
check_api($certcur['method'] === 'internal' && $certcur['type'] === 'user' && $certcur['caref'] === 'ca1' && $certcur['catosignwith'] === 'ca1' &&
    $certcur['csrtosign'] === 'new' && $certcur['import_type'] === 'x509' && $certcur['keylen'] === '2048' && $certcur['ecname'] === 'prime256v1' &&
    $certcur['csrsign_digest_alg'] === 'sha256' && $certcur['lifetime'] === '3650' && $certcur['csrsign_lifetime'] === '3650' && $certcur['dn_country'] === '' &&
    $certcur['autorenew'] === false && $certcur['key'] === '' && $certcur['descr'] === '',
    'a new certificate starts as the page\'s new form (internal, user, first CA, a new pasted request to sign, X.509 import)');
list($cp, $cp12) = restapi_pki_cert_create_post(array('descr' => 'API cert', 'dn_commonname' => 'www.example', 'type' => 'server', 'autorenew' => true,
    'altnames' => array(array('type' => 'DNS', 'value' => 'www.example'), array('type' => 'IP', 'value' => '192.0.2.10'))), $certcur);
check_api($cp['descr'] === 'API cert' && $cp['type'] === 'server' && $cp['autorenew'] === 'yes' && !isset($cp['pkcs12_intermediate']) &&
    $cp['altname_value1'] === '192.0.2.10' && $cp['altname_type0'] === 'DNS' && $cp['act'] === 'new' && $cp['save'] === 'Save' && $cp['method'] === 'internal' &&
    $cp['keylen'] === '2048' && $cp12 === null && !isset($cp['altnames']) && !isset($cp['pkcs12']), 'a certificate post: fields over the new form, SAN rows, ticked boxes "yes"');
list($ip, $ip12) = restapi_pki_cert_create_post(array('method' => 'import', 'import_type' => 'pkcs12', 'pkcs12' => base64_encode("\x30\x82binary"),
    'pkcs12_pass' => 'pw', 'pkcs12_intermediate' => true), $certcur);
check_api($ip12 === "\x30\x82binary" && $ip['pkcs12_intermediate'] === 'yes' && $ip['pkcs12_pass'] === 'pw' && !isset($ip['pkcs12']),
    'a PKCS #12 import: the file is decoded and passed apart from the post');
list(, $none12) = restapi_pki_cert_create_post(array('pkcs12' => ''), $certcur);
check_api($none12 === null, 'an empty PKCS #12 field is no file');
check_api(api_error_status(function () use ($certcur) { restapi_pki_cert_create_post(array('pkcs12' => '%%%not base64'), $certcur); }) === 400 &&
    api_error_status(function () use ($certcur) { restapi_pki_cert_create_post(array('pkcs12' => array()), $certcur); }) === 400 &&
    api_error_status(function () use ($certcur) { restapi_pki_cert_create_post(array('bogus' => 'x'), $certcur); }) === 400 &&
    api_error_status(function () use ($certcur) { restapi_pki_cert_create_post(array('autorenew' => 'yes'), $certcur); }) === 400 &&
    api_error_status(function () use ($certcur) { restapi_pki_cert_create_post(array('prv' => 'x'), $certcur); }) === 400 &&
    api_error_status(function () use ($certcur) { restapi_pki_cert_create_post(array('altnames' => 'DNS:a'), $certcur); }) === 400,
    'malformed certificate posts are 400 (bad PKCS #12 data, unknown fields, non-boolean checkboxes, altnames not a list)');
check_api(!isset(restapi_pki_cert_create_types()['pkcs12']) && !isset(restapi_pki_cert_create_types()['altnames']) &&
    array_keys(restapi_pki_cert_edit_types()) === array('autorenew', 'descr', 'cert', 'key'),
    'certificate forms: new (PKCS #12 and SANs converted apart), edit (name, auto renewal, certificate, key)');
$certedit = restapi_pki_form_fields(array('descr' => 'C', 'autorenew' => true, 'cert' => $smoke_cert_pem, 'key' => $smoke_key_pem), restapi_pki_cert_edit_types(), array());
check_api(restapi_pki_mask_key($certedit)['key'] === '(set)' && $certedit['autorenew'] === true &&
    strpos(json_encode(restapi_pki_mask_key($certedit)), 'PRIVATE KEY') === false, 'the certificate edit form reads its key as "(set)"');

$routes_pki = file_get_contents("{$root}/src/etc/inc/restapi/routes_pki.inc");
$cert_out = $fn_body($routes_pki, 'restapi_pki_cert_out');
check_api(strpos($cert_out, 'restapi_pki_mask_key($fields)') !== false && strpos($cert_out, "restapi_pki_pem_only(\$crt, restapi_pki_cert_labels())") !== false &&
    strpos($cert_out, "restapi_pki_pem_only(\$csr, restapi_pki_csr_labels())") !== false &&
    strpos($cert_out, "\$fields['cert'] = restapi_pki_pem_only(\$fields['cert'], restapi_pki_cert_labels());") !== false,
    'a certificate is returned with its key masked and its certificate and request fields as PEM blocks only');
check_api(strpos($fn_body($routes_pki, 'restapi_h_pki_cert_pem'), 'restapi_pki_pem_only(') !== false && strpos($fn_body($routes_pki, 'restapi_h_pki_cert_csr'), 'restapi_pki_pem_only(') !== false &&
    strpos($fn_body($routes_pki, 'restapi_h_pki_ca_pem'), 'restapi_pki_pem_only(') !== false && strpos($fn_body($routes_pki, 'restapi_pki_ca_out'), "restapi_pki_pem_only(\$pem") !== false,
    'certificate, request and CA downloads hold PEM blocks only');
check_api(strpos($fn_body($routes_pki, 'restapi_h_pki_cert_update'), "restapi_vpn_secret_body(\$req['body'], 'key')") !== false &&
    strpos($fn_body($routes_pki, 'restapi_h_pki_cert_update'), "new RestApiError(409, 'csr_pending'") !== false &&
    strpos($fn_body($routes_pki, 'restapi_h_pki_cert_update'), "pki_cert_save(\$post, \$refid, 'edit')") !== false,
    'a certificate PUT is the edit form (key kept for "(set)"); a pending request is 409');
check_api(strpos($fn_body($routes_pki, 'restapi_h_pki_cert_complete'), 'pki_cert_csr_complete($post, $item)') !== false &&
    strpos($fn_body($routes_pki, 'restapi_h_pki_cert_complete'), "new RestApiError(409, 'not_pending'") !== false &&
    strpos($fn_body($routes_pki, 'restapi_h_pki_cert_delete'), "new RestApiError(409, 'in_use'") !== false &&
    strpos($fn_body($routes_pki, 'restapi_h_pki_cert_create'), "pki_cert_save(\$post, null, 'new', null, \$pkcs12)") !== false,
    'certificate writes go through the page\'s functions; deleting one in use is 409');
check_api(strpos($fn_body($routes_pki, 'restapi_h_pki_cert_create'), 'userid') === false && !isset(restapi_pki_cert_create_types()['userid']) &&
    !isset(restapi_pki_cert_create_types()['certref']), 'certificate routes do not change users (user certificates belong to the user routes)');

$pki_inc = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/system_certificates.inc");
$certpage = file_get_contents("{$root}/src/usr/local/www/system_certmanager.php");
foreach (array('pki_cert_methods($userid ?? null, $act)', 'pki_cert_types()', 'pki_cert_default_lifetime()', 'pki_cert_delete($id)',
    "pki_cert_new_form(\$_POST['method'])", "pki_cert_form('edit', \$thiscert)", "pki_cert_form('csr', \$thiscert)",
    'pki_cert_save($pconfig, $id ?? null, $act, $userid ?? null, $pkcs12_file, $savemsg, $unset_act)',
    'pki_cert_csr_complete($pconfig, $cert_item_config, $savemsg)', 'pki_cert_csr_list()', 'pki_cert_existing_list($userid ?? null)',
    'pki_ca_signing_list()', 'pki_key_lengths()', 'pki_key_types()', 'require_once("system_certificates.inc");') as $call) {
	check_api(strpos($certpage, $call) !== false, "system_certmanager.php uses {$call}");
}
check_api(strpos($certpage, 'write_config(') === false && strpos($certpage, 'config_set_path(') === false && strpos($certpage, 'config_del_path(') === false &&
    !preg_match('/(?<![a-z_])(cert_create|csr_generate|csr_sign|cert_import|ca_import|csr_complete)\(/', $certpage),
    'system_certmanager.php changes the configuration only through system_certificates.inc');
preg_match_all('/^function (\w+)\(/m', $certpage, $m);
check_api($m[1] === array() && strpos($certpage, 'function list_cas(') === false && strpos($certpage, 'function list_csrs(') === false,
    'system_certmanager.php defines no PHP functions (list_cas() and list_csrs() are pki_ca_signing_list() and pki_cert_csr_list())');
check_api(strpos($certpage, '$internal_ca_count = count(pki_ca_signing_list());') !== false && strpos($certpage, '<?php if ($internal_ca_count): ?>') === false,
    'the "no internal CA" note counts CAs with a key (it counted certificates), and the form script is always loaded');
check_api(strpos($certpage, "case 'key':") !== false && strpos($certpage, 'cert_pkcs12_export(') !== false,
    'the page keeps its own private key and PKCS #12 exports');
foreach (array('pki_cert_save', 'pki_cert_delete', 'pki_cert_csr_complete', 'pki_cert_new_form', 'pki_cert_form', 'pki_cert_existing_list', 'pki_cert_used_by') as $fn) {
	$body = $fn_body($pki_inc, $fn);
	check_api(strpos($pki_inc, "function {$fn}(") !== false && strpos($body, '$_POST') === false && strpos($body, '$_REQUEST') === false &&
	    strpos($body, '$_FILES') === false && strpos($body, '$_SESSION') === false, "{$fn}() reads the form passed in");
}
$cert_save = $fn_body($pki_inc, 'pki_cert_save');
check_api(strpos($cert_save, 'pki_cert_methods($userid, $act)') !== false && strpos($cert_save, 'Please select a valid Method.') !== false,
    'certificate save: the method must be one of the page\'s choices (an unknown one stored an empty certificate)');
check_api(strpos($cert_save, "array_key_exists((string)\$post['type'], pki_cert_types())") !== false && strpos($cert_save, 'Please select a valid Certificate Type.') !== false,
    'certificate save: the type must be server or user (any other value created a CA or a self-signed certificate)');
check_api(strpos($cert_save, "array_key_exists((string)\$post['caref'], pki_ca_signing_list())") !== false &&
    strpos($cert_save, "array_key_exists((string)\$post['catosignwith'], pki_ca_signing_list())") !== false &&
    strpos($cert_save, "array_key_exists((string)\$post['csrtosign'], pki_cert_csr_list())") !== false,
    'certificate save: the CA and the request to sign must be the page\'s choices (an unknown CA appended an empty CA)');
check_api(strpos($cert_save, "if (\$post['csrsign_lifetime'] > \$max_lifetime) {") !== false && strpos($cert_save, "\$_POST['lifetime']") === false,
    'signing checks the signed certificate\'s lifetime (it checked the internal certificate\'s field)');
check_api(strpos($cert_save, 'The certificate signing request could not be signed.') !== false,
    'a request that cannot be signed is refused (the CA\'s next serial was written without a certificate)');
check_api(strpos($cert_save, "isset(\$cert) && !empty(\$cert['refid'])") !== false && strpos($cert_save, 'The user to add the certificate to does not exist.') !== false,
    'a certificate is added to an existing user only (an unknown user position created a user without a name)');
check_api(strpos($cert_save, 'pki_dn_validate($post, $input_errors);') !== false && strpos($cert_save, "pki_dn_from_form(\$pconfig, 'csr_dn_')") !== false &&
    strpos($cert_save, "do_input_validation(\$post, \$reqdfields, \$reqdfieldsn, \$input_errors);") !== false,
    'certificate save uses the shared subject helpers and the page\'s required fields');
$csr_complete = $fn_body($pki_inc, 'pki_cert_csr_complete');
check_api(strpos($csr_complete, "cert_get_publickey(base64_decode(\$thiscert['csr']), false, 'csr')") !== false &&
    strpos($csr_complete, "cert_get_publickey(\$pconfig['csr']") === false && strpos($csr_complete, 'This certificate has no pending signing request.') !== false,
    'completing a request compares the certificate with the stored request (the posted copy was compared)');
check_api(strpos($fn_body($pki_inc, 'pki_cert_delete'), 'if (cert_in_use($id)) {') !== false, 'deleting a certificate in use is refused like the page');
check_api(strpos($fn_body($pki_inc, 'pki_cert_used_by'), 'is_webgui_cert($refid)') !== false && strpos($fn_body($pki_inc, 'pki_cert_used_by'), 'is_user_cert($refid)') !== false &&
    strpos($fn_body($pki_inc, 'pki_cert_used_by'), 'is_kea_cert(') !== false && strpos($fn_body($pki_inc, 'pki_cert_used_by'), 'pki_packages_using(') !== false,
    'used_by lists what the page\'s In Use column lists');

/* User manager: users, groups, privileges and authentication servers (area users) */
check_api(isset(restapi_areas()['users']), 'the users permission area exists');
$users_routes = array(
	'GET /v1/users' => array('restapi_h_users_list', 'system_usermanager.php', 'users'),
	'GET /v1/users/{name}' => array('restapi_h_users_get', 'system_usermanager.php', 'users'),
	'POST /v1/users' => array('restapi_h_users_create', 'system_usermanager.php', 'users'),
	'PUT /v1/users/{name}' => array('restapi_h_users_update', 'system_usermanager.php', 'users'),
	'DELETE /v1/users/{name}' => array('restapi_h_users_delete', 'system_usermanager.php', 'users'),
	'GET /v1/users/{name}/privileges' => array('restapi_h_users_privs_get', 'system_usermanager.php', 'users'),
	'PUT /v1/users/{name}/privileges' => array('restapi_h_users_privs_set', 'system_usermanager.php', 'users'),
	'GET /v1/users/{name}/certificates' => array('restapi_h_users_certs_list', 'system_usermanager.php', 'users'),
	'POST /v1/users/{name}/certificates' => array('restapi_h_users_certs_add', 'system_certmanager.php', 'pki'),
	'DELETE /v1/users/{name}/certificates/{refid}' => array('restapi_h_users_certs_remove', 'system_usermanager.php', 'users'),
	'GET /v1/groups' => array('restapi_h_groups_list', 'system_groupmanager.php', 'users'),
	'GET /v1/groups/{name}' => array('restapi_h_groups_get', 'system_groupmanager.php', 'users'),
	'POST /v1/groups' => array('restapi_h_groups_create', 'system_groupmanager.php', 'users'),
	'PUT /v1/groups/{name}' => array('restapi_h_groups_update', 'system_groupmanager.php', 'users'),
	'DELETE /v1/groups/{name}' => array('restapi_h_groups_delete', 'system_groupmanager.php', 'users'),
	'GET /v1/groups/{name}/privileges' => array('restapi_h_groups_privs_get', 'system_groupmanager.php', 'users'),
	'PUT /v1/groups/{name}/privileges' => array('restapi_h_groups_privs_set', 'system_groupmanager.php', 'users'),
	'GET /v1/groups/{name}/members' => array('restapi_h_groups_members_get', 'system_groupmanager.php', 'users'),
	'PUT /v1/groups/{name}/members' => array('restapi_h_groups_members_set', 'system_groupmanager.php', 'users'),
	'GET /v1/auth-servers' => array('restapi_h_authsrv_list', 'system_authservers.php', 'users'),
	'GET /v1/auth-servers/{name}' => array('restapi_h_authsrv_get', 'system_authservers.php', 'users'),
	'POST /v1/auth-servers' => array('restapi_h_authsrv_create', 'system_authservers.php', 'users'),
	'PUT /v1/auth-servers/{name}' => array('restapi_h_authsrv_update', 'system_authservers.php', 'users'),
	'DELETE /v1/auth-servers/{name}' => array('restapi_h_authsrv_delete', 'system_authservers.php', 'users'),
	'POST /v1/auth-servers/{name}/test' => array('restapi_h_authsrv_test', 'diag_authentication.php', 'users'),
	'GET /v1/privileges' => array('restapi_h_privileges', 'system_usermanager.php', 'users'),
	'DELETE /v1/users/{name}/api-keys' => array('restapi_h_users_keys_revoke', 'system_usermanager.php', 'users'),
);
foreach ($v1 as $r) {
	$key = "{$r['method']} {$r['path']}";
	if (preg_match('#^/v1/(users|groups|auth-servers|privileges)(/|$)#', $r['path'])) {
		check_api(isset($users_routes[$key]), "{$key} is a known user manager route");
		check_api($r['handler'] === $users_routes[$key][0] && $r['page'] === $users_routes[$key][1] && $r['area'] === $users_routes[$key][2],
		    "{$key} is handled by {$users_routes[$key][0]}, guarded by {$users_routes[$key][1]} in area {$users_routes[$key][2]}");
		check_api(($r['method'] === 'GET') xor $r['write'], "{$key}: only GET is a read");
		check_api(!isset($r['query']['apply']), "{$key} has no ?apply (applied at once like the pages)");
		unset($users_routes[$key]);
	}
}
check_api(empty($users_routes), 'every user manager route exists: ' . implode(', ', array_keys($users_routes)));
foreach (array('GET /v1/auth-servers/Local%20Database' => 'restapi_h_authsrv_get', 'POST /v1/auth-servers/E6%20LDAP/test' => 'restapi_h_authsrv_test',
    'DELETE /v1/users/apitest-u1/certificates/6ac0b594bc838' => 'restapi_h_users_certs_remove', 'PUT /v1/groups/admins/members' => 'restapi_h_groups_members_set') as $key => $want) {
	list($m, $p) = explode(' ', $key);
	list($r, $params) = restapi_match($v1, $m, $p);
	check_api($r['handler'] === $want, "{$key} reaches {$want}");
}
check_api(restapi_match($v1, 'GET', '/v1/auth-servers/E6%20LDAP')[1] === array('name' => 'E6 LDAP'), 'server names with spaces are URL-encoded path parameters');

/* Users: secrets are write-only, SSH keys as fingerprints */
$smoke_key = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGq1jQvDq0fA8Oq7sQx2KX1q0oS5D0b6Yf8kq3cK9w9F ops@example';
$smoke_fp = 'SHA256:' . rtrim(base64_encode(hash('sha256', base64_decode('AAAAC3NzaC1lZDI1NTE5AAAAIGq1jQvDq0fA8Oq7sQx2KX1q0oS5D0b6Yf8kq3cK9w9F'), true)), '=');
$fps = restapi_users_key_fingerprints("# comment\n{$smoke_key}\n\nfrom=\"192.0.2.1\",no-pty ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGq1jQvDq0fA8Oq7sQx2KX1q0oS5D0b6Yf8kq3cK9w9F x\r\njunk line\nssh-rsa %%%");
check_api($fps === array(array('type' => 'ssh-ed25519', 'fingerprint' => $smoke_fp), array('type' => 'ssh-ed25519', 'fingerprint' => $smoke_fp),
    array('type' => '(unknown)', 'fingerprint' => '(invalid)'), array('type' => 'ssh-rsa', 'fingerprint' => '(invalid)')),
    'SSH keys are returned as SHA256 fingerprints (options, comments, blank and invalid lines handled)');
check_api(strpos(json_encode($fps), 'AAAAC3Nza') === false && strpos(json_encode($fps), 'ops@example') === false, 'fingerprints carry neither the key nor its comment');
check_api(restapi_users_has_password(array('bcrypt-hash' => '$2y$x')) && restapi_users_has_password(array('sha512-hash' => 'x')) &&
    !restapi_users_has_password(array('name' => 'x')), 'a password is reported as set from any stored hash');
$css = array('FreeSense.css' => 'FreeSense', 'FreeSense-dark.css' => 'Dark');
$unew = restapi_users_values(array(), $css);
check_api($unew['webguicss'] === 'FreeSense.css' && $unew['dashboardcolumns'] === '2' && $unew['webguifixedmenu'] === '' && $unew['webguihostnamemenu'] === '' &&
    $unew['groups'] === array() && $unew['disabled'] === false && $unew['password'] === '', 'a new user starts as the page\'s new form (theme, 2 columns, no groups)');
$uform = array('usernamefld' => 'bob', 'descr' => 'Bob', 'webguicss' => 'gone.css', 'dashboardcolumns' => '9', 'webguifixedmenu' => 'fixed', 'webguihostnamemenu' => 'bogus',
    'groups' => array('ops'), 'authorizedkeys' => $smoke_key, 'ipsecpsk' => 'psk1', 'disabled' => true, 'keephistory' => false);
$ucur = restapi_users_values($uform, $css);
check_api($ucur['webguicss'] === 'FreeSense.css' && $ucur['dashboardcolumns'] === '2' && $ucur['webguifixedmenu'] === 'fixed' && $ucur['webguihostnamemenu'] === '' &&
    $ucur['disabled'] === true && $ucur['groups'] === array('ops'), 'a user\'s values are preselected like the edit form (unknown theme, columns and menu fall back)');
$up = restapi_users_post(array('descr' => 'Bobby', 'password' => '(set)', 'ipsecpsk' => '(set)'), $ucur);
check_api($up['usernamefld'] === 'bob' && $up['passwordfld1'] === '' && $up['passwordfld2'] === '' && $up['ipsecpsk'] === 'psk1' && $up['authorizedkeys'] === $smoke_key &&
    $up['disabled'] === 'yes' && !isset($up['keephistory']) && $up['groups'] === array('ops') && $up['descr'] === 'Bobby' && !isset($up['name']) && !isset($up['password']) &&
    $up['save'] === 'Save', 'a user post: "(set)" and omitted secrets keep their value, checkboxes "yes" or left out');
$up = restapi_users_post(array('password' => 'N3w-pass', 'groups' => array(), 'disabled' => false, 'uid' => 0), $ucur);
check_api($up['passwordfld1'] === 'N3w-pass' && $up['passwordfld2'] === 'N3w-pass' && !isset($up['groups']) && !isset($up['disabled']) && !isset($up['uid']),
    'a new password is posted twice (confirmation); no groups and unticked boxes are left out; read-only fields are ignored');
foreach (array(array('bcrypt-hash' => 'x'), array('createcert' => true), array('disabled' => 'yes'), array('groups' => 'admins'), array('password' => array('x')),
    array('scope' => 'system')) as $bad) {
	check_api(api_error_status(function () use ($bad, $ucur) { restapi_users_post($bad, $ucur); }) === 400 || isset($bad['scope']), 'malformed user bodies are 400: ' . json_encode($bad));
}
$up = restapi_users_post(array('scope' => 'system'), $ucur);
check_api(!isset($up['scope']) && !isset($up['utype']), 'the scope is read-only (never posted from the body)');
check_api(restapi_users_priv_list(array('a', 'b', 'a')) === array('a', 'b') && api_error_status(function () { restapi_users_priv_list('page-all'); }) === 400 &&
    api_error_status(function () { restapi_users_priv_list(array('x' => 'page-all')); }) === 400 && api_error_status(function () { restapi_users_priv_list(array(1)); }) === 400 &&
    api_error_status(function () { restapi_users_priv_list(null); }) === 400, 'privilege lists must be lists of ids');
check_api(api_error_status(function () { restapi_users_only(array('members' => array(), 'x' => 1), array('members')); }) === 400 &&
    api_error_status(function () { restapi_users_only(array('members' => array()), array('members')); }) === null, 'sub-resource bodies take only their field');
$e403 = restapi_users_refusal_error(array('status' => 403, 'errors' => array('Only privileges the current user holds can be granted. Not held: <b>x</b>.')));
$e409 = restapi_users_refusal_error(array('status' => 409, 'errors' => array('Cannot delete user admin because it is a system user.')));
check_api($e403->status === 403 && $e403->error_code === 'privilege_escalation' && $e403->getMessage() === 'Only privileges the current user holds can be granted. Not held: x.' &&
    $e409->status === 409 && $e409->error_code === 'protected', 'refusals are 403 privilege_escalation or 409 protected with the page\'s message');

/* Authentication servers: bind password and shared secret are write-only */
$asc = array('type' => array('ldap' => 'LDAP', 'radius' => 'RADIUS'), 'ldap_urltype' => array('Standard TCP' => 'Standard TCP', 'STARTTLS Encrypted' => 'x',
    'SSL/TLS Encrypted' => 'y'), 'ldap_caref' => array('global' => 'Global'), 'ldap_protver' => array(2 => 2, 3 => 3), 'ldap_scope' => array('one' => 'One', 'subtree' => 'Sub'),
    'radius_protocol' => array('PAP' => 'PAP', 'MSCHAPv2' => 'MS-CHAPv2'), 'radius_srvcs' => array('both' => 'b', 'auth' => 'a', 'acct' => 'c'),
    'radius_nasip_attribute' => array('lan' => 'LAN'));
$asnew = restapi_authsrv_values(array('ldap_protver' => 3, 'ldap_anon' => true, 'radius_protocol' => 'MSCHAPv2', 'radius_srvcs' => 'both', 'radius_auth_port' => '1812',
    'radius_acct_port' => '1813'), $asc, true, array('attr_user' => 'cn', 'attr_group' => 'cn', 'attr_member' => 'member', 'allow_unauthenticated' => 'true'));
check_api($asnew['type'] === 'ldap' && $asnew['ldap_port'] === '389' && $asnew['ldap_protver'] === '3' && $asnew['ldap_scope'] === 'one' && $asnew['ldap_caref'] === 'global' &&
    $asnew['ldap_attr_user'] === 'cn' && $asnew['ldap_attr_member'] === 'member' && $asnew['ldap_allow_unauthenticated'] === true && $asnew['ldap_anon'] === true &&
    $asnew['radius_protocol'] === 'MSCHAPv2' && $asnew['radius_auth_port'] === '1812' && $asnew['ldap_timeout'] === '',
    'a new server starts as the page\'s form after its script ran (LDAP, port 389, OpenLDAP template)');
$asedit = restapi_authsrv_values(array('type' => 'radius', 'radius_host' => '192.0.2.61', 'radius_secret' => 'sekrit', 'radius_srvcs' => 'auth',
    'ldap_bindpw' => 'bindpw', 'ldap_authcn' => 'ou=a;ou=b'), $asc, false);
$asmask = restapi_authsrv_mask($asedit);
check_api($asmask['radius_secret'] === '(set)' && $asmask['ldap_bindpw'] === '(set)' && strpos(json_encode($asmask), 'sekrit') === false &&
    restapi_authsrv_mask(array('radius_secret' => ''))['radius_secret'] === '', 'the shared secret and bind password read as "(set)"');
$aspost = restapi_authsrv_post($asedit, restapi_authsrv_types(true));
check_api($aspost['ldapauthcontainers'] === 'ou=a;ou=b' && !isset($aspost['ldap_authcn']) && $aspost['save'] === 'Save' && $aspost['type'] === 'radius',
    'a server post names the containers ldapauthcontainers like the form');
check_api(restapi_authsrv_types(false)['type'] === 'ro' && restapi_authsrv_types(true)['type'] === 'string' && restapi_authsrv_types(false)['name'] === 'string',
    'the type of a server is fixed once created (the page disables the other types when editing)');

/* The security rules of system_usermanager.inc, run with stub configuration functions in a separate PHP process */
$um_inc = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/system_usermanager.inc");
$um_fns = array('usermgr_caller_privs', 'usermgr_is_superuser', 'usermgr_missing_privs', 'usermgr_priv_names', 'usermgr_group_privs', 'usermgr_user_privs',
    'usermgr_refusal', 'usermgr_grant_refusal', 'usermgr_manage_user_refusal', 'usermgr_manage_group_refusal', 'usermgr_admin_members',
    'usermgr_last_admin_refusal', 'usermgr_caller_name', 'usermgr_user_delete_refusal', 'usermgr_user_save_refusal', 'usermgr_group_members_fixed',
    'usermgr_group_used_by', 'usermgr_group_delete_refusal', 'usermgr_group_save_refusal', 'usermgr_user_privs_add_refusal', 'usermgr_group_privs_add_refusal',
    'usermgr_unknown_privs');
$um_code = '';
foreach ($um_fns as $fn) {
	check_api(strpos($um_inc, "function {$fn}(") !== false, "system_usermanager.inc defines {$fn}()");
	$um_code .= $fn_body($um_inc, $fn) . "\n}\n\n";
}
$um_harness = <<<'PHP'
<?php
function gettext($t) { return $t; }
function g_get($k) { return ($k === 'admin_group') ? 'admins' : null; }
function is_numericint($a) { return (is_int($a) && $a >= 0) || (is_string($a) && ($a !== '') && ctype_digit($a)); }
function config_get_path($path, $default = null) {
	$v = $GLOBALS['cfg'];
	foreach (explode('/', $path) as $p) {
		if (!is_array($v) || !array_key_exists($p, $v)) { return $default; }
		$v = $v[$p];
	}
	return $v;
}
function getGroupEntry($name) {
	foreach (config_get_path('system/group', []) as $idx => $g) { if ($g['name'] === $name) { return array('idx' => $idx, 'item' => $g); } }
	return null;
}
function local_user_get_groups($user, $all = false) {
	$out = array();
	foreach (config_get_path('system/group', []) as $g) {
		if (($all || ($g['name'] != 'all')) && is_array($g['member'] ?? null) && in_array($user['uid'] ?? null, $g['member'])) { $out[] = $g['name']; }
	}
	if ($all) { $out[] = 'all'; }
	sort($out);
	return $out;
}
function get_user_privileges(&$user) {
	$privs = is_array($user['priv'] ?? null) ? $user['priv'] : array();
	foreach (local_user_get_groups($user, true) as $name) { $g = getGroupEntry($name); if (is_array($g['item']['priv'] ?? null)) { $privs = array_merge($privs, $g['item']['priv']); } }
	return $privs;
}
$priv_list = array();
foreach (array('page-all' => 'WebCfg - All pages', 'user-shell-access' => 'User - System: Shell account access', 'page-system-usermanager' => 'WebCfg - System: User Manager',
    'page-status-interfaces' => 'WebCfg - Status: Interfaces', 'api-users-write' => 'REST API - Users: write', 'api-pki-write' => 'REST API - PKI: write',
    'system-xmlrpc-ha-sync' => 'User - System: Copy files (HA sync)', 'page-dashboard-all' => 'WebCfg - Dashboard (all)') as $id => $name) {
	$priv_list[$id] = array('name' => $name);
}
$cfg = array('system' => array(
	'user' => array(
		0 => array('name' => 'admin', 'uid' => '0', 'scope' => 'system', 'priv' => array('user-shell-access')),
		1 => array('name' => 'mgr', 'uid' => '2001', 'scope' => 'user', 'priv' => array('page-system-usermanager', 'api-users-write', 'page-dashboard-all')),
		2 => array('name' => 'bob', 'uid' => '2002', 'scope' => 'user', 'priv' => array('page-dashboard-all')),
		3 => array('name' => 'boss', 'uid' => '2003', 'scope' => 'user', 'priv' => array('page-status-interfaces')),
		4 => array('name' => 'root2', 'uid' => '2005', 'scope' => 'user'),
	),
	'group' => array(
		0 => array('name' => 'admins', 'gid' => '1998', 'scope' => 'system', 'member' => array('0', '2005'), 'priv' => array('page-all')),
		1 => array('name' => 'ops', 'gid' => '2000', 'scope' => 'local', 'member' => array('2002'), 'priv' => array('page-dashboard-all')),
		2 => array('name' => 'net', 'gid' => '2001', 'scope' => 'local', 'member' => array(), 'priv' => array('page-status-interfaces')),
	)),
	'ipsec' => array('client' => array('auth_groups' => 'x,net')));
$u = function ($i) { return $GLOBALS['cfg']['system']['user'][$i]; };
$admin = $u(0); $mgr = $u(1); $bob = $u(2); $boss = $u(3); $root2 = $u(4);
$fail = 0;
function t($ok, $msg) { global $fail; if (!$ok) { $fail++; echo "FAIL {$msg}\n"; } }
function st($r) { return ($r === null) ? null : $r['status']; }
PHP;
$um_tests = <<<'PHP'
t(usermgr_is_superuser($admin) && usermgr_is_superuser($root2) && !usermgr_is_superuser($mgr) && !usermgr_is_superuser(null) && !usermgr_is_superuser(array('name' => 'x')),
    'administrators: uid 0 and page-all holders only (unknown callers never)');
t(usermgr_missing_privs($mgr, array('page-all', 'page-dashboard-all', 'page-all')) === array('page-all') && usermgr_missing_privs($admin, array('page-all', 'x')) === array() &&
    usermgr_missing_privs(null, array('page-dashboard-all')) === array('page-dashboard-all'), 'missing privileges of a caller');
foreach (array('page-all', 'user-shell-access', 'system-xmlrpc-ha-sync', 'api-pki-write') as $p) {
	t(st(usermgr_grant_refusal($mgr, array($p))) === 403, "a limited caller cannot grant {$p}");
}
t(usermgr_grant_refusal($mgr, array('api-users-write', 'page-dashboard-all')) === null && usermgr_grant_refusal($root2, array('page-all', 'user-shell-access')) === null,
    'privileges the caller holds can be granted; administrators grant anything');
t(strpos(usermgr_grant_refusal($mgr, array('page-all'))['errors'][0], 'Not held: WebCfg - All pages.') !== false, 'the refusal names the privileges');
t(st(usermgr_manage_user_refusal($mgr, $boss)) === 403 && st(usermgr_manage_user_refusal($mgr, $admin)) === 403 && usermgr_manage_user_refusal($mgr, $bob) === null &&
    usermgr_manage_user_refusal($mgr, $mgr) === null, 'a limited caller manages only users whose privileges it holds (not admin, not boss)');
t(st(usermgr_user_save_refusal(array('usernamefld' => 'esc', 'groups' => array('admins')), null, $mgr)) === 403, 'a new user in the admins group: 403');
t(st(usermgr_user_save_refusal(array('usernamefld' => 'esc', 'groups' => array('net')), null, $mgr)) === 403, 'a new user in a group with privileges the caller lacks: 403');
t(usermgr_user_save_refusal(array('usernamefld' => 'esc', 'groups' => array('ops', 'nosuch')), null, $mgr) === null, 'a new user in a group within the caller\'s privileges');
t(st(usermgr_user_save_refusal(array('usernamefld' => 'bob', 'groups' => array('ops', 'net')), 2, $mgr)) === 403, 'adding a user to a group with privileges the caller lacks: 403');
t(usermgr_user_save_refusal(array('usernamefld' => 'bob', 'groups' => array(), 'disabled' => 'yes'), 2, $mgr) === null, 'a limited caller may disable a user it manages');
t(st(usermgr_user_save_refusal(array('usernamefld' => 'boss', 'passwordfld1' => 'x'), 3, $mgr)) === 403, 'a limited caller cannot set the password of a user it does not manage');
t(st(usermgr_user_save_refusal(array('usernamefld' => 'admin', 'passwordfld1' => 'x', 'groups' => array('admins')), 0, $mgr)) === 403, 'nor of admin');
t(st(usermgr_user_save_refusal(array('usernamefld' => 'mgr', 'disabled' => 'yes'), 1, $mgr)) === 409, 'the caller cannot disable its own user');
t(st(usermgr_user_save_refusal(array('usernamefld' => 'admin', 'disabled' => 'yes', 'groups' => array('admins')), 0, $root2)) === 409, 'a system user cannot be disabled');
t(st(usermgr_user_save_refusal(array('usernamefld' => 'boss2', 'groups' => array('admins')), 0, $root2)) === 409, 'a system user cannot be renamed');
t(usermgr_user_save_refusal(array('usernamefld' => 'admin', 'groups' => array('admins')), 0, $admin) === null, 'admin saves itself');
$GLOBALS['cfg']['system']['group'][0]['member'] = array('0');
t(st(usermgr_user_save_refusal(array('usernamefld' => 'admin', 'groups' => array()), 0, $admin)) === 409, 'the last admins member stays in the group');
$GLOBALS['cfg']['system']['group'][0]['member'] = array('2005');
t(st(usermgr_user_delete_refusal($root2, $admin)) === 409, 'the last admins member cannot be deleted');
$GLOBALS['cfg']['system']['group'][0]['member'] = array('0', '2005');
t(usermgr_user_delete_refusal($root2, $admin) === null, 'an admins member is deleted while others remain');
t(st(usermgr_user_delete_refusal($admin, $root2)) === 409 && st(usermgr_user_delete_refusal($mgr, $mgr)) === 409 && st(usermgr_user_delete_refusal($boss, $mgr)) === 403 &&
    usermgr_user_delete_refusal($bob, $mgr) === null, 'deletes: system user 409, own user 409, a user with privileges the caller lacks 403');
$g = function ($i) { return $GLOBALS['cfg']['system']['group'][$i]; };
t(st(usermgr_group_save_refusal(array('groupname' => 'admins', 'members' => array('0')), 0, $mgr)) === 403, 'a limited caller cannot change admins');
t(st(usermgr_group_save_refusal(array('groupname' => 'wheel', 'members' => array('0')), 0, $admin)) === 409, 'a system group cannot be renamed');
$GLOBALS['cfg']['system']['group'][0]['gid'] = '1999';
t(st(usermgr_group_save_refusal(array('groupname' => 'admins', 'members' => array()), 0, $admin)) === 409 &&
    usermgr_group_save_refusal(array('groupname' => 'admins', 'members' => array('0')), 0, $admin) === null, 'an admins group with its membership shown (gid 1999) keeps a member');
$GLOBALS['cfg']['system']['group'][0]['gid'] = '1998';
t(usermgr_group_save_refusal(array('groupname' => 'admins'), 0, $admin) === null, 'the group with gid 1998 keeps its members (no membership on the form)');
t(st(usermgr_group_save_refusal(array('groupname' => 'copy', 'dup' => '0'), null, $mgr)) === 403 && usermgr_group_save_refusal(array('groupname' => 'copy', 'dup' => '1'), null, $mgr) === null &&
    usermgr_group_save_refusal(array('groupname' => 'new'), null, $mgr) === null, 'a copy of a group (also the first, position 0) needs its privileges');
t(st(usermgr_group_save_refusal(array('groupname' => 'net', 'members' => array('2002')), 2, $mgr)) === 403 && usermgr_group_save_refusal(array('groupname' => 'ops', 'members' => array()), 1, $mgr) === null,
    'group members change only for groups whose privileges the caller holds');
t(st(usermgr_group_delete_refusal($g(0), $admin)) === 409 && st(usermgr_group_delete_refusal($g(2), $admin)) === 409 && usermgr_group_delete_refusal($g(1), $mgr) === null,
    'group deletes: system 409, in use by IPsec 409, allowed otherwise');
t(strpos(usermgr_group_delete_refusal($g(2), $admin)['errors'][0], 'IPsec mobile client group authentication') !== false, 'the in-use refusal names the user');
$GLOBALS['cfg']['ipsec']['client']['auth_groups'] = 'x';
t(st(usermgr_group_delete_refusal($g(2), $mgr)) === 403 && usermgr_group_delete_refusal($g(2), $admin) === null, 'a group with privileges the caller lacks: 403');
t(st(usermgr_user_privs_add_refusal(2, array('page-all'), $mgr)) === 403 && usermgr_user_privs_add_refusal(2, array('page-dashboard-all'), $mgr) === null &&
    st(usermgr_user_privs_add_refusal(3, array('page-dashboard-all'), $mgr)) === 403, 'adding user privileges: held only, to users the caller manages');
t(st(usermgr_group_privs_add_refusal(1, array('user-shell-access'), $mgr)) === 403 && st(usermgr_group_privs_add_refusal(0, array(), $mgr)) === 403 &&
    usermgr_group_privs_add_refusal(1, array('api-users-write'), $mgr) === null, 'adding group privileges: held only, to groups the caller manages');
t(usermgr_unknown_privs(array('page-all', 'nope', 7)) === array('nope', '7'), 'unknown privilege ids');
t(usermgr_group_members_fixed($g(0)) && !usermgr_group_members_fixed($g(1)) && !usermgr_group_members_fixed(array('name' => 'x')), 'the gid 1998 group keeps its members');
t(usermgr_user_privs($bob) === array('page-dashboard-all') && usermgr_user_privs($bob, array('net')) === array('page-dashboard-all', 'page-status-interfaces'),
    'a user\'s privileges: its own and its groups\'');
echo ($fail === 0) ? "ALL OK\n" : "{$fail} failed\n";
PHP;
$um_file = tempnam(sys_get_temp_dir(), 'umtest');
file_put_contents($um_file, $um_harness . "\n" . $um_code . $um_tests);
$um_out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($um_file) . ' 2>&1');
unlink($um_file);
check_api(trim($um_out) === 'ALL OK', "the user manager security rules hold (escalation, protected objects, in use):\n{$um_out}");

/* Static guards: every change runs the security checks, in the GUI and the API */
$um_body = function ($fn) use ($fn_body, $um_inc) { return $fn_body($um_inc, $fn); };
check_api(strpos($um_body('usermgr_is_superuser'), "(string)\$caller['uid'] === '0'") !== false && strpos($um_body('usermgr_is_superuser'), "in_array('page-all', usermgr_caller_privs(\$caller), true)") !== false,
    'administrators are uid 0 or page-all holders');
check_api(strpos($um_body('usermgr_user_save'), 'usermgr_user_save_refusal($post, $id, $caller)') !== false &&
    strpos($um_body('usermgr_group_save'), 'usermgr_group_save_refusal($post, $id, $caller)') !== false &&
    strpos($um_body('usermgr_user_privs_add'), 'usermgr_user_privs_add_refusal($userid, $pconfig[\'sysprivs\'], $caller)') !== false &&
    strpos($um_body('usermgr_group_privs_add'), 'usermgr_group_privs_add_refusal($groupid, $pconfig[\'sysprivs\'], $caller)') !== false &&
    strpos($um_body('usermgr_user_privs_add'), 'usermgr_unknown_privs(') !== false && strpos($um_body('usermgr_group_privs_add'), 'usermgr_unknown_privs(') !== false,
    'every save and privilege change runs the escalation checks (and refuses unknown privileges)');
check_api(strpos($um_body('usermgr_user_delete'), 'usermgr_user_delete_refusal(') !== false && strpos($um_body('usermgr_users_delete'), 'usermgr_user_delete_refusal(') !== false &&
    strpos($um_body('usermgr_user_delete_refusal'), "if (\$user['scope'] == \"system\")") !== false,
    '[fix] single and bulk user deletes refuse system users (the single delete had no system check)');
check_api(strpos($um_body('usermgr_group_delete'), 'usermgr_group_delete_refusal(') !== false && strpos($um_body('usermgr_groups_delete'), 'usermgr_group_delete_refusal(') !== false &&
    strpos($um_body('usermgr_group_delete_refusal'), "if (\$group['scope'] == \"system\")") !== false && strpos($um_body('usermgr_group_delete_refusal'), 'usermgr_group_used_by(') !== false &&
    strpos($um_body('usermgr_group_used_by'), "'ipsec/client/auth_groups'") !== false, '[fix] group deletes refuse system groups and groups in use (IPsec mobile clients)');
foreach (array('usermgr_user_priv_remove', 'usermgr_user_cert_remove') as $fn) {
	check_api(strpos($um_body($fn), 'usermgr_manage_user_refusal($caller, ') !== false, "{$fn}() changes only users the caller manages");
}
check_api(strpos($um_body('usermgr_group_priv_remove'), 'usermgr_manage_group_refusal($caller, ') !== false, 'removing a group privilege needs the group\'s privileges');
check_api(strpos($um_body('usermgr_user_save'), "if (!isset(\$userent['scope'])) {") !== false && strpos($um_body('usermgr_user_save'), "\$post['utype']") === false,
    '[fix] the scope of a user never comes from the form (a posted utype made a user a system user)');
check_api(strpos($um_body('usermgr_group_save'), 'if (usermgr_group_members_fixed($group)) {') !== false && strpos($um_body('usermgr_group_save'), "\$existing['scope'] == 'system'") !== false &&
    strpos($um_body('usermgr_group_save'), "in_array(\$post['gtype'], array('local', 'remote'), true)") !== false,
    '[fix] saving the gid 1998 group keeps its members (the admins group was emptied); scopes are local, remote or the stored system scope');
check_api(substr_count($um_inc, "isset(\$post['dup']) && is_numericint(\$post['dup'])") === 2, '[fix] copying the first group copies its privileges, and the copy is checked');
check_api(strpos($um_body('usermgr_user_remove'), 'local_user_revoke_api_keys($user[\'name\'])') !== false && strpos($um_body('usermgr_user_save'), 'local_user_rename_api_keys($stored_name, $userent[\'name\'])') !== false &&
    strpos($um_body('usermgr_user_save'), 'local_user_revoke_api_keys($userent[\'name\'])') !== false,
    'REST API keys: revoked with a deleted user (and never inherited by a new user of the same name), moved with a renamed one');
foreach (array('usermgr_user_delete', 'usermgr_users_delete', 'usermgr_user_cert_remove', 'usermgr_user_priv_remove', 'usermgr_group_save', 'usermgr_group_delete',
    'usermgr_groups_delete', 'usermgr_group_priv_remove', 'usermgr_user_privs_add', 'usermgr_group_privs_add', 'usermgr_user_save_refusal', 'usermgr_group_save_refusal') as $fn) {
	$body = $um_body($fn);
	check_api(strpos($body, '$_POST') === false && strpos($body, '$_REQUEST') === false && strpos($body, '$_SESSION') === false, "{$fn}() reads the form and caller passed in");
}
check_api(substr_count($um_body('usermgr_user_save'), '$_SESSION') === 3 && strpos($um_body('usermgr_user_save'), '$_POST') === false,
    'the user save reads the session only to clear the insecure password warnings (like the page)');
$auth_inc = file_get_contents("{$root}/src/etc/inc/auth.inc");
$set_pw = substr($auth_inc, strpos($auth_inc, 'function local_user_set_password('));
check_api(strpos(substr($set_pw, 0, strpos($set_pw, "\n}\n")), 'local_user_revoke_api_keys(') === false && strpos($auth_inc, 'function local_user_revoke_api_keys(') !== false,
    'a changed password leaves the user\'s REST API keys working (decision 2026-10-06); revoking is explicit');
$restapi_inc_rk = file_get_contents("{$root}/src/etc/inc/restapi.inc");
check_api(strpos($restapi_inc_rk, 'function restapi_revoke_user_tokens($username)') !== false &&
    strpos($restapi_inc_rk, 'local_user_revoke_api_keys($username)') !== false, '"Revoke all keys" revokes through local_user_revoke_api_keys() and saves');
check_api(strpos(file_get_contents("{$root}/src/usr/local/www/system_restapi_keys.php"), 'restapi_revoke_user_tokens($me)') !== false,
    'My API Keys can revoke all of the signed-in user\'s keys (and only theirs)');
check_api(strpos(file_get_contents("{$root}/src/usr/local/www/system_restapi.php"), "=== 'revoke_all'") !== false,
    'the REST API admin page can revoke all keys of a user');
$rk_users = file_get_contents("{$root}/src/etc/inc/restapi/routes_users.inc");
$rk_fn = substr($rk_users, strpos($rk_users, 'function restapi_h_users_keys_revoke('));
check_api(strpos(substr($rk_fn, 0, strpos($rk_fn, "\n}\n")), 'usermgr_manage_user_refusal($req[\'user\']') !== false,
    'revoking another user\'s keys through the API needs the right to manage that user');
$restapi_um = file_get_contents("{$root}/src/etc/inc/restapi.inc");
$local_user = substr($restapi_um, strpos($restapi_um, 'function restapi_local_user('));
$local_user = substr($local_user, 0, strpos($local_user, "\n}\n"));
check_api(strpos($local_user, "!isset(\$entry['idx'])") !== false && strpos($local_user, "isset(\$user['disabled']) && (\$user['disabled'] !== false)") !== false &&
    strpos($local_user, "empty(\$user['disabled'])") === false,
    '[fix] a key of a deleted user never resolves to a stand-in user, and a disabled user (stored as an empty element) is refused');

$as_inc = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/system_authservers.inc");
$as_used = $fn_body($as_inc, 'authsrv_used_by');
foreach (array("'system/webgui/authmode'", "'openvpn/openvpn-server'", "'ipsec/client/user_source'", "'captiveportal'", "'auth_server', 'auth_server2'", "'radacct_server'") as $needle) {
	check_api(strpos($as_used, $needle) !== false, "authentication servers in use: {$needle}");
}
$as_del = $fn_body($as_inc, 'authsrv_delete');
check_api(strpos($as_del, '$used = authsrv_used_by($serverdeleted);') !== false && strpos($as_del, 'authsrv_used_by') < strpos($as_del, 'config_del_path(') &&
    strpos($as_del, 'is built in and cannot be deleted') !== false, '[fix] a server in use (or the Local Database) is never deleted');
$as_save = $fn_body($as_inc, 'authsrv_save');
check_api(strpos($as_save, 'authsrv_webgui_refusal($a_server[$id][\'name\'], $caller)') !== false && strpos($as_save, 'A valid authentication server type must be selected.') !== false &&
    strpos($as_save, 'The authentication server to edit does not exist or cannot be changed.') !== false && strpos($as_save, '$_POST') === false,
    '[fix] server saves: only administrators change the webConfigurator\'s server; the type and position must be valid');
check_api(strpos($fn_body($as_inc, 'authsrv_webgui_refusal'), 'usermgr_is_superuser($caller)') !== false, 'the webConfigurator\'s server needs an administrator');
check_api(strpos($fn_body($as_inc, 'authsrv_test'), 'logger') === false && strpos($fn_body($as_inc, 'authsrv_test'), 'authenticate_user($username, $password, $authcfg, $attributes)') !== false,
    'the authentication test authenticates like Diagnostics > Authentication and logs no password');
$guiconfig = file_get_contents("{$root}/src/usr/local/www/guiconfig.inc");
foreach (array("'Standard TCP' => 389", "'SSL/TLS Encrypted' => 636", "'CHAP_MD5' => \"MD5-CHAP\"", "'acct' => gettext(\"Accounting\")", "'subtree' => gettext(\"Entire Subtree\")") as $needle) {
	check_api(strpos($guiconfig, $needle) !== false && strpos($as_inc, $needle) !== false, "authentication server choices match guiconfig.inc: {$needle}");
}

/* The pages are thin wrappers */
$pages_um = array(
	'system_usermanager.php' => array('usermgr_user_form($this_user)', "usermgr_user_delete(\$id ?? null, \$_POST['username'] ?? null, \$guiuser)",
	    "usermgr_users_delete(\$_POST['delete_check'], \$guiuser)", "usermgr_user_cert_remove(\$id ?? null, \$_POST['certid'], \$guiuser)",
	    "usermgr_user_priv_remove(\$id, \$_POST['privid'], \$guiuser)", 'usermgr_user_save($_POST, $id ?? null, $guiuser, $pconfig, $savemsg)',
	    'usermgr_user_priv_table($id, $read_only)', 'usermgr_user_cert_table($id, $read_only)', 'usermgr_user_cert_cas()'),
	'system_groupmanager.php' => array("usermgr_group_delete(\$id, \$_REQUEST['groupname'] ?? null, \$guiuser)", "usermgr_group_priv_remove(\$id, \$_REQUEST['privid'], \$guiuser)",
	    'usermgr_group_form($id, $dup)', "usermgr_groups_delete(\$_POST['delete_check'], \$guiuser)", 'usermgr_group_save($_POST, $id, $guiuser, $pconfig, $savemsg)',
	    'usermgr_group_priv_table($id, $read_only, $dup)'),
	'system_usermanager_addprivs.php' => array('usermgr_user_privs_add($userid, $_POST, $guiuser)', 'usermgr_priv_list_sorted()', "usermgr_priv_choices(\$spriv_list, \$a_user['priv'])",
	    'usermgr_root_priv_text()'),
	'system_groupmanager_addprivs.php' => array('usermgr_group_privs_add($groupid, $_POST, $guiuser)', 'usermgr_priv_list_sorted()', 'usermgr_root_priv_text()',
	    "if (!is_numericint(\$groupid) || !is_array(config_get_path(\"system/group/{\$groupid}\"))) {"),
	'system_authservers.php' => array('authsrv_ldap_containers_html($_REQUEST)', 'authsrv_list()', "authsrv_delete(\$_POST['id'], \$guiuser)", 'authsrv_form($a_server[$id], $dup ?? false)',
	    'authsrv_new_form()', 'authsrv_save($_POST, $id, $guiuser)', 'authsrv_radiusnas_list()', "\$read_only = (is_array(\$guiuser) && userHasPrivilege(\$guiuser, \"user-config-readonly\"));",
	    "if (\$_POST['save'] && !\$read_only) {"),
);
foreach ($pages_um as $page => $calls) {
	$src = file_get_contents("{$root}/src/usr/local/www/{$page}");
	foreach ($calls as $call) {
		check_api(strpos($src, $call) !== false, "{$page} uses {$call}");
	}
	check_api(!preg_match('/(?<![a-z_])(write_config|config_set_path|config_del_path|local_user_set|local_user_del|local_group_set|local_group_del|local_user_set_password|cert_create)\(/', $src),
	    "{$page} changes the configuration only through the shared functions");
	preg_match_all('/^function (\w+)\(/m', $src, $m);
	check_api($m[1] === array(), "{$page} defines no PHP functions (they are prefixed in the shared includes)");
}
foreach (array('build_priv_table', 'build_cert_table', 'cpusercmp', 'admin_groups_sort', 'build_priv_list', 'get_root_priv_item_text', 'build_radiusnas_list') as $old) {
	check_api(strpos($um_inc . $as_inc, "function {$old}(") === false, "the pages' {$old}() is prefixed in the shared includes");
}
$front_um = file_get_contents("{$root}/src/usr/local/www/api/index.php");
check_api(strpos($front_um, "require_once('system_usermanager.inc');") !== false && strpos($front_um, "require_once('system_authservers.inc');") !== false,
    'the API front controller loads system_usermanager.inc and system_authservers.inc');

/* The API never returns a password hash or a secret; every write runs the page's security checks first */
$routes_users = file_get_contents("{$root}/src/etc/inc/restapi/routes_users.inc");
check_api(substr_count($routes_users, "'bcrypt-hash'") === 1 && strpos($fn_body($routes_users, 'restapi_users_has_password'), "\$user['bcrypt-hash']") !== false,
    'only restapi_users_has_password() reads a password hash (to say whether there is one)');
foreach (array('restapi_users_out', 'restapi_groups_out', 'restapi_authsrv_out', 'restapi_users_privs_out', 'restapi_users_certs_out') as $fn) {
	$body = $fn_body($routes_users, $fn);
	check_api(strpos($routes_users, "function {$fn}(") !== false && !preg_match('/return \$(user|group|server|cert|values|p|form)\b|\$out = \$(user|group|server|values|form);|\+ \$(user|group|server|values|form|p)\b|array_merge\(\$(user|group|server|values|form)\b/', $body),
	    "{$fn}() copies fields one by one (never the stored entry or form)");
}
$users_out = $fn_body($routes_users, 'restapi_users_out');
check_api(strpos($users_out, "'ipsecpsk' => restapi_svc_mask(\$values['ipsecpsk'])") !== false && strpos($users_out, "'authorizedkeys' =>") === false &&
    strpos($users_out, "restapi_users_key_fingerprints(\$values['authorizedkeys'])") !== false && strpos($users_out, "'password' => restapi_users_has_password(\$user) ? '(set)' : ''") !== false,
    'users: the IPsec key and password read as "(set)", SSH keys as count and fingerprints');
check_api(strpos($fn_body($routes_users, 'restapi_authsrv_out'), "'fields' => restapi_authsrv_mask(\$fields)") !== false, 'servers: the bind password and shared secret are masked');
foreach (array('restapi_users_save' => 'usermgr_user_save_refusal($post, $id, $caller)', 'restapi_h_users_privs_set' => 'usermgr_user_privs_add_refusal($id, $add, $req[\'user\'])',
    'restapi_h_users_certs_add' => "usermgr_manage_user_refusal(\$req['user'], ", 'restapi_groups_save' => 'usermgr_group_save_refusal($post, $id, $caller)',
    'restapi_h_groups_privs_set' => 'usermgr_group_privs_add_refusal($id, $add, $req[\'user\'])', 'restapi_authsrv_save' => 'authsrv_webgui_refusal($server[\'name\'], $caller)') as $fn => $call) {
	check_api(strpos($fn_body($routes_users, $fn), $call) !== false, "{$fn}() refuses privilege escalation before saving ({$call})");
}
foreach (array('restapi_users_save' => 'usermgr_user_save(', 'restapi_h_users_delete' => 'usermgr_user_delete(', 'restapi_h_users_privs_set' => 'usermgr_user_priv_remove(',
    'restapi_h_users_certs_add' => 'pki_cert_save(', 'restapi_h_users_certs_remove' => 'usermgr_user_cert_remove(', 'restapi_groups_save' => 'usermgr_group_save(',
    'restapi_h_groups_delete' => 'usermgr_group_delete(', 'restapi_h_groups_privs_set' => 'usermgr_group_privs_add(', 'restapi_authsrv_save' => 'authsrv_save(',
    'restapi_h_authsrv_delete' => 'authsrv_delete(', 'restapi_h_authsrv_test' => 'authsrv_test(') as $fn => $call) {
	check_api(strpos($fn_body($routes_users, $fn), $call) !== false, "{$fn}() writes through the page's {$call})");
}
check_api(!preg_match('/(?<![a-z_])(write_config|config_set_path|config_del_path|local_user_set|local_user_del|local_user_set_password)\(/', $routes_users),
    'routes_users.inc changes nothing itself');
foreach (array('restapi_users_post' => array('password', 'authorizedkeys', 'ipsecpsk'), 'restapi_authsrv_save' => array('ldap_bindpw', 'radius_secret')) as $fn => $secrets) {
	foreach ($secrets as $secret) {
		check_api(strpos($fn_body($routes_users, $fn), "restapi_vpn_secret_body(\$body, '{$secret}')") !== false ||
		    strpos($fn_body($routes_users, $fn), "array('password', 'authorizedkeys', 'ipsecpsk')") !== false, "{$fn}(): \"(set)\" keeps {$secret}");
	}
}

/* F1: System > Advanced settings, tunables, logs, packages and the system version */
foreach (array('system.settings', 'logs', 'packages') as $area) {
	check_api(isset(restapi_areas()[$area]), "the {$area} permission area exists");
}
$f1_routes = array();
foreach ($v1 as $r) {
	$f1_routes["{$r['method']} {$r['path']}"] = $r;
}
foreach (array(
    'GET /v1/system/advanced/firewall' => array('system_advanced_firewall.php', 'system.settings', false),
    'PUT /v1/system/advanced/firewall' => array('system_advanced_firewall.php', 'system.settings', true),
    'GET /v1/system/advanced/networking' => array('system_advanced_network.php', 'system.settings', false),
    'PUT /v1/system/advanced/networking' => array('system_advanced_network.php', 'system.settings', true),
    'GET /v1/system/advanced/misc' => array('system_advanced_misc.php', 'system.settings', false),
    'PUT /v1/system/advanced/misc' => array('system_advanced_misc.php', 'system.settings', true),
    'GET /v1/system/advanced/notifications' => array('system_advanced_notifications.php', 'system.settings', false),
    'PUT /v1/system/advanced/notifications' => array('system_advanced_notifications.php', 'system.settings', true),
    'GET /v1/system/tunables' => array('system_advanced_sysctl.php', 'system.settings', false),
    'GET /v1/system/tunables/{name}' => array('system_advanced_sysctl.php', 'system.settings', false),
    'POST /v1/system/tunables' => array('system_advanced_sysctl.php', 'system.settings', true),
    'PUT /v1/system/tunables/{name}' => array('system_advanced_sysctl.php', 'system.settings', true),
    'DELETE /v1/system/tunables/{name}' => array('system_advanced_sysctl.php', 'system.settings', true),
    'POST /v1/system/tunables/apply' => array('system_advanced_sysctl.php', 'system.settings', true),
    'GET /v1/logs' => array('status_logs.php', 'logs', false),
    'GET /v1/logs/{name}' => array('status_logs.php', 'logs', false),
    'GET /v1/logs/firewall' => array('status_logs_filter.php', 'logs', false),
    'GET /v1/logs/vpn/{name}' => array('status_logs_vpn.php', 'logs', false),
    'GET /v1/packages/installed' => array('pkg_mgr_installed.php', 'packages', false),
    'GET /v1/packages/available' => array('pkg_mgr.php', 'packages', false),
    'GET /v1/system/version' => array('pkg_mgr_install.php', 'packages', false),
    'GET /v1/status/services' => array('status_services.php', 'status', false)) as $key => $want) {
	check_api(isset($f1_routes[$key]) && ($f1_routes[$key]['page'] === $want[0]) && ($f1_routes[$key]['area'] === $want[1]) &&
	    ($f1_routes[$key]['write'] === $want[2]), "route {$key} (page {$want[0]}, area {$want[1]})");
}
foreach ($v1 as $r) {
	if (($r['area'] === 'logs') || (($r['area'] === 'packages') && ($r['handler'] !== 'restapi_h_firmware_update') && (strpos($r['handler'], 'restapi_h_pkgop_') !== 0))) {
		check_api(($r['method'] === 'GET') && !$r['write'], "{$r['method']} {$r['path']}: logs and the package lists are read only (the operations are step F4)");
	}
}
list($r) = restapi_match($v1, 'GET', '/v1/logs/firewall');
check_api($r['handler'] === 'restapi_h_logs_firewall', 'the firewall log route wins over /v1/logs/{name}');
list($r, $p) = restapi_match($v1, 'GET', '/v1/logs/dmesg.boot');
check_api($r['handler'] === 'restapi_h_logs_get' && $p['name'] === 'dmesg.boot', 'log names may contain a dot');
list($r, $p) = restapi_match($v1, 'GET', '/v1/logs/vpn/logins');
check_api($r['handler'] === 'restapi_h_logs_vpn' && $p['name'] === 'logins', 'VPN log route');
list($r, $p) = restapi_match($v1, 'GET', '/v1/system/tunables/net.inet.tcp.log_debug');
check_api($r['handler'] === 'restapi_h_tunables_get' && $p['name'] === 'net.inet.tcp.log_debug', 'tunables are addressed by name');
list($r) = restapi_match($v1, 'POST', '/v1/system/tunables/apply');
check_api($r['handler'] === 'restapi_h_tunables_apply', 'POST .../tunables/apply is the apply action, not a tunable');
foreach (array('/v1/logs/..%2F..%2Fetc%2Fpasswd', '/v1/logs/%2Fetc%2Fpasswd', '/v1/logs/a%00b', '/v1/logs/vpn/..%2Fpasswd') as $path) {
	check_api(api_error_status(function () use ($v1, $path) { restapi_match($v1, 'GET', $path); }) === 404, "{$path} is not a log name");
}

/* Logs: only the GUI's log names, mapped to fixed files */
$catalog = restapi_log_catalog();
foreach (array('system', 'dhcpd', 'auth', 'portalauth', 'ipsec', 'ppp', 'openvpn', 'ntpd', 'gateways', 'routing', 'resolver', 'wireless',
    'nginx', 'dmesg.boot', 'utx', 'userlog') as $name) {
	check_api(isset($catalog[$name]) && ($catalog[$name]['family'] === 'system') && ($catalog[$name]['page'] === 'status_logs.php'),
	    "{$name} is a system log (status_logs.php)");
}
check_api($catalog['firewall']['file'] === 'filter.log' && $catalog['firewall']['page'] === 'status_logs_filter.php', 'the firewall log is filter.log');
check_api($catalog['vpn/logins']['file'] === 'vpn.log' && $catalog['vpn/pppoe']['file'] === 'poes.log' && $catalog['vpn/l2tp']['file'] === 'l2tps.log',
    'the PPPoE/L2TP logs');
foreach ($catalog as $name => $entry) {
	check_api((basename($entry['file']) === $entry['file']) && preg_match('/^[a-z0-9.]+$/D', $entry['file']) &&
	    (strpos(restapi_log_path($entry), '/var/log/') === 0), "log {$name} is a plain file name in /var/log");
	check_api(is_file("{$root}/src/usr/local/www/{$entry['page']}"), "log {$name}: page {$entry['page']} exists");
}
$status_logs = file_get_contents("{$root}/src/usr/local/www/status_logs.php");
preg_match('/\$allowed_logs = array\((.*?)\n\);/s', $status_logs, $m);
preg_match_all('/^\t"([a-z.]+)" => array/m', $m[1], $gui_logs);
check_api(!empty($gui_logs[1]) && (array_keys(array_filter($catalog, function ($e) { return $e['family'] === 'system'; })) === $gui_logs[1]),
    'the system log names are exactly the list status_logs.php allows');
foreach (array('passwd', '../../etc/passwd', '/etc/passwd', 'system.log', 'filter', 'firewall', 'config.xml', 'vpn', 'vpn/logins', '', '.', '..',
    'SYSTEM', 'system ', "system\0") as $bad) {
	check_api(api_error_status(function () use ($bad) { restapi_log_entry('system', $bad); }) === 404, "\"{$bad}\" is not a system log name");
}
foreach (array('system', 'vpn', 'poes', '../vpn', 'logins/../x') as $bad) {
	check_api(api_error_status(function () use ($bad) { restapi_log_entry('vpn', $bad); }) === 404, "\"{$bad}\" is not a VPN log name");
}
check_api(api_error_status(function () { restapi_log_entry('firewall', 'system'); }) === 404, 'the firewall family has only the firewall log');
check_api(restapi_log_entry('system', 'dhcpd')['file'] === 'dhcpd.log' && restapi_log_entry('vpn', 'l2tp')['file'] === 'l2tps.log', 'known names resolve');
check_api(restapi_log_path(array('file' => '../../etc/passwd')) === '/var/log/passwd', 'a log path never leaves the log directory');
check_api(restapi_log_lines(array()) === 50 && restapi_log_lines(array('lines' => '')) === 50 && restapi_log_lines(array('lines' => '2000')) === 2000 &&
    restapi_log_lines(array('lines' => '1')) === 1, 'lines: default 50, 1 to 2000');
foreach (array('0', '2001', '-1', '1.5', 'abc', '10; ls', ' 5', array('5')) as $bad) {
	check_api(api_error_status(function () use ($bad) { restapi_log_lines(array('lines' => $bad)); }) === 400, 'lines ' . json_encode($bad) . ' is refused');
}
check_api(restapi_log_format(array(), $catalog['system']) === 'parsed' && restapi_log_format(array('format' => 'raw'), $catalog['system']) === 'raw' &&
    restapi_log_format(array(), $catalog['dmesg.boot']) === 'raw' && restapi_log_format(array(), $catalog['utx']) === 'parsed', 'formats like the GUI views');
foreach (array(array('dmesg.boot', 'parsed'), array('userlog', 'parsed'), array('utx', 'raw'), array('system', 'table'), array('system', 'notable'), array('system', 'none')) as $c) {
	check_api(api_error_status(function () use ($catalog, $c) { restapi_log_format(array('format' => $c[1]), $catalog[$c[0]]); }) === 400,
	    "{$c[0]} cannot be read as {$c[1]}");
}
check_api(restapi_log_text(array('filter' => 'sshd'), 'filter') === 'sshd' && restapi_log_text(array(), 'filter') === '', 'text parameters');
foreach (array(str_repeat('a', 201), "a\nb", "a\x00", array('x')) as $bad) {
	check_api(api_error_status(function () use ($bad) { restapi_log_text(array('filter' => $bad), 'filter'); }) === 400, 'filter ' . json_encode($bad) . ' is refused');
}
$routes_logs = file_get_contents("{$root}/src/etc/inc/restapi/routes_logs.inc");
check_api(!preg_match('/(?<![a-z_])(file_get_contents|fopen|readfile|file|exec|shell_exec|system|passthru|popen)\(/', $routes_logs),
    'routes_logs.inc reads logs only through dump_log() and conv_log_filter()');
check_api(strpos($fn_body($routes_logs, 'restapi_log_read'), 'dump_log($path, $lines,') !== false &&
    strpos($fn_body($routes_logs, 'restapi_log_read'), 'conv_log_filter($path, $lines,') !== false, 'logs are read with the GUI functions');
foreach (array('restapi_h_logs_get' => "restapi_log_entry('system', \$name)", 'restapi_h_logs_vpn' => "restapi_log_entry('vpn', \$name)",
    'restapi_h_logs_firewall' => "restapi_log_entry('firewall', 'firewall')") as $fn => $call) {
	check_api(strpos($fn_body($routes_logs, $fn), $call) !== false && strpos($fn_body($routes_logs, $fn), 'restapi_log_lines(') !== false &&
	    strpos($fn_body($routes_logs, $fn), 'restapi_log_filter(') !== false, "{$fn}() resolves the name through the whitelist and bounds lines and filter");
}
check_api(strpos($fn_body($routes_logs, 'restapi_log_filter'), 'cleanup_regex_pattern($filter)') !== false, 'filters are cleaned like the GUI raw filter');

/* System > Advanced: the forms as the API shows them */
check_api(restapi_sysadv_natreflection(array()) === 'proxy' && restapi_sysadv_natreflection(array('enablenatreflectionpurenat' => 'yes')) === 'purenat' &&
    restapi_sysadv_natreflection(array('disablenatreflection' => 'yes', 'enablenatreflectionpurenat' => 'yes')) === 'disable', 'NAT reflection mode as the page preselects it');
$pft = array();
foreach (array('TCP' => array('first', 'opening'), 'UDP' => array('first'), 'Other' => array('first', 'single', 'multiple'), 'FRAG' => array('frag'),
    'ADAPTIVE' => array('start', 'end')) as $proto => $types) {
	foreach ($types as $t) {
		$key = strtolower($proto) . $t . 'timeout';
		$pft[$proto][$t] = array('keyname' => $key, 'value' => '1', 'name' => $key);
	}
}
check_api(restapi_sysadv_timeout_keys($pft) === array('tcpfirsttimeout', 'tcpopeningtimeout', 'udpfirsttimeout', 'otherfirsttimeout',
    'othersingletimeout', 'othermultipletimeout'), 'state timeout fields stop after the Other group, like the page');
check_api(restapi_sysadv_timeout_keys(array()) === array(), 'no pf timeouts, no fields');
$masked = restapi_sysadv_mask(array('a' => 'secret', 'b' => '', 'c' => 'x'), array('a', 'b', 'missing'));
check_api($masked === array('a' => '(set)', 'b' => '', 'c' => 'x'), 'secrets read as "(set)" (empty stays empty)');
check_api(restapi_sysadv_keep_secrets(array('a' => '(set)', 'b' => 'new', 'c' => '(set)'), array('a', 'b')) === array('b' => 'new', 'c' => '(set)'),
    '"(set)" keeps a secret');
check_api(restapi_sysadv_password_post('proxypass', 'old', array()) === array('proxypass' => DMYPWD, 'proxypass_confirm' => DMYPWD) &&
    restapi_sysadv_password_post('proxypass', '', array()) === array('proxypass' => '', 'proxypass_confirm' => '') &&
    restapi_sysadv_password_post('smtppassword', 'old', array('smtppassword' => 'n')) === array('smtppassword' => 'n', 'smtppassword_confirm' => 'n') &&
    restapi_sysadv_password_post('proxypass', 'old', array('proxypass' => '')) === array('proxypass' => '', 'proxypass_confirm' => ''),
    'passwords post like the page: the placeholder keeps, a new value is confirmed, "" clears');
$choices = array('optimization' => array('normal' => 'N', 'aggressive' => 'A'), 'tftpinterface' => array('lan' => 'LAN'), 'harddiskstandby' => array('' => 'on', '0.5' => '6'));
restapi_sysadv_check_choices(array('optimization' => 'normal', 'tftpinterface' => array('lan'), 'harddiskstandby' => '0.5', 'other' => 'x'), $choices,
    array('optimization', 'tftpinterface', 'harddiskstandby'));
foreach (array(array('optimization' => 'bogus'), array('tftpinterface' => array('lan', 'opt9')), array('harddiskstandby' => '1; reboot'), array('optimization' => '')) as $bad) {
	check_api(api_error_status(function () use ($bad, $choices) { restapi_sysadv_check_choices($bad, $choices, array('optimization', 'tftpinterface', 'harddiskstandby')); }) === 422,
	    'select value ' . json_encode($bad) . ' outside the options is a 422');
}
$t = restapi_tunable_out(array('tunable' => 'a&amp;b', 'value' => '1&lt;2', 'descr' => 'd', 'modified' => true));
check_api($t === array('tunable' => 'a&b', 'value' => '1<2', 'descr' => 'd', 'custom' => true), 'tunables are decoded from the stored HTML encoding');
check_api(restapi_tunable_out(array('tunable' => 'x', 'value' => 'default'), '5')['custom'] === false &&
    restapi_tunable_out(array('tunable' => 'x', 'value' => 'default'), '5')['running_value'] === '5', 'system defaults are not custom');

/* Packages and version */
check_api(restapi_pkg_status(array('broken' => true), null) === 'not_installed' && restapi_pkg_status(array('obsolete' => true), null) === 'not_in_repository' &&
    restapi_pkg_status(array('installed_version' => '1', 'version' => '2'), '<') === 'upgrade_available' &&
    restapi_pkg_status(array('installed_version' => '2', 'version' => '2'), '=') === 'up_to_date' &&
    restapi_pkg_status(array('installed_version' => '3', 'version' => '2'), '>') === 'newer_than_available' &&
    restapi_pkg_status(array('installed_version' => '3'), null) === 'unknown' && restapi_pkg_status(array('installed_version' => '1', 'version' => '2'), '?') === 'compare_error',
    'package status as the Installed Packages page shows it');
$pkg = restapi_pkg_out(array('name' => 'FreeSense-pkg-x', 'shortname' => 'x', 'desc' => 'A <b>tool</b> &amp; more', 'version' => '2', 'installed_version' => '1',
    'www' => 'UNKNOWN', 'deps' => array('php83' => array('origin' => 'lang/php83')), 'freesense' => array('display_name' => 'X', 'category' => 'c',
    'capabilities' => array('vpn'))), '<');
check_api($pkg['description'] === 'A tool & more' && $pkg['www'] === null && $pkg['dependencies'] === array('php83') && $pkg['status'] === 'upgrade_available' &&
    $pkg['display_name'] === 'X' && $pkg['capabilities'] === array('vpn') && $pkg['installed_version'] === '1', 'installed package entry');
check_api(!array_key_exists('installed_version', restapi_pkg_out(array('name' => 'p'), false)) && !array_key_exists('status', restapi_pkg_out(array('name' => 'p'), false)),
    'available packages have no installed version or status');
$ver = restapi_version_out(array('version' => '1.1.1', 'installed_version' => '1.1.0', 'pkg_version_compare' => '<'), '1.1.0-DEVELOPMENT');
check_api($ver === array('running_version' => '1.1.0-DEVELOPMENT', 'installed_version' => '1.1.0', 'latest_version' => '1.1.1', 'update_available' => true,
    'status' => 'update_available'), 'version: update available');
check_api(restapi_version_out(array('version' => '1', 'installed_version' => '1', 'pkg_version_compare' => '='), 'x')['update_available'] === false,
    'version: up to date');

/* The pages and shared functions */
$net_inc = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/system_advanced_network.inc");
$net_save = $fn_body($net_inc, 'saveAdvancedNetworking');
check_api(substr_count($net_save, "\treturn ") === 1 && str_ends_with($net_save, "\n\treturn \$json ? json_encode(\$rv) : \$rv;") &&
    strpos($net_save, "\$rv['input_errors'] = \$input_errors;") > strrpos($net_save, "\n\t}\n"), '[fix] saveAdvancedNetworking() returns its result (and the errors) when validation fails');
check_api(strpos($net_save, "\$post['duid'] = get_duid_from_file();") !== false, '[fix] the networking form keeps the system DUID placeholder after a save');
$net_page = file_get_contents("{$root}/src/usr/local/www/system_advanced_network.php");
check_api(strpos($net_page, "var duid = '<?=\$pconfig['global-v6duid']?>';") === false &&
    strpos($net_page, "var duid = '<?=preg_replace('/[^0-9A-Fa-f:]/', '', (string)\$pconfig['global-v6duid'])?>';") !== false,
    'the posted DUID is not echoed unescaped into the page script now that errors re-render the form');
$misc_inc = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/system_advanced_misc.inc");
check_api(strpos($misc_inc, "\$pconfig['mds_disable'] = config_get_path('system/mds_disable', '');") !== false,
    '[fix] the MDS select preselects only Default when unset (a browser posted "Mitigation disabled")');
$sysctl_inc = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/system_advanced_sysctl.inc");
check_api(strpos($sysctl_inc, 'function deleteTunable($id, $complete = false, $redirect = true) {') !== false &&
    strpos($fn_body($sysctl_inc, 'deleteTunable'), "if (\$redirect) {\n\t\t\t\tFreeSenseHeader(") !== false, 'deleteTunable() redirects only when asked');
check_api(strpos($fn_body($sysctl_inc, 'saveTunable'), 'findTunable($post[\'tunable\'])') !== false, '[fix] a second entry for the same tunable is refused');
$sysctl_page = file_get_contents("{$root}/src/usr/local/www/system_advanced_sysctl.php");
check_api(strpos($sysctl_page, 'deleteTunable($id)') !== false && strpos($sysctl_page, 'saveTunable($_POST, $id)') !== false, 'the tunables page is unchanged');
$routes_system = file_get_contents("{$root}/src/etc/inc/restapi/routes_system.inc");
check_api(!preg_match('/(?<![a-z_])(write_config|config_set_path|config_del_path|mark_subsystem_dirty|set_sysctl|set_single_sysctl)\(/', $routes_system),
    'routes_system.inc changes nothing itself');
foreach (array('restapi_h_sysadv_fw_set' => 'saveSystemAdvancedFirewall(', 'restapi_h_sysadv_net_set' => 'saveAdvancedNetworking(',
    'restapi_h_sysadv_misc_set' => 'saveSystemAdvancedMisc(', 'restapi_h_sysadv_notif_set' => 'saveAdvancedNotifications(',
    'restapi_tunables_save' => 'saveTunable(', 'restapi_h_tunables_delete' => 'deleteTunable($idx, $apply, false)') as $fn => $call) {
	check_api(strpos($fn_body($routes_system, $fn), $call) !== false, "{$fn}() writes through the page's {$call})");
}
check_api(!preg_match("/'test-(smtp|telegram|pushover|slack)'/", $routes_system) && strpos($fn_body($routes_system, 'restapi_h_sysadv_notif_set'), "array('save' => 'Save')") !== false,
    'the notification test buttons are never posted (only Save)');
check_api(strpos($fn_body($routes_system, 'restapi_sysadv_notif_out'), 'restapi_sysadv_mask($settings, restapi_sysadv_notif_secrets())') !== false &&
    restapi_sysadv_notif_secrets() === array('smtppassword', 'api', 'pushoverapikey', 'pushoveruserkey', 'slack_api') &&
    strpos($fn_body($routes_system, 'restapi_sysadv_misc_out'), "restapi_sysadv_mask(\$settings, array('proxypass'))") !== false,
    'the SMTP and proxy passwords and the Telegram, Pushover and Slack keys are masked');
foreach (array('system_advanced_firewall.inc', 'system_advanced_network.inc', 'system_advanced_misc.inc', 'system_advanced_notifications.inc',
    'system_advanced_sysctl.inc', 'syslog.inc', 'pkg-utils.inc') as $inc) {
	check_api(strpos(file_get_contents("{$root}/src/usr/local/www/api/index.php"),"require_once('{$inc}');") !== false, "the API front controller loads {$inc}");
}
$routes_pkg = file_get_contents("{$root}/src/etc/inc/restapi/routes_packages.inc");
check_api(strpos($fn_body($routes_pkg, 'restapi_h_pkg_installed'), 'restapi_pkg_not_busy();') !== false &&
    strpos($fn_body($routes_pkg, 'restapi_h_pkg_available'), 'restapi_pkg_not_busy();') !== false &&
    strpos($fn_body($routes_pkg, 'restapi_pkg_not_busy'), "is_subsystem_dirty('packagelock')") !== false, 'package lists are refused while packages are being changed');
check_api(!preg_match('/(?<![a-z_])(pkg_install|pkg_delete|install_package|delete_package|pkg_exec|mwexec|exec|shell_exec)\(/', $routes_pkg),
    'routes_packages.inc installs or removes nothing');

/* F2: Diagnostics (ping, traceroute, DNS lookup, states) and the NDP table */
check_api(isset(restapi_areas()['diagnostics']), 'the diagnostics permission area exists');
$f2_routes = array();
foreach ($v1 as $r) {
	$f2_routes["{$r['method']} {$r['path']}"] = $r;
}
foreach (array(
    'POST /v1/diagnostics/ping' => array('diag_ping.php', 'diagnostics', true),
    'POST /v1/diagnostics/traceroute' => array('diag_traceroute.php', 'diagnostics', true),
    'GET /v1/diagnostics/sources' => array('diag_ping.php', 'diagnostics', false),
    'POST /v1/diagnostics/dns-lookup' => array('diag_dns.php', 'diagnostics', false),
    'GET /v1/diagnostics/states' => array('diag_dump_states.php', 'diagnostics', false),
    'DELETE /v1/diagnostics/states' => array('diag_dump_states.php', 'diagnostics', true),
    'GET /v1/status/ndp' => array('diag_ndp.php', 'status', false)) as $key => $want) {
	check_api(isset($f2_routes[$key]) && ($f2_routes[$key]['page'] === $want[0]) && ($f2_routes[$key]['area'] === $want[1]) &&
	    ($f2_routes[$key]['write'] === $want[2]), "route {$key} (page {$want[0]}, area {$want[1]}, " . ($want[2] ? 'write' : 'read') . ')');
}
$safe = array();
foreach ($v1 as $r) {
	if ($r['safe']) {
		$safe[] = "{$r['method']} {$r['path']}";
	}
}
check_api($safe === array('POST /v1/diagnostics/dns-lookup') && ($f2_routes['POST /v1/diagnostics/dns-lookup']['scope'] === 'diagnostics:read'),
    'only the DNS lookup is a POST with the read scope ("safe")');
check_api(restapi_route('POST', '/v1/x', 'h', array('page' => 'x.php', 'area' => 'status', 'write' => true, 'safe' => true))['safe'] === false,
    'a write route is never "safe"');
check_api(in_array('confirm', $f2_routes['DELETE /v1/diagnostics/states']['body']['required'], true) &&
    in_array('source', $f2_routes['DELETE /v1/diagnostics/states']['body']['required'], true), 'killing states documents confirm and source');
foreach ($v1 as $r) {
	check_api(!preg_match('#^/v1/diagnostics/(states/)?(reset|flush|all)#', $r['path']) ||
	    (($r['method'] === 'POST') && ($r['path'] === '/v1/diagnostics/states/reset') && ($r['area'] === 'operations') && ($r['page'] === 'diag_resetstate.php')),
	    "{$r['path']}: the only state table reset is the F4 operation (POST /v1/diagnostics/states/reset, area operations)");
}

/* Request bodies become the pages' form posts */
check_api(restapi_diag_ping_post(array('host' => 'h')) === array('host' => 'h', 'count' => '3', 'wait' => '1', 'ipproto' => 'ipv4', 'sourceip' => ''),
    'ping defaults: 3 pings, 1 s, IPv4, automatic source');
check_api(restapi_diag_ping_post(array('host' => 'h', 'count' => 10, 'wait' => 3, 'ipprotocol' => 'ipv6', 'source' => 'lan')) ===
    array('host' => 'h', 'count' => '10', 'wait' => '3', 'ipproto' => 'ipv6', 'sourceip' => 'lan'), 'ping fields');
check_api(restapi_diag_ping_post(array('host' => 'h', 'count' => '5'))['count'] === '5', 'a count of digits is accepted');
foreach (array(array('count' => 11), array('count' => 0), array('count' => -1), array('wait' => 11), array('wait' => 0), array('count' => 10, 'wait' => 4),
    array('count' => 4, 'wait' => 8), array('count' => '3; id'), array('count' => 1.5), array('count' => true), array('count' => '1e1'), array('ipprotocol' => 'inet'),
    array('ipprotocol' => 6), array('source' => array('lan')), array('source' => "lan\n"), array('host' => array('a')), array('host' => str_repeat('a', 254)),
    array('host' => "a\x00b"), array('bogus' => 1), array('host' => null)) as $bad) {
	check_api(api_error_status(function () use ($bad) { restapi_diag_ping_post($bad + array('host' => 'h')); }) === 400, 'ping body ' . json_encode($bad) . ' is a 400');
}
check_api(api_error_status(function () { restapi_diag_ping_post(array()); }) === 400, 'ping needs a host');
check_api(restapi_diag_traceroute_post(array('host' => 'h')) === array('host' => 'h', 'ttl' => '18', 'ipproto' => 'ipv4', 'sourceip' => 'any'),
    'traceroute defaults: 18 hops, IPv4, any source, UDP, numeric (unticked boxes are absent)');
check_api(restapi_diag_traceroute_post(array('host' => 'h', 'maxttl' => 64, 'protocol' => 'icmp', 'resolve' => true, 'source' => '')) ===
    array('host' => 'h', 'ttl' => '64', 'ipproto' => 'ipv4', 'sourceip' => 'any', 'useicmp' => 'yes', 'resolve' => 'yes'), 'ICMP and resolve tick the boxes');
check_api(!isset(restapi_diag_traceroute_post(array('host' => 'h', 'protocol' => 'udp', 'resolve' => false))['useicmp']), 'UDP and no resolve leave the boxes unticked');
foreach (array(array('maxttl' => 65), array('maxttl' => 0), array('maxttl' => '3;id'), array('protocol' => 'tcp'), array('resolve' => 'yes'), array('resolve' => 1),
    array('ipprotocol' => 'ipv5'), array('wait' => 1)) as $bad) {
	check_api(api_error_status(function () use ($bad) { restapi_diag_traceroute_post($bad + array('host' => 'h')); }) === 400, 'traceroute body ' . json_encode($bad) . ' is a 400');
}

/* Output parsing */
$ping_out = "PING 192.168.228.1 (192.168.228.1): 56 data bytes\n64 bytes from 192.168.228.1: icmp_seq=0 ttl=128 time=0.396 ms\n\n" .
    "--- 192.168.228.1 ping statistics ---\n2 packets transmitted, 1 packets received, 50.0% packet loss\nround-trip min/avg/max/stddev = 0.396/0.396/0.396/0.000 ms\n";
check_api(restapi_diag_ping_stats($ping_out) === array('transmitted' => 2, 'received' => 1, 'packet_loss' => 50.0,
    'rtt' => array('min' => 0.396, 'avg' => 0.396, 'max' => 0.396, 'stddev' => 0.0)), 'ping statistics are parsed');
check_api(restapi_diag_ping_stats("3 packets transmitted, 0 packets received, 100.0% packet loss\n") ===
    array('transmitted' => 3, 'received' => 0, 'packet_loss' => 100.0, 'rtt' => null), 'no replies: no round-trip times');
check_api(restapi_diag_ping_stats('') === array('transmitted' => null, 'received' => null, 'packet_loss' => null, 'rtt' => null), 'no output: no statistics');
check_api(restapi_diag_traceroute_hops(" 1  192.168.228.1  0.3 ms  0.2 ms  0.2 ms\n 2  * * *\n10  a (1.2.3.4)  1 ms\n    b (1.2.3.5)  2 ms\n") === array(
    array('hop' => 1, 'text' => '192.168.228.1  0.3 ms  0.2 ms  0.2 ms'), array('hop' => 2, 'text' => '* * *'), array('hop' => 10, 'text' => "a (1.2.3.4)  1 ms\nb (1.2.3.5)  2 ms")),
    'traceroute hops are parsed (continuation lines stay with their hop)');
check_api(restapi_diag_dns_types(null, false) === null && restapi_diag_dns_types(null, true) === null && restapi_diag_dns_types('AAAA', false) === array(DNS_AAAA) &&
    restapi_diag_dns_types('CNAME', false) === array(DNS_CNAME) && restapi_diag_dns_types('PTR', true) === null, 'DNS record types');
foreach (array(array('PTR', false), array('A', true), array('MX', false), array('ANY', false)) as $c) {
	check_api(api_error_status(function () use ($c) { restapi_diag_dns_types($c[0], $c[1]); }) === 400, "record type {$c[0]} for " . ($c[1] ? 'an address' : 'a hostname') . ' is a 400');
}
check_api(restapi_diag_query_ms(' 12 msec') === 12 && restapi_diag_query_ms('0 msec') === 0 && restapi_diag_query_ms('No response') === null, 'query times in ms');

/* State filter and kill bounds */
$f2_ifs = array('wan' => 'WAN', 'lan' => 'LAN', 'enc0' => 'IPsec', 'lo0' => 'lo0', 'all' => 'all');
check_api(restapi_diag_states_post(array(), $f2_ifs) === array('interface' => 'all', 'filter' => ''), 'state list defaults: all interfaces, filter always set');
check_api(restapi_diag_states_post(array('interface' => 'lo0', 'filter' => '10.0.0.1', 'ruleid' => '5,77'), $f2_ifs) ===
    array('interface' => 'lo0', 'filter' => '10.0.0.1', 'ruleid' => '5,77'), 'state list filters');
foreach (array(array('interface' => 'opt9'), array('interface' => array('lan')), array('ruleid' => '1;id'), array('ruleid' => '1,'), array('ruleid' => 'a'),
    array('filter' => str_repeat('x', 101)), array('filter' => "a\nb"), array('filter' => array('x'))) as $bad) {
	check_api(api_error_status(function () use ($bad, $f2_ifs) { restapi_diag_states_post($bad, $f2_ifs); }) === 400, 'state query ' . json_encode($bad) . ' is a 400');
}
foreach (array('0', '10001', 'x', '-1', '5 ') as $bad) {
	check_api(api_error_status(function () use ($bad) { restapi_diag_int(array('limit' => $bad), 'limit', 1, RESTAPI_STATES_LIMIT_MAX, 500); }) === 400, "limit \"{$bad}\" is a 400");
}
check_api(restapi_diag_prefix('10.0.0.0/8') === 8 && restapi_diag_prefix('2001:db8::/32') === 32 && restapi_diag_prefix('::/0') === 0 &&
    restapi_diag_prefix('1.2.3.4') === '' && restapi_diag_prefix('a/b') === '', 'prefix lengths');
check_api((RESTAPI_KILL_MIN_PREFIX_V4 === 8) && (RESTAPI_KILL_MIN_PREFIX_V6 === 32) && (RESTAPI_PING_MAX_SECONDS === 30) && (RESTAPI_TRACEROUTE_TIMEOUT === 60),
    'kill and run-time bounds');

/* The pages' shared functions (diag_tools.inc, diag_dump_states.inc), run with the real validators from util.inc in a separate PHP process */
$f2_util = file_get_contents("{$root}/src/etc/inc/util.inc");
$f2_tools = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/diag_tools.inc");
$f2_states = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/diag_dump_states.inc");
$f2_code = '';
foreach (array('do_input_validation', 'is_numericint', 'is_ipaddr', 'is_ipaddrv4', 'is_hostname', 'is_domain', 'is_subnet', 'is_subnetv4', 'is_subnetv6') as $fn) {
	check_api(strpos($f2_util, "function {$fn}(") !== false, "util.inc defines {$fn}()");
	$f2_code .= $fn_body($f2_util, $fn) . "\n}\n\n";
}
foreach (array('diag_exec', 'diag_request_string', 'diag_idn_host', 'diag_request_host', 'diag_host_errors', 'diag_source_error', 'diag_ping_check',
    'diag_ping_command', 'diag_traceroute_check', 'diag_traceroute_command', 'diag_dns_host') as $fn) {
	check_api(strpos($f2_tools, "function {$fn}(") !== false, "diag_tools.inc defines {$fn}()");
	$f2_code .= $fn_body($f2_tools, $fn) . "\n}\n\n";
}
foreach (array('diag_states_kill_target', 'diag_states_filter_errors') as $fn) {
	check_api(strpos($f2_states, "function {$fn}(") !== false, "diag_dump_states.inc defines {$fn}()");
	$f2_code .= $fn_body($f2_states, $fn) . "\n}\n\n";
}
$f2_harness = <<<'PHP'
<?php
define('DIAG_PING_MAX_COUNT', 10); define('DIAG_PING_DEFAULT_COUNT', 3); define('DIAG_PING_MAX_WAIT', 10); define('DIAG_PING_DEFAULT_WAIT', 1);
define('DIAG_TRACEROUTE_MAX_TTL', 64); define('DIAG_TRACEROUTE_DEFAULT_TTL', 18);
function gettext($t) { return $t; }
/* Net_IPv6 is not available here; the firewall's is_ipaddrv6() is stricter, never looser. */
function is_ipaddrv6($ip) { return is_string($ip) && (strpos($ip, '/') === false) && (filter_var(explode('%', $ip)[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false); }
function is_linklocal($ip) { return is_string($ip) && (stripos($ip, 'fe80:') === 0); }
function get_ll_scope($ip) { return 'em1'; }
/* The intl extension is not available here: refuse what UTS #46 refuses in the tests (a leading hyphen, a slash). */
if (!function_exists('idn_to_ascii')) {
	function idn_to_ascii($h) { return preg_match('#^-|/#', $h) ? false : strtolower($h); }
	function idn_to_utf8($h) { if ($h === '' || $h === false) { throw new ValueError('idn_to_utf8(): Argument #1 ($domain) must not be empty'); } return $h; }
}
function get_possible_traffic_source_addresses($ll = false) { return array('wan' => 'WAN', 'lan' => 'LAN', '_lloclan' => 'LAN IPv6 Link-Local', 'lo0' => 'Localhost', '10.0.0.5' => 'VIP'); }
function get_interface_ip($if) { return array('wan' => '203.0.113.2', 'lan' => '192.168.1.1', 'lo0' => '127.0.0.1')[$if] ?? null; }
function get_interface_ipv6($if) { return array('lan' => '2001:db8::1', '_lloclan' => 'fe80::1%em1')[$if] ?? null; }
$fail = 0;
function t($ok, $msg) { global $fail; if (!$ok) { $fail++; echo "FAIL {$msg}\n"; } }
PHP;
$f2_tests = <<<'PHP'
$ok = array('host' => '192.168.1.10', 'count' => '3', 'wait' => '1', 'ipproto' => 'ipv4', 'sourceip' => '');
$c = diag_ping_check($ok);
t($c['input_errors'] === array() && $c['host'] === '192.168.1.10' && $c['count'] === '3' && $c['wait'] === '1', 'a valid ping request');
t(diag_ping_check(array('host' => 'example.com', 'count' => '3'))['input_errors'] === array(), 'the DNS page link (host and count only) is valid');
foreach (array('127.0.0.1; id', '$(id)', '`id`', '127.0.0.1 -f', '-f', '-c1000', 'localhost|id', 'a&&reboot', '../../etc/passwd', "a\nb", "127.0.0.1\x00", 'a b', '', ' ') as $h) {
	$c = diag_ping_check(array('host' => $h) + $ok);
	t(!empty($c['input_errors']), 'ping host ' . json_encode($h) . ' is refused');
	$c = diag_traceroute_check(array('host' => $h, 'ttl' => '18'));
	t(!empty($c['input_errors']), 'traceroute host ' . json_encode($h) . ' is refused');
}
t(in_array('Hostname must be a valid hostname or IP address.', diag_ping_check(array('host' => '-f') + $ok)['input_errors'], true) &&
    (diag_ping_check(array('host' => '-f') + $ok)['host_utf8'] === '-f'), 'a host IDN refuses fails validation and is shown as entered (no ValueError)');
foreach (array('lan; id', '$(id)', 'nosuch', '1.2.3.4', 'any', array('lan')) as $s) {
	t(in_array('The source address must be one of the listed addresses.', diag_ping_check(array('sourceip' => $s) + $ok)['input_errors'], true), 'ping source ' . json_encode($s) . ' is refused');
}
foreach (array('', 'lan', '_lloclan', '10.0.0.5') as $s) {
	t(diag_ping_check(array('sourceip' => $s) + $ok)['input_errors'] === array(), "ping source \"{$s}\" is accepted");
}
t(diag_traceroute_check(array('host' => 'h.example', 'ttl' => '3', 'sourceip' => 'any'))['input_errors'] === array() &&
    !empty(diag_traceroute_check(array('host' => 'h.example', 'ttl' => '3', 'sourceip' => ''))['input_errors']), 'traceroute: "any" is its automatic source, "" is not');
foreach (array('0', '11', 'abc', '1.5', '-1', '3; id') as $n) {
	t(!empty(diag_ping_check(array('count' => $n) + $ok)['input_errors']), "count {$n} is refused");
	t(!empty(diag_ping_check(array('wait' => $n) + $ok)['input_errors']), "wait {$n} is refused");
}
foreach (array('0', '65', 'abc', '3;id', '1.5') as $n) {
	t(!empty(diag_traceroute_check(array('host' => 'h.example', 'ttl' => $n))['input_errors']), "hops {$n} are refused");
}
t(diag_traceroute_check(array('host' => 'h.example', 'ttl' => '64'))['input_errors'] === array(), '64 hops are allowed');
t(!empty(diag_ping_check(array('ipproto' => 'ipv6') + $ok)['input_errors']) && !empty(diag_ping_check(array('host' => '::1', 'ipproto' => 'ipv4') + $ok)['input_errors']) &&
    !empty(diag_ping_check(array('ipproto' => 'inet') + $ok)['input_errors']), 'the IP protocol must match the host and be ipv4 or ipv6');
t(diag_ping_check(array('count' => '') + $ok)['count'] === 3, 'an empty count falls back to the default count (was the default wait)');

t(diag_ping_command('192.168.1.10', 'ipv4', '', '3', '1') === array('/sbin/ping', '-c3', '-i1', '192.168.1.10'), 'ping command, automatic source');
t(diag_ping_command('h.example', 'ipv4', 'lan', '2', '5') === array('/sbin/ping', '-S192.168.1.1', '-c2', '-i5', 'h.example'), 'ping command from an interface');
t(diag_ping_command('fe80::2', 'ipv6', '_lloclan', '1', '1') === array('/sbin/ping6', '-Sfe80::1%em1', '-c1', '-i1', 'fe80::2%em1'), 'ping6 from a link-local address adds the scope');
t(diag_traceroute_command('h.example', 'ipv4', 'any', '18', false, false) === array('/usr/sbin/traceroute', '-n', '-w', '2', '-m', '18', 'h.example'), 'traceroute command (numeric, UDP)');
t(diag_traceroute_command('h.example', 'ipv4', 'lan', '5', true, true) === array('/usr/sbin/traceroute', '-s', '192.168.1.1', '-w', '2', '-I', '-m', '5', 'h.example'),
    'traceroute with names, ICMP and a source (no empty argument)');
t(diag_traceroute_command('2001:db8::9', 'ipv6', 'lan', '5', true, false) === array('/usr/sbin/traceroute6', '-l', '-s', '2001:db8::1', '-w', '2', '-m', '5', '2001:db8::9'), 'traceroute6 with names');

list($h, $u) = diag_dns_host(" [www.example.org]; ");
t($h === 'www.example.org' && $u === 'www.example.org', 'the DNS page trims brackets, quotes and semicolons');
t(diag_dns_host('-f') === array('', '-f') && diag_dns_host(array('x')) === array('', ''), 'DNS: refused or non-string names give an empty host');

t(diag_states_kill_target('192.168.1.5') === '192.168.1.5/32' && diag_states_kill_target('2001:db8::5') === '2001:db8::5/128' &&
    diag_states_kill_target('10.0.0.0/8') === '10.0.0.0/8' && diag_states_kill_target('x') === '' && diag_states_kill_target('1.2.3.4; id') === '',
    'Kill States: an address is a host network (/32, IPv6 /128 - it was /32), a subnet is kept, anything else is nothing');
t(diag_states_filter_errors(array('interface' => 'lan', 'ruleid' => '5')) !== array() && diag_states_filter_errors(array('interface' => 'all', 'ruleid' => '5')) === array(),
    'interface and rule ID filters cannot be combined');

/* diag_exec(): an argument list never reaches a shell; timeouts stop the command */
$r = diag_exec(array('/bin/echo', 'a; id', '$(id)', '`id`'));
t($r['stdout'] === "a; id \$(id) `id`\n" && !$r['timed_out'] && $r['status'] === 0, 'arguments are passed as they are (no shell)');
$r = diag_exec(array('/bin/sh', '-c', 'echo out; echo err >&2; exit 3'));
t($r['stdout'] === "out\n" && $r['stderr'] === "err\n" && $r['status'] === 3, 'stdout and stderr are kept apart');
$t0 = microtime(true);
$r = diag_exec(array('/bin/sh', '-c', 'trap "echo stopped; exit 0" INT; echo started; sleep 20 >/dev/null 2>&1 & wait'), 1);
t($r['timed_out'] && (strpos($r['stdout'], "started\n") === 0) && (strpos($r['stdout'], 'stopped') !== false) && (microtime(true) - $t0 < 4),
    'a timeout sends SIGINT (the command prints its summary) and returns at once');
$t0 = microtime(true);
$r = diag_exec(array('/bin/sh', '-c', 'trap "" INT; sleep 5'), 1);
t($r['timed_out'] && (microtime(true) - $t0 < 5), 'a command that ignores SIGINT is killed 2 seconds later');
$r = diag_exec(array('/bin/sh', '-c', 'yes | head -c 300000'), 0, 1000);
t(strlen($r['stdout']) === 1000, 'output is bounded');
t(diag_exec(array('/nonexistent/command'))['stdout'] === '', 'a missing command gives no output');
echo ($fail === 0) ? "ALL OK\n" : "{$fail} failed\n";
PHP;
$f2_file = tempnam(sys_get_temp_dir(), 'f2test');
file_put_contents($f2_file, $f2_harness . "\n" . $f2_code . $f2_tests);
$f2_out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($f2_file) . ' 2>&1');
unlink($f2_file);
check_api(trim($f2_out) === 'ALL OK', "the diagnostics pages' checks hold (injection, bounds, sources, commands, kill targets, timeouts):\n{$f2_out}");

/* Static guards: the pages are thin wrappers, nothing runs through a shell, the API never creates aliases or flushes the state table */
$f2_page = function ($p) use ($root) { return file_get_contents("{$root}/src/usr/local/www/{$p}"); };
check_api(strpos($f2_page('diag_ping.php'), 'diag_ping_check($_REQUEST)') !== false && strpos($f2_page('diag_ping.php'), 'diag_exec(diag_ping_command(') !== false &&
    strpos($f2_page('diag_ping.php'), 'shell_exec') === false, 'diag_ping.php checks and pings through diag_tools.inc');
check_api(strpos($f2_page('diag_traceroute.php'), 'diag_traceroute_check($_REQUEST)') !== false && strpos($f2_page('diag_traceroute.php'), 'diag_exec(diag_traceroute_command(') !== false &&
    strpos($f2_page('diag_traceroute.php'), 'shell_exec') === false, 'diag_traceroute.php checks and traces through diag_tools.inc');
check_api(strpos($f2_page('diag_dns.php'), 'diag_dns_lookup($_POST, $host)') !== false && strpos($f2_page('diag_dns.php'), 'write_config') === false &&
    preg_match('/isset\(\$_POST\[\'create_alias\'\]\).*\n\tif \(diag_dns_create_alias\(\$host\)\)/', $f2_page('diag_dns.php')) &&
    !preg_match('/^function /m', $f2_page('diag_dns.php')), 'diag_dns.php looks up through diag_tools.inc and creates an alias only for its button');
check_api(strpos($f2_page('diag_dump_states.php'), 'FreeSense_kill_states(') === false && strpos($f2_page('diag_dump_states.php'), 'diag_states_kill_pair(') !== false &&
    strpos($f2_page('diag_dump_states.php'), 'diag_states_kill_filter($_POST[\'filter\'])') !== false && strpos($f2_page('diag_dump_states.php'), 'diag_states_filter_errors($_POST)') !== false,
    'diag_dump_states.php kills states and checks filters through diag_dump_states.inc');
check_api(strpos($f2_page('diag_ndp.php'), '$data = diag_ndp_table();') !== false, 'diag_ndp.php reads the table through diag_ndp.inc');
check_api(!preg_match('/(?<![a-z_])(shell_exec|system|passthru|popen|proc_open)\(/', str_replace($fn_body($f2_tools, 'diag_exec'), '', $f2_tools)) &&
    substr_count($f2_tools, 'exec(') === substr_count($f2_tools, 'diag_exec(') + 1 &&
    strpos($f2_tools, 'exec("/usr/bin/drill " . escapeshellarg($host) . " " . escapeshellarg("@" . trim($dns_server))') !== false,
    'diag_tools.inc runs commands only through diag_exec() (argument lists) and the escaped drill timing of the DNS page');
check_api(strpos($fn_body($f2_tools, 'diag_exec'), 'proc_open(array_map(\'strval\', array_values($argv))') !== false, 'diag_exec() passes an argument list to proc_open()');
check_api(strpos($fn_body($f2_tools, 'diag_dns_lookup'), 'write_config') === false && strpos($fn_body($f2_tools, 'diag_dns_lookup'), 'config_set_path') === false,
    'the DNS lookup changes nothing');
check_api(strpos($f2_tools, 'function display_host_results') === false && strpos($f2_page('diag_dns.php'), 'diag_dns_display_host_results(') !== false,
    'the DNS page helper is prefixed (diag_dns_display_host_results)');
$f2_api = file_get_contents("{$root}/src/etc/inc/restapi/routes_diagnostics.inc");
foreach (array('diag_dns_create_alias', 'write_config', 'config_set_path', 'filter_flush_state_table', 'pfctl', 'FreeSense_kill_states', 'shell_exec', 'exec(\'', 'exec("') as $call) {
	check_api(strpos($f2_api, $call) === false, "routes_diagnostics.inc never calls {$call}");
}
$f2_kill = $fn_body($f2_api, 'restapi_h_diag_states_kill');
check_api(strpos($f2_kill, "(\$body['confirm'] ?? null) !== true") < strpos($f2_kill, 'diag_states_kill_pair(') &&
    strpos($f2_kill, 'RESTAPI_KILL_MIN_PREFIX_V4') < strpos($f2_kill, 'diag_states_kill_filter(') &&
    strpos($f2_kill, "restapi_ip_in_network(\$client, \$tokill)") < strpos($f2_kill, 'diag_states_kill_filter('),
    'killing states needs confirm, a narrow enough source and spares the request\'s own connection');
check_api(strpos($fn_body($f2_api, 'restapi_h_diag_ping'), 'diag_ping_check($post)') !== false &&
    strpos($fn_body($f2_api, 'restapi_h_diag_ping'), "+ RESTAPI_PING_GRACE)") !== false &&
    strpos($fn_body($f2_api, 'restapi_h_diag_traceroute'), 'diag_traceroute_check($post)') !== false &&
    strpos($fn_body($f2_api, 'restapi_h_diag_traceroute'), 'RESTAPI_TRACEROUTE_TIMEOUT)') !== false &&
    strpos($fn_body($f2_api, 'restapi_h_diag_dns'), 'diag_dns_lookup(array(\'host\' => $hostname), $host, $types)') !== false &&
    strpos($fn_body($f2_api, 'restapi_h_diag_states'), 'diag_states_filter_errors($post)') !== false,
    'the API checks requests with the pages\' functions and bounds the run time');
foreach (array('diag_tools.inc', 'diag_dump_states.inc', 'diag_ndp.inc') as $inc) {
	check_api(strpos(file_get_contents("{$root}/src/usr/local/www/api/index.php"), "require_once('{$inc}');") !== false, "the API front controller loads {$inc}");
}

/* F3: General Setup, High Availability, Update Settings and Admin Access */
$f3_routes = array();
foreach ($v1 as $r) {
	$f3_routes["{$r['method']} {$r['path']}"] = $r;
}
foreach (array(
    'GET /v1/system/general' => array('system.php', false),
    'PUT /v1/system/general' => array('system.php', true),
    'GET /v1/system/hasync' => array('system_hasync.php', false),
    'PUT /v1/system/hasync' => array('system_hasync.php', true),
    'GET /v1/system/update-settings' => array('system_update_settings.php', false),
    'PUT /v1/system/update-settings' => array('system_update_settings.php', true),
    'GET /v1/system/advanced/admin' => array('system_advanced_admin.php', false),
    'PUT /v1/system/advanced/admin' => array('system_advanced_admin.php', true)) as $key => $want) {
	check_api(isset($f3_routes[$key]) && ($f3_routes[$key]['page'] === $want[0]) && ($f3_routes[$key]['area'] === 'system.settings') &&
	    ($f3_routes[$key]['write'] === $want[1]), "route {$key} (page {$want[0]}, area system.settings)");
}
foreach ($v1 as $r) {
	check_api(!preg_match('#gitsync|/system/update-settings/#', $r['path']), "{$r['method']} {$r['path']}: no GitSync route");
	check_api(!in_array($r['page'], array('system.php', 'system_hasync.php', 'system_update_settings.php', 'system_advanced_admin.php'), true) ||
	    in_array($r['method'], array('GET', 'PUT'), true), "{$r['method']} {$r['path']}: the system settings pages only have GET and PUT");
}
check_api(strpos(restapi_areas()['system.settings'], 'general setup, high availability, update settings, admin access') !== false,
    'the system.settings area names the new pages');

/* General Setup helpers */
check_api(!restapi_sysgen_multiwan(array()) && !restapi_sysgen_multiwan(array(array('ipprotocol' => 'inet'), array('ipprotocol' => 'inet6'))) &&
    restapi_sysgen_multiwan(array(array('ipprotocol' => 'inet'), array('ipprotocol' => 'inet'))) &&
    restapi_sysgen_multiwan(array(array('ipprotocol' => 'inet6'), array('ipprotocol' => 'inet6'))), 'a gateway per DNS server only with two gateways of a family');
$gp = array('hostname' => 'fw', 'domain' => 'home.arpa', 'dnsserver' => array('192.0.2.53', '2001:db8::53'), 'dnshost0' => 'dns.example',
    'dnsgw0' => 'WAN_DHCP', 'dnsallowoverride' => true, 'dnslocalhost' => null, 'timezone' => 'Etc/UTC', 'timeservers' => 'pool.ntp.org',
    'language' => 'en_US', 'webguicss' => 'gone.css', 'webguifixedmenu' => null, 'webguihostnamemenu' => 'fqdn', 'dashboardcolumns' => 2,
    'logincss' => null, 'login_message' => htmlentities("Authorised <users> only & \"staff\""));
foreach (restapi_sysgen_flags() as $flag) {
	$gp[$flag] = in_array($flag, array('loginshowhost', 'dnsallowoverride'), true);
}
$gv = restapi_sysgen_values($gp, false, array('FreeSense.css' => 'FreeSense'));
check_api($gv['dnsservers'] === array(array('address' => '192.0.2.53', 'hostname' => 'dns.example'), array('address' => '2001:db8::53', 'hostname' => '')) &&
    $gv['webguicss'] === 'FreeSense.css' && $gv['dashboardcolumns'] === '2' && $gv['dnslocalhost'] === '' && $gv['loginshowhost'] === true &&
    $gv['dnsallowoverride'] === true && $gv['interfacessort'] === false && $gv['login_message'] === "Authorised <users> only & \"staff\"",
    'general: the form as API values (theme like the select, the login message as text, no gateways with one WAN)');
$gv2 = restapi_sysgen_values($gp, true, array('gone.css' => 'gone'));
check_api($gv2['dnsservers'][0]['gateway'] === 'WAN_DHCP' && $gv2['dnsservers'][1]['gateway'] === 'none' && $gv2['webguicss'] === 'gone.css',
    'general: gateways ("none" when unset) when the page offers them');
check_api(array_keys(restapi_sysgen_types()) === array_merge(array('dnsservers', 'choices', 'applied', 'hostname', 'domain', 'dnslocalhost', 'timezone',
    'timeservers', 'language', 'webguicss', 'webguifixedmenu', 'webguihostnamemenu', 'dashboardcolumns', 'logincss', 'login_message'), restapi_sysgen_flags()) &&
    !array_diff(array_keys($gv), array_keys(restapi_sysgen_types())), 'general: every returned field has a type');
check_api(restapi_sysgen_dns_rows(array(array('address' => '192.0.2.53'), array('address' => '192.0.2.54', 'hostname' => 'h')), false) ===
    array('dns0' => '192.0.2.53', 'dnshost0' => '', 'dns1' => '192.0.2.54', 'dnshost1' => 'h'), 'general: DNS servers as the page\'s rows');
check_api(restapi_sysgen_dns_rows(array(), false) === array('dns0' => '', 'dnshost0' => '') &&
    restapi_sysgen_dns_rows(array(), true) === array('dns0' => '', 'dnshost0' => '', 'dnsgw0' => 'none'), 'general: no DNS servers posts one empty row like the page');
check_api(restapi_sysgen_dns_rows(array(array('address' => '192.0.2.53', 'gateway' => 'GW')), true)['dnsgw0'] === 'GW' &&
    restapi_sysgen_dns_rows(array(array('address' => '192.0.2.53', 'gateway' => 'none')), false) === array('dns0' => '192.0.2.53', 'dnshost0' => ''),
    'general: gateways only when the page offers them ("none" is always fine)');
check_api(api_error_status(function () { restapi_sysgen_dns_rows(array(array('address' => '192.0.2.53', 'gateway' => 'GW')), false); }) === 422 &&
    api_error_status(function () { restapi_sysgen_dns_rows('192.0.2.53', false); }) === 400 &&
    api_error_status(function () { restapi_sysgen_dns_rows(array('192.0.2.53'), false); }) === 400 &&
    api_error_status(function () { restapi_sysgen_dns_rows(array('a' => array('address' => 'x')), false); }) === 400 &&
    api_error_status(function () { restapi_sysgen_dns_rows(array(array('address' => '192.0.2.53', 'port' => '53')), false); }) === 400 &&
    api_error_status(function () { restapi_sysgen_dns_rows(array(array('address' => array('x'))), false); }) === 400,
    'general: malformed DNS server lists are refused');
$cc = array('logincss' => array('1e3f75;' => 'Dark Blue'), 'timezone' => array('Etc/UTC' => 'Etc/UTC'));
check_api(api_error_status(function () use ($cc) { restapi_settings_check_changed_choices(array('logincss' => '', 'timezone' => 'Etc/UTC'), array('logincss' => ''), $cc, array('logincss', 'timezone')); }) === null &&
    api_error_status(function () use ($cc) { restapi_settings_check_changed_choices(array('logincss' => 'x'), array('logincss' => ''), $cc, array('logincss')); }) === 422 &&
    api_error_status(function () use ($cc) { restapi_settings_check_changed_choices(array('timezone' => "Etc/UTC\nx"), array('timezone' => 'Etc/UTC'), $cc, array('timezone')); }) === 422,
    'selects: a stored value may be kept, a changed one must be offered');

/* High Availability helpers */
$hasync_inc = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/system_hasync.inc");
if (!function_exists('system_hasync_checkbox_names')) {
	eval(substr($fn_body($hasync_inc, 'system_hasync_checkbox_names'), 0) . "\n}\n");
}
check_api(!in_array('synchronizekea6', restapi_hasync_flags(false), true) && in_array('synchronizekea6', restapi_hasync_flags(true), true) &&
    count(restapi_hasync_flags(true)) === 23, 'hasync: the Kea DHCPv6 option only with the Kea backend');
$hv = restapi_hasync_values(array('pfsyncenabled' => 'on', 'synctlsinsecure' => 'yes', 'adminsync' => false, 'synchronizeusers' => '',
    'pfhostid' => 'ab01', 'password' => 'pw'), false);
check_api($hv['pfsyncenabled'] === true && $hv['synctlsinsecure'] === true && $hv['adminsync'] === false && $hv['synchronizeusers'] === false &&
    $hv['pfhostid'] === 'ab01' && $hv['pfsyncpeerip'] === '' && $hv['password'] === 'pw' && !isset($hv['synchronizekea6']),
    'hasync: stored values as the page ticks them ("on"; TLS verification when set)');
$hp = restapi_hasync_post(array_merge($hv, array('pfsyncpeerip' => '192.0.2.70')), false, 'pw', array());
check_api($hp['pfsyncenabled'] === 'on' && $hp['synctlsinsecure'] === 'yes' && !isset($hp['adminsync']) && !isset($hp['synchronizekea6']) &&
    $hp['pfsyncpeerip'] === '192.0.2.70' && $hp['passwordfld'] === DMYPWD && $hp['passwordfld_confirm'] === DMYPWD,
    'hasync: posts "on" ("yes" for TLS verification), unticked boxes left out, the stored password kept by the placeholder');
check_api(restapi_hasync_post($hv, false, '', array())['passwordfld'] === '' && restapi_hasync_post($hv, false, 'pw', array('password' => 'new'))['passwordfld_confirm'] === 'new' &&
    restapi_hasync_post($hv, false, 'pw', array('password' => ''))['passwordfld'] === '' &&
    restapi_sysadv_keep_secrets(array('password' => '(set)', 'username' => 'u'), array('password')) === array('username' => 'u'),
    'hasync: a new or empty password is posted twice; "(set)" keeps it');
check_api(array_keys(array_filter(restapi_hasync_types(false), function ($t) { return $t === 'string'; })) ===
    array('pfhostid', 'pfsyncpeerip', 'pfsyncinterface', 'synchronizetoip', 'username', 'password'), 'hasync: the text fields');
$routes_system = file_get_contents("{$root}/src/etc/inc/restapi/routes_system.inc");
check_api(strpos($fn_body($routes_system, 'restapi_hasync_out'), "restapi_sysadv_mask(\$values, array('password'))") !== false &&
    strpos($fn_body($routes_system, 'restapi_h_hasync_set'), "restapi_sysadv_keep_secrets(\$req['body'], array('password'))") !== false,
    'hasync: the sync password is never returned ("(set)")');

/* Update settings: no GitSync */
check_api(restapi_update_gitsync_fields() === array('synconupgrade', 'repositoryurl', 'branch', 'minimal', 'diff', 'show_files', 'show_command', 'dryrun'),
    'update settings: every GitSync field of the page is listed');
$upd_set = $fn_body($routes_system, 'restapi_h_update_settings_set');
check_api(strpos($upd_set, "throw new RestApiError(400, 'not_available'") < strpos($upd_set, 'restapi_svc_merge(') &&
    strpos($upd_set, 'system_update_settings_save(restapi_svc_post($values, $types), pkg_list_repos(), false)') !== false &&
    strpos($upd_set, "restapi_sysadv_check_choices(array('fwbranch' => \$values['fwbranch']), array('fwbranch' => pkg_build_repo_list())") !== false &&
    strpos($upd_set, 'restapi_pkg_not_busy();') !== false,
    'update settings: GitSync fields are refused first, the branch must be listed, the save leaves GitSync alone');
check_api(strpos($fn_body($routes_system, 'restapi_update_out'), "\$settings['gitsync_configured'] = !empty(config_get_path('system/gitsync'));") !== false &&
    substr_count($routes_system, 'system/gitsync') === 1 && strpos($routes_system, "'repositoryurl' =>") === false,
    'update settings: GitSync is only reported as configured yes/no');
$upd_inc = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/system_update_settings.inc");
$upd_save = $fn_body($upd_inc, 'system_update_settings_save');
$upd_rest = preg_replace('/\n\t\tif \(\$gitsync\) \{\n.*?\n\t\t\}\n/s', "\n", $upd_save);
$upd_rest = str_replace("\t\tif (\$gitsync && empty(config_get_path('system/gitsync'))) {\n\t\t\tconfig_del_path('system/gitsync');\n\t\t}\n", '', $upd_rest);
check_api(strpos($upd_inc, 'function system_update_settings_save(array $post, array $repos, $gitsync = true) {') !== false &&
    substr_count($upd_save, 'if ($gitsync) {') === 2 && preg_match_all("/config_(set|del)_path\('system\/gitsync/", $upd_save) > 10 &&
    strpos(substr($upd_rest, strpos($upd_rest, "{\n")), 'gitsync') === false && strpos($upd_rest, 'repositoryurl') === false && strpos($upd_rest, "pkg_switch_repo();") !== false,
    'update settings: every GitSync write is inside if ($gitsync)');
$upd_page = file_get_contents("{$root}/src/usr/local/www/system_update_settings.php");
check_api(strpos($upd_page, 'system_update_settings_save($_POST, $repos);') !== false && strpos($upd_page, 'write_config(') === false &&
    strpos($upd_page, 'config_set_path(') === false, 'the Update Settings page saves through system_update_settings_save() (GitSync included)');

/* Admin Access helpers and lock-out guards */
check_api(array_keys(restapi_admin_lockout_fields()) === array('webguiproto', 'webguiport', 'noantilockout', 'nodnsrebindcheck', 'nohttpreferercheck',
    'enablesshd', 'sshport', 'sshdkeyonly'), 'admin: the lock-out fields');
$ac = array('webguiproto' => 'https', 'webguiport' => '', 'noantilockout' => false, 'nodnsrebindcheck' => false, 'nohttpreferercheck' => true,
    'enablesshd' => true, 'sshport' => '', 'sshdkeyonly' => 'disabled', 'webgui-redirect' => false, 'max_procs' => '2', 'loginautocomplete' => false);
check_api(restapi_admin_lockout_changes($ac, $ac) === array() && restapi_admin_lockout_changes($ac, array('max_procs' => '3', 'loginautocomplete' => true) + $ac) === array(),
    'admin: harmless changes need no confirm');
foreach (array(array('webguiport' => '8443'), array('webguiproto' => 'http'), array('noantilockout' => true), array('nodnsrebindcheck' => true),
    array('nohttpreferercheck' => false), array('enablesshd' => false), array('sshport' => '2222'), array('sshdkeyonly' => 'enabled')) as $change) {
	check_api(restapi_admin_lockout_changes($ac, $change + $ac) === array_keys($change), 'admin: confirm needed for ' . json_encode($change));
}
check_api(restapi_admin_lockout_changes(array('noantilockout' => true, 'enablesshd' => false) + $ac, $ac) === array(),
    'admin: re-enabling the anti-lockout rule or SSH needs no confirm');
$admin_set = $fn_body($routes_system, 'restapi_h_admin_set');
check_api(strpos($admin_set, "if (!empty(\$lockout) && (\$confirm !== true)) {") < strpos($admin_set, 'doAdvancedAdminPOST(') &&
    strpos($admin_set, "throw new RestApiError(400, 'confirm_required'") !== false && strpos($admin_set, "'webgui_url' => \$after") !== false &&
    strpos($admin_set, 'restapi_admin_port_conflicts($current, $values, restapi_admin_listeners())') < strpos($admin_set, 'doAdvancedAdminPOST(') &&
    strpos($admin_set, 'doAdvancedAdminPOST(restapi_admin_post($values, $types), true, false)') !== false &&
    strpos($admin_set, 'restapi_admin_restart_after_response($webgui, $sshd);') !== false && strpos($admin_set, "'restart' => array(") !== false,
    'admin: lock-out changes need confirm and port conflicts are refused before saving; the save leaves the restarts to the API');
$admin_restart = $fn_body($routes_system, 'restapi_admin_restart_after_response');
check_api(strpos($admin_restart, 'register_shutdown_function(') !== false && strpos($admin_restart, 'ignore_user_abort(true);') < strpos($admin_restart, 'fastcgi_finish_request();') &&
    strpos($admin_restart, 'fastcgi_finish_request();') < strpos($admin_restart, 'sleep(RESTAPI_ADMIN_RESTART_DELAY);') &&
    strpos($admin_restart, 'sleep(RESTAPI_ADMIN_RESTART_DELAY);') < strpos($admin_restart, 'restart_SSHD();') &&
    strpos($admin_restart, 'restart_SSHD();') < strpos($admin_restart, 'restart_GUI();') && strpos($routes_system, 'restart_GUI()') > strpos($routes_system, 'function restapi_admin_restart_after_response('),
    'admin: the webConfigurator and sshd restart only after the response has been sent');
$ap = array('webguiproto' => 'https', 'ssl-certref' => 'c1', 'webguiport' => null, 'max_procs' => 2, 'althostnames' => null, 'sshdkeyonly' => null,
    'sshport' => null, 'sshguard_threshold' => '', 'sshguard_blocktime' => '', 'sshguard_detection_time' => '', 'sshguard_whitelist' => '192.0.2.0/24  10.0.0.1/32',
    'serialspeed' => null, 'primaryconsole' => null, 'disablehttpredirect' => false, 'disablehsts' => true, 'ocsp-staple' => false, 'loginautocomplete' => false,
    'quietlogin' => false, 'roaming' => true, 'noantilockout' => false, 'nodnsrebindcheck' => false, 'nohttpreferercheck' => false, 'pagenamefirst' => false,
    'enablesshd' => false, 'sshdagentforwarding' => false, 'enableserial' => null, 'disableconsolemenu' => false);
$at = array('webguiproto' => 'string', 'ssl-certref' => 'string', 'webguiport' => 'string', 'max_procs' => 'string', 'althostnames' => 'string',
    'sshdkeyonly' => 'string', 'sshport' => 'string', 'sshguard_threshold' => 'string', 'sshguard_blocktime' => 'string', 'sshguard_detection_time' => 'string',
    'sshguard_whitelist' => 'list', 'serialspeed' => 'string', 'primaryconsole' => 'string');
foreach (array_keys(restapi_admin_flags()) as $flag) {
	$at[$flag] = 'bool';
}
$av = restapi_admin_values($ap, $at, array('sshdkeyonly' => array('disabled' => 'a', 'enabled' => 'b', 'both' => 'c')));
check_api($av['sshguard_whitelist'] === array('192.0.2.0/24', '10.0.0.1/32') && $av['sshdkeyonly'] === 'disabled' && $av['max_procs'] === '2' &&
    $av['serialspeed'] === '' && $av['primaryconsole'] === '' && $av['webgui-hsts'] === true && $av['roaming'] === true && $av['enableserial'] === false &&
    $av['webgui-login-messages'] === false, 'admin: the form as API values (stored selects kept, pass list as a list)');
$apost = restapi_admin_post($av, $at);
check_api($apost['webgui-hsts'] === 'yes' && $apost['roaming'] === 'yes' && !isset($apost['webgui-redirect']) && 
    $apost['sshguard_whitelist'] === '192.0.2.0/24 10.0.0.1/32' && $apost['serialspeed'] === '' && $apost['ssl-certref'] === 'c1' && $apost['sshdkeyonly'] === 'disabled',
    'admin: API values to the page\'s JSON-mode post');
check_api(isset(restapi_admin_values($ap + array(), $at, array('sshdkeyonly' => array('disabled' => 'a')))['enableserial']) &&
    !isset(restapi_admin_values(array('enableserial' => true) + $ap, array_diff_key($at, array('enableserial' => 1, 'primaryconsole' => 1)), array('sshdkeyonly' => array('disabled' => 'a')))['primaryconsole']) &&
    restapi_admin_values(array('enableserial' => '') + $ap, $at, array('sshdkeyonly' => array('disabled' => 'a')))['enableserial'] === true,
    'admin: the serial terminal is ticked when set at all; forced consoles are not offered');
check_api(restapi_admin_gui_port(array('webguiport' => '', 'webguiproto' => 'https')) === 443 && restapi_admin_gui_port(array('webguiport' => '', 'webguiproto' => 'http')) === 80 &&
    restapi_admin_gui_port(array('webguiport' => '8443', 'webguiproto' => 'http')) === 8443 && restapi_admin_ssh_port(array('enablesshd' => false, 'sshport' => '22')) === null &&
    restapi_admin_ssh_port(array('enablesshd' => true, 'sshport' => '')) === 22 && restapi_admin_ssh_port(array('enablesshd' => true, 'sshport' => '2222')) === 2222,
    'admin: effective webConfigurator and SSH ports');
$listen = restapi_admin_parse_sockstat(array('root     nginx      123 6  tcp4   *:443                 *:*', 'root     nginx      123 7  tcp6   *:443                 *:*',
    'root     ntopng     55  3  tcp46  *:3000                *:*', 'root     sshd       9   3  tcp4   192.0.2.1:22          *:*', 'garbage', 'bgpd bgpd 1 2 tcp6 [::1]:179 *:*'));
check_api($listen === array(443 => array('nginx'), 3000 => array('ntopng'), 22 => array('sshd'), 179 => array('bgpd')), 'admin: sockstat listeners by port');
$cur = array('webguiproto' => 'https', 'webguiport' => '', 'webgui-redirect' => false, 'enablesshd' => true, 'sshport' => '');
check_api(restapi_admin_port_conflicts($cur, $cur, $listen) === array(), 'admin: the current ports are no conflict (nginx and sshd hold them)');
check_api(restapi_admin_port_conflicts($cur, array('webguiport' => '3000') + $cur, $listen) === array('Port 3000 is already used by ntopng.') &&
    restapi_admin_port_conflicts($cur, array('webguiport' => '22') + $cur, $listen) === array('The webConfigurator and SSH cannot both use port 22.', 'Port 22 is already used by sshd.') &&
    count(restapi_admin_port_conflicts($cur, array('webguiport' => '80') + $cur, $listen)) === 1 &&
    restapi_admin_port_conflicts($cur, array('webguiport' => '80', 'webgui-redirect' => true) + $cur, $listen) === array() &&
    restapi_admin_port_conflicts($cur, array('sshport' => '179') + $cur, $listen) === array('Port 179 is already used by bgpd.') &&
    restapi_admin_port_conflicts($cur, array('sshport' => '443') + $cur, $listen) === array('The webConfigurator and SSH cannot both use port 443.', 'Port 443 is already used by nginx.') &&
    count(restapi_admin_port_conflicts($cur, array('sshport' => '80') + $cur, $listen)) === 1 &&
    restapi_admin_port_conflicts($cur, array('webguiport' => '8443') + $cur, $listen) === array() &&
    restapi_admin_port_conflicts($cur, array('enablesshd' => false, 'webguiport' => '22') + $cur, $listen) === array(),
    'admin: a port used by another service, the SSH port or the HTTPS redirect is refused');
check_api(restapi_admin_gui_url('192.168.228.2', 'https', '') === 'https://192.168.228.2/' && restapi_admin_gui_url('192.168.228.2:8443', 'https', '8443') === 'https://192.168.228.2:8443/' &&
    restapi_admin_gui_url('fw.example:443', 'http', '') === 'http://fw.example/' && restapi_admin_gui_url('[2001:db8::1]:443', 'https', '444') === 'https://[2001:db8::1]:444/' &&
    restapi_admin_gui_url('[::1]', 'https', '443') === 'https://[::1]/' && restapi_admin_gui_url('evil"><x', 'https', '') === 'https://<firewall address>/' &&
    restapi_admin_gui_url('', 'http', '8080') === 'http://<firewall address>:8080/', 'admin: the webConfigurator address after the change');
$admin_inc = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/system_advanced_admin.inc");
$admin_post_fn = $fn_body($admin_inc, 'doAdvancedAdminPOST');
check_api(strpos($admin_inc, 'function doAdvancedAdminPOST($post, $json = false, $restart_now = true) {') !== false &&
    strpos($admin_post_fn, "if (\$restart_sshd && \$json && \$restart_now) {\n\t\trestart_SSHD();") !== false &&
    strpos($admin_post_fn, "if (\$restart_webgui && \$json && \$restart_now) {\n\t\trestart_GUI();") !== false,
    'doAdvancedAdminPOST() can leave the restarts to the caller (the page is unchanged)');
check_api(strpos($admin_post_fn, "(\$post[\$field] != '') && (!is_numericint(\$post[\$field]) || (\$post[\$field] < 1))") < strpos($admin_post_fn, 'if (!$input_errors) {') &&
    strpos($admin_post_fn, "foreach (explode(' ', (string)\$post['sshguard_whitelist']) as \$whitelist_address) {") < strpos($admin_post_fn, '} else {'),
    '[fix] login protection numbers are checked (sshguard.conf is read by a shell); the JSON pass list is checked like the rows');
$admin_page = file_get_contents("{$root}/src/usr/local/www/system_advanced_admin.php");
check_api(strpos($admin_page, '$rv = doAdvancedAdminPOST($_POST);') !== false && strpos($admin_page, "if (\$restart_webgui) {\n\trestart_GUI();") !== false,
    'the Admin Access page saves and restarts as before');

/* The pages are thin wrappers */
$gen_inc = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/system_general.inc");
$gen_save = $fn_body($gen_inc, 'system_general_save');
check_api(strpos($gen_save, '$_POST') === false && strpos($gen_save, 'write_config($changedesc);') !== false && strpos($gen_save, 'global $changedesc, $changecount;') !== false,
    'system_general_save() works on its argument and logs the changes like the page');
check_api(strpos($gen_save, "if (!in_array(\$post['timezone'], system_get_timezone_list(), true)) {") < strpos($gen_save, 'if ($input_errors) {'),
    '[fix] the timezone must be one of the choices (it is written to /var/db/zoneinfo)');
$gen_page = file_get_contents("{$root}/src/usr/local/www/system.php");
check_api(strpos($gen_page, '$rv = system_general_save($_POST);') !== false && strpos($gen_page, '$pconfig = system_general_settings();') !== false &&
    strpos($gen_page, 'write_config(') === false && strpos($gen_page, 'config_set_path(') === false && strpos($gen_page, 'mwexec(') === false,
    'the General Setup page is a thin wrapper');
check_api(strpos($gen_inc, 'function is_timezone(') === false && strpos($gen_page, 'function is_timezone(') !== false,
    'the unused page helper is_timezone() (also in wizard.php) stays in the page');
$ha_page = file_get_contents("{$root}/src/usr/local/www/system_hasync.php");
check_api(strpos($ha_page, '$rv = system_hasync_save($_POST);') !== false && strpos($ha_page, 'write_config(') === false &&
    strpos($ha_page, 'header("Location: system_hasync.php");') !== false && strpos($hasync_inc, 'header(') === false,
    'the High Availability page is a thin wrapper (it redirects, the shared function does not)');
$util_inc = file_get_contents("{$root}/src/etc/inc/util.inc");
$guiconfig_inc = file_get_contents("{$root}/src/usr/local/www/guiconfig.inc");
check_api(strpos($util_inc, 'function update_if_changed($varname, & $orig, $new) {') !== false && strpos($util_inc, 'function update_changedesc($update) {') !== false &&
    strpos($guiconfig_inc, 'function update_if_changed(') === false && strpos($guiconfig_inc, 'function update_changedesc(') === false,
    'update_if_changed() and update_changedesc() live in util.inc');
foreach (array('restapi_h_sysgen_set' => 'system_general_save($post)', 'restapi_h_hasync_set' => 'system_hasync_save(restapi_hasync_post(',
    'restapi_h_update_settings_set' => 'system_update_settings_save(', 'restapi_h_admin_set' => 'doAdvancedAdminPOST(') as $fn => $call) {
	check_api(strpos($fn_body($routes_system, $fn), $call) !== false, "{$fn}() writes through the page's {$call})");
}
foreach (array('system_general.inc', 'system_hasync.inc', 'system_update_settings.inc', 'system_advanced_admin.inc') as $inc) {
	check_api(strpos(file_get_contents("{$root}/src/usr/local/www/api/index.php"), "require_once('{$inc}');") !== false, "the API front controller loads {$inc}");
}

/* F4: system operations (services, state table reset, reboot, halt, packages, system update) */
check_api(isset(restapi_areas()['operations']) && strpos(restapi_areas()['packages'], 'install, reinstall, remove, system update') !== false,
    'the operations area exists and the packages area names the operations');
$f4_routes = array();
foreach ($v1 as $r) {
	$f4_routes["{$r['method']} {$r['path']}"] = $r;
}
$f4_want = array(
    'POST /v1/services/{name}/start' => array('status_services.php', 'operations', 'restapi_h_service_start'),
    'POST /v1/services/{name}/stop' => array('status_services.php', 'operations', 'restapi_h_service_stop'),
    'POST /v1/services/{name}/restart' => array('status_services.php', 'operations', 'restapi_h_service_restart'),
    'POST /v1/diagnostics/states/reset' => array('diag_resetstate.php', 'operations', 'restapi_h_states_reset'),
    'POST /v1/system/reboot' => array('diag_reboot.php', 'operations', 'restapi_h_system_reboot'),
    'POST /v1/system/halt' => array('diag_halt.php', 'operations', 'restapi_h_system_halt'),
    'POST /v1/packages/{name}/install' => array('pkg_mgr_install.php', 'packages', 'restapi_h_pkgop_install'),
    'POST /v1/packages/{name}/reinstall' => array('pkg_mgr_install.php', 'packages', 'restapi_h_pkgop_reinstall'),
    'DELETE /v1/packages/{name}' => array('pkg_mgr_install.php', 'packages', 'restapi_h_pkgop_delete'),
    'POST /v1/system/firmware/update' => array('pkg_mgr_install.php', 'packages', 'restapi_h_firmware_update'));
foreach ($f4_want as $key => $want) {
	check_api(isset($f4_routes[$key]) && ($f4_routes[$key]['page'] === $want[0]) && ($f4_routes[$key]['area'] === $want[1]) &&
	    ($f4_routes[$key]['handler'] === $want[2]) && $f4_routes[$key]['write'] && !$f4_routes[$key]['safe'] &&
	    ($f4_routes[$key]['scope'] === "{$want[1]}:write") && in_array('confirm', $f4_routes[$key]['body']['required'] ?? array(), true),
	    "route {$key} (page {$want[0]}, scope {$want[1]}:write, confirm in the body schema)");
}
check_api(isset($f4_routes['GET /v1/packages/operation']) && !$f4_routes['GET /v1/packages/operation']['write'] &&
    $f4_routes['GET /v1/packages/operation']['page'] === 'pkg_mgr_install.php', 'GET /v1/packages/operation (packages:read)');
foreach (array('POST /v1/system/reboot', 'POST /v1/system/halt', 'POST /v1/packages/{name}/install', 'POST /v1/packages/{name}/reinstall',
    'DELETE /v1/packages/{name}', 'POST /v1/system/firmware/update') as $key) {
	check_api(strpos($f4_routes[$key]['summary'], 'Requires {"confirm": true} and an administrator') !== false, "{$key}: the summary documents confirm and administrator");
}
foreach ($v1 as $r) {
	check_api(stripos($r['path'], 'reroot') === false && stripos($r['summary'] . json_encode($r['body']), '"reroot"') === false,
	    "{$r['method']} {$r['path']}: reroot is not exposed");
	if ($r['area'] === 'operations') {
		check_api($r['write'] && isset($f4_want["{$r['method']} {$r['path']}"]), "{$r['method']} {$r['path']}: every operations route is a known write");
	}
}
foreach (array('ntpd', 'unbound', 'syslogd', 'dpinger', 'kea-dhcp4', 'openvpn', 'captiveportal', 'syslog-ng', 'dnsmasq', 'radvd', 'sshd', 'ipsec',
    'dhcrelay6', 'miniupnpd', 'FRR%20zebra') as $svc) {
	foreach (array('start', 'stop', 'restart') as $action) {
		list($r, $p) = restapi_match($v1, 'POST', "/v1/services/{$svc}/{$action}");
		check_api(($r['handler'] === "restapi_h_service_{$action}") && ($p['name'] === rawurldecode($svc)), "POST /v1/services/{$svc}/{$action} is the service control");
	}
}
list($r, $p) = restapi_match($v1, 'DELETE', '/v1/packages/FreeSense-pkg-nut');
check_api($r['handler'] === 'restapi_h_pkgop_delete' && $p['name'] === 'FreeSense-pkg-nut', 'DELETE /v1/packages/{name}');
list($r) = restapi_match($v1, 'GET', '/v1/packages/operation');
check_api($r['handler'] === 'restapi_h_pkgop_status', 'GET /v1/packages/operation is the status, not a package');
list($r) = restapi_match($v1, 'GET', '/v1/packages/installed');
check_api($r['handler'] === 'restapi_h_pkg_installed', 'the package lists keep their routes');
check_api(api_error_status(function () use ($v1) { restapi_match($v1, 'GET', '/v1/packages/FreeSense-pkg-nut'); }) === 405 &&
    api_error_status(function () use ($v1) { restapi_match($v1, 'POST', '/v1/system/reroot'); }) === 404 &&
    api_error_status(function () use ($v1) { restapi_match($v1, 'DELETE', '/v1/packages/a%2Fb'); }) === 404, 'no GET of a package, no reroot, no slash in a package name');

/* Bodies, confirm and administrators */
check_api(restapi_ops_body(array('confirm' => true, 'vpnid' => 1, 'zone' => 'z'), array('confirm' => 'bool', 'vpnid' => 'int', 'zone' => 'string')) ===
    array('confirm' => true, 'vpnid' => 1, 'zone' => 'z') && restapi_ops_body(array('vpnid' => '12'), array('vpnid' => 'int')) === array('vpnid' => '12'),
    'operation bodies: the declared fields');
foreach (array(array('force' => true), array('confirm' => 'true'), array('confirm' => 1), array('confirm' => null), array('vpnid' => '1;id'), array('vpnid' => 1.5),
    array('zone' => array('x')), array('type' => true)) as $bad) {
	check_api(api_error_status(function () use ($bad) { restapi_ops_body($bad, array('confirm' => 'bool', 'vpnid' => 'int', 'zone' => 'string', 'type' => 'string')); }) === 400,
	    'operation bodies: refused ' . json_encode($bad));
}
check_api(api_error_status(function () { restapi_ops_confirm(array('confirm' => true), 'x'); }) === null, 'confirm: true is accepted');
foreach (array(array(), array('confirm' => false), array('confirm' => 'true'), array('confirm' => 1), array('confirm' => 'yes'), array('confirm' => array(true))) as $bad) {
	check_api(api_error_status(function () use ($bad) { restapi_ops_confirm($bad, 'x'); }) === 400, 'confirm: only true is accepted, not ' . json_encode($bad));
}
$f4_privs = function ($user, $priv) { return in_array($priv, $user['privs'] ?? array(), true); };
check_api(restapi_ops_is_admin(array('uid' => '0'), $f4_privs) && restapi_ops_is_admin(array('uid' => 0), $f4_privs) &&
    restapi_ops_is_admin(array('uid' => '2000', 'privs' => array('page-all')), $f4_privs) &&
    !restapi_ops_is_admin(array('uid' => '2000', 'privs' => array('page-diagnostics-rebootsystem', 'page-diagnostics-haltsystem', 'page-system-packagemanager-installpackage',
    'api-operations-write', 'api-packages-write', 'user-shell-access')), $f4_privs) && !restapi_ops_is_admin(array('uid' => '00'), $f4_privs) &&
    !restapi_ops_is_admin(array(), $f4_privs) && !restapi_ops_is_admin(null, $f4_privs),
    'administrators: uid 0 or page-all; page and API privileges alone are not enough');

/* Reboot types */
check_api(restapi_ops_reboot_mode(null, false) === 'reboot' && restapi_ops_reboot_mode('normal', true) === 'reboot' && restapi_ops_reboot_mode('fsck', true) === 'fsckreboot',
    'reboot: normal and fsck map to the page\'s methods');
check_api(api_error_status(function () { restapi_ops_reboot_mode('fsck', false); }) === 422 && api_error_status(function () { restapi_ops_reboot_mode('reroot', true); }) === 422 &&
    api_error_status(function () { restapi_ops_reboot_mode('fsckreboot', true); }) === 422 && api_error_status(function () { restapi_ops_reboot_mode('Normal', true); }) === 422 &&
    api_error_status(function () { restapi_ops_reboot_mode('', true); }) === 422, 'reboot: reroot, the page\'s raw method names and fsck where it is not offered are refused');

/* Services */
$f4_svcs = array(array('name' => 'ntpd', 'description' => 'NTP'), array('name' => 'openvpn', 'mode' => 'server', 'id' => 0, 'vpnid' => '1'),
    array('name' => 'openvpn', 'mode' => 'client', 'id' => 0, 'vpnid' => '2'), array('name' => 'captiveportal', 'zone' => 'guest'), array(), array('name' => 'syslog-ng'));
check_api(restapi_ops_find_service($f4_svcs, 'ntpd', array())['description'] === 'NTP' && restapi_ops_find_service($f4_svcs, 'openvpn', array('mode' => 'client', 'vpnid' => 2))['vpnid'] === '2' &&
    restapi_ops_find_service($f4_svcs, 'openvpn', array('mode' => 'server', 'vpnid' => '1'))['mode'] === 'server' &&
    restapi_ops_find_service($f4_svcs, 'captiveportal', array('zone' => 'guest'))['zone'] === 'guest', 'services are found by name, OpenVPN by mode and vpnid, captive portal by zone');
foreach (array(array('nosuch', array(), 404), array('openvpn', array(), 400), array('openvpn', array('mode' => 'server'), 400), array('openvpn', array('mode' => 'server', 'vpnid' => 2), 404),
    array('captiveportal', array(), 400), array('captiveportal', array('zone' => 'other'), 404), array('ntpd', array('zone' => 'guest'), 400), array('NTPD', array(), 404), array('', array(), 404)) as $c) {
	check_api(api_error_status(function () use ($f4_svcs, $c) { restapi_ops_find_service($f4_svcs, $c[0], $c[1]); }) === $c[2], "service lookup {$c[0]} " . json_encode($c[1]) . " is {$c[2]}");
}
check_api(restapi_ops_service_extras('restartservice', array('name' => 'ntpd')) === array('ajax' => 'ajax', 'mode' => 'restartservice', 'service' => 'ntpd') &&
    restapi_ops_service_extras('stopservice', $f4_svcs[1]) === array('ajax' => 'ajax', 'mode' => 'stopservice', 'service' => 'openvpn', 'vpnmode' => 'server', 'zone' => 'server', 'id' => '1') &&
    restapi_ops_service_extras('startservice', $f4_svcs[3]) === array('ajax' => 'ajax', 'mode' => 'startservice', 'service' => 'captiveportal', 'vpnmode' => 'guest', 'zone' => 'guest'),
    'the service control functions get the fields the page\'s buttons post (vpnmode/zone/id like FreeSenseHelpers.js)');
check_api(api_error_status(function () { restapi_ops_service_allowed('start', false, true); }) === null && api_error_status(function () { restapi_ops_service_allowed('start', true, true); }) === 409 &&
    api_error_status(function () { restapi_ops_service_allowed('start', false, false); }) === 409 && api_error_status(function () { restapi_ops_service_allowed('stop', true, false); }) === null &&
    api_error_status(function () { restapi_ops_service_allowed('restart', true, true); }) === null && api_error_status(function () { restapi_ops_service_allowed('stop', false, true); }) === 409 &&
    api_error_status(function () { restapi_ops_service_allowed('restart', false, true); }) === 409, 'services: start only a stopped enabled service, stop/restart only a running one (like the page\'s buttons)');

/* Package names, log tail, state */
foreach (array('FreeSense-pkg-nut', 'FreeSense-pkg-Status_Traffic_Totals', 'FreeSense-pkg-mDNS-Bridge', 'FreeSense-pkg-a.b') as $ok) {
	check_api(restapi_pkgop_valid_name($ok, 'FreeSense-pkg-'), "package name {$ok} is accepted");
}
foreach (array('nut', 'FreeSense-pkg-', 'ALL_PACKAGES', 'FreeSense-pkg-ALL_PACKAGES -f', 'FreeSense-pkg-a;id', 'FreeSense-pkg-$(id)', 'FreeSense-pkg-a b', "FreeSense-pkg-a\n",
    'FreeSense-pkg-a/../../x', '-rFreeSense-pkg-x', 'FreeSense-pkg-a+b', 'freesense-pkg-nut', 'FreeSense-pkg-' . str_repeat('a', 120), 'xFreeSense-pkg-nut', '', null, array('FreeSense-pkg-nut')) as $bad) {
	check_api(!restapi_pkgop_valid_name($bad, 'FreeSense-pkg-'), 'package name ' . json_encode($bad) . ' is refused');
}
check_api(restapi_pkgop_tail("a\nb\nc\n", 2) === array('b', 'c') && restapi_pkgop_tail("a\r\nb", 5) === array('a', 'b') && restapi_pkgop_tail('', 5) === array() &&
    restapi_pkgop_tail("x\n\n", 5) === array('x'), 'operation log: the last lines');
check_api(restapi_pkgop_state(true, null) === 'running' && restapi_pkgop_state(true, 0) === 'running' && restapi_pkgop_state(false, 0) === 'succeeded' &&
    restapi_pkgop_state(false, 1) === 'failed' && restapi_pkgop_state(false, null) === 'stopped', 'operation states');
check_api(restapi_pkgop_lines(array()) === RESTAPI_PKGOP_LINES_DEFAULT && restapi_pkgop_lines(array('lines' => '7')) === 7 &&
    api_error_status(function () { restapi_pkgop_lines(array('lines' => '0')); }) === 400 && api_error_status(function () { restapi_pkgop_lines(array('lines' => '1001')); }) === 400 &&
    api_error_status(function () { restapi_pkgop_lines(array('lines' => '5x')); }) === 400 && api_error_status(function () { restapi_pkgop_lines(array('lines' => array('5'))); }) === 400,
    'operation log: lines 1-1000');

/* The Package Installer's shared functions (log, progress and mode file parsing) */
$f4_inc = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/pkg_mgr_install.inc");
foreach (array('pkg_mgr_install_name_ok', 'pkg_mgr_install_read_mode', 'pkg_mgr_install_read_log', 'pkg_mgr_install_read_progress') as $fn) {
	if (!function_exists($fn)) {
		eval($fn_body($f4_inc, $fn) . "\n}\n");
	}
}
$f4_tmp = tempnam(sys_get_temp_dir(), 'f4');
file_put_contents("{$f4_tmp}.txt", "Installing <b>x</b> & y\nmore\n__RC=0 __REBOOT_AFTER=10\n");
$f4_log = pkg_mgr_install_read_log($f4_tmp);
check_api($f4_log === array('log' => "Installing &lt;b&gt;x&lt;/b&gt; &amp; y\nmore\nSuccess\n", 'status' => array('reboot_needed' => 'yes', 'exitstatus' => '0')) &&
    pkg_mgr_install_read_log($f4_tmp, false)['log'] === "Installing <b>x</b> & y\nmore\nSuccess\n", 'the log as the page shows it (escaped) and as the API returns it (plain)');
file_put_contents("{$f4_tmp}.txt", "err\n__RC=75\n");
check_api(pkg_mgr_install_read_log($f4_tmp) === array('log' => "err\nFailed\n", 'status' => array('reboot_needed' => 'no', 'exitstatus' => "75\n")) &&
    pkg_mgr_install_read_log("{$f4_tmp}-none") === null, 'a failed operation; no log yet is null ("not_ready")');
file_put_contents("{$f4_tmp}.json", "{\"type\":\"INFO_PROGRESS_TICK\",\"data\":{\"current\":3,\"total\":9}}\n{\"type\":\"INFO_PROGRESS_TICK\",\"data\":{\"current\":4,\"total\":9}}\nx\n");
check_api(pkg_mgr_install_read_progress($f4_tmp) === array('type' => 'INFO_PROGRESS_TICK', 'data' => array('current' => 4, 'total' => 9)) &&
    pkg_mgr_install_read_progress("{$f4_tmp}-none") === array(), 'the newest progress record');
foreach (array("installpkg\nFreeSense-pkg-nut" => array('installpkg', 'FreeSense-pkg-nut'), 'installpkgFreeSense-pkg-nut' => array('installpkg', 'FreeSense-pkg-nut'),
    "delete\nFreeSense-pkg-a" => array('delete', 'FreeSense-pkg-a'), 'reinstallpkgFreeSense-pkg-b' => array('reinstallpkg', 'FreeSense-pkg-b'),
    'firmwareupdate' => array('firmwareupdate', null), 'reinstallall' => array('reinstallall', null), "installpkg\n../../x" => array('installpkg', null), '' => array(null, null)) as $content => $want) {
	file_put_contents($f4_tmp, $content);
	check_api(pkg_mgr_install_read_mode($f4_tmp) === $want, 'mode file ' . json_encode($content) . ' reads as ' . json_encode($want));
}
array_map('unlink', array($f4_tmp, "{$f4_tmp}.txt", "{$f4_tmp}.json"));
check_api(pkg_mgr_install_name_ok('FreeSense-pkg-x') && !pkg_mgr_install_name_ok('../x') && !pkg_mgr_install_name_ok('') && !pkg_mgr_install_name_ok(array()),
    'the page\'s package name check');
$f4_start = $fn_body($f4_inc, 'pkg_mgr_install_start');
check_api(strpos($f4_start, '@file_put_contents($gui_mode, implode("\n", $mode));') !== false && strpos($f4_start, '@file_put_contents($gui_mode, $mode);') === false,
    '[fix] the mode file has one item per line (the page reads the package back)');
check_api(strpos($f4_start, 'write_config(gettext("Creating restore point before package installation."));') < strpos($f4_start, 'mwexec_bg(') &&
    strpos($f4_start, "if ((int)\$matches[1] != 75) {") !== false && strpos($f4_start, 'for ($idx = 0; $idx < 30; $idx++) {') !== false &&
    strpos($f4_start, '"-i ALL_PACKAGES -f"') !== false && substr_count($f4_start, 'mwexec_bg(') === 1,
    'the start writes a restore point first and retries while another instance holds the lock, like the page did');

/* The pages are thin wrappers */
$f4_page = function ($p) use ($root) { return file_get_contents("{$root}/src/usr/local/www/{$p}"); };
check_api(strpos($f4_page('status_services.php'), '$savemsg = status_services_control($_POST[\'mode\'], $service_name, $_REQUEST);') !== false &&
    !preg_match('/service_control_(start|stop|restart)\(/', $f4_page('status_services.php')) && strpos($f4_page('status_services.php'), 'sleep(5);') !== false,
    'status_services.php controls services through status_services_control() (and still waits 5 s)');
check_api(strpos($f4_page('diag_reboot.php'), 'if (!diag_reboot_run($_POST[\'rebootmode\'])) {') !== false && strpos($f4_page('diag_reboot.php'), '$rebootmodes = diag_reboot_modes();') !== false &&
    !preg_match('/system_reboot|nextboot|notify_all_remote/', $f4_page('diag_reboot.php')) && strpos($f4_page('diag_reboot.php'), "if (g_get('debug')) {") !== false,
    'diag_reboot.php reboots through diag_system.inc (debug guard kept)');
check_api(strpos($f4_page('diag_halt.php'), 'diag_halt_run();') !== false && !preg_match('/system_halt|notify_all_remote/', $f4_page('diag_halt.php')) &&
    strpos($f4_page('diag_halt.php'), "if (g_get('debug')) {") !== false, 'diag_halt.php halts through diag_system.inc (debug guard kept)');
check_api(strpos($f4_page('diag_resetstate.php'), "\$savemsg = diag_resetstate_run(!empty(\$_POST['statetable']), !empty(\$_POST['sourcetracking']));") !== false &&
    !preg_match('/filter_flush_state_table|pfctl/', $f4_page('diag_resetstate.php')), 'diag_resetstate.php resets through diag_system.inc');
$f4_pkgpage = $f4_page('pkg_mgr_install.php');
check_api(strpos($f4_pkgpage, '$started = pkg_mgr_install_start($pkgmode, $pkgname, $firmwareupdate, $_POST[\'fwbranch\'] ?? \'\', $repos);') !== false &&
    !preg_match('/mwexec_bg|write_config\(gettext\("Creating restore point|function waitfor_string_in_file|INFO_PROGRESS_TICK|__RC=/', $f4_pkgpage) &&
    strpos($f4_pkgpage, 'pkg_mgr_install_finish($postlog);') !== false && strpos($f4_pkgpage, 'list($mode, $mode_pkgname) = pkg_mgr_install_read_mode($gui_mode);') !== false,
    'pkg_mgr_install.php starts and polls through pkg_mgr_install.inc (the poll keeps its finished work)');
$f4_diag = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/diag_system.inc");
check_api(strpos($fn_body($f4_diag, 'diag_reboot_run'), "case 'reroot':") !== false && strpos($fn_body($f4_diag, 'diag_reboot_run'), 'default:') !== false,
    'the page keeps its reroot method');

/* API guards: administrators and confirm before anything happens, the dangerous work after the answer, no reroot, no system update by accident */
$f4_ops = file_get_contents("{$root}/src/etc/inc/restapi/routes_operations.inc");
foreach (array('restapi_h_system_reboot' => 'restapi_ops_after_response(', 'restapi_h_system_halt' => 'restapi_ops_after_response(',
    'restapi_pkgop_handle' => 'restapi_pkgop_start(', 'restapi_h_firmware_update' => 'restapi_pkgop_start(') as $fn => $act) {
	$b = $fn_body($f4_ops, $fn);
	check_api(strpos($b, 'restapi_ops_require_admin($req, ') !== false && strpos($b, 'restapi_ops_require_admin($req, ') < strpos($b, 'restapi_ops_confirm($body, ') &&
	    strpos($b, 'restapi_ops_confirm($body, ') < strpos($b, $act), "{$fn}(): administrator, then confirm, then {$act}");
}
foreach (array('restapi_service_control' => 'status_services_control(', 'restapi_h_states_reset' => 'restapi_ops_after_response(') as $fn => $act) {
	$b = $fn_body($f4_ops, $fn);
	check_api(strpos($b, 'restapi_ops_confirm($body, ') !== false && strpos($b, 'restapi_ops_confirm($body, ') < strpos($b, $act), "{$fn}(): confirm before {$act}");
}
check_api(strpos($fn_body($f4_ops, 'restapi_h_system_halt'), "restapi_ops_after_response(RESTAPI_OPS_DELAY, function () {\n\t\tdiag_halt_run();") !== false &&
    substr_count($f4_ops, 'diag_halt_run(') === 1 && substr_count($f4_ops, 'diag_reboot_run(') === 1 && substr_count($f4_ops, 'diag_resetstate_run(') === 1 &&
    strpos($fn_body($f4_ops, 'restapi_h_system_reboot'), "restapi_ops_after_response(RESTAPI_OPS_DELAY, function () use (\$mode) {\n\t\tdiag_reboot_run(\$mode);") !== false &&
    strpos($fn_body($f4_ops, 'restapi_h_states_reset'), "function () use (\$statetable, \$sourcetracking) {\n\t\tdiag_resetstate_run(\$statetable, \$sourcetracking);") !== false,
    'halt, reboot and the state reset run only after the answer');
check_api(strpos($fn_body($f4_ops, 'restapi_h_system_reboot'), '$mode = restapi_ops_reboot_mode($body[\'type\'] ?? null, diag_reboot_fsck_available());') !== false &&
    strpos($f4_ops, 'system_reboot_sync') === false && strpos($f4_ops, "'reroot')") !== false, 'reboot: only the methods restapi_ops_reboot_mode() returns (never reroot)');
$f4_after = $fn_body($f4_ops, 'restapi_ops_after_response');
check_api(strpos($f4_after, 'register_shutdown_function(') !== false && strpos($f4_after, 'ignore_user_abort(true);') < strpos($f4_after, 'fastcgi_finish_request();') &&
    strpos($f4_after, 'fastcgi_finish_request();') < strpos($f4_after, 'sleep($delay);') && strpos($f4_after, 'sleep($delay);') < strpos($f4_after, '$fn();'),
    'the deferred operations run after the response has been sent');
check_api(strpos($fn_body($f4_ops, 'restapi_h_system_reboot'), "if (g_get('debug')) {") < strpos($fn_body($f4_ops, 'restapi_h_system_reboot'), 'restapi_ops_after_response(') &&
    strpos($fn_body($f4_ops, 'restapi_h_system_halt'), "if (g_get('debug')) {") < strpos($fn_body($f4_ops, 'restapi_h_system_halt'), 'restapi_ops_after_response('),
    'reboot and halt keep the pages\' debug guard');
$f4_fw = $fn_body($f4_ops, 'restapi_h_firmware_update');
check_api(strpos($f4_fw, "if ((\$v['pkg_version_compare'] ?? null) !== '<') {") < strpos($f4_fw, 'restapi_pkgop_start(') && strpos($f4_fw, 'restapi_pkgop_not_running();') < strpos($f4_fw, 'restapi_pkgop_start(') &&
    strpos($f4_fw, "restapi_pkgop_start('', '', true)") !== false, 'system update: only when an update is available and nothing runs');
$f4_handle = $fn_body($f4_ops, 'restapi_pkgop_handle');
check_api(strpos($f4_handle, '$name = restapi_pkgop_name($req);') < strpos($f4_handle, 'restapi_pkgop_start(') && strpos($f4_handle, 'restapi_pkgop_not_running();') < strpos($f4_handle, 'restapi_pkgop_start(') &&
    strpos($f4_handle, 'is_vital_system_default_package($name)') < strpos($f4_handle, 'restapi_pkgop_start(') &&
    strpos($f4_handle, "pkg_exec('rquery %n ' . escapeshellarg(\$name), \$out, \$err)") < strpos($f4_handle, 'restapi_pkgop_start('),
    'package operations: valid name, nothing running, never a vital package, install only what the repository has');
check_api(strpos($fn_body($f4_ops, 'restapi_pkgop_name'), "!restapi_pkgop_valid_name(\$name, g_get('pkg_prefix')) || !pkg_mgr_install_name_ok(\$name) || !pkg_valid_name(\$name)") !== false &&
    strpos($fn_body($f4_ops, 'restapi_pkgop_not_running'), 'restapi_pkg_not_busy();') !== false, 'package names pass the API, page and package checks; packagelock is respected');
$f4_status = $fn_body($f4_ops, 'restapi_pkgop_status');
$f4_once = $fn_body($f4_ops, 'restapi_pkgop_finish_once');
check_api(strpos($f4_status, '$completed_now = restapi_pkgop_finish_once($postlog);') !== false && strpos($f4_status, "if (!\$running && \$finish && (\$cur['source'] === 'api')) {") !== false &&
    strpos($f4_status, 'pkg_mgr_install_finish(') === false && substr_count($f4_ops, 'pkg_mgr_install_finish(') === 1 &&
    strpos($f4_once, "lock('restapi-pkgop', LOCK_EX)") < strpos($f4_once, "\$rec['finish_pending'] = false;") &&
    strpos($f4_once, 'restapi_pkgop_record_write($rec);') < strpos($f4_once, 'pkg_mgr_install_finish($postlog);') &&
    strpos($fn_body($f4_ops, 'restapi_pkgop_start'), 'restapi_pkgop_status(20, false)') !== false && strpos($fn_body($f4_ops, 'restapi_pkgop_start'), "'finish_pending' => true,") !== false,
    'the status read does the finished work once (API operations only, after the end, under a lock), the start answer never');
$f4_prev = $fn_body($f4_ops, 'restapi_pkgop_complete_previous');
$f4_st = $fn_body($f4_ops, 'restapi_pkgop_start');
check_api(strpos($f4_prev, "(\$cur['source'] === 'api') && !empty(\$cur['record']['finish_pending']) && !isvalidpid(\$paths['gui_pidfile'])") !== false &&
    strpos($f4_prev, 'restapi_pkgop_finish_once(') !== false && strpos($f4_st, 'restapi_pkgop_complete_previous();') < strpos($f4_st, 'pkg_mgr_install_start(') &&
    substr_count($f4_ops, 'restapi_pkgop_complete_previous()') === 2 && strpos($fn_body($f4_ops, 'restapi_pkgop_not_running'), 'finish') === false,
    'a new operation first completes an ended API operation whose status was never read - only once every check has passed');
check_api(!preg_match('/(?<![a-z_])(mwexec|mwexec_bg|exec|shell_exec|system|passthru|write_config|system_reboot|system_halt|filter_flush_state_table)\(/', $f4_ops) &&
    strpos($f4_ops, 'pfctl') === false && !preg_match('/service_control_(start|stop|restart)\(/', $f4_ops), 'routes_operations.inc acts only through the pages\' functions');
foreach (array('status_services.inc', 'diag_system.inc', 'pkg_mgr_install.inc') as $inc) {
	check_api(strpos(file_get_contents("{$root}/src/usr/local/www/api/index.php"), "require_once('{$inc}');") !== false, "the API front controller loads {$inc}");
}
check_api(strpos($fn_body(file_get_contents("{$root}/src/etc/inc/restapi/routes_v1.inc"), 'restapi_h_status_services'), "foreach (array('mode', 'vpnid', 'zone') as \$field) {") !== false,
    'the services list shows what addresses OpenVPN instances and captive portal zones');

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


/* API Explorer (System > REST API > API Explorer) */
$explorer_page = file_get_contents("{$root}/src/usr/local/www/system_restapi_explorer.php");
$explorer_inc_src = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/restapi_explorer.inc");
check_api(strpos($explorer_page, "##|+PRIV\n##|*IDENT=page-system-restapi-explorer\n") !== false &&
    strpos($explorer_page, "##|*MATCH=system_restapi_explorer.php*\n##|-PRIV") !== false, 'the explorer page has its own privilege');
check_api(strpos($defs, "\$priv_list['page-system-restapi-explorer'] = array();") !== false &&
    strpos($defs, "\$priv_list['page-system-restapi-explorer']['match'][] = \"system_restapi_explorer.php*\";") !== false,
    'the explorer privilege is in priv.defs.inc');
foreach (array('page-system-restapi' => 'system_restapi.php', 'page-system-restapi-keys' => 'system_restapi_keys.php') as $priv => $page) {
	check_api(strpos(file_get_contents("{$root}/src/usr/local/www/{$page}"), "##|*MATCH=system_restapi_explorer.php*") !== false &&
	    strpos($defs, "\$priv_list['{$priv}']['match'][] = \"system_restapi_explorer.php*\";") !== false,
	    "{$priv} also opens the explorer");
	check_api(strpos(file_get_contents("{$root}/src/usr/local/www/{$page}"), "restapi_print_tabs('{$page}'") !== false, "{$page} shows the REST API tabs");
}
check_api(strpos($explorer_page, "restapi_print_tabs('system_restapi_explorer.php', false, \$view)") !== false &&
    strpos(file_get_contents("{$root}/src/usr/local/FreeSense/include/www/restapi_keys.inc"), "'system_restapi_explorer.php' => gettext('API Explorer')") !== false,
    'the API Explorer tab is on every REST API page');
/* The OpenAPI download: after the GUI's authentication and page privilege check, and checked again before any output. */
$dl = substr($explorer_page, strpos($explorer_page, "if ((\$_GET['download'] ?? '') === 'openapi')"));
$dl = substr($dl, 0, strpos($dl, "\n}\n"));
check_api(strpos($explorer_page, 'require_once("guiconfig.inc");') < strpos($explorer_page, "\$_GET['download']") &&
    strpos($dl, "if (!isAllowedPage('system_restapi_explorer.php')) {") !== false &&
    strpos($dl, "isAllowedPage(") < strpos($dl, 'echo $doc;') && strpos($dl, 'restapi_openapi(restapi_routes_v1()') !== false,
    'the openapi.json download is privilege-checked');
/* No server-side capability and no key on the server: the page never reads a key, writes config or files. */
foreach (array('explorer page' => $explorer_page, 'restapi_explorer.inc' => $explorer_inc_src) as $what => $src) {
	check_api(!preg_match('/(?<![a-z_])(write_config|config_set_path|config_del_path|file_put_contents|fopen|fwrite|touch|unlink|' .
	    'restapi_create_token|restapi_revoke_token|setcookie|log_error|logger|syslog|mwexec|exec|shell_exec)\(/', $src),
	    "the {$what} writes nothing on the server");
	check_api(strpos($src, '$_POST') === false && strpos($src, '$_REQUEST') === false && strpos($src, 'HTTP_AUTHORIZATION') === false &&
	    strpos($src, '$_COOKIE') === false, "the {$what} never receives an API key");
}
check_api(strpos($explorer_page, "credentials: 'omit'") !== false && strpos($explorer_page, "'Authorization': 'Bearer ' + apiKey") !== false &&
    strpos($explorer_page, "'/api' + path + (qs.toString()") !== false, 'Try it sends the key only as the Authorization header, without the GUI session cookie');
check_api(substr_count($explorer_page, 'window.sessionStorage.') === 3 && substr_count($explorer_page, 'sessionStorage.setItem(') === 1 && strpos($explorer_page, 'localStorage') === false &&
    strpos($explorer_page, 'storeSet(remember.checked ? apiKey : \'\')') !== false, 'the key is remembered only in sessionStorage and only on request');
check_api(strpos($explorer_page, '"Authorization: Bearer $FREESENSE_API_KEY"') !== false && strpos($explorer_page, 'incKey.checked && apiKey') !== false,
    'copy as curl uses a placeholder unless the user includes the key');
check_api(strpos($explorer_page, "restapi_explorer_model(restapi_routes_v1(), restapi_areas(),") !== false &&
    strpos($explorer_page, '<script type="application/json" id="fx-model"><?=restapi_explorer_json($model)?></script>') !== false &&
    !preg_match('/<\?=\s*\$(ep|group|f)\[\'(summary|path|url|label|reason|page|scope|method)\'\]/', $explorer_page),
    'the explorer renders the route model (escaped) and embeds it as JSON');

set_include_path(get_include_path() . PATH_SEPARATOR . realpath($root . '/src/usr/local/FreeSense/include/www'));
require_once('restapi_explorer.inc');
$fx_seen = array();
$fx_model = restapi_explorer_model($v1, restapi_areas(), function ($r) { return array('priv:' . $r['page']); },
    function ($r, $flags) { return $r['write'] ? 'no writes for this viewer' : ''; });
$fx_n = 0;
foreach ($fx_model['areas'] as $g) {
	check_api(($g['label'] !== '') && ($g['id'] === 'meta' || isset(restapi_areas()[$g['id']])), "explorer area {$g['id']} has a label");
	foreach ($g['endpoints'] as $ep) {
		$fx_n++;
		$fx_seen["{$ep['method']} {$ep['path']}"] = $ep;
		check_api(($ep['area'] === '' ? 'meta' : $ep['area']) === $g['id'], "{$ep['method']} {$ep['path']} is listed under its area");
	}
}
check_api($fx_n === count($v1) && $fx_model['count'] === count($v1) && $fx_model['areas'][0]['id'] === 'meta', 'the explorer lists every route once');
foreach ($v1 as $r) {
	$ep = $fx_seen["{$r['method']} {$r['path']}"] ?? null;
	check_api($ep !== null && $ep['scope'] === $r['scope'] && $ep['page'] === $r['page'] && $ep['url'] === "/api{$r['path']}" &&
	    $ep['summary'] === $r['summary'] && $ep['params'] === $r['params'] && $ep['produces'] === $r['produces'] &&
	    count($ep['query']) === count($r['query']) && $ep['privileges'] === array("priv:{$r['page']}"),
	    "the explorer shows {$r['method']} {$r['path']}");
	check_api($ep['callable'] === !$r['write'] && ($r['write'] ? $ep['reason'] === 'no writes for this viewer' : $ep['reason'] === ''),
	    "the explorer marks whether the viewer may call {$r['method']} {$r['path']}");
	check_api(!$r['write'] || $ep['flags']['kind'] === 'write', "{$r['method']} {$r['path']} is shown as a write");
	if ($ep['example'] !== null) {
		$ex = json_decode($ep['example'], true);
		check_api(is_array($ex) && (!array_key_exists('confirm', $ex) || $ex['confirm'] === false),
		    "the body example of {$r['method']} {$r['path']} is a JSON object and never pre-confirms");
	}
}
$fx = function ($m, $p) use ($fx_seen) { return $fx_seen["{$m} {$p}"]['flags']; };
check_api($fx('POST', '/v1/system/reboot') === array('kind' => 'write', 'confirm' => 'always', 'admin' => true, 'apply' => 'direct', 'destructive' => true) &&
    $fx('POST', '/v1/system/halt')['admin'] && $fx('POST', '/v1/system/firmware/update')['admin'] && $fx('DELETE', '/v1/packages/{name}')['admin'],
    'reboot, halt, the system update and package removal are flagged confirm + administrator');
check_api($fx('PUT', '/v1/system/advanced/admin')['confirm'] === 'conditional' && !$fx('PUT', '/v1/system/advanced/admin')['admin'],
    'admin access changes are flagged as sometimes needing confirm');
check_api($fx('POST', '/v1/interfaces/assignments')['confirm'] === 'always' && $fx('DELETE', '/v1/diagnostics/states')['confirm'] === 'always' &&
    $fx('DELETE', '/v1/diagnostics/states')['destructive'] && $fx('POST', '/v1/diagnostics/states/reset')['destructive'] &&
    $fx('POST', '/v1/config/revisions/{time}/restore')['confirm'] === 'always', 'interface assignments, state kill/reset and restore need confirm');
check_api($fx('POST', '/v1/firewall/aliases')['apply'] === 'staged' && $fx('POST', '/v1/firewall/aliases/apply')['apply'] === 'applies' &&
    $fx('GET', '/v1/firewall/aliases') === array('kind' => 'read', 'confirm' => '', 'admin' => false, 'apply' => '', 'destructive' => false) &&
    !$fx('POST', '/v1/firewall/aliases')['destructive'] && $fx('DELETE', '/v1/firewall/aliases/{name}')['destructive'],
    'staged writes, apply endpoints, reads and deletes are told apart');
check_api($fx('POST', '/v1/diagnostics/dns-lookup')['kind'] === 'read' && $fx_seen['POST /v1/diagnostics/dns-lookup']['callable'],
    'a safe POST lookup is shown as a read');
check_api($fx('GET', '/v1/me')['kind'] === 'read' && $fx_seen['GET /v1/me']['scope'] === '' && $fx_seen['GET /v1/me']['privileges'] === array('priv:@authenticated'),
    'the any-key endpoints have no scope');
$ex = json_decode($fx_seen['POST /v1/firewall/aliases']['example'], true);
check_api($ex['name'] === '' && $ex['type'] === 'host' && $ex['entries'] === array(array('address' => '', 'detail' => '')) && $ex['update_frequency'] === 0,
    'the body example follows the schema (first enum value, typed defaults, one array item)');
check_api(json_decode($fx_seen['POST /v1/system/reboot']['example'], true) === array('confirm' => false, 'type' => 'normal'),
    'the reboot example must be confirmed by hand');
check_api(restapi_explorer_example(array('type' => 'object', 'description' => 'form fields')) instanceof stdClass &&
    restapi_explorer_example(null) === null && restapi_explorer_example(array('type' => 'array', 'items' => array('type' => 'string'))) === array(''),
    'examples of free-form objects and arrays');
/* Embedded JSON: valid, and nothing in the route text can close the <script> element or break out of it. */
$fx_evil = restapi_explorer_model(array(restapi_route('GET', '/v1/evil', 'h', array('page' => 'x.php', 'area' => 'status',
    'summary' => "</script><script>alert(1)</script> & 'x' \"y\" \xff", 'query' => array('q' => '<b>bold</b>')))), restapi_areas());
$fx_json = restapi_explorer_json($fx_evil);
check_api(strpbrk($fx_json, "<>&'") === false && json_decode($fx_json, true)['areas'][0]['endpoints'][0]['query'][0]['description'] === '<b>bold</b>' &&
    strpos(json_decode($fx_json, true)['areas'][0]['endpoints'][0]['summary'], '</script><script>alert(1)</script>') === 0,
    'route text is escaped in the embedded JSON and decodes unchanged');
$fx_full = restapi_explorer_json($fx_model);
check_api(is_array(json_decode($fx_full, true)) && json_last_error() === JSON_ERROR_NONE && strpbrk($fx_full, '<>') === false,
    'the embedded explorer JSON is valid');
$fx_access = restapi_explorer_access_for(null);
check_api($fx_access($v1[0], array()) !== '', 'a non-local GUI user is told keys are for local users only');
echo "REST API smoke test passed.\n";
