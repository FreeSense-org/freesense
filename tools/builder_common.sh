#!/bin/sh
#
# builder_common.sh
#
# part of pfSense (https://www.pfsense.org)
# Copyright (c) 2004-2026 The FreeSense Project
# All rights reserved.
#
# FreeSBIE portions of the code
# Copyright (c) 2005 Dario Freni
# and copied from FreeSBIE project
# All rights reserved.
#
# Licensed under the Apache License, Version 2.0 (the "License");
# you may not use this file except in compliance with the License.
# You may obtain a copy of the License at
#
# http://www.apache.org/licenses/LICENSE-2.0
#
# Unless required by applicable law or agreed to in writing, software
# distributed under the License is distributed on an "AS IS" BASIS,
# WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
# See the License for the specific language governing permissions and
# limitations under the License.

if [ -z "${IMAGES_FINAL_DIR}" -o "${IMAGES_FINAL_DIR}" = "/" ]; then
	echo "IMAGES_FINAL_DIR is not defined"
	print_error_pfS
fi

kldload filemon >/dev/null 2>&1

lc() {
	echo "${1}" | tr '[[:upper:]]' '[[:lower:]]'
}

git_last_commit() {
	export CURRENT_COMMIT=$(git -C ${BUILDER_ROOT} log -1 --format='%H')
	export CURRENT_AUTHOR=$(git -C ${BUILDER_ROOT} log -1 --format='%an')
	echo ">>> Last known commit $CURRENT_AUTHOR - $CURRENT_COMMIT"
	echo "$CURRENT_COMMIT" > $SCRATCHDIR/build_commit_info.txt
}

# Create core pkg repository
core_pkg_create_repo() {
	if [ ! -d "${CORE_PKG_REAL_PATH}/All" ]; then
		return
	fi

	############ ATTENTION ##############
	#
	# For some reason pkg-repo fail without / in the end of directory name
	# so removing it will break command
	#
	# https://github.com/freebsd/pkg/issues/1364
	#
	echo -n ">>> Creating core packages repository... "
	if pkg repo -q "${CORE_PKG_REAL_PATH}/"; then
		echo "Done!"
	else
		echo "Failed!"
		print_error_pfS
	fi

	# Use the same directory structure as poudriere does to avoid
	# breaking snapshot repositories during rsync
	ln -sf $(basename ${CORE_PKG_REAL_PATH}) ${CORE_PKG_PATH}/.latest
	ln -sf .latest/All ${CORE_PKG_ALL_PATH}
	ln -sf .latest/digests.txz ${CORE_PKG_PATH}/digests.txz
	ln -sf .latest/meta.conf ${CORE_PKG_PATH}/meta.conf
	ln -sf .latest/meta.txz ${CORE_PKG_PATH}/meta.txz
	ln -sf .latest/packagesite.txz ${CORE_PKG_PATH}/packagesite.txz
}

# Create core pkg (base, kernel)
core_pkg_create() {
	local _template="${1}"
	local _flavor="${2}"
	local _version="${3}"
	local _root="${4}"
	local _findroot="${5}"
	local _filter="${6}"

	local _template_path=${BUILDER_TOOLS}/templates/core_pkg/${_template}

	# Use default pkg repo to obtain ABI and ALTABI
	local _abi=$(sed -e "s/%%ARCH%%/${TARGET_ARCH}/g" \
	    ${PKG_REPO_DEFAULT%%.conf}.abi)
	local _altabi_arch=$(get_altabi_arch ${TARGET_ARCH})
	local _altabi=$(sed -e "s/%%ARCH%%/${_altabi_arch}/g" \
	    ${PKG_REPO_DEFAULT%%.conf}.altabi)

	${BUILDER_SCRIPTS}/create_core_pkg.sh \
		-t "${_template_path}" \
		-f "${_flavor}" \
		-v "${_version}" \
		-r "${_root}" \
		-s "${_findroot}" \
		-F "${_filter}" \
		-d "${CORE_PKG_REAL_PATH}/All" \
		-a "${_abi}" \
		-A "${_altabi}" \
		|| print_error_pfS
}

# This routine will output that something went wrong
print_error_pfS() {
	echo
	echo "####################################"
	echo "Something went wrong, check errors!" >&2
	echo "####################################"
	echo
	echo "NOTE: a lot of times you can run './build.sh --clean-builder' to resolve."
	echo
	[ -n "${LOGFILE}" -a -f "${LOGFILE}" ] && \
		echo "Log saved on ${LOGFILE}" && \
	echo
	kill $$
	exit 1
}

# This routine will verify that the kernel has been
# installed OK to the staging area.
ensure_kernel_exists() {
	if [ ! -f "$1/boot/kernel/kernel.gz" ]; then
		echo ">>> ERROR: Could not locate $1/boot/kernel.gz"
		print_error_pfS
	fi
	KERNEL_SIZE=$(stat -f "%z" $1/boot/kernel/kernel.gz)
	if [ "$KERNEL_SIZE" -lt 3500 ]; then
		echo ">>> ERROR: Kernel $1/boot/kernel.gz appears to be smaller than it should be: $KERNEL_SIZE"
		print_error_pfS
	fi
}

get_pkg_name() {
	echo "${PRODUCT_NAME}-${1}-${CORE_PKG_VERSION}"
}

# This routine builds all related kernels
build_all_kernels() {
	# Set KERNEL_BUILD_PATH if it has not been set
	if [ -z "${KERNEL_BUILD_PATH}" ]; then
		KERNEL_BUILD_PATH=$SCRATCHDIR/kernels
		echo ">>> KERNEL_BUILD_PATH has not been set. Setting to ${KERNEL_BUILD_PATH}!"
	fi

	[ -d "${KERNEL_BUILD_PATH}" ] \
		&& rm -rf ${KERNEL_BUILD_PATH}

	# FreeSense: import a prefetched kernel pkg (tools/ci/freesense-fetch-kernel.sh —
	# base-build already compiled + published the identical kernel from the same
	# committed pin). The caller pinned DATESTRING to the base build's stamp, so
	# get_pkg_name resolves to the fetched file's exact name and the existing
	# NO_BUILDKERNEL short-circuit in the loop below does the rest. The .latest/All
	# links must exist for that check (core_pkg_create_repo only makes them later).
	# A missing/mismatched file falls through to a normal source build.
	if [ -n "${FREESENSE_PREFETCHED_KERNEL_DIR:-}" ]; then
		mkdir -p ${CORE_PKG_REAL_PATH}/All
		# -n: replace an existing symlink instead of descending INTO its target
		# (plain ln -sf against a symlink-to-dir creates the link inside it).
		ln -sfn $(basename ${CORE_PKG_REAL_PATH}) ${CORE_PKG_PATH}/.latest
		if [ -L "${CORE_PKG_ALL_PATH}" ] || [ ! -e "${CORE_PKG_ALL_PATH}" ]; then
			ln -sfn .latest/All ${CORE_PKG_ALL_PATH}
		fi
		for _kern in ${BUILD_KERNELS}; do
			_kfile="$(get_pkg_name kernel-${_kern}).pkg"
			if [ -f "${FREESENSE_PREFETCHED_KERNEL_DIR}/${_kfile}" ]; then
				cp "${FREESENSE_PREFETCHED_KERNEL_DIR}/${_kfile}" ${CORE_PKG_REAL_PATH}/All/
			fi
			# Skip the build ONLY if the pkg is visible exactly where the
			# loop's short-circuit (and build.sh's installer-kernel step)
			# will look. NO_BUILDKERNEL without that file would sail past
			# the compile and die at install time with no obj tree.
			if [ -f "${CORE_PKG_ALL_PATH}/${_kfile}" ]; then
				export NO_BUILDKERNEL=yes
				echo ">>> Using prefetched ${_kfile} — skipping kernel-toolchain + buildkernel"
			else
				unset NO_BUILDKERNEL
				echo ">>> WARN: prefetched ${_kfile} not resolvable via ${CORE_PKG_ALL_PATH} — building from source"
			fi
		done
	fi

	# Build embedded kernel
	for BUILD_KERNEL in $BUILD_KERNELS; do
		unset KERNCONF
		unset KERNEL_DESTDIR
		unset KERNEL_NAME
		export KERNCONF=$BUILD_KERNEL
		export KERNEL_DESTDIR="$KERNEL_BUILD_PATH/$BUILD_KERNEL"
		export KERNEL_NAME=${BUILD_KERNEL}

		LOGFILE="${BUILDER_LOGS}/kernel.${KERNCONF}.${TARGET}.log"
		echo ">>> Building $BUILD_KERNEL kernel."  | tee -a ${LOGFILE}

		if [ -n "${NO_BUILDKERNEL}" -a -f "${CORE_PKG_ALL_PATH}/$(get_pkg_name kernel-${KERNEL_NAME}).pkg" ]; then
			echo ">>> NO_BUILDKERNEL set, skipping build" | tee -a ${LOGFILE}
			continue
		fi

		buildkernel

		echo ">>> Staging $BUILD_KERNEL kernel..." | tee -a ${LOGFILE}
		installkernel

		ensure_kernel_exists $KERNEL_DESTDIR

		echo ">>> Creating pkg of $KERNEL_NAME-debug kernel to staging area..."  | tee -a ${LOGFILE}
		core_pkg_create kernel-debug ${KERNEL_NAME} ${CORE_PKG_VERSION} ${KERNEL_DESTDIR} \
		    "./usr/lib/debug/boot" \*.debug
		rm -rf ${KERNEL_DESTDIR}/usr

		echo ">>> Creating pkg of $KERNEL_NAME kernel to staging area..."  | tee -a ${LOGFILE}
		core_pkg_create kernel ${KERNEL_NAME} ${CORE_PKG_VERSION} ${KERNEL_DESTDIR} "./boot/kernel ./boot/modules"

		rm -rf $KERNEL_DESTDIR 2>&1 1>/dev/null
	done
}

install_default_kernel() {
	if [ -z "${1}" ]; then
		echo ">>> ERROR: install_default_kernel called without a kernel config name"| tee -a ${LOGFILE}
		print_error_pfS
	fi

	export KERNEL_NAME="${1}"

	echo -n ">>> Installing kernel to be used by image ${KERNEL_NAME}..." | tee -a ${LOGFILE}

	# Copy kernel package to chroot, otherwise pkg won't find it to install.
	# -M: accept missing deps — the kernel pkg declares a dep on FreeSense-boot
	# (same stamp); its loader FILES are already staged, and a not-yet-registered
	# boot pkg must not block the kernel landing (run 29148436302).
	if ! pkg_chroot_add ${FINAL_CHROOT_DIR} kernel-${KERNEL_NAME} -M; then
		echo ">>> ERROR: Error installing kernel package $(get_pkg_name kernel-${KERNEL_NAME}).pkg" | tee -a ${LOGFILE}
		print_error_pfS
	fi

	# Set kernel pkg as vital to avoid user end up removing it for any reason
	pkg_chroot ${FINAL_CHROOT_DIR} set -v 1 -y $(get_pkg_name kernel-${KERNEL_NAME})

	if [ ! -f $FINAL_CHROOT_DIR/boot/kernel/kernel.gz ]; then
		echo ">>> ERROR: No kernel installed on $FINAL_CHROOT_DIR and the resulting image will be unusable. STOPPING!" | tee -a ${LOGFILE}
		print_error_pfS
	fi
	mkdir -p $FINAL_CHROOT_DIR/pkgs
	if [ -z "${2}" -o -n "${INSTALL_EXTRA_KERNELS}" ]; then
		cp ${CORE_PKG_ALL_PATH}/$(get_pkg_name kernel-${KERNEL_NAME}).pkg $FINAL_CHROOT_DIR/pkgs
		if [ -n "${INSTALL_EXTRA_KERNELS}" ]; then
			for _EXTRA_KERNEL in $INSTALL_EXTRA_KERNELS; do
				_EXTRA_KERNEL_PATH=${CORE_PKG_ALL_PATH}/$(get_pkg_name kernel-${_EXTRA_KERNEL}).pkg
				if [ -f "${_EXTRA_KERNEL_PATH}" ]; then
					echo -n ". adding ${_EXTRA_KERNEL_PATH} on image /pkgs folder"
					cp ${_EXTRA_KERNEL_PATH} $FINAL_CHROOT_DIR/pkgs
				else
					echo ">>> ERROR: Requested kernel $(get_pkg_name kernel-${_EXTRA_KERNEL}).pkg was not found to be put on image /pkgs folder!"
					print_error_pfS
				fi
			done
		fi
	fi
	echo "Done." | tee -a ${LOGFILE}

	unset KERNEL_NAME
}

