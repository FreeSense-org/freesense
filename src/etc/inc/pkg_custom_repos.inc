<?php
/*
 * pkg_custom_repos.inc
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
 * Custom package repositories (System > Package Manager > Repositories).
 *
 * config.xml is the source of truth (packagerepos/pkgrepo, packagerepos/freebsd);
 * it lives on /cf and is carried over by every firmware upgrade. The pkg files
 * are rendered from it into /usr/local/etc/pkg/custom-repos, a directory pkg does
 * not read by itself, so the system upgrader never sees these repositories.
 * The WebUI asks for them explicitly (REPOS_DIR) when it needs them.
 * The rules live in pkg_custom_repos/core.inc.
 */

require_once('config.inc');
require_once('pkg-utils.inc');
require_once('pkg_custom_repos/core.inc');

/* The configured custom repositories, in priority order. */
function pkgrepo_list() {
	$repos = config_get_path('packagerepos/pkgrepo', array());
	if (!is_array($repos)) {
		return array();
	}
	$list = array();
	foreach ($repos as $repo) {
		if (!is_array($repo)) {
			continue;
		}
		/* config.xml writes an empty element for "on", and "key" is a list tag there, so the key is stored as keydata */
		$repo['enable'] = isset($repo['enable']);
		$repo['ack_unsigned'] = isset($repo['ack_unsigned']);
		$repo['key'] = is_string($repo['keydata'] ?? null) ? $repo['keydata'] : '';
		unset($repo['keydata']);
		$list[] = $repo;
	}
	return $list;
}

function pkgrepo_find($id) {
	foreach (pkgrepo_list() as $index => $repo) {
		if (($repo['id'] ?? '') === (string)$id) {
			return array($index, $repo);
		}
	}
	return array(null, null);
}

function pkgrepo_freebsd_enabled() {
	return config_path_enabled('packagerepos', 'freebsd');
}

/* The priority a repository gets (never stored, always computed). */
function pkgrepo_priority_of(array $repo) {
	$priorities = pkgrepo_priorities(pkgrepo_list());
	return $priorities[$repo['name']] ?? PKGREPO_PRIORITY_FREEBSD;
}

/*
 * Add ($id empty) or change a repository from a form post. Returns
 * array('input_errors' => [...], 'id' => id).
 */
function pkgrepo_save($post, $id = '') {
	$repos = pkgrepo_list();
	$index = null;
	$existing = array();
	$others = array();
	foreach ($repos as $i => $repo) {
		if ($id !== '' && ($repo['id'] ?? '') === $id) {
			$index = $i;
			$existing = $repo;
		} else {
			$others[] = $repo;
		}
	}
	if ($id !== '' && $index === null) {
		return array('input_errors' => array(gettext('That repository does not exist.')), 'id' => $id);
	}
	/* Keep the stored key when the form is saved without retyping it. */
	if (($post['key'] ?? '') === '' && ($post['signature_type'] ?? '') === ($existing['signature_type'] ?? null)) {
		$post['key'] = $existing['key'] ?? '';
	}
	$errors = pkgrepo_validate($post, $others);
	if (!empty($errors)) {
		return array('input_errors' => $errors, 'id' => $id);
	}
	$type = $post['signature_type'];
	$entry = array(
		'id' => ($id !== '') ? $id : bin2hex(random_bytes(6)),
		'name' => $post['name'],
		'descr' => trim((string)($post['descr'] ?? '')),
		'url' => rtrim($post['url'], '/'),
		'signature_type' => $type,
		'key' => ($type === 'none') ? '' : (($type === 'pubkey') ? pkgrepo_pubkey_body($post['key']) : pkgrepo_fingerprint_hex($post['key'])),
	);
	if (!empty($post['enable'])) {
		$entry['enable'] = true;
	}
	if ($type === 'none') {
		$entry['ack_unsigned'] = true;
	}
	if ($index === null) {
		$repos[] = $entry;
	} else {
		$repos[$index] = $entry;
	}
	pkgrepo_store($repos, sprintf(gettext('Package repository "%s" saved.'), $entry['name']));
	return array('input_errors' => array(), 'id' => $entry['id']);
}

function pkgrepo_store(array $repos, $message) {
	$stored = array();
	foreach (array_values($repos) as $repo) {
		$repo['keydata'] = $repo['key'] ?? '';
		unset($repo['key']);
		foreach (array('enable', 'ack_unsigned') as $flag) {
			if (!empty($repo[$flag])) {
				$repo[$flag] = true;
			} else {
				unset($repo[$flag]);
			}
		}
		$stored[] = $repo;
	}
	config_set_path('packagerepos/pkgrepo', $stored);
	write_config($message);
	pkgrepo_apply();
}

