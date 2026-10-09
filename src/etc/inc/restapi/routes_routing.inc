<?php
/*
 * restapi/routes_routing.inc
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
 * REST API v1 routing routes: gateways, the default gateways, gateway
 * groups and static routes.
 *
 * Writes go through the functions the System > Routing pages call
 * (validate_gateway()/save_gateway() in gwlb.inc and the routing_*()
 * functions in system_routing.inc), so validation and in-use checks are
 * the GUI's. Like the GUI, every change is staged (the "staticroutes"
 * dirty flag) until POST /v1/routing/apply; add ?apply=true to a write to
 * apply at once.
 *
 * Gateways and gateway groups are addressed by name, static routes by
 * their position in config.xml.
 *
 * For the WebUI every gateway, gateway group and static route carries
 * "fields" (the edit form's fields, which create and update take) and
 * "display" (the list page's columns as plain text); the schemas
 * routing/gateways, routing/gateway_groups, routing/static_routes and
 * routing/default_gateways (restapi/schemas/routing.inc) key the save
 * functions' messages to those fields.
 *
 * The front controller loads system_routing.inc at file scope.
 */

require_once('restapi/framework.inc');

function restapi_routes_routing() {
	$apply_query = array('apply' => 'true to apply the change at once (default: stage it like the GUI)');
	$gw_body = array('type' => 'object', 'description' => 'The same fields as the gateway edit page: ' .
	    'interface, ipprotocol (inet|inet6), name, gateway (address or "dynamic"), descr, disabled, monitor_disable, ' .
	    'action_disable, monitor, dpinger_dont_add_static_route, force_down, gw_down_kill_states, weight, ' .
	    'data_payload, latencylow, latencyhigh, losslow, losshigh, interval, loss_interval, time_period, ' .
	    'alert_interval, nonlocalgateway. Checkboxes are booleans. GET returns them as "fields". ' .
	    'The name of an existing gateway cannot change.');
	$group_body = array('type' => 'object', 'properties' => array(
		'name' => array('type' => 'string'),
		'descr' => array('type' => 'string'),
		'trigger' => array('type' => 'string', 'enum' => array('down', 'downloss', 'downlatency', 'downlosslatency')),
		'keep_failover_states' => array('type' => 'string', 'enum' => array('', 'keep', 'kill')),
		'items' => array('type' => 'array', 'items' => array('type' => 'object', 'properties' => array(
		    'gateway' => array('type' => 'string'),
		    'tier' => array('type' => 'integer', 'description' => '1 (highest priority) to 5'),
		    'vip' => array('type' => 'string', 'description' => 'Virtual IP for local endpoints, optional')))),
	));
	$route_body = array('type' => 'object', 'description' => 'The same fields as the static route edit page: ' .
	    'network (address or alias; "address/bits" is also accepted), network_subnet (bits), gateway (name), ' .
	    'descr, disabled (boolean). GET returns them as "fields".');

	return array(
		restapi_route('POST', '/v1/routing/apply', 'restapi_h_routing_apply', array(
			'page' => 'system_gateways.php', 'area' => 'routing', 'write' => true,
			'summary' => 'Apply pending gateway, gateway group and static route changes')),

		/* Gateways */
		restapi_route('GET', '/v1/routing/gateways', 'restapi_h_gateway_list', array(
			'page' => 'system_gateways.php', 'area' => 'routing',
			'summary' => 'List gateways (with "fields": the edit form fields, and "display": the list columns as text and the monitoring status)')),
		restapi_route('GET', '/v1/routing/gateways/{name}', 'restapi_h_gateway_get', array(
			'page' => 'system_gateways.php', 'area' => 'routing', 'summary' => 'Get a gateway (with "fields" and "display")')),
		restapi_route('POST', '/v1/routing/gateways', 'restapi_h_gateway_create', array(
			'page' => 'system_gateways_edit.php', 'area' => 'routing', 'write' => true, 'schema' => 'routing/gateways',
			'body' => $gw_body, 'query' => $apply_query, 'summary' => 'Create a gateway')),
		restapi_route('PUT', '/v1/routing/gateways/{name}', 'restapi_h_gateway_update', array(
			'page' => 'system_gateways_edit.php', 'area' => 'routing', 'write' => true, 'schema' => 'routing/gateways',
			'body' => $gw_body, 'query' => $apply_query,
			'summary' => 'Update a gateway (omitted fields keep their value; false clears a checkbox)')),
		restapi_route('DELETE', '/v1/routing/gateways/{name}', 'restapi_h_gateway_delete', array(
			'page' => 'system_gateways.php', 'area' => 'routing', 'write' => true, 'query' => $apply_query,
			'summary' => 'Delete a gateway that no group, static route or DNS server uses')),
		restapi_route('POST', '/v1/routing/gateways/{name}/toggle', 'restapi_h_gateway_toggle', array(
			'page' => 'system_gateways.php', 'area' => 'routing', 'write' => true, 'query' => $apply_query,
			'summary' => 'Enable or disable a gateway (disabling is refused while it is in use)')),
		restapi_route('GET', '/v1/routing/default-gateways', 'restapi_h_default_gateways_get', array(
			'page' => 'system_gateways.php', 'area' => 'routing',
			'summary' => 'The IPv4 and IPv6 default gateway selection and the choices')),
		restapi_route('PUT', '/v1/routing/default-gateways', 'restapi_h_default_gateways_set', array(
			'page' => 'system_gateways.php', 'area' => 'routing', 'write' => true, 'query' => $apply_query,
			'schema' => 'routing/default_gateways',
			'body' => array('type' => 'object', 'properties' => array(
			    'defaultgw4' => array('type' => 'string', 'description' => 'Gateway or group name, "" (automatic) or "-" (none)'),
			    'defaultgw6' => array('type' => 'string', 'description' => 'Gateway or group name, "" (automatic) or "-" (none)'))),
			'summary' => 'Set the default gateways (omitted fields keep their value)')),

		/* Gateway groups */
		restapi_route('GET', '/v1/routing/gateway-groups', 'restapi_h_gwgroup_list', array(
			'page' => 'system_gateway_groups.php', 'area' => 'routing',
			'summary' => 'List gateway groups (with "fields": the editor fields, and "display": the list columns as text)')),
		restapi_route('GET', '/v1/routing/gateway-groups/{name}', 'restapi_h_gwgroup_get', array(
			'page' => 'system_gateway_groups.php', 'area' => 'routing', 'summary' => 'Get a gateway group (with "fields" and "display")')),
		restapi_route('POST', '/v1/routing/gateway-groups', 'restapi_h_gwgroup_create', array(
			'page' => 'system_gateway_groups_edit.php', 'area' => 'routing', 'write' => true, 'schema' => 'routing/gateway_groups',
			'body' => $group_body, 'query' => $apply_query, 'summary' => 'Create a gateway group')),
		restapi_route('PUT', '/v1/routing/gateway-groups/{name}', 'restapi_h_gwgroup_update', array(
			'page' => 'system_gateway_groups_edit.php', 'area' => 'routing', 'write' => true, 'schema' => 'routing/gateway_groups',
			'body' => $group_body, 'query' => $apply_query,
			'summary' => 'Update a gateway group (omitted fields keep their value; "items" replaces the members)')),
		restapi_route('DELETE', '/v1/routing/gateway-groups/{name}', 'restapi_h_gwgroup_delete', array(
			'page' => 'system_gateway_groups.php', 'area' => 'routing', 'write' => true, 'query' => $apply_query,
			'summary' => 'Delete a gateway group that is not a default gateway and nothing references')),

		/* Static routes */
		restapi_route('GET', '/v1/routing/static-routes', 'restapi_h_sroute_list', array(
			'page' => 'system_routes.php', 'area' => 'routing',
			'summary' => 'List static routes (with "fields": the edit form fields, and "display": the list columns as text)')),
		restapi_route('GET', '/v1/routing/static-routes/{id}', 'restapi_h_sroute_get', array(
			'page' => 'system_routes.php', 'area' => 'routing', 'summary' => 'Get a static route (with "fields" and "display")')),
		restapi_route('POST', '/v1/routing/static-routes', 'restapi_h_sroute_create', array(
			'page' => 'system_routes_edit.php', 'area' => 'routing', 'write' => true, 'schema' => 'routing/static_routes',
			'body' => $route_body, 'query' => $apply_query, 'summary' => 'Create a static route')),
		restapi_route('PUT', '/v1/routing/static-routes/{id}', 'restapi_h_sroute_update', array(
			'page' => 'system_routes_edit.php', 'area' => 'routing', 'write' => true, 'schema' => 'routing/static_routes',
			'body' => $route_body, 'query' => $apply_query,
			'summary' => 'Update a static route (omitted fields keep their value; false clears a checkbox)')),
		restapi_route('DELETE', '/v1/routing/static-routes/{id}', 'restapi_h_sroute_delete', array(
			'page' => 'system_routes.php', 'area' => 'routing', 'write' => true, 'query' => $apply_query,
			'summary' => 'Delete a static route')),
		restapi_route('POST', '/v1/routing/static-routes/{id}/toggle', 'restapi_h_sroute_toggle', array(
			'page' => 'system_routes.php', 'area' => 'routing', 'write' => true, 'query' => $apply_query,
			'summary' => 'Enable or disable a static route')),
	);
}

