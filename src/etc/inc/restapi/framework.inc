<?php
/*
 * restapi/framework.inc
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
 * REST API building blocks with no dependency on the rest of the system:
 * routing, errors, token parsing, network checks and the OpenAPI document.
 * Everything that touches config.xml lives in restapi.inc.
 */

require_once('restapi/areas.inc');

define('RESTAPI_TOKEN_PREFIX', 'fsk');

class RestApiError extends Exception {
	public $status;
	public $error_code;
	public $details;

	public function __construct($status, $error_code, $message, $details = array()) {
		parent::__construct($message);
		$this->status = (int)$status;
		$this->error_code = $error_code;
		$this->details = $details;
	}

	public function payload() {
		$error = array('code' => $this->error_code, 'message' => $this->getMessage());
		if (!empty($this->details)) {
			$error['details'] = $this->details;
		}
		return array('error' => $error);
	}
}

/*
 * Turn the input_errors list a GUI save function returns into a 422. When
 * the route names a resource schema, details.fields maps each message to
 * the field it is about (schema.inc); details.messages keeps them all.
 */
function restapi_validation_error(array $input_errors) {
	$messages = array_values(array_map(function ($m) {
		return html_entity_decode(strip_tags((string)$m), ENT_QUOTES);
	}, $input_errors));
	$details = array('messages' => $messages);
	if (function_exists('restapi_request_context')) {
		$ctx = restapi_request_context();
		$schema = ($ctx['schema'] !== null) ? restapi_schema_get($ctx['schema']) : null;
		if (is_array($schema)) {
			$mapped = restapi_errors_to_fields($messages, $schema, is_array($ctx['body']) ? $ctx['body'] : array());
			if (!empty($mapped['fields'])) {
				$details['fields'] = $mapped['fields'];
			}
		}
	}
	return new RestApiError(422, 'validation_failed', 'The request failed validation.', $details);
}

/* ------------------------------------------------------------------ */
/* Routing                                                             */
/* ------------------------------------------------------------------ */

/*
 * Define a route. $path is a template such as "/v1/backup/{id}/test".
 * Options:
 *   page      GUI page whose privilege grants access (required); "@authenticated"
 *             means any valid key (and no area)
 *   area      permission area from restapi/areas.inc (required otherwise)
 *   write     true when the route changes state; its scope is <area>:write,
 *             otherwise <area>:read
 *   safe      true for a POST that changes nothing (e.g. a lookup that needs a
 *             body); it keeps the read scope (write must be false)
 *   summary   one line for the OpenAPI document
 *   tag       OpenAPI tag (defaults to the area)
 *   body      OpenAPI schema of the JSON body (array), optional
 *   query     array of query parameter name => description, optional
 *   produces  response media type, default application/json
 *   schema    resource schema whose fields key validation errors (schema.inc)
 */
function restapi_route($method, $path, $handler, array $opts) {
	if (empty($opts['page'])) {
		throw new InvalidArgumentException("route {$method} {$path} has no privilege page");
	}
	$area = $opts['area'] ?? '';
	if (($opts['page'] !== '@authenticated') && !array_key_exists($area, restapi_areas())) {
		throw new InvalidArgumentException("route {$method} {$path} has no valid permission area");
	}
	list($regex, $params) = restapi_compile_path($path);
	$write = !empty($opts['write']);
	return array(
		'method' => strtoupper($method),
		'path' => $path,
		'regex' => $regex,
		'params' => $params,
		'handler' => $handler,
		'page' => $opts['page'],
		'area' => $area,
		'write' => $write,
		'safe' => !$write && !empty($opts['safe']),
		'scope' => ($area === '') ? '' : restapi_scope($area, $write ? 'write' : 'read'),
		'summary' => $opts['summary'] ?? '',
		'tag' => $opts['tag'] ?? (($area === '') ? 'meta' : $area),
		'body' => $opts['body'] ?? null,
		'query' => $opts['query'] ?? array(),
		'produces' => $opts['produces'] ?? 'application/json',
		'schema' => $opts['schema'] ?? null,
	);
}

