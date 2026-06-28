#!/bin/sh
# import_foreign_config.sh — FreeSense installer helper.
#
# Imports the BASIC configuration from another firewall distribution (pfSense CE
# or OPNsense) into the new FreeSense install. It locates a config.xml on an
# attached USB stick, converts it to a FreeSense-compatible config.xml, and
# stages it at /tmp/recovered_config/config.xml — the same path the installer's
# config-copy step reads to drop config.xml into the freshly installed system.
#
# Scope (deliberately conservative): core networking + system basics only.
#   - pfSense:  schema is identical (FreeSense is rebranded pfSense). We rename
#               the root element and STRIP installed packages.
#   - OPNsense: schema diverged. We keep only the legacy, pfSense-compatible
#               sections (interfaces, system basics, DHCP, gateways, routes,
#               VLANs, sysctl) and DROP everything OPNsense-specific (the
#               <OPNsense> MVC tree, firewall rules, NAT, VPN, packages) so a
#               naive import can't lock you out. Review settings after first boot.
#
# Packages are NEVER imported.

STAGE_DIR=/tmp/recovered_config
STAGE=${STAGE_DIR}/config.xml
MNT=/tmp/import_mnt
SRC=/tmp/import_src_config.xml
DIALOG=${DIALOG:-/usr/bin/dialog}

msg()  { ${DIALOG} --backtitle "FreeSense Installer" --title "Import config" --msgbox "$1" 0 0; }
yesno(){ ${DIALOG} --backtitle "FreeSense Installer" --title "Import config" --yesno "$1" 0 0; }

mkdir -p "${STAGE_DIR}" "${MNT}"

# --- find a config.xml on any attached msdos/ufs partition --------------------
find_source() {
	rm -f "${SRC}"
	# msdos (FAT) partitions
	for dev in $(/sbin/gpart show -p 2>/dev/null | /usr/bin/egrep '(fat32|fat16|\!11|\!12|\!14)' | /usr/bin/awk '{print $3}'); do
		[ -e "/dev/${dev}" ] || continue
		if /sbin/mount -t msdosfs "/dev/${dev}" "${MNT}" 2>/dev/null; then
			for p in conf/config.xml config.xml; do
				if [ -r "${MNT}/${p}" ]; then cp "${MNT}/${p}" "${SRC}"; /sbin/umount "${MNT}"; return 0; fi
			done
			/sbin/umount "${MNT}" 2>/dev/null
		fi
	done
	# ufs partitions (e.g. a config export on a ufs stick)
	for dev in $(/sbin/gpart show -p 2>/dev/null | /usr/bin/egrep 'freebsd-ufs' | /usr/bin/awk '{print $3}'); do
		[ -e "/dev/${dev}" ] || continue
		if /sbin/mount -t ufs -o ro "/dev/${dev}" "${MNT}" 2>/dev/null; then
			for p in conf/config.xml config.xml cf/conf/config.xml; do
				if [ -r "${MNT}/${p}" ]; then cp "${MNT}/${p}" "${SRC}"; /sbin/umount "${MNT}"; return 0; fi
			done
			/sbin/umount "${MNT}" 2>/dev/null
		fi
	done
	return 1
}

# --- pfSense -> FreeSense: rename root, strip <installedpackages> --------------
convert_pfsense() {
	awk '
	/^[[:space:]]*<pfsense>[[:space:]]*$/  { print "<freesense>"; next }
	/^[[:space:]]*<\/pfsense>[[:space:]]*$/{ print "</freesense>"; next }
	/^[[:space:]]*<installedpackages>/     { drop=1 }
	drop { if ($0 ~ /<\/installedpackages>/) drop=0; next }
	{ print }
	' "${SRC}" > "${STAGE}"
}

