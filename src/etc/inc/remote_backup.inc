<?php
/*
 * remote_backup.inc
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
 * Scheduled and on-change encrypted configuration backups to remote
 * storage (S3-compatible, WebDAV, SFTP).
 *
 * Every uploaded file is encrypted with the same format as an encrypted
 * download from Diagnostics > Backup & Restore (encrypt_data() +
 * tagfile_reformat()), so it restores there unchanged with the passphrase.
 * A "<name>.sha256" manifest is uploaded beside it to detect corruption;
 * it is not a defence against deliberate tampering.
 *
 * Config: <remotebackup> with enable, passphrase, frequency, hour, minute,
 * weekday, onchange, notify_success and a <backuptarget> list (a list tag
 * in xmlparse.inc; "target" and "schedule" mean other things there).
 */

require_once('globals.inc');
require_once('config.lib.inc');
require_once('util.inc');
require_once('notices.inc');
require_once('crypt.inc');
require_once('remote_backup/util.inc');
require_once('remote_backup/s3.inc');
require_once('remote_backup/webdav.inc');
require_once('remote_backup/sftp.inc');

define('REMOTE_BACKUP_CLI', '/usr/local/sbin/freesense-remote-backup');
define('REMOTE_BACKUP_STATE', '/var/db/remote_backup.json');
define('REMOTE_BACKUP_PENDING', '/var/run/remote_backup.pending');
define('REMOTE_BACKUP_NOTICE_ID', 'remote_backup');
define('REMOTE_BACKUP_DEFAULT_RETENTION', 30);
define('REMOTE_BACKUP_MAX_RETENTION', 1000);
define('REMOTE_BACKUP_MIN_PASSPHRASE', 12);

/* Fields that hold secrets: never echoed back to the browser or logged. */
global $remote_backup_secret_fields;
$remote_backup_secret_fields = array('s3_secret_key', 'dav_password');

function remote_backup_types() {
	return array(
		's3' => gettext('S3-compatible (AWS S3, Cloudflare R2, MinIO, Backblaze B2)'),
		'webdav' => gettext('WebDAV (Nextcloud, ownCloud, NAS)'),
		'sftp' => gettext('SFTP (SSH key)'),
	);
}

/*
 * Checkbox flags are stored as empty elements, which read back as "".
 * Turn them into real booleans so callers can test them with empty().
 */
function remote_backup_flags($item, array $flags) {
	foreach ($flags as $flag) {
		$item[$flag] = isset($item[$flag]) && ($item[$flag] !== false);
	}
	return $item;
}

function remote_backup_settings() {
	$settings = config_get_path('remotebackup', []);
	$settings = is_array($settings) ? $settings : [];
	unset($settings['backuptarget']);
	return remote_backup_flags($settings, array('enable', 'onchange', 'notify_success'));
}

function remote_backup_normalize_target($target) {
	return remote_backup_flags($target, array('enable', 'include_rrd', 'include_sshkeys',
	    'include_extra', 's3_path_style'));
}

function remote_backup_targets() {
	$targets = config_get_path('remotebackup/backuptarget', []);
	$targets = is_array($targets) ? array_values(array_filter($targets, 'is_array')) : [];
	return array_map('remote_backup_normalize_target', $targets);
}

/* Return array($index, $target) for a target id, or array(null, null). */
function remote_backup_find_target($id) {
	foreach (config_get_path('remotebackup/backuptarget', []) as $idx => $target) {
		if (is_array($target) && isset($target['id']) && hash_equals((string)$target['id'], (string)$id)) {
			return array($idx, remote_backup_normalize_target($target));
		}
	}
	return array(null, null);
}

function remote_backup_new_id() {
	return bin2hex(random_bytes(6));
}

/* ------------------------------------------------------------------ */
/* Naming and retention                                                */
/* ------------------------------------------------------------------ */

