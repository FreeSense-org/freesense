#!/bin/sh
# pin-rust-to-freebsd.sh
#
# Reuse FreeBSD's PREBUILT lang/rust binary instead of the ~5h from-source compile.
# rust is a build-only toolchain dep (pulled only by security/suricata; it never ships
# in a FreeSense image), so tracking FreeBSD's *published binary* — which lags ports HEAD
# by a patch or two — is perfectly fine, and this script re-checks + lifts to their newest
# build on EVERY run, so it stays current automatically with no manual pinning.
#
# How it works: FreeBSD's binary rust records the exact ports commit it was built from
# (ports_top_git_hash). We (1) read FreeBSD's current binary rust version + that commit,
# (2) revert ONLY lang/rust in the poudriere ports tree to that commit (rest stays HEAD),
# and (3) drop FreeBSD's rust .pkg into the poudriere package cache. poudriere then finds
# an up-to-date rust (matching version + options + the base snapshot's shlibs, since
# FreeBSD builds against the same __FreeBSD_version we pin to) and REUSES it — no compile.
#
# make.conf exempts lang/rust from the global DOCS strip so our wanted options match
# FreeBSD's (DOCS on) — otherwise poudriere would reject their binary and rebuild.
#
# Best-effort: any failure just leaves the tree at HEAD so rust builds normally (the old
# behaviour) rather than breaking the run. Run AFTER `build.sh --update-poudriere-ports`
# (which resets+overlays the tree) and BEFORE `build.sh --update-pkg-repo` (the bulk).
set -u
ABI="${RUST_ABI:-FreeBSD:16:amd64}"

# Discover the poudriere ports tree + package dir if the caller didn't set them.
PORTSDIR="${PORTSDIR:-$(poudriere ports -l 2>/dev/null | awk 'NR>1{print $NF; exit}')}"
PKGTOP="${PKGTOP:-$(ls -d /usr/local/poudriere/data/packages/*/ 2>/dev/null | head -1)}"
PKGTOP="${PKGTOP%/}"

skip() { echo ">>> rust-pin: $1 — leaving lang/rust at HEAD (rust will build from source)"; exit 0; }
[ -n "$PORTSDIR" ] && [ -d "$PORTSDIR/lang/rust" ] || skip "poudriere ports tree not found"
[ -n "$PKGTOP" ] && [ -d "$PKGTOP" ] || skip "poudriere package dir not found"

# Query FreeBSD's binary repo in isolation (its own REPOS_DIR; doesn't touch the box repos).
Q=/root/.rustpin
rm -rf "$Q"; mkdir -p "$Q/repos" "$Q/cache" "$Q/dl"
cat > "$Q/repos/FreeBSD.conf" <<EOF
FreeBSD: {
  url: "pkg+https://pkg.freebsd.org/\${ABI}/latest",
  mirror_type: "srv",
  signature_type: "fingerprints",
  fingerprints: "/usr/share/keys/pkg",
  enabled: yes
}
EOF
PKG="pkg -o ABI=$ABI -o REPOS_DIR=$Q/repos -o PKG_CACHEDIR=$Q/cache -o IGNORE_OSVERSION=yes"
$PKG update -f >/dev/null 2>&1 || skip "could not reach FreeBSD pkg repo"

RVER=$($PKG rquery -r FreeBSD '%v' rust 2>/dev/null)
RCOMMIT=$($PKG rquery -r FreeBSD '%Ak=%Av' rust 2>/dev/null | sed -n 's/^ports_top_git_hash=//p')
[ -n "$RVER" ] && [ -n "$RCOMMIT" ] || skip "FreeBSD does not publish a rust binary for $ABI"
echo ">>> rust-pin: FreeBSD binary rust = $RVER (ports tree @ ${RCOMMIT})"

# Pin ONLY lang/rust in the tree to that commit; the rest of the tree stays at HEAD.
git -C "$PORTSDIR" fetch --depth 1 origin "$RCOMMIT" >/dev/null 2>&1 \
  || git -C "$PORTSDIR" fetch origin "$RCOMMIT" >/dev/null 2>&1 \
  || skip "could not fetch ports commit $RCOMMIT"
git -C "$PORTSDIR" checkout "$RCOMMIT" -- lang/rust 2>/dev/null || skip "could not check out lang/rust@$RCOMMIT"
TREEVER=$(make -C "$PORTSDIR/lang/rust" -V PKGVERSION 2>/dev/null)
echo ">>> rust-pin: lang/rust pinned to $TREEVER"

# Fetch FreeBSD's rust BINARY (rust only; its deps like curl still build in-tree) and drop
# it into the poudriere cache. The subsequent `pkg repo` regen indexes it, and poudriere
# reuses it because version + options + shlibs now all match.
$PKG fetch -y -o "$Q/dl" rust >/dev/null 2>&1 || $PKG fetch -y rust >/dev/null 2>&1 || skip "could not fetch FreeBSD rust binary"
RUSTPKG=$(find "$Q" -name 'rust-*.pkg' | head -1)
[ -n "$RUSTPKG" ] || skip "fetched rust binary not found on disk"
mkdir -p "$PKGTOP/.real_cache/All"
cp "$RUSTPKG" "$PKGTOP/.real_cache/All/"

# Regenerate the local repo catalog so poudriere sees the freshly-seeded rust when it
# computes the build queue (idempotent — a caller may also regen; harmless).
rm -f "$PKGTOP/.real_cache"/packagesite.* "$PKGTOP/.real_cache"/meta.* "$PKGTOP/.real_cache"/data.* 2>/dev/null || true
pkg repo "$PKGTOP/.real_cache/" >/dev/null 2>&1 || echo ">>> rust-pin: WARN pkg repo regen failed (reuse may degrade)"
echo ">>> rust-pin: seeded $(basename "$RUSTPKG") + regenerated catalog — poudriere will REUSE it (no rust compile)"
