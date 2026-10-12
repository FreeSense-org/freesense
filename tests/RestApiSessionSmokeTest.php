<?php
/*
 * Standalone CI regression test for the WebUI session bridge
 * (src/etc/inc/restapi_session.inc); run with `php tests/RestApiSessionSmokeTest.php`.
 *
 * The bridge functions are extracted from the source and run in a separate
 * PHP process with stub configuration, session and user functions.
 */

$root = dirname(__DIR__);
$inc = file_get_contents("{$root}/src/etc/inc/restapi_session.inc");
$front = file_get_contents("{$root}/src/usr/local/www/api/index.php");
$restapi = file_get_contents("{$root}/src/etc/inc/restapi.inc");
$routes = file_get_contents("{$root}/src/etc/inc/restapi/routes_v1.inc");

function check($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

/* The source of one top-level function, without its closing brace. */
function fn_source($src, $name) {
	$start = strpos($src, "function {$name}(");
	check($start !== false, "function {$name}() exists");
	$end = strpos($src, "\n}\n", $start);
	return substr($src, $start, $end - $start);
}

$fns = array('restapi_session_requested', 'restapi_session_cookie_id', 'restapi_session_problem',
    'restapi_session_authenticate', 'restapi_session_user', 'restapi_session_token');
$code = '';
foreach ($fns as $fn) {
	$code .= fn_source($inc, $fn) . "\n}\n\n";
}
$code .= fn_source($restapi, 'restapi_user_allowed_pages') . "\n}\n\n";

$harness = <<<'PHP'
<?php
class RestApiError extends Exception {
	public $status; public $code_;
	function __construct($status, $code, $message) { parent::__construct($message); $this->status = $status; $this->code_ = $code; }
}
define('RESTAPI_SESSION_TOKEN_KEY', 'fs_webui_csrf');
define('LOG_AUTH_EVENT_ERROR', 3);
$GLOBALS['cfg'] = array('system/webgui/session_timeout' => 240, 'system/webgui/roaming' => 'enabled');
$GLOBALS['proto'] = 'https';
$GLOBALS['sess'] = array();
$GLOBALS['users'] = array();
$GLOBALS['authlog'] = array();
function config_get_path($p, $d = null) { return $GLOBALS['cfg'][$p] ?? $d; }
function webgui_request_protocol() { return $GLOBALS['proto']; }
function restapi_session_read($id) { return $GLOBALS['sess']; }
function log_auth_event($e, $who, $ip, $msg) { $GLOBALS['authlog'][] = $msg; }
function restapi_local_user($name) { $u = $GLOBALS['users'][$name] ?? null; return (is_array($u) && !(isset($u['disabled']) && ($u['disabled'] !== false))) ? $u : null; }
function getUserEntry($name) {
	if (isset($GLOBALS['users'][$name])) return array('idx' => 0, 'item' => $GLOBALS['users'][$name]);
	return ($GLOBALS['cfg']['authmode'] ?? 'Local Auth') === 'Local Auth' ? null : array('idx' => null, 'item' => array('name' => $name));
}
function getPrivPages($u, &$pages) { foreach (($u['priv'] ?? array()) as $p) $pages[] = $p; }
function local_user_get_groups($u) { return array(); }
/* phpsession_* stubs for restapi_session_token() */
$GLOBALS['opened'] = 0; $GLOBALS['written'] = 0;
function phpsession_begin() { $GLOBALS['opened']++; }
function phpsession_end($write = false) { if ($write) $GLOBALS['written']++; }
$fail = 0;
function t($ok, $what) { global $fail; if (!$ok) { $fail++; echo "FAIL: {$what}\n"; } }
function status_of(callable $f) { try { $f(); } catch (RestApiError $e) { return $e->status; } return null; }
PHP;

$tests = <<<'PHP'
$now = time();
$good = array('Logged_In' => 'True', 'Username' => 'alice', 'protocol' => 'https', 'REMOTE_ADDR' => '192.0.2.10',
    'last_access' => $now - 60, 'authsource' => 'Local Database', 'fs_webui_csrf' => str_repeat('a', 64));
$GLOBALS['users']['alice'] = array('name' => 'alice', 'priv' => array('page-firewall-rules'));

/* Which requests use the bridge */
$_COOKIE[session_name()] = 'abcdefghijklmnopqrstuvwxyz012345';
$_SERVER['HTTP_X_CSRF_TOKEN'] = 'x';
t(restapi_session_requested(''), 'cookie + CSRF header and no Authorization uses the session bridge');
t(!restapi_session_requested('Bearer fsk_x'), 'an Authorization header always means an API key');
unset($_SERVER['HTTP_X_CSRF_TOKEN']);
t(!restapi_session_requested(''), 'without the CSRF header the request is not a WebUI request');
$_SERVER['HTTP_X_CSRF_TOKEN'] = 'x';
$_COOKIE[session_name()] = '../../etc/passwd';
t(!restapi_session_requested(''), 'a malformed session id is ignored');
$_COOKIE[session_name()] = 'abcdefghijklmnopqrstuvwxyz012345';

/* Session checks mirror session_auth() */
t(restapi_session_problem($good, '192.0.2.10') === null, 'a signed-in, fresh session is usable');
t(restapi_session_problem(array(), '192.0.2.10') === 'not signed in', 'an empty session is not signed in');
t(restapi_session_problem(array('Logged_In' => 'True') + $good, '192.0.2.10') === null, 'Logged_In must be set');
$GLOBALS['proto'] = 'http';
t(restapi_session_problem($good, '192.0.2.10') === 'protocol changed', 'a session from HTTPS is not used over HTTP');
$GLOBALS['proto'] = 'https';
t(restapi_session_problem($good, '198.51.100.9') === null, 'roaming enabled: another address is fine');
$GLOBALS['cfg']['system/webgui/roaming'] = 'disabled';
t(restapi_session_problem($good, '198.51.100.9') === 'address changed', 'roaming disabled: another address is refused');
$GLOBALS['cfg']['system/webgui/roaming'] = 'enabled';
t(restapi_session_problem(array('last_access' => $now - 241 * 60) + $good, '192.0.2.10') === 'session expired', 'a stale session is refused');
$GLOBALS['cfg']['system/webgui/session_timeout'] = 0;
t(restapi_session_problem(array('last_access' => $now - 99999) + $good, '192.0.2.10') === null, 'timeout 0 never expires');
$GLOBALS['cfg']['system/webgui/session_timeout'] = 240;

/* Authentication */
$GLOBALS['sess'] = $good;
$_SERVER['HTTP_X_CSRF_TOKEN'] = str_repeat('a', 64);
$ctx = restapi_session_authenticate('192.0.2.10');
t($ctx['session'] === true && $ctx['user']['name'] === 'alice' && $ctx['token']['id'] === 'session' && $ctx['token']['scopes'] === '' &&
    empty($ctx['token']['readonly']) && $ctx['authsource'] === 'Local Database', 'a valid session authenticates as its user, unlimited like the GUI');
$_SERVER['HTTP_X_CSRF_TOKEN'] = str_repeat('b', 64);
t(status_of(function () { restapi_session_authenticate('192.0.2.10'); }) === 403 && count($GLOBALS['authlog']) === 1,
    'a wrong CSRF token is 403 and logged for login protection');
$GLOBALS['sess'] = array('fs_webui_csrf' => '') + $good;
$_SERVER['HTTP_X_CSRF_TOKEN'] = '';
t(status_of(function () { restapi_session_authenticate('192.0.2.10'); }) === 403, 'a session without a WebUI token never matches (even an empty header)');
$GLOBALS['sess'] = array('last_access' => $now - 999999) + $good;
$_SERVER['HTTP_X_CSRF_TOKEN'] = str_repeat('a', 64);
t(status_of(function () { restapi_session_authenticate('192.0.2.10'); }) === 401, 'an expired session is 401');
$GLOBALS['sess'] = array();
t(status_of(function () { restapi_session_authenticate('192.0.2.10'); }) === 401, 'no session is 401');
$GLOBALS['sess'] = $good;
$GLOBALS['users']['alice']['disabled'] = '';
t(status_of(function () { restapi_session_authenticate('192.0.2.10'); }) === 401, 'a user disabled after signing in is refused');
unset($GLOBALS['users']['alice']['disabled']);
$GLOBALS['users']['alice']['expires'] = '01/01/2000';
t(status_of(function () { restapi_session_authenticate('192.0.2.10'); }) === 401, 'an expired account is refused');
unset($GLOBALS['users']['alice']['expires']);

/* Remote (LDAP/RADIUS) users get the pages the GUI computed at sign-in */
$GLOBALS['cfg']['authmode'] = 'ldap';
$GLOBALS['sess'] = array('Username' => 'bob', 'authsource' => 'LDAP/corp', 'page-match' => array('page-dashboard-all', 'page-status-gateways')) + $good;
$ctx = restapi_session_authenticate('192.0.2.10');
t($ctx['user']['name'] === 'bob' && $ctx['user']['__pages'] === array('page-dashboard-all', 'page-status-gateways'), 'a remote user carries the session\'s pages');
t(restapi_user_allowed_pages($ctx['user']) === array('page-dashboard-all', 'page-status-gateways'), 'authorization uses those pages');
$GLOBALS['cfg']['authmode'] = 'Local Auth';
$GLOBALS['sess'] = array('Username' => 'ghost') + $good;
t(status_of(function () { restapi_session_authenticate('192.0.2.10'); }) === 401, 'a local-auth session for a removed user is refused');
/* Default installs have no system/webgui/authmode: getUserEntry() then returns a stand-in for any name */
$GLOBALS['cfg']['authmode'] = 'unset';
$GLOBALS['sess'] = array('Username' => 'ghost', 'page-match' => array('firewall_rules.php*')) + $good;
t(status_of(function () { restapi_session_authenticate('192.0.2.10'); }) === 401, 'a removed local user is refused when authmode is unset (session from the local database)');
$GLOBALS['sess'] = array('Username' => 'ghost', 'authsource' => 'Local Database Fallback') + $good;
t(status_of(function () { restapi_session_authenticate('192.0.2.10'); }) === 401, 'also after a local-database fallback sign-in');
$GLOBALS['cfg']['authmode'] = 'Local Auth';

/* The page frame's token */
$_SESSION = array('Logged_In' => 'True');
$tok = restapi_session_token();
t(preg_match('/^[0-9a-f]{64}$/', $tok) && $GLOBALS['written'] === 1, 'a token is created once and stored in the session');
t(restapi_session_token() === $tok && $GLOBALS['written'] === 1, 'later calls return the same token without writing the session');
$_SESSION = array();
t(restapi_session_token() === '', 'no token without a signed-in session');

echo $fail ? "FAILED {$fail}\n" : "ALL OK\n";
PHP;

$file = tempnam(sys_get_temp_dir(), 'sesstest');
file_put_contents($file, $harness . "\n" . $code . $tests);
$out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1');
unlink($file);
check(trim($out) === 'ALL OK', "session bridge rules hold:\n{$out}");

/* Front controller wiring */
check(strpos($front, "require_once('restapi_session.inc');") !== false, 'the front controller loads the bridge');
check(strpos($front, '$webui = restapi_session_requested($authorization);') !== false &&
    strpos($front, "throw new RestApiError(404, 'not_on_listener'") < strpos($front, 'restapi_session_authenticate($remote_ip)'),
    'WebUI sessions are refused on API listeners, before authenticating');
check(strpos($front, "if (\$webui) {\n\t\t\$restapi_log_ctx['k'] = 'session';\n\t\t\$ctx = restapi_session_authenticate(\$remote_ip);") !== false,
    'session requests authenticate through the bridge');
check(strpos($front, "if (!restapi_enabled()) {") > strpos($front, '} else {') &&
    strpos($front, "elseif (!\$settings['guiapi'])") > strpos($front, '$webui = restapi_session_requested'),
    'the API-key settings (enabled, WebGUI port, HTTPS, allowed networks) apply only to API keys');
check(strpos($front, "(\$restapi_log_ctx['k'] === 'session') && !\$write && (\$status < 400)") !== false,
    'successful WebUI reads are not written to the API request log');
check(strpos($front, "((\$e->status === 401) && !\$webui) ? array('WWW-Authenticate'") !== false, 'no Bearer challenge for WebUI requests');
check(strpos($front, "\"{\$ctx['authsource']} (WebUI)\"") !== false, 'config revisions name the WebUI as the source');
check(strpos($restapi, "define('RESTAPI_LEVEL', ") !== false && strpos($routes, "restapi_route('GET', '/v1/meta', 'restapi_h_meta'") !== false &&
    strpos($routes, "'api_level' => RESTAPI_LEVEL,") !== false, 'GET /api/v1/meta reports the API level');
check(strpos($inc, "'use_cookies' => 0") !== false && strpos($inc, "'read_and_close' => true") !== false,
    'the bridge reads the session without locking it, extending it or sending a cookie');

echo "REST API session bridge smoke test passed.\n";