/* The hostname part of every file name, restricted to safe characters. */
function remote_backup_host_label() {
	$host = config_get_path('system/hostname', 'freesense');
	$domain = config_get_path('system/domain', '');
	$label = ($domain !== '') ? "{$host}.{$domain}" : $host;
	$label = strtolower(preg_replace('/[^A-Za-z0-9.-]/', '-', $label));
	return ($label === '') ? 'freesense' : $label;
}

/* ------------------------------------------------------------------ */
/* Validation and saving                                               */
/* ------------------------------------------------------------------ */

function remote_backup_validate_settings($post) {
	$input_errors = array();
	$current = remote_backup_settings();

	if (!empty($post['enable'])) {
		$have_passphrase = !empty($current['passphrase']) ||
		    (isset($post['passphrase']) && $post['passphrase'] !== '' && $post['passphrase'] !== DMYPWD);
		if (!$have_passphrase) {
			$input_errors[] = gettext('An encryption passphrase is required to enable remote backups.');
		}
	}
	if (isset($post['passphrase']) && $post['passphrase'] !== DMYPWD && $post['passphrase'] !== '') {
		if ($post['passphrase'] !== ($post['passphrase_confirm'] ?? '')) {
			$input_errors[] = gettext('The passphrase and its confirmation do not match.');
		} elseif (strlen($post['passphrase']) < REMOTE_BACKUP_MIN_PASSPHRASE) {
			$input_errors[] = sprintf(gettext('The passphrase must be at least %d characters long.'),
			    REMOTE_BACKUP_MIN_PASSPHRASE);
		}
	}
	if (!in_array($post['frequency'] ?? '', array('none', 'hourly', 'daily', 'weekly'), true)) {
		$input_errors[] = gettext('The schedule is invalid.');
	}
	if (!is_numericint($post['minute'] ?? '') || $post['minute'] > 59) {
		$input_errors[] = gettext('The minute must be between 0 and 59.');
	}
	if (!is_numericint($post['hour'] ?? '') || $post['hour'] > 23) {
		$input_errors[] = gettext('The hour must be between 0 and 23.');
	}
	if (!is_numericint($post['weekday'] ?? '') || $post['weekday'] > 6) {
		$input_errors[] = gettext('The weekday is invalid.');
	}
	return $input_errors;
}

function remote_backup_save_settings($post) {
	$input_errors = remote_backup_validate_settings($post);
	if (!empty($input_errors)) {
		return array('input_errors' => $input_errors);
	}

	/* Start from the raw section so the target list is kept. */
	$settings = config_get_path('remotebackup', []);
	$settings = is_array($settings) ? $settings : [];
	$settings['enable'] = !empty($post['enable']);
	if (isset($post['passphrase']) && $post['passphrase'] !== DMYPWD && $post['passphrase'] !== '') {
		$settings['passphrase'] = $post['passphrase'];
	}
	$settings['frequency'] = $post['frequency'];
	$settings['minute'] = (string)(int)$post['minute'];
	$settings['hour'] = (string)(int)$post['hour'];
	$settings['weekday'] = (string)(int)$post['weekday'];
	$settings['onchange'] = !empty($post['onchange']);
	$settings['notify_success'] = !empty($post['notify_success']);
	foreach (array('enable', 'onchange', 'notify_success') as $flag) {
		if (!$settings[$flag]) {
			unset($settings[$flag]);
		}
	}
	config_set_path('remotebackup', $settings);
	remote_backup_configure_cron();
	write_config(gettext('Remote backup settings saved.') . '-NoReMoTeBaCkUp');
	return array('input_errors' => array());
}

/* Install or remove the cron jobs to match the settings. Does not write config. */
function remote_backup_configure_cron() {
	require_once('services.inc');
	$s = remote_backup_settings();
	$enabled = !empty($s['enable']);
	$schedule = $s['frequency'] ?? 'none';
	$minute = (string)($s['minute'] ?? '17');
	$hour = (string)($s['hour'] ?? '2');
	$weekday = (string)($s['weekday'] ?? '0');

	$cron_active = $enabled && ($schedule !== 'none');
	install_cron_job(REMOTE_BACKUP_CLI . ' run --reason cron', $cron_active, $minute,
	    ($schedule === 'hourly') ? '*' : $hour, '*', '*',
	    ($schedule === 'weekly') ? $weekday : '*', 'root', false);
	install_cron_job(REMOTE_BACKUP_CLI . ' run --reason change', $enabled && !empty($s['onchange']),
	    '*/5', '*', '*', '*', '*', 'root', false);
}

