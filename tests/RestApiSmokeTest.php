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
	if (strpos($r['path'], '/v1/services/') === 0) {
		$want = preg_match('#^/v1/services/dns-(forwarder|resolver)(/|$)#', $r['path']) ? 'services.dns' :
		    (preg_match('#^/v1/services/ntp(/|$)#', $r['path']) ? 'services.time' :
		    (preg_match('#^/v1/services/(dyndns|rfc2136)/#', $r['path']) ? 'services.ddns' :
		    (preg_match('#^/v1/services/dhcp(v6)?/#', $r['path']) ? 'services.dhcp' : 'services.misc')));
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
