# FreeSense — Foreign-config import guard & base-OS sanitizer (GUI side)

Goal: make the running-system config restore (Diagnostics > Backup & Restore) as
safe and capable as the (now-fixed) installer importer, using the SAME
detect → convert → package-map → sanitize flow, so importing an OPNsense (or
older pfSense) config can never brick the box and packages are auto-mapped +
suggested instead of dropped.

## Root causes this addresses (all from the OPNsense import incident)
1. Base FreeBSD users/groups/shells wiped  → sshd dead, console dropped to raw login:
2. Empty foreign tags (`<disableconsolemenu/>`) read as "enabled"  → menu off
3. IPsec NAT-T / schema divergence  → one-way VPN (already hand-fixed on the box)
4. GUI restore has ZERO foreign-config detection (only checks `<freesense>` root)

## Pieces

### A. New module `src/etc/inc/config_import_guard.inc`
- `freesense_config_detect_lineage($xmlstring)` → 'freesense'|'pfsense'|'opnsense'|'unknown'
  Sniff root element + corroborating markers (OPNsense: `<theme>opnsense`, `<OPNsense>`,
  `<system><firmware>`; pfSense: `<pfsense>` root + `<version>` decimal scheme).
- `freesense_config_convert_foreign($xmlstring, $lineage)` → converted `<freesense>` string
  - pfSense: rename root only (keep everything incl. installedpackages).
  - OPNsense: rename root; DROP `<OPNsense>` MVC tree + schema-incompatible subsystems
    (filter/nat/ipsec/openvpn/trafficshaper/load_balancer/dhcrelay/widgets/version/bootup);
    KEEP ca/cert/system/users. Strip known-toxic empty flag tags
    (`disableconsolemenu`, etc.). Mirrors the installer's convert_opnsense() awk.
- `freesense_config_sanitize_base_os()` → DEFENCE-IN-DEPTH, run after EVERY restore:
  - ensure FreeBSD base users/groups exist (sshd + daemon/operator/_pflogd/_dhcp/…);
  - ensure `/etc/rc.initial` is in `/etc/shells`;
  - ensure root shell = `/etc/rc.initial`;
  - `pwd_mkdb -p /etc/master.passwd`.
  Idempotent; cheap; guarantees the box always boots + is loginable.
  Reuses the base-user table already proven on the live box (see repair_passwd.sh).

### B. Wire into GUI restore — `.../www/backup.inc` execPost(), full-restore branch (~L327)
- BEFORE the `<freesense>` root check: detect lineage on `$data`; if foreign,
  convert to `<freesense>` (so downstream logic is unchanged) and record a notice +
  the package suggestions for the confirmation UI.
- AFTER config_install + the existing console_configure() (~L409): call
  `freesense_config_sanitize_base_os()`.

### C. Wire into `restore_backup()` — `src/etc/inc/config.lib.inc:142`
- This is the programmatic/installer-first-boot path. After the config.xml is
  written (~L200 disable_security_checks), call sanitize_base_os() too, so the
  installer path is equally protected.

### D. GUI confirmation + package checklist — `diag_backup.php`
- When a foreign config is detected on upload, show an interstitial:
  "Detected an OPNsense/pfSense config. It will be converted. Packages found:
   [checklist, auto-mapped pre-checked, guesses unchecked]. Convert & restore?"
- Selected packages written to `/conf/needs_package_sync` companion manifest
  so first boot installs them (same manifest name the installer uses:
  import_packages). Reuses config_import_pkgmap.inc (already built + tested).

## Test strategy (on the live box, via GUI PHP-exec)
- Unit: detect_lineage on synthetic pf/opn/free roots; convert_foreign keeps
  ca/cert/users, drops OPNsense tree; sanitize_base_os idempotent + fixes a
  deliberately-broken shells/passwd.
- Integration: feed a small OPNsense-shaped XML through the whole execPost path
  in a dry-run (no reboot) and assert the resulting config parses as freesense.

## Status
- [x] Shared package map + PHP map engine (config_import_pkgmap.{map,inc}) — tested
- [x] Installer importer rewritten to same flow + keeps certs/users — tested
- [x] A. config_import_guard.inc — tested (detect, convert, sanitize break/repair)
- [x] B. backup.inc wire-in — tested (foreign config converts + parses as freesense)
- [x] C. config.lib.inc wire-in — sanitize after restore_backup write
- [x] D. diag_backup.php confirmation UI + pkg summary/manifest — tested
- All PHP files lint clean (php -l); installer script sh -n clean.

## Files changed (source tree)
- NEW src/etc/config_import_pkgmap.map           (shared pkg map, both flows)
- NEW src/etc/inc/config_import_pkgmap.inc        (pkg auto-map + suggest engine)
- NEW src/etc/inc/config_import_guard.inc         (detect + convert + sanitize)
- MOD tools/installer/import_foreign_config.sh    (same flow; keeps certs/users)
- MOD tools/builder_common.sh                     (stage .map into installer chroot)
- MOD src/etc/inc/config.lib.inc                  (sanitize after restore_backup)
- MOD src/usr/local/FreeSense/include/www/backup.inc (GUI restore detect+convert+pkg+sanitize)
- MOD src/usr/local/www/diag_backup.php           (info banner)