function remote_backup_validate_target($post, $existing = null) {
	$input_errors = array();
	$types = remote_backup_types();
	$type = $post['type'] ?? '';
	$secret = function ($field) use ($post, $existing) {
		$value = $post[$field] ?? '';
		if ($value === DMYPWD) {
			return $existing[$field] ?? '';
		}
		return $value;
	};

	if (!array_key_exists($type, $types)) {
		$input_errors[] = gettext('The storage type is invalid.');
		return $input_errors;
	}
	if (($post['retention'] ?? '') !== '' &&
	    (!is_numericint($post['retention']) || $post['retention'] < 1 ||
	    $post['retention'] > REMOTE_BACKUP_MAX_RETENTION)) {
		$input_errors[] = sprintf(gettext('Retention must be between 1 and %d backups.'),
		    REMOTE_BACKUP_MAX_RETENTION);
	}

	switch ($type) {
	case 's3':
		$endpoint = trim($post['s3_endpoint'] ?? '');
		if (!remote_backup_valid_http_url($endpoint)) {
			$input_errors[] = gettext('The S3 endpoint must be an http:// or https:// URL.');
		}
		if (!preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D', $post['s3_bucket'] ?? '')) {
			$input_errors[] = gettext('The bucket name is invalid.');
		}
		if (!preg_match('/^[A-Za-z0-9-]{1,32}$/D', $post['s3_region'] ?? '')) {
			$input_errors[] = gettext('The region is invalid (use "auto" for Cloudflare R2).');
		}
		if (!remote_backup_valid_prefix($post['s3_prefix'] ?? '')) {
			$input_errors[] = gettext('The prefix may contain letters, digits, ".", "_", "-" and "/" only.');
		}
		if (!preg_match('/^[\x21-\x7e]{1,128}$/D', $post['s3_access_key'] ?? '')) {
			$input_errors[] = gettext('The access key ID is required.');
		}
		if (!preg_match('/^[\x21-\x7e]{1,256}$/D', $secret('s3_secret_key'))) {
			$input_errors[] = gettext('The secret access key is required.');
		}
		break;
	case 'webdav':
		if (!remote_backup_valid_http_url(trim($post['dav_url'] ?? ''))) {
			$input_errors[] = gettext('The WebDAV URL must be an http:// or https:// URL.');
		}
		if (($post['dav_username'] ?? '') !== '' &&
		    preg_match('/[\x00-\x1f:]/', $post['dav_username'])) {
			$input_errors[] = gettext('The WebDAV username contains invalid characters.');
		}
		break;
	case 'sftp':
		if (!is_hostname($post['sftp_host'] ?? '') && !is_ipaddr($post['sftp_host'] ?? '')) {
			$input_errors[] = gettext('The SFTP host must be a hostname or IP address.');
		}
		if (!is_port($post['sftp_port'] ?? '', false)) {
			$input_errors[] = gettext('The SFTP port is invalid.');
		}
		if (!preg_match('/^[A-Za-z0-9._][A-Za-z0-9._-]{0,63}$/D', $post['sftp_username'] ?? '')) {
			$input_errors[] = gettext('The SFTP username is invalid.');
		}
		if (!remote_backup_sftp_valid_path($post['sftp_path'] ?? '')) {
			$input_errors[] = gettext('The remote directory may not contain quotes, backslashes or control characters.');
		}
		$hostkey = trim($post['sftp_hostkey'] ?? '');
		if ($hostkey !== '' && remote_backup_sftp_fingerprint($hostkey) === null) {
			$input_errors[] = gettext('The trusted host key is not a valid SSH public key.');
		}
		break;
	}
	return $input_errors;
}

/*
 * Save a target from posted fields. $id is a target id, or null to add.
 * Secret fields posted as DMYPWD keep their stored value.
 */