function pkgrepo_delete($id) {
	$repos = array();
	$name = '';
	foreach (pkgrepo_list() as $repo) {
		if (($repo['id'] ?? '') === (string)$id) {
			$name = $repo['name'];
		} else {
			$repos[] = $repo;
		}
	}
	if ($name === '') {
		return false;
	}
	pkgrepo_store($repos, sprintf(gettext('Package repository "%s" deleted.'), $name));
	/* its cached catalogue goes too, so its packages are no longer offered */
	if (pkgrepo_name_valid($name)) {
		mwexec('/bin/rm -rf ' . escapeshellarg('/var/db/pkg/repos/' . $name));
	}
	return true;
}

function pkgrepo_toggle($id) {
	$repos = pkgrepo_list();
	foreach ($repos as $i => $repo) {
		if (($repo['id'] ?? '') === (string)$id) {
			if (empty($repo['enable'])) {
				$repos[$i]['enable'] = true;
			} else {
				unset($repos[$i]['enable']);
			}
			pkgrepo_store($repos, sprintf(gettext('Package repository "%s" changed.'), $repo['name']));
			return true;
		}
	}
	return false;
}

/* Move a repository up (towards a higher priority) or down. */
function pkgrepo_move($id, $direction) {
	$repos = pkgrepo_list();
	foreach ($repos as $i => $repo) {
		if (($repo['id'] ?? '') === (string)$id) {
			$j = ($direction === 'up') ? $i - 1 : $i + 1;
			if (!isset($repos[$j])) {
				return false;
			}
			list($repos[$i], $repos[$j]) = array($repos[$j], $repos[$i]);
			pkgrepo_store($repos, gettext('Package repository order changed.'));
			return true;
		}
	}
	return false;
}

function pkgrepo_set_freebsd($enable) {
	if ($enable) {
		config_set_path('packagerepos/freebsd', true);
	} else {
		config_del_path('packagerepos/freebsd');
	}
	write_config($enable ? gettext('FreeBSD package repository enabled.') : gettext('FreeBSD package repository disabled.'));
	pkgrepo_apply();
}

/*
 * Make the pkg files match the configuration: render, write into a staging
 * directory, swap it in. Safe to run any number of times (boot, save, restore).
 */
function pkgrepo_apply() {
	$files = pkgrepo_render_all(pkgrepo_list(), pkgrepo_freebsd_enabled());
	$groups = array('custom-repos' => array(), 'custom-keys' => array());
	foreach ($files as $path => $content) {
		list($group, $rest) = explode('/', $path, 2);
		$groups[$group][$rest] = $content;
	}
	foreach ($groups as $group => $members) {
		$dir = '/usr/local/etc/pkg/' . $group;
		$stage = $dir . '.new';
		$old = $dir . '.old';
		mwexec('/bin/rm -rf ' . escapeshellarg($stage) . ' ' . escapeshellarg($old));
		if (empty($members)) {
			mwexec('/bin/rm -rf ' . escapeshellarg($dir));
			continue;
		}
		foreach ($members as $rest => $content) {
			$target = $stage . '/' . $rest;
			@mkdir(dirname($target), 0755, true);
			file_put_contents($target, $content);
			@chmod($target, 0644);
		}
		if (is_dir($dir)) {
			rename($dir, $old);
		}
		rename($stage, $dir);
		mwexec('/bin/rm -rf ' . escapeshellarg($old));
	}
}

/* Run pkg with the custom repositories visible. Returns array(rc, stdout, stderr). */
function pkgrepo_pkg($params) {
	$out = '';
	$err = '';
	$rc = pkg_exec($params, $out, $err, array('REPOS_DIR' => pkgrepo_repos_dir(true)));
	return array($rc, $out, $err);
}

/* Fetch the catalogue of one repository; returns array(ok, message). */
function pkgrepo_refresh($name) {
	if (!pkgrepo_name_valid($name) && $name !== 'FreeBSD-ports') {
		return array(false, gettext('Unknown repository.'));
	}
	list($rc, $out, $err) = pkgrepo_pkg('update -f -r ' . escapeshellarg($name));
	/* pkg exits 0 even when the signature is refused; it leaves no catalogue behind and says why on stderr */
	$ok = ($rc === 0) && trim($err) === '' && file_exists('/var/db/pkg/repos/' . $name . '/db');
	return array($ok, trim($ok ? $out : (trim($err) !== '' ? $err : $out)));
}

