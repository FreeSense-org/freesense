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

/* The captive portal status widget disconnects only through a POST. */
$widget = file_get_contents($www . '/widgets/widgets/captive_portal_status.widget.php');
if (strpos($widget, "\$_GET['act']") !== false || strpos($widget, '&amp;act=del') !== false ||
    strpos($widget, "captiveportal_disconnect_client(\$_POST['id'], 6);") === false) {
	$fail('The captive portal status widget must disconnect clients through a POST form.');
}

/* Actions that change the configuration only run on a POST (csrf-magic checks POST only). */
foreach (['system_gateways.php', 'pkg.php'] as $page) {
	if (strpos(file_get_contents($www . '/' . $page), "\$_REQUEST['act']") !== false) {
		$fail("{$page} must read act from \$_POST, not \$_REQUEST.");
	}
}
foreach (['firewall_shaper.php', 'firewall_shaper_queues.php', 'firewall_shaper_vinterface.php'] as $page) {
	$source = file_get_contents($www . '/' . $page);
	if (strpos($source, '$post_actions = [') === false || preg_match('/if \(\$_GET\) \{\s+switch/', $source) ||
	    preg_match('/\$_GET\[\'action\'\]\) \{/', $source)) {
		$fail("{$page} must take delete and other changing actions from a POST only.");
	}
}
$shaper = file_get_contents($www . '/firewall_shaper.php');
if (strpos($shaper, 'action=resetall" usepost') === false || substr_count($shaper, "setAttribute('usepost', true)") !== 1 ||
    substr_count(file_get_contents($www . '/firewall_shaper_vinterface.php'), "setAttribute('usepost', true)") !== 1 ||
    substr_count(file_get_contents($www . '/firewall_shaper_queues.php'), "'post' => true") !== 2) {
	$fail('The traffic shaper delete, reset and clone links must post (usepost).');
}

/* unserialize() never instantiates objects. */
foreach (['/src/etc/inc/notices.inc', '/src/usr/local/bin/kea2fib6', '/src/etc/inc/xmlparse_attr.inc'] as $file) {
	$source = file_get_contents($root . $file);
	if (substr_count($source, 'unserialize(') !== substr_count($source, "'allowed_classes' => false])")) {
		$fail("{$file} calls unserialize() without allowed_classes => false.");
	}
}
if (strpos(file_get_contents($root . '/src/etc/inc/util.inc'), "?array \$options = ['allowed_classes' => false]):mixed {") === false) {
	$fail('unserialize_data() must default to allowed_classes => false.');
}

/* Legacy password hashes are replaced at login. */
$auth = file_get_contents($root . '/src/etc/inc/auth.inc');
if (strpos($auth, 'function local_user_upgrade_legacy_hash(') === false ||
    strpos($auth, 'local_user_upgrade_legacy_hash($username, $passwd);') === false) {
	$fail('A login with a legacy (crypt or MD5) hash must store the current hash.');
}

/* The XML-RPC methods that run code are for admin (uid 0) only, not for the HA sync privilege. */
$xmlrpc = file_get_contents($www . '/xmlrpc.php');
foreach (['exec_php', 'exec_shell'] as $method) {
	if (!preg_match('/function ' . $method . '\(\$code\) \{\s+\$this->auth\(true\);/', $xmlrpc)) {
		$fail("XML-RPC {$method}() must call auth(true) (uid 0 only).");
	}
}
/* An LDAP or RADIUS user has no uid; the privilege check must not depend on one. */
if (strpos($xmlrpc, "\$is_root = (is_array(\$user_entry) && ((\$user_entry['uid'] ?? null) === '0'));") === false ||
    strpos($xmlrpc, "if (!\$is_root && (\$root_only || !is_array(\$user_entry) ||") === false ||
    strpos($xmlrpc, "isset(\$user_entry['uid']) &&") !== false) {
	$fail('XML-RPC auth() must allow uid 0 only, or a user that holds the HA sync privilege.');
}