# This builds FreeBSD (make buildworld)
# Imported from FreeSBIE
make_world() {
	LOGFILE=${BUILDER_LOGS}/buildworld.${TARGET}
	echo ">>> LOGFILE set to $LOGFILE." | tee -a ${LOGFILE}
	if [ -n "${NO_BUILDWORLD}" ]; then
		echo ">>> NO_BUILDWORLD set, skipping build" | tee -a ${LOGFILE}
		return
	fi

	# Deterministic fast path for the split builder. The orchestrator verifies and
	# mirrors one official base.txz alongside the exact FreeBSD source commit, then
	# passes its local path here. Both chroots therefore start from identical,
	# checksum-pinned distribution bytes without a moving package repository.
	if [ -n "${FREESENSE_DIST_WORLD_ARCHIVE:-}" ]; then
		if [ ! -f "${FREESENSE_DIST_WORLD_ARCHIVE}" ]; then
			echo ">>> ERROR: pinned world archive is missing: ${FREESENSE_DIST_WORLD_ARCHIVE}" | tee -a ${LOGFILE}
			print_error_pfS
		fi
		echo ">>> $(LC_ALL=C date) - seeding world from pinned base.txz (NO buildworld)..." | tee -a ${LOGFILE}
		script -aq $LOGFILE env \
			STAGE_CHROOT_DIR="${STAGE_CHROOT_DIR}" \
			INSTALLER_CHROOT_DIR="${INSTALLER_CHROOT_DIR}" \
			FREESENSE_DIST_WORLD_ARCHIVE="${FREESENSE_DIST_WORLD_ARCHIVE}" \
			sh ${BUILDER_TOOLS}/ci/freesense-dist-world.sh \
			|| print_error_pfS
		BUILD_CC="${STAGE_CHROOT_DIR}/usr/bin/cc"
		make_world_pkgbase_tail
		return
	fi

	# FreeSense LEVER 1 (pkgbase world) — NOW THE DEFAULT: instead of compiling the
	# world with buildworld (~4.3h, ~99% verbatim FreeBSD), seed the staging + installer
	# chroots from FreeBSD-16 prebuilt pkgbase binaries (~47min total core build). The
	# custom kernel is still built from patched source below (build_all_kernels); the
	# src/ overlay + patched userland still land on top in clone_to_staging_area, and the
	# monolithic base.txz delivery is UNCHANGED (proven end-to-end 2026-07-09: 47m,
	# 339 pkgbase pkgs, base/kernel/rc pkgs identical names+versions). The classic
	# buildworld path is kept as an escape hatch via FREESENSE_CLASSIC_WORLD=1.
	if [ "${FREESENSE_CLASSIC_WORLD:-0}" != "1" ]; then
		echo ">>> $(LC_ALL=C date) - pkgbase world: fetching FreeBSD base binaries (NO buildworld)..." | tee -a ${LOGFILE}
		[ -d "${INSTALLER_CHROOT_DIR}" ] || mkdir -p ${INSTALLER_CHROOT_DIR}
		[ -d "${STAGE_CHROOT_DIR}" ] || mkdir -p ${STAGE_CHROOT_DIR}
		script -aq $LOGFILE env \
			STAGE_CHROOT_DIR="${STAGE_CHROOT_DIR}" \
			INSTALLER_CHROOT_DIR="${INSTALLER_CHROOT_DIR}" \
			TARGET_ARCH="${TARGET_ARCH}" \
			FREEBSD_SRC_DIR="${FREEBSD_SRC_DIR}" \
			sh ${BUILDER_TOOLS}/ci/freesense-pkgbase-world.sh \
			|| print_error_pfS
		# The classic path builds a cross cc in obj; with no buildworld there is no
		# obj toolchain, so downstream steps use the chroot's own clang (from the
		# FreeBSD-clang pkgbase package we just seeded).
		BUILD_CC="${STAGE_CHROOT_DIR}/usr/bin/cc"
		make_world_pkgbase_tail
		return
	fi

	echo ">>> $(LC_ALL=C date) - Starting build world for ${TARGET} architecture..." | tee -a ${LOGFILE}
	script -aq $LOGFILE ${BUILDER_SCRIPTS}/build_freebsd.sh -K -s ${FREEBSD_SRC_DIR} \
		|| print_error_pfS
	echo ">>> $(LC_ALL=C date) - Finished build world for ${TARGET} architecture..." | tee -a ${LOGFILE}

	LOGFILE=${BUILDER_LOGS}/installworld.${TARGET}
	echo ">>> LOGFILE set to $LOGFILE." | tee -a ${LOGFILE}

	[ -d "${INSTALLER_CHROOT_DIR}" ] \
		|| mkdir -p ${INSTALLER_CHROOT_DIR}

	echo ">>> Installing world with bsdinstall for ${TARGET} architecture..." | tee -a ${LOGFILE}
	script -aq $LOGFILE ${BUILDER_SCRIPTS}/install_freebsd.sh -i -K \
		-s ${FREEBSD_SRC_DIR} \
		-d ${INSTALLER_CHROOT_DIR} \
		|| print_error_pfS

	# Copy additional installer scripts
	install -o root -g wheel -m 0755 ${BUILDER_TOOLS}/installer/*.sh \
		${INSTALLER_CHROOT_DIR}/root

	# Ship the shared foreign-config package map so the installer importer uses
	# the exact same pfSense/OPNsense->FreeSense mappings as the GUI importer.
	if [ -f "${PRODUCT_SRC}/etc/config_import_pkgmap.map" ]; then
		install -o root -g wheel -m 0644 \
			"${PRODUCT_SRC}/etc/config_import_pkgmap.map" \
			${INSTALLER_CHROOT_DIR}/root/config_import_pkgmap.map || \
			echo ">>> WARN: config_import_pkgmap.map not staged into installer (non-fatal)"
	fi

	# XXX set root password since we don't have nullok enabled
	pw -R ${INSTALLER_CHROOT_DIR} usermod root -w yes

	echo ">>> Installing world without bsdinstall for ${TARGET} architecture..." | tee -a ${LOGFILE}
	script -aq $LOGFILE ${BUILDER_SCRIPTS}/install_freebsd.sh -K \
		-s ${FREEBSD_SRC_DIR} \
		-d ${STAGE_CHROOT_DIR} \
		|| print_error_pfS

	# Use the builder cross compiler from obj to produce the final binary.
	BUILD_CC="${MAKEOBJDIRPREFIX}${FREEBSD_SRC_DIR}/${TARGET}.${TARGET_ARCH}/tmp/usr/bin/cc"

	[ -f "${BUILD_CC}" ] || print_error_pfS

	install_branded_bsdinstall_binaries

	# XXX It must go to the scripts
	[ -d "${STAGE_CHROOT_DIR}/usr/local/bin" ] \
		|| mkdir -p ${STAGE_CHROOT_DIR}/usr/local/bin
	makeargs="CC=${BUILD_CC} DESTDIR=${STAGE_CHROOT_DIR}"
	echo ">>> Building and installing crypto tools and athstats for ${TARGET} architecture... (Starting - $(LC_ALL=C date))" | tee -a ${LOGFILE}
	(script -aq $LOGFILE make -C ${FREEBSD_SRC_DIR}/tools/tools/crypto ${makeargs} clean all install || echo ">>> WARN: crypto tools build skipped (non-fatal)";) | egrep '^>>>' | tee -a ${LOGFILE}
	# XXX FIX IT
#	(script -aq $LOGFILE make -C ${FREEBSD_SRC_DIR}/tools/tools/ath/athstats ${makeargs} clean all install || print_error_pfS;) | egrep '^>>>' | tee -a ${LOGFILE}
	echo ">>> Building and installing crypto tools and athstats for ${TARGET} architecture... (Finished - $(LC_ALL=C date))" | tee -a ${LOGFILE}

	if [ "${PRODUCT_NAME}" = "pfSense" -a -n "${GNID_REPO_BASE}" ]; then
		echo ">>> Building gnid... " | tee -a ${LOGFILE}
		(\
			cd ${GNID_SRC_DIR} && \
			make \
				CC=${BUILD_CC} \
				INCLUDE_DIR=${GNID_INCLUDE_DIR} \
				LIBCRYPTO_DIR=${GNID_LIBCRYPTO_DIR} \
			clean gnid \
		) || print_error_pfS
		install -o root -g wheel -m 0700 ${GNID_SRC_DIR}/gnid \
			${STAGE_CHROOT_DIR}/usr/sbin \
			|| print_error_pfS
		install -o root -g wheel -m 0700 ${GNID_SRC_DIR}/gnid \
			${INSTALLER_CHROOT_DIR}/usr/sbin \
			|| print_error_pfS
	fi

	unset makeargs
}

# Build the three bsdinstall programs that compile OSNAME into their dialog
# chrome. Copying the patched shell scripts is not enough: the pkgbase binaries
# arrive with the upstream "FreeBSD Installer" backtitle embedded in them (most
# visibly in distextract's archive progress dialog). Rebuilding only these
# small programs avoids a full buildworld while keeping every installer screen
# consistently branded.
install_branded_bsdinstall_binaries() {
	local _bsd_src="${FREEBSD_SRC_DIR}/usr.sbin/bsdinstall"
	local _component=""
	local _binary=""
	local _objdir=""

	if [ ! -d "${_bsd_src}" -o ! -x "${BUILD_CC}" ]; then
		echo ">>> ERROR: cannot build branded bsdinstall binaries (source/compiler missing)" | tee -a ${LOGFILE}
		print_error_pfS
	fi

	echo ">>> Building bsdinstall chrome for ${PRODUCT_NAME}..." | tee -a ${LOGFILE}
	(
		unset MAKEOBJDIRPREFIX
		# Do not combine clean/all: bmake may decide opt_osname.h is current
		# before the clean target removes it, leaving the default OSNAME header.
		make -C "${_bsd_src}/include" clean
		make -C "${_bsd_src}/include" OSNAME="${PRODUCT_NAME}" all
		_include_objdir=$(make -C "${_bsd_src}/include" -V .OBJDIR)
		grep -Fq "#define OSNAME \"${PRODUCT_NAME}\"" \
			"${_include_objdir}/opt_osname.h"
		for _component in distextract distfetch partedit; do
			make -C "${_bsd_src}/${_component}" clean
			make -C "${_bsd_src}/${_component}" CC="${BUILD_CC}" \
				OSNAME="${PRODUCT_NAME}" all
			# Resolve, verify, and install while MAKEOBJDIRPREFIX is still in
			# exactly the same state used for compilation.
			_objdir=$(make -C "${_bsd_src}/${_component}" -V .OBJDIR)
			_binary="${_objdir}/${_component}"
			if [ ! -x "${_binary}" ] || \
			    ! strings "${_binary}" | grep -Fq "${PRODUCT_NAME} Installer"; then
				echo "${_component}: branded binary missing at ${_binary}"
				exit 1
			fi
			install -o root -g wheel -m 0555 "${_binary}" \
				${INSTALLER_CHROOT_DIR}/usr/libexec/bsdinstall/${_component}
		done
	) >> ${LOGFILE} 2>&1 || {
		echo ">>> ERROR: failed to build ${PRODUCT_NAME}-branded bsdinstall binaries" | tee -a ${LOGFILE}
		print_error_pfS
	}

	echo ">>> Verified bsdinstall chrome: ${PRODUCT_NAME} Installer" | tee -a ${LOGFILE}
}

# FreeSense LEVER 1 (pkgbase world): the tail of make_world for the pkgbase path.
# The chroots are already populated from FreeBSD pkgbase (freesense-pkgbase-world.sh),
# so there is no installworld to run. This mirrors the classic tail: stage the extra
# installer scripts + foreign-config map, set the installer root password, and build
# the crypto tools — but compiled with the chroot's own clang (BUILD_CC set by the
# caller to ${STAGE_CHROOT_DIR}/usr/bin/cc) since there is no obj cross-toolchain.
make_world_pkgbase_tail() {
	LOGFILE=${BUILDER_LOGS}/installworld.${TARGET}

	# Copy additional installer scripts
	install -o root -g wheel -m 0755 ${BUILDER_TOOLS}/installer/*.sh \
		${INSTALLER_CHROOT_DIR}/root

	# Ship the shared foreign-config package map (same as the classic path).
	if [ -f "${PRODUCT_SRC}/etc/config_import_pkgmap.map" ]; then
		install -o root -g wheel -m 0644 \
			"${PRODUCT_SRC}/etc/config_import_pkgmap.map" \
			${INSTALLER_CHROOT_DIR}/root/config_import_pkgmap.map || \
			echo ">>> WARN: config_import_pkgmap.map not staged into installer (non-fatal)"
	fi

	# XXX set root password since we don't have nullok enabled
	pw -R ${INSTALLER_CHROOT_DIR} usermod root -w yes

	# Build crypto tools with the seeded chroot's clang. Non-fatal: the classic path
	# also treats this as best-effort, and the tools are a convenience, not core.
	if [ -x "${BUILD_CC}" ]; then
		[ -d "${STAGE_CHROOT_DIR}/usr/local/bin" ] || mkdir -p ${STAGE_CHROOT_DIR}/usr/local/bin
		makeargs="CC=${BUILD_CC} DESTDIR=${STAGE_CHROOT_DIR}"
		echo ">>> pkgbase: building crypto tools with chroot clang... ($(LC_ALL=C date))" | tee -a ${LOGFILE}
		(script -aq $LOGFILE make -C ${FREEBSD_SRC_DIR}/tools/tools/crypto ${makeargs} clean all install \
			|| echo ">>> WARN: crypto tools build skipped (non-fatal)";) | egrep '^>>>' | tee -a ${LOGFILE}
		unset makeargs
	else
		echo ">>> WARN: no BUILD_CC (${BUILD_CC}) — skipping crypto tools (non-fatal)" | tee -a ${LOGFILE}
	fi

	install_branded_bsdinstall_binaries

	# FreeSense pkgbase userland delivery (patch triage 2026-07-11, corrected
	# 2026-07-11 after an install test): with no buildworld, patches that touch
	# USERLAND are never compiled/installed — the chroots hold stock pkgbase
	# binaries. The src/ overlay ships /etc/FreeSense-rc and
	# customize_stagearea_for_image symlinks /etc/pfSense-rc -> it, BUT the thing
	# that ACTUALLY hands boot to that script is a hook patched into base's
	# /etc/rc (patch 0009): `if [ -f /etc/pfSense-rc ]; then . /etc/pfSense-rc;
	# exit 0; fi`. Under pkgbase that /etc/rc is STOCK FreeBSD (no hook), so the
	# installed system boots straight to raw FreeBSD ("Amnesiac", no console
	# menu / no GUI) even though FreeSense-rc is present. Five pieces are NOT
	# covered by the stock world and are delivered here straight from the PATCHED
	# source tree — all plain text, no compile:
	#   1. bsdinstall scripts (patch 0005): the FreeSense install flow. Without
	#      them the ISO boots a GENERIC FreeBSD installer, loses config
	#      recovery/import + the FreeSense ZFS layout, and guided-UFS ABORTS
	#      (scripts/auto calls the fix_fstab helper that only exists patched).
	#   2. startbsdinstall (0005): the installer entry menu.
	#   3. gettytab (0009): the al.3wire serial autologin capability.
	#   4. loader branding (0004): brand/logo lua + the defaults block, appended
	#      to /boot/defaults/loader.conf so it ships inside base.txz /
	#      FreeSense-base exactly like the classic build did (loader.conf.local
	#      remains the user's file).
	#   5. /etc/rc + /etc/rc.shutdown (0009): the boot hook that execs
	#      /etc/pfSense-rc. THE load-bearing one — without it nothing ever runs
	#      FreeSense-rc and the box boots to Amnesiac. Delivered to BOTH chroots
	#      (installer env and base.txz) and hard-verified below.
	# Deliberately NOT delivered (compiled, and functionally irrelevant here):
	# ppp(8) PPP_CONFDIR (FreeSense PPP runs on mpd5) and the 1-char resizewin fix.
	_bsd_src="${FREEBSD_SRC_DIR}/usr.sbin/bsdinstall"
	if [ -d "${_bsd_src}" ]; then
		mkdir -p ${INSTALLER_CHROOT_DIR}/usr/libexec/bsdinstall
		for _f in auto config zfsboot copy_configxml_from_usb fix_fstab; do
			if [ -f "${_bsd_src}/scripts/${_f}" ]; then
				install -o root -g wheel -m 0755 "${_bsd_src}/scripts/${_f}" \
					${INSTALLER_CHROOT_DIR}/usr/libexec/bsdinstall/${_f}
			else
				echo ">>> WARN: patched bsdinstall script '${_f}' missing in src tree" | tee -a ${LOGFILE}
			fi
		done
		if [ -f "${_bsd_src}/startbsdinstall" ]; then
			install -o root -g wheel -m 0755 "${_bsd_src}/startbsdinstall" \
				${INSTALLER_CHROOT_DIR}/usr/sbin/startbsdinstall
		fi
	fi

	# Installer autostart: the classic path got this from `install_freebsd.sh -i`
	# (which copied FreeBSD's stock release/rc.local). pkgbase-world skips that
	# script, so without an /etc/rc.local the installer media boots to a bare
	# login prompt instead of launching the installer. Ship the FreeSense
	# installer rc.local (runs startbsdinstall, keeping the branded chrome +
	# config import) into the INSTALLER chroot ONLY — never the installed system.
	_inst_rclocal="${BUILDER_TOOLS}/installer/installer-rc.local"
	if [ -f "${_inst_rclocal}" ]; then
		install -o root -g wheel -m 0555 "${_inst_rclocal}" \
			${INSTALLER_CHROOT_DIR}/etc/rc.local
	else
		echo ">>> ERROR: ${_inst_rclocal} missing — installer media would boot to a login prompt, not the installer" | tee -a ${LOGFILE}
		print_error_pfS
	fi
	# The boot hook lives in the patched base /etc/rc (source: libexec/rc/rc).
	# It is BOOT-CRITICAL: verify the hook is actually present in the source
	# before shipping it, so a silently-dropped patch 0009 fails the build here
	# instead of producing an Amnesiac ISO. Same for rc.shutdown.
	_rc_src="${FREEBSD_SRC_DIR}/libexec/rc/rc"
	_rcshut_src="${FREEBSD_SRC_DIR}/libexec/rc/rc.shutdown"
	if [ ! -f "${_rc_src}" ]; then
		echo ">>> ERROR: ${_rc_src} missing — cannot deliver the FreeSense boot hook" | tee -a ${LOGFILE}
		print_error_pfS
	fi
	if ! grep -q '/etc/pfSense-rc' "${_rc_src}"; then
		echo ">>> ERROR: ${_rc_src} has no /etc/pfSense-rc hook — patch 0009 did not apply. Refusing to ship an Amnesiac base.txz." | tee -a ${LOGFILE}
		print_error_pfS
	fi

	for _root in ${STAGE_CHROOT_DIR} ${INSTALLER_CHROOT_DIR}; do
		if [ -f "${FREEBSD_SRC_DIR}/libexec/getty/gettytab" ]; then
			install -o root -g wheel -m 0644 \
				"${FREEBSD_SRC_DIR}/libexec/getty/gettytab" ${_root}/etc/gettytab
		fi
		# Boot hook: the patched /etc/rc that execs /etc/pfSense-rc (-> FreeSense-rc).
		# Overwrites the stock pkgbase /etc/rc that has no hook. Without this the
		# installed system and the installer env both boot to raw FreeBSD.
		install -o root -g wheel -m 0555 "${_rc_src}" ${_root}/etc/rc
		if [ -f "${_rcshut_src}" ]; then
			install -o root -g wheel -m 0555 "${_rcshut_src}" ${_root}/etc/rc.shutdown
		fi
		mkdir -p ${_root}/boot/lua
		for _lua in brand-${PRODUCT_NAME}.lua logo-${PRODUCT_NAME}.lua; do
			if [ -f "${FREEBSD_SRC_DIR}/stand/lua/${_lua}" ]; then
				install -o root -g wheel -m 0444 \
					"${FREEBSD_SRC_DIR}/stand/lua/${_lua}" ${_root}/boot/lua/${_lua}
			fi
		done
		if [ -f "${_root}/boot/defaults/loader.conf" ] && \
		    ! grep -q "loader_menu_title=\"Welcome to ${PRODUCT_NAME}\"" \
		    ${_root}/boot/defaults/loader.conf; then
			cat >> ${_root}/boot/defaults/loader.conf <<EOF

### ${PRODUCT_NAME} specific default values #######################
loader_color="NO"
loader_logo="${PRODUCT_NAME}"
loader_brand="${PRODUCT_NAME}"
loader_menu_title="Welcome to ${PRODUCT_NAME}"
EOF
		fi
	done
	echo ">>> pkgbase: patched-userland delivery done (bsdinstall + gettytab + loader branding + /etc/rc boot hook)." | tee -a ${LOGFILE}

	echo ">>> pkgbase world tail complete." | tee -a ${LOGFILE}
}

# This routine creates a ova image that contains
# a ovf and vmdk file. These files can be imported
# right into vmware or virtual box.
# (and many other emulation platforms)
# http://www.vmware.com/pdf/ovf_whitepaper_specification.pdf
create_ova_image() {
	# XXX create a .ovf php creator that you can pass:
	#     1. populatedSize
	#     2. license
	#     3. product name
	#     4. version
	#     5. number of network interface cards
	#     6. allocationUnits
	#     7. capacity
	#     8. capacityAllocationUnits

	LOGFILE=${BUILDER_LOGS}/ova.${TARGET}.log

	local _mntdir=${OVA_TMP}/mnt

	if [ -d "${_mntdir}" ]; then
		local _dev
		# XXX Root cause still didn't found but it doesn't umount
		#     properly on looped builds and then require this extra
		#     check
		while true; do
			_dev=$(mount -p ${_mntdir} 2>/dev/null | awk '{print $1}')
			[ $? -ne 0 -o -z "${_dev}" ] \
				&& break
			umount -f ${_mntdir}
			mdconfig -d -u ${_dev#/dev/}
		done
		chflags -R noschg ${OVA_TMP}
		rm -rf ${OVA_TMP}
	fi

	mkdir -p $(dirname ${OVAPATH})

	mkdir -p ${_mntdir}

	if [ -z "${OVA_SWAP_PART_SIZE_IN_GB}" -o "${OVA_SWAP_PART_SIZE_IN_GB}" = "0" ]; then
		# first partition size (freebsd-ufs)
		local OVA_FIRST_PART_SIZE_IN_GB=${VMDK_DISK_CAPACITY_IN_GB}
		# Calculate real first partition size, removing 256 blocks (131072 bytes) beginning/loader
		local OVA_FIRST_PART_SIZE=$((${OVA_FIRST_PART_SIZE_IN_GB}*1024*1024*1024-131072))
		# Unset swap partition size variable
		unset OVA_SWAP_PART_SIZE
		# Parameter used by mkimg
		unset OVA_SWAP_PART_PARAM
	else
		# first partition size (freebsd-ufs)
		local OVA_FIRST_PART_SIZE_IN_GB=$((VMDK_DISK_CAPACITY_IN_GB-OVA_SWAP_PART_SIZE_IN_GB))
		# Use first partition size in g
		local OVA_FIRST_PART_SIZE="${OVA_FIRST_PART_SIZE_IN_GB}g"
		# Calculate real swap size, removing 256 blocks (131072 bytes) beginning/loader
		local OVA_SWAP_PART_SIZE=$((${OVA_SWAP_PART_SIZE_IN_GB}*1024*1024*1024-131072))
		# Parameter used by mkimg
		local OVA_SWAP_PART_PARAM="-p freebsd-swap/swap0::${OVA_SWAP_PART_SIZE}"
	fi

	# Prepare folder to be put in image
	customize_stagearea_for_image "ova"
	install_default_kernel ${DEFAULT_KERNEL} "no"

	# Fill fstab
	echo ">>> Installing platform specific items..." | tee -a ${LOGFILE}
	echo "/dev/gpt/${PRODUCT_NAME}	/	ufs		rw	1	1" > ${FINAL_CHROOT_DIR}/etc/fstab
	if [ -n "${OVA_SWAP_PART_SIZE}" ]; then
		echo "/dev/gpt/swap0	none	swap	sw	0	0" >> ${FINAL_CHROOT_DIR}/etc/fstab
	fi

	# Create / partition
	echo -n ">>> Creating / partition... " | tee -a ${LOGFILE}
	truncate -s ${OVA_FIRST_PART_SIZE} ${OVA_TMP}/${OVFUFS}
	local _md=$(mdconfig -a -f ${OVA_TMP}/${OVFUFS})
	trap "mdconfig -d -u ${_md}; return" 1 2 15 EXIT

	newfs -L ${PRODUCT_NAME} -j /dev/${_md} 2>&1 >>${LOGFILE}

	if ! mount /dev/${_md} ${_mntdir} 2>&1 >>${LOGFILE}; then
		echo "Failed!" | tee -a ${LOGFILE}
		echo ">>> ERROR: Error mounting temporary vmdk image. STOPPING!" | tee -a ${LOGFILE}
		print_error_pfS
	fi
	trap "sync; sleep 3; umount ${_mntdir} || umount -f ${_mntdir}; mdconfig -d -u ${_md}; return" 1 2 15 EXIT

	echo "Done!" | tee -a ${LOGFILE}

	clone_directory_contents ${FINAL_CHROOT_DIR} ${_mntdir}

	sync
	sleep 3
	umount ${_mntdir} || umount -f ${_mntdir} >>${LOGFILE} 2>&1
	mdconfig -d -u ${_md}
	trap "-" 1 2 15 EXIT

	# Create raw disk
	echo -n ">>> Creating raw disk... " | tee -a ${LOGFILE}
	mkimg \
		-s gpt \
		-f raw \
		-b ${FINAL_CHROOT_DIR}/boot/pmbr \
		-p freebsd-boot:=${FINAL_CHROOT_DIR}/boot/gptboot \
		-p freebsd-ufs/${PRODUCT_NAME}:=${OVA_TMP}/${OVFUFS} \
		${OVA_SWAP_PART_PARAM} \
		-o ${OVA_TMP}/${OVFRAW} 2>&1 >> ${LOGFILE}

	if [ $? -ne 0 -o ! -f ${OVA_TMP}/${OVFRAW} ]; then
		if [ -f ${OVA_TMP}/${OVFUFS} ]; then
			rm -f ${OVA_TMP}/${OVFUFS}
		fi
		if [ -f ${OVA_TMP}/${OVFRAW} ]; then
			rm -f ${OVA_TMP}/${OVFRAW}
		fi
		echo "Failed!" | tee -a ${LOGFILE}
		echo ">>> ERROR: Error creating temporary vmdk image. STOPPING!" | tee -a ${LOGFILE}
		print_error_pfS
	fi
	echo "Done!" | tee -a ${LOGFILE}

	# We don't need it anymore
	rm -f ${OVA_TMP}/${OVFUFS} >/dev/null 2>&1

	# Convert raw to vmdk
	echo -n ">>> Creating vmdk disk... " | tee -a ${LOGFILE}
	vmdktool -z9 -v ${OVA_TMP}/${OVFVMDK} ${OVA_TMP}/${OVFRAW}

	if [ $? -ne 0 -o ! -f ${OVA_TMP}/${OVFVMDK} ]; then
		if [ -f ${OVA_TMP}/${OVFRAW} ]; then
			rm -f ${OVA_TMP}/${OVFRAW}
		fi
		if [ -f ${OVA_TMP}/${OVFVMDK} ]; then
			rm -f ${OVA_TMP}/${OVFVMDK}
		fi
		echo "Failed!" | tee -a ${LOGFILE}
		echo ">>> ERROR: Error creating vmdk image. STOPPING!" | tee -a ${LOGFILE}
		print_error_pfS
	fi
	echo "Done!" | tee -a ${LOGFILE}

	rm -f ${OVA_TMP}/${OVFRAW}

	ova_setup_ovf_template

	echo -n ">>> Writing final ova image... " | tee -a ${LOGFILE}
	# Create OVA file for vmware
	gtar -C ${OVA_TMP} -cpf ${OVAPATH} ${PRODUCT_NAME}.ovf ${OVFVMDK}
	echo "Done!" | tee -a ${LOGFILE}
	rm -f ${OVA_TMP}/${OVFVMDK} >/dev/null 2>&1

	echo ">>> OVA created: $(LC_ALL=C date)" | tee -a ${LOGFILE}
}

# called from create_ova_image
ova_setup_ovf_template() {
	if [ ! -f ${OVFTEMPLATE} ]; then
		echo ">>> ERROR: OVF template file (${OVFTEMPLATE}) not found."
		print_error_pfS
	fi

	#  OperatingSystemSection (${PRODUCT_NAME}.ovf)
	#  42   FreeBSD 32-Bit
	#  78   FreeBSD 64-Bit
	if [ "${TARGET}" = "amd64" ]; then
		local _os_id="78"
		local _os_type="freebsd64Guest"
		local _os_descr="FreeBSD 64-Bit"
	else
		echo ">>> ERROR: Platform not supported for OVA (${TARGET})"
		print_error_pfS
	fi

	local POPULATED_SIZE=$(du -d0 -k $FINAL_CHROOT_DIR | cut -f1)
	local POPULATED_SIZE_IN_BYTES=$((${POPULATED_SIZE}*1024))
	local VMDK_FILE_SIZE=$(stat -f "%z" ${OVA_TMP}/${OVFVMDK})

	sed \
		-e "s,%%VMDK_FILE_SIZE%%,${VMDK_FILE_SIZE},g" \
		-e "s,%%VMDK_DISK_CAPACITY_IN_GB%%,${VMDK_DISK_CAPACITY_IN_GB},g" \
		-e "s,%%POPULATED_SIZE_IN_BYTES%%,${POPULATED_SIZE_IN_BYTES},g" \
		-e "s,%%OS_ID%%,${_os_id},g" \
		-e "s,%%OS_TYPE%%,${_os_type},g" \
		-e "s,%%OS_DESCR%%,${_os_descr},g" \
		-e "s,%%PRODUCT_NAME%%,${PRODUCT_NAME},g" \
		-e "s,%%PRODUCT_NAME_SUFFIX%%,${PRODUCT_NAME_SUFFIX},g" \
		-e "s,%%PRODUCT_VERSION%%,${PRODUCT_VERSION},g" \
		-e "s,%%PRODUCT_URL%%,${PRODUCT_URL},g" \
		-e "s#%%VENDOR_NAME%%#${VENDOR_NAME}#g" \
		-e "s#%%OVF_INFO%%#${OVF_INFO}#g" \
		-e "/^%%PRODUCT_LICENSE%%/r ${BUILDER_ROOT}/LICENSE" \
		-e "/^%%PRODUCT_LICENSE%%/d" \
		${OVFTEMPLATE} > ${OVA_TMP}/${PRODUCT_NAME}.ovf
}

# Cleans up previous builds
clean_builder() {
	# Clean out directories
	echo ">>> Cleaning up previous build environment...Please wait!"

	staginareas_clean_each_run

	if [ -d "${STAGE_CHROOT_DIR}" ]; then
		echo -n ">>> Cleaning ${STAGE_CHROOT_DIR}... "
		chflags -R noschg ${STAGE_CHROOT_DIR} 2>&1 >/dev/null
		rm -rf ${STAGE_CHROOT_DIR}/* 2>/dev/null
		echo "Done."
	fi

	if [ -d "${INSTALLER_CHROOT_DIR}" ]; then
		echo -n ">>> Cleaning ${INSTALLER_CHROOT_DIR}... "
		chflags -R noschg ${INSTALLER_CHROOT_DIR} 2>&1 >/dev/null
		rm -rf ${INSTALLER_CHROOT_DIR}/* 2>/dev/null
		echo "Done."
	fi

	if [ -z "${NO_CLEAN_FREEBSD_OBJ}" -a -d "${FREEBSD_SRC_DIR}" ]; then
		OBJTREE=$(make -C ${FREEBSD_SRC_DIR} -V OBJTREE)
		if [ -d "${OBJTREE}" ]; then
			echo -n ">>> Cleaning FreeBSD objects dir staging..."
			echo -n "."
			chflags -R noschg ${OBJTREE} 2>&1 >/dev/null
			echo -n "."
			rm -rf ${OBJTREE}/*
			echo "Done!"
		fi
		if [ -d "${KERNEL_BUILD_PATH}" ]; then
			echo -n ">>> Cleaning previously built kernel stage area..."
			rm -rf $KERNEL_BUILD_PATH/*
			echo "Done!"
		fi
	fi
	mkdir -p $KERNEL_BUILD_PATH

	echo -n ">>> Cleaning previously built images..."
	rm -rf $IMAGES_FINAL_DIR/*
	echo "Done!"

	echo -n ">>> Cleaning previous builder logs..."
	if [ -d "$BUILDER_LOGS" ]; then
		rm -rf ${BUILDER_LOGS}
	fi
	mkdir -p ${BUILDER_LOGS}

	echo "Done!"

	echo ">>> Cleaning of builder environment has finished."
}

clone_directory_contents() {
	if [ ! -e "$2" ]; then
		mkdir -p "$2"
	fi
	if [ ! -d "$1" -o ! -d "$2" ]; then
		if [ -z "${LOGFILE}" ]; then
			echo ">>> ERROR: Argument $1 supplied is not a directory!"
		else
			echo ">>> ERROR: Argument $1 supplied is not a directory!" | tee -a ${LOGFILE}
		fi
		print_error_pfS
	fi
	echo -n ">>> Using TAR to clone $1 to $2 ..."
	tar -C ${1} -c -f - . | tar -C ${2} -x -p -f -
	echo "Done!"
}

clone_to_staging_area() {
	# Clone everything to the final staging area
	echo -n ">>> Cloning everything to ${STAGE_CHROOT_DIR} staging area..."
	LOGFILE=${BUILDER_LOGS}/cloning.${TARGET}.log

	tar -C ${PRODUCT_SRC} -c -f - . | \
		tar -C ${STAGE_CHROOT_DIR} -x -p -f -

	# Belt-and-suspenders: guarantee the boot/rc scripts are executable.
	# The real fix is the git file modes (they must be tracked 100755), but a
	# clone from a filesystem/index that lost the exec bit would otherwise ship
	# /etc/rc.* at 0644, which makes FreeSense-rc fail every rc.* with
	# "Permission denied" -> config never loads -> the box boots as "Amnesiac".
	# Restore the exec bit on every shebang script under the staged tree so a
	# lost mode can never brick the boot again.
	find ${STAGE_CHROOT_DIR}/etc ${STAGE_CHROOT_DIR}/usr/local/bin \
		${STAGE_CHROOT_DIR}/usr/local/sbin -type f 2>/dev/null | \
		while read -r _f; do
			if [ "$(head -c2 "${_f}" 2>/dev/null)" = '#!' ]; then
				chmod 0755 "${_f}"
			fi
		done

	mkdir -p ${STAGE_CHROOT_DIR}/etc/mtree
	mtree -Pcp ${STAGE_CHROOT_DIR}/var > ${STAGE_CHROOT_DIR}/etc/mtree/var.dist
	mtree -Pcp ${STAGE_CHROOT_DIR}/etc > ${STAGE_CHROOT_DIR}/etc/mtree/etc.dist
	if [ -d ${STAGE_CHROOT_DIR}/usr/local/etc ]; then
		mtree -Pcp ${STAGE_CHROOT_DIR}/usr/local/etc > ${STAGE_CHROOT_DIR}/etc/mtree/localetc.dist
	fi

	## Add buildtime and lastcommit information
	# This is used for detecting updates.
	echo "$BUILTDATESTRING" > $STAGE_CHROOT_DIR/etc/version.buildtime
	# Record last commit info if it is available.
	if [ -f $SCRATCHDIR/build_commit_info.txt ]; then
		cp $SCRATCHDIR/build_commit_info.txt $STAGE_CHROOT_DIR/etc/version.lastcommit
	fi

	local _exclude_files="${SCRATCHDIR}/base_exclude_files"
	sed \
		-e "s,%%PRODUCT_NAME%%,${PRODUCT_NAME},g" \
		-e "s,%%VERSION%%,${_version},g" \
		${BUILDER_TOOLS}/templates/core_pkg/base/exclude_files \
		> ${_exclude_files}

	mkdir -p ${STAGE_CHROOT_DIR}${PRODUCT_SHARE_DIR} >/dev/null 2>&1

	# Delete any base.txz/base.mtree left in the chroot by a PREVIOUS build before we
	# regenerate them. The exclude_files list names these paths, but the stage chroot is
	# reused across builds and a stale ~1GB base.txz from the prior run was ending up
	# INSIDE the new base.txz (self-nested, ~1GB of pure bloat) — belt-and-suspenders
	# with the -X exclude so it can't ship regardless of tar -X pattern semantics.
	rm -f ${STAGE_CHROOT_DIR}${PRODUCT_SHARE_DIR}/base.txz \
	      ${STAGE_CHROOT_DIR}${PRODUCT_SHARE_DIR}/base.mtree

	# Include a sample pkg stable conf to base
	setup_pkg_repo \
		${PKG_REPO_DEFAULT} \
		${STAGE_CHROOT_DIR}${PRODUCT_SHARE_DIR}/${PRODUCT_NAME}-repo.conf \
		${TARGET} \
		${TARGET_ARCH}

	# FreeSense: seed the channel manifest + per-branch repo confs so the
	# System > Update branch selector is populated on a fresh install.
	stage_repo_channels ${STAGE_CHROOT_DIR}

	mtree \
		-c \
		-k uid,gid,mode,size,flags,sha256digest \
		-p ${STAGE_CHROOT_DIR} \
		-X ${_exclude_files} \
		> ${STAGE_CHROOT_DIR}${PRODUCT_SHARE_DIR}/base.mtree
	# base.txz is a ~550MB xz of the whole staged world. Default -cJf runs SINGLE-THREADED
	# xz (~8-10 min native, ~20-30 min under QEMU) and emits ZERO output the whole time —
	# which repeatedly looked like a hang (see the 2026-07-10 diagnostic). Pipe to xz -T0
	# (all cores) instead: ~8x faster on a 12-core box AND a far shorter silent window. The
	# echo makes the step no longer silent so monitors don't misread it as wedged.
	echo ">>> Compressing base.txz (xz -T0, all cores; ~large, expect a quiet minute or two)..." | tee -a ${LOGFILE}
	tar -C ${STAGE_CHROOT_DIR} -X ${_exclude_files} --create --file - . \
		| xz -T0 -c > ${STAGE_CHROOT_DIR}${PRODUCT_SHARE_DIR}/base.txz
	echo ">>> base.txz done: $(ls -lh ${STAGE_CHROOT_DIR}${PRODUCT_SHARE_DIR}/base.txz 2>/dev/null | awk '{print $5}')" | tee -a ${LOGFILE}

	core_pkg_create rc "" ${CORE_PKG_VERSION} ${STAGE_CHROOT_DIR}
	core_pkg_create base "" ${CORE_PKG_VERSION} ${STAGE_CHROOT_DIR}
	mkdir -p ${STAGE_CHROOT_DIR}/conf.default
	cp ${BUILDER_TOOLS}/ci/freesense-config.xml ${STAGE_CHROOT_DIR}/conf.default/config.xml
	core_pkg_create default-config "" ${CORE_PKG_VERSION} ${STAGE_CHROOT_DIR}

	local DEFAULTCONF=${STAGE_CHROOT_DIR}/conf.default/config.xml

	# Save current WAN and LAN if value
	local _old_wan_if=$(xml sel -t -v "${XML_ROOTOBJ}/interfaces/wan/if" ${DEFAULTCONF})
	local _old_lan_if=$(xml sel -t -v "${XML_ROOTOBJ}/interfaces/lan/if" ${DEFAULTCONF})

	# Change default interface names to match vmware driver
	xml ed -P -L -u "${XML_ROOTOBJ}/interfaces/wan/if" -v "vmx0" ${DEFAULTCONF}
	xml ed -P -L -u "${XML_ROOTOBJ}/interfaces/lan/if" -v "vmx1" ${DEFAULTCONF}
	core_pkg_create default-config "vmware" ${CORE_PKG_VERSION} ${STAGE_CHROOT_DIR}

	# Restore default values to be used by serial package
	xml ed -P -L -u "${XML_ROOTOBJ}/interfaces/wan/if" -v "${_old_wan_if}" ${DEFAULTCONF}
	xml ed -P -L -u "${XML_ROOTOBJ}/interfaces/lan/if" -v "${_old_lan_if}" ${DEFAULTCONF}

	# Activate serial console in config.xml
	xml ed -L -P -d "${XML_ROOTOBJ}/system/enableserial" ${DEFAULTCONF}
	xml ed -P -s "${XML_ROOTOBJ}/system" -t elem -n "enableserial" \
		${DEFAULTCONF} > ${DEFAULTCONF}.tmp
	xml fo -t ${DEFAULTCONF}.tmp > ${DEFAULTCONF}
	rm -f ${DEFAULTCONF}.tmp

	mkdir -p ${STAGE_CHROOT_DIR}/cf/conf
	echo force > ${STAGE_CHROOT_DIR}/cf/conf/enableserial_force

	core_pkg_create default-config-serial "" ${CORE_PKG_VERSION} ${STAGE_CHROOT_DIR}
	core_pkg_create default-config "bhyve" ${CORE_PKG_VERSION} ${STAGE_CHROOT_DIR}

	rm -f ${STAGE_CHROOT_DIR}/cf/conf/enableserial_force
	rm -f ${STAGE_CHROOT_DIR}/cf/conf/config.xml

	# Normal image builds need pkg in the staging root.  A --build-core run is
	# itself producing the first dependency-closed repository, so reaching out
	# to the public repository here creates a circular bootstrap dependency.
	if [ "${FREESENSE_SKIP_STAGE_PKG_BOOTSTRAP:-no}" = "yes" ]; then
		echo ">>> FreeSense: core-only build skips external pkg bootstrap"
	else
		pkg_bootstrap ${STAGE_CHROOT_DIR} || return $?
	fi

	# Make sure correct repo is available on tmp dir
	mkdir -p ${STAGE_CHROOT_DIR}/tmp/pkg/pkg-repos
	setup_pkg_repo \
		${PKG_REPO_BUILD} \
		${STAGE_CHROOT_DIR}/tmp/pkg/pkg-repos/repo.conf \
		${TARGET} \
		${TARGET_ARCH} \
		staging \
		${STAGE_CHROOT_DIR}/tmp/pkg/pkg.conf

	echo "Done!"
}

create_final_staging_area() {
	if [ -z "${FINAL_CHROOT_DIR}" ]; then
		echo ">>> ERROR: FINAL_CHROOT_DIR is not set, cannot continue!" | tee -a ${LOGFILE}
		print_error_pfS
	fi

	if [ -d "${FINAL_CHROOT_DIR}" ]; then
		echo -n ">>> Previous ${FINAL_CHROOT_DIR} detected cleaning up..." | tee -a ${LOGFILE}
		chflags -R noschg ${FINAL_CHROOT_DIR} 2>&1 1>/dev/null
		rm -rf ${FINAL_CHROOT_DIR}/* 2>&1 1>/dev/null
		echo "Done." | tee -a ${LOGFILE}
	fi

	echo ">>> Preparing Final image staging area: $(LC_ALL=C date)" 2>&1 | tee -a ${LOGFILE}
	echo ">>> Cloning ${STAGE_CHROOT_DIR} to ${FINAL_CHROOT_DIR}" 2>&1 | tee -a ${LOGFILE}
	clone_directory_contents ${STAGE_CHROOT_DIR} ${FINAL_CHROOT_DIR}

	if [ ! -f $FINAL_CHROOT_DIR/sbin/init ]; then
		echo ">>> ERROR: Something went wrong during cloning -- Please verify!" 2>&1 | tee -a ${LOGFILE}
		print_error_pfS
	fi
}

customize_stagearea_for_image() {
	local _image_type="$1"
	local _default_config="" # filled with $2 below
	local _image_variant="$3"

	if [ -n "$2" ]; then
		_default_config="$2"
	elif [ "${_image_type}" = "memstickserial" -o \
	     "${_image_type}" = "memstickadi" ]; then
		_default_config="default-config-serial"
	elif [ "${_image_type}" = "ova" ]; then
		_default_config="default-config-vmware"
	else
		_default_config="default-config"
	fi

	# Prepare final stage area
	create_final_staging_area

	pkg_chroot_add ${FINAL_CHROOT_DIR} rc
	pkg_chroot_add ${FINAL_CHROOT_DIR} base

	# Set base/rc pkgs as vital to avoid user end up removing it for any reason
	pkg_chroot ${FINAL_CHROOT_DIR} set -v 1 -y $(get_pkg_name rc)
	pkg_chroot ${FINAL_CHROOT_DIR} set -v 1 -y $(get_pkg_name base)

	if [ "${_image_type}" = "iso" -o \
	     "${_image_type}" = "memstick" -o \
	     "${_image_type}" = "memstickserial" -o \
	     "${_image_type}" = "memstickadi" ]; then
		mkdir -p ${FINAL_CHROOT_DIR}/pkgs
		cp ${CORE_PKG_ALL_PATH}/*default-config*.pkg ${FINAL_CHROOT_DIR}/pkgs
	fi

	pkg_chroot_add ${FINAL_CHROOT_DIR} ${_default_config}

	# FreeSense boot hook (TWO halves, both required):
	#   (a) /etc/rc must contain `if [ -f /etc/pfSense-rc ]; then . /etc/pfSense-rc;
	#       exit 0; fi`. Under the CLASSIC build that hook is patched into base's
	#       /etc/rc (patch 0009) and compiled in via buildworld. Under pkgbase-world
	#       there is no buildworld, so make_world_pkgbase_tail installs the patched
	#       /etc/rc into the chroots explicitly — WITHOUT that, /etc/rc is stock and
	#       the box boots to RAW FreeBSD (Amnesiac, no console menu/GUI) even though
	#       the symlink below is present. (Regression found + fixed 2026-07-11.)
	#   (b) our rebrand renamed the product rc to /etc/${PRODUCT_NAME}-rc, so the
	#       hook's hard-coded /etc/pfSense-rc must resolve — symlink it here. Cheap +
	#       reproducible; avoids rebuilding base.txz just to rebrand the hook string.
	ln -sf ${PRODUCT_NAME}-rc ${FINAL_CHROOT_DIR}/etc/pfSense-rc
	ln -sf ${PRODUCT_NAME}-rc.shutdown ${FINAL_CHROOT_DIR}/etc/pfSense-rc.shutdown

	# XXX: Workaround to avoid pkg to complain regarding release
	#      repo on first boot since packages are installed from
	#      staging server during build phase
	if [ -n "${USE_PKG_REPO_STAGING}" ]; then
		_read_cmd="select value from repodata where key='packagesite'"
		if [ -n "${_IS_RELEASE}" -o -n "${_IS_RC}" ]; then
			local _tgt_server="${PKG_REPO_SERVER_RELEASE}"
		else
			local _tgt_server="${PKG_REPO_SERVER_DEVEL}"
		fi
		for _db in ${FINAL_CHROOT_DIR}/var/db/pkg/repo-*sqlite; do
			_cur=$(/usr/local/bin/sqlite3 ${_db} "${_read_cmd}")
			_new=$(echo "${_cur}" | sed -e "s,^${PKG_REPO_SERVER_STAGING},${_tgt_server},")
			/usr/local/bin/sqlite3 ${_db} "update repodata set value='${_new}' where key='packagesite'"
		done
	fi

	if [ -n "$_image_variant" -a \
	    -d ${BUILDER_TOOLS}/templates/custom_logos/${_image_variant} ]; then
		mkdir -p ${FINAL_CHROOT_DIR}/usr/local/share/${PRODUCT_NAME}/custom_logos
		cp -f \
			${BUILDER_TOOLS}/templates/custom_logos/${_image_variant}/*.svg \
			${FINAL_CHROOT_DIR}/usr/local/share/${PRODUCT_NAME}/custom_logos
		cp -f \
			${BUILDER_TOOLS}/templates/custom_logos/${_image_variant}/*.css \
			${FINAL_CHROOT_DIR}/usr/local/share/${PRODUCT_NAME}/custom_logos
	fi

	# Remove temporary repo conf
	rm -rf ${FINAL_CHROOT_DIR}/tmp/pkg
}

create_distribution_tarball() {
	mkdir -p ${INSTALLER_CHROOT_DIR}/usr/freebsd-dist

	# xz -T0 (all cores) not single-threaded -cJf: this is the ISO's ~1GB dist tarball, the
	# heaviest silent step in the ISO build (~10min single-threaded -> ~1-2min on 12 cores).
	# NOTE: this is a SEPARATE base.txz from clone_to_staging_area's (the update payload) —
	# it's the tarball the INSTALLER extracts onto disk. It previously excluded ONLY ./pkgs,
	# so it shipped /usr/src (~1.5GB, dep-pulled by a FreeBSD-set-* meta) + the nested
	# ./usr/local/share/%%PRODUCT%%/base.txz (~900MB, the update payload cloned STAGE->FINAL)
	# — the ISO stayed ~6GB raw even after the update-payload base.txz was slimmed
	# (2026-07-11). Strip the same fat trees here + the nested payload so the installed
	# system is lean too. (The seed-level rm in freesense-pkgbase-world.sh removes /usr/src
	# from the chroots; these excludes are belt-and-suspenders + cover the nested payload.)
	echo -n ">>> Creating distribution tarball (xz -T0)... " | tee -a ${LOGFILE}
	tar -C ${FINAL_CHROOT_DIR} \
		--exclude ./pkgs \
		--exclude ./usr/src --exclude ./usr/tests --exclude ./usr/lib32 --exclude ./usr/lib/debug \
		--exclude "./usr/local/share/${PRODUCT_NAME}/base.txz" \
		--exclude "./usr/local/share/${PRODUCT_NAME}/base.mtree" \
		--create --file - . \
		| xz -T0 -c > ${INSTALLER_CHROOT_DIR}/usr/freebsd-dist/base.txz
	echo "Done!" | tee -a ${LOGFILE}

	echo -n ">>> Creating manifest... " | tee -a ${LOGFILE}
	(cd ${INSTALLER_CHROOT_DIR}/usr/freebsd-dist && \
		sh ${FREEBSD_SRC_DIR}/release/scripts/make-manifest.sh base.txz) \
		> ${INSTALLER_CHROOT_DIR}/usr/freebsd-dist/MANIFEST
	echo "Done!" | tee -a ${LOGFILE}
}

create_iso_image() {
	local _variant="$1"

	LOGFILE=${BUILDER_LOGS}/isoimage.${TARGET}

	if [ -z "${ISOPATH}" ]; then
		echo ">>> ISOPATH is empty skipping generation of ISO image!" | tee -a ${LOGFILE}
		return
	fi

	echo ">>> Building bootable ISO image for ${TARGET}" | tee -a ${LOGFILE}

	mkdir -p $(dirname ${ISOPATH})

	local _image_path=${ISOPATH}
	if [ -n "${_variant}" ]; then
		_image_path=$(echo "$_image_path" | \
			sed "s/${PRODUCT_NAME_SUFFIX}-/&${_variant}-/")
		VARIANTIMAGES="${VARIANTIMAGES}${VARIANTIMAGES:+ }${_image_path}"
	fi

	customize_stagearea_for_image "iso" "" $_variant
	install_default_kernel ${DEFAULT_KERNEL}

	BOOTCONF=${INSTALLER_CHROOT_DIR}/boot.config
	LOADERCONF=${INSTALLER_CHROOT_DIR}/boot/loader.conf

	rm -f ${LOADERCONF} ${BOOTCONF} >/dev/null 2>&1
	echo 'autoboot_delay="3"' > ${LOADERCONF}
	echo 'kern.cam.boot_delay=10000' >> ${LOADERCONF}
	cat ${LOADERCONF} > ${FINAL_CHROOT_DIR}/boot/loader.conf

	create_distribution_tarball

	FSLABEL=$(echo ${PRODUCT_NAME} | tr '[:lower:]' '[:upper:]')

	sh ${FREEBSD_SRC_DIR}/release/${TARGET}/mkisoimages.sh -b \
		${FSLABEL} \
		${_image_path} \
		${INSTALLER_CHROOT_DIR}

	if [ ! -f "${_image_path}" ]; then
		echo "ERROR! ISO image was not built"
		print_error_pfS
	fi

	gzip -qf $_image_path &
	_bg_pids="${_bg_pids}${_bg_pids:+ }$!"

	echo ">>> ISO created: $(LC_ALL=C date)" | tee -a ${LOGFILE}
}

create_memstick_image() {
	local _variant="$1"

	LOGFILE=${BUILDER_LOGS}/memstick.${TARGET}
	if [ "${MEMSTICKPATH}" = "" ]; then
		echo ">>> MEMSTICKPATH is empty skipping generation of memstick image!" | tee -a ${LOGFILE}
		return
	fi

	mkdir -p $(dirname ${MEMSTICKPATH})

	local _image_path=${MEMSTICKPATH}
	if [ -n "${_variant}" ]; then
		_image_path=$(echo "$_image_path" | \
			sed "s/-memstick-/-memstick-${_variant}-/")
		VARIANTIMAGES="${VARIANTIMAGES}${VARIANTIMAGES:+ }${_image_path}"
	fi

	customize_stagearea_for_image "memstick" "" $_variant
	install_default_kernel ${DEFAULT_KERNEL}

	echo ">>> Creating memstick to ${_image_path}." 2>&1 | tee -a ${LOGFILE}

	BOOTCONF=${INSTALLER_CHROOT_DIR}/boot.config
	LOADERCONF=${INSTALLER_CHROOT_DIR}/boot/loader.conf

	rm -f ${LOADERCONF} ${BOOTCONF} >/dev/null 2>&1

	echo 'autoboot_delay="3"' > ${LOADERCONF}
	echo 'kern.cam.boot_delay=10000' >> ${LOADERCONF}
	echo 'boot_serial="NO"' >> ${LOADERCONF}
	cat ${LOADERCONF} > ${FINAL_CHROOT_DIR}/boot/loader.conf

	create_distribution_tarball

	FSLABEL=$(echo ${PRODUCT_NAME} | tr '[:lower:]' '[:upper:]')

	sh ${FREEBSD_SRC_DIR}/release/${TARGET}/mkisoimages.sh -b \
		${FSLABEL} \
		${_image_path} \
		${INSTALLER_CHROOT_DIR}

	if [ ! -f "${_image_path}" ]; then
		echo "ERROR! memstick image was not built"
		print_error_pfS
	fi

	gzip -qf $_image_path &
	_bg_pids="${_bg_pids}${_bg_pids:+ }$!"

	echo ">>> MEMSTICK created: $(LC_ALL=C date)" | tee -a ${LOGFILE}
}

create_memstick_serial_image() {
	LOGFILE=${BUILDER_LOGS}/memstickserial.${TARGET}
	if [ "${MEMSTICKSERIALPATH}" = "" ]; then
		echo ">>> MEMSTICKSERIALPATH is empty skipping generation of memstick image!" | tee -a ${LOGFILE}
		return
	fi

	mkdir -p $(dirname ${MEMSTICKSERIALPATH})

	customize_stagearea_for_image "memstickserial"
	install_default_kernel ${DEFAULT_KERNEL}

	echo ">>> Creating serial memstick to ${MEMSTICKSERIALPATH}." 2>&1 | tee -a ${LOGFILE}

	BOOTCONF=${INSTALLER_CHROOT_DIR}/boot.config
	LOADERCONF=${INSTALLER_CHROOT_DIR}/boot/loader.conf

	echo ">>> Activating serial console..." 2>&1 | tee -a ${LOGFILE}
	echo "-S115200 -D" > ${BOOTCONF}

	# Activate serial console+video console in loader.conf
	echo 'autoboot_delay="3"' > ${LOADERCONF}
	echo 'kern.cam.boot_delay=10000' >> ${LOADERCONF}
	echo 'boot_multicons="YES"' >> ${LOADERCONF}
	echo 'boot_serial="YES"' >> ${LOADERCONF}
	echo 'console="comconsole,vidconsole"' >> ${LOADERCONF}
	echo 'comconsole_speed="115200"' >> ${LOADERCONF}

	cat ${BOOTCONF} >> ${FINAL_CHROOT_DIR}/boot.config
	cat ${LOADERCONF} >> ${FINAL_CHROOT_DIR}/boot/loader.conf

	create_distribution_tarball

	sh ${FREEBSD_SRC_DIR}/release/${TARGET}/make-memstick.sh \
		${INSTALLER_CHROOT_DIR} \
		${MEMSTICKSERIALPATH}

	if [ ! -f "${MEMSTICKSERIALPATH}" ]; then
		echo "ERROR! memstick serial image was not built"
		print_error_pfS
	fi

	gzip -qf $MEMSTICKSERIALPATH &
	_bg_pids="${_bg_pids}${_bg_pids:+ }$!"

	echo ">>> MEMSTICKSERIAL created: $(LC_ALL=C date)" | tee -a ${LOGFILE}
}

create_memstick_adi_image() {
	LOGFILE=${BUILDER_LOGS}/memstickadi.${TARGET}
	if [ "${MEMSTICKADIPATH}" = "" ]; then
		echo ">>> MEMSTICKADIPATH is empty skipping generation of memstick image!" | tee -a ${LOGFILE}
		return
	fi

	mkdir -p $(dirname ${MEMSTICKADIPATH})

	customize_stagearea_for_image "memstickadi"
	install_default_kernel ${DEFAULT_KERNEL}

	echo ">>> Creating serial memstick to ${MEMSTICKADIPATH}." 2>&1 | tee -a ${LOGFILE}

	BOOTCONF=${INSTALLER_CHROOT_DIR}/boot.config
	LOADERCONF=${INSTALLER_CHROOT_DIR}/boot/loader.conf

	echo ">>> Activating serial console..." 2>&1 | tee -a ${LOGFILE}
	echo "-S115200 -h" > ${BOOTCONF}

	# Activate serial console+video console in loader.conf
	echo 'autoboot_delay="3"' > ${LOADERCONF}
	echo 'kern.cam.boot_delay=10000' >> ${LOADERCONF}
	echo 'boot_serial="YES"' >> ${LOADERCONF}
	echo 'console="comconsole"' >> ${LOADERCONF}
	echo 'comconsole_speed="115200"' >> ${LOADERCONF}
	echo 'comconsole_port="0x2F8"' >> ${LOADERCONF}
	echo 'hint.uart.0.flags="0x00"' >> ${LOADERCONF}
	echo 'hint.uart.1.flags="0x10"' >> ${LOADERCONF}

	cat ${BOOTCONF} >> ${FINAL_CHROOT_DIR}/boot.config
	cat ${LOADERCONF} >> ${FINAL_CHROOT_DIR}/boot/loader.conf

	create_distribution_tarball

	sh ${FREEBSD_SRC_DIR}/release/${TARGET}/make-memstick.sh \
		${INSTALLER_CHROOT_DIR} \
		${MEMSTICKADIPATH}

	if [ ! -f "${MEMSTICKADIPATH}" ]; then
		echo "ERROR! memstick ADI image was not built"
		print_error_pfS
	fi

	gzip -qf $MEMSTICKADIPATH &
	_bg_pids="${_bg_pids}${_bg_pids:+ }$!"

	echo ">>> MEMSTICKADI created: $(LC_ALL=C date)" | tee -a ${LOGFILE}
}

get_altabi_arch() {
	local _target_arch="$1"

	if [ "${_target_arch}" = "amd64" ]; then
		echo "x86:64"
	elif [ "${_target_arch}" = "i386" ]; then
		echo "x86:32"
	elif [ "${_target_arch}" = "armv7" ]; then
		echo "32:el:eabi:softfp"
	else
		echo ">>> ERROR: Invalid arch"
		print_error_pfS
	fi
}

# Create pkg conf on desired place with desired arch/branch
setup_pkg_repo() {
	if [ -z "${4}" ]; then
		return
	fi

	local _template="${1}"
	local _target="${2}"
	local _arch="${3}"
	local _target_arch="${4}"
	local _staging="${5}"
	local _pkg_conf="${6}"
	# FreeSense: 'none' — the repo URL is a plain https:// single static bucket (R2), so pkg
	# fetches <url>/meta.conf directly with no mirror resolution. (srv would do a failing DNS
	# SRV lookup; http would treat the URL as a mirror-list. none = use the URL as-is.)
	local _mirror_type="none"
	local _signature_type="fingerprints"

	if [ -z "${_template}" -o ! -f "${_template}" ]; then
		echo ">>> ERROR: It was not possible to find pkg conf template ${_template}"
		print_error_pfS
	fi

	if [ -n "${_staging}" -a -n "${USE_PKG_REPO_STAGING}" ]; then
		local _pkg_repo_server_devel=${PKG_REPO_SERVER_STAGING}
		local _pkg_repo_branch_devel=${PKG_REPO_BRANCH_STAGING}
		local _pkg_repo_server_release=${PKG_REPO_SERVER_STAGING}
		local _pkg_repo_branch_release=${PKG_REPO_BRANCH_STAGING}
	else
		local _pkg_repo_server_devel=${PKG_REPO_SERVER_DEVEL}
		local _pkg_repo_branch_devel=${PKG_REPO_BRANCH_DEVEL}
		local _pkg_repo_server_release=${PKG_REPO_SERVER_RELEASE}
		local _pkg_repo_branch_release=${PKG_REPO_BRANCH_RELEASE}
	fi

	# FreeSense: bake in %%OSVERSION%% and %%VERSION%% here. pfSense's repo conf leaves these
	# as placeholders for its PATCHED pkg-static to resolve at runtime; we use UPSTREAM FreeBSD
	# pkg, which fetches them LITERALLY -> the box requests FreeSense_%%OSVERSION%%_amd64-... and
	# 404s. Resolve them now to match the FreeSense-repo Makefile: a -DEVELOPMENT build uses
	# OSVERSION=master + VERSION=<prefix>devel; a release build uses the release branch for both.
	if [ -n "${_IS_RELEASE}" ]; then
		local _pkg_repo_osversion="${_pkg_repo_branch_release}"
		local _pkg_repo_version="${REPO_PATH_PREFIX}${_pkg_repo_branch_release}"
	else
		local _pkg_repo_osversion="master"
		local _pkg_repo_version="${REPO_PATH_PREFIX}${_pkg_repo_branch_devel}"
	fi

	mkdir -p $(dirname ${_target}) >/dev/null 2>&1

	sed \
		-e "s/%%ARCH%%/${_target_arch}/" \
		-e "s/%%MIRROR_TYPE%%/${_mirror_type}/" \
		-e "s/%%PKG_REPO_BRANCH_DEVEL%%/${_pkg_repo_branch_devel}/g" \
		-e "s/%%PKG_REPO_BRANCH_RELEASE%%/${_pkg_repo_branch_release}/g" \
		-e "s,%%PKG_REPO_SERVER_DEVEL%%,${_pkg_repo_server_devel},g" \
		-e "s,%%PKG_REPO_SERVER_RELEASE%%,${_pkg_repo_server_release},g" \
		-e "s,%%POUDRIERE_PORTS_NAME%%,${POUDRIERE_PORTS_NAME},g" \
		-e "s/%%PRODUCT_NAME%%/${PRODUCT_NAME}/g" \
		-e "s/%%REPO_BRANCH_PREFIX%%/${REPO_PATH_PREFIX}/g" \
		-e "s/%%SIGNATURE_TYPE%%/${_signature_type}/" \
		-e "s/%%OSVERSION%%/${_pkg_repo_osversion}/g" \
		-e "s/%%VERSION%%/${_pkg_repo_version}/g" \
		${_template} \
		> ${_target}

	local ALTABI_ARCH=$(get_altabi_arch ${_target_arch})

	ABI=$(cat ${_template%%.conf}.abi 2>/dev/null \
	    | sed -e "s/%%ARCH%%/${_target_arch}/g")
	ALTABI=$(cat ${_template%%.conf}.altabi 2>/dev/null \
	    | sed -e "s/%%ARCH%%/${ALTABI_ARCH}/g")

	if [ -n "${_pkg_conf}" -a -n "${ABI}" -a -n "${ALTABI}" ]; then
		mkdir -p $(dirname ${_pkg_conf})
		echo "ABI=${ABI}" > ${_pkg_conf}
		echo "ALTABI=${ALTABI}" >> ${_pkg_conf}
	fi
}

# FreeSense: write the public channel manifest and seed the per-branch repo confs
# into the image, so System > Update's branch selector is populated out of the box
# (even offline). FreeSense-repoc refreshes these from pkg.freesense.org at runtime.
# The box's OWN channel is marked default so an un-chosen box upgrades in-channel.
stage_repo_channels() {
	local _root="${1}"
	local _share="${_root}${PRODUCT_SHARE_DIR}"
	local _repos="${_root}/usr/local/etc/${PRODUCT_NAME}/pkg/repos"

	local _abi=$(cat ${PKG_REPO_BASE}/${PRODUCT_NAME}-repo-devel.abi 2>/dev/null \
	    | sed -e "s/%%ARCH%%/${TARGET_ARCH}/g")
	local _altabi=$(cat ${PKG_REPO_BASE}/${PRODUCT_NAME}-repo-devel.altabi 2>/dev/null \
	    | sed -e "s/%%ARCH%%/$(get_altabi_arch ${TARGET_ARCH})/g")
	[ -n "${_abi}" ] || _abi="FreeBSD:16:${TARGET_ARCH}"
	[ -n "${_altabi}" ] || _altabi="freebsd:16:x86:64"

	mkdir -p "${_share}" "${_repos}"
	# Schema 2 binds each OS channel to an independently published optional
	# package train. PRODUCT_VERSION is the only normal source of the train.
	local _server_devel="${PKG_REPO_SERVER_DEVEL#pkg+}"
	local _server_release="${PKG_REPO_SERVER_RELEASE#pkg+}"
	cat > "${_share}/repos.manifest.json" <<EOF
{
  "schema": 2,
  "channels": [
    {
      "name": "${FREESENSE_PACKAGE_TRAIN}",
      "description": "Latest stable version (${FREESENSE_PACKAGE_TRAIN}.x)",
      "server": "${_server_release}",
      "system_channel": "${FREESENSE_PACKAGE_TRAIN}",
      "package_train": "${FREESENSE_PACKAGE_TRAIN}",
      "abi": "${_abi}",
      "altabi": "${_altabi}",
      "default": true
    },
    {
      "name": "devel",
      "description": "Development version",
      "server": "${_server_devel}",
      "system_channel": "devel",
      "package_train": "${FREESENSE_PACKAGE_TRAIN}",
      "abi": "${_abi}",
      "altabi": "${_altabi}",
      "default": false
    }$(if [ -n "${FREESENSE_CANDIDATE_ID:-}" ]; then cat <<CANDIDATE
,
    {
      "name": "candidate",
      "description": "RC Preview (${FREESENSE_CANDIDATE_ID}) - not for production",
      "server": "${_server_release%/}/candidates/${FREESENSE_CANDIDATE_ID}",
      "system_server": "${_server_release%/}/candidates/${FREESENSE_CANDIDATE_ID}",
      "packages_server": "${_server_release}",
      "system_channel": "${FREESENSE_PACKAGE_TRAIN}",
      "package_train": "${FREESENSE_PACKAGE_TRAIN}",
      "abi": "${_abi}",
      "altabi": "${_altabi}",
      "default": false
    }
CANDIDATE
fi)
  ]
}
EOF

	# Separate trust paths allow independent system/package signing keys. Until
	# dedicated public fingerprints are supplied, seed both from the existing
	# FreeSense trust anchor so development images remain installable.
	for _repo_class in system packages; do
		mkdir -p "${_share}/keys/${_repo_class}/trusted" "${_share}/keys/${_repo_class}/revoked"
		cp -f "${_share}/keys/pkg/trusted/"* "${_share}/keys/${_repo_class}/trusted/" 2>/dev/null || true
		: > "${_share}/keys/${_repo_class}/revoked/.empty"
	done

	# Seed the per-branch confs offline from that manifest, reusing FreeSense-repoc
	# itself (from the ports overlay) so the conf-writing logic lives in one place.
	local _repoc="${OVERLAY_DIR:-/root/freesense-system-ports}/sysutils/${PRODUCT_NAME}-repoc/files/${PRODUCT_NAME}-repoc"
	if [ -r "${_repoc}" ]; then
		PRODUCT="${PRODUCT_NAME}" REPOS_DIR="${_repos}" SHARE_DIR="${_share}" \
		ARCH="${TARGET_ARCH}" MANIFEST_LOCAL="${_share}/repos.manifest.json" \
		    sh "${_repoc}" -l || echo ">>> WARNING: FreeSense channel seed failed"
	else
		echo ">>> WARNING: FreeSense-repoc not found at ${_repoc}; branch selector will seed at runtime only"
	fi

	# Pin the box's OWN channel as default (devel images track devel; release images
	# track the release branch), overriding the manifest's global default.
	rm -f "${_repos}/${PRODUCT_NAME}-repo-"*.default 2>/dev/null
	if [ -n "${FREESENSE_CANDIDATE_ID:-}" ]; then
		: > "${_repos}/${PRODUCT_NAME}-repo-candidate.default"
	elif [ -n "${_IS_RELEASE}" ]; then
		: > "${_repos}/${PRODUCT_NAME}-repo-${FREESENSE_PACKAGE_TRAIN}.default"
	else
		: > "${_repos}/${PRODUCT_NAME}-repo-devel.default"
	fi
}

depend_check() {
	for _pkg in ${BUILDER_PKG_DEPENDENCIES}; do
		if ! pkg info -e ${_pkg}; then
			echo "Missing dependency (${_pkg})."
			print_error_pfS
		fi
	done
}

# This routine ensures any ports / binaries that the builder
# system needs are on disk and ready for execution.
builder_setup() {
	# If Product-builder is already installed, just leave
	if pkg info -e -q ${PRODUCT_NAME}-builder; then
		return
	fi

	if [ ! -f ${PKG_REPO_PATH} ]; then
		[ -d $(dirname ${PKG_REPO_PATH}) ] \
			|| mkdir -p $(dirname ${PKG_REPO_PATH})

		update_freebsd_sources

		local _arch=$(uname -m)
		setup_pkg_repo \
			${PKG_REPO_BUILD} \
			${PKG_REPO_PATH} \
			${_arch} \
			${_arch} \
			"staging"

		# Use fingerprint keys from repo
		sed -i '' -e "/fingerprints:/ s,\"/,\"${BUILDER_ROOT}/src/," \
			${PKG_REPO_PATH}
	fi

	pkg install ${PRODUCT_NAME}-builder
}

# Updates FreeBSD sources
update_freebsd_sources() {
	if [ "${1}" = "full" ]; then
		local _full=1
		local _clone_params=""
	else
		local _full=0
		local _clone_params="--depth 1 --single-branch"
	fi

	if [ -n "${NO_BUILDWORLD}" -a -n "${NO_BUILDKERNEL}" ]; then
		echo ">>> NO_BUILDWORLD and NO_BUILDKERNEL set, skipping update of freebsd sources" | tee -a ${LOGFILE}
		return
	fi

	if [ -n "${FREEBSD_SRC_PATCHES_DIR}" -a -f "${FREEBSD_SRC_PATCHES_DIR}/manifest.env" ]; then
		# FreeSense patch-based flow: stock freebsd/freebsd-src @ a pinned commit
		# + our small change-set (FreeSense-org/freesense-freebsd-patches), instead
		# of a full fork. Incremental like git_checkout.sh: reset+clean the existing
		# tree, (re-)fetch the pin if missing, apply the series. Re-inits the tree if
		# it currently points at a different remote (e.g. the old fork).
		. ${FREEBSD_SRC_PATCHES_DIR}/manifest.env
		echo ">>> Obtaining stock FreeBSD sources (${UPSTREAM_REF}) + FreeSense change-set..."
		if [ -n "${FREESENSE_EPOCH_OFFLINE:-}" ]; then
			_epoch_src="${FREESENSE_EPOCH_SOURCE_ARCHIVE:-/root/epoch/freebsd-src.tar.zst}"
			[ -s "${_epoch_src}" ] || { echo ">>> ERROR: missing epoch FreeBSD source ${_epoch_src}"; print_error_pfS; }
			rm -rf "${FREEBSD_SRC_DIR}"
			mkdir -p "${FREEBSD_SRC_DIR}"
			tar --zstd --strip-components 1 -xf "${_epoch_src}" -C "${FREEBSD_SRC_DIR}"
			[ "$(git -C "${FREEBSD_SRC_DIR}" rev-parse HEAD 2>/dev/null)" = "${UPSTREAM_REF}" ] \
				|| { echo ">>> ERROR: epoch source does not match ${UPSTREAM_REF}"; print_error_pfS; }
		elif [ -d "${FREEBSD_SRC_DIR}/.git" ] && \
		   [ "$(git -C ${FREEBSD_SRC_DIR} config --get remote.origin.url 2>/dev/null)" = "${UPSTREAM_URL}" ]; then
			git -C ${FREEBSD_SRC_DIR} reset -q --hard
			git -C ${FREEBSD_SRC_DIR} clean -qfd
		else
			rm -rf ${FREEBSD_SRC_DIR}
			mkdir -p ${FREEBSD_SRC_DIR}
			git -C ${FREEBSD_SRC_DIR} init -q
			git -C ${FREEBSD_SRC_DIR} remote add origin ${UPSTREAM_URL}
		fi
		if [ -z "${FREESENSE_EPOCH_OFFLINE:-}" ] && ! git -C ${FREEBSD_SRC_DIR} cat-file -e "${UPSTREAM_REF}^{commit}" 2>/dev/null; then
			git -C ${FREEBSD_SRC_DIR} fetch -q --depth 1 origin ${UPSTREAM_REF} \
				|| git -C ${FREEBSD_SRC_DIR} fetch -q origin ${UPSTREAM_REF}
		fi
		git -C ${FREEBSD_SRC_DIR} checkout -q -f ${UPSTREAM_REF}
		git -C ${FREEBSD_SRC_DIR} clean -qfd
		if [ ! -d "${FREEBSD_SRC_DIR}/.git" ]; then
			echo ">>> ERROR: could not obtain stock FreeBSD src @ ${UPSTREAM_REF}"
			print_error_pfS
		fi
		sh ${FREEBSD_SRC_PATCHES_DIR}/apply.sh ${FREEBSD_SRC_DIR} || print_error_pfS

		# NO_CLEAN safeguard: if the freebsd source identity (pinned commit + the
		# applied patch set) changed since the last build, force a one-time clean of
		# the FreeBSD obj so an incremental world can't reuse stale objects. Unchanged
		# identity => keep the obj (the whole point of NO_CLEAN_FREEBSD_OBJ).
		if [ -n "${NO_CLEAN_FREEBSD_OBJ}" ]; then
			local _phash=$(cat ${FREEBSD_SRC_PATCHES_DIR}/patches/*.patch 2>/dev/null | sha256 -q 2>/dev/null)
			local _fbsd_id="${UPSTREAM_REF} ${_phash}"
			local _stamp="${FREEBSD_SRC_DIR}/../.freesense-fbsd-src-id"
			if [ ! -f "${_stamp}" ] || [ "$(cat ${_stamp} 2>/dev/null)" != "${_fbsd_id}" ]; then
				echo ">>> FreeBSD source identity changed (pin/patches) — forcing a clean obj this build."
				local _objtree=$(make -C ${FREEBSD_SRC_DIR} -V OBJTREE 2>/dev/null)
				if [ -n "${_objtree}" -a -d "${_objtree}" ]; then
					chflags -R noschg ${_objtree} 2>/dev/null
					rm -rf ${_objtree}/*
				fi
				[ -d "${KERNEL_BUILD_PATH}" ] && rm -rf ${KERNEL_BUILD_PATH}/*
				echo "${_fbsd_id}" > "${_stamp}"
			else
				echo ">>> FreeBSD source identity unchanged — reusing obj (NO_CLEAN_FREEBSD_OBJ)."
			fi
		fi
	else
		echo ">>> Obtaining FreeBSD sources (${FREEBSD_BRANCH})..."
		${BUILDER_SCRIPTS}/git_checkout.sh \
			-r ${FREEBSD_REPO_BASE} \
			-d ${FREEBSD_SRC_DIR} \
			-b ${FREEBSD_BRANCH}

		if [ $? -ne 0 -o ! -d "${FREEBSD_SRC_DIR}/.git" ]; then
			echo ">>> ERROR: It was not possible to clone FreeBSD src repo"
			print_error_pfS
		fi
	fi
	. ${BUILDER_TOOLS}/ci/freesense-confrename.sh

	if [ -n "${GIT_FREEBSD_COSHA1}" ]; then
		echo -n ">>> Checking out desired commit (${GIT_FREEBSD_COSHA1})... "
		( git -C  ${FREEBSD_SRC_DIR} checkout ${GIT_FREEBSD_COSHA1} ) 2>&1 | \
			grep -C3 -i -E 'error|fatal'
		echo "Done!"
	fi

	if [ "${PRODUCT_NAME}" = "pfSense" -a -n "${GNID_REPO_BASE}" ]; then
		echo ">>> Obtaining gnid sources..."
		${BUILDER_SCRIPTS}/git_checkout.sh \
			-r ${GNID_REPO_BASE} \
			-d ${GNID_SRC_DIR} \
			-b ${GNID_BRANCH}
	fi
}

pkg_chroot() {
	local _root="${1}"
	shift

	if [ $# -eq 0 ]; then
		return -1
	fi

	if [ -z "${_root}" -o "${_root}" = "/" -o ! -d "${_root}" ]; then
		return -1
	fi

	mkdir -p \
		${SCRATCHDIR}/pkg_cache \
		${_root}/var/cache/pkg \
		${_root}/dev

	/sbin/mount -t nullfs ${SCRATCHDIR}/pkg_cache ${_root}/var/cache/pkg
	/sbin/mount -t devfs devfs ${_root}/dev
	cp -f /etc/resolv.conf ${_root}/etc/resolv.conf
	touch ${BUILDER_LOGS}/install_pkg_install_ports.txt
	local _params=""
	if [ -f "${_root}/tmp/pkg/pkg-repos/repo.conf" ]; then
		_params="--repo-conf-dir /tmp/pkg/pkg-repos "
	fi
	if [ -f "${_root}/tmp/pkg/pkg.conf" ]; then
		_params="${_params} --config /tmp/pkg/pkg.conf "
	fi
	# -e: return the CHILD's exit status. Without it script(1) exits 0 for its own
	# success and every pkg failure here reported as success — run 29148436302's
	# kernel never landed in the chroot and no guard fired until image assembly.
	script -aeq ${BUILDER_LOGS}/install_pkg_install_ports.txt \
		chroot ${_root} pkg ${_params}$@ >/dev/null 2>&1
	local result=$?
	rm -f ${_root}/etc/resolv.conf
	/sbin/umount -f ${_root}/dev
	/sbin/umount -f ${_root}/var/cache/pkg

	if [ ${result} -ne 0 ]; then
		echo ">>> pkg_chroot: 'pkg $@' in ${_root} FAILED (rc=${result}) — last log lines:"
		tail -30 ${BUILDER_LOGS}/install_pkg_install_ports.txt 2>/dev/null | tr -cd '[:print:]\n'
	fi

	return $result
}


pkg_chroot_add() {
	if [ -z "${1}" -o -z "${2}" ]; then
		return 1
	fi

	local _target="${1}"
	local _pkg="$(get_pkg_name ${2}).pkg"
	local _flags="${3:-}"

	if [ ! -d "${_target}" ]; then
		echo ">>> ERROR: Target dir ${_target} not found"
		print_error_pfS
	fi

	if [ ! -f ${CORE_PKG_ALL_PATH}/${_pkg} ]; then
		echo ">>> ERROR: Package ${_pkg} not found"
		print_error_pfS
	fi

	cp ${CORE_PKG_ALL_PATH}/${_pkg} ${_target}
	pkg_chroot ${_target} add ${_flags} /${_pkg}
	local _rc=$?
	rm -f ${_target}/${_pkg}
	return ${_rc}
}

pkg_bootstrap() {
	local _root=${1:-"${STAGE_CHROOT_DIR}"}

	setup_pkg_repo \
		${PKG_REPO_BUILD} \
		${_root}${PKG_REPO_PATH} \
		${TARGET} \
		${TARGET_ARCH} \
		"staging"

	pkg_chroot ${_root} bootstrap -f
}

# This routine assists with installing various
# freebsd ports files into the pfsense-fs staging
# area.
install_pkg_install_ports() {
	local MAIN_PKG="${1}"

	if [ -z "${MAIN_PKG}" ]; then
		MAIN_PKG=${PRODUCT_NAME}
	fi

	echo ">>> Installing pkg repository in chroot (${STAGE_CHROOT_DIR})..."

	[ -d ${STAGE_CHROOT_DIR}/var/cache/pkg ] || \
		mkdir -p ${STAGE_CHROOT_DIR}/var/cache/pkg

	[ -d ${SCRATCHDIR}/pkg_cache ] || \
		mkdir -p ${SCRATCHDIR}/pkg_cache

	mkdir -p ${STAGE_CHROOT_DIR}/usr/local/sbin # bootstrap pkg-static into chroot
	[ -x ${STAGE_CHROOT_DIR}/usr/local/sbin/pkg ] || cp /usr/local/sbin/pkg-static ${STAGE_CHROOT_DIR}/usr/local/sbin/pkg
	. ${BUILDER_TOOLS}/ci/freesense-localrepo.sh
	echo -n ">>> Installing built ports (packages) in chroot (${STAGE_CHROOT_DIR})... "
	# First mark all packages as automatically installed
	pkg_chroot ${STAGE_CHROOT_DIR} set -A 1 -a
	# Install all necessary packages
	if ! pkg_chroot ${STAGE_CHROOT_DIR} install ${MAIN_PKG} ${custom_package_list}; then
		echo "Failed!"
		print_error_pfS
	fi
	# Make sure required packages are set as non-automatic
	pkg_chroot ${STAGE_CHROOT_DIR} set -A 0 pkg ${MAIN_PKG} ${custom_package_list}
	# pkg and MAIN_PKG are vital
	pkg_chroot ${STAGE_CHROOT_DIR} set -y -v 1 pkg ${MAIN_PKG}
	# Remove unnecessary packages
	pkg_chroot ${STAGE_CHROOT_DIR} autoremove
	echo "Done!"
}

staginareas_clean_each_run() {
	echo -n ">>> Cleaning build directories: "
	if [ -d "${FINAL_CHROOT_DIR}" ]; then
		BASENAME=$(basename ${FINAL_CHROOT_DIR})
		echo -n "$BASENAME "
		chflags -R noschg ${FINAL_CHROOT_DIR} 2>&1 >/dev/null
		rm -rf ${FINAL_CHROOT_DIR}/* 2>/dev/null
	fi
	echo "Done!"
}

# Imported from FreeSBIE
buildkernel() {
	local _kernconf=${1:-${KERNCONF}}

	if [ -n "${NO_BUILDKERNEL}" ]; then
		echo ">>> NO_BUILDKERNEL set, skipping build" | tee -a ${LOGFILE}
		return
	fi

	if [ -z "${_kernconf}" ]; then
		echo ">>> ERROR: No kernel configuration defined probably this is not what you want! STOPPING!" | tee -a ${LOGFILE}
		print_error_pfS
	fi

	local _old_kernconf=${KERNCONF}
	export KERNCONF=${_kernconf}

	# FreeSense LEVER 1 (pkgbase world, now default): the classic path builds the kernel
	# toolchain as a side effect of buildworld. With pkgbase world there is no buildworld,
	# so the kernel compiler/headers are missing — run kernel-toolchain first (~30-45m, vs
	# ~4h for a full world). Runs unless the classic-world escape hatch is set. Idempotent +
	# guarded so it runs only once (the obj toolchain persists across the per-kernel loop).
	if [ "${FREESENSE_CLASSIC_WORLD:-0}" != "1" ] && [ -z "${_FREESENSE_KTOOLCHAIN_DONE:-}" ]; then
		echo ">>> $(LC_ALL=C date) - pkgbase: building kernel-toolchain (no buildworld)..." | tee -a ${LOGFILE}
		# CRITICAL: use the SAME obj dir build_freebsd.sh (called by this function with
		# no -o) will use, so the toolchain and buildkernel share one obj tree.
		# build_freebsd.sh defaults objdir=${srcdir}/../obj -> MAKEOBJDIRPREFIX=that.
		local _ktc_obj="${FREEBSD_SRC_DIR}/../obj"
		local _ncpu=$(sysctl -qn hw.ncpu 2>/dev/null || echo 2)
		script -aq $LOGFILE env MAKEOBJDIRPREFIX="${_ktc_obj}" \
			make -C ${FREEBSD_SRC_DIR} -j$((_ncpu*2)) kernel-toolchain \
			|| print_error_pfS
		export _FREESENSE_KTOOLCHAIN_DONE=1
		echo ">>> $(LC_ALL=C date) - pkgbase: kernel-toolchain ready." | tee -a ${LOGFILE}
	fi

	echo ">>> $(LC_ALL=C date) - Starting build kernel for ${TARGET} architecture..." | tee -a ${LOGFILE}
	script -aq $LOGFILE ${BUILDER_SCRIPTS}/build_freebsd.sh -W -s ${FREEBSD_SRC_DIR} \
		|| print_error_pfS
	echo ">>> $(LC_ALL=C date) - Finished build kernel for ${TARGET} architecture..." | tee -a ${LOGFILE}

	export KERNCONF=${_old_kernconf}
}

# Imported from FreeSBIE
installkernel() {
	local _destdir=${1:-${KERNEL_DESTDIR}}
	local _kernconf=${2:-${KERNCONF}}

	if [ -z "${_kernconf}" ]; then
		echo ">>> ERROR: No kernel configuration defined probably this is not what you want! STOPPING!" | tee -a ${LOGFILE}
		print_error_pfS
	fi

	local _old_kernconf=${KERNCONF}
	export KERNCONF=${_kernconf}

	mkdir -p ${STAGE_CHROOT_DIR}/boot
	echo ">>> Installing kernel (${_kernconf}) for ${TARGET} architecture..." | tee -a ${LOGFILE}
	script -aq $LOGFILE ${BUILDER_SCRIPTS}/install_freebsd.sh -W -D -z \
		-s ${FREEBSD_SRC_DIR} \
		-d ${_destdir} \
		|| print_error_pfS

	export KERNCONF=${_old_kernconf}
}

# Launch is ran first to setup a few variables that we need
# Imported from FreeSBIE
launch() {
	if [ "$(id -u)" != "0" ]; then
		echo "Sorry, this must be done as root."
	fi

	echo ">>> Operation $0 has started at $(date)"
}

finish() {
	echo ">>> Operation $0 has ended at $(date)"
}

pkg_repo_rsync() {
	local _repo_path_param="${1}"
	local _ignore_final_rsync="${2}"
	local _aws_sync_cmd="aws s3 sync --quiet --exclude '.real*/*' --exclude '.latest/*'"

	if [ -z "${_repo_path_param}" -o ! -d "${_repo_path_param}" ]; then
		return
	fi

	if [ -n "${SKIP_FINAL_RSYNC}" ]; then
		_ignore_final_rsync="1"
	fi

	# Sanitize path
	_repo_path=$(realpath ${_repo_path_param})

	local _repo_dir=$(dirname ${_repo_path})
	local _repo_base=$(basename ${_repo_path})

	# Add ./ it's an rsync trick to make it chdir to directory before sending it
	_repo_path="${_repo_dir}/./${_repo_base}"

	if [ -z "${LOGFILE}" ]; then
		local _logfile="/dev/null"
	else
		local _logfile="${LOGFILE}"
	fi

	if [ -n "${PKG_REPO_SIGNING_COMMAND}" -a -z "${DO_NOT_SIGN_PKG_REPO}" ]; then
		# Detect poudriere directory structure
		if [ -L "${_repo_path}/.latest" ]; then
			local _real_repo_path=$(readlink -f ${_repo_path}/.latest)
		else
			local _real_repo_path=${_repo_path}
		fi

		echo -n ">>> Signing repository... " | tee -a ${_logfile}
		############ ATTENTION ##############
		#
		# For some reason pkg-repo fail without / in the end of directory name
		# so removing it will break command
		#
		# https://github.com/freebsd/pkg/issues/1364
		#
		if script -aq ${_logfile} pkg repo ${_real_repo_path}/ \
		    signing_command: ${PKG_REPO_SIGNING_COMMAND} >/dev/null 2>&1; then
			echo "Done!" | tee -a ${_logfile}
		else
			echo "Failed!" | tee -a ${_logfile}
			echo ">>> ERROR: An error occurred trying to sign repo"
			print_error_pfS
		fi

		local _pkgfile="${_repo_path}/Latest/pkg.pkg"
		if [ -e ${_pkgfile} ]; then
			echo -n ">>> Signing Latest/pkg.pkg for bootstrapping... " | tee -a ${_logfile}

			if sha256 -q ${_pkgfile} | ${PKG_REPO_SIGNING_COMMAND} \
			    > ${_pkgfile}.sig 2>/dev/null; then
				# XXX Temporary workaround to create link to pkg sig
				[ -e ${_repo_path}/Latest/pkg.txz ] && \
					ln -sf pkg.pkg.sig ${_repo_path}/Latest/pkg.txz.sig
				echo "Done!" | tee -a ${_logfile}
			else
				echo "Failed!" | tee -a ${_logfile}
				echo ">>> ERROR: An error occurred trying to sign Latest/pkg.txz"
				print_error_pfS
			fi
		fi
	fi

	if [ -z "${UPLOAD}" ]; then
		return
	fi

	local _pkg_rsync_site
	for _pkg_rsync_site in ${PKG_RSYNC_HOSTS}; do
		eval _pkg_rsync_hostname=\$PKG_RSYNC_HOSTNAME_$_pkg_rsync_site
		if [ -z "${_pkg_rsync_hostname}" ]; then
			echo "PKG_RSYNC_HOSTNAME_$_pkg_rsync_site is empty, skipping.."
			continue
		fi
		# Make sure destination directory exist
		ssh -o StrictHostKeyChecking=no -p ${PKG_RSYNC_SSH_PORT} \
			${PKG_RSYNC_USERNAME}@${_pkg_rsync_hostname} \
			"mkdir -p ${PKG_RSYNC_DESTDIR}"

		echo -n ">>> Sending updated repository to ${_pkg_rsync_hostname}... " | tee -a ${_logfile}
		if script -aq ${_logfile} rsync -Have "ssh -o StrictHostKeyChecking=no -p ${PKG_RSYNC_SSH_PORT}" \
			--timeout=60 --delete-delay ${_repo_path} \
			${PKG_RSYNC_USERNAME}@${_pkg_rsync_hostname}:${PKG_RSYNC_DESTDIR} >> ${BUILDER_LOGS}/rsync.log 2>&1
		then
			echo "Done!" | tee -a ${_logfile}
		else
			echo "Failed!" | tee -a ${_logfile}
			echo ">>> ERROR: An error occurred sending repo to remote hostname"
			print_error_pfS
		fi

		if [ -z "${USE_PKG_REPO_STAGING}" -o -n "${_ignore_final_rsync}" ]; then
			return
		fi

		if [ -n "${_IS_RELEASE}" -o "${_repo_path_param}" = "${CORE_PKG_PATH}" ]; then
			local _pkg_final_rsync_hostname
			eval _pkg_final_rsync_hostname=\$PKG_FINAL_RSYNC_HOSTNAME_$_pkg_rsync_site
			if [ -z "${_pkg_final_rsync_hostname}" ]; then
				_pkg_final_rsync_hostname="$_pkg_rsync_hostname"
			fi

			# Send .real* directories first to prevent having a broken repo while transfer happens
			local _cmd="rsync -Have \"ssh -o StrictHostKeyChecking=no -p ${PKG_FINAL_RSYNC_SSH_PORT}\" \
				--timeout=60 ${PKG_RSYNC_DESTDIR}/./${_repo_base%%-core}* \
				--include=\"/*\" --include=\"*/.real*\" --include=\"*/.real*/***\" \
				--exclude=\"*\" \
				${PKG_FINAL_RSYNC_USERNAME}@${_pkg_final_rsync_hostname}:${PKG_FINAL_RSYNC_DESTDIR}"

			echo -n ">>> Sending updated packages to ${_pkg_final_rsync_hostname}... " | tee -a ${_logfile}
			if script -aq ${_logfile} ssh -o StrictHostKeyChecking=no -p ${PKG_RSYNC_SSH_PORT} \
				${PKG_RSYNC_USERNAME}@${_pkg_rsync_hostname} ${_cmd} >> ${BUILDER_LOGS}/rsync.log 2>&1; then
				echo "Done!" | tee -a ${_logfile}
			else
				echo "Failed!" | tee -a ${_logfile}
				echo ">>> ERROR: An error occurred sending repo to final hostname"
				print_error_pfS
			fi

			_cmd="rsync -Have \"ssh -o StrictHostKeyChecking=no -p ${PKG_FINAL_RSYNC_SSH_PORT}\" \
				--timeout=60 --delete-delay ${PKG_RSYNC_DESTDIR}/./${_repo_base%%-core}* \
				${PKG_FINAL_RSYNC_USERNAME}@${_pkg_final_rsync_hostname}:${PKG_FINAL_RSYNC_DESTDIR}"

			echo -n ">>> Sending updated repositories metadata to ${_pkg_final_rsync_hostname}... " | tee -a ${_logfile}
			if script -aq ${_logfile} ssh -o StrictHostKeyChecking=no -p ${PKG_RSYNC_SSH_PORT} \
				${PKG_RSYNC_USERNAME}@${_pkg_rsync_hostname} ${_cmd} >> ${BUILDER_LOGS}/rsync.log 2>&1; then
				echo "Done!" | tee -a ${_logfile}
			else
				echo "Failed!" | tee -a ${_logfile}
				echo ">>> ERROR: An error occurred sending repo to final hostname"
				print_error_pfS
			fi

			if [ -z "${PKG_FINAL_S3_PATH}" ]; then
				continue
			fi

			local _repos=$(ssh -o StrictHostKeyChecking=no -p ${PKG_FINAL_RSYNC_SSH_PORT} \
			    ${PKG_FINAL_RSYNC_USERNAME}@${_pkg_final_rsync_hostname} \
			    "ls -1d ${PKG_FINAL_RSYNC_DESTDIR}/${_repo_base%%-core}*")
			for _repo in ${_repos}; do
				echo -n ">>> Sending updated packages to AWS ${PKG_FINAL_S3_PATH}... " | tee -a ${_logfile}
				if script -aq ${_logfile} ssh -o StrictHostKeyChecking=no -p ${PKG_FINAL_RSYNC_SSH_PORT} \
				    ${PKG_FINAL_RSYNC_USERNAME}@${_pkg_final_rsync_hostname} \
				    "${_aws_sync_cmd} ${_repo} ${PKG_FINAL_S3_PATH}/$(basename ${_repo})"; then
					echo "Done!" | tee -a ${_logfile}
				else
					echo "Failed!" | tee -a ${_logfile}
					echo ">>> ERROR: An error occurred sending files to AWS S3"
					print_error_pfS
				fi
				echo -n ">>> Cleaning up packages at AWS ${PKG_FINAL_S3_PATH}... " | tee -a ${_logfile}
				if script -aq ${_logfile} ssh -o StrictHostKeyChecking=no -p ${PKG_FINAL_RSYNC_SSH_PORT} \
				    ${PKG_FINAL_RSYNC_USERNAME}@${_pkg_final_rsync_hostname} \
				    "${_aws_sync_cmd} --delete ${_repo} ${PKG_FINAL_S3_PATH}/$(basename ${_repo})"; then
					echo "Done!" | tee -a ${_logfile}
				else
					echo "Failed!" | tee -a ${_logfile}
					echo ">>> ERROR: An error occurred sending files to AWS S3"
					print_error_pfS
				fi
			done
		fi
	done
}

