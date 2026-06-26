echo ">>> FreeSense: building boot package from jail bootloader"
_bs=${SCRATCHDIR}/freesense-bootstage
rm -rf ${_bs}; mkdir -p ${_bs}/boot
cp -a /usr/local/poudriere/jails/FreeSense_master_amd64/boot/. ${_bs}/boot/
rm -rf ${_bs}/boot/kernel ${_bs}/boot/modules
core_pkg_create boot "" ${CORE_PKG_VERSION} ${_bs} "./boot"