/*
 * Every package one repository offers: array of array(name, version, comment),
 * sorted by name. Large repositories (FreeBSD's has tens of thousands) are
 * filtered and capped by the caller.
 */
function pkgrepo_catalog($name) {
	list($rc, $out) = pkgrepo_pkg('rquery -U -r ' . escapeshellarg($name) . ' ' . escapeshellarg('%n|%v|%c'));
	$packages = array();
	if ($rc === 0) {
		foreach (preg_split('/\r?\n/', trim($out)) as $line) {
			$parts = explode('|', $line, 3);
			if (count($parts) === 3 && $parts[0] !== '') {
				$packages[] = $parts;
			}
		}
	}
	usort($packages, function ($a, $b) {
		return strcmp($a[0], $b[0]);
	});
	return $packages;
}

/*
 * The repositories FreeSense ships (never editable): name, url, priority, from
 * the generated /usr/local/etc/pkg/repos/FreeSense.conf, with the documented
 * priorities as the fallback.
 */
function pkgrepo_builtin() {
	$defaults = array(
		'FreeSense' => array(gettext('FreeSense system'), PKGREPO_PRIORITY_SYSTEM),
		'FreeSense-packages' => array(gettext('FreeSense optional packages'), PKGREPO_PRIORITY_PACKAGES),
		'FreeSense-mirror' => array(gettext('FreeSense mirror'), PKGREPO_PRIORITY_MIRROR),
	);
	$conf = @file_get_contents('/usr/local/etc/pkg/repos/FreeSense.conf');
	$found = array();
	if (is_string($conf)) {
		foreach ($defaults as $name => $info) {
			if (preg_match('/^' . preg_quote($name, '/') . ':\s*\{(.*?)^\}/ms', $conf, $m)) {
				$url = preg_match('/url:\s*"([^"]+)"/', $m[1], $u) ? $u[1] : '';
				$priority = preg_match('/priority:\s*(\d+)/', $m[1], $p) ? (int)$p[1] : $info[1];
				$found[] = array('name' => $name, 'descr' => $info[0], 'url' => $url, 'priority' => $priority);
			}
		}
	}
	if (empty($found)) {
		foreach (array('FreeSense', 'FreeSense-packages') as $name) {
			$found[] = array('name' => $name, 'descr' => $defaults[$name][0], 'url' => '', 'priority' => $defaults[$name][1]);
		}
	}
	return $found;
}

/* The installed packages that came from a repository that is not FreeSense's. */
function pkgrepo_installed_foreign() {
	list($rc, $out) = pkgrepo_pkg('query ' . escapeshellarg('%n|%v|%R'));
	$foreign = pkgrepo_foreign_names();
	$packages = array();
	if ($rc === 0) {
		foreach (preg_split('/\r?\n/', trim($out)) as $line) {
			$parts = explode('|', $line, 3);
			if (count($parts) === 3 && in_array($parts[2], $foreign, true)) {
				$packages[] = $parts;
			}
		}
	}
	return $packages;
}

/* A package name as pkg writes it (any package, not only FreeSense's). */
function pkgrepo_pkg_name_ok($name) {
	return is_string($name) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]{0,127}$/D', $name) === 1;
}

/* The names the FreeSense repositories supply, asked of the system repositories only. */
function pkgrepo_system_names() {
	$names = array();
	foreach (array('FreeSense', 'FreeSense-packages') as $repo) {
		$out = '';
		$err = '';
		if (pkg_exec('rquery -U -r ' . escapeshellarg($repo) . ' ' . escapeshellarg('%n'), $out, $err,
		    array('REPOS_DIR' => pkgrepo_repos_dir(false))) === 0) {
			$names = array_merge($names, preg_split('/\r?\n/', trim($out)));
		}
	}
	return array_values(array_filter(array_unique($names)));
}

/* The repositories whose packages are not FreeSense's. */
function pkgrepo_foreign_names() {
	$names = array_column(pkgrepo_list(), 'name');
	if (pkgrepo_freebsd_enabled()) {
		$names[] = 'FreeBSD-ports';
	}
	return $names;
}

/*
 * What installing a package would do, and whether it is allowed.
 * Returns array('plan' => ..., 'reasons' => [...], 'raw' => string, 'ok' => bool).
 */
function pkgrepo_review_install($pkgname) {
	list($rc, $out, $err) = pkgrepo_pkg('install -n ' . escapeshellarg($pkgname));
	$plan = pkgrepo_parse_plan($out);
	$reasons = array();
	if ($rc !== 0) {
		$reasons[] = ($err !== '') ? trim($err) : gettext('pkg could not work out what installing this would do.');
	} elseif (empty($plan['new'])) {
		$reasons[] = gettext('Nothing would be installed (it may already be installed).');
	} else {
		$reasons = pkgrepo_guard($plan, pkgrepo_foreign_names(), pkgrepo_system_names());
	}
	return array('plan' => $plan, 'reasons' => $reasons, 'raw' => $out, 'ok' => empty($reasons));
}

