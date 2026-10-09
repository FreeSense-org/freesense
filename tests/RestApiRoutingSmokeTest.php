<?php
/*
 * Standalone CI regression test for the WebUI 2.0 API surface of gateways,
 * gateway groups, static routes, Dynamic DNS / RFC 2136 clients and router
 * advertisements (schemas, "fields"/"display", keyed save errors, secrets,
 * the list status helpers shared with the 1.x pages, route wiring); run with
 * `php tests/RestApiRoutingSmokeTest.php`.
 */

$root = dirname(__DIR__);
set_include_path(get_include_path() . PATH_SEPARATOR . realpath($root . '/src/etc/inc'));
if (!function_exists('gettext')) {
	function gettext($t) { return $t; }
}
require_once('restapi/framework.inc');
require_once('restapi/routes_v1.inc');

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

/* The RestApiError of $fn, or null. */
function api_error(callable $fn) {
	try {
		$fn();
	} catch (RestApiError $e) {
		return $e;
	}
	return null;
}

/* The GUI code reads optional config keys without isset(); that is all it may warn about. */
set_error_handler(function ($no, $msg, $file, $line) {
	if (strpos($msg, 'Undefined array key') === 0) {
		return true;
	}
	fwrite(STDERR, "FAIL: PHP warning: {$msg} ({$file}:{$line})\n");
	exit(1);
}, E_WARNING | E_NOTICE | E_DEPRECATED);

/* ---- Config and system stubs ---- */
if (!defined('AF_INET')) {
	define('AF_INET', 2);
	define('AF_INET6', 28);
}
define('DMYPWD', '********');
define('GW_CACHE_DISABLED', 1);
define('GW_CACHE_LOCALHOST', 2);
define('GW_CACHE_INACTIVE', 4);
define('GW_CACHE_INDEXED', 8);
define('GW_CACHE_ALL', 7);
$services_inc = file_get_contents("{$root}/src/etc/inc/services.inc");
foreach (array('DYNDNS_PROVIDER_VALUES', 'DYNDNS_PROVIDER_DESCRIPTIONS') as $const) {
	check(preg_match("/define\('{$const}', '([^']*)'\);/", $services_inc, $m) === 1, "services.inc defines {$const}");
	define($const, $m[1]);
}
$dyndns_split_domain_types = array('cloudflare', 'cloudflare-v6', 'namecheap');
$tmp = sys_get_temp_dir() . '/restapi-routing-' . getmypid();
@mkdir($tmp);

