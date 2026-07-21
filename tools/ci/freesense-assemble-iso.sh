#!/bin/sh
# Compile-free ISO assembly from the split base and system repositories.

assemble_iso_from_repositories() {
	set -eu
	: "${FREESENSE_ASSEMBLY_BASE_REPO:?base repository is required}"
	: "${FREESENSE_ASSEMBLY_SYSTEM_REPO:?system repository is required}"
	: "${FREESENSE_ASSEMBLY_FREEBSD_SRC:?pinned FreeBSD release tools are required}"

	for _repo in "${FREESENSE_ASSEMBLY_BASE_REPO}" "${FREESENSE_ASSEMBLY_SYSTEM_REPO}"; do
		test -s "${_repo}/meta.conf"
		test -s "${_repo}/packagesite.pkg"
		test -n "$(find "${_repo}/All" -type f -name '*.pkg' -print -quit)"
		for _package in "${_repo}"/All/*.pkg; do
			pkg query -F "${_package}" '%n|%v|%o' >/dev/null
		done
	done

	rm -rf "${INSTALLER_CHROOT_DIR}" "${STAGE_CHROOT_DIR}" "${FINAL_CHROOT_DIR}" \
		"${SCRATCHDIR}/assembly-repository"
	mkdir -p "${INSTALLER_CHROOT_DIR}" "${STAGE_CHROOT_DIR}" "${FINAL_CHROOT_DIR}" \
		"${SCRATCHDIR}/assembly-repository/All" "${BUILDER_LOGS}"

	# Merge by package filename and reject any non-reproducible duplicate.
	for _repo in "${FREESENSE_ASSEMBLY_BASE_REPO}" "${FREESENSE_ASSEMBLY_SYSTEM_REPO}"; do
		for _package in "${_repo}"/All/*.pkg; do
			_destination="${SCRATCHDIR}/assembly-repository/All/$(basename "${_package}")"
			if [ -e "${_destination}" ]; then
				[ "$(sha256 -q "${_package}")" = "$(sha256 -q "${_destination}")" ] || {
					echo ">>> ERROR: conflicting package $(basename "${_package}")"
					return 1
				}
			else
				cp "${_package}" "${_destination}"
			fi
		done
	done

	# Seed enough userland to run pkg inside each fresh root. pkg then executes
	# package scripts and registers the exact immutable repository closure.
	for _root in "${INSTALLER_CHROOT_DIR}" "${STAGE_CHROOT_DIR}"; do
		for _pattern in \
			"${PRODUCT_NAME}-base-*.pkg" "${PRODUCT_NAME}-boot-*.pkg" \
			"${PRODUCT_NAME}-kernel-${PRODUCT_NAME}-*.pkg" "${PRODUCT_NAME}-rc-*.pkg" \
			"pkg-[0-9]*.pkg"; do
			_package=$(find "${SCRATCHDIR}/assembly-repository/All" -name "${_pattern}" -type f | sort | tail -1)
			[ -n "${_package}" ] || {
				echo ">>> ERROR: assembly package missing: ${_pattern}"
				return 1
			}
			tar -xpf "${_package}" -C "${_root}" --exclude '+*'
		done
		mkdir -p "${_root}/tmp/assembly-pkgs" "${_root}/dev"
	done

	cp "${FREESENSE_ASSEMBLY_BASE_REPO}"/All/*.pkg \
		"${INSTALLER_CHROOT_DIR}/tmp/assembly-pkgs/"
	cp "${SCRATCHDIR}/assembly-repository/All"/*.pkg \
		"${STAGE_CHROOT_DIR}/tmp/assembly-pkgs/"
	for _root in "${INSTALLER_CHROOT_DIR}" "${STAGE_CHROOT_DIR}"; do
		mount -t devfs devfs "${_root}/dev"
		chroot "${_root}" /bin/sh -c 'pkg add -f /tmp/assembly-pkgs/*.pkg'
		umount -f "${_root}/dev"
		rm -rf "${_root}/tmp/assembly-pkgs"
	done

	clone_directory_contents "${STAGE_CHROOT_DIR}" "${FINAL_CHROOT_DIR}"
	_default=$(find "${FREESENSE_ASSEMBLY_BASE_REPO}/All" \
		-name "${PRODUCT_NAME}-default-config-[0-9]*.pkg" -type f | sort | tail -1)
	[ -n "${_default}" ] || { echo ">>> ERROR: default configuration package missing"; return 1; }
	cp "${_default}" "${FINAL_CHROOT_DIR}/tmp/default-config.pkg"
	mount -t devfs devfs "${FINAL_CHROOT_DIR}/dev"
	chroot "${FINAL_CHROOT_DIR}" pkg add -f /tmp/default-config.pkg
	umount -f "${FINAL_CHROOT_DIR}/dev"
	rm -f "${FINAL_CHROOT_DIR}/tmp/default-config.pkg"

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
	create_distribution_tarball
	mkdir -p "$(dirname "${ISOPATH}")"
	FSLABEL=$(echo "${PRODUCT_NAME}" | tr '[:lower:]' '[:upper:]')
	sh "${FREEBSD_SRC_DIR}/release/${TARGET}/mkisoimages.sh" -b \
		"${FSLABEL}" "${ISOPATH}" "${INSTALLER_CHROOT_DIR}"
	test -s "${ISOPATH}"
	gzip -kf "${ISOPATH}"
	sha256 "${ISOPATH}" "${ISOPATH}.gz" > "${ISOPATH}.sha256"

	python3 - "${SCRATCHDIR}/assembly-repository/All" "${ISOPATH}" <<'PY'
import datetime, hashlib, json, pathlib, sys
packages, image = map(pathlib.Path, sys.argv[1:])
components = []
for package in sorted(packages.glob("*.pkg")):
    components.append({"type": "library", "name": package.name,
        "hashes": [{"alg": "SHA-256", "content": hashlib.sha256(package.read_bytes()).hexdigest()}]})
sbom = {"bomFormat": "CycloneDX", "specVersion": "1.5", "version": 1, "components": components}
image.with_suffix(image.suffix + ".sbom.cdx.json").write_text(json.dumps(sbom, indent=2, sort_keys=True) + "\n")
provenance = {"schema": 1, "kind": "freesense-split-iso",
    "created_at": datetime.datetime.now(datetime.timezone.utc).isoformat(),
    "iso_sha256": hashlib.sha256(image.read_bytes()).hexdigest(),
    "package_count": len(components)}
image.with_suffix(image.suffix + ".provenance.json").write_text(json.dumps(provenance, indent=2, sort_keys=True) + "\n")
PY
	echo ">>> compile-free ISO complete: ${ISOPATH}"
}
