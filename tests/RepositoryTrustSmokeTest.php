<?php
$fingerprintPath = dirname(__DIR__) . '/src/usr/local/share/FreeSense/keys/pkg/trusted/freesense';
$fingerprint = file_get_contents($fingerprintPath);
if ($fingerprint === false) {
	fwrite(STDERR, "Unable to read the FreeSense repository trust fingerprint.\n");
	exit(1);
}

if (!preg_match('/\Afunction: sha256\Rfingerprint: "([0-9a-f]{64})"\R?\z/', $fingerprint, $matches)) {
	fwrite(STDERR, "FreeSense repository trust fingerprint has an invalid format.\n");
	exit(1);
}

$channelSigningKeySha256 = '0bb460ba8bbcddd828e3dc7433d78d75bde8242231dca44cf4e8be2ce168bba5';
if (!hash_equals($channelSigningKeySha256, $matches[1])) {
	fwrite(STDERR, "FreeSense repository trust does not match the channel signing public key.\n");
	exit(1);
}

echo "Repository trust smoke test passed.\n";
