<?php
/*
 * Smoke test for the central request gate. Run: php tests/InputGateSmokeTest.php
 */

$repo = dirname(__DIR__);
require_once($repo . '/src/etc/inc/input_gate.inc');

$failures = 0;
function check(string $name, bool $ok, string $detail = ''): void {
	global $failures;
	echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : ": {$detail}") . "\n";
	$failures += $ok ? 0 : 1;
}
function classes(array $values, array $multiline = [], array $skip = []): string {
	return input_gate_summary(input_gate_scan($values, $multiline, $skip));
}

/* Ordinary input passes, however unusual its printable characters are. */
check('plain text', classes(['descr' => 'Router "A" $5; a|b <c> & d # é 日本語']) === '');
check('every shell character in a password', classes(['passwordfld1' => "\"'\\\$;&|<>#` ~!*()"]) === '');
check('numbers, booleans and null from REST', classes(['a' => 1, 'b' => true, 'c' => null, 'd' => 1.5]) === '');
check('lists and numeric keys', classes(['rule' => ['1', '2'], 'map' => ['x' => ['y' => 'z']]]) === '');
check('an empty field', classes(['a' => '']) === '');
check('field names with brackets, dots and colons', classes(['a.b:c-d_e[0]' => 'x']) === '');

/* Line breaks. */
check('LF is refused', classes(['username' => "a\nb"]) === 'username=newline');
check('CR is refused', classes(['username' => "a\rb"]) === 'username=newline');
check('tab is its own class', classes(['descr' => "a\tb"]) === 'descr=tab');
check('a declared field allows them', classes(['cert' => "a\r\nb\tc\n"], ['cert']) === '');
check('only that field', classes(['cert' => "a\n", 'descr' => "b\n"], ['cert']) === 'descr=newline');
check('in a nested value', classes(['a' => ['b' => "x\n"]]) === 'a[b]=newline');
check('lists under a declared field are allowed', classes(['cert' => ["a\n"]], ['cert']) === '');
check('a nested key is not matched by name', classes(['a' => ['cert' => "x\n"]], ['cert']) === 'a[cert]=newline');
check('name* covers numbered fields', classes(['value0' => "a\n", 'value12' => "b\n"], ['value*']) === '');
check('name* covers only digits', classes(['valueX' => "a\n"], ['value*']) === 'valueX=newline');
check('name* does not cover the bare name by itself', classes(['value' => "a\n"], ['value*']) === 'value=newline');

/* Control characters are refused in every field, multi-line or not. */
foreach (["\x00", "\x01", "\x08", "\x0b", "\x0c", "\x0e", "\x1f", "\x7f"] as $c) {
	check(sprintf('control character 0x%02x, even when multi-line', ord($c)), classes(['cert' => "a{$c}b"], ['cert']) === 'cert=control');
}

/* Encoding. */
check('broken UTF-8', classes(['a' => "x\xff"]) === 'a=encoding');
check('broken UTF-8, even when multi-line', classes(['a' => "x\xc3"], ['a']) === 'a=encoding');

/* Field names. A bad name must not reach the log. */
check('a name with a line break', classes(["a\nb" => 'x']) === '(invalid name)=name');
check('a name with a quote', classes(['a"b' => 'x']) === '(invalid name)=name');
check('a nested name', classes(['a' => ["b\nc" => 'x']]) === 'a[(invalid name)]=name');
check('an over-long name', classes([str_repeat('a', 129) => 'x']) === '(invalid name)=name');
check('128 characters is allowed', classes([str_repeat('a', 128) => 'x']) === '');
check('an empty name', classes(['' => 'x']) === '(invalid name)=name');
check('a trailing newline in a name', classes(["a\n" => 'x']) === '(invalid name)=name');
check('the summary never holds a value', strpos(classes(['a' => "secretvalue\n"]), 'secretvalue') === false);

/* Limits. */
check('depth 3 is allowed', classes(['a' => ['b' => ['c' => 'x']]]) === '');
check('depth 4 is refused', classes(['a' => ['b' => ['c' => ['d' => 'x']]]]) === 'a[b][c]=depth');
check('4096 values are allowed', classes(array_fill(0, 4096, 'x')) === '');
check('4097 are refused', classes(array_fill(0, 4097, 'x')) === '(request)=count');
$many = input_gate_scan(array_fill(0, 500, "x\n"));
check('the report is bounded', count($many) === INPUT_GATE_MAX_HITS, (string)count($many));
$deep = 'x';
for ($i = 0; $i < 2000; $i++) {
	$deep = [$deep];
}
check('a very deep value stops at the limit', classes(['a' => $deep]) === 'a[0][0]=depth');

