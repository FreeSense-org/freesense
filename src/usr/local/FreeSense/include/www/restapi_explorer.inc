<?php
/*
 * restapi_explorer.inc
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
 * System > REST API > API Explorer: the endpoint reference shown on the page,
 * built from the live route table (restapi_routes_v1(), including package
 * routes), so it always matches the installed code.
 *
 * The model functions are pure (no config, session or file access); the
 * page passes in the areas, the privilege names and a callback that says
 * whether the signed-in user could call a route. The explorer adds no
 * server-side capability: "Try it" runs in the browser against the public
 * API with the key the user pastes, which is never sent to this page.
 */

/* sessionStorage name of the key, only used when the user ticks "remember for this tab". */
define('RESTAPI_EXPLORER_STORAGE_KEY', 'freesense.restapi.explorer.key');

/*
 * Flags of a route the explorer surfaces:
 *   kind         read | write (a "safe" POST is a read)
 *   confirm      always (the body requires "confirm": true), conditional
 *                (some changes need it, e.g. admin access lock-out risks) or ''
 *   admin        only administrators (admin user or "WebCfg - All pages")
 *   apply        staged (?apply=true applies at once), applies (applies the
 *                pending changes of the area), direct (no staging; applies
 *                like the GUI page) or '' for reads
 *   destructive  a disruptive or destructive operation (delete, reboot, halt,
 *                update, restore, state reset, ...)
 */
function restapi_explorer_flags(array $route) {
	$body = is_array($route['body'] ?? null) ? $route['body'] : null;
	$summary = (string)($route['summary'] ?? '');
	$write = !empty($route['write']);
	$path = (string)($route['path'] ?? '');

	$confirm = '';
	if ($body !== null && in_array('confirm', (array)($body['required'] ?? array()), true)) {
		$confirm = 'always';
	} elseif (($body !== null && (isset($body['properties']['confirm']) ||
	    preg_match('/(?<![a-z_])confirm(?![a-z_])/i', json_encode($body)))) ||
	    stripos($summary, '"confirm"') !== false) {
		$confirm = 'conditional';
	}

	$apply = '';
	if ($write) {
		if (isset($route['query']['apply'])) {
			$apply = 'staged';
		} elseif (preg_match('#/apply$#', $path)) {
			$apply = 'applies';
		} else {
			$apply = 'direct';
		}
	}

	$admin = (bool)preg_match('/\ban administrator\b/i', $summary);
	$destructive = $write && (($route['method'] ?? '') === 'DELETE' || $confirm === 'always' || $admin ||
	    preg_match('#/(reboot|halt|firmware|restore|reset|kill)(/|$)#', $path));

	return array(
		'kind' => $write ? 'write' : 'read',
		'confirm' => $confirm,
		'admin' => $admin,
		'apply' => $apply,
		'destructive' => (bool)$destructive,
	);
}

/*
 * An example JSON value for a schema: every property with a neutral value,
 * the first enum entry, and "confirm" always false (the user has to set it to
 * true on purpose). Returns null when the schema says nothing usable.
 */
function restapi_explorer_example($schema, $depth = 0) {
	if (!is_array($schema) || $depth > 6) {
		return null;
	}
	if (isset($schema['example'])) {
		return $schema['example'];
	}
	if (!empty($schema['enum']) && is_array($schema['enum'])) {
		return array_values($schema['enum'])[0];
	}
	$type = $schema['type'] ?? (isset($schema['properties']) ? 'object' : null);
	switch ($type) {
	case 'object':
		$out = new stdClass();
		foreach ((array)($schema['properties'] ?? array()) as $name => $prop) {
			$value = ($name === 'confirm') ? false : restapi_explorer_example($prop, $depth + 1);
			$out->{$name} = $value ?? '';
		}
		return $out;
	case 'array':
		$item = restapi_explorer_example($schema['items'] ?? null, $depth + 1);
		return ($item === null) ? array() : array($item);
	case 'boolean':
		return false;
	case 'integer':
	case 'number':
		return 0;
	case 'string':
		return '';
	}
	return null;
}

/* Area labels in display order: the known areas, then any other area a package route uses. */
function restapi_explorer_area_label($area, array $areas) {
	if ($area === '') {
		return gettext('General (any valid API key)');
	}
	return $areas[$area] ?? $area;
}

/*
 * The explorer model.
 *   $routes     restapi_routes_v1()
 *   $areas      restapi_areas()
 *   $privnames  callable($route) => array of privilege names that grant the route
 *               (GUI page privileges and the area privilege), or null
 *   $access     callable($route, $flags) => '' when the signed-in user could call
 *               the route with a key of their own, else the reason; or null
 * Returns array('areas' => [area => [id, label, endpoints]], 'count' => n).
 */
