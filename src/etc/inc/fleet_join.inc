<?php
/*
 * fleet_join.inc
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
 * Joining a FreeSense Fleet controller: the join link and join key, the
 * proofs both sides compute, and the client that runs the handshake with
 * a rollback if any step fails.
 *
 *   link   https://<controller>:<port>/fleet/join#<token-id>.<token-secret>.<cert-sha256>
 *          (single use; the fragment never leaves the box and is the pin)
 *   key    FLT-XXXX-XXXX-XXXX-XXXX-XXXX (95 bits + a check character), sent by
 *          another channel, never in the link, logs or user data afterwards
 *
 * Handshake (every request is TLS pinned to the link's fingerprint):
 *   1 POST begin     bearer token-secret            -> challenge
 *   2 POST enroll    nonce, node WireGuard key, proof over the challenge
 *   3 GET  status    until the admin approved, both screens show the code
 *   4 POST complete  the node's new API key, proof over its hash
 * The controller verifies the proofs with the same HKDF/HMAC (see
 * fleet_join_proof()); the test vectors are in tests/FleetJoinSmokeTest.php.
 *
 * Functions marked "pure" touch neither the system nor config.xml.
 */

require_once('fleet_node.inc');

define('FLEET_JOIN_INFO', 'FreeSense-Fleet-Node-Join-v1');
define('FLEET_JOIN_ALPHABET', '0123456789ABCDEFGHJKMNPQRSTVWXYZ');
define('FLEET_JOIN_KEY_DATA_CHARS', 19);
/* The steps both screens show, in order (the Activity Center step ids). */
define('FLEET_JOIN_STEPS', array('connect', 'verify', 'prove', 'approval', 'tunnel', 'heartbeat'));

class FleetJoinError extends Exception {
	/** @var string FLEET-E code, e.g. "E101" */
	public $fleet_code;

	public function __construct(string $fleet_code, string $message) {
		parent::__construct($message);
		$this->fleet_code = $fleet_code;
	}
}

/* ------------------------------------------------------------------ */
/* Join key                                                            */
/* ------------------------------------------------------------------ */

/* pure: the check character of the 19 data characters. */
function fleet_join_key_check(string $data): string {
	return FLEET_JOIN_ALPHABET[ord(hash('sha256', $data, true)[0]) >> 3];
}

/* pure: a new random join key; returns array(display form, 19 data characters). */
function fleet_join_key_generate(): array {
	$data = '';
	for ($i = 0; $i < FLEET_JOIN_KEY_DATA_CHARS; $i++) {
		$data .= FLEET_JOIN_ALPHABET[random_int(0, 31)];
	}
	return array('FLT-' . implode('-', str_split($data . fleet_join_key_check($data), 4)), $data);
}

/*
 * pure: what an admin typed -> the 19 data characters, or null. Case and
 * dashes don't matter; O reads as 0 and I or L as 1 (Crockford).
 */
function fleet_join_key_normalize(string $typed): ?string {
	$s = preg_replace('/[\s-]+/', '', strtoupper($typed));
	if (strpos($s, 'FLT') === 0) {
		$s = substr($s, 3);
	}
	$s = strtr($s, array('O' => '0', 'I' => '1', 'L' => '1'));
	if (!preg_match('/^[' . FLEET_JOIN_ALPHABET . ']{' . (FLEET_JOIN_KEY_DATA_CHARS + 1) . '}$/D', $s)) {
		return null;
	}
	$data = substr($s, 0, FLEET_JOIN_KEY_DATA_CHARS);
	return hash_equals(fleet_join_key_check($data), $s[FLEET_JOIN_KEY_DATA_CHARS]) ? $data : null;
}

/* ------------------------------------------------------------------ */
/* Join link                                                           */
/* ------------------------------------------------------------------ */

/* pure: a host name, IPv4 address or bracketed IPv6 address, with an optional port. */
function fleet_join_valid_authority(string $authority): bool {
	if (!preg_match('/^(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9]([A-Za-z0-9.-]{0,251}[A-Za-z0-9])?)(:([0-9]{1,5}))?$/D', $authority, $m)) {
		return false;
	}
	return !isset($m[4]) || (((int)$m[4] >= 1) && ((int)$m[4] <= 65535));
}

