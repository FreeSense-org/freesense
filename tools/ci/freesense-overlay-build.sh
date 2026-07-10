#!/bin/sh
# freesense-overlay-build.sh — FreeSense prebuilt-overlay package builder (PLANNER).
#
# Computes the "custom set": the ONLY ports we build ourselves. Everything else in
# the closure is pure FreeBSD stock and comes from the frozen snapshot binaries
# (packages.tar) VERBATIM — never re-verified, never cascade-rebuilt.
#
# The custom set = union of:
#   (a) OVERLAY origins  — every category/port the FreeSense-ports overlay ships
#                          (FreeSense-*, vendored, patched). FreeBSD never publishes
#                          these (custom name) or we changed their recipe.
#   (b) OPTION-DIVERGENT origins — stock ports whose make.conf *_SET/UNSET_FORCE
#                          options differ from FreeBSD's default binary, so FreeBSD's
#                          binary genuinely doesn't fit (parsed from make.conf).
#   (c) must-build.extra — the curated list of the same (kept in sync by hand).
#
# It writes that set to tools/conf/pfPorts/poudriere_bulk so the subsequent
# `poudriere bulk` builds ONLY the custom set. The stock base layer is provided by
# freesense-lean-seed.sh (untars packages.tar into All/ first), so the custom
# ports' stock build/run deps resolve to prebuilt binaries — no stock port is ever
# a bulk TARGET, so the ca_root_nss->rust cascade class of bug cannot occur.
#
# Gated by FREESENSE_OVERLAY=1 (else this script is a no-op and the normal
# full-closure lean-seed path runs). Best-effort + diagnosed.
#
# Env in:  OVERLAY_DIR (default /root/freesense-ports)
#          BUILDER_TOOLS (…/tools) or SRC_DIR (…/freesense-src)
# Writes:  ${BULK_FILE}  (the custom-set bulk list)
set -u

[ "${FREESENSE_OVERLAY:-0}" = "1" ] || { echo ">>> overlay: FREESENSE_OVERLAY!=1, skipping (normal path)"; exit 0; }

OVERLAY_DIR="${OVERLAY_DIR:-/root/freesense-ports}"
# locate the tools/conf/pfPorts dir
if [ -n "${BUILDER_TOOLS:-}" ]; then
	CONF="${BUILDER_TOOLS}/conf/pfPorts"
elif [ -n "${SRC_DIR:-}" ]; then
	CONF="${SRC_DIR}/tools/conf/pfPorts"
else
	CONF="$(cd "$(dirname "$0")/../conf/pfPorts" 2>/dev/null && pwd)"
fi
MAKECONF="${CONF}/make.conf"
MUSTBUILD="${CONF}/must-build.extra"
BULK_FILE="${BULK_FILE:-${CONF}/poudriere_bulk}"
DIAG="${DIAG:-/tmp/overlay-build.diag}"
: > "$DIAG"
say(){ echo ">>> overlay: $*"; echo "$*" >> "$DIAG"; }

TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT
SET="$TMP/set"; : > "$SET"

# ---- (a) OVERLAY origins: category/port dirs the overlay ships ----------------
# freesense-ports lays files under <category>/<PkgDir>/... . The origin poudriere
# wants is category/port. Derive it from the top two path components of each file,
# de-duplicated. (Same tree the overlay cp walk uses.)
if [ -d "$OVERLAY_DIR" ]; then
	( cd "$OVERLAY_DIR" && find . -type f \
		\( -path './.git/*' -o -path './*/files/*' \) -prune -o -type f -print 2>/dev/null ) \
	| sed -e 's#^\./##' \
	| awk -F/ 'NF>=2 && $1!="" && $2!="" {print $1"/"$2}' \
	| sort -u >> "$SET"
	say "(a) overlay origins: $(sort -u "$SET" | wc -l | tr -d ' ')"
else
	say "WARN overlay dir $OVERLAY_DIR absent — (a) empty"
fi

# ---- (b) OPTION-DIVERGENT origins from make.conf ------------------------------
# make.conf per-port overrides look like:  <cat>_<port>_SET_FORCE=... / _UNSET_FORCE=...
# The key is category_port with the FIRST underscore = the category/port separator,
# BUT port names contain dashes and the split is ambiguous. FreeBSD/pfSense encode
# the origin as <category>_<portname-with-dashes-kept>. We reconstruct: take up to
# the _SET_FORCE/_UNSET_FORCE suffix, then split category on the FIRST '_'. Ports
# keep their dashes (already), and any '_' inside a portname would be rare — the
# must-build.extra list (c) is the authoritative backstop for those.
if [ -f "$MAKECONF" ]; then
	grep -E '^[a-z0-9]+_[A-Za-z0-9_-]+_(SET|UNSET)_FORCE[+]?=' "$MAKECONF" 2>/dev/null \
	| sed -E 's/_(SET|UNSET)_FORCE[+]?=.*$//' \
	| while IFS= read -r key; do
		cat="${key%%_*}"          # first token = category
		port="${key#*_}"          # rest = portname (dashes kept)
		[ -n "$cat" ] && [ -n "$port" ] && echo "${cat}/${port}"
	  done | sort -u >> "$SET"
	say "(b) parsed make.conf overrides"
