<?php
/*
 * restapi/routes_pki.inc
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
 * REST API v1 routes for the certificate manager (area pki): certificate
 * authorities (/v1/pki/cas), certificates (/v1/pki/certificates) and
 * certificate revocation lists (/v1/pki/crls), addressed by refid.
 *
 * Writes go through the functions the pages call (system_certificates.inc),
 * so validation is the GUI's. Like the pages, every write is applied at once:
 * a saved or deleted CA rebuilds the trust store; CRL changes refresh the
 * OpenVPN servers' CRLs and reconfigure IPsec; certificates are stored (the
 * services using one are not restarted, like the page). There is nothing to
 * stage and no apply endpoint.
 *
 * Private keys are never returned and cannot be exported (no key or PKCS #12
 * export; the GUI keeps its own): responses say whether an entry has one
 * ("has_private_key"); the edit forms' "key" reads as "(set)". A key may be
 * sent (importing a certificate or CA, signing a pasted request, editing);
 * it is stored on the firewall only; sending "(set)" or leaving it out keeps
 * the stored one. Certificates, CA certificates, signing requests and CRLs
 * can be downloaded in PEM format; downloads and returned certificate fields
 * hold only the certificate (or request) blocks of the stored text, so a key
 * pasted into a certificate field is never returned either.
 *
 * Certificates of users (the page's userid parameter, "Choose an existing
 * certificate") are not handled here; they belong to the user routes.
 *
 * The output builders below copy the fields they return one by one; stored
 * entries (which hold private keys, also inside revoked certificate copies
 * of a CRL) are never returned as they are. Only restapi_pki_has_key() reads
 * the stored key, and only to say whether there is one.
 *
 * The front controller loads system_certificates.inc at file scope.
 */

require_once('restapi/framework.inc');
require_once('restapi/routes_firewall.inc');
require_once('restapi/routes_services.inc');
require_once('restapi/routes_vpn.inc');
require_once('restapi/routes_openvpn.inc');