/* pure: build a join link. */
function fleet_join_link_build(string $authority, string $token_id, string $token_secret, string $cert_sha256): string {
	return "https://{$authority}/fleet/join#{$token_id}.{$token_secret}." . strtolower($cert_sha256);
}

/* pure: parse a join link; returns array(authority, host, port, token_id, token_secret, cert_sha256) or throws E102. */
function fleet_join_link_parse(string $link): array {
	$link = trim($link);
	if (!preg_match('~^https://([^/#?\s]+)/fleet/join#([0-9a-f]{12})\.([A-Za-z0-9_-]{43})\.([0-9a-f]{64})$~D', $link, $m) ||
	    !fleet_join_valid_authority($m[1])) {
		throw new FleetJoinError('E102', 'This is not a FreeSense Fleet join link.');
	}
	preg_match('/^(\[[^\]]+\]|[^:]+)(?::([0-9]+))?$/D', $m[1], $a);
	return array('authority' => $m[1], 'host' => trim($a[1], '[]'), 'port' => (int)($a[2] ?? 443),
	    'token_id' => $m[2], 'token_secret' => $m[3], 'cert_sha256' => $m[4]);
}

/* ------------------------------------------------------------------ */
/* Proofs                                                              */
/* ------------------------------------------------------------------ */

/* pure: the key material both sides derive from the join key and the pinned certificate. */
function fleet_join_okm(string $key_data, string $cert_sha256_hex): string {
	return hash_hkdf('sha256', $key_data, 32, FLEET_JOIN_INFO, (string)hex2bin($cert_sha256_hex));
}

/* pure: the node's proof that it knows the join key, bound to this exchange. */
function fleet_join_proof(string $okm, string $challenge, string $nonce, string $token_id, string $node_wg_pub,
    string $cert_sha256_hex): string {
	return hash_hmac('sha256', "enroll\0" . $challenge . $nonce . $token_id . $node_wg_pub . hex2bin($cert_sha256_hex), $okm);
}

/* pure: the proof sent with the node's new API key; the controller learns the key's hash, nobody else the key. */
function fleet_join_complete_proof(string $okm, string $enrollment_id, string $api_key): string {
	return hash_hmac('sha256', "complete\0" . $enrollment_id . hash('sha256', $api_key, true), $okm);
}

/* pure: the six digits both screens show; the admin approves only when they match. */
function fleet_join_code(string $node_wg_pub, string $controller_wg_pub, string $challenge, string $nonce): string {
	$h = hash('sha256', $node_wg_pub . $controller_wg_pub . $challenge . $nonce, true);
	return sprintf('%06d', unpack('N', substr($h, 0, 4))[1] % 1000000);
}

/* pure: SHA-256 of a PEM certificate's DER, lower-case hexadecimal ("" for text that is not one). */
function fleet_cert_fingerprint(string $pem): string {
	if (!preg_match('/-----BEGIN CERTIFICATE-----(.+?)-----END CERTIFICATE-----/s', $pem, $m)) {
		return '';
	}
	$der = base64_decode(preg_replace('/\s+/', '', $m[1]), true);
	return ($der === false) ? '' : hash('sha256', $der);
}

/* ------------------------------------------------------------------ */
/* Errors                                                              */
/* ------------------------------------------------------------------ */