function remote_backup_save_target($post, $id = null) {
	global $remote_backup_secret_fields;

	list($idx, $existing) = ($id !== null) ? remote_backup_find_target($id) : array(null, null);
	if (($id !== null) && ($idx === null)) {
		return array('input_errors' => array(gettext('The backup target no longer exists.')));
	}
	$input_errors = remote_backup_validate_target($post, $existing);
	if (!empty($input_errors)) {
		return array('input_errors' => $input_errors);
	}

	$type = $post['type'];
	$target = array(
		'id' => $existing['id'] ?? remote_backup_new_id(),
		'type' => $type,
		'descr' => trim((string)($post['descr'] ?? '')),
		'retention' => (string)(($post['retention'] ?? '') === '' ?
		    REMOTE_BACKUP_DEFAULT_RETENTION : (int)$post['retention']),
	);
	foreach (array('enable', 'include_rrd', 'include_sshkeys', 'include_extra') as $flag) {
		if (!empty($post[$flag])) {
			$target[$flag] = true;
		}
	}

	$fields = array(
		's3' => array('s3_endpoint', 's3_region', 's3_bucket', 's3_prefix', 's3_access_key', 's3_secret_key', 's3_path_style'),
		'webdav' => array('dav_url', 'dav_username', 'dav_password'),
		'sftp' => array('sftp_host', 'sftp_port', 'sftp_username', 'sftp_path', 'sftp_hostkey'),
	);
	foreach ($fields[$type] as $field) {
		$value = $post[$field] ?? '';
		if (in_array($field, $remote_backup_secret_fields, true) && ($value === DMYPWD)) {
			$value = $existing[$field] ?? '';
		}
		if ($field === 's3_path_style') {
			if (!empty($value)) {
				$target[$field] = true;
			}
			continue;
		}
		$target[$field] = is_string($value) ? trim($value) : '';
	}
	if ($type === 'sftp') {
		/* The key pair is generated on the firewall; keep it across edits. */
		foreach (array('sftp_privkey', 'sftp_pubkey') as $field) {
			if (!empty($existing[$field])) {
				$target[$field] = $existing[$field];
			}
		}
		if (empty($target['sftp_privkey'])) {
			$keypair = remote_backup_sftp_generate_keypair();
			if ($keypair === null) {
				return array('input_errors' => array(gettext('Could not generate an SSH key pair.')));
			}
			$target['sftp_privkey'] = base64_encode($keypair['private']);
			$target['sftp_pubkey'] = $keypair['public'];
		}
	}

	if ($idx === null) {
		config_set_path('remotebackup/backuptarget/', $target);
	} else {
		config_set_path("remotebackup/backuptarget/{$idx}", $target);
	}
	write_config(sprintf(gettext('Remote backup target "%s" saved.'),
	    $target['descr'] ?: $target['id']) . '-NoReMoTeBaCkUp');
	return array('input_errors' => array(), 'id' => $target['id']);
}

function remote_backup_delete_target($id) {
	list($idx, $target) = remote_backup_find_target($id);
	if ($idx === null) {
		return false;
	}
	config_del_path("remotebackup/backuptarget/{$idx}");
	$targets = config_get_path('remotebackup/backuptarget', []);
	config_set_path('remotebackup/backuptarget', array_values($targets));
	write_config(sprintf(gettext('Remote backup target "%s" deleted.'),
	    $target['descr'] ?: $target['id']) . '-NoReMoTeBaCkUp');
	$state = remote_backup_state_read();
	unset($state[$id]);
	remote_backup_state_write($state);
	return true;
}

function remote_backup_toggle_target($id) {
	list($idx, $target) = remote_backup_find_target($id);
	if ($idx === null) {
		return false;
	}
	if (!empty($target['enable'])) {
		config_del_path("remotebackup/backuptarget/{$idx}/enable");
	} else {
		config_set_path("remotebackup/backuptarget/{$idx}/enable", true);
	}
	write_config(sprintf(gettext('Remote backup target "%s" %s.'), $target['descr'] ?: $target['id'],
	    empty($target['enable']) ? gettext('enabled') : gettext('disabled')) . '-NoReMoTeBaCkUp');
	return true;
}