/* What removing a package would do, and whether it is allowed (see pkgrepo_guard_removal()). */
function pkgrepo_review_remove($pkgname) {
	list($rc, $out, $err) = pkgrepo_pkg('delete -n ' . escapeshellarg($pkgname));
	$plan = pkgrepo_parse_plan($out);
	$origin = array();
	list($qrc, $qout) = pkgrepo_pkg('query ' . escapeshellarg('%n|%R'));
	if ($qrc === 0) {
		foreach (preg_split('/\r?\n/', trim($qout)) as $line) {
			$parts = explode('|', $line, 2);
			if (count($parts) === 2) {
				$origin[$parts[0]] = $parts[1];
			}
		}
	}
	$reasons = pkgrepo_guard_removal($pkgname, $plan, $origin, pkgrepo_foreign_names());
	if ($rc !== 0 && empty($reasons)) {
		$reasons[] = ($err !== '') ? trim($err) : gettext('pkg could not work out what removing this would do.');
	}
	return array('plan' => array('new' => array(), 'removed' => $plan['removed']), 'reasons' => $reasons, 'raw' => $out, 'ok' => empty($reasons));
}

/* The installed packages: name => array(version, repository it came from). */
function pkgrepo_installed_map() {
	list($rc, $out) = pkgrepo_pkg('query ' . escapeshellarg('%n|%v|%R'));
	$map = array();
	if ($rc === 0) {
		foreach (preg_split('/\r?\n/', trim($out)) as $line) {
			$parts = explode('|', $line, 3);
			if (count($parts) === 3) {
				$map[$parts[0]] = array($parts[1], $parts[2]);
			}
		}
	}
	return $map;
}

/* The Source filter's choices: name => label. Only enabled repositories are offered. */
function pkgrepo_source_options() {
	$options = array();
	foreach (pkgrepo_list() as $repo) {
		if (!empty($repo['enable'])) {
			$options[$repo['name']] = $repo['name'];
		}
	}
	if (pkgrepo_freebsd_enabled()) {
		$options['FreeBSD-ports'] = 'FreeBSD';
	}
	return $options;
}

/*
 * Every package one source offers, for the Available Packages list: array of
 * array(name, version, comment, state, installed_version) where state is
 * available|installed|update. The caller filters and caps it.
 */
function pkgrepo_source_rows($source) {
	$installed = pkgrepo_installed_map();
	$rows = array();
	foreach (pkgrepo_catalog($source) as $p) {
		$state = 'available';
		$installed_version = '';
		if (isset($installed[$p[0]])) {
			$installed_version = $installed[$p[0]][0];
			$state = ($installed[$p[0]][1] === $source && pkg_version_compare($installed_version, $p[1]) == '<') ? 'update' : 'installed';
		}
		$rows[] = array($p[0], $p[1], $p[2], $state, $installed_version);
	}
	return $rows;
}

/*
 * The installed packages that came from a custom or the FreeBSD repository, with
 * the version their repository offers now: array of array(name, version, repo,
 * available, state, comment) where state is current|update|missing.
 */
function pkgrepo_installed_foreign_detail() {
	$detail = array();
	foreach (pkgrepo_installed_foreign() as $p) {
		list($rc, $out) = pkgrepo_pkg('rquery -U -r ' . escapeshellarg($p[2]) . ' ' . escapeshellarg('%v|%c') . ' ' . escapeshellarg($p[0]));
		$available = '';
		$comment = '';
		if ($rc === 0 && trim($out) !== '') {
			list($available, $comment) = array_pad(explode('|', trim($out), 2), 2, '');
		}
		if ($available === '') {
			$state = 'missing';
		} else {
			$state = (pkg_version_compare($p[1], $available) == '<') ? 'update' : 'current';
		}
		$detail[] = array($p[0], $p[1], $p[2], $available, $state, $comment);
	}
	return $detail;
}

