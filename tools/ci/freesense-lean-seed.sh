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
SNAPSHOT_ID="${FREESENSE_SNAPSHOT_ID:-$REV}"
# channel (main=devel, RELENG_1_0=stable). Keys the frozen snapshot per channel so devel (rolling)
# and stable (frozen on an older rev, possibly the SAME rev with different options) never collide.
CHAN="${FREESENSE_CHANNEL:-main}"
PKGTOP="/usr/local/poudriere/data/packages/${JAIL:-FreeSense_main_amd64}-${PORTS:-FreeSense_main}"
# poudriere ATOMIC repo layout (verified against poudriere common.sh stash_packages:3248 /
# commit_packages:3293 / convert_repository:3199):
#   .real_<n>/            REAL dir holding the repo (All/, Latest/, catalog files)
#   .latest -> .real_<n>  symlink to the current real dir
#   All, Latest, meta.conf, packagesite.pkg, ... at top level = SYMLINKS into .latest/
# bulk NEVER reads the top level directly: stash_packages clones .latest -> .building
# (hardlinks) and the whole run reads/writes .building; commit_packages then renames
# .building -> .real_<new>, atomically repoints .latest, and re-links the top level.
# TWO proven failure modes (one per prior attempt):
#   run 29113843117: seed in .real_cache but NO Latest/pkg.pkg inside it -> the .building
#     clone had no bootstrap -> ensure_pkg_installed() fails -> "pkg bootstrap missing:
#     unable to inspect existing packages, cleaning all packages" -> ENTIRE seed wiped ->
#     full from-source rebuild incl lang/rust (~4h). Offline epoch path re-hit this on
#     2026-07-15 (runs 29421043596 / 29423566026): stock seeded then wiped, rebuild of
#     ports-mgmt/pkg offline fails -> print_error_pfS -> rc=143.
#   run 29130633234: seeded REAL All/meta/meta.conf at the TOP level -> commit_packages'
#     relink loop hits them ("meta shadows repository file in .latest/meta") and unlink(2)
#     on the real All/ dir returns EPERM ("Operation not permitted") -> set -e -> the whole
#     build ABORTS after 1h25m of successful building.
# Correct recipe: REAL files go in .real_cache (our stable .real_<n>), .latest -> .real_cache,
# Latest/pkg.pkg lives INSIDE .real_cache, and the top level stays symlink-only.
REPODIR="${PKGTOP}/.real_cache"

# Finalize a seeded .real_cache so poudriere bulk will REUSE it (shared by online + offline).
lean_seed_finalize_repo() {
	# --- BOOTSTRAP: Latest/pkg.pkg INSIDE the real dir (see run 29113843117) -------------
	PKGBOOT="$(ls "$REPODIR/All"/pkg-[0-9]*.pkg 2>/dev/null | sort -V | tail -1)"
	if [ -n "$PKGBOOT" ]; then
		mkdir -p "$REPODIR/Latest"
		ln -sf "../All/$(basename "$PKGBOOT")" "$REPODIR/Latest/pkg.pkg"
		ln -sf "../All/$(basename "$PKGBOOT")" "$REPODIR/Latest/pkg.txz"
		echo ">>> lean-seed: bootstrap Latest/pkg.pkg -> All/$(basename "$PKGBOOT")"
	else
		echo ">>> lean-seed: WARN no pkg-*.pkg in seed -> poudriere may wipe (bootstrap link not created)"
	fi

	rm -f "$REPODIR"/packagesite.* "$REPODIR"/meta.* "$REPODIR"/data.* 2>/dev/null || true
	pkg repo "$REPODIR/" >/dev/null 2>&1 || echo ">>> lean-seed: WARN pkg repo regen failed (reuse degraded)"

	ln -sfn .real_cache "$PKGTOP/.latest"
	for _e in All Latest meta meta.conf meta.txz packagesite.pkg packagesite.txz data.pkg data.txz digests.pkg; do
		if [ -e "$PKGTOP/$_e" ] && [ ! -L "$PKGTOP/$_e" ]; then
			echo ">>> lean-seed: removing REAL top-level ${_e} (must be symlink-only)"
			rm -rf "$PKGTOP/${_e:?}"
		fi
	done
	ln -sfn .latest/All    "$PKGTOP/All"
	ln -sfn .latest/Latest "$PKGTOP/Latest"

	# Stamp .jailversion so poudriere does not false-positive wipe the seed.
	POUD_ETC="${POUDRIERED:-/usr/local/etc/poudriere.d}"
	_jv=""
	_jail="${JAIL:-${FREESENSE_JAIL_NAME:-FreeSense_main_amd64}}"
	for jf in "$POUD_ETC/jails/$_jail/version" /usr/local/etc/poudriere.d/jails/"$_jail"/version; do
		[ -r "$jf" ] && { _jv="$(cat "$jf" 2>/dev/null)"; break; }
	done
	[ -n "$_jv" ] || _jv="$(poudriere jail -i -j "$_jail" 2>/dev/null | sed -n 's/^Version: *//p' | head -1)"
	if [ -n "$_jv" ]; then
		printf '%s\n' "$_jv" > "$REPODIR/.jailversion"
		[ -e "$PKGTOP/.jailversion" ] && printf '%s\n' "$_jv" > "$PKGTOP/.jailversion" 2>/dev/null || true
		echo ">>> lean-seed: stamped .jailversion=$_jv (jail $_jail)"
	else
		rm -f "$REPODIR/.jailversion" "$PKGTOP/.jailversion" 2>/dev/null || true
		echo ">>> lean-seed: WARN could not read jail version -> removed stale .jailversion"
	fi
}

