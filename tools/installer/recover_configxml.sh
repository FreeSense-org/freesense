#!/bin/sh
# part of FreeSense (https://www.freesense.org)
# Copyright (c) 2004-2026 The FreeSense Project
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
#
# $FreeBSD$

# Recover config.xml from an installed UFS or ZFS system. Automatic recovery
# uses the conventional /cf/conf/config.xml location. When an older or unusual
# layout does not expose that path, the user can explicitly choose a dataset and
# file after acknowledging that forced recovery bypasses normal lineage checks.

recovery_mount=/tmp/mnt_recovery
recovery_selected_mount=/tmp/mnt_recovery_selected
recovery_dir=/tmp/recovered_config
mounted_paths=/tmp/recovery-mounted-paths
zdb_log=/tmp/recover-zdb-label.log
zpool_log=/tmp/recover-zpool.log
DIALOG=${DIALOG:-/usr/bin/bsddialog}

pool_name=""
pool_guid=""
pool_imported=no

/bin/mkdir -p "${recovery_mount}" "${recovery_selected_mount}" "${recovery_dir}"
/bin/rm -f "${mounted_paths}" "${zdb_log}" "${zpool_log}"

message() {
	"${DIALOG}" --backtitle "FreeSense Installer" --title "$1" --msgbox "$2" 0 0
}

confirm_force() {
	"${DIALOG}" --backtitle "FreeSense Installer" --title "Force config recovery" \
		--yes-label "Force copy" --no-label "Cancel" --yesno "$1" 0 0
}

record_mount() {
	echo "$1" >> "${mounted_paths}"
}

cleanup_source() {
	if [ -s "${mounted_paths}" ]; then
		/usr/bin/tail -r "${mounted_paths}" | while IFS= read -r mounted_path; do
			[ -n "${mounted_path}" ] && /sbin/umount "${mounted_path}" 2>/dev/null
		done
		/bin/rm -f "${mounted_paths}"
	fi
	if [ "${pool_imported}" = "yes" ] && [ -n "${pool_name}" ]; then
		/sbin/zpool export -f "${pool_name}" 2>/dev/null
		pool_imported=no
	fi
}

trap cleanup_source EXIT HUP INT TERM

config_lineage() {
	/usr/bin/grep -m1 -oE '<(freesense|pfsense|opnsense)>' "$1" 2>/dev/null |
	    /usr/bin/tr -d '<>'
}

stage_config() {
	source_config="$1"
	[ -r "${source_config}" ] && [ -s "${source_config}" ] || return 1

	lineage="`config_lineage "${source_config}"`"
	case "${lineage}" in
	freesense)
		;;
	pfsense|opnsense)
		confirm_force "The selected file is a ${lineage} configuration, not a converted FreeSense configuration.

The safer choice is Cancel, then use \"Import config\" so FreeSense can convert it.

Force-copying a foreign configuration can prevent the installed firewall from booting correctly. Continue anyway?" ||
		    return 1
		;;
	*)
		confirm_force "The selected file is not recognized as a FreeSense, pfSense, or OPNsense configuration.

Force-copying an unknown or malformed file can leave the installed firewall unbootable.

Continue anyway?" || return 1
		;;
	esac

	if ! /bin/cp "${source_config}" "${recovery_dir}/config.xml"; then
		message "Recover config.xml" "The selected configuration could not be copied."
		return 1
	fi
	echo "Recovered config.xml from ${source_config}, stored in ${recovery_dir}."
	return 0
}

recover_standard_config() {
	for relative_path in cf/conf/config.xml conf/config.xml config.xml; do
		if [ -r "${recovery_mount}/${relative_path}" ] &&
		    [ -s "${recovery_mount}/${relative_path}" ]; then
			stage_config "${recovery_mount}/${relative_path}"
			return $?
		fi
	done
	return 1
}

