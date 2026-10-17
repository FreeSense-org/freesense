<?php
$root = dirname(__DIR__);
$www = $root . '/src/usr/local/www';
$cp = file_get_contents($root . '/src/etc/inc/captiveportal.inc');
$fail = function (string $message): void {
	fwrite(STDERR, $message . "\n");
	exit(1);
};

/* Captive portal element names are plain file names. */
if (!preg_match('/function captiveportal_element_name_valid\(.*?\n}\n/s', $cp, $match)) {
	$fail('captiveportal_element_name_valid() is missing.');
}
eval($match[0]);
foreach (['captiveportal-logo.png', 'favicon.ico', 'captiveportal-a_b-1.css'] as $good) {
	if (!captiveportal_element_name_valid($good)) {
		$fail("Valid element name rejected: {$good}");
	}
}
foreach (['', '../x', 'captiveportal-../../usr/local/www/x.php', 'a/b', 'a\b', "a\0b", '.htaccess', 'a..b', null, ['x'], str_repeat('a', 256)] as $bad) {
	if (captiveportal_element_name_valid($bad)) {
		$fail('Invalid element name accepted: ' . json_encode($bad));
	}
}
if (strpos($cp, 'if (!captiveportal_element_name_valid($data[\'name\'] ?? null)) {') === false) {
	$fail('captiveportal_write_elements() writes element names without checking them.');
}
$fm = file_get_contents($www . '/services_captiveportal_filemanager.php');
if (substr_count($fm, 'captiveportal_element_name_valid(') !== 2) {
	$fail('The file manager must check element names on upload and on delete.');
}

/* Session IDs and voucher material come from a CSPRNG. */
if (strpos($cp, '$sessionid = bin2hex(random_bytes(8));') === false || strpos($cp, 'md5(mt_rand()') !== false) {
	$fail('Captive portal session IDs must come from random_bytes().');
}
$vouchers = file_get_contents($www . '/services_captiveportal_vouchers.php');
if (preg_match('/[^_]rand\(\)/', $vouchers)) {
	$fail('Voucher settings must not use rand().');
}

/* No CSRF bypass from request-controlled input. */
foreach (['services_captiveportal_vouchers.php', 'services_captiveportal_hasync.php'] as $page) {
	$source = file_get_contents($www . '/' . $page);
	if (strpos($source, "if (!empty(\$_POST['postafterlogin']) && empty(\$GLOBALS['webgui_postafterlogin_signin'])) {") === false) {
		$fail("{$page} accepts postafterlogin without a sign-in in the same request.");
	}
}
if (strpos(file_get_contents($root . '/src/etc/inc/auth.inc'), "\$GLOBALS['webgui_postafterlogin_signin'] = true;") === false) {
	$fail('session_auth() does not mark a postafterlogin sign-in.');
}
if (strpos(file_get_contents($www . '/services_captiveportal.php'), "(\$_SERVER['REQUEST_METHOD'] === 'GET')") === false) {
	$fail('services_captiveportal.php skips the CSRF check for POST requests.');
}
$capture = file_get_contents($www . '/diag_packet_capture.php');
$download = strpos($capture, "if (isset(\$_POST['download_button'])) {\n\t\t\$action = 'download';");
$start = strpos($capture, "isset(\$_POST['start_button'])");
if ($download === false || $start === false || $download > $start) {
	$fail('A packet capture download request (no CSRF check) must not be able to start, stop or clear a capture.');
}

echo "WebUI hardening smoke test passed\n";