$GLOBALS['config'] = array();
function config_get_path($path, $default = null) {
	$node = $GLOBALS['config'];
	foreach (explode('/', $path) as $key) {
		if (!is_array($node) || !array_key_exists($key, $node)) {
			return $default;
		}
		$node = $node[$key];
	}
	return $node;
}
function config_path_enabled($path, $enable_key = 'enable') {
	$v = config_get_path("{$path}/{$enable_key}");
	return ($v !== null) && ($v !== false);
}
function array_get_path($arr, $path, $default = null) {
	foreach (explode('/', $path) as $key) {
		if (!is_array($arr) || !array_key_exists($key, $arr)) {
			return $default;
		}
		$arr = $arr[$key];
	}
	return $arr;
}
function g_get($key, $default = null) { return ($key === 'conf_path') ? $GLOBALS['tmp'] : $default; }
function get_configured_interface_with_descr($all = false) { return array('wan' => 'WAN', 'lan' => 'LAN', 'opt1' => 'DMZ'); }
function return_gateway_groups_array($fixup = false) { return array('Failover' => array('ipprotocol' => 'inet', 'descr' => 'f')); }
function convert_friendly_interface_to_friendly_descr($if) { return get_configured_interface_with_descr()[$if] ?? strtoupper($if); }
function get_gateways($flags = 0) {
	$all = array(
		'WAN_DHCP' => array('name' => 'WAN_DHCP', 'friendlyiface' => 'wan', 'friendlyifdescr' => 'WAN', 'interface' => 'em0', 'gateway' => '198.51.100.1',
		    'monitor' => '198.51.100.1', 'ipprotocol' => 'inet', 'dynamic' => true, 'attribute' => 'system', 'isdefaultgw' => true, 'descr' => 'Interface wan Gateway'),
		'LTE' => array('name' => 'LTE', 'friendlyiface' => 'opt1', 'friendlyifdescr' => 'DMZ', 'interface' => 'em2', 'gateway' => '192.0.2.1',
		    'monitor' => '9.9.9.9', 'ipprotocol' => 'inet', 'attribute' => 0, 'descr' => 'Backup line'),
		'OLD' => array('name' => 'OLD', 'friendlyiface' => 'lan', 'friendlyifdescr' => 'LAN', 'interface' => 'em1', 'gateway' => '10.0.0.254',
		    'monitor' => '10.0.0.254', 'ipprotocol' => 'inet', 'attribute' => 1, 'disabled' => true, 'descr' => ''),
		'WAN6' => array('name' => 'WAN6', 'friendlyiface' => 'wan', 'friendlyifdescr' => 'WAN', 'interface' => 'em0', 'gateway' => '2001:db8::1',
		    'monitor' => '2001:db8::1', 'ipprotocol' => 'inet6', 'attribute' => 2, 'descr' => 'v6'),
	);
	if (!($flags & GW_CACHE_DISABLED)) {
		unset($all['OLD']);
	}
	return ($flags & GW_CACHE_INDEXED) ? array_values($all) : $all;
}
function refresh_gateways() {}
function return_gateways_status($byname = false) {
	return array('WAN_DHCP' => array('name' => 'WAN_DHCP', 'status' => 'online', 'substatus' => 'none', 'delay' => '4.2ms', 'loss' => '0.0%'),
	    'LTE' => array('name' => 'LTE', 'status' => 'down', 'substatus' => 'highloss', 'delay' => '80ms', 'loss' => '40%'));
}
function build_vip_list($fif, $family = 'all') {
	$list = array('address' => 'Interface Address');
	if ($fif === 'wan') {
		$list['_vip5'] = '198.51.100.10 (Cluster)';
	}
	return $list;
}
function available_default_gateways() {
	return array('v4' => array('' => 'Automatic', 'WAN_DHCP' => 'WAN_DHCP', 'LTE' => 'LTE', 'Failover' => 'Failover (f)', '-' => 'None'),
	    'v6' => array('' => 'Automatic', 'WAN6' => 'WAN6', '-' => 'None'),
	    'defaultgw4' => config_get_path('gateways/defaultgw4', ''), 'defaultgw6' => config_get_path('gateways/defaultgw6', ''));
}
function get_possible_listen_ips() { return array('lan' => 'LAN'); }
function get_failover_interface($if, $family = 'all') { return ($if === 'wan') ? 'em0' : $if; }
function get_interface_ip($if) { return $GLOBALS['ifip4'][$if] ?? null; }
function get_interface_ipv6($if) { return $GLOBALS['ifip6'][$if] ?? null; }
function get_request_source_address($interface, $family = null) {
	$ip = ($family === AF_INET6) ? ($GLOBALS['ifip6'][$interface] ?? null) : ($GLOBALS['ifip4'][$interface] ?? null);
	return ($ip === null) ? array(null, 'no address') : array($ip, null);
}
function is_ipaddrv4($a) { return filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false; }
function is_ipaddrv6($a) { return filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false; }
function is_private_ip($a) { return filter_var($a, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false; }
function is_v6gua(string $a): bool { return is_ipaddrv6($a) && (strncmp(strtolower($a), 'fe80', 4) !== 0); }
function dyndnsCheckIP($interface, $mode = null, $family = null) { check(false, 'reads never ask a check IP service'); }
function dyndns_save_client($post, $id, $dup = false) { $GLOBALS['saved'] = $post; return array('input_errors' => array(), 'id' => (int)$id); }
function rfc2136_save_client($post, $id, $dup = false) { $GLOBALS['saved'] = $post; return array('input_errors' => array(), 'id' => (int)$id); }

/* The real shared code of the 1.x pages. */
$ddns_inc = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/services_dyndns.inc");
foreach (array('dyndns_check_ip_mode_list', 'dyndns_type_list', 'dyndns_build_if_list', 'dyndns_client_hostname', 'dyndns_client_cache_files',
    'dyndns_cache_address', 'dyndns_current_ip', 'dyndns_client_status', 'dyndns_interface_label', 'dyndns_client_settings',
    'rfc2136_key_algos', 'rfc2136_build_us_list', 'rfc2136_source_families', 'rfc2136_record_types', 'rfc2136_client_cache_files',
    'rfc2136_client_status', 'rfc2136_client_settings') as $fn) {
	eval(fn_source($ddns_inc, $fn));
}
$routing_inc = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/system_routing.inc");
foreach (array('routing_gateway_group_triggers', 'routing_gateway_group_keep_states') as $fn) {
	eval(fn_source($routing_inc, $fn));
}
$dhcp_inc = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/services_dhcp.inc");
foreach (array('radvd_ramode_values', 'radvd_rapriority_values') as $fn) {
	eval(fn_source($dhcp_inc, $fn));
}

/* ---- Schemas ---- */
$names = array('routing/gateways' => array('system_gateways_edit.php', 'routing'),
    'routing/gateway_groups' => array('system_gateway_groups_edit.php', 'routing'),
    'routing/static_routes' => array('system_routes_edit.php', 'routing'),
    'routing/default_gateways' => array('system_gateways.php', 'routing'),
    'services/dyndns' => array('services_dyndns_edit.php', 'services.ddns'),
    'services/rfc2136' => array('services_rfc2136_edit.php', 'services.ddns'),
    'services/router_advertisements' => array('services_radvd.php', 'services.dhcp'));
$schemas = array();
foreach ($names as $res => list($page, $area)) {
	$def = restapi_schemas()[$res] ?? null;
	check(is_array($def) && ($def[1] === $page) && ($def[2] === $area), "{$res} is registered with the privilege of {$page} ({$area})");
	$schemas[$res] = restapi_schema_get($res);
	check(is_array($schemas[$res]) && ($schemas[$res]['resource'] === $res) && !empty($schemas[$res]['sections']), "{$res} builds");
	check(json_encode($schemas[$res]) !== false, "{$res} is JSON");
}
$field = function ($res, $name) use ($schemas) {
	foreach ($schemas[$res]['sections'] as $sec) {
		foreach ($sec['fields'] as $f) {
			if ($f['name'] === $name) {
				return $f + array('_section' => $sec['id']);
			}
		}
	}
	return null;
};

$gwf = array_keys(restapi_schema_fields($schemas['routing/gateways']));
sort($gwf);
$want = array('action_disable', 'alert_interval', 'data_payload', 'descr', 'disabled', 'dpinger_dont_add_static_route', 'force_down', 'gateway',
    'gw_down_kill_states', 'interface', 'interval', 'ipprotocol', 'latencyhigh', 'latencylow', 'loss_interval', 'losshigh', 'losslow', 'monitor',
    'monitor_disable', 'name', 'nonlocalgateway', 'time_period', 'weight');
check($gwf === $want, 'gateway schema fields are the edit page\'s form fields');
check($field('routing/gateways', 'weight')['_section'] === 'advanced' && count($field('routing/gateways', 'weight')['options']) === 30 &&
    $field('routing/gateways', 'interval')['placeholder'] === '500', 'the thresholds are advanced, weight 1-30, dpinger defaults as placeholders');
check(array_column($field('routing/gateways', 'interface')['options'], 'value') === array('wan', 'lan', 'opt1'), 'gateway interfaces are the page\'s');
check(array_keys(restapi_schema_fields($schemas['routing/gateway_groups'])) ===
    array('name', 'descr', 'items', 'items.*.gateway', 'items.*.tier', 'items.*.vip', 'trigger', 'keep_failover_states'),
    'gateway group schema: name, descr, members grid {gateway, tier, vip}, trigger, keep_failover_states');
$items = $field('routing/gateway_groups', 'items');
check(array_column($items['fields'][0]['options'], 'value') === array('WAN_DHCP', 'LTE', 'WAN6') &&
    array_column($items['fields'][1]['options'], 'value') === array('1', '2', '3', '4', '5') &&
    array_column($items['fields'][2]['options'], 'value') === array('address', '_vip5'),
    'members: the enabled gateways, tiers 1-5, the interface address and the VIPs of the members\' interfaces');
check(array_column($field('routing/gateway_groups', 'trigger')['options'], 'value') === array('down', 'downloss', 'downlatency', 'downlosslatency') &&
    array_column($field('routing/gateway_groups', 'keep_failover_states')['options'], 'value') === array('', 'keep', 'kill'),
    'trigger and failover state choices come from system_routing.inc');
check(array_keys(restapi_schema_fields($schemas['routing/static_routes'])) === array('network', 'network_subnet', 'gateway', 'disabled', 'descr'),
    'static route schema fields are the edit page\'s');
check(array_column($field('routing/static_routes', 'gateway')['options'], 'label', 'value') ===
    array('WAN_DHCP' => 'WAN_DHCP - 198.51.100.1', 'LTE' => 'LTE - 192.0.2.1', 'OLD' => 'OLD - 10.0.0.254', 'WAN6' => 'WAN6 - 2001:db8::1'),
    'static route gateways are "name - address" including disabled ones, like the page');
check(array_column($field('routing/default_gateways', 'defaultgw4')['options'], 'value') === array('', 'WAN_DHCP', 'LTE', 'Failover', '-') &&
    array_column($field('routing/default_gateways', 'defaultgw6')['options'], 'value') === array('', 'WAN6', '-'),
    'default gateway choices are available_default_gateways()');

$ddf = array_keys(restapi_schema_fields($schemas['services/dyndns']));
check(array_diff($ddf, array_keys(restapi_dyndns_types())) === array() && !in_array('curl_proxy', $ddf, true),
    'every Dynamic DNS schema field is an API field (the proxy only when a proxy is configured)');
$GLOBALS['config']['system']['proxyurl'] = 'proxy.example';
check(in_array('curl_proxy', array_keys(restapi_schema_fields(restapi_schema_get('services/dyndns'))), true), 'with a proxy the "Use proxy" switch shows');
unset($GLOBALS['config']['system']);
check(count($field('services/dyndns', 'type')['options']) === count(explode(' ', DYNDNS_PROVIDER_VALUES)), 'every service type is offered');
$vis = function ($name) use ($field) {
	return $field('services/dyndns', $name)['visibleWhen']['in'] ?? 'always';
};
check(in_array('custom', $vis('updateurl'), true) && !in_array('cloudflare', $vis('updateurl'), true) && $vis('requestif') === array('custom', 'custom-v6'),
    'the update URL and the request interface are for custom entries only');
check(in_array('cloudflare', $vis('domainname'), true) && in_array('namecheap', $vis('domainname'), true) && !in_array('dyndns', $vis('domainname'), true),
    'the domain name shows for split-domain services');
check($vis('proxied') === array('cloudflare', 'cloudflare-v6') && in_array('route53', $vis('zoneid'), true) && !in_array('desec', $vis('username'), true) &&
    in_array('dyndns', $vis('username'), true) && !in_array('custom', $vis('host'), true) && $vis('password') === 'always',
    'per-service fields follow the edit page\'s setVisible()');
check($field('services/dyndns', 'password')['type'] === 'secret' && strpos($field('services/dyndns', 'password')['help'], 'leave it empty to keep') !== false &&
    empty($field('services/dyndns', 'password')['required']), 'the password is a secret, documented as kept when empty');
check(array_diff(array_keys(restapi_schema_fields($schemas['services/rfc2136'])), array_keys(restapi_rfc2136_types())) === array(),
    'every RFC 2136 schema field is an API field');
check($field('services/rfc2136', 'keydata')['type'] === 'secret' && empty($field('services/rfc2136', 'keydata')['required']) &&
    strpos($field('services/rfc2136', 'keydata')['help'], 'leave it empty to keep') !== false, 'the RFC 2136 key is a secret kept when empty');
$raf = array_keys(restapi_schema_fields($schemas['services/router_advertisements']));
check(array_diff(array_filter($raf, function ($n) { return strpos($n, '.*.') === false; }), array_keys(restapi_radvd_types_test())) === array(),
    'every router advertisement schema field is an API field');
check($field('services/router_advertisements', 'rainterface')['optionsFrom'] === 'choices.rainterface' &&
    $field('services/router_advertisements', 'subnets')['type'] === 'entry-grid' && $field('services/router_advertisements', 'radnsserver')['max'] === 4,
    'RA interface choices come from the item; subnets and DNS servers are grids');

/* The RA API field types without config access (pref64_prefix needs the override). */
function restapi_radvd_types_test() {
	$GLOBALS['config']['system']['allow_nat64_prefix_override'] = true;
	$t = restapi_radvd_types('lan');
	unset($GLOBALS['config']['system']);
	return $t;
}

/* ---- Save messages land on their fields ---- */
$gwlb = file_get_contents("{$root}/src/etc/inc/gwlb.inc");
$util = file_get_contents("{$root}/src/etc/inc/util.inc");
$sources = array('gwlb.inc' => $gwlb, 'system_routing.inc' => $routing_inc, 'services_dyndns.inc' => $ddns_inc, 'services_dhcp.inc' => $dhcp_inc,
    'routes_routing.inc' => file_get_contents("{$root}/src/etc/inc/restapi/routes_routing.inc"), 'util.inc' => $util);
$cases = array(
	'routing/gateways' => array('gwlb.inc', array(
		array('The field %s is required.', array('Name'), 'name', 'util.inc'),
		array('The field %s is required.', array('Interface'), 'interface', 'util.inc'),
		array('A valid gateway name must be specified.', null, 'name'),
		array('The %1$s name must be less than 32 characters long, may not consist of only numbers, may not consist of only underscores, and may only contain the following characters: %2$s',
		    array('gateway', 'a-z, A-Z, 0-9, _'), 'name', 'util.inc'),
		array('Gateway "%1$s" cannot be disabled because it is in use on Gateway Group "%2$s"', array('LTE', 'Failover'), 'disabled'),
		array('Gateway "%1$s" cannot be disabled because it is in use on Static Route "%2$s"', array('LTE', '10.0.0.0/8'), 'disabled'),
		array('A valid gateway IP address must be specified.', null, 'gateway'),
		array('Cannot add IPv4 Gateway Address because no IPv4 address could be found on the interface.', null, 'gateway'),
		array("The gateway address %s does not lie within one of the chosen interface's subnets.", array('10.9.9.9'), 'gateway'),
		array('Dynamic gateway values cannot be specified for interfaces with a static IPv6 configuration.', null, 'gateway'),
		array('A valid data payload must be specified.', null, 'data_payload'),
		array('Please select a valid State Killing on Gateway Failure mode.', null, 'gw_down_kill_states'),
		array("The IPv6 gateway address '%s' can not be used as a IPv4 gateway.", array('2001:db8::9'), 'gateway'),
		array("The IPv4 monitor address '%s' can not be used on a IPv6 gateway.", array('192.0.2.9'), 'monitor'),
		array('Changing name on a gateway is not allowed.', null, 'name'),
		array('The gateway name "%s" already exists.', array('LTE'), 'name'),
		array('The gateway IP address "%s" already exists.', array('192.0.2.1'), 'gateway'),
		array('The monitor IP address "%s" is already in use. A different monitor IP must be chosen.', array('9.9.9.9'), 'monitor'),
		array('The low latency threshold needs to be a numeric value.', null, 'latencylow'),
		array('The high latency threshold needs to be positive.', null, 'latencyhigh'),
		array('The low Packet Loss threshold needs to be less than 100.', null, 'losslow'),
		array('The high Packet Loss threshold needs to be 100 or less.', null, 'losshigh'),
		array('The time period over which results are averaged needs to be positive.', null, 'time_period'),
		array('The probe interval needs to be a numeric value.', null, 'interval'),
		array('The loss interval setting needs to be positive.', null, 'loss_interval'),
		array('The alert interval needs to be a numeric value.', null, 'alert_interval'),
		array('The high latency threshold needs to be greater than the low latency threshold', null, 'latencyhigh'),
		array('The high packet loss threshold needs to be higher than the low packet loss threshold', null, 'losshigh'),
		array('The loss interval needs to be greater than or equal to the high latency threshold.', null, 'loss_interval'),
		array('The time period needs to be greater than twice the probe interval plus the loss interval.', null, 'time_period'),
		array('The alert interval needs to be greater than or equal to the probe interval.', null, 'alert_interval'),
	)),
	'routing/gateway_groups' => array('system_routing.inc', array(
		array('The field %s is required.', array('Name'), 'name', 'util.inc'),
		array('A valid gateway group name must be specified.', null, 'name'),
		array('The %1$s name must not be an IP protocol name such as TCP, UDP, ICMP etc.', array('gateway group'), 'name', 'util.inc'),
		array('Changing name on a gateway group is not allowed.', null, 'name'),
		array('A gateway group with this name "%s" already exists.', array('Failover'), 'name'),
		array('A gateway group cannot have the same name as a gateway "%s" please choose another name.', array('LTE'), 'name'),
		array('No gateway(s) have been selected to be used in this group', null, 'items'),
		array('\" is not an enabled gateway.', null, 'items.0.gateway', 'routes_routing.inc', '"NOPE_GW" is not an enabled gateway.'),
	)),
	'routing/static_routes' => array('system_routing.inc', array(
		array('The field %s is required.', array('Destination network'), 'network', 'util.inc'),
		array('The field %s is required.', array('Destination network bit count'), 'network_subnet', 'util.inc'),
		array('The field %s is required.', array('Gateway'), 'gateway', 'util.inc'),
		array('A valid IPv4 or IPv6 destination network or an alias must be specified.', null, 'network'),
		array('A valid destination network bit count must be specified.', null, 'network_subnet'),
		array('A valid gateway must be specified.', null, 'gateway'),
		array('The gateway is disabled but the route is not. The route must be disabled in order to choose a disabled gateway.', null, 'gateway'),
		array('The gateway "%1$s" is a different Address Family than network "%2$s".', array('192.0.2.1', '2001:db8::'), 'gateway'),
		array('A IPv4 subnet can not be over 32 bits.', null, 'network_subnet'),
		array('A route to these destination networks already exists', null, 'network'),
		array('This network conflicts with address configured on interface %s.', array('LAN'), 'network'),
	)),
	'routing/default_gateways' => array('routes_routing.inc', array(
		array('\"{$field}\" must be one of: ', null, 'defaultgw4', 'routes_routing.inc', '"defaultgw4" must be one of: "" (automatic), WAN_DHCP'),
	)),
	'services/dyndns' => array('services_dyndns.inc', array(
		array('The field %s is required.', array('Service type'), 'type', 'util.inc'),
		array('The field %s is required.', array('Hostname'), 'host', 'util.inc'),
		array('The field %s is required.', array('Password'), 'password', 'util.inc'),
		array('The field %s is required.', array('Username'), 'username', 'util.inc'),
		array('The field %s is required.', array('Domain name'), 'domainname', 'util.inc'),
		array('The field %s is required.', array('Update URL'), 'updateurl', 'util.inc'),
		array('Password and confirmed password must match.', null, 'password'),
		array('The specified option for Check IP Mode is invalid.', null, 'check_ip_mode'),
		array('The specified Service Type is invalid.', null, 'type'),
		array('A valid interface to monitor must be selected.', null, 'interface'),
		array('A valid interface to send the update from must be selected.', null, 'requestif'),
		array('The hostname contains invalid characters.', null, 'host'),
		array('The MX contains invalid characters.', null, 'mx'),
		array('The username contains invalid characters.', null, 'username'),
		array('The max cache age must be an integer and greater than 0 (or empty for the default).', null, 'maxcacheage'),
	)),
	'services/rfc2136' => array('services_dyndns.inc', array(
		array('The field %s is required.', array('Hostname'), 'host', 'util.inc'),
		array('The field %s is required.', array('TTL'), 'ttl', 'util.inc'),
		array('The field %s is required.', array('Key name'), 'keyname', 'util.inc'),
		array('The field %s is required.', array('Key'), 'keydata', 'util.inc'),
		array('The DNS update host name contains invalid characters.', null, 'host'),
		array('The DNS zone name contains invalid characters.', null, 'zone'),
		array('The DNS update TTL must be an integer.', null, 'ttl'),
		array('The DNS update key name contains invalid characters.', null, 'keyname'),
		array('The DNS update key algorithm is invalid.', null, 'keyalgorithm'),
		array('The DNS update key contains an invalid character (quote, backslash or line break).', null, 'keydata'),
		array('The DNS update server must be an IP address or host name, optionally followed by a space and a port.', null, 'server'),
		array('A valid interface must be selected.', null, 'interface'),
		array('A valid update source must be selected.', null, 'updatesource'),
		array('A valid update source family must be selected.', null, 'updatesourcefamily'),
		array('A valid record type must be selected.', null, 'recordtype'),
	)),
	'services/router_advertisements' => array('services_dhcp.inc', array(
		array('Invalid Router Mode.', null, 'ramode'),
		array('Invalid Router Priority.', null, 'rapriority'),
		array('Invalid RA Interface.', null, 'rainterface'),
		array('Router Advertisements can only be enabled on interfaces configured with static IPv6 or Track Interface.', null, 'ramode'),
		array('An invalid subnet or alias was specified. [%1$s/%2$s]', array('2001:zz::', '64'), 'subnets.0.subnet'),
		array('A valid IPv6 address must be specified for each of the DNS servers.', null, 'radnsserver'),
		array('A valid domain search list must be specified.', null, 'radomainsearchlist'),
		array('A valid lifetime below 2 hours will be ignored by clients (RFC 4862 Section 5.5.3 point e)', null, 'ravalidlifetime'),
		array('Valid lifetime must be an integer.', null, 'ravalidlifetime'),
		array('Minimum advertisement interval must be no less than 3.', null, 'raminrtradvinterval'),
		array('Minimum advertisement interval must be no greater than 0.75 * Maximum advertisement interval', null, 'raminrtradvinterval'),
		array('Maximum advertisement interval must be no less than 4 and no greater than 1800.', null, 'ramaxrtradvinterval'),
		array('Default preferred lifetime must be an integer.', null, 'rapreferredlifetime'),
		array('Router lifetime must be an integer between 0 and 9000.', null, 'raadvdefaultlifetime'),
		array('Default valid lifetime must be greater than Default preferred lifetime.', null, 'ravalidlifetime'),
		array('NAT64 Prefix Lifetime must be from 1 to 65528.', null, 'pref64_lifetime'),
	)),
);
$bodies = array('routing/gateway_groups' => array('items' => array(array('gateway' => 'NOPE_GW', 'tier' => '1', 'vip' => 'address'))),
    'services/router_advertisements' => array('subnets' => array(array('subnet' => '2001:zz::/64'))));
foreach ($cases as $res => list($src, $list)) {
	foreach ($list as $case) {
		list($template, $args, $want) = $case;
		$in = $case[3] ?? $src;
		check(strpos($sources[$in], $template) !== false, "{$in} still says \"{$template}\"");
		$msg = $case[4] ?? ($args ? vsprintf($template, $args) : $template);
		$r = restapi_errors_to_fields(array($msg), $schemas[$res], $bodies[$res] ?? array());
		check(array_keys($r['fields']) === array($want), "{$res}: \"{$msg}\" lands on {$want}" . (empty($r['fields']) ? ' (unmatched)' : ' (got ' . key($r['fields']) . ')'));
	}
}

/* ---- Gateways: display and status ---- */
$gws = get_gateways(GW_CACHE_ALL);
$d = restapi_routing_gateway_display($gws['WAN_DHCP'], return_gateways_status(true));
check($d === array('interface' => 'WAN', 'address' => 'dynamic', 'monitor' => '198.51.100.1', 'family' => 'IPv4', 'default' => true, 'enabled' => true,
    'inactive' => false, 'description' => 'Interface wan Gateway', 'status' => array('state' => 'online', 'substate' => 'none', 'delay' => '4.2ms', 'loss' => '0.0%')),
    'gateway display: interface label, "dynamic", monitor, family, default, enabled, description and dpinger status');
$d = restapi_routing_gateway_display($gws['OLD'], return_gateways_status(true));
check($d['address'] === '10.0.0.254' && $d['enabled'] === false && $d['default'] === false && $d['status']['state'] === 'unknown' && $d['status']['delay'] === '',
    'a disabled, unmonitored gateway reads as disabled with an unknown state');
check(restapi_routing_gateway_display($gws['WAN6'], array())['family'] === 'IPv6', 'IPv6 gateways read as IPv6');
$out = restapi_gateway_out($gws['LTE'], return_gateways_status(true));
check(isset($out['fields'], $out['display'], $out['name'], $out['editable']) && $out['display']['status']['state'] === 'down' &&
    $out['fields']['interface'] === 'opt1' && $out['fields']['monitor'] === '9.9.9.9', 'a gateway keeps its keys and adds "fields" and "display"');

/* ---- Gateway groups ---- */
$group = array('name' => 'Failover', 'descr' => 'f', 'trigger' => 'downloss', 'item' => array('WAN_DHCP|1|address', 'LTE|2|', 'OLD|3|address'));
$gd = restapi_routing_group_display($group, array_keys(get_gateways()), routing_gateway_group_triggers());
check($gd === array('members' => array('WAN_DHCP (tier 1)', 'LTE (tier 2)'), 'trigger' => 'Packet Loss', 'description' => 'f'),
    'group display: existing members with their tier (like the list page), the trigger\'s label');
$gf = restapi_routing_group_fields($group);
check($gf['items'][1] === array('gateway' => 'LTE', 'tier' => '2', 'vip' => 'address') && $gf['trigger'] === 'downloss',
    'group fields: tiers as select values, no VIP = the interface address');
$post = restapi_routing_group_post(restapi_routing_body($gf + array('fields' => array(), 'display' => array())), array('WAN_DHCP', 'LTE', 'OLD'));
check($post['LTE'] === '2' && $post['LTE_vip'] === 'address' && $post['name'] === 'Failover', 'the fields are a valid save body (read-only keys dropped)');
check(routing_gateway_group_triggers()['down'] === 'Member Down', 'the trigger labels moved into system_routing.inc');
$ggpage = file_get_contents("{$root}/src/usr/local/www/system_gateway_groups_edit.php");
check(strpos($ggpage, '$categories = routing_gateway_group_triggers();') !== false && strpos($ggpage, 'routing_gateway_group_keep_states()') !== false &&
    strpos($ggpage, "'downlosslatency' =>") === false, 'the group edit page takes its choices from system_routing.inc');

/* ---- Static routes ---- */
$rd = restapi_routing_route_display(array('network' => '10.20.0.0/16', 'gateway' => 'LTE', 'descr' => 'branch'), $gws, 'convert_friendly_interface_to_friendly_descr');
check($rd === array('network' => '10.20.0.0/16', 'gateway' => 'LTE - 192.0.2.1', 'interface' => 'DMZ', 'enabled' => true, 'inactive' => false,
    'description' => 'branch'), 'static route display: network, "name - address", the gateway\'s interface');
$rd = restapi_routing_route_display(array('network' => 'BRANCH_NETS', 'gateway' => 'GONE', 'disabled' => ''), $gws, 'convert_friendly_interface_to_friendly_descr');
check($rd['gateway'] === 'GONE' && $rd['interface'] === '' && $rd['enabled'] === false && $rd['network'] === 'branch_nets',
    'a route to a missing gateway shows its name; disabled routes read as disabled');
check(restapi_body_over(array('descr' => 'n', 'disabled' => false), array('descr' => 'o', 'disabled' => 'yes', 'network' => '10.0.0.0')) ===
    array('descr' => 'n', 'network' => '10.0.0.0'), 'updates merge with restapi_body_over() (false clears a checkbox)');

/* ---- Default gateways ---- */
$GLOBALS['config']['gateways']['defaultgw4'] = 'LTE';
$dg = restapi_default_gateways_out();
check($dg['fields'] === array('defaultgw4' => 'LTE', 'defaultgw6' => '') && isset($dg['choices']), 'default gateways carry their form fields');

/* ---- Dynamic DNS: status, display, fields, secrets ---- */
$GLOBALS['ifip4'] = array('wan' => '203.0.113.7', 'lan' => '10.0.0.1', 'em0' => '203.0.113.7');
$GLOBALS['ifip6'] = array('wan' => '2001:db8:1::7', 'em0' => '2001:db8:1::7');
$c1 = array('id' => 0, 'type' => 'cloudflare', 'host' => 'home', 'domainname' => 'example.org', 'interface' => 'wan', 'requestif' => 'wan', 'username' => '',
    'password' => base64_encode('S3cretDDNS'), 'enable' => true, 'descr' => 'Home');
$c2 = array('id' => 1, 'type' => 'dyndns', 'host' => 'lab.example.net', 'interface' => 'lan', 'requestif' => 'lan', 'password' => base64_encode('Other'), 'descr' => '');
$GLOBALS['config']['dyndnses']['dyndns'] = array($c1, $c2);
list($f4) = dyndns_client_cache_files($c1);
check(dyndns_client_status($c1, false) === array('status' => 'unknown', 'family' => null, 'cached_ip' => null, 'cached_ipv6' => null),
    'no cache file: "unknown" (the page\'s "Not updated yet")');
file_put_contents($f4, '203.0.113.7|1700000000');
check(dyndns_client_status($c1, false)['status'] === 'ok', 'cached address = public interface address: ok');
file_put_contents($f4, '203.0.113.99|1700000000');
check(dyndns_client_status($c1, false) === array('status' => 'fail', 'family' => 'inet', 'cached_ip' => '203.0.113.99', 'cached_ipv6' => null),
    'a different cached address: fail');
list($f4b, $f6b) = dyndns_client_cache_files($c2);
file_put_contents($f6b, '2001:db8:1::7|1');
check(dyndns_client_status($c2, false)['status'] === 'fail' && dyndns_client_status($c2, false)['family'] === 'inet6',
    'only an IPv6 cache: the IPv6 address is compared; no address on the interface fails, like the page');
$GLOBALS['ifip6']['lan'] = '2001:db8:1::7';
check(dyndns_client_status($c2, false)['status'] === 'ok', 'a global IPv6 interface address is compared without a check IP service');
file_put_contents($f4b, '198.51.100.5|1');
check(dyndns_client_status($c2, false)['status'] === 'unknown', 'a private interface address needs a check IP service: unknown');
check(dyndns_current_ip('lan', 'never', AF_INET, false) === '10.0.0.1', '"never" uses the interface address');
check(dyndns_interface_label('wan_stf', true) === 'WAN' && dyndns_interface_label('Failover') === 'Failover', 'interface labels like the list pages');

$out = restapi_dyndns_out(0);
check($out['display'] === array('service' => 'Cloudflare', 'hostname' => 'home.example.org', 'interface' => 'WAN', 'cached_ip' => '203.0.113.99',
    'status' => 'fail', 'enabled' => true, 'description' => 'Home'), 'Dynamic DNS display: service label, full host name, interface, cached IP, status');
check($out['password'] === '(set)' && $out['fields']['password'] === '' && strpos(json_encode($out), 'S3cret') === false &&
    strpos(json_encode($out), base64_encode('S3cretDDNS')) === false, 'the password is never returned');
check($out['fields']['enable'] === true && $out['fields']['type'] === 'cloudflare' && !isset($out['fields']['id'], $out['fields']['cached_ip']),
    'fields are the editable API fields');
restapi_h_dyndns_update(array('params' => array('id' => '0'), 'body' => $out['fields'] + array('display' => array(), 'fields' => array())));
check($GLOBALS['saved']['passwordfld'] === DMYPWD && $GLOBALS['saved']['passwordfld_confirm'] === DMYPWD, 'an update with an empty password keeps the stored one');
restapi_h_dyndns_update(array('params' => array('id' => '0'), 'body' => array('password' => '(set)')));
check($GLOBALS['saved']['passwordfld'] === DMYPWD, '"(set)" keeps the password');
restapi_h_dyndns_update(array('params' => array('id' => '0'), 'body' => array('descr' => 'x')));
check($GLOBALS['saved']['passwordfld'] === DMYPWD && $GLOBALS['saved']['descr'] === 'x', 'an omitted password is kept');
restapi_h_dyndns_update(array('params' => array('id' => '0'), 'body' => array('password' => 'N3w')));
check($GLOBALS['saved']['passwordfld'] === 'N3w', 'a new password is saved');
restapi_h_dyndns_create(array('body' => array('type' => 'custom', 'updateurl' => 'https://x', 'password' => '')));
check($GLOBALS['saved']['passwordfld'] === '', 'a new client with an empty password posts it empty');
$list = restapi_h_dyndns_list(array())['data'];
check(count($list) === 2 && $list[1]['display']['enabled'] === false && $list[1]['display']['service'] === 'DynDNS (dynamic)', 'the list carries display for every client');

/* RFC 2136 */
$r1 = array('enable' => true, 'host' => 'fw.example.org', 'server' => '192.0.2.53', 'interface' => 'wan', 'keyname' => 'k', 'keyalgorithm' => 'hmac-sha256',
    'keydata' => 'S3cretKey==', 'ttl' => '60', 'recordtype' => '', 'descr' => 'Primary');
$GLOBALS['config']['dnsupdates']['dnsupdate'] = array($r1);
list($rf4, $rf6) = rfc2136_client_cache_files($r1);
file_put_contents($rf4, '203.0.113.7|1');
file_put_contents($rf6, '2001:db8:1::8|1');
$st = rfc2136_client_status($r1, false);
check($st['status'] === 'ok' && $st['status_ipv4'] === 'ok' && $st['status_ipv6'] === 'fail', 'RFC 2136: IPv4 decides the status, each cache has its own');
check(rfc2136_client_status($r1 + array('usepublicip' => true), false)['status'] === 'ok', 'a public interface address needs no check IP service');
$GLOBALS['ifip4']['em0'] = '10.1.1.1';
check(rfc2136_client_status($r1 + array('usepublicip' => true), false)['status'] === 'unknown', 'a private address with "use public IP": unknown');
$GLOBALS['ifip4']['em0'] = '203.0.113.7';
$out = restapi_rfc2136_out(0);
check($out['display'] === array('interface' => 'WAN', 'server' => '192.0.2.53', 'hostname' => 'fw.example.org', 'record_type' => 'Both',
    'cached_ip' => '203.0.113.7', 'cached_ipv6' => '2001:db8:1::8', 'status_ipv4' => 'ok', 'status_ipv6' => 'fail', 'status' => 'ok',
    'enabled' => true, 'description' => 'Primary'), 'RFC 2136 display');
check($out['keydata'] === '(set)' && $out['fields']['keydata'] === '' && strpos(json_encode($out), 'S3cretKey') === false, 'the key is never returned');
restapi_h_rfc2136_update(array('params' => array('id' => '0'), 'body' => $out['fields']));
check($GLOBALS['saved']['keydata'] === 'S3cretKey==', 'an update with an empty key keeps the stored one');
restapi_h_rfc2136_update(array('params' => array('id' => '0'), 'body' => array('keydata' => 'New==')));
check($GLOBALS['saved']['keydata'] === 'New==', 'a new key is saved');

/* The 1.x list pages use the shared status helpers */
foreach (array('services_dyndns.php' => array('dyndns_client_status($dyndns)', 'dyndns_interface_label($dyndns[\'interface\'], true)'),
    'services_rfc2136.php' => array('rfc2136_client_status($rfc2136)', 'dyndns_interface_label($rfc2136[\'interface\'])')) as $page => $calls) {
	$src = file_get_contents("{$root}/src/usr/local/www/{$page}");
	foreach ($calls as $call) {
		check(strpos($src, $call) !== false, "{$page} uses {$call}");
	}
	check(strpos($src, 'dyndnsCheckIP(') === false && strpos($src, 'file_get_contents(') === false, "{$page} reads the caches through services_dyndns.inc");
}
check(strpos(fn_source($ddns_inc, 'dyndns_current_ip'), 'return dyndnsCheckIP($interface, $mode, $address_family);') !== false,
    'with $remote (the pages) the status asks dyndnsCheckIP() exactly like before');

/* ---- Router advertisements ---- */
$vals = array('ramode' => 'unmanaged', 'rapriority' => 'high', 'subnets' => array('2001:db8:5::/64', 'lan_nets'), 'radnsserver' => array('2001:db8::53'));
$rf = restapi_radvd_fields($vals);
check($rf['subnets'] === array(array('subnet' => '2001:db8:5::/64'), array('subnet' => 'lan_nets')) && $rf['radnsserver'] === array(array('server' => '2001:db8::53')),
    'RA fields: subnets and DNS servers as grid rows');
check(restapi_radvd_body($rf) === $vals + array() && restapi_radvd_body(array('subnets' => array('a/64'))) === array('subnets' => array('a/64')) &&
    restapi_radvd_body(array('radnsserver' => array(array('server' => ' ')))) === array('radnsserver' => array()),
    'RA bodies take grid rows or plain lists (empty rows dropped)');
$rd = restapi_radvd_display('LAN', $vals, radvd_ramode_values(), radvd_rapriority_values(), true);
check($rd === array('interface' => 'LAN', 'mode' => 'Unmanaged', 'priority' => 'High', 'dhcpv6' => 'Enabled, not advertised', 'enabled' => true),
    'RA display: short mode name, priority, a DHCPv6 server the mode does not advertise');
check(restapi_radvd_display('LAN', array('ramode' => 'managed', 'rapriority' => 'medium'), radvd_ramode_values(), radvd_rapriority_values(), true)['dhcpv6'] === 'Enabled' &&
    restapi_radvd_display('LAN', array('ramode' => 'disabled', 'rapriority' => 'medium'), radvd_ramode_values(), radvd_rapriority_values(), false) ===
    array('interface' => 'LAN', 'mode' => 'Disabled', 'priority' => 'Normal', 'dhcpv6' => 'Disabled', 'enabled' => false), 'RA display of managed and disabled modes');
check(isset(restapi_radvd_types('lan')['fields'], restapi_radvd_types('lan')['display']) && restapi_radvd_types('lan')['fields'] === 'ro',
    'a GET result can be sent back (fields/display are read-only)');

/* ---- Route wiring ---- */
$v1 = restapi_routes_v1();
$by = array();
foreach ($v1 as $r) {
	$by["{$r['method']} {$r['path']}"] = $r;
}
foreach (array('POST /v1/routing/gateways' => 'routing/gateways', 'PUT /v1/routing/gateways/{name}' => 'routing/gateways',
    'POST /v1/routing/gateway-groups' => 'routing/gateway_groups', 'PUT /v1/routing/gateway-groups/{name}' => 'routing/gateway_groups',
    'POST /v1/routing/static-routes' => 'routing/static_routes', 'PUT /v1/routing/static-routes/{id}' => 'routing/static_routes',
    'PUT /v1/routing/default-gateways' => 'routing/default_gateways',
    'POST /v1/services/dyndns/clients' => 'services/dyndns', 'PUT /v1/services/dyndns/clients/{id}' => 'services/dyndns',
    'POST /v1/services/rfc2136/clients' => 'services/rfc2136', 'PUT /v1/services/rfc2136/clients/{id}' => 'services/rfc2136',
    'PUT /v1/services/router-advertisements/{if}' => 'services/router_advertisements') as $key => $schema) {
	check(isset($by[$key]) && $by[$key]['schema'] === $schema, "{$key} keys its errors with {$schema}");
	check(isset(restapi_schemas()[$schema]) && (restapi_schemas()[$schema][2] === $by[$key]['area']), "{$schema} is in the area of {$key}");
}
foreach (array('GET /v1/routing/gateways', 'GET /v1/routing/gateway-groups', 'GET /v1/routing/static-routes', 'GET /v1/services/dyndns/clients',
    'GET /v1/services/rfc2136/clients', 'GET /v1/services/router-advertisements', 'GET /v1/services/router-advertisements/{if}',
    'POST /v1/services/dyndns/clients/{id}/toggle', 'POST /v1/services/dyndns/clients/{id}/update', 'DELETE /v1/services/dyndns/clients/{id}',
    'POST /v1/services/rfc2136/clients/{id}/toggle', 'POST /v1/services/rfc2136/clients/{id}/update', 'DELETE /v1/services/rfc2136/clients/{id}',
    'POST /v1/routing/gateways/{name}/toggle', 'POST /v1/routing/static-routes/{id}/toggle', 'POST /v1/routing/apply') as $key) {
	check(isset($by[$key]), "route {$key} exists");
}
list($route, $params) = restapi_match($v1, 'GET', '/v1/services/router-advertisements');
check($route['handler'] === 'restapi_h_radvd_list' && $route['area'] === 'services.dhcp' && $route['page'] === 'services_radvd.php',
    'the RA list needs the Router Advertisement page');
list($route, $params) = restapi_match($v1, 'GET', '/v1/routing/pending');
check($route['handler'] === 'restapi_h_routing_pending' && $route['area'] === 'routing' && $route['page'] === 'system_gateways.php' && !$route['write'],
    'GET /v1/routing/pending reads with the gateway list\'s privilege');
function is_subsystem_dirty($s) { return ($s === 'staticroutes') && !empty($GLOBALS['dirty']); }
$GLOBALS['dirty'] = false;
check(restapi_h_routing_pending(array()) === array('data' => array('pending' => false)), 'pending: false when nothing is staged');
$GLOBALS['dirty'] = true;
check(restapi_h_routing_pending(array()) === array('data' => array('pending' => true)), 'pending: true while the staticroutes flag is dirty');
check(in_array('routing-v2', restapi_capabilities_test($root), true), 'capability routing-v2 is announced');
function restapi_capabilities_test($root) {
	$src = file_get_contents("{$root}/src/etc/inc/restapi.inc");
	eval(fn_source($src, 'restapi_capabilities'));
	return restapi_capabilities();
}

array_map('unlink', glob("{$tmp}/*"));
@rmdir($tmp);
echo "REST API routing smoke test passed.\n";
