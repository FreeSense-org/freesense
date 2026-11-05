<?php
/*
 * input_gate.inc
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2025-2026 The FreeSense Project
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
 * One check for every signed-in WebUI request and REST call, before any page
 * or handler code runs. It knows nothing about what a field means: it only
 * refuses text no form field needs, such as control characters, and line
 * breaks in fields that are not multi-line.
 *
 * config system/webgui/input_gate: off, log (default) or enforce.
 * Field names and problem classes are logged, never values.
 */

const INPUT_GATE_MAX_DEPTH = 3;
const INPUT_GATE_MAX_VALUES = 4096;
const INPUT_GATE_MAX_HITS = 20;
const INPUT_GATE_SKIP = ['__csrf_magic'];

/* Root tools: the input is a command or a whole file. */
const INPUT_GATE_EXEMPT_PAGES = ['diag_command.php', 'diag_edit.php'];

/* These check themselves once their XML is loaded: see input_gate_run_xml(). */
const INPUT_GATE_XML_PAGES = ['pkg_edit.php', 'pkg.php', 'wizard.php'];

/* Multi-line fields of the core pages. A package page declares its own by
 * setting $input_gate_multiline before it includes guiconfig.inc. */
const INPUT_GATE_MULTILINE = [
	'diag_backup_remote_edit.php' => ['sftp_pubkey_display'],
	'firewall_aliases_import.php' => ['aliasimport'],
	'pkg_mgr_install.php' => ['output'],
	'pkg_mgr_repos_edit.php' => ['key'],
	'services_captiveportal.php' => ['termsconditions'],
	'services_captiveportal_vouchers.php' => ['publickey', 'privatekey'],
	'services_dhcp.php' => ['custom_kea_config'],
	'services_dhcp_edit.php' => ['custom_kea_config'],
	'services_dhcp_settings.php' => ['custom_kea_config'],
	'services_dhcpv6.php' => ['custom_kea_config'],
	'services_dhcpv6_edit.php' => ['custom_kea_config'],
	'services_dhcpv6_settings.php' => ['custom_kea_config'],
	'services_dnsmasq.php' => ['custom_options'],
	'services_dyndns_edit.php' => ['resultmatch'],
	'services_ntpd.php' => ['leaptext'],
	'services_ntpd_gps.php' => ['gpsinitcmd'],
	'services_unbound.php' => ['custom_options'],
	'status_captiveportal_expire.php' => ['vouchers'],
	'status_captiveportal_test.php' => ['vouchers'],
	'system.php' => ['login_message'],
	'system_camanager.php' => ['cert', 'key'],
	'system_certmanager.php' => ['cert', 'csr', 'csrpaste', 'key', 'keypaste'],
	'system_crlmanager.php' => ['crltext'],
	'system_usermanager.php' => ['authorizedkeys'],
	'vpn_openvpn_client.php' => ['custom_options', 'shared_key', 'tls'],
	'vpn_openvpn_csc.php' => ['custom_options'],
	'vpn_openvpn_server.php' => ['custom_options', 'shared_key', 'tls'],
	'rss.widget.php' => ['rssfeed'],
];

/* Multi-line fields of the REST API, whatever the route. */
const INPUT_GATE_REST_MULTILINE = [
	'authorizedkeys', 'cert', 'certificate', 'config', 'crltext', 'csr',
	'custom_kea_config', 'custom_options', 'gpsinitcmd', 'invitation', 'key',
	'leaptext', 'login_message', 'privatekey', 'private_key', 'publickey',
	'resultmatch', 'shared_key', 'termsconditions', 'text', 'tls', 'vouchers',
];

/*
 * The problems in a set of request values, as [field, class] pairs. Pure.
 * A multiline entry is a field name; "name*" also covers name0, name1 and so on.
 * Classes: control, newline, tab, encoding, name, depth, count.
 */
function input_gate_scan(array $values, array $multiline = [], array $skip = []): array {
	$hits = [];
	$count = 0;
	input_gate_walk($values, '', 1, false, $multiline, $skip, $hits, $count);
	return array_values($hits);
}

function input_gate_walk(array $values, string $prefix, int $depth, bool $inherited, array $multiline, array $skip, array &$hits, int &$count): void {
	foreach ($values as $key => $value) {
		$key = (string)$key;
		$top = ($prefix === '');
		if ($top && in_array($key, $skip, true)) {
			continue;
		}
		$valid = (preg_match('/^[A-Za-z0-9_.:\-\[\]]{1,128}$/D', $key) === 1);
		/* A name that fails the check never reaches the log. */
		$field = $top ? ($valid ? $key : '(invalid name)') : $prefix . '[' . ($valid ? $key : '(invalid name)') . ']';
		if (!$valid) {
			input_gate_hit($hits, $field, 'name');
		}
		if (++$count > INPUT_GATE_MAX_VALUES) {
			input_gate_hit($hits, '(request)', 'count');
			return;
		}
		$allowed = $inherited || ($top && input_gate_field_is_multiline($key, $multiline));
		if (is_array($value)) {
			if ($depth >= INPUT_GATE_MAX_DEPTH) {
				input_gate_hit($hits, $field, 'depth');
				continue;
			}
			input_gate_walk($value, $field, $depth + 1, $allowed, $multiline, $skip, $hits, $count);
			continue;
		}
		if (!is_string($value)) {
			continue;
		}
		if (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $value) === 1) {
			input_gate_hit($hits, $field, 'control');
		}
		if (!$allowed && (strpbrk($value, "\r\n") !== false)) {
			input_gate_hit($hits, $field, 'newline');
		}
		if (!$allowed && (strpos($value, "\t") !== false)) {
			input_gate_hit($hits, $field, 'tab');
		}
		if (preg_match('//u', $value) === false) {
			input_gate_hit($hits, $field, 'encoding');
		}
	}
}