/* ------------------------------------------------------------------ */
/* Pure helpers (no config access)                                     */
/* ------------------------------------------------------------------ */

/*
 * The gateway edit form's fields for an entry of get_gateways(GW_CACHE_INDEXED),
 * preselected the way system_gateways_edit.php does: the interface select
 * shows the friendly interface name, a dynamic gateway shows "dynamic", a
 * monitor IP equal to the gateway shows empty, checkboxes are "yes" when
 * ticked and absent otherwise.
 */
function restapi_routing_gateway_fields(array $gw) {
	$post = array();
	foreach (array('name', 'weight', 'interval', 'loss_interval', 'alert_interval', 'time_period',
	    'ipprotocol', 'latencylow', 'latencyhigh', 'losslow', 'losshigh', 'gw_down_kill_states',
	    'data_payload', 'descr') as $field) {
		if (isset($gw[$field]) && is_scalar($gw[$field])) {
			$post[$field] = (string)$gw[$field];
		}
	}
	$post['interface'] = (string)($gw['friendlyiface'] ?? '');
	$post['friendlyiface'] = (string)($gw['friendlyiface'] ?? '');
	$gateway = (string)($gw['gateway'] ?? '');
	$post['gateway'] = !empty($gw['dynamic']) ? 'dynamic' : $gateway;
	$monitor = (string)($gw['monitor'] ?? '');
	$post['monitor'] = ($gateway == $monitor) ? '' : $monitor;
	foreach (array('force_down', 'dpinger_dont_add_static_route', 'monitor_disable', 'action_disable',
	    'nonlocalgateway', 'disabled') as $flag) {
		if (isset($gw[$flag]) && ($gw[$flag] !== false)) {
			$post[$flag] = 'yes';
		}
	}
	$attribute = $gw['attribute'] ?? null;
	if (($attribute === 'system') || is_numeric($attribute)) {
		$post['attribute'] = (string)$attribute;
	}
	return $post;
}

