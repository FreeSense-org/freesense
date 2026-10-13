<?php
/* Standalone CI regression test; run with `php tests/PackageRepositoriesSmokeTest.php`. */

$root = dirname(__DIR__);
set_include_path(get_include_path() . PATH_SEPARATOR . realpath($root . '/src/etc/inc'));
if (!function_exists('gettext')) {
	function gettext($s) {
		return $s;
	}
}
require_once('pkg_custom_repos/core.inc');

function check_pr($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

/* The invariant: custom repositories always rank below every FreeSense one. */
$repos = array();
for ($i = 1; $i <= PKGREPO_MAX_CUSTOM; $i++) {
	$repos[] = array('name' => "extra{$i}", 'url' => "https://r{$i}.example.org/repo", 'signature_type' => 'none', 'enable' => true);
}
$priorities = pkgrepo_priorities($repos);
check_pr(count($priorities) === PKGREPO_MAX_CUSTOM, 'every custom repository gets a priority');
check_pr(max($priorities) < pkgrepo_lowest_system_priority(), 'custom priorities are below the lowest FreeSense one');
check_pr(min($priorities) > PKGREPO_PRIORITY_FREEBSD, 'the FreeBSD repository ranks below every custom one');
check_pr($priorities['extra1'] > $priorities['extra5'], 'list order is the priority order');
check_pr(PKGREPO_PRIORITY_FREEBSD >= 0, 'priorities stay non-negative');

/* Validation */
$good = array('name' => 'acme', 'descr' => 'Acme', 'url' => 'https://pkg.acme.example/FreeBSD:16:amd64/latest',
    'signature_type' => 'fingerprints', 'key' => str_repeat('ab', 32));
check_pr(pkgrepo_validate($good, array()) === array(), 'a valid signed repository passes');
foreach (array('FreeSense-x', 'freebsd', 'Acme', 'a', '1abc', 'a b', 'a/b', '../x', str_repeat('a', 33)) as $bad) {
	check_pr(!pkgrepo_name_valid($bad), "name refused: {$bad}");
}
foreach (array('http://x.example/r', 'https://u:p@x.example/r', 'https://x.example/r?a=b', 'https://x.example/r#f',
    'https://x.example/r"; evil', "https://x.example/r\nenabled: yes", 'ftp://x.example', 'https:///r', 'https://x.example:99999/r',
    'file:///tmp/r', 'https://exa mple.org/r') as $bad) {
	check_pr(!pkgrepo_url_valid($bad), "URL refused: " . json_encode($bad));
}
check_pr(pkgrepo_url_valid('https://pkg.example.org:8443/a/b'), 'https URL with port and path passes');
check_pr(pkgrepo_validate(array_merge($good, array('name' => 'acme')), array(array('name' => 'acme'))) !== array(), 'a duplicate name is refused');
check_pr(pkgrepo_validate(array_merge($good, array('key' => 'xyz')), array()) !== array(), 'a bad fingerprint is refused');
check_pr(pkgrepo_validate(array_merge($good, array('signature_type' => 'none', 'key' => '')), array()) !== array(), 'unsigned needs the acknowledgement');
check_pr(pkgrepo_validate(array_merge($good, array('signature_type' => 'none', 'key' => '', 'ack_unsigned' => '1')), array()) === array(),
    'unsigned passes once acknowledged');
$others = array_slice($repos, 0, PKGREPO_MAX_CUSTOM);
check_pr(pkgrepo_validate($good, $others) !== array(), 'the sixth custom repository is refused');
check_pr(pkgrepo_fingerprint_file("function: \"sha256\"\nfingerprint: \"" . str_repeat('AB', 32) . "\"") === "function: \"sha256\"\nfingerprint: \"" . str_repeat('ab', 32) . "\"\n",
    'the fingerprint file form is accepted and lower-cased');
check_pr(pkgrepo_pubkey_valid("-----BEGIN PRIVATE KEY-----\nAAAA\n-----END PRIVATE KEY-----") === false, 'a private key is not a public key');
check_pr(pkgrepo_fingerprint_hex("function: \"sha256\"\nfingerprint: \"" . str_repeat('AB', 32) . "\"") === str_repeat('ab', 32), 'fingerprint digits are extracted');

/* A pasted public key may arrive with CRLF, or with its line breaks lost. */
if (function_exists('openssl_pkey_new')) {
	$res = openssl_pkey_new(array('private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA));
	$pem = openssl_pkey_get_details($res)['key'];
	$body = pkgrepo_pubkey_body($pem);
	check_pr($body !== null && strpos($body, "\n") === false, 'a public key reduces to its base64 body');
	check_pr(pkgrepo_pubkey_body(str_replace("\n", "\r\n", $pem)) === $body, 'CRLF line endings are accepted');
	check_pr(pkgrepo_pubkey_body(str_replace("\n", '', $pem)) === $body, 'lost line breaks are accepted');
	check_pr(pkgrepo_pubkey_pem($body) === $pem, 'the rendered file is the canonical PEM that pkg reads');
	check_pr(pkgrepo_pubkey_body(substr($pem, 0, 60) . "!!!" . substr($pem, 63)) === null, 'a damaged key is refused');
	$rendered = pkgrepo_render_all(array(array('name' => 'k', 'url' => 'https://k.example/r', 'signature_type' => 'pubkey', 'key' => $body, 'enable' => true)), false);
	check_pr($rendered['custom-keys/k/pubkey.pem'] === $pem, 'a stored key body is written back as a valid PEM file');
}

/* Rendering */
$files = pkgrepo_render_all(array(
	array('name' => 'acme', 'url' => 'https://pkg.acme.example/r', 'signature_type' => 'fingerprints', 'key' => str_repeat('ab', 32), 'enable' => true),
	array('name' => 'open', 'url' => 'https://open.example/r', 'signature_type' => 'none', 'key' => '', 'enable' => false),
), true);
check_pr(strpos($files['custom-repos/acme.conf'], 'priority: 5,') !== false, 'the first repository gets priority 5');
check_pr(strpos($files['custom-repos/open.conf'], 'priority: 4,') !== false && strpos($files['custom-repos/open.conf'], 'enabled: no') !== false,
    'the second gets 4 and a disabled one is written disabled');
check_pr(strpos($files['custom-repos/acme.conf'], 'fingerprints: "/usr/local/etc/pkg/custom-keys/acme"') !== false, 'signed repositories point at their key directory');
check_pr(isset($files['custom-keys/acme/trusted/acme']) && isset($files['custom-keys/acme/revoked/.keep']), 'the fingerprint is written where pkg looks');
check_pr(strpos($files['custom-repos/open.conf'], 'signature_type') === false, 'an unsigned repository writes no signature settings');
check_pr($files['custom-repos/FreeBSD-ports.conf'] === "FreeBSD-ports: { enabled: yes, priority: 0 }\n", 'the FreeBSD repository is switched on at priority 0');
check_pr(!isset(pkgrepo_render_all(array(), false)['custom-repos/FreeBSD-ports.conf']), 'the FreeBSD repository is not written when it is off');
foreach ($files as $path => $content) {
	check_pr(strpos($path, '..') === false, "no path escape: {$path}");
	check_pr(preg_match('/priority: (\d+)/', $content, $m) !== 1 || (int)$m[1] < pkgrepo_lowest_system_priority(), "rendered priority below the system: {$path}");
}

/* The system upgrader never sees the custom directory; the WebUI can ask for it. */
check_pr(strpos(pkgrepo_repos_dir(false), 'custom-repos') === false, 'the system REPOS_DIR has no custom directory');
check_pr(substr(pkgrepo_repos_dir(true), -strlen('/usr/local/etc/pkg/custom-repos/')) === '/usr/local/etc/pkg/custom-repos/',
    'the custom directory comes last so it can only add repositories');

/* The install guard, on the real pkg output format. */
$ok = "Checking integrity... done (0 conflicting)\nThe following 1 package(s) will be affected (of 0 checked):\n\nNew packages to be INSTALLED:\n\tcustom-hello: 1.0 [spike]\n\nNumber of packages to be installed: 1\n";
check_pr(pkgrepo_guard(pkgrepo_parse_plan($ok), array('spike'), array('lldpd')) === array(), 'a plain install from a custom repository is fine');
$deps = "New packages to be INSTALLED:\n\tautoconf: 2.73 [FreeSense]\n\tm4: 1.4.21,1 [FreeSense]\n\ttool: 1.0 [spike]\n\n";
check_pr(pkgrepo_guard(pkgrepo_parse_plan($deps), array('spike'), array('autoconf', 'm4')) === array(), 'dependencies from FreeSense are fine');
$upgrade = "Installed packages to be UPGRADED:\n\topenssl: 3.0.1 -> 3.9.9 [spike]\n\nNew packages to be INSTALLED:\n\ttool: 1.0 [spike]\n";
check_pr(pkgrepo_guard(pkgrepo_parse_plan($upgrade), array('spike'), array()) !== array(), 'changing an installed package is refused');
$remove = "Installed packages to be REMOVED:\n\tphp85\n\nNew packages to be INSTALLED:\n\ttool: 1.0 [spike]\n";
check_pr(pkgrepo_guard(pkgrepo_parse_plan($remove), array('spike'), array()) !== array(), 'removing an installed package is refused');
$shadow = "New packages to be INSTALLED:\n\tautoconf: 999.0 [spike]\n";
check_pr(pkgrepo_guard(pkgrepo_parse_plan($shadow), array('spike'), array('autoconf')) !== array(), 'a package FreeSense supplies cannot come from a custom repository');
$reserved = "New packages to be INSTALLED:\n\tFreeSense-pkg-evil: 1.0 [spike]\n\tphp85-evil: 1.0 [spike]\n\tpkg: 9.0 [spike]\n";
check_pr(count(pkgrepo_guard(pkgrepo_parse_plan($reserved), array('spike'), array())) === 3, 'reserved names are refused');
check_pr(pkgrepo_guard(pkgrepo_parse_plan($reserved), array('other'), array()) === array(), 'only packages from the foreign repository are judged by name');

/* Removal: only packages from a custom repository, and nothing of FreeSense's may go with them. */
$origin = array('tool' => 'spike', 'libtool' => 'spike', 'php85' => 'FreeSense', 'FreeSense-pkg-x' => 'FreeSense-packages', 'nginx' => 'FreeSense');
$rm = pkgrepo_parse_plan("Installed packages to be REMOVED:\n\ttool: 1.0\n\n");
check_pr($rm['removed'] === array('tool'), 'the removal plan is parsed');
check_pr(pkgrepo_guard_removal('tool', $rm, $origin, array('spike')) === array(), 'a custom package can be removed');
check_pr(pkgrepo_guard_removal('nginx', pkgrepo_parse_plan("Installed packages to be REMOVED:\n\tnginx: 1\n"), $origin, array('spike')) !== array(),
    'a FreeSense package cannot be removed this way');
check_pr(pkgrepo_guard_removal('FreeSense-pkg-x', array('removed' => array('FreeSense-pkg-x')), $origin, array('FreeSense-packages')) !== array(),
    'a reserved name cannot be removed even if its repository were foreign');
$with_dependent = pkgrepo_parse_plan("Installed packages to be REMOVED:\n\tlibtool: 1.0\n\tphp85: 8.5\n\n");
check_pr(count(pkgrepo_guard_removal('libtool', $with_dependent, $origin, array('spike'))) === 1, 'removing a package that would take a FreeSense one with it is refused');
check_pr(pkgrepo_guard_removal('ghost', $rm, $origin, array('spike')) !== array(), 'a package that is not installed is refused');

/* Updating one package from its own repository may change that package and nothing else. */
$upgrade = pkgrepo_parse_plan("Installed packages to be UPGRADED:\n\ttool: 1.0 -> 2.0 [spike]\n\nNew packages to be INSTALLED:\n\tlibnew: 1.0 [spike]\n");
check_pr($upgrade['changed'] === array(array('tool', '1.0 -> 2.0', 'spike')), 'the update plan is parsed');
check_pr(pkgrepo_guard($upgrade, array('spike'), array(), array('tool')) === array(), 'an explicit update of the package itself is allowed');
check_pr(pkgrepo_guard($upgrade, array('spike'), array(), array('other')) !== array(), 'an update that changes another package is refused');
$upgrade_other = pkgrepo_parse_plan("Installed packages to be UPGRADED:\n\ttool: 1.0 -> 2.0 [spike]\n\topenssl: 3 -> 4 [spike]\n");
check_pr(count(pkgrepo_guard($upgrade_other, array('spike'), array(), array('tool'))) === 1, 'an update that drags another installed package along is refused');
check_pr(pkgrepo_guard(pkgrepo_parse_plan("Installed packages to be UPGRADED:\n\ttool: 1.0 -> 2.0 [FreeSense]\n"), array('spike'), array(), array('tool')) !== array(),
    'an update that would come from a FreeSense repository is not an update of a custom package');

/* The package lists: official first, other sources by filter, one Source column, no foreign data pushed at load. */
$sources = file_get_contents($root . '/src/usr/local/FreeSense/include/www/pkg_mgr_sources.inc');
$available = file_get_contents($root . '/src/usr/local/www/pkg_mgr.php');
$installed = file_get_contents($root . '/src/usr/local/www/pkg_mgr_installed.php');
foreach (array('Available' => $available, 'Installed' => $installed) as $label => $page) {
	check_pr(strpos($page, 'data-fs-filter-source="official"') !== false, "{$label}: official rows carry the source attribute");
	check_pr(strpos($page, 'pkg_mgr_official_badge()') !== false, "{$label}: official rows are marked Official");
	check_pr(strpos($page, 'gettext("Source")') !== false, "{$label}: there is a Source column");
	check_pr(strpos($page, "'source' => pkg_mgr_source_filter(") !== false, "{$label}: the toolbar has a Source filter");
}
check_pr(strpos($installed, "source.value = 'official'") !== false, 'the Installed list starts on the official packages');
check_pr(strpos($available, "'ajax'") !== false && strpos($available, "=== 'source'") !== false && strpos($available, 'PKG_MGR_SOURCE_LIMIT') !== false,
    'the Available list loads another source on demand, capped');
check_pr(strpos($available, 'pkgrepo_source_rows($source)') !== false, 'the search text only decides which rows are kept');
check_pr(strpos($sources, 'mode=installcustom') !== false && strpos($sources, 'mode=upgradecustom') !== false && strpos($sources, 'mode=removecustom') !== false,
    'rows of other sources offer install, update and remove');

/* The WebUI's own package operations stay on the FreeSense repositories; only the custom ones opt in. */
$utils = file_get_contents($root . '/src/etc/inc/pkg-utils.inc');
check_pr(strpos($utils, '"REPOS_DIR" => "' . pkgrepo_repos_dir(false) . '"') !== false,
    'pkg_env() limits pkg to the system repository directories by default');
$config_layer = file_get_contents($root . '/src/etc/inc/pkg_custom_repos.inc');
check_pr(substr_count($config_layer, "'REPOS_DIR' => pkgrepo_repos_dir(true)") >= 2,
    'the custom repository operations ask for the custom directory explicitly');

echo "Package repositories smoke test passed.\n";
