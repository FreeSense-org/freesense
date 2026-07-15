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
case "${REPO_KIND:-system}" in
	system) _default_overlay=/root/freesense-system-ports ;;
	packages) _default_overlay=/root/freesense-packages ;;
	*) echo ">>> snapshot: invalid REPO_KIND '${REPO_KIND}'" >&2; exit 1 ;;
esac
OVERLAY="${FREESENSE_OVERLAY_DIR:-${_default_overlay}}"
SYSTEM_OVERLAY="${FREESENSE_SYSTEM_OVERLAY_DIR:-/root/freesense-system-ports}"
REV="${FREESENSE_REV:-}"
SNAPSHOT_ID="${FREESENSE_SNAPSHOT_ID:-$REV}"
CHAN="${FREESENSE_CHANNEL:-main}"
WORKER_ID="${FREESENSE_SNAPSHOT_WORKER_ID:-}"
WORKER_COUNT="${FREESENSE_SNAPSHOT_WORKER_COUNT:-0}"
EXTRA="$(dirname "$0")/../conf/pfPorts/must-build.extra"
PKGTOP="/usr/local/poudriere/data/packages/${JAIL}-${PORTS}"
REPODIR="${PKGTOP}/.real_cache"; [ -d "$REPODIR" ] || REPODIR="$PKGTOP"
TREE="/usr/local/poudriere/ports/${PORTS}"
SNAPBASE="R2:freesense-pkg/ports-cache/stock/${CHAN}"
SNAPDIR="${SNAPBASE}/${SNAPSHOT_ID}"

DIAG=/tmp/stock-snapshot.diag; : > "$DIAG"
say(){ echo ">>> stock-snapshot: $*"; printf '%s\n' "$*" >> "$DIAG"; }
finish(){
	_role=legacy
	[ -n "${WORKER_ID}" ] && _role="worker-${WORKER_ID}"
	rclone copyto "$DIAG" "R2:freesense-pkg/debug/stock-snapshot-${REPO_KIND:-system}-${_role}.diag" \
		--s3-no-check-bucket >/dev/null 2>&1 || true
}
trap finish EXIT
bail(){ say "ABORT — $1"; exit 1; }          # producer failure must stop workers; never degrade into a full source build
snap_has(){ [ -n "$(rclone lsf "$1" 2>/dev/null)" ]; }

[ -n "$JAIL" ] && [ -n "$BULK" ] && [ -n "$PORTS" ] || bail "missing env (JAIL/BULK/PORTS)"
command -v rclone >/dev/null 2>&1 || bail "no rclone"
[ -n "$REV" ] || bail "no FREESENSE_REV — cannot key the snapshot"
say "JAIL=$JAIL PORTS=$PORTS OVERLAY=$OVERLAY TREE=$TREE CHAN=$CHAN REV=$REV SNAPSHOT_ID=$SNAPSHOT_ID"

# --- 0. idempotency: this rev already fully banked? (meta is uploaded LAST, so it's the sentinel)
if snap_has "${SNAPDIR}/meta"; then
	say "snapshot ${SNAPSHOT_ID} already exists (${SNAPDIR}/meta) — nothing to do"
	# make sure 'current' points at it (cheap self-heal if a prior run died before the flip)
	CUR=$(rclone cat "${SNAPBASE}/current" 2>/dev/null | tr -d '\r\n')
	[ "$CUR" = "$SNAPSHOT_ID" ] || { printf '%s\n' "$SNAPSHOT_ID" > /tmp/ss-cur; rclone copyto --s3-no-check-bucket /tmp/ss-cur "${SNAPBASE}/current" >>"$DIAG" 2>&1 || true; }
	exit 0
fi

