<?php
/*
 * remote_backup/util.inc
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
 * Remote backup helpers with no dependency on the rest of the system, so
 * they can be tested standalone: file naming, retention selection, URL
 * checks and the HTTP client shared by the S3 and WebDAV drivers.
 */

function remote_backup_filename($host_label, $timestamp, $config_hash) {
	return sprintf('%s-%s-%s.xml', $host_label, gmdate('Ymd-His', $timestamp),
	    substr($config_hash, 0, 8));
}

/* True when $name is a backup written by the firewall with $host_label. */
function remote_backup_is_own_file($name, $host_label) {
	return (bool)preg_match('/^' . preg_quote($host_label, '/') .
	    '-\d{8}-\d{6}-[0-9a-f]{8}\.xml$/D', $name);
}

/*
 * Given a remote listing, return the backup files that fall outside the
 * newest $keep. Only files written by this firewall are ever selected,
 * and the newest backup is always kept.
 */
function remote_backup_prune_selection(array $names, $host_label, $keep) {
	$own = array_values(array_filter($names, function ($name) use ($host_label) {
		return remote_backup_is_own_file($name, $host_label);
	}));
	/* The UTC timestamp in the name sorts lexically. */
	rsort($own, SORT_STRING);
	return array_slice($own, max(1, (int)$keep));
}

/* A sha256sum(1)-compatible manifest line. */
function remote_backup_manifest($name, $data) {
	return hash('sha256', $data) . "  {$name}\n";
}

function remote_backup_valid_http_url($url) {
	$parts = parse_url((string)$url);
	return is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), array('http', 'https'), true) &&
	    !empty($parts['host']) && !isset($parts['user']) && !isset($parts['pass']) &&
	    !isset($parts['query']) && !isset($parts['fragment']);
}

function remote_backup_valid_prefix($prefix) {
	return ($prefix === '') || ((bool)preg_match('#^[A-Za-z0-9._/-]{1,200}$#D', (string)$prefix) &&
	    strpos($prefix, '..') === false);
}

/*
 * Returns array('code' => int, 'body' => string, 'error' => string).
 * $userpwd is "user:password" for HTTP authentication, or null.
 */
function remote_backup_http($method, $url, $headers = array(), $body = null, $userpwd = null) {
	$ch = curl_init();
	curl_setopt_array($ch, array(
		CURLOPT_URL => $url,
		CURLOPT_CUSTOMREQUEST => $method,
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HTTPHEADER => $headers,
		CURLOPT_CONNECTTIMEOUT => 15,
		CURLOPT_TIMEOUT => 300,
		CURLOPT_FOLLOWLOCATION => false,
		CURLOPT_SSL_VERIFYPEER => true,
		CURLOPT_SSL_VERIFYHOST => 2,
		CURLOPT_USERAGENT => 'FreeSense remote backup',
		CURLOPT_PROTOCOLS_STR => 'http,https',
	));
	if ($body !== null) {
		curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
	}
	if ($userpwd !== null) {
		curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC | CURLAUTH_DIGEST);
		curl_setopt($ch, CURLOPT_USERPWD, $userpwd);
	}
	if (function_exists('set_curlproxy')) {
		set_curlproxy($ch);
	}
	$response = curl_exec($ch);
	return array(
		'code' => (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
		'body' => is_string($response) ? $response : '',
		'error' => ($response === false) ? curl_error($ch) : '',
	);
}

/* A short, single-line error from an HTTP result. */
function remote_backup_http_error($result) {
	if ($result['error'] !== '') {
		return $result['error'];
	}
	$detail = '';
	if (preg_match('#<Message>(.*?)</Message>#s', $result['body'], $m)) {
		$detail = ': ' . html_entity_decode(trim($m[1]));
	}
	return "HTTP {$result['code']}" . substr($detail, 0, 200);
}