function restapi_compile_path($template) {
	preg_match_all('#\{([a-z_][a-z0-9_]*)\}#i', $template, $names);
	/* preg_quote() escapes the braces, so match the escaped placeholders. */
	$regex = preg_replace('#\\\\\{[a-z_][a-z0-9_]*\\\\\}#i', '([A-Za-z0-9._:%@+-]+)',
	    preg_quote($template, '#'));
	return array('#^' . $regex . '$#D', $names[1]);
}

/*
 * Find the route for a request. Returns array($route, $params).
 * Throws 404 for an unknown path and 405 for a known path with another method.
 */
function restapi_match(array $routes, $method, $path) {
	$allowed = array();
	foreach ($routes as $route) {
		if (!preg_match($route['regex'], $path, $m)) {
			continue;
		}
		if ($route['method'] !== strtoupper($method)) {
			$allowed[] = $route['method'];
			continue;
		}
		$params = array();
		foreach ($route['params'] as $i => $name) {
			/* Parameters may be URL-encoded (e.g. user%40example.org). Never allow
			 * a decoded slash or control character: some handlers build config
			 * paths from these values. */
			$value = rawurldecode($m[$i + 1]);
			if (($value === '') || preg_match('#[/\x00-\x1f\x7f]#', $value)) {
				throw new RestApiError(404, 'not_found', 'No such API endpoint.');
			}
			$params[$name] = $value;
		}
		return array($route, $params);
	}
	if (!empty($allowed)) {
		throw new RestApiError(405, 'method_not_allowed',
		    'Method not allowed. Allowed: ' . implode(', ', array_unique($allowed)) . '.');
	}
	throw new RestApiError(404, 'not_found', 'No such API endpoint.');
}

/* At most this many sub-requests in one POST /api/v1/batch. */
define('RESTAPI_BATCH_MAX', 20);

/*
 * Run the sub-requests of POST /api/v1/batch: up to RESTAPI_BATCH_MAX reads
 * in one PHP worker, so a WebUI page that polls several sources does not
 * occupy several php-fpm workers. Each sub-request is matched, authorized
 * with $authorize($route) and handled exactly as if it were sent alone;
 * one failing sub-request never fails the others.
 *
 *   $requests   [{id, method: 'GET', path: '/api/v1/status/x?if=wan'}]
 *   $routes     the route table (restapi_routes_v1())
 *   $authorize  callable($route): throws RestApiError when not allowed
 *   $base       the request array of the batch call (user, token, session)
 * Returns [{id, status, body}] where body is the usual {data} / {error}.
 */
/* The JSON body of a handler result: {data} plus {meta} when the handler sets it (paging cursors, totals). */
function restapi_result_body(array $result) {
	$body = array('data' => $result['data'] ?? null);
	if (isset($result['meta']) && is_array($result['meta'])) {
		$body['meta'] = $result['meta'];
	}
	return $body;
}