/* What updating a package from the repository it came from would do, and whether it is allowed. */
function pkgrepo_review_upgrade($pkgname) {
	$map = pkgrepo_installed_map();
	$foreign = pkgrepo_foreign_names();
	if (!isset($map[$pkgname])) {
		return array('plan' => array('new' => array(), 'changed' => array()), 'reasons' => array(sprintf(gettext('%s is not installed.'), $pkgname)), 'raw' => '', 'ok' => false);
	}
	$repo = $map[$pkgname][1];
	if (!in_array($repo, $foreign, true) || preg_match(PKGREPO_RESERVED_PATTERN, $pkgname) === 1) {
		return array('plan' => array('new' => array(), 'changed' => array()),
		    'reasons' => array(sprintf(gettext('%s is managed by FreeSense and is not updated from here.'), $pkgname)), 'raw' => '', 'ok' => false);
	}
	list($rc, $out, $err) = pkgrepo_pkg('upgrade -n -r ' . escapeshellarg($repo) . ' ' . escapeshellarg($pkgname));
	$plan = pkgrepo_parse_plan($out);
	$reasons = pkgrepo_guard($plan, $foreign, pkgrepo_system_names(), array($pkgname));
	if ($rc !== 0 && empty($reasons)) {
		$reasons[] = ($err !== '') ? trim($err) : gettext('pkg could not work out what updating this would do.');
	} elseif (empty($reasons) && empty($plan['changed'])) {
		$reasons[] = gettext('It is already up to date.');
	}
	return array('plan' => $plan, 'reasons' => $reasons, 'raw' => $out, 'ok' => empty($reasons), 'repo' => $repo);
}

/*
 * Run an install or removal in the background with the log protocol the
 * Package Installer page polls (log file, socket file, pid file, "__RC=n" line).
 * Returns the same array as pkg_mgr_install_start().
 */
function pkgrepo_start($pkgmode, $pkgname) {
	$paths = pkg_mgr_install_paths();
	$logfilename = pkg_mgr_install_logfile(false, $pkgname);
	$result = array('started' => false, 'reason' => 'failed', 'failmsg' => '', 'progbar' => false,
	    'logfilename' => $logfilename, 'pid' => null);
	if (!pkgrepo_pkg_name_ok($pkgname) || !in_array($pkgmode, array('installcustom', 'removecustom', 'upgradecustom'), true)) {
		return $result;
	}
	if ($pkgmode === 'installcustom') {
		$review = pkgrepo_review_install($pkgname);
		if (!$review['ok']) {
			$result['failmsg'] = implode("\n", $review['reasons']);
			return $result;
		}
		$action = 'install -y';
	} elseif ($pkgmode === 'upgradecustom') {
		$review = pkgrepo_review_upgrade($pkgname);
		if (!$review['ok']) {
			$result['failmsg'] = implode("
", $review['reasons']);
			return $result;
		}
		$action = 'upgrade -y -r ' . escapeshellarg($review['repo']);
	} else {
		$review = pkgrepo_review_remove($pkgname);
		if (!$review['ok']) {
			$result['failmsg'] = implode("
", $review['reasons']);
			return $result;
		}
		$action = 'delete -y';
	}
	if (pkg_mgr_install_readonly_user()) {
		$result['reason'] = 'readonly';
		$result['failmsg'] = pkg_mgr_install_readonly_message();
		return $result;
	}
	if (isvalidpid($paths['gui_pidfile'])) {
		$result['reason'] = 'another_instance';
		$result['failmsg'] = gettext("Another update or package operation is running. Try again when it has finished.");
		return $result;
	}
	write_config(gettext("Creating restore point before package installation."));
	foreach (array('.txt', '.json', '.done') as $suffix) {
		unlink_if_exists($logfilename . $suffix);
	}
	unlink_if_exists($paths['sock_file']);
	$env = '';
	foreach (pkg_env(array('REPOS_DIR' => pkgrepo_repos_dir(true))) as $key => $value) {
		$env .= ' ' . $key . '=' . escapeshellarg((string)$value);
	}
	$log = escapeshellarg($logfilename . '.txt');
	$command = '/bin/sh -c ' . escapeshellarg(
	    ': > ' . escapeshellarg($paths['sock_file']) . '; ' .
	    '/usr/bin/env' . $env . ' /usr/local/sbin/pkg-static ' . $action . ' ' . escapeshellarg($pkgname) .
	    ' >> ' . $log . ' 2>&1; rc=$?; echo "__RC=$rc" >> ' . $log . '; echo "__EXIT=$rc" >> ' . $log);
	/* Return at once: the page's status polls follow the run (and see a quick one end). */
	$execpid = mwexec_bg($command);
	@file_put_contents($paths['gui_pidfile'], $execpid);
	@file_put_contents($paths['gui_mode'], implode("\n", array($pkgmode, $pkgname)));
	return array('started' => true, 'reason' => '', 'failmsg' => '', 'progbar' => false,
	    'logfilename' => $logfilename, 'pid' => (int)$execpid);
}