# --- OPNsense -> FreeSense: keep compatible sections, drop the rest ------------
convert_opnsense() {
	# Rename the root, then keep only legacy pfSense-compatible top-level sections.
	# DROP the OPNsense MVC tree + schema-divergent sections (firewall/NAT/VPN/etc.)
	# and the OPNsense <version> (so FreeSense applies its own config upgrade).
	# POSIX awk only (the installer runs FreeBSD base awk, not gawk): a small
	# state machine tracks the current top-level section ("cur") so nested elements
	# that happen to share a dropped name are never misclassified.
	awk '
	BEGIN{
		split("OPNsense filter nat trafficshaper load_balancer ipsec openvpn dhcrelay widgets installedpackages ca cert revoked_certs syslog firmware bootup version", a, " ")
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
}

# --- main ---------------------------------------------------------------------
exec 3>&1
BRAND=$(${DIALOG} --backtitle "FreeSense Installer" \
	--title "Import from another firewall" \
	--menu "Import the BASIC configuration from another firewall distribution.\n\nPackages are not imported. Review settings after the first boot." \
	0 0 0 \
	"pfSense"  "Import a pfSense CE config.xml (full core config, no packages)" \
	"OPNsense" "Import an OPNsense config.xml (basic networking only)" \
	"Cancel"   "Return to the installer menu" \
	2>&1 1>&3) || { exec 3>&-; exit 1; }
exec 3>&-
[ "${BRAND}" = "Cancel" ] && exit 1

msg "Insert the USB stick that holds the exported config.xml (in /, /conf/, or /cf/conf/), then choose OK to scan for it."

if ! find_source; then
	msg "No config.xml found on any attached USB partition.\n\nExport the config from your old firewall, copy config.xml to a FAT/UFS USB stick (in the root or a conf/ folder), plug it in, and try again."
	exit 1
fi

# Identify the config by its root element. A FreeSense config is <freesense>;
# pfSense is <pfsense>; OPNsense is <opnsense>. Importing the WRONG brand is a hard
# error (no silent "import anyway") so a mismatched/corrupt file can't be applied.
ROOT=$(/usr/bin/grep -m1 -oE '<(pfsense|opnsense|freesense)>' "${SRC}" | /usr/bin/tr -d '<>')
case "${BRAND}" in
pfSense)
	if [ "${ROOT}" = "freesense" ]; then
		msg "Error: this is already a FreeSense config (<freesense>), not a pfSense config.\n\nUse \"Recover config.xml\" to restore a FreeSense backup. Nothing was imported."
		exit 1
	elif [ "${ROOT}" != "pfsense" ]; then
		msg "Error: not a valid pfSense config.\n\nThe file's root element is <${ROOT:-unknown}>, but a pfSense config must be <pfsense>. Nothing was imported."
		exit 1
	fi
	convert_pfsense ;;
OPNsense)
	if [ "${ROOT}" = "freesense" ]; then
		msg "Error: this is already a FreeSense config (<freesense>), not an OPNsense config.\n\nUse \"Recover config.xml\" to restore a FreeSense backup. Nothing was imported."
		exit 1
	elif [ "${ROOT}" != "opnsense" ]; then
		msg "Error: not a valid OPNsense config.\n\nThe file's root element is <${ROOT:-unknown}>, but an OPNsense config must be <opnsense>. Nothing was imported."
		exit 1
	fi
	convert_opnsense ;;
esac

if [ ! -s "${STAGE}" ] || ! /usr/bin/grep -q '<freesense>' "${STAGE}"; then
	rm -f "${STAGE}"
	msg "Conversion failed — the config was not imported. The installer will continue with a default configuration."
	exit 1
fi

# mark so the post-install config copy treats this as an imported config
/usr/bin/touch "${STAGE_DIR}/imported_foreign_config" 2>/dev/null

msg "Imported a ${BRAND} configuration (basic settings, no packages).\n\nIt will be applied to the new system during installation. After the first boot, review Interfaces, System, and Firewall settings.\n\nNow choose \"Install\" from the menu to continue."
exit 0
