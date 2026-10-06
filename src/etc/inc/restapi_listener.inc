<?php
/*
 * restapi_listener.inc
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
 * All rights reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/*
 * REST API listeners (restapi/apilistener): extra HTTPS ports that serve
 * only /api/, bound to the addresses of one interface or virtual IP, so the
 * API can be reached (e.g. from the internet) without exposing the WebGUI.
 *
 * Each runs in its own nginx instance (restapi_listener_paths()), not in
 * the WebGUI's: a listener that cannot start (an address that is not up yet,
 * a port in use) affects neither the WebGUI nor the other listeners. Every other path answers 404. The
 * API front controller knows the listener from the RESTAPI_LISTENER FastCGI
 * parameter (set by nginx, not by the client) and applies its read-only
 * flag and allowed networks. An optional firewall rule passes the port on
 * the listener's interface; login protection blocks the port like the GUI's.
 */

require_once('restapi.inc');

define('RESTAPI_LISTENER_STATUS', '/var/run/restapi_listeners.json');
define('RESTAPI_LISTENER_DEFAULT_PORT', 8443);
define('RESTAPI_LISTENER_DEFAULT_RATE', 10);
define('RESTAPI_LISTENER_MAX', 16);

/* The configured listeners with defaults, in configuration order. */
function restapi_listeners() {
	$out = array();
	foreach ((array)config_get_path('restapi/apilistener', []) as $l) {
		if (!is_array($l) || !preg_match('/^[0-9a-f]{8}$/', (string)($l['id'] ?? ''))) {
			continue;
		}
		$out[] = restapi_listener_normalize($l);
	}
	return $out;
}

function restapi_listener_normalize(array $l) {
	/* A flag is set while present: config.xml keeps it as an empty element (''). */
	$flag = function ($k) use ($l) {
		return isset($l[$k]) && ($l[$k] !== false);
	};
	return array(
		'id' => (string)($l['id'] ?? ''),
		'enable' => $flag('enable'),
		'descr' => (string)($l['descr'] ?? ''),
		'address' => (string)($l['address'] ?? ''),
		'port' => (int)($l['port'] ?? RESTAPI_LISTENER_DEFAULT_PORT),
		'certref' => (string)($l['certref'] ?? ''),
		'readonly' => $flag('readonly'),
		'allowednetworks' => (string)($l['allowednetworks'] ?? ''),
		'ratelimit' => (int)($l['ratelimit'] ?? RESTAPI_LISTENER_DEFAULT_RATE),
		'firewall' => $flag('firewall'),
	);
}

function restapi_listener_find($id) {
	foreach (restapi_listeners() as $idx => $l) {
		if ($l['id'] === (string)$id) {
			return array($idx, $l);
		}
	}
	return array(null, null);
}

/* The listener a request came through (from the nginx FastCGI parameter), or null for the WebGUI port. */
function restapi_request_listener() {
	$id = (string)($_SERVER['RESTAPI_LISTENER'] ?? '');
	if ($id === '') {
		return null;
	}
	list(, $l) = restapi_listener_find($id);
	/* A parameter for a listener that no longer exists or is disabled: refuse rather than fall back to the GUI's rules. */
	return ($l !== null && $l['enable']) ? $l : array('id' => $id, 'enable' => false);
}

/* Addresses a listener can bind to: interfaces and virtual IPs (as get_possible_listen_ips()), key => label. */
function restapi_listener_address_choices() {
	require_once('interfaces.inc');
	$choices = array();
	foreach (get_possible_listen_ips() as $key => $label) {
		if (strpos((string)$key, '_lloc') === 0) {
			continue;
		}
		$choices[(string)$key] = $label;
	}
	return $choices;
}

/* The certificates a listener can use (with a private key), refid => name. */
function restapi_listener_cert_choices() {
	$out = array();
	foreach (config_get_path('cert', []) as $cert) {
		if (is_array($cert) && !empty($cert['refid']) && !empty($cert['prv']) && !empty($cert['crt'])) {
			$out[$cert['refid']] = (string)($cert['descr'] ?? $cert['refid']);
		}
	}
	return $out;
}

/*
 * Where a listener binds and what its firewall rule targets:
 * array('addresses' => [ip, ...], 'realif' => 'em0'|'' , 'to' => pf target|'').
 * An interface binds its current IPv4 and global IPv6 address; a virtual IP
 * its own address; Localhost 127.0.0.1 and ::1 (no firewall rule).
 */
