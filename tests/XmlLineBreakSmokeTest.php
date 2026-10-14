<?php
/* Standalone CI regression test; run with `php tests/XmlLineBreakSmokeTest.php`. */

set_include_path(get_include_path() . PATH_SEPARATOR . realpath(__DIR__ . '/../src/etc/inc'));
require_once('xmlparse.inc');

function check_xml($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

/* Browsers submit textareas with CRLF; every line break must survive config.xml. */
$values = [
	'lf' => "a\nb",
	'crlf' => "https://dns.quad9.net/dns-query\r\nhttps://cloudflare-dns.com/dns-query",
	'cr' => "a\rb",
	'blank_lines' => "a\r\n\r\nb",
	'indent' => "a\n\tb",
	'entity' => "a\r\n&amp; <b>",
	'descr' => "line one\r\nline two",
];
$xml = dump_xml_config(['test' => $values], 'freesense');
$doc = new DOMDocument();
check_xml(@$doc->loadXML($xml) === true, 'written config must be well-formed XML');

$file = tempnam(sys_get_temp_dir(), 'fs-xml-');
file_put_contents($file, $xml);
$back = parse_xml_config($file, 'freesense');
unlink($file);
foreach ($values as $key => $value) {
	check_xml(($back['test'][$key] ?? null) === $value, "{$key} must survive a round trip, got " . json_encode($back['test'][$key] ?? null));
}

/* Formatting whitespace around a value is still not part of it. */
$file = tempnam(sys_get_temp_dir(), 'fs-xml-');
file_put_contents($file, "<?xml version=\"1.0\"?>\n<freesense>\n\t<test>\n\t\t<v>\n\t\t\tvalue\n\t\t</v>\n\t</test>\n</freesense>\n");
$back = parse_xml_config($file, 'freesense');
unlink($file);
check_xml(($back['test']['v'] ?? null) === "value", 'indentation around a value must be trimmed, got ' . json_encode($back['test']['v'] ?? null));

echo "XML line break smoke test passed.\n";