# A normal build epoch runs with guest networking disabled. The producer has
# already resolved and fetched the stock closure, so seed those signed bytes
# directly instead of consulting R2 or pkg.freebsd.org.
# MUST apply the same Latest/pkg.pkg + symlink-only top-level recipe as the online
# path, or poudriere prints "pkg bootstrap missing... cleaning all packages" and
# rebuilds stock from source offline (which then fails without distfiles).
if [ -n "${FREESENSE_EPOCH_OFFLINE:-}" ]; then
	case "${REPO_KIND:-system}" in
		system) _epoch_stock="${FREESENSE_EPOCH_SYSTEM_STOCK:-/root/epoch/stock-system-packages.tar}" ;;
		packages) _epoch_stock="${FREESENSE_EPOCH_PACKAGE_STOCK:-/root/epoch/stock-optional-packages.tar}" ;;
		*) echo ">>> lean-seed: invalid REPO_KIND '${REPO_KIND:-}'" >&2; exit 1 ;;
	esac
	[ -s "${_epoch_stock}" ] || { echo ">>> lean-seed: missing offline stock archive ${_epoch_stock}" >&2; exit 1; }
	mkdir -p "${REPODIR}/All"
	tar -xf "${_epoch_stock}" -C "${REPODIR}/All"
	_n="$(find "${REPODIR}/All" -name '*.pkg' 2>/dev/null | wc -l | tr -d ' ')"
	echo ">>> lean-seed: offline epoch ${FREESENSE_EPOCH_ID:-unknown} extracted ${_n} stock packages"
	lean_seed_finalize_repo
	echo ">>> lean-seed: offline epoch ${FREESENSE_EPOCH_ID:-unknown} finalized for poudriere reuse"
	return 0 2>/dev/null || exit 0
fi
SNAPDIR="R2:freesense-pkg/ports-cache/stock/${CHAN}/${SNAPSHOT_ID}"

DIAG=/tmp/lean-seed.diag; : > "$DIAG"
say(){ echo ">>> lean-seed: $*"; printf '%s\n' "$*" >> "$DIAG"; }
finish(){ rclone copyto "$DIAG" R2:freesense-pkg/debug/lean-seed.diag --s3-no-check-bucket >/dev/null 2>&1 || true; }
trap finish EXIT
bail(){ say "ABORT — $1 (refusing an accidental full source build)"; exit 1; }
# `rclone lsf MISSING` EXITS 0 with empty output (a successful listing of nothing), so testing its
# exit code is a false-positive existence check. Test for NON-EMPTY output instead.
snap_has(){ [ -n "$(rclone lsf "$1" 2>/dev/null)" ]; }

[ -n "$JAIL" ] && [ -n "$PORTS" ] || bail "missing env (JAIL/PORTS)"
command -v rclone >/dev/null 2>&1 || bail "no rclone"
[ -n "$REV" ] || bail "no FREESENSE_REV — cannot resolve the stock snapshot"
say "JAIL=$JAIL PORTS=$PORTS PKGTOP=$PKGTOP REPODIR=$REPODIR CHAN=$CHAN REV=$REV SNAPSHOT_ID=$SNAPSHOT_ID"

# --- consume: pull this rev's frozen stock snapshot and seed it into the repo -----------------
if ! snap_has "${SNAPDIR}/packages.tar"; then
	bail "no stock snapshot at ${SNAPDIR}/packages.tar for build fingerprint ${SNAPSHOT_ID}"
fi
say "stock snapshot HIT for ${CHAN}/${SNAPSHOT_ID} -> seeding from ${SNAPDIR}/packages.tar (no FreeBSD fetch)"
mkdir -p "$REPODIR/All"
rm -f /tmp/packages.tar
if ! rclone copyto --s3-no-check-bucket --retries 10 --low-level-retries 20 \
	"${SNAPDIR}/packages.tar" /tmp/packages.tar >>"$DIAG" 2>&1; then
	bail "download of ${SNAPDIR}/packages.tar failed"
fi
# The bank and ports tree share one ports_top_git_hash, so the bank is authoritative for
# every stock package name. Never let a numerically newer package from another revision win:
# that preserves an incoherent dependency graph (for example log4cplus 2.2 in a tree whose
# Kea packages require 2.1.2) and causes the same rebuild on every consumer.
_bank_members=/tmp/lean-bank-members.txt
if ! tar -tf /tmp/packages.tar | sed 's#^\./##; /^$/d' > "$_bank_members"; then
	bail "cannot list packages.tar"
fi
_replaced=0
while IFS= read -r _member; do
	_f=$(basename "$_member")
	case "$_f" in
		*.pkg) ;;
		*) continue ;;
	esac
	_b=$(printf '%s' "$_f" | sed 's#\.pkg$##; s#-[0-9][^-]*.*$##')
	[ -n "$_b" ] || continue
	for _old in "$REPODIR/All/${_b}-"[0-9]*.pkg; do
		[ -e "$_old" ] || continue
		rm -f "$_old"; _replaced=$((_replaced+1))
	done
done < "$_bank_members"
rm -f "$_bank_members"
[ "$_replaced" -gt 0 ] && say "removed ${_replaced} cached stock packages superseded by frozen bank"
if ! tar -xf /tmp/packages.tar -C "$REPODIR/All" >>"$DIAG" 2>&1; then
	bail "untar of packages.tar failed"
fi
rm -f /tmp/packages.tar
say "repo now holds $(ls "$REPODIR/All"/*.pkg 2>/dev/null | wc -l | tr -d ' ') pkgs after frozen stock seed"

# Same finalize as the offline epoch path (Latest/pkg.pkg + symlink top-level + jailversion).
lean_seed_finalize_repo

say "DONE (frozen) — poudriere bulk will REUSE the frozen stock and build only custom/patched"
