<?php
/* Standalone CI regression test for resource schemas and keyed 422 errors; run with `php tests/RestApiSchemaSmokeTest.php`. */

$root = dirname(__DIR__);
set_include_path(get_include_path() . PATH_SEPARATOR . realpath($root . '/src/etc/inc'));
if (!function_exists('gettext')) {
	function gettext($t) { return $t; }
}
require_once('restapi/framework.inc');
require_once('restapi/schema.inc');

function check($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

/* Every schema is well formed (the WebUI form element format) */
$types = array('text', 'number', 'secret', 'select', 'checklist', 'switch', 'segmented', 'textarea', 'address', 'port', 'typeahead',
    'entry-grid', 'file', 'datetime', 'color');
foreach (restapi_schemas() as $resource => $def) {
	check(count($def) === 3 && is_string($def[1]) && is_string($def[2]), "{$resource}: registry entry names a builder, a GUI page and an area");
	$s = restapi_schema_get($resource);
	check(is_array($s) && $s['resource'] === $resource && !empty($s['title']) && !empty($s['sections']), "{$resource}: builds with a title and sections");
	$names = array();
	foreach ($s['sections'] as $sec) {
		check(!empty($sec['id']), "{$resource}: every section has an id");
		foreach ($sec['fields'] as $f) {
			check(!empty($f['name']) && !empty($f['label']), "{$resource}: every field has a name and label");
			check(in_array($f['type'] ?? 'text', $types, true), "{$resource}.{$f['name']}: known field type");
			check(!isset($names[$f['name']]), "{$resource}.{$f['name']}: field names are unique");
			$names[$f['name']] = $f;
			if (($f['type'] ?? '') === 'entry-grid') {
				foreach ($f['fields'] as $c) {
					check(!empty($c['name']) && !empty($c['label']) && in_array($c['type'] ?? 'text', $types, true), "{$resource}.{$f['name']}: grid columns are well formed");
				}
			}
		}
	}
	foreach ($names as $f) {
		if (isset($f['visibleWhen']['field'])) {
			check(isset($names[$f['visibleWhen']['field']]), "{$resource}.{$f['name']}: visibleWhen refers to a field of the schema");
		}
	}
	foreach ((array)($s['order'] ?? array()) as $id) {
		check(in_array($id, array_column($s['sections'], 'id'), true), "{$resource}: order names existing sections");
	}
}

/* The alias schema matches the alias API exactly */
$alias = restapi_schema_get('firewall/aliases');
$fields = restapi_schema_fields($alias);
check(array_keys($fields) === array('name', 'type', 'description', 'entries', 'entries.*.address', 'entries.*.detail', 'update_frequency'),
    'alias schema fields are the API fields (name, type, description, entries{address, detail}, update_frequency)');
$type_values = array_column($alias['sections'][0]['fields'][1]['options'], 'value');
check($type_values === array('host', 'network', 'port', 'url', 'url_ports', 'urltable', 'urltable_ports'), 'alias types are the API enum');

/* Messages become field errors */
$body = array('name' => 'BAD NAME', 'entries' => array(array('address' => '10.0.0.1'), array('address' => '10.0.0.300', 'detail' => 'typo')));
$r = restapi_errors_to_fields(array(
	'The field "Name" is required.',
	'10.0.0.300 is not a valid host, network or alias.',
	'Reserved word used for alias name.',
	'The update frequency must be between 1 and 365 days.',
	'Something went wrong elsewhere.',
), $alias, $body);
check($r['fields']['entries.1.address'] === '10.0.0.300 is not a valid host, network or alias.', 'a message naming a grid value lands on that cell');
check($r['fields']['name'] === 'The field "Name" is required. Reserved word used for alias name.', 'quoted and plain label mentions land on the field; several are joined');
check($r['fields']['update_frequency'] === 'The update frequency must be between 1 and 365 days.', 'the longest matching label wins (update frequency, not "description")');
check($r['unmatched'] === array('Something went wrong elsewhere.'), 'unrelated messages stay for the error summary');
$r = restapi_errors_to_fields(array('ntp: x'), restapi_schema_get('services/ntp'), array('servers' => array(array('server' => 'x'))));
check($r['fields'] === array(), 'grid values shorter than 3 characters never match (too ambiguous)');

$ntp = restapi_schema_get('services/ntp');
$r = restapi_errors_to_fields(array('The supplied value for NTP Orphan Mode is invalid.',
    'NTP Time Server names must be valid domain names, IPv4 addresses, or IPv6 addresses'), $ntp, array('servers' => array(array('server' => 'no!'))));
check(isset($r['fields']['ntporphan'], $r['fields']['servers']) && $r['unmatched'] === array(),
    'errorMatch phrases map the GUI\'s NTP messages (as seen on the firewall) to their fields');

/* restapi_validation_error() keys messages by the route's schema */
restapi_request_context(array('schema' => 'firewall/aliases', 'body' => $body));
$e = restapi_validation_error(array('The field &quot;Name&quot; is <b>required</b>.', 'Nothing matches this.'));
$p = $e->payload();
check($e->status === 422 && $p['error']['details']['fields'] === array('name' => 'The field "Name" is required.') &&
    $p['error']['details']['messages'] === array('The field "Name" is required.', 'Nothing matches this.'), '422 carries fields and keeps all messages');
restapi_request_context(array('schema' => null, 'body' => array()));
$p = restapi_validation_error(array('x'))->payload();
check(!isset($p['error']['details']['fields']), 'routes without a schema keep the old shape');

/* Wiring */
$v1 = file_get_contents("{$root}/src/etc/inc/restapi/routes_v1.inc");
$fw = file_get_contents("{$root}/src/etc/inc/restapi/routes_firewall.inc");
$ntp = file_get_contents("{$root}/src/etc/inc/restapi/routes_ntp.inc");
$front = file_get_contents("{$root}/src/usr/local/www/api/index.php");
check(strpos($v1, "restapi_route('GET', '/v1/schema/{area}/{name}', 'restapi_h_schema'") !== false, 'GET /api/v1/schema/{area}/{name}');
check(substr_count($fw, "'schema' => 'firewall/aliases'") === 2 && strpos($ntp, "'schema' => 'services/ntp'") !== false, 'alias and NTP saves name their schema');
check(strpos($front, "restapi_request_context(array('schema' => \$route['schema'] ?? null, 'body' => \$req['body']));") !== false,
    'the front controller records the route schema and body');
check(strpos(file_get_contents("{$root}/src/etc/inc/restapi/schema.inc"), "restapi_authorize(array('user' => \$req['user'], 'token' => \$req['token'])") !== false,
    'reading a schema needs the privilege of its editor');

echo "REST API schema smoke test passed.\n";
