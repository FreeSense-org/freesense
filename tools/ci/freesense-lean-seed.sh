#!/bin/sh
# freesense-lean-seed.sh — FreeSense lean-overlay build, CONSUMER side.
#
# Seed the poudriere repo from the FROZEN WEEKLY STOCK SNAPSHOT in R2 so the bulk that follows
# REUSES FreeBSD's prebuilt stock binaries and builds ONLY the ~135 custom/patched ports (kills
# the ~5h rust compile + the huge cold build). Sourced from builder_common.sh poudriere_bulk()
# AFTER the tree is pinned to the snapshot's commit + overlaid + version-stamped and this jail's
# make.conf is written (so the seeded set matches what the real bulk wants).
#
# NO FreeBSD contact here. The live fetch + banking now lives in a SEPARATE weekly job
# (tools/ci/freesense-stock-snapshot.sh, run via the ports-build 'snapshot' job) which mirrors
# the FULL stock closure to R2 once per rev. This script only READS that snapshot:
#     R2:.../ports-cache/stock/<chan>/<REV>/packages.tar   (all stock .pkg for this rev)
# Builds are therefore pure-consume + reproducible; there is no per-batch pkg.freebsd.org fetch
# and no "which batch banked it" incompleteness (one job with the full list builds the snapshot).
#
# STRICT to FREESENSE_REV: the jail is created from REV's base seed and the ports tree is pinned
# to REV's commit, so ONLY REV's stock is ABI/version-consistent with this build. If REV's
# snapshot is absent (the weekly snapshot job hasn't produced it yet / failed), we DON'T reach
# for a different rev (its shlibs/versions would just get rejected and rebuilt anyway) — we skip
# and let poudriere build everything from source (the old, safe, slow behaviour). A consistent
# roll-back to last week is done at the WORKFLOW level by overriding the rev (see stock-snapshot).
#
# Correctness is guaranteed by poudriere itself: it reuses a seeded package ONLY when
# pkgname+version+options+deps match what the tree wants; every custom/patched port either has a
# FreeSense-* name FreeBSD never publishes, or a divergent option -> poudriere rejects the seed
# and rebuilds it. So a wrong/extra seed only ever costs an extra source build.
#
# Best-effort + diagnosed (uploads a diag log to R2:.../debug/lean-seed.diag). Any failure leaves
# the bulk to build everything from source.
#
# Env in: FREESENSE_JAIL_NAME FREESENSE_PORTS_NAME FREESENSE_REV FREESENSE_CHANNEL
set -u
JAIL="${FREESENSE_JAIL_NAME:-}"
PORTS="${FREESENSE_PORTS_NAME:-}"
REV="${FREESENSE_REV:-}"
# channel (main=devel, RELENG_1_0=stable). Keys the frozen snapshot per channel so devel (rolling)
# and stable (frozen on an older rev, possibly the SAME rev with different options) never collide.
CHAN="${FREESENSE_CHANNEL:-main}"
PKGTOP="/usr/local/poudriere/data/packages/${JAIL}-${PORTS}"
REPODIR="${PKGTOP}/.real_cache"; [ -d "$REPODIR" ] || REPODIR="$PKGTOP"
SNAPDIR="R2:freesense-pkg/ports-cache/stock/${CHAN}/${REV}"

DIAG=/tmp/lean-seed.diag; : > "$DIAG"
say(){ echo ">>> lean-seed: $*"; printf '%s\n' "$*" >> "$DIAG"; }
finish(){ rclone copyto "$DIAG" R2:freesense-pkg/debug/lean-seed.diag --s3-no-check-bucket >/dev/null 2>&1 || true; }
trap finish EXIT
bail(){ say "SKIP — $1 (bulk will build everything from source)"; exit 0; }
# `rclone lsf MISSING` EXITS 0 with empty output (a successful listing of nothing), so testing its
# exit code is a false-positive existence check. Test for NON-EMPTY output instead.
snap_has(){ [ -n "$(rclone lsf "$1" 2>/dev/null)" ]; }

[ -n "$JAIL" ] && [ -n "$PORTS" ] || bail "missing env (JAIL/PORTS)"
command -v rclone >/dev/null 2>&1 || bail "no rclone"
[ -n "$REV" ] || bail "no FREESENSE_REV — cannot resolve the stock snapshot"
say "JAIL=$JAIL PORTS=$PORTS PKGTOP=$PKGTOP REPODIR=$REPODIR CHAN=$CHAN REV=$REV"

# --- consume: pull this rev's frozen stock snapshot and seed it into the repo -----------------
if ! snap_has "${SNAPDIR}/packages.tar"; then
	bail "no stock snapshot at ${SNAPDIR}/packages.tar for rev ${REV}"
fi
say "stock snapshot HIT for ${CHAN}/${REV} -> seeding from ${SNAPDIR}/packages.tar (no FreeBSD fetch)"
mkdir -p "$REPODIR/All"
rm -f /tmp/packages.tar
if ! rclone copyto --s3-no-check-bucket --retries 10 --low-level-retries 20 \
	"${SNAPDIR}/packages.tar" /tmp/packages.tar >>"$DIAG" 2>&1; then
	bail "download of ${SNAPDIR}/packages.tar failed"
fi
# packages.tar members are the *.pkg files at top level (tarred with -C <All> .)
if ! tar -xf /tmp/packages.tar -C "$REPODIR/All" >>"$DIAG" 2>&1; then
	bail "untar of packages.tar failed"
fi
rm -f /tmp/packages.tar
say "repo now holds $(ls "$REPODIR/All"/*.pkg 2>/dev/null | wc -l | tr -d ' ') pkgs after frozen stock seed"

# regenerate the catalog from the seeded All/ (frozen stock + any custom cache restored earlier)
rm -f "$REPODIR"/packagesite.* "$REPODIR"/meta.* "$REPODIR"/data.* 2>/dev/null || true
pkg repo "$REPODIR/" >/dev/null 2>&1 || say "WARN pkg repo regen failed (reuse degraded)"
[ -d "$PKGTOP/.real_cache" ] && ln -sfn .real_cache "$PKGTOP/.latest" 2>/dev/null || true
say "DONE (frozen) — poudriere bulk will REUSE the frozen stock and build only custom/patched"
