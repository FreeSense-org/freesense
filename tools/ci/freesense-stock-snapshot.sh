#!/bin/sh
# freesense-stock-snapshot.sh — FreeSense weekly FROZEN STOCK SNAPSHOT builder (PRODUCER side).
#
# THE ONLY thing that talks to pkg.freebsd.org for the package set. Run once per rev by the
# ports-build 'snapshot' job (ports-batch.yml with snapshot:true), on the SAME jail/pinned-tree a
# real build uses but with the FULL bulk list (no subset), so it computes the COMPLETE stock
# closure — not the per-batch slice that made the old self-populating bank incomplete.
#
# It mirrors, for this week's snapshot rev, into an IMMUTABLE R2 folder:
#     R2:.../ports-cache/stock/<chan>/<REV>/
#         packages.tar          all stock .pkg (FreeBSD prebuilt binaries the custom ports need)
#         ports-src.tar.zst     the pinned + overlaid ports SOURCE tree (reproducibility/fallback)
#         ports_top_git_hash    the freebsd-ports commit the binaries were built from (pin source)
#         meta                  rev, hash, channel, counts, FreeBSD_version, build time
#     R2:.../ports-cache/stock/<chan>/current    -> <REV>   (flipped LAST, the "ready" signal)
#     R2:.../ports-cache/stock/<chan>/previous   -> prior good REV (for a consistent roll-back)
#
# Builds then pure-consume packages.tar (freesense-lean-seed.sh) and pin the tree to the frozen
# ports_top_git_hash (builder_common.sh poudriere_pin_ports_tree) -> zero version drift, no live
# FreeBSD in the build. Idempotent: if this rev is already banked, it exits without re-fetching.
#
# Env in: FREESENSE_JAIL_NAME FREESENSE_BULK FREESENSE_PORTS_NAME FREESENSE_OVERLAY_DIR
#         FREESENSE_REV FREESENSE_CHANNEL
set -u
JAIL="${FREESENSE_JAIL_NAME:-}"
BULK="${FREESENSE_BULK:-}"
PORTS="${FREESENSE_PORTS_NAME:-}"
OVERLAY="${FREESENSE_OVERLAY_DIR:-/root/freesense-ports}"
REV="${FREESENSE_REV:-}"
CHAN="${FREESENSE_CHANNEL:-main}"
EXTRA="$(dirname "$0")/../conf/pfPorts/must-build.extra"
PKGTOP="/usr/local/poudriere/data/packages/${JAIL}-${PORTS}"
REPODIR="${PKGTOP}/.real_cache"; [ -d "$REPODIR" ] || REPODIR="$PKGTOP"
TREE="/usr/local/poudriere/ports/${PORTS}"
SNAPBASE="R2:freesense-pkg/ports-cache/stock/${CHAN}"
SNAPDIR="${SNAPBASE}/${REV}"

DIAG=/tmp/stock-snapshot.diag; : > "$DIAG"
say(){ echo ">>> stock-snapshot: $*"; printf '%s\n' "$*" >> "$DIAG"; }
finish(){ rclone copyto "$DIAG" R2:freesense-pkg/debug/stock-snapshot.diag --s3-no-check-bucket >/dev/null 2>&1 || true; }
trap finish EXIT
bail(){ say "ABORT — $1"; exit 0; }          # best-effort: a failed snapshot degrades to source builds
snap_has(){ [ -n "$(rclone lsf "$1" 2>/dev/null)" ]; }

[ -n "$JAIL" ] && [ -n "$BULK" ] && [ -n "$PORTS" ] || bail "missing env (JAIL/BULK/PORTS)"
command -v rclone >/dev/null 2>&1 || bail "no rclone"
[ -n "$REV" ] || bail "no FREESENSE_REV — cannot key the snapshot"
say "JAIL=$JAIL PORTS=$PORTS OVERLAY=$OVERLAY TREE=$TREE CHAN=$CHAN REV=$REV"

