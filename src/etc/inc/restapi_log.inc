<?php
/*
 * restapi_log.inc
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
 * The REST API request log: one JSON object per line in RESTAPI_REQLOG_FILE.
 * It records who called what and how it ended (time, client address, method,
 * path without the query string, status, duration, key ID, user, listener,
 * error code, user agent), never a request or response body, a query value
 * or a key secret. Failed authentications also go to the auth log for login
 * protection; this log is for the Status > REST API page.
 *
 * Cleanup: entries older than the retention period are removed daily (cron,
 * RESTAPI_REQLOG_CLI) and whenever the status page is opened; when the file
 * grows past the size limit, the oldest entries are dropped right away.
 */

define('RESTAPI_REQLOG_FILE', '/var/log/restapi.log');
define('RESTAPI_REQLOG_LOCK', '/var/run/restapi_log.lock');
define('RESTAPI_REQLOG_CLI', '/usr/local/sbin/freesense-restapi-log');
define('RESTAPI_REQLOG_LEVELS', array('off', 'failures', 'changes', 'all'));
define('RESTAPI_REQLOG_DEFAULT_LEVEL', 'failures');
define('RESTAPI_REQLOG_DEFAULT_DAYS', 30);
define('RESTAPI_REQLOG_DEFAULT_MB', 10);

/* The logging settings (restapi/log_*), with defaults. */
function restapi_reqlog_settings($section = null) {
	if ($section === null) {
		$section = config_get_path('restapi', []);
	}
	$section = is_array($section) ? $section : array();
	$level = (string)($section['log_level'] ?? RESTAPI_REQLOG_DEFAULT_LEVEL);
	$days = (int)($section['log_days'] ?? RESTAPI_REQLOG_DEFAULT_DAYS);
	$mb = (int)($section['log_mb'] ?? RESTAPI_REQLOG_DEFAULT_MB);
	return array(
		'level' => in_array($level, RESTAPI_REQLOG_LEVELS, true) ? $level : RESTAPI_REQLOG_DEFAULT_LEVEL,
		'days' => (($days >= 1) && ($days <= 365)) ? $days : RESTAPI_REQLOG_DEFAULT_DAYS,
		'mb' => (($mb >= 1) && ($mb <= 500)) ? $mb : RESTAPI_REQLOG_DEFAULT_MB,
	);
}

/*
 * Whether a request is logged at $level: failures are 4xx/5xx answers,
 * changes add every write (a matched write route, or for an unmatched
 * request any method that is not a read).
 */
function restapi_reqlog_wanted($level, $status, $write) {
	switch ($level) {
	case 'all':
		return true;
	case 'changes':
		return $write || ($status >= 400);
	case 'failures':
		return $status >= 400;
	}
	return false;
}

