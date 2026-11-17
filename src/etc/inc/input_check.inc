<?php
/*
 * input_check.inc
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
 * Field rules for a form or REST saver, in one declaration per saver:
 *
 *   list($clean, $errors) = input_check($post, array(
 *       'descr' => array('line', 'label' => gettext('Description'), 'max' => 255),
 *       'proto' => array('enum', 'values' => array('tcp', 'udp'), 'required' => true),
 *       'username*' => array('login'),
 *   ));
 *
 * $clean holds ONLY declared fields, in their declared shape, so an undeclared
 * field cannot reach a saver that reads it. A field that is missing or empty
 * passes unless it is required. Checks between fields stay in the saver.
 *
 * Rules: line text secret flag int decimal enum token login ntp_refid ip ipv4
 * ipv6 subnet hostname domain alias port port_range ip_or_alias port_or_alias
 * url_http mac abs_path list.
 * Options: label, required, max (length, or highest int), min (lowest int),
 * values (enum), under (abs_path directories), of and max_items (list).
 * "name*" also matches name0, name1 and so on. Error messages never hold a value.
 */

const INPUT_CHECK_LINE_MAX = 1024;
const INPUT_CHECK_TEXT_MAX = 1048576;

function input_check(array $post, array $rules): array {
	$clean = [];
	$errors = [];
	foreach ($rules as $name => $rule) {
		$names = [$name];
		if (str_ends_with($name, '*')) {
			$base = substr($name, 0, -1);
			$names = array_values(array_filter(array_map('strval', array_keys($post)), function ($key) use ($base) {
				return (strlen($key) > strlen($base)) && str_starts_with($key, $base) && ctype_digit(substr($key, strlen($base)));
			}));
		}
		foreach ($names as $field) {
			input_check_field($post, $field, $rule, $clean, $errors);
		}
	}
	return [$clean, $errors];
}

function input_check_field(array $post, string $field, array $rule, array &$clean, array &$errors): void {
	$type = $rule[0] ?? '';
	$label = $rule['label'] ?? $field;
	$value = $post[$field] ?? null;
	$list = ($type === 'list');

	if (($value === null) || ($value === '') || ($value === [])) {
		if (!empty($rule['required'])) {
			$errors[] = sprintf(gettext('The field %s is required.'), $label);
		}
		if (array_key_exists($field, $post)) {
			$clean[$field] = $list ? [] : '';
		}
		return;
	}
	if ($list) {
		$items = is_array($value) ? array_values($value) : [$value];
		$item_rule = array_merge([$rule['of'] ?? ''], array_diff_key($rule, array_flip([0, 'of', 'max_items', 'required', 'label'])));
		$out = [];
		$bad = isset($rule['max_items']) && (count($items) > $rule['max_items']);
		foreach ($items as $item) {
			$checked = input_check_value($item, $item_rule);
			$bad = $bad || ($checked === null);
			$out[] = $checked;
		}
		if ($bad) {
			$errors[] = sprintf(gettext('The value of %s is not valid.'), $label);
			return;
		}
		$clean[$field] = $out;
		return;
	}
	$checked = input_check_value($value, $rule);
	if ($checked === null) {
		$errors[] = sprintf(gettext('The value of %s is not valid.'), $label);
		return;
	}
	$clean[$field] = $checked;
}

/* The value in its clean form, or null when it does not fit the rule. */
function input_check_value(mixed $value, array $rule): mixed {
	$type = $rule[0] ?? '';
	if (!is_scalar($value)) {
		return null;
	}
	if (($type === 'flag') && is_bool($value)) {
		return $value;
	}
	$text = (string)$value;
	$max = (int)($rule['max'] ?? 0);

	$valid = match ($type) {
		'line' => (preg_match('/[\x00-\x1f\x7f]/', $text) === 0) && (strlen($text) <= ($max ?: INPUT_CHECK_LINE_MAX)),
		'text' => (preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $text) === 0) && (strlen($text) <= ($max ?: INPUT_CHECK_TEXT_MAX)),
		'secret' => (preg_match('/[\x00\r\n]/', $text) === 0) && (strlen($text) <= ($max ?: INPUT_CHECK_LINE_MAX)),
		'flag' => (preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $text) === 1),
		'int' => is_numericint($text) && (strlen($text) <= 18) &&
		    (!isset($rule['min']) || ((int)$text >= $rule['min'])) && (!isset($rule['max']) || ((int)$text <= $rule['max'])),
		'decimal', 'token', 'login', 'ntp_refid' => cfg_value_valid($type, $text),
		'enum' => in_array($text, array_map('strval', $rule['values'] ?? []), true),
		'ip' => is_ipaddr($text),
		'ipv4' => is_ipaddrv4($text),
		'ipv6' => is_ipaddrv6($text),
		'subnet' => is_subnet($text),
		'hostname' => is_hostname($text),
		'domain' => is_domain($text),
		'alias' => (is_validaliasname($text) === true),
		'port' => is_port($text),
		'port_range' => is_portrange($text) || is_port($text),
		'ip_or_alias' => is_ipaddroralias($text),
		'port_or_alias' => is_port_or_alias($text),
		'url_http' => is_URL($text, true) && (preg_match('/[\x00-\x20\x7f]/', $text) === 0),
		'mac' => is_macaddr($text),
		'abs_path' => input_check_path($text, $rule['under'] ?? null),
		default => throw new InvalidArgumentException("unknown input rule {$type}"),
	};
	if (!$valid) {
		return null;
	}
	return ($type === 'text') ? str_replace(["\r\n", "\r"], "\n", $text) : $text;
}

function input_check_path(string $path, ?array $under): bool {
	if (!cfg_value_valid('path', $path)) {
		return false;
	}
	if ($under === null) {
		return true;
	}
	foreach ($under as $dir) {
		if (str_starts_with($path, rtrim($dir, '/') . '/')) {
			return true;
		}
	}
	return false;
}