fi

# ---- (c) must-build.extra (authoritative curated list) -----------------------
if [ -f "$MUSTBUILD" ]; then
	grep -vE '^\s*#|^\s*$' "$MUSTBUILD" | sed -e 's/[[:space:]]*$//' | grep -vE '^$' >> "$SET"
	say "(c) must-build.extra added"
fi

# ---- (d) EXCLUDE orphans: ports with make.conf options but NO FreeSense consumer
# Some make.conf option blocks are reference/prep for future migrations (snort3,
# suricata4) that no current FreeSense-pkg depends on. Their options make (b) pick
# them up, but building them wastes time (heavy, unused). Drop from the custom set
# -> fetch/absent. Keep SHORT; only ports verified to have zero FreeSense consumers.
EXCLUDE_ORPHANS="security/snort3 security/suricata4"
for orphan in $EXCLUDE_ORPHANS; do
	grep -vxF "$orphan" "$SET" > "$SET.x" 2>/dev/null && mv "$SET.x" "$SET"
done
say "(d) excluded orphans: $EXCLUDE_ORPHANS"

# ---- normalize to the %%PRODUCT_NAME%% placeholder ---------------------------
# poudriere_bulk() sed-substitutes %%PRODUCT_NAME%% -> ${PRODUCT_NAME} when it reads
# the list (builder_common.sh ~2364). The overlay dirs are already renamed to the
# product name (e.g. security/FreeSense-pkg-snort), so map that leading product
# token back to %%PRODUCT_NAME%% for a canonical list that dedups cleanly against
# the existing bulk/exclude lists and survives a product rename. Default product =
# FreeSense; override via PRODUCT_NAME.
_PN="${PRODUCT_NAME:-FreeSense}"
sort -u "$SET" | grep -vE '^\s*$' \
	| sed -E "s#/${_PN}-#/%%PRODUCT_NAME%%-#; s#/${_PN}\$#/%%PRODUCT_NAME%%#" \
	> "$TMP/final"

# ---- validate origins against the ports tree (drop stale/nonexistent) --------
# make.conf/must-build.extra can carry origins that no longer exist in the current
# FreeBSD ports tree (e.g. net/haproxy22 was removed). The full-closure lean-seed
# tolerates that, but the overlay's EXPLICIT bulk list hits a hard poudriere
# "Nonexistent origin" fatal. If a ports tree is available at plan time, drop any
# STOCK origin whose dir is absent. (%%PRODUCT_NAME%% overlay origins are validated
# via the overlay dir instead; they always exist there by construction.)
# PORTSTREE: poudriere tree if already created, else a checked-out freebsd-ports.
PORTSTREE="${PORTSTREE:-}"
if [ -z "$PORTSTREE" ]; then
	for c in /usr/local/poudriere/ports/*/. /usr/ports/.; do
		[ -d "$c" ] && [ -f "${c%/.}/Mk/bsd.port.mk" ] && { PORTSTREE="${c%/.}"; break; }
	done
fi
if [ -n "$PORTSTREE" ] && [ -d "$PORTSTREE" ]; then
	: > "$TMP/valid"; dropped=""
	while IFS= read -r origin; do
		case "$origin" in
			*%%PRODUCT_NAME%%*) echo "$origin" >> "$TMP/valid" ;;   # overlay origin, trust
			*) if [ -d "$PORTSTREE/$origin" ]; then echo "$origin" >> "$TMP/valid"
			   else dropped="$dropped $origin"; fi ;;
		esac
	done < "$TMP/final"
	mv "$TMP/valid" "$TMP/final"
	[ -n "$dropped" ] && say "dropped nonexistent origins:$dropped"
else
	say "no ports tree at plan time -> skipping origin validation (relies on curated lists)"
fi

N=$(wc -l < "$TMP/final" | tr -d ' ')
say "CUSTOM SET = ${N} origins (everything else = FreeBSD stock binary, verbatim)"

# ---- write the bulk list = the custom set ------------------------------------
# The subsequent `poudriere bulk` builds ONLY these; their stock deps come from the
# untarred snapshot packages.tar (via freesense-lean-seed.sh). Publish/merge then
# unions the stock binaries + these built pkgs into the signed FreeSense repo.
cp "$TMP/final" "$BULK_FILE"
say "wrote ${N}-origin custom-set bulk list -> $BULK_FILE"
echo ">>> overlay: custom set ($N):"; sed 's/^/    /' "$BULK_FILE"

# best-effort diag upload if rclone + R2 available
if command -v rclone >/dev/null 2>&1; then
	rclone copyto "$DIAG" R2:freesense-pkg/debug/overlay-build.diag --s3-no-check-bucket >/dev/null 2>&1 || true
fi
exit 0