function input_gate_hit(array &$hits, string $field, string $class): void {
	if (count($hits) < INPUT_GATE_MAX_HITS) {
		$hits["{$field}={$class}"] = [$field, $class];
	}
}

function input_gate_field_is_multiline(string $key, array $multiline): bool {
	if (in_array($key, $multiline, true)) {
		return true;
	}
	$base = rtrim($key, '0123456789');
	return ($base !== $key) && in_array($base . '*', $multiline, true);
}

/* The multi-line fields of a package or wizard definition. Pure. */
function input_gate_xml_multiline(array $definition): array {
	$found = [];
	array_walk($definition, function ($node) use (&$found) {
		if (is_array($node)) {
			$found = array_merge($found, input_gate_xml_multiline($node));
		}
	});
	if (in_array($definition['type'] ?? '', ['textarea', 'textarea_source'], true)) {
		foreach (['fieldname', 'name'] as $tag) {
			if (is_string($definition[$tag] ?? null)) {
				/* Row helpers post name0, name1 and so on. */
				$found[] = $definition[$tag];
				$found[] = $definition[$tag] . '*';
			}
		}
	}
	return array_values(array_unique($found));
}

function input_gate_page(): string {
	return basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
}

/* The problems in this WebUI request, using the page's own multi-line fields. */
function input_gate_hits(?array $multiline = null): array {
	$page = input_gate_page();
	if ($multiline === null) {
		if (in_array($page, INPUT_GATE_EXEMPT_PAGES, true) || in_array($page, INPUT_GATE_XML_PAGES, true)) {
			return [];
		}
		$declared = $GLOBALS['input_gate_multiline'] ?? [];
		$multiline = array_merge(INPUT_GATE_MULTILINE[$page] ?? [], is_array($declared) ? $declared : []);
	}
	return array_merge(input_gate_scan($_GET, $multiline, INPUT_GATE_SKIP), input_gate_scan($_POST, $multiline, INPUT_GATE_SKIP));
}

function input_gate_mode(): string {
	$mode = config_get_path('system/webgui/input_gate', 'log');
	return in_array($mode, ['off', 'enforce'], true) ? $mode : 'log';
}

/* The hits as one log-safe line. Pure. */
function input_gate_summary(array $hits): string {
	$parts = [];
	foreach ($hits as [$field, $class]) {
		$parts[] = "{$field}={$class}";
	}
	return implode(', ', array_slice(array_unique($parts), 0, INPUT_GATE_MAX_HITS));
}

function input_gate_log(string $mode, string $where, array $hits): void {
	$where = preg_replace('/[^A-Za-z0-9_.\/\-]/', '?', $where);
	logger(LOG_WARNING, "input gate ({$mode}): {$where}: " . input_gate_summary($hits), LOG_PREFIX_WEBGUI);
}

function input_gate_refuse(array $hits): void {
	http_response_code(400);
	header('Content-Type: text/plain; charset=utf-8');
	echo gettext('This request was refused: it has invalid characters in these fields:') . "\n";
	echo input_gate_summary($hits) . "\n";
	exit;
}

function input_gate_report(string $where, array $hits): void {
	if (!$hits) {
		return;
	}
	$mode = input_gate_mode();
	if ($mode === 'off') {
		return;
	}
	input_gate_log($mode, $where, $hits);
	if ($mode === 'enforce') {
		input_gate_refuse($hits);
	}
}

/* Check this WebUI request. Called once the user is signed in and allowed the page. */
function input_gate_run(): void {
	input_gate_report(input_gate_page(), input_gate_hits());
}

/* The same check for a page whose fields come from package or wizard XML. */
function input_gate_run_xml(mixed $definition): void {
	input_gate_report(input_gate_page(), input_gate_hits(input_gate_xml_multiline(is_array($definition) ? $definition : [])));
}

/*
 * Check a REST call. The caller turns a non-empty result into its own error
 * response, so API clients get JSON and not the WebUI's plain text page.
 */
function input_gate_run_rest(string $path, array $query, array $body): array {
	$hits = array_merge(input_gate_scan($query, INPUT_GATE_REST_MULTILINE), input_gate_scan($body, INPUT_GATE_REST_MULTILINE));
	if (!$hits) {
		return [];
	}
	$mode = input_gate_mode();
	if ($mode === 'off') {
		return [];
	}
	input_gate_log($mode, "api{$path}", $hits);
	return ($mode === 'enforce') ? $hits : [];
}