/* A stored gateway group ("gateway|tier|vip" members) in API shape. */
function restapi_routing_group_out(array $group) {
	$items = array();
	foreach ((array)($group['item'] ?? array()) as $item) {
		$parts = explode('|', (string)$item);
		$items[] = array('gateway' => $parts[0], 'tier' => (int)($parts[1] ?? 0), 'vip' => (string)($parts[2] ?? ''));
	}
	return array(
		'name' => (string)($group['name'] ?? ''),
		'descr' => (string)($group['descr'] ?? ''),
		'trigger' => (string)($group['trigger'] ?? ''),
		'keep_failover_states' => (string)($group['keep_failover_states'] ?? ''),
		'items' => $items,
	);
}

/*
 * A gateway group's editor fields (schema routing/gateway_groups, also a
 * valid create/update body): the members as {gateway, tier, vip} rows
 * with the tier as the select's string and "address" (the interface
 * address, the edit page's first choice) for a member without a VIP.
 */
function restapi_routing_group_fields(array $group) {
	$out = restapi_routing_group_out($group);
	foreach ($out['items'] as &$item) {
		$item['tier'] = (string)$item['tier'];
		if ($item['vip'] === '') {
			$item['vip'] = 'address';
		}
	}
	unset($item);
	return $out;
}

