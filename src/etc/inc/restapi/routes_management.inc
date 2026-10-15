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
		restapi_route('POST', '/v1/management/leave', 'restapi_h_mgmt_leave', array(
			'page' => 'system_fleet.php', 'area' => 'management', 'write' => true,
			'body' => array('type' => 'object', 'required' => array('confirm'), 'properties' => array('confirm' => array('type' => 'boolean'))),
			'summary' => 'Leave the fleet: removes the controller\'s access, the tunnel and the listener after the answer is sent')),
		restapi_route('GET', '/v1/management/commit-confirm', 'restapi_h_mgmt_confirm_list', array(
			'page' => 'system_fleet.php', 'area' => 'management',
			'summary' => 'Changes waiting for confirmation (each is reverted at its deadline)')),
		restapi_route('POST', '/v1/management/commit-confirm', 'restapi_h_mgmt_confirm_begin', array(
			'page' => 'system_fleet.php', 'area' => 'management', 'write' => true,
			'body' => array('type' => 'object', 'required' => array('seconds'), 'properties' => array(
			    'seconds' => array('type' => 'integer', 'description' => 'Time to confirm, 30 to 3600'),
			    'label' => array('type' => 'string', 'description' => 'What is being changed (shown if it is reverted)'))),
			'summary' => 'Snapshot the configuration before a risky change; it is restored at the deadline unless confirmed')),
		restapi_route('POST', '/v1/management/commit-confirm/{id}/confirm', 'restapi_h_mgmt_confirm_accept', array(
			'page' => 'system_fleet.php', 'area' => 'management', 'write' => true,
			'summary' => 'The change worked: keep it')),
		restapi_route('DELETE', '/v1/management/commit-confirm/{id}', 'restapi_h_mgmt_confirm_rollback', array(
			'page' => 'system_fleet.php', 'area' => 'management', 'write' => true,
			'summary' => 'Revert to the snapshot now')),
		restapi_route('POST', '/v1/management/restore', 'restapi_h_mgmt_restore', array(
			'page' => 'system_fleet.php', 'area' => 'management', 'write' => true,
			'body' => array('type' => 'object', 'required' => array('config', 'commit_confirm'), 'properties' => array(
			    'config' => array('type' => 'string', 'description' => 'The whole config.xml to restore'),
			    'commit_confirm' => array('type' => 'string', 'description' => 'ID of the open commit-confirm window this restore runs in'),
			    'description' => array('type' => 'string', 'description' => 'Shown in the configuration history'))),
			'summary' => 'Restore a configuration from the controller (inside a commit-confirm window; Fleet membership is kept)')),
		restapi_route('PUT', '/v1/management/activity', 'restapi_h_mgmt_activity_put', array(
			'page' => 'system_fleet.php', 'area' => 'management', 'write' => true,
			'body' => array('type' => 'object', 'required' => array('text'), 'properties' => array(
			    'text' => array('type' => 'string'), 'step' => array('type' => 'integer'), 'steps' => array('type' => 'integer'),
			    'by' => array('type' => 'string'), 'seconds' => array('type' => 'integer', 'description' => 'How long the notice stays, 10 to 3600'))),
			'summary' => 'Show "Fleet is working on this firewall" on the WebUI and console until it expires')),
		restapi_route('DELETE', '/v1/management/activity', 'restapi_h_mgmt_activity_delete', array(
			'page' => 'system_fleet.php', 'area' => 'management', 'write' => true,
			'summary' => 'Clear the activity notice')),
		restapi_route('POST', '/v1/auth/handoff', 'restapi_h_mgmt_handoff', array(
			'page' => 'system_fleet.php', 'area' => 'management', 'write' => true,
			'body' => array('type' => 'object', 'required' => array('user', 'operator'), 'properties' => array(
			    'user' => array('type' => 'string', 'description' => 'Local user to sign in as; must be listed in <fleet><handoff_users> by a local administrator'),
			    'operator' => array('type' => 'string', 'description' => 'Who opens the WebUI (recorded in the log)'),
			    'seconds' => array('type' => 'integer', 'description' => 'Validity, 10 to 120 (default 60)'))),
			'summary' => 'A one-time link that opens the WebUI signed in (Fleet "Open WebUI")')),
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
		'breakglass' => fleet_breakglass_read(),
		'pending_confirms' => count(fleet_confirm_pending()),
		'activity' => fleet_activity_read(),
		'carp' => $carp['summary'],
		'api_level' => RESTAPI_LEVEL,
	) + fleet_handoff_offer());
}

/* The controller removes a box from the fleet. The answer goes out first; the tunnel and listener it came through are taken down after. */
function restapi_h_mgmt_leave($req) {
	restapi_mgmt_enrolled();
	if (($req['body']['confirm'] ?? false) !== true) {
		throw new RestApiError(400, 'confirmation_required', 'Leaving the fleet requires {"confirm": true}.');
	}
	register_shutdown_function(function () {
		if (function_exists('fastcgi_finish_request')) {
			fastcgi_finish_request();
		}
		fleet_leave('leave');
	});
	return array('data' => array('leaving' => true));
}

