<?php
/* Standalone CI regression test; run with `php tests/XmlNumericKeySmokeTest.php`. */

set_include_path(get_include_path() . PATH_SEPARATOR . realpath(__DIR__ . '/../src/etc/inc'));
require_once('xmlparse.inc');

function check_xml($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

/* A package storing a plain list under a tag that is not a list tag. */
$config = ['installedpackages' => ['example' => [
	'tasks' => [['name' => 'one', 'command' => '/usr/bin/true'], ['name' => 'two', 'command' => '/usr/bin/false']],
	'countries' => ['RU', 'CN'],
]]];
$xml = dump_xml_config($config, 'freesense');

check_xml(!preg_match('/<\/?[0-9]/', $xml), 'numeric keys must not become XML tags');
$doc = new DOMDocument();
check_xml(@$doc->loadXML($xml) === true, 'written config must be well-formed XML');

$file = tempnam(sys_get_temp_dir(), 'fs-xml-');
file_put_contents($file, $xml);
$back = parse_xml_config($file, 'freesense');
unlink($file);
$example = $back['installedpackages']['example'] ?? [];
check_xml(($example['tasks']['item'][1]['name'] ?? null) === 'two', 'list entries must survive a round trip');
check_xml(($example['countries']['item'] ?? null) === ['RU', 'CN'], 'scalar list entries must survive a round trip');

/* Lists under real list tags keep their normal repeated-tag form. */
$xml = dump_xml_config(['filter' => ['rule' => [['descr' => 'a'], ['descr' => 'b']]]], 'freesense');
check_xml(substr_count($xml, '<rule>') === 2 && strpos($xml, '<item>') === false, 'list tags must keep repeated tags');

echo "XML numeric key smoke test passed.\n";
