<?php
/*
 * restapi/routes_pkgrepos.inc
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
 * Custom package repositories: System > Package Manager > Repositories.
 *   GET    /v1/packages/repositories                  the built-in, FreeBSD and custom repositories
 *   POST   /v1/packages/repositories                  add a custom repository
 *   PUT    /v1/packages/repositories/{id}             change one (an empty "key" keeps the stored key)
 *   DELETE /v1/packages/repositories/{id}             delete one (installed packages stay)
 *   POST   /v1/packages/repositories/{id}/refresh     fetch its package list
 *   GET    /v1/packages/repositories/{id}/packages    its packages (?q= filters, at most 300)
 *   PUT    /v1/packages/freebsd-repository            switch the FreeBSD package repository on or off
 * The page's rules apply unchanged (pkg_custom_repos/core.inc): https only, a
 * fingerprint or public key or an acknowledged unsigned repository, and fixed
 * priorities below every FreeSense repository. Keys are never returned.
 */

require_once('restapi/framework.inc');

function restapi_routes_pkgrepos() {
	$body = array('type' => 'object', 'description' => 'name (lowercase letters, digits, "-" and "_"; not starting with ' .
	    '"freesense" or "freebsd"), descr, url (https://), signature_type (fingerprints|pubkey|none), key (the SHA-256 ' .
	    'fingerprint, or a PEM public key), enable and ack_unsigned (required for signature_type none) as booleans.');
	$r = function ($method, $path, $handler, $summary, $write = false, $request_body = null) {
		return restapi_route($method, $path, $handler, array('page' => 'pkg_mgr_repos.php', 'area' => 'packages',
		    'write' => $write, 'summary' => $summary, 'body' => $request_body));
	};
	return array(
		$r('GET', '/v1/packages/repositories', 'restapi_h_pkgrepo_list',
		    'The built-in, FreeBSD and custom package repositories with their fixed priorities (no keys)'),
		$r('POST', '/v1/packages/repositories', 'restapi_h_pkgrepo_create', 'Add a custom package repository', true, $body),
		$r('PUT', '/v1/packages/repositories/{id}', 'restapi_h_pkgrepo_update',
		    'Change a custom package repository (omitted fields and an empty key keep their value)', true, $body),
		$r('DELETE', '/v1/packages/repositories/{id}', 'restapi_h_pkgrepo_delete',
		    'Delete a custom package repository (packages installed from it stay installed)', true),
		$r('POST', '/v1/packages/repositories/{id}/refresh', 'restapi_h_pkgrepo_refresh',
		    'Fetch the package list of a repository', true),
		$r('GET', '/v1/packages/repositories/{id}/packages', 'restapi_h_pkgrepo_packages',
		    'Packages a repository offers ("id" is a custom repository id or "freebsd"); "q" filters by name or description, at most 300 are returned'),
		$r('PUT', '/v1/packages/freebsd-repository', 'restapi_h_pkgrepo_freebsd',
		    'Switch the FreeBSD optional-package repository on or off', true,
		    array('type' => 'object', 'required' => array('enable'), 'properties' => array('enable' => array('type' => 'boolean')))),
	);
}

/* A repository as the API shows it: never the key, only whether one is set. */
function restapi_pkgrepo_out(array $repo, $priority) {
	return array(
		'id' => $repo['id'],
		'name' => $repo['name'],
		'descr' => (string)($repo['descr'] ?? ''),
		'url' => $repo['url'],
		'signature_type' => $repo['signature_type'],
		'key_set' => ($repo['signature_type'] !== 'none') && ($repo['key'] ?? '') !== '',
		'enable' => !empty($repo['enable']),
		'priority' => (int)$priority,
	);
}

function restapi_pkgrepo_find($id) {
	list(, $repo) = pkgrepo_find((string)$id);
	if ($repo === null) {
		throw new RestApiError(404, 'not_found', 'No custom package repository with that id.');
	}
	return $repo;
}

/* The form fields from the body: a boolean is "on", omitted fields keep the stored value. */
function restapi_pkgrepo_post(array $body, array $existing) {
	foreach ($body as $key => $value) {
		if (!in_array($key, array('name', 'descr', 'url', 'signature_type', 'key', 'enable', 'ack_unsigned'), true)) {
			throw new RestApiError(400, 'unknown_field', "Unknown field \"{$key}\".");
		}
		if (in_array($key, array('enable', 'ack_unsigned'), true) ? !is_bool($value) : !is_string($value)) {
			throw new RestApiError(400, 'invalid_field', "Field \"{$key}\" has the wrong type.");
		}
	}
	$post = array_merge(array(
		'name' => $existing['name'] ?? '', 'descr' => $existing['descr'] ?? '', 'url' => $existing['url'] ?? '',
		'signature_type' => $existing['signature_type'] ?? 'fingerprints', 'key' => '',
		'enable' => !empty($existing['enable']), 'ack_unsigned' => !empty($existing['ack_unsigned']),
	), $body);
	if (!$post['enable']) {
		unset($post['enable']);
	}
	if (!$post['ack_unsigned']) {
		unset($post['ack_unsigned']);
	}
	return $post;
}

