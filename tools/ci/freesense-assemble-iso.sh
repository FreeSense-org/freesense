#!/bin/sh
# Compile-free ISO assembly from one sealed System repository.

run_in_assembly_chroot() (
	set -eu
	_assembly_root="${1}"
	shift
	_assembly_devfs_mounted=""

	cleanup_assembly_devfs() {
		_assembly_status=$?
		trap - EXIT HUP INT TERM
		if [ -n "${_assembly_devfs_mounted}" ] && \
		    ! umount -f "${_assembly_root}/dev"; then
			echo ">>> ERROR: unable to unmount ${_assembly_root}/dev" >&2
			[ "${_assembly_status}" -ne 0 ] || _assembly_status=1
		fi
		exit "${_assembly_status}"
	}

	trap cleanup_assembly_devfs EXIT
	trap 'exit 129' HUP
	trap 'exit 130' INT
	trap 'exit 143' TERM
	mount -t devfs devfs "${_assembly_root}/dev"
	_assembly_devfs_mounted=yes
	chroot "${_assembly_root}" "$@"
)

install_assembly_channel() {
	_channel_payload="${FREESENSE_ASSEMBLY_CHANNEL_PAYLOAD}"
	_channel="${FREESENSE_ASSEMBLY_CHANNEL}"
	_current_system="${FREESENSE_SYSTEM_FINGERPRINT}"
	_share="${FINAL_CHROOT_DIR}${PRODUCT_SHARE_DIR}"
	_repos="${FINAL_CHROOT_DIR}/usr/local/etc/${PRODUCT_NAME}/pkg/repos"
	_guest_payload="/tmp/freesense-assembly-channel.json"
	_guest_validator="/tmp/freesense-validate-channel.php"
	_validator="${BUILDER_TOOLS}/ci/freesense-validate-channel.php"

	case "${_channel}" in
	devel|stable) : ;;
	*)
		echo ">>> ERROR: assembly channel must be devel or stable" >&2
		return 1
		;;
	esac
	if [ "${#_current_system}" -ne 64 ]; then
		echo ">>> ERROR: assembly system fingerprint must be SHA-256" >&2
		return 1
	fi
	case "${_current_system}" in
	*[!0-9a-f]*)
		echo ">>> ERROR: assembly system fingerprint must be SHA-256" >&2
		return 1
		;;
	esac
	[ -s "${_channel_payload}" ] || {
		echo ">>> ERROR: verified assembly channel payload is missing" >&2
		return 1
	}
	[ -r "${_validator}" ] || {
		echo ">>> ERROR: assembly channel validator is missing" >&2
		return 1
	}
	[ -x "${FINAL_CHROOT_DIR}/usr/local/bin/php" ] || {
		echo ">>> ERROR: installed PHP is required to validate the assembly channel" >&2
		return 1
	}
	[ -x "${FINAL_CHROOT_DIR}/usr/local/sbin/${PRODUCT_NAME}-repoc" ] || {
		echo ">>> ERROR: installed ${PRODUCT_NAME}-repoc is required" >&2
		return 1
	}

	cp "${_channel_payload}" "${FINAL_CHROOT_DIR}${_guest_payload}"
	cp "${_validator}" "${FINAL_CHROOT_DIR}${_guest_validator}"
	_channel_status=0
	chroot "${FINAL_CHROOT_DIR}" /usr/local/bin/php -n \
		"${_guest_validator}" "${_guest_payload}" "${_channel}" \
		"${_current_system}" || _channel_status=$?
	rm -f "${FINAL_CHROOT_DIR}${_guest_validator}"
	[ "${_channel_status}" -eq 0 ] || {
		rm -f "${FINAL_CHROOT_DIR}${_guest_payload}"
		echo ">>> ERROR: assembly channel payload does not select the current system" >&2
		return "${_channel_status}"
	}

	mkdir -p "${_share}" "${_repos}"
	install -o root -g wheel -m 0444 "${FINAL_CHROOT_DIR}${_guest_payload}" \
		"${_share}/repos.manifest.json"
	cmp -s "${_channel_payload}" "${_share}/repos.manifest.json" || {
		echo ">>> ERROR: installed channel payload differs from verified input" >&2
		return 1
	}
	rm -f "${FINAL_CHROOT_DIR}${_guest_payload}"
	rm -f "${_repos}/${PRODUCT_NAME}-repo-"*.default 2>/dev/null || true
	: > "${_repos}/${PRODUCT_NAME}-repo-${_channel}.default"

	run_in_assembly_chroot "${FINAL_CHROOT_DIR}" /usr/bin/env \
		PRODUCT="${PRODUCT_NAME}" \
		REPOS_DIR="/usr/local/etc/${PRODUCT_NAME}/pkg/repos" \
		SHARE_DIR="${PRODUCT_SHARE_DIR}" ARCH="${TARGET_ARCH}" \
		MANIFEST_LOCAL="${PRODUCT_SHARE_DIR}/repos.manifest.json" \
		"/usr/local/sbin/${PRODUCT_NAME}-repoc" -l

	_selected="${_repos}/${PRODUCT_NAME}-repo-${_channel}"
	[ -s "${_selected}.conf" ] || {
		echo ">>> ERROR: selected assembly channel configuration was not materialized" >&2
		return 1
	}
	[ -f "${_selected}.default" ] || {
		echo ">>> ERROR: selected assembly channel default marker was not preserved" >&2
		return 1
	}
	_default_count=$(find "${_repos}" -type f \
		-name "${PRODUCT_NAME}-repo-*.default" -print | awk 'END { print NR + 0 }')
	[ "${_default_count}" -eq 1 ] || {
		echo ">>> ERROR: assembly must contain exactly one selected channel" >&2
		return 1
	}
	grep -Fq "/artifacts/system/${_current_system}/amd64" "${_selected}.conf" || {
		echo ">>> ERROR: selected repository configuration does not match the current system" >&2
		return 1
	}
}

