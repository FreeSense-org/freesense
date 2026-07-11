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
# We want everything EXCEPT the kernel packages (FreeBSD-kernel-*, FreeBSD-src*), the
# debug sets (*-dbg), tests, and lib32 — to keep the image lean. Rather than install the
# whole FreeBSD-* glob and delete the unwanted ~191 afterwards (which fetched+unpacked
# them only to remove them, twice), we resolve the wanted names from the catalog up front
# (compute_wanted) and install exactly that set — one pass per chroot.
# Common pkg args for talking to the pkgbase repo with a forced target ABI. Wrapped in
# a function so update + rquery + install all use the SAME repo dir / ABI / osversion.
#   ABI / OSVERSION       : force the FreeBSD:16 target (host is 15.x) — issue #2533.
#   REPOS_DIR             : our throwaway conf ONLY (ignore the host's own repos).
#   IGNORE_OSVERSION=yes  : the host pkg is 15.x installing 16 pkgs -> silences the
#                           "Major OS version upgrade detected" refusal for a fresh root.
#   ASSUME_ALWAYS_YES via env below (NOT -o, which conflicts and warns).
pkgbase() {
	_root="$1"; shift
	env ABI="${ABI}" ASSUME_ALWAYS_YES=yes \
		pkg --rootdir "${_root}" \
		    -o OSVERSION="${OSVERSION}" \
		    -o REPOS_DIR="${REPO_CONF_DIR}" \
		    -o IGNORE_OSVERSION=yes \
		    "$@"
}

# What we do NOT want in a firewall image / what we build ourselves: the custom kernel
# (FreeBSD-kernel-*), the full src tree (FreeBSD-src*), debug symbols (*-dbg — the single
# biggest chunk of base_latest), tests, and lib32 (WITHOUT_LIB32 in the classic build).
# These are shell case-globs, matched against each candidate name below.
UNWANTED='FreeBSD-kernel-* FreeBSD-src* FreeBSD-*-dbg FreeBSD-tests* FreeBSD-*-lib32'

# Compute the exact "wanted" pkgbase name list ONCE from the catalog, so we install only
# what ships — no fetch-then-delete. Previously seed_chroot installed the whole FreeBSD-*
# glob (~530 pkgs, incl. all the huge -dbg/src/tests sets) and then deleted ~191 of them,
# and did that TWICE (stage + installer) — hundreds of packages fetched+unpacked only to
# be removed. Filtering up front turns that into a single precise install per chroot.
# Uses a scratch root just for the catalog query (the wanted set is identical for both
# real chroots, so we resolve it here and reuse it).
compute_wanted() {
	_probe=$(mktemp -d)
	if ! pkgbase "${_probe}" update -f -r FreeBSD-base >/dev/null; then
		rm -rf "${_probe}"; die "pkg update -r FreeBSD-base failed (cannot reach base_latest)"
	fi
	# All available pkgbase names, minus the UNWANTED case-globs.
	# NB: `pkg rquery FMT 'FreeBSD-*'` matches an EXACT name unless -g is given — the bare
	# glob returned 0 rows and killed the first ISO run of this code (run 29143251784:
	# "resolved only 0 wanted pkgbase names"). Use -a (all remote pkgs — base_latest carries
	# only FreeBSD-*) + a positive case-glob in the loop as the filter. No 2>/dev/null: if
	# pkg complains we want it in the log, not swallowed.
	pkgbase "${_probe}" rquery -a -r FreeBSD-base '%n' | while read -r _n; do
		[ -n "${_n}" ] || continue
		case "${_n}" in FreeBSD-*) ;; *) continue ;; esac
		_skip=0
		for _pat in ${UNWANTED}; do
			# shellcheck disable=SC2254  # intentional glob match
			case "${_n}" in ${_pat}) _skip=1; break ;; esac
		done
		[ "${_skip}" = 0 ] && printf '%s\n' "${_n}"
	done
	rm -rf "${_probe}"
}

WANTED=$(compute_wanted)
WANTED_N=$(printf '%s\n' "${WANTED}" | grep -c . || true)
[ "${WANTED_N:-0}" -gt 50 ] || die "resolved only ${WANTED_N} wanted pkgbase names — catalog query looks wrong"
say "resolved ${WANTED_N} wanted pkgbase packages (kernel/src/dbg/tests/lib32 excluded up front)"