/*
 * A gateway group's list columns as text (system_gateway_groups.php): the
 * members that exist ("WAN_DHCP (tier 1)", in stored order), the trigger's
 * label and the description. $gateways: names of the gateways the page
 * knows (get_gateways()); $triggers: routing_gateway_group_triggers().
 */
function restapi_routing_group_display(array $group, array $gateways, array $triggers) {
	$members = array();
	foreach ((array)($group['item'] ?? array()) as $item) {
		$parts = explode('|', (string)$item);
		if (in_array($parts[0], $gateways, true)) {
			$members[] = sprintf(gettext('%1$s (tier %2$s)'), $parts[0], $parts[1] ?? '');
		}
	}
	$trigger = (string)($group['trigger'] ?? '');
	return array(
		'members' => $members,
		'trigger' => (string)($triggers[$trigger] ?? $trigger),
		'description' => (string)($group['descr'] ?? ''),
	);
}

/*
 * A gateway's list columns as text (system_gateways.php) and its
 * monitoring status. $gw is an entry of get_gateways(); $status is
 * return_gateways_status(true) (by name). A gateway dpinger does not
 * report on has state "unknown" and no delay or loss.
 */
function restapi_routing_gateway_display(array $gw, array $status) {
	$st = $status[$gw['name'] ?? ''] ?? null;
	return array(
		'interface' => (string)($gw['friendlyifdescr'] ?? ($gw['friendlyiface'] ?? '')),
		'address' => !empty($gw['dynamic']) ? 'dynamic' : (string)($gw['gateway'] ?? ''),
		'monitor' => (string)($gw['monitor'] ?? ''),
		'family' => (($gw['ipprotocol'] ?? '') === 'inet6') ? 'IPv6' : 'IPv4',
		'default' => isset($gw['isdefaultgw']),
		'enabled' => !(isset($gw['disabled']) && ($gw['disabled'] !== false)),
		'inactive' => isset($gw['inactive']),
		'description' => (string)($gw['descr'] ?? ''),
		'status' => array(
			'state' => is_array($st) ? (string)($st['status'] ?? 'unknown') : 'unknown',
			'substate' => is_array($st) ? (string)($st['substatus'] ?? '') : '',
			'delay' => is_array($st) ? (string)($st['delay'] ?? '') : '',
			'loss' => is_array($st) ? (string)($st['loss'] ?? '') : '',
		),
	);
}

/*
 * A static route's list columns as text (system_routes.php): the network
 * in lower case, the gateway as "name - address", the gateway's interface
 * and whether the route is enabled. $gateways is get_gateways(GW_CACHE_ALL);
 * $ifdescr turns a friendly interface name into its description.
 */
function restapi_routing_route_display(array $route, array $gateways, callable $ifdescr) {
	$gw = $gateways[$route['gateway'] ?? ''] ?? null;
	return array(
		'network' => strtolower((string)($route['network'] ?? '')),
		'gateway' => is_array($gw) ? ($gw['name'] . ' - ' . ($gw['gateway'] ?? '')) : (string)($route['gateway'] ?? ''),
		'interface' => (is_array($gw) && isset($gw['friendlyiface'])) ? (string)$ifdescr($gw['friendlyiface']) : '',
		'enabled' => !(isset($route['disabled']) && ($route['disabled'] !== false)),
		'inactive' => is_array($gw) && isset($gw['inactive']),
		'description' => (string)($route['descr'] ?? ''),
	);
}

/* A request body without the read-only parts of a GET (so a GET result can be sent back). */
function restapi_routing_body(array $body) {
	unset($body['fields'], $body['display']);
	return $body;
}

/*
 * An API gateway group body to the edit form's fields: one tier select
 * named after each member gateway and "<gateway>_vip". $gateways lists the
 * gateway names the form offers (enabled gateways).
 */
