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
| firewall_schedule_edit | Editor | **done** (f-firewall): summary card when editing; "Add time range" card with a button calendar (weekday headers = weekly, days = dates, aria-pressed, one delegated script, no inline handlers/styles) and time selects; configured ranges as an fs-table with remove; posts the same fields | A | |
| firewall_aliases | List | tabs IP/Ports/URLs/All via registry; search over name/values/descr; type filter; header actions Add + Import; copy | B | |
| firewall_aliases_edit | Editor | **done**: entry grid; a filter appears above it when the alias has > 20 entries (any page's grid gets it) | D | M |
| firewall_aliases_import | Tool | compact form, consistent with the Tool type | E | |
| firewall_nat, _1to1, _npt | List | toolbar + search + existing bulk bar (`del_x`, `toggle_x`); keep drag order; Save order in the toolbar. **F** (f-firewall): port forward table scrolls inside its card below 992 px, empty state in its own tbody (separator rows stay correct) | B | M |
| firewall_nat_out | List | mode selector becomes a one-row strip above the list (allowed exception); both tables get the list styling. **F** (f-firewall): Automatic rules are a read-only fs-table card (search, count, escaped values, static-port badges) | B | M |
| firewall_nat_edit, _1to1_edit, _npt_edit, _out_edit | Editor | standard editor; advanced collapsed | D | |
| firewall_rules | Special | badges for pass/block/reject, `fs_row_actions`, toolbar search; **keep** separators, drag, interface tabs, bulk. **F** (f-firewall): table scrolls inside its card below 992 px (sortable scrolls the page while dragging), empty-state row with Add rule, no inline styles on rule flags, copy modal buttons primary/outline | B | **H** |
| firewall_rules_edit | Editor | **reviewed**: sections already follow use (action, match, source, destination, log/description); advanced is behind its toggle; no reorder | D | **H** |
| firewall_virtual_ip / _edit | List / Editor | standard | B / D | |
| firewall_shaper, _queues, _vinterface, _wizards | Special | tabs via registry; tree styling; fix the corrupted caret glyph (core CSS, Phase A) | E | M |
| easyrule | Endpoint | — | | |

## W2 — Interfaces

| Page | Type | Do | Ph | Risk |
|---|---|---|---|---|
| (all 11) | — | **one registry group `interfaces`**: Assignments, Groups, VLANs, Bridges, LAGGs, then QinQs, VXLANs, GRE, GIF, PPPs, Wireless under More ▾ (decision 3) | A | |
| interfaces_assign | Special | **done**: searchable list (interface, inline port select, configured addresses, disabled badge), changed / duplicate ports marked, Edit / Delete (confirmed) row actions, header Add interface modal; add / save / delete / apply logic unchanged | B | M |
| interfaces_groups, _vlan, _qinq, _vxlan, _gre, _gif, _bridge, _lagg, _ppps, _wireless | List | **identical template**: search, columns name / parent / mono details / descr, row actions edit + delete, header Add. Convert one, then copy it 9× | B | |
| the matching `*_edit` (10) | Editor | standard; qinq_edit tags become the entry grid | D | |
| interfaces | Special (Settings) | **done**: MAC / MTU / MSS / Speed and Duplex moved to a collapsible "Link Settings" section (open when any is set or a save failed); field names and save logic unchanged; sticky Save from the Form bar | D | **H** |
| interfaces_nic_settings | List + Settings | **done**: tab in the Interfaces tab bar; Adapters (searchable list, offload chips, per-adapter Configure / Details modals) and Profile (profile cards, ALTQ) views; old `?view=` names redirect. Adapter ids are now valid XML names (`nic_` prefix) | D | |

## W3 — DNS and DHCP services

| Page | Type | Do | Ph | Risk |
|---|---|---|---|---|
| services_unbound | Settings + **R1** | **pilot for R1**: views General / Host overrides / Domain overrides via `fs_view_switch`; tabs General · Access lists · Advanced stay as registry tabs; overrides become List pages with search | A | M |
| services_unbound_host_edit, _domainoverride_edit | Editor | Cancel returns to `services_unbound.php?view=hosts` / `view=domains`; host aliases become the entry grid | D | |
| services_unbound_acls | List + Editor (`act=edit`) | list view standard; editor networks become the entry grid | B / D | |
| services_unbound_advanced | Settings | standard | D | |
| services_dnsmasq | Settings + **R1** | **done**: General / Host overrides / Domain overrides tabs (`?view=`), searchable lists, header Add | C | M |
| services_dnsmasq_edit, _domainoverride_edit | Editor | **done**: save and Cancel return to the view | D | |
| services_dhcp, services_dhcpv6 | Settings + **R1** | **done (mappings)**: interface tabs stay; view switch Settings / Static Mappings (n); mappings are a searchable List with header Add, editors return to it. Address pools stay in Settings (still a sub-list there). **F** (f-firewall): DHCPv6 "no eligible interface" empty state; no per-interface config lookups (log flood) when there is none | C | **H** |
| services_dhcp_edit, _dhcpv6_edit | Editor | standard | D | |
| services_dhcp_settings, _dhcpv6_settings, _dhcp_relay, _dhcpv6_relay | Settings | relay server lists become the entry grid | D | |
| services_radvd | Settings | DNS lists become the entry grid | D | |

## W4 — Other services

| Page | Type | Do | Ph | Risk |
|---|---|---|---|---|
| services_captiveportal_zones / _edit | List / Editor | standard | B / D | |
| services_captiveportal | Settings | 7-tab registry group with `['zone' => $cpzone]`; sections | D | M |
| services_captiveportal_ip, _mac, _hostname (+ `_edit`) | List / Editor | standard list template. **F** (f-firewall): hostname_edit validates the zone before reading its zoneid (no log warning); status_captiveportal "no zones" empty state with Add zone | B / D | |
| services_captiveportal_vouchers | List + **R1** | **done**: view switch Rolls (n) / Settings; rolls list with CSV export / edit / delete; header Add roll when vouchers are on (otherwise a hint links to Settings) | C | M |
| services_captiveportal_vouchers_edit | Editor | standard | D | |
| services_captiveportal_filemanager | List | header action "Upload file" opens the upload card; list standard | B | |
| services_captiveportal_hasync | Settings | standard | D | |
| services_ntpd, _gps, _pps | Settings | ntpd servers become the entry grid | D | |
| services_ntpd_acls | Settings + **R2** | **done**: custom restrictions are a List (network + flag badges) with an Add / Edit modal; the Default restrictions card below saves on its own; the page rebuilds the former form post for ntpd_save_acls(), so config format and REST API are unchanged | C | M |
| services_dyndns / _edit, services_rfc2136 / _edit, services_checkip / _edit | List / Editor | standard; badges for update status; copy exists for dyndns/rfc2136 | B / D | |
| services_igmpproxy / _edit | List / Editor | standard; edit networks become the entry grid | B / D | |
| services_pppoe / _edit | List / Editor | standard; users in the editor become the entry grid | B / D | |
| services_wol | List + **R8** | **done**: one device list (Wake / Edit / Delete per row); header Wake all (confirm), Wake a MAC… (modal), Add device (modal; needs the edit privilege) | C | |
| services_wol_edit | Editor | standard | D | |
| services_snmp | Settings | standard | D | |

## W5 — VPN

| Page | Type | Do | Ph | Risk |
|---|---|---|---|---|
| vpn_ipsec | Special | **done** (Phase E): tiles, toolbar (search incl. phase 2 networks, status/IKE filters, bulk delete), phase 2 entries nested under each tunnel (collapsible) with mode, networks and proposal chips; toggles/deletes via `fs_row_actions` (same `toggle_N`/`del_N`/`togglep2_N`/`delp2_N` posts), move buttons shown while entries are selected, phase 2 bulk delete (`delp2`) | B+ | **H** |
| vpn_ipsec_phase1, _phase2 | Editor | **done** (Phase E): header summary card (description, enabled badge, IKE version/mode, remote gateway or local/remote networks, proposal, parent phase 1); cards in fill-in order (General, Connection, Authentication, Proposal / General, Networks, Proposal); Keep alive, Expiration and replacement and phase 1 Advanced options collapsible; Save + Cancel; form posts unchanged (parity-checked) | D | M |
| vpn_ipsec_mobile, _settings | Settings | **done** (Phase E): summary card, regrouped sections; rarely used options, RADIUS tuning and logging collapsible | D | |
| vpn_ipsec_keys / _edit | List / Editor | **done** (Phase E): type/source filters, masked keys with show button, EAP options column; editor with summary card and an EAP options section | B / D | |
| vpn_openvpn_server, _client, _csc | List + Editor (`act=edit`) | list view standard (badges, row actions); editor sections | B / D | M |
| vpn_openvpn_server | List + Editor | **done** (Phase E): tiles, mode/state filters, mode badge, crypto chips; editor header card, cards General → Endpoint → Crypto → Tunnel → Clients, collapsible certificate checks / DNS-NetBIOS / ping / advanced; form parity checked in 28 states | E | |
| vpn_l2tp | Settings | standard | D | **done** (Phase E): summary card, cards in fill-in order, RADIUS/Advanced collapsible |
| vpn_l2tp_users / _edit | List / Editor | standard | B / D | **done** (Phase E): tiles, filter, add/edit modal posting to _edit; editor rebuilt with summary card |

## W6 — System

| Page | Type | Do | Ph | Risk |
|---|---|---|---|---|
| system | Settings | DNS servers become the entry grid | D | M |
| system_advanced_misc | Settings | **pilot**; registry group `system-advanced` (6 tabs) | A | |
| system_advanced_admin, _firewall, _network, _notifications | Settings | standard | D | |
| system_advanced_sysctl | List + Editor (`act=edit`) | **done**: searchable list (Custom / Default filter, description under the name); Add and Edit in a modal (reopens with the input after a validation error); delete confirms; the act=edit page stays for links | C | |
| system_usermanager | List + Editor (`act=edit`) | existing bulk becomes the bulk bar; badges (disabled, expired); search | B | M |
| system_groupmanager | List + Editor | standard | B | |
| system_usermanager_addprivs, _groupmanager_addprivs | Editor | **done**: the multi-select, its hidden shadow copy, the filter box and Filter/Clear buttons are one searchable checklist (grouped, descriptions inline, admin-level badges, Selected only); Cancel returns to the user / group | D | |
| system_usermanager_settings, _passwordmg, system_user_settings | Settings | standard | D | |
| system_authservers | List + Editor | standard | B / D | |
| system_camanager, _certmanager, _crlmanager | List + Editor | **delete the hand-rolled search JS**, use `data-fs-table` (with a column filter replacing "Name/DN/Both"); badges for expiry (warn < 30 days, block when expired) | B | M |
| system_certmanager_renew | Tool | standard | E | |
| system_gateways / _edit | List / Editor | badges for default/status; copy exists | B / D | M |
| system_gateway_groups / _edit | List / Editor | standard; tiers keep their selects | B / D | |
| system_routes / _edit | List / Editor | standard; copy exists | B / D | |
| system_hasync | Settings | standard | D | |
| system_update_settings | Settings | standard | D | |
| system_boot_environments | List + **R8** | **reference for R8**: one Environments list; Create snapshot / Edit (rename + description) / Clone in modals; labelled row actions with confirmations; Settings tab kept | B | M |
| system_restapi, _restapi_keys, _restapi_explorer | Settings / List / Special | already share one tab bar (`restapi_print_tabs()`); no change needed | — | |
| pkg_mgr, pkg_mgr_installed | List | **done**: tiles (available / installed / updates; installed / up to date / updates / need attention), fs-table with search, category and status filters, count and sort after the AJAX load via `FreeSenseUI.initTables()`; status badges, version current → available in mono; Install / Manage / Update / Reinstall / Remove as row actions (GET flows unchanged, Update / Reinstall / Remove confirm first); Available now lists installed packages too, with an Installed badge | E | |
| pkg_mgr_install | Tool | **done**: review step (header, fs tiles, capabilities, Cancel + action button), progress step (state header with icon and badge, progress bar, result message with links back) and mono output console with Auto-scroll and Copy; System Update uses the `system-update` tabs and tiles; all POST fields and polling unchanged | E | |
| pkg, pkg_edit | Special | **last**, CSS only unless decided otherwise; test 3 XML packages | F | **H** |

## W7 — Status and logs

| Page | Type | Do | Ph | Risk |
|---|---|---|---|---|
| status_gateways | Status | **pilot** (tiles + badges); tabs with status_gateway_groups | A | |
| status_gateway_groups | Status | standard. **done**: one card per group, tier + status badges, member tiles | E | |
| status_services | Status | Start/Stop/Restart as row actions; badges. **done**: tiles, search + state filter, stop (and restart of network-critical services) confirm | E | M |
| status_interfaces | Status | one card per interface; mono addresses; badges up/down. **done**: 2-column card grid, traffic tiles, DHCP release modal | E | |
| status_dhcp_leases, status_dhcpv6_leases | Status | **done**: tiles (active / static / expired / total or prefixes), searchable lease list with state + online badges and state / client / interface filters, `?all=` kept as the Show / Hide expired toolbar button, row actions (static mapping, WoL mapping, send WoL, confirmed delete), confirmed Clear all leases header action; Leases / (Prefix delegation) / Pools views | E | |
| status_carp, status_ntpd | Status | standard; row actions where they exist (disconnect, kill). **F** (f-firewall): CARP empty state (Add virtual IP / High availability sync), outline Reset demotion button | E | |
| status_ipsec, _leases, _sad, _spd | Status | **done**: registry group `status-ipsec`; Overview tiles + tunnel list (connected / connecting / disconnected / waiting badges, state filter), expandable child SAs kept across the 5 s refresh, connect / disconnect as row actions (disconnect confirmed, same AJAX handler); leases, SADs (confirmed delete) and SPDs as searchable lists | E | M |
| status_openvpn, status_unbound, status_upnp, status_wireless, status_queues | Status | **done** (status-b): OpenVPN one card per server (service badge + controls, client list with confirmed Disconnect / Halt, collapsible routing table) and instance lists; Unbound speed / stats views; UPnP confirmed Delete all; Wireless Rescan header action; Queues tree with live stats and collapse toggles. **F** (f-firewall): UPnP "turned off" empty state with Configure UPnP | E | |
| status_captiveportal, _vouchers, _voucher_rolls, _expire, _test | Status / Tool | registry group with zone param | E | **done** (F): zone picker, tiles, users list with confirmed disconnect, voucher/roll lists with badges and usage meter, test/expire as tool cards |
| status_graph | Status | chart card + controls row (themed nvd3) | E | **done** (F): controls row + more options, top hosts fs-table; traffic-graphs.js uses --fs-series-*. status_graph_cpu rebuilt as native token-themed SVG chart with tiles |
| status_restapi | Status | already near-standard; align with tokens | E | |
| status_filter_reload | Tool | **done**: console output card (Copy, Reloading/Done badge, live polling), Reload filter / Force config sync as header actions (same POST fields) | E | |
| status_logs, _filter, _filter_dynamic, _filter_summary, _packages, _vpn, _settings | Log / Settings | **done**: tabs from registry `status-logs` (`includes/tabs/logs.inc`), second level as view switch; one log card per page (quick search, level filter, server filter fields in the toolbar, "More filters" collapsible, count); mono time, process chips, severity edge; firewall rows with action badges + rule popover, resolve / EasyRule row actions; Log settings modal + confirmed Clear log as header actions; summary tiles + donut/table cards; settings in sections with collapsible Storage and rotation, Reset log files as confirmed header action. Field names unchanged. Also rebuilt diag_packet_capture (options card, collapsible view options, Start/Stop bar, last-capture card, console output) | E | M |
| index (dashboard) + widgets | Special | widget = flat card, compact header, tiles (10-03 phase 6) | E | M |

## W8 — Diagnostics

| Page | Type | Do | Ph | Risk |
|---|---|---|---|---|
| diag_ping | Tool | **pilot**; **done** (E): rebuilt into the shared two-column tool layout (options card, results card with Copy and empty state) | A | |
| diag_traceroute, _dns, _testport, _authentication, _smart, _pf_info, _pftop, _system_activity, _limiter_info, _packet_capture | Tool | same as the pilot. **done** (traceroute, dns, testport, authentication, smart): two-column tool layout, compact options card + result card (mono output, Copy); smart uses a view switch Information / Logs / Self-tests. **done** (pf_info): tiles + view switch Counters / Interfaces (IPv4/IPv6 filter) / Limits and timeouts, live refresh in place. **done** (limiter_info): tiles + output cards, live refresh. **done** (F, pftop): tool layout, options apply live, pause switch, Copy. **done** (F, system_activity): tiles (load, CPU, memory, threads), searchable thread list with busy filter refreshed in place, raw top output collapsible. **polish** (F, packet_capture): sentence-case filter labels and placeholders, shorter hint | E | |
| diag_routes, _sockets, _states_summary, _gmirror | Status | table styling. **done** (routes, sockets, states_summary): tiles, searchable lists, IPv4/IPv6 view switch (routes, sockets), view switch per summary (states_summary). **done** (F, gmirror): tiles, mirror and consumer fs-tables with badges/chips and row actions, confirmation step as a danger card, empty rows | E | |
| diag_arp, diag_ndp | Status | **done**: tiles, toolbar search + interface/state filters, status badges, mono IP/MAC, Wake-on-LAN + delete row actions (confirmed), clear table as a confirmed header action | E | |
| diag_dump_states, _dump_states_sources, diag_resetstate | Status / Tool | registry group `states`; filter toolbar (server-side); kill state as a row action. **done** (F): tiles; server filters in the list toolbar; mono flows with original addresses; confirmed kill row actions (AJAX as before) and confirmed Kill states; source tracking empty-state card linking to the sticky setting; reset as a danger card with option cards and the shared confirm | E | M |
| diag_tables | Status | **done**: table picker in the toolbar (GET `type`), tiles (entries, type, last update), searchable entries with confirmed per-row remove, Update now / Empty table as header actions | E | |
| diag_backup | Tool | registry group `backup`: Backup & Restore · Remote · History. **done** (F): Back up / Restore cards side by side (one form, fields unchanged), package functions card, package review and restored-settings lists as fs-tables with chips, badges and confirmed row actions (fixes the phone overflow, audit O03) | E | M |
| diag_backup_remote / _edit | List + **R1** / Editor | **done**: view switch Targets / Settings; targets list (status badge, last success, run / browse / test / edit / toggle / delete); browse results are a list with download / restore | C | M |
| diag_confbak | List + **R1** | **done**: history list with search, Old/New compare radios and Compare in the toolbar, current config marked; restore / download / delete with confirmations; retention in a header "Settings" modal; readable diff. Fixed: backup sizes showed 0 B (backup_config() cached the size before writing the file) | C | |
| diag_command, diag_edit | Tool | **done**: danger cards with warning headers, input groups, no inline handlers; command output keeps the first `<pre>` | E | M |
| diag_reboot, diag_halt, diag_defaults | Tool | **done**: danger confirm card (consequences, red action naming the verb, Cancel to the dashboard); reboot methods as radio cards | E | M |

## Not pages (don't touch)

`bandwidth_by_ip`, `getqueuestats`, `getserviceproviders`, `getstats`, `graph`, `graph_cpu`, `ifstats`,
`stats`, `xmlrpc`, `uploadconfig`, `csrf_error`, `crash_reporter` (gets tokens only; **done** in F: files list with downloads, report console with Copy, confirmed delete as header action, empty state), `help`, `status`
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
