<?php
/*
 * Standalone CI regression test for the DHCP server API of the WebUI 2.0
 * (schemas, keyed validation errors, "fields"/"display" items, the
 * /servers routes and the relay and settings forms); run with
 * `php tests/RestApiDhcpSmokeTest.php`.
 *
 * The form functions of services_dhcp.inc the API reads through
 * (dhcp_iface_context(), dhcp_server_form(), dhcp_staticmap_form(), ...) are
 * loaded from the include itself; the saves are stubs that record the POST
 * the API builds.
 */

$root = dirname(__DIR__);
set_include_path(get_include_path() . PATH_SEPARATOR . realpath($root . '/src/etc/inc'));
if (!function_exists('gettext')) {
	function gettext($t) { return $t; }
}
require_once('restapi/framework.inc');
require_once('restapi/routes_v1.inc');

/* The GUI form code reads optional keys without isset(); that is all it may warn about. */
set_error_handler(function ($no, $msg, $file, $line) {
	if ((strpos($msg, 'Undefined array key') === 0) || (strpos($msg, 'Trying to access array offset on') === 0) ||
	    ((strpos($file, "eval()'d code") !== false) && (strpos($msg, 'Passing null to parameter') !== false))) {
		return true;
	}
	fwrite(STDERR, "FAIL: PHP warning: {$msg} ({$file}:{$line})\n");
	exit(1);
}, E_WARNING | E_NOTICE | E_DEPRECATED);

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

/* The RestApiError $fn raises, or null. */
function api_error(callable $fn) {
	try {
		$fn();
	} catch (RestApiError $e) {
		return $e;
	}
	return null;
}