function restapi_routing_group_post(array $body, array $gateways) {
	$post = array();
	foreach (array('name', 'descr', 'trigger', 'keep_failover_states') as $field) {
		if (isset($body[$field])) {
			if (!is_scalar($body[$field])) {
				throw new RestApiError(400, 'invalid_field', "Field \"{$field}\" must be a string.");
			}
			$post[$field] = (string)$body[$field];
		}
	}
	$items = $body['items'] ?? array();
	if (!is_array($items) || !array_is_list($items)) {
		throw new RestApiError(400, 'invalid_field', '"items" must be a list of {gateway, tier, vip}.');
	}
	$errors = array();
	foreach ($items as $item) {
		$gw = is_array($item) ? (string)($item['gateway'] ?? '') : '';
		$tier = is_array($item) ? ($item['tier'] ?? 1) : null;
		if (!in_array($gw, $gateways, true) || in_array($gw, array('name', 'descr', 'trigger', 'keep_failover_states'), true)) {
			$errors[] = "\"{$gw}\" is not an enabled gateway.";
			continue;
		}
		if (!is_numeric($tier) || ((int)$tier != $tier) || ($tier < 1) || ($tier > 5)) {
			$errors[] = "The tier of gateway \"{$gw}\" must be 1 to 5.";
			continue;
		}
		$post[$gw] = (string)(int)$tier;
		$post["{$gw}_vip"] = (string)($item['vip'] ?? '');
	}
	if (!empty($errors)) {
		throw restapi_validation_error($errors);
	}
	return $post;
}

/* The static route edit form's fields for a stored route, as the page preselects them. */
function restapi_routing_route_fields(array $route) {
	$parts = explode('/', (string)($route['network'] ?? ''));
	$post = array(
		'network' => $parts[0],
		'network_subnet' => (string)($parts[1] ?? ''),
		'gateway' => (string)($route['gateway'] ?? ''),
		'descr' => (string)($route['descr'] ?? ''),
	);
	if (isset($route['disabled']) && ($route['disabled'] !== false)) {
		$post['disabled'] = 'yes';
	}
	return $post;
}

/*
 * PUT semantics over a form: the body's fields replace the current form
 * fields; a checkbox sent as false is unticked (absent), not kept.
 */
function restapi_routing_merge(array $body, array $current) {
	$post = restapi_body_as_post($body) + $current;
	foreach ($body as $key => $value) {
		if ($value === false) {
			unset($post[$key]);
		}
	}
	return $post;
}

/* Accept "network": "a.b.c.d/bits" as network plus network_subnet. */
function restapi_routing_split_network(array $post) {
	if (isset($post['network']) && is_string($post['network']) && (strpos($post['network'], '/') !== false)) {
		list($net, $bits) = explode('/', $post['network'], 2);
		$post['network'] = $net;
		if (!isset($post['network_subnet']) || ($post['network_subnet'] === '')) {
			$post['network_subnet'] = $bits;
		}
	}
	return $post;
}

/* ------------------------------------------------------------------ */
/* Shared                                                              */
/* ------------------------------------------------------------------ */

function restapi_routing_pending() {
	return is_subsystem_dirty('staticroutes');
}

/* The ?apply=true part of a write response. */
function restapi_routing_maybe_apply($req) {
	if (!restapi_want_apply($req)) {
		return array('pending' => restapi_routing_pending());
	}
	$retval = routing_apply_changes();
	return array('pending' => restapi_routing_pending(), 'apply' => array('applied' => ($retval == 0)));
}

function restapi_h_routing_apply($req) {
	$retval = routing_apply_changes();
	return array('data' => array('applied' => ($retval == 0), 'pending' => restapi_routing_pending()));
}

function restapi_routing_conflict(array $input_errors) {
	return new RestApiError(409, 'in_use', implode(' ', array_map(function ($m) {
		return html_entity_decode(strip_tags((string)$m), ENT_QUOTES);
	}, $input_errors)));
}

/* ------------------------------------------------------------------ */
/* Gateways                                                            */
/* ------------------------------------------------------------------ */