if [ -n "${WORKER_ID}" ]; then
	[ "${WORKER_COUNT}" -gt 0 ] 2>/dev/null || bail "snapshot worker requires a positive worker count"
	_worker_complete=$(rclone cat "${SNAPDIR}/workers/${WORKER_ID}/complete" 2>/dev/null | tr -d '\r\n')
	if [ "${_worker_complete}" = "${SNAPSHOT_ID} worker-${WORKER_ID}/${WORKER_COUNT}" ]; then
		say "worker-${WORKER_ID} output already complete for this plan"
		exit 0
	fi
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
if [ "${REPO_KIND:-system}" = packages ] && [ -d "$SYSTEM_OVERLAY" ]; then
	( cd "$SYSTEM_OVERLAY" && find . -mindepth 2 -maxdepth 2 -type d 2>/dev/null | grep -v '/\.git' | sed 's,^\./,,' ) >> "$EXCL"
fi
[ -f "$EXTRA" ] && grep -vE '^[[:space:]]*(#|$)' "$EXTRA" >> "$EXCL"
sort -u "$EXCL" -o "$EXCL"
say "exclusion (must-build) origins: $(wc -l < "$EXCL" | tr -d ' ')"

# --- 3. FULL closure via poudriere dry-run (BULK = full list, set by the snapshot job) ---------
# Poudriere may refresh/reset its ports tree after the initial create step. Reapply
# the exact immutable overlays immediately before metadata resolution so package
# framework/support files cannot disappear between provisioning and the dry-run.
if ! REPO_KIND="${REPO_KIND:-system}" POUDRIERE_PORTS_NAME="$PORTS" \
	OVERLAY_DIR="$OVERLAY" FREESENSE_SYSTEM_OVERLAY_DIR="$SYSTEM_OVERLAY" \
	sh "$(dirname "$0")/freesense-ports-overlay.sh" >>"$DIAG" 2>&1; then
	bail "could not reapply immutable overlays before closure resolution"
fi
NOUT=/tmp/ss-n.out
if ! poudriere bulk -n -f "$BULK" -j "$JAIL" -p "$PORTS" > "$NOUT" 2>&1; then
	say "=== failed poudriere bulk -n tail ==="
	tail -80 "$NOUT" >> "$DIAG"
	bail "Poudriere could not resolve the complete worker closure"
fi
say "=== poudriere bulk -n tail ==="; tail -20 "$NOUT" >> "$DIAG"; say "=== end -n tail ==="
LOGD="/usr/local/poudriere/data/logs/bulk/${JAIL}-${PORTS}/latest"
RAW=/tmp/ss-raw; : > "$RAW"
for f in "$LOGD/.poudriere.ports.queued" "$LOGD/.poudriere.all_pkgs" "$LOGD/.data.json"; do
	[ -f "$f" ] && cat "$f" >> "$RAW"
done
CANDIDATES=/tmp/ss-candidates.lst
QUEUE=/tmp/ss-queue.lst
REJECTED=/tmp/ss-rejected.lst
{ cat "$RAW" 2>/dev/null; cat "$NOUT"; } | grep -oE '[a-z][a-z0-9_-]*/[A-Za-z0-9._+-]+' | sort -u > "$CANDIDATES"
: > "$QUEUE"
: > "$REJECTED"
while IFS= read -r _origin; do
	[ -n "${_origin}" ] || continue
	if [ -f "$TREE/${_origin}/Makefile" ]; then
		echo "${_origin}" >> "$QUEUE"
	else
		echo "${_origin}" >> "$REJECTED"
	fi
done < "$CANDIDATES"
say "closure origins parsed: $(wc -l < "$QUEUE" | tr -d ' ')"
say "non-origin path tokens rejected: $(wc -l < "$REJECTED" | tr -d ' ')"
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