function restapi_listener_resolve(array $l) {
	require_once('interfaces.inc');
	$key = $l['address'];
	$out = array('addresses' => array(), 'realif' => '', 'to' => '');
	if ($key === 'lo0') {
		$out['addresses'] = array('127.0.0.1', '::1');
		return $out;
	}
	$vips = get_configured_vip_list();
	if (isset($vips[$key])) {
		$vip = get_configured_vip($key);
		if (is_ipaddr($vips[$key])) {
			$out['addresses'][] = $vips[$key];
			$out['to'] = $vips[$key];
		}
		if (is_array($vip) && !empty($vip['interface'])) {
			$out['realif'] = (string)get_real_interface($vip['interface']);
		}
		return $out;
	}
	if (config_get_path("interfaces/{$key}") === null) {
		return $out;
	}
	$v4 = get_interface_ip($key);
	$v6 = get_interface_ipv6($key);
	if (is_ipaddrv4($v4)) {
		$out['addresses'][] = $v4;
	}
	if (is_ipaddrv6($v6) && !is_linklocal($v6)) {
		$out['addresses'][] = $v6;
	}
	$out['realif'] = (string)get_real_interface($key);
	$out['to'] = ($out['realif'] !== '') ? "({$out['realif']})" : '';
	return $out;
}

/* What listens on TCP port $port on all addresses or on one of $addresses now, as "command (address)" strings. */
function restapi_listener_port_users($port, array $addresses) {
	$lines = array();
	exec('/usr/bin/sockstat -4 -6 -l -P tcp -p ' . (int)$port . ' 2>/dev/null', $lines);
	return restapi_listener_parse_sockstat($lines, $addresses);
}

/*
 * sockstat -l output (pure): "command (local address)" per listening socket
 * that conflicts with binding $addresses (one on all addresses, "*", or on
 * one of them), without duplicates.
 */
function restapi_listener_parse_sockstat(array $lines, array $addresses) {
	$out = array();
	foreach ($lines as $line) {
		$f = preg_split('/\s+/', trim($line));
		if ((count($f) < 6) || ($f[0] === 'USER') || !ctype_digit($f[2])) {
			continue;
		}
		$host = trim(substr($f[5], 0, (int)strrpos($f[5], ':')), '[]');
		if (($host === '*') || in_array($host, $addresses, true)) {
			$out["{$f[1]} ({$f[5]})"] = true;
		}
	}
	return array_keys($out);
}

/* Ports other services already use on every address (WebGUI, its HTTP redirect, SSH). */
function restapi_listener_reserved_ports() {
	require_once('filter.inc');
	return array_map('intval', filter_get_antilockout_ports(true));
}