/* One log line (no trailing newline) for an entry; long or odd text is cut and cleaned. */
function restapi_reqlog_line(array $e) {
	$text = function ($v, $max) {
		$v = preg_replace('/[\x00-\x1f\x7f]/', '', (string)$v);
		return (strlen($v) > $max) ? substr($v, 0, $max) : $v;
	};
	return json_encode(array(
		't' => round((float)($e['t'] ?? microtime(true)), 3),
		'ip' => $text($e['ip'] ?? '', 45),
		'm' => $text($e['m'] ?? '', 10),
		'p' => $text($e['p'] ?? '', 300),
		's' => (int)($e['s'] ?? 0),
		'ms' => (int)($e['ms'] ?? 0),
		'k' => $text($e['k'] ?? '', 12),
		'u' => $text($e['u'] ?? '', 64),
		'l' => $text($e['l'] ?? '', 32),
		'w' => !empty($e['w']),
		'c' => $text($e['c'] ?? '', 64),
		'ua' => $text($e['ua'] ?? '', 160),
	), JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
}

/* Append an entry if the level wants it; trims the file when it passes the size limit. */
function restapi_reqlog_request(array $e, $settings = null, $file = RESTAPI_REQLOG_FILE) {
	$settings = is_array($settings) ? $settings : restapi_reqlog_settings();
	if (!restapi_reqlog_wanted($settings['level'], (int)($e['s'] ?? 0), !empty($e['w']))) {
		return false;
	}
	if (@file_put_contents($file, restapi_reqlog_line($e) . "\n", FILE_APPEND | LOCK_EX) === false) {
		return false;
	}
	@chmod($file, 0600);
	clearstatcache(true, $file);
	if (@filesize($file) > $settings['mb'] * 1048576) {
		restapi_reqlog_prune($settings, $file);
	}
	return true;
}

/*
 * Drop entries older than the retention period and, when the file is still
 * larger than the limit, the oldest entries until it is at 80% of it.
 * Returns the number of entries removed.
 */
function restapi_reqlog_prune($settings = null, $file = RESTAPI_REQLOG_FILE, $now = null) {
	$settings = is_array($settings) ? $settings : restapi_reqlog_settings();
	$now = ($now === null) ? time() : $now;
	if (!is_file($file)) {
		return 0;
	}
	$lock = @fopen((dirname($file) === dirname(RESTAPI_REQLOG_FILE)) ? RESTAPI_REQLOG_LOCK : "{$file}.lock", 'c');
	if ($lock) {
		flock($lock, LOCK_EX);
	}
	$lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	$lines = is_array($lines) ? $lines : array();
	$cutoff = $now - ($settings['days'] * 86400);
	$keep = array();
	foreach ($lines as $line) {
		$e = json_decode($line, true);
		if (is_array($e) && ((float)($e['t'] ?? 0) >= $cutoff)) {
			$keep[] = $line;
		}
	}
	$limit = (int)($settings['mb'] * 1048576 * 0.8);
	$size = array_sum(array_map(function ($l) { return strlen($l) + 1; }, $keep));
	while (($size > $limit) && !empty($keep)) {
		$size -= strlen(array_shift($keep)) + 1;
	}
	$removed = count($lines) - count($keep);
	if ($removed > 0) {
		$tmp = "{$file}.tmp";
		@file_put_contents($tmp, empty($keep) ? '' : implode("\n", $keep) . "\n", LOCK_EX);
		@chmod($tmp, 0600);
		@rename($tmp, $file);
	}
	if ($lock) {
		flock($lock, LOCK_UN);
		fclose($lock);
	}
	return $removed;
}

/* Remove every entry. */
function restapi_reqlog_clear($file = RESTAPI_REQLOG_FILE) {
	return is_file($file) ? (@file_put_contents($file, '', LOCK_EX) !== false) : true;
}

/* Every entry, oldest first (unreadable lines are skipped). */
function restapi_reqlog_read($file = RESTAPI_REQLOG_FILE) {
	$out = array();
	$lines = is_file($file) ? @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : array();
	foreach ((array)$lines as $line) {
		$e = json_decode($line, true);
		if (is_array($e) && isset($e['t'], $e['s'])) {
			$out[] = $e;
		}
	}
	return $out;
}

/*
 * The entries matching $f, newest first:
 *   since  unix time; q  words that must all appear (path, key, user, address, code, method)
 *   status ''|ok (<400)|failed (>=400)|2xx|4xx|5xx|401|403|429
 *   method ''|GET|POST|...; listener ''|'gui'|<listener id>; kind ''|read|write
 */
function restapi_reqlog_filter(array $entries, array $f) {
	$since = (float)($f['since'] ?? 0);
	$words = array_values(array_filter(preg_split('/\s+/', strtolower((string)($f['q'] ?? ''))), 'strlen'));
	$status = (string)($f['status'] ?? '');
	$method = strtoupper((string)($f['method'] ?? ''));
	$listener = (string)($f['listener'] ?? '');
	$kind = (string)($f['kind'] ?? '');
	$out = array();
	foreach ($entries as $e) {
		$s = (int)$e['s'];
		if (((float)$e['t'] < $since) || (($method !== '') && (($e['m'] ?? '') !== $method)) ||
		    (($listener !== '') && ((($e['l'] ?? '') === '' ? 'gui' : $e['l']) !== $listener)) ||
		    (($kind === 'write') && empty($e['w'])) || (($kind === 'read') && !empty($e['w']))) {
			continue;
		}
		if ((($status === 'ok') && ($s >= 400)) || (($status === 'failed') && ($s < 400)) ||
		    (preg_match('/^([1-5])xx$/', $status, $m) && ((int)floor($s / 100) !== (int)$m[1])) ||
		    (ctype_digit($status) && ($s !== (int)$status))) {
			continue;
		}
		if (!empty($words)) {
			$hay = strtolower(implode(' ', array($e['m'] ?? '', $e['p'] ?? '', $e['k'] ?? '', $e['u'] ?? '', $e['ip'] ?? '',
			    $e['c'] ?? '', $s, $e['ua'] ?? '')));
			foreach ($words as $w) {
				if (strpos($hay, $w) === false) {
					continue 2;
				}
			}
		}
		$out[] = $e;
	}
	return array_reverse($out);
}

/*
 * Summary of the entries since $since (default the last 24 hours, in hourly
 * buckets ending at $now): totals, failures, clients, average duration, the
 * busiest keys, client addresses and failing addresses.
 */
function restapi_reqlog_stats(array $entries, $now = null, $hours = 24) {
	$now = ($now === null) ? time() : $now;
	$start = (int)(floor($now / 3600) - $hours + 1) * 3600;
	$buckets = array();
	for ($i = 0; $i < $hours; $i++) {
		$buckets[] = array('t' => $start + ($i * 3600), 'ok' => 0, 'failed' => 0);
	}
	$total = $failed = $ms = 0;
	$ips = $keys = $failing = array();
	foreach ($entries as $e) {
		$t = (float)$e['t'];
		if ($t < $start) {
			continue;
		}
		$bad = ((int)$e['s'] >= 400);
		$i = (int)floor(($t - $start) / 3600);
		if (isset($buckets[$i])) {
			$buckets[$i][$bad ? 'failed' : 'ok']++;
		}
		$total++;
		$failed += $bad ? 1 : 0;
		$ms += (int)($e['ms'] ?? 0);
		$ip = (string)($e['ip'] ?? '');
		$ips[$ip] = ($ips[$ip] ?? 0) + 1;
		if (($e['k'] ?? '') !== '') {
			$key = $e['k'] . "\t" . ($e['u'] ?? '');
			$keys[$key] = ($keys[$key] ?? 0) + 1;
		}
		if ($bad) {
			$failing[$ip] = ($failing[$ip] ?? 0) + 1;
		}
	}
	arsort($ips);
	arsort($keys);
	arsort($failing);
	return array(
		'total' => $total,
		'failed' => $failed,
		'clients' => count($ips),
		'avg_ms' => $total ? (int)round($ms / $total) : 0,
		'buckets' => $buckets,
		'top_ips' => array_slice($ips, 0, 5, true),
		'top_keys' => array_slice($keys, 0, 5, true),
		'top_failing' => array_slice($failing, 0, 5, true),
	);
}

/*
 * Install the daily cleanup job. It stays installed when logging is off, so
 * entries written earlier still age out. Does not write config.
 */
function restapi_reqlog_configure_cron() {
	require_once('services.inc');
	install_cron_job(RESTAPI_REQLOG_CLI . ' prune', true, '23', '3', '*', '*', '*', 'root', false);
}