poudriere_possible_archs() {
	local _arch=$(uname -m)
	local _archs=""

	# If host is amd64, we'll create both repos, and if possible armv7
	if [ "${_arch}" = "amd64" ]; then
		_archs="amd64.amd64"

		if [ -f /usr/local/bin/qemu-arm-static ]; then
			# Make sure binmiscctl is ok
			/usr/local/etc/rc.d/qemu_user_static forcestart >/dev/null 2>&1

			if binmiscctl lookup armv7 >/dev/null 2>&1; then
				_archs="${_archs} arm.armv7"
			fi
		fi
	fi

	if [ -n "${ARCH_LIST}" ]; then
		local _found=0
		for _desired_arch in ${ARCH_LIST}; do
			_found=0
			for _possible_arch in ${_archs}; do
				if [ "${_desired_arch}" = "${_possible_arch}" ]; then
					_found=1
					break
				fi
			done
			if [ ${_found} -eq 0 ]; then
				echo ">>> ERROR: Impossible to build for arch: ${_desired_arch}"
				print_error_pfS
			fi
		done
		_archs="${ARCH_LIST}"
	fi

	echo ${_archs}
}

poudriere_jail_name() {
	local _jail_arch="${1}"

	if [ -z "${_jail_arch}" ]; then
		return 1
	fi

	# Remove arch
	echo "${PRODUCT_NAME}_${POUDRIERE_BRANCH}_${_jail_arch##*.}"
}

