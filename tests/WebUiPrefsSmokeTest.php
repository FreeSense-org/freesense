<?php
/*
 * Standalone CI regression test for the caller's WebUI data
 * (src/etc/inc/webui_prefs.inc, src/etc/inc/restapi/routes_me.inc);
 * run with `php tests/WebUiPrefsSmokeTest.php`.
 */

$root = dirname(__DIR__);
$src = file_get_contents("{$root}/src/etc/inc/webui_prefs.inc");

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

$tmp = sys_get_temp_dir() . '/webui-prefs-test-' . getmypid();
@mkdir($tmp, 0700, true);
define('WEBUI_PREFS_DIR', "{$tmp}/conf");
define('WEBUI_LAYOUT_MAX_BYTES', 65536);
$GLOBALS['cfg'] = array('system/webgui/session_timeout' => 240, 'system/webui/defaults' => array('theme' => 'freesense', 'accent' => 'blue'));
function config_get_path($p, $d = null) { return $GLOBALS['cfg'][$p] ?? $d; }

foreach (array('webui_user_file', 'webui_read_json', 'webui_write_json', 'webui_pref_defaults', 'webui_pref_validate', 'webui_prefs_get',
    'webui_prefs_put', 'webui_layout_validate', 'webui_layout_get', 'webui_layout_put', 'webui_layout_delete', 'webui_initials',
    'webui_profile_validate', 'webui_session_decode', 'webui_serialized_end', 'webui_session_public_id', 'webui_sessions_list',
    'webui_session_revoke') as $fn) {
	eval(fn_source($src, $fn));
}

/* Preferences */
check(webui_pref_defaults()['theme'] === 'freesense' && webui_pref_defaults()['accent'] === 'blue' && webui_pref_defaults()['mode'] === 'auto',
    'administrator defaults apply to users without preferences');
list($v, $e) = webui_pref_validate(array('mode' => 'dark', 'accent' => 'teal', 'density' => 'compact', 'start_page' => '/network/interfaces'));
check($e === array() && $v['mode'] === 'dark', 'valid preferences pass');
list($v, $e) = webui_pref_validate(array('mode' => 'neon', 'theme' => '../../x', 'colour' => 'red', 'start_page' => 'javascript:alert(1)'));
check(isset($e['mode'], $e['theme'], $e['colour'], $e['start_page']) && $v === array(), 'bad values, unknown keys and non-path start pages are refused');
check(webui_prefs_get('alice')['mode'] === 'auto', 'no saved preferences: defaults');
check(webui_prefs_put('alice', array('mode' => 'dark'))['mode'] === 'dark' && webui_prefs_put('alice', array('accent' => 'teal'))['mode'] === 'dark',
    'preferences are merged and kept');
check(webui_prefs_get('bob')['mode'] === 'auto', 'preferences are per user');
check(basename(webui_user_file('prefs', '../../etc/passwd')) === hash('sha256', '../../etc/passwd') . '.json', 'file names are hashes of the user name');
file_put_contents(webui_user_file('prefs', 'eve'), json_encode(array('mode' => '<script>', 'theme' => 'midnight')));
check(webui_prefs_get('eve')['mode'] === 'auto' && webui_prefs_get('eve')['theme'] === 'midnight', 'a tampered file never yields invalid values');

/* Layout */
$w = array(array('id' => 'w1', 'type' => 'traffic', 'size' => 'xl', 'settings' => array('iface' => 'wan')), array('id' => 'w2', 'type' => 'system'));
list($l, $err) = webui_layout_validate($w);
check($err === null && $l[1]['size'] === 'md' && $l[1]['collapsed'] === false && $l[0]['settings'] === array('iface' => 'wan'), 'a layout is normalised');
check(webui_layout_validate(array(array('id' => 'w1', 'type' => 'a'), array('id' => 'w1', 'type' => 'b')))[1] !== null, 'duplicate widget ids are refused');
check(webui_layout_validate(array(array('id' => 'x y', 'type' => 'a')))[1] !== null && webui_layout_validate(array('a' => 1))[1] !== null &&
    webui_layout_validate(array(array('id' => 'a', 'type' => 'a', 'size' => 'huge')))[1] !== null, 'bad ids, maps and sizes are refused');
check(webui_layout_validate(array_fill(0, 65, array('id' => 'a', 'type' => 'a')))[1] !== null, 'at most 64 widgets');
check(webui_layout_get('alice') === null && webui_layout_put('alice', $l) && webui_layout_get('alice') === $l, 'layouts are saved per user');
webui_layout_delete('alice');
check(webui_layout_get('alice') === null, 'reset removes the saved layout');

/* Profile */
check(webui_initials('Alex Morgan') === 'AM' && webui_initials('admin') === 'A' && webui_initials('Ærlig Øst Ålund') === 'ÆØ', 'initials');
list($v, $e) = webui_profile_validate(array('name' => '  Alex Morgan ', 'email' => 'admin@example.org'));
check($e === array() && $v['name'] === 'Alex Morgan', 'a valid profile');
list($v, $e) = webui_profile_validate(array('email' => 'nope', 'name' => str_repeat('x', 65), 'uid' => '0'));
check(isset($e['email'], $e['name'], $e['uid']), 'bad email, long name and other fields are refused');
check(webui_profile_validate(array('email' => ''))[1] === array(), 'an empty email clears it');

