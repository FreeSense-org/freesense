# WebUI standardization plan

Date: 2026-10-06
Status: proposal. No code has changed yet.
Repo: `src/usr/local/www`, branch `main` (1.1 Development only)
Builds on: the 2026-10-03 WebUI overhaul plan (workspace planning notes) (visual tokens, shell, tables, forms). None of that plan has shipped yet; this plan absorbs it and sequences the two together.

Every implementer reads `00-worker-brief.md` first.

---

## 1. Goal

This is not a redesign. The goal is that **every page looks and behaves like one product**:

- each page is **one of six page types**, each with a fixed layout
- the same **list page** everywhere: header with Add, a toolbar with search, filters, count and bulk actions, a clean table, labelled row actions, a confirm-before-delete dialog and a proper empty state
- **settings and lists stop sharing one long scrolling page**: settings go in one tab and each record list gets its own tab
- **"form tables" become lists**. A repeating row editor whose rows are really records becomes a list with an editor
- **tabs come from one registry**, render the same way everywhere and overflow cleanly
- a recognisable "cool" signature, cheaply: ink chrome, coral accent, flat cards, underline tabs, monospace data, pill badges and sticky toolbars

## 2. What the survey found

| Finding | Numbers | Consequence |
|---|---|---|
| Page count | 221 PHP files, about 190 real pages; the rest are endpoints | Needs a page map and parallel workers (`04-page-map.md`) |
| Three different list/edit styles | about 40 `x.php` + `x_edit.php` pairs; 17 single-file `?act=edit` pages; about 10 pages with a settings form **and** record tables stacked | Standardize on List + Editor; split the stacked pages |
| Tabs copy-pasted | 437 `$tab_array[] =` lines in 92 core files; Interfaces has **11 tabs**; overflow falls back to a `<select>` | Central tab registry plus a new overflow rendering. `display_top_tabs()` keeps its signature because 61 package files call it |
| Search re-implemented per page | 9 pages, each with its own jQuery (regex on hard-coded column indexes) | One `data-fs-table` JS enhancer |
| Bulk select | only 7 pages (rules, NAT ×4, IPsec, users) | Standard bulk bar; add bulk delete only where the handler supports arrays |
| Delete confirm | `window.confirm("Are you sure you wish to delete …?")` in `interceptGET()`, 61 pages use `usepost` | Swap in one confirm modal; keep the POST mechanism |
| "Add" placement | bottom of table, sometimes duplicated (WoL has two) | Primary action moves to the page header |
| Header actions | `head.inc` hard-codes flags (`$system_logs_filter_form_hidden`, …) and renders unlabeled icons | `$page_actions[]` array rendered as labelled buttons |
| Row actions | 120 bare `fa-trash-can`, 67 `fa-pencil` anchors with no accessible name | `fs_row_actions()` helper |
| Packages | 90 package files include `head.inc`, 61 call `display_top_tabs` | Class names and PHP signatures are a stable API. Restyle; don't rename |
| Privileges | Page privileges match by URL prefix (`services_unbound.php*`) | Never rename or remove a page; a new view is `?view=` or a new file added to the **existing** privilege's `match` list |

Bug found: `interfaces_nic_settings.php`, `system_advanced_network.php` and `widgets/widgets/log.widget.php` use Font Awesome 4 classes (`class="fa fa-…"`, some with v4 names such as `fa-lightbulb-o`), and no v4 shim is loaded. Expect missing icons; fix it in Phase A.

Newer pages (REST API, remote backup) already use a cleaner idiom: icons in headings, count badges, `align-middle`. They point in the same direction but are not the standard yet.

## 3. The standard (summary; details in this folder)

### Six page types