poudriere_rename_ports() {
	# The selected purpose-built overlay is FreeSense-named in Git,
	# so this function is normally a no-op. It is kept as a SAFETY NET: if a
	# pfSense-* port dir is ever re-imported from upstream, it still gets renamed
	# here before the bulk build.
	if [ "${PRODUCT_NAME}" = "pfSense" ]; then
		return;
	fi

	LOGFILE=${BUILDER_LOGS}/poudriere.log

	local _ports_dir="/usr/local/poudriere/ports/${POUDRIERE_PORTS_NAME}"

	echo -n ">>> Renaming product ports on ${POUDRIERE_PORTS_NAME}... " | tee -a ${LOGFILE}
	for d in $(find ${_ports_dir} -depth 2 -type d -name '*pfSense*'); do
		local _pdir=$(dirname ${d})
		local _pname=$(echo $(basename ${d}) | sed "s,pfSense,${PRODUCT_NAME},")
		local _plist=""
		local _pdescr=""

		if [ -e ${_pdir}/${_pname} ]; then
			rm -rf ${_pdir}/${_pname}
		fi

		cp -r ${d} ${_pdir}/${_pname}

		if [ -f ${_pdir}/${_pname}/pkg-plist ]; then
			_plist=${_pdir}/${_pname}/pkg-plist
		fi

		if [ -f ${_pdir}/${_pname}/pkg-descr ]; then
			_pdescr=${_pdir}/${_pname}/pkg-descr
		fi

		sed -i '' -e "s,pfSense,${PRODUCT_NAME},g" \
			  -e "s,https://www.pfsense.org,${PRODUCT_URL},g" \
			  -e "/^MAINTAINER=/ s,^.*$,MAINTAINER=	${PRODUCT_EMAIL}," \
			${_pdir}/${_pname}/Makefile ${_pdescr} ${_plist}

		# PHP module is special
		if echo "${_pname}" | grep -q "^php[0-9]*-${PRODUCT_NAME}-module"; then
			local _product_capital=$(echo ${PRODUCT_NAME} | tr '[a-z]' '[A-Z]')
			sed -i '' -e "s,PHP_PFSENSE,PHP_${_product_capital},g" \
				  -e "s,PFSENSE_SHARED_LIBADD,${_product_capital}_SHARED_LIBADD,g" \
				  -e "s,pfSense,${PRODUCT_NAME},g" \
				  -e "s,pfSense.c,${PRODUCT_NAME}\.c,g" \
				${_pdir}/${_pname}/files/config.m4

			sed -i '' -e "s,COMPILE_DL_PFSENSE,COMPILE_DL_${_product_capital}," \
				  -e "s,pfSense_module_entry,${PRODUCT_NAME}_module_entry,g" \
				  -e "s,php_pfSense.h,php_${PRODUCT_NAME}\.h,g" \
				  -e "/ZEND_GET_MODULE/ s,pfSense,${PRODUCT_NAME}," \
				  -e "/PHP_PFSENSE_WORLD_EXTNAME/ s,pfSense,${PRODUCT_NAME}," \
				${_pdir}/${_pname}/files/pfSense.c \
				${_pdir}/${_pname}/files/php_pfSense.h 2>/dev/null

			# FreeSense: blanket-rewrite EVERY pfSense* token in all C sources/headers so
			# #include lines (pfSense_arginfo.h, pfSense_private.h, etc.) match the files
			# that get renamed below. The hardcoded list above misses some headers and
			# references a dummynet.c that newer port versions don't ship.
			for _src in $(find ${_pdir}/${_pname}/files \( -name '*.c' -o -name '*.h' \) 2>/dev/null); do
				sed -i '' -e "s,pfSense,${PRODUCT_NAME},g" "${_src}"
			done
		fi

		if [ -d ${_pdir}/${_pname}/files ]; then
			for fd in $(find ${_pdir}/${_pname}/files -name '*pfSense*'); do
				local _fddir=$(dirname ${fd})
				local _fdname=$(echo $(basename ${fd}) | sed "s,pfSense,${PRODUCT_NAME},")

				mv ${fd} ${_fddir}/${_fdname}
			done
		fi

		# FreeSense: the package metadata info.xml uses the all-lowercase upstream
		# root element <pfsensepkgs>, which the case-sensitive pfSense->FreeSense
		# renames above never match. Rewrite it (open + close tag) to
		# <freesensepkgs> so the GUI — which parses freesensepkgs — can register
		# the package's menus, services and tabs. The package <name> and other
		# fields contain no "pfsensepkgs" token, so they are left untouched.
		_lcprod=$(echo ${PRODUCT_NAME} | tr 'A-Z' 'a-z')
		for _info in $(find ${_pdir}/${_pname}/files -name 'info.xml' 2>/dev/null); do
			sed -i '' -e "s,pfsensepkgs,${_lcprod}pkgs,g" "${_info}"
		done
	done
	echo "Done!" | tee -a ${LOGFILE}
}

