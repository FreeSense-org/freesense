#!/bin/sh
# pin-rust-to-freebsd.sh
#
# Reuse FreeBSD's PREBUILT lang/rust binary instead of the ~5h from-source compile.
# rust is a build-only toolchain dep (pulled only by security/suricata; it never ships
# in a FreeSense image), so tracking FreeBSD's *published binary* — which lags ports HEAD
# by a patch or two — is perfectly fine, and this re-checks + lifts to their newest build
# on EVERY run, so it stays current automatically with no manual pinning.
#
# FreeBSD's binary rust records the exact ports commit it was built from (ports_top_git_hash).
# We (1) read FreeBSD's current binary rust version + that commit, (2) revert ONLY lang/rust
# in the poudriere ports tree to that commit (rest stays HEAD), and (3) drop FreeBSD's rust
# .pkg into the poudriere cache. poudriere then finds an up-to-date rust (matching version +
# options + the base snapshot's shlibs, since FreeBSD builds against the same __FreeBSD_version
# we pin to) and REUSES it — no compile. make.conf exempts lang/rust from the DOCS strip so
# our wanted options match FreeBSD's.
#
# Best-effort + verbose: any failure just leaves the tree at HEAD so rust builds normally.
# Run AFTER `build.sh --update-poudriere-ports` and BEFORE `build.sh --update-pkg-repo`.
set -u

PORTSDIR="${PORTSDIR:-$(poudriere ports -l 2>/dev/null | awk 'NR>1{print $NF; exit}')}"
PKGTOP="${PKGTOP:-$(ls -d /usr/local/poudriere/data/packages/*/ 2>/dev/null | head -1)}"; PKGTOP="${PKGTOP%/}"

skip() { echo ">>> rust-pin: SKIP — $1 (lang/rust stays at HEAD; rust builds from source)"; exit 0; }
echo ">>> rust-pin: PORTSDIR=$PORTSDIR PKGTOP=$PKGTOP"
[ -n "$PORTSDIR" ] && [ -d "$PORTSDIR/lang/rust" ] || skip "poudriere ports tree not found"
[ -n "$PKGTOP" ] && [ -d "$PKGTOP" ] || skip "poudriere package dir not found"

# Use the VM host's FreeBSD binary repo — it is already configured and working (poudriere
# itself was pkg-installed from it). Refresh catalogs, then query rust.
echo ">>> rust-pin: refreshing repo catalogs..."
pkg update -f >/tmp/rp.upd 2>&1 || true; tail -2 /tmp/rp.upd 2>/dev/null

RVER=$(pkg rquery -r FreeBSD '%v' rust 2>/tmp/rp.q)
echo ">>> rust-pin: 'pkg rquery -r FreeBSD %v rust' -> '${RVER}'  $(cat /tmp/rp.q 2>/dev/null)"
# fall back to the default-repo query (no -r) if the named lookup came up empty
if [ -z "$RVER" ]; then
  RVER=$(pkg rquery '%v' rust 2>/dev/null)
  echo ">>> rust-pin: fallback 'pkg rquery %v rust' -> '${RVER}'"
fi
case "$RVER" in *[0-9]*) : ;; *) skip "FreeBSD rust version not resolvable ('$RVER')" ;; esac

RCOMMIT=$(pkg rquery -r FreeBSD '%Ak=%Av' rust 2>/dev/null | sed -n 's/^ports_top_git_hash=//p')
[ -n "$RCOMMIT" ] || RCOMMIT=$(pkg rquery '%Ak=%Av' rust 2>/dev/null | sed -n 's/^ports_top_git_hash=//p')
echo ">>> rust-pin: FreeBSD rust = $RVER  ports_top_git_hash='${RCOMMIT:-<none>}'"
[ -n "$RCOMMIT" ] || skip "no ports_top_git_hash annotation on FreeBSD rust"

# Pin ONLY lang/rust in the tree to that commit; the rest of the tree stays at HEAD.
git -C "$PORTSDIR" fetch --depth 1 origin "$RCOMMIT" >/dev/null 2>&1 \
  || git -C "$PORTSDIR" fetch origin "$RCOMMIT" >/dev/null 2>&1 \
  || skip "could not fetch ports commit $RCOMMIT into the poudriere tree"
git -C "$PORTSDIR" checkout "$RCOMMIT" -- lang/rust 2>/tmp/rp.co \
  || { cat /tmp/rp.co 2>/dev/null; skip "could not check out lang/rust@$RCOMMIT"; }
TREEVER=$(make -C "$PORTSDIR/lang/rust" -V PKGVERSION 2>/dev/null)
echo ">>> rust-pin: lang/rust tree pinned to '$TREEVER' (want '$RVER')"

# Fetch FreeBSD's rust BINARY (rust only; its deps like curl still build in-tree) and drop it
# into the poudriere cache, then regenerate the catalog so poudriere reuses it.
pkg fetch -y -r FreeBSD rust >/tmp/rp.fetch 2>&1 || pkg fetch -y rust >/tmp/rp.fetch 2>&1 \
  || { tail -3 /tmp/rp.fetch 2>/dev/null; skip "pkg fetch rust failed"; }
RUSTPKG=$(find /var/cache/pkg -name 'rust-*.pkg' 2>/dev/null | head -1)
[ -n "$RUSTPKG" ] || skip "fetched rust .pkg not found under /var/cache/pkg"
mkdir -p "$PKGTOP/.real_cache/All"
cp "$RUSTPKG" "$PKGTOP/.real_cache/All/"
rm -f "$PKGTOP/.real_cache"/packagesite.* "$PKGTOP/.real_cache"/meta.* "$PKGTOP/.real_cache"/data.* 2>/dev/null || true
pkg repo "$PKGTOP/.real_cache/" >/dev/null 2>&1 || echo ">>> rust-pin: WARN pkg repo regen failed (reuse may degrade)"
echo ">>> rust-pin: DONE — seeded $(basename "$RUSTPKG") + pinned tree to $TREEVER; poudriere will REUSE it (no rust compile)"
