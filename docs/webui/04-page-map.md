# 04 — Page map and assignments

Every page in `src/usr/local/www`, grouped by worker area. One worker area produces
one PR per phase. Columns:

- **Type**: List, Editor, Settings, Status, Tool, Log, Special (visual layer only), or Endpoint (no UI, don't touch)
- **Do**: the conversion; `R1`/`R2` are consolidation rules from `PLAN.md`
- **Ph**: phase (A pilot, B lists, C consolidation, D settings/editors, E status/tools, F cleanup)
- **Risk**: H means extra VM testing and a reviewer who knows the page

Facts used below (verified 2026-10-06):
- **bulk handler exists** on firewall_rules, firewall_nat, nat_1to1, nat_npt, nat_out (`del_x`, `toggle_x`), vpn_ipsec and system_usermanager (`delete_check[]`, `dellall`)
- **copy (`?dup=`) exists** in the editors for aliases, NAT (all 4), rules, dyndns, rfc2136, gateways, gateway groups and routes

Bulk or copy anywhere else is a **logic** task: a separate PR with a smoke test, and only if decision 2 in `PLAN.md` is yes.

---

## W1 — Firewall

| Page | Type | Do | Ph | Risk |
|---|---|---|---|---|
| firewall_schedule | List | **pilot**: toolbar + search, badge "Active", row actions (edit, delete), header Add | A | |
| firewall_schedule_edit | Editor | **pilot**; time-range builder keeps its custom widget, restyled | A | |
| firewall_aliases | List | tabs IP/Ports/URLs/All via registry; search over name/values/descr; type filter; header actions Add + Import; copy | B | |
| firewall_aliases_edit | Editor | entry grid for entries (can hold hundreds; grid gets its own search when > 20 rows) | D | M |
| firewall_aliases_import | Tool | compact form, consistent with the Tool type | E | |
| firewall_nat, _1to1, _npt | List | toolbar + search + existing bulk bar (`del_x`, `toggle_x`); keep drag order; Save order in the toolbar | B | M |
| firewall_nat_out | List | mode selector becomes a one-row strip above the list (allowed exception); both tables get the list styling | B | M |
| firewall_nat_edit, _1to1_edit, _npt_edit, _out_edit | Editor | standard editor; advanced collapsed | D | |
| firewall_rules | Special | badges for pass/block/reject, `fs_row_actions`, toolbar search; **keep** separators, drag, interface tabs, bulk | B | **H** |
| firewall_rules_edit | Editor | sections ordered by use; advanced collapsed | D | **H** |
| firewall_virtual_ip / _edit | List / Editor | standard | B / D | |
| firewall_shaper, _queues, _vinterface, _wizards | Special | tabs via registry; tree styling; fix the corrupted caret glyph (core CSS, Phase A) | E | M |
| easyrule | Endpoint | — | | |

## W2 — Interfaces

| Page | Type | Do | Ph | Risk |
|---|---|---|---|---|
| (all 11) | — | **one registry group `interfaces`**: Assignments, Groups, VLANs, Bridges, LAGGs, then QinQs, VXLANs, GRE, GIF, PPPs, Wireless under More ▾ (decision 3) | A | |
| interfaces_assign | Special | restyle the table + Add row; badges for status | B | M |
| interfaces_groups, _vlan, _qinq, _vxlan, _gre, _gif, _bridge, _lagg, _ppps, _wireless | List | **identical template**: search, columns name / parent / mono details / descr, row actions edit + delete, header Add. Convert one, then copy it 9× | B | |
| the matching `*_edit` (10) | Editor | standard; qinq_edit tags become the entry grid | D | |
| interfaces | Special (Settings) | sections only; sticky Save; advanced collapsed; no structure change | D | **H** |
| interfaces_nic_settings | Settings | hardware table + per-NIC override cards; sticky Save; confirm panel becomes the confirm modal; **fix FA4 icon classes** (`fa fa-lightbulb-o`, `fa fa-exclamation-triangle`), which FA6 doesn't have | D | |

## W3 — DNS and DHCP services

| Page | Type | Do | Ph | Risk |
|---|---|---|---|---|
| services_unbound | Settings + **R1** | **pilot for R1**: views General / Host overrides / Domain overrides via `fs_view_switch`; tabs General · Access lists · Advanced stay as registry tabs; overrides become List pages with search | A | M |
| services_unbound_host_edit, _domainoverride_edit | Editor | Cancel returns to `services_unbound.php?view=hosts` / `view=domains`; host aliases become the entry grid | D | |
| services_unbound_acls | List + Editor (`act=edit`) | list view standard; editor networks become the entry grid | B / D | |
| services_unbound_advanced | Settings | standard | D | |
| services_dnsmasq | Settings + **R1** | same split as Unbound: General / Host overrides / Domain overrides | C | M |
| services_dnsmasq_edit, _domainoverride_edit | Editor | standard; Cancel returns to the view | D | |
| services_dhcp, services_dhcpv6 | Settings + **R1** | interface tabs stay; add view switch General / Address pools / Static mappings; static mappings become a List with search (MAC, IP, hostname, descr) | C | **H** |
| services_dhcp_edit, _dhcpv6_edit | Editor | standard | D | |
| services_dhcp_settings, _dhcpv6_settings, _dhcp_relay, _dhcpv6_relay | Settings | relay server lists become the entry grid | D | |
| services_radvd | Settings | DNS lists become the entry grid | D | |

## W4 — Other services

| Page | Type | Do | Ph | Risk |
|---|---|---|---|---|
| services_captiveportal_zones / _edit | List / Editor | standard | B / D | |
| services_captiveportal | Settings | 7-tab registry group with `['zone' => $cpzone]`; sections | D | M |
| services_captiveportal_ip, _mac, _hostname (+ `_edit`) | List / Editor | standard list template | B / D | |
| services_captiveportal_vouchers | List + **R1** | view switch Rolls / Settings; rolls list standard | C | M |
| services_captiveportal_vouchers_edit | Editor | standard | D | |
| services_captiveportal_filemanager | List | header action "Upload file" opens the upload card; list standard | B | |
| services_captiveportal_hasync | Settings | standard | D | |
| services_ntpd, _gps, _pps | Settings | ntpd servers become the entry grid | D | |
| services_ntpd_acls | Settings + **R2** | "Custom access restrictions" rows (network + mask + 8 flags) become a **List** with an editor (`?act=edit` branch in the same file); defaults stay as Settings. **logic: moves fields from a repeater to a record form; config format unchanged** | C | M |
| services_dyndns / _edit, services_rfc2136 / _edit, services_checkip / _edit | List / Editor | standard; badges for update status; copy exists for dyndns/rfc2136 | B / D | |
| services_igmpproxy / _edit | List / Editor | standard; edit networks become the entry grid | B / D | |
| services_pppoe / _edit | List / Editor | standard; users in the editor become the entry grid | B / D | |
| services_wol | List + **R1** | list of devices with row action **Wake**, header actions "Add device" + "Wake all"; the one-off "wake by MAC" form becomes a compact card above the list; remove the duplicate Add | C | |
| services_wol_edit | Editor | standard | D | |
| services_snmp | Settings | standard | D | |

## W5 — VPN

| Page | Type | Do | Ph | Risk |
|---|---|---|---|---|
| vpn_ipsec | Special | badges, `fs_row_actions`, toolbar; keep P1/P2 nesting and the existing bulk. Deferred from Phase B to its own PR (deletes run through hidden submit buttons; needs test tunnels) | B+ | **H** |
| vpn_ipsec_phase1, _phase2 | Editor | sections; advanced collapsed | D | M |
| vpn_ipsec_mobile, _settings | Settings | standard | D | |
| vpn_ipsec_keys / _edit | List / Editor | standard | B / D | |
| vpn_openvpn_server, _client, _csc | List + Editor (`act=edit`) | list view standard (badges, row actions); editor sections | B / D | M |
| vpn_l2tp | Settings | standard | D | |
| vpn_l2tp_users / _edit | List / Editor | standard | B / D | |

## W6 — System

| Page | Type | Do | Ph | Risk |
|---|---|---|---|---|
| system | Settings | DNS servers become the entry grid | D | M |
| system_advanced_misc | Settings | **pilot**; registry group `system-advanced` (6 tabs) | A | |
| system_advanced_admin, _firewall, _network, _notifications | Settings | standard | D | |
| system_advanced_sysctl | List + Editor (`act=edit`) | **R1**: today the list and the edit form show together; make them separate views; list search on tunable name | C | |
| system_usermanager | List + Editor (`act=edit`) | existing bulk becomes the bulk bar; badges (disabled, expired); search | B | M |
| system_groupmanager | List + Editor | standard | B | |
| system_usermanager_addprivs, _groupmanager_addprivs | Editor | the privilege multi-select becomes a **searchable checklist** (component added in Phase D) | D | |
| system_usermanager_settings, _passwordmg, system_user_settings | Settings | standard | D | |
| system_authservers | List + Editor | standard | B / D | |
| system_camanager, _certmanager, _crlmanager | List + Editor | **delete the hand-rolled search JS**, use `data-fs-table` (with a column filter replacing "Name/DN/Both"); badges for expiry (warn < 30 days, block when expired) | B | M |
| system_certmanager_renew | Tool | standard | E | |
| system_gateways / _edit | List / Editor | badges for default/status; copy exists | B / D | M |
| system_gateway_groups / _edit | List / Editor | standard; tiers keep their selects | B / D | |
| system_routes / _edit | List / Editor | standard; copy exists | B / D | |
| system_hasync | Settings | standard | D | |
| system_update_settings | Settings | standard | D | |
| system_boot_environments | List | row actions (activate, delete) with confirm; badge "Active" | B | M |
| system_restapi, _restapi_keys, _restapi_explorer | Settings / List / Special | already share one tab bar (`restapi_print_tabs()`); no change needed | — | |
| pkg_mgr, pkg_mgr_installed | List | search becomes `data-fs-table`; badges (installed, update available). Moved to Phase E: the tables load over AJAX, so the enhancer needs a re-init hook | E | |
| pkg_mgr_install | Tool | progress console | E | |
| pkg, pkg_edit | Special | **last**, CSS only unless decided otherwise; test 3 XML packages | F | **H** |

## W7 — Status and logs

| Page | Type | Do | Ph | Risk |
|---|---|---|---|---|
| status_gateways | Status | **pilot** (tiles + badges); tabs with status_gateway_groups | A | |
| status_gateway_groups | Status | standard | E | |
| status_services | Status | Start/Stop/Restart as row actions; badges | E | M |
| status_interfaces | Status | one card per interface; mono addresses; badges up/down | E | |
| status_dhcp_leases, status_dhcpv6_leases | Status | the existing search becomes the toolbar (server-side kept); badges online/offline/static | E | |
| status_carp, status_ntpd, status_unbound, status_upnp, status_wireless, status_queues, status_openvpn | Status | standard; row actions where they exist (disconnect, kill) | E | |
| status_ipsec, _leases, _sad, _spd | Status | registry group `status-ipsec`; badges connected/connecting/down | E | M |
| status_captiveportal, _vouchers, _voucher_rolls, _expire, _test | Status / Tool | registry group with zone param | E | |
| status_graph | Status | chart card + controls row (themed nvd3) | E | |
| status_restapi | Status | already near-standard; align with tokens | E | |
| status_filter_reload | Tool | console output | E | |
| status_logs, _filter, _filter_dynamic, _filter_summary, _packages, _vpn, _settings | Log / Settings | changes go through `status_logs_common.inc` once; filter toolbar; Log settings page action | E | M |
| index (dashboard) + widgets | Special | widget = flat card, compact header, tiles (10-03 phase 6) | E | M |

## W8 — Diagnostics

| Page | Type | Do | Ph | Risk |
|---|---|---|---|---|
| diag_ping | Tool | **pilot** | A | |
| diag_traceroute, _dns, _testport, _authentication, _smart, _pf_info, _pftop, _system_activity, _limiter_info, _packet_capture | Tool | same as the pilot | E | |
| diag_routes, _sockets, _states_summary, _gmirror | Status | table styling | E | |
| diag_arp, diag_ndp | Status | hand-rolled search becomes `data-fs-table`; delete entry as a row action | E | |
| diag_dump_states, _dump_states_sources, diag_resetstate | Status / Tool | registry group `states`; filter toolbar (server-side); kill state as a row action | E | M |
| diag_tables | Status | table selector becomes a toolbar select; entries list with search; per-row delete | E | |
| diag_backup | Tool | registry group `backup`: Backup & Restore · Remote · History | E | M |
| diag_backup_remote / _edit | List + **R1** / Editor | view switch Targets / Settings; browse = sub-view of a target | C | M |
| diag_confbak | List + **R1** | history list with search; retention settings become the header action "Settings", which expands a card | C | |
| diag_command, diag_edit | Tool | danger styling; no structure change | E | M |
| diag_reboot, diag_halt, diag_defaults | Tool | **danger confirm card** pattern | E | M |

## Not pages (don't touch)

`bandwidth_by_ip`, `getqueuestats`, `getserviceproviders`, `getstats`, `graph`, `graph_cpu`, `ifstats`,
`stats`, `xmlrpc`, `uploadconfig`, `csrf_error`, `crash_reporter` (gets tokens only), `help`, `status`
(support dump), `wizard` (Special, Phase F), `api/`.

---

## Parallelization

```
Phase A   [lead]  shell + tokens + components + tabs registry + 6 pilots     (sequential, blocks all)
Phase B   W1 W2 W4 W5 W6 W7* W8*  lists           (parallel; W3 only after the A pilot)
Phase C   W3 W4 W6 W8  splits (R1/R2)             (parallel; each after its area's B)
Phase D   [lead] Form classes + entry grid, then W1–W6 editors/settings in parallel
Phase E   W1 W6 W7 W8  status, logs, tools, dashboard
Phase F   [lead] cleanup, shim removal, a11y + package spot-check
```
`*` W7 and W8 have few lists, so their Phase B is small and can merge into E.

Conflict rule: workers in the same phase never share files. The only shared files are the
registry files `includes/tabs/<area>.inc`; one file per area, owned by that area's worker.