| Type | Used for | Layout |
|---|---|---|
| **List** | collections of records (aliases, VLANs, users, overrides…) | header + Add → tabs → toolbar → table → empty state |
| **Editor** | one record (`*_edit.php`, `?act=edit`) | header "Add X" / "Edit X" → form sections → sticky action bar (Save, Cancel) |
| **Settings** | singleton config (System › Advanced, NTP, SNMP…) | header → tabs → sections → sticky Save bar; advanced options collapsed |
| **Status** | read-only live data (gateways, leases, IPsec, services) | header + refresh → summary tiles → tables with search |
| **Tool** | diagnostics (ping, DNS, test port, capture, command) | input card with Run → output (mono console or table) |
| **Log** | `status_logs*` | filter bar → log table (shared `status_logs_common.inc`) |

Special cases keep their own structure and only get the visual layer: firewall rules (drag order, separators), IPsec P1/P2 nesting, the traffic shaper tree, interface assignment, the dashboard, the wizard and the XML package renderer (`pkg.php`, `pkg_edit.php`).

### Consolidation rules

- **R1. One page, one type.** If a Settings form shares a page with record lists, split it into tabs using `?view=` in the same file:
  - Unbound: General | Host overrides | Domain overrides | Access lists | Advanced
  - DNS Forwarder: General | Host overrides | Domain overrides
  - DHCP / DHCPv6: interface tabs, then General | Address pools | Static mappings
  - Remote backup: Targets | Settings
  - Captive portal vouchers: Rolls | Settings
  - A **one-row mode strip** may stay above a list, e.g. the outbound NAT mode.
- **R2. Form tables become lists.** Convert a repeating row editor to List + Editor when its rows are records: more than 3 fields, per-row options or a description. The canonical example is NTP › ACLs › custom restrictions, which has 8 flag checkboxes per row. Simple value lists keep the inline **entry grid**, now styled the same everywhere; examples are alias entries, DNS servers, NTP servers and host-override aliases.
- **R3. One primary action, in the header.** No duplicate Add buttons at the top and bottom of a table. Save for reorderable lists sits in the toolbar.
- **R4. Every list gets search.** It runs client-side by default, because rows are already server-rendered. Logs, states and leases keep their server-side filters, restyled into the toolbar.
- **R5. Every delete confirms in a modal that names the object.** Bulk delete only where the POST handler already accepts arrays. Adding array support to a handler is a logic change, so it gets its own small PR with a smoke test.
- **R6. Tabs come from the registry.** At most about 7 visible, the rest go under "More ▾", and there is no select fallback. Dynamic tab groups (per interface, per CP zone) stay page-local but render through the same function.
- **R7. URLs are an API.** No page is renamed or deleted. Menus, widgets, `shortcuts/`, `help.php` mapping, packages, docs and bookmarks must still land.

### Shared building blocks (built once, in Phase A)

| Piece | Where | Replaces |
|---|---|---|
| `css/_freesense-tokens.css` | new | scattered literals |
| `css/_freesense-components.css` | new | per-page inline styles |
| `includes/fs_ui.inc` (`fs_page_action`, `fs_tabs`, `fs_table_toolbar`, `fs_row_actions`, `fs_badge`, `fs_empty_row`, `fs_view_switch`) | new, required by `guiconfig.inc` | hand-written markup |
| `includes/tabs/<area>.inc` registry | new | 437 copy-pasted tab lines |
| `js/freesense-ui.js` (`data-fs-table`, confirm modal, sticky action bar) | new | 9 search scripts, `confirm()` |
| `head.inc` header: breadcrumb, `<h1>`, labelled `$page_actions` | edit | `.header` box + icon context links |
| `display_top_tabs()` output | edit, same signature | pills + select fallback |
| `classes/Form/*` sticky action bar, grid, entry grid styling | edit, same public API | BS3 float grid |

## 4. Phases

Each phase ships as several small PRs. `main` must never sit in a half-migrated state: every PR leaves every page working, whether old-style or new-style.