# --- 7. resolve the shared pin from the canonical fetched pkg bootstrap ------------------------
# AUTHORITATIVE: read the annotations off an actual fetched .pkg — this is the exact freebsd-ports
# commit (and __FreeBSD_version) FreeBSD BUILT the binaries from. `pkg rquery` reads the CATALOG,
# which routinely OMITS ports_top_git_hash (empirically empty), so the old resolver fell through to
# the tree's git HEAD — which is NEWER than FreeBSD's build commit -> builds pinned to a too-new tree
# -> the frozen binaries read as "new version" and got mass-rebuilt. `pkg query -F` reads the .pkg
# file, not the catalog. Fetch into a clean directory: /var/cache/pkg may also
# contain the annotation-free bootstrap used to install pkg itself, and a normal
# `pkg fetch` considers that stale bootstrap a cache hit. This is the same
# isolated probe used by poudriere_pin_ports_tree in builder_common.sh.
PINPROBE=/tmp/ss-pinprobe
rm -rf "$PINPROBE"
if ! pkg fetch -y ${FBREPO:+-r $FBREPO} -o "$PINPROBE" pkg >>"$DIAG" 2>&1; then
	bail "could not fetch canonical pkg bootstrap for the ports pin"
fi
PINPKG=$(find "$PINPROBE" -type f -name '*.pkg' 2>/dev/null | head -1)
[ -n "$PINPKG" ] || bail "isolated canonical pkg fetch produced no package file"
PINMANIFEST=/tmp/ss-pin-manifest.json
if ! tar -xOf "$PINPKG" +MANIFEST >"$PINMANIFEST" 2>>"$DIAG"; then
	bail "could not extract +MANIFEST from the canonical pkg bootstrap"
fi
HASH=$(grep -oE '"ports_top_git_hash":"[0-9a-f]{40}"' "$PINMANIFEST" | head -1 | cut -d'"' -f4)
FBVER=$(grep -oE '"FreeBSD_version":"[0-9]+"' "$PINMANIFEST" | head -1 | cut -d'"' -f4)
printf '%s' "$HASH" | grep -Eq '^[0-9a-f]{40}$' \
	|| bail "canonical pkg bootstrap has no valid ports_top_git_hash annotation"
say "pin ports_top_git_hash=$HASH FreeBSD_version=${FBVER:-<none>} (isolated canonical $(basename "$PINPKG"))"

# --- 8. build the artifacts: packages.tar + ports-src.tar.zst + meta ---------------------------
STAGE=/tmp/ss-stage; rm -rf "$STAGE"; mkdir -p "$STAGE"
# Fetch source material only for ports that the offline build will compile.
# Stock dependencies are consumed from the frozen binary bank above, so walking
# every root recursively would both waste space and let software installed on
# this producer host influence validation of ports that are never built here.
FETCH_FAIL=/tmp/ss-fetch-fail; : > "$FETCH_FAIL"
# System ports generate this archive from the separately pinned FreeSense source
# at build time. Seed a deterministic temporary copy so direct source-port
# fetches do not try an intentionally absent MASTER_SITE.
# It is removed before the epoch distfile archive because source.tar.zst is the
# authoritative copy consumed by ordinary offline builds.
GENERATED_SOURCE=/usr/ports/distfiles/freesense-src.tar.gz
rm -f "$GENERATED_SOURCE"
tar -czf "$GENERATED_SOURCE" -C /root freesense-src >>"$DIAG" 2>&1 \
	|| bail "could not stage generated FreeSense source distfile"
# Run the standalone fetch pass with exactly the same generated make.conf as
# Poudriere.  Without this, the host's installed ports can change option
# resolution (for example ports OpenSSL plus base GSSAPI in bind-tools), making
# fetch-recursive reject a closure that Poudriere already proved valid.
FETCH_MAKE_CONF="/usr/local/etc/poudriere.d/${PORTS}-make.conf"
[ -s "$FETCH_MAKE_CONF" ] \
	|| bail "missing Poudriere make.conf for verified distfile fetch: ${FETCH_MAKE_CONF}"
