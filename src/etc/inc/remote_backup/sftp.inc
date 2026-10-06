<?php
/*
 * remote_backup/sftp.inc
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
 * SFTP storage driver using the base system OpenSSH sftp(1) in batch mode.
 * Authentication is by an Ed25519 key pair generated on the firewall; the
 * server host key must be explicitly trusted and is pinned through a
 * private known_hosts file (StrictHostKeyChecking=yes).
 */

define('REMOTE_BACKUP_SFTP_HOSTKEY_ALIAS', 'freesense-remote-backup-target');

function remote_backup_sftp_valid_path($path) {
	return !preg_match('/["\\\\\x00-\x1f\x7f]/', (string)$path) && (strlen((string)$path) <= 255);
}

/* OpenSSH-style "SHA256:..." fingerprint of "type base64 [comment]", or null. */
function remote_backup_sftp_fingerprint($keyline) {
	$parts = preg_split('/\s+/', trim((string)$keyline));
	if (count($parts) < 2 || !preg_match('/^(ssh-(ed25519|rsa|dss)|ecdsa-sha2-nistp(256|384|521)|sk-[a-z0-9@.-]+)$/D', $parts[0])) {
		return null;
	}
	$blob = base64_decode($parts[1], true);
	if ($blob === false || strlen($blob) < 20) {
		return null;
	}
	/* The blob starts with the length-prefixed key type; it must agree. */
	$len = unpack('N', substr($blob, 0, 4))[1];
	if (substr($blob, 4, $len) !== $parts[0]) {
		return null;
	}
	return 'SHA256:' . rtrim(base64_encode(hash('sha256', $blob, true)), '=');
}

function remote_backup_sftp_tempdir() {
	$old_umask = umask(0077);
	$dir = tempnam('/tmp', 'rbsftp');
	if ($dir !== false) {
		@unlink($dir);
		if (!@mkdir($dir, 0700)) {
			$dir = false;
		}
	}
	umask($old_umask);
	return $dir;
}

function remote_backup_sftp_rmdir($dir) {
	if (!is_string($dir) || $dir === '' || !is_dir($dir)) {
		return;
	}
	foreach (glob("{$dir}/{,.}*", GLOB_BRACE) ?: array() as $file) {
		if (is_file($file)) {
			@unlink($file);
		}
	}
	@rmdir($dir);
}

/* Run a command without a shell. Returns array(exit code, stdout, stderr). */
function remote_backup_sftp_exec(array $cmd, $dir, $stdin = null, $cwd = null) {
	$out = "{$dir}/.stdout";
	$err = "{$dir}/.stderr";
	$proc = proc_open($cmd, array(
		0 => ($stdin === null) ? array('file', '/dev/null', 'r') : array('pipe', 'r'),
		1 => array('file', $out, 'w'),
		2 => array('file', $err, 'w'),
	), $pipes, $cwd);
	if (!is_resource($proc)) {
		return array(127, '', 'could not start ' . $cmd[0]);
	}
	if ($stdin !== null) {
		fwrite($pipes[0], $stdin);
		fclose($pipes[0]);
	}
	$code = proc_close($proc);
	$result = array($code, (string)@file_get_contents($out), (string)@file_get_contents($err));
	@unlink($out);
	@unlink($err);
	return $result;
}

/* Generate an Ed25519 key pair. Returns array('private', 'public') or null. */
function remote_backup_sftp_generate_keypair() {
	$dir = remote_backup_sftp_tempdir();
	if ($dir === false) {
		return null;
	}
	$comment = 'freesense-backup@' . (function_exists('remote_backup_host_label') ? remote_backup_host_label() : 'firewall');
	list($code) = remote_backup_sftp_exec(array('/usr/bin/ssh-keygen', '-q', '-t', 'ed25519', '-N', '',
	    '-C', $comment, '-f', "{$dir}/id_ed25519"), $dir);
	$keypair = null;
	if ($code === 0 && is_file("{$dir}/id_ed25519") && is_file("{$dir}/id_ed25519.pub")) {
		$keypair = array(
			'private' => file_get_contents("{$dir}/id_ed25519"),
			'public' => trim(file_get_contents("{$dir}/id_ed25519.pub")),
		);
	}
	remote_backup_sftp_rmdir($dir);
	return $keypair;
}

/*
 * Fetch the server's host keys for the user to confirm.
 * Returns array of array('key' => "type base64", 'fingerprint' => ...), or an error string.
 */
function remote_backup_sftp_scan($host, $port) {
	$dir = remote_backup_sftp_tempdir();
	if ($dir === false) {
		return 'could not create a temporary directory';
	}
	list($code, $stdout, $stderr) = remote_backup_sftp_exec(array('/usr/bin/ssh-keyscan', '-T', '10',
	    '-p', (string)(int)$port, '-t', 'ed25519,ecdsa,rsa', $host), $dir);
	remote_backup_sftp_rmdir($dir);
	$keys = array();
	foreach (explode("\n", $stdout) as $line) {
		$line = trim($line);
		if ($line === '' || $line[0] === '#') {
			continue;
		}
		$parts = preg_split('/\s+/', $line);
		if (count($parts) < 3) {
			continue;
		}
		$keyline = "{$parts[1]} {$parts[2]}";
		$fingerprint = remote_backup_sftp_fingerprint($keyline);
		if ($fingerprint !== null) {
			$keys[] = array('key' => $keyline, 'fingerprint' => $fingerprint);
		}
	}
	/* Offer the strongest key type first; ssh-keyscan output order varies. */
	$rank = function ($k) {
		$type = strtok($k['key'], ' ');
		return ($type === 'ssh-ed25519') ? 0 : ((strpos($type, 'ecdsa-') === 0) ? 1 : 2);
	};
	usort($keys, function ($a, $b) use ($rank) {
		return $rank($a) <=> $rank($b);
	});
	if (empty($keys)) {
		$msg = trim(preg_replace('/^#.*$/m', '', $stderr));
		return ($msg !== '') ? $msg : 'no SSH host key was returned (check host, port and firewall rules)';
	}
	return $keys;
}