/* pure: the fleet error code for a controller reply {status, error code}. */
function fleet_join_error_for_reply(int $status, string $api_code): FleetJoinError {
	$map = array(
		'token_expired' => array('E102', 'The join link has expired or was already used. Ask for a new invitation.'),
		'token_used' => array('E102', 'The join link has expired or was already used. Ask for a new invitation.'),
		'bad_proof' => array('E103', 'The join key is wrong.'),
		'invitation_burned' => array('E104', 'Too many wrong keys: this invitation is no longer valid.'),
		'clock_skew' => array('E108', 'The clocks differ by more than 5 minutes. Synchronize NTP first.'),
		'instance_mismatch' => array('E111', 'This invitation belongs to another cloud instance.'),
		'binding_mismatch' => array('E121', 'This invitation is bound to another serial number or MAC address.'),
		'rejected' => array('E103', 'The administrator rejected this box.'),
	);
	if (isset($map[$api_code])) {
		return new FleetJoinError($map[$api_code][0], $map[$api_code][1]);
	}
	if (($status === 401) || ($status === 403)) {
		return new FleetJoinError('E103', 'The controller refused the join.');
	}
	return new FleetJoinError('E105', "The controller answered unexpectedly (HTTP {$status}).");
}

/* ------------------------------------------------------------------ */
/* Client                                                              */
/* ------------------------------------------------------------------ */

/*
 * Run the handshake. $io supplies everything that touches the system, so the
 * flow can be tested and every step undone:
 *
 *   post(path, body|null, bearer) -> array(status, json)   TLS pinned to the link
 *   get(path, bearer)             -> array(status, json)
 *   wg_keypair()                  -> array(private, public)  public as 32 raw bytes
 *   clock()                       -> UNIX time
 *   create_access(role)           -> array(api_key, undo callable, array(cert_sha256, certref))
 *                                    the fleet-controller user, its key and the box's API certificate
 *   apply(array $membership)      -> undo callable    config.xml <fleet>, tunnel, listener
 *   inventory()                   -> array            model, serial, version, NICs, instance id
 *   step(name, state, text)       progress for the screen ("start", "done", "wait", "fail")
 *   sleep(seconds)                waiting between status polls
 *
 * On any failure everything already done is undone in reverse order (E110)
 * and the original error is thrown. Returns the membership that was applied.
 */