# --- 0. idempotency: this rev already fully banked? (meta is uploaded LAST, so it's the sentinel)
if snap_has "${SNAPDIR}/meta"; then
	say "rev ${REV} already snapshotted (${SNAPDIR}/meta exists) — nothing to do"
	# make sure 'current' points at it (cheap self-heal if a prior run died before the flip)
	CUR=$(rclone cat "${SNAPBASE}/current" 2>/dev/null | tr -dc '0-9a-f')
	[ "$CUR" = "$REV" ] || { printf '%s\n' "$REV" > /tmp/ss-cur; rclone copyto --s3-no-check-bucket /tmp/ss-cur "${SNAPBASE}/current" >>"$DIAG" 2>&1 || true; }
	exit 0
fi

# --- 1. FreeBSD binary ports repo (name differs by FreeBSD version) ----------------------------
pkg update -f >>"$DIAG" 2>&1 || true
FBREPO=""
for r in FreeBSD-ports FreeBSD; do
	v=$(pkg rquery -r "$r" '%v' pkg 2>/dev/null)
	say "probe repo '$r' (pkg %v) -> '$v'"
	[ -n "$v" ] && { FBREPO="$r"; break; }
done
RQ="pkg rquery ${FBREPO:+-r $FBREPO}"
say "FreeBSD ports repo = '${FBREPO:-<unnamed/all>}'; rust there = '$($RQ '%v' rust 2>/dev/null)'"
[ -n "$($RQ '%v' rust 2>/dev/null)" ] || bail "FreeBSD repo has no queryable packages"

# --- 2. must-build exclusion (overlay origins + curated extras) — never fetch these ------------
EXCL=/tmp/ss-excl.lst
( cd "$OVERLAY" 2>/dev/null && find . -mindepth 2 -maxdepth 2 -type d 2>/dev/null | grep -v '/\.git' | sed 's,^\./,,' ) > "$EXCL" 2>/dev/null || : > "$EXCL"
[ -f "$EXTRA" ] && grep -vE '^[[:space:]]*(#|$)' "$EXTRA" >> "$EXCL"
sort -u "$EXCL" -o "$EXCL"
say "exclusion (must-build) origins: $(wc -l < "$EXCL" | tr -d ' ')"

# --- 3. FULL closure via poudriere dry-run (BULK = full list, set by the snapshot job) ---------
NOUT=/tmp/ss-n.out
poudriere bulk -n -f "$BULK" -j "$JAIL" -p "$PORTS" > "$NOUT" 2>&1 || true
say "=== poudriere bulk -n tail ==="; tail -20 "$NOUT" >> "$DIAG"; say "=== end -n tail ==="
LOGD="/usr/local/poudriere/data/logs/bulk/${JAIL}-${PORTS}/latest"
RAW=/tmp/ss-raw; : > "$RAW"
for f in "$LOGD/.poudriere.ports.queued" "$LOGD/.poudriere.all_pkgs" "$LOGD/.data.json"; do
	[ -f "$f" ] && cat "$f" >> "$RAW"
done
QUEUE=/tmp/ss-queue.lst
{ cat "$RAW" 2>/dev/null; cat "$NOUT"; } | grep -oE '[a-z][a-z0-9_-]*/[A-Za-z0-9._+-]+' | sort -u > "$QUEUE"
say "closure origins parsed: $(wc -l < "$QUEUE" | tr -d ' ')"
[ -s "$QUEUE" ] || bail "could not parse the build closure from poudriere -n"

# --- 4. stock = closure - exclusion (never fetch FreeSense-*/pfSense-*) ------------------------
STOCK=/tmp/ss-stock.lst
comm -23 "$QUEUE" "$EXCL" 2>/dev/null > "$STOCK" || cp "$QUEUE" "$STOCK"
grep -vE '/(FreeSense|pfSense)' "$STOCK" > "$STOCK.f" 2>/dev/null && mv "$STOCK.f" "$STOCK"
say "stock origins to fetch: $(wc -l < "$STOCK" | tr -d ' ')"