choose_config_path() {
	browse_root="$1"
	confirm_force "Automatic recovery did not find config.xml in a standard location.

Advanced recovery lets you select any readable file inside the mounted installation. Only select a configuration backup you trust.

Open the file selector?" || return 1

	exec 3>&1
	selected_path="`"${DIALOG}" --backtitle "FreeSense Installer" \
		--title "Select config.xml manually" \
		--fselect "${browse_root}/" 20 76 2>&1 1>&3`"
	dialog_rc=$?
	exec 3>&-
	[ ${dialog_rc} -eq 0 ] || return 1

	case "${selected_path}" in
	"${browse_root}"/*)
		;;
	*)
		message "Recover config.xml" "The selected path is outside the mounted source and was rejected."
		return 1
		;;
	esac
	if [ ! -f "${selected_path}" ] || [ ! -r "${selected_path}" ] ||
	    [ ! -s "${selected_path}" ]; then
		message "Recover config.xml" "The selected path is not a readable, non-empty file."
		return 1
	fi
	stage_config "${selected_path}"
}

load_zfs() {
	if /sbin/kldstat -q -m zfs; then
		return 0
	fi
	if /usr/bin/timeout 15 /sbin/kldload zfs > /tmp/recover-zfs-load.log 2>&1; then
		return 0
	fi
	echo "Unable to load ZFS support."
	/bin/cat /tmp/recover-zfs-load.log 2>/dev/null
	return 1
}

read_zfs_identity() {
	recover_disk="$1"
	/bin/rm -f "${zdb_log}"

	# zdb versions differ on whether label details are emitted on stdout or
	# stderr. Capture both streams, then parse the first complete label.
	# A partially damaged label can make zdb return non-zero even though another
	# label copy contains the pool identity we need. Parse the captured output
	# first and only reject it when no usable identity is present.
	/usr/bin/timeout 15 /sbin/zdb -l "/dev/${recover_disk}" \
	    > "${zdb_log}" 2>&1 || true
	pool_name="`/usr/bin/awk -F "'" \
		'/^[[:space:]]*name:[[:space:]]*/ {print $2; exit}' "${zdb_log}"`"
	pool_guid="`/usr/bin/awk \
		'/^[[:space:]]*pool_guid:[[:space:]]*/ {print $2; exit}' "${zdb_log}"`"

	if [ -z "${pool_name}" ] || [ -z "${pool_guid}" ]; then
		echo "Unable to parse ZFS pool information from ${recover_disk}."
		/bin/cat "${zdb_log}" 2>/dev/null
		return 1
	fi
	return 0
}

import_zfs_pool() {
	recover_disk="$1"
	if /sbin/zpool list -H -o name 2>/dev/null |
	    /usr/bin/grep -Fqx "${pool_name}"; then
		echo "ZFS pool ${pool_name} is already imported; using it read-only."
		return 0
	fi

	echo "Found ZFS pool ${pool_name}; importing only ${recover_disk} read-only."
	if ! /usr/bin/timeout 30 /sbin/zpool import -N -f \
	    -o readonly=on -o cachefile=none -R "${recovery_mount}" \
	    -d "/dev/${recover_disk}" "${pool_guid}" > "${zpool_log}" 2>&1; then
		echo "Unable to import ${pool_name} from ${recover_disk} for recovery."
		/bin/cat "${zpool_log}" 2>/dev/null
		return 1
	fi
	pool_imported=yes
	return 0
}

dataset_exists() {
	[ -n "$1" ] && /sbin/zfs list -H -o name "$1" >/dev/null 2>&1
}