function restapi_routes_pki() {
	$route = function ($method, $path, $handler, $page, $summary, $write = false, $body = null, $produces = null) {
		$opts = array('page' => $page, 'area' => 'pki', 'summary' => $summary);
		if ($write) {
			$opts['write'] = true;
			$opts['body'] = $body;
		}
		if ($produces !== null) {
			$opts['produces'] = $produces;
		}
		return restapi_route($method, $path, $handler, $opts);
	};

	$ca_create = array('type' => 'object', 'description' => 'A new CA as its page\'s form: method (internal: create a ' .
	    'self-signed CA, intermediate: create a CA signed by "caref", existing: import "cert" with the optional private key ' .
	    '"key"), descr, trust and randomserial (booleans), serial (next certificate serial of an imported CA), caref, ' .
	    'keytype (RSA|ECDSA), keylen, ecname, digest_alg, lifetime (days), dn_commonname, dn_country, dn_state, dn_city, ' .
	    'dn_organization, dn_organizationalunit. Omitted fields take the page\'s defaults. Applied at once (trust store).');
	$ca_update = array('type' => 'object', 'description' => 'The CA edit form ("fields" of GET): descr, trust, randomserial, ' .
	    'serial, cert (PEM), key (private key PEM; "(set)" or leaving it out keeps it, "" removes it). Omitted fields keep ' .
	    'their value. Applied at once (trust store).');
	$crl_create = array('type' => 'object', 'description' => 'A new CRL as its page\'s form: caref (required), method ' .
	    '(internal: kept and signed by this system, needs a CA with a private key; existing: import "crltext"), descr, ' .
	    'lifetime (days) and serial of an internal CRL, crltext (PEM) of an imported one. Applied at once.');
	$crl_update = array('type' => 'object', 'description' => 'The CRL edit form ("fields" of GET): descr, lifetime and serial ' .
	    '(internal CRL) or descr and crltext (imported CRL). Omitted fields keep their value. Applied at once.');
	$crl_revoke = array('type' => 'object', 'description' => 'certref: refid (or list of refids) of certificates signed by ' .
	    'the CRL\'s CA; serials: serial numbers (decimal or 0x hex; list or text separated by spaces); reason: RFC 5280 ' .
	    'reason code (-1: no status, the default). Internal CRLs only. Applied at once.');

	$cert_create = array('type' => 'object', 'description' => 'A new certificate as its page\'s form. method: internal (create a ' .
	    'certificate signed by the internal CA "caref": keytype (RSA|ECDSA), keylen, ecname, digest_alg, lifetime (days), ' .
	    'dn_commonname, dn_country, dn_state, dn_city, dn_organization, dn_organizationalunit, autorenew), import ' .
	    '(import_type x509: "cert" and the optional private key "key" in PEM format; import_type pkcs12: "pkcs12" (the ' .
	    'PKCS #12 file, base64), pkcs12_pass, pkcs12_intermediate (also import its intermediate CAs)), external (create a ' .
	    'private key and a certificate signing request: csr_keytype, csr_keylen, csr_ecname, csr_digest_alg, ' .
	    'csr_dn_commonname, csr_dn_country, ...; complete it with POST .../{refid}/complete), sign (sign a request with the ' .
	    'internal CA "catosignwith": csrtosign "new" with csrpaste (PEM) and the optional private key keypaste, or the refid ' .
	    'of a pending request; csrsign_lifetime, csrsign_digest_alg). Also descr, type (server|user) and altnames: list of ' .
	    '{type: DNS|IP|URI|email, value} (subject alternative names; the common name is added automatically). Omitted ' .
	    'fields take the page\'s defaults. Private keys are stored on the firewall only. Applied at once.');
	$cert_update = array('type' => 'object', 'description' => 'The certificate edit form ("fields" of GET): descr, autorenew, ' .
	    'cert (PEM), key (private key PEM; "(set)" or leaving it out keeps it, "" removes it). Omitted fields keep their ' .
	    'value. Not for a pending signing request (complete it). Applied at once.');
	$cert_complete = array('type' => 'object', 'description' => 'certificate: the signed certificate (PEM; it must match the ' .
	    'pending request\'s key), descr: optional new name. Applied at once.');

	$ca = '/v1/pki/cas';
	$crl = '/v1/pki/crls';
	$cert = '/v1/pki/certificates';
	return array(
		$route('GET', $ca, 'restapi_h_pki_ca_list', 'system_camanager.php',
		    'List the certificate authorities (subject, issuer, validity, serial, has_private_key, used_by)'),
		$route('GET', "{$ca}/{refid}", 'restapi_h_pki_ca_get', 'system_camanager.php',
		    'Get a certificate authority with its certificate (PEM) and edit form ("fields"); never its private key'),
		$route('GET', "{$ca}/{refid}/certificate", 'restapi_h_pki_ca_pem', 'system_camanager.php',
		    'Download the certificate of a certificate authority (PEM)', false, null, 'text/plain'),
		$route('POST', $ca, 'restapi_h_pki_ca_create', 'system_camanager.php',
		    'Create (internal, intermediate) or import (existing) a certificate authority (applied at once)', true, $ca_create),
		$route('PUT', "{$ca}/{refid}", 'restapi_h_pki_ca_update', 'system_camanager.php',
		    'Update a certificate authority (applied at once; omitted fields keep their value)', true, $ca_update),
		$route('DELETE', "{$ca}/{refid}", 'restapi_h_pki_ca_delete', 'system_camanager.php',
		    'Delete a certificate authority and its CRLs (409 while in use; its certificates are kept)', true),
		$route('GET', $cert, 'restapi_h_pki_cert_list', 'system_certmanager.php',
		    'List the certificates and pending signing requests (subject, issuer, SANs, validity, has_private_key, is_csr, used_by)'),
		$route('GET', "{$cert}/{refid}", 'restapi_h_pki_cert_get', 'system_certmanager.php',
		    'Get a certificate with its certificate or signing request (PEM) and edit form ("fields"); never its private key'),
		$route('GET', "{$cert}/{refid}/certificate", 'restapi_h_pki_cert_pem', 'system_certmanager.php',
		    'Download a certificate (PEM)', false, null, 'text/plain'),
		$route('GET', "{$cert}/{refid}/csr", 'restapi_h_pki_cert_csr', 'system_certmanager.php',
		    'Download the certificate signing request of a pending request (PEM)', false, null, 'text/plain'),
		$route('POST', $cert, 'restapi_h_pki_cert_create', 'system_certmanager.php',
		    'Create (internal), import, request (external) or sign a certificate (applied at once)', true, $cert_create),
		$route('PUT', "{$cert}/{refid}", 'restapi_h_pki_cert_update', 'system_certmanager.php',
		    'Update a certificate (applied at once; omitted fields keep their value)', true, $cert_update),
		$route('POST', "{$cert}/{refid}/complete", 'restapi_h_pki_cert_complete', 'system_certmanager.php',
		    'Complete a pending signing request with the signed certificate (applied at once)', true, $cert_complete),
		$route('DELETE', "{$cert}/{refid}", 'restapi_h_pki_cert_delete', 'system_certmanager.php',
		    'Delete a certificate or pending signing request (409 while in use)', true),
		$route('GET', $crl, 'restapi_h_pki_crl_list', 'system_crlmanager.php', 'List the certificate revocation lists'),
		$route('GET', "{$crl}/{refid}", 'restapi_h_pki_crl_get', 'system_crlmanager.php',
		    'Get a certificate revocation list with its revoked certificates and edit form ("fields")'),
		$route('GET', "{$crl}/{refid}/crl", 'restapi_h_pki_crl_pem', 'system_crlmanager.php',
		    'Download a certificate revocation list (PEM; an internal CRL is signed again, like the page\'s export)', false, null,
		    'text/plain'),
		$route('POST', $crl, 'restapi_h_pki_crl_create', 'system_crlmanager.php',
		    'Create (internal) or import (existing) a certificate revocation list (applied at once)', true, $crl_create),
		$route('PUT', "{$crl}/{refid}", 'restapi_h_pki_crl_update', 'system_crlmanager.php',
		    'Update a certificate revocation list (applied at once; omitted fields keep their value)', true, $crl_update),
		$route('DELETE', "{$crl}/{refid}", 'restapi_h_pki_crl_delete', 'system_crlmanager.php',
		    'Delete a certificate revocation list (409 while in use)', true),
		$route('POST', "{$crl}/{refid}/revoke", 'restapi_h_pki_crl_revoke', 'system_crlmanager.php',
		    'Revoke certificates (by refid) or serial numbers in an internal CRL (applied at once)', true, $crl_revoke),
		$route('DELETE', "{$crl}/{refid}/revoked/{cert}", 'restapi_h_pki_crl_unrevoke', 'system_crlmanager.php',
		    'Remove a revoked certificate (by its refid, or the serial of an entry revoked by serial) from an internal CRL', true),
	);
}

/* ------------------------------------------------------------------ */
/* Pure helpers (no config access)                                     */
/* ------------------------------------------------------------------ */

/* Whether a stored CA or certificate has a private key (the only place the API reads it). */
function restapi_pki_has_key(array $entry) {
	return !empty($entry['prv']);
}

/* A UNIX time as an ISO 8601 UTC time ('' when unknown). */
function restapi_pki_time($ts) {
	if (!is_numeric($ts) || ((int)$ts <= 0)) {
		return '';
	}
	return gmdate('Y-m-d\TH:i:s\Z', (int)$ts);
}

/*
 * The details of a certificate in PEM format: subject, issuer (OpenSSL's
 * one-line forms), serial, validity (ISO 8601 UTC), whether it may act as a
 * CA. Empty strings when it does not parse.
 */
function restapi_pki_cert_details($pem) {
	$out = array('subject' => '', 'issuer' => '', 'serial' => '', 'valid_from' => '', 'valid_until' => '');
	$d = (is_string($pem) && ($pem !== '')) ? @openssl_x509_parse($pem) : false;
	if (!is_array($d)) {
		return $out;
	}
	$dn = function ($parts) {
		$items = array();
		foreach ((array)$parts as $key => $value) {
			foreach ((array)$value as $v) {
				$items[] = "{$key}={$v}";
			}
		}
		return implode(', ', $items);
	};
	$out['subject'] = $dn($d['subject'] ?? array());
	$out['issuer'] = $dn($d['issuer'] ?? array());
	$out['serial'] = (string)($d['serialNumber'] ?? '');
	$out['valid_from'] = restapi_pki_time($d['validFrom_time_t'] ?? 0);
	$out['valid_until'] = restapi_pki_time($d['validTo_time_t'] ?? 0);
	return $out;
}

