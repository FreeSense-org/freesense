<?php
/* Standalone CI regression test for serving WebUI 2.0 next to the GUI; run with `php tests/WebUiServeSmokeTest.php`. */

$root = dirname(__DIR__);
$system = file_get_contents("{$root}/src/etc/inc/system.inc");
$auth = file_get_contents("{$root}/src/etc/inc/auth.inc");
$globals = file_get_contents("{$root}/src/etc/inc/globals.inc");

function check($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

function fn_body($src, $name) {
	$start = strpos($src, "function {$name}(");
	check($start !== false, "{$name}() exists");
	return substr($src, $start, strpos($src, "\n}\n", $start) - $start);
}

/* nginx */
check(strpos($globals, "define('WEBUI2_ROOT', '/usr/local/www-ui');") !== false, 'the WebUI 2.0 install root is defined once');
check(strpos($system, "if ((\$captive_portal == false) && is_file(WEBUI2_ROOT . '/index.php')) {") !== false,
    'the WebUI 2.0 locations exist only when FreeSense-webui is installed (and never on captive portal servers)');
check(strpos($system, "location ^~ /next/ {\n\t\t\tfastcgi_pass") !== false &&
    strpos($system, "fastcgi_param  SCRIPT_FILENAME  {\$webui_root}/index.php;") !== false,
    'every /next/ path goes to the WebUI front controller (a fixed script, never a path from the URL)');
check(strpos($system, "location ^~ /ui/ {\n\t\t\troot {\$webui_root};\n\t\t\texpires \\\$static_expires;") !== false &&
    strpos($system, "location ^~ /themes/ {\n\t\t\troot {\$webui_root};\n\t\t\texpires \\\$static_expires;") !== false,
    'the engine and themes are static files with the GUI\'s cache rules');
check(strpos($system, '{$static_expires}{$api_location}{$webui_location}		location / {') !== false,
    'the WebUI 2.0 locations are emitted before the GUI\'s own locations');

/* Sign-in shared by the GUI and the WebUI */
$signin = fn_body($auth, 'webgui_session_signin');
check(strpos($signin, 'session_regenerate_id();') !== false && strpos($signin, "\$_SESSION['protocol'] = webgui_request_protocol();") !== false &&
    strpos($signin, "\$_SESSION['REMOTE_ADDR'] = \$_SERVER['REMOTE_ADDR'];") !== false && strpos($signin, 'phpsession_end(true);') !== false,
    'sign-in starts a fresh session id and records protocol and address like before');
check(strpos($signin, 'log_auth_event(LOG_AUTH_EVENT_LOGIN,') !== false && strpos($signin, 'log_auth_event(LOG_AUTH_EVENT_ERROR,') !== false,
    'successful and failed sign-ins are logged (login protection reads them)');
check(strpos($signin, "authenticate_user(\$username, \$password, \$authcfg, \$attributes)") < strpos($signin, "authenticate_user(\$username, \$password))"),
    'the configured authentication server is tried before the local database, as before');
$sa = fn_body($auth, 'session_auth');
check(strpos($sa, "webgui_session_signin(\$_POST['usernamefld'], \$_POST['passwordfld'])") !== false && strpos($sa, 'session_regenerate_id') === false,
    'the GUI login uses the shared sign-in');
$so = fn_body($auth, 'webgui_session_signout');
check(strpos($so, 'phpsession_destroy();') !== false && strpos($so, "setcookie(session_name(), ''") !== false &&
    strpos($so, 'LOG_AUTH_EVENT_LOGOUT') !== false, 'sign-out destroys the session, clears the cookie and is logged');

echo "WebUI serve smoke test passed.\n";