function restapi_listener_validate(array $post, $id = null) {
	$input_errors = array();
	$choices = restapi_listener_address_choices();
	$address = (string)($post['address'] ?? '');
	if (!isset($choices[$address])) {
		$input_errors[] = gettext('Choose the interface or virtual IP to listen on.');
	}
	$port = (string)($post['port'] ?? '');
	if (!ctype_digit($port) || ((int)$port < 1) || ((int)$port > 65535)) {
		$input_errors[] = gettext('The port must be a number from 1 to 65535.');
	} elseif (in_array((int)$port, restapi_listener_reserved_ports(), true)) {
		$input_errors[] = sprintf(gettext('Port %d is used by the WebGUI or SSH. Choose another port, e.g. %d.'), (int)$port, RESTAPI_LISTENER_DEFAULT_PORT);
	} else {
		foreach (restapi_listeners() as $other) {
			if (($other['id'] !== (string)$id) && $other['enable'] && ($other['address'] === $address) && ($other['port'] === (int)$port)) {
				$input_errors[] = sprintf(gettext('Another listener already uses this address and port %d.'), (int)$port);
			}
		}
		/*
		 * Another service on the port: a listener bound to one address would
		 * take that address over from a service listening on all addresses.
		 * A listener that keeps its own address and port is not checked.
		 */
		list(, $current) = ($id === null) ? array(null, null) : restapi_listener_find($id);
		$own = is_array($current) && $current['enable'] && ($current['port'] === (int)$port) && ($current['address'] === $address);
		$users = $own ? array() : restapi_listener_port_users((int)$port,
		    restapi_listener_resolve(restapi_listener_normalize(array('address' => $address)))['addresses']);
		if (!empty($users)) {
			$input_errors[] = sprintf(gettext('Port %1$d is already in use (%2$s). Choose another port.'), (int)$port,
			    htmlspecialchars(implode(', ', $users)));
		}
	}
	if (!isset(restapi_listener_cert_choices()[(string)($post['certref'] ?? '')])) {
		$input_errors[] = gettext('Choose a certificate with a private key (System > Certificates).');
	}
	foreach (restapi_parse_networks($post['allowednetworks'] ?? '') as $net) {
		if (!restapi_valid_network($net)) {
			$input_errors[] = sprintf(gettext('"%s" is not a valid address or network.'), htmlspecialchars($net));
		}
	}
	$rate = (string)($post['ratelimit'] ?? '');
	if (($rate !== '') && (!ctype_digit($rate) || ((int)$rate > 1000))) {
		$input_errors[] = gettext('The rate limit must be from 0 (off) to 1000 requests per second.');
	}
	if (strlen((string)($post['descr'] ?? '')) > 80) {
		$input_errors[] = gettext('The description may have at most 80 characters.');
	}
	if (($id === null) && (count(restapi_listeners()) >= RESTAPI_LISTENER_MAX)) {
		$input_errors[] = sprintf(gettext('At most %d listeners can be configured.'), RESTAPI_LISTENER_MAX);
	}
	return $input_errors;
}

/* Add ($id null) or change a listener, save, and apply. Returns array('input_errors' => [...]). */
function restapi_listener_save(array $post, $id = null) {
	$input_errors = restapi_listener_validate($post, $id);
	if (!empty($input_errors)) {
		return array('input_errors' => $input_errors);
	}
	$entry = array(
		'id' => ($id === null) ? bin2hex(random_bytes(4)) : (string)$id,
		'descr' => trim((string)($post['descr'] ?? '')),
		'address' => (string)$post['address'],
		'port' => (int)$post['port'],
		'certref' => (string)$post['certref'],
		'allowednetworks' => implode(' ', restapi_parse_networks($post['allowednetworks'] ?? '')),
		'ratelimit' => (($post['ratelimit'] ?? '') === '') ? RESTAPI_LISTENER_DEFAULT_RATE : (int)$post['ratelimit'],
	);
	foreach (array('enable', 'readonly', 'firewall') as $flag) {
		if (!empty($post[$flag])) {
			$entry[$flag] = true;
		}
	}
	$list = array_values(array_filter((array)config_get_path('restapi/apilistener', []), 'is_array'));
	list($idx) = ($id === null) ? array(null) : restapi_listener_find($id);
	if ($idx === null) {
		$list[] = $entry;
	} else {
		$list[$idx] = $entry;
	}
	config_set_path('restapi/apilistener', $list);
	write_config(sprintf(gettext('REST API listener %1$s (port %2$d) saved.'), $entry['id'], $entry['port']));
	restapi_listeners_apply();
	return array('input_errors' => array(), 'id' => $entry['id']);
}

function restapi_listener_delete($id) {
	list($idx, $l) = restapi_listener_find($id);
	if ($idx === null) {
		return false;
	}
	$list = array_values(array_filter((array)config_get_path('restapi/apilistener', []), 'is_array'));
	array_splice($list, $idx, 1);
	config_set_path('restapi/apilistener', $list);
	write_config(sprintf(gettext('REST API listener %1$s (port %2$d) deleted.'), $l['id'], $l['port']));
	restapi_listeners_apply();
	return true;
}

function restapi_listener_set_enabled($id, $enable) {
	list($idx, $l) = restapi_listener_find($id);
	if ($idx === null) {
		return false;
	}
	if ($enable) {
		config_set_path("restapi/apilistener/{$idx}/enable", true);
	} else {
		config_del_path("restapi/apilistener/{$idx}/enable");
	}
	write_config(sprintf($enable ? gettext('REST API listener %1$s (port %2$d) enabled.') :
	    gettext('REST API listener %1$s (port %2$d) disabled.'), $l['id'], $l['port']));
	restapi_listeners_apply();
	return true;
}

