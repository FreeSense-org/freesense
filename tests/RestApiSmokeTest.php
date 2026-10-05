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

/* Static guards */
$front =file_get_contents("{$root}/src/usr/local/www/api/index.php");
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