/* ---- Config and system stubs ---- */
$GLOBALS['config'] = array(
	'system' => array('domain' => 'home.arpa', 'dnsserver' => array('9.9.9.9', '2620:fe::fe')),
	'interfaces' => array(
		'wan' => array('ipaddr' => 'dhcp', 'ipaddrv6' => 'dhcp6'),
		'lan' => array('ipaddr' => '192.168.1.1', 'subnet' => '24', 'ipaddrv6' => 'track6', 'track6-interface' => 'wan'),
		'opt1' => array('ipaddr' => '10.0.0.1', 'subnet' => '31'),
	),
	'dhcpd' => array('lan' => array(
		'enable' => '', 'range' => array('from' => '192.168.1.100', 'to' => '192.168.1.199'),
		'dnsserver' => array('192.168.1.1', '1.1.1.1'), 'denyunknown' => 'class', 'ddnsdomainkey' => 'c2VjcmV0',
		'pool' => array(array('range' => array('from' => '192.168.1.210', 'to' => '192.168.1.220'), 'descr' => 'IoT')),
		'staticmap' => array(
			array('mac' => '00:11:22:33:44:55', 'ipaddr' => '192.168.1.10', 'hostname' => 'nas', 'descr' => 'Storage',
			    'arp_table_static_entry' => '', 'dnsserver' => array('192.168.1.1')),
			array('mac' => '00:11:22:33:44:66', 'cid' => 'printer-1', 'ipaddr' => '', 'hostname' => '', 'descr' => ''),
		),
	)),
	'dhcpdv6' => array('lan' => array('enable' => '', 'range' => array('from' => '::1000', 'to' => '::2000'), 'ramode' => 'assist',
	    'staticmap' => array(array('duid' => '00:01:00:01:aa:bb:cc:dd:ee:ff', 'ipaddrv6' => '::10', 'hostname' => 'nas6', 'pdprefix' => '2001:db8:42::/56')))),
	'unbound' => array('enable' => ''),
);
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
function config_path_enabled($path, $key = 'enable') {
	$node = config_get_path($path);
	return is_array($node) && isset($node[$key]);
}
function array_get_path($arr, $path, $default = null) {
	$node = $arr;
	foreach (array_filter(explode('/', $path), 'strlen') as $key) {
		if (!is_array($node) || !array_key_exists($key, $node)) {
			return $default;
		}
		$node = $node[$key];
	}
	return $node;
}
function array_path_enabled($arr, $path, $key = 'enable') {
	$node = array_get_path($arr, $path);
	return is_array($node) && isset($node[$key]);
}
function array_init_path(&$arr, $path) {
	if (!is_array($arr)) {
		$arr = array();
	}
	if (!isset($arr[$path]) || !is_array($arr[$path])) {
		$arr[$path] = array();
	}
}
function dhcp_get_backend() { return 'kea'; }
function dhcp_is_backend($b) { return $b === 'kea'; }
function g_get($key) { return ($key === 'services_dhcp_server_enable') ? true : null; }
function get_configured_interface_with_descr($all = false) { return array('wan' => 'WAN', 'lan' => 'LAN', 'opt1' => 'DMZ'); }
function get_configured_pppoe_server_interfaces() { return array(); }
function convert_friendly_interface_to_friendly_descr($if) { return strtoupper($if); }
function is_ipaddrv4($a) { return filter_var((string)$a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false; }
function is_ipaddrv6($a) { return filter_var((string)$a, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false; }
function is_linklocal($a) { return stripos((string)$a, 'fe80:') === 0; }
function is_numericint($v) { return is_int($v) || (is_string($v) && ctype_digit($v)); }
function get_interface_ip($if) { return (string)config_get_path("interfaces/{$if}/ipaddr", ''); }
function get_interface_subnet($if) { return (string)config_get_path("interfaces/{$if}/subnet", ''); }
function get_interface_ipv6($if) { return ($if === 'lan') ? '2001:db8:42::1' : ''; }
function get_interface_subnetv6($if) { return ($if === 'lan') ? '64' : ''; }
function gen_subnetv4($ip, $bits) { return long2ip(ip2long($ip) & (-1 << (32 - (int)$bits))); }
function gen_subnetv4_max($ip, $bits) { return long2ip(ip2long($ip) | ((1 << (32 - (int)$bits)) - 1)); }
function gen_subnet($ip, $bits) { return gen_subnetv4($ip, $bits); }
function gen_subnetv6($ip, $bits) { return ($ip === '::') ? '::' : '2001:db8:42::'; }
function gen_subnetv6_max($ip, $bits) { return ($ip === '::') ? '::ffff:ffff:ffff:ffff' : '2001:db8:42::ffff:ffff:ffff:ffff'; }
function ip_after($ip) { return long2ip(ip2long($ip) + 1); }
function ip_before($ip) { return long2ip(ip2long($ip) - 1); }
function dhcpv6_pd_str_help($sn) { return '::xxxx:xxxx:xxxx:xxxx'; }
function link_interface_to_bridge($if) { return false; }
function is_subsystem_dirty($s) { return in_array($s, $GLOBALS['dirty'] ?? array(), true); }
function kea_custom_config_editable() { return $GLOBALS['kea_editable'] ?? true; }
function kea_earlydnsreg_enabled(array $conf, array $map) { return (($map['earlydnsregpolicy'] ?? '') === 'enable') ? array(1) : false; }
function kea6_earlydnsreg_enabled(array $conf, array $map) { return false; }
function dhcp_relay_interface_list($v6) { return $v6 ? array('lan' => 'LAN') : array('lan' => 'LAN', 'opt1' => 'DMZ'); }
function dhcp_relay_carp_list($v6) { return array('none' => 'none'); }

/* The form functions of services_dhcp.inc, as the API reads through them */
$dhcp_inc = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/services_dhcp.inc");
foreach (array('kea_log_levels', 'kea_ha_roles', 'dhcp_iface_context', 'dhcp6_iface_context', 'dhcp_staticmap_dnsregpolicy_values',
    'dhcp_staticmap_form', 'dhcp_server_dnsregpolicy_values', 'dhcp_server_conf', 'dhcp_server_form', 'dhcp6_staticmap_form',
    'dhcp6_server_iflist', 'dhcp6_server_offered', 'dhcp6_server_relay_enabled', 'dhcp6_server_prefix', 'dhcp6_server_conf',
    'dhcp6_server_form') as $fn) {
	eval(fn_source($dhcp_inc, $fn));
}

/* Saves: record the POST the API builds, store a mapping like the page */
function dhcp_server_save($if, $pool, $act, array $post) {
	$GLOBALS['saved'] = $post;
	if (!empty($GLOBALS['save_errors'])) {
		return array('input_errors' => $GLOBALS['save_errors'], 'saved' => false, 'missing_pool' => false, 'id' => null);
	}
	$GLOBALS['config']['dhcpd'][$if]['range'] = array('from' => $post['range_from'], 'to' => $post['range_to']);
	if (empty($post['enable'])) {
		unset($GLOBALS['config']['dhcpd'][$if]['enable']);
	}
	return array('input_errors' => array(), 'saved' => true, 'missing_pool' => false, 'id' => null);
}
function dhcp6_server_save($if, $pool, $act, array $post) {
	$GLOBALS['saved'] = $post;
	return array('input_errors' => array(), 'saved' => true, 'missing_pool' => false, 'id' => null);
}
function dhcp_staticmap_save($if, $id, array $post) {
	$GLOBALS['saved'] = $post;
	if (!empty($GLOBALS['save_errors'])) {
		return array('input_errors' => $GLOBALS['save_errors'], 'warnings' => array());
	}
	$map = array('mac' => $post['mac'], 'ipaddr' => $post['ipaddr'], 'hostname' => $post['hostname'], 'descr' => $post['descr'],
	    'dnsserver' => array_values(array_filter(array($post['dns1'], $post['dns2'], $post['dns3'], $post['dns4']), 'strlen')));
	$maps = $GLOBALS['config']['dhcpd'][$if]['staticmap'];
	if ($id === null) {
		$maps[] = $map;
	} else {
		$maps[$id] = $map;
	}
	usort($maps, function ($a, $b) {
		return strcmp(sprintf('%u', ip2long($a['ipaddr'] ?: '255.255.255.255')), sprintf('%u', ip2long($b['ipaddr'] ?: '255.255.255.255')));
	});
	$GLOBALS['config']['dhcpd'][$if]['staticmap'] = $maps;
	foreach ($maps as $i => $m) {
		if ($m['mac'] === $post['mac']) {
			return array('input_errors' => array(), 'warnings' => array('The IP address <b>x</b> is in use.'), 'id' => $i);
		}
	}
	return array('input_errors' => array(), 'warnings' => array());
}
function dhcp6_staticmap_save($if, $id, array $post) {
	$GLOBALS['saved'] = $post;
	return array('input_errors' => array(), 'warnings' => array(), 'id' => 0);
}
function dhcp_staticmap_delete($if, $id) {
	unset($GLOBALS['config']['dhcpd'][$if]['staticmap'][$id]);
	$GLOBALS['config']['dhcpd'][$if]['staticmap'] = array_values($GLOBALS['config']['dhcpd'][$if]['staticmap']);
	return true;
}
function dhcp_apply_changes() { $GLOBALS['applied'] = true; return 0; }
function dhcp6_apply_changes() { return 0; }

function req(array $params, array $body = array(), array $query = array()) {
	return array('params' => $params, 'body' => $body, 'query' => $query, 'path' => '/v1/services/dhcp/x', 'user' => 'admin', 'token' => null);
}

/* ================================================================== */
/* Schemas                                                             */
/* ================================================================== */

$names = array('services/dhcp_server', 'services/dhcp_static_mapping', 'services/dhcpv6_server', 'services/dhcpv6_static_mapping',
    'services/dhcp_settings', 'services/dhcpv6_settings', 'services/dhcp_relay', 'services/dhcpv6_relay');
$all = restapi_schemas();
foreach ($names as $name) {
	check(isset($all[$name]), "schema {$name} is registered");
	check(is_file("{$root}/src/usr/local/www/{$all[$name][1]}"), "{$name}: its privilege page {$all[$name][1]} exists");
	check(isset(restapi_areas()[$all[$name][2]]), "{$name}: its area exists");
	$s = restapi_schema_get($name);
	check(is_array($s) && ($s['resource'] === $name) && !empty($s['sections']), "{$name} builds");
}
check($all['services/dhcp_relay'][2] === 'services.misc' && $all['services/dhcp_server'][2] === 'services.dhcp',
    'relay schemas need the relay area, server schemas the DHCP area');

/* Schema fields are the API's form fields: every editable field of the running (Kea) backend, nothing else */
$editable = function (array $types, array $drop = array()) {
	return array_values(array_diff(array_keys(array_filter($types, function ($t) {
		return $t !== 'ro';
	})), $drop));
};
$top = function ($name) {
	return array_keys(array_filter(restapi_schema_fields(restapi_schema_get($name)), function ($f) {
		return $f['parent'] === null;
	}));
};
$same = function (array $a, array $b) {
	sort($a);
	sort($b);
	return $a === $b;
};
check($same($top('services/dhcp_server'), $editable(restapi_dhcp_server_types(false))), 'dhcp_server fields = the DHCP Server page fields (Kea)');
check($same($top('services/dhcp_static_mapping'), $editable(restapi_dhcp_map_types())), 'dhcp_static_mapping fields = the edit page fields (Kea)');
check($same($top('services/dhcpv6_server'), $editable(restapi_dhcp6_server_types(false))), 'dhcpv6_server fields = the DHCPv6 Server page fields (Kea)');
check($same($top('services/dhcpv6_static_mapping'), $editable(restapi_dhcp6_map_types('lan'))), 'dhcpv6_static_mapping fields = the edit page fields (Kea)');
check($same($top('services/dhcp_settings'), array_merge(array('backend'), $editable(restapi_dhcp_settings_types()))),
    'dhcp_settings fields = the settings page fields and the backend');
check($same($top('services/dhcp_relay'), $editable(restapi_relay_types())), 'dhcp_relay fields = the relay page fields');

$s4 = restapi_schema_get('services/dhcp_server');
$by = array();
foreach ($s4['sections'] as $sec) {
	foreach ($sec['fields'] as $f) {
		$by[$f['name']] = $f + array('section' => $sec);
	}
}
check($by['dnsserver']['type'] === 'entry-grid' && $by['dnsserver']['max'] === 4 && $by['winsserver']['max'] === 2 &&
    $by['dnsserver']['fields'][0]['name'] === 'address', 'address lists are entry grids of {address} rows with the page\'s limits');
check(array_column($by['denyunknown']['options'], 'value') === array('disabled', 'enabled', 'class') &&
    array_column($by['dnsregpolicy']['options'], 'value') === array('default', 'enable', 'disable'), 'select choices are the page\'s');
foreach (array('mac_allow', 'ntpserver', 'tftp', 'ldap', 'netboot', 'filename', 'custom_kea_config') as $f) {
	check(!empty($by[$f]['section']['advanced']), "dhcp_server: {$f} is in an advanced section");
}
foreach (array('enable', 'range_from', 'dnsserver', 'gateway') as $f) {
	check(empty($by[$f]['section']['advanced']), "dhcp_server: {$f} is not advanced");
}
check(($by['filename']['visibleWhen'] ?? null) === array('field' => 'netboot', 'truthy' => true), 'boot files show with network booting');
check($by['custom_kea_config']['readonly'] === false, 'custom Kea JSON is editable with the privilege');
$GLOBALS['kea_editable'] = false;
check(restapi_schema_fields(restapi_schema_get('services/dhcp_server'))['custom_kea_config'] !== null &&
    restapi_schema_get('services/dhcp_server')['sections'][count($s4['sections']) - 1]['fields'][0]['readonly'] === true,
    'custom Kea JSON is read-only without the privilege (like the page)');
$GLOBALS['kea_editable'] = true;
$settings = restapi_schema_get('services/dhcp_settings');
check($settings['sections'][0]['fields'][0]['name'] === 'backend' && $settings['sections'][0]['fields'][0]['readonly'] === true &&
    array_column($settings['sections'][0]['fields'][0]['options'], 'value') === array('kea'), 'the backend is shown read-only: Kea only');

/* ?interface= adds the interface's hints */
$plain = restapi_schema_get('services/dhcp_server');
$lan = restapi_schema_get('services/dhcp_server', array('interface' => 'lan'));
$lan_fields = array();
foreach ($lan['sections'] as $sec) {
	foreach ($sec['fields'] as $f) {
		$lan_fields[$f['name']] = $f;
	}
}
check($lan_fields['range_from']['placeholder'] === '192.168.1.1' && $lan_fields['range_to']['placeholder'] === '192.168.1.254' &&
    $lan_fields['gateway']['placeholder'] === '192.168.1.1' && $lan_fields['domain']['placeholder'] === 'home.arpa',
    'schema ?interface=lan: range, gateway and domain placeholders of the interface');
check(strpos($lan['sections'][1]['description'], '192.168.1.0/24') !== false && strpos($lan['sections'][1]['description'], '192.168.1.1 – 192.168.1.254') !== false,
    'schema ?interface=lan: the pool section names the subnet and its usable range');
check($lan_fields['dnsserver']['fields'][0]['placeholder'] === '192.168.1.1', 'DNS placeholder: the interface address while the resolver runs');
check(restapi_schema_get('services/dhcp_server', array('interface' => 'nope')) == $plain, 'an unknown ?interface= is ignored');
check(restapi_schema_get('services/dhcpv6_server', array('interface' => 'lan'))['sections'][1]['description'] !== '', 'DHCPv6 schema with ?interface=');

/* ================================================================== */
/* Validation messages land on their fields                            */
/* ================================================================== */

/* $cases: message => field; each message (or its sprintf template) is a real one of the save functions. */
$relay_inc = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/services_dhcp_relay.inc");
$util_inc = file_get_contents("{$root}/src/etc/inc/util.inc");
$routes_dhcp = file_get_contents("{$root}/src/etc/inc/restapi/routes_dhcp.inc");
$source = $dhcp_inc . $relay_inc . $util_inc . $routes_dhcp;
$mapped = function ($schema, array $cases, array $body = array()) use ($source) {
	foreach ($cases as $msg => $want) {
		$template = (string)(is_array($want) ? $want[1] : $msg);
		check(strpos($source, $template) !== false, "\"{$template}\" is a real save message");
		$want = is_array($want) ? $want[0] : $want;
		$r = restapi_errors_to_fields(array($msg), restapi_schema_get($schema), $body);
		$got = array_keys($r['fields']);
		check($got === array($want), "{$schema}: \"{$msg}\" lands on {$want} (got " . implode(',', $got) . ')');
	}
};
$mapped('services/dhcp_server', array(
	'The specified range lies outside of the current subnet.' => 'range_from',
	'The DHCP range cannot overlap any static DHCP mappings.' => 'range_from',
	'The range is invalid (first element higher than second element).' => 'range_from',
	'The specified range must not be within the range configured on another DHCP pool for this interface.' => 'range_from',
	'The network address cannot be used in the starting subnet range.' => 'range_from',
	'The subnet range cannot overlap with virtual IP address 192.168.1.150.' => array('range_from', 'The subnet range cannot overlap with virtual IP address %s.'),
	'The field Range begin is required.' => array('range_from', 'The field %s is required.'),
	'The field Range end is required.' => array('range_to', 'The field %s is required.'),
	'The broadcast address cannot be used in the ending subnet range.' => 'range_to',
	'A valid IPv4 address must be specified for range to.' => 'range_to',
	'A valid IP address must be specified for each of the DNS servers.' => 'dnsserver',
	'A valid IP address must be specified for the primary/secondary WINS servers.' => 'winsserver',
	'A valid IP address or hostname must be specified for the NTP servers.' => 'ntpserver',
	'The gateway address 10.9.9.1 does not lie within the chosen interface\'s subnet.' => array('gateway', 'The gateway address %s does not lie within the chosen interface\'s subnet.'),
	'The default lease time must be at least 60 seconds.' => 'deftime',
	'The maximum lease time must be at least 60 seconds, and the same value or greater than the default lease time.' => 'maxtime',
	'If a mac allow list is specified, it must contain only valid partial MAC addresses.' => 'mac_allow',
	'If a mac deny list is specified, it must contain only valid partial MAC addresses.' => 'mac_deny',
	'A valid domain name must be specified for the DNS domain.' => 'domain',
	'A valid domain search list must be specified.' => 'domainsearchlist',
	'A valid IP address, hostname or URL must be specified for the TFTP server.' => 'tftp',
	'A valid IP address must be specified for the network boot server.' => 'nextserver',
	'Invalid DNS Registration Policy.' => 'dnsregpolicy',
	'Invalid Early DNS Registration Policy.' => 'earlydnsregpolicy',
	'Cannot enable static ARP when there are static map entries without IP addresses. Ensure all static maps have IP addresses and try again.' => 'staticarp',
	'The DHCP relay on the LAN interface must be disabled before enabling the DHCP server.' => array('enable', 'interface must be disabled before enabling the DHCP server.'),
	'DHCP Registration features in the DNS Resolver are active and require at least one enabled DHCP Server.' => 'enable',
	'Custom configuration is not a well formed JSON object.' => 'custom_kea_config',
	'This user does not have sufficient privileges to edit the custom Kea configuration.' => 'custom_kea_config',
));
$mapped('services/dhcp_static_mapping', array(
	'Either MAC address or Client identifier must be specified' => 'mac',
	'This MAC address or Client identifier already exists.' => 'mac',
	'A valid MAC address must be specified.' => 'mac',
	'A valid MAC address must be specified for use with static ARP.' => 'mac',
	'A valid IPv4 address must be specified.' => 'ipaddr',
	'A valid IPv4 address must be specified for use with static ARP.' => 'ipaddr',
	'Static ARP is enabled.  An IP address must be specified.' => 'ipaddr',
	'The IP address must not be within the DHCP range for this interface.' => 'ipaddr',
	'The IP address must not be within the range configured on a DHCP pool for this interface.' => 'ipaddr',
	'The IP address must lie in the LAN subnet.' => array('ipaddr', 'The IP address must lie in the %s subnet.'),
	'The IP address cannot be the LAN broadcast address.' => array('ipaddr', 'The IP address cannot be the %s broadcast address.'),
	'The hostname cannot end with a hyphen according to RFC952' => 'hostname',
	'The hostname can only contain the characters A-Z, 0-9 and \'-\'.' => 'hostname',
	'A valid hostname is specified, but the domain name part should be omitted' => 'hostname',
	'Invalid Early DNS Registration Policy.' => 'earlydnsregpolicy',
	'A valid IPv4 address must be specified for the gateway.' => 'gateway',
	'A valid IPV4 address must be specified for each of the DNS servers.' => 'dnsserver',
	'A valid IPv4 address must be specified for the primary/secondary WINS servers.' => 'winsserver',
	'A valid IPv4 address, hostname or URL must be specified for the TFTP server.' => 'tftp',
	'A valid domain name must be specified for the DNS domain.' => 'domain',
	'Custom configuration is not a well formed JSON object.' => 'custom_kea_config',
));
$mapped('services/dhcpv6_server', array(
	'A valid range must be specified.' => 'range_from',
	'Range From and Range To must both be entered.' => 'range_from',
	'The specified range lies outside of the current subnet.' => 'range_from',
	'The specified range must not be within the primary DHCPv6 address pool for this interface.' => 'range_from',
	'The prefix (upper 64 bits) must be zero.  Use the form ::xxxx' => array('range_from', 'The prefix (upper %1$s bits) must be zero.  Use the form %2$s'),
	'Delegated prefix must be a valid IPv6 prefix.' => 'pdprefix',
	'Delegated Prefix Length is not an integer in the required range.' => 'pdprefixlen',
	'Delegated length must be greater than or equal to the prefix length.' => 'pddellen',
	'A valid IPv6 address must be specified for each of the DNS servers.' => 'dnsserver',
	'A valid IPv6 address must be specified for the NTP servers.' => 'ntpserver',
	'A valid URL must be specified for the network bootfile.' => 'bootfile_url',
	'The maximum lease time must be at least 60 seconds, and the same value or greater than the default lease time.' => 'maxtime',
	'The DHCPv6 Server can only be enabled on interfaces configured with a static IPv6 address or Track Interface.' => 'enable',
));
$mapped('services/dhcpv6_static_mapping', array(
	'The field DUID is required.' => array('duid', 'The field %s is required.'),
	'A valid DUID must be specified.' => 'duid',
	'This Hostname, IP or DUID already exists.' => 'duid',
	'A valid IPv6 address must be specified.' => 'ipaddrv6',
	'The prefix (upper 56 bits) must be zero.  Use the form ::xxxx' => array('ipaddrv6', 'The prefix (upper %1$s bits) must be zero.  Use the form %2$s'),
	'A valid delegated prefix must be specified.' => 'pdprefix',
	'The hostname cannot end with a hyphen according to RFC952' => 'hostname',
));
$mapped('services/dhcp_settings', array(
	'Local name must be hostname-like.' => 'ha_localname',
	'Remote name must be non-empty and hostname-like.' => 'ha_remotename',
	'Local address must be a valid IPv4 or IPv6 address.' => array('ha_localip', '%s address must be a valid IPv4 or IPv6 address.'),
	'Remote address must be a valid IPv4 or IPv6 address.' => array('ha_remoteip', '%s address must be a valid IPv4 or IPv6 address.'),
	'Invalid local port.' => array('ha_localport', 'Invalid %s port.'),
	'Invalid remote port.' => array('ha_remoteport', 'Invalid %s port.'),
	'Invalid node role.' => 'ha_role',
	'Heartbeat Delay must be a non-negative integer.' => array('ha_heartbeatdelay', '%s must be a non-negative integer.'),
	'Max Rejected Updates must be a non-negative integer.' => array('ha_maxrejectedleaseupdates', '%s must be a non-negative integer.'),
	'A valid server certificate must be selected for TLS transport.' => 'ha_scertref',
	'A valid client certificate must be selected for mutual TLS.' => 'ha_ccertref',
	'Invalid log level.' => 'loglevel',
	'Only the Kea DHCP backend is available in FreeSense.' => 'backend',
));
$mapped('services/dhcp_relay', array(
	'The field Interface is required.' => array('interface', 'The field %s is required.'),
	'opt9 is not an interface the relay can use.' => array('interface', '%s is not an interface the relay can use.'),
	'At least one Upstream Server must be specified.' => 'server',
	'DHCP Relay cannot be enabled while DHCP Server is enabled on any interface.' => 'enable',
	'A valid CARP Status VIP must be selected.' => 'carpstatusvip',
));
$mapped('services/dhcp_relay', array(
	'Upstream Server address 10.0.0.300 is not a valid IPv4 address.' => array('server.1.address', 'Upstream Server address %s is not a valid IPv4 address.'),
), array('server' => array(array('address' => '10.0.0.1'), array('address' => '10.0.0.300'))));

/* ================================================================== */
/* Pure helpers                                                        */
/* ================================================================== */

check(restapi_dhcp_range_text('192.168.1.100', '192.168.1.199') === '192.168.1.100 – 192.168.1.199' &&
    restapi_dhcp_range_text('192.168.1.100', '') === '', 'a range reads "from – to", nothing without both ends');
check(restapi_dhcp_dns_hint(array('9.9.9.9', '2620:fe::fe'), false, '192.168.1.1', false) === array('9.9.9.9') &&
    restapi_dhcp_dns_hint(array('9.9.9.9', '2620:fe::fe'), false, '', true) === array('2620:fe::fe') &&
    restapi_dhcp_dns_hint(array('9.9.9.9'), true, '192.168.1.1', false) === array('192.168.1.1') &&
    restapi_dhcp_dns_hint(array('9.9.9.9'), true, '192.168.1.1', false, array('10.0.0.53', '')) === array('10.0.0.53'),
    'DNS placeholders as the pages compute them (pool list, resolver address, system servers of the family)');
check(restapi_svc_rows_out(array('dnsserver' => array('1.1.1.1', '8.8.8.8'), 'x' => 'y'), array('dnsserver', 'ntpserver')) ===
    array('dnsserver' => array(array('address' => '1.1.1.1'), array('address' => '8.8.8.8')), 'x' => 'y'), 'lists become {address} rows');
check(restapi_svc_rows_in(array('dnsserver' => array(array('address' => '1.1.1.1'), '8.8.8.8', array('address' => ''), ''), 'ntpserver' => 'x'),
    array('dnsserver', 'ntpserver')) === array('dnsserver' => array('1.1.1.1', '8.8.8.8'), 'ntpserver' => 'x'),
    'rows or strings become a list; empty rows are dropped; other shapes are left for the type check');
check(api_error(function () {
	restapi_svc_rows_in(array('dnsserver' => array(array('address' => '1.1.1.1', 'bogus' => 1))), array('dnsserver'));
})->status === 400, 'rows with other keys are refused');
check(restapi_dhcp_stored(array('enable' => '', 'ddnsdomainkey' => 'k', 'omapi_key' => '', 'staticmap' => array(1), 'fields' => 1),
    array('staticmap'), array('ddnsdomainkey', 'omapi_key')) === array('enable' => '', 'ddnsdomainkey' => '(set)', 'omapi_key' => ''),
    'the stored part drops sub-resources and masks keys');
check(restapi_dhcp_map_display(array('mac' => '00:11:22:33:44:55', 'ipaddr' => '192.168.1.10', 'hostname' => 'nas', 'descr' => 'Storage',
    'cid' => 'c1', 'arp_table_static_entry' => ''), true) === array('mac' => '00:11:22:33:44:55', 'ip' => '192.168.1.10', 'hostname' => 'nas',
    'description' => 'Storage', 'client_id' => 'c1', 'name' => 'nas', 'static_arp' => true, 'early_dns' => true), 'static mapping display');
check(restapi_dhcp_map_display(array('mac' => '00:11:22:33:44:66'))['name'] === '00:11:22:33:44:66' &&
    restapi_dhcp_map_display(array('mac' => 'm', 'ipaddr' => '10.0.0.9'))['name'] === '10.0.0.9', 'the mapping name falls back to IP, then MAC (like the list page)');
check(restapi_dhcp6_map_display(array('duid' => 'd', 'ipaddrv6' => '::10', 'pdprefix' => '2001:db8::/56', 'descr' => 'x')) ===
    array('duid' => 'd', 'ip' => '::10', 'prefix' => '2001:db8::/56', 'hostname' => '', 'description' => 'x', 'name' => '::10', 'early_dns' => false),
    'DHCPv6 static mapping display');

/* ================================================================== */
/* Handlers on sample config                                           */
/* ================================================================== */

$servers = restapi_h_dhcp_servers(req(array()))['data'];
check(array_column($servers, 'interface') === array('wan', 'lan', 'opt1'), 'every configured interface is listed');
check($servers[1] === array('interface' => 'lan', 'description' => 'LAN', 'enabled' => true, 'range' => '192.168.1.100 – 192.168.1.199',
    'static_count' => 2, 'subnet' => '192.168.1.0/24', 'available' => true, 'pools' => 1), 'the LAN row: '. json_encode($servers[1]));
check(!$servers[0]['available'] && ($servers[0]['subnet'] === '') && !$servers[2]['available'] && ($servers[2]['subnet'] === '10.0.0.0/31'),
    'a DHCP WAN and a /31 cannot have a DHCP server');

$item = restapi_h_dhcp_srv_get(req(array('interface' => 'lan')))['data'];
check($item['interface'] === 'lan' && $item['enable'] === '' && !isset($item['staticmap']) && !isset($item['pool']) &&
    $item['ddnsdomainkey'] === '(set)' && $item['pending'] === false, 'server item: interface, stored settings without sub-resources, keys masked');
check($item['fields']['enable'] === true && $item['fields']['range_from'] === '192.168.1.100' && $item['fields']['denyunknown'] === 'class' &&
    $item['fields']['dnsserver'] === array(array('address' => '192.168.1.1'), array('address' => '1.1.1.1')) && $item['fields']['winsserver'] === array(),
    'server fields: the form values, address lists as rows');
check($item['display']['subnet'] === '192.168.1.0/24' && $item['display']['subnet_range'] === '192.168.1.1 – 192.168.1.254' &&
    $item['display']['range'] === '192.168.1.100 – 192.168.1.199' && $item['display']['pools'] === 1 && $item['display']['static_count'] === 2 &&
    $item['display']['backend'] === 'Kea DHCP' && $item['display']['placeholders']['gateway'] === '192.168.1.1' &&
    $item['display']['placeholders']['dnsserver'] === array('192.168.1.1'), 'server display: subnet, ranges, counts and hints');
check(api_error(function () { restapi_h_dhcp_srv_get(req(array('interface' => 'opt1'))); })->status === 409 &&
    api_error(function () { restapi_h_dhcp_srv_get(req(array('interface' => 'opt9'))); })->status === 404, 'unusable 409, unknown 404');

/* PUT: partial over the form; rows or strings; false clears; the page's POST */
restapi_request_context(array('schema' => 'services/dhcp_server', 'body' => array()));
$put = restapi_h_dhcp_srv_set(req(array('interface' => 'lan'), array('range_to' => '192.168.1.150',
    'dnsserver' => array(array('address' => '9.9.9.9'), '149.112.112.112'), 'ntpserver' => array())));
$post = $GLOBALS['saved'];
check($post['if'] === 'lan' && $post['save'] === 'Save' && $post['enable'] === 'yes' && $post['range_from'] === '192.168.1.100' &&
    $post['range_to'] === '192.168.1.150' && $post['dns1'] === '9.9.9.9' && $post['dns2'] === '149.112.112.112' && $post['dns3'] === '' &&
    $post['denyunknown'] === 'class', 'PUT posts the page form: kept fields, changed range, rows as dns1..dns4');
check(!isset($post['ddnsdomainkey']) || ($post['ddnsdomainkey'] !== '(set)'), 'a masked key is never posted');
check(isset($put['data']['fields'], $put['data']['display']) && $put['data']['fields']['range_to'] === '192.168.1.150' &&
    $put['data']['pending'] === false && !isset($put['data']['apply']), 'PUT answers with the item (staged, no apply)');
restapi_h_dhcp_srv_set(req(array('interface' => 'lan'), array('enable' => false)));
check(!isset($GLOBALS['saved']['enable']), 'false clears a switch');
check(api_error(function () { restapi_h_dhcp_srv_set(req(array('interface' => 'lan'), array('bogus' => 1))); })->status === 400,
    'unknown fields are refused');
$GLOBALS['saved'] = null;
restapi_h_dhcp_srv_set(array_merge(req(array('interface' => 'lan'), array('enable' => true)), array('query' => array('apply' => 'true'))));
check(!empty($GLOBALS['applied']), '?apply=true applies at once');

/* A failed save is a 422 keyed by the schema */
$GLOBALS['save_errors'] = array('The DHCP range cannot overlap any static DHCP mappings.', 'Something else.');
restapi_request_context(array('schema' => 'services/dhcp_server', 'body' => array('range_from' => '192.168.1.5')));
$e = api_error(function () { restapi_h_dhcp_srv_set(req(array('interface' => 'lan'), array('range_from' => '192.168.1.5'))); });
$p = $e->payload();
check($e->status === 422 && $p['error']['details']['fields'] === array('range_from' => 'The DHCP range cannot overlap any static DHCP mappings.') &&
    count($p['error']['details']['messages']) === 2, '422: the overlap lands on range_from, all messages kept');
$GLOBALS['save_errors'] = array();
$GLOBALS['config']['dhcpd']['lan']['enable'] = '';

/* Static mappings */
$maps = restapi_h_dhcp_smap_list(req(array('interface' => 'lan')))['data'];
check(count($maps) === 2 && $maps[0]['id'] === 0 && $maps[0]['mac'] === '00:11:22:33:44:55' &&
    $maps[0]['fields']['ipaddr'] === '192.168.1.10' && $maps[0]['fields']['arp_table_static_entry'] === true &&
    $maps[0]['fields']['dnsserver'] === array(array('address' => '192.168.1.1')) && $maps[0]['fields']['earlydnsregpolicy'] === 'default',
    'static mapping items: id, stored, fields');
check($maps[0]['display'] === array('mac' => '00:11:22:33:44:55', 'ip' => '192.168.1.10', 'hostname' => 'nas', 'description' => 'Storage',
    'client_id' => '', 'name' => 'nas', 'static_arp' => true, 'early_dns' => false) && $maps[1]['display']['client_id'] === 'printer-1',
    'static mapping display: ' . json_encode($maps[0]['display']));
check(restapi_h_dhcp_smap_get(req(array('interface' => 'lan', 'id' => '00-11-22-33-44-66')))['data']['id'] === 1, 'a mapping by MAC address');
$created = restapi_h_dhcp_smap_create(req(array('interface' => 'lan'), array('mac' => '00:11:22:33:44:77', 'ipaddr' => '192.168.1.5',
    'hostname' => 'cam', 'dnsserver' => array(array('address' => '192.168.1.1')))));
check($created['status'] === 201 && $GLOBALS['saved']['dns1'] === '192.168.1.1' && $GLOBALS['saved']['save'] === 'Save' &&
    $created['data']['id'] === 0 && $created['data']['display']['hostname'] === 'cam' && $created['data']['warnings'] === array('The IP address x is in use.'),
    'POST creates through the page save; the answer is the item at its sorted position with the warnings as text');
$updated = restapi_h_dhcp_smap_update(req(array('interface' => 'lan', 'id' => '0'), array('descr' => 'Camera')));
check($GLOBALS['saved']['mac'] === '00:11:22:33:44:77' && $GLOBALS['saved']['ipaddr'] === '192.168.1.5' && $updated['data']['display']['description'] === 'Camera',
    'PUT keeps the other fields');
check(restapi_h_dhcp_smap_delete(req(array('interface' => 'lan', 'id' => '0')))['data']['deleted'] === 0 &&
    count(config_get_path('dhcpd/lan/staticmap')) === 2, 'DELETE removes the mapping');
$GLOBALS['save_errors'] = array('A valid MAC address must be specified.');
restapi_request_context(array('schema' => 'services/dhcp_static_mapping', 'body' => array('mac' => 'zz')));
$e = api_error(function () { restapi_h_dhcp_smap_create(req(array('interface' => 'lan'), array('mac' => 'zz'))); });
check($e->status === 422 && $e->payload()['error']['details']['fields'] === array('mac' => 'A valid MAC address must be specified.'),
    'an invalid MAC lands on mac');
$GLOBALS['save_errors'] = array();

/* DHCPv6 */
$servers6 = restapi_h_dhcp6_servers(req(array()))['data'];
check($servers6[1]['interface'] === 'lan' && $servers6[1]['available'] && $servers6[1]['track6'] && $servers6[1]['range'] === '::1000 – ::2000' &&
    $servers6[1]['static_count'] === 1 && $servers6[1]['ramode'] === 'assist' && !$servers6[2]['available'], 'DHCPv6 rows: ' . json_encode($servers6[1]));
$item6 = restapi_h_dhcp6_srv_get(req(array('interface' => 'lan')))['data'];
check($item6['fields']['enable'] === true && $item6['fields']['range_from'] === '::1000' && is_array($item6['fields']['dnsserver']) &&
    $item6['display']['track6'] === true && $item6['display']['prefix'] === '::/64' && !isset($item6['staticmap']), 'DHCPv6 server item');
restapi_h_dhcp6_srv_set(req(array('interface' => 'lan'), array('dnsserver' => array(array('address' => '2001:db8::53')), 'dhcp6c-dns' => true)));
check($GLOBALS['saved']['dns1'] === '2001:db8::53' && $GLOBALS['saved']['dhcp6c-dns'] === 'yes' && !isset($GLOBALS['saved']['wins1']),
    'DHCPv6 PUT posts the page form');
$maps6 = restapi_h_dhcp6_smap_list(req(array('interface' => 'lan')))['data'];
check($maps6[0]['fields']['duid'] === '00:01:00:01:aa:bb:cc:dd:ee:ff' && $maps6[0]['display']['prefix'] === '2001:db8:42::/56' &&
    $maps6[0]['display']['name'] === 'nas6', 'DHCPv6 static mapping item');
restapi_h_dhcp6_smap_create(req(array('interface' => 'lan'), array('duid' => '00:01:00:01:11:22:33:44:55:66')));
check($GLOBALS['saved']['duid'] === '00:01:00:01:11:22:33:44:55:66' && $GLOBALS['saved']['earlydnsregpolicy'] === 'default', 'DHCPv6 POST');

$GLOBALS['dirty'] = array('dhcpd');
check(restapi_h_dhcp_pending(req(array()))['data'] === array('pending' => true) &&
    restapi_h_dhcp6_pending(req(array()))['data'] === array('pending' => false), 'pending per server');

/* ================================================================== */
/* Wiring                                                              */
/* ================================================================== */

$v1 = restapi_routes_v1();
$seen = array();
foreach ($v1 as $r) {
	$seen["{$r['method']} {$r['path']}"] = $r;
}
foreach (array('dhcp' => '', 'dhcpv6' => '6') as $svc => $v) {
	$want = array(
		"GET /v1/services/{$svc}/pending" => array("restapi_h_dhcp{$v}_pending", "services_{$svc}.php", null),
		"GET /v1/services/{$svc}/servers" => array("restapi_h_dhcp{$v}_servers", "services_{$svc}.php", null),
		"GET /v1/services/{$svc}/servers/{interface}" => array("restapi_h_dhcp{$v}_srv_get", "services_{$svc}.php", null),
		"PUT /v1/services/{$svc}/servers/{interface}" => array("restapi_h_dhcp{$v}_srv_set", "services_{$svc}.php", "services/{$svc}_server"),
		"GET /v1/services/{$svc}/servers/{interface}/static-mappings" => array("restapi_h_dhcp{$v}_smap_list", "services_{$svc}.php", null),
		"GET /v1/services/{$svc}/servers/{interface}/static-mappings/{id}" => array("restapi_h_dhcp{$v}_smap_get", "services_{$svc}.php", null),
		"POST /v1/services/{$svc}/servers/{interface}/static-mappings" => array("restapi_h_dhcp{$v}_smap_create", "services_{$svc}_edit.php", "services/{$svc}_static_mapping"),
		"PUT /v1/services/{$svc}/servers/{interface}/static-mappings/{id}" => array("restapi_h_dhcp{$v}_smap_update", "services_{$svc}_edit.php", "services/{$svc}_static_mapping"),
		"DELETE /v1/services/{$svc}/servers/{interface}/static-mappings/{id}" => array("restapi_h_dhcp{$v}_smap_delete", "services_{$svc}.php", null),
		"PUT /v1/services/{$svc}/settings" => array("restapi_h_dhcp{$v}_settings_set", "services_{$svc}_settings.php", "services/{$svc}_settings"),
		"POST /v1/services/{$svc}/apply" => array("restapi_h_dhcp{$v}_apply", "services_{$svc}.php", null),
	);
	foreach ($want as $key => list($handler, $page, $schema)) {
		check(isset($seen[$key]), "route {$key} exists");
		$r = $seen[$key];
		check($r['handler'] === $handler && $r['page'] === $page && $r['area'] === 'services.dhcp' && $r['schema'] === $schema,
		    "{$key}: {$handler}, guarded by {$page}, schema " . var_export($schema, true));
		check(!$r['write'] || preg_match('#/apply$#', $r['path']) || isset($r['query']['apply']), "{$key} takes ?apply=true");
		check(function_exists($handler), "{$handler}() exists");
	}
	foreach (array("GET /v1/services/{$svc}/servers" => "restapi_h_dhcp{$v}_servers", "GET /v1/services/{$svc}/pending" => "restapi_h_dhcp{$v}_pending",
	    "GET /v1/services/{$svc}/servers/lan" => "restapi_h_dhcp{$v}_srv_get",
	    "DELETE /v1/services/{$svc}/servers/lan/static-mappings/3" => "restapi_h_dhcp{$v}_smap_delete",
	    "GET /v1/services/{$svc}/lan" => "restapi_h_dhcp{$v}_server_get") as $key => $handler) {
		list($m, $p) = explode(' ', $key);
		check(restapi_match($v1, $m, $p)[0]['handler'] === $handler, "{$key} reaches {$handler} (the fixed paths come before {if})");
	}
}
check($seen['PUT /v1/services/dhcp-relay']['schema'] === 'services/dhcp_relay' && $seen['PUT /v1/services/dhcpv6-relay']['schema'] === 'services/dhcpv6_relay',
    'relay saves name their schema');
check(strpos(file_get_contents("{$root}/src/etc/inc/restapi.inc"), "'dhcp-server'") !== false, 'capability dhcp-server');
check(strpos(file_get_contents("{$root}/src/etc/inc/restapi/schema.inc"), "restapi_schema_get(\$resource, is_array(\$req['query'] ?? null) ? \$req['query'] : array())") !== false,
    'GET /v1/schema passes the query (?interface=) to the builder');
check(substr_count($routes_dhcp, 'write_config(') === 0 && substr_count($routes_dhcp, 'mark_subsystem_dirty(') === 0,
    'the DHCP routes save only through the pages\' functions');
foreach (array('services_dhcp.php', 'services_dhcp_edit.php', 'services_dhcpv6.php', 'services_dhcpv6_edit.php') as $page) {
	check(strpos(file_get_contents("{$root}/src/usr/local/www/{$page}"), 'restapi') === false, "{$page} (1.x) does not depend on the API");
}

echo "REST API DHCP smoke test passed.\n";
