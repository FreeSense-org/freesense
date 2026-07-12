#!/bin/sh
# import_foreign_config.sh — FreeSense installer helper.
#
# Imports a configuration from another firewall distribution (pfSense CE or
# OPNsense) into the new FreeSense install. It locates a config.xml on an
# attached USB stick, converts it to a FreeSense-compatible config.xml, maps the
# source packages to FreeSense packages (auto-mapping the confident ones and
# SUGGESTING the rest for you to confirm), and stages the result at
# /tmp/recovered_config/config.xml — the same path the installer's config-copy
# step reads to drop config.xml into the freshly installed system.
#
# This uses the SAME detect -> convert -> package-map -> suggest flow as the
# running-system GUI importer (diag_backup.php), and reads the SAME shared
# package map (config_import_pkgmap.map) so the two never drift.
#
# Scope:
#   - pfSense:  schema is identical (FreeSense is rebranded pfSense). We rename
#               the root element and keep the full config. Packages map 1:1.
#   - OPNsense: schema diverged. We keep the legacy pfSense-compatible sections
#               INCLUDING certs/CA and users (so you are not locked out and your
#               PKI survives), and DROP only the OPNsense-specific <OPNsense> MVC
#               tree and schema-incompatible subsystems that would break the
#               parser. Package settings are not carried; the mapped packages are
#               reinstalled and you reconfigure them after first boot.
#
# Nothing is installed by this script. It only stages config.xml + a package
# manifest; first boot reads them.

STAGE_DIR=/tmp/recovered_config
STAGE=${STAGE_DIR}/config.xml
PKG_MANIFEST=${STAGE_DIR}/import_packages
MNT=/tmp/import_mnt
SRC=/tmp/import_src_config.xml
DIALOG=${DIALOG:-/usr/bin/bsddialog}
# Shared package map (staged next to this script by the builder).
PKGMAP=${PKGMAP:-/root/config_import_pkgmap.map}

msg()  { ${DIALOG} --backtitle "FreeSense Installer" --title "Import config" --msgbox "$1" 0 0; }
yesno(){ ${DIALOG} --backtitle "FreeSense Installer" --title "Import config" --yesno "$1" 0 0; }

mkdir -p "${STAGE_DIR}" "${MNT}"

# --- select and read a config.xml from FAT, UFS, or ZFS ------------------------
copy_source_from_mount() {
	for p in config.xml conf/config.xml cf/conf/config.xml; do
		if [ -r "${MNT}/${p}" -a -s "${MNT}/${p}" ]; then
			/bin/cp "${MNT}/${p}" "${SRC}"
			return 0
		fi
	done
	return 1
}

find_zfs_source() {
	dev="$1"
	zdb_label="`/sbin/zdb -l /dev/${dev} 2>/dev/null`"
	pool_name="`echo "${zdb_label}" | /usr/bin/sed -n "s/^[[:space:]]*name: '\([^']*\)'.*/\1/p" | /usr/bin/head -1`"
	pool_guid="`echo "${zdb_label}" | /usr/bin/awk '/^[[:space:]]*pool_guid:/ {print $2; exit}'`"

	if [ -z "${pool_name}" -o -z "${pool_guid}" ]; then
		msg "Unable to read ZFS pool information from ${dev}."
		return 1
	fi

	if ! /sbin/kldstat -q -m zfs; then
		if ! /usr/bin/timeout 15 /sbin/kldload zfs >/tmp/import-zfs-load.log 2>&1; then
			msg "Unable to load ZFS support.\n\n`cat /tmp/import-zfs-load.log 2>/dev/null`"
			return 1
		fi
	fi

	if ! /usr/bin/timeout 30 /sbin/zpool import -N -f \
	    -o readonly=on -o cachefile=none -R "${MNT}" \
	    -d "/dev/${dev}" "${pool_guid}" >/tmp/import-zpool.log 2>&1; then
		msg "Unable to import ${pool_name} from ${dev}.\n\n`cat /tmp/import-zpool.log 2>/dev/null`"
		return 1
	fi

	root_dataset="`/sbin/zpool get -H -o value bootfs "${pool_name}" 2>/dev/null`"
	if [ -z "${root_dataset}" -o "${root_dataset}" = "-" ]; then
		root_dataset="`/sbin/zfs list -H -r -o name,mountpoint "${pool_name}" 2>/dev/null | /usr/bin/awk '$2 == "/" {print $1; exit}'`"
	fi
	if [ -z "${root_dataset}" ]; then
		root_dataset="`/sbin/zfs list -H -r -o name "${pool_name}" 2>/dev/null | /usr/bin/awk '/\/ROOT\/default$/ {print; exit}'`"
	fi

	if [ -z "${root_dataset}" ] || \
	    ! /sbin/mount -t zfs -o ro "${root_dataset}" "${MNT}" 2>/dev/null; then
		/sbin/zpool export -f "${pool_name}" 2>/dev/null
		msg "Unable to mount the active boot environment from ${pool_name}."
		return 1
	fi

	# pfSense/FreeSense commonly place configuration in a dedicated /cf
	# dataset. OPNsense generally keeps /conf in the root, but also support a
	# dedicated /conf dataset if present.
	for mp in /cf /conf; do
		dataset="`/sbin/zfs list -H -r -o name,mountpoint "${pool_name}" 2>/dev/null | /usr/bin/awk -v wanted="${mp}" '$2 == wanted {print $1; exit}'`"
		if [ -n "${dataset}" ]; then
			/bin/mkdir -p "${MNT}${mp}"
			/sbin/mount -t zfs -o ro "${dataset}" "${MNT}${mp}" 2>/dev/null || true
		fi
	done

	copy_source_from_mount
	rc=$?
	/sbin/umount "${MNT}/conf" 2>/dev/null
	/sbin/umount "${MNT}/cf" 2>/dev/null
	/sbin/umount "${MNT}" 2>/dev/null
	/sbin/zpool export -f "${pool_name}" 2>/dev/null
	return ${rc}
}

