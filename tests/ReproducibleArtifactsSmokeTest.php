<?php

$root = dirname(__DIR__);
$builderPath = $root . '/tools/builder_common.sh';
$corePackagePath = $root . '/build/scripts/create_core_pkg.sh';
$assemblerPath = $root . '/tools/ci/freesense-assemble-iso.sh';

$builder = file_get_contents($builderPath);
$corePackage = file_get_contents($corePackagePath);
$assembler = file_get_contents($assemblerPath);
if ($builder === false || $corePackage === false || $assembler === false) {
	fwrite(STDERR, "Unable to read reproducible-artifact builder sources.\n");
	exit(1);
}

$assemblyContracts = [
	'require_source_date_epoch || return 1',
	'PKG_INSTALL_EPOCH="${SOURCE_DATE_EPOCH}"',
	'pkg query -a "%t" | sort -u',
];
foreach ($assemblyContracts as $contract) {
	if (!str_contains($assembler, $contract)) {
		fwrite(STDERR, "ISO assembler is missing reproducible install contract: {$contract}\n");
		exit(1);
	}
}
if (substr_count($assembler, 'PKG_INSTALL_EPOCH="${SOURCE_DATE_EPOCH}"') !== 2) {
	fwrite(STDERR, "Every ISO assembly pkg installation must use the pinned install epoch.\n");
	exit(1);
}

$archiveContracts = [
	'require_source_date_epoch()',
	'create_reproducible_mtree()',
	'create_reproducible_txz()',
	'command -v gtar',
	'--sort=name',
	'--format=pax',
	'--mtime="@${SOURCE_DATE_EPOCH}"',
	'--numeric-owner',
	'--no-xattrs --no-acls --no-selinux',
	'--pax-option=exthdr.name=%d/PaxHeaders/%f,delete=atime,delete=ctime',
	'--use-compress-program="xz -T0"',
	'"${STAGE_CHROOT_DIR}${PRODUCT_SHARE_DIR}/base.txz"',
	'"${INSTALLER_CHROOT_DIR}/usr/freebsd-dist/base.txz"',
	'-k type,uid,gid,mode,nlink,size,link,flags',
	'local _mtree_tmp="${SCRATCHDIR}/default-mtrees.$$"',
	'"${_mtree_tmp}/etc.dist"',
	"sed -e '/^#/d'",
];
foreach ($archiveContracts as $contract) {
	if (!str_contains($builder, $contract)) {
		fwrite(STDERR, "Builder is missing reproducible archive contract: {$contract}\n");
		exit(1);
	}
}
if (substr_count($builder, '-k type,uid,gid,mode,nlink,size,link,flags') !== 3) {
	fwrite(STDERR, "Every generated defaults mtree must omit wall-clock timestamps.\n");
	exit(1);
}
if (preg_match_all('/^\\s*create_reproducible_mtree(?:\\s|\\\\)/m', $builder) !== 4) {
	fwrite(STDERR, "Every generated mtree must use the normalized atomic helper.\n");
	exit(1);
}

$poudriereContracts = [
	'FREESENSE_REQUIRE_SOURCE_DATE_EPOCH',
	'SOURCE_DATE_EPOCH=${SOURCE_DATE_EPOCH}',
	'.export SOURCE_DATE_EPOCH',
];
foreach ($poudriereContracts as $contract) {
	if (!str_contains($builder, $contract)) {
		fwrite(STDERR, "Builder is missing Poudriere epoch contract: {$contract}\n");
		exit(1);
	}
}

$coreContracts = [
	'SOURCE_DATE_EPOCH -- Decimal source commit timestamp (required)',
	'case "${SOURCE_DATE_EPOCH:-}" in',
	"''|*[!0-9]*)",
	'export SOURCE_DATE_EPOCH',
	'pkg create -T 0',
];
foreach ($coreContracts as $contract) {
	if (!str_contains($corePackage, $contract)) {
		fwrite(STDERR, "Core package builder is missing epoch contract: {$contract}\n");
		exit(1);
	}
}

$coreExport = strpos($corePackage, 'export SOURCE_DATE_EPOCH');
$pkgCreate = strpos($corePackage, 'pkg create -T 0');
if ($coreExport === false || $pkgCreate === false || $coreExport >= $pkgCreate) {
	fwrite(STDERR, "Core package epoch is not exported before pkg create.\n");
	exit(1);
}

$legacyArchivePatterns = [
	'tar -C ${STAGE_CHROOT_DIR} -X ${_exclude_files} --create --file - .',
	'tar -C ${FINAL_CHROOT_DIR}',
	'--owner=0',
	'--group=0',
];
foreach ($legacyArchivePatterns as $pattern) {
	if (str_contains($builder, $pattern)) {
		fwrite(STDERR, "Builder still contains non-deterministic archive path: {$pattern}\n");
		exit(1);
	}
}

echo "Reproducible artifact smoke test passed.\n";