# --- 5. map stock origins -> FreeBSD pkgnames, fetch them all ----------------------------------
NAMES=/tmp/ss-names.lst
$RQ '%o|%n' 2>/dev/null | awk -F'|' 'NR==FNR{w[$0]=1;next} ($1 in w){print $2}' "$STOCK" - | sort -u > "$NAMES"
say "stock pkgnames resolved from FreeBSD repo: $(wc -l < "$NAMES" | tr -d ' ')"
[ -s "$NAMES" ] || bail "no stock pkgnames resolved (repo/name mismatch?)"
xargs -L 250 pkg fetch -y ${FBREPO:+-r $FBREPO} < "$NAMES" >>"$DIAG" 2>&1 || true
CACHE=$(pkg config PKG_CACHEDIR 2>/dev/null); CACHE="${CACHE:-/var/cache/pkg}"
say "fetched .pkg in cache ($CACHE): $(find "$CACHE" -name '*.pkg' 2>/dev/null | wc -l | tr -d ' ')"

# --- 6. stage EXACTLY the resolved stock (name-version) from the fetch cache -------------------
# We fetched fresh, so the cache holds precisely the current FreeBSD versions -> filtering by the
# resolved %n-%v set banks stock only (no custom, no strays). This is the AUTHORITATIVE version
# set for the rev; every build this week seeds these identical bytes.
BANKALL=/tmp/ss-bank/All; rm -rf /tmp/ss-bank; mkdir -p "$BANKALL"
$RQ '%n-%v' 2>/dev/null | sort -u > /tmp/ss-nv.lst || : > /tmp/ss-nv.lst
if [ -s /tmp/ss-nv.lst ]; then
	find "$CACHE" -name '*.pkg' 2>/dev/null | while read -r p; do
		b=$(basename "$p" .pkg)
		grep -qxF "$b" /tmp/ss-nv.lst 2>/dev/null && cp -n "$p" "$BANKALL/" 2>/dev/null || true
	done