/* The field types of a new CA (POST) and of the CA edit form (PUT). */
function restapi_pki_ca_create_types() {
	return restapi_ovpn_typemap(array(), array('trust', 'randomserial'), array(),
	    array('method', 'descr', 'cert', 'key', 'serial', 'caref', 'keytype', 'keylen', 'ecname', 'digest_alg', 'lifetime',
	    'dn_commonname', 'dn_country', 'dn_state', 'dn_city', 'dn_organization', 'dn_organizationalunit'));
}

function restapi_pki_ca_edit_types() {
	return restapi_ovpn_typemap(array('refid', 'method'), array('trust', 'randomserial'), array(),
	    array('descr', 'serial', 'cert', 'key'));
}

/*
 * A CA form (pki_ca_form()) in API shape for $types: checkboxes as
 * booleans, selects ($c: field => choices) as the page preselects them,
 * the rest as strings. The private key in clear: mask it before returning.
 */
function restapi_pki_form_fields(array $p, array $types, array $c) {
	$out = array();
	foreach ($types as $field => $type) {
		$value = $p[$field] ?? null;
		if ($type === 'bool') {
			$out[$field] = !empty($value);
		} elseif (array_key_exists($field, $c)) {
			$out[$field] = restapi_ovpn_select($value, $c[$field]);
		} else {
			$out[$field] = is_scalar($value) ? (string)$value : '';
		}
	}
	return $out;
}

/* $fields with the private key read as "(set)" (or "" when there is none). */
function restapi_pki_mask_key(array $fields) {
	if (array_key_exists('key', $fields)) {
		$fields['key'] = restapi_svc_mask(is_scalar($fields['key']) ? $fields['key'] : '');
	}
	return $fields;
}

/* The field types of a new CRL (POST) and of the internal and imported CRL edit forms (PUT). */
function restapi_pki_crl_create_types() {
	return restapi_ovpn_typemap(array(), array(), array(), array('caref', 'method', 'descr', 'crltext', 'lifetime', 'serial'));
}

function restapi_pki_crl_edit_types($internal) {
	return $internal ? restapi_ovpn_typemap(array('refid'), array(), array(), array('descr', 'lifetime', 'serial')) :
	    restapi_ovpn_typemap(array('refid'), array(), array(), array('descr', 'crltext'));
}

/*
 * A revoked certificate entry of a CRL in API shape ($serial: its serial,
 * $reasons: the reason texts). Only these fields: the stored entry is a copy
 * of the certificate, private key included.
 */
function restapi_pki_revoked_out(array $entry, $serial, array $reasons) {
	$reason = (int)($entry['reason'] ?? -1);
	return array(
		'refid' => is_scalar($entry['refid'] ?? null) ? (string)$entry['refid'] : '',
		'descr' => is_scalar($entry['descr'] ?? null) ? (string)$entry['descr'] : '',
		'serial' => is_scalar($serial) ? (string)$serial : '',
		'reason' => $reason,
		'reason_text' => (string)($reasons[$reason] ?? ''),
		'revoke_time' => (int)($entry['revoke_time'] ?? 0),
		'revoked_at' => restapi_pki_time($entry['revoke_time'] ?? 0),
	);
}

/*
 * The revocation request as the CRL edit form posts it (no "save": revoke
 * only). $form: the CRL's current form (descr, lifetime, serial). certref
 * and serials may be text or lists; reason a number.
 */
function restapi_pki_revoke_post(array $body, $refid, array $form) {
	foreach (array_keys($body) as $key) {
		if (!in_array($key, array('certref', 'serials', 'reason'), true)) {
			throw new RestApiError(400, 'unknown_field', "Unknown field \"{$key}\".");
		}
	}
	$list = function ($key) use ($body) {
		$value = $body[$key] ?? array();
		if (is_string($value) || is_int($value)) {
			$value = ($key === 'serials') ? preg_split('/\s+/', trim((string)$value), -1, PREG_SPLIT_NO_EMPTY) :
			    array((string)$value);
		}
		if (!is_array($value) || !array_is_list($value)) {
			throw new RestApiError(400, 'invalid_field', "Field \"{$key}\" must be a string or a list of strings.");
		}
		foreach ($value as $item) {
			if (!is_string($item) && !is_int($item)) {
				throw new RestApiError(400, 'invalid_field', "Field \"{$key}\" must be a string or a list of strings.");
			}
		}
		return array_values(array_filter(array_map('strval', $value), 'strlen'));
	};
	$reason = $body['reason'] ?? -1;
	if (!is_int($reason) && !(is_string($reason) && preg_match('/^-?[0-9]+$/', $reason))) {
		throw new RestApiError(400, 'invalid_field', 'Field "reason" must be a number.');
	}
	$post = array('descr' => (string)($form['descr'] ?? ''), 'lifetime' => (string)($form['lifetime'] ?? ''),
	    'serial' => (string)($form['serial'] ?? ''), 'crlreason' => (string)$reason, 'revokeserial' => implode(' ', $list('serials')),
	    'id' => (string)$refid, 'act' => 'addcert', 'crlref' => (string)$refid);
	$certs = $list('certref');
	if (!empty($certs)) {
		$post['certref'] = $certs;
	}
	return $post;
}

/*
 * Only the PEM blocks labelled $labels (e.g. CERTIFICATE) of a stored text,
 * each on its own line ('' when there is none). A certificate field may hold
 * anything that was pasted with the certificate, a private key included;
 * returned certificates and requests go through this.
 */
function restapi_pki_pem_only($text, array $labels) {
	if (!is_string($text) || ($text === '')) {
		return '';
	}
	$alt = implode('|', array_map(function ($l) {
		return preg_quote($l, '/');
	}, $labels));
	if (!preg_match_all('/-----BEGIN (' . $alt . ')-----[A-Za-z0-9+\/=\s]*-----END \1-----/', $text, $m)) {
		return '';
	}
	return implode("\n", $m[0]) . "\n";
}

/* The labels of certificate and signing request PEM blocks. */
function restapi_pki_cert_labels() {
	return array('CERTIFICATE');
}

function restapi_pki_csr_labels() {
	return array('CERTIFICATE REQUEST', 'NEW CERTIFICATE REQUEST');
}