function fleet_join_run(array $link, string $key_data, array $io, int $approval_timeout = 900): array {
	$undo = array();
	$bearer = $link['token_secret'];
	$step = $io['step'];
	try {
		$step('connect', 'start', '');
		$okm = fleet_join_okm($key_data, $link['cert_sha256']);
		list($status, $begin) = $io['post']('/fleet/join/begin', array('token_id' => $link['token_id']), $bearer);
		if ($status !== 200) {
			throw fleet_join_error_for_reply($status, (string)($begin['error']['code'] ?? ''));
		}
		$challenge = base64_decode((string)($begin['challenge'] ?? ''), true);
		$controller_pub = base64_decode((string)($begin['controller_wg_pub'] ?? ''), true);
		if (($challenge === false) || (strlen((string)$challenge) !== 32) || ($controller_pub === false) || (strlen((string)$controller_pub) !== 32)) {
			throw new FleetJoinError('E105', 'The controller sent an invalid challenge.');
		}
		if (abs((int)($begin['time'] ?? 0) - $io['clock']()) > 300) {
			throw new FleetJoinError('E108', 'The clocks differ by more than 5 minutes. Synchronize NTP first.');
		}
		$role = in_array($begin['role'] ?? '', FLEET_ROLES, true) ? $begin['role'] : 'monitor';
		$step('connect', 'done', '');

		$step('verify', 'start', '');
		list($wg_private, $node_pub) = $io['wg_keypair']();
		$nonce = random_bytes(32);
		$step('verify', 'done', '');

		$step('prove', 'start', '');
		$proof = fleet_join_proof($okm, $challenge, $nonce, $link['token_id'], $node_pub, $link['cert_sha256']);
		list($status, $enroll) = $io['post']('/fleet/join/enroll', array(
			'token_id' => $link['token_id'], 'nonce' => base64_encode($nonce), 'node_wg_pub' => base64_encode($node_pub),
			'proof' => $proof, 'inventory' => $io['inventory'](),
		), $bearer);
		if ($status !== 200) {
			throw fleet_join_error_for_reply($status, (string)($enroll['error']['code'] ?? ''));
		}
		$eid = (string)($enroll['enrollment_id'] ?? '');
		if (!preg_match('/^e-[0-9a-z]{16,40}$/D', $eid)) {
			throw new FleetJoinError('E105', 'The controller sent an invalid enrollment id.');
		}
		$step('prove', 'done', '');

		$code = fleet_join_code($node_pub, $controller_pub, $challenge, $nonce);
		$step('approval', 'wait', $code);
		$deadline = $io['clock']() + $approval_timeout;
		while (true) {
			list($status, $state) = $io['get']("/fleet/join/status/{$eid}", $bearer);
			if ($status !== 200) {
				throw fleet_join_error_for_reply($status, (string)($state['error']['code'] ?? ''));
			}
			if (($state['state'] ?? '') === 'approved') {
				break;
			}
			if (($state['state'] ?? 'pending') !== 'pending') {
				throw fleet_join_error_for_reply(403, 'rejected');
			}
			if ($io['clock']() > $deadline) {
				throw new FleetJoinError('E102', 'Nobody approved this box in time.');
			}
			$io['sleep'](3);
		}
		$step('approval', 'done', '');

		$step('tunnel', 'start', '');
		list($api_key, $undo_access, $extra) = $io['create_access']($role) + array(2 => array());
		$undo[] = $undo_access;
		list($status, $done) = $io['post']('/fleet/join/complete', array(
			'enrollment_id' => $eid, 'api_key' => $api_key,
			'proof' => fleet_join_complete_proof($okm, $eid, $api_key), 'node_cert_sha256' => (string)($extra['cert_sha256'] ?? ''),
		), $bearer);
		if ($status !== 200) {
			throw fleet_join_error_for_reply($status, (string)($done['error']['code'] ?? ''));
		}
		$membership = array(
			'node_id' => (string)($done['node_id'] ?? ''), 'controller_url' => "https://{$link['authority']}",
			'controller_fingerprint' => $link['cert_sha256'], 'controller_signing_key' => (string)($done['signing_key'] ?? ''),
			'method' => (string)($done['method'] ?? 'wireguard'), 'overlay_address' => (string)($done['overlay_address'] ?? ''),
			'role' => (string)($done['role'] ?? 'monitor'), 'hostname' => (string)($done['hostname'] ?? ''),
			'peers' => (array)($done['peers'] ?? array()), 'wg_private' => $wg_private,
			'listener_certref' => (string)($extra['certref'] ?? ''), 'controller_networks' => (array)($done['controller_networks'] ?? array()),
		);
		if (!fleet_valid_node_id($membership['node_id']) || !in_array($membership['method'], FLEET_METHODS, true)) {
			throw new FleetJoinError('E105', 'The controller sent an invalid membership.');
		}
		$undo[] = $io['apply']($membership);
		$step('tunnel', 'done', '');

		$step('heartbeat', 'done', '');
		return $membership;
	} catch (FleetJoinError $e) {
		fleet_join_rollback($undo);
		$step('fail', 'fail', $e->fleet_code . ': ' . $e->getMessage());
		throw $e;
	} catch (Throwable $e) {
		fleet_join_rollback($undo);
		$step('fail', 'fail', 'E110: ' . $e->getMessage());
		throw new FleetJoinError('E110', 'Joining failed part-way and was undone: ' . $e->getMessage());
	}
}

/* Undo what a failed join did, newest first; one failing undo never stops the rest. */
function fleet_join_rollback(array $undo): void {
	foreach (array_reverse($undo) as $fn) {
		try {
			if (is_callable($fn)) {
				$fn();
			}
		} catch (Throwable $e) {
			/* Keep undoing. */
		}
	}
}

/* ------------------------------------------------------------------ */
/* System access: pinned HTTPS and WireGuard keys                      */
/* ------------------------------------------------------------------ */

/* Largest answer read: a configuration with many rules and certificates fits easily. */
define('FLEET_HTTP_MAX_BYTES', 16 * 1048576);

