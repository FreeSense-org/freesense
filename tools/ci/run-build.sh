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

# Stage 1: refresh the poudriere ports tree (git reset + re-apply our overlay +
# rebrand rename). REQUIRED on every build: poudriere_create_ports_tree only
# overlays on first creation, so without this the tree keeps the PREVIOUS build's
# version/channel — e.g. a stale DISTVERSION (security/FreeSense would build as the
# old 2.9.0.a.<ts> instead of tracking src/etc/version). This reset lets DISTVERSION
# resolve to ${PRODUCT_VERSION} (1.0.0 for a -RELEASE branch, 1.1.0 for devel).
echo ">>> ./build.sh --update-poudriere-ports"
./build.sh --update-poudriere-ports

# Stage 2: rebuild ports as needed + regenerate/sign the pkg repo.
# (Cached poudriere packages are reused; only changed ports rebuild, then signed.)
echo ">>> ./build.sh --update-pkg-repo"
./build.sh --update-pkg-repo

# Stage 2: build the installer ISO from the staged repo.
# (build.sh requires an explicit image type: iso | ova | memstick | all)
echo ">>> ./build.sh iso"
./build.sh iso

echo "=== run-build done: $(date) ==="
