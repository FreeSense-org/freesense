echo ">>> FreeSense: building boot package from jail bootloader"
# Derive the poudriere jail name dynamically (PRODUCT_NAME_BRANCH_arch, e.g.
# FreeSense_main_amd64) instead of hardcoding — the branch (main/master) and arch vary.
_fs_jail=$(poudriere_jail_name amd64 2>/dev/null || echo "${PRODUCT_NAME}_${POUDRIERE_BRANCH}_amd64")
_bs=${SCRATCHDIR}/freesense-bootstage
rm -rf ${_bs}; mkdir -p ${_bs}/boot
cp -a /usr/local/poudriere/jails/${_fs_jail}/boot/. ${_bs}/boot/
rm -rf ${_bs}/boot/kernel ${_bs}/boot/modules
core_pkg_create boot "" ${CORE_PKG_VERSION} ${_bs} "./boot"
