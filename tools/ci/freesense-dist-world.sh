#!/bin/sh
set -eu

: "${STAGE_CHROOT_DIR:?STAGE_CHROOT_DIR is required}"
: "${INSTALLER_CHROOT_DIR:?INSTALLER_CHROOT_DIR is required}"
: "${FREESENSE_DIST_WORLD_ARCHIVE:?FREESENSE_DIST_WORLD_ARCHIVE is required}"

[ -f "${FREESENSE_DIST_WORLD_ARCHIVE}" ] || {
	echo ">>> dist-world: pinned distribution archive is missing" >&2
	exit 1
}

echo ">>> dist-world: preparing clean stage and installer roots"
rm -rf "${STAGE_CHROOT_DIR}" "${INSTALLER_CHROOT_DIR}"
mkdir -p "${STAGE_CHROOT_DIR}" "${INSTALLER_CHROOT_DIR}"

echo ">>> dist-world: extracting the exact base.txz into both roots"
tar -xpf "${FREESENSE_DIST_WORLD_ARCHIVE}" -C "${STAGE_CHROOT_DIR}" &
stage_pid=$!
tar -xpf "${FREESENSE_DIST_WORLD_ARCHIVE}" -C "${INSTALLER_CHROOT_DIR}" &
installer_pid=$!

stage_status=0
installer_status=0
wait "${stage_pid}" || stage_status=$?
wait "${installer_pid}" || installer_status=$?
[ "${stage_status}" -eq 0 ] && [ "${installer_status}" -eq 0 ] || {
	echo ">>> dist-world: archive extraction failed (stage=${stage_status}, installer=${installer_status})" >&2
	exit 1
}

for root in "${STAGE_CHROOT_DIR}" "${INSTALLER_CHROOT_DIR}"; do
	[ -x "${root}/bin/sh" ] || { echo ">>> dist-world: ${root}/bin/sh is missing" >&2; exit 1; }
	[ -x "${root}/usr/bin/cc" ] || { echo ">>> dist-world: ${root}/usr/bin/cc is missing" >&2; exit 1; }
	[ -f "${root}/etc/master.passwd" ] || { echo ">>> dist-world: ${root}/etc/master.passwd is missing" >&2; exit 1; }
done

echo ">>> dist-world: pinned world seed ready"