/* The gateway list the pages use, refreshed; ids are its positions. */
function restapi_gateways() {
	refresh_gateways();
	return get_gateways(GW_CACHE_INDEXED);
}

function restapi_gateway_index(array $gateways, $name) {
	foreach ($gateways as $idx => $gw) {
		if (($gw['name'] ?? null) === $name) {
			return $idx;
		}
	}
	throw new RestApiError(404, 'not_found', 'No gateway with that name.');
}

/* A gateway: its stored values, "fields" and "display". $status is restapi_gateway_status(), passed in for lists. */
function restapi_gateway_out(array $gw, ?array $status = null) {
	return array(
		'name' => $gw['name'],
		'interface' => $gw['friendlyiface'] ?? '',
		'ipprotocol' => $gw['ipprotocol'] ?? '',
		'gateway' => $gw['gateway'] ?? '',
		'monitor' => $gw['monitor'] ?? '',
		'descr' => $gw['descr'] ?? '',
		'dynamic' => !empty($gw['dynamic']),
		'disabled' => isset($gw['disabled']) && ($gw['disabled'] !== false),
		'inactive' => isset($gw['inactive']),
		'default' => isset($gw['isdefaultgw']),
		'editable' => is_numeric($gw['attribute'] ?? null),
		'fields' => restapi_routing_gateway_fields($gw),
		'display' => restapi_routing_gateway_display($gw, $status ?? restapi_gateway_status()),
	);
}

/* dpinger's status of every gateway by name (return_gateways_status()), read once per request. */
function restapi_gateway_status() {
	$status = function_exists('return_gateways_status') ? return_gateways_status(true) : array();
	return is_array($status) ? $status : array();
}

function restapi_gateway_by_name($name) {
	$gateways = restapi_gateways();
	return restapi_gateway_out($gateways[restapi_gateway_index($gateways, $name)]);
}

function restapi_gateway_save($post, $id, $req, $status) {
	$errors = routing_save_gateway($post, $id);
	if (!empty($errors)) {
		throw restapi_validation_error($errors);
	}
	$extra = restapi_routing_maybe_apply($req);
	return array('status' => $status, 'data' => restapi_gateway_by_name((string)$post['name']) + $extra);
}

function restapi_h_gateway_list($req) {
	$status = restapi_gateway_status();
	return array('data' => array_values(array_map(function ($gw) use ($status) {
		return restapi_gateway_out($gw, $status);
	}, restapi_gateways())));
}

function restapi_h_gateway_get($req) {
	return array('data' => restapi_gateway_by_name($req['params']['name']));
}

function restapi_h_gateway_create($req) {
	/* What the edit page's selects post when left at their first choice. */
	$post = restapi_body_as_post(restapi_routing_body($req['body'])) + array('ipprotocol' => 'inet', 'weight' => '1', 'gw_down_kill_states' => '');
	return restapi_gateway_save($post, null, $req, 201);
}

function restapi_h_gateway_update($req) {
	$gateways = restapi_gateways();
	$id = restapi_gateway_index($gateways, $req['params']['name']);
	$post = restapi_body_over(restapi_routing_body($req['body']), restapi_routing_gateway_fields($gateways[$id]));
	return restapi_gateway_save($post, $id, $req, 200);
}

function restapi_gateway_editable(array $gateways, $id) {
	if (!is_numeric($gateways[$id]['attribute'] ?? null)) {
		throw new RestApiError(409, 'not_editable', 'This gateway is generated by the system and cannot be deleted or disabled.');
	}
}

function restapi_h_gateway_delete($req) {
	$gateways = restapi_gateways();
	$id = restapi_gateway_index($gateways, $req['params']['name']);
	restapi_gateway_editable($gateways, $id);
	$input_errors = array();
	if (!routing_delete_gateway($gateways, $id, $input_errors)) {
		throw restapi_routing_conflict($input_errors);
	}
	return array('data' => array('deleted' => $req['params']['name']) + restapi_routing_maybe_apply($req));
}