/*
 * Subject alternative names as OpenSSL lists them (cert_get_sans():
 * "DNS:host", "IP Address:192.0.2.1", "email:...", "URI:...") as a list of
 * {type, value} with the page's types (DNS, IP, URI, email).
 */
function restapi_pki_san_list(array $sans) {
	$map = array('DNS' => 'DNS', 'IP Address' => 'IP', 'IP' => 'IP', 'URI' => 'URI', 'email' => 'email');
	$out = array();
	foreach ($sans as $san) {
		$san = trim((string)$san);
		$pos = strpos($san, ':');
		if (($san === '') || ($pos === false)) {
			continue;
		}
		$type = substr($san, 0, $pos);
		$out[] = array('type' => $map[$type] ?? $type, 'value' => substr($san, $pos + 1));
	}
	return $out;
}

/*
 * The subject alternative names of a request body (list of {type, value})
 * as the page's rows (altname_type0/altname_value0, ...); 400 when malformed.
 */
function restapi_pki_altname_post($altnames) {
	$msg = 'Field "altnames" must be a list of {type, value}.';
	if (!is_array($altnames) || !array_is_list($altnames)) {
		throw new RestApiError(400, 'invalid_field', $msg);
	}
	$post = array();
	foreach ($altnames as $i => $altname) {
		if (!is_array($altname) || array_is_list($altname) && !empty($altname)) {
			throw new RestApiError(400, 'invalid_field', $msg);
		}
		foreach (array_keys($altname) as $key) {
			if (!in_array($key, array('type', 'value'), true)) {
				throw new RestApiError(400, 'invalid_field', $msg);
			}
		}
		$type = $altname['type'] ?? '';
		$value = $altname['value'] ?? '';
		if (!is_string($type) || !is_string($value)) {
			throw new RestApiError(400, 'invalid_field', $msg);
		}
		$post["altname_type{$i}"] = $type;
		$post["altname_value{$i}"] = $value;
	}
	return $post;
}

/* The field types of a new certificate (POST; altnames and pkcs12 are converted separately) and of the edit form (PUT). */
function restapi_pki_cert_create_types() {
	return restapi_ovpn_typemap(array(), array('autorenew', 'pkcs12_intermediate'), array(),
	    array('method', 'descr', 'type', 'caref', 'keytype', 'keylen', 'ecname', 'digest_alg', 'lifetime', 'dn_commonname', 'dn_country',
	    'dn_state', 'dn_city', 'dn_organization', 'dn_organizationalunit', 'import_type', 'cert', 'key', 'pkcs12_pass', 'csr_keytype',
	    'csr_keylen', 'csr_ecname', 'csr_digest_alg', 'csr_dn_commonname', 'csr_dn_country', 'csr_dn_state', 'csr_dn_city',
	    'csr_dn_organization', 'csr_dn_organizationalunit', 'catosignwith', 'csrtosign', 'csrpaste', 'keypaste', 'csrsign_lifetime',
	    'csrsign_digest_alg'));
}

function restapi_pki_cert_edit_types() {
	return restapi_ovpn_typemap(array(), array('autorenew'), array(), array('descr', 'cert', 'key'));
}

/*
 * The new certificate form as a browser posts it: the page's defaults
 * (pki_cert_new_form()) and the fields the page leaves empty or preselects
 * (sign: a new pasted request, the default lifetime; import: X.509).
 */
function restapi_pki_cert_new_form(array $defaults, $lifetime) {
	return $defaults + array('descr' => '', 'caref' => '', 'catosignwith' => '', 'csrtosign' => 'new', 'csrpaste' => '', 'keypaste' => '',
	    'csrsign_lifetime' => $lifetime, 'import_type' => 'x509', 'cert' => '', 'key' => '', 'pkcs12_pass' => '');
}

/*
 * The body of a new certificate (POST) as the page's post: fields merged over
 * $current (the new form in API shape, see restapi_pki_form_fields()),
 * altnames as rows, the PKCS #12 file decoded. Returns array(post, pkcs12
 * file or null).
 */
function restapi_pki_cert_create_post(array $body, array $current) {
	$types = restapi_pki_cert_create_types();
	$rows = array();
	if (array_key_exists('altnames', $body)) {
		$rows = restapi_pki_altname_post($body['altnames']);
		unset($body['altnames']);
	}
	$pkcs12 = null;
	if (array_key_exists('pkcs12', $body)) {
		if (!is_string($body['pkcs12'])) {
			throw new RestApiError(400, 'invalid_field', 'Field "pkcs12" must be a string (the PKCS #12 file, base64).');
		}
		if ($body['pkcs12'] !== '') {
			$pkcs12 = base64_decode($body['pkcs12'], true);
			if (($pkcs12 === false) || ($pkcs12 === '')) {
				throw new RestApiError(400, 'invalid_field', 'Field "pkcs12" must be the PKCS #12 file in base64.');
			}
		}
		unset($body['pkcs12']);
	}
	$post = restapi_svc_post(restapi_svc_merge($body, $current, $types), $types) + $rows;
	$post['act'] = 'new';
	$post['save'] = 'Save';
	return array($post, $pkcs12);
}

/* The subject of a signing request in PEM format (OpenSSL's one-line form; '' when it does not parse). */
function restapi_pki_csr_subject($pem) {
	$d = (is_string($pem) && ($pem !== '')) ? @openssl_csr_get_subject($pem, true) : false;
	if (!is_array($d)) {
		return '';
	}
	$items = array();
	foreach ($d as $key => $value) {
		foreach ((array)$value as $v) {
			$items[] = "{$key}={$v}";
		}
	}
	return implode(', ', $items);
}

/* ------------------------------------------------------------------ */
/* Certificate authorities                                             */
/* ------------------------------------------------------------------ */

/* The CA with refid $refid (its config item), or a 404. */
function restapi_pki_ca_item($refid) {
	$item = ctype_alnum((string)$refid) ? lookup_ca((string)$refid) : array('item' => null);
	if (empty($item['item'])) {
		throw new RestApiError(404, 'not_found', 'No certificate authority with that refid.');
	}
	return $item['item'];
}

/* The choices of the new CA form. */
function restapi_pki_ca_choices() {
	global $openssl_digest_algs;
	return array(
		'method' => pki_ca_methods(),
		'caref' => pki_ca_signing_list(),
		'keytype' => array_combine(pki_key_types(), pki_key_types()),
		'keylen' => array_combine(pki_key_lengths(), pki_key_lengths()),
		'ecname' => cert_build_curve_list(),
		'digest_alg' => array_combine($openssl_digest_algs, $openssl_digest_algs),
		'dn_country' => get_cert_country_codes(),
	);
}