find_source() {
	rm -f "${SRC}"
	gpart_output="`/sbin/gpart show -p 2>/dev/null`"
	targets="`echo "${gpart_output}" | /usr/bin/egrep '(fat32|fat16|ms-basic-data|\!11|\!12|\!14|freebsd-ufs|freebsd-zfs)' | /usr/bin/awk '{print $3}'`"
	target_list=""

	for dev in ${targets}; do
		[ -e "/dev/${dev}" ] || continue
		details="`echo "${gpart_output}" | /usr/bin/grep "[[:space:]]${dev}[[:space:]]" | /usr/bin/awk '{print $4, $5; exit}'`"
		target_list="${target_list} \"${dev}\" \"${details}\""
	done

	if [ -z "${target_list}" ]; then
		msg "No FAT, UFS, or ZFS source partitions were found."
		return 1
	fi

	exec 3>&1
	dev="`echo ${target_list} | xargs -o ${DIALOG} --backtitle "FreeSense Installer" \
		--title "Import pfSense or OPNsense config" \
		--menu "Select the partition containing config.xml" \
		0 0 0 2>&1 1>&3`"
	rc=$?
	exec 3>&-
	[ ${rc} -eq 0 ] || return 2

	part_type="`echo "${gpart_output}" | /usr/bin/grep "[[:space:]]${dev}[[:space:]]" | /usr/bin/awk '{print $4; exit}'`"
	case "${part_type}" in
	freebsd-zfs)
		find_zfs_source "${dev}"
		return $?
		;;
	freebsd-ufs)
		fs_type=ufs
		;;
	*)
		fs_type=msdosfs
		;;
	esac

	if /sbin/mount -t "${fs_type}" -o ro "/dev/${dev}" "${MNT}" 2>/dev/null; then
		copy_source_from_mount
		rc=$?
		/sbin/umount "${MNT}" 2>/dev/null
		return ${rc}
	fi
	return 1
}

# --- detect lineage by root element -------------------------------------------
# echoes: pfsense | opnsense | freesense | unknown
detect_lineage() {
	/usr/bin/grep -m1 -oE '<(pfsense|opnsense|freesense)>' "${SRC}" 2>/dev/null | /usr/bin/tr -d '<>'
}

# --- pfSense -> FreeSense: rename root, keep everything ------------------------
# (Packages are handled separately via the package map; we keep
# <installedpackages> so package SETTINGS survive.)
convert_pfsense() {
	awk '
	/^[[:space:]]*<pfsense>[[:space:]]*$/  { print "<freesense>"; next }
	/^[[:space:]]*<\/pfsense>[[:space:]]*$/{ print "</freesense>"; next }
	{ print }
	' "${SRC}" > "${STAGE}"
}