say "distfile fetch make.conf=${FETCH_MAKE_CONF}"
# A direct ports make still observes the producer's /usr/local and package DB.
# Keep both empty so installed host ports (notably openssl) cannot invalidate a
# source port configured for the clean Poudriere jail's base libraries.
FETCH_ENV_ROOT=/tmp/ss-fetch-env
rm -rf "$FETCH_ENV_ROOT"
mkdir -p "$FETCH_ENV_ROOT/local" "$FETCH_ENV_ROOT/pkgdb" "$FETCH_ENV_ROOT/portdb"
# A stock package is only an optimisation: Poudriere may reject it when the
# frozen package repository lags the pinned ports tree, its options differ, or
# an archive entry is absent. Any origin in the resolved closure can therefore
# fall back to a source build. Fetch the complete closure so the sealed epoch
# remains valid when consumers physically disable networking.
SOURCE_FETCH=/tmp/ss-source-fetch.lst
cp "$QUEUE" "$SOURCE_FETCH"
say "offline fallback origins requiring distfiles: $(wc -l < "$SOURCE_FETCH" | tr -d ' ')"
while read -r _origin; do
	[ -n "${_origin}" ] || continue
	_dir="${TREE}/${_origin}"
	if [ ! -d "${_dir}" ]; then
		echo "${_origin} (missing from pinned ports tree)" >> "$FETCH_FAIL"
		continue
	fi
	if ! env __MAKE_CONF="$FETCH_MAKE_CONF" \
		LOCALBASE="$FETCH_ENV_ROOT/local" PKG_DBDIR="$FETCH_ENV_ROOT/pkgdb" \
		PORT_DBDIR="$FETCH_ENV_ROOT/portdb" BATCH=yes \
		DISABLE_VULNERABILITIES=yes DISTDIR=/usr/ports/distfiles \
		make -C "${_dir}" NO_DEPENDS=yes fetch >>"$DIAG" 2>&1; then
		echo "${_origin}" >> "$FETCH_FAIL"
	fi
done < "$SOURCE_FETCH"
if [ -s "$FETCH_FAIL" ]; then
	say "distfile fetch failures: $(tr '\n' ' ' < "$FETCH_FAIL")"
	bail "offline epoch would be missing required distfiles"
fi
rm -f "$GENERATED_SOURCE"
tar --zstd -cf "$STAGE/distfiles.tar.zst" -C /usr/ports/distfiles . >>"$DIAG" 2>&1 \
	|| bail "failed to archive verified distfiles"
say "distfiles.tar.zst: $(ls -l "$STAGE/distfiles.tar.zst" | awk '{print $5}') bytes"
# packages.tar: members are the *.pkg at top level (consume untars straight into repo All/)
if ! tar -cf "$STAGE/packages.tar" -C "$BANKALL" . >>"$DIAG" 2>&1; then
	bail "failed to build packages.tar"
fi
say "packages.tar: $(ls -l "$STAGE/packages.tar" | awk '{print $5}') bytes, ${NSTOCK} pkgs"
# ports-src.tar.zst: the pinned + overlaid ports SOURCE tree (archival / reproducibility /
# fallback). NOT consumed by the build (it pins via git to ports_top_git_hash) — this is the
# frozen source of record. --zstd is already used elsewhere in this codebase (base-build).
if [ -z "${WORKER_ID}" ] && [ -d "$TREE" ] && tar --zstd -cf "$STAGE/ports-src.tar.zst" -C "$(dirname "$TREE")" "$(basename "$TREE")" >>"$DIAG" 2>&1; then
	say "ports-src.tar.zst: $(ls -l "$STAGE/ports-src.tar.zst" | awk '{print $5}') bytes"
elif [ -z "${WORKER_ID}" ]; then
	say "WARN could not tar the ports source tree ${TREE} (snapshot still publishes packages)"