/* pure: an HTTP/1.0 response -> array(status, decoded JSON body or array(), raw body). */
function fleet_http_parse_response(string $raw): array {
	if (!preg_match('~^HTTP/1\.[01] (\d{3}) [^\r\n]*\r\n(.*?)\r\n\r\n(.*)$~Ds', $raw, $m)) {
		throw new FleetJoinError('E105', 'The controller sent no valid HTTP answer.');
	}
	$json = json_decode($m[3], true);
	/* The raw body too, for answers that are not JSON (a configuration as XML). */
	return array((int)$m[1], is_array($json) ? $json : array(), $m[3]);
}

/* pure: the peer certificate matches the fingerprint from the link. */
function fleet_pin_matches(string $peer_pem, string $pinned_sha256): bool {
	$fp = fleet_cert_fingerprint($peer_pem);
	return ($fp !== '') && hash_equals($pinned_sha256, $fp);
}

/*
 * One request to the controller, TLS pinned to the link's certificate
 * fingerprint: the normal CA check is off, and nothing is sent before the
 * certificate matched (E101). HTTP/1.0 with "Connection: close" so the
 * answer needs no chunk decoding.
 */
function fleet_http_pinned(array $link, string $method, string $path, ?array $body, string $bearer): array {
	$ctx = stream_context_create(array('ssl' => array(
		'verify_peer' => false, 'verify_peer_name' => false, 'capture_peer_cert' => true,
		'peer_name' => $link['host'], 'SNI_enabled' => true, 'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT |
		    STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)));
	$host = (strpos($link['host'], ':') !== false) ? "[{$link['host']}]" : $link['host'];
	$errno = 0;
	$errstr = '';
	$fp = @stream_socket_client("ssl://{$host}:{$link['port']}", $errno, $errstr, 5, STREAM_CLIENT_CONNECT, $ctx);
	if ($fp === false) {
		throw new FleetJoinError('E105', "{$link['authority']} is unreachable ({$errstr}). Check the address, DNS, the tunnel and that TCP port {$link['port']} is open.");
	}
	try {
		$cert = stream_context_get_params($fp)['options']['ssl']['peer_certificate'] ?? null;
		$pem = '';
		if ($cert !== null) {
			openssl_x509_export($cert, $pem);
		}
		if (!fleet_pin_matches($pem, $link['cert_sha256'])) {
			throw new FleetJoinError('E101', 'The controller\'s certificate does not match the join link. Possible interception: do not continue.');
		}
		stream_set_timeout($fp, 30);
		$payload = ($body === null) ? '' : json_encode($body, JSON_UNESCAPED_SLASHES);
		fwrite($fp, "{$method} {$path} HTTP/1.0\r\nHost: {$link['authority']}\r\nAuthorization: Bearer {$bearer}\r\n" .
		    "Accept: application/json\r\nConnection: close\r\n" .
		    (($body === null) ? '' : "Content-Type: application/json\r\nContent-Length: " . strlen($payload) . "\r\n") .
		    "\r\n" . $payload);
		$raw = stream_get_contents($fp, FLEET_HTTP_MAX_BYTES);
	} finally {
		fclose($fp);
	}
	return fleet_http_parse_response((string)$raw);
}

/* A new WireGuard key pair: array(private key as text, public key as 32 raw bytes). */
function fleet_wg_keypair(): array {
	$wg = is_executable('/usr/bin/wg') ? '/usr/bin/wg' : '/usr/local/bin/wg';
	$private = trim((string)shell_exec(escapeshellarg($wg) . ' genkey 2>/dev/null'));
	if (!preg_match('~^[A-Za-z0-9+/]{43}=$~D', $private)) {
		throw new RuntimeException('wg(8) could not create a key.');
	}
	$spec = array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'w'));
	$proc = proc_open(array($wg, 'pubkey'), $spec, $pipes);
	if (!is_resource($proc)) {
		throw new RuntimeException('wg(8) could not derive the public key.');
	}
	fwrite($pipes[0], $private . "\n");
	fclose($pipes[0]);
	$public = trim((string)stream_get_contents($pipes[1]));
	fclose($pipes[1]);
	proc_close($proc);
	$raw = base64_decode($public, true);
	if (($raw === false) || (strlen($raw) !== 32)) {
		throw new RuntimeException('wg(8) returned an invalid public key.');
	}
	return array($private, $raw);
}