/* Redirect query values are URL-encoded, shell arguments are escaped, and ".ko" is a suffix. */
foreach (['firewall_rules.php', 'firewall_rules_edit.php', 'firewall_aliases_edit.php', 'firewall_aliases_import.php'] as $page) {
	if (preg_match('/Location: [^\'"]*\?[^\'"]*[\'"] \. htmlspecialchars ?\(/', file_get_contents($www . '/' . $page))) {
		$fail("{$page} redirects must use rawurlencode(), not htmlspecialchars().");
	}
}
if (strpos($cp, '/usr/bin/printf \"{$rules}\"') !== false) {
	$fail('captiveportal_load_pfctl() must pass the rules and anchor through escapeshellarg().');
}
if (strpos($auth, 'pw usershow -n {$') !== false) {
	$fail('auth.inc must escape the user name passed to pw usershow.');
}
$util = file_get_contents($root . '/src/etc/inc/util.inc');
if (strpos($util, "rtrim(\$module_name, '.ko')") !== false || strpos($util, 'dmesg |grep $ifname') !== false) {
	$fail('util.inc: is_module_loaded() must strip the ".ko" suffix, and dmesg must get an escaped interface name.');
}

/* Security audit 2026-10-27. */
/* Pages whose privileges name a query value take that value from the query string only. */
if (strpos(file_get_contents($www . '/status_logs.php'), "\$_REQUEST['logfile']") !== false) {
	$fail('status_logs.php must read the log name from $_GET: its privileges match the request URI.');
}
foreach (['pkg.php', 'pkg_edit.php'] as $page) {
	$source = file_get_contents($www . '/' . $page);
	if (strpos($source, "\$xml_request = \$_GET['xml'] ?? '';") === false ||
	    strpos($source, "(isset(\$_POST['xml']) && \$_POST['xml'] !== \$xml_request)") === false ||
	    strpos($source, "!isAllowedPage(\"{$page}?xml=\" . \$xml_request)") === false) {
		$fail("{$page} must load only the package named in the query string the user may open.");
	}
}
/* State changes on these pages need a CSRF-checked POST. */
if (strpos(file_get_contents($www . '/diag_tables.php'), "\$_REQUEST['delete']") !== false) {
	$fail('diag_tables.php must delete table entries on POST only.');
}
if (strpos(file_get_contents($www . '/status_logs_common.inc'), "\$save_settings = (\$_SERVER['REQUEST_METHOD'] === 'POST') ?") === false) {
	$fail('Log display settings must be saved on POST only.');
}
/* Privileges that amount to root say so. */
$privdefs = file_get_contents($root . '/src/etc/inc/priv.defs.inc');
foreach (['page-services-captiveportal', 'page-services-captiveportal-filemanager'] as $priv) {
	if (strpos($privdefs, "\$priv_list['{$priv}']['warn'] = \"standard-warning-root\";") === false) {
		$fail("{$priv} runs uploaded PHP as root and must carry the root warning.");
	}
}
/* "Deny Config Write" also denies package and system updates. */
$pkginstall = file_get_contents($root . '/src/usr/local/FreeSense/include/www/pkg_mgr_install.inc');
$customrepos = file_get_contents($root . '/src/etc/inc/pkg_custom_repos.inc');
if (strpos($pkginstall, 'function pkg_mgr_install_readonly_user()') === false ||
    strpos($pkginstall, 'if (pkg_mgr_install_readonly_user()) {') === false ||
    strpos($customrepos, 'if (pkg_mgr_install_readonly_user()) {') === false) {
	$fail('Package operations must be refused for a user with "Deny Config Write".');
}
/* The RSS cache (unserialized by root) is not world-writable, and feed links are web links. */
$rss = file_get_contents($www . '/widgets/widgets/rss.widget.php');
if (strpos($rss, '/tmp/simplepie') !== false || strpos($rss, 'chmod a+rw') !== false ||
    strpos($rss, "['http', 'https']") === false) {
	$fail('The RSS widget must cache in a root-only directory and only link http(s) URLs.');
}

