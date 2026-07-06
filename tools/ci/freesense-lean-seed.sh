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
# FROZEN STOCK BANK (FREESENSE_REV): FreeBSD's live 'latest' repo rolls under us -> two batches
# (or a batch and publish) fetch different versions of the same stock port -> poudriere deletes
# and rebuilds the drifted ones ("new version"). To kill that at the root we freeze the stock
# closure into an IMMUTABLE, rev-keyed R2 bank:
#     R2:.../ports-cache/stock/<chan>/<REV>/{All/*.pkg, packagesite.*, meta.*, data.*, ports_top_git_hash}
#     R2:.../ports-cache/stock/<chan>/current   (one line = the channel's active REV pointer)
# HIT  (bank exists for this week's rev): rclone the frozen bytes straight into the repo and skip
#      the live fetch entirely -> every batch AND publish seed IDENTICAL bytes -> zero drift.
# MISS (first build of a new rev): do the live fetch below, seed the build, then BANK exactly
#      that fetched All/ + catalog + resolved ports_top_git_hash and flip 'current' -> the rest of
#      the week's builds are frozen. Rev is the same snapshot rev the jail was built from (base +
#      jail + stock share one rev) so shlib/__FreeBSD_version drift is structurally impossible.
#
# Env in: FREESENSE_JAIL_NAME FREESENSE_BULK FREESENSE_MAKECONF FREESENSE_PORTS_NAME FREESENSE_OVERLAY_DIR FREESENSE_REV
set -u
JAIL="${FREESENSE_JAIL_NAME:-}"
BULK="${FREESENSE_BULK:-}"
PORTS="${FREESENSE_PORTS_NAME:-}"
OVERLAY="${FREESENSE_OVERLAY_DIR:-/root/freesense-ports}"
REV="${FREESENSE_REV:-}"
# channel (main=devel, RELENG_1_0=stable). Keys the frozen bank per channel so devel (rolling)
# and stable (frozen on an older rev, possibly the SAME rev with different options) never collide.
CHAN="${FREESENSE_CHANNEL:-main}"
EXTRA="$(dirname "$0")/../conf/pfPorts/must-build.extra"
PKGTOP="/usr/local/poudriere/data/packages/${JAIL}-${PORTS}"
REPODIR="${PKGTOP}/.real_cache"; [ -d "$REPODIR" ] || REPODIR="$PKGTOP"
STOCKBANK="R2:freesense-pkg/ports-cache/stock/${CHAN}/${REV}"
STOCKPTR="R2:freesense-pkg/ports-cache/stock/${CHAN}/current"

DIAG=/tmp/lean-seed.diag; : > "$DIAG"
say(){ echo ">>> lean-seed: $*"; printf '%s\n' "$*" >> "$DIAG"; }
finish(){ rclone copyto "$DIAG" R2:freesense-pkg/debug/lean-seed.diag --s3-no-check-bucket >/dev/null 2>&1 || true; }
trap finish EXIT
bail(){ say "SKIP — $1 (bulk will build everything from source)"; exit 0; }

[ -n "$JAIL" ] && [ -n "$BULK" ] && [ -n "$PORTS" ] || bail "missing env (JAIL/BULK/PORTS)"
say "JAIL=$JAIL PORTS=$PORTS PKGTOP=$PKGTOP REPODIR=$REPODIR OVERLAY=$OVERLAY CHAN=$CHAN REV=${REV:-<none>}"

# --- 0. FROZEN STOCK BANK: if this week's rev is already banked, seed from the frozen copy -------
if [ -n "$REV" ] && command -v rclone >/dev/null 2>&1 \
   && rclone lsf "${STOCKBANK}/meta.conf" >/dev/null 2>&1; then
	say "frozen stock bank HIT for rev ${REV} -> seeding from ${STOCKBANK} (no live FreeBSD fetch)"
	mkdir -p "$REPODIR/All"
	# Immutable + additive: --ignore-existing keeps anything already restored (custom cache) and
	# lays the frozen stock alongside it. Copy the catalog too so poudriere trusts the set.
	rclone copy --fast-list --transfers 16 --ignore-existing "${STOCKBANK}/All" "$REPODIR/All" >>"$DIAG" 2>&1 || true
	say "repo now holds $(ls "$REPODIR/All"/*.pkg 2>/dev/null | wc -l | tr -d ' ') pkgs after frozen seed"
	# regenerate the catalog from the merged All/ (frozen stock + restored custom)
	rm -f "$REPODIR"/packagesite.* "$REPODIR"/meta.* "$REPODIR"/data.* 2>/dev/null || true
	pkg repo "$REPODIR/" >/dev/null 2>&1 || say "WARN pkg repo regen failed (reuse degraded)"
	[ -d "$PKGTOP/.real_cache" ] && ln -sfn .real_cache "$PKGTOP/.latest" 2>/dev/null || true
	say "DONE (frozen) — poudriere bulk will REUSE the frozen stock and build only custom/patched"
	exit 0
