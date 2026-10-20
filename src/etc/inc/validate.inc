<?php
/*
 * validate.inc
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
 * Shared input checks for the WebUI, the REST API and the system libraries.
 *
 * Every function here is pure: it takes a value and answers true or false
 * (or returns a list of messages) without reading config.xml, running a
 * command or touching disk. The file loads on its own in php-cli, so the
 * tests cover it directly, and util.inc loads it for the appliance.
 *
 * What goes here: the checks that were copied from page to page, such as
 * "no control characters", "only letters, digits and a few punctuation
 * marks", "a whole number in this range", "one of these values" and "these
 * fields are required". Address, port and host checks stay in util.inc
 * (is_ipaddr(), is_port(), is_hostname(), ...); this file does not repeat
 * them.
 *
 * Rules for a new check:
 *  - it takes `mixed` and never trusts the type: a form posts strings, the
 *    API posts JSON, a tampered request posts arrays
 *  - every regex is anchored with the D modifier, so a trailing newline
 *    does not slip through `$`
 *  - a value that is not text is refused, never cast and accepted
 *  - it owns no config.xml section and keeps no data outside config.xml
 */

/* Control characters other than tab, LF and CR. DEL (0x7f) counts as one. */
define('VALIDATE_CONTROL_CHARS', '/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/');
/* Every control character, including tab, LF and CR. */
define('VALIDATE_CONTROL_CHARS_ALL', '/[\x00-\x1f\x7f]/');

/* pure: true when $text holds a control character. Tab, LF and CR count only
 * when $allow_whitespace is false. null is empty text; a value that is not
 * text at all (an array, an object) counts as holding one, so a check that
 * refuses on true also refuses it. */
function validate_has_control_chars(mixed $text, bool $allow_whitespace = false): bool {
	if ($text === null) {
		return false;
	}
	if (!is_scalar($text)) {
		return true;
	}
	$pattern = $allow_whitespace ? VALIDATE_CONTROL_CHARS : VALIDATE_CONTROL_CHARS_ALL;
	return (preg_match($pattern, (string)$text) === 1);
}

/* pure: $text with every control character (tab, LF and CR included)
 * replaced by $replacement. For a value shown in a log or a list, never for
 * one that is checked: refuse those with validate_has_control_chars(). */
function validate_strip_control_chars(mixed $text, string $replacement = ''): string {
	if (!is_scalar($text)) {
		return '';
	}
	return (string)preg_replace(VALIDATE_CONTROL_CHARS_ALL, $replacement, (string)$text);
}

/* pure: true when $value is a string of $min to $max bytes with no control
 * characters. With $multiline, tab, LF and CR are allowed. $max of 0 means no
 * upper limit. */
function validate_text(mixed $value, int $max, int $min = 0, bool $multiline = false): bool {
	if (!is_string($value)) {
		return false;
	}
	$len = strlen($value);
	if (($len < $min) || (($max > 0) && ($len > $max))) {
		return false;
	}
	return !validate_has_control_chars($value, $multiline);
}

/* pure: true when $value holds only ASCII letters, digits and the characters
 * in $extra, and is at most $max bytes ($max of 0: no limit). null and ''
 * pass: whether the field may be empty is the required check's job. A value
 * that is not text fails. This is the check behind user, group, host and key
 * names that end up in a configuration file or on a command line. */
function validate_name_chars(mixed $value, string $extra = '.-_', int $max = 0): bool {
	if ($value === null) {
		return true;
	}
	if (!is_string($value)) {
		return false;
	}
	if (($max > 0) && (strlen($value) > $max)) {
		return false;
	}
	return (preg_match('/^[A-Za-z0-9' . preg_quote($extra, '/') . ']*$/D', $value) === 1);
}

/* pure: true when $value is one of $allowed, compared as strings (a form posts
 * "1", JSON posts 1; both match an allowed 1). Booleans never match, on
 * either side: a flag is a different check. A value that is not scalar
 * never matches. */
function validate_in_list(mixed $value, array $allowed): bool {
	if (!is_scalar($value) || is_bool($value)) {
		return false;
	}
	foreach ($allowed as $a) {
		if (is_scalar($a) && !is_bool($a) && ((string)$a === (string)$value)) {
			return true;
		}
	}
	return false;
}

/* pure: true when $value is a whole number written in decimal digits within
 * $min..$max (null: no bound). An int is accepted as is. A string may carry
 * a leading '-' only when $min is null or negative; "1e3", " 1", "1.0" and
 * more than 18 significant digits are refused. */
function validate_int(mixed $value, ?int $min = null, ?int $max = null): bool {
	if (is_int($value)) {
		$n = $value;
	} elseif (is_string($value) && (preg_match('/^(-?)([0-9]+)$/D', $value, $m) === 1)) {
		if (($m[1] === '-') && ($min !== null) && ($min >= 0)) {
			return false;
		}
		if (strlen(ltrim($m[2], '0')) > 18) {
			return false;
		}
		$n = (int)$value;
	} else {
		return false;
	}
	if (($min !== null) && ($n < $min)) {
		return false;
	}
	if (($max !== null) && ($n > $max)) {
		return false;
	}
	return true;
}

/* pure: true when $value is a TCP, UDP or SCTP port number, 1 to 65535. Use
 * is_port() from util.inc where a service name such as "ssh" is also fine. */
function validate_port_number(mixed $value): bool {
	return validate_int($value, 1, 65535);
}

/* pure: true when $value is a hexadecimal string of exactly $length
 * characters ($length of 0: any length above zero). Both cases pass; compare
 * a digest with hash_equals() after strtolower(), never with ==. */
function validate_hex(mixed $value, int $length = 0): bool {
	if (!is_string($value) || ($value === '')) {
		return false;
	}
	if (($length > 0) && (strlen($value) !== $length)) {
		return false;
	}
	return ctype_xdigit($value);
}

/* pure: true when $value is standard Base64 (the +/ alphabet, padded) that
 * decodes to at least $min_bytes. A value with whitespace, a missing pad or
 * characters outside the alphabet is refused, unlike base64_decode(). */
function validate_base64(mixed $value, int $min_bytes = 0): bool {
	if (!is_string($value) || ($value === '') || ((strlen($value) % 4) !== 0)) {
		return false;
	}
	if (preg_match('~^[A-Za-z0-9+/]*={0,2}$~D', $value) !== 1) {
		return false;
	}
	$raw = base64_decode($value, true);
	return ($raw !== false) && (strlen($raw) >= $min_bytes);
}

/* pure: true when a form value is blank: missing (null), '' or false. "0" and
 * an empty array are values, as do_input_validation() has always treated
 * them. */
function validate_is_blank(mixed $value): bool {
	return ($value === null) || ($value === '') || ($value === false);
}

/* pure: the "is required" messages for the keys of $fields (key => label)
 * that are blank in $post, in the order of $fields. An empty list means every
 * required field is present. */
function validate_required(array $post, array $fields): array {
	$errors = array();
	foreach ($fields as $key => $label) {
		if (validate_is_blank($post[$key] ?? null)) {
			$errors[] = sprintf(gettext('The field %s is required.'), $label);
		}
	}
	return $errors;
}

/* pure: the "contains invalid characters" messages for the string values of
 * $post that hold a control character other than tab, LF or CR. Only string
 * values are checked: arrays (multi-selects) are the page's own business. */
function validate_post_control_chars(array $post): array {
	$errors = array();
	foreach ($post as $name => $value) {
		if (is_string($value) && validate_has_control_chars($value, true)) {
			$errors[] = sprintf(gettext('The field %s contains invalid characters.'), $name);
		}
	}
	return $errors;
}
