#!/bin/sh
# run-build.sh — CI build driver, runs ON the FreeBSD builder VM.
#
# Rebuilds the pkg repo (regenerates the FreeSense-repo config pkg + signed
# metadata; cached poudriere packages are reused) and then the installer
# images (ISO). Launched detached by the build-iso workflow, which polls the
# .rc sentinel rather than holding an SSH session open for hours.
#
# Logs to /root/build-ci.log; writes the final exit code to /root/build-ci.rc.
set -e

# Send ALL output to the log the build-iso workflow polls/tails.
exec > /root/build-ci.log 2>&1

SRC_DIR=${SRC_DIR:-/root/freesense-src}
cd "${SRC_DIR}"

echo "=== run-build start: $(date) ==="
echo ">>> freesense-src HEAD: $(git rev-parse --short HEAD) ($(git log -1 --format=%s))"
# Lean-pin strictness (builder_common.sh poudriere_pin_ports_tree): by default the build
# ABORTS if it can't pin the ports tree to FreeBSD's build commit, because a silent miss
# degrades into a ~600-port from-source compile (the 5h+ ISO timeout, run 29038348912).
# The pet-VM self-host lane sometimes can't resolve the pin (no FreeBSD repo to read the
# hash) and intentionally builds at HEAD — it sets FREESENSE_PIN_STRICT=0 to allow that.
echo ">>> lean-pin strict=${FREESENSE_PIN_STRICT:-1} rev=${FREESENSE_REV:-<unset>} chan=${FREESENSE_CHANNEL:-main}"

# Stage 0: re-tar the freesense-src distfile from the CURRENT checkout. security/FreeSense-system
# builds from this tarball (DISTFILES=freesense-src.tar.gz, NO_CHECKSUM) — NOT the git tree — so a
# stale distfile silently ships week-old /etc/version + /usr/local/www (right pkg VERSION from git,
# wrong CONTENTS). build-iso.yml does this in its workflow "Update source" step; run-build MUST too,
# or self-hosted builds ship stale source. Excludes match sync-freesense.sh (tmp/ is GBs of obj).
echo ">>> Stage 0: re-tar freesense-src distfile (FreeSense-system consumes it, not the git tree)"
_DF="${DISTFILES_DIR:-/usr/ports/distfiles}"
mkdir -p "${_DF}"
rm -f "${_DF}/freesense-src.tar.gz"
tar czf "${_DF}/freesense-src.tar.gz" -C "$(dirname "${SRC_DIR}")" \
	--exclude="$(basename "${SRC_DIR}")/.git" \
	--exclude="$(basename "${SRC_DIR}")/tmp" \
	--exclude="$(basename "${SRC_DIR}")/logs" \
	"$(basename "${SRC_DIR}")"
echo ">>> distfile version=$(tar xzOf "${_DF}/freesense-src.tar.gz" "$(basename "${SRC_DIR}")/src/etc/version" 2>/dev/null)"

# Stage 1: refresh the poudriere ports tree (git reset + re-apply our overlay +
# rebrand rename). REQUIRED on every build: poudriere_create_ports_tree only
# overlays on first creation, so without this the tree keeps the PREVIOUS build's
# version/channel — e.g. a stale DISTVERSION (security/FreeSense would build as the
# old 2.9.0.a.<ts> instead of tracking src/etc/version). This reset lets DISTVERSION
# resolve to ${PRODUCT_VERSION} (1.0.0 for a -RELEASE branch, 1.1.0 for devel).
echo ">>> ./build.sh --update-poudriere-ports"
./build.sh --update-poudriere-ports

# Stage 2: rebuild ports as needed + regenerate/sign the pkg repo. The lean-overlay seed
# (pin the tree to FreeBSD's build commit, fetch FreeBSD's stock binaries, build ONLY custom)
# runs INSIDE this step: builder_common.sh poudriere_bulk sources tools/ci/freesense-lean-seed.sh.
echo ">>> ./build.sh --update-pkg-repo"
./build.sh --update-pkg-repo

# Stage 2.5: consume base-build's banked kernel instead of compiling it (kernel-
# toolchain + buildkernel is ~60-70min on a cold VM — the dominant ISO cost).
# freesense-fetch-kernel.sh pulls the signed kernel pkg from R2 base/<channel>/
# (rev-guarded against FREESENSE_REV) and pins DATESTRING to the base build's
# stamp so the iso stage's get_pkg_name resolves to the fetched file's exact
# name. Any failure falls back to the classic source build. Opt out with
# FREESENSE_FETCH_KERNEL=0.
if [ "${FREESENSE_FETCH_KERNEL:-1}" = "1" ] \
    && sh tools/ci/freesense-fetch-kernel.sh; then
	. /tmp/freesense-kernel.env
	export DATESTRING FREESENSE_PREFETCHED_KERNEL_DIR
	echo ">>> Stage 2.5: banked kernel prefetched (DATESTRING pinned to ${DATESTRING})"
else
	echo ">>> Stage 2.5: no banked kernel — the iso stage will compile it from source"
fi

# Stage 3: build the installer ISO from the staged repo.
# (build.sh requires an explicit image type: iso | ova | memstick | all)
echo ">>> ./build.sh iso"
./build.sh iso

echo "=== run-build done: $(date) ==="