# FreeSense lean-overlay: resolve the FreeBSD binary ports repo name on the build VM.
# FreeBSD 15/16 (pkgbase era) split the repo into FreeBSD-base (world) + FreeBSD-ports (apps);
# older FreeBSD called the single repo FreeBSD. This is almost certainly why an earlier
# `pkg rquery -r FreeBSD` returned empty. Print the name; empty => caller uses an unnamed query.
freesense_freebsd_ports_repo() {
	local _r
	for _r in FreeBSD-ports FreeBSD; do
		if [ -n "$(pkg rquery -r "${_r}" '%v' pkg 2>/dev/null)" ]; then
			echo "${_r}"; return 0
		fi
	done
	return 0
}

# FreeSense lean-overlay: pin the WHOLE poudriere ports tree to the exact freebsd-ports commit
# FreeBSD's currently-published binaries were built from (every FreeBSD pkg carries the
# ports_top_git_hash annotation). This makes stock ports' versions match FreeBSD's binaries so
# freesense-lean-seed.sh can drop those binaries in and poudriere REUSES them (build only
# custom). MUST run BEFORE the overlay cp -f: a whole-tree checkout resets tracked files (the
# vendored FreeSense-* dirs are untracked and survive; the partial open-vm-tools Makefile patch
# is re-applied by the overlay after). Best-effort: any failure leaves the tree at branch HEAD
# and everything just builds from source (the old behaviour).
poudriere_pin_ports_tree() {
	local _tree="/usr/local/poudriere/ports/${POUDRIERE_PORTS_NAME}"
	local _repo _commit _chan _curr _pinsrc _pf _snapshot_id _checkout_ok
	[ -d "${_tree}/.git" ] || { echo ">>> lean-pin: ${_tree} is not a git checkout; leaving at HEAD"; return 0; }
	# Offline epochs carry the exact ports commit and complete git object in the
	# verified ports archive. Never contact origin while the build VM is isolated.
	if [ -n "${FREESENSE_EPOCH_PORTS_SHA:-}" ]; then
		_commit="${FREESENSE_EPOCH_PORTS_SHA}"
		_pinsrc="build epoch ${FREESENSE_EPOCH_ID:-producer}"
	elif [ -n "${FREESENSE_EPOCH_OFFLINE:-}" ]; then
		echo ">>> ERROR: offline epoch has no ports SHA"
		print_error_pfS
	fi
	# FROZEN STOCK: prefer the ports_top_git_hash recorded in this week's immutable R2 stock bank
	# (keyed by the base snapshot rev FREESENSE_REV). Deterministic + identical across every batch
	# and publish, so the tree is pinned to the SAME commit the frozen binaries were built from ->
	# poudriere reuses them with zero "new version" drift. Falls back to the live pkg.freebsd.org
	# query only when the bank isn't populated yet (the very first build of a new rev).
	if [ -z "${_commit}" ] && [ -n "${FREESENSE_REV:-}" ] && command -v rclone >/dev/null 2>&1; then
		_chan="${FREESENSE_CHANNEL:-main}"
		_snapshot_id="${FREESENSE_SNAPSHOT_ID:-${FREESENSE_REV}}"
		# Deterministic + identical across every build. Try the exact rev, then the channel's
		# 'current' pointer (in case the seed rev and the banked rev drift by a snapshot).
		_commit=$(rclone cat --s3-no-check-bucket "R2:freesense-pkg/ports-cache/stock/${_chan}/${_snapshot_id}/ports_top_git_hash" 2>/dev/null | tr -dc '0-9a-f')
		if [ -z "${_commit}" ] && [ -z "${FREESENSE_SNAPSHOT:-}" ]; then
			_curr=$(rclone cat --s3-no-check-bucket "R2:freesense-pkg/ports-cache/stock/${_chan}/current" 2>/dev/null | tr -d '\r\n')
			if [ -n "${_curr}" ] && [ "${_curr}" != "${_snapshot_id}" ]; then
				_commit=$(rclone cat --s3-no-check-bucket "R2:freesense-pkg/ports-cache/stock/${_chan}/${_curr}/ports_top_git_hash" 2>/dev/null | tr -dc '0-9a-f')
				[ -n "${_commit}" ] && echo ">>> lean-pin: snapshot ${_snapshot_id} not banked; using 'current'=${_curr}" | tee -a ${LOGFILE}
			fi
		fi
		if [ -n "${_commit}" ]; then
			_pinsrc="frozen-bank stock/${_chan}"
			echo ">>> lean-pin: using frozen ports_top_git_hash from ${_pinsrc} = ${_commit}" | tee -a ${LOGFILE}
		fi
	fi
	if [ -z "${_commit}" ]; then
		pkg update -f >/dev/null 2>&1 || true
		_repo=$(freesense_freebsd_ports_repo)
		# AUTHORITATIVE: read ports_top_git_hash from a fetched package FILE's manifest — this is the
		# exact freebsd-ports commit FreeBSD BUILT the binaries from. `pkg rquery` reads the CATALOG,
		# which routinely OMITS ports_top_git_hash (empirically empty), so the old resolver fell back
		# to the tree's git HEAD — which is NEWER than FreeBSD's build commit -> the pinned tree wanted
		# newer versions than the frozen binaries -> mass "new version" rebuilds. Fetch one small pkg
		# and read the annotation off the file instead. (`pkg query -F` reads the .pkg, not the catalog.)
		rm -rf /tmp/pinprobe 2>/dev/null || true
		if pkg fetch -y ${_repo:+-r ${_repo}} -o /tmp/pinprobe pkg >/dev/null 2>&1; then
			_pf=$(find /tmp/pinprobe -name '*.pkg' 2>/dev/null | head -1)
			if [ -n "${_pf}" ] && tar -xOf "${_pf}" +MANIFEST >/tmp/pinprobe.manifest 2>/dev/null; then
				_commit=$(grep -oE '"ports_top_git_hash":"[0-9a-f]{40}"' /tmp/pinprobe.manifest | head -1 | cut -d'"' -f4)
			fi
			[ -n "${_commit}" ] && _pinsrc="live pkg.freebsd.org"
		fi
		# last-ditch: the catalog annotation (usually empty, kept for completeness)
		[ -n "${_commit}" ] || _commit=$(pkg rquery ${_repo:+-r ${_repo}} '%Ak=%Av' pkg 2>/dev/null | sed -n 's/^ports_top_git_hash=//p')
		[ -n "${_commit}" ] || _commit=$(pkg rquery '%Ak=%Av' pkg 2>/dev/null | sed -n 's/^ports_top_git_hash=//p')
	fi
	if [ -z "${_commit}" ]; then
		# LOUD FAILURE. A missed pin silently degrades the WHOLE lean build into a
		# ~600-port from-source compile (rust/llvm/boost) — the exact 5h+ job the lean
		# model exists to kill. Never let that masquerade as the fast path again.
		echo "!!!====================================================================!!!" | tee -a ${LOGFILE}
		echo "!!! LEAN-PIN MISS: could not resolve FreeBSD ports_top_git_hash"           | tee -a ${LOGFILE}
		echo "!!!   repo='${_repo:-none}' rev='${FREESENSE_REV:-<unset>}' chan='${FREESENSE_CHANNEL:-main}'" | tee -a ${LOGFILE}
		echo "!!!   Tree stays at HEAD -> the ~577 cached stock binaries WON'T reuse ->"  | tee -a ${LOGFILE}
		echo "!!!   poudriere rebuilds the full stock closure FROM SOURCE (multi-hour)."  | tee -a ${LOGFILE}
		echo "!!!   Fix the frozen bank (stock/<chan>/<rev>/ports_top_git_hash) or creds." | tee -a ${LOGFILE}
		echo "!!!====================================================================!!!" | tee -a ${LOGFILE}
		# Drop a breadcrumb on R2 so a headless run is diagnosable even if it times out.
		if command -v rclone >/dev/null 2>&1; then
			printf 'lean-pin MISS rev=%s chan=%s repo=%s at %s\n' \
				"${FREESENSE_REV:-<unset>}" "${FREESENSE_CHANNEL:-main}" "${_repo:-none}" "$(LC_ALL=C date -u)" \
				| rclone rcat --s3-no-check-bucket "R2:freesense-pkg/debug/lean-pin-miss.txt" 2>/dev/null || true
		fi
		# STRICT mode (default ON): abort rather than burn hours building stock from source.
		# Set FREESENSE_PIN_STRICT=0 only for a deliberate cold from-source build.
		if [ "${FREESENSE_PIN_STRICT:-1}" = "1" ]; then
			echo ">>> lean-pin: FREESENSE_PIN_STRICT=1 -> aborting (set =0 to allow the slow from-source path)" | tee -a ${LOGFILE}
			print_error_pfS
		fi
		echo ">>> lean-pin: FREESENSE_PIN_STRICT=0 -> proceeding with from-source build (SLOW)" | tee -a ${LOGFILE}
		return 0
	fi
	echo -n ">>> lean-pin: pinning ${POUDRIERE_PORTS_NAME} to freebsd-ports ${_commit} (source: ${_pinsrc:-live}, FreeBSD repo '${_repo:-unnamed}')... " | tee -a ${LOGFILE}
	_checkout_ok=0
	if [ -n "${FREESENSE_EPOCH_OFFLINE:-}" ]; then
		git -C "${_tree}" cat-file -e "${_commit}^{commit}" 2>/dev/null \
			&& git -C "${_tree}" checkout -q -f "${_commit}" 2>/dev/null \
			&& _checkout_ok=1
	else
		{ git -C "${_tree}" fetch --depth 1 origin "${_commit}" >/dev/null 2>&1 \
		  || git -C "${_tree}" fetch origin "${_commit}" >/dev/null 2>&1; } \
			&& git -C "${_tree}" checkout -q -f "${_commit}" 2>/dev/null \
			&& _checkout_ok=1
	fi
	if [ "${_checkout_ok}" = 1 ]; then
		echo "Done!" | tee -a ${LOGFILE}
	else
		# Resolved the hash but couldn't check it out — same slow-path danger. Fail loud too.
		echo "FAILED to fetch/checkout ${_commit}" | tee -a ${LOGFILE}
		if command -v rclone >/dev/null 2>&1; then
			printf 'lean-pin CHECKOUT-FAIL commit=%s rev=%s at %s\n' \
				"${_commit}" "${FREESENSE_REV:-<unset>}" "$(LC_ALL=C date -u)" \
				| rclone rcat --s3-no-check-bucket "R2:freesense-pkg/debug/lean-pin-miss.txt" 2>/dev/null || true
		fi
		if [ "${FREESENSE_PIN_STRICT:-1}" = "1" ]; then
			echo ">>> lean-pin: FREESENSE_PIN_STRICT=1 -> aborting (checkout failed, would build from source)" | tee -a ${LOGFILE}
			print_error_pfS
		fi
		echo ">>> lean-pin: FREESENSE_PIN_STRICT=0 -> leaving tree at HEAD (SLOW from-source build)" | tee -a ${LOGFILE}
	fi
	return 0
}

