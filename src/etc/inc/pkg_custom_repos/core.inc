<?php
/*
 * pkg_custom_repos/core.inc
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
 * Custom package repositories: the rules, with no configuration or shell access
 * so they can be tested on their own (tests/PackageRepositoriesSmokeTest.php).
 *
 * The invariant everything here protects: the FreeSense repositories always win.
 * pkg picks a package from the highest-priority repository that has it, whatever
 * the versions are, so custom repositories get priorities that are always below
 * the lowest FreeSense one, and the system upgrader never reads them at all.
 */

/* Priorities of the FreeSense repositories (set by FreeSense-repoc). */
const PKGREPO_PRIORITY_SYSTEM = 100;
const PKGREPO_PRIORITY_PACKAGES = 50;
const PKGREPO_PRIORITY_MIRROR = 10;
/* Custom repositories use CUSTOM_TOP down; the FreeBSD repository is last. */
const PKGREPO_PRIORITY_CUSTOM_TOP = 5;
const PKGREPO_PRIORITY_FREEBSD = 0;
const PKGREPO_MAX_CUSTOM = 5;

const PKGREPO_DIR_CONF = '/usr/local/etc/pkg/custom-repos';
const PKGREPO_DIR_KEYS = '/usr/local/etc/pkg/custom-keys';
/* The directories pkg reads for the system (what FreeSense-upgrade is limited to). */
const PKGREPO_SYSTEM_DIRS = array('/etc/pkg/', '/usr/local/etc/pkg/repos/');

const PKGREPO_SIGNATURES = array('fingerprints', 'pubkey', 'none');

/* Package names a custom repository may never supply. */
const PKGREPO_RESERVED_PATTERN = '/^(FreeSense|FreeBSD|php[0-9]*-FreeSense|pkg$|pkg-|kernel|php8)/i';

/* The lowest priority of any repository FreeSense ships. */
function pkgrepo_lowest_system_priority() {
	return min(PKGREPO_PRIORITY_SYSTEM, PKGREPO_PRIORITY_PACKAGES, PKGREPO_PRIORITY_MIRROR);
}

/* The repository name: it names a file, a pkg repository and a key directory. */
function pkgrepo_name_valid($name) {
	return is_string($name) && preg_match('/^[a-z][a-z0-9_-]{1,31}$/D', $name) === 1 &&
	    preg_match('/^(freesense|freebsd)/', $name) !== 1;
}

/*
 * An https URL with a host and an optional path; nothing that could smuggle
 * anything into the generated configuration file.
 */
function pkgrepo_url_valid($url) {
	if (!is_string($url) || $url === '' || strlen($url) > 255 || preg_match('/^[A-Za-z0-9._~:\/?#\[\]@!$&()*+,;=%-]+$/D', $url) !== 1) {
		return false;
	}
	$parts = parse_url($url);
	if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) ||
	    isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
		return false;
	}
	if (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535)) {
		return false;
	}
	if (preg_match('/^[A-Za-z0-9.-]+$/D', $parts['host']) !== 1 && filter_var(trim($parts['host'], '[]'), FILTER_VALIDATE_IP) === false) {
		return false;
	}
	return !isset($parts['path']) || preg_match('/^[A-Za-z0-9._~\/:@%+-]*$/D', $parts['path']) === 1;
}

/*
 * A fingerprint the way pkg stores it. Accepts the 64 hex digits alone or the
 * two-line file ("function: sha256", "fingerprint: ..."), returns the file
 * content or null.
 */
function pkgrepo_fingerprint_file($text) {
	$text = trim((string)$text);
	if (preg_match('/^[0-9a-fA-F]{64}$/D', $text) === 1) {
		return "function: \"sha256\"\nfingerprint: \"" . strtolower($text) . "\"\n";
	}
	if (preg_match('/^function:\s*"?sha256"?\s*[\r\n]+fingerprint:\s*"?([0-9a-fA-F]{64})"?$/D', $text, $m) === 1) {
		return "function: \"sha256\"\nfingerprint: \"" . strtolower($m[1]) . "\"\n";
	}
	return null;
}

/* Just the 64 hex digits of a fingerprint given in either form, or null. */
function pkgrepo_fingerprint_hex($text) {
	$file = pkgrepo_fingerprint_file($text);
	return ($file !== null && preg_match('/fingerprint: "([0-9a-f]{64})"/', $file, $m) === 1) ? $m[1] : null;
}

/*
 * The base64 body of a PEM public key, or null. The pasted text may have lost
 * its line breaks on the way (a form, an editor), so only the markers and the
 * base64 are relied on; a certificate or private key is refused.
 */
function pkgrepo_pubkey_body($pem) {
	$pem = trim((string)$pem);
	if (strlen($pem) > 4096 ||
	    preg_match('/^-----BEGIN PUBLIC KEY-----\s*([A-Za-z0-9+\/=\s]+?)\s*-----END PUBLIC KEY-----$/D', $pem, $m) !== 1) {
		return null;
	}
	$body = preg_replace('/\s+/', '', $m[1]);
	if ($body === '' || preg_match('/^[A-Za-z0-9+\/]+={0,2}$/D', $body) !== 1) {
		return null;
	}
	if (function_exists('openssl_pkey_get_public') && openssl_pkey_get_public(pkgrepo_pubkey_pem($body)) === false) {
		return null;
	}
	return $body;
}

