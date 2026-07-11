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
# CRITICAL: poudriere `bulk` mounts and reads ${PKGTOP} DIRECTLY as its $PACKAGES dir
# ("Mounting packages from: .../${JAIL}-${PORTS}") — it reads ${PKGTOP}/All and requires a
# bootstrappable ${PKGTOP}/Latest/pkg.pkg. It NEVER looks in ${PKGTOP}/.real_cache. The old code
# seeded into .real_cache (because the workflow pre-creates it), so poudriere saw an EMPTY repo,
# hit "pkg bootstrap missing", wiped everything, and rebuilt the whole closure (incl. lang/rust,
# ~4h) from source. Seed into ${PKGTOP} itself so poudriere actually reuses it. .real_cache is only
# the S3-save staging convention; we keep .latest resolvable for that plumbing below.
REPODIR="${PKGTOP}"
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
# Fold in the workflow's restored shared cache (.real_cache/All) — but ONLY the packages the frozen
# bank does NOT already provide. The frozen stock bank (stock/<chan>/<rev>) is AUTHORITATIVE for
# stock: it is version-correct for the pinned ports tree by construction. The restored .real_cache
# is the accumulated OUTPUT of past builds, keyed only by jail-rev — so within a rev it carries
# STALE stock at older versions (e.g. json-c-0.18 when the tree now wants 0.19, ca_root_nss-3.124
# vs 3.125). If we blindly union it in, those stale copies SHADOW the frozen bank's correct ones;
# poudriere then "Deleting json-c-0.18: new version 0.19" + a missing-dependency CASCADE
# (clamav/snort3/ntopng/kea/squid...) -> ~15-20 needless from-source rebuilds (proven run 29130633234).
# Fix: keep a .real_cache pkg ONLY if its pkgname-base (name minus -version) is NOT already present
# in the frozen seed. That preserves genuinely-custom FreeSense-built pkgs salvaged from sibling
# batches (the bank never has those) while letting the bank win every stock version.
if [ -d "$PKGTOP/.real_cache/All" ] && [ "$REPODIR" != "$PKGTOP/.real_cache" ]; then
	# names already provided by the frozen bank, as pkgname-base (strip the -<version> tail)
	_bankbases=/tmp/lean-bank-bases.txt
	ls "$REPODIR/All"/*.pkg 2>/dev/null | sed 's#.*/##; s#\.pkg$##; s#-[0-9][^-]*.*$##' | sort -u > "$_bankbases"
	_folded=0; _skipped=0
	for _p in "$PKGTOP/.real_cache/All"/*.pkg; do
		[ -e "$_p" ] || continue
		_b=$(basename "$_p" .pkg | sed 's#-[0-9][^-]*.*$##')
		if grep -qxF "$_b" "$_bankbases"; then
			_skipped=$((_skipped+1))   # frozen bank already has this name -> its version wins
		else
			cp -n "$_p" "$REPODIR/All"/ 2>/dev/null && _folded=$((_folded+1))
		fi
	done
	rm -f "$_bankbases"
	say "folded ${_folded} custom cache pkgs; skipped ${_skipped} shadowing the frozen bank (bank version wins)"
fi
say "repo now holds $(ls "$REPODIR/All"/*.pkg 2>/dev/null | wc -l | tr -d ' ') pkgs after frozen stock seed"

# --- BOOTSTRAP: give poudriere a Latest/pkg.pkg so its sanity check does NOT wipe the seed -------
# poudriere's sanity_check_pkg_repo requires a bootstrappable pkg at ${PACKAGES}/Latest/pkg.pkg;
# without it, it logs "pkg bootstrap missing: unable to inspect existing packages, cleaning all
# packages" and DELETES the entire seed (proven in run 29113843117 -> full from-source rebuild incl
# rust). The frozen tar ships pkg-<ver>.pkg in All/ but NO Latest/ dir, so we build the link here.
# pkg wants Latest/pkg.pkg (and historically pkg.txz) pointing at the newest pkg-*.pkg in All/.
PKGBOOT="$(ls "$REPODIR/All"/pkg-[0-9]*.pkg 2>/dev/null | sort -V | tail -1)"
if [ -n "$PKGBOOT" ]; then
	mkdir -p "$REPODIR/Latest"
	# relative symlinks so the repo stays relocatable (poudriere resolves within the mount)
	ln -sf "../All/$(basename "$PKGBOOT")" "$REPODIR/Latest/pkg.pkg"
	ln -sf "../All/$(basename "$PKGBOOT")" "$REPODIR/Latest/pkg.txz"
	say "bootstrap: Latest/pkg.pkg -> All/$(basename "$PKGBOOT")"
else
	say "WARN no pkg-*.pkg in seed -> poudriere may still wipe (bootstrap link not created)"
fi

# regenerate the catalog from the seeded All/ (frozen stock + any custom cache restored earlier)
rm -f "$REPODIR"/packagesite.* "$REPODIR"/meta.* "$REPODIR"/data.* 2>/dev/null || true
pkg repo "$REPODIR/" >/dev/null 2>&1 || say "WARN pkg repo regen failed (reuse degraded)"
# Keep .latest resolvable for the S3-save plumbing (workflow does readlink .latest). We seeded into
# ${PKGTOP} itself now, so point .latest at '.' (self) rather than the empty .real_cache.
ln -sfn . "$PKGTOP/.latest" 2>/dev/null || true

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
# Latest/pkg.pkg. That is fixed above by seeding into ${PKGTOP} and creating the Latest bootstrap link.
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
