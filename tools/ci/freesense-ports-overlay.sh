#!/bin/sh
# freesense-ports-overlay.sh — overlay our vendored/patched port recipes onto the
# poudriere ports tree (which is UPSTREAM FreeBSD ports).
#
# Sourced by builder_common.sh's poudriere_create_ports_tree() and update_poudriere_ports()
# right BEFORE poudriere_rename_ports() (now a no-op safety net — the overlay is
# FreeSense-named in git since 2026-07-03). Idempotent.
#
# Two kinds of overlay entries, both handled by a file-level merge (copy each overlay file
# into the corresponding port dir, overwriting only the files we ship):
#   * FULL vendored ports — the FreeSense-* dirs that do NOT exist in upstream FreeBSD ports
#     (security/FreeSense-system, the plugins, etc.). The whole dir lands.
#   * PARTIAL patches to STOCK ports — e.g. net/kea ships only Makefile+distinfo to replace
#     the upstream port's (de-Netgate); upstream's pkg-plist/files/ MUST be preserved.
# A file-level merge does the right thing for both: we never blow away upstream files we
# didn't intend to touch.
#
# The selected purpose-built overlay is cloned to ${OVERLAY_DIR} by
# provision-buildhost.sh. REPO_KIND selects the default when OVERLAY_DIR is absent.

case "${REPO_KIND:-system}" in
	system) _default_overlay=/root/freesense-system-ports ;;
	packages) _default_overlay=/root/freesense-packages ;;
	*) echo ">>> ERROR: REPO_KIND must be system or packages" >&2; exit 1 ;;
esac
_overlay="${OVERLAY_DIR:-${_default_overlay}}"
_ports="/usr/local/poudriere/ports/${POUDRIERE_PORTS_NAME}"

if [ ! -d "${_overlay}" ]; then
	echo ">>> ERROR: ${REPO_KIND:-system} ports overlay not found at ${_overlay}" >&2
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

# Optional-package ports include this framework directly.  Treat a missing
# framework as an overlay failure here, before Poudriere turns it into dozens
# of misleading per-port metadata errors.
if [ "${REPO_KIND:-system}" = packages ]; then
	_framework="${_ports}/Mk/bsd.freesense-package.mk"
	if [ ! -s "${_framework}" ]; then
		echo ">>> ERROR: optional-package overlay did not install ${_framework}" >&2
		exit 1
	fi
fi

# Bind every optional package to the automatically derived compatibility train. Keep
# this centralized so a newly-added FreeSense-pkg-* port cannot accidentally
# ship without the platform ABI dependency. Insert before the port framework's
# final include; the source overlay remains clean and ordinary ports tooling
# sees the dependency after overlay application.
if [ "${REPO_KIND:-system}" = packages ]; then
	find "${_ports}" -type f -path '*/*FreeSense-pkg-*/Makefile' | while IFS= read -r _makefile; do
		grep -q 'bsd.freesense-package.mk' "${_makefile}" && continue
		sed -i '' '/^\.include <bsd\.port.*\.mk>/i\
.include <bsd.freesense-package.mk>\
' "${_makefile}"
	done
fi
echo "Done!"
