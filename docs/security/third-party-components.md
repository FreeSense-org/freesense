# Known CVEs in third-party components

Status as of 2026-10-15. This file tracks publicly known vulnerabilities in
components FreeSense ships or inherits from upstream pfSense. It covers the
JavaScript libraries under `src/usr/local/www/vendor` and the runtime Composer
dependencies in `composer.json`.

First-party code issues are not listed here. Report those privately as described
in [SECURITY.md](../../.github/SECURITY.md).

## Bundled JavaScript

| Component | Version | Status |
|---|---|---|
| jquery.cookie (in `jquery-treegrid/js/`) | 1.3.1 | **Removed.** CVE-2022-23395 (prototype pollution leading to DOM XSS) affects 1.3.1, but no page loaded the file: `foot.inc` loads js-cookie 3.0.8 and `js/jquery-cookie-shim.js`, which gives jquery-treegrid the `$.cookie` calls it needs. The unused file is removed from the package and from installed systems (`FreeSense.obsoletedfiles`). |
| js-cookie | 3.0.8 | Current. No known CVE. |
| d3 | 3.5.17 | Unmaintained major version (v3). No known CVE. |
| d3pie | 0.1.6 | Unmaintained. No known CVE. |
| nvd3 | 1.8.6 | Unmaintained. No known CVE. |

## Upstream pfSense advisories (2026)

FreeSense is derived from pfSense. The following upstream advisories were
checked against this code base.

| Advisory | Topic | FreeSense status |
|---|---|---|
| CVE-2026-97730 | Dashboard widget path traversal leading to PHP file inclusion (authenticated). Fixed upstream in pfSense Plus 26.07 and CE 2.9.0. | Not affected. The dashboard includes only widgets whose names match installed `*.widget.php` files (`dashboard_sequence_entry_valid()` in `index.php`). |
| SA-26_07 | OpenVPN server `dns_domain` and `netbios_scope` accept CR/LF. | Not affected. `openvpn_add_dhcpopts()` in `src/etc/inc/openvpn.inc` drops values with quotes or control characters, and the NetBIOS scope is validated. |

## Composer runtime dependencies

| Package | Version |
|---|---|
| simplepie/simplepie | 1.9.0 |
| mikehaertl/php-shellcommand | 1.7.0 |
| nette/utils | 4.1.5 |
| symfony/console | 8.1.8 |
| symfony/cache | 8.1.8 |

`composer audit --locked` reports no advisories for these versions
(2026-10-15). CI runs it on every pull request (`quality.yml`), and Dependabot
proposes Composer updates monthly (`.github/dependabot.yml`).

## Follow-up

1. Plan removal or replacement of the d3 v3, d3pie and nvd3 components.
2. PEAR XML_RPC2 comes from FreeBSD ports, not from this repository; check it at
   each FreeBSD pin refresh.