fi
# fallback: if the name-version filter matched nothing, bank whatever we fetched
[ -n "$(ls "$BANKALL"/*.pkg 2>/dev/null | head -1)" ] || find "$CACHE" -name '*.pkg' -exec cp -n {} "$BANKALL/" \; 2>/dev/null || true
NSTOCK=$(ls "$BANKALL"/*.pkg 2>/dev/null | wc -l | tr -d ' ')
say "staged ${NSTOCK} stock pkgs for the bank"
[ "${NSTOCK:-0}" -gt 0 ] || bail "no stock pkgs staged — refusing to publish an empty snapshot"

# --- 7. resolve the pin commit (ports_top_git_hash) the binaries were built from ---------------
# Annotation first (names the exact freebsd-ports commit FreeBSD built from); .poudriere.git_hash
# then git HEAD as solid fallbacks (self-consistent with the tree we computed the closure on).
HASH=$(pkg rquery ${FBREPO:+-r $FBREPO} '%Ak=%Av' pkg 2>/dev/null | sed -n 's/^ports_top_git_hash=//p')
[ -n "$HASH" ] || HASH=$(pkg rquery '%Ak=%Av' pkg 2>/dev/null | sed -n 's/^ports_top_git_hash=//p')
[ -n "$HASH" ] || HASH=$(cat "${LOGD}/.poudriere.git_hash" 2>/dev/null | tr -dc '0-9a-f')
[ -n "$HASH" ] || HASH=$(git -C "$TREE" rev-parse HEAD 2>/dev/null | tr -dc '0-9a-f')
FBVER=$(pkg rquery ${FBREPO:+-r $FBREPO} '%Ak=%Av' pkg 2>/dev/null | sed -n 's/^FreeBSD_version=//p')
say "pin ports_top_git_hash=${HASH:-<none>} FreeBSD_version=${FBVER:-<none>}"

# --- 8. build the artifacts: packages.tar + ports-src.tar.zst + meta ---------------------------
STAGE=/tmp/ss-stage; rm -rf "$STAGE"; mkdir -p "$STAGE"
# packages.tar: members are the *.pkg at top level (consume untars straight into repo All/)
if ! tar -cf "$STAGE/packages.tar" -C "$BANKALL" . >>"$DIAG" 2>&1; then
	bail "failed to build packages.tar"
fi
say "packages.tar: $(ls -l "$STAGE/packages.tar" | awk '{print $5}') bytes, ${NSTOCK} pkgs"
# ports-src.tar.zst: the pinned + overlaid ports SOURCE tree (archival / reproducibility /
# fallback). NOT consumed by the build (it pins via git to ports_top_git_hash) — this is the
# frozen source of record. --zstd is already used elsewhere in this codebase (base-build).
if [ -d "$TREE" ] && tar --zstd -cf "$STAGE/ports-src.tar.zst" -C "$(dirname "$TREE")" "$(basename "$TREE")" >>"$DIAG" 2>&1; then
	say "ports-src.tar.zst: $(ls -l "$STAGE/ports-src.tar.zst" | awk '{print $5}') bytes"
else
	say "WARN could not tar the ports source tree ${TREE} (snapshot still publishes packages)"
fi
[ -n "$HASH" ] && printf '%s\n' "$HASH" > "$STAGE/ports_top_git_hash"
{
	echo "rev=${REV}"
	echo "channel=${CHAN}"
	echo "ports_top_git_hash=${HASH:-}"
	echo "freebsd_version=${FBVER:-}"
	echo "stock_pkgs=${NSTOCK}"
	echo "built_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ 2>/dev/null)"
} > "$STAGE/meta"

# --- 9. upload the immutable rev folder (meta LAST = the "ready" sentinel) ---------------------
# Upload the heavy members first; only if they all land do we write meta + flip 'current', so a
# partial/failed snapshot never reads as ready.
UP_OK=1
for f in packages.tar ports-src.tar.zst ports_top_git_hash; do
	[ -f "$STAGE/$f" ] || continue
	rclone copyto --s3-no-check-bucket --transfers 8 --retries 10 --low-level-retries 20 \
		"$STAGE/$f" "${SNAPDIR}/$f" >>"$DIAG" 2>&1 || { say "WARN upload of $f failed"; UP_OK=0; }
done
if [ "$UP_OK" != 1 ]; then
	bail "one or more artifacts failed to upload — NOT flipping pointers (retry next run)"
fi

# --- 10. rotate current -> previous, then flip current -> REV (the ready signal) ---------------
OLDCUR=$(rclone cat "${SNAPBASE}/current" 2>/dev/null | tr -dc '0-9a-f')
if [ -n "$OLDCUR" ] && [ "$OLDCUR" != "$REV" ]; then
	printf '%s\n' "$OLDCUR" > /tmp/ss-prev
	rclone copyto --s3-no-check-bucket /tmp/ss-prev "${SNAPBASE}/previous" >>"$DIAG" 2>&1 \
		&& say "rotated previous -> ${OLDCUR}" || say "WARN could not write previous pointer"
fi
rclone copyto --s3-no-check-bucket "$STAGE/meta" "${SNAPDIR}/meta" >>"$DIAG" 2>&1 || bail "meta upload failed"
printf '%s\n' "$REV" > /tmp/ss-cur
if rclone copyto --s3-no-check-bucket /tmp/ss-cur "${SNAPBASE}/current" >>"$DIAG" 2>&1; then
	say "SNAPSHOT PUBLISHED for ${CHAN}/${REV} (${NSTOCK} stock pkgs, hash=${HASH:-<none>}); current -> ${REV}"
else
	say "WARN could not flip current pointer — snapshot bytes are up, builds will still find them via REV"
fi