function restapi_explorer_model(array $routes, array $areas, $privnames = null, $access = null) {
	$groups = array();
	foreach (array_keys($areas) as $area) {
		$groups[$area] = array();
	}
	$i = 0;
	foreach ($routes as $route) {
		if (!is_array($route) || !isset($route['method'], $route['path'])) {
			continue;
		}
		$flags = restapi_explorer_flags($route);
		$area = (string)($route['area'] ?? '');
		$body = is_array($route['body'] ?? null) ? $route['body'] : null;
		$query = array();
		foreach ((array)($route['query'] ?? array()) as $name => $descr) {
			$query[] = array('name' => (string)$name, 'description' => (string)$descr);
		}
		$reason = is_callable($access) ? (string)$access($route, $flags) : '';
		$example = ($body !== null) ? restapi_explorer_example($body) : null;
		$groups[$area][] = array(
			'id' => 'ep' . $i++,
			'method' => (string)$route['method'],
			'path' => (string)$route['path'],
			'url' => '/api' . $route['path'],
			'summary' => (string)($route['summary'] ?? ''),
			'area' => $area,
			'scope' => (string)($route['scope'] ?? ''),
			'page' => (string)($route['page'] ?? ''),
			'privileges' => is_callable($privnames) ? array_values((array)$privnames($route)) : array(),
			'params' => array_values(array_map('strval', (array)($route['params'] ?? array()))),
			'query' => $query,
			'body' => $body,
			'example' => ($example === null) ? null : json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
			'produces' => (string)($route['produces'] ?? 'application/json'),
			'flags' => $flags,
			'callable' => ($reason === ''),
			'reason' => $reason,
		);
	}

	$out = array();
	$count = 0;
	foreach ($groups as $area => $endpoints) {
		if (empty($endpoints)) {
			continue;
		}
		$area = (string)$area;
		$out[] = array(
			'id' => ($area === '') ? 'meta' : $area,
			'label' => restapi_explorer_area_label($area, $areas),
			'endpoints' => $endpoints,
		);
		$count += count($endpoints);
	}
	/* The "any valid key" endpoints (/v1/me, openapi.json) first. */
	usort($out, function ($a, $b) {
		return ($b['id'] === 'meta') <=> ($a['id'] === 'meta');
	});
	return array('areas' => $out, 'count' => $count);
}

/* JSON safe to embed in a <script type="application/json"> element (no "<", ">", "&" or quotes in the text). */
function restapi_explorer_json($data) {
	return json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT |
	    JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
}

/*
 * The page's access callback for $viewer (the signed-in local user, or null).
 * Mirrors the API's own checks (restapi_authenticate()/restapi_authorize() and
 * the operations' administrator check) for a key without scope limits.
 */
function restapi_explorer_access_for($viewer) {
	if (!is_array($viewer)) {
		return function ($route, $flags) {
			return gettext('API keys are available to local users only.');
		};
	}
	$has_access = restapi_user_has_access($viewer);
	$readonly = userHasPrivilege($viewer, 'user-config-readonly');
	$is_admin = restapi_ops_is_admin($viewer, 'userHasPrivilege');
	return function ($route, $flags) use ($viewer, $has_access, $readonly, $is_admin) {
		if (!$has_access) {
			return gettext('You do not have REST API access ("WebCfg - System: REST API access").');
		}
		if (!restapi_user_holds_route($viewer, $route)) {
			return sprintf(gettext('You lack the %1$s scope: it needs GUI access to %2$s or the "%3$s" privilege.'),
			    $route['scope'], $route['page'], restapi_area_privilege($route['area'], $route['write'] ? 'write' : 'read'));
		}
		if (!empty($route['write']) && $readonly) {
			return gettext('Your account may not change the configuration.');
		}
		if (!empty($flags['admin']) && !$is_admin) {
			return gettext('Only administrators (the admin user or "WebCfg - All pages") may call this.');
		}
		return '';
	};
}

/* Privilege names that grant a route: the GUI page's privileges (from $priv_list) and the area privilege. */
function restapi_explorer_privnames_for(array $priv_list) {
	$cache = array();
	return function ($route) use ($priv_list, &$cache) {
		if (($route['page'] ?? '') === '@authenticated') {
			return array();
		}
		$page = (string)$route['page'];
		if (!isset($cache[$page])) {
			$names = array();
			foreach ($priv_list as $priv) {
				foreach ((array)($priv['match'] ?? array()) as $match) {
					if (fnmatch($match, $page)) {
						$names[] = (string)$priv['name'];
						break;
					}
				}
			}
			$cache[$page] = $names;
		}
		$area_priv = restapi_area_privilege($route['area'], !empty($route['write']) ? 'write' : 'read');
		$names = $cache[$page];
		$names[] = $priv_list[$area_priv]['name'] ?? $area_priv;
		return $names;
	};
}