# --- OPNsense -> FreeSense: keep compatible sections, drop the rest ------------
# Keeps interfaces/system/DHCP/gateways/routes/VLANs/sysctl AND ca/cert/users so
# the PKI and accounts survive. Drops the <OPNsense> MVC tree and the
# schema-divergent subsystems (firewall/nat/vpn/etc.) plus the OPNsense <version>
# so FreeSense applies its own config upgrade. POSIX awk only.
convert_opnsense() {
	awk '
	BEGIN{
		# Top-level sections to DROP (schema-incompatible or OPNsense-only).
		# NOTE: ca/cert/system/users are intentionally NOT dropped anymore.
		split("OPNsense filter nat trafficshaper load_balancer ipsec openvpn dhcrelay widgets installedpackages bootup version", a, " ")
		for(i in a) drop[a[i]]=1
		cur=""; keep=1
	}
	function tn(s,   p,t){ sub(/^[ \t]+/,"",s); sub(/[ \t\r]+$/,"",s); if(substr(s,1,1)!="<")return ""; if(substr(s,2,1)=="/")return ""; p=index(s,">"); if(p==0)return ""; t=substr(s,2,p-2); if(index(t," ")>0||index(t,"/")>0)return ""; return t }
	{
		raw=$0
		if (raw ~ /^[ \t]*<opnsense>[ \t]*$/){ print "<freesense>"; next }
		if (raw ~ /^[ \t]*<\/opnsense>[ \t]*$/){ print "</freesense>"; next }
		if (cur==""){
			name=tn(raw)
			if(name!=""){
				if(index(raw, "</" name ">")>0){ if(!(name in drop)) print raw; next }
				cur=name; keep=((name in drop)?0:1); if(keep) print raw; next
			}
			print raw; next
		} else {
			closed = (raw ~ ("^[ \t]*</" cur ">[ \t]*$"))
			if(keep) print raw
			if(closed){ cur=""; keep=1 }
			next
		}
	}
	' "${SRC}" > "${STAGE}"

	# OPNsense stores Unbound configuration inside the dropped MVC subtree. Seed
	# FreeSense's resolver when no compatible top-level resolver survived, or the
	# imported firewall has no DNS and cannot reach the update repositories.
	if ! /usr/bin/grep -q '<unbound>' "${STAGE}"; then
		/usr/bin/awk '
		/<\/freesense>/ { print "\t<unbound>"; print "\t\t<enable></enable>"; print "\t</unbound>" }
		{ print }
		' "${STAGE}" > "${STAGE}.dns" && /bin/mv "${STAGE}.dns" "${STAGE}"
	fi
}

# --- extract source package/plugin names from the ORIGINAL config -------------
# pfSense/FreeSense: <installedpackages><package><name>...
# OPNsense:          <system><firmware><plugins>os-a,os-b,...</plugins>
# echoes one source name per line.
source_packages() {
	_lin="$1"
	case "${_lin}" in
	pfsense|freesense)
		/usr/bin/grep -oE '<name>[^<]+</name>' "${SRC}" 2>/dev/null | \
			/usr/bin/sed -E 's/<\/?name>//g'
		# Only names that appear within installedpackages: filter to the block.
		;;
	opnsense)
		/usr/bin/sed -n 's/.*<plugins>\(.*\)<\/plugins>.*/\1/p' "${SRC}" 2>/dev/null | \
			/usr/bin/tr ', \t' '\n\n\n\n' | /usr/bin/grep -v '^$'
		;;
	esac
}

# For pfSense we must restrict <name> matches to the installedpackages block,
# because <name> is used elsewhere too. Extract that block first.
pfsense_package_names() {
	awk '
	/<installedpackages>/{inp=1}
	/<\/installedpackages>/{inp=0}
	inp && match($0, /<name>[^<]+<\/name>/){
		s=substr($0,RSTART,RLENGTH); gsub(/<\/?name>/,"",s); print s
	}
	' "${SRC}"
}

