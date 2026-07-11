#!/bin/sh
# freesense-fetch-kernel.sh — consume base-build's already-published kernel pkg
# instead of compiling it (kernel-toolchain ~30-45m + buildkernel ~15-20m on a
# cold ISO VM, the single biggest remaining cost of the pkgbase ISO path).
#
# base-build.yml compiles the SAME kernel weekly from the SAME committed pin
# (manifest.env UPSTREAM_REF) and publishes it signed to R2 base/<channel>/All/.
# This script pulls that pkg and emits an env file the caller sources before
# `build.sh iso`:
#   DATESTRING                      — pinned to the base build's stamp so
#                                     CORE_PKG_VERSION (and thus get_pkg_name)
#                                     resolves to the fetched file's exact name;
#                                     build_all_kernels' NO_BUILDKERNEL check
#                                     then short-circuits naturally.
#   FREESENSE_PREFETCHED_KERNEL_DIR — where the pkg landed; build_all_kernels
#                                     imports it into the core pkg repo.
#
# REV GUARD: the fetched kernel must come from the same freebsd-src rev this
# build pins (FREESENSE_REV), else we'd pair a stale kernel with a newer world.
# Checked against base/<chan>/os-snapshot.json (authoritative) or
# ports-cache/<chan>/.built-base-sha (fallback). If neither marker exists yet
# (both publish steps are newer than the last base-build run) the newest banked
# kernel is accepted with a LOUD warning — tolerable because manifest.env's pin
# is only ever bumped BY a successful base-build at that pin.
#
# Any failure exits non-zero => the caller falls back to the source build.
set -u

say(){ echo ">>> fetch-kernel: $*"; }
fail(){ echo ">>> fetch-kernel: SKIP: $*" >&2; exit 1; }

RCLONE=${RCLONE:-rclone}
R2=${FREESENSE_R2_REMOTE:-R2:freesense-pkg}
CHAN=${FREESENSE_CHANNEL:-main}
WANT_REV=${FREESENSE_REV:-}
PRODUCT=${PRODUCT_NAME:-FreeSense}
KERNCONF=${FREESENSE_FETCH_KERNCONF:-${PRODUCT}}
OUTDIR=${FREESENSE_PREFETCH_DIR:-/root/prefetched-kernel}
ENVOUT=${FREESENSE_FETCH_ENV:-/tmp/freesense-kernel.env}

command -v "${RCLONE}" >/dev/null 2>&1 || fail "rclone not available"

# channel -> base repo dir (mirror of base-build.yml's ref->channel map)
case "${CHAN}" in
	main) BCH=devel ;;
	RELENG_*) BCH=stable ;;
	*) BCH=${CHAN} ;;
esac

# --- resolve the base build's version + rev from the provenance markers ---------
BASE_VER=""; BASE_REV=""
if ${RCLONE} copyto "${R2}/base/${BCH}/os-snapshot.json" /tmp/os-snapshot.json 2>/dev/null \
    && [ -s /tmp/os-snapshot.json ]; then
	BASE_REV=$(sed -n 's/.*"freebsd_rev"[^"]*"\([^"]*\)".*/\1/p' /tmp/os-snapshot.json)
	BASE_VER=$(sed -n 's/.*"base_version"[^"]*"\([^"]*\)".*/\1/p' /tmp/os-snapshot.json)
	say "os-snapshot.json: rev=${BASE_REV:-?} base_version=${BASE_VER:-?}"
elif ${RCLONE} copyto "${R2}/ports-cache/${CHAN}/.built-base-sha" /tmp/built-base-sha 2>/dev/null \
    && [ -s /tmp/built-base-sha ]; then
	BASE_REV=$(awk '{print $3}' /tmp/built-base-sha)
	say ".built-base-sha: rev=${BASE_REV:-?}"
fi

if [ -n "${WANT_REV}" ] && [ -n "${BASE_REV}" ]; then
	_want12=$(printf '%.12s' "${WANT_REV}"); _got12=$(printf '%.12s' "${BASE_REV}")
	[ "${_want12}" = "${_got12}" ] || \
		fail "banked kernel is rev ${_got12} but this build pins ${_want12} — compile instead (or re-run base-build)"
	say "rev guard OK (${_want12})"
else
	say "WARNING: no provenance marker on R2 yet — accepting newest banked kernel UNVERIFIED"
fi

# --- pick the kernel pkg ---------------------------------------------------------
# Debug pkg is named ${PRODUCT}-kernel-debug-${KERNCONF}-*, so the positive prefix
# match below cannot pick it up.
if [ -n "${BASE_VER}" ]; then
	KPKG="${PRODUCT}-kernel-${KERNCONF}-${BASE_VER}.pkg"
else
	KPKG=$(${RCLONE} lsf "${R2}/base/${BCH}/All/" 2>/dev/null \
		| grep "^${PRODUCT}-kernel-${KERNCONF}-" | sort -V | tail -1)
	[ -n "${KPKG}" ] || fail "no ${PRODUCT}-kernel-${KERNCONF}-*.pkg under base/${BCH}/All/"
fi

# --- derive the pinned DATESTRING from the pkg version (...(a|b|r).YYYYMMDD.HHMM) ---
_ver=${KPKG#${PRODUCT}-kernel-${KERNCONF}-}; _ver=${_ver%.pkg}
DSTR=$(printf '%s' "${_ver}" | sed -nE 's/^.*\.[abr]\.([0-9]{8})\.([0-9]{4}).*$/\1-\2/p')
[ -n "${DSTR}" ] || fail "cannot derive DATESTRING from kernel pkg version '${_ver}'"

mkdir -p "${OUTDIR}"
say "fetching base/${BCH}/All/${KPKG} ..."
${RCLONE} copyto "${R2}/base/${BCH}/All/${KPKG}" "${OUTDIR}/${KPKG}" || fail "download failed"
_sz=$(wc -c < "${OUTDIR}/${KPKG}" 2>/dev/null | tr -d ' ')
[ "${_sz:-0}" -gt 1000000 ] || fail "downloaded pkg is implausibly small (${_sz:-0} bytes)"

cat > "${ENVOUT}" <<EOF
DATESTRING=${DSTR}
FREESENSE_PREFETCHED_KERNEL_DIR=${OUTDIR}
EOF
say "OK — ${KPKG} (${_sz} bytes); DATESTRING pinned to ${DSTR}; env -> ${ENVOUT}"
