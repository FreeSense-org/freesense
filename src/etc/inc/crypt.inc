<?php
/*
 * crypt.inc
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
 * Copyright (c) 2008 Shrew Soft Inc. All rights reserved.
 * All rights reserved.
 *
 * originally part of m0n0wall (http://m0n0.ch/wall)
 * Copyright (c) 2003-2004 Manuel Kasper <mk@neon1.net>.
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

define('PFS_OPENSSL_DEFAULT_ITERATIONS', '500000');

	function crypt_cleanup($file) {
		unlink_if_exists($file);
		unlink_if_exists("{$file}.dec");
		unlink_if_exists("{$file}.enc");
	}

	function crypt_data($val, $pass, $opt, $legacy = false, $iterations = PFS_OPENSSL_DEFAULT_ITERATIONS) {
		/* The plaintext and ciphertext must only be readable by root. */
		$old_umask = umask(0077);
		$file = tempnam("/tmp", "php-encrypt");
		/* Ensure the files do not already exist */

		crypt_cleanup($file);
		file_put_contents("{$file}.dec", $val);

		/* Use PBKDF2 Key Derivation (https://en.wikipedia.org/wiki/PBKDF2)
		 *  unless we need to read old data encrypted without it. */
		$md = ($legacy) ? "md5" : "sha256";
		$cmd = ['/usr/bin/openssl', 'enc', $opt, '-aes-256-cbc',
		    '-in', "{$file}.dec", '-out', "{$file}.enc",
		    '-pass', 'fd:3', '-salt', '-md', $md];
		if (!$legacy) {
			array_push($cmd, '-pbkdf2', '-iter', (string)$iterations);
		}

		/* Hand the passphrase over a pipe so it never appears in the
		 * process list (pfSense-SA-26_19). */
		$exitcode = 1;
		$proc = proc_open($cmd, [
		    0 => ['file', '/dev/null', 'r'],
		    1 => ['file', '/dev/null', 'w'],
		    2 => ['file', '/dev/null', 'w'],
		    3 => ['pipe', 'r'],
		], $pipes);
		if (is_resource($proc)) {
			fwrite($pipes[3], $pass . "\n");
			fclose($pipes[3]);
			$exitcode = proc_close($proc);
		}
		umask($old_umask);

		if (($exitcode == 0) && file_exists("{$file}.enc") && (filesize("{$file}.enc") > 0)) {
			$result = file_get_contents("{$file}.enc");
		} elseif (($opt == "-d") && ($legacy === false) && ($iterations == PFS_OPENSSL_DEFAULT_ITERATIONS)) {
			/* If it failed with the current default iterations,
			 * next try with previous default number of iterations. */
			crypt_cleanup($file);
			$result = crypt_data($val, $pass, $opt, false, '10000');
		} elseif (($opt == "-d") && ($legacy === false)) {
			/* Operation failed without new options, try old. */
			crypt_cleanup($file);
			$result = crypt_data($val, $pass, $opt, true);
		} else {
			$result = "";
			logger(LOG_ERR, localize_text("Failed to encrypt/decrypt data!"));
		}

		/* Cleanup */
		crypt_cleanup($file);
		return $result;
	}

	function encrypt_data(& $data, $pass, $legacy = false) {
		return base64_encode(crypt_data($data, $pass, "-e", $legacy));
	}

	function decrypt_data(& $data, $pass, $legacy = false) {
		return crypt_data(base64_decode($data), $pass, "-d", $legacy);
	}

	function tagfile_reformat($in, & $out, $tag) {

		$out = "---- BEGIN {$tag} ----\n";

		$size = 80;
		$oset = 0;
		while ($size >= 64) {
			$line = substr($in, $oset, 64);
			$out .= $line."\n";
			$size = strlen($line);
			$oset += $size;
		}

		$out .= "---- END {$tag} ----\n";

		return true;
	}

	function tagfile_deformat($in, & $out, $tag) {

		$btag_val = "---- BEGIN {$tag} ----";
		$etag_val = "---- END {$tag} ----";

		$btag_len = strlen($btag_val);
		$etag_len = strlen($etag_val);

		$btag_pos = stripos($in, $btag_val);
		$etag_pos = stripos($in, $etag_val);

		if (($btag_pos === false) || ($etag_pos === false)) {
			return false;
		}

		$body_pos = $btag_pos + $btag_len;
		$body_len = strlen($in);
		$body_len -= $btag_len;
		$body_len -= $etag_len + 1;

		$out = substr($in, $body_pos, $body_len);

		return true;
	}

?>