/* Restart the listeners and reload the firewall rules after a change. */
function restapi_listeners_apply() {
	require_once('filter.inc');
	restapi_listeners_configure();
	filter_configure();
}

/*
 * An nginx configuration for listeners (pure); each listener gets its own
 * (restapi_listeners_configure()). $rows: array of
 * array('id', 'port', 'addresses' => [...], 'ratelimit', 'crt', 'key').
 */
function restapi_listener_nginx_conf(array $rows, $pid, $socket = '/var/run/php-fpm.socket') {
	$conf = "# REST API listeners (generated by restapi_listener.inc; serves only /api/)\n" .
	    "pid {$pid};\nuser root wheel;\nworker_processes 1;\n" .
	    "error_log syslog:server=unix:/var/run/log,facility=local5;\n\n" .
	    "events {\n\tworker_connections 512;\n}\n\n" .
	    "http {\n" .
	    "\tdefault_type application/json;\n\tserver_tokens off;\n\taccess_log off;\n\tsendfile off;\n" .
	    "\tkeepalive_timeout 30;\n\tclient_header_timeout 10;\n\tclient_body_timeout 30;\n\tclient_max_body_size 64m;\n" .
	    "\tlimit_req_status 429;\n";
	foreach ($rows as $r) {
		if ((int)$r['ratelimit'] > 0) {
			$conf .= "\tlimit_req_zone \$binary_remote_addr zone=restapi_{$r['id']}:2m rate=" . (int)$r['ratelimit'] . "r/s;\n";
		}
	}
	foreach ($rows as $r) {
		$port = (int)$r['port'];
		$conf .= "\n\tserver {\n";
		foreach ($r['addresses'] as $addr) {
			$conf .= "\t\tlisten " . (is_ipaddrv6($addr) ? "[{$addr}]" : $addr) . ":{$port} ssl;\n";
		}
		$conf .= "\t\thttp2 on;\n" .
		    "\t\troot /usr/local/www;\n" .
		    "\t\tssl_certificate {$r['crt']};\n\t\tssl_certificate_key {$r['key']};\n" .
		    "\t\tssl_protocols TLSv1.2 TLSv1.3;\n" .
		    "\t\tssl_ciphers \"EECDH+AESGCM:EDH+AESGCM:AES256+EECDH:AES256+EDH:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305\";\n" .
		    "\t\tssl_prefer_server_ciphers on;\n\t\tssl_session_timeout 10m;\n\t\tssl_session_tickets off;\n" .
		    "\t\tadd_header Strict-Transport-Security \"max-age=31536000\" always;\n" .
		    "\t\tadd_header X-Content-Type-Options nosniff always;\n" .
		    "\t\tlocation ^~ /api/ {\n";
		if ((int)$r['ratelimit'] > 0) {
			$conf .= "\t\t\tlimit_req zone=restapi_{$r['id']} burst=" . ((int)$r['ratelimit'] * 2) . " nodelay;\n";
		}
		$conf .= "\t\t\tfastcgi_pass unix:{$socket};\n" .
		    "\t\t\tfastcgi_param SCRIPT_FILENAME /usr/local/www/api/index.php;\n" .
		    "\t\t\tfastcgi_param HTTP_PROXY \"\";\n" .
		    "\t\t\tfastcgi_param RESTAPI_LISTENER {$r['id']};\n" .
		    "\t\t\tfastcgi_read_timeout 300;\n" .
		    "\t\t\tinclude /usr/local/etc/nginx/fastcgi_params;\n" .
		    "\t\t}\n" .
		    "\t\tlocation / {\n" .
		    "\t\t\treturn 404 '{\"error\":{\"code\":\"not_found\",\"message\":\"Only the REST API (/api/) is served on this port.\"}}';\n" .
		    "\t\t}\n" .
		    "\t}\n";
	}
	return $conf . "}\n";
}

/* The files of listener $id's own nginx instance. */
function restapi_listener_paths($id) {
	return array(
		'conf' => "/var/etc/nginx-restapi-{$id}.conf",
		'pid' => "/var/run/nginx-restapi-{$id}.pid",
		'crt' => "/var/etc/restapi-{$id}.crt",
		'key' => "/var/etc/restapi-{$id}.key",
	);
}

function restapi_listener_running($id) {
	$pid = restapi_listener_paths($id)['pid'];
	return is_file($pid) && isvalidpid($pid);
}

