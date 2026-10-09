<?php
/*
 * schema.inc
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
 * Resource schemas for the WebUI's generated editors and for keyed
 * validation errors.
 *
 * A schema describes the fields of an API resource in the format of the
 * WebUI form element (freesense-webui packages/ui/elements/form/schema.md):
 * sections of fields with type, label, help, options, limits and
 * visibility rules. GET /api/v1/schema/{area}/{name} serves it; labels and
 * help are translated on the server.
 *
 * Routes that save a resource name it with the route option 'schema'. When
 * the shared GUI save function returns its input_errors, each message is
 * attached to the field it is about (by the field's label, or by the value
 * of an entry-grid cell it mentions), so the WebUI shows it next to that
 * field. A field may list extra phrases in "errorMatch" for messages that
 * word it differently than its label (e.g. "NTP Orphan Mode"). Messages that match no field stay in details.messages and are shown
 * in the form's error summary.
 */

require_once('restapi/schemas/firewall_aliases.inc');
require_once('restapi/schemas/firewall_rules.inc');
require_once('restapi/schemas/services_dns_resolver.inc');
require_once('restapi/schemas/services_ntp.inc');

/* resource => array(builder function, GUI page that grants read access, permission area) */
function restapi_schemas() {
	return array(
		'firewall/aliases' => array('restapi_schema_firewall_aliases', 'firewall_aliases_edit.php', 'firewall.aliases'),
		'firewall/rules' => array('restapi_schema_firewall_rules', 'firewall_rules_edit.php', 'firewall.rules'),
		'firewall/floating_rules' => array('restapi_schema_firewall_floating_rules', 'firewall_rules_edit.php', 'firewall.rules'),
		'services/dns_resolver' => array('restapi_schema_services_dns_resolver', 'services_unbound.php', 'services.dns'),
		'services/dns_resolver_advanced' => array('restapi_schema_services_dns_resolver_advanced', 'services_unbound_advanced.php', 'services.dns'),
		'services/dns_host_override' => array('restapi_schema_services_dns_host_override', 'services_unbound_host_edit.php', 'services.dns'),
		'services/dns_domain_override' => array('restapi_schema_services_dns_domain_override', 'services_unbound_domainoverride_edit.php', 'services.dns'),
		'services/dns_access_list' => array('restapi_schema_services_dns_access_list', 'services_unbound_acls.php', 'services.dns'),
		'services/ntp' => array('restapi_schema_services_ntp', 'services_ntpd.php', 'services.time'),
	);
}

/* The schema of a resource, or null. */
function restapi_schema_get($resource) {
	$all = restapi_schemas();
	if (!isset($all[$resource])) {
		return null;
	}
	$schema = call_user_func($all[$resource][0]);
	$schema['resource'] = $resource;
	return $schema;
}

/*
 * Every field of a schema as name => array(label, parent) where parent is
 * the entry-grid a column belongs to (null for top-level fields).
 */
function restapi_schema_fields(array $schema) {
	$out = array();
	$sections = $schema['sections'] ?? array(array('fields' => $schema['fields'] ?? array()));
	foreach ($sections as $section) {
		foreach ((array)($section['fields'] ?? array()) as $f) {
			if (!isset($f['name'])) {
				continue;
			}
			$out[$f['name']] = array('label' => (string)($f['label'] ?? ''), 'parent' => null, 'type' => $f['type'] ?? 'text',
			    'match' => array_map('strval', (array)($f['errorMatch'] ?? array())));
			if (($f['type'] ?? '') === 'entry-grid') {
				foreach ((array)($f['fields'] ?? array()) as $c) {
					if (isset($c['name'])) {
						$out["{$f['name']}.*.{$c['name']}"] = array('label' => (string)($c['label'] ?? ''), 'parent' => $f['name'], 'column' => $c['name']);
					}
				}
			}
		}
	}
	return $out;
}

/*
 * Attach validation messages to schema fields.
 *   $messages  plain-text messages (from the GUI save function)
 *   $schema    the resource schema
 *   $body      the request body (for entry-grid cell values)
 * Returns array(fields => {name: message}, unmatched => [messages]).
 */
function restapi_errors_to_fields(array $messages, array $schema, array $body = array()) {
	$fields = restapi_schema_fields($schema);
	$out = array();
	$unmatched = array();
	foreach ($messages as $msg) {
		$m = (string)$msg;
		$lower = mb_strtolower($m);
		$hit = null;

		/* 1. An entry-grid cell whose value the message names (e.g. "10.0.0.300 is not a valid address"). */
		foreach ($fields as $name => $f) {
			if (empty($f['parent']) || !is_array($body[$f['parent']] ?? null)) {
				continue;
			}
			foreach ($body[$f['parent']] as $i => $row) {
				$v = is_array($row) ? (string)($row[$f['column']] ?? '') : '';
				if ((strlen($v) >= 3) && (strpos($m, $v) !== false)) {
					$hit = "{$f['parent']}.{$i}.{$f['column']}";
					break 2;
				}
			}
		}
		/* 2. A label in quotes ("Name" is required), then the longest label mentioned anywhere. */
		if ($hit === null) {
			$best = '';
			foreach ($fields as $name => $f) {
				$label = mb_strtolower($f['label']);
				if (($label === '') || !empty($f['parent'])) {
					continue;
				}
				foreach (array("\"{$label}\"", "'{$label}'", "“{$label}”") as $q) {
					if (strpos($lower, $q) !== false) {
						$hit = $name;
						break 2;
					}
				}
				foreach (array_merge(array($label), array_map('mb_strtolower', $f['match'] ?? array())) as $phrase) {
					if ((mb_strlen($phrase) > mb_strlen($best)) && preg_match('/(^|[^\pL])' . preg_quote($phrase, '/') . '([^\pL]|$)/u', $lower)) {
						$best = $phrase;
						$hit = $name;
					}
				}
			}
		}
		if ($hit === null) {
			$unmatched[] = $m;
		} elseif (isset($out[$hit])) {
			$out[$hit] .= ' ' . $m;
		} else {
			$out[$hit] = $m;
		}
	}
	return array('fields' => $out, 'unmatched' => $unmatched);
}

/* The request being handled (route + body), so restapi_validation_error() can key messages. */
function restapi_request_context(?array $set = null) {
	static $ctx = array('schema' => null, 'body' => array());
	if ($set !== null) {
		$ctx = $set + array('schema' => null, 'body' => array());
	}
	return $ctx;
}

function restapi_h_schema($req) {
	$resource = "{$req['params']['area']}/{$req['params']['name']}";
	$all = restapi_schemas();
	if (!isset($all[$resource])) {
		throw new RestApiError(404, 'not_found', 'No schema for this resource.');
	}
	/* Reading a schema needs the privilege of the editor it describes. */
	restapi_authorize(array('user' => $req['user'], 'token' => $req['token']),
	    restapi_route('GET', '/v1/schema', 'restapi_h_schema', array('page' => $all[$resource][1], 'area' => $all[$resource][2])));
	return array('data' => restapi_schema_get($resource));
}
