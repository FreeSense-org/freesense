<?php
/*
 * Copyright (c) 2026 The FreeSense Project
 * SPDX-License-Identifier: Apache-2.0
 * Behavior tests for the security audit fixes, with appliance I/O isolated in namespaces.
 */

if (!function_exists('gettext')) {
	function gettext($text) { return $text; }
}

function audit_check(bool $ok, string $message): void {
	if (!$ok) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

function audit_functions(string $relative, array $names): string {
	$source = file_get_contents(dirname(__DIR__) . '/' . $relative);
	$code = '';
	foreach ($names as $name) {
		$start = strpos($source, "function {$name}(");
		audit_check($start !== false, "{$name} exists");
		$end = strpos($source, "\n}\n", $start);
		audit_check($end !== false, "{$name} ends at file scope");
		$code .= substr($source, $start, $end - $start + 3) . "\n";
	}
	return $code;
}

$work = sys_get_temp_dir() . '/freesense-audit-' . bin2hex(random_bytes(6));
mkdir($work, 0700);
$GLOBALS['audit_work'] = $work;
$GLOBALS['audit_users'] = array('alice' => array('name' => 'alice', 'uid' => '2000'));

$stubs = <<<'PHP'
namespace AuditSession;
function getUserEntry($name) {
	return isset($GLOBALS['audit_users'][$name]) ? array('idx' => 0, 'item' => $GLOBALS['audit_users'][$name]) :
	    array('idx' => null, 'item' => array('name' => $name));
}
function is_account_expired($name) { return ($GLOBALS['audit_users'][$name]['expires'] ?? '') === 'expired'; }
PHP;
eval($stubs . audit_functions('src/etc/inc/auth.inc', array('webgui_session_account_problem')));
$session = array('Username' => 'alice', 'authsource' => 'Local Database');
audit_check(AuditSession\webgui_session_account_problem($session) === null, 'enabled account remains signed in');
$GLOBALS['audit_users']['alice']['disabled'] = '';
audit_check(AuditSession\webgui_session_account_problem($session) !== null, 'empty disabled XML element invalidates an existing session');
unset($GLOBALS['audit_users']['alice']['disabled']);
$GLOBALS['audit_users']['alice']['expires'] = 'expired';
audit_check(AuditSession\webgui_session_account_problem($session) !== null, 'expired accounts lose their sessions');
unset($GLOBALS['audit_users']['alice']);
audit_check(AuditSession\webgui_session_account_problem($session) !== null, 'deleted local account cannot become a directory stand-in');
audit_check(AuditSession\webgui_session_account_problem(array('Username' => 'alice', 'authsource' => 'FreeSense Fleet (operator)')) !== null, 'deleted Fleet local account is refused');
audit_check(AuditSession\webgui_session_account_problem(array('Username' => 'directory-user', 'authsource' => 'LDAP/corp')) === null, 'directory sign-in is retained');

$stubs = <<<'PHP'
namespace AuditRoute;
use InvalidArgumentException;
use RuntimeException;
function private_state_dir($sub) { $p = $GLOBALS['audit_work'] . '/private/' . $sub; if (!is_dir($p)) mkdir($p, 0700, true); return $p; }
function lock($name, $op) { return true; }
function unlock($key) {}
function unserialize_data($raw, $default = null) { return unserialize($raw, array('allowed_classes' => false)); }
function file_put_contents_private($path, $data) { return file_put_contents($path, $data); }
function unlink_if_exists($path) { if (file_exists($path)) unlink($path); }
function is_subnet($value) { $p = explode('/', $value); return count($p) === 2 && filter_var($p[0], FILTER_VALIDATE_IP) && ctype_digit($p[1]) && (int)$p[1] <= (str_contains($p[0], ':') ? 128 : 32); }
function is_subnetv6($value) { return is_subnet($value) && str_contains($value, ':'); }
function is_ipaddr($value) { return filter_var(explode('%', $value)[0], FILTER_VALIDATE_IP) !== false && !preg_match('/[;\s$]/', $value); }
function mwexec($command, $mute = false) { $GLOBALS['audit_commands'][] = $command; return 0; }
function logger($level, $message) {}
PHP;
eval($stubs . audit_functions('src/etc/inc/system.inc', array('system_routing_state_path', 'system_routing_delete_command', 'system_routing_queue_deletions', 'system_routing_apply_deletions')));
$GLOBALS['audit_commands'] = array();
AuditRoute\system_routing_queue_deletions(array(array('target' => '192.0.2.0/24', 'gateway' => '192.0.2.1')));
AuditRoute\system_routing_queue_deletions(array(array('target' => '2001:db8::/64', 'gateway' => 'fe80::1%em0')));
audit_check(AuditRoute\system_routing_apply_deletions() === 0 && count($GLOBALS['audit_commands']) === 2, 'multiple edits retain and apply IPv4 and scoped IPv6 deletions');
audit_check(str_contains($GLOBALS['audit_commands'][1], '-inet6'), 'IPv6 keeps its route family');
audit_check(AuditRoute\system_routing_delete_command(array('target' => '192.0.2.0/24;id', 'gateway' => '')) === null, 'shell text cannot become a route');
file_put_contents(AuditRoute\system_routing_state_path('deletions'), serialize(array('/bin/echo untrusted')));
audit_check(AuditRoute\system_routing_apply_deletions() !== 0 && count($GLOBALS['audit_commands']) === 2, 'legacy command strings are refused without execution');
foreach (array('src/usr/local/FreeSense/include/www/system_routing.inc', 'src/usr/local/FreeSense/include/www/interfaces_edit.inc', 'src/usr/local/FreeSense/include/www/alias-utils.inc', 'src/usr/local/www/xmlrpc.php') as $relative) {
	$source = file_get_contents(dirname(__DIR__) . '/' . $relative);
	audit_check(str_contains($source, 'system_routing_apply_deletions()') && !str_contains($source, '.system_routes.apply'), "{$relative} uses the protected apply path");
}

$stubs = <<<'PHP'
namespace AuditDns;
function getUserEntry($name) { return array('item' => array('name' => $name)); }
function userHasPrivilege($user, $priv) { return in_array($priv, $user['priv'] ?? array(), true); }
function config_get_path($path, $default = null) { return $GLOBALS['audit_config'][$path] ?? $default; }
function config_path_enabled($section, $flag) { return !empty($GLOBALS['audit_config'][$section . '/' . $flag]); }
PHP;
eval($stubs . audit_functions('src/etc/inc/services.inc', array('services_dns_custom_editable', 'services_dns_custom_enforce')));
$GLOBALS['audit_config'] = array('unbound/custom_options' => base64_encode('server: verbosity: 1'), 'unbound/python' => true, 'unbound/python_script' => 'existing.py');
$post = array('custom_options' => 'server: username: root', 'python_script' => 'different.py'); $errors = array();
AuditDns\services_dns_custom_enforce('unbound', $post, $errors, array('name' => 'operator'));
audit_check(count($errors) > 0 && $post['custom_options'] === 'server: verbosity: 1' && $post['python_script'] === 'existing.py', 'unauthorized changes are rejected and stored options preserved');
$post = array('port' => '5353'); $errors = array();
AuditDns\services_dns_custom_enforce('unbound', $post, $errors);
audit_check(!$errors && isset($post['python']) && $post['custom_options'] === 'server: verbosity: 1', 'ordinary GUI saves preserve omitted read-only fields, including enabled Python');
audit_check(AuditDns\services_dns_custom_editable('unbound', array('uid' => '0')), 'administrator retains custom DNS access');
audit_check(AuditDns\services_dns_custom_editable('dnsmasq', array('priv' => array('page-services-dnsforwarder-custom'))), 'dedicated privilege grants custom forwarder access');
audit_check(!AuditDns\services_dns_custom_editable('unbound', array('priv' => array('page-services-dnsforwarder-custom'))), 'forwarder privilege does not grant resolver access');
audit_check(AuditDns\services_dns_custom_editable('unbound', array('__pages' => array('services_unbound.php?custom_config=*'))), 'directory REST sessions retain the explicit custom privilege');

$stubs = <<<'PHP'
namespace AuditPppoe;
use InvalidArgumentException;
function array_get_path($values, $path, $default = null) { foreach (explode('/', $path) as $key) { if (!is_array($values) || !array_key_exists($key, $values)) return $default; $values = $values[$key]; } return $values; }
PHP;
eval($stubs . audit_functions('src/etc/inc/vpn.inc', array('vpn_pppoe_radius_value')));
$pppoe = array('radius' => array('nasip' => '192.0.2.3', 'acct_update' => '60', 'server' => array('nasip' => '192.0.2.4', 'acct_update' => '30')));
audit_check(AuditPppoe\vpn_pppoe_radius_value($pppoe, 'nasip') === '192.0.2.3' && AuditPppoe\vpn_pppoe_radius_value($pppoe, 'acct_update') === '60', 'PPPoE form values take precedence');
unset($pppoe['radius']['nasip']);
audit_check(AuditPppoe\vpn_pppoe_radius_value($pppoe, 'nasip') === '192.0.2.4', 'older nested backup values remain compatible');


$stubs = <<<'PHP'
namespace AuditDownload;
use Throwable;
function curl_init() { return (object)array('options' => array()); }
function curl_setopt_array($ch, $options) { $ch->options = $options; $GLOBALS['audit_curl_options'] = $options; return true; }
function curl_setopt($ch, $key, $value) { $ch->options[$key] = $value; return true; }
function curl_exec($ch) {
	$data = "192.0.2.1\n";
	$writer = $ch->options[CURLOPT_WRITEFUNCTION];
	$ok = ($writer($ch, $data) === strlen($data));
	$GLOBALS['audit_curl_errno'] = $ok ? (($GLOBALS['audit_transfer'] === 'truncated') ? 18 : 0) : 23;
	return $ok && ($GLOBALS['audit_transfer'] !== 'truncated');
}
function curl_errno($ch) { return $GLOBALS['audit_curl_errno']; }
function curl_getinfo($ch, $key) { return ($GLOBALS['audit_transfer'] === 'http-error') ? 500 : 200; }
function set_curlproxy($ch) {}
function g_get($key) { return 'audit'; }
function config_path_enabled($section, $key) { return !empty($GLOBALS['audit_config'][$section . '/' . $key]); }
function unlink_if_exists($path) { if (file_exists($path)) unlink($path); }
function logger($level, $message) {}
PHP;
$options = array('CURLOPT_URL', 'CURLOPT_PROTOCOLS_STR', 'CURLOPT_REDIR_PROTOCOLS_STR', 'CURLOPT_FOLLOWLOCATION',
    'CURLOPT_MAXREDIRS', 'CURLOPT_CAPATH', 'CURLOPT_SSL_VERIFYHOST', 'CURLOPT_SSL_VERIFYPEER',
    'CURLOPT_CONNECTTIMEOUT', 'CURLOPT_TIMEOUT', 'CURLOPT_LOW_SPEED_LIMIT', 'CURLOPT_LOW_SPEED_TIME',
    'CURLOPT_USERAGENT', 'CURLOPT_WRITEFUNCTION', 'CURLOPT_HEADERFUNCTION', 'CURLINFO_RESPONSE_CODE');
foreach ($options as $i => $option) {
	define('AuditDownload\\' . $option, $i + 1);
}
eval($stubs . audit_functions('src/etc/inc/freesense-utils.inc', array('download_file_transfer', 'download_file',
    'download_file_with_progress_bar', 'alias_url_verify_tls', 'alias_archive_copy', 'alias_unpack_archive')));
$destination = $work . '/feed.txt';
$GLOBALS['audit_transfer'] = 'complete';
audit_check(AuditDownload\download_file('https://example.invalid/feed', $destination), 'complete HTTP 200 transfer succeeds');
$expected = file_get_contents($destination);
foreach (array('truncated', 'http-error') as $failure) {
	$GLOBALS['audit_transfer'] = $failure;
	audit_check(!AuditDownload\download_file('https://example.invalid/feed', $destination), "{$failure} is refused");
	audit_check(file_get_contents($destination) === $expected, "{$failure} retains the previous alias feed");
}
$GLOBALS['audit_transfer'] = 'complete';
audit_check(!AuditDownload\download_file_transfer('https://example.invalid/feed', $destination, true, 5, 0, null, 4), 'byte budget stops an oversized response');
audit_check(file_get_contents($destination) === $expected, 'oversized response retains the old file');
audit_check($GLOBALS['audit_curl_options'][AuditDownload\CURLOPT_TIMEOUT] === 120 &&
    $GLOBALS['audit_curl_options'][AuditDownload\CURLOPT_PROTOCOLS_STR] === 'http,https' &&
    $GLOBALS['audit_curl_options'][AuditDownload\CURLOPT_REDIR_PROTOCOLS_STR] === 'https', 'zero timeout is bounded and original/redirect protocols are restricted');
$GLOBALS['audit_config'] = array();
audit_check(AuditDownload\alias_url_verify_tls(), 'HTTPS verification is the default');
$GLOBALS['audit_config']['system/allowinsecurealiasesurl'] = true;
audit_check(!AuditDownload\alias_url_verify_tls(), 'an explicit compatibility exception is honored');
audit_check(!glob($work . '/.download-*'), 'failed transfers clean up temporary files');

/* Real tar process integration: preserve member boundaries without extracting members. */
if (is_executable('/usr/bin/tar')) {
	mkdir($work . '/members');
	file_put_contents($work . '/members/a.txt', '192.0.2.1');
	file_put_contents($work . '/members/b.txt', '198.51.100.1');
	$archive = $work . '/feed.tgz';
	$process = proc_open(array('/usr/bin/tar', '-czf', $archive, '-C', $work . '/members', 'b.txt', 'a.txt'), array(), $pipes);
	audit_check(proc_close($process) === 0, 'test archive is created');
	audit_check(AuditDownload\alias_unpack_archive($archive), 'ordinary multi-member alias archive is readable');
	audit_check(file_get_contents($archive) === "192.0.2.1\n198.51.100.1\n", 'members remain sorted with a newline between them');
	$out = fopen('php://temp', 'w+b'); $budget = 4;
	audit_check(!AuditDownload\alias_archive_copy(array(PHP_BINARY, '-r', 'echo str_repeat("x", 8);'), $out, $budget, microtime(true) + 5), 'archive output budget refuses expansion');
	fclose($out);
	$out = fopen('php://temp', 'w+b'); $budget = 4;
	audit_check(!AuditDownload\alias_archive_copy(array(PHP_BINARY, '-r', 'usleep(500000);'), $out, $budget, microtime(true) - 1), 'archive deadline terminates a stalled process');
	fclose($out);
}

$stubs = <<<'PHP'
namespace AuditRecovery;
function private_state_dir($sub) { $dir = $GLOBALS['audit_work'] . '/private/' . $sub; if (!is_dir($dir)) mkdir($dir, 0700, true); return $dir; }
function file_put_contents_private($path, $data) { $n = file_put_contents($path, $data); chmod($path, 0600); return $n; }
function unlink_if_exists($path) { if (file_exists($path)) unlink($path); }
function logger($level, $message) {}
PHP;
eval($stubs . audit_functions('src/etc/inc/config.lib.inc', array('disable_security_checks', 'restore_security_checks', 'security_checks_disabled')));
audit_check(!AuditRecovery\security_checks_disabled(), 'recovery access is absent by default');
if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
	AuditRecovery\disable_security_checks();
	audit_check(AuditRecovery\security_checks_disabled(), 'a root-only temporary recovery marker works');
	$marker = $work . '/private/webgui/recovery';
	file_put_contents($marker, (string)(time() - 1));
	audit_check(!AuditRecovery\security_checks_disabled(), 'expired recovery access is refused');
	file_put_contents($marker, (string)(time() + 3600));
	audit_check(!AuditRecovery\security_checks_disabled(), 'an overlong recovery window is refused');
	AuditRecovery\disable_security_checks(); chmod($marker, 0666);
	audit_check(!AuditRecovery\security_checks_disabled(), 'a writable recovery marker is refused');
	unlink($marker); symlink($destination, $marker);
	audit_check(!AuditRecovery\security_checks_disabled(), 'a recovery symlink is refused');
	unlink($marker);
}
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($iterator as $entry) {
	$entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
}
rmdir($work);
echo "Audit remediation smoke test passed.\n";
