<?php
/* The rules in cfg_format.inc, with the values that matter. No system needed. */
require dirname(__DIR__) . '/src/etc/inc/cfg_format.inc';

function cfg_check($ok, $message) {
	if (!$ok) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

$table = [
	'int' => [['0', '120', '4294967295'], ['', '-1', '1.5', '1;id', "1\n", '9abc', '$(id)']],
	'decimal' => [['0', '0.155', '-0.5', '12'], ['', '1e5', '1;id', "0.1\nx", 'abc', '0.1 flag1 1']],
	'token' => [['fe80::1', '2001:db8::/64', 'hmac-sha256', 'infinity', 'a.b_c-1'], ['', 'a b', 'a;b', 'a"b', "a\n", 'a{b']],
	'quoted' => [['my host', 'a;b', 'x{y}', '2026-01-01 10:00'], ['', 'a"b', 'a\\b', "a\nb", "a\rb", "a\x00"]],
	'path' => [['/usr/local/bin/x.sh', '/root/a_b-c.conf'], ['', 'x.sh', '/a/../b', '/a b', '/a;b', "/a\n", '/'], ],
	'login' => [['alice', 'alice@corp.example', 'a.b_c-d+e'], ['', 'a b', 'a"b', 'a\\b', 'a#b', 'a:b', '!a', "a\nb"]],
	'ntp_refid' => [['GPS', 'PPS', 'A.B'], ['', 'TOOLONG', 'a b', 'a;', "a\n"]],
	'statement' => [
		['dhcp-class-identifier "my id; with } text"', 'dhcp-client-identifier 01:{mac_addr_hexL:}', 'subnet-mask, routers, domain-name-servers',
		    'supersede domain-name-servers 127.0.0.1', 'host-name "{hostname}"', 'prepend domain-name "x.example"'],
		['', 'a; script "/bin/evil"', "a\nb", 'a } interface "x" {', 'media "x;id"; script', 'a"b', 'x `id`', 'x $(id)', 'a { b', 'bad "unterminated'],
	],
];
foreach ($table as $rule => [$good, $bad]) {
	foreach ($good as $v) {
		cfg_check(cfg_value_valid($rule, $v), "{$rule} must accept " . json_encode($v));
	}
	foreach ($bad as $v) {
		cfg_check(!cfg_value_valid($rule, $v), "{$rule} must refuse " . json_encode($v));
	}
}
cfg_check(!cfg_value_valid('nosuchrule', 'a'), 'an unknown rule refuses everything');
cfg_check(!cfg_value_valid('token', ['a']), 'an array is never valid');

cfg_check(cfg_has_ctl("a\nb") && cfg_has_ctl("a\tb") && !cfg_has_ctl('a b'), 'cfg_has_ctl');
cfg_check(cfg_one_line("a\r\nb\tc") === 'a b c', 'cfg_one_line');
cfg_check(cfg_invalid_fields(['a' => '1', 'b' => 'x;', 'c' => ''], ['a' => 'int', 'b' => 'int', 'c' => 'int', 'd' => 'int']) === ['b'], 'empty and absent fields are fine');

[$clean, $bad] = cfg_dhcp_client_clean(['dhcphostname' => 'ok', 'adv_dhcp_send_options' => 'a; script "/bin/evil"', 'adv_dhcp_pt_retry' => '15', 'adv_dhcp_pt_reboot' => '10;x', 'unrelated' => 'x;y']);
cfg_check($bad === ['adv_dhcp_send_options', 'adv_dhcp_pt_reboot'], 'the bad DHCP fields are named');
cfg_check($clean['adv_dhcp_send_options'] === '' && $clean['adv_dhcp_pt_reboot'] === '' && $clean['dhcphostname'] === 'ok' && $clean['adv_dhcp_pt_retry'] === '15', 'only the bad DHCP fields are cleared');
cfg_check($clean['unrelated'] === 'x;y', 'fields without a rule are left alone');

cfg_check(cfg_int_list('1492,1500') === ['1492', '1500'], 'a number list');
cfg_check(cfg_int_list("1492,1\nset x,abc") === ['1492', '', ''], 'bad list entries become empty');
cfg_check(cfg_int_list('') === [''], 'an empty list stays one empty entry');

$item = ['minute' => '*/7', 'hour' => '1-3', 'mday' => '*', 'month' => '*', 'wday' => 'mon', 'who' => 'root', 'command' => '/bin/echo "a $b"'];
cfg_check(cron_item_valid($item), 'a normal cron item');
cfg_check(cron_item_valid(['minute' => '@reboot'] + $item), '@reboot');
foreach ([['minute' => '0 * * * * root evil #'], ['hour' => "1\n"], ['who' => 'root evil'], ['command' => "/bin/x\n* * * * * root y"], ['command' => ''], ['wday' => '']] as $change) {
	cfg_check(!cron_item_valid($change + $item), 'cron item refused: ' . json_encode($change));
}

cfg_check(strongswan_text_valid('Welcome to the VPN.'), 'a normal banner');
foreach (['', 'a } b', 'a { b', 'a"b', 'a\\b', "a\nb"] as $v) {
	cfg_check(!strongswan_text_valid($v), 'banner refused: ' . json_encode($v));
}
foreach (['wan-traffic.rrd', 'captiveportal-lan.db', 'dhcp4.leases'] as $v) {
	cfg_check(cfg_filename_valid($v), "file name {$v}");
}
foreach (['', 'a;id.rrd', 'a b', '../x', '.hidden', 'a/b', "a\n", 'a$(id)', 'x..y'] as $v) {
	cfg_check(!cfg_filename_valid($v), 'file name refused: ' . json_encode($v));
}
echo "cfg_format smoke test passed\n";