find_root_dataset() {
	root_dataset="`/sbin/zpool get -H -o value bootfs "${pool_name}" 2>/dev/null`"
	if [ "${root_dataset}" = "-" ] || ! dataset_exists "${root_dataset}"; then
		root_dataset="`/sbin/zfs list -H -r -o name,mountpoint "${pool_name}" 2>/dev/null |
		    /usr/bin/awk '$2 == "/" {print $1; exit}'`"
	fi
	if ! dataset_exists "${root_dataset}"; then
		root_dataset="`/sbin/zfs list -H -r -o name "${pool_name}" 2>/dev/null |
		    /usr/bin/awk '/\/ROOT\/default$/ {print; exit}'`"
	fi
	dataset_exists "${root_dataset}"
}

select_root_dataset() {
	dataset_list=/tmp/recover-zfs-datasets
	/sbin/zfs list -H -r -o name,mountpoint "${pool_name}" > "${dataset_list}" 2>/dev/null ||
	    return 1

	set --
	while IFS="	" read -r dataset mountpoint; do
		[ -n "${dataset}" ] || continue
		set -- "$@" "${dataset}" "${mountpoint:-no-mountpoint}"
	done < "${dataset_list}"
	[ "$#" -gt 0 ] || return 1

	message "Advanced ZFS recovery" "The active boot environment could not be determined automatically.

Select the dataset that contains the old system root. You can then choose the configuration path manually."
	exec 3>&1
	root_dataset="`"${DIALOG}" --backtitle "FreeSense Installer" \
		--title "Select system root dataset" \
		--menu "Select a ZFS dataset to mount read-only" 0 0 0 "$@" 2>&1 1>&3`"
	dialog_rc=$?
	exec 3>&-
	[ ${dialog_rc} -eq 0 ] && dataset_exists "${root_dataset}"
}

mount_dataset() {
	dataset="$1"
	target="$2"
	/bin/mkdir -p "${target}"
	if /sbin/mount -t zfs -o ro "${dataset}" "${target}" 2>/dev/null; then
		record_mount "${target}"
		return 0
	fi
	return 1
}

mount_config_dataset() {
	wanted_mountpoint="$1"
	target="${recovery_mount}${wanted_mountpoint}"

	dataset="`/sbin/zfs list -H -r -o name,mountpoint "${pool_name}" 2>/dev/null |
	    /usr/bin/awk -v wanted="${wanted_mountpoint}" '$2 == wanted {print $1; exit}'`"
	if ! dataset_exists "${dataset}"; then
		dataset="`/sbin/zfs list -H -r -o name "${pool_name}" 2>/dev/null |
		    /usr/bin/awk -v suffix="${wanted_mountpoint}" \
		    'length($0) >= length(suffix) && substr($0, length($0)-length(suffix)+1) == suffix {print; exit}'`"
	fi
	[ -n "${dataset}" ] || return 1

	# The config may already be part of the mounted root dataset.
	if [ -d "${target}" ] && /sbin/mount | /usr/bin/grep -Fq " on ${target} "; then
		return 0
	fi
	mount_dataset "${dataset}" "${target}"
}

recover_from_zfs() {
	recover_disk="$1"
	load_zfs || return 1
	read_zfs_identity "${recover_disk}" || return 1
	import_zfs_pool "${recover_disk}" || return 1

	if ! find_root_dataset; then
		select_root_dataset || {
			echo "No ZFS root dataset was selected."
			return 1
		}
	fi
	if ! mount_dataset "${root_dataset}" "${recovery_mount}"; then
		echo "Unable to mount ${root_dataset} for recovery."
		if ! select_root_dataset ||
		    ! mount_dataset "${root_dataset}" "${recovery_mount}"; then
			return 1
		fi
	fi

	# Support both modern ROOT/default/cf layouts and older pools with /cf or
	# /conf as a sibling dataset elsewhere in the pool.
	[ -d "${recovery_mount}/cf/conf" ] ||
	    mount_config_dataset /cf >/dev/null 2>&1 || true
	[ -d "${recovery_mount}/conf" ] ||
	    mount_config_dataset /conf >/dev/null 2>&1 || true

	if recover_standard_config; then
		return 0
	fi
	if choose_config_path "${recovery_mount}"; then
		return 0
	fi

	# Last resort: let the user mount any dataset as a separate browse root.
	if select_root_dataset &&
	    mount_dataset "${root_dataset}" "${recovery_selected_mount}"; then
		choose_config_path "${recovery_selected_mount}"
		return $?
	fi
	return 1
}

recover_from_ufs() {
	recover_disk="$1"
	if /sbin/mount -t ufs -o ro "/dev/${recover_disk}" "${recovery_mount}" 2>/dev/null; then
		record_mount "${recovery_mount}"
	else
		message "UFS recovery warning" "The selected UFS filesystem could not be mounted read-only.

FreeSense can run a filesystem check and retry. This writes repairs to the old installation.

Continue with the filesystem check?" || return 1
		attempts=0
		while [ ${attempts} -lt 10 ]; do
			/sbin/fsck -y -t ufs "/dev/${recover_disk}"
			if /sbin/mount -t ufs -o ro "/dev/${recover_disk}" \
			    "${recovery_mount}" 2>/dev/null; then
				record_mount "${recovery_mount}"
				break
			fi
			attempts=$((attempts+1))
		done
		/sbin/mount | /usr/bin/grep -Fq " on ${recovery_mount} " || return 1
	fi

	recover_standard_config || choose_config_path "${recovery_mount}"
}

recover_ssh_keys() {
	[ -d "${recovery_mount}/etc/ssh" ] || return 0
	for keytype in rsa ed25519; do
		private_key="${recovery_mount}/etc/ssh/ssh_host_${keytype}_key"
		public_key="${private_key}.pub"
		if [ -s "${private_key}" ] && [ -s "${public_key}" ]; then
			/bin/cp "${private_key}" "${recovery_dir}/ssh_host_${keytype}_key"
			/bin/cp "${public_key}" "${recovery_dir}/ssh_host_${keytype}_key.pub"
			echo "Recovered ${keytype} SSH key from ${recover_disk}."
		fi
	done
}

# Find candidate FreeBSD UFS and ZFS partitions.
gpart_output="`/sbin/gpart show -p 2>/dev/null`"
target_disks="`echo "${gpart_output}" |
    /usr/bin/awk '/(freebsd-ufs|freebsd-zfs)/ {print $3}'`"
set --
for try_device in ${target_disks}; do
	[ -e "/dev/${try_device}" ] || continue
	fs_details="`echo "${gpart_output}" |
	    /usr/bin/awk -v device="${try_device}" '$3 == device {print $4, $5; exit}'`"
	set -- "$@" "${try_device}" "${fs_details}"
done

if [ "$#" -eq 0 ]; then
	message "Recover config.xml" "No UFS or ZFS installation partitions were found."
	exit 1
fi

exec 3>&1
recover_disk="`"${DIALOG}" --backtitle "FreeSense Installer" \
	--title "Recover config.xml and SSH keys" \
	--menu "Select the partition containing the previous installation" \
	0 0 0 "$@" 2>&1 1>&3`"
dialog_rc=$?
exec 3>&-
[ ${dialog_rc} -eq 0 ] || exit 1

fs_type="`echo "${gpart_output}" |
    /usr/bin/awk -v device="${recover_disk}" '$3 == device {print $4; exit}'`"
echo "Attempting to recover config.xml from ${recover_disk} (${fs_type})."

recovery_ok=no
case "${fs_type}" in
freebsd-ufs)
	recover_from_ufs "${recover_disk}" && recovery_ok=yes
	;;
freebsd-zfs)
	recover_from_zfs "${recover_disk}" && recovery_ok=yes
	;;
*)
	message "Recover config.xml" "The selected partition type is not supported."
	;;
esac

if [ "${recovery_ok}" = "yes" ] &&
    [ -r "${recovery_dir}/config.xml" ] &&
    [ -s "${recovery_dir}/config.xml" ]; then
	recover_ssh_keys
else
	/bin/rm -f "${recovery_dir}/config.xml"
	message "Recover config.xml" "No configuration was recovered from the selected installation."
	exit 1
fi

cleanup_source
trap - EXIT HUP INT TERM
exit 0
