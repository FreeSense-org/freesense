<?php
/* Standalone CI regression test for the Fleet management routes and fleet_node.inc; run with `php tests/RestApiManagementSmokeTest.php`. */

$root = dirname(__DIR__);
set_include_path(get_include_path() . PATH_SEPARATOR . realpath($root . '/src/etc/inc') .
    PATH_SEPARATOR . realpath($root . '/src/usr/local/FreeSense/include/www'));
if (!function_exists('gettext')) {
	function gettext($t) { return $t; }
}
function userHasPrivilege($user, $priv) { return false; }
require_once('restapi/framework.inc');
require_once('restapi/routes_v1.inc');
require_once('fleet_node.inc');

function check($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

function invalid_of(callable $fn) {
	try {
		$fn();
	} catch (InvalidArgumentException $e) {
		return $e->getMessage();
	}
	return null;
}

/* ---- routes ---- */
$routes = array();
foreach (restapi_routes_management() as $r) {
	$routes["{$r['method']} {$r['path']}"] = $r;
}
foreach (array('GET /v1/status/carp', 'GET /v1/management/status', 'GET /v1/management/policy-bundle',
    'PUT /v1/management/policy-bundle', 'GET /v1/management/vault', 'GET /v1/management/vault/{id}',
    'PUT /v1/management/vault/{id}', 'DELETE /v1/management/vault/{id}', 'POST /v1/management/leave') as $key) {
	check(isset($routes[$key]), "route {$key} exists");
	check(function_exists($routes[$key]['handler']), "handler of {$key} exists");
}
check($routes['GET /v1/status/carp']['scope'] === 'status:read' && $routes['GET /v1/status/carp']['page'] === 'status_carp.php',
    'CARP status: the CARP page grants it, status:read');
foreach ($routes as $key => $r) {
	if (strpos($key, '/v1/management/') !== false) {
		check($r['area'] === 'management' && $r['page'] === 'system_fleet.php', "{$key}: management area");
		check($r['write'] === in_array($r['method'], array('PUT', 'DELETE', 'POST'), true), "{$key}: PUT, DELETE and POST write; GET reads");
	}
}
check(array_key_exists('management', restapi_areas()) && restapi_area_privilege('management', 'write') === 'api-management-write',
    'the management area gives the api-management-* privileges');
check(in_array('management:write', restapi_all_scopes(), true), 'management:write is a scope');
$all = array();
foreach (restapi_routes_v1() as $r) {
	$all[] = "{$r['method']} {$r['path']}";
}
check(count(array_intersect(array_keys($routes), $all)) === count($routes), 'the v1 table includes the management routes');
check(count($all) === count(array_unique($all)), 'no duplicate routes');
list($r, $params) = restapi_match(restapi_routes_v1(), 'PUT', '/v1/management/vault/box-1.2026.cfg');
check($r['handler'] === 'restapi_h_mgmt_vault_put' && $params['id'] === 'box-1.2026.cfg', 'vault ids route');
$restapi_src = file_get_contents("{$root}/src/etc/inc/restapi.inc");
check(preg_match("/define\('RESTAPI_LEVEL', (\d+)\)/", $restapi_src, $m) && (int)$m[1] >= 9, 'API level 9 or later');
check(strpos($restapi_src, "'carp', 'management'") !== false, 'capabilities name carp and management');
check(strpos(file_get_contents("{$root}/src/usr/local/www/api/index.php"), "require_once('fleet_node.inc');") !== false,
    'the front controller loads fleet_node.inc');

/* ---- membership ---- */
$m = fleet_membership(array());
check(!$m['enrolled'] && $m['role'] === 'monitor' && $m['method'] === null && $m['vault_quota_pct'] === FLEET_VAULT_QUOTA_PCT,
    'no <fleet>: not enrolled, defaults');
$section = array('enable' => '', 'node_id' => 'n-0123456789', 'controller_url' => 'https://hq.example:8443',
    'controller_fingerprint' => 'AB:CD', 'method' => 'wireguard', 'role' => 'managed', 'joined' => '1760000000',
    'vault_quota_pct' => '10', 'controller_signing_key' => 'x');
$m = fleet_membership($section);
check($m['enrolled'] && $m['role'] === 'managed' && $m['joined'] === 1760000000 && $m['vault_quota_pct'] === 10 &&
    $m['controller']['fingerprint'] === 'ab:cd' && $m['controller']['signing_key'] === true, 'enrolled box (empty <enable/> counts)');
check(!fleet_membership(array('method' => 'carrier-pigeon') + $section)['enrolled'], 'an unknown method is not enrolled');
check(!fleet_membership(array_diff_key($section, array('enable' => 1)))['enrolled'], 'disabled is not enrolled');
check(fleet_membership(array('vault_quota_pct' => '90') + $section)['vault_quota_pct'] === FLEET_VAULT_QUOTA_PCT,
    'an out-of-range quota falls back to the default');
check(!isset(fleet_membership($section)['controller']['signing_key_pem']), 'the signing key itself is not reported');

/* ---- vault ---- */
check(fleet_vault_valid_id('box-1.2026_cfg') && !fleet_vault_valid_id('../x') && !fleet_vault_valid_id('.hidden') &&
    !fleet_vault_valid_id('a..b') && !fleet_vault_valid_id('a/b') && !fleet_vault_valid_id(str_repeat('a', 97)) &&
    !fleet_vault_valid_id(''), 'replica ids');
check(fleet_vault_quota_bytes(1000, 0, 5) === 50 && fleet_vault_quota_bytes(900, 100, 5) === 50 &&
    fleet_vault_quota_bytes(1000, 0, 99) === 500 && fleet_vault_quota_bytes(-5, 0, 5) === 0, 'quota: a share of free + used');
check(fleet_vault_fits(50, 0, 0, 50) && !fleet_vault_fits(51, 0, 0, 50) && fleet_vault_fits(40, 50, 40, 50),
    'fits: replacing a replica frees its old size');
$blob = random_bytes(300);
$good = array('data' => base64_encode($blob), 'sha256' => hash('sha256', $blob), 'owner' => 'n-0123456789',
    'kind' => 'config', 'created' => 1760000000, 'label' => 'nightly');
list($bytes, $meta) = fleet_vault_parse_upload($good);
check($bytes === $blob && $meta['size'] === 300 && $meta['owner'] === 'n-0123456789' && $meta['kind'] === 'config',
    'a valid upload parses');
check(strpos(invalid_of(function () use ($good) { fleet_vault_parse_upload(array('sha256' => str_repeat('0', 64)) + $good); }), 'sha256:') === 0,
    'a wrong hash is refused on sha256');
check(strpos(invalid_of(function () use ($good) { fleet_vault_parse_upload(array('data' => '***') + $good); }), 'data:') === 0,
    'bad base64 is refused on data');
check(strpos(invalid_of(function () use ($good) { fleet_vault_parse_upload(array('owner' => '../x') + $good); }), 'owner:') === 0,
    'the owner must be a node id');
check(strpos(invalid_of(function () use ($good) { fleet_vault_parse_upload(array('kind' => 'secret') + $good); }), 'kind:') === 0,
    'unknown kinds are refused');
check(strpos(invalid_of(function () use ($good) { fleet_vault_parse_upload(array('created' => '1760000000') + $good); }), 'created:') === 0,
    'created must be an integer');
check(strpos(invalid_of(function () use ($good) { fleet_vault_parse_upload($good + array('extra' => 1)); }), 'extra:') === 0,
    'unknown fields are refused');

/* ---- policy bundle ---- */
$key = openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'));
$pem = openssl_pkey_get_details($key)['key'];
$other = openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'));
$sign = function (array $bundle, $k) {
	$text = json_encode($bundle);
	openssl_sign($text, $sig, $k, OPENSSL_ALGO_SHA256);
	return array('bundle' => $text, 'signature' => base64_encode($sig));
};
$b2 = array('version' => 2, 'node_id' => 'n-0123456789', 'rules' => array(array('id' => 'SEC-012', 'mode' => 'lock')));
check(fleet_policy_bundle_verify($sign($b2, $key), $pem, 'n-0123456789', null)['version'] === 2, 'a signed bundle for this box');
check(fleet_policy_bundle_verify($sign(array('node_id' => '*') + $b2, $key), $pem, 'n-0123456789', array('version' => 2))['node_id'] === '*',
    'a fleet-wide bundle of the same version');
$code = function ($body, $current = null, $pem_used = null) use ($pem) {
	return strtok((string)invalid_of(function () use ($body, $current, $pem, $pem_used) {
		fleet_policy_bundle_verify($body, $pem_used ?? $pem, 'n-0123456789', $current);
	}), ':');
};
check($code($sign($b2, $other)) === 'bad_signature', 'another key\'s signature is refused');
$tampered = $sign($b2, $key);
$tampered['bundle'] = str_replace('lock', 'audit', $tampered['bundle']);
check($code($tampered) === 'bad_signature', 'a changed bundle is refused');
check($code($sign($b2, $key), array('version' => 3)) === 'stale_bundle', 'an older bundle is refused (no rollback)');
check($code($sign(array('node_id' => 'n-other-box1') + $b2, $key)) === 'wrong_node', 'a bundle for another box is refused');
check($code($sign(array('version' => '2') + $b2, $key)) === 'invalid_bundle', 'the version must be an integer');
check($code($sign($b2, $key), null, 'not a key') === 'no_signing_key', 'no controller key: refused');
check($code(array('bundle' => 'x', 'signature' => '***')) === 'invalid_signature', 'the signature must be base64');

/* ---- CARP ---- */
$vips = array(
	array('mode' => 'carp', 'uniqid' => 'a1', 'interface' => 'wan', 'vhid' => '1', 'subnet' => '198.51.100.10', 'subnet_bits' => '24', 'advskew' => '0'),
	array('mode' => 'ipalias', 'uniqid' => 'b2', 'interface' => 'lan', 'subnet' => '10.0.0.5', 'subnet_bits' => '24'),
	array('mode' => 'carp', 'uniqid' => 'c3', 'interface' => 'lan', 'vhid' => '2', 'subnet' => '10.0.0.1', 'subnet_bits' => '24', 'advskew' => '100'),
);
$states = array('a1' => 'MASTER', 'c3' => 'BACKUP');
$entries = fleet_carp_entries($vips, true, function ($id) use ($states) { return $states[$id] ?? ''; });
check(count($entries) === 2 && $entries[0]['state'] === 'MASTER' && $entries[1]['vhid'] === 2 && $entries[1]['advskew'] === 100 &&
    $entries[0]['address'] === '198.51.100.10/24', 'only CARP VIPs, typed');
check(fleet_carp_summary($entries, true) === 'mixed', 'MASTER + BACKUP is mixed');
$states['c3'] = 'MASTER';
check(fleet_carp_summary(fleet_carp_entries($vips, true, function ($id) use ($states) { return $states[$id]; }), true) === 'master', 'all MASTER');
$hs = fleet_hasync_summary(array('synchronizetoip' => '10.0.0.2', 'synchronizerules' => 'on', 'synchronizealiases' => 'on', 'synchronizenat' => '', 'pfsyncenabled' => 'on'));
check(($hs['peer'] === '10.0.0.2') && ($hs['sections'] === array('aliases', 'rules')), 'HA sync: the peer and the sections switched on');
check(fleet_hasync_summary(array('synchronizerules' => 'on'))['sections'] === array(), 'HA sync without a peer syncs nothing (the secondary of a pair)');
check(strpos((string)file_get_contents(__DIR__ . '/../src/etc/inc/restapi/routes_management.inc'), "'carp_vips' =>") !== false, 'the Fleet status carries each CARP address and its state');
$rm = (string)file_get_contents(__DIR__ . '/../src/etc/inc/restapi/routes_management.inc');
check((strpos($rm, "'/v1/management/ha-sync-user'") !== false) && (strpos($rm, "fleet_hasync_user_set(null)") !== false), 'the HA sync user can be set and removed through the management API');
check(strpos((string)file_get_contents(__DIR__ . '/../src/etc/inc/fleet_accounts.inc'), "'fleet-hasync'") !== false, 'users sync never touches the HA sync user');
$pk = openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'));
openssl_x509_export(openssl_csr_sign(openssl_csr_new(array('commonName' => 'pin-test'), $pk), null, $pk, 1), $pem);
openssl_x509_export(openssl_x509_read($pem), $pem2);
check((hash('sha256', fleet_pem_der($pem)) === openssl_x509_fingerprint($pem, 'sha256')) && (fleet_pem_der('junk') === ''),
    'the WebGUI certificate fingerprint is SHA-256 over its DER form (what TLS peer_fingerprint checks)');
$xc = (string)file_get_contents(__DIR__ . '/../src/etc/inc/xmlrpc_client.inc') . (string)file_get_contents(__DIR__ . '/../src/etc/inc/xmlrpc_pinned_request.inc');
check((strpos($xc, "'ssl_peer_fingerprint'") !== false) && (strpos($xc, "config_get_path('hasync/syncpeerpin'") !== false), 'HA sync pins the peer certificate when a fingerprint is set');
check(strpos((string)file_get_contents(__DIR__ . '/../src/etc/inc/restapi.inc'), "define('RESTAPI_LEVEL', 18);") !== false, 'API level 18: microcode_early and the CPU status fields (17: the crypto_ccp and crypto_ccr settings, 16: the crypto_qat setting, 15: the encrypted HA link, 14: the port probe, 13: controller endpoints, 12: virtual IPs and the HA sync user)');
require_once(__DIR__ . '/../src/etc/inc/fleet_halink.inc');
$hl = array('address' => '10.254.7.1', 'bits' => 30, 'peer_address' => '10.254.7.2', 'peer_public_key' => str_repeat('A', 43) . '=', 'peer_endpoint' => '', 'keepalive' => 0);
check(fleet_halink_errors($hl) === array() && fleet_halink_errors(array('peer_endpoint' => 'fw.example.net:51831', 'keepalive' => 25) + $hl) === array(),
    'an HA link request: a /30, both addresses in it, a public key, an optional endpoint');
check(count(fleet_halink_errors(array('bits' => 24) + $hl)) === 1 && count(fleet_halink_errors(array('peer_address' => '10.254.8.2') + $hl)) === 1 &&
    count(fleet_halink_errors(array('peer_address' => '10.254.7.1') + $hl)) === 1 && count(fleet_halink_errors(array('peer_public_key' => 'short') + $hl)) === 1 &&
    count(fleet_halink_errors(array('peer_endpoint' => 'fw.example.net') + $hl)) === 1 && count(fleet_halink_errors(array('peer_endpoint' => 'a b:1') + $hl)) === 1 &&
    count(fleet_halink_errors(array('keepalive' => -1) + $hl)) === 1, 'an HA link request is refused for a wrong prefix, another network, the same address, a bad key, a bad endpoint or keepalive');
check((fleet_halink_endpoint('') === array('', 0)) && (fleet_halink_endpoint('203.0.113.5:51831') === array('203.0.113.5', 51831)) && (fleet_halink_endpoint('x:70000') === null),
    'the HA link endpoint is host:port or empty');
$pp = fleet_halink_peer_post('tun_wg0', $hl['peer_public_key'], '10.254.7.2', 'fw.example.net:51831', 25);
check(($pp['endpoint'] === 'fw.example.net') && ($pp['port'] === '51831') && ($pp['persistentkeepalive'] === '25') && ($pp['address0'] === '10.254.7.2') && ($pp['address_subnet0'] === '32') &&
    (strpos($pp['descr'], FLEET_HALINK_MARK) === 0) && (fleet_halink_peer_post('tun_wg0', $hl['peer_public_key'], '10.254.7.2', '', 0)['endpoint'] === ''),
    'the HA link peer is reached only at its own address and carries the Fleet mark');
$tp = fleet_halink_tunnel_post('KEY', 51831, '10.254.7.1', 30);
check(($tp['listenport'] === '51831') && ($tp['address0'] === '10.254.7.1') && ($tp['address_subnet0'] === '30') && (strpos($tp['descr'], FLEET_HALINK_MARK) === 0), 'the HA link tunnel post');
check((fleet_halink_free_opt(array('wan', 'lan')) === 'opt1') && (fleet_halink_free_opt(array('wan', 'opt1', 'opt3')) === 'opt2') && (fleet_halink_handshake("KEY1	0
KEY2	1700000000
") === 1700000000) && (fleet_halink_handshake('') === 0),
    'the HA link takes the next free optN and reads the latest handshake');
check((fleet_halink_endpoint_hosts("KEY1	203.0.113.5:51831
KEY2	(none)
KEY3	198.51.100.7:40000
") === array('203.0.113.5', '198.51.100.7')) && (fleet_halink_endpoint_hosts('') === array()),
    'the hosts the HA link talks to are read from wg show endpoints (so their states can be dropped when the link goes)');
$rmh = (string)file_get_contents(__DIR__ . '/../src/etc/inc/restapi/routes_management.inc');
check((strpos($rmh, "'/v1/management/ha-link/prepare'") !== false) && (strpos($rmh, "restapi_route('PUT', '/v1/management/ha-link'") !== false) && (strpos($rmh, "restapi_route('DELETE', '/v1/management/ha-link'") !== false) &&
    (strpos($restapi_src, "'ha-link'") !== false), 'the HA link can be prepared, made, read and removed through the management API');
check((restapi_probe_address('203.0.113.5', 8443) === 'tcp://203.0.113.5:8443') && (restapi_probe_address('2001:db8::1', 8443) === 'tcp://[2001:db8::1]:8443') &&
    (restapi_probe_address('fleet.example.net', 443) === 'tcp://fleet.example.net:443') && (restapi_probe_address('a b', 1) === null) && (restapi_probe_address('x;rm', 1) === null) &&
    (restapi_probe_address('203.0.113.5', 70000) === null), 'the port probe connects only to an IP address or DNS name and a valid port');
check(fleet_carp_entries($vips, true, function ($id) { return 'weird'; })[0]['state'] === 'UNKNOWN', 'unexpected text is UNKNOWN');
check(fleet_carp_entries($vips, false, function ($id) { return 'MASTER'; })[0]['state'] === 'DISABLED' &&
    fleet_carp_summary($entries, false) === 'disabled', 'CARP disabled');
check(fleet_carp_summary(array(), true) === 'none', 'no CARP VIPs');

/* ---- rollback protection ---- */
check(fleet_rollback_protection(true, array()) && !fleet_rollback_protection(false, array()), 'ZFS boot environments protect by default; UFS does not');
check(!fleet_rollback_protection(true, array('enabled' => 'false')) && !fleet_rollback_protection(true, array('automatic_rollback' => 'false')),
    'turning off snapshots or rollback removes the protection');

echo "RestApiManagementSmokeTest: OK\n";
