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
		$want = (strpos($r['path'], '/v1/services/dns-forwarder') === 0) ? 'services.dns' : 'services.misc';
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