/* A CA in API shape: its summary, or (full) also its certificate (PEM), CRLs and edit form. Never its private key. */
function restapi_pki_ca_out(array $ca, $full, $packages = null) {
	if ($packages === null) {
		$packages = pki_package_usage('used_ca');
	}
	$pem = base64_decode((string)($ca['crt'] ?? ''));
	$details = restapi_pki_cert_details($pem);
	$issuer_name = ($details['subject'] === $details['issuer']) ? 'self-signed' : 'external';
	$issuer_ca = lookup_ca($ca['caref'] ?? '');
	if (!empty($issuer_ca['item'])) {
		$issuer_name = (string)$issuer_ca['item']['descr'];
	}
	$certcount = 0;
	foreach (array_merge((array)config_get_path('cert', []), (array)config_get_path('ca', [])) as $entry) {
		if (is_array($entry) && (($entry['caref'] ?? null) == $ca['refid'])) {
			$certcount++;
		}
	}
	$out = array(
		'refid' => (string)$ca['refid'],
		'descr' => (string)($ca['descr'] ?? ''),
		'caref' => (string)($ca['caref'] ?? ''),
		'issuer_name' => $issuer_name,
	) + $details + array(
		'has_private_key' => restapi_pki_has_key($ca),
		'trust' => (($ca['trust'] ?? '') === 'enabled'),
		'randomserial' => (($ca['randomserial'] ?? '') === 'enabled'),
		'next_serial' => is_scalar($ca['serial'] ?? null) ? (string)$ca['serial'] : '',
		'certificates' => $certcount,
		'in_use' => ca_in_use($ca['refid']),
		'used_by' => pki_ca_used_by($ca['refid'], $packages),
	);
	if (!$full) {
		return $out;
	}
	$crls = array();
	foreach ((array)config_get_path('crl', []) as $crl) {
		if (is_array($crl) && (($crl['caref'] ?? null) == $ca['refid']) && isset($crl['refid'])) {
			$crls[] = (string)$crl['refid'];
		}
	}
	$fields = restapi_pki_form_fields(pki_ca_form('edit', $ca), restapi_pki_ca_edit_types(), array());
	$fields['cert'] = restapi_pki_pem_only($fields['cert'], restapi_pki_cert_labels());
	return $out + array('certificate' => restapi_pki_pem_only($pem, restapi_pki_cert_labels()), 'crls' => $crls,
	    'fields' => restapi_pki_mask_key($fields),
	    'choices' => array_map('restapi_svc_choices', restapi_pki_ca_choices()));
}

function restapi_h_pki_ca_list($req) {
	$packages = pki_package_usage('used_ca');
	$out = array();
	foreach ((array)config_get_path('ca', []) as $ca) {
		if (is_array($ca) && isset($ca['refid'])) {
			$out[] = restapi_pki_ca_out($ca, false, $packages);
		}
	}
	return array('data' => $out);
}

function restapi_h_pki_ca_get($req) {
	return array('data' => restapi_pki_ca_out(restapi_pki_ca_item($req['params']['refid']), true));
}

function restapi_h_pki_ca_pem($req) {
	$ca = restapi_pki_ca_item($req['params']['refid']);
	return array('raw' => restapi_pki_pem_only(base64_decode((string)($ca['crt'] ?? '')), restapi_pki_cert_labels()), 'type' => 'text/plain');
}

function restapi_h_pki_ca_create($req) {
	$body = $req['body'];
	$c = restapi_pki_ca_choices();
	$current = restapi_pki_form_fields(pki_ca_form('new', null, null), restapi_pki_ca_create_types(), $c);
	$values = restapi_svc_merge($body, $current, restapi_pki_ca_create_types());
	$post = restapi_svc_post($values, restapi_pki_ca_create_types());
	$post['save'] = 'Save';
	$before = array_keys((array)config_get_path('ca', []));
	$errors = pki_ca_save($post, null, 'new');
	if (!empty($errors)) {
		throw restapi_validation_error($errors);
	}
	$cas = (array)config_get_path('ca', []);
	$new = array_diff(array_keys($cas), $before);
	$ca = $cas[empty($new) ? array_key_last($cas) : reset($new)];
	return array('status' => 201, 'data' => restapi_pki_ca_out($ca, true));
}

function restapi_h_pki_ca_update($req) {
	$ca = restapi_pki_ca_item($req['params']['refid']);
	$body = restapi_vpn_secret_body($req['body'], 'key');
	$types = restapi_pki_ca_edit_types();
	$current = restapi_pki_form_fields(pki_ca_form('edit', $ca), $types, array());
	$values = restapi_svc_merge($body, $current, $types);
	$post = restapi_svc_post($values, $types);
	$post['method'] = 'existing';
	$post['refid'] = (string)$ca['refid'];
	$post['id'] = (string)$ca['refid'];
	$post['save'] = 'Save';
	$errors = pki_ca_save($post, (string)$ca['refid'], 'edit');
	if (!empty($errors)) {
		throw restapi_validation_error($errors);
	}
	return array('data' => restapi_pki_ca_out(restapi_pki_ca_item($ca['refid']), true));
}

function restapi_h_pki_ca_delete($req) {
	$ca = restapi_pki_ca_item($req['params']['refid']);
	$rv = pki_ca_delete((string)$ca['refid']);
	if ($rv === null) {
		throw new RestApiError(404, 'not_found', 'No certificate authority with that refid.');
	}
	if (!$rv['deleted']) {
		$e = restapi_validation_error(array($rv['savemsg']));
		throw new RestApiError(409, 'in_use', $e->details['messages'][0],
		    array('used_by' => pki_ca_used_by($ca['refid'], pki_package_usage('used_ca'))));
	}
	return array('data' => array('deleted' => (string)$ca['refid']));
}

/* ------------------------------------------------------------------ */
/* Certificate revocation lists                                        */
/* ------------------------------------------------------------------ */

/* The CRL with refid $refid (lookup_crl(): its index and item), or a 404. */
function restapi_pki_crl_item($refid) {
	$item = ctype_alnum((string)$refid) ? lookup_crl((string)$refid) : array('item' => null);
	if (empty($item['item']) || !is_array($item['item'])) {
		throw new RestApiError(404, 'not_found', 'No certificate revocation list with that refid.');
	}
	return $item;
}

