# Known CVEs in third-party components

Status as of 2026-11-09. This file tracks publicly known vulnerabilities in
components FreeSense ships or inherits from upstream pfSense. It covers the
JavaScript libraries under `src/usr/local/www/vendor`, the runtime Composer
dependencies (shipped by `devel/FreeSense-composer-deps` in
`freesense-system-ports`), the ports the System image pins, and the upstream
pfSense advisories.

First-party code issues are not listed here. Report those privately as described
in [SECURITY.md](../../.github/SECURITY.md).

## Bundled JavaScript

Versions are read from the files' own banners.

| Component | Version | Status |
|---|---|---|
| jQuery | 4.0.0 | Current. No known CVE. |
| jQuery UI | 1.14.2 | Current. No known CVE. |
| Bootstrap (CSS and bundle) | 5.3.8 | Current. No known CVE. |
| Font Awesome Free | 7.3.1 | Current. |
| js-cookie | 3.0.8 | Current. No known CVE. |
| jquery.cookie (in `jquery-treegrid/js/`) | 1.3.1 | **Removed.** CVE-2022-23395 (prototype pollution leading to DOM XSS) affects 1.3.1, but no page loaded the file: `foot.inc` loads js-cookie 3.0.8 and `js/jquery-cookie-shim.js`, which gives jquery-treegrid the `$.cookie` calls it needs. The unused file is removed from the package and from installed systems (`FreeSense.obsoletedfiles`). |
| jquery-treegrid | 0.3.0 | Unmaintained. No known CVE. Its development files (Gruntfile.js, package.json, bower.json, README.md) are still served. |
| d3 | 3.5.17 | Unmaintained major version (v3). No known CVE. |
| d3pie | 0.1.8 (internal version string 0.1.6) | Unmaintained. No known CVE. |
| nvd3 | 1.8.6 | Unmaintained; its tooltips insert HTML. No known CVE. |
| visibility.js | 2.0.2 | Unmaintained. No known CVE. |
| sortable.js | unversioned, locally modified | No known CVE. |
| csrf-magic | 1.0.4 | Unmaintained. No known CVE. |

## Composer runtime dependencies

| Package | Version | Status |
|---|---|---|
| simplepie/simplepie | 1.9.0 | **CVE-2026-111234** (local file read: a feed redirects or autodiscovers SimplePie to a `file://` URL). No fixed release. **Mitigated:** the RSS widget fetches feeds itself over http(s) only and hands SimplePie the raw text with autodiscovery off (`rss.widget.php`). `composer audit` does not list this CVE yet. |
| mikehaertl/php-shellcommand | 1.7.0 | No advisories. |
| nette/utils | 4.1.5 | No advisories. |
| symfony/console | 8.1.8 | No advisories. |
| symfony/cache | 8.1.8 | No advisories. No longer used by the RSS widget. |

`composer audit --locked` reports no advisories for these versions
(2026-11-09). CI runs it on every pull request (`quality.yml`), and Dependabot
proposes Composer updates monthly (`.github/dependabot.yml`). The shipped
packages come from the `FreeSense-composer-deps` port, which also ships
`psr/simple-cache` 3.0.0 (not in `composer.lock`); CI does not audit that
port's versions.

## System ports (ports pin of 2026-10-23, FreeBSD source of 2026-10-26)

Fixes published after the current pins. Each arrives with the next FreeBSD pin
unless noted.

| Component | Pinned | Fixed in | Advisories |
|---|---|---|---|
| Suricata (optional package) | 8.0.7 | 8.0.8 | 32 issues fixed upstream (6 high); CVE ids pending |
| OpenSSH (FreeBSD base, sshd off by default) | 10.4p1 | 10.5p1, 10.6p1 | Security fixes without CVE ids; the compression issues apply after sign-in only |
| expat (port and base libbsdxml) | 2.9.0 | 2.10.0 | CVE-2026-113351, -115506, -117610, -118370 (denial of service) |
| sqlite3 (port and base) | 3.53.4 | 3.54.0 | CVE-2026-119371 to -119379, -119591; need a hostile database file or SQL text. Only the captive portal session database uses it. |
| net-snmp (optional package) | 5.9.5.2 | no fixed release | CVE-2026-89147 (SMUX denial of service); only with SMUX enabled |
| Python 3.12 imaplib and poplib | 3.12.15 | no fixed release | CVE-2025-15366, CVE-2025-15367; FreeSense does not use these modules |
| sshguard | 2.5.1 (stock FreeBSD port) | — | No CVE. The stock scanner has no pattern for webConfigurator login failures (the pfSense-SA-24_06 problem), so failed WebUI, XML-RPC and REST API logins are never blocked. Needs a port patch. |

phpseclib (`freesense-system-ports` `security/phpseclib`, not a FreeBSD port, so
VuXML never lists it) is updated from 2.0.17 to 2.0.56, which fixes
CVE-2021-30130, CVE-2023-52892, CVE-2024-27354, CVE-2024-27355, CVE-2026-32935,
CVE-2026-40194, CVE-2026-44167 and CVE-2026-55599. Only the captive portal
voucher key generator uses it.

The fixes listed here before (nginx 1.30.6, unbound 1.26.2, curl 8.23.0,
miniupnpd 2.3.11, ca_root_nss 3.132 with FreeBSD-SA-26:73.caroot, OpenVPN
2.7.8) are in the current pins.

## Upstream pfSense advisories

FreeSense is derived from pfSense. Every 2024-2026 advisory was checked
against this code base. The ones not listed are not affected or do not apply
(no Auto Config Backup code, for example).

| Advisory | Topic | FreeSense status |
|---|---|---|
| SA-26_22 (CVE-2026-92182) | XML-RPC skips the privilege check for LDAP and RADIUS users (no uid). | **Fixed** (`xmlrpc.php` `auth()`). |
| CVE-2026-111215 | Command injection through the wireless clone parent interface. | **Fixed** (`interfaces_wireless_edit.php`, `interfaces.inc`). |
| SA-24_06, SA-25_09 | sshguard misses webConfigurator login failures. | **Affected**: see sshguard above. |
| SA-26_04 (CVE-2026-38961) | RSS widget title XSS. | Not affected. Links and item text are also escaped and limited to http(s). |
| SA-26_21 (CVE-2026-67189) | Traffic graph host name XSS. | Not affected on the page. `bandwidth_by_ip.php` answers as text/plain. |
| SA-26_18 | Dashboard widget path traversal leading to PHP file inclusion (authenticated). Fixed upstream in pfSense Plus 26.07 and CE 2.9.0. | Not affected. The dashboard includes only widgets whose names match installed `*.widget.php` files (`dashboard_sequence_entry_valid()` in `index.php`). No CVE record was found for the ID this file listed before (CVE-2026-97730). |
| SA-26_07 | OpenVPN server `dns_domain` and `netbios_scope` accept CR/LF. | Not affected. `openvpn_add_dhcpopts()` in `src/etc/inc/openvpn.inc` drops values with quotes or control characters, and the NetBIOS scope is validated. |

## Follow-up

1. Check that the next FreeBSD pin carries Suricata 8.0.8, OpenSSH 10.5p1 or
   later, expat 2.10.0 and sqlite3 3.54; otherwise overlay the port.
2. Ship an sshguard port patch that detects webConfigurator login failures.
3. Replace phpseclib (voucher keys can be made with OpenSSL).
4. Plan removal or replacement of the d3 v3, d3pie and nvd3 components and the
   jquery-treegrid development files.
5. PEAR XML_RPC2 comes from FreeBSD ports, not from this repository; check it at
   each FreeBSD pin refresh.