/* Sessions */
$sess = 'Logged_In|s:4:"True";Username|s:5:"alice";authsource|s:14:"Local Database";protocol|s:5:"https";REMOTE_ADDR|s:10:"192.0.2.10";' .
    'last_access|i:' . (time() - 60) . ';login_time|i:' . (time() - 3600) . ';user_agent|s:16:"Firefox on Linux";' .
    'page-match|a:2:{i:0;s:18:"page-dashboard-all";i:1;s:4:"x|y;";}flag|b:1;n|N;ratio|d:0.5;';
$d = webui_session_decode($sess);
check($d['Username'] === 'alice' && $d['page-match'] === array('page-dashboard-all', 'x|y;') && $d['flag'] === true && $d['n'] === null &&
    $d['ratio'] === 0.5, 'PHP session data decodes, including strings with | and ; inside');
check(webui_session_decode('Username|s:9:"truncated";bad') === null && webui_session_decode('x|O:8:"stdClass":0:{}') === null,
    'broken data and objects are refused');
$sd = "{$tmp}/sessions";
@mkdir($sd);
file_put_contents("{$sd}/sess_aaaaaaaaaaaaaaaaaaaaaaaaaa", $sess);
file_put_contents("{$sd}/sess_bbbbbbbbbbbbbbbbbbbbbbbbbb", str_replace(array('192.0.2.10', 'Firefox on Linux'), array('192.0.2.99', 'Safari on iPhone'), $sess));
file_put_contents("{$sd}/sess_cccccccccccccccccccccccccc", str_replace('s:5:"alice"', 's:3:"bob"', $sess));
file_put_contents("{$sd}/sess_dddddddddddddddddddddddddd", str_replace('last_access|i:' . (time() - 60), 'last_access|i:' . (time() - 99999), $sess));
file_put_contents("{$sd}/sess_eeeeeeeeeeeeeeeeeeeeeeeeee", '');
$list = webui_sessions_list('alice', 'aaaaaaaaaaaaaaaaaaaaaaaaaa', $sd);
check(count($list) === 2 && count(array_filter(array_column($list, 'current'))) === 1, 'own live sessions are listed (not other users, stale or empty ones)');
check(strpos(json_encode($list), 'aaaaaaaaaaaaaaaaaaaaaaaaaa') === false && preg_match('/^[0-9a-f]{16}$/', $list[0]['id']),
    'session ids are never exposed, only a derived public id');
$other = array_values(array_filter($list, function ($s) { return !$s['current']; }))[0];
$mine = array_values(array_filter($list, function ($s) { return $s['current']; }))[0];
check($other['agent'] === 'Safari on iPhone' && $other['signed_in'] !== null, 'address, agent and sign-in time are shown');
check(webui_session_revoke('alice', $mine['id'], 'aaaaaaaaaaaaaaaaaaaaaaaaaa', $sd) === 'current', 'the current session cannot be revoked here');
check(webui_session_revoke('bob', $other['id'], null, $sd) === 'not_found' && is_file("{$sd}/sess_bbbbbbbbbbbbbbbbbbbbbbbbbb"),
    'another user cannot revoke your session');
check(webui_session_revoke('alice', $other['id'], 'aaaaaaaaaaaaaaaaaaaaaaaaaa', $sd) === 'ok' && !is_file("{$sd}/sess_bbbbbbbbbbbbbbbbbbbbbbbbbb"),
    'your other session is signed out');

/* Wiring */
$routes = file_get_contents("{$root}/src/etc/inc/restapi/routes_me.inc");
$v1 = file_get_contents("{$root}/src/etc/inc/restapi/routes_v1.inc");
$front = file_get_contents("{$root}/src/usr/local/www/api/index.php");
$auth = file_get_contents("{$root}/src/etc/inc/auth.inc");
check(strpos($v1, 'restapi_routes_me()') !== false && strpos($front, "require_once('webui_prefs.inc');") !== false, 'me routes are registered and loaded');
check(substr_count($routes, "'page' => '@authenticated'") === 10 && substr_count($routes, "'write' => true") === 6, 'own-data routes; changes are writes');
check(strpos($routes, "function restapi_h_me_signout(\$req) {
	if (empty(\$req['session'])) {") !== false && strpos($routes, 'webgui_session_signout(false);') !== false,
    'sign-out ends only the WebUI session the request came from');
check(strpos($routes, "throw new RestApiError(409, 'remote_user'") !== false, 'remote users cannot edit a local profile');
check(strpos($front, "'session_id' => \$ctx['session_id'] ?? null,") !== false &&
    strpos(file_get_contents("{$root}/src/etc/inc/restapi_session.inc"), "'session_id' => \$id,") !== false, 'the caller\'s session is known to the handlers');
check(strpos($auth, "\$_SESSION['login_time'] = time();") !== false && strpos($auth, "\$_SESSION['user_agent'] = substr(") !== false,
    'sign-in time and browser are recorded for the session list');

exec('rm -rf ' . escapeshellarg($tmp));
echo "WebUI preferences smoke test passed.\n";