/* The edit form of a CRL in API shape (internal: descr, lifetime, serial; imported: descr, crltext). */
function restapi_pki_crl_fields(array $crl) {
	if (is_crl_internal($crl)) {
		return array('descr' => (string)($crl['descr'] ?? ''), 'lifetime' => is_scalar($crl['lifetime'] ?? null) ? (string)$crl['lifetime'] : '',
		    'serial' => is_scalar($crl['serial'] ?? null) ? (string)$crl['serial'] : '');
	}
	return array('descr' => (string)($crl['descr'] ?? ''), 'crltext' => !empty($crl['text']) ? base64_decode($crl['text']) : '');
}

/* A CRL in API shape: its summary, or (full) also its revoked certificates and edit form. */
function restapi_pki_crl_out(array $crl, $full, $packages = null) {
	global $openssl_crl_status;
	if ($packages === null) {
		$packages = pki_package_usage('used_crl');
	}
	$internal = is_crl_internal($crl);
	$ca = lookup_ca($crl['caref'] ?? '');
	$revoked = is_array($crl['cert'] ?? null) ? $crl['cert'] : array();
	$out = array(
		'refid' => (string)$crl['refid'],
		'descr' => (string)($crl['descr'] ?? ''),
		'caref' => (string)($crl['caref'] ?? ''),
		'ca' => (string)($ca['item']['descr'] ?? ''),
		'method' => $internal ? 'internal' : 'existing',
		'internal' => $internal,
		'serial' => is_scalar($crl['serial'] ?? null) ? (string)$crl['serial'] : '',
		'lifetime' => is_scalar($crl['lifetime'] ?? null) ? (string)$crl['lifetime'] : '',
		'revoked_count' => $internal ? count($revoked) : null,
		'in_use' => (bool)crl_in_use($crl['refid']),
		'used_by' => pki_crl_used_by($crl['refid'], $packages),
	);
	if (!$full) {
		return $out;
	}
	$list = array();
	foreach ($revoked as $entry) {
		if (is_array($entry)) {
			$list[] = restapi_pki_revoked_out($entry, crl_get_entry_serial($entry), (array)$openssl_crl_status);
		}
	}
	return $out + array('revoked' => $list, 'fields' => restapi_pki_crl_fields($crl));
}

function restapi_h_pki_crl_list($req) {
	$packages = pki_package_usage('used_crl');
	$out = array();
	foreach ((array)config_get_path('crl', []) as $crl) {
		if (is_array($crl) && isset($crl['refid'])) {
			$out[] = restapi_pki_crl_out($crl, false, $packages);
		}
	}
	return array('data' => $out);
}

function restapi_h_pki_crl_get($req) {
	return array('data' => restapi_pki_crl_out(restapi_pki_crl_item($req['params']['refid'])['item'], true));
}

function restapi_h_pki_crl_pem($req) {
	$item = restapi_pki_crl_item($req['params']['refid']);
	$ca = lookup_ca($item['item']['caref'] ?? '');
	/* An internal CRL is signed again like the page's export (in memory only); it needs its CA's private key. */
	if (is_crl_internal($item['item']) && !empty($ca['item']) && restapi_pki_has_key($ca['item'])) {
		$pem = pki_crl_export($item);
	} else {
		$pem = !empty($item['item']['text']) ? base64_decode($item['item']['text']) : '';
	}
	if ($pem === '') {
		throw new RestApiError(404, 'not_found', 'The certificate revocation list has no data.');
	}
	return array('raw' => $pem, 'type' => 'text/plain');
}

function restapi_h_pki_crl_create($req) {
	$body = $req['body'];
	$caref = $body['caref'] ?? '';
	if (!is_string($caref)) {
		throw new RestApiError(400, 'invalid_field', 'Field "caref" must be a string.');
	}
	$method = $body['method'] ?? null;
	$rv = pki_crl_new_form(is_string($method) ? $method : null, $caref);
	if (!empty($rv['input_errors'])) {
		throw restapi_validation_error($rv['input_errors']);
	}
	$p = $rv['pconfig'];
	$methods = pki_crl_method_list(!restapi_pki_has_key((array)$rv['ca']));
	$current = array('caref' => (string)$p['caref'], 'method' => restapi_ovpn_select($p['method'], $methods), 'descr' => '',
	    'crltext' => '', 'lifetime' => (string)$p['lifetime'], 'serial' => (string)$p['serial']);
	$types = restapi_pki_crl_create_types();
	$post = restapi_svc_post(restapi_svc_merge($body, $current, $types), $types);
	$post['save'] = 'Save';
	$before = array_keys((array)config_get_path('crl', []));
	$none = null;
	$errors = pki_crl_save($post, $none, 'new');
	if (!empty($errors)) {
		throw restapi_validation_error($errors);
	}
	$crls = (array)config_get_path('crl', []);
	$new = array_diff(array_keys($crls), $before);
	$crl = $crls[empty($new) ? array_key_last($crls) : reset($new)];
	return array('status' => 201, 'data' => restapi_pki_crl_out($crl, true));
}

function restapi_h_pki_crl_update($req) {
	$item = restapi_pki_crl_item($req['params']['refid']);
	$refid = (string)$item['item']['refid'];
	$internal = is_crl_internal($item['item']);
	$types = restapi_pki_crl_edit_types($internal);
	$values = restapi_svc_merge($req['body'], restapi_pki_crl_fields($item['item']), $types);
	$post = restapi_svc_post($values, $types);
	$post['id'] = $refid;
	$post['save'] = 'Save';
	if ($internal) {
		/* the internal CRL edit form (its Save button) */
		$post['act'] = 'addcert';
		$post['crlref'] = $refid;
		$errors = pki_crl_revoke($post, $post);
	} else {
		$post['act'] = 'editimported';
		$errors = pki_crl_save($post, $item, 'editimported');
	}
	if (!empty($errors)) {
		throw restapi_validation_error($errors);
	}
	return array('data' => restapi_pki_crl_out(restapi_pki_crl_item($refid)['item'], true));
}

