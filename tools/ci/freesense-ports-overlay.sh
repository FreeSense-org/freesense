#!/bin/sh
# freesense-ports-overlay.sh — overlay our vendored/patched port recipes onto the
# poudriere ports tree (which is UPSTREAM FreeBSD ports).
#
# Sourced by builder_common.sh's poudriere_create_ports_tree() and update_poudriere_ports()
# right BEFORE poudriere_rename_ports() (which turns pfSense-* into FreeSense-*). Idempotent.
#
# Two kinds of overlay entries, both handled by a file-level merge (copy each overlay file
# into the corresponding port dir, overwriting only the files we ship):
#   * FULL vendored ports — the 82 pfSense-* dirs that do NOT exist in upstream FreeBSD ports
#     (security/pfSense-system, the plugins, etc.). The whole dir lands.
#   * PARTIAL patches to STOCK ports — e.g. net/kea ships only Makefile+distinfo to replace
#     the upstream port's (de-Netgate); upstream's pkg-plist/files/ MUST be preserved.
# A file-level merge does the right thing for both: we never blow away upstream files we
# didn't intend to touch.
#
# Overlay repo (FreeSense-org/freesense-ports) is cloned to ${OVERLAY_DIR} by
# provision-buildhost.sh (default /root/freesense-ports). Override via env.

_overlay="${OVERLAY_DIR:-/root/freesense-ports}"
_ports="/usr/local/poudriere/ports/${POUDRIERE_PORTS_NAME}"

if [ ! -d "${_overlay}" ]; then
	echo ">>> ERROR: ports overlay not found at ${_overlay} (clone FreeSense-org/freesense-ports)" >&2
	print_error_pfS 2>/dev/null || exit 1
fi
if [ ! -d "${_ports}" ]; then
	echo ">>> ERROR: poudriere ports tree not found at ${_ports}" >&2
	print_error_pfS 2>/dev/null || exit 1
fi

echo -n ">>> FreeSense: overlaying ports from ${_overlay} onto ${_ports}... "
# Walk every file in the overlay (excluding repo meta + .git), copy it to the same
# relative path under the poudriere ports tree, creating parent dirs as needed.
( cd "${_overlay}" && find . -type f \
	! -path './.git/*' \
	! -name 'PATCHES.md' ! -name 'README.md' ! -name '.gitattributes' \
	-print ) | while IFS= read -r rel; do
	rel=${rel#./}
	_dst="${_ports}/${rel}"
	mkdir -p "$(dirname "${_dst}")"
	cp -f "${_overlay}/${rel}" "${_dst}"
done
echo "Done!"
