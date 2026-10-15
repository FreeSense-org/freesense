<?php
/* Standalone CI regression test; run with `php tests/RadiusChapSmokeTest.php`. */

require_once(__DIR__ . '/../src/etc/inc/radius_chap.inc');

function check_chap($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

/* DES through OpenSSL 3 (3DES with the key repeated) against the classic single-DES vector. */
$chap = new Crypt_CHAP_MSv1();
$des = new ReflectionMethod($chap, '_desEncrypt');
/* _desEncrypt() takes the 56 key bits without the parity bits: the first 7 bits of each key byte. */
$keybits = '';
foreach (str_split(hex2bin('133457799BBCDFF1')) as $c) {
	$keybits .= substr(sprintf('%08b', ord($c)), 0, 7);
}
$key7 = '';
foreach (str_split($keybits, 8) as $byte) {
	$key7 .= chr(bindec($byte));
}
check_chap(bin2hex($des->invoke($chap, $key7, hex2bin('0123456789ABCDEF'))) === '85e813540f0ab405',
    'single DES test vector (key 133457799BBCDFF1)');

/* NT password hash: MD4 over the UTF-16LE password (well-known value for "Password"). */
check_chap(strtoupper(bin2hex($chap->ntPasswordHash('Password'))) === 'A4F49C406510BDCAB6824EE7C30FD852',
    'NT hash of "Password"');

/* RFC 2759 section 9.2 test case. */
$v2 = new Crypt_CHAP_MSv2();
$v2->username = 'User';
$v2->password = 'clientPass';
$v2->authChallenge = hex2bin('5B5D7C7D7B3F2F3E3C2C602132262628');
$v2->peerChallenge = hex2bin('21402324255E262A28295F2B3A337C7E');
check_chap(strtoupper(bin2hex($v2->challengeHash())) === 'D02E4386BCE91226', 'MS-CHAPv2 challenge hash (RFC 2759)');
check_chap(strtoupper(bin2hex($v2->challengeResponse())) === '82309ECD8D708B5EA08FAA3981CD83544233114A3D85D6DF',
    'MS-CHAPv2 NT response (RFC 2759)');

/* RFC 2433 appendix: MS-CHAPv1 NT response for "MyPw" with challenge 102DB5DF085D3041. */
$v1 = new Crypt_CHAP_MSv1();
$v1->password = 'MyPw';
$v1->challenge = hex2bin('102DB5DF085D3041');
check_chap(strtoupper(bin2hex($v1->ntChallengeResponse())) === '4E9D3C8F9CFD385D5BF4D3246791956CA4C351AB409A3D61',
    'MS-CHAPv1 NT response (RFC 2433)');

/* CHAP-MD5: response is MD5(id | password | challenge); the constructor must generate a challenge. */
$md5 = new Crypt_CHAP_MD5();
check_chap(strlen((string)$md5->challenge) === 8, 'CHAP-MD5 generates an 8 byte challenge (PHP 8 never calls PHP 4 constructors)');
$md5->password = 'secret';
$md5->chapid = 7;
check_chap($md5->challengeResponse() === md5(chr(7) . 'secret' . $md5->challenge, true), 'CHAP-MD5 response');

$a = new Crypt_CHAP_MD5();
$b = new Crypt_CHAP_MD5();
check_chap($a->challenge !== $b->challenge, 'challenges are random');

echo "RADIUS CHAP smoke test passed.\n";
