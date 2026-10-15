<?php
/*
 * restapi/routes_management.inc
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
 * Central management (FreeSense Fleet): what a Fleet controller reads and
 * writes on a managed box, plus the CARP status the fleet dashboard shows.
 * The controller calls these as the box's API-only "fleet-controller" user,
 * which holds the "REST API - management" privilege. The library is
 * fleet_node.inc; the front controller loads it.
 */

function restapi_routes_management() {
	$vault_body = array('type' => 'object', 'required' => array('data', 'sha256', 'owner', 'created'), 'properties' => array(
		'data' => array('type' => 'string', 'description' => 'The encrypted backup, base64-encoded (opaque to this box)'),
		'sha256' => array('type' => 'string', 'description' => 'SHA-256 of the decoded data, hexadecimal'),
		'owner' => array('type' => 'string', 'description' => 'Node id of the box the backup belongs to'),
		'kind' => array('type' => 'string', 'enum' => array('config', 'extended', 'controller')),
		'created' => array('type' => 'integer', 'description' => 'When the backup was taken (UNIX time)'),
		'label' => array('type' => 'string'),
	));
	return array(
		restapi_route('GET', '/v1/status/carp', 'restapi_h_status_carp', array(
			'page' => 'status_carp.php', 'area' => 'status',
			'summary' => 'CARP: enabled, maintenance mode, demotion, the state of every CARP VIP and a summary (master, backup, mixed)')),

		restapi_route('GET', '/v1/management/status', 'restapi_h_mgmt_status', array(
			'page' => 'system_fleet.php', 'area' => 'management',
			'summary' => 'Fleet membership, version, configuration revision and hash, rollback protection, policy bundle, vault use and CARP summary (the heartbeat)')),
		restapi_route('GET', '/v1/management/policy-bundle', 'restapi_h_mgmt_bundle_get', array(
			'page' => 'system_fleet.php', 'area' => 'management',
			'summary' => 'The controller-signed policy bundle this box enforces')),
		restapi_route('PUT', '/v1/management/policy-bundle', 'restapi_h_mgmt_bundle_put', array(
			'page' => 'system_fleet.php', 'area' => 'management', 'write' => true,
			'body' => array('type' => 'object', 'required' => array('bundle', 'signature'), 'properties' => array(
			    'bundle' => array('type' => 'string', 'description' => 'The bundle JSON as text: {version, node_id ("*" or this box), rules: [...]}'),
			    'signature' => array('type' => 'string', 'description' => 'base64 ECDSA P-256 / SHA-256 signature of the bundle text by the controller'))),
			'summary' => 'Replace the policy bundle; refused unless signed by this box\'s controller and at least as new as the one held')),
		restapi_route('GET', '/v1/management/vault', 'restapi_h_mgmt_vault_list', array(
			'page' => 'system_fleet.php', 'area' => 'management',
			'summary' => 'Encrypted backup replicas this box keeps for the fleet, with use and quota')),
		restapi_route('GET', '/v1/management/vault/{id}', 'restapi_h_mgmt_vault_get', array(
			'page' => 'system_fleet.php', 'area' => 'management',
			'summary' => 'One replica (base64) with its metadata and whether it still matches its hash')),
		restapi_route('PUT', '/v1/management/vault/{id}', 'restapi_h_mgmt_vault_put', array(
			'page' => 'system_fleet.php', 'area' => 'management', 'write' => true, 'body' => $vault_body,
			'summary' => 'Store or replace a replica (checked against its SHA-256 and the vault quota)')),
		restapi_route('DELETE', '/v1/management/vault/{id}', 'restapi_h_mgmt_vault_delete', array(
			'page' => 'system_fleet.php', 'area' => 'management', 'write' => true,
			'summary' => 'Remove a replica')),
	);
}

function restapi_h_status_carp($req) {
	return array('data' => fleet_carp_status());
}

/* 409 unless the box has joined a fleet (the agent writes <fleet> at join). */
function restapi_mgmt_enrolled() {
	$m = fleet_node_membership();
	if (!$m['enrolled']) {
		throw new RestApiError(409, 'not_enrolled', 'This firewall is not managed by a Fleet controller.');
	}
	return $m;
}

