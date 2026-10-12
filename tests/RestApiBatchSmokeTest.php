<?php
/* Standalone CI regression test for POST /api/v1/batch; run with `php tests/RestApiBatchSmokeTest.php`. */

$root = dirname(__DIR__);
set_include_path(get_include_path() . PATH_SEPARATOR . realpath($root . '/src/etc/inc'));
require_once('restapi/framework.inc');

function check($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

function h_gateways($req) { return array('data' => array(array('name' => 'WAN_DHCP', 'user' => $req['user']['name']))); }
function h_iface($req) { return array('data' => array('id' => $req['params']['id'], 'range' => $req['query']['range'] ?? null)); }
function h_secret($req) { return array('data' => 'secret'); }
function h_raw($req) { return array('raw' => 'x', 'type' => 'text/plain'); }
function h_boom($req) { throw new RuntimeException('database exploded'); }
function h_bad($req) { throw new RestApiError(422, 'validation_failed', 'nope', array('fields' => array('a' => 'b'))); }

$routes = array(
	restapi_route('GET', '/v1/status/gateways', 'h_gateways', array('page' => '@authenticated')),
	restapi_route('GET', '/v1/status/interfaces/{id}', 'h_iface', array('page' => '@authenticated')),
	restapi_route('GET', '/v1/secret', 'h_secret', array('page' => 'secret.php', 'area' => 'status')),
	restapi_route('GET', '/v1/raw', 'h_raw', array('page' => '@authenticated')),
	restapi_route('GET', '/v1/boom', 'h_boom', array('page' => '@authenticated')),
	restapi_route('GET', '/v1/bad', 'h_bad', array('page' => '@authenticated')),
	restapi_route('POST', '/v1/things', 'h_gateways', array('page' => '@authenticated', 'write' => true)),
);
$authorized = array();
$authorize = function ($route) use (&$authorized) {
	$authorized[] = $route['path'];
	if ($route['page'] === 'secret.php') {
		throw new RestApiError(403, 'forbidden', 'not for you');
	}
};
$base = array('user' => array('name' => 'alice'), 'token' => array('id' => 'session'), 'session' => true);
$run = function ($requests) use ($routes, $authorize, $base) { return restapi_batch_run($requests, $routes, $authorize, $base); };
$status = function (callable $f) { try { $f(); } catch (RestApiError $e) { return $e->status; } return null; };

$out = $run(array(
	array('id' => 'gw', 'method' => 'GET', 'path' => '/api/v1/status/gateways'),
	array('id' => 'if', 'path' => '/v1/status/interfaces/wan?range=1h'),
	array('id' => 'sec', 'method' => 'GET', 'path' => '/api/v1/secret'),
	array('id' => 'nf', 'method' => 'GET', 'path' => '/api/v1/nope'),
	array('id' => 'raw', 'method' => 'GET', 'path' => '/api/v1/raw'),
	array('id' => 'boom', 'method' => 'GET', 'path' => '/api/v1/boom'),
	array('id' => 'bad', 'method' => 'GET', 'path' => '/api/v1/bad'),
	array('id' => 'post', 'method' => 'POST', 'path' => '/api/v1/things'),
	array('id' => 'nest', 'method' => 'GET', 'path' => '/api/v1/batch'),
	array('id' => 'other', 'method' => 'GET', 'path' => '/index.php'),
	'garbage',
));
$by = array();
foreach ($out as $r) {
	$by[$r['id']] = $r;
}
check(count($out) === 11, 'every sub-request gets an answer, in order');
check($by['gw']['status'] === 200 && $by['gw']['body']['data'][0]['user'] === 'alice', 'a sub-request runs as the batch caller');
check($by['if']['body']['data'] === array('id' => 'wan', 'range' => '1h'), 'path parameters and query strings reach the handler; /v1 and /api/v1 both work');
check($by['sec']['status'] === 403 && $by['sec']['body']['error']['code'] === 'forbidden', 'each sub-request is authorized on its own');
check($by['nf']['status'] === 404, 'an unknown path is 404 for that entry only');
check($by['raw']['status'] === 400 && $by['raw']['body']['error']['code'] === 'not_batchable', 'raw (non-JSON) endpoints are refused');
check($by['boom']['status'] === 500 && strpos(json_encode($by['boom']), 'exploded') === false, 'an internal error does not leak its message');
check($by['bad']['status'] === 422 && $by['bad']['body']['error']['details']['fields'] === array('a' => 'b'), 'error details (field errors) are kept');
check($by['post']['status'] === 400 && $by['post']['body']['error']['code'] === 'batch_read_only', 'only GET requests may be batched');
check($by['nest']['status'] === 400 && $by['other']['status'] === 400, 'batches cannot nest and only /api/v1 endpoints are allowed');
check($by['10']['status'] === 400, 'a malformed entry is answered with its index as id');
check(!in_array('/v1/things', $authorized, true), 'a refused POST is never authorized or run');

check($status(function () use ($run) { $run(array_fill(0, RESTAPI_BATCH_MAX + 1, array('path' => '/api/v1/status/gateways'))); }) === 400,
    'more than RESTAPI_BATCH_MAX requests is refused');
check($status(function () use ($run) { $run(array('a' => array('path' => '/api/v1/status/gateways'))); }) === 400 &&
    $status(function () use ($run) { $run(null); }) === 400, 'requests must be a list');

$routes_src = file_get_contents("{$root}/src/etc/inc/restapi/routes_v1.inc");
check(strpos($routes_src, "restapi_route('POST', '/v1/batch', 'restapi_h_batch'") !== false &&
    strpos($routes_src, 'function ($route) use ($ctx) { restapi_authorize($ctx, $route); }') !== false,
    'POST /api/v1/batch authorizes sub-requests with the caller\'s user and key');
check(strpos(file_get_contents("{$root}/src/etc/inc/restapi.inc"), "return array('session', 'batch',") !== false, 'meta reports the batch capability');

echo "REST API batch smoke test passed.\n";
