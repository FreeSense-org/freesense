<?php
/*
 * Smoke test for the field rules. Run: php tests/InputCheckSmokeTest.php
 */

$root = dirname(__DIR__);
// util.inc requires the PEAR Net_IPv6 package. The firewall has it; where it is missing (CI), a stub
// stands in and the IPv6 cases are skipped rather than faked.
$have_net_ipv6 = (stream_resolve_include_path('Net/IPv6.php') !== false);
$work_dir = sys_get_temp_dir() . '/freesense-input-check-' . getmypid();
@mkdir($work_dir . '/stub/Net', 0755, true);
file_put_contents($work_dir . '/stub/Net/IPv6.php', "<?php
class Net_IPv6 {}
");
set_include_path(get_include_path() . PATH_SEPARATOR . $root . '/src/etc/inc' . PATH_SEPARATOR . $work_dir . '/stub');
if (!function_exists('gettext')) {
	function gettext($s) {
		return $s;
	}
}
require_once('globals.inc');
require_once('util.inc');
require_once('input_check.inc');

$failures = 0;
function t(string $name, bool $ok, string $detail = ''): void {
	global $failures, $have_net_ipv6;
	if (!$have_net_ipv6 && str_contains($name, '::')) {
		echo "skip {$name} (no Net_IPv6)
";
		return;
	}
	echo ($ok ? 'ok   ' : 'FAIL ') . $name . ($ok || $detail === '' ? '' : ": {$detail}") . "\n";
	$failures += $ok ? 0 : 1;
}
function passes(array $rule, mixed $value): bool {
	[, $errors] = input_check(['f' => $value], ['f' => $rule]);
	return $errors === [];
}

$table = [
	/* rule, accepted, refused */
	[['line'], ['Router "A" $5; a|b <c> é 日本語', 'x'], ["a\nb", "a\rb", "a\tb", "a\x01", "a\x7f", "a\n", str_repeat('x', 1025), ['a']]],
	[['line', 'max' => 3], ['abc'], ['abcd']],
	[['text'], ["a\r\nb\tc\n", 'x'], ["a\x01b", "a\x00", ['a']]],
	[['secret'], ["\"'\\\$;&|<>#` ~!*()\t", 'DMYPWD'], ["a\nb", "a\rb", "a\x00b", ['a']]],
	[['flag'], ['yes', 'on', 'ON', true, '1', 'enable_x'], ["yes\n", 'a b', 'a;b', str_repeat('a', 33), ['yes']]],
	[['int'], ['0', '5', 5, '65535'], ['-1', '1e2', '5 ', "5\n", '0x10', '1.5', 'a', '99999999999999999999', ['1']]],
	[['int', 'min' => 1, 'max' => 10], ['1', '10'], ['0', '11']],
	[['decimal'], ['0.155', '-1', '5'], ['1e2', "1\n", '1 2', 'a']],
	[['enum', 'values' => ['tcp', 'udp', 5]], ['tcp', 'udp', '5', 5], ['TCP', "tcp\n", ' tcp', '0', 'other', ['tcp']]],
	[['token'], ['em0', 'a.b_c:d/e-f'], ['a b', "a\n", 'a;b', 'a"b']],
	[['login'], ['user.name@example-1'], ['a b', "a\nb", 'a:b', '!a']],
	[['ntp_refid'], ['GPS', 'PPS'], ['TOOLONG', 'a b', "G\n"]],
	[['ip'], ['192.168.1.1', '2001:db8::1'], ['192.168.1.1 ', "192.168.1.1\n", 'a', '1.2.3', '192.168.1.1%$(id)']],
	[['ipv4'], ['192.168.1.1'], ['2001:db8::1', '999.1.1.1']],
	[['ipv6'], ['2001:db8::1'], ['192.168.1.1', "2001:db8::1\n"]],
	[['subnet'], ['192.168.1.0/24', '2001:db8::/32'], ['192.168.1.0', "192.168.1.0/24\n", '192.168.1.0/33']],
	[['hostname'], ['router', 'router.example.org'], ['a b', "router\n", 'a;b']],
	[['domain'], ['example.org'], ["example.org\n", 'a b', '-bad-.org']],
	[['alias'], ['My_Alias1'], ['a b', "a\n", 'a-b;c']],
	[['port'], ['1', '65535'], ['0', '65536', '1:2', "80\n", 'a']],
	[['port_range'], ['80', '80:90'], ["80:90\n", 'a:b', '90 91']],
	[['url_http'], ['http://example.org/a?b=c&d=e', 'HTTPS://example.org:8443/'], ['file:///etc/passwd', 'ftp://example.org/', 'gopher://a/', "http://a/\nb", 'http://a b/', 'example.org']],
	[['mac'], ['00:11:22:33:44:55'], ["00:11:22:33:44:55\n", '00:11', 'zz:11:22:33:44:55']],
	[['abs_path'], ['/var/etc/a.conf'], ['var/etc', '/a/../b', "/a\n", '/a b', '/a;b']],
	[['abs_path', 'under' => ['/var/etc']], ['/var/etc/a.conf'], ['/etc/passwd', '/var/etcetera/a']],
];
foreach ($table as [$rule, $good, $bad]) {
	$name = $rule[0];
	foreach ($good as $v) {
		t("{$name} accepts " . json_encode($v), passes($rule, $v));
	}
	foreach ($bad as $v) {
		t("{$name} refuses " . json_encode($v, JSON_PARTIAL_OUTPUT_ON_ERROR), !passes($rule, $v));
	}
}

/* Declared fields only: this is what stops a posted extra field reaching a saver. */
[$clean, $errors] = input_check(['a' => 'x', 'evil' => 'y', 'act' => 'del'], ['a' => ['line']]);
t('undeclared fields do not reach clean', $clean === ['a' => 'x'] && $errors === []);
t('a missing optional field is absent, and passes', input_check([], ['a' => ['line']]) === [[], []]);
t('an empty optional field stays empty', input_check(['a' => ''], ['a' => ['int']]) === [['a' => ''], []]);
t('a refused value is not in clean', input_check(['a' => "x\n"], ['a' => ['line']])[0] === []);

/* Required. */
t('required: missing', count(input_check([], ['a' => ['line', 'required' => true]])[1]) === 1);
t('required: empty', count(input_check(['a' => ''], ['a' => ['line', 'required' => true]])[1]) === 1);
t('required: an empty list', count(input_check(['a' => []], ['a' => ['list', 'of' => 'line', 'required' => true]])[1]) === 1);
t('required: 0 counts as filled', input_check(['a' => '0'], ['a' => ['int', 'required' => true]])[1] === []);

/* Messages. */
[, $errors] = input_check(['a' => "hidden\n"], ['a' => ['line', 'label' => 'The label']]);
t('the message uses the label', strpos($errors[0], 'The label') !== false);
t('the message never holds the value', strpos($errors[0], 'hidden') === false);

/* Shape. */
t('text normalises line ends', input_check(['a' => "x\r\ny\rz"], ['a' => ['text']])[0]['a'] === "x\ny\nz");
t('a number becomes a string', input_check(['a' => 5], ['a' => ['int']])[0]['a'] === '5');
t('a list stays a list', input_check(['a' => ['x', 'y']], ['a' => ['list', 'of' => 'token']])[0]['a'] === ['x', 'y']);
t('list keys are not kept', input_check(['a' => ['evil' => 'x']], ['a' => ['list', 'of' => 'token']])[0]['a'] === ['x']);
t('a single value becomes a list', input_check(['a' => 'x'], ['a' => ['list', 'of' => 'token']])[0]['a'] === ['x']);
t('a list refuses a bad item', count(input_check(['a' => ['x', "y\n"]], ['a' => ['list', 'of' => 'token']])[1]) === 1);
t('a list refuses a nested list', count(input_check(['a' => [['x']]], ['a' => ['list', 'of' => 'token']])[1]) === 1);
t('a list reports one error', count(input_check(['a' => ["x\n", "y\n"]], ['a' => ['list', 'of' => 'token']])[1]) === 1);
t('max_items', count(input_check(['a' => ['x', 'y']], ['a' => ['list', 'of' => 'token', 'max_items' => 1]])[1]) === 1);
t('list items take options', count(input_check(['a' => ['5', '50']], ['a' => ['list', 'of' => 'int', 'max' => 10]])[1]) === 1);

/* Numbered fields. */
[$clean, $errors] = input_check(['user0' => 'a', 'user12' => 'b', 'userX' => 'c', 'user' => 'd'], ['user*' => ['login']]);
t('name* takes numbered fields only', $clean === ['user0' => 'a', 'user12' => 'b'] && $errors === [], json_encode($clean));
t('name* checks each one', count(input_check(['user0' => "a\n", 'user1' => 'b c'], ['user*' => ['login']])[1]) === 2);

/* A typo in a rule table is a bug, not a pass. */
$thrown = false;
try {
	input_check(['a' => 'x'], ['a' => ['nosuchrule']]);
} catch (InvalidArgumentException $e) {
	$thrown = true;
}
t('an unknown rule throws', $thrown);

/* Validators must not accept a trailing newline. */
foreach (['is_subnet' => '10.0.0.0/8', 'is_unqualified_hostname' => 'router', 'is_mcastv4' => '224.0.0.1', 'is_mcastv6' => 'ff02::1',
    'is_valid_shaperbw' => '10Mb', 'is_ipaddrv6_v4map' => '::ffff:1.2.3.4'] as $fn => $value) {
	t("{$fn} accepts {$value}", (bool)$fn($value));
	t("{$fn} refuses a trailing newline", !$fn("{$value}\n"));
}

if ($failures) {
	echo "{$failures} failed\n";
	exit(1);
}
echo "all passed\n";
