<?php
/*
 * cfg_format.inc
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
 * What a value may look like where it is written into a daemon configuration
 * or a shell command. Pure functions with no dependencies, so they are testable
 * anywhere (tests/CfgFormatSmokeTest.php).
 *
 * Writers call these because a value can reach them without passing a form: a
 * restored backup, HA sync, Fleet, a package. Forms call them too, to say what
 * is wrong. Refuse rather than repair: a writer skips the item and logs it.
 */

/* True when $text holds a control character, which would end a line in a configuration file. */
function cfg_has_ctl($text): bool {
	return (preg_match('/[\x00-\x1f\x7f]/', (string)$text) === 1);
}

/* $text as one line, for comments. */
function cfg_one_line($text): string {
	return (string)preg_replace('/[\x00-\x1f\x7f]+/', ' ', (string)$text);
}

/*
 * Whether $value fits a named rule. An empty value is not valid for any rule;
 * callers decide whether a field is optional.
 *
 *   int        whole number
 *   decimal    number, optionally negative or with a fraction
 *   token      letters, digits and . _ : / - only
 *   quoted     fits between double quotes on one line
 *   path       absolute path of plain names
 *   statement  a dhclient or dhcp6c option body: words and quoted strings, with
 *              {placeholders}, and nothing that ends the statement or block
 *   login      a user name, one token of one line in a secrets file
 *   ntp_refid  an NTP reference id
 */
function cfg_value_valid(string $rule, $value): bool {
	if (!is_scalar($value)) {
		return false;
	}
	$value = (string)$value;
	switch ($rule) {
		case 'int':
			return preg_match('/^[0-9]{1,10}$/D', $value) === 1;
		case 'decimal':
			return preg_match('/^-?[0-9]{1,6}(\.[0-9]{1,9})?$/D', $value) === 1;
		case 'token':
			return preg_match('/^[A-Za-z0-9._:\/-]{1,255}$/D', $value) === 1;
		case 'quoted':
			return ($value !== '') && (strlen($value) <= 1024) && !cfg_has_ctl($value) &&
			    (strpbrk($value, '"\\') === false);
		case 'path':
			return (preg_match('/^(\/[A-Za-z0-9_.-]+)+$/D', $value) === 1) && (strpos($value, '..') === false);
		case 'statement':
			if (($value === '') || (strlen($value) > 2048) || cfg_has_ctl($value)) {
				return false;
			}
			$rest = (string)preg_replace(['/"[^"\\\\]*"/', '/\{[a-zA-Z0-9_ :.-]{1,32}\}/'], ' ', $value);
			return preg_match('/^[A-Za-z0-9 _.,:=\/%+-]*$/D', $rest) === 1;
		case 'login':
			return preg_match('/^[^\s"\\\\#:!\x00-\x1f\x7f]{1,128}$/D', $value) === 1;
		case 'ntp_refid':
			return preg_match('/^[A-Za-z0-9.]{1,4}$/D', $value) === 1;
	}
	return false;
}

/* The names of the fields in $values that break their rule. Empty fields are fine: they are not written. */
function cfg_invalid_fields(array $values, array $rules): array {
	$bad = [];
	foreach ($rules as $field => $rule) {
		$value = $values[$field] ?? '';
		if (($value !== '') && ($value !== null) && !cfg_value_valid($rule, $value)) {
			$bad[] = $field;
		}
	}
	return $bad;
}

/* Rules for the fields the DHCP client configuration writers use. */
function cfg_dhcp_client_rules(): array {
	$rules = [
		'dhcphostname' => 'quoted',
		'adv_dhcp_send_options' => 'statement',
		'adv_dhcp_request_options' => 'statement',
		'adv_dhcp_required_options' => 'statement',
		'adv_dhcp_option_modifiers' => 'statement',
		'adv_dhcp_config_file_override_path' => 'path',
		'adv_dhcp6_interface_statement_send_options' => 'statement',
		'adv_dhcp6_interface_statement_request_options' => 'statement',
		'adv_dhcp6_interface_statement_script' => 'path',
		'adv_dhcp6_config_file_override_path' => 'path',
		'adv_dhcp6_key_info_statement_realm' => 'quoted',
		'adv_dhcp6_key_info_statement_secret' => 'quoted',
		'adv_dhcp6_key_info_statement_expire' => 'quoted',
	];
	foreach (['timeout', 'retry', 'select_timeout', 'reboot', 'backoff_cutoff', 'initial_interval'] as $timing) {
		$rules["adv_dhcp_pt_{$timing}"] = 'int';
	}
	foreach (['id_assoc_statement_address', 'id_assoc_statement_address_id', 'id_assoc_statement_address_pltime',
	    'id_assoc_statement_address_vltime', 'id_assoc_statement_prefix', 'id_assoc_statement_prefix_id',
	    'id_assoc_statement_prefix_pltime', 'id_assoc_statement_prefix_vltime', 'prefix_interface_statement_sla_id',
	    'prefix_interface_statement_sla_len', 'authentication_statement_authname', 'authentication_statement_protocol',
	    'authentication_statement_algorithm', 'authentication_statement_rdm', 'key_info_statement_keyname',
	    'key_info_statement_keyid'] as $token) {
		$rules["adv_dhcp6_{$token}"] = 'token';
	}
	return $rules;
}

/* $values without the DHCP client fields that break their rule, and the names removed. */
function cfg_dhcp_client_clean(array $values): array {
	$bad = cfg_invalid_fields($values, cfg_dhcp_client_rules());
	foreach ($bad as $field) {
		$values[$field] = '';
	}
	return [$values, $bad];
}

/* A comma-separated list of numbers per link, as mpd settings are stored. Anything else becomes empty. */
function cfg_int_list($list): array {
	return array_map(fn($v) => cfg_value_valid('int', trim($v)) ? trim($v) : '', explode(',', (string)$list));
}

/* Whether a cron item can be written as one line of /etc/crontab. */
function cron_item_valid(array $item): bool {
	foreach (['minute', 'hour', 'mday', 'month', 'wday'] as $field) {
		if (preg_match('/^[A-Za-z0-9*,\/@-]+$/D', (string)($item[$field] ?? '')) !== 1) {
			return false;
		}
	}
	return (preg_match('/^[a-z0-9_-]+$/D', (string)($item['who'] ?? '')) === 1) &&
	    !empty($item['command']) && !cfg_has_ctl($item['command']);
}

/* Whether $text can be an unquoted strongSwan value: a brace would close the section and start another. */
function strongswan_text_valid($text): bool {
	return ((string)$text !== '') && (preg_match('/[\x00-\x1f\x7f{}"\\\\]/', (string)$text) !== 1);
}

/* A file name from a backup, before it becomes a path or reaches a shell. */
function cfg_filename_valid($name): bool {
	return (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,254}$/D', (string)$name) === 1) && (strpos((string)$name, '..') === false);
}