function restapi_batch_run($requests, array $routes, callable $authorize, array $base) {
	if (!is_array($requests) || !array_is_list($requests)) {
		throw new RestApiError(400, 'invalid_batch', 'requests must be a list of {id, method, path}.');
	}
	if (count($requests) > RESTAPI_BATCH_MAX) {
		throw new RestApiError(400, 'batch_too_large', 'A batch may hold at most ' . RESTAPI_BATCH_MAX . ' requests.');
	}
	$out = array();
	foreach ($requests as $i => $r) {
		$id = (is_array($r) && isset($r['id']) && is_scalar($r['id'])) ? (string)$r['id'] : (string)$i;
		try {
			if (!is_array($r) || !is_string($r['path'] ?? null)) {
				throw new RestApiError(400, 'invalid_batch', 'Each request needs a path.');
			}
			if (strtoupper((string)($r['method'] ?? 'GET')) !== 'GET') {
				throw new RestApiError(400, 'batch_read_only', 'A batch may only contain GET requests.');
			}
			$url = parse_url($r['path']);
			$path = preg_replace('#^/api(?=/)#', '', rtrim((string)($url['path'] ?? ''), '/'));
			$query = array();
			parse_str((string)($url['query'] ?? ''), $query);
			if (($path === '/v1/batch') || (strpos($path, '/v1/') !== 0)) {
				throw new RestApiError(400, 'invalid_batch', 'Only /api/v1 endpoints (other than batch) can be batched.');
			}
			list($route, $params) = restapi_match($routes, 'GET', $path);
			$authorize($route);
			$result = call_user_func($route['handler'], array_merge($base, array(
				'path' => $path, 'params' => $params, 'query' => $query, 'body' => array())));
			if (isset($result['raw'])) {
				throw new RestApiError(400, 'not_batchable', 'This endpoint returns raw data and cannot be batched.');
			}
			$out[] = array('id' => $id, 'status' => (int)($result['status'] ?? 200), 'body' => restapi_result_body($result));
		} catch (RestApiError $e) {
			$out[] = array('id' => $id, 'status' => $e->status, 'body' => $e->payload());
		} catch (Throwable $e) {
			$out[] = array('id' => $id, 'status' => 500, 'body' => array('error' => array('code' => 'internal_error', 'message' => 'Internal error; see the system log.')));
		}
	}
	return $out;
}

/* Decode a JSON request body; an empty body is an empty object. */
function restapi_decode_body($raw) {
	if (trim((string)$raw) === '') {
		return array();
	}
	$body = json_decode($raw, true);
	if (!is_array($body) || (array_is_list($body) && !empty($body))) {
		throw new RestApiError(400, 'invalid_json', 'The request body must be a JSON object.');
	}
	return $body;
}

/* ------------------------------------------------------------------ */
/* Tokens and networks                                                 */
/* ------------------------------------------------------------------ */

function restapi_generate_secret() {
	return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
}

function restapi_format_token($id, $secret) {
	return RESTAPI_TOKEN_PREFIX . "_{$id}_{$secret}";
}

/* Parse "Bearer fsk_<id>_<secret>". Returns array($id, $secret) or null. */
function restapi_parse_authorization($header) {
	/* The auth scheme is case-insensitive (RFC 9110); the token is not. */
	if (!preg_match('/^\s*(?i:bearer)\s+' . RESTAPI_TOKEN_PREFIX . '_([0-9a-f]{12})_([A-Za-z0-9_-]{43})\s*$/D',
	    (string)$header, $m)) {
		return null;
	}
	return array($m[1], $m[2]);
}

function restapi_hash_secret($secret) {
	return hash('sha256', $secret);
}

function restapi_verify_secret($secret, $stored_hash) {
	return is_string($stored_hash) && (strlen($stored_hash) === 64) &&
	    hash_equals($stored_hash, restapi_hash_secret($secret));
}

/* Parse a list of networks separated by spaces, commas or newlines. */
function restapi_parse_networks($text) {
	$out = array();
	foreach (preg_split('/[\s,]+/', trim((string)$text)) as $net) {
		if ($net !== '') {
			$out[] = $net;
		}
	}
	return $out;
}

function restapi_valid_network($net) {
	$parts = explode('/', $net, 2);
	$bin = @inet_pton($parts[0]);
	if ($bin === false) {
		return false;
	}
	if (!isset($parts[1])) {
		return true;
	}
	$max = strlen($bin) * 8;
	return ctype_digit($parts[1]) && ((int)$parts[1] <= $max);
}