/* Stop listener $id's nginx and wait (up to 5 s) until its port is free. */
function restapi_listener_stop($id) {
	$pid = restapi_listener_paths($id)['pid'];
	if (restapi_listener_running($id)) {
		killbypid($pid, 5);
	}
	@unlink($pid);
}

/* Write $content to $file (mode $mode) when it differs; returns whether it changed. */
function restapi_listener_write($file, $content, $mode) {
	if (@file_get_contents($file) === $content) {
		return false;
	}
	@file_put_contents($file, $content);
	@chmod($file, $mode);
	return true;
}

/*
 * Start, restart or stop each listener's own nginx instance to match the
 * configuration. A listener is restarted only when its configuration or
 * certificate changed (or it is not running), and one that cannot start
 * (an address that is not up, a port in use) never affects the others or
 * the WebGUI. Instances of removed or disabled listeners are stopped and
 * their files removed. Records each listener's state for the GUI.
 * Returns false when an enabled listener could not start. $force restarts
 * every enabled listener.
 */
function restapi_listeners_configure($force = false) {
	require_once('certs.inc');
	$status = array();
	$wanted = array();
	$enabled = function_exists('restapi_enabled') && restapi_enabled();
	$ok = true;
	foreach (restapi_listeners() as $l) {
		if (!$l['enable'] || !$enabled) {
			$status[$l['id']] = array('state' => $enabled ? 'disabled' : 'api_disabled', 'addresses' => array());
			continue;
		}
		$wanted[$l['id']] = true;
		$paths = restapi_listener_paths($l['id']);
		$where = restapi_listener_resolve($l);
		$cert = lookup_cert($l['certref']);
		$cert = is_array($cert) ? ($cert['item'] ?? null) : null;
		if (empty($where['addresses']) || !is_array($cert) || empty($cert['crt']) || empty($cert['prv'])) {
			restapi_listener_stop($l['id']);
			$status[$l['id']] = array('state' => empty($where['addresses']) ? 'no_address' : 'no_certificate', 'addresses' => $where['addresses']);
			continue;
		}
		$chain = ca_chain($cert);
		$changed = restapi_listener_write($paths['crt'], str_replace("\r", '', base64_decode($cert['crt'])) .
		    (($chain !== '') ? "\n{$chain}" : ''), 0644);
		$changed = restapi_listener_write($paths['key'], str_replace("\r", '', base64_decode($cert['prv'])), 0600) || $changed;
		$changed = restapi_listener_write($paths['conf'], restapi_listener_nginx_conf(array(array('id' => $l['id'], 'port' => $l['port'],
		    'addresses' => $where['addresses'], 'ratelimit' => $l['ratelimit'], 'crt' => $paths['crt'], 'key' => $paths['key'])),
		    $paths['pid']), 0644) || $changed;
		$status[$l['id']] = array('state' => 'running', 'addresses' => $where['addresses']);
		if (!$force && !$changed && restapi_listener_running($l['id'])) {
			continue;
		}

		$out = array();
		exec('/usr/local/sbin/nginx -t -c ' . escapeshellarg($paths['conf']) . ' 2>&1', $out, $rc);
		restapi_listener_stop($l['id']);
		if ($rc === 0) {
			$out = array();
			exec('/usr/local/sbin/nginx -c ' . escapeshellarg($paths['conf']) . ' 2>&1', $out, $rc);
			/* The master writes its pid file just after it daemonizes. */
			for ($i = 0; ($i < 10) && ($rc === 0) && !restapi_listener_running($l['id']); $i++) {
				usleep(100000);
			}
		}
		if (($rc !== 0) || !restapi_listener_running($l['id'])) {
			$error = restapi_listener_nginx_error($out);
			$status[$l['id']] = array('state' => 'error', 'addresses' => $where['addresses'],
			    'error' => ($error !== '') ? $error : gettext('nginx did not start'));
			/* Remove the configuration so the next run tries again. */
			@unlink($paths['conf']);
			logger(LOG_ERR, localize_text('REST API listener %1$s (port %2$d) could not start: %3$s', $l['id'], $l['port'],
			    $status[$l['id']]['error']));
			$ok = false;
		}
	}
	/* Instances of listeners that were removed or disabled. */
	foreach ((array)glob('/var/etc/nginx-restapi-*.conf') as $file) {
		if (preg_match('#/nginx-restapi-([0-9a-f]{8})\.conf$#', $file, $m) && !isset($wanted[$m[1]])) {
			restapi_listener_stop($m[1]);
			$paths = restapi_listener_paths($m[1]);
			@unlink($paths['conf']);
			@unlink($paths['crt']);
			@unlink($paths['key']);
		}
	}
	@file_put_contents(RESTAPI_LISTENER_STATUS, json_encode(array('time' => time(), 'listeners' => $status)));
	return $ok;
}

