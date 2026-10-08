<?php
/*
 * webui_prefs.inc
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
 * The signed-in user's WebUI data: profile, appearance preferences,
 * dashboard layout and GUI sessions (GET/PUT /api/v1/me/..., /dashboard/layout).
 *
 * The profile (display name, email) is part of the user account in
 * config.xml. Preferences and the dashboard layout change often (a theme
 * switch, a widget drag), so they live in small per-user files under
 * /conf/webui instead of creating a configuration revision each time;
 * administrators set defaults for preferences in system/webui/defaults.
 */

define('WEBUI_PREFS_DIR', '/conf/webui');
define('WEBUI_LAYOUT_MAX_BYTES', 65536);

/* --------------------------------------------------------------- files */

/* A per-user file name that cannot be steered by the user name. */
function webui_user_file($kind, $username) {
	return WEBUI_PREFS_DIR . "/{$kind}/" . hash('sha256', (string)$username) . '.json';
}

function webui_read_json($file) {
	$data = @json_decode((string)@file_get_contents($file), true);
	return is_array($data) ? $data : null;
}

function webui_write_json($file, $data) {
	$dir = dirname($file);
	if (!is_dir($dir)) {
		@mkdir($dir, 0700, true);
	}
	$tmp = $file . '.tmp';
	if ((@file_put_contents($tmp, json_encode($data, JSON_UNESCAPED_SLASHES), LOCK_EX) === false) || !@rename($tmp, $file)) {
		@unlink($tmp);
		return false;
	}
	@chmod($file, 0600);
	return true;
}

/* -------------------------------------------------------- preferences */

function webui_pref_defaults() {
	$d = config_get_path('system/webui/defaults', array());
	$d = is_array($d) ? $d : array();
	return array(
		'theme' => (string)($d['theme'] ?? 'freesense'),
		'mode' => (string)($d['mode'] ?? 'auto'),
		'accent' => (string)($d['accent'] ?? ''),
		'density' => (string)($d['density'] ?? 'comfortable'),
		'start_page' => '/',
	);
}

/*
 * Validate a preference update. Returns array(clean values, field errors).
 * Unknown keys are refused so typos do not silently do nothing.
 */
function webui_pref_validate(array $in) {
	$rules = array(
		'theme' => '/^[a-z][a-z0-9-]{1,39}$/',
		'mode' => '/^(light|dark|auto)$/',
		'accent' => '/^([a-z][a-z0-9-]{0,23})?$/',
		'density' => '/^(comfortable|compact)$/',
		'start_page' => '#^/[A-Za-z0-9/_.-]{0,200}$#',
	);
	$out = array();
	$errors = array();
	foreach ($in as $k => $v) {
		if (!isset($rules[$k])) {
			$errors[(string)$k] = 'Unknown preference.';
		} elseif (!is_string($v) || !preg_match($rules[$k], $v)) {
			$errors[$k] = array(
				'theme' => 'A theme name: lowercase letters, digits and hyphens.',
				'mode' => 'One of light, dark or auto.',
				'accent' => 'An accent name of the theme.',
				'density' => 'One of comfortable or compact.',
				'start_page' => 'A WebUI path such as /network/interfaces.',
			)[$k];
		} else {
			$out[$k] = $v;
		}
	}
	return array($out, $errors);
}

function webui_prefs_get($username) {
	$saved = webui_read_json(webui_user_file('prefs', $username)) ?? array();
	list($clean) = webui_pref_validate(array_intersect_key($saved, webui_pref_defaults()));
	return array_merge(webui_pref_defaults(), $clean);
}

function webui_prefs_put($username, array $values) {
	$file = webui_user_file('prefs', $username);
	$saved = webui_read_json($file) ?? array();
	return webui_write_json($file, array_merge($saved, $values)) ? webui_prefs_get($username) : null;
}

/* --------------------------------------------------- dashboard layout */

