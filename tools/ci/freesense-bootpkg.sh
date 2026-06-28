echo ">>> FreeSense: building boot package from the built product bootloader"
# Derive the poudriere jail name dynamically (PRODUCT_NAME_BRANCH_arch, e.g.
# FreeSense_main_amd64) instead of hardcoding — the branch (main/master) and arch vary.
_fs_jail=$(poudriere_jail_name amd64 2>/dev/null || echo "${PRODUCT_NAME}_${POUDRIERE_BRANCH}_amd64")
_bs=${SCRATCHDIR}/freesense-bootstage
rm -rf ${_bs}; mkdir -p ${_bs}/boot
# IMPORTANT: source the bootloader from the freshly built product staging area
# (installworld of the patched FREEBSD_SRC_DIR = the FreeSense-branded loader.conf
# + lua), NOT the persistent poudriere jail — the jail's /boot is stale stock/fork
# branding (it showed "Welcome to pfSense" + the pf logo in the booted image).
# Fall back to the jail only if the staging /boot isn't populated yet.
_bootsrc=${STAGE_CHROOT_DIR}/boot
if [ ! -e "${_bootsrc}/defaults/loader.conf" ]; then
	echo ">>> WARN: ${_bootsrc} has no loader.conf — falling back to the jail bootloader"
	_bootsrc=/usr/local/poudriere/jails/${_fs_jail}/boot
fi
echo ">>> boot package source: ${_bootsrc}"
cp -a ${_bootsrc}/. ${_bs}/boot/
rm -rf ${_bs}/boot/kernel ${_bs}/boot/modules
# Prune any stale pfSense loader branding that may linger in a non-wiped staging
# area (NO_CLEAN). The active loader.conf references the FreeSense brand/logo; these
# unreferenced pfSense lua files must not ship.
rm -f ${_bs}/boot/lua/logo-pfSensebw.lua ${_bs}/boot/lua/brand-pfSense.lua
# Safety: ensure the packaged loader.conf is FreeSense-branded (not a stale pfSense one).
if [ -f ${_bs}/boot/defaults/loader.conf ] && grep -q 'loader_brand="pfSense"' ${_bs}/boot/defaults/loader.conf 2>/dev/null; then
	echo ">>> WARN: staged loader.conf was pfSense-branded — rewriting to FreeSense"
	sed -i '' -e 's/loader_logo="pfSensebw"/loader_logo="FreeSense"/' \
		-e 's/loader_brand="pfSense"/loader_brand="FreeSense"/' \
		-e 's/loader_menu_title="Welcome to pfSense"/loader_menu_title="Welcome to FreeSense"/' \
		${_bs}/boot/defaults/loader.conf
fi
core_pkg_create boot "" ${CORE_PKG_VERSION} ${_bs} "./boot"
