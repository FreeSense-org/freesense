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
| jquery.cookie (in `jquery-treegrid/js/`) | 1.3.1 | **Affected.** CVE-2022-23395 (prototype pollution leading to DOM XSS). OSV lists 1.3.1 as affected. No upstream fix exists, and one vendor disputes the impact. The script is loaded globally from `foot.inc`. Replace it with the already-bundled js-cookie 3.0.8. |
| d3 | 3.5.17 | Unmaintained major version (v3). No CVE located in search. Not exhaustive. |
| d3pie | 0.1.6 | Unmaintained. No CVE located in search. Not exhaustive. |
| nvd3 | 1.8.6 | Unmaintained. No CVE located in search. Not exhaustive. |
| jQuery | 4.0.0 | Current major. Not individually searched. |
| jQuery UI | 1.14.2 | Current. Not individually searched. |
| Bootstrap | 5.3.8 | Current. Not individually searched. |
| js-cookie | 3.0.8 | Current. Not individually searched. |

## Upstream pfSense advisories (2026)

FreeSense is derived from pfSense. The following upstream advisories were
checked against this code base.

| Advisory | Topic | FreeSense status |
|---|---|---|
| CVE-2026-97730 | Dashboard widget path traversal leading to PHP file inclusion (authenticated). NVD CVSS 3.1 8.5. Fixed upstream in pfSense Plus 26.07 and CE 2.9.0. | Not reproduced. The dashboard includes only widgets whose names match installed `*.widget.php` files (`index.php`, `dashboard_sequence_entry_valid`). |
| SA-26_07 | OpenVPN server `dns_domain` and `netbios_scope` accept CR/LF. | Mitigated. `openvpn_add_dhcpopts()` in `src/etc/inc/openvpn.inc` drops values with quotes or control characters, and the NetBIOS scope is validated. |
| SA-26_10 to SA-26_15, SA-26_21 | Stored XSS in WebGUI display fields (firewall log and rule descriptions, schedules, DHCP and IPsec descriptions, PPP provider and plan, traffic graph option). | Partly checked. Output escaping is present in the paths reviewed. Not every field has been traced. |
| SA-26_22 | Topic not retrieved. | Not assessed. |

## Composer runtime dependencies

| Package | Version |
|---|---|
| simplepie/simplepie | 1.9.0 |
| mikehaertl/php-shellcommand | 1.7.0 |
| nette/utils | 4.1.5 |
| symfony/console | 8.1.8 |
| symfony/cache | 8.1.8 |

- No CVE was located for simplepie 1.9.0 in search.
- `composer audit` could not be run in the audit environment because the package
  registry was not reachable. It must run in CI against the locked versions.
- PEAR XML_RPC2 is not vendored in this repository, so its version cannot be
  checked here.

## Follow-up

1. Remove jquery.cookie and use js-cookie.
2. Run `composer audit --locked` in CI, and keep Dependabot enabled
   (`.github/dependabot.yml`).
3. Plan removal or replacement of the d3 v3, d3pie and nvd3 components.
4. Pin the PEAR XML_RPC2 version in the package build so it can be audited.