function restapi_h_gateway_toggle($req) {
	$gateways = restapi_gateways();
	$id = restapi_gateway_index($gateways, $req['params']['name']);
	restapi_gateway_editable($gateways, $id);
	$input_errors = array();
	if (!routing_toggle_gateway($gateways, $id, $input_errors)) {
		throw restapi_routing_conflict($input_errors);
	}
	$realid = $gateways[$id]['attribute'];
	$state = (config_get_path("gateways/gateway_item/{$realid}/disabled") === null) ? 'enabled' : 'disabled';
	return array('data' => array('name' => $req['params']['name'], 'state' => $state) + restapi_routing_maybe_apply($req));
}

function restapi_default_gateways_out() {
	$dflts = available_default_gateways();
	return array(
		'defaultgw4' => (string)$dflts['defaultgw4'],
		'defaultgw6' => (string)$dflts['defaultgw6'],
		'choices' => array('defaultgw4' => $dflts['v4'], 'defaultgw6' => $dflts['v6']),
		'fields' => array('defaultgw4' => (string)$dflts['defaultgw4'], 'defaultgw6' => (string)$dflts['defaultgw6']),
	);
}

function restapi_h_default_gateways_get($req) {
	return array('data' => restapi_default_gateways_out());
}

function restapi_h_default_gateways_set($req) {
	$current = restapi_default_gateways_out();
	$post = array();
	$errors = array();
	foreach (array('defaultgw4', 'defaultgw6') as $field) {
		$value = $req['body'][$field] ?? $current[$field];
		if (!is_scalar($value) || !array_key_exists((string)$value, $current['choices'][$field])) {
			$errors[] = "\"{$field}\" must be one of: " . implode(', ', array_map(function ($k) {
				return ($k === '') ? '"" (automatic)' : (string)$k;
			}, array_keys($current['choices'][$field])));
			continue;
		}
		$post[$field] = (string)$value;
	}
	if (!empty($errors)) {
		throw restapi_validation_error($errors);
	}
	routing_save_default_gateways($post);
	$out = restapi_default_gateways_out();
	unset($out['choices'], $out['fields']);
	return array('data' => $out + restapi_routing_maybe_apply($req));
}

/* ------------------------------------------------------------------ */
/* Gateway groups                                                      */
/* ------------------------------------------------------------------ */

function restapi_gwgroup_index($name) {
	foreach (config_get_path('gateways/gateway_group', []) as $idx => $group) {
		if (is_array($group) && (($group['name'] ?? null) === $name)) {
			return $idx;
		}
	}
	throw new RestApiError(404, 'not_found', 'No gateway group with that name.');
}

/* A gateway group: the API shape plus "fields" (the editor fields) and "display" (the list columns as text). */
function restapi_gwgroup_out(array $group, ?array $gateways = null) {
	$gateways = $gateways ?? array_keys(get_gateways());
	return restapi_routing_group_out($group) + array(
		'fields' => restapi_routing_group_fields($group),
		'display' => restapi_routing_group_display($group, $gateways, routing_gateway_group_triggers()),
	);
}

function restapi_gwgroup_by_name($name) {
	return restapi_gwgroup_out(config_get_path('gateways/gateway_group/' . restapi_gwgroup_index($name)));
}

function restapi_gwgroup_save(array $body, $id, $req, $status) {
	$post = restapi_routing_group_post(restapi_routing_body($body), array_keys(get_gateways()));
	$result = routing_save_gateway_group($post, $id);
	if (!empty($result['input_errors'])) {
		throw restapi_validation_error($result['input_errors']);
	}
	return array('status' => $status, 'data' => restapi_gwgroup_by_name((string)$post['name']) +
	    restapi_routing_maybe_apply($req));
}

function restapi_h_gwgroup_list($req) {
	$gateways = array_keys(get_gateways());
	return array('data' => array_values(array_map(function ($group) use ($gateways) {
		return restapi_gwgroup_out($group, $gateways);
	}, array_filter(config_get_path('gateways/gateway_group', []), 'is_array'))));
}

function restapi_h_gwgroup_get($req) {
	return array('data' => restapi_gwgroup_by_name($req['params']['name']));
}

