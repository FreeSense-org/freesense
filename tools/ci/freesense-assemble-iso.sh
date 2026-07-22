#!/bin/sh
# Compile-free ISO assembly from one sealed System repository.
# FREESENSE_ISO_ASSEMBLY_API=2

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
	[ -x "${FINAL_CHROOT_DIR}/usr/local/sbin/${PRODUCT_NAME}-repoc" ] || {
		echo ">>> ERROR: installed ${PRODUCT_NAME}-repoc is required" >&2
		return 1
	}

	cp "${_channel_payload}" "${FINAL_CHROOT_DIR}${_guest_payload}"
	mkdir -p "${_share}" "${_repos}"
	rm -f "${_repos}/${PRODUCT_NAME}-repo-"*.default 2>/dev/null || true
	: > "${_repos}/${PRODUCT_NAME}-repo-${_channel}.default"

	run_in_assembly_chroot "${FINAL_CHROOT_DIR}" /usr/bin/env \
		PRODUCT="${PRODUCT_NAME}" \
		REPOS_DIR="/usr/local/etc/${PRODUCT_NAME}/pkg/repos" \
		SHARE_DIR="${PRODUCT_SHARE_DIR}" \
		MANIFEST_LOCAL="${_guest_payload}" \
		"/usr/local/sbin/${PRODUCT_NAME}-repoc" -l || {
		rm -f "${FINAL_CHROOT_DIR}${_guest_payload}"
		echo ">>> ERROR: assembly channel payload is not a verified release pair" >&2
		return 1
	}

	install -o root -g wheel -m 0444 "${FINAL_CHROOT_DIR}${_guest_payload}" \
		"${_share}/repos.manifest.json"
	cmp -s "${_channel_payload}" "${_share}/repos.manifest.json" || {
		echo ">>> ERROR: installed channel payload differs from verified input" >&2
		return 1
	}
	rm -f "${FINAL_CHROOT_DIR}${_guest_payload}"

	_selected="${_repos}/${PRODUCT_NAME}-repo-${_channel}"
	[ -s "${_selected}.conf" ] || {
		echo ">>> ERROR: selected assembly channel configuration was not materialized" >&2
		return 1
	}
	[ -f "${_selected}.default" ] || {
		echo ">>> ERROR: selected assembly channel default marker was not preserved" >&2
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
	_assembly_keys="${BUILDER_ROOT}/src/usr/local/share/${PRODUCT_NAME}/keys/pkg"
	_assembly_package_names="${SCRATCHDIR}/assembly-package-names.$$"
	cleanup_assembly_repository() {
		for _cleanup_root in "${INSTALLER_CHROOT_DIR}" "${STAGE_CHROOT_DIR}"; do
			rm -rf "${_cleanup_root}/tmp/assembly-repo" \
				"${_cleanup_root}/tmp/assembly-repos" \
				"${_cleanup_root}/tmp/assembly-keys" \
				"${_cleanup_root}/tmp/assembly-cache"
			rm -f "${_cleanup_root}/tmp/assembly-package-names" \
				"${_cleanup_root}/tmp/pkg-bootstrap.pkg" \
				"${_cleanup_root}/var/db/pkg/repo-FreeSenseAssembly.sqlite"*
		done
		rm -f "${_assembly_package_names}"
	}
	cleanup_assembly_exit() {
		_assembly_status=$?
		trap - EXIT HUP INT TERM
		set +e
		cleanup_assembly_repository
		exit "${_assembly_status}"
	}
	trap cleanup_assembly_exit EXIT
	trap 'exit 129' HUP
	trap 'exit 130' INT
	trap 'exit 143' TERM

	test -s "${_repo}/meta.conf"
	test -s "${_repo}/data.pkg"
	test -s "${_repo}/packagesite.pkg"
	test -s "${_assembly_keys}/trusted/freesense"
	: > "${_assembly_package_names}"
	# Keep the same small image-root contract as the native builder.  The
	# repository also contains build-only tools, overlapping core archives, and
	# mutually exclusive image variants; pkg resolves only these intended roots.
	for _package_name in "${PRODUCT_NAME}" ${custom_package_list:-}; do
		case "${_package_name}" in
		''|[!A-Za-z0-9]*|*[!A-Za-z0-9+_.-]*)
			echo ">>> ERROR: invalid package name in the image root set" >&2
			return 1
			;;
		esac
		printf '%s\n' "${_package_name}" >> "${_assembly_package_names}"
	done
	LC_ALL=C sort -u -o "${_assembly_package_names}" "${_assembly_package_names}"
	test -s "${_assembly_package_names}"

	rm -rf "${FINAL_CHROOT_DIR}"
	mkdir -p "${FINAL_CHROOT_DIR}" "${BUILDER_LOGS}"
	sh "${BUILDER_TOOLS}/ci/freesense-dist-world.sh"

	_pkg_package=$(find "${_repo}/All" -name 'pkg-[0-9]*.pkg' \
		-type f | sort | tail -1)
	[ -n "${_pkg_package}" ] || {
		echo ">>> ERROR: assembly package missing: pkg-[0-9]*.pkg"
		return 1
	}

	# Bootstrap only pkg into the pinned world. Register it first so the normal
	# closure transaction never has to install the package manager running it.
	for _root in "${INSTALLER_CHROOT_DIR}" "${STAGE_CHROOT_DIR}"; do
		tar -xpf "${_pkg_package}" -C "${_root}" --exclude '+*'
		mkdir -p "${_root}/tmp/assembly-repo/All" \
			"${_root}/tmp/assembly-repos" \
			"${_root}/tmp/assembly-keys" \
			"${_root}/tmp/assembly-cache" "${_root}/dev"
		cp "${_repo}/meta.conf" "${_repo}"/*.pkg \
			"${_root}/tmp/assembly-repo/"
		cp "${_repo}"/All/*.pkg "${_root}/tmp/assembly-repo/All/"
		cp -R "${_assembly_keys}/." "${_root}/tmp/assembly-keys/"
		cp "${_assembly_package_names}" \
			"${_root}/tmp/assembly-package-names"
		cp "${_pkg_package}" "${_root}/tmp/pkg-bootstrap.pkg"
		cat > "${_root}/tmp/assembly-repos/FreeSenseAssembly.conf" <<'EOF'
FreeSenseAssembly: {
  url: "file:///tmp/assembly-repo",
  mirror_type: "none",
  signature_type: "fingerprints",
  fingerprints: "/tmp/assembly-keys",
  enabled: yes
}
EOF
	done

	for _root in "${INSTALLER_CHROOT_DIR}" "${STAGE_CHROOT_DIR}"; do
		# pkg records NOW() in local.sqlite for every installed package.  pkg's
		# supported override makes the installed package database reproducible.
		run_in_assembly_chroot "${_root}" /usr/bin/env \
			PKG_INSTALL_EPOCH="${SOURCE_DATE_EPOCH}" /bin/sh -c \
			'report_pkg_failure() {
				_log=$1
				echo ">>> relevant pkg diagnostics:" >&2
				grep -Eai "(^pkg:|error|conflict|failed|failure|already installed)" \
					"${_log}" | tail -n 40 >&2 || true
				echo ">>> final pkg output:" >&2
				tail -n 60 "${_log}" >&2 || true
			}
			if ! pkg add /tmp/pkg-bootstrap.pkg >/tmp/pkg-bootstrap.log 2>&1; then
				report_pkg_failure /tmp/pkg-bootstrap.log
				echo ">>> ERROR: unable to register the pinned pkg bootstrap" >&2
				exit 1
			fi
			rm -f /tmp/pkg-bootstrap.pkg /tmp/pkg-bootstrap.log
			set --
			while IFS= read -r _package_name; do
				[ -n "${_package_name}" ] || continue
				set -- "$@" "${_package_name}"
			done < /tmp/assembly-package-names
			if [ "$#" -eq 0 ]; then
				echo ">>> ERROR: pinned System package closure is empty" >&2
				exit 1
			fi
			if ! pkg -o REPOS_DIR=/tmp/assembly-repos \
				-o PKG_CACHEDIR=/tmp/assembly-cache \
				install -r FreeSenseAssembly -y "$@" \
				>/tmp/pkg-closure.log 2>&1; then
				report_pkg_failure /tmp/pkg-closure.log
				echo ">>> ERROR: unable to install the pinned System package closure" >&2
				exit 1
			fi
			rm -f /tmp/pkg-closure.log
			_pkg_epochs=$(pkg query -a "%t" | sort -u)
			if [ "${_pkg_epochs}" != "${PKG_INSTALL_EPOCH}" ]; then
				printf ">>> ERROR: package install epoch mismatch (expected %s, got %s)\n" \
					"${PKG_INSTALL_EPOCH}" "${_pkg_epochs:-empty}" >&2
				exit 1
			fi'
	done
	cleanup_assembly_repository

	clone_directory_contents "${STAGE_CHROOT_DIR}" "${FINAL_CHROOT_DIR}"
	_default=$(find "${_repo}/All" \
		-name "${PRODUCT_NAME}-default-config-[0-9]*.pkg" -type f | sort | tail -1)
	[ -n "${_default}" ] || { echo ">>> ERROR: default configuration package missing"; return 1; }
	cp "${_default}" "${FINAL_CHROOT_DIR}/tmp/default-config.pkg"
	run_in_assembly_chroot "${FINAL_CHROOT_DIR}" /usr/bin/env \
		PKG_INSTALL_EPOCH="${SOURCE_DATE_EPOCH}" \
		/bin/sh -c 'if ! pkg add -f /tmp/default-config.pkg \
			>/tmp/pkg-default.log 2>&1; then
			tail -n 60 /tmp/pkg-default.log >&2 || true
			echo ">>> ERROR: unable to apply the default configuration package" >&2
			exit 1
		fi
		rm -f /tmp/pkg-default.log
		_pkg_epochs=$(pkg query -a "%t" | sort -u)
		if [ "${_pkg_epochs}" != "${PKG_INSTALL_EPOCH}" ]; then
			printf ">>> ERROR: final package install epoch mismatch (expected %s, got %s)\n" \
				"${PKG_INSTALL_EPOCH}" "${_pkg_epochs:-empty}" >&2
			exit 1
		fi'
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
		test -f "${_root}/etc/${PRODUCT_NAME}-rc"
		test -f "${_root}/etc/${PRODUCT_NAME}-rc.shutdown"
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
	trap - EXIT HUP INT TERM
}