function restapi_h_mgmt_confirm_list($req) {
	return array('data' => fleet_confirm_pending());
}

function restapi_h_mgmt_confirm_begin($req) {
	restapi_mgmt_enrolled();
	restapi_reject_unknown_fields($req['body'], array('seconds', 'label'));
	if (!is_int($req['body']['seconds'] ?? null)) {
		throw restapi_field_error('seconds', 'An integer from ' . FLEET_CONFIRM_MIN . ' to ' . FLEET_CONFIRM_MAX . ' is required.');
	}
	try {
		$record = fleet_confirm_begin($req['body']['seconds'], (string)($req['body']['label'] ?? ''), (string)$req['user']['name']);
	} catch (InvalidArgumentException $e) {
		list($field, $message) = array_pad(explode(': ', $e->getMessage(), 2), 2, '');
		throw restapi_field_error($field, $message);
	} catch (RuntimeException $e) {
		throw new RestApiError(500, 'snapshot_failed', $e->getMessage());
	}
	return array('data' => $record, 'status' => 201);
}

/*
 * Restore a configuration sent by the controller. Only inside an open
 * commit-confirm window: if the box cannot be reached afterwards, the
 * watchdog puts the snapshot back by itself.
 */
function restapi_h_mgmt_restore($req) {
	restapi_mgmt_enrolled();
	restapi_reject_unknown_fields($req['body'], array('config', 'commit_confirm', 'description'));
	$id = (string)($req['body']['commit_confirm'] ?? '');
	if (!fleet_confirm_valid_id($id) || (fleet_confirm_read($id) === null)) {
		throw restapi_field_error('commit_confirm', 'Open a commit-confirm window first (POST /v1/management/commit-confirm) and pass its id.');
	}
	if (!is_string($req['body']['config'] ?? null) || ($req['body']['config'] === '')) {
		throw restapi_field_error('config', 'The whole config.xml is required.');
	}
	$description = trim((string)($req['body']['description'] ?? ''));
	if (($description === '') || (strlen($description) > 200) || preg_match('/[\x00-\x1f\x7f]/', $description)) {
		$description = 'Restored by FreeSense Fleet';
	}
	try {
		fleet_restore_apply($req['body']['config'], $description);
	} catch (InvalidArgumentException $e) {
		throw restapi_field_error('config', $e->getMessage());
	} catch (RuntimeException $e) {
		throw new RestApiError(500, 'restore_failed', $e->getMessage());
	}
	return array('data' => array('restored' => true, 'commit_confirm' => $id,
	    'note' => 'Rules and aliases are active now; interface and service settings apply fully after a reboot.'));
}

function restapi_mgmt_confirm_id($req) {
	$id = (string)$req['params']['id'];
	if (!fleet_confirm_valid_id($id) || (fleet_confirm_read($id) === null)) {
		throw new RestApiError(404, 'not_found', 'No such pending change.');
	}
	return $id;
}

function restapi_h_mgmt_confirm_accept($req) {
	fleet_confirm_accept(restapi_mgmt_confirm_id($req));
	return array('data' => array('confirmed' => true));
}

function restapi_h_mgmt_confirm_rollback($req) {
	$id = restapi_mgmt_confirm_id($req);
	if (!fleet_confirm_rollback($id, 'the controller asked for it')) {
		throw new RestApiError(500, 'restore_failed', 'The snapshot could not be restored.');
	}
	return array('data' => array('reverted' => true));
}

function restapi_h_mgmt_activity_put($req) {
	restapi_mgmt_enrolled();
	try {
		$record = fleet_activity_record($req['body'], time());
	} catch (InvalidArgumentException $e) {
		list($field, $message) = array_pad(explode(': ', $e->getMessage(), 2), 2, '');
		throw restapi_field_error($field, $message);
	}
	if (@file_put_contents(FLEET_ACTIVITY_FILE, json_encode($record), LOCK_EX) === false) {
		throw new RestApiError(500, 'write_failed', 'The notice could not be saved.');
	}
	return array('data' => $record);
}

function restapi_h_mgmt_activity_delete($req) {
	@unlink(FLEET_ACTIVITY_FILE);
	return array('data' => null);
}
function restapi_h_mgmt_handoff($req) {
	restapi_mgmt_enrolled();
	try {
		$request = fleet_handoff_request($req['body'], fleet_handoff_allowed_users((string)config_get_path('fleet/handoff_users', '')));
		list($token, $expires) = fleet_handoff_issue($request);
	} catch (DomainException $e) {
		throw new RestApiError(403, 'handoff_not_allowed', $e->getMessage());
	} catch (InvalidArgumentException $e) {
		list($field, $message) = array_pad(explode(': ', $e->getMessage(), 2), 2, '');
		throw restapi_field_error($field, $message);
	}
	logger(LOG_NOTICE, localize_text('Fleet: one-time WebUI link issued for %1$s (operator %2$s).', $request['user'], $request['operator']));
	return array('data' => array('path' => '/?fleet_handoff=' . $token, 'expires' => $expires), 'status' => 201);
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