function restapi_h_gwgroup_create($req) {
	/* The edit page's trigger select starts at "Member Down". */
	return restapi_gwgroup_save($req['body'] + array('trigger' => 'down'), null, $req, 201);
}

function restapi_h_gwgroup_update($req) {
	$id = restapi_gwgroup_index($req['params']['name']);
	$current = restapi_routing_group_out(config_get_path("gateways/gateway_group/{$id}"));
	return restapi_gwgroup_save($req['body'] + $current, $id, $req, 200);
}

function restapi_h_gwgroup_delete($req) {
	$id = restapi_gwgroup_index($req['params']['name']);
	$input_errors = array();
	if (!routing_delete_gateway_group($id, $input_errors)) {
		throw restapi_routing_conflict($input_errors);
	}
	return array('data' => array('deleted' => $req['params']['name']) + restapi_routing_maybe_apply($req));
}

/* ------------------------------------------------------------------ */
/* Static routes                                                       */
/* ------------------------------------------------------------------ */

function restapi_sroutes() {
	$routes = config_get_path('staticroutes/route', []);
	return is_array($routes) ? $routes : array();
}

/* A static route with "fields" and "display". $gateways is get_gateways(GW_CACHE_ALL), passed in for lists. */
function restapi_sroute_out($id, ?array $gateways = null) {
	$route = (array)config_get_path("staticroutes/route/{$id}", []);
	return array(
		'id' => (int)$id,
		'network' => $route['network'] ?? '',
		'gateway' => $route['gateway'] ?? '',
		'descr' => $route['descr'] ?? '',
		'disabled' => isset($route['disabled']) && ($route['disabled'] !== false),
		'fields' => restapi_routing_route_fields($route),
		'display' => restapi_routing_route_display($route, $gateways ?? get_gateways(GW_CACHE_ALL),
		    'convert_friendly_interface_to_friendly_descr'),
	);
}

function restapi_sroute_save($post, $id, $req) {
	$errors = routing_save_static_route($post, $id);
	if (!empty($errors)) {
		throw restapi_validation_error($errors);
	}
	return restapi_routing_maybe_apply($req);
}

function restapi_h_sroute_list($req) {
	$gateways = get_gateways(GW_CACHE_ALL);
	return array('data' => array_map(function ($id) use ($gateways) {
		return restapi_sroute_out($id, $gateways);
	}, array_keys(restapi_sroutes())));
}

function restapi_h_sroute_get($req) {
	return array('data' => restapi_sroute_out(restapi_index_param($req['params']['id'], restapi_sroutes(), 'static route')));
}

function restapi_h_sroute_create($req) {
	$post = restapi_routing_split_network(restapi_body_as_post(restapi_routing_body($req['body'])));
	$extra = restapi_sroute_save($post, null, $req);
	return array('status' => 201, 'data' => restapi_sroute_out(count(restapi_sroutes()) - 1) + $extra);
}

function restapi_h_sroute_update($req) {
	$id = restapi_index_param($req['params']['id'], restapi_sroutes(), 'static route');
	$post = restapi_body_over(restapi_routing_split_network(restapi_routing_body($req['body'])),
	    restapi_routing_route_fields(config_get_path("staticroutes/route/{$id}")));
	$extra = restapi_sroute_save($post, $id, $req);
	return array('data' => restapi_sroute_out($id) + $extra);
}

function restapi_h_sroute_delete($req) {
	$id = restapi_index_param($req['params']['id'], restapi_sroutes(), 'static route');
	routing_delete_static_route($id);
	return array('data' => array('deleted' => $id) + restapi_routing_maybe_apply($req));
}

function restapi_h_sroute_toggle($req) {
	$id = restapi_index_param($req['params']['id'], restapi_sroutes(), 'static route');
	$input_errors = array();
	if (!routing_toggle_static_route($id, $input_errors)) {
		throw restapi_routing_conflict($input_errors);
	}
	return array('data' => array('id' => $id,
	    'state' => config_path_enabled("staticroutes/route/{$id}", 'disabled') ? 'disabled' : 'enabled') +
	    restapi_routing_maybe_apply($req));
}
