#!/bin/sh
# Pull latest FreeSense source from GitHub, then refresh the build distfile that the
# security/<PRODUCT>-system port consumes (DISTFILES=freesense-src.tar.gz, WRKSRC=
# ${WRKDIR}/freesense-src, do-install copies from ${WRKSRC}/src).
#
# IMPORTANT: /root/freesense-src is the SINGLE tree used as BOTH source AND build framework,
# so build.sh fills it with a multi-GB tmp/ obj dir (FreeBSD-src checkout, buildworld output).
# That MUST be excluded or the distfile balloons to GBs (was 14G+). Only the rebranded source
# (src/, etc/, conf, composer files) belongs in the tarball.
set -e

SRC_DIR="${SRC_DIR:-/root/freesense-src}"
DISTFILES_DIR="${DISTFILES_DIR:-/usr/ports/distfiles}"
_parent=$(dirname "${SRC_DIR}")
_name=$(basename "${SRC_DIR}")

git -C "${SRC_DIR}" pull --ff-only
mkdir -p "${DISTFILES_DIR}"
rm -f "${DISTFILES_DIR}/freesense-src.tar.gz"
# Exclude build/VCS cruft: tmp/ (build obj, GBs), .git, logs/, and any *.core.
tar czf "${DISTFILES_DIR}/freesense-src.tar.gz" -C "${_parent}" \
	--exclude="${_name}/.git" \
	--exclude="${_name}/tmp" \
	--exclude="${_name}/logs" \
	"${_name}"
echo "synced + re-tarred: $(ls -lh ${DISTFILES_DIR}/freesense-src.tar.gz)"
