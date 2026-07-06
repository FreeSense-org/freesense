#!/bin/sh
# freesense-lean-seed.sh — FreeSense lean-overlay build.
#
# Fetch FreeBSD's PREBUILT stock binaries and seed them into the poudriere repo so the bulk
# that follows REUSES them and builds ONLY the ~135 custom/patched ports (kills the ~5h rust
# compile + the huge cold-build). Sourced from builder_common.sh poudriere_bulk() AFTER the
# tree is pinned to FreeBSD's build commit + overlaid + version-stamped and this jail's
# make.conf is written (so the closure/options match what the real bulk wants).
#
# Correctness is guaranteed by poudriere itself: it reuses a seeded package ONLY when
# pkgname+version+options+deps match what the tree wants; every custom/patched port either has
# a FreeSense-* name FreeBSD never publishes, or a divergent option -> poudriere rejects the
# seed and rebuilds it. So a wrong/extra seed only ever costs an extra source build. The
# exclusion list below is a BANDWIDTH optimization, not a correctness requirement.
#
# Best-effort + heavily diagnosed (uploads a diag log to R2:.../debug/lean-seed.diag). Any
# failure leaves the bulk to build everything from source (the old behaviour).
#
# Env in: FREESENSE_JAIL_NAME FREESENSE_BULK FREESENSE_MAKECONF FREESENSE_PORTS_NAME FREESENSE_OVERLAY_DIR
set -u
JAIL="${FREESENSE_JAIL_NAME:-}"
BULK="${FREESENSE_BULK:-}"
PORTS="${FREESENSE_PORTS_NAME:-}"
OVERLAY="${FREESENSE_OVERLAY_DIR:-/root/freesense-ports}"
EXTRA="$(dirname "$0")/../conf/pfPorts/must-build.extra"
PKGTOP="/usr/local/poudriere/data/packages/${JAIL}-${PORTS}"
REPODIR="${PKGTOP}/.real_cache"; [ -d "$REPODIR" ] || REPODIR="$PKGTOP"

DIAG=/tmp/lean-seed.diag; : > "$DIAG"
say(){ echo ">>> lean-seed: $*"; printf '%s\n' "$*" >> "$DIAG"; }
finish(){ rclone copyto "$DIAG" R2:freesense-pkg/debug/lean-seed.diag --s3-no-check-bucket >/dev/null 2>&1 || true; }
trap finish EXIT
bail(){ say "SKIP — $1 (bulk will build everything from source)"; exit 0; }

[ -n "$JAIL" ] && [ -n "$BULK" ] && [ -n "$PORTS" ] || bail "missing env (JAIL/BULK/PORTS)"
say "JAIL=$JAIL PORTS=$PORTS PKGTOP=$PKGTOP REPODIR=$REPODIR OVERLAY=$OVERLAY"

# --- 1. FreeBSD binary ports repo (name differs by FreeBSD version) ------------------------
pkg update -f >>"$DIAG" 2>&1 || true
say "freebsd repo lines: $(pkg -vv 2>/dev/null | grep -iE 'url' | grep -i freebsd | tr -s ' ' | tr '\n' '|')"
FBREPO=""
for r in FreeBSD-ports FreeBSD; do
  v=$(pkg rquery -r "$r" '%v' pkg 2>/dev/null)
  say "probe repo '$r' (pkg %v) -> '$v'"
  [ -n "$v" ] && { FBREPO="$r"; break; }
done
RQ="pkg rquery ${FBREPO:+-r $FBREPO}"
say "FreeBSD ports repo = '${FBREPO:-<unnamed/all>}'; rust there = '$($RQ '%v' rust 2>/dev/null)'"
[ -n "$($RQ '%v' rust 2>/dev/null)" ] || bail "FreeBSD repo has no queryable packages"

# --- 2. must-build exclusion (overlay origins + curated extras) — bandwidth optimization ----
EXCL=/tmp/lean-excl.lst
( cd "$OVERLAY" 2>/dev/null && find . -mindepth 2 -maxdepth 2 -type d 2>/dev/null | grep -v '/\.git' | sed 's,^\./,,' ) > "$EXCL" 2>/dev/null || : > "$EXCL"
[ -f "$EXTRA" ] && grep -vE '^[[:space:]]*(#|$)' "$EXTRA" >> "$EXCL"
sort -u "$EXCL" -o "$EXCL"
say "exclusion (must-build) origins: $(wc -l < "$EXCL" | tr -d ' ')"

