#!/bin/sh
# freesense-pkgbase-world.sh — LEVER 1 spike: seed the base WORLD from FreeBSD-16
# pkgbase binaries instead of compiling it with buildworld.
#
# WHY: `./build.sh --build-core` today runs a full `make buildworld` (~4h) and keeps
# a base.txz that is ~99% verbatim FreeBSD. FreeBSD 16 publishes its entire base as
# signed pkgbase packages (pkg.freebsd.org/FreeBSD:16:amd64/base_latest). This script
# installs that prebuilt world into the staging + installer chroots, so the ~4h world
# compile is replaced by a fetch. The custom KERNEL is still built from patched source
# (build_all_kernels), and the src/ overlay + patched userland still land on top — so
# the resulting base.txz is functionally the same, just assembled from binaries.
#
# This is Phase 1 (measure the time win). It does NOT change packaging or the update
# flow: clone_to_staging_area still tars STAGE_CHROOT_DIR -> base.txz and emits the
# FreeSense-base pkg with the same CORE_PKG_VERSION. System>Update is unaffected.
#
# Gated by FREESENSE_PKGBASE_WORLD=1 (else make_world runs the classic buildworld).
#
# Env in (from builder_common.sh make_world scope):
#   STAGE_CHROOT_DIR INSTALLER_CHROOT_DIR TARGET_ARCH FREEBSD_SRC_DIR
# Optional:
#   FREESENSE_PKGBASE_URL   (default the live base_latest; override to pin a snapshot)
#   FREESENSE_PKGBASE_ABI   (default FreeBSD:<major>:<arch> derived from src param.h)
set -u

say(){ echo ">>> pkgbase-world: $*"; }
die(){ echo ">>> pkgbase-world: ERROR: $*" >&2; exit 1; }

STAGE="${STAGE_CHROOT_DIR:?STAGE_CHROOT_DIR unset}"
INST="${INSTALLER_CHROOT_DIR:?INSTALLER_CHROOT_DIR unset}"
SRCDIR="${FREEBSD_SRC_DIR:?FREEBSD_SRC_DIR unset}"
ARCH="${TARGET_ARCH:-$(uname -p)}"

# --- derive ABI + OSVERSION from the SAME patched src we build the kernel from -------
# The empty-dir pkg bootstrap mis-detects ABI/OSVERSION from the host (pkg issue #2533),
# so we MUST pass both explicitly. Reading __FreeBSD_version from the checked-out src
# guarantees the fetched world matches the kernel we're about to build (same rev).
PARAM="${SRCDIR}/sys/sys/param.h"
[ -f "$PARAM" ] || die "missing ${PARAM} (run update_freebsd_sources first)"
OSVERSION=$(awk '/^#define[[:space:]]+__FreeBSD_version/ {print $3}' "$PARAM")
[ -n "$OSVERSION" ] || die "could not read __FreeBSD_version from ${PARAM}"
FBSD_MAJOR=$(( OSVERSION / 100000 ))
ABI="${FREESENSE_PKGBASE_ABI:-FreeBSD:${FBSD_MAJOR}:${ARCH}}"
BASE_URL="${FREESENSE_PKGBASE_URL:-pkg+https://pkg.freebsd.org/${ABI}/base_latest}"
say "ABI=${ABI} OSVERSION=${OSVERSION} url=${BASE_URL}"

# --- a throwaway repo conf pointing at FreeBSD's pkgbase --------------------------
# Kept OUT of the chroots' own /etc/pkg so it can't leak into the shipped image; we
# point pkg at it via -R <dir>. base_latest is signed (fingerprints), but for a build-
# time fetch into a chroot we trust the transport + re-sign under our fingerprint later
# (same trust model the ports lean-seed uses). signature_type:none here = don't verify
# FreeBSD's key at fetch time; the shipped repo is what the box verifies.
REPO_CONF_DIR=$(mktemp -d)
trap 'rm -rf "$REPO_CONF_DIR"' EXIT
cat > "${REPO_CONF_DIR}/FreeBSD-base.conf" <<EOF
FreeBSD-base: {
  url: "${BASE_URL}",
  mirror_type: "srv",
  signature_type: "none",
  enabled: yes
}
EOF

# --- the pkgbase set we want: the whole base MINUS the kernel (we build our own) ----
# FreeBSD-set-base is the meta that pulls the full world. We want everything EXCEPT the
# kernel packages (FreeBSD-kernel-generic*, FreeBSD-src*, and the -dbg debug sets to
# keep the image lean). Install by glob, then the kernel/debug/src are excluded.
seed_chroot() {
	_root="$1"; _label="$2"
	say "seeding ${_label} world into ${_root}"
	mkdir -p "${_root}"

	# Install all FreeBSD-* base packages except kernel/src/debug into the target root.
	# -o ABI / OSVERSION: force the target's ABI (issue #2533). -r FreeBSD-base: only
	# our throwaway repo. --repo-conf-dir: use our conf, ignore the host's repos.
	# -g 'FreeBSD-*': glob the whole set. We then remove kernel/src/dbg after.
	if ! env ABI="${ABI}" \
		pkg --rootdir "${_root}" \
		    --repo-conf-dir "${REPO_CONF_DIR}" \
		    -o OSVERSION="${OSVERSION}" \
		    -o ASSUME_ALWAYS_YES=yes \
		    -o REPOS_DIR="${REPO_CONF_DIR}" \
		    install -Uy -r FreeBSD-base \
		    -g 'FreeBSD-runtime' 'FreeBSD-clibs' 'FreeBSD-utilities' \
		       'FreeBSD-libexecinfo' 'FreeBSD-libcompiler_rt' \
		       'FreeBSD-*' ; then
		die "pkgbase install into ${_root} failed"
	fi

	# Drop what we do NOT want in a firewall image / what we build ourselves:
	#   kernel (we build the custom FreeSense kernel), the full src tree, debug syms,
	#   tests, and lib32 (WITHOUT_LIB32 in the classic build).
	env ABI="${ABI}" pkg --rootdir "${_root}" -o ASSUME_ALWAYS_YES=yes \
		delete -y -g 'FreeBSD-kernel-*' 'FreeBSD-src*' 'FreeBSD-*-dbg' \
		         'FreeBSD-tests*' 'FreeBSD-*-lib32' 2>/dev/null || true

	_n=$(env ABI="${ABI}" pkg --rootdir "${_root}" info 2>/dev/null | wc -l | tr -d ' ')
	[ "${_n:-0}" -gt 50 ] || die "${_label}: only ${_n} pkgs installed — seed looks incomplete"
	say "${_label}: ${_n} pkgbase packages installed"
}

seed_chroot "${STAGE}" "stage"
seed_chroot "${INST}"  "installer"

say "DONE — world seeded from FreeBSD pkgbase (no buildworld). Kernel builds next."