/* A layout: a list of {id, type, size, settings, collapsed}. Returns array(layout, error). */
function webui_layout_validate($widgets) {
	if (!is_array($widgets) || !array_is_list($widgets) || (count($widgets) > 64)) {
		return array(null, 'widgets must be a list of at most 64 widgets.');
	}
	if (strlen(json_encode($widgets)) > WEBUI_LAYOUT_MAX_BYTES) {
		return array(null, 'The layout is too large.');
	}
	$out = array();
	$ids = array();
	foreach ($widgets as $i => $w) {
		if (!is_array($w) || !is_string($w['id'] ?? null) || !preg_match('/^[A-Za-z0-9_-]{1,40}$/', $w['id']) ||
		    !is_string($w['type'] ?? null) || !preg_match('/^[a-z][a-z0-9:_-]{0,63}$/', $w['type'])) {
			return array(null, "Widget {$i} needs an id and a type.");
		}
		if (isset($ids[$w['id']])) {
			return array(null, "Widget id {$w['id']} is used twice.");
		}
		$ids[$w['id']] = true;
		$size = (string)($w['size'] ?? 'md');
		if (!in_array($size, array('sm', 'md', 'lg', 'xl', 'full'), true)) {
			return array(null, "Widget {$w['id']} has an unknown size.");
		}
		$settings = $w['settings'] ?? array();
		if (!is_array($settings)) {
			return array(null, "Widget {$w['id']} settings must be an object.");
		}
		$out[] = array('id' => $w['id'], 'type' => $w['type'], 'size' => $size, 'settings' => $settings,
		    'collapsed' => !empty($w['collapsed']));
	}
	return array($out, null);
}

function webui_layout_get($username) {
	$data = webui_read_json(webui_user_file('dashboard', $username));
	return isset($data['widgets']) ? $data['widgets'] : null;
}

function webui_layout_put($username, array $widgets) {
	return webui_write_json(webui_user_file('dashboard', $username), array('widgets' => $widgets, 'saved' => time()));
}

function webui_layout_delete($username) {
	@unlink(webui_user_file('dashboard', $username));
}

/* ------------------------------------------------------------ profile */

/* Initials for an avatar: first letters of the first two words. */
function webui_initials($name) {
	$words = preg_split('/\s+/u', trim((string)$name), -1, PREG_SPLIT_NO_EMPTY);
	$out = '';
	foreach (array_slice($words, 0, 2) as $w) {
		$out .= mb_strtoupper(mb_substr($w, 0, 1));
	}
	return $out;
}

/* Returns array(clean values, field errors) for a profile update. */
function webui_profile_validate(array $in) {
	$out = array();
	$errors = array();
	foreach ($in as $k => $v) {
		if ($k === 'name') {
			if (!is_string($v) || (mb_strlen(trim($v)) > 64) || preg_match('/[\x00-\x1f\x7f]/', $v)) {
				$errors['name'] = 'A name of at most 64 characters.';
			} else {
				$out['name'] = trim($v);
			}
		} elseif ($k === 'email') {
			if (!is_string($v) || (($v !== '') && (filter_var($v, FILTER_VALIDATE_EMAIL) === false)) || (strlen($v) > 254)) {
				$errors['email'] = 'A valid email address, or empty.';
			} else {
				$out['email'] = $v;
			}
		} else {
			$errors[(string)$k] = 'This cannot be changed here.';
		}
	}
	return array($out, $errors);
}

/* ----------------------------------------------------------- sessions */

/*
 * Decode a PHP session file (serialize_handler "php": key|serialized...).
 * Returns the session array, or null when the data cannot be read.
 */
function webui_session_decode($data) {
	$out = array();
	$pos = 0;
	$len = strlen((string)$data);
	while ($pos < $len) {
		$bar = strpos($data, '|', $pos);
		if ($bar === false) {
			return null;
		}
		$key = substr($data, $pos, $bar - $pos);
		$end = webui_serialized_end($data, $bar + 1);
		if ($end === null) {
			return null;
		}
		$value = @unserialize(substr($data, $bar + 1, $end - $bar - 1), array('allowed_classes' => false));
		$out[$key] = $value;
		$pos = $end;
	}
	return $out;
}