# --- 3. closure via poudriere dry-run -------------------------------------------------------
NOUT=/tmp/lean-n.out
poudriere bulk -n -f "$BULK" -j "$JAIL" -p "$PORTS" > "$NOUT" 2>&1 || true
say "=== poudriere bulk -n tail ==="; tail -20 "$NOUT" >> "$DIAG"; say "=== end -n tail ==="
LOGD="/usr/local/poudriere/data/logs/bulk/${JAIL}-${PORTS}/latest"
say "log dir contents: $(ls -a "$LOGD" 2>/dev/null | tr '\n' ' ')"
RAW=/tmp/lean-raw; : > "$RAW"
for f in "$LOGD/.poudriere.ports.queued" "$LOGD/.poudriere.all_pkgs" "$LOGD/.data.json"; do
  [ -f "$f" ] && { say "reading queue file $f"; cat "$f" >> "$RAW"; }
done
# closure origins = any category/port token from the queue files + the -n stdout
QUEUE=/tmp/lean-queue.lst
{ cat "$RAW" 2>/dev/null; cat "$NOUT"; } | grep -oE '[a-z][a-z0-9_-]*/[A-Za-z0-9._+-]+' | sort -u > "$QUEUE"
say "closure origins parsed: $(wc -l < "$QUEUE" | tr -d ' ')"
[ -s "$QUEUE" ] || bail "could not parse the build closure from poudriere -n"

# --- 4. stock = closure - exclusion (never fetch FreeSense-*/pfSense-*) ----------------------
STOCK=/tmp/lean-stock.lst
comm -23 "$QUEUE" "$EXCL" 2>/dev/null > "$STOCK" || cp "$QUEUE" "$STOCK"
grep -vE '/(FreeSense|pfSense)' "$STOCK" > "$STOCK.f" 2>/dev/null && mv "$STOCK.f" "$STOCK"
say "stock origins to fetch: $(wc -l < "$STOCK" | tr -d ' ')"

# --- 5. map stock origins -> FreeBSD pkgnames, fetch them all, seed into the repo -----------
NAMES=/tmp/lean-names.lst
$RQ '%o|%n' 2>/dev/null | awk -F'|' 'NR==FNR{w[$0]=1;next} ($1 in w){print $2}' "$STOCK" - | sort -u > "$NAMES"
say "stock pkgnames resolved from FreeBSD repo: $(wc -l < "$NAMES" | tr -d ' ')"
[ -s "$NAMES" ] || bail "no stock pkgnames resolved (repo/name mismatch?)"
mkdir -p "$REPODIR/All"
# fetch in batches (each pulls the binary to PKG_CACHEDIR)
xargs -L 250 pkg fetch -y ${FBREPO:+-r $FBREPO} < "$NAMES" >>"$DIAG" 2>&1 || true
CACHE=$(pkg config PKG_CACHEDIR 2>/dev/null); CACHE="${CACHE:-/var/cache/pkg}"
say "fetched .pkg in cache ($CACHE): $(find "$CACHE" -name '*.pkg' 2>/dev/null | wc -l | tr -d ' ')"
find "$CACHE" -name '*.pkg' -exec cp -n {} "$REPODIR/All/" \; 2>/dev/null || true
say "repo now holds $(ls "$REPODIR/All"/*.pkg 2>/dev/null | wc -l | tr -d ' ') pkgs (seeded stock + any restored cache)"

# --- 6. regenerate the catalog so poudriere sees the seeds ----------------------------------
rm -f "$REPODIR"/packagesite.* "$REPODIR"/meta.* "$REPODIR"/data.* 2>/dev/null || true
pkg repo "$REPODIR/" >/dev/null 2>&1 || say "WARN pkg repo regen failed (reuse degraded)"
[ -d "$PKGTOP/.real_cache" ] && ln -sfn .real_cache "$PKGTOP/.latest" 2>/dev/null || true
say "DONE — poudriere bulk will REUSE the seeds and build only the custom/patched ports"