seed_chroot() {
	_root="$1"; _label="$2"
	say "seeding ${_label} world into ${_root}"
	mkdir -p "${_root}"

	# 1. Populate the FreeBSD-base catalog for THIS root's repo dir. Without this the
	#    install fails "Repository FreeBSD-base cannot be opened. 'pkg update' required"
	#    (the earlier probe updated a DIFFERENT temp dir — that was the run-1 bug).
	if ! pkgbase "${_root}" update -f -r FreeBSD-base; then
		die "${_label}: pkg update -r FreeBSD-base failed (cannot reach base_latest)"
	fi

	# 2. Install EXACTLY the pre-filtered wanted set — one pass, no fetch-then-delete.
	#    (Named packages, not a glob, so the unwanted sets are never fetched at all.)
	# shellcheck disable=SC2086  # $WANTED is an intentional word list
	if ! pkgbase "${_root}" install -y -r FreeBSD-base ${WANTED}; then
		die "pkgbase install into ${_root} failed"
	fi

	# 3. Safety prune: if any WANTED package hard-depends on an UNWANTED one, pkg would have
	#    pulled it back in as a dependency. base_latest's runtime set is leaf-clean today so
	#    this is normally a no-op, but keep the guarantee the old delete-after pass gave —
	#    the image never ships kernel/src/dbg/tests/lib32. Cheap when there's nothing to do.
	# shellcheck disable=SC2086  # UNWANTED is an intentional glob list
	if pkgbase "${_root}" info -g ${UNWANTED} >/dev/null 2>&1; then
		say "${_label}: pruning dependency-pulled unwanted packages"
		# shellcheck disable=SC2086
		pkgbase "${_root}" delete -y -f -g ${UNWANTED} 2>/dev/null || true
	fi

	# 4. Physically strip the fat runtime-irrelevant trees. The base.txz exclude_files
	#    (built from the STAGE chroot) already drops /usr/src etc. from the update payload,
	#    but the INSTALLER chroot IS the live ISO filesystem — so an unstripped /usr/src
	#    (~1.5GB, dep-pulled via a FreeBSD-set-* meta the UNWANTED globs don't name-match)
	#    bloats the raw ISO even when base.txz is slim (2026-07-11: ISO stayed ~4GB while
	#    base.txz dropped to 873MB). rm them from BOTH chroots so neither the payload nor
	#    the installer media carries them. These are never needed at runtime on a firewall.
	for _fat in usr/src usr/tests usr/lib32 usr/lib/debug; do
		if [ -d "${_root}/${_fat}" ]; then
			chflags -R noschg "${_root}/${_fat}" 2>/dev/null || true
			rm -rf "${_root}/${_fat}"
		fi
	done

	_n=$(pkgbase "${_root}" info 2>/dev/null | wc -l | tr -d ' ')
	[ "${_n:-0}" -gt 50 ] || die "${_label}: only ${_n} pkgs installed — seed looks incomplete"
	say "${_label}: ${_n} pkgbase packages installed"
}

# The stage and installer chroots are fully independent — different roots (each with its
# OWN ${_root}/var/cache/pkg, so no shared-cache race), sharing only the read-only catalog
# (WANTED, resolved once above). The work is fetch/unpack-bound, not CPU-bound, so seeding
# them CONCURRENTLY overlaps the network+disk waits instead of paying both serially. Each
# logs to its own file; we print them after so the combined output stays readable, and we
# honor a non-zero exit from either.
if [ "${FREESENSE_PKGBASE_PARALLEL_SEED:-1}" = 1 ]; then
	say "seeding stage + installer chroots concurrently"
	_slog=$(mktemp); _ilog=$(mktemp)
	( seed_chroot "${STAGE}" "stage"     >"${_slog}" 2>&1 ) & _spid=$!
	( seed_chroot "${INST}"  "installer" >"${_ilog}" 2>&1 ) & _ipid=$!
	wait "${_spid}"; _src=$?
	wait "${_ipid}"; _irc=$?
	echo "----- stage seed log -----";     cat "${_slog}"
	echo "----- installer seed log -----"; cat "${_ilog}"
	rm -f "${_slog}" "${_ilog}"
	[ "${_src}" = 0 ] || die "stage chroot seed failed (rc=${_src})"
	[ "${_irc}" = 0 ] || die "installer chroot seed failed (rc=${_irc})"
else
	# Serial fallback (FREESENSE_PKGBASE_PARALLEL_SEED=0) — simpler to debug if the
	# concurrent fetches ever contend badly on a constrained builder.
	seed_chroot "${STAGE}" "stage"
	seed_chroot "${INST}"  "installer"
fi

say "DONE — world seeded from FreeBSD pkgbase (no buildworld). Kernel builds next."