poudriere_create_ports_tree() {
	LOGFILE=${BUILDER_LOGS}/poudriere.log

	if ! poudriere ports -l | grep -q -E "^${POUDRIERE_PORTS_NAME}[[:blank:]]"; then
		local _branch=""
		if [ -z "${POUDRIERE_PORTS_GIT_URL}" ]; then
			echo ">>> ERROR: POUDRIERE_PORTS_GIT_URL is not defined"
			print_error_pfS
		fi
		if [ -n "${POUDRIERE_PORTS_GIT_BRANCH}" ]; then
			_branch="${POUDRIERE_PORTS_GIT_BRANCH}"
		fi
		echo -n ">>> Creating poudriere ports tree, it may take some time... " | tee -a ${LOGFILE}
		if [ -n "${FREESENSE_EPOCH_OFFLINE:-}" ]; then
			_epoch_ports="${FREESENSE_EPOCH_PORTS_ARCHIVE:-/root/epoch/freebsd-ports.tar.zst}"
			[ -s "${_epoch_ports}" ] || { echo ">>> ERROR: missing epoch ports archive ${_epoch_ports}"; print_error_pfS; }
			script -aq ${LOGFILE} poudriere ports -c -p "${POUDRIERE_PORTS_NAME}" -m none
			mkdir -p "/usr/local/poudriere/ports/${POUDRIERE_PORTS_NAME}"
			tar --zstd --strip-components 1 -xf "${_epoch_ports}" -C "/usr/local/poudriere/ports/${POUDRIERE_PORTS_NAME}"
			[ -d "/usr/local/poudriere/ports/${POUDRIERE_PORTS_NAME}/.git" ] \
				|| { echo ">>> ERROR: epoch ports archive is not a git checkout"; print_error_pfS; }
		elif [ "${AWS}" = 1 ]; then
			set -e
			script -aq ${LOGFILE} poudriere ports -c -p "${POUDRIERE_PORTS_NAME}" -m none
			script -aq ${LOGFILE} zfs create ${ZFS_TANK}/poudriere/ports/${POUDRIERE_PORTS_NAME}

			# If S3 doesn't contain stashed ports tree, create one
			if ! aws_exec s3 ls s3://pfsense-engineering-build-pkg/${FLAVOR}-ports.tz >/dev/null 2>&1; then
				mkdir ${SCRATCHDIR}/${FLAVOR}-ports
				${BUILDER_SCRIPTS}/git_checkout.sh \
				    -r ${POUDRIERE_PORTS_GIT_URL} \
				    -d ${SCRATCHDIR}/${FLAVOR}-ports \
				    -b ${POUDRIERE_PORTS_GIT_BRANCH}

				tar --zstd -C ${SCRATCHDIR} -cf ${FLAVOR}-ports.tz ${FLAVOR}-ports
				aws_exec s3 cp ${FLAVOR}-ports.tz s3://pfsense-engineering-build-pkg/${FLAVOR}-ports.tz --no-progress
			else
				# Download local copy of the ports tree stashed in S3
				echo ">>>  Downloading cached copy of the ports tree from S3.." | tee -a ${LOGFILE}
				aws_exec s3 cp s3://pfsense-engineering-build-pkg/${FLAVOR}-ports.tz . --no-progress
			fi

			script -aq ${LOGFILE} tar --strip-components 1 -xf ${FLAVOR}-ports.tz -C /usr/local/poudriere/ports/${POUDRIERE_PORTS_NAME}
			# Update the ports tree
			(
				cd /usr/local/poudriere/ports/${POUDRIERE_PORTS_NAME}
				echo ">>>  Updating cached copy of the ports tree from git.." | tee -a ${LOGFILE}
				script -aq ${LOGFILE} git pull
				script -aq ${LOGFILE} git checkout ${_branch}
			)
			set +e
		else
			if ! script -aq ${LOGFILE} poudriere ports -c -p "${POUDRIERE_PORTS_NAME}" -m git -U ${POUDRIERE_PORTS_GIT_URL} -B ${_branch} >/dev/null 2>&1; then
				echo "" | tee -a ${LOGFILE}
				echo ">>> ERROR: Error creating poudriere ports tree, aborting..." | tee -a ${LOGFILE}
				print_error_pfS
			fi
		fi
		echo "Done!" | tee -a ${LOGFILE}
		# FreeSense lean-overlay: pin the stock tree to FreeBSD's binary-build commit BEFORE
		# the overlay (a whole-tree checkout resets tracked files; the vendored FreeSense-*
		# dirs are untracked and survive, the partial open-vm-tools patch re-applies below).
		poudriere_pin_ports_tree
		# FreeSense: the ports tree above is UPSTREAM FreeBSD ports (no pfSense-* dirs).
		# Overlay the selected FreeSense system/package port recipes BEFORE
		# poudriere_rename_ports turns them into FreeSense-*. No pfSense-fork dependency.
		. ${BUILDER_TOOLS}/ci/freesense-ports-overlay.sh
		poudriere_rename_ports
	fi
}