/* A copy of the target that is safe to show in the GUI. */
function remote_backup_target_for_display($target) {
	global $remote_backup_secret_fields;
	foreach ($remote_backup_secret_fields as $field) {
		if (!empty($target[$field])) {
			$target[$field] = DMYPWD;
		}
	}
	unset($target['sftp_privkey']);
	return $target;
}

/* ------------------------------------------------------------------ */
/* Driver dispatch                                                     */
/* ------------------------------------------------------------------ */

/*
 * Each driver implements, for a target array $t:
 *   put($t, $name, $data)  -> true or an error string
 *   list($t)               -> array of file names, or an error string
 *   get($t, $name)         -> file contents, or array('error' => string)
 *   delete($t, $name)      -> true or an error string
 */
function remote_backup_driver($t, $op, ...$args) {
	$type = $t['type'] ?? '';
	if (!in_array($type, array('s3', 'webdav', 'sftp'), true)) {
		return ($op === 'get') ? array('error' => 'unknown storage type') : 'unknown storage type';
	}
	return call_user_func_array("remote_backup_{$type}_{$op}", array_merge(array($t), $args));
}

/*
 * Write, list and delete a small probe file to prove the credentials work.
 * Returns array('ok' => bool, 'message' => string).
 */
function remote_backup_test_target($t) {
	if (($t['type'] ?? '') === 'sftp' && empty($t['sftp_hostkey'])) {
		return array('ok' => false, 'message' => gettext('Trust the server host key first.'));
	}
	$probe = '.freesense-probe-' . bin2hex(random_bytes(4));
	$result = remote_backup_driver($t, 'put', $probe, "FreeSense remote backup connectivity test\n");
	if ($result !== true) {
		return array('ok' => false, 'message' => sprintf(gettext('Upload failed: %s'), $result));
	}
	$names = remote_backup_driver($t, 'list');
	$listed = is_array($names) && in_array($probe, $names, true);
	$deleted = remote_backup_driver($t, 'delete', $probe);
	if (!is_array($names)) {
		return array('ok' => false, 'message' => sprintf(gettext('Upload worked but listing failed: %s'), $names));
	}
	if (!$listed) {
		return array('ok' => false, 'message' => gettext('Upload worked but the file did not appear in the listing. Check the path or prefix.'));
	}
	if ($deleted !== true) {
		return array('ok' => false, 'message' => sprintf(gettext('Upload and listing worked but delete failed (retention will not work): %s'), $deleted));
	}
	return array('ok' => true, 'message' => gettext('Upload, listing and delete all succeeded.'));
}

/* Remote backups of this firewall, newest first. */
function remote_backup_list($t) {
	$names = remote_backup_driver($t, 'list');
	if (!is_array($names)) {
		return $names;
	}
	$host_label = remote_backup_host_label();
	$own = array_values(array_filter($names, function ($name) use ($host_label) {
		return remote_backup_is_own_file($name, $host_label);
	}));
	rsort($own, SORT_STRING);
	return $own;
}

/*
 * Download a backup and verify it against its manifest when one exists.
 * Returns array('data' => string, 'verified' => bool) or array('error' => string).
 */
function remote_backup_fetch($t, $name) {
	if (!remote_backup_is_own_file($name, remote_backup_host_label())) {
		return array('error' => gettext('That file is not a backup of this firewall.'));
	}
	$data = remote_backup_driver($t, 'get', $name);
	if (is_array($data)) {
		return $data;
	}
	$verified = false;
	$manifest = remote_backup_driver($t, 'get', "{$name}.sha256");
	if (is_string($manifest)) {
		$expected = strtolower(strtok($manifest, " \t\n"));
		if (!hash_equals($expected, hash('sha256', $data))) {
			return array('error' => gettext('The downloaded file does not match its checksum manifest.'));
		}
		$verified = true;
	}
	return array('data' => $data, 'verified' => $verified);
}