/* The file pkg reads: the body in 64-character lines between the markers. */
function pkgrepo_pubkey_pem($body) {
	return "-----BEGIN PUBLIC KEY-----\n" . chunk_split($body, 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function pkgrepo_pubkey_valid($pem) {
	return pkgrepo_pubkey_body($pem) !== null;
}

/*
 * Check a submitted repository. $others are the other configured repositories
 * (without this one). Returns a list of messages, empty when valid.
 */
function pkgrepo_validate($post, array $others) {
	$errors = array();
	$name = (string)($post['name'] ?? '');
	if (!pkgrepo_name_valid($name)) {
		$errors[] = gettext('The name must be 2-32 lowercase letters, digits, "-" or "_", start with a letter, and not start with "freesense" or "freebsd".');
	}
	foreach ($others as $other) {
		if (($other['name'] ?? '') === $name) {
			$errors[] = gettext('A repository with that name already exists.');
		}
	}
	if (count($others) >= PKGREPO_MAX_CUSTOM) {
		$errors[] = sprintf(gettext('At most %d custom repositories are supported.'), PKGREPO_MAX_CUSTOM);
	}
	if (strlen((string)($post['descr'] ?? '')) > 120) {
		$errors[] = gettext('The description is limited to 120 characters.');
	}
	if (!pkgrepo_url_valid((string)($post['url'] ?? ''))) {
		$errors[] = gettext('The URL must be an https:// address without a user name, password or query.');
	}
	$type = (string)($post['signature_type'] ?? '');
	if (!in_array($type, PKGREPO_SIGNATURES, true)) {
		$errors[] = gettext('Choose how the repository is verified.');
	} elseif ($type === 'fingerprints' && pkgrepo_fingerprint_file($post['key'] ?? '') === null) {
		$errors[] = gettext('The fingerprint must be 64 hexadecimal digits (SHA-256).');
	} elseif ($type === 'pubkey' && !pkgrepo_pubkey_valid($post['key'] ?? '')) {
		$errors[] = gettext('The public key must be a PEM "PUBLIC KEY" block.');
	} elseif ($type === 'none' && empty($post['ack_unsigned'])) {
		$errors[] = gettext('Confirm that you understand an unsigned repository cannot be verified.');
	}
	return $errors;
}

/*
 * The priority of each repository, by name. The list order is the priority
 * order: the first custom repository gets the highest one. Never user-editable.
 */
function pkgrepo_priorities(array $repos) {
	$priorities = array();
	$next = PKGREPO_PRIORITY_CUSTOM_TOP;
	foreach (array_values($repos) as $repo) {
		$priorities[$repo['name']] = max($next, PKGREPO_PRIORITY_FREEBSD + 1);
		$next--;
	}
	return $priorities;
}

/* A UCL string value; the validated input cannot hold a quote, this keeps it that way. */
function pkgrepo_ucl_string($value) {
	return '"' . str_replace(array('\\', '"', "\n", "\r"), array('\\\\', '\\"', '', ''), (string)$value) . '"';
}

/* The pkg configuration for one repository. */
function pkgrepo_render_conf(array $repo, $priority) {
	$name = $repo['name'];
	$lines = array(
		"{$name}: {",
		'  url: ' . pkgrepo_ucl_string($repo['url']) . ',',
		'  mirror_type: "none",',
	);
	if ($repo['signature_type'] === 'fingerprints') {
		$lines[] = '  signature_type: "fingerprints",';
		$lines[] = '  fingerprints: ' . pkgrepo_ucl_string(PKGREPO_DIR_KEYS . "/{$name}") . ',';
	} elseif ($repo['signature_type'] === 'pubkey') {
		$lines[] = '  signature_type: "pubkey",';
		$lines[] = '  pubkey: ' . pkgrepo_ucl_string(PKGREPO_DIR_KEYS . "/{$name}/pubkey.pem") . ',';
	}
	$lines[] = '  priority: ' . (int)$priority . ',';
	$lines[] = '  enabled: ' . (empty($repo['enable']) ? 'no' : 'yes');
	$lines[] = "}\n";
	return implode("\n", $lines);
}

/*
 * Everything that has to exist on disk for the configuration, as path (relative
 * to /usr/local/etc/pkg) => content. The FreeBSD repository is the shipped
 * definition switched on at the lowest priority; its kernel-module repository
 * stays off because it is built for FreeBSD's kernel, not ours.
 */
function pkgrepo_render_all(array $repos, $freebsd) {
	$files = array();
	$priorities = pkgrepo_priorities($repos);
	foreach ($repos as $repo) {
		$name = $repo['name'];
		$files["custom-repos/{$name}.conf"] = pkgrepo_render_conf($repo, $priorities[$name]);
		if ($repo['signature_type'] === 'fingerprints') {
			$files["custom-keys/{$name}/trusted/{$name}"] = pkgrepo_fingerprint_file($repo['key']);
			$files["custom-keys/{$name}/revoked/.keep"] = '';
		} elseif ($repo['signature_type'] === 'pubkey') {
			$files["custom-keys/{$name}/pubkey.pem"] = pkgrepo_pubkey_pem(pkgrepo_pubkey_body($repo['key']) ?? $repo['key']);
		}
	}
	if ($freebsd) {
		$files['custom-repos/FreeBSD-ports.conf'] = 'FreeBSD-ports: { enabled: yes, priority: ' . PKGREPO_PRIORITY_FREEBSD . " }\n";
	}
	return $files;
}

/* The REPOS_DIR value: system directories, plus the custom one when wanted. */
function pkgrepo_repos_dir($with_custom) {
	$dirs = PKGREPO_SYSTEM_DIRS;
	if ($with_custom) {
		$dirs[] = PKGREPO_DIR_CONF . '/';
	}
	return implode(',', $dirs);
}

/*
 * What pkg says it will do (pkg install -n / upgrade -n). Returns
 * array('new' => [[name, version, repo]], 'changed' => [[name, detail, repo]],
 * 'removed' => [name]).
 */
function pkgrepo_parse_plan($output) {
	$plan = array('new' => array(), 'changed' => array(), 'removed' => array());
	$section = '';
	foreach (preg_split('/\r?\n/', (string)$output) as $line) {
		if (preg_match('/^New packages to be INSTALLED:/', $line)) {
			$section = 'new';
		} elseif (preg_match('/^Installed packages to be (UPGRADED|REINSTALLED|DOWNGRADED):/', $line)) {
			$section = 'changed';
		} elseif (preg_match('/^(Installed|Existing) packages to be REMOVED:/', $line)) {
			$section = 'removed';
		} elseif (preg_match('/^[A-Z][A-Za-z ]+:$/', $line) || trim($line) === '') {
			$section = '';
		} elseif ($section !== '' && preg_match('/^\s+(\S+?): (.+?)(?: \[([^\]]+)\])?$/', $line, $m)) {
			if ($section === 'removed') {
				$plan['removed'][] = $m[1];
			} else {
				$plan[$section][] = array($m[1], $m[2], $m[3] ?? '');
			}
		} elseif ($section === 'removed' && preg_match('/^\s+(\S+)$/', $line, $m)) {
			$plan['removed'][] = $m[1];
		}
	}
	return $plan;
}

/*
 * Decide whether a plan from a custom (or the FreeBSD) repository is safe.
 * $foreign are the repository names that are not FreeSense's, $system the
 * package names the FreeSense repositories supply. Returns a list of reasons
 * the plan is refused, empty when it is fine.
 */
function pkgrepo_guard(array $plan, array $foreign, array $system) {
	$reasons = array();
	foreach ($plan['changed'] as $item) {
		$reasons[] = sprintf(gettext('It would change the installed package %s.'), $item[0]);
	}
	foreach ($plan['removed'] as $name) {
		$reasons[] = sprintf(gettext('It would remove the installed package %s.'), $name);
	}
	foreach ($plan['new'] as $item) {
		if (!in_array($item[2], $foreign, true)) {
			continue;
		}
		if (preg_match(PKGREPO_RESERVED_PATTERN, $item[0]) === 1) {
			$reasons[] = sprintf(gettext('%s is a name reserved for FreeSense and the base system.'), $item[0]);
		} elseif (in_array($item[0], $system, true)) {
			$reasons[] = sprintf(gettext('%s is supplied by the FreeSense repositories and cannot come from another one.'), $item[0]);
		}
	}
	return array_values(array_unique($reasons));
}

/*
 * Decide whether removing a package is safe: it must come from a repository
 * that is not FreeSense's, and so must everything pkg would take with it (a
 * removal also removes what depends on the package). $origin maps installed
 * package name => repository it came from. Returns the reasons it is refused.
 */
function pkgrepo_guard_removal($name, array $plan, array $origin, array $foreign) {
	$reasons = array();
	if (!isset($origin[$name])) {
		return array(sprintf(gettext('%s is not installed.'), $name));
	}
	if (!in_array($origin[$name], $foreign, true)) {
		$reasons[] = sprintf(gettext('%s did not come from a custom repository, so it is managed by FreeSense.'), $name);
	}
	if (preg_match(PKGREPO_RESERVED_PATTERN, $name) === 1) {
		$reasons[] = sprintf(gettext('%s is a name reserved for FreeSense and the base system.'), $name);
	}
	foreach ($plan['removed'] as $removed) {
		if ($removed !== $name && !in_array($origin[$removed] ?? '', $foreign, true)) {
			$reasons[] = sprintf(gettext('It would also remove %s, which belongs to FreeSense.'), $removed);
		}
	}
	return array_values(array_unique($reasons));
}