assemble_iso_from_repositories() {
	set -eu
	require_source_date_epoch || return 1
	: "${FREESENSE_ASSEMBLY_SYSTEM_REPO:?system repository is required}"
	: "${FREESENSE_ASSEMBLY_FREEBSD_SRC:?pinned FreeBSD release tools are required}"
	: "${FREESENSE_ASSEMBLY_CHANNEL_PAYLOAD:?verified channel payload is required}"
	: "${FREESENSE_ASSEMBLY_CHANNEL:?selected channel is required}"
	: "${FREESENSE_SYSTEM_FINGERPRINT:?current system fingerprint is required}"
	: "${FREESENSE_DIST_WORLD_ARCHIVE:?pinned FreeBSD world archive is required}"

	_repo="${FREESENSE_ASSEMBLY_SYSTEM_REPO}"
	test -s "${_repo}/meta.conf"
	test -s "${_repo}/packagesite.pkg"
	test -n "$(find "${_repo}/All" -type f -name '*.pkg' -print -quit)"
	for _package in "${_repo}"/All/*.pkg; do
		pkg query -F "${_package}" '%n|%v|%o' >/dev/null
	done

	rm -rf "${FINAL_CHROOT_DIR}"
	mkdir -p "${FINAL_CHROOT_DIR}" "${BUILDER_LOGS}"
	sh "${BUILDER_TOOLS}/ci/freesense-dist-world.sh"

	_pkg_package=$(find "${_repo}/All" -name 'pkg-[0-9]*.pkg' \
		-type f | sort | tail -1)
	[ -n "${_pkg_package}" ] || {
		echo ">>> ERROR: assembly package missing: pkg-[0-9]*.pkg"
		return 1
	}

	# Bootstrap only pkg into the pinned world. pkg then installs and registers
	# every package from the exact immutable System repository closure once.
	for _root in "${INSTALLER_CHROOT_DIR}" "${STAGE_CHROOT_DIR}"; do
		tar -xpf "${_pkg_package}" -C "${_root}" --exclude '+*'
		mkdir -p "${_root}/tmp/assembly-pkgs" "${_root}/dev"
	done

	cp "${_repo}"/All/*.pkg "${INSTALLER_CHROOT_DIR}/tmp/assembly-pkgs/"
	cp "${_repo}"/All/*.pkg "${STAGE_CHROOT_DIR}/tmp/assembly-pkgs/"
	for _root in "${INSTALLER_CHROOT_DIR}" "${STAGE_CHROOT_DIR}"; do
		# pkg records NOW() in local.sqlite for every installed package.  pkg's
		# supported override makes the installed package database reproducible.
		run_in_assembly_chroot "${_root}" /usr/bin/env \
			PKG_INSTALL_EPOCH="${SOURCE_DATE_EPOCH}" /bin/sh -c \
			'pkg add /tmp/assembly-pkgs/*.pkg &&
			test "$(pkg query -a "%t" | sort -u)" = "${PKG_INSTALL_EPOCH}"'
		rm -rf "${_root}/tmp/assembly-pkgs"
	done

	clone_directory_contents "${STAGE_CHROOT_DIR}" "${FINAL_CHROOT_DIR}"
	_default=$(find "${_repo}/All" \
		-name "${PRODUCT_NAME}-default-config-[0-9]*.pkg" -type f | sort | tail -1)
	[ -n "${_default}" ] || { echo ">>> ERROR: default configuration package missing"; return 1; }
	cp "${_default}" "${FINAL_CHROOT_DIR}/tmp/default-config.pkg"
	run_in_assembly_chroot "${FINAL_CHROOT_DIR}" /usr/bin/env \
		PKG_INSTALL_EPOCH="${SOURCE_DATE_EPOCH}" \
		/bin/sh -c 'pkg add -f /tmp/default-config.pkg &&
		test "$(pkg query -a "%t" | sort -u)" = "${PKG_INSTALL_EPOCH}"'
	rm -f "${FINAL_CHROOT_DIR}/tmp/default-config.pkg"
	install_assembly_channel

	mkdir -p "${INSTALLER_CHROOT_DIR}/pkgs"
	cp "${_default}" "${INSTALLER_CHROOT_DIR}/pkgs/"
	install -o root -g wheel -m 0555 "${BUILDER_TOOLS}/installer/installer-rc.local" \
		"${INSTALLER_CHROOT_DIR}/etc/rc.local"
	ln -sf "${PRODUCT_NAME}-rc" "${FINAL_CHROOT_DIR}/etc/pfSense-rc"
	ln -sf "${PRODUCT_NAME}-rc.shutdown" "${FINAL_CHROOT_DIR}/etc/pfSense-rc.shutdown"

	for _root in "${INSTALLER_CHROOT_DIR}" "${FINAL_CHROOT_DIR}"; do
		grep -q '^sshd:' "${_root}/etc/master.passwd"
		grep -q '^nobody:' "${_root}/etc/master.passwd"
		grep -q '/etc/pfSense-rc' "${_root}/etc/rc"
		test -x "${_root}/usr/sbin/bsdinstall"
		test -f "${_root}/boot/kernel/zfs.ko"
		test -f "${_root}/boot/kernel/opensolaris.ko"
	done
	_kernel="${INSTALLER_CHROOT_DIR}/boot/kernel/kernel"
	_kernel_tmp=""
	if [ -f "${_kernel}.gz" ]; then
		_kernel_tmp="${SCRATCHDIR}/assembler-kernel.elf"
		gzip -dc "${_kernel}.gz" > "${_kernel_tmp}"
		_kernel="${_kernel_tmp}"
	fi
	_ident=$(/usr/sbin/config -x "${_kernel}" | awk '$1=="ident"{print $2;exit}')
	[ "${_ident}" = "${PRODUCT_NAME}" ] || {
		echo ">>> ERROR: kernel ident ${_ident:-unknown} is not ${PRODUCT_NAME}"
		return 1
	}
	[ -z "${_kernel_tmp}" ] || rm -f "${_kernel_tmp}"

	FREEBSD_SRC_DIR="${FREESENSE_ASSEMBLY_FREEBSD_SRC}"
	export FREEBSD_SRC_DIR DEFAULT_KERNEL="${PRODUCT_NAME}"
	LOGFILE="${BUILDER_LOGS}/isoimage.${TARGET}"
	create_distribution_tarball
	mkdir -p "$(dirname "${ISOPATH}")"
	FSLABEL=$(echo "${PRODUCT_NAME}" | tr '[:lower:]' '[:upper:]')
	sh "${FREEBSD_SRC_DIR}/release/${TARGET}/mkisoimages.sh" -b \
		"${FSLABEL}" "${ISOPATH}" "${INSTALLER_CHROOT_DIR}"
	test -s "${ISOPATH}"
	echo ">>> compile-free ISO complete: ${ISOPATH}"
}
