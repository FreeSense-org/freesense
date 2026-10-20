<?php
/*
 * Standalone CI regression test for the shared input checks
 * (src/etc/inc/validate.inc) and the anchoring of the util.inc validators
 * that build on them; run with `php tests/ValidateSmokeTest.php`.
 *
 * util.inc cannot load outside the appliance (it needs Net_IPv6), so the
 * util.inc functions under test are read out of the source and evaluated.
 */

$root = dirname(__DIR__);
set_include_path(get_include_path() . PATH_SEPARATOR . realpath($root . '/src/etc/inc'));

if (!function_exists('gettext')) {
	function gettext($s) {
		return $s;
	}
}

require_once('validate.inc');

function check($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

/* The source of one top-level function of $code. */
function fn_source($code, $name) {
	if (!preg_match('/\nfunction ' . preg_quote($name, '/') . '\(.*?\n}\n/s', $code, $m)) {
		fwrite(STDERR, "FAIL: function {$name}() not found\n");
		exit(1);
	}
	return $m[0];
}

/* Control characters */
check(!validate_has_control_chars('plain text, with punctuation!'), 'plain text has no control characters');
check(!validate_has_control_chars(null) && !validate_has_control_chars('') && !validate_has_control_chars(42), 'null, empty and numbers are clean');
foreach (array("a\x00b", "a\x1fb", "a\x7fb", "a\tb", "a\nb", "a\rb") as $bad) {
	check(validate_has_control_chars($bad), 'control character detected: ' . json_encode($bad));
}
foreach (array("a\tb", "a\nb", "a\r\nb") as $ok) {
	check(!validate_has_control_chars($ok, true), 'whitespace allowed when asked: ' . json_encode($ok));
}
foreach (array("a\x00b", "a\x0bb", "a\x7fb") as $bad) {
	check(validate_has_control_chars($bad, true), 'other control characters still refused: ' . json_encode($bad));
}
check(validate_has_control_chars(array('x')) && validate_has_control_chars(new stdClass()), 'a non-text value counts as unsafe');
check(validate_strip_control_chars("a\x00b\tc\x7fd") === 'abcd', 'strip removes every control character');
check(validate_strip_control_chars("a\nb", ' ') === 'a b', 'strip can replace');
check(validate_strip_control_chars(array('x')) === '' && validate_strip_control_chars(null) === '', 'strip of a non-text value is empty');
check(validate_strip_control_chars('é ü') === 'é ü', 'strip leaves UTF-8 alone');

/* Text with length bounds */
check(validate_text('hello', 10) && validate_text('hello', 5) && validate_text('', 10), 'text within the limit');
check(!validate_text('hello', 4), 'text over the limit');
check(validate_text('abc', 10, 3) && !validate_text('ab', 10, 3) && !validate_text('', 10, 1), 'text under the minimum');
check(validate_text(str_repeat('x', 5000), 0), 'max 0 means no upper limit');
check(!validate_text("a\nb", 10) && validate_text("a\nb", 10, 0, true) && !validate_text("a\x00b", 10, 0, true), 'multiline only when asked');
foreach (array(null, 5, true, array('x'), 1.5) as $bad) {
	check(!validate_text($bad, 10), 'text must be a string: ' . json_encode($bad));
}
check(!validate_text("abc\x7f", 10), 'DEL is a control character');

/* Name characters */
foreach (array('admin', 'john.doe', 'host-1', 'a_b', 'ABC123', '', null) as $ok) {
	check(validate_name_chars($ok), 'name characters accepted: ' . json_encode($ok));
}
foreach (array('a b', "a\n", "abc\n", 'a/b', 'a;b', 'a$b', 'ä', "a\x00", '../x', '*', array('a'), 5, true) as $bad) {
	check(!validate_name_chars($bad), 'name characters refused: ' . json_encode($bad));
}
check(validate_name_chars('a-b', '-') && !validate_name_chars('a.b', '-') && validate_name_chars('abc', ''), 'extra characters are the caller\'s choice');
check(validate_name_chars('a/b', '/') && validate_name_chars('a]b', ']') && !validate_name_chars('a^b', ']'), 'extra characters are quoted in the pattern');
check(validate_name_chars('abcd', '.-_', 4) && !validate_name_chars('abcde', '.-_', 4), 'name length limit');

/* Allow lists */
check(validate_in_list('dark', array('auto', 'dark', 'light')), 'value in the list');
check(validate_in_list(1, array('1', '2')) && validate_in_list('2', array(1, 2)), 'numbers and strings compare as strings');
check(!validate_in_list('Dark', array('dark')) && !validate_in_list('', array('dark')) && !validate_in_list(null, array('dark')), 'exact match only');
check(!validate_in_list(array('dark'), array('dark')) && !validate_in_list('0', array(false)), 'no loose comparison');
check(!validate_in_list('1', array(true)), 'booleans in the list do not match a string');

/* Whole numbers */
foreach (array('0', '7', '007', '65535', 42, '-3') as $ok) {
	check(validate_int($ok), 'integer accepted: ' . json_encode($ok));
}
foreach (array('', ' 1', '1 ', '1.0', '1e3', '0x1f', '+1', "1\n", '--1', null, true, 1.5, array(1), '9999999999999999999') as $bad) {
	check(!validate_int($bad), 'integer refused: ' . json_encode($bad));
}
check(validate_int('5', 1, 10) && validate_int('1', 1, 10) && validate_int('10', 1, 10), 'integer inside the range');
check(!validate_int('0', 1, 10) && !validate_int('11', 1, 10) && !validate_int(-1, 0), 'integer outside the range');
check(!validate_int('-1', 0) && validate_int('-1', -5) && validate_int('-1', null, 5), 'negative only when the minimum allows it');
check(validate_int('000000000000000000001'), 'leading zeros do not count as digits');
check(validate_port_number('22') && validate_port_number(65535) && !validate_port_number('0') && !validate_port_number('65536') && !validate_port_number('ssh'), 'port numbers');

/* Hex and Base64 */
check(validate_hex('deadBEEF') && validate_hex(str_repeat('a', 64), 64) && !validate_hex(str_repeat('a', 63), 64), 'hex strings');
check(!validate_hex('') && !validate_hex('xyz') && !validate_hex("ab\n") && !validate_hex(array('ab')) && !validate_hex(12), 'hex refusals');
check(validate_base64('aGVsbG8=') && validate_base64('aGk=', 2) && !validate_base64('aGk=', 3), 'base64 strings');
foreach (array('', 'aGVsbG8', "aGVsbG8=\n", 'aGVs bG8=', 'aGVsbG8-', '====', array('x'), 4) as $bad) {
	check(!validate_base64($bad), 'base64 refused: ' . json_encode($bad));
}

/* Required fields */
check(validate_is_blank(null) && validate_is_blank('') && validate_is_blank(false), 'blank values');
check(!validate_is_blank('0') && !validate_is_blank(0) && !validate_is_blank(' ') && !validate_is_blank(array()), 'values that are not blank');
$errors = validate_required(array('name' => '', 'descr' => 'x', 'port' => '0'), array('name' => 'Name', 'descr' => 'Description', 'port' => 'Port', 'other' => 'Other'));
check($errors === array('The field Name is required.', 'The field Other is required.'), 'required messages: ' . json_encode($errors));
check(validate_required(array('a' => '1'), array('a' => 'A')) === array(), 'no message when every field is present');
$errors = validate_post_control_chars(array('ok' => "a\tb\n", 'bad' => "a\x00b", 'list' => array("a\x00b"), 'num' => 5));
check($errors === array('The field bad contains invalid characters.'), 'post control characters: ' . json_encode($errors));

/* util.inc builds on the library and anchors its validators. */
$util = file_get_contents($root . '/src/etc/inc/util.inc');
check(strpos($util, "require_once('validate.inc');") !== false, 'util.inc loads validate.inc');
foreach (array('is_ipaddrv4', 'is_unqualified_hostname', 'is_domain', 'is_hostname', 'is_subnet', 'is_mcastv4', 'is_valid_shaperbw', 'do_input_validation') as $fn) {
	eval(fn_source($util, $fn));
}
function is_ipaddrv6($ipaddr) {
	return false;
}
foreach (array('example.com', 'host', 'a-b.example.org.', 'x_y.example') as $ok) {
	check(is_domain($ok) && is_hostname($ok), 'domain accepted: ' . $ok);
}
foreach (array("example.com\n", "example.com\r\n", "host\n", '-bad.example', 'a b', '', null, array('x')) as $bad) {
	check(!is_domain($bad), 'domain refused: ' . json_encode($bad));
	check(!is_hostname($bad), 'hostname refused: ' . json_encode($bad));
}
check(is_unqualified_hostname('host1') && !is_unqualified_hostname("host1\n") && !is_unqualified_hostname('a.b'), 'unqualified hostname');
check(is_subnet('192.0.2.0/24') === 4 && !is_subnet("192.0.2.0/24\n") && !is_subnet('192.0.2.0/33') && !is_subnet('192.0.2.0'), 'subnet');
check(is_mcastv4('224.0.0.1') && !is_mcastv4("224.0.0.1\n") && !is_mcastv4('10.0.0.1'), 'multicast v4');
check(is_valid_shaperbw('10Mb') && is_valid_shaperbw('50%') && !is_valid_shaperbw("10Mb\n") && !is_valid_shaperbw('10 Mb'), 'shaper bandwidth');

$input_errors = array();
do_input_validation(array('name' => '', 'descr' => "a\x00b", 'ok' => "x\ty", 'zero' => '0'), array('name', 'zero', 'missing'), array('Name', 'Zero', 'Missing'), $input_errors);
check($input_errors === array('The field descr contains invalid characters.', 'The field Name is required.', 'The field Missing is required.'),
    'do_input_validation keeps its messages: ' . json_encode($input_errors));
$input_errors = array('earlier');
do_input_validation(array('a' => 'x'), array('a'), array('A'), $input_errors);
check($input_errors === array('earlier'), 'do_input_validation keeps earlier errors and adds none');
$input_errors = null;
do_input_validation(array(), array('a'), array('A'), $input_errors);
check($input_errors === array('The field A is required.'), 'do_input_validation starts the error list when it is not one');
$input_errors = array();
do_input_validation(array('a' => "x\x7f"), null, null, $input_errors);
check($input_errors === array('The field a contains invalid characters.'), 'DEL is refused in form input');

/* Every anchored regex in validate.inc carries the D modifier. */
$lib = file_get_contents($root . '/src/etc/inc/validate.inc');
preg_match_all('/\$([\/~])([a-zA-Z]*)\'/', $lib, $m);
check(count($m[0]) >= 3, 'anchored patterns found in validate.inc');
foreach ($m[2] as $i => $flags) {
	check(strpos($flags, 'D') !== false, 'anchored pattern without D: ' . $m[0][$i]);
}

/* The copies the library replaced are gone. */
foreach (array('src/etc/inc', 'src/usr/local/www', 'src/usr/local/FreeSense/include/www') as $dir) {
	$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir));
	foreach ($it as $file) {
		$path = (string)$file;
		if (!preg_match('/\.(inc|php)$/', $path) || (strpos($path, '/vendor/') !== false) || (substr($path, -13) === '/validate.inc')) {
			continue;
		}
		$src = file_get_contents($path);
		check(strpos($src, '[^a-zA-Z0-9\.\-_]') === false && strpos($src, '[^A-Za-z0-9\.\-\_]') === false, 'hand-written name character class in ' . $path);
		check(strpos($src, "preg_match('/[\\x00-\\x1f\\x7f]/'") === false, 'hand-written control character check in ' . $path);
	}
}
check(strpos(file_get_contents($root . '/src/usr/local/FreeSense/include/www/vpn_l2tp.inc'), 'return validate_has_control_chars($text);') !== false, 'l2tp uses the library');

echo "OK: validate.inc and the util.inc validators it backs\n";
