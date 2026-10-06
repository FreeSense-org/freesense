<?php
/*
 * remote_backup/s3.inc
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
 * S3-compatible storage driver (AWS S3, Cloudflare R2, MinIO, Backblaze B2)
 * using AWS Signature Version 4. The signing follows the same scheme as
 * r53.class, generalised for any method, path, query and header set.
 */

/* URI-encode each segment of an S3 object path, keeping the slashes. */
function remote_backup_s3_encode_path($path) {
	return implode('/', array_map('rawurlencode', explode('/', $path)));
}

function remote_backup_s3_canonical_query(array $query) {
	$pairs = array();
	foreach ($query as $key => $value) {
		$pairs[rawurlencode($key)] = rawurlencode((string)$value);
	}
	ksort($pairs, SORT_STRING);
	$out = array();
	foreach ($pairs as $key => $value) {
		$out[] = "{$key}={$value}";
	}
	return implode('&', $out);
}

/*
 * Compute a SigV4 Authorization header value.
 * $headers maps lower-case header names to values and must include host,
 * x-amz-content-sha256 and x-amz-date; every header given is signed.
 */
function remote_backup_s3_authorization($method, $canonical_uri, array $query, array $headers,
    $payload_hash, $access_key, $secret_key, $region, $amz_date, $service = 's3') {
	ksort($headers, SORT_STRING);
	$canonical_headers = '';
	foreach ($headers as $name => $value) {
		$canonical_headers .= strtolower($name) . ':' . trim(preg_replace('/\s+/', ' ', (string)$value)) . "\n";
	}
	$signed_headers = implode(';', array_map('strtolower', array_keys($headers)));

	$canonical_request = implode("\n", array($method, $canonical_uri,
	    remote_backup_s3_canonical_query($query), $canonical_headers, $signed_headers, $payload_hash));

	$date_stamp = substr($amz_date, 0, 8);
	$scope = "{$date_stamp}/{$region}/{$service}/aws4_request";
	$string_to_sign = implode("\n", array('AWS4-HMAC-SHA256', $amz_date, $scope,
	    hash('sha256', $canonical_request)));

	$key = hash_hmac('sha256', $date_stamp, "AWS4{$secret_key}", true);
	$key = hash_hmac('sha256', $region, $key, true);
	$key = hash_hmac('sha256', $service, $key, true);
	$key = hash_hmac('sha256', 'aws4_request', $key, true);
	$signature = hash_hmac('sha256', $string_to_sign, $key);

	return "AWS4-HMAC-SHA256 Credential={$access_key}/{$scope}, " .
	    "SignedHeaders={$signed_headers}, Signature={$signature}";
}

/* The object key for a file name, under the target's prefix. */
function remote_backup_s3_key($t, $name) {
	$prefix = trim($t['s3_prefix'] ?? '', '/');
	return ($prefix === '') ? $name : "{$prefix}/{$name}";
}

/*
 * Send a signed request. $key is the object key, or '' for the bucket.
 * Returns the remote_backup_http() result array.
 */
function remote_backup_s3_request($t, $method, $key, array $query = array(), $body = '') {
	$endpoint = parse_url(rtrim($t['s3_endpoint'], '/'));
	$scheme = strtolower($endpoint['scheme']);
	$host = strtolower($endpoint['host']);
	if (isset($endpoint['port'])) {
		$host .= ":{$endpoint['port']}";
	}
	$base_path = rtrim($endpoint['path'] ?? '', '/');
	$bucket = $t['s3_bucket'];

	if (!empty($t['s3_path_style'])) {
		$path = "{$base_path}/{$bucket}/" . $key;
	} else {
		$host = "{$bucket}.{$host}";
		$path = "{$base_path}/" . $key;
	}
	$canonical_uri = remote_backup_s3_encode_path($path);

	$payload_hash = hash('sha256', $body);
	$amz_date = gmdate('Ymd\THis\Z');
	$headers = array(
		'host' => $host,
		'x-amz-content-sha256' => $payload_hash,
		'x-amz-date' => $amz_date,
	);
	$authorization = remote_backup_s3_authorization($method, $canonical_uri, $query, $headers,
	    $payload_hash, $t['s3_access_key'], $t['s3_secret_key'], $t['s3_region'], $amz_date);

	$http_headers = array(
		"x-amz-content-sha256: {$payload_hash}",
		"x-amz-date: {$amz_date}",
		"Authorization: {$authorization}",
		'Expect:',
	);
	if ($method === 'PUT') {
		$http_headers[] = 'Content-Type: application/octet-stream';
	}
	$url = "{$scheme}://{$host}{$canonical_uri}";
	if (!empty($query)) {
		$url .= '?' . remote_backup_s3_canonical_query($query);
	}
	return remote_backup_http($method, $url, $http_headers, ($method === 'PUT') ? $body : null);
}

function remote_backup_s3_put($t, $name, $data) {
	$result = remote_backup_s3_request($t, 'PUT', remote_backup_s3_key($t, $name), array(), $data);
	return ($result['error'] === '' && $result['code'] >= 200 && $result['code'] < 300) ?
	    true : remote_backup_http_error($result);
}

function remote_backup_s3_get($t, $name) {
	$result = remote_backup_s3_request($t, 'GET', remote_backup_s3_key($t, $name));
	if ($result['error'] === '' && $result['code'] === 200) {
		return $result['body'];
	}
	return array('error' => remote_backup_http_error($result));
}

function remote_backup_s3_delete($t, $name) {
	$result = remote_backup_s3_request($t, 'DELETE', remote_backup_s3_key($t, $name));
	return ($result['error'] === '' && in_array($result['code'], array(200, 204), true)) ?
	    true : remote_backup_http_error($result);
}

/* File names directly under the prefix (ListObjectsV2, all pages). */
function remote_backup_s3_list($t) {
	$prefix = trim($t['s3_prefix'] ?? '', '/');
	$prefix = ($prefix === '') ? '' : "{$prefix}/";
	$names = array();
	$token = null;
	for ($page = 0; $page < 100; $page++) {
		$query = array('list-type' => '2', 'prefix' => $prefix, 'delimiter' => '/');
		if ($token !== null) {
			$query['continuation-token'] = $token;
		}
		$result = remote_backup_s3_request($t, 'GET', '', $query);
		if ($result['error'] !== '' || $result['code'] !== 200) {
			return remote_backup_http_error($result);
		}
		$names = array_merge($names, remote_backup_s3_parse_list($result['body'], $prefix));
		if (!preg_match('#<IsTruncated>\s*true\s*</IsTruncated>#i', $result['body']) ||
		    !preg_match('#<NextContinuationToken>(.*?)</NextContinuationToken>#s', $result['body'], $m)) {
			break;
		}
		$token = html_entity_decode($m[1], ENT_QUOTES | ENT_XML1);
	}
	return $names;
}

function remote_backup_s3_parse_list($xml, $prefix) {
	$names = array();
	if (preg_match_all('#<Contents>.*?<Key>(.*?)</Key>.*?</Contents>#s', $xml, $matches)) {
		foreach ($matches[1] as $key) {
			$key = html_entity_decode($key, ENT_QUOTES | ENT_XML1);
			if (($prefix !== '') && (strpos($key, $prefix) !== 0)) {
				continue;
			}
			$name = substr($key, strlen($prefix));
			if ($name !== '' && strpos($name, '/') === false) {
				$names[] = $name;
			}
		}
	}
	return $names;
}
