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

# Stage 1: rebuild ports as needed + regenerate/sign the pkg repo.
# (All 562 pkgs are cached in poudriere; only changed ports — e.g. the
#  FreeSense-repo config pkg — rebuild, then metadata is re-signed.)
echo ">>> ./build.sh --update-pkg-repo"
./build.sh --update-pkg-repo

# Stage 2: build the installer ISO from the staged repo.
# (build.sh requires an explicit image type: iso | ova | memstick | all)
echo ">>> ./build.sh iso"
./build.sh iso

echo "=== run-build done: $(date) ==="