| Phase | Content | PRs | Parallel? |
|---|---|---|---|
| **A. Foundation and shell** | 10-03 phases 0–2 (baseline screenshots, caret glyph bug, tokens, fluid container, header, flex footer, flat cards) **plus** the shared building blocks above, the tab registry and the confirm modal. Converts **6 pilot pages**, one per type, as the golden references | 3–4 | **No: one worker, sequential.** Everything else depends on it |
| **B. Lists** | all List pages: toolbar, search, badges, row actions, empty state, Add in header. 10-03 phase 3 | one per area (8) | **Yes**, by menu area |
| **C. Consolidation** | R1/R2 splits: Unbound, DNSmasq, DHCP/DHCPv6, WoL, remote backup, config history, CP vouchers, NTP ACLs, Unbound ACLs, sysctl | one per page | Yes, after B for that area |
| **D. Settings and editors** | Form class grid and sticky bar (10-03 phase 4), the entry grid, consistent "advanced" toggles, all Settings/Editor pages | 1 central + one per area | Central first, then areas in parallel |
| **E. Status, logs, tools, dashboard** | summary tiles, refresh control, tool output console, log toolbar, widget cards (10-03 phase 6) | one per area | Yes |
| **F. Cleanup and QA** | inline styles, `onclick`, BS3 leftovers, shim removal, login page, a11y pass (10-03 phases 5, 7, 8) | 2–3 | Partly |

### Pilot pages (Phase A golden references)

| Type | Pilot | Why |
|---|---|---|
| List | `firewall_schedule.php` | small, has an edit pair, no bulk/drag complexity |
| Editor | `firewall_schedule_edit.php` | pairs with the list pilot |
| Settings | `system_advanced_misc.php` | tabbed, sections, no lists |
| Status | `status_gateways.php` | tabs, badges, small |
| Tool | `diag_ping.php` | Run → output |
| List + R1 split | `services_unbound.php` | proves `?view=` tabs + privilege match |

Workers copy the pilots, not their memory of Bootstrap.

## 5. Release impact

- **System-only.** Every change is in the `freesense` base repo, so each merge produces a **System build only**, through the normal nightly Development build. **Optional Packages do not rebuild**, and no PR in this plan edits `freesense-packages`. Packages pick up the new look through the shared CSS, `display_top_tabs()` and the Form classes.
- **1.1 only.** No 1.0.x backport.
- **No manual builds.** Verify on the 1.1 test VM (`192.168.228.2`) from the nightly build, or by copying changed files onto the VM.
- Package spot-check every phase: 2–3 package pages (an XML `pkg_edit.php` page and a custom-PHP package page).

## 6. Decisions (2026-10-06)

The user said to start, so the recommended defaults apply:

1. **Editors are separate pages**, not modals. This keeps URLs, privileges, server validation and deep links.
2. **Bulk delete beyond the current 7 pages:** yes, as individual Phase C PRs, each with handler support and a smoke test.
3. **Interfaces tabs:** keep the 11 pages behind an overflow tab bar.
4. **From the 10-03 plan:** fluid page width with a 1680px cap, and zebra stripes dropped. The font stays Roboto for now; `--fs-font-ui` / `--fs-font-mono` make a later switch a one-line change.
5. **The style guide lives in the repo** as `docs/webui/`.
6. **Libraries upgraded first** (freesense #93): FA 7.3.1, jQuery UI 1.14.2, js-cookie 3.0.8, Visibility.js 2.0.2. The d3 3 / nvd3 / d3pie charts can only move to d3 7 by rewriting the three charts, which is now part of Phase E.
7. **Icons:** canonical-name pass across core plus a CI guard (`webui/icon-names`). Icon meaning changes happen per page in Phase B.

## 7. Out of scope

Replacing Bootstrap/jQuery, an SPA rewrite, replacing d3/nvd3, renaming or deleting pages, and changing config handling, privileges or validation logic. The only exception is the explicitly scoped bulk-delete handler PRs in R5.