/* True when $ip is inside $net (address or CIDR, IPv4 or IPv6). */
function restapi_ip_in_network($ip, $net) {
	$ip_bin = @inet_pton($ip);
	$parts = explode('/', $net, 2);
	$net_bin = @inet_pton($parts[0]);
	if ($ip_bin === false || $net_bin === false || strlen($ip_bin) !== strlen($net_bin)) {
		return false;
	}
	$bits = isset($parts[1]) ? (int)$parts[1] : strlen($net_bin) * 8;
	$bytes = intdiv($bits, 8);
	if (substr($ip_bin, 0, $bytes) !== substr($net_bin, 0, $bytes)) {
		return false;
	}
	$rest = $bits % 8;
	if ($rest === 0) {
		return true;
	}
	$mask = (0xff << (8 - $rest)) & 0xff;
	return (ord($ip_bin[$bytes]) & $mask) === (ord($net_bin[$bytes]) & $mask);
}

/* An empty list allows every address. */
function restapi_ip_allowed($ip, array $networks) {
	if (empty($networks)) {
		return true;
	}
	foreach ($networks as $net) {
		if (restapi_ip_in_network($ip, $net)) {
			return true;
		}
	}
	return false;
}

/* ------------------------------------------------------------------ */
/* OpenAPI                                                             */
/* ------------------------------------------------------------------ */

function restapi_openapi(array $routes, $version) {
	$error_ref = array('$ref' => '#/components/responses/Error');
	$paths = array();
	foreach ($routes as $route) {
		$op = array(
			'summary' => $route['summary'],
			'tags' => array($route['tag']),
			'x-freesense-privilege-page' => $route['page'],
			'x-freesense-scope' => $route['scope'],
			'responses' => array(
				'200' => array('description' => 'Success', 'content' => array(
				    $route['produces'] => array('schema' => ($route['produces'] === 'application/json') ?
				    array('type' => 'object', 'properties' => array('data' => new stdClass())) :
				    array('type' => 'string')))),
				'401' => $error_ref,
				'403' => $error_ref,
			),
		);
		if ($route['write']) {
			$op['responses']['412'] = $error_ref;
			$op['responses']['422'] = $error_ref;
		}
		$parameters = array();
		foreach ($route['params'] as $name) {
			$parameters[] = array('name' => $name, 'in' => 'path', 'required' => true,
			    'schema' => array('type' => 'string'));
		}
		foreach ($route['query'] as $name => $description) {
			$parameters[] = array('name' => $name, 'in' => 'query', 'required' => false,
			    'description' => $description, 'schema' => array('type' => 'string'));
		}
		if (!empty($parameters)) {
			$op['parameters'] = $parameters;
		}
		if (is_array($route['body'])) {
			$op['requestBody'] = array('required' => false, 'content' => array(
			    'application/json' => array('schema' => $route['body'])));
		}
		$paths['/api' . $route['path']][strtolower($route['method'])] = $op;
	}
	ksort($paths);

	return array(
		'openapi' => '3.0.3',
		'info' => array(
			'title' => 'FreeSense REST API',
			'version' => $version,
			'description' => 'Each endpoint needs the scope in x-freesense-scope: the key owner holds it by being allowed to ' .
			    'open the GUI page in x-freesense-privilege-page, or by the matching "REST API - <area>" privilege, ' .
			    'and a key limited to scopes must include it (a write scope includes read). ' .
			    'Changes are validated by the same code as the GUI.',
		),
		'servers' => array(array('url' => '/')),
		'security' => array(array('bearerAuth' => array())),
		'paths' => $paths,
		'components' => array(
			'securitySchemes' => array('bearerAuth' => array('type' => 'http', 'scheme' => 'bearer',
			    'bearerFormat' => RESTAPI_TOKEN_PREFIX . '_<id>_<secret>')),
			'responses' => array('Error' => array('description' => 'Error', 'content' => array(
			    'application/json' => array('schema' => array('type' => 'object', 'properties' => array(
			    'error' => array('type' => 'object', 'properties' => array(
			    'code' => array('type' => 'string'),
			    'message' => array('type' => 'string'),
			    'details' => array('type' => 'object'))))))))),
		),
	);
}
