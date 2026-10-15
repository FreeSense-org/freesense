<?php
/*
 * radius_chap.inc
 *
 * part of FreeSense (https://www.freesense.org)
 *
 * Copyright (c) 2025-2026 The FreeSense Project
 * Copyright (c) 2002-2010, Michael Bretterklieber <michael@bretterklieber.com>
 * All rights reserved.
 *
 * Based on the Crypt_CHAP PEAR package, 3-clause BSD license:
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions
 * are met:
 *
 * 1. Redistributions of source code must retain the above copyright
 *    notice, this list of conditions and the following disclaimer.
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 * 3. The names of the authors may not be used to endorse or promote products
 *    derived from this software without specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND
 * ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
 * IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT,
 * INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING,
 * BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY
 * OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING
 * NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE,
 * EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 */

/*
 * CHAP packet generators used by radius_backed() (auth.inc):
 *   CHAP-MD5  RFC 1994
 *   MS-CHAPv1 RFC 2433
 *   MS-CHAPv2 RFC 2759
 *
 * The PEAR package no longer works on PHP 8: it relies on PHP 4 style
 * constructors, brace string offsets and the removed mcrypt extension. This
 * copy keeps the class names and properties Auth_RADIUS expects, uses real
 * constructors and random_bytes(), and does DES through OpenSSL.
 */

class Crypt_CHAP {
	/* Random binary challenge */
	public $challenge = null;
	/* Binary response */
	public $response = null;
	/* User name (MS-CHAPv2 hashes it into the challenge) */
	public $username = null;
	/* User password */
	public $password = null;
	/* Id of the authentication request */
	public $chapid = 1;

	public function __construct() {
		$this->generateChallenge();
	}

	/* Generate a random binary challenge of $size bytes. */
	public function generateChallenge($varname = 'challenge', $size = 8) {
		$this->$varname = random_bytes($size);
		return $this->$varname;
	}

	/* Generate the response. Overridden by the protocol classes. */
	public function challengeResponse() {
	}
}

/* CHAP-MD5: MD5 over the chap id, the plain text password and the challenge. */
class Crypt_CHAP_MD5 extends Crypt_CHAP {
	public function challengeResponse() {
		return md5(pack('C', $this->chapid) . $this->password . $this->challenge, true);
	}
}

/*
 * MS-CHAPv1. It uses the NT hash, MD4 over the UTF-16LE password, never the
 * plain text password itself.
 */
class Crypt_CHAP_MSv1 extends Crypt_CHAP {
	/* 0 = use LM response, 1 = use NT response */
	public $flags = 1;

	public function ntPasswordHash($password = null) {
		$password = $password ?? $this->password;
		return hash('md4', $this->str2unicode($password), true);
	}

	/* Convert a UTF-8 string to UTF-16LE. */
	public function str2unicode($str) {
		return mb_convert_encoding((string) $str, 'UTF-16LE', 'UTF-8');
	}

	public function challengeResponse() {
		return $this->_challengeResponse();
	}

	public function ntChallengeResponse() {
		return $this->_challengeResponse(false);
	}

	public function lmChallengeResponse() {
		return $this->_challengeResponse(true);
	}

	/* Three DES encryptions of the challenge with the 21 byte (zero padded) hash. */
	protected function _challengeResponse($lm = false) {
		$hash = $lm ? $this->lmPasswordHash() : $this->ntPasswordHash();
		$hash = str_pad($hash, 21, "\0");

		return $this->_desEncrypt(substr($hash, 0, 7), $this->challenge) .
		    $this->_desEncrypt(substr($hash, 7, 7), $this->challenge) .
		    $this->_desEncrypt(substr($hash, 14, 7), $this->challenge);
	}

	/* LAN Manager hash of the upper-cased password. */
	public function lmPasswordHash($password = null) {
		$plain = $password ?? $this->password;
		$plain = str_pad(substr(strtoupper($plain), 0, 14), 14, "\0");

		return $this->_desEncrypt(substr($plain, 0, 7), 'KGS!@#$%') .
		    $this->_desEncrypt(substr($plain, 7, 7), 'KGS!@#$%');
	}

	/*
	 * Single DES (ECB, no padding) of one 8 byte block with a 7 byte key.
	 * OpenSSL 3 only provides single DES in its legacy provider, but 3DES
	 * with the same key three times is identical to single DES.
	 */
	protected function _desEncrypt($key7, $block) {
		$key = $this->_desAddParity($key7);
		$out = openssl_encrypt($block, 'des-ede3-ecb', $key . $key . $key,
		    OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING);
		if ($out === false) {
			throw new RuntimeException('DES is not available in OpenSSL');
		}
		return $out;
	}

	/* Expand a 7 byte DES key to 8 bytes, setting odd parity on each. */
	protected function _desAddParity($key) {
		$bits = '';
		foreach (str_split($key) as $c) {
			$bits .= sprintf('%08b', ord($c));
		}

		$out = '';
		foreach (str_split($bits, 7) as $chunk) {
			$v = bindec($chunk) << 1;
			if ((substr_count(decbin($v), '1') % 2) === 0) {
				$v |= 1;
			}
			$out .= chr($v);
		}
		return $out;
	}

	/* Response packet: LM response, NT response, flag (1 = NT response used). */
	public function response($lm = false) {
		$ntresp = $this->ntChallengeResponse();
		$lmresp = $lm ? $this->lmChallengeResponse() : str_repeat("\0", 24);

		return $lmresp . $ntresp . pack('C', !$lm);
	}
}

/*
 * MS-CHAPv2. The 8 byte DES challenge is a SHA-1 hash over the peer
 * challenge, the authenticator challenge and the user name.
 */
class Crypt_CHAP_MSv2 extends Crypt_CHAP_MSv1 {
	/* 16 byte random binary peer challenge */
	public $peerChallenge = null;
	/* 16 byte random binary authenticator challenge */
	public $authChallenge = null;

	public function __construct() {
		parent::__construct();
		$this->generateChallenge('peerChallenge', 16);
		$this->generateChallenge('authChallenge', 16);
	}

	public function ntPasswordHashHash($nthash) {
		return hash('md4', $nthash, true);
	}

	public function challengeHash() {
		return substr(hash('sha1', $this->peerChallenge . $this->authChallenge . $this->username, true), 0, 8);
	}

	public function challengeResponse() {
		$this->challenge = $this->challengeHash();
		return $this->_challengeResponse();
	}
}