/* The offset just after the serialized value starting at $i, or null. */
function webui_serialized_end($s, $i) {
	$type = $s[$i] ?? '';
	switch ($type) {
		case 'N':
			return (($s[$i + 1] ?? '') === ';') ? $i + 2 : null;
		case 'b':
		case 'i':
		case 'd':
			$semi = strpos($s, ';', $i);
			return ($semi === false) ? null : $semi + 1;
		case 's':
			if (!preg_match('/\Gs:(\d+):"/', $s, $m, 0, $i)) {
				return null;
			}
			$end = $i + strlen($m[0]) + (int)$m[1];
			return (substr($s, $end, 2) === '";') ? $end + 2 : null;
		case 'a':
			if (!preg_match('/\Ga:(\d+):\{/', $s, $m, 0, $i)) {
				return null;
			}
			$p = $i + strlen($m[0]);
			for ($n = 0; $n < 2 * (int)$m[1]; $n++) {
				$p = webui_serialized_end($s, $p);
				if ($p === null) {
					return null;
				}
			}
			return (($s[$p] ?? '') === '}') ? $p + 1 : null;
		default:
			return null;
	}
}

/* A short, stable public id for a session (never the session id itself). */
function webui_session_public_id($sid) {
	return substr(hash('sha256', 'webui-session:' . $sid), 0, 16);
}

/*
 * The GUI sessions of $username: id, current, address, agent, signed in, last seen.
 * $current_sid is the caller's own session id (or null for API keys).
 */
function webui_sessions_list($username, $current_sid = null, $dir = null) {
	$dir = $dir ?? (ini_get('session.save_path') ?: sys_get_temp_dir());
	$timeout = (int)config_get_path('system/webgui/session_timeout', 240);
	$out = array();
	foreach ((array)glob(rtrim($dir, '/') . '/sess_*') as $file) {
		$sid = substr(basename($file), 5);
		$s = webui_session_decode((string)@file_get_contents($file));
		if (!is_array($s) || empty($s['Logged_In']) || (($s['Username'] ?? null) !== $username)) {
			continue;
		}
		$last = (int)($s['last_access'] ?? 0);
		if (($timeout > 0) && ($last < (time() - $timeout * 60))) {
			continue;
		}
		$out[] = array(
			'id' => webui_session_public_id($sid),
			'current' => ($current_sid !== null) && hash_equals((string)$sid, (string)$current_sid),
			'address' => (string)($s['REMOTE_ADDR'] ?? ''),
			'agent' => (string)($s['user_agent'] ?? ''),
			'authsource' => (string)($s['authsource'] ?? ''),
			'signed_in' => isset($s['login_time']) ? gmdate('c', (int)$s['login_time']) : null,
			'last_seen' => ($last > 0) ? gmdate('c', $last) : null,
		);
	}
	usort($out, function ($a, $b) { return strcmp((string)$b['last_seen'], (string)$a['last_seen']); });
	return $out;
}

/* Sign out one of $username's sessions by public id. Returns 'ok', 'current' or 'not_found'. */
function webui_session_revoke($username, $public_id, $current_sid = null, $dir = null) {
	$dir = $dir ?? (ini_get('session.save_path') ?: sys_get_temp_dir());
	foreach ((array)glob(rtrim($dir, '/') . '/sess_*') as $file) {
		$sid = substr(basename($file), 5);
		if (!hash_equals(webui_session_public_id($sid), (string)$public_id)) {
			continue;
		}
		$s = webui_session_decode((string)@file_get_contents($file));
		if (!is_array($s) || (($s['Username'] ?? null) !== $username)) {
			return 'not_found';
		}
		if (($current_sid !== null) && hash_equals((string)$sid, (string)$current_sid)) {
			return 'current';
		}
		@unlink($file);
		return 'ok';
	}
	return 'not_found';
}