function remote_backup_prune($t) {
	$names = remote_backup_driver($t, 'list');
	if (!is_array($names)) {
		return $names;
	}
	$keep = (int)($t['retention'] ?? REMOTE_BACKUP_DEFAULT_RETENTION);
	$errors = array();
	foreach (remote_backup_prune_selection($names, remote_backup_host_label(), $keep) as $old) {
		foreach (array($old, "{$old}.sha256") as $file) {
			if (($file !== $old) && !in_array($file, $names, true)) {
				continue;
			}
			$result = remote_backup_driver($t, 'delete', $file);
			if ($result !== true) {
				$errors[] = "{$file}: {$result}";
			}
		}
	}
	return empty($errors) ? true : implode('; ', $errors);
}

/* ------------------------------------------------------------------ */
/* Building and running backups                                        */
/* ------------------------------------------------------------------ */

/*
 * Build the encrypted payload with the same code as an encrypted download
 * from Diagnostics > Backup & Restore. backup.inc must already be loaded
 * at file scope (rrd_data_xml() relies on its globals).
 */
function remote_backup_build($t, $passphrase) {
	if (!function_exists('execPost')) {
		return array('error' => 'backup.inc is not loaded');
	}
	$post = array(
		'download' => 'download',
		'encrypt' => 'yes',
		'encrypt_password' => $passphrase,
		'encrypt_password_confirm' => $passphrase,
		'backuparea' => '',
	);
	if (empty($t['include_rrd'])) {
		$post['donotbackuprrd'] = 'yes';
	}
	if (!empty($t['include_sshkeys'])) {
		$post['backupssh'] = 'yes';
	}
	if (!empty($t['include_extra'])) {
		$post['backupdata'] = 'yes';
	}
	$result = execPost($post, array(), false);
	if (!is_string($result)) {
		$errors = is_array($result) ? ($result['input_errors'] ?? array()) : array();
		return array('error' => $errors ? implode(' ', $errors) : 'backup could not be built');
	}
	$decoded = json_decode($result, true);
	$data = base64_decode($decoded['contents'] ?? '', true);
	if (!is_string($data) || (strpos($data, '---- BEGIN config.xml ----') !== 0)) {
		return array('error' => gettext('Encrypting the backup failed.'));
	}
	return array('data' => $data);
}

function remote_backup_config_hash() {
	$hash = @hash_file('sha256', g_get('conf_path') . '/config.xml');
	return ($hash === false) ? str_repeat('0', 64) : $hash;
}

function remote_backup_state_read() {
	$state = @json_decode((string)@file_get_contents(REMOTE_BACKUP_STATE), true);
	return is_array($state) ? $state : array();
}

function remote_backup_state_write($state) {
	$tmp = REMOTE_BACKUP_STATE . '.tmp';
	if (@file_put_contents($tmp, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX) !== false) {
		@chmod($tmp, 0600);
		@rename($tmp, REMOTE_BACKUP_STATE);
	}
}

/*
 * Run backups for every enabled target, or for target $only_id.
 * $reason is cron, change or manual. Unless $force, a target whose last
 * successful upload has the current config hash is skipped.
 * Returns array(target id => array('status' => ok|skipped|failed, 'message' => ...)).
 */
