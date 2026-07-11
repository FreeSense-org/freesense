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
#     full from-source rebuild incl lang/rust (~4h).
#   run 29130633234: seeded REAL All/meta/meta.conf at the TOP level -> commit_packages'
#     relink loop hits them ("meta shadows repository file in .latest/meta") and unlink(2)
#     on the real All/ dir returns EPERM ("Operation not permitted") -> set -e -> the whole
#     build ABORTS after 1h25m of successful building.
# Correct recipe: REAL files go in .real_cache (our stable .real_<n>), .latest -> .real_cache,
# Latest/pkg.pkg lives INSIDE .real_cache, and the top level stays symlink-only.
REPODIR="${PKGTOP}/.real_cache"
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
# UNTAR WITH -k (keep existing), THEN KEEP-NEWEST-PER-PKGNAME. Two stale-copy problems meet here:
#  (a) the restored .real_cache carries OLD stock versions from past builds (json-c-0.18 when the
#      pinned tree wants 0.19) -> poudriere "new version" deletes + a missing-dep CASCADE through
#      clamav/snort3/ntopng/kea/squid (~15-20 needless rebuilds, run 29130633234);
#  (b) the cache ALSO carries FRESH tree-matched rebuilds NEWER than the frozen bank's lagging
#      copies (log4cplus-2.2.0.1 built last run vs the bank's 2.1.2 — FreeBSD's `latest` binaries
#      lag its ports tree, see the frozen-bank memory). A naive "bank always wins" purge deleted
#      those fresh copies and re-seeded the stale ones -> the SAME cascade re-ran every publish
#      (kea alone ~1h20). And for IDENTICAL filenames (kea-3.2.0.pkg in both), the cache copy is
#      the coherent one (linked against the tree's dep versions) while the bank's was linked
#      against its own older set -> the bank copy loses poudriere's dep check -> rebuild.
# The rule that solves both: versions only move forward, so extract the bank WITHOUT overwriting
# existing files (tar -k: an existing fresh copy beats the bank's same-named stale-linked twin),
# then keep only the HIGHEST version per pkgname-base (old stale copies lose to the bank's newer
# ones; fresh rebuilds beat the bank's laggards; duplicate FreeSense timestamp cores dedupe too).
# poudriere's own sanity/dep checks stay the final authority — a wrong survivor is just deleted
# and rebuilt (self-healing). pkgname-base strip (-[0-9][^-]*.*$) verified safe on
# py312-/openldap26-/zabbix7-/go125 style names; version order via sort -V (FreeBSD sort has -V).
# packages.tar members are the *.pkg files at top level (tarred with -C <All> .)
if ! tar -xkf /tmp/packages.tar -C "$REPODIR/All" >>"$DIAG" 2>&1; then
	# bsdtar -k exits 0 on skipped-existing; a real error means a broken download/extract
	bail "untar of packages.tar failed"
fi
rm -f /tmp/packages.tar
_all=/tmp/lean-all-pkgs.txt
ls "$REPODIR/All"/*.pkg 2>/dev/null | sed 's#.*/##' | sort -V > "$_all"
_pruned=0; _prev_base=""; _prev_file=""
while IFS= read -r _f; do
	_b=$(printf '%s' "$_f" | sed 's#\.pkg$##; s#-[0-9][^-]*.*$##')
	if [ "$_b" = "$_prev_base" ]; then
		# same pkgname, lower version sorted first -> drop the older copy
		rm -f "$REPODIR/All/$_prev_file"; _pruned=$((_pruned+1))
	fi
	_prev_base="$_b"; _prev_file="$_f"
done < "$_all"
rm -f "$_all"
[ "${_pruned}" -gt 0 ] && say "pruned ${_pruned} older duplicate versions (keep-newest-per-pkgname)"
say "repo now holds $(ls "$REPODIR/All"/*.pkg 2>/dev/null | wc -l | tr -d ' ') pkgs after frozen stock seed"

# --- BOOTSTRAP: Latest/pkg.pkg INSIDE the real dir, so the .building clone can bootstrap pkg ----
# ensure_pkg_installed() (common.sh:7312) reads ${PACKAGES}/Latest/pkg.pkg (PACKAGES = the .building
# clone of .latest). If unreadable -> delete_all_pkgs "pkg bootstrap missing" -> the ENTIRE seed is
# wiped (proven run 29113843117 -> full from-source rebuild incl rust). The frozen tar ships
# pkg-<ver>.pkg in All/ but NO Latest/ dir, so build the link here, INSIDE ${REPODIR} where the
# clone picks it up. Relative symlink = exactly what poudriere itself creates.
PKGBOOT="$(ls "$REPODIR/All"/pkg-[0-9]*.pkg 2>/dev/null | sort -V | tail -1)"
if [ -n "$PKGBOOT" ]; then
	mkdir -p "$REPODIR/Latest"
	ln -sf "../All/$(basename "$PKGBOOT")" "$REPODIR/Latest/pkg.pkg"
	ln -sf "../All/$(basename "$PKGBOOT")" "$REPODIR/Latest/pkg.txz"
	say "bootstrap: Latest/pkg.pkg -> All/$(basename "$PKGBOOT")"