/* The dynamic firewall log never reads a path named in the request. */
$syslog = file_get_contents($root . '/src/etc/inc/syslog.inc');
if (strpos($syslog, "\$logfile = getGETPOSTsettingvalue('logfile', null);") !== false ||
    strpos($syslog, "\$logfile = g_get('varlog_path') . '/filter.log';") === false) {
	$fail('handle_ajax() must read the firewall log only.');
}
/* Dashboard widgets only act with the privilege of the status page that has the same action. */
if (strpos(file_get_contents($www . '/widgets/widgets/captive_portal_status.widget.php'), "isAllowedPage('status_captiveportal.php')") === false ||
    strpos(file_get_contents($www . '/widgets/widgets/openvpn.widget.php'), "isAllowedPage('status_openvpn.php')") === false) {
	$fail('Widget disconnects need the Captive Portal or OpenVPN status page privilege.');
}
if (strpos(file_get_contents($www . '/widgets/widgets/log.widget.php'), "if (!empty(\$_POST['widgetkey']) && empty(\$_POST['ajax'])) {") === false) {
	$fail('The firewall log widget must save its settings on POST only.');
}
$index = file_get_contents($www . '/index.php');
if (strpos($index, "\$_REQUEST['closenotice']") !== false || strpos($index, 'userHasPrivilege($notice_user, "user-view-clear-notices")') === false) {
	$fail('index.php must clear notices on POST only, for users who may clear them.');
}
/* Pages that replace the configuration carry the root warning and honour "Deny Config Write". */
$upload = file_get_contents($www . '/uploadconfig.php');
if (strpos($upload, "userHasPrivilege(\$guiuser, 'user-config-readonly')") === false ||
    strpos($upload, '{$g[\'tmp_path\']}/config.xml') !== false ||
    strpos($privdefs, "\$priv_list['page-hidden-uploadconfiguration']['warn'] = \"standard-warning-root\";") === false ||
    strpos($privdefs, "\$priv_list['page-freesensewizardsubsystem']['warn'] = \"standard-warning-root\";") === false) {
	$fail('uploadconfig.php and the wizard must be flagged as root, and uploadconfig must refuse read-only users.');
}
/* Plain-text replies holding user-controlled text are not rendered as HTML. */
foreach (['status_logs_filter.php', 'bandwidth_by_ip.php'] as $page) {
	if (strpos(file_get_contents($www . '/' . $page), "header('Content-Type: text/plain; charset=UTF-8');") === false) {
		$fail("{$page} must answer its text replies as text/plain.");
	}
}
/* The HA sync password is never sent to a peer it was not entered for. */
$hasync = file_get_contents($root . '/src/usr/local/FreeSense/include/www/system_hasync.inc');
if (strpos($hasync, "((string)(\$old_hasync['synchronizetoip'] ?? '') !== (string)\$a_hasync['synchronizetoip'])") === false) {
	$fail('Changing the HA sync peer must require the sync password again.');
}
/* Signing out through the REST session bridge destroys the server-side session. */
if (strpos(file_get_contents($root . '/src/etc/inc/restapi/routes_me.inc'), "session_id(\$req['session_id']);\n\twebgui_session_signout(false);") === false) {
	$fail('restapi_h_me_signout() must open the real session before signing out.');
}