/* The useful part of nginx's error output (the emerg/error line without the timestamp). */
function restapi_listener_nginx_error(array $out) {
	foreach ($out as $line) {
		if (preg_match('/\[(emerg|error|crit|alert)\]\s+(?:\d+#\d+:\s+)?(.*)$/', $line, $m)) {
			return $m[2];
		}
	}
	return trim(implode(' ', $out));
}

/* Each listener's last recorded state; a recorded "running" is checked against its process. */
function restapi_listener_status() {
	$data = @json_decode((string)@file_get_contents(RESTAPI_LISTENER_STATUS), true);
	$items = is_array($data['listeners'] ?? null) ? $data['listeners'] : array();
	foreach ($items as $id => $s) {
		if ((($s['state'] ?? '') === 'running') && preg_match('/^[0-9a-f]{8}$/', (string)$id) && !restapi_listener_running($id)) {
			$items[$id]['state'] = 'stopped';
		}
	}
	return $items;
}

/* Reconfigure after an interface address change when a listener uses that interface (or one of its virtual IPs). */
function restapi_listeners_interface_changed($interface) {
	require_once('interfaces.inc');
	foreach (restapi_listeners() as $l) {
		if (!$l['enable']) {
			continue;
		}
		$vip = (strpos($l['address'], '_vip') === 0) ? get_configured_vip($l['address']) : null;
		if (($l['address'] === $interface) || (is_array($vip) && (($vip['interface'] ?? '') === $interface))) {
			restapi_listeners_configure();
			return true;
		}
	}
	return false;
}

/*
 * Firewall pass rules for listeners with "Add firewall rule" (pure):
 * $targets from restapi_listener_firewall_targets(). $tracker and $label are
 * filter.inc's rule ID and label callbacks.
 */
function restapi_listener_filter_rules(array $targets, $log, callable $tracker, callable $label) {
	$rules = array();
	foreach ($targets as $t) {
		$from = empty($t['sources']) ? 'any' : '{ ' . implode(' ', $t['sources']) . ' }';
		$rules[] = "pass in {$log} quick on {$t['realif']} proto tcp from {$from} to {$t['to']} port {$t['port']} " .
		    "ridentifier {$tracker()} keep state " . $label("REST API listener {$t['id']}");
	}
	return $rules;
}

/* What restapi_listener_filter_rules() needs for each enabled listener with a firewall rule. */
function restapi_listener_firewall_targets() {
	$targets = array();
	if (!restapi_enabled()) {
		return $targets;
	}
	$global = restapi_parse_networks(restapi_settings()['allowednetworks']);
	foreach (restapi_listeners() as $l) {
		if (!$l['enable'] || !$l['firewall']) {
			continue;
		}
		$where = restapi_listener_resolve($l);
		if (($where['realif'] === '') || ($where['to'] === '')) {
			continue;
		}
		$own = restapi_parse_networks($l['allowednetworks']);
		$targets[] = array('id' => $l['id'], 'realif' => $where['realif'], 'to' => $where['to'], 'port' => $l['port'],
		    'sources' => !empty($own) ? $own : $global);
	}
	return $targets;
}

/* Ports of enabled listeners (login protection blocks them like the WebGUI port). */
function restapi_listener_ports() {
	$ports = array();
	foreach (restapi_listeners() as $l) {
		if ($l['enable']) {
			$ports[$l['port']] = $l['port'];
		}
	}
	return array_values($ports);
}

/* Whether a listener uses certificate $refid (it may not be deleted; renewing it restarts the listeners). */
function restapi_listener_uses_cert($refid) {
	foreach (restapi_listeners() as $l) {
		if ($l['certref'] === (string)$refid) {
			return true;
		}
	}
	return false;
}