else
	say "WARN no pkg-*.pkg in seed -> poudriere may still wipe (bootstrap link not created)"
fi

# regenerate the catalog from the seeded All/ — INSIDE the real dir (real catalog files at the
# PKGTOP top level are fatal at commit time, see layout note above)
rm -f "$REPODIR"/packagesite.* "$REPODIR"/meta.* "$REPODIR"/data.* 2>/dev/null || true
pkg repo "$REPODIR/" >/dev/null 2>&1 || say "WARN pkg repo regen failed (reuse degraded)"

# --- top-level hygiene: .latest -> .real_cache; top level = SYMLINKS ONLY ----------------------
# stash_packages keys off `[ -L ${PACKAGES}/.latest ]` and clones ITS target; commit_packages'
# relink loop unlinks top-level entries to re-link them into the new .real_<n> — unlink(2) on a
# REAL directory returns EPERM and kills the build after all ports built (run 29130633234). So:
# remove any REAL (non-symlink) top-level entry defensively, then create the canonical symlinks.
ln -sfn .real_cache "$PKGTOP/.latest"
for _e in All Latest meta meta.conf meta.txz packagesite.pkg packagesite.txz data.pkg data.txz digests.pkg; do
	if [ -e "$PKGTOP/$_e" ] && [ ! -L "$PKGTOP/$_e" ]; then
		say "removing REAL top-level ${_e} (would be fatal at commit; real copy lives in .real_cache)"
		rm -rf "$PKGTOP/${_e:?}"
	fi
done
ln -sfn .latest/All    "$PKGTOP/All"
ln -sfn .latest/Latest "$PKGTOP/Latest"

# --- stamp .jailversion so poudriere does NOT wipe the freshly-seeded cache -------------------
# poudriere's prepare_ports() (common.sh ~10301) reads ${PACKAGES}/.jailversion and, if it exists
# but != `jget ${JAILNAME} version`, runs delete_all_pkgs "newer version of jail" — nuking every
# seed we just laid down. The R2 cache-restore ships an OLD .jailversion, and the jail here is
# freshly created from THIS rev's base seed, so the strings differ -> false-positive wipe. In our
# model the jail (base-<rev>.txz) and the stock binaries (stock/<chan>/<rev>) come from the SAME
# pinned rev, so they ARE ABI-consistent by construction; the version-string mismatch is spurious.
# Overwrite .jailversion to the jail's current version so the check passes (do NOT delete it — an
# absent file skips the check, but a stale restored one would still fire). If we can't read the
# jail version, remove the stale file (skip-the-check) rather than leave a mismatching one.
# NB: the "pkg bootstrap missing" wipe is a SEPARATE, independent failure (NOT a consequence of a
# jailversion wipe, as an earlier comment wrongly claimed). Run 29113843117 proved it: the jailversion
# stamp fired correctly (no "newer version of jail" line) yet poudriere STILL wiped with "pkg bootstrap
# missing" — because the seed had been written to .real_cache (which poudriere never reads) with no
# Latest/pkg.pkg. Fixed above by creating the Latest/pkg.pkg bootstrap link INSIDE ${REPODIR}
# (.real_cache), where stash_packages' .building clone picks it up.
# poudriere stores the jail's version string at ${POUDRIERED}/jails/<jail>/version and compares
# EXACTLY that (`jget ${JAILNAME} version`, common.sh:10306). Read the same file so our stamp is
# byte-identical to what the check reads. POUDRIERED defaults to <etc>/poudriere.d; on a standard
# install that is /usr/local/etc/poudriere.d. Fall back to `poudriere jail -i` if the layout differs.
POUD_ETC="${POUDRIERED:-/usr/local/etc/poudriere.d}"
JV=""
for jf in "$POUD_ETC/jails/$JAIL/version" /usr/local/etc/poudriere.d/jails/"$JAIL"/version; do
	[ -r "$jf" ] && { JV="$(cat "$jf" 2>/dev/null)"; break; }
done
[ -n "$JV" ] || JV="$(poudriere jail -i -j "$JAIL" 2>/dev/null | sed -n 's/^Version: *//p' | head -1)"
if [ -n "$JV" ]; then
	printf '%s\n' "$JV" > "$REPODIR/.jailversion"
	[ -e "$PKGTOP/.jailversion" ] && printf '%s\n' "$JV" > "$PKGTOP/.jailversion" 2>/dev/null || true
	say "stamped .jailversion=$JV (matches jail $JAIL -> no false-positive cache wipe)"
else
	rm -f "$REPODIR/.jailversion" "$PKGTOP/.jailversion" 2>/dev/null || true
	say "WARN could not read jail version -> removed stale .jailversion (poudriere skips the check)"
fi

say "DONE (frozen) — poudriere bulk will REUSE the frozen stock and build only custom/patched"