poudriere_init() {
	local _error=0
	local _archs=$(poudriere_possible_archs)

	LOGFILE=${BUILDER_LOGS}/poudriere.log

	# Sanity checks
	if [ -z "${ZFS_TANK}" ]; then
		echo ">>> ERROR: \$ZFS_TANK is empty" | tee -a ${LOGFILE}
		error=1
	fi

	if [ -z "${ZFS_ROOT}" ]; then
		echo ">>> ERROR: \$ZFS_ROOT is empty" | tee -a ${LOGFILE}
		error=1
	fi

	if [ -z "${POUDRIERE_PORTS_NAME}" ]; then
		echo ">>> ERROR: \$POUDRIERE_PORTS_NAME is empty" | tee -a ${LOGFILE}
		error=1
	fi

	if [ ${_error} -eq 1 ]; then
		print_error_pfS
	fi

	# Check if zpool exists
	if ! zpool list ${ZFS_TANK} >/dev/null 2>&1; then
		echo ">>> ERROR: ZFS tank ${ZFS_TANK} not found, please create it and try again..." | tee -a ${LOGFILE}
		print_error_pfS
	fi

	# Check if zfs rootfs exists
	if ! zfs list ${ZFS_TANK}${ZFS_ROOT} >/dev/null 2>&1; then
		echo -n ">>> Creating ZFS filesystem ${ZFS_TANK}${ZFS_ROOT}... "
		if zfs create -o atime=off -o mountpoint=/usr/local${ZFS_ROOT} \
		    ${ZFS_TANK}${ZFS_ROOT} >/dev/null 2>&1; then
			echo "Done!"
		else
			echo "Failed!"
			print_error_pfS
		fi
	fi

	# Make sure poudriere is installed
	if [ ! -f /usr/local/bin/poudriere ]; then
		echo ">>> Installing poudriere..." | tee -a ${LOGFILE}
		if ! pkg install poudriere >/dev/null 2>&1; then
			echo ">>> ERROR: poudriere was not installed, aborting..." | tee -a ${LOGFILE}
			print_error_pfS
		fi
	fi

	# Create poudriere.conf
	if [ -z "${POUDRIERE_PORTS_GIT_URL}" ]; then
		echo ">>> ERROR: POUDRIERE_PORTS_GIT_URL is not defined"
		print_error_pfS
	fi

	# PARALLEL_JOBS us ncpu / 4 for best performance
	local _parallel_jobs=$(sysctl -qn hw.ncpu)
	_parallel_jobs=$((_parallel_jobs / 1))

	echo ">>> Creating poudriere.conf" | tee -a ${LOGFILE}
	cat <<EOF >/usr/local/etc/poudriere.conf
ZPOOL=${ZFS_TANK}
ZROOTFS=${ZFS_ROOT}
RESOLV_CONF=/etc/resolv.conf
BASEFS=/usr/local/poudriere
USE_PORTLINT=no
USE_TMPFS=yes
NOLINUX=yes
DISTFILES_CACHE=/usr/ports/distfiles
CHECK_CHANGED_OPTIONS=yes
CHECK_CHANGED_DEPS=yes
ATOMIC_PACKAGE_REPOSITORY=yes
COMMIT_PACKAGES_ON_FAILURE=no
ALLOW_MAKE_JOBS=yes
PARALLEL_JOBS=${_parallel_jobs}
EOF

	if pkg info -e ccache; then
	cat <<EOF >>/usr/local/etc/poudriere.conf
CCACHE_DIR=/var/cache/ccache
EOF
	fi

	# Create specific items conf
	[ ! -d /usr/local/etc/poudriere.d ] \
		&& mkdir -p /usr/local/etc/poudriere.d

	# Create DISTFILES_CACHE if it doesn't exist
	if [ ! -d /usr/ports/distfiles ]; then
		mkdir -p /usr/ports/distfiles
	fi

	if [ "${AWS}" = 1 ]; then
		# Find the distfiles cache for our branch, but fall back to devel cache if it does not exist
		if [ "${FLAVOR}" = "Plus" ]; then
			DEFAULT_BRANCH="plus-devel"
		else
			DEFAULT_BRANCH="devel"
		fi

		if [ "${POUDRIERE_PORTS_GIT_BRANCH}" = "${DEFAULT_BRANCH}" ]; then
			DISTFILES="${FLAVOR}-${POUDRIERE_PORTS_GIT_BRANCH}-distfiles"
		else
			if aws_exec s3 ls s3://pfsense-engineering-build-pkg/${FLAVOR}-${POUDRIERE_PORTS_GIT_BRANCH}-distfiles.tar >/dev/null 2>&1; then
				DISTFILES="${FLAVOR}-${POUDRIERE_PORTS_GIT_BRANCH}-distfiles"
			else
				DISTFILES="${FLAVOR}-${DEFAULT_BRANCH}-distfiles"
				echo ">>> ${FLAVOR}-${POUDRIERE_PORTS_GIT_BRANCH}-distfiles.tar, not found."
				echo ">>> Falling back to ${DISTFILES}.tar"
			fi
		fi

		if aws_exec s3 ls s3://pfsense-engineering-build-pkg/${DISTFILES}.tar >/dev/null 2>&1; then
			# Download a copy of the distfiles from S3
			echo ">>> Downloading distfile cache ${DISTFILES} from S3.." | tee -a ${LOGFILE}
			aws_exec s3 cp s3://pfsense-engineering-build-pkg/${DISTFILES}.tar . --no-progress
			script -aq ${LOGFILE} tar -xf ${DISTFILES}.tar -C /usr/ports/distfiles
			# Save a list of distfiles
			find /usr/ports/distfiles > pre-build-distfile-list
		else
			echo ">>> No distfile cache found, all distfiles will be fetched."
			touch pre-build-distfile-list
		fi
	fi

	# Remove old jails
	for jail_arch in ${_archs}; do
		jail_name=$(poudriere_jail_name ${jail_arch})

		if poudriere jail -i -j "${jail_name}" >/dev/null 2>&1; then
			echo ">>> Poudriere jail ${jail_name} already exists, deleting it..." | tee -a ${LOGFILE}
			poudriere jail -d -j "${jail_name}"
		fi
	done

	# Remove old ports tree
	if poudriere ports -l | grep -q -E "^${POUDRIERE_PORTS_NAME}[[:blank:]]"; then
		echo ">>> Poudriere ports tree ${POUDRIERE_PORTS_NAME} already exists, deleting it..." | tee -a ${LOGFILE}
		poudriere ports -d -p "${POUDRIERE_PORTS_NAME}"
		if [ "${AWS}" = 1 ]; then
			for d in `zfs list -o name`; do
				if [ "${d}" = "${ZFS_TANK}/poudriere/ports/${POUDRIERE_PORTS_NAME}" ]; then
					script -aq ${LOGFILE} zfs destroy ${ZFS_TANK}/poudriere/ports/${POUDRIERE_PORTS_NAME}
				fi
			done
		fi
	fi

	local native_xtools=""
	# Now we are ready to create jails
	for jail_arch in ${_archs}; do
		jail_name=$(poudriere_jail_name ${jail_arch})

		if [ "${jail_arch}" = "arm.armv7" ]; then
			native_xtools="-x"
		else
			native_xtools=""
		fi

		echo ">>> Creating jail ${jail_name}, it may take some time... " | tee -a ${LOGFILE}
		if [ "${AWS}" = "1" ]; then
			mkdir objs
			echo ">>> Downloading prebuilt release objs from s3://pfsense-engineering-build-freebsd-obj-tarballs/${FLAVOR}/${FREEBSD_BRANCH}/ ..." | tee -a ${LOGFILE}
			# Download prebuilt release tarballs from previous job
			aws_exec s3 cp s3://pfsense-engineering-build-freebsd-obj-tarballs/${FLAVOR}/${FREEBSD_BRANCH}/LATEST-${jail_arch} objs --no-progress
			SRC_COMMIT=`cat objs/LATEST-${jail_arch}`
			aws_exec s3 cp s3://pfsense-engineering-build-freebsd-obj-tarballs/${FLAVOR}/${FREEBSD_BRANCH}/MANIFEST-${jail_arch}-${SRC_COMMIT} objs --no-progress
			ln -s MANIFEST-${jail_arch}-${SRC_COMMIT} objs/MANIFEST
			for i in base doc kernel src tests; do
				if [ ! -f objs/${i}-${jail_arch}-${SRC_COMMIT}.txz ]; then
					aws_exec s3 cp s3://pfsense-engineering-build-freebsd-obj-tarballs/${FLAVOR}/${FREEBSD_BRANCH}/${i}-${jail_arch}-${SRC_COMMIT}.txz objs --no-progress
					ln -s ${i}-${jail_arch}-${SRC_COMMIT}.txz objs/${i}.txz
				fi
			done

			if ! script -aq ${LOGFILE} poudriere jail -c -j "${jail_name}" -v ${FREEBSD_BRANCH} \
					-a ${jail_arch} -m url=file://${PWD}/objs >/dev/null 2>&1; then
				echo "" | tee -a ${LOGFILE}
				echo ">>> ERROR: Error creating jail ${jail_name}, aborting..." | tee -a ${LOGFILE}
				print_error_pfS
			fi

			# Download a cached pkg repo from S3
			OLDIFS=${IFS}
			IFS=$'\n'
			echo ">>> Downloading cached pkgs for ${jail_arch} from S3.." | tee -a ${LOGFILE}
			if aws_exec s3 ls s3://pfsense-engineering-build-pkg/${FLAVOR}-${POUDRIERE_PORTS_GIT_BRANCH}-pkgs-${jail_arch}.tar >/dev/null 2>&1; then
				aws_exec s3 cp s3://pfsense-engineering-build-pkg/${FLAVOR}-${POUDRIERE_PORTS_GIT_BRANCH}-pkgs-${jail_arch}.tar . --no-progress
				[ ! -d /usr/local/poudriere/data/packages/${jail_name}-${POUDRIERE_PORTS_NAME} ] && mkdir -p /usr/local/poudriere/data/packages/${jail_name}-${POUDRIERE_PORTS_NAME}
				echo "Extracting ${FLAVOR}-${POUDRIERE_PORTS_GIT_BRANCH}-pkgs-${jail_arch}.tar to /usr/local/poudriere/data/packages/${jail_name}-${POUDRIERE_PORTS_NAME}" | tee -a ${LOGFILE}
				[ ! -d /usr/local/poudriere/data/packages/${jail_name}-${POUDRIERE_PORTS_NAME} ] && mkdir /usr/local/poudriere/data/packages/${jail_name}-${POUDRIERE_PORTS_NAME}
				script -aq ${LOGFILE} tar -xf ${FLAVOR}-${POUDRIERE_PORTS_GIT_BRANCH}-pkgs-${jail_arch}.tar -C /usr/local/poudriere/data/packages/${jail_name}-${POUDRIERE_PORTS_NAME}
				# Save a list of pkgs
				cd /usr/local/poudriere/data/packages/${jail_name}-${POUDRIERE_PORTS_NAME}/.latest
				find . > ${WORKSPACE}/pre-build-pkg-list-${jail_arch}
				cd ${WORKSPACE}
			else
				touch pre-build-pkg-list-${jail_arch}
			fi
			IFS=${OLDIFS}
		else
			if ! script -aq ${LOGFILE} poudriere jail -c -j "${jail_name}" -v ${FREEBSD_BRANCH} \
					-a ${jail_arch} -m git -U ${FREEBSD_REPO_BASE_POUDRIERE} ${native_xtools} >/dev/null 2>&1; then
				echo "" | tee -a ${LOGFILE}
				echo ">>> ERROR: Error creating jail ${jail_name}, aborting..." | tee -a ${LOGFILE}
				print_error_pfS
			fi
		fi
		echo "Done!" | tee -a ${LOGFILE}
	done

	poudriere_create_ports_tree

	echo ">>> Poudriere is now configured!" | tee -a ${LOGFILE}
}

poudriere_update_jails() {
	local _archs=$(poudriere_possible_archs)

	LOGFILE=${BUILDER_LOGS}/poudriere.log

	local native_xtools=""
	for jail_arch in ${_archs}; do
		jail_name=$(poudriere_jail_name ${jail_arch})

		local _create_or_update="-u"
		local _create_or_update_text="Updating"
		if ! poudriere jail -i -j "${jail_name}" >/dev/null 2>&1; then
			echo ">>> Poudriere jail ${jail_name} not found, creating..." | tee -a ${LOGFILE}
			_create_or_update="-c -v ${FREEBSD_BRANCH} -a ${jail_arch} -m git -U ${FREEBSD_REPO_BASE_POUDRIERE}"
			_create_or_update_text="Creating"
		fi

		if [ "${jail_arch}" = "arm.armv7" ]; then
			native_xtools="-x"
		else
			native_xtools=""
		fi

		echo -n ">>> ${_create_or_update_text} jail ${jail_name}, it may take some time... " | tee -a ${LOGFILE}
		if ! script -aq ${LOGFILE} poudriere jail ${_create_or_update} -j "${jail_name}" ${native_xtools} >/dev/null 2>&1; then
			echo "" | tee -a ${LOGFILE}
			echo ">>> ERROR: Error ${_create_or_update_text} jail ${jail_name}, aborting..." | tee -a ${LOGFILE}
			print_error_pfS
		fi
		echo "Done!" | tee -a ${LOGFILE}
	done
}

poudriere_update_ports() {
	LOGFILE=${BUILDER_LOGS}/poudriere.log

	# Create ports tree if necessary
	if ! poudriere ports -l | grep -q -E "^${POUDRIERE_PORTS_NAME}[[:blank:]]"; then
		poudriere_create_ports_tree
	else
		echo -n ">>> Resetting local changes on ports tree ${POUDRIERE_PORTS_NAME}... " | tee -a ${LOGFILE}
		script -aq ${LOGFILE} git -C "/usr/local/poudriere/ports/${POUDRIERE_PORTS_NAME}" reset --hard >/dev/null 2>&1
		script -aq ${LOGFILE} git -C "/usr/local/poudriere/ports/${POUDRIERE_PORTS_NAME}" clean -fd >/dev/null 2>&1
		echo "Done!" | tee -a ${LOGFILE}
		# FreeSense lean-overlay: instead of `poudriere ports -u` (pull to freebsd-ports HEAD),
		# PIN the tree to FreeBSD's binary-build commit so stock ports match FreeBSD's prebuilt
		# binaries (freesense-lean-seed.sh then reuses them). Runs BEFORE the overlay re-apply.
		poudriere_pin_ports_tree
		# FreeSense: the reset/clean/pin above leaves upstream FreeBSD ports (no pfSense-* dirs);
		# re-apply the overlay before rename.
		. ${BUILDER_TOOLS}/ci/freesense-ports-overlay.sh
		poudriere_rename_ports
	fi
}