/* Skipped fields. */
check('the CSRF token is skipped', classes(['__csrf_magic' => "sid:x\n"], [], INPUT_GATE_SKIP) === '');
check('skip applies at the top level only', classes(['a' => ['__csrf_magic' => "x\n"]], [], INPUT_GATE_SKIP) === 'a[__csrf_magic]=newline');

/* Package and wizard XML. */
$pkg = ['fields' => ['field' => [
	['fieldname' => 'plain', 'type' => 'input'],
	['fieldname' => 'notes', 'type' => 'textarea'],
	['fieldname' => 'rows', 'type' => 'rowhelper', 'rowhelper' => ['rowhelperfield' => [['fieldname' => 'cell', 'type' => 'textarea']]]],
	['name' => 'wizardtext', 'type' => 'textarea'],
]]];
$ml = input_gate_xml_multiline($pkg);
check('XML: a textarea', classes(['notes' => "a\n"], $ml) === '');
check('XML: a row helper textarea', classes(['cell3' => "a\n"], $ml) === '');
check('XML: a wizard textarea', classes(['wizardtext' => "a\n"], $ml) === '');
check('XML: an input stays one line', classes(['plain' => "a\n"], $ml) === 'plain=newline');
check('XML: nothing to find', input_gate_xml_multiline([]) === []);

/* The tables stay consistent with the pages they name. */
foreach (array_keys(INPUT_GATE_MULTILINE) as $page) {
	$paths = [$repo . "/src/usr/local/www/{$page}", $repo . "/src/usr/local/www/widgets/widgets/{$page}"];
	check("listed page exists: {$page}", (bool)array_filter($paths, 'file_exists'));
}
foreach (array_merge(INPUT_GATE_XML_PAGES) as $page) {
	check("{$page} checks itself once its XML is loaded", strpos(file_get_contents($repo . "/src/usr/local/www/{$page}"), 'input_gate_run_xml($pkg)') !== false);
}
check('the sign-in layer runs the gate', strpos(file_get_contents($repo . '/src/etc/inc/authgui.inc'), 'input_gate_run();') !== false);
check('the AJAX shortcut runs the gate', strpos(file_get_contents($repo . '/src/etc/inc/auth_check.inc'), 'input_gate_hits()') !== false);
check('the REST API runs the gate', strpos(file_get_contents($repo . '/src/usr/local/www/api/index.php'), 'input_gate_run_rest(') !== false);

/* do_input_validation() must walk lists too. */
$util = file_get_contents($repo . '/src/etc/inc/util.inc');
preg_match('/function do_input_validation\(.*?\n}\n/s', $util, $m);
eval($m[0]);
function dv(array $post, array $req = []): array {
	$errors = [];
	do_input_validation($post, $req, $req, $errors);
	return $errors;
}
if (!function_exists('gettext')) {
	function gettext($s) {
		return $s;
	}
}
check('validation: a control character in a string', count(dv(['a' => "x\x01"])) === 1);
check('validation: a control character in a list', count(dv(['a' => ["ok", "x\x01"]])) === 1);
check('validation: one in a nested list', count(dv(['a' => [["x\x01"]]])) === 1);
check('validation: one error per field', count(dv(['a' => ["x\x01", "y\x02"]])) === 1);
check('validation: clean values', dv(['a' => ['ok'], 'b' => 'ok']) === []);
check('validation: a missing required field', count(dv([], ['a'])) === 1);
check('validation: an empty required field', count(dv(['a' => ''], ['a'])) === 1);
check('validation: an empty required list', count(dv(['a' => []], ['a'])) === 1);
check('validation: a filled required list', dv(['a' => ['x']], ['a']) === []);
check('validation: 0 counts as filled', dv(['a' => '0'], ['a']) === []);

if ($failures) {
	echo "{$failures} failed\n";
	exit(1);
}
echo "all passed\n";
