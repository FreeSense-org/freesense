<?php

$makeConf = file_get_contents(dirname(__DIR__) . '/tools/conf/pfPorts/make.conf');
if ($makeConf === false) {
	fwrite(STDERR, "Unable to read package build options.\n");
	exit(1);
}

function forced_options(string $makeConf, string $port, string $kind): array
{
	$pattern = '/^' . preg_quote($port . '_' . $kind . '_FORCE=', '/') . '([^\r\n]*)/m';
	if (!preg_match($pattern, $makeConf, $match)) {
		fwrite(STDERR, "Missing {$port} {$kind} option contract.\n");
		exit(1);
	}

	return preg_split('/\s+/', trim($match[1]), -1, PREG_SPLIT_NO_EMPTY);
}

$suricataSet = forced_options($makeConf, 'security_suricata', 'SET');
$suricataUnset = forced_options($makeConf, 'security_suricata', 'UNSET');
$nginxSet = forced_options($makeConf, 'www_nginx', 'SET');

if (!in_array('LUA', $suricataSet, true) ||
    in_array('LUAJIT', $suricataSet, true) ||
    !in_array('LUAJIT', $suricataUnset, true)) {
	fwrite(STDERR, "Suricata must use stock Lua and explicitly reject LuaJIT.\n");
	exit(1);
}

if (!in_array('LUA', $nginxSet, true)) {
	fwrite(STDERR, "The nginx WebGUI Lua runtime contract changed unexpectedly.\n");
	exit(1);
}

echo "Package runtime compatibility smoke test passed.\n";
