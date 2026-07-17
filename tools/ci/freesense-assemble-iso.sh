#!/bin/sh
# Compile-free ISO assembly from authenticated base and system repositories.

assemble_iso_from_seals() {
	set -eu
	: "${FREESENSE_ASSEMBLY_BASE_REPO:?sealed base repository is required}"
	: "${FREESENSE_ASSEMBLY_SYSTEM_REPO:?sealed system repository is required}"
	: "${FREESENSE_ASSEMBLY_BASE_SEAL:?base seal is required}"
	: "${FREESENSE_ASSEMBLY_BASE_SEAL_SIG:?base seal signature is required}"
	: "${FREESENSE_ASSEMBLY_SYSTEM_SEAL:?system seal is required}"
	: "${FREESENSE_ASSEMBLY_SYSTEM_SEAL_SIG:?system seal signature is required}"
	: "${FREESENSE_ASSEMBLY_BASE_PUBLIC_KEY:?base repository public key is required}"
	: "${FREESENSE_ASSEMBLY_SYSTEM_PUBLIC_KEY:?system repository public key is required}"
	: "${FREESENSE_ASSEMBLY_BUILD_LOCK:?Build Lock is required}"
	: "${FREESENSE_ASSEMBLY_EPOCH:?epoch manifest is required}"
	: "${FREESENSE_ASSEMBLY_ISO_TOOLS:?ISO tool archive root is required}"

	echo ">>> assembler: authenticate immutable inputs"
	openssl dgst -sha256 -verify "${FREESENSE_ASSEMBLY_BASE_PUBLIC_KEY}" \
		-signature "${FREESENSE_ASSEMBLY_BASE_SEAL_SIG}" "${FREESENSE_ASSEMBLY_BASE_SEAL}"
	openssl dgst -sha256 -verify "${FREESENSE_ASSEMBLY_SYSTEM_PUBLIC_KEY}" \
		-signature "${FREESENSE_ASSEMBLY_SYSTEM_SEAL_SIG}" "${FREESENSE_ASSEMBLY_SYSTEM_SEAL}"

	python3 - \
		"${FREESENSE_ASSEMBLY_BASE_SEAL}" "${FREESENSE_ASSEMBLY_BASE_REPO}" \
		"${FREESENSE_ASSEMBLY_SYSTEM_SEAL}" "${FREESENSE_ASSEMBLY_SYSTEM_REPO}" \
		"${FREESENSE_ASSEMBLY_BUILD_LOCK}" "${FREESENSE_ASSEMBLY_EPOCH}" <<'PY'
import hashlib,json,pathlib,sys
base_seal,base_repo,system_seal,system_repo,lock_path,epoch_path=map(pathlib.Path,sys.argv[1:])
base=json.loads(base_seal.read_text()); system=json.loads(system_seal.read_text())
lock_raw=lock_path.read_bytes(); lock=json.loads(lock_raw); epoch=json.loads(epoch_path.read_text())
lock_digest=hashlib.sha256(lock_raw).hexdigest()
def fail(message): raise SystemExit("assembler input error: "+message)
if base.get("build_lock_digest") != lock_digest: fail("base seal Build Lock mismatch")
if system.get("build_lock",{}).get("digest") != lock_digest: fail("system seal Build Lock mismatch")
if base.get("epoch_digest") != lock["artifact_backend"]["manifest_sha256"]: fail("base epoch mismatch")
if system.get("build_epoch",{}).get("manifest_sha256") != lock["artifact_backend"]["manifest_sha256"]: fail("system epoch mismatch")
if lock["artifact_backend"]["id"] != epoch["epoch_id"]: fail("epoch identity mismatch")
if base.get("freebsd_sha") != epoch["upstream"]["freebsd_source_sha"]: fail("FreeBSD source mismatch")
if base.get("abi") != epoch["abi"]: fail("ABI mismatch")
if base.get("source_sha") != lock["inputs"]["source_sha"]: fail("base source mismatch")
if base.get("os_definition_sha") != lock["inputs"]["os_definition_sha"]: fail("base OS definition mismatch")
if system["inputs"]["source_sha"] != lock["inputs"]["source_sha"]: fail("system source mismatch")
if system["inputs"]["overlay_sha"] != lock["inputs"]["system_overlay_sha"]: fail("system overlay mismatch")
if system["inputs"]["os_definition_sha"] != lock["inputs"]["os_definition_sha"]: fail("system OS definition mismatch")
if system.get("package_train") != lock["package_train"]: fail("package train mismatch")
def verify(repository, packages):
    for item in packages:
        path=repository/"All"/item["name"]
        if not path.is_file(): fail("missing package "+item["name"])
        digest=hashlib.sha256(path.read_bytes()).hexdigest()
        if digest != item["sha256"]: fail("package hash mismatch "+item["name"])
verify(base_repo,base["packages"])
verify(system_repo,system.get("packages",[]))
if not system.get("packages"): fail("system seal does not cover package hashes")
base_by_name={item["name"]:item["sha256"] for item in base["packages"]}
system_by_name={item["name"]:item["sha256"] for item in system["packages"]}
for name,digest in base_by_name.items():
    if system_by_name.get(name) != digest: fail("system/base core package disagreement "+name)
base_names=[name for name in base_by_name if name.startswith("FreeSense-base-")]
if len(base_names) != 1 or not base_names[0].endswith("-"+base["core_version"]+".pkg"):
    fail("core version does not match sealed base package")
print("authenticated",len(base["packages"])+len(system["packages"]),"packages")
PY

	rm -rf "${INSTALLER_CHROOT_DIR}" "${STAGE_CHROOT_DIR}" "${FINAL_CHROOT_DIR}" \
		"${SCRATCHDIR}/assembly-repository"
	mkdir -p "${INSTALLER_CHROOT_DIR}" "${STAGE_CHROOT_DIR}" "${FINAL_CHROOT_DIR}" \
		"${SCRATCHDIR}/assembly-repository/All" "${BUILDER_LOGS}"
	cp "${FREESENSE_ASSEMBLY_BASE_REPO}"/All/*.pkg "${SCRATCHDIR}/assembly-repository/All/"
	cp "${FREESENSE_ASSEMBLY_SYSTEM_REPO}"/All/*.pkg "${SCRATCHDIR}/assembly-repository/All/"

	# Seed enough userland to run pkg inside each new root, then let pkg execute
	# package scripts and register the complete immutable closure.
	for _root in "${INSTALLER_CHROOT_DIR}" "${STAGE_CHROOT_DIR}"; do
		for _pattern in \
			"${PRODUCT_NAME}-base-*.pkg" "${PRODUCT_NAME}-boot-*.pkg" \
			"${PRODUCT_NAME}-kernel-${PRODUCT_NAME}-*.pkg" "${PRODUCT_NAME}-rc-*.pkg" \
			"pkg-[0-9]*.pkg"; do
			_package=$(find "${SCRATCHDIR}/assembly-repository/All" -name "${_pattern}" -type f | sort | tail -1)
			[ -n "${_package}" ] || {
				echo ">>> ERROR: sealed assembly package missing: ${_pattern}"
				return 1
			}
			tar -xpf "${_package}" -C "${_root}" --exclude '+*'
		done
		mkdir -p "${_root}/tmp/assembly-pkgs" "${_root}/dev"
	done

	# Installer root contains only the authenticated core closure.
	python3 - "${FREESENSE_ASSEMBLY_BASE_SEAL}" "${INSTALLER_CHROOT_DIR}/tmp/assembly-pkgs" \
		"${FREESENSE_ASSEMBLY_BASE_REPO}/All" <<'PY'
import json,pathlib,shutil,sys
seal=json.load(open(sys.argv[1])); out=pathlib.Path(sys.argv[2]); repo=pathlib.Path(sys.argv[3])
for item in seal["packages"]: shutil.copy2(repo/item["name"],out/item["name"])
PY
	# Installed staging gets the complete system closure, including the same core.
	cp "${SCRATCHDIR}/assembly-repository/All"/*.pkg "${STAGE_CHROOT_DIR}/tmp/assembly-pkgs/"
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
	cp "${FREESENSE_ASSEMBLY_BASE_REPO}"/All/${PRODUCT_NAME}-default-config*.pkg \
		"${INSTALLER_CHROOT_DIR}/pkgs/"
	install -o root -g wheel -m 0555 "${BUILDER_TOOLS}/installer/installer-rc.local" \
		"${INSTALLER_CHROOT_DIR}/etc/rc.local"
	ln -sf "${PRODUCT_NAME}-rc" "${FINAL_CHROOT_DIR}/etc/pfSense-rc"
	ln -sf "${PRODUCT_NAME}-rc.shutdown" "${FINAL_CHROOT_DIR}/etc/pfSense-rc.shutdown"

	echo ">>> assembler: run boot/account/installer tripwires"
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
		echo ">>> ERROR: sealed kernel ident ${_ident:-unknown} is not ${PRODUCT_NAME}"
		return 1
	}
	[ -z "${_kernel_tmp}" ] || rm -f "${_kernel_tmp}"

	FREEBSD_SRC_DIR="${FREESENSE_ASSEMBLY_ISO_TOOLS}"
	export FREEBSD_SRC_DIR DEFAULT_KERNEL="${PRODUCT_NAME}"
	create_distribution_tarball
	mkdir -p "$(dirname "${ISOPATH}")"
	FSLABEL=$(echo "${PRODUCT_NAME}" | tr '[:lower:]' '[:upper:]')
	sh "${FREEBSD_SRC_DIR}/release/${TARGET}/mkisoimages.sh" -b \
		"${FSLABEL}" "${ISOPATH}" "${INSTALLER_CHROOT_DIR}"
	test -s "${ISOPATH}"
	gzip -kf "${ISOPATH}"
	sha256 "${ISOPATH}" "${ISOPATH}.gz" > "${ISOPATH}.sha256"
	python3 - "${FREESENSE_ASSEMBLY_BASE_SEAL}" "${FREESENSE_ASSEMBLY_SYSTEM_SEAL}" \
		"${FREESENSE_ASSEMBLY_BUILD_LOCK}" "${ISOPATH}" <<'PY'
import datetime,hashlib,json,pathlib,sys
base,system,lock,image=map(pathlib.Path,sys.argv[1:])
packages=json.load(open(base))["packages"]+json.load(open(system))["packages"]
sbom={"bomFormat":"CycloneDX","specVersion":"1.5","version":1,
      "components":[{"type":"library","name":p["name"],"hashes":[{"alg":"SHA-256","content":p["sha256"]}]} for p in packages]}
image.with_suffix(image.suffix+".sbom.cdx.json").write_text(json.dumps(sbom,indent=2,sort_keys=True)+"\n")
raw=lock.read_bytes()
provenance={"schema":1,"kind":"freesense-iso-provenance",
 "created_at":datetime.datetime.now(datetime.timezone.utc).isoformat(),
 "build_lock_digest":hashlib.sha256(raw).hexdigest(),
 "base_seal_digest":hashlib.sha256(base.read_bytes()).hexdigest(),
 "system_seal_digest":hashlib.sha256(system.read_bytes()).hexdigest(),
 "iso_sha256":hashlib.sha256(image.read_bytes()).hexdigest()}
image.with_suffix(image.suffix+".provenance.json").write_text(json.dumps(provenance,indent=2,sort_keys=True)+"\n")
PY
	echo ">>> assembler-only ISO complete: ${ISOPATH}"
}