save_logs_to_s3() {
	# Save a copy of the past few logs into S3
	DATE=`date +%Y%m%d-%H%M%S`
	script -aq ${LOGFILE} tar --zstd -cf pkg-logs-${jail_arch}-${DATE}.tar -C /usr/local/poudriere/data/logs/bulk/${jail_name}-${POUDRIERE_PORTS_NAME}/latest/ .
	aws_exec s3 cp pkg-logs-${jail_arch}-${DATE}.tar s3://pfsense-engineering-build-pkg/logs/ --no-progress
	echo ">>> Uploading pkg-logs-${jail_arch}-${DATE}.tar to s3" | tee -a ${LOGFILE}
	OLDIFS=${IFS}
	IFS=$'\n'
	local _logtemp=$( mktemp /tmp/loglist.XXXXX )
	for i in $(aws_exec s3 ls s3://pfsense-engineering-build-pkg/logs/); do
		echo ${i} | awk '{print $4}' | grep pkg-logs-${jail_arch} | tr -d '\r' >> ${_logtemp}
	done
	# keep at least ~30 days of logs, plus some extra for one off runs
	local _maxlogs=45
	local _curlogs=0
	_curlogs=$( wc -l ${_logtemp} | awk '{print $1}' )
	if [ ${_curlogs} -gt ${_maxlogs} ]; then
		local _extralogs=$(( ${_curlogs} - ${_maxlogs} ))
		for _last in $( head -${_extralogs} ${_logtemp} ); do
			aws_exec s3 rm s3://pfsense-engineering-build-pkg/logs/${_last}
		done
	fi
	IFS=${OLDIFS}
}

save_pkgs_to_s3() {
	cd /usr/local/poudriere/data/packages/${jail_name}-${POUDRIERE_PORTS_NAME}/.latest
	find . > ${WORKSPACE}/post-build-pkg-list-${jail_arch}
	cd ${WORKSPACE}
	diff pre-build-pkg-list-${jail_arch} post-build-pkg-list-${jail_arch} > /dev/null
	if [ $? = 1 ]; then
		echo ">>> Saving a copy of the package repo into S3..." | tee -a ${LOGFILE}
		[ -f ${FLAVOR}-${POUDRIERE_PORTS_GIT_BRANCH}-pkgs-${jail_arch}.tar ] && rm ${FLAVOR}-${POUDRIERE_PORTS_GIT_BRANCH}-pkgs-${jail_arch}.tar
		script -aq ${LOGFILE} tar -cf ${FLAVOR}-${POUDRIERE_PORTS_GIT_BRANCH}-pkgs-${jail_arch}.tar -C /usr/local/poudriere/data/packages/${jail_name}-${POUDRIERE_PORTS_NAME} .
		aws_exec s3 cp ${FLAVOR}-${POUDRIERE_PORTS_GIT_BRANCH}-pkgs-${jail_arch}.tar s3://pfsense-engineering-build-pkg/ --no-progress
	else
		echo ">>> No pkgs different, not saving to S3..." | tee -a ${LOGFILE}
	fi
	save_logs_to_s3
}

aws_exec() {
	script -aq ${LOGFILE} \
	    env AWS_ACCESS_KEY_ID=${AWS_ACCESS_KEY_ID} \
	    AWS_SECRET_ACCESS_KEY=${AWS_SECRET_ACCESS_KEY} \
	    AWS_DEFAULT_REGION=us-east-2 \
	    AWS_DEFAULT_OUTPUT=text \
	    aws $@
	return $?
}

poudriere_bulk() {
	local _archs=$(poudriere_possible_archs)
	local _makeconf

	# Create DISTFILES_CACHE if it doesn't exist
	if [ ! -d /usr/ports/distfiles ]; then
		mkdir -p /usr/ports/distfiles
	fi

	LOGFILE=${BUILDER_LOGS}/poudriere.log

	local _pkg_rsync_site_count=0
	for _pkg_rsync_site in ${PKG_RSYNC_HOSTS}; do
		eval _pkg_rsync_hostname=\$PKG_RSYNC_HOSTNAME_$_pkg_rsync_site
		[ -n "${_pkg_rsync_hostname}" ] && \
			_pkg_rsync_site_count=$(( ${_pkg_rsync_site_count} + 1 ))
	done

	if [ -n "${UPLOAD}" ] && [ "${_pkg_rsync_site_count}" -eq 0 ]; then
		echo ">>> ERROR: PKG_RSYNC_HOSTS is not set"
		print_error_pfS
	fi

	rm -f ${LOGFILE}

	poudriere_create_ports_tree

	[ -d /usr/local/etc/poudriere.d ] || \
		mkdir -p /usr/local/etc/poudriere.d

	_makeconf=/usr/local/etc/poudriere.d/${POUDRIERE_PORTS_NAME}-make.conf
	if [ -f "${BUILDER_TOOLS}/conf/pfPorts/make.conf" ]; then
		sed -e "s,%%PRODUCT_NAME%%,${PRODUCT_NAME},g" \
		    -e "s,%%PRODUCT_VERSION%%,${PRODUCT_VERSION},g" \
		    -e "s,%%FREESENSE_PACKAGE_TRAIN%%,${FREESENSE_PACKAGE_TRAIN},g" \
		    "${BUILDER_TOOLS}/conf/pfPorts/make.conf" > ${_makeconf}
	fi

	cat <<EOF >>/usr/local/etc/poudriere.d/${POUDRIERE_PORTS_NAME}-make.conf

PKG_REPO_BRANCH_DEVEL=${PKG_REPO_BRANCH_DEVEL}
PKG_REPO_BRANCH_NEXT=${PKG_REPO_BRANCH_NEXT}
PKG_REPO_BRANCH_RELEASE=${PKG_REPO_BRANCH_RELEASE}
PKG_REPO_BRANCH_PREVIOUS=${PKG_REPO_BRANCH_PREVIOUS}
PKG_REPO_SERVER_DEVEL=${PKG_REPO_SERVER_DEVEL}
PKG_REPO_SERVER_RELEASE=${PKG_REPO_SERVER_RELEASE}
POUDRIERE_PORTS_NAME=${POUDRIERE_PORTS_NAME}
FREESENSE_DEFAULT_REPO=${FREESENSE_DEFAULT_REPO}
PRODUCT_NAME=${PRODUCT_NAME}
FREESENSE_PACKAGE_TRAIN=${FREESENSE_PACKAGE_TRAIN}
REPO_BRANCH_PREFIX=${REPO_PATH_PREFIX}
EOF

	local _value=""
	for jail_arch in ${_archs}; do
		eval "_value=\${PKG_REPO_BRANCH_DEVEL_${jail_arch##*.}}"
		if [ -n "${_value}" ]; then
			echo "PKG_REPO_BRANCH_DEVEL_${jail_arch##*.}=${_value}" \
				>> ${_makeconf}
		fi
		eval "_value=\${PKG_REPO_BRANCH_RELEASE_${jail_arch##*.}}"
		if [ -n "${_value}" ]; then
			echo "PKG_REPO_BRANCH_RELEASE_${jail_arch##*.}=${_value}" \
				>> ${_makeconf}
		fi
		eval "_value=\${PKG_REPO_SERVER_DEVEL_${jail_arch##*.}}"
		if [ -n "${_value}" ]; then
			echo "PKG_REPO_SERVER_DEVEL_${jail_arch##*.}=${_value}" \
				>> ${_makeconf}
		fi
		eval "_value=\${PKG_REPO_SERVER_RELEASE_${jail_arch##*.}}"
		if [ -n "${_value}" ]; then
			echo "PKG_REPO_SERVER_RELEASE_${jail_arch##*.}=${_value}" \
				>> ${_makeconf}
		fi
	done

	# Stamp a clean version on EVERY core port that ships DISTVERSION=${PRODUCT_VERSION}
	# (security/FreeSense + -system + -ce, sysutils/FreeSense-repo + -default-config[-serial],
	# devel/php-FreeSense-module). PRODUCT_VERSION is NOT defined in the poudriere build env
	# (the make.conf only sets FREESENSE_PKG_SET_VERSION), so without this they build with an
	# EMPTY version -> "unwanted unversioned dependency" failures. Find them dynamically so a
	# new such port is covered automatically.
	# devel  -> <ver>-ALPHA-<datestamp>  (ports framework renders it <ver>.a.<datestamp>)
	# release -> clean <ver> with the -RELEASE suffix stripped (1.0.0-RELEASE -> 1.0.0).
	if [ -z "${_IS_RELEASE}" ]; then
		local _meta_pkg_version="$(echo "${PRODUCT_VERSION}" | sed 's,DEVELOPMENT,ALPHA,')-${DATESTRING}"
	else
		local _meta_pkg_version="${PRODUCT_VERSION%%-*}"
	fi
	local _pdir="/usr/local/poudriere/ports/${POUDRIERE_PORTS_NAME}"
	grep -rlE 'DISTVERSION=[[:space:]]*\$\{PRODUCT_VERSION\}|PORTVERSION=[[:space:]]*\$\{PRODUCT_VERSION\}' \
		"${_pdir}" --include=Makefile 2>/dev/null | while read -r _mf; do
		sed -i '' \
			-e "/^DISTVERSION/ s,^.*,DISTVERSION=	${_meta_pkg_version}," \
			-e "/^PORTVERSION/ s,^.*,PORTVERSION=	${_meta_pkg_version}," \
			-e "/^PORTREVISION=/d" \
			"${_mf}"
	done

	# Copy over pkg repo templates to pfSense-repo
	mkdir -p /usr/local/poudriere/ports/${POUDRIERE_PORTS_NAME}/sysutils/${PRODUCT_NAME}-repo/files
	cp -f ${PKG_REPO_BASE}/* \
		/usr/local/poudriere/ports/${POUDRIERE_PORTS_NAME}/sysutils/${PRODUCT_NAME}-repo/files

	for jail_arch in ${_archs}; do
		jail_name=$(poudriere_jail_name ${jail_arch})

		if ! poudriere jail -i -j "${jail_name}" >/dev/null 2>&1; then
			echo ">>> Poudriere jail ${jail_name} not found, skipping..." | tee -a ${LOGFILE}
			continue
		fi

		_ref_bulk=${SCRATCHDIR}/poudriere_bulk.${POUDRIERE_BRANCH}.ref.${jail_arch}
		rm -rf ${_ref_bulk} ${_ref_bulk}.tmp
		touch ${_ref_bulk}.tmp
		if [ -f "${POUDRIERE_BULK}.${jail_arch#*.}" ]; then
			cat "${POUDRIERE_BULK}.${jail_arch#*.}" >> ${_ref_bulk}.tmp
		fi
		if [ -f "${POUDRIERE_BULK}" ]; then
			cat "${POUDRIERE_BULK}" >> ${_ref_bulk}.tmp
		fi
		cat ${_ref_bulk}.tmp | sort -u > ${_ref_bulk}

		_bulk=${SCRATCHDIR}/poudriere_bulk.${POUDRIERE_BRANCH}.${jail_arch}
		sed -e "s,%%PRODUCT_NAME%%,${PRODUCT_NAME},g" ${_ref_bulk} > ${_bulk}

		local _exclude_bulk="${POUDRIERE_BULK}.exclude.${jail_arch}"
		if [ -f "${_exclude_bulk}" ]; then
			mv ${_bulk} ${_bulk}.tmp
			sed -e "s,%%PRODUCT_NAME%%,${PRODUCT_NAME},g" ${_exclude_bulk} > ${_bulk}.exclude
			cat ${_bulk}.tmp ${_bulk}.exclude | sort | uniq -u > ${_bulk}
			rm -f ${_bulk}.tmp ${_bulk}.exclude
		fi

		# FreeSense FROZEN STOCK SNAPSHOT (producer): when FREESENSE_SNAPSHOT is set this is the
		# weekly snapshot job, not a build. It runs on the full bulk list (the workflow leaves the
		# subset blank) against this same pinned+overlaid tree + make.conf, mirrors the COMPLETE
		# stock closure + ports source to R2:.../stock/<chan>/<rev>/, then RETURNS before building
		# anything (there is nothing to build — the snapshot only needs the closure + fetch + tar).
		if [ -n "${FREESENSE_SNAPSHOT:-}" ]; then
			echo ">>> FreeSense stock-snapshot mode: rev ${FREESENSE_REV:-<none>} (${jail_arch}), worker=${FREESENSE_SNAPSHOT_WORKER_ID:-merge/legacy}" | tee -a ${LOGFILE}
			_snapshot_program=${BUILDER_TOOLS}/ci/freesense-stock-snapshot.sh
			[ -n "${FREESENSE_SNAPSHOT_MERGE:-}" ] && _snapshot_program=${BUILDER_TOOLS}/ci/freesense-stock-merge.sh
			FREESENSE_JAIL_NAME="${jail_name}" FREESENSE_BULK="${_bulk}" \
			FREESENSE_PORTS_NAME="${POUDRIERE_PORTS_NAME}" FREESENSE_OVERLAY_DIR="${OVERLAY_DIR:-/root/freesense-system-ports}" \
			FREESENSE_REV="${FREESENSE_REV:-}" FREESENSE_CHANNEL="${FREESENSE_CHANNEL:-main}" FREESENSE_SNAPSHOT_ID="${FREESENSE_SNAPSHOT_ID:-${FREESENSE_REV:-}}" \
			FREESENSE_SNAPSHOT_WORKER_ID="${FREESENSE_SNAPSHOT_WORKER_ID:-}" FREESENSE_SNAPSHOT_WORKER_COUNT="${FREESENSE_SNAPSHOT_WORKER_COUNT:-0}" \
				sh ${_snapshot_program} || {
					echo ">>> stock-snapshot failed; refusing to continue" | tee -a ${LOGFILE}
					_snapshot_diag=/tmp/stock-snapshot.diag
					[ -n "${FREESENSE_SNAPSHOT_MERGE:-}" ] && _snapshot_diag=/tmp/stock-snapshot-merge.diag
					if [ -s "${_snapshot_diag}" ]; then
						{
							echo ">>> stock-snapshot diagnostic tail"
							tail -200 "${_snapshot_diag}"
							echo ">>> end stock-snapshot diagnostic tail"
						} | tee -a ${LOGFILE}
					fi
					return 1
				}
			return 0
		fi

		# FreeSense lean-overlay (consumer): seed FreeBSD's PREBUILT stock binaries from THIS rev's
		# frozen snapshot so the bulk below REUSES them and builds ONLY the ~135 custom/patched ports
		# (kills the 5h rust + the huge cold build). No FreeBSD contact here — the snapshot job above
		# produced the bytes. Best-effort; a missing snapshot just falls back to building from source.
		# Runs here so it sees the pinned+overlaid tree + this make.conf.
		FREESENSE_JAIL_NAME="${jail_name}" \
		FREESENSE_PORTS_NAME="${POUDRIERE_PORTS_NAME}" \
		FREESENSE_REV="${FREESENSE_REV:-}" FREESENSE_CHANNEL="${FREESENSE_CHANNEL:-main}" FREESENSE_SNAPSHOT_ID="${FREESENSE_SNAPSHOT_ID:-${FREESENSE_REV:-}}" \
			sh ${BUILDER_TOOLS}/ci/freesense-lean-seed.sh || {
				if [ "${FREESENSE_PIN_STRICT:-1}" = "0" ]; then
					echo ">>> lean-seed failed; non-strict local build may continue from source"
				else
					echo ">>> lean-seed failed; refusing an accidental full source build"
					return 1
				fi
			}

		echo ">>> Poudriere bulk started at `date "+%Y/%m/%d %H:%M:%S"` for ${jail_arch}"
		_poudriere_test_flag=""
		if [ -n "${FREESENSE_PORT_TESTS:-}" ]; then
			_poudriere_test_flag="-t"
			echo ">>> FreeSense port test mode enabled (Poudriere bulk -t)" | tee -a ${LOGFILE}
		fi
		if ! poudriere bulk ${_poudriere_test_flag} -f ${_bulk} -j ${jail_name} -p ${POUDRIERE_PORTS_NAME}; then
			echo ">>> ERROR: Something went wrong..."
			if [ "${AWS}" = 1 ]; then
				save_pkgs_to_s3
			fi
			print_error_pfS
		fi
		echo ">>> Poudriere bulk complated at `date "+%Y/%m/%d %H:%M:%S"` for ${jail_arch}"

		# lean-overlay: poudriere may REBUILD a stock port that was also FETCHED (plain name);
		# pkg then names the rebuild name-ver~hash.pkg so it won't clobber the fetched
		# name-ver.pkg. Both live in .latest/All, and the next step (poudriere pkgclean) dies:
		# "Found duplicated packages" + the '~' breaks its version parser ("NN~..: not completely
		# converted") -> set -e -> the WHOLE build aborts even though bulk succeeded. Drop the
		# hashed twin. Prefer the fresh rebuild: Poudriere created it because the frozen
		# package did not match this exact tree/options/dependency set.
		_leandir="/usr/local/poudriere/data/packages/${jail_name}-${POUDRIERE_PORTS_NAME}/.latest/All"
		if [ -d "${_leandir}" ]; then
			for _h in "${_leandir}"/*~*.pkg; do
				[ -e "${_h}" ] || continue
				_plain="${_h%~*}.pkg"
				if [ -e "${_plain}" ]; then
					echo ">>> lean-dedup: rebuilt package wins $(basename "${_h}") -> $(basename "${_plain}")"
					rm -f "${_plain}"
					mv "${_h}" "${_plain}"
				fi
			done
		fi

		echo ">>> Cleaning up old packages from repo..."
		if ! poudriere pkgclean -f ${_bulk} -j ${jail_name} -p ${POUDRIERE_PORTS_NAME} -y; then
			echo ">>> ERROR: Something went wrong..."
			print_error_pfS
		fi

		if [ "${AWS}" = 1 ]; then
			save_pkgs_to_s3
		fi

		pkg_repo_rsync "/usr/local/poudriere/data/packages/${jail_name}-${POUDRIERE_PORTS_NAME}"
	done

	if [ "${AWS}" = 1 ]; then
		echo ">>> Run poudriere distclean to prune old distfiles..." | tee -a ${LOGFILE}
		if ! poudriere distclean -f ${_bulk} -p ${POUDRIERE_PORTS_NAME} -n; then
			echo ">>> ERROR: Something went wrong..."
			print_error_pfS
		fi
		echo ">>> Save a copy of the distfiles into S3..." | tee -a ${LOGFILE}
		# Save a copy of the distfiles from S3
		find /usr/ports/distfiles > post-build-distfile-list
		diff pre-build-distfile-list post-build-distfile-list > /dev/null
		if [ $? -eq 1 ]; then
			rm -f ${FLAVOR}-${POUDRIERE_PORTS_GIT_BRANCH}-distfiles.tar
			script -aq ${LOGFILE} tar -cf ${FLAVOR}-${POUDRIERE_PORTS_GIT_BRANCH}-distfiles.tar -C /usr/ports/distfiles .
			aws_exec s3 cp ${FLAVOR}-${POUDRIERE_PORTS_GIT_BRANCH}-distfiles.tar s3://pfsense-engineering-build-pkg/ --no-progress
		fi
	fi
}

# This routine is called to write out to stdout
# a string. The string is appended to $SNAPSHOTSLOGFILE
snapshots_update_status() {
	if [ -z "$1" ]; then
		return
	fi
	if [ -z "${SNAPSHOTS}" -a -z "${POUDRIERE_SNAPSHOTS}" ]; then
		return
	fi
	echo "$*"
	echo "`date` -|- $*" >> $SNAPSHOTSLOGFILE
}

create_sha256() {
	local _file="${1}"

	if [ ! -f "${_file}" ]; then
		return 1
	fi

	( \
		cd $(dirname ${_file}) && \
		sha256 $(basename ${_file}) > $(basename ${_file}).sha256 \
	)
}

snapshots_create_latest_symlink() {
	local _image="${1}"

	if [ -z "${_image}" ]; then
		return
	fi

	if [ -z "${TIMESTAMP_SUFFIX}" ]; then
		return
	fi

	if [ ! -f "${_image}" ]; then
		return
	fi

	local _symlink=$(echo ${_image} | sed "s,${TIMESTAMP_SUFFIX},-latest,")
	ln -sf $(basename ${_image}) ${_symlink}
	ln -sf $(basename ${_image}).sha256 ${_symlink}.sha256
}

snapshots_create_sha256() {
	local _img=""

	for _img in ${ISOPATH} ${MEMSTICKPATH} ${MEMSTICKSERIALPATH} ${MEMSTICKADIPATH} ${OVAPATH} ${VARIANTIMAGES}; do
		if [ -f "${_img}.gz" ]; then
			_img="${_img}.gz"
		fi
		if [ ! -f "${_img}" ]; then
			continue
		fi
		create_sha256 ${_img}
		snapshots_create_latest_symlink ${_img}
	done
}

snapshots_scp_files() {
	if [ -z "${RSYNC_COPY_ARGUMENTS}" ]; then
		RSYNC_COPY_ARGUMENTS="-Have \"ssh -o StrictHostKeyChecking=no\" --timeout=60"
	fi

	snapshots_update_status ">>> Copying core pkg repo to ${PKG_RSYNC_HOSTNAME}"
	pkg_repo_rsync "${CORE_PKG_PATH}"
	snapshots_update_status ">>> Finished copying core pkg repo"

	for _rsyncip in ${RSYNCIP}; do
		snapshots_update_status ">>> Copying files to ${_rsyncip}"

		# Ensure directory(s) are available
		ssh -o StrictHostKeyChecking=no ${RSYNCUSER}@${_rsyncip} "mkdir -p ${RSYNCPATH}/installer"
		if [ -d $IMAGES_FINAL_DIR/virtualization ]; then
			ssh -o StrictHostKeyChecking=no ${RSYNCUSER}@${_rsyncip} "mkdir -p ${RSYNCPATH}/virtualization"
		fi
		# ensure permissions are correct for r+w
		ssh -o StrictHostKeyChecking=no ${RSYNCUSER}@${_rsyncip} "chmod -R ug+rw ${RSYNCPATH}/."
		rsync $RSYNC_COPY_ARGUMENTS $IMAGES_FINAL_DIR/installer/* \
			${RSYNCUSER}@${_rsyncip}:${RSYNCPATH}/installer/
		if [ -d $IMAGES_FINAL_DIR/virtualization ]; then
			rsync $RSYNC_COPY_ARGUMENTS $IMAGES_FINAL_DIR/virtualization/* \
				${RSYNCUSER}@${_rsyncip}:${RSYNCPATH}/virtualization/
		fi

		snapshots_update_status ">>> Finished copying files."
	done
}
