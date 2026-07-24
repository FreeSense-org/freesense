<?php
/* Standalone CI regression test; run with `php tests/WebGuiRestoreSmokeTest.php`. */

function check_webgui_restore($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

$auth = file_get_contents(__DIR__ . '/../src/etc/inc/auth.inc');
$authgui = file_get_contents(__DIR__ . '/../src/etc/inc/authgui.inc');
$utils = file_get_contents(__DIR__ . '/../src/etc/inc/freesense-utils.inc');
$backup = file_get_contents(__DIR__ . '/../src/usr/local/FreeSense/include/www/backup.inc');

check_webgui_restore($auth !== false, 'WebGUI authentication implementation is unreadable');
check_webgui_restore($authgui !== false, 'WebGUI login implementation is unreadable');
check_webgui_restore($utils !== false, 'configuration restore implementation is unreadable');
check_webgui_restore($backup !== false, 'GUI backup implementation is unreadable');

check_webgui_restore(
    strpos($auth, "'secure' => webgui_request_is_secure()") !== false &&
    strpos($auth, "\$_SESSION['protocol'] = webgui_request_protocol()") !== false,
    'session cookies and protocol tracking do not follow the active request');
check_webgui_restore(
    strpos($authgui, 'webgui_request_is_secure() ? \'; secure\' : \'\'') !== false,
    'the browser cookie test does not follow the active request');
check_webgui_restore(
    strpos($utils, 'function sanitize_restored_system_webgui()') !== false &&
    strpos($utils, "cert_create_selfsigned('', '', false)") !== false &&
    strpos($utils, "config_set_path('system/webgui/protocol', 'http')") !== false &&
    strpos($utils, 'sanitize_restored_system_webgui();') !== false,
    'System restore does not validate the WebGUI certificate with a safe HTTP fallback');
check_webgui_restore(
    strpos($backup, "if (\$post['restorearea'] == 'system')") !== false &&
    strpos($backup, 'mark_subsystem_dirty("restore");') !== false,
    'System restore does not require a reboot to synchronize the WebGUI listener');

echo "WebGUI restore session safety: valid\n";