/*
 * Run sftp batch commands against the target in a private working directory.
 * $files maps local file names to contents staged in that directory; sftp
 * runs with it as the working directory, so batch commands use bare names.
 * Returns array('ok' => bool, 'stdout' => string, 'error' => string, 'dir' => string);
 * the caller must remove 'dir' with remote_backup_sftp_rmdir().
 */
function remote_backup_sftp_session($t, array $commands, array $files = array()) {
	$dir = remote_backup_sftp_tempdir();
	if ($dir === false) {
		return array('ok' => false, 'stdout' => '', 'error' => 'could not create a temporary directory', 'dir' => '');
	}
	if (empty($t['sftp_hostkey']) || empty($t['sftp_privkey'])) {
		return array('ok' => false, 'stdout' => '', 'error' => 'the host key or client key is missing', 'dir' => $dir);
	}
	file_put_contents("{$dir}/id", base64_decode($t['sftp_privkey']));
	chmod("{$dir}/id", 0600);
	file_put_contents("{$dir}/known_hosts", REMOTE_BACKUP_SFTP_HOSTKEY_ALIAS . ' ' . trim($t['sftp_hostkey']) . "\n");
	foreach ($files as $file => $data) {
		file_put_contents("{$dir}/{$file}", $data);
	}

	$batch = '';
	$path = trim((string)($t['sftp_path'] ?? ''));
	if ($path !== '') {
		$batch .= "cd \"{$path}\"\n";
	}
	$batch .= implode("\n", $commands) . "\n";
	file_put_contents("{$dir}/batch", $batch);

	$host = $t['sftp_host'];
	if (is_ipaddrv6($host)) {
		$host = "[{$host}]";
	}
	$cmd = array('/usr/bin/sftp', '-b', "{$dir}/batch", '-F', '/dev/null',
	    '-P', (string)(int)$t['sftp_port'],
	    '-i', "{$dir}/id",
	    '-o', 'IdentitiesOnly=yes',
	    '-o', 'BatchMode=yes',
	    '-o', 'PasswordAuthentication=no',
	    '-o', 'KbdInteractiveAuthentication=no',
	    '-o', 'StrictHostKeyChecking=yes',
	    '-o', "UserKnownHostsFile={$dir}/known_hosts",
	    '-o', 'GlobalKnownHostsFile=/dev/null',
	    '-o', 'HostKeyAlias=' . REMOTE_BACKUP_SFTP_HOSTKEY_ALIAS,
	    '-o', 'ConnectTimeout=15',
	    '-o', 'ServerAliveInterval=15',
	    '-o', 'ServerAliveCountMax=4',
	    '-o', 'LogLevel=ERROR',
	    '-o', "User={$t['sftp_username']}",
	    $host);
	list($code, $stdout, $stderr) = remote_backup_sftp_exec($cmd, $dir, null, $dir);
	$error = trim($stderr);
	if (stripos($error, 'Host key verification failed') !== false ||
	    stripos($error, 'REMOTE HOST IDENTIFICATION HAS CHANGED') !== false) {
		$error = 'the server host key does not match the trusted key';
	} elseif (stripos($error, 'Permission denied') !== false) {
		$error = 'the server rejected the firewall\'s SSH key; add the public key shown on the target page to ~/.ssh/authorized_keys';
	}
	return array('ok' => ($code === 0), 'stdout' => $stdout,
	    'error' => ($code === 0) ? '' : (($error !== '') ? substr($error, 0, 300) : "sftp exited with code {$code}"),
	    'dir' => $dir);
}

function remote_backup_sftp_put($t, $name, $data) {
	$session = remote_backup_sftp_session($t, array("put upload \"{$name}\""), array('upload' => $data));
	remote_backup_sftp_rmdir($session['dir']);
	return $session['ok'] ? true : $session['error'];
}

function remote_backup_sftp_get($t, $name) {
	$session = remote_backup_sftp_session($t, array("get \"{$name}\" download"));
	$data = $session['ok'] ? @file_get_contents("{$session['dir']}/download") : false;
	remote_backup_sftp_rmdir($session['dir']);
	if ($data === false) {
		return array('error' => $session['ok'] ? 'the downloaded file could not be read' : $session['error']);
	}
	return $data;
}

function remote_backup_sftp_delete($t, $name) {
	$session = remote_backup_sftp_session($t, array("rm \"{$name}\""));
	remote_backup_sftp_rmdir($session['dir']);
	return $session['ok'] ? true : $session['error'];
}

function remote_backup_sftp_list($t) {
	$session = remote_backup_sftp_session($t, array('ls -1a'));
	remote_backup_sftp_rmdir($session['dir']);
	if (!$session['ok']) {
		return $session['error'];
	}
	return remote_backup_sftp_parse_list($session['stdout']);
}

/* Names from "ls -1a" batch output; sftp echoes each command as "sftp> ...". */
function remote_backup_sftp_parse_list($stdout) {
	$names = array();
	foreach (explode("\n", $stdout) as $line) {
		$line = rtrim($line, "\r");
		if ($line === '' || strpos($line, 'sftp>') === 0) {
			continue;
		}
		$name = basename($line);
		if ($name !== '.' && $name !== '..') {
			$names[] = $name;
		}
	}
	return $names;
}
