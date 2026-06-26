#!/bin/sh
# Rebrand bsdinstall startbsdinstall installer chrome: pfSense -> FreeSense.
# FreeBSD-src is reset every build, so this is re-applied by freesense-confrename.sh.
SBI="$1"
[ -f "$SBI" ] || { echo "fs-rebrand-installer: $SBI not found"; exit 0; }

# 1) Replace the ESF/Netgate copyright heredoc body with an honest FreeSense/Apache notice.
awk '
index($0,"msg=$(cat <<EOD")==1 {
  print
  print "FreeSense - Copyright and License Notice"
  print ""
  print "FreeSense is a community rebuild and rebrand of the open-source"
  print "pfSense(R) CE firewall distribution, licensed under the Apache"
  print "License, Version 2.0."
  print ""
  print "FreeSense is a derivative work of pfSense CE, Copyright 2004-2016"
  print "Electric Sheep Fencing, LLC and Copyright 2014-2026 Rubicon"
  print "Communications, LLC (Netgate), originally published under the Apache"
  print "License 2.0. Original copyright notices are retained per that license."
  print "Portions originally based on m0n0wall."
  print ""
  print "\"pfSense\" is a registered trademark of Electric Sheep Fencing, LLC,"
  print "licensed to Netgate. FreeSense is NOT pfSense and is not affiliated"
  print "with or endorsed by Netgate or Electric Sheep Fencing; the name is used"
  print "only to identify the upstream project from which FreeSense is derived."
  print ""
  print "This software is provided \"AS IS\", without warranty of any kind. See"
  print "the LICENSE file or http://www.apache.org/licenses/LICENSE-2.0"
  skip=1; next
}
skip && $0=="EOD" { skip=0; print; next }
skip { next }
{ print }
' "$SBI" > "$SBI.fsnew" && mv "$SBI.fsnew" "$SBI"

# 2) Rebrand hardcoded dialog strings + installer product name (leave device-label/ZFS-pool alone).
sed -i '' \
  -e 's/pfSense Installer/FreeSense Installer/g' \
  -e 's/Welcome to pfSense!/Welcome to FreeSense!/g' \
  -e 's/Install pfSense/Install FreeSense/g' \
  -e 's/Installation of pfSense complete/Installation of FreeSense complete/g' \
  -e 's/OSNAME=pfSense/OSNAME=FreeSense/g' \
  "$SBI"
echo "fs-rebrand-installer: rebranded $SBI"