function restapi_h_pki_crl_delete($req) {
	$item = restapi_pki_crl_item($req['params']['refid']);
	$refid = (string)$item['item']['refid'];
	$rv = pki_crl_delete($refid);
	if ($rv === null) {
		throw new RestApiError(404, 'not_found', 'No certificate revocation list with that refid.');
	}
	if (!$rv['deleted']) {
		$e = restapi_validation_error(array($rv['savemsg']));
		throw new RestApiError(409, 'in_use', $e->details['messages'][0],
		    array('used_by' => pki_crl_used_by($refid, pki_package_usage('used_crl'))));
	}
	return array('data' => array('deleted' => $refid));
}

function restapi_h_pki_crl_revoke($req) {
	$item = restapi_pki_crl_item($req['params']['refid']);
	$refid = (string)$item['item']['refid'];
	$post = restapi_pki_revoke_post($req['body'], $refid, restapi_pki_crl_fields($item['item']) + array('lifetime' => '', 'serial' => ''));
	$errors = pki_crl_revoke($post, $post);
	if (!empty($errors)) {
		throw restapi_validation_error($errors);
	}
	return array('data' => restapi_pki_crl_out(restapi_pki_crl_item($refid)['item'], true));
}

function restapi_h_pki_crl_unrevoke($req) {
	$item = restapi_pki_crl_item($req['params']['refid']);
	$refid = (string)$item['item']['refid'];
	$cert = (string)$req['params']['cert'];
	$by_refid = false;
	foreach ((array)($item['item']['cert'] ?? array()) as $entry) {
		if (is_array($entry) && !empty($entry['refid']) && ((string)$entry['refid'] === $cert)) {
			$by_refid = true;
		}
	}
	$rv = $by_refid ? pki_crl_unrevoke($item, $cert) : pki_crl_unrevoke($item, '', $cert);
	if ($rv === null) {
		throw new RestApiError(404, 'not_found', 'The certificate revocation list has no revoked certificate with that refid or serial.');
	}
	if (!$rv['removed']) {
		throw restapi_validation_error(array($rv['savemsg']));
	}
	return array('data' => restapi_pki_crl_out(restapi_pki_crl_item($refid)['item'], true));
}

/* ------------------------------------------------------------------ */
/* Certificates                                                        */
/* ------------------------------------------------------------------ */

/* The certificate with refid $refid (lookup_cert(): its index and item), or a 404. */
function restapi_pki_cert_item($refid) {
	$item = ctype_alnum((string)$refid) ? lookup_cert((string)$refid) : array('item' => null);
	if (empty($item['item']) || !is_array($item['item'])) {
		throw new RestApiError(404, 'not_found', 'No certificate with that refid.');
	}
	return $item;
}

/* The choices of the new certificate form. */
function restapi_pki_cert_choices() {
	global $openssl_digest_algs, $cert_altname_types;
	$digests = array_combine($openssl_digest_algs, $openssl_digest_algs);
	$keytypes = array_combine(pki_key_types(), pki_key_types());
	$keylens = array_combine(pki_key_lengths(), pki_key_lengths());
	return array(
		'method' => pki_cert_methods(),
		'type' => pki_cert_types(),
		'caref' => pki_ca_signing_list(),
		'catosignwith' => pki_ca_signing_list(),
		'csrtosign' => pki_cert_csr_list(),
		'keytype' => $keytypes,
		'csr_keytype' => $keytypes,
		'keylen' => $keylens,
		'csr_keylen' => $keylens,
		'ecname' => cert_build_curve_list(),
		'csr_ecname' => cert_build_curve_list(),
		'digest_alg' => $digests,
		'csr_digest_alg' => $digests,
		'csrsign_digest_alg' => $digests,
		'dn_country' => get_cert_country_codes(),
		'csr_dn_country' => get_cert_country_codes(),
		'import_type' => array('x509' => 'X.509 (PEM)', 'pkcs12' => 'PKCS #12 (PFX)'),
		'altname_type' => (array)$cert_altname_types,
	);
}

/*
 * A certificate in API shape: its summary, or (full) also its certificate or
 * signing request (PEM blocks only), purpose and edit form. Never its private key.
 */
function restapi_pki_cert_out(array $cert, $full, $packages = null) {
	if ($packages === null) {
		$packages = pki_package_usage('used_certificates');
	}
	$refid = (string)$cert['refid'];
	$crt = base64_decode((string)($cert['crt'] ?? ''));
	$csr = base64_decode((string)($cert['csr'] ?? ''));
	$is_csr = ($csr !== '');
	$details = restapi_pki_cert_details($crt);
	/* the page's Issuer column */
	if ($crt !== '') {
		$issuer_name = ($details['subject'] === $details['issuer']) ? 'self-signed' : 'external';
	} else {
		$issuer_name = 'private key only';
	}
	if ($is_csr) {
		$issuer_name = 'external - signature pending';
		$details['subject'] = restapi_pki_csr_subject($csr);
	}
	$ca = lookup_ca($cert['caref'] ?? '');
	if (!empty($ca['item'])) {
		$issuer_name = (string)$ca['item']['descr'];
	}
	$out = array(
		'refid' => $refid,
		'descr' => (string)($cert['descr'] ?? ''),
		'type' => is_scalar($cert['type'] ?? null) ? (string)$cert['type'] : '',
		'caref' => is_scalar($cert['caref'] ?? null) ? (string)$cert['caref'] : '',
		'issuer_name' => $issuer_name,
	) + $details + array(
		'sans' => ($crt !== '') ? restapi_pki_san_list((array)cert_get_sans($crt, false)) : array(),
		'has_private_key' => restapi_pki_has_key($cert),
		'is_csr' => $is_csr,
		'autorenew' => (($cert['autorenew'] ?? '') === 'enabled'),
		'revoked' => (bool)is_cert_revoked($cert),
		'in_use' => (bool)cert_in_use($refid),
		'used_by' => pki_cert_used_by($refid, $packages),
	);
	if (!$full) {
		return $out;
	}
	$purpose = ($crt !== '') ? cert_get_purpose($crt, false) : array();
	$extra = array(
		'certificate' => restapi_pki_pem_only($crt, restapi_pki_cert_labels()),
		'csr' => restapi_pki_pem_only($csr, restapi_pki_csr_labels()),
		'purpose' => array(
			'ca' => (($purpose['ca'] ?? '') === 'Yes'),
			'server' => (($purpose['server'] ?? '') === 'Yes'),
			'key_usage' => array_values(array_map('strval', (array)($purpose['ku'] ?? array()))),
			'extended_key_usage' => array_values(array_map('strval', (array)($purpose['eku'] ?? array()))),
		),
		'renewable' => (bool)is_cert_locally_renewable($cert),
	);
	if ($is_csr) {
		$extra['fields'] = array('descr' => (string)($cert['descr'] ?? ''), 'csr' => $extra['csr']);
	} else {
		$fields = restapi_pki_form_fields(pki_cert_form('edit', $cert), restapi_pki_cert_edit_types(), array());
		$fields['cert'] = restapi_pki_pem_only($fields['cert'], restapi_pki_cert_labels());
		$extra['fields'] = restapi_pki_mask_key($fields);
	}
	return $out + $extra;
}