function remote_backup_run($only_id = null, $reason = 'manual', $force = false) {
	$settings = remote_backup_settings();
	if (empty($settings['enable']) && ($reason !== 'manual')) {
		return array();
	}
	if (empty($settings['passphrase'])) {
		return array('*' => array('status' => 'failed', 'message' => gettext('No encryption passphrase is configured.')));
	}

	$lock = try_lock('remote_backup', 120);
	if (!$lock) {
		return array('*' => array('status' => 'failed', 'message' => gettext('Another remote backup run is in progress.')));
	}
	if ($reason === 'change') {
		/* Cleared before uploading so a save during the run queues another. */
		@unlink(REMOTE_BACKUP_PENDING);
	}

	$host_label = remote_backup_host_label();
	$hash = remote_backup_config_hash();
	$state = remote_backup_state_read();
	$results = array();
	$payloads = array();

	foreach (remote_backup_targets() as $t) {
		$id = $t['id'] ?? '';
		if ($id === '' || (($only_id !== null) && ($id !== $only_id))) {
			continue;
		}
		if (($only_id === null) && empty($t['enable'])) {
			continue;
		}
		$label = $t['descr'] ?: $id;
		$entry = $state[$id] ?? array();
		$entry['last_attempt'] = time();
		$entry['last_reason'] = $reason;

		if (!$force && (($entry['last_success_hash'] ?? '') === $hash)) {
			$results[$id] = array('status' => 'skipped', 'message' => gettext('Configuration unchanged since the last upload.'));
			$state[$id] = $entry;
			continue;
		}

		$variant = (empty($t['include_rrd']) ? '' : 'r') . (empty($t['include_sshkeys']) ? '' : 's') .
		    (empty($t['include_extra']) ? '' : 'x');
		if (!isset($payloads[$variant])) {
			$payloads[$variant] = remote_backup_build($t, $settings['passphrase']);
		}
		$payload = $payloads[$variant];

		$message = null;
		$name = remote_backup_filename($host_label, time(), $hash);
		if (isset($payload['error'])) {
			$message = $payload['error'];
		} else {
			$put = remote_backup_driver($t, 'put', $name, $payload['data']);
			if ($put !== true) {
				$message = sprintf(gettext('upload failed: %s'), $put);
			} else {
				$put = remote_backup_driver($t, 'put', "{$name}.sha256",
				    remote_backup_manifest($name, $payload['data']));
				if ($put !== true) {
					$message = sprintf(gettext('manifest upload failed: %s'), $put);
				}
			}
		}

		if ($message === null) {
			$pruned = remote_backup_prune($t);
			$entry['last_success'] = time();
			$entry['last_success_hash'] = $hash;
			$entry['last_file'] = $name;
			$entry['last_size'] = strlen($payload['data']);
			$entry['last_error'] = ($pruned === true) ? '' : sprintf(gettext('retention cleanup failed: %s'), $pruned);
			$results[$id] = array('status' => 'ok', 'message' => sprintf(gettext('Uploaded %s.'), $name) .
			    (($pruned === true) ? '' : ' ' . $entry['last_error']));
			logger(LOG_NOTICE, localize_text('Remote backup to "%s" uploaded %s', $label, $name));
		} else {
			$entry['last_error'] = $message;
			$results[$id] = array('status' => 'failed', 'message' => $message);
			logger(LOG_ERR, localize_text('Remote backup to "%s" failed: %s', $label, $message));
		}
		$state[$id] = $entry;
	}
	remote_backup_state_write($state);
	unlock($lock);

	remote_backup_notify($results, $settings);
	return $results;
}

function remote_backup_notify($results, $settings) {
	$failed = array();
	$uploaded = 0;
	foreach ($results as $id => $result) {
		if ($result['status'] === 'failed') {
			list(, $t) = remote_backup_find_target($id);
			$failed[] = (($t['descr'] ?? '') ?: $id) . ': ' . $result['message'];
		} elseif ($result['status'] === 'ok') {
			$uploaded++;
		}
	}

	if (!empty($failed)) {
		file_notice(REMOTE_BACKUP_NOTICE_ID,
		    sprintf(gettext('Remote configuration backup failed: %s'), implode('; ', $failed)),
		    gettext('Remote Backup'), '/diag_backup_remote.php', 2);
		return;
	}
	/* Clear earlier failure notices once every target succeeds again. */
	foreach ((get_notices() ?: array()) as $key => $notice) {
		if (($notice['id'] ?? '') === REMOTE_BACKUP_NOTICE_ID) {
			close_notice($key);
		}
	}
	if (($uploaded > 0) && !empty($settings['notify_success'])) {
		notify_all_remote(sprintf(gettext('Remote configuration backup of %1$s uploaded to %2$d target(s).'),
		    remote_backup_host_label(), $uploaded));
	}
}
