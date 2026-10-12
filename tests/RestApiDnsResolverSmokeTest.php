<?php
/*
 * Standalone CI regression test for the DNS Resolver (Unbound) API surface of
 * the WebUI 2.0: schemas, validation messages keyed to fields, "fields" and
 * "display" of the list entries, pending/apply and the route wiring; run
 * with `php tests/RestApiDnsResolverSmokeTest.php`. The save functions are
 * the real ones of services_unbound.inc (the 1.x pages call them too).
 */

$root = dirname(__DIR__);
set_include_path(get_include_path() . PATH_SEPARATOR . realpath($root . '/src/etc/inc'));
if (!function_exists('gettext')) {
	function gettext($t) { return $t; }
}
require_once('restapi/framework.inc');
require_once('restapi/schema.inc');
require_once('restapi/routes_firewall.inc');
require_once('restapi/routes_unbound.inc');

/* The GUI save code reads optional form fields without isset(); that is all it may warn about. */
set_error_handler(function ($no, $msg, $file, $line) {
	if (strpos($msg, 'Undefined array key') === 0) {
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

/* ---- The real config and validation helpers, a few system stubs ---- */
$GLOBALS['config'] = array();
$util = file_get_contents("{$root}/src/etc/inc/util.inc");
foreach (array('array_get_path', 'array_set_path', 'array_path_enabled', 'array_del_path', 'do_input_validation', 'is_numericint',
    'is_ipaddr', 'is_ipaddrv4', 'is_subnet', 'is_subnetv6', 'is_unqualified_hostname', 'is_hostname', 'is_domain', 'is_port') as $fn) {
	eval(fn_source($util, $fn));
}
$cfglib = file_get_contents("{$root}/src/etc/inc/config.lib.inc");
foreach (array('config_get_path', 'config_set_path', 'config_del_path', 'config_path_enabled') as $fn) {
	eval(fn_source($cfglib, $fn));
}
function log_invalid_config_path($path) { check(false, "invalid config path {$path}"); }
function is_ipaddrv6($ip) { return is_string($ip) && (strpos($ip, '/') === false) && (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false); }
function write_config($desc) { $GLOBALS['writes'][] = $desc; return true; }
$GLOBALS['dirty'] = array();
function mark_subsystem_dirty($s) { $GLOBALS['dirty'][$s] = true; }
function clear_subsystem_dirty($s) { unset($GLOBALS['dirty'][$s]); }
function is_subsystem_dirty($s) { return !empty($GLOBALS['dirty'][$s]); }
function g_get($key) { return array('unbound_chroot_path' => sys_get_temp_dir() . '/no-such-unbound-chroot', 'tmp_path' => sys_get_temp_dir())[$key] ?? ''; }
$GLOBALS['backend'] = 'isc';
function dhcp_is_backend($b) { return $GLOBALS['backend'] === $b; }
function get_possible_listen_ips($include_ipv6_link_local = false) { return array('lan' => 'LAN', 'wan' => 'WAN', 'lo0' => 'Localhost'); }
function unbound_local_zone_types() {
	return array('deny' => 'Deny', 'refuse' => 'Refuse', 'static' => 'Static', 'transparent' => 'Transparent', 'typetransparent' => 'Type Transparent',
	    'redirect' => 'Redirect', 'inform' => 'Inform', 'inform_deny' => 'Inform Deny', 'nodefault' => 'No Default');
}
function cert_build_list($type, $purpose) { return array('c1' => 'Web &amp; DNS'); }
function get_dns_nameservers($a, $b) { return array('127.0.0.1'); }
function ip_in_subnet($ip, $subnet) { return strpos($ip, '127.') === 0; }
function is_dhcp_server_enabled() { return false; }
function test_unbound_config($cfg, &$output) { return 0; }
function validate_nat64_prefix($prefix) { return $prefix === '64:ff9b::/96'; }
function unbound_get_next_id() { return 7; }
$GLOBALS['applied'] = 0;
function unbound_apply_changes() { $GLOBALS['applied']++; clear_subsystem_dirty('unbound'); return 0; }

/* The real DNS Resolver functions of the 1.x pages. */
$inc = file_get_contents("{$root}/src/usr/local/FreeSense/include/www/services_unbound.inc");
foreach (array('unbound_python_scripts', 'unbound_certs_available', 'unbound_general_settings', 'unbound_build_if_list', 'unbound_save_general',
    'unbound_delete_override', 'unbound_hostcmp', 'unbound_hosts_sort', 'unbound_save_host', 'unbound_save_domain_override',
    'unbound_acl_actions', 'unbound_delete_acl', 'unbound_save_acl', 'unbound_edns_sizes', 'unbound_advanced_choices',
    'unbound_advanced_settings', 'unbound_save_advanced') as $fn) {
	eval(fn_source($inc, $fn));
}

/* A request through the route's schema, as the front controller makes it. */
function call_route($handler, $schema, array $body, array $params = array(), array $query = array()) {
	restapi_request_context(array('schema' => $schema, 'body' => $body));
	return $handler(array('params' => $params, 'body' => $body, 'query' => $query));
}

/* The fields a 422 is keyed to. */
function error_fields(callable $fn) {
	$e = api_error($fn);
	check($e && ($e->status === 422), 'the request fails validation (422)' . ($e ? " (got {$e->status}: {$e->getMessage()})" : ''));
	return $e->payload()['error']['details']['fields'] ?? array();
}

/* ---- Schemas ---- */
$names = array('services/dns_resolver' => 'services_unbound.php', 'services/dns_resolver_advanced' => 'services_unbound_advanced.php',
    'services/dns_host_override' => 'services_unbound_host_edit.php', 'services/dns_domain_override' => 'services_unbound_domainoverride_edit.php',
    'services/dns_access_list' => 'services_unbound_acls.php');
foreach ($names as $resource => $page) {
	$s = restapi_schema_get($resource);
	check(is_array($s) && ($s['resource'] === $resource) && !empty($s['sections']), "{$resource} builds");
	check(restapi_schemas()[$resource][1] === $page && restapi_schemas()[$resource][2] === 'services.dns', "{$resource} is read with {$page}'s privilege");
	check(is_file("{$root}/src/usr/local/www/{$page}"), "{$page} exists");
}
$byname = function ($schema) {
	$out = array();
	foreach ($schema['sections'] as $sec) {
		foreach ($sec['fields'] as $f) {
			$out[$f['name']] = $f + array('section' => $sec['id'], 'advanced' => !empty($sec['advanced']));
		}
	}
	return $out;
};

$gen = restapi_schema_get('services/dns_resolver');
$gf = $byname($gen);
$api_general = array_keys(array_filter(restapi_unbound_types(), function ($t) { return $t !== 'ro'; }));
sort($api_general);
$schema_general = array_keys($gf);
sort($schema_general);
check($schema_general === $api_general, 'the general schema has exactly the writable API fields (the page\'s form fields): ' . implode(', ', array_diff($api_general, $schema_general)));
check($gf['active_interface']['type'] === 'checklist' && $gf['outgoing_interface']['type'] === 'checklist' &&
    array_column($gf['active_interface']['options'], 'value') === array('all', 'lan', 'wan', 'lo0'), 'interfaces are checklists of the page\'s addresses ("all" first)');
check(array_column($gf['system_domain_local_zone_type']['options'], 'value') === array_keys(unbound_local_zone_types()) &&
    $gf['system_domain_local_zone_type']['default'] === 'transparent', 'zone types come from unbound_local_zone_types()');
check($gf['sslcertref']['options'] === array() && strpos($gf['sslcertref']['help'], 'No Certificates') === 0,
    'without certificates there is nothing to choose and the help says so');
check($gf['python_script']['options'] === array(array('value' => '', 'label' => 'No Python Module scripts found')),
    'python scripts come from unbound_python_scripts() (the page\'s placeholder when there are none)');
foreach (array('python_order', 'python_script') as $f) {
	check($gf[$f]['visibleWhen'] === array('field' => 'python', 'truthy' => true), "{$f} shows only with the Python module, like the page");
}
check($gf['custom_options']['type'] === 'textarea' && $gf['custom_options']['advanced'], 'custom options stay a textarea, in an advanced section');
check($gf['python']['advanced'] && $gf['dnssec']['type'] === 'switch' && !$gf['enable']['advanced'], 'Python module is advanced; the switches are switches');
$GLOBALS['backend'] = 'kea';
check(!isset($byname(restapi_schema_get('services/dns_resolver'))['regdhcp']) && !isset(restapi_unbound_types()['regdhcpstatic']),
    'with Kea neither the page, the API nor the schema offer DHCP registration');
$GLOBALS['backend'] = 'isc';
$GLOBALS['config']['cert'] = array(array('refid' => 'c1'));
$gf2 = $byname(restapi_schema_get('services/dns_resolver'));
check($gf2['sslcertref']['options'] === array(array('value' => 'c1', 'label' => 'Web & DNS')), 'certificates are the page\'s list (labels unescaped)');
unset($GLOBALS['config']['cert']);

$adv = restapi_schema_get('services/dns_resolver_advanced');
$af = $byname($adv);
$api_adv = array_keys(array_filter(restapi_unbound_adv_types(), function ($t) { return $t !== 'ro'; }));
$api_adv = array_values(array_diff($api_adv, array('dns64_prefix')));
sort($api_adv);
$schema_adv = array_keys($af);
sort($schema_adv);
check($schema_adv === $api_adv, 'the advanced schema has the API fields (the DNS64 prefix only when the page shows it): ' . implode(', ', array_diff($api_adv, $schema_adv)));
foreach (unbound_advanced_choices() as $key => $values) {
	check(array_column($af[$key]['options'], 'value') === $values && in_array($af[$key]['default'], $values, true),
	    "{$key} offers exactly unbound_advanced_choices() with a valid default");
}
check(array_column($af['msgcachesize']['options'], 'label')[0] === '4 MB' && array_column($af['infra_host_ttl']['options'], 'label')[4] === '15 minutes' &&
    array_column($af['unwanted_reply_threshold']['options'], 'label')[2] === '10 million' &&
    array_column($af['log_verbosity']['options'], 'label')[1] === 'Level 1: Basic operational information' &&
    array_column($af['edns_buffer_size']['options'], 'label')[0] === 'Automatic value based on active interface MTUs', 'select labels read like the page');
$GLOBALS['config']['system']['allow_nat64_prefix_override'] = true;
check(isset($byname(restapi_schema_get('services/dns_resolver_advanced'))['dns64_prefix']), 'the DNS64 prefix is offered when System > Advanced allows it');
unset($GLOBALS['config']['system']);

$hs = restapi_schema_get('services/dns_host_override');
check(array_keys(restapi_schema_fields($hs)) === array('host', 'domain', 'ip', 'descr', 'aliases', 'aliases.*.host', 'aliases.*.domain', 'aliases.*.description'),
    'host override schema: host, domain, ip, descr and the aliases grid');
$ds = restapi_schema_get('services/dns_domain_override');
check(array_keys(restapi_schema_fields($ds)) === array('domain', 'ip', 'forward_tls_upstream', 'tls_hostname', 'descr'), 'domain override schema');
$as = restapi_schema_get('services/dns_access_list');
check(array_keys(restapi_schema_fields($as)) === array('aclname', 'aclaction', 'description', 'networks', 'networks.*.acl_network', 'networks.*.mask',
    'networks.*.description'), 'access list schema: name, action, description and the networks grid (the page\'s columns)');
check(array_column($byname($as)['aclaction']['options'], 'value') === array_keys(unbound_acl_actions()) && $byname($as)['networks']['max'] === 50,
    'actions come from unbound_acl_actions(); at most 50 networks like the page');

/* ---- Every save message lands on its field ---- */
$cases = array(
	'services/dns_resolver' => array(
		array('The DNS Forwarder is enabled using this port. Choose a non-conflicting port, or disable the DNS Forwarder.', 'port'),
		array('Acting as an SSL/TLS server requires a valid server certificate', 'sslcertref'),
		array('At least one DNS server must be specified under System > General Setup to enable Forwarding mode.', 'forwarding'),
		array('One or more Network Interfaces must be selected for binding.', 'active_interface'),
		array('This system is configured to use the DNS Resolver as its DNS server, so Localhost or All must be selected in Network Interfaces.', 'active_interface'),
		array('One or more Outgoing Network Interfaces must be selected.', 'outgoing_interface'),
		array(array('%s is not an interface the DNS Resolver offers.', 'em9'), 'active_interface'),
		array('A valid port number must be specified.', 'port'),
		array('A valid SSL/TLS port number must be specified.', 'tlsport'),
		array('DHCP Server must be enabled for DHCP Registration to work in DNS Resolver.', 'regdhcp'),
		array('A System Domain Local Zone Type of "redirect" is not compatible with dynamic DHCP Registration.', 'system_domain_local_zone_type'),
		array('The submitted Python Module Script does not exist or is invalid.', 'python_script'),
		array('The generated config file cannot be parsed by unbound. Please correct the following errors:', 'custom_options'),
	),
	'services/dns_resolver_advanced' => array(
		array('A valid value for Message Cache Size must be specified.', 'msgcachesize'),
		array('A valid value must be specified for Outgoing TCP Buffers.', 'outgoing_num_tcp'),
		array('A valid value must be specified for Incoming TCP Buffers.', 'incoming_num_tcp'),
		array('A valid value must be specified for EDNS Buffer Size.', 'edns_buffer_size'),
		array('A valid value must be specified for Number of Queries per Thread.', 'num_queries_per_thread'),
		array('A valid value must be specified for Jostle Timeout.', 'jostle_timeout'),
		array("'Maximum TTL for RRsets and Messages' must be a positive integer.", 'cache_max_ttl'),
		array("'Minimum TTL for RRsets and Messages' must be a positive integer.", 'cache_min_ttl'),
		array('A valid value must be specified for TTL for Host Cache Entries.', 'infra_host_ttl'),
		array('A valid value must be specified for Number of Hosts to Cache.', 'infra_cache_numhosts'),
		array('A valid value must be specified for Unwanted Reply Threshold.', 'unwanted_reply_threshold'),
		array('A valid value must be specified for Log Level.', 'log_verbosity'),
		array('Harden DNSSEC Data option can only be enabled if DNSSEC support is enabled.', 'dnssecstripped'),
		array('The DNS64 prefix is invalid.', 'dns64_prefix'),
		array("'Drop Old UDP Queries' must be a non-negative integer.", 'sock_queue_timeout'),
	),
	'services/dns_host_override' => array(
		array(array('The field %s is required.', 'Domain'), 'domain'),
		array(array('The field %s is required.', 'IP address'), 'ip'),
		array("The hostname can only contain the characters A-Z, 0-9, '_' and '-'. It may not start or end with '-'.", 'host'),
		array('A valid hostname is specified, but the domain name part should be omitted', 'host'),
		array('A valid domain must be specified.', 'domain'),
		array('A valid IP addresses must be specified.', 'ip'),
		array(array('The field %s is required.', 'Alias Domain'), 'aliases'),
		array("Hostnames in an alias list can only contain the characters A-Z, 0-9 and '-'. They may not start or end with '-'.", 'aliases'),
		array('A valid alias hostname is specified, but the domain name part should be omitted', 'aliases'),
		array('A valid domain must be specified in alias list.', 'aliases'),
		array('This host/domain override combination already exists with an IPv4 address.', 'host'),
		array('This host/domain override combination already exists with an IPv6 address.', 'host'),
	),
	'services/dns_domain_override' => array(
		array(array('The field %s is required.', 'Domain'), 'domain'),
		array(array('The field %s is required.', 'IP address'), 'ip'),
		array('A valid domain must be specified after _msdcs.', 'domain'),
		array('A valid domain must be specified.', 'domain'),
		array('A valid IP address and port must be specified, for example 192.168.100.10@5353.', 'ip'),
		array('A valid IP address must be specified, for example 192.168.100.10.', 'ip'),
		array('The supplied TLS hostname is not valid.', 'tls_hostname'),
	),
	'services/dns_access_list' => array(
		array('A valid IP address must be entered for each row under Networks.', 'networks'),
		array('A valid IPv4 netmask must be entered for each IPv4 row under Networks.', 'networks'),
		array('A valid IPv6 address must be entered for {$networkacl[$x][\'acl_network\']}.', 'networks'),
		array('A valid IPv6 netmask must be entered for each IPv6 row under Networks.', 'networks'),
		array('A valid action must be selected.', 'aclaction'),
		array('The access list name cannot contain line breaks.', 'aclname'),
	),
);
$required = array('Domain', 'IP address', 'Alias Domain');
foreach ($cases as $resource => $list) {
	$schema = restapi_schema_get($resource);
	if ($resource === 'services/dns_resolver_advanced') {
		$GLOBALS['config']['system']['allow_nat64_prefix_override'] = true;
		$schema = restapi_schema_get($resource);
		unset($GLOBALS['config']['system']);
	}
	foreach ($list as list($template, $field)) {
		$msg = $template;
		if (is_array($template)) {
			list($template, $arg) = $template;
			$msg = sprintf($template, $arg);
			if (strpos($template, 'The field %s') === 0) {
				check(strpos($inc, "gettext(\"{$arg}\")") !== false, "the save function names the required field \"{$arg}\"");
				$template = null;
			}
		}
		if ($template !== null) {
			check(strpos($inc, $template) !== false, "services_unbound.inc still says \"{$template}\"");
		}
		$r = restapi_errors_to_fields(array($msg), $schema, array());
		check(array_keys($r['fields']) === array($field), "{$resource}: \"{$msg}\" lands on {$field}" .
		    (empty($r['fields']) ? ' (unmatched)' : ' (got ' . implode(', ', array_keys($r['fields'])) . ')'));
	}
}

/* ---- Host overrides ---- */
$e = error_fields(function () {
	call_route('restapi_h_unbound_host_create', 'services/dns_host_override', array('host' => 'bad host', 'domain' => 'example.org', 'ip' => '192.0.2.300',
	    'aliases' => array(array('host' => 'www', 'domain' => ''))));
});
check(isset($e['host'], $e['ip'], $e['aliases']) && count($e) === 3, 'a host override\'s messages are keyed to host, ip and the aliases grid');
check($GLOBALS['dirty'] === array() && empty($GLOBALS['writes']), 'a refused save changes nothing and stages nothing');

$out = call_route('restapi_h_unbound_host_create', 'services/dns_host_override', array('host' => 'nas', 'domain' => 'home.arpa',
    'ip' => '192.0.2.10,2001:db8::10', 'descr' => 'Storage', 'aliases' => array(array('host' => 'files', 'domain' => 'home.arpa', 'description' => 'SMB'),
    array('host' => '', 'domain' => 'nas.example.org'))));
check($out['status'] === 201, 'a host override is created (201)');
$h = $out['data'];
check($h['pending'] === true && is_subsystem_dirty('unbound') && $GLOBALS['applied'] === 0, 'saving stages the change like the page (pending, not applied)');
check($h['display'] === array('host' => 'nas.home.arpa', 'addresses' => array('192.0.2.10', '2001:db8::10'),
    'aliases' => array('files.home.arpa', 'nas.example.org'), 'description' => 'Storage'), 'host display: fqdn, addresses, aliases, description');
check($h['fields'] === array('host' => 'nas', 'domain' => 'home.arpa', 'ip' => '192.0.2.10,2001:db8::10', 'descr' => 'Storage',
    'aliases' => array(array('host' => 'files', 'domain' => 'home.arpa', 'description' => 'SMB'), array('host' => '', 'domain' => 'nas.example.org', 'description' => ''))),
    'host fields are the edit form (aliases as grid rows)');
check($h['id'] === 0 && $h['host'] === 'nas' && $h['ip'] === '192.0.2.10,2001:db8::10', 'the stored fields stay on the item');

call_route('restapi_h_unbound_host_create', 'services/dns_host_override', array('host' => 'app', 'domain' => 'home.arpa', 'ip' => '192.0.2.20'));
$list = restapi_h_unbound_host_list(array())['data'];
check(array_column($list, 'id') === array(0, 1) && $list[0]['display']['host'] === 'app.home.arpa' && $list[1]['display']['host'] === 'nas.home.arpa',
    'the list is sorted by host like the page');
check($list[0]['display']['aliases'] === array() && $list[0]['fields']['aliases'] === array(), 'without aliases the lists are empty');
$e = error_fields(function () {
	call_route('restapi_h_unbound_host_create', 'services/dns_host_override', array('host' => 'app', 'domain' => 'home.arpa', 'ip' => '192.0.2.21'));
});
check(array_keys($e) === array('host'), 'a duplicate host/domain is reported on the host');

/* A GET item is a valid PUT body (ids, fields and display are read-only). */
$item = $list[1];
$item['descr'] = 'NAS';
$out = call_route('restapi_h_unbound_host_update', 'services/dns_host_override', $item, array('id' => '1'));
check($out['data']['display']['description'] === 'NAS' && count($out['data']['display']['aliases']) === 2, 'a GET item round-trips as a PUT body');
$out = call_route('restapi_h_unbound_host_update', 'services/dns_host_override', array('host' => 'zz'), array('id' => '0'));
check($out['data']['id'] === 1 && $out['data']['display']['host'] === 'zz.home.arpa', 'a renamed host answers with its new (sorted) id');
$GLOBALS['config']['unbound']['hosts'][0]['ip'] = array('192.0.2.30', '192.0.2.31');
check(restapi_unbound_host_out(0)['display']['addresses'] === array('192.0.2.30', '192.0.2.31') && restapi_unbound_host_out(0)['fields']['ip'] === '192.0.2.30,192.0.2.31',
    'an address list stored as an array reads like the page shows it');

/* ---- Domain overrides ---- */
$e = error_fields(function () {
	call_route('restapi_h_unbound_domain_create', 'services/dns_domain_override', array('domain' => 'corp.example', 'ip' => '192.0.2.53@99999',
	    'tls_hostname' => 'not valid!'));
});
check(array_keys($e) === array('ip', 'tls_hostname'), 'domain override messages are keyed to ip and tls_hostname');
$out = call_route('restapi_h_unbound_domain_create', 'services/dns_domain_override', array('domain' => 'corp.example', 'ip' => '192.0.2.53@5353',
    'forward_tls_upstream' => true, 'tls_hostname' => 'dns.corp.example', 'descr' => 'Corp'));
check($out['status'] === 201 && $out['data']['display'] === array('domain' => 'corp.example', 'server' => '192.0.2.53@5353', 'tls' => true, 'description' => 'Corp'),
    'domain display: domain, server, tls, description');
check($out['data']['fields'] === array('domain' => 'corp.example', 'ip' => '192.0.2.53@5353', 'forward_tls_upstream' => true,
    'tls_hostname' => 'dns.corp.example', 'descr' => 'Corp'), 'domain fields are the edit form');
$out = call_route('restapi_h_unbound_domain_update', 'services/dns_domain_override', array('forward_tls_upstream' => false) + $out['data'], array('id' => '0'));
check($out['data']['display']['tls'] === false && $out['data']['fields']['tls_hostname'] === 'dns.corp.example', 'false clears TLS queries, the rest stays');

/* ---- Access lists ---- */
$e = error_fields(function () {
	call_route('restapi_h_unbound_acl_create', 'services/dns_access_list', array('aclname' => 'Lab', 'aclaction' => 'drop',
	    'networks' => array(array('acl_network' => '10.0.0.0', 'mask' => 8), array('acl_network' => '2001:zz::', 'mask' => 64, 'description' => 'v6'))));
});
check(isset($e['aclaction'], $e['networks'], $e['networks.1.acl_network']), 'access list messages: the action, the grid and the bad address\'s cell');
$out = call_route('restapi_h_unbound_acl_create', 'services/dns_access_list', array('aclname' => 'Lab', 'aclaction' => 'allow snoop', 'description' => 'Admins',
    'networks' => array(array('acl_network' => '10.0.0.0', 'mask' => 8, 'description' => 'Office'), array('network' => '2001:db8::/32'))));
$a = $out['data'];
check($out['status'] === 201 && $a['aclid'] === '7' && $a['networks'] === array(array('network' => '10.0.0.0/8', 'description' => 'Office'),
    array('network' => '2001:db8::/32', 'description' => '')), 'an access list is created from the grid rows (and "network" rows)');
check($a['display'] === array('name' => 'Lab', 'action' => 'Allow Snoop', 'networks' => array('10.0.0.0/8', '2001:db8::/32'), 'description' => 'Admins'),
    'access list display: name, action label, networks, description');
check($a['fields'] === array('aclname' => 'Lab', 'aclaction' => 'allow snoop', 'description' => 'Admins', 'networks' => array(
    array('acl_network' => '10.0.0.0', 'mask' => '8', 'description' => 'Office'), array('acl_network' => '2001:db8::', 'mask' => '32', 'description' => ''))),
    'access list fields are the edit form (networks as the page\'s columns)');
$out = call_route('restapi_h_unbound_acl_update', 'services/dns_access_list', array('networks' => array_slice($a['fields']['networks'], 0, 1)) + $a, array('id' => '0'));
check($out['data']['display']['networks'] === array('10.0.0.0/8') && $out['data']['aclid'] === '7', 'a PUT of the fields replaces the networks and keeps the ACL id');
$GLOBALS['config']['unbound']['acls'][0]['aclaction'] = 'Deny';
check(restapi_unbound_acl_out(0)['fields']['aclaction'] === 'deny' && restapi_unbound_acl_out(0)['display']['action'] === 'Deny',
    'a stored action in other case reads as the page selects it');
check(restapi_unbound_acl_rows(array(array('acl_network' => '192.0.2.0', 'mask' => 24))) === array('acl_network0' => '192.0.2.0', 'mask0' => '24', 'description0' => '') &&
    api_error(function () { restapi_unbound_acl_rows(array(array('acl_network' => array('x')))); })->status === 400, 'grid rows become the form\'s numbered fields');

/* ---- Advanced settings ---- */
$e = error_fields(function () {
	call_route('restapi_h_unbound_adv_set', 'services/dns_resolver_advanced', array('msgcachesize' => '3', 'dnssecstripped' => true, 'cache_max_ttl' => '-1'));
});
check(array_keys($e) === array('msgcachesize', 'cache_max_ttl', 'dnssecstripped'), 'advanced messages are keyed to their fields');
$out = call_route('restapi_h_unbound_adv_set', 'services/dns_resolver_advanced', array('msgcachesize' => '50', 'prefetch' => true));
check($out['data']['msgcachesize'] === '50' && $out['data']['prefetch'] === true && $out['data']['pending'] === true, 'advanced settings save (staged)');

/* ---- General settings ---- */
$GLOBALS['config']['unbound']['enable'] = true;
$GLOBALS['config']['unbound']['active_interface'] = 'all';
$GLOBALS['config']['unbound']['outgoing_interface'] = 'all';
$GLOBALS['config']['unbound']['custom_options'] = '';
$e = error_fields(function () {
	call_route('restapi_h_unbound_set', 'services/dns_resolver', array('active_interface' => array(), 'outgoing_interface' => array(), 'port' => '70000',
	    'forwarding' => true, 'python' => true, 'python_script' => 'nope'));
});
check(isset($e['active_interface'], $e['outgoing_interface'], $e['port'], $e['forwarding'], $e['python_script']) && count($e) === 5,
    'general messages are keyed to their fields');
$out = call_route('restapi_h_unbound_set', 'services/dns_resolver', array('active_interface' => array('lan', 'lo0'), 'custom_options' => "server:\r\n  verbosity: 1"));
check($out['data']['active_interface'] === array('lan', 'lo0') && $out['data']['pending'] === true &&
    base64_decode($GLOBALS['config']['unbound']['custom_options']) === "server:\n  verbosity: 1", 'general settings save like the page (staged)');

/* ---- Pending and apply ---- */
check(restapi_h_unbound_pending(array())['data'] === array('pending' => true), 'GET /pending reports staged changes');
$out = restapi_h_unbound_apply(array());
check($out['data'] === array('applied' => true, 'pending' => false) && $GLOBALS['applied'] === 1, 'POST /apply runs the pages\' Apply');
check(restapi_h_unbound_pending(array())['data'] === array('pending' => false), 'nothing pending after apply');
$out = restapi_h_unbound_domain_delete(array('params' => array('id' => '0'), 'query' => array()));
check($out['data']['pending'] === true && restapi_h_unbound_pending(array())['data']['pending'] === true, 'a delete stages like the page');
$out = restapi_h_unbound_acl_delete(array('params' => array('id' => '0'), 'query' => array('apply' => 'true')));
check($out['data']['pending'] === false && $out['data']['apply'] === array('applied' => true) && $GLOBALS['applied'] === 2, '?apply=true applies at once');
$apply_src = fn_source($inc, 'unbound_apply_changes');
check(strpos($apply_src, 'services_unbound_configure()') !== false && strpos($apply_src, "clear_subsystem_dirty('unbound')") !== false,
    'the apply function is the pages\' (reconfigures Unbound, clears the flag)');
foreach (array('unbound_save_general', 'unbound_save_host', 'unbound_save_domain_override', 'unbound_save_acl', 'unbound_save_advanced',
    'unbound_delete_override', 'unbound_delete_acl') as $fn) {
	check(strpos(fn_source($inc, $fn), "mark_subsystem_dirty(") !== false && strpos(fn_source($inc, $fn), 'services_unbound_configure') === false,
	    "{$fn}() stages its change (marks unbound dirty, applies nothing)");
}

/* ---- Route wiring ---- */
$routes = array();
foreach (restapi_routes_unbound() as $r) {
	$routes["{$r['method']} {$r['path']}"] = $r;
}
$base = '/v1/services/dns-resolver';
$expect = array(
	"PUT {$base}" => 'services/dns_resolver',
	"PUT {$base}/advanced" => 'services/dns_resolver_advanced',
	"POST {$base}/host-overrides" => 'services/dns_host_override',
	"PUT {$base}/host-overrides/{id}" => 'services/dns_host_override',
	"POST {$base}/domain-overrides" => 'services/dns_domain_override',
	"PUT {$base}/domain-overrides/{id}" => 'services/dns_domain_override',
	"POST {$base}/access-lists" => 'services/dns_access_list',
	"PUT {$base}/access-lists/{id}" => 'services/dns_access_list',
	"POST {$base}/acls" => 'services/dns_access_list',
	"PUT {$base}/acls/{id}" => 'services/dns_access_list',
);
foreach ($expect as $key => $schema) {
	check(isset($routes[$key]) && $routes[$key]['schema'] === $schema && $routes[$key]['write'], "{$key} saves through schema {$schema}");
}
check(isset($routes["GET {$base}/pending"]) && !$routes["GET {$base}/pending"]['write'] && $routes["GET {$base}/pending"]['handler'] === 'restapi_h_unbound_pending' &&
    $routes["GET {$base}/pending"]['page'] === 'services_unbound.php', 'GET /pending is a read on the general page\'s privilege');
foreach (array('GET', 'GET/{id}', 'POST', 'PUT/{id}', 'DELETE/{id}') as $m) {
	list($method, $suffix) = array_pad(explode('/', $m, 2), 2, '');
	$suffix = ($suffix === '') ? '' : "/{$suffix}";
	$new = $routes["{$method} {$base}/access-lists{$suffix}"] ?? null;
	$old = $routes["{$method} {$base}/acls{$suffix}"] ?? null;
	check($new && $old && $new['handler'] === $old['handler'] && $new['page'] === 'services_unbound_acls.php' && $old['page'] === 'services_unbound_acls.php',
	    "{$method} access-lists{$suffix} exists, and /acls{$suffix} still answers the same");
}
foreach ($routes as $key => $r) {
	if ($r['write'] && ($r['path'] !== "{$base}/apply")) {
		check(isset($r['query']['apply']), "{$key} accepts ?apply=true");
	}
	check($r['area'] === 'services.dns', "{$key} is in area services.dns");
}
check(strpos(file_get_contents("{$root}/src/etc/inc/restapi.inc"), "'dns-resolver'") !== false, 'the dns-resolver capability is announced');
check(strpos(file_get_contents("{$root}/.github/workflows/quality.yml"), 'RestApiDnsResolverSmokeTest') !== false, 'CI runs this test');

/* ---- 1.x pages are unchanged: they call the same functions ---- */
$pages = array(
	'services_unbound.php' => array('unbound_save_general($_POST)', 'unbound_apply_changes()', 'unbound_delete_override($_POST[\'type\'], $_POST[\'id\'])'),
	'services_unbound_host_edit.php' => array('unbound_save_host($_POST, $id)'),
	'services_unbound_domainoverride_edit.php' => array('unbound_save_domain_override($_POST, $id)'),
	'services_unbound_acls.php' => array('unbound_save_acl($_POST', 'unbound_delete_acl($id)', 'unbound_apply_changes()'),
	'services_unbound_advanced.php' => array('unbound_save_advanced($_POST)', 'unbound_apply_changes()'),
);
foreach ($pages as $page => $calls) {
	$src = file_get_contents("{$root}/src/usr/local/www/{$page}");
	foreach ($calls as $call) {
		check(strpos($src, $call) !== false, "{$page} still calls {$call}");
	}
}

echo "REST API DNS resolver smoke test passed.\n";