function restapi_h_mgmt_status($req) {
	$m = fleet_node_membership();
	$conf = g_get('conf_path') . '/config.xml';
	$be = be_load();
	$bundle = fleet_policy_bundle_read();
	$carp = fleet_carp_status();
	return array('data' => array(
		'membership' => $m,
		'hostname' => config_get_path('system/hostname') . '.' . config_get_path('system/domain'),
		'version' => trim((string)@file_get_contents('/etc/version')),
		'platform' => php_uname('m'),
		'time' => time(),
		'config_revision' => array(
			'time' => (int)config_get_path('revision/time', 0),
			'description' => (string)config_get_path('revision/description', ''),
			'sha256' => (string)@hash_file('sha256', $conf),
			'config_version' => (string)config_get_path('version', ''),
		),
		'rollback_protection' => fleet_rollback_protection($be['available'] && $be['compatible'],
		    (array)config_get_path('system/bootenv', [])),
		'policy_bundle' => ($bundle === null) ? null :
		    array('version' => (int)$bundle['bundle']['version'], 'received' => (int)$bundle['received']),
		'vault' => fleet_vault_usage($m),
		'carp' => $carp['summary'],
		'api_level' => RESTAPI_LEVEL,
	));
}

function restapi_h_mgmt_bundle_get($req) {
	$stored = fleet_policy_bundle_read();
	if ($stored === null) {
		throw new RestApiError(404, 'not_found', 'This firewall holds no policy bundle.');
	}
	return array('data' => array('bundle' => $stored['bundle'], 'signature' => $stored['signature'],
	    'received' => (int)$stored['received']));
}

/* InvalidArgumentException "<code>: <message>" from fleet_node.inc -> a RestApiError. */
function restapi_mgmt_error(InvalidArgumentException $e, $status, $default_code) {
	if (preg_match('/^([a-z_]+): (.*)$/s', $e->getMessage(), $m)) {
		return new RestApiError($status, $m[1], $m[2]);
	}
	return new RestApiError($status, $default_code, $e->getMessage());
}

function restapi_h_mgmt_bundle_put($req) {
	$m = restapi_mgmt_enrolled();
	restapi_reject_unknown_fields($req['body'], array('bundle', 'signature'));
	try {
		$record = fleet_policy_bundle_store($req['body'], $m);
	} catch (InvalidArgumentException $e) {
		$err = restapi_mgmt_error($e, 422, 'invalid_bundle');
		if (in_array($err->error_code, array('stale_bundle', 'no_signing_key'), true)) {
			$err->status = 409;
		}
		throw $err;
	}
	logger(LOG_NOTICE, localize_text('Fleet: policy bundle version %d received.', (int)$record['bundle']['version']));
	return array('data' => array('version' => (int)$record['bundle']['version'], 'received' => (int)$record['received']));
}

function restapi_mgmt_vault_id($req) {
	$id = (string)$req['params']['id'];
	if (!fleet_vault_valid_id($id)) {
		throw new RestApiError(404, 'not_found', 'No such replica.');
	}
	return $id;
}

function restapi_h_mgmt_vault_list($req) {
	$list = fleet_vault_list();
	return array('data' => array_values($list), 'meta' => fleet_vault_usage(fleet_node_membership(), $list));
}

function restapi_h_mgmt_vault_get($req) {
	$replica = fleet_vault_get(restapi_mgmt_vault_id($req));
	if ($replica === null) {
		throw new RestApiError(404, 'not_found', 'No such replica.');
	}
	$replica['data'] = base64_encode($replica['bytes']);
	unset($replica['bytes']);
	return array('data' => $replica);
}

function restapi_h_mgmt_vault_put($req) {
	$m = restapi_mgmt_enrolled();
	$id = restapi_mgmt_vault_id($req);
	try {
		$meta = fleet_vault_put($id, $req['body'], $m);
	} catch (InvalidArgumentException $e) {
		list($field, $message) = array_pad(explode(': ', $e->getMessage(), 2), 2, '');
		throw restapi_field_error($field, $message);
	} catch (LengthException $e) {
		throw new RestApiError(507, 'quota_exceeded', $e->getMessage(), array('vault' => fleet_vault_usage($m)));
	}
	return array('data' => $meta, 'status' => $meta['replaced'] ? 200 : 201);
}

function restapi_h_mgmt_vault_delete($req) {
	if (!fleet_vault_delete(restapi_mgmt_vault_id($req))) {
		throw new RestApiError(404, 'not_found', 'No such replica.');
	}
	return array('data' => null);
}