fi
[ -n "$REV" ] && say "frozen stock bank MISS for rev ${REV} -> live fetch below, then bank the result"

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
# Stage EXACTLY the fetched stock closure into a dedicated bank dir (so the frozen bank we upload
# holds only the stock we depend on — not VM-tooling leftovers or restored custom cache), then
# seed the live build from the same staging dir.
BANKALL=/tmp/lean-bank/All; mkdir -p "$BANKALL"
$RQ '%n-%v' 2>/dev/null | sort -u > /tmp/lean-fetched-nv.lst || : > /tmp/lean-fetched-nv.lst
find "$CACHE" -name '*.pkg' | while read -r p; do
	b=$(basename "$p" .pkg)
	# keep it only if its name-version is in the FreeBSD stock set we resolved (skip strays)
	if grep -qxF "$b" /tmp/lean-fetched-nv.lst 2>/dev/null; then cp -n "$p" "$BANKALL/"; fi
done 2>/dev/null || true
# fallback: if the name-version filter matched nothing, bank whatever we fetched (old behaviour)
[ -n "$(ls "$BANKALL"/*.pkg 2>/dev/null | head -1)" ] || find "$CACHE" -name '*.pkg' -exec cp -n {} "$BANKALL/" \; 2>/dev/null || true
cp -n "$BANKALL"/*.pkg "$REPODIR/All/" 2>/dev/null || true
say "staged $(ls "$BANKALL"/*.pkg 2>/dev/null | wc -l | tr -d ' ') stock pkgs; repo now holds $(ls "$REPODIR/All"/*.pkg 2>/dev/null | wc -l | tr -d ' ') pkgs (stock + any restored cache)"

# --- 6. regenerate the catalog so poudriere sees the seeds ----------------------------------
rm -f "$REPODIR"/packagesite.* "$REPODIR"/meta.* "$REPODIR"/data.* 2>/dev/null || true
pkg repo "$REPODIR/" >/dev/null 2>&1 || say "WARN pkg repo regen failed (reuse degraded)"
[ -d "$PKGTOP/.real_cache" ] && ln -sfn .real_cache "$PKGTOP/.latest" 2>/dev/null || true

# --- 7. FREEZE: bank this rev's stock closure to R2 (immutable) + flip the 'current' pointer ---
# So every LATER build this week (other batches + publish) takes the frozen-HIT path above and
# seeds identical bytes -> no version drift. Only the first build of a new rev reaches here.
if [ -n "$REV" ] && command -v rclone >/dev/null 2>&1 && [ -n "$(ls "$BANKALL"/*.pkg 2>/dev/null | head -1)" ]; then
	# double-check nobody banked while we were fetching (two batches racing) — if so, skip upload
	if rclone lsf "${STOCKBANK}/meta.conf" >/dev/null 2>&1; then
		say "another build already banked stock/${REV} while we fetched — skipping upload"
	else
		# record the ports_top_git_hash these binaries were built from (pins the tree deterministically)
		HASH=$(pkg rquery ${FBREPO:+-r $FBREPO} '%Ak=%Av' pkg 2>/dev/null | sed -n 's/^ports_top_git_hash=//p')
		[ -n "$HASH" ] || HASH=$(pkg rquery '%Ak=%Av' pkg 2>/dev/null | sed -n 's/^ports_top_git_hash=//p')
		# build a self-contained catalog for the bank dir, then upload All/ + catalog + hash
		pkg repo /tmp/lean-bank/ >/dev/null 2>&1 || say "WARN bank catalog regen failed"
		[ -n "$HASH" ] && printf '%s\n' "$HASH" > /tmp/lean-bank/ports_top_git_hash
		say "banking $(ls "$BANKALL"/*.pkg 2>/dev/null | wc -l | tr -d ' ') stock pkgs -> ${STOCKBANK} (hash=${HASH:-<none>})"
		if rclone copy --fast-list --transfers 16 --ignore-existing /tmp/lean-bank "${STOCKBANK}" >>"$DIAG" 2>&1; then
			# meta.conf is the HIT sentinel — upload it LAST so a partial bank never reads as complete
			rclone copyto --s3-no-check-bucket /tmp/lean-bank/meta.conf "${STOCKBANK}/meta.conf" >>"$DIAG" 2>&1 || true
			# flip the per-channel pointer only after a fully successful bank
			printf '%s\n' "$REV" > /tmp/stock-current
			rclone copyto --s3-no-check-bucket /tmp/stock-current "$STOCKPTR" >>"$DIAG" 2>&1 || true
			say "frozen stock bank PUBLISHED for ${CHAN}/${REV}; ${CHAN} 'current' -> ${REV}"
		else
			say "WARN bank upload failed — this run still builds fine; next run retries the fetch+bank"
		fi
	fi
fi
say "DONE — poudriere bulk will REUSE the seeds and build only the custom/patched ports"