fi
[ -n "$HASH" ] && printf '%s\n' "$HASH" > "$STAGE/ports_top_git_hash"
{
	echo "rev=${REV}"
	echo "snapshot_id=${SNAPSHOT_ID}"
	echo "channel=${CHAN}"
	echo "ports_top_git_hash=${HASH:-}"
	echo "freebsd_version=${FBVER:-}"
	echo "stock_pkgs=${NSTOCK}"
	echo "built_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ 2>/dev/null)"
} > "$STAGE/meta"

if [ -n "${WORKER_ID}" ]; then
	WROOT="${SNAPDIR}/workers/${WORKER_ID}"
	for f in packages.tar distfiles.tar.zst ports_top_git_hash meta; do
		[ -s "$STAGE/$f" ] || bail "worker-${WORKER_ID} did not produce ${f}"
		rclone copyto --s3-no-check-bucket --transfers 8 --retries 10 --low-level-retries 20 \
			"$STAGE/$f" "$WROOT/$f" >>"$DIAG" 2>&1 || bail "worker-${WORKER_ID} upload of ${f} failed"
	done
	cp "$BULK" /tmp/ss-worker-roots
	rclone copyto --s3-no-check-bucket /tmp/ss-worker-roots "$WROOT/roots.txt" >>"$DIAG" 2>&1 \
		|| bail "worker-${WORKER_ID} roots upload failed"
	printf '%s worker-%s/%s\n' "$SNAPSHOT_ID" "$WORKER_ID" "$WORKER_COUNT" >/tmp/ss-worker-complete
	rclone copyto --s3-no-check-bucket /tmp/ss-worker-complete "$WROOT/complete" >>"$DIAG" 2>&1 \
		|| bail "worker-${WORKER_ID} completion marker upload failed"
	say "worker-${WORKER_ID}/${WORKER_COUNT} PUBLISHED (${NSTOCK} stock packages)"
	exit 0
fi

# --- 9. upload the immutable rev folder (meta LAST = the "ready" sentinel) ---------------------
# Upload the heavy members first; only if they all land do we write meta + flip 'current', so a
# partial/failed snapshot never reads as ready.
UP_OK=1
for f in packages.tar ports-src.tar.zst ports_top_git_hash distfiles.tar.zst; do
	[ -f "$STAGE/$f" ] || continue
	rclone copyto --s3-no-check-bucket --transfers 8 --retries 10 --low-level-retries 20 \
		"$STAGE/$f" "${SNAPDIR}/$f" >>"$DIAG" 2>&1 || { say "WARN upload of $f failed"; UP_OK=0; }
done
if [ "$UP_OK" != 1 ]; then
	bail "one or more artifacts failed to upload — NOT flipping pointers (retry next run)"
fi

# --- 10. rotate current -> previous, then flip current -> REV (the ready signal) ---------------
OLDCUR=$(rclone cat "${SNAPBASE}/current" 2>/dev/null | tr -d '\r\n')
if [ -n "$OLDCUR" ] && [ "$OLDCUR" != "$SNAPSHOT_ID" ]; then
	printf '%s\n' "$OLDCUR" > /tmp/ss-prev
	rclone copyto --s3-no-check-bucket /tmp/ss-prev "${SNAPBASE}/previous" >>"$DIAG" 2>&1 \
		&& say "rotated previous -> ${OLDCUR}" || say "WARN could not write previous pointer"
fi
rclone copyto --s3-no-check-bucket "$STAGE/meta" "${SNAPDIR}/meta" >>"$DIAG" 2>&1 || bail "meta upload failed"
printf '%s\n' "$SNAPSHOT_ID" > /tmp/ss-cur
if rclone copyto --s3-no-check-bucket /tmp/ss-cur "${SNAPBASE}/current" >>"$DIAG" 2>&1; then
	say "SNAPSHOT PUBLISHED for ${CHAN}/${SNAPSHOT_ID} (${NSTOCK} stock pkgs, hash=${HASH:-<none>}); current -> ${SNAPSHOT_ID}"
else
	say "WARN could not flip current pointer — snapshot bytes are up, builds will still find them via REV"
fi