# --- map ONE source package via the shared table ------------------------------
# args: <lineage> <source-name>
# echoes: "<target>|<confidence>"  (target empty => no equivalent)
map_one() {
	_lin="$1"; _name="$2"
	# OPNsense lookup strips a leading os-
	_key="${_name}"
	[ "${_lin}" = "opnsense" ] && _key=$(echo "${_name}" | /usr/bin/sed -E 's/^os-//')
	if [ -r "${PKGMAP}" ]; then
		_line=$(/usr/bin/awk -v l="${_lin}" -v k="${_key}" '
			$1==l && $2==k { print $3"|"$4; found=1; exit }
			END{ if(!found) print "" }
		' "${PKGMAP}")
		if [ -n "${_line}" ]; then
			# "-" target means core/none per confidence; normalize
			echo "${_line}" | /usr/bin/sed 's/^-|/|/'
			return 0
		fi
	fi
	# identity fallback for same-lineage pfSense; guess for opnsense bare name
	if [ "${_lin}" = "pfsense" ] || [ "${_lin}" = "freesense" ]; then
		echo "${_name}|likely"
	else
		echo "${_key}|guess"
	fi
}

# --- build a dialog --checklist of package suggestions, capture selection -----
# Populates ${PKG_MANIFEST} with the chosen FreeSense package names.
choose_packages() {
	_lin="$1"
	: > "${PKG_MANIFEST}"

	# gather source names
	if [ "${_lin}" = "opnsense" ]; then
		_names=$(source_packages opnsense)
	else
		_names=$(pfsense_package_names)
	fi
	[ -z "${_names}" ] && { msg "No packages were detected in the source config.\n\nOnly the base configuration will be imported."; return 0; }

	# build checklist items: tag=freesense-name  item=desc  status=on/off
	_items=""
	_skipped=""
	_core=""
	for _n in ${_names}; do
		_res=$(map_one "${_lin}" "${_n}")
		_tgt=$(echo "${_res}" | /usr/bin/cut -d'|' -f1)
		_conf=$(echo "${_res}" | /usr/bin/cut -d'|' -f2)
		case "${_conf}" in
		exact|likely)
			_items="${_items} \"${_tgt}\" \"${_n} -> ${_tgt} (${_conf})\" on"
			;;
		guess)
			[ -n "${_tgt}" ] && _items="${_items} \"${_tgt}\" \"${_n} -> ${_tgt}? (please confirm)\" off"
			;;
		core)
			_core="${_core}\n  ${_n} (built into FreeSense)"
			;;
		*)
			_skipped="${_skipped}\n  ${_n} (no FreeSense equivalent)"
			;;
		esac
	done

	if [ -n "${_items}" ]; then
		exec 3>&1
		_sel=$(eval ${DIALOG} --backtitle '"FreeSense Installer"' \
			--title '"Packages to install"' \
			--checklist '"Auto-mapped packages are pre-checked. Suggested (uncertain) matches are unchecked - tick the ones you want. Package SETTINGS are only carried for pfSense; OPNsense package settings must be reconfigured after first boot."' \
			0 0 0 ${_items} 2>&1 1>&3)
		_rc=$?
		exec 3>&-
		if [ ${_rc} -eq 0 ]; then
			for _p in ${_sel}; do
				echo "${_p}" | /usr/bin/tr -d '"' >> "${PKG_MANIFEST}"
			done
		fi
	fi

	# report core + skipped so nothing is silently dropped
	_note=""
	[ -n "${_core}" ]    && _note="${_note}\nAlready built into FreeSense (no package needed):${_core}\n"
	[ -n "${_skipped}" ] && _note="${_note}\nNo FreeSense equivalent - skipped:${_skipped}\n"
	[ -n "${_note}" ]    && msg "Package mapping summary:\n${_note}"
	return 0
}

# --- main ---------------------------------------------------------------------
msg "Import from an existing pfSense/OPNsense installation or an exported config.xml on USB.\n\nSelect its FAT, UFS, or ZFS partition on the next screen. The source is mounted read-only."

find_source
find_rc=$?
case ${find_rc} in
0)
	;;
2)
	# User cancelled the partition menu.
	exit 1
	;;
*)
	msg "No readable config.xml was found on the selected partition.\n\nExpected locations are /config.xml, /conf/config.xml, or /cf/conf/config.xml."
	exit 1
	;;
esac

# Auto-detect the source distribution by its root element.
LINEAGE=$(detect_lineage)
case "${LINEAGE}" in
freesense)
	msg "This is already a FreeSense config (<freesense>).\n\nUse \"Recover config.xml\" to restore a FreeSense backup instead. Nothing was imported."
	exit 1
	;;
pfsense)
	yesno "Detected a pfSense CE config.\n\nFreeSense is rebranded pfSense, so the full configuration (including package settings) can be imported. Packages will be mapped 1:1 and you choose which to install.\n\nImport it now?" || exit 1
	convert_pfsense
	;;
opnsense)
	yesno "Detected an OPNsense config.\n\nBasic networking, system settings, certificates and users will be imported. OPNsense-specific firewall/NAT/VPN/package settings are NOT carried and must be reconfigured after first boot.\n\nImport it now?" || exit 1
	convert_opnsense
	;;
*)
	msg "Could not recognize this config.\n\nThe root element is <${LINEAGE:-unknown}>, but it must be <pfsense> or <opnsense>. Nothing was imported."
	exit 1
	;;
esac

# Validate the conversion produced a FreeSense config.
if [ ! -s "${STAGE}" ] || ! /usr/bin/grep -q '<freesense>' "${STAGE}"; then
	rm -f "${STAGE}"
	msg "Conversion failed — the config was not imported. The installer will continue with a default configuration."
	exit 1
fi

# Map + choose packages (same suggestion flow as the GUI importer).
choose_packages "${LINEAGE}"

# mark so the post-install config copy treats this as an imported config
/usr/bin/touch "${STAGE_DIR}/imported_foreign_config" 2>/dev/null

_pkgcount=0
[ -f "${PKG_MANIFEST}" ] && _pkgcount=$(/usr/bin/wc -l < "${PKG_MANIFEST}" | /usr/bin/tr -d ' ')

msg "Imported a ${LINEAGE} configuration.\n\n${_pkgcount} package(s) selected to install on first boot.\n\nAfter the first boot, review Interfaces, DNS Resolver, System, Firewall, and (for OPNsense imports) VPN settings.\n\nNow choose \"Install\" from the menu to continue."
exit 0