/* OpenVPN: the client-chosen user name never reaches eval, and odd names are refused. */
$connect = file_get_contents($root . '/src/usr/local/sbin/openvpn.connect_async.sh');
if (strpos($connect, 'eval ') !== false || strpos($connect, '.*|*[!A-Za-z0-9._@+-]*)') === false) {
	$fail('openvpn.connect_async.sh must not eval, and must refuse user names outside the allowed characters.');
}
if (strpos(file_get_contents($root . '/src/etc/inc/openvpn.auth-user.php'), "preg_match('/^[A-Za-z0-9_@+-][A-Za-z0-9._@+-]*\$/D', (string)\$username)") === false) {
	$fail('openvpn.auth-user.php must refuse user names outside the allowed characters.');
}
$learn = file_get_contents($root . '/src/usr/local/sbin/openvpn.learn-address.sh');
if (strpos($learn, "''|[.-]*|*[!A-Za-z0-9.-]*)") === false || strpos($learn, 'local_data ${CN}') !== false) {
	$fail('openvpn.learn-address.sh must register host names only and pass them as arguments.');
}
/* Root never runs or loads a script from a fixed name in /tmp. */
$services = file_get_contents($root . '/src/etc/inc/services.inc');
$filter = file_get_contents($root . '/src/etc/inc/filter.inc');
if (strpos($services, "{\$g['tmp_path']}/dhcpd.sh") !== false || strpos($filter, "{\$g['tmp_path']}/commands.txt") !== false ||
    strpos(file_get_contents($root . '/src/usr/local/bin/ping_hosts.sh'), '/tmp/tmpHOSTS') !== false ||
    strpos(file_get_contents($root . '/src/etc/rc.php_ini_setup'), '> /tmp/php.ini') !== false) {
	$fail('dhcpd.sh, commands.txt, ping_hosts.sh and php.ini must use private temporary files.');
}
/* Daemon configurations that hold secrets are readable by root only. */
$ipsec = file_get_contents($root . '/src/etc/inc/ipsec.inc');
if (strpos($util, 'function file_put_contents_private(') === false ||
    strpos($ipsec, 'file_put_contents_private("{$g[\'varetc_path\']}/ipsec/swanctl.conf"') === false ||
    strpos($ipsec, 'file_put_contents_private("{$g[\'varetc_path\']}/ipsec/strongswan.conf"') === false ||
    substr_count(file_get_contents($root . '/src/etc/inc/interfaces.inc'), 'file_put_contents_private(') !== 2 ||
    strpos($services, '@chmod("{$g[\'varetc_path\']}/snmpd.conf", 0600);') === false) {
	$fail('IPsec, wireless, PPP and SNMP configurations must be written 0600.');
}
/* Dynamic DNS requests that carry credentials verify the server. */
if (preg_match('/(duiadns\.net|dyn\.dns\.he\.net)[^\n]*\n[^\n]*\n?[^\n]*SSL_VERIFYPEER, FALSE/', file_get_contents($root . '/src/etc/inc/dyndns.class'))) {
	$fail('duiadns and he-net updates must verify TLS.');
}
/* The PKCS#12 export never writes into a directory it did not create. */
if (strpos(file_get_contents($root . '/src/etc/inc/certs.inc'), "if ((\$workdir === false) || !@mkdir(\$workdir, 0700)) {") === false) {
	$fail('cert_pkcs12_export() must stop when it cannot create its work directory.');
}
/* DHCP client host names are plain labels before they reach unbound. */
if (strpos(file_get_contents($root . '/src/usr/local/bin/kea2unbound'), "preg_match('/^[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?\$/D', \$hostname)") === false) {
	$fail('kea2unbound must only write plain DNS labels.');
}
/* Captive portal: the client-supplied zone is a plain name, and RADIUS numbers go into SQL as numbers. */
if (strpos(file_get_contents($root . '/src/usr/local/captiveportal/index.php'), "if (!preg_match('/^[a-z0-9_]+\$/D', \$cpzone)) {") === false ||
    strpos($cp, "? (int)\$attributes['session_timeout'] : 'NULL';") === false) {
	$fail('The captive portal must validate the zone and cast RADIUS numbers.');
}

/* CVE-2026-111215: the wireless parent is a listed wireless device and is escaped. */
if (strpos(file_get_contents($www . '/interfaces_wireless_edit.php'), "in_array((string)\$_POST['if'], array_column(interface_list_wireless(), 'if'), true)") === false ||
    strpos(file_get_contents($root . '/src/etc/inc/interfaces.inc'), 'wlandev {$baseif}') !== false) {
	$fail('The wireless parent interface must be validated and escaped.');
}
/* Limiter numbers reach dnctl as integers. */
if (preg_match('/dnctl (pipe|sched|queue) delete " \. \$this->GetNumber\(\)/', file_get_contents($root . '/src/etc/inc/shaper.inc'))) {
	$fail('shaper.inc must cast limiter numbers before dnctl.');
}
/* CVE-2026-111234: SimplePie never fetches; the widget fetches http(s) only and hands it the text. */
if (strpos($rss, 'CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS') === false ||
    strpos($rss, '$tmp_feed->set_raw_data($feed_data);') === false || strpos($rss, 'set_feed_url(') !== false ||
    strpos($rss, 'FilesystemAdapter') !== false) {
	$fail('The RSS widget must fetch feeds itself over http(s) and give SimplePie raw data only.');
}

echo "WebUI hardening smoke test passed\n";