function restapi_h_pki_cert_list($req) {
	$packages = pki_package_usage('used_certificates');
	$out = array();
	foreach ((array)config_get_path('cert', []) as $cert) {
		if (is_array($cert) && isset($cert['refid'])) {
			$out[] = restapi_pki_cert_out($cert, false, $packages);
		}
	}
	return array('data' => $out);
}

function restapi_h_pki_cert_get($req) {
	return array('data' => restapi_pki_cert_out(restapi_pki_cert_item($req['params']['refid'])['item'], true));
}

function restapi_h_pki_cert_pem($req) {
	$cert = restapi_pki_cert_item($req['params']['refid'])['item'];
	$pem = restapi_pki_pem_only(base64_decode((string)($cert['crt'] ?? '')), restapi_pki_cert_labels());
	if ($pem === '') {
		throw new RestApiError(404, 'not_found', 'The entry has no certificate (a pending signing request?).');
	}
	return array('raw' => $pem, 'type' => 'text/plain');
}

function restapi_h_pki_cert_csr($req) {
	$cert = restapi_pki_cert_item($req['params']['refid'])['item'];
	$pem = restapi_pki_pem_only(base64_decode((string)($cert['csr'] ?? '')), restapi_pki_csr_labels());
	if ($pem === '') {
		throw new RestApiError(404, 'not_found', 'The certificate has no pending signing request.');
	}
	return array('raw' => $pem, 'type' => 'text/plain');
}

function restapi_h_pki_cert_create($req) {
	$c = restapi_pki_cert_choices();
	unset($c['altname_type']);
	$form = restapi_pki_cert_new_form(pki_cert_new_form(null), pki_cert_default_lifetime());
	$current = restapi_pki_form_fields($form, restapi_pki_cert_create_types(), $c);
	list($post, $pkcs12) = restapi_pki_cert_create_post($req['body'], $current);
	$before = array();
	foreach ((array)config_get_path('cert', []) as $cert) {
		$before[] = (string)($cert['refid'] ?? '');
	}
	$errors = pki_cert_save($post, null, 'new', null, $pkcs12);
	if (!empty($errors)) {
		throw restapi_validation_error($errors);
	}
	$new = null;
	foreach ((array)config_get_path('cert', []) as $cert) {
		if (is_array($cert) && isset($cert['refid']) && !in_array((string)$cert['refid'], $before, true)) {
			$new = $cert;
		}
	}
	if ($new === null) {
		throw new RestApiError(500, 'internal_error', 'The certificate was not stored.');
	}
	return array('status' => 201, 'data' => restapi_pki_cert_out($new, true));
}

function restapi_h_pki_cert_update($req) {
	$cert = restapi_pki_cert_item($req['params']['refid'])['item'];
	$refid = (string)$cert['refid'];
	if (!empty($cert['csr'])) {
		throw new RestApiError(409, 'csr_pending', 'The certificate signing request is pending: complete it with POST ' .
		    "/api/v1/pki/certificates/{$refid}/complete.");
	}
	$body = restapi_vpn_secret_body($req['body'], 'key');
	$types = restapi_pki_cert_edit_types();
	$current = restapi_pki_form_fields(pki_cert_form('edit', $cert), $types, array());
	$post = restapi_svc_post(restapi_svc_merge($body, $current, $types), $types);
	$post['method'] = 'edit';
	$post['import_type'] = 'x509';
	$post['id'] = $refid;
	$post['act'] = 'edit';
	$post['save'] = 'Save';
	$errors = pki_cert_save($post, $refid, 'edit');
	if (!empty($errors)) {
		throw restapi_validation_error($errors);
	}
	return array('data' => restapi_pki_cert_out(restapi_pki_cert_item($refid)['item'], true));
}

function restapi_h_pki_cert_complete($req) {
	$item = restapi_pki_cert_item($req['params']['refid']);
	$refid = (string)$item['item']['refid'];
	if (empty($item['item']['csr'])) {
		throw new RestApiError(409, 'not_pending', 'The certificate has no pending signing request.');
	}
	$body = $req['body'];
	foreach ($body as $key => $value) {
		if (!in_array($key, array('certificate', 'descr'), true)) {
			throw new RestApiError(400, 'unknown_field', "Unknown field \"{$key}\".");
		}
		if (!is_string($value)) {
			throw new RestApiError(400, 'invalid_field', "Field \"{$key}\" must be a string.");
		}
	}
	$form = pki_cert_form('csr', $item['item']);
	$post = array('descr' => $body['descr'] ?? (string)$form['descr'], 'csr' => (string)$form['csr'],
	    'cert' => $body['certificate'] ?? '', 'id' => $refid, 'act' => 'csr', 'save' => 'Update');
	$errors = pki_cert_csr_complete($post, $item);
	if (!empty($errors)) {
		throw restapi_validation_error($errors);
	}
	return array('data' => restapi_pki_cert_out(restapi_pki_cert_item($refid)['item'], true));
}

function restapi_h_pki_cert_delete($req) {
	$cert = restapi_pki_cert_item($req['params']['refid'])['item'];
	$refid = (string)$cert['refid'];
	$rv = pki_cert_delete($refid);
	if ($rv === null) {
		throw new RestApiError(404, 'not_found', 'No certificate with that refid.');
	}
	if (!$rv['deleted']) {
		$e = restapi_validation_error(array($rv['savemsg']));
		throw new RestApiError(409, 'in_use', $e->details['messages'][0],
		    array('used_by' => pki_cert_used_by($refid, pki_package_usage('used_certificates'))));
	}
	return array('data' => array('deleted' => $refid));
}
