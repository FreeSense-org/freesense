<?php
/*
 * remote_backup/webdav.inc
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

/* WebDAV storage driver (Nextcloud, ownCloud, NAS appliances, rclone serve). */

function remote_backup_webdav_base($t) {
	return rtrim($t['dav_url'], '/') . '/';
}

function remote_backup_webdav_userpwd($t) {
	if (($t['dav_username'] ?? '') === '') {
		return null;
	}
	return $t['dav_username'] . ':' . ($t['dav_password'] ?? '');
}

function remote_backup_webdav_ok($result, array $codes) {
	return ($result['error'] === '') && in_array($result['code'], $codes, true);
}

function remote_backup_webdav_put($t, $name, $data) {
	$url = remote_backup_webdav_base($t) . rawurlencode($name);
	$result = remote_backup_http('PUT', $url, array('Content-Type: application/octet-stream', 'Expect:'),
	    $data, remote_backup_webdav_userpwd($t));
	if (in_array($result['code'], array(404, 409), true)) {
		/* The collection does not exist yet: create it once and retry. */
		remote_backup_http('MKCOL', remote_backup_webdav_base($t), array(), null,
		    remote_backup_webdav_userpwd($t));
		$result = remote_backup_http('PUT', $url, array('Content-Type: application/octet-stream', 'Expect:'),
		    $data, remote_backup_webdav_userpwd($t));
	}
	return remote_backup_webdav_ok($result, array(200, 201, 204)) ? true : remote_backup_http_error($result);
}

function remote_backup_webdav_get($t, $name) {
	$result = remote_backup_http('GET', remote_backup_webdav_base($t) . rawurlencode($name), array(), null,
	    remote_backup_webdav_userpwd($t));
	if (remote_backup_webdav_ok($result, array(200))) {
		return $result['body'];
	}
	return array('error' => remote_backup_http_error($result));
}

function remote_backup_webdav_delete($t, $name) {
	$result = remote_backup_http('DELETE', remote_backup_webdav_base($t) . rawurlencode($name), array(), null,
	    remote_backup_webdav_userpwd($t));
	return remote_backup_webdav_ok($result, array(200, 204)) ? true : remote_backup_http_error($result);
}

function remote_backup_webdav_list($t) {
	$body = '<?xml version="1.0" encoding="utf-8"?>' .
	    '<d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/></d:prop></d:propfind>';
	$result = remote_backup_http('PROPFIND', remote_backup_webdav_base($t),
	    array('Depth: 1', 'Content-Type: application/xml; charset=utf-8'), $body,
	    remote_backup_webdav_userpwd($t));
	if ($result['code'] === 404) {
		return array();
	}
	if (!remote_backup_webdav_ok($result, array(207))) {
		return remote_backup_http_error($result);
	}
	return remote_backup_webdav_parse_list($result['body']);
}

/* File names (not collections) from a Depth: 1 PROPFIND multistatus body. */
function remote_backup_webdav_parse_list($xml) {
	$names = array();
	/* Namespace prefixes vary by server (d:, D:, lp1:, none). */
	if (!preg_match_all('#<(?:[A-Za-z0-9]+:)?response\b.*?</(?:[A-Za-z0-9]+:)?response>#s', $xml, $responses)) {
		return $names;
	}
	foreach ($responses[0] as $response) {
		if (!preg_match('#<(?:[A-Za-z0-9]+:)?href>(.*?)</(?:[A-Za-z0-9]+:)?href>#s', $response, $href)) {
			continue;
		}
		if (preg_match('#<(?:[A-Za-z0-9]+:)?collection\s*/?>#', $response)) {
			continue;
		}
		$path = parse_url(html_entity_decode(trim($href[1]), ENT_QUOTES | ENT_XML1), PHP_URL_PATH);
		$name = rawurldecode(basename(rtrim((string)$path, '/')));
		if ($name !== '') {
			$names[] = $name;
		}
	}
	return $names;
}