function restapi_h_pkgrepo_list($req) {
	$repos = pkgrepo_list();
	$priorities = pkgrepo_priorities($repos);
	$custom = array();
	foreach ($repos as $repo) {
		$custom[] = restapi_pkgrepo_out($repo, $priorities[$repo['name']]);
	}
	return array('data' => array(
		'builtin' => array_map(function ($b) {
			return array('name' => $b['name'], 'descr' => $b['descr'], 'url' => $b['url'], 'priority' => (int)$b['priority'],
			    'editable' => false);
		}, pkgrepo_builtin()),
		'freebsd' => array('name' => 'FreeBSD-ports', 'enable' => pkgrepo_freebsd_enabled(), 'priority' => PKGREPO_PRIORITY_FREEBSD),
		'custom' => $custom,
		'limits' => array('max_custom' => PKGREPO_MAX_CUSTOM, 'custom_priorities' => '1-' . PKGREPO_PRIORITY_CUSTOM_TOP,
		    'lowest_system_priority' => pkgrepo_lowest_system_priority()),
	));
}

function restapi_h_pkgrepo_create($req) {
	$rv = pkgrepo_save(restapi_pkgrepo_post((array)$req['body'], array()), '');
	if (!empty($rv['input_errors'])) {
		throw restapi_validation_error($rv['input_errors']);
	}
	$repo = restapi_pkgrepo_find($rv['id']);
	return array('status' => 201, 'data' => restapi_pkgrepo_out($repo, pkgrepo_priority_of($repo)));
}

function restapi_h_pkgrepo_update($req) {
	$existing = restapi_pkgrepo_find($req['params']['id']);
	$rv = pkgrepo_save(restapi_pkgrepo_post((array)$req['body'], $existing), $existing['id']);
	if (!empty($rv['input_errors'])) {
		throw restapi_validation_error($rv['input_errors']);
	}
	$repo = restapi_pkgrepo_find($existing['id']);
	return array('data' => restapi_pkgrepo_out($repo, pkgrepo_priority_of($repo)));
}

function restapi_h_pkgrepo_delete($req) {
	$repo = restapi_pkgrepo_find($req['params']['id']);
	pkgrepo_delete($repo['id']);
	return array('data' => array('deleted' => $repo['id']));
}

function restapi_h_pkgrepo_refresh($req) {
	$repo = restapi_pkgrepo_find($req['params']['id']);
	list($ok, $message) = pkgrepo_refresh($repo['name']);
	if (!$ok) {
		throw new RestApiError(502, 'repository_error', $message !== '' ? $message : 'The package list could not be fetched.');
	}
	return array('data' => array('refreshed' => $repo['name'], 'message' => $message));
}

function restapi_h_pkgrepo_packages($req) {
	if ($req['params']['id'] === 'freebsd') {
		if (!pkgrepo_freebsd_enabled()) {
			throw new RestApiError(409, 'disabled', 'The FreeBSD package repository is switched off.');
		}
		$name = 'FreeBSD-ports';
	} else {
		$name = restapi_pkgrepo_find($req['params']['id'])['name'];
	}
	$query = trim((string)($req['query']['q'] ?? ''));
	$packages = array();
	$total = 0;
	foreach (pkgrepo_catalog($name) as $p) {
		if ($query === '' || stripos($p[0], $query) !== false || stripos($p[2], $query) !== false) {
			$total++;
			if (count($packages) < 300) {
				$packages[] = array('name' => $p[0], 'version' => $p[1], 'description' => $p[2]);
			}
		}
	}
	return array('data' => array('repository' => $name, 'total' => $total, 'packages' => $packages));
}

function restapi_h_pkgrepo_freebsd($req) {
	if (!is_bool($req['body']['enable'] ?? null)) {
		throw new RestApiError(400, 'invalid_field', 'Field "enable" must be a boolean.');
	}
	pkgrepo_set_freebsd($req['body']['enable']);
	return array('data' => array('name' => 'FreeBSD-ports', 'enable' => pkgrepo_freebsd_enabled(), 'priority' => PKGREPO_PRIORITY_FREEBSD));
}
