<?php
/* Standalone CI regression test; run with `php tests/RemoteBackupSmokeTest.php`. */

$root = dirname(__DIR__);
set_include_path(get_include_path() . PATH_SEPARATOR . realpath($root . '/src/etc/inc'));
require_once('remote_backup/util.inc');
require_once('remote_backup/s3.inc');
require_once('remote_backup/webdav.inc');
require_once('remote_backup/sftp.inc');

function check_rb($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

/* SigV4: the published AWS S3 examples ("Signature Calculations for the
 * Authorization Header: Transferring Payload in a Single Chunk"). */
$access = 'AKIAIOSFODNN7EXAMPLE';
$secret = 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY';
$empty = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';
$host = 'examplebucket.s3.amazonaws.com';
$date = '20130524T000000Z';

$auth = remote_backup_s3_authorization('GET', '/test.txt', array(), array(
	'host' => $host, 'range' => 'bytes=0-9', 'x-amz-content-sha256' => $empty, 'x-amz-date' => $date,
), $empty, $access, $secret, 'us-east-1', $date);
check_rb(strpos($auth, 'Signature=f0e8bdb87c964420e857bd35b5d6ed310bd44f0170aba48dd91039c6036bdb41') !== false,
    "GET object signature: {$auth}");
check_rb(strpos($auth, 'SignedHeaders=host;range;x-amz-content-sha256;x-amz-date,') !== false,
    "GET object signed headers: {$auth}");
check_rb(strpos($auth, 'Credential=AKIAIOSFODNN7EXAMPLE/20130524/us-east-1/s3/aws4_request,') !== false,
    "credential scope: {$auth}");

$put_hash = '44ce7dd67c959e0d3524ffac1771dfbba87d2b6b4b4e99e42034a8b803f8b072';
$auth = remote_backup_s3_authorization('PUT', remote_backup_s3_encode_path('/test$file.text'), array(), array(
	'date' => 'Fri, 24 May 2013 00:00:00 GMT', 'host' => $host, 'x-amz-content-sha256' => $put_hash,
	'x-amz-date' => $date, 'x-amz-storage-class' => 'REDUCED_REDUNDANCY',
), $put_hash, $access, $secret, 'us-east-1', $date);
check_rb(strpos($auth, 'Signature=98ad721746da40c64f1a55b78f14c238d841ea1380cd77a1b5971af0ece108bd') !== false,
    "PUT object signature (path encoding): {$auth}");

$auth = remote_backup_s3_authorization('GET', '/', array('max-keys' => '2', 'prefix' => 'J'), array(
	'host' => $host, 'x-amz-content-sha256' => $empty, 'x-amz-date' => $date,
), $empty, $access, $secret, 'us-east-1', $date);
check_rb(strpos($auth, 'Signature=34b48302e7b5fa45bde8084f4b7868a86f0a534bc59db6670ed5711ef69dc6f7') !== false,
    "list objects signature (query canonicalisation): {$auth}");

check_rb(remote_backup_s3_canonical_query(array('prefix' => 'a b/', 'list-type' => '2', 'delimiter' => '/')) ===
    'delimiter=%2F&list-type=2&prefix=a%20b%2F', 'canonical query must sort and RFC 3986-encode');
check_rb(remote_backup_s3_key(array('s3_prefix' => '/site-a/'), 'x.xml') === 'site-a/x.xml', 'prefix is trimmed');
check_rb(remote_backup_s3_key(array('s3_prefix' => ''), 'x.xml') === 'x.xml', 'empty prefix');

/* ListObjectsV2 parsing: entity decoding, prefix stripping, no sub-folders. */
$xml = '<ListBucketResult><Contents><Key>fw/a&amp;b.xml</Key></Contents>' .
    '<Contents><Key>fw/sub/c.xml</Key></Contents><Contents><Key>fw/d.xml</Key><Size>1</Size></Contents></ListBucketResult>';
check_rb(remote_backup_s3_parse_list($xml, 'fw/') === array('a&b.xml', 'd.xml'), 'S3 list parsing');

/* File naming and retention */
$hash = str_repeat('ab', 32);
$name = remote_backup_filename('fw.example.org', 1790000000, $hash);
check_rb($name === 'fw.example.org-' . gmdate('Ymd-His', 1790000000) . '-abababab.xml', "file name: {$name}");
check_rb(remote_backup_is_own_file($name, 'fw.example.org'), 'own file must match');
check_rb(!remote_backup_is_own_file($name, 'fw.example'), 'another host label must not match');
check_rb(!remote_backup_is_own_file('fw.example.org-20260921-000000-abababab.xml.sha256', 'fw.example.org'),
    'manifests are not backup files');
check_rb(!remote_backup_is_own_file('../fw.example.org-20260921-000000-abababab.xml', 'fw.example.org'),
    'paths never match');

$listing = array(
	'fw-20260101-000000-00000001.xml', 'fw-20260101-000000-00000001.xml.sha256',
	'fw-20260103-000000-00000003.xml', 'fw-20260102-000000-00000002.xml',
	'other-20250101-000000-00000009.xml', 'notes.txt', 'fw-latest.xml',
);
check_rb(remote_backup_prune_selection($listing, 'fw', 2) === array('fw-20260101-000000-00000001.xml'),
    'retention keeps the newest two and ignores foreign files');
check_rb(remote_backup_prune_selection($listing, 'fw', 0) === array('fw-20260102-000000-00000002.xml', 'fw-20260101-000000-00000001.xml'),
    'retention always keeps at least the newest backup');
check_rb(remote_backup_prune_selection($listing, 'other', 1) === array(), 'nothing to prune for one backup');

check_rb(remote_backup_manifest('x.xml', 'abc') ===
    "ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad  x.xml\n", 'sha256sum manifest format');

/* URL and prefix validation */
check_rb(remote_backup_valid_http_url('https://acct.r2.cloudflarestorage.com'), 'R2 endpoint is valid');
check_rb(remote_backup_valid_http_url('http://192.0.2.10:9000/'), 'MinIO endpoint is valid');
check_rb(!remote_backup_valid_http_url('ftp://example.org/'), 'ftp is rejected');
check_rb(!remote_backup_valid_http_url('https://user:pw@example.org/'), 'credentials in URL are rejected');
check_rb(!remote_backup_valid_http_url('file:///etc/passwd'), 'file URLs are rejected');
check_rb(remote_backup_valid_prefix('freesense/site-a'), 'normal prefix');
check_rb(!remote_backup_valid_prefix('a/../b'), 'dot-dot prefix is rejected');

/* WebDAV PROPFIND parsing with and without namespace prefixes */
$dav = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:">' .
    '<d:response><d:href>/dav/fw/</d:href><d:propstat><d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop></d:propstat></d:response>' .
    '<d:response><d:href>/dav/fw/a%20b.xml</d:href><d:propstat><d:prop><d:resourcetype/></d:prop></d:propstat></d:response>' .
    '<d:response><d:href>https://h/dav/fw/c.xml.sha256</d:href><d:propstat><d:prop><d:resourcetype/></d:prop></d:propstat></d:response>' .
    '</d:multistatus>';
check_rb(remote_backup_webdav_parse_list($dav) === array('a b.xml', 'c.xml.sha256'), 'WebDAV list parsing');
$dav2 = '<multistatus xmlns="DAV:"><response><href>/x/sub/</href><propstat><prop><resourcetype><collection /></resourcetype></prop></propstat></response>' .
    '<response><href>/x/e.xml</href></response></multistatus>';
check_rb(remote_backup_webdav_parse_list($dav2) === array('e.xml'), 'WebDAV list parsing without prefixes');

/* SFTP host key fingerprints match ssh-keygen -lf */
check_rb(remote_backup_sftp_fingerprint('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIL/rYOaZH3hZ38eI4OLZOab0njkkjxlIApMDYgn24iMk test') ===
    'SHA256:Rpf3HCcLokomR+U9gQ4DKU545KHM8FEpVShr3uZZGSc', 'Ed25519 fingerprint');
check_rb(remote_backup_sftp_fingerprint('ssh-rsa AAAAC3NzaC1lZDI1NTE5AAAAIL/rYOaZH3hZ38eI4OLZOab0njkkjxlIApMDYgn24iMk') === null,
    'a key type that disagrees with the blob is rejected');
check_rb(remote_backup_sftp_fingerprint('not a key') === null, 'garbage is rejected');
check_rb(remote_backup_sftp_valid_path('backups/fw-a') && remote_backup_sftp_valid_path(''), 'normal SFTP paths');
check_rb(!remote_backup_sftp_valid_path("a\"\nrm *"), 'quotes and newlines cannot inject batch commands');
check_rb(remote_backup_sftp_parse_list("sftp> cd \"x\"\nsftp> ls -1a\n.\n..\n.freesense-probe-1\nfw-1.xml\n") ===
    array('.freesense-probe-1', 'fw-1.xml'), 'sftp ls parsing');

/* Static guards on the integration points */
$configlib = file_get_contents($root . '/src/etc/inc/config.lib.inc');
check_rb(preg_match("#config_path_enabled\('remotebackup', 'onchange'\)\) \{\s*@touch\('/var/run/remote_backup\.pending'\);\s*\}#", $configlib) === 1,
    'write_config() must only touch the pending flag for on-change backups');
$rb = file_get_contents($root . '/src/etc/inc/remote_backup.inc');
check_rb(strpos($rb, "'/var/run/remote_backup.pending'") !== false, 'flag path must match write_config()');
check_rb(strpos($rb, "encrypt' => 'yes'") !== false, 'uploads must use the encrypted download path');
$diag = file_get_contents($root . '/src/usr/local/www/diag_backup.php');
check_rb(strpos($diag, "freesense_package_restore_create_preview(array(") !== false &&
    strpos($diag, "\$remote_tmp);") !== false, 'remote restore must use the package-restore preview');
$backup = file_get_contents($root . '/src/usr/local/FreeSense/include/www/backup.inc');
check_rb(strpos($backup, '} elseif (!is_uploaded_file($upload)) {') !== false,
    'browser restores must still require an uploaded file');

echo "Remote backup smoke test passed.\n";
