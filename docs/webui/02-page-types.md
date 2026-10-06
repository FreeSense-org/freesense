# 02 — The six page types

Every page is exactly one type. If a page seems to need two, it gets tabs or views
instead (rule R1 in `PLAN.md`). Copy the **pilot** for your type. The pilots are
converted in Phase A and are the source of truth if this text and the pilot disagree.

Shared shell for every type (rendered by `head.inc`):

```
┌ navbar (ink chrome) ──────────────────────────────────────────────┐
│ Firewall › Aliases                       (breadcrumb, muted 13px) │
│ Aliases                       [⤓ Import] [＋ Add alias]           │  ← <h1> + $page_actions
│ ───────────────────────────────────────────────────────────────── │
│  IP   Ports   URLs   All                         (underline tabs) │
│ ┌ apply-changes banner (only when dirty) ───────────────────────┐ │
│ └───────────────────────────────────────────────────────────────┘ │
│   … page type body …                                              │
└───────────────────────────────────────────────────────────────────┘
```

- The `<h1>` is the last `$pgtitle` element. The rest of `$pgtitle` is the breadcrumb.
- `$page_actions` holds at most **one primary** button (coral) plus up to 2 secondary ones. More than that goes into a "⋯" menu.
- Tabs sit directly under the header. A second level, like DHCP "General | Pools | Static mappings", uses `fs_view_switch()` (a segmented control), never a second tab bar.
- The apply-changes banner (`print_apply_box`) goes above the body, full width, always in the same place.

---

## 1. List

**Pilot:** `firewall_schedule.php`. Use it for collections of records.

```
[ toolbar: (🔍 Search schedules…)  [Type ▾]          12 of 40 │ ⋯ ]
[ bulk bar (replaces toolbar while rows are selected):
  3 selected   [Disable] [Delete]                    [Clear]       ]
┌──┬────────┬──────────────┬───────────────┬──────────┬───────────┐
│☐ │ Status │ Name         │ Value (mono)  │ Descr.   │   ✎ ⧉ 🗑  │
├──┼────────┼──────────────┼───────────────┼──────────┼───────────┤
│☐ │ ●Active│ office_hours │ Mon–Fri 08–17 │ …        │   ✎ ⧉ 🗑  │
└──┴────────┴──────────────┴───────────────┴──────────┴───────────┘
  empty:      "No schedules yet."  [＋ Add schedule]
  no results: "No schedules match “foo”."  [Clear search]
```

Rules:
- Card contains: toolbar → table, edge to edge (no inner table border).
- Column order: select (only if bulk is supported) → status badge → identifying name → data columns → description → actions (right-aligned, fixed width).
- Double-click a row to edit (`table-rowdblclickedit`, which already exists). The name cell is also a link to the editor.
- Disabled records: the row text is muted plus a `disabled` badge. Don't rely on opacity only.
- Reorderable lists (rules, NAT, gateway groups) keep drag handles. "Save order" sits in the toolbar and appears only after the order changes.
- No Add or Delete buttons under the table.

## 2. Editor

**Pilot:** `firewall_schedule_edit.php`. Use it for one record (`*_edit.php`, or the `?act=new|edit` branch of a single-file page).

```
Firewall › Schedules › office_hours
Edit schedule                                   (h1: "Add …" or "Edit …")
┌ General ─────────────────────────────────────────────────────────┐
│ Name *        [office_hours        ]                             │
│               help text, muted 13px, under the field             │
│ Description   [                    ]                             │
└──────────────────────────────────────────────────────────────────┘
┌ Time ranges ─────────────────────────── (entry grid, see 03) ────┐
└──────────────────────────────────────────────────────────────────┘
▔▔ sticky action bar ▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔▔
  [💾 Save]  Cancel
```

Rules:
- Build the form with the `Form` classes. They render sections as flat cards and provide the sticky bar.
- The h1 says **"Add {thing}"** or **"Edit {thing}"**, and the breadcrumb includes the record name when editing.
- Cancel returns to the list and keeps the list's tab/view.
- Advanced options go in a section collapsed behind "Show advanced options" (the existing `btnadv` pattern, which gets one shared style).
- Validation errors: a summary at the top that links to each field, plus inline field errors (centralized in Phase D; don't hand-roll it).
- Inside an editor, repeated simple values use the **entry grid** (`03-components.md`). Repeated **records** don't belong in an editor; they become their own List (rule R2).

## 3. Settings

**Pilot:** `system_advanced_misc.php`. Use it for singleton configuration.

Same shell as the Editor, with these differences: there's no Cancel (Settings has no list to return to), tabs are common, and the h1 is the area name ("Advanced", "NTP"). Sections are ordered from most used to least used, and the sticky bar holds **Save** only.

A settings page that also shows records (overrides, mappings, targets) gets those records split into their own tab or view, as List pages.

## 4. Status

**Pilot:** `status_gateways.php`. Use it for read-only or live data.

```
Status › Gateways                       [⟳ Refresh: 10s ▾]
┌ tile ─────┐┌ tile ─────┐┌ tile ─────┐┌ tile ─────┐
│ 3 Online  ││ 1 Down    ││ RTT 12 ms ││ Loss 0 %  │   (optional summary strip)
└───────────┘└───────────┘└───────────┘└───────────┘
[ toolbar: search · filter · count ]
[ table with fs_badge() status column ]
```

Rules:
- Use summary tiles only when there are real headline numbers. There are 2–4 tiles; each shows a number, a label and an optional badge.
- The auto-refresh control is a single header action (interval dropdown plus a pause button). It never animates rows on refresh.
- Row actions on status pages are operational (Start/Stop/Restart service, Disconnect, Kill state, Delete lease) and use `fs_row_actions()`. Destructive ones confirm.

## 5. Tool

**Pilot:** `diag_ping.php`. Use it for diagnostics that take input and produce output.

```
Diagnostics › Ping
┌ Parameters ─────────────────────────────────────────────────────┐
│ Host  [8.8.8.8    ]  Protocol [IPv4 ▾]  Count [3]               │
│                                                    [▶ Run]       │
└─────────────────────────────────────────────────────────────────┘
┌ Output ─────────────────────────────────────── [⧉ Copy] ────────┐
│ <pre class="fs-console"> mono, scrolls inside, max 60vh </pre>  │
└─────────────────────────────────────────────────────────────────┘
```

Rules:
- Use a compact inline form, not one field per row, when it has 4 fields or fewer.
- Run shows a spinner and `aria-busy` while working. The output card appears only once there is output.
- Tabular output (DNS, routes, sockets, states) uses the List table styling without bulk or Add.
- Dangerous tools (halt, reboot, factory defaults, command prompt, edit file) use the **danger confirm** card: a red-edged card that states the consequence, plus a button whose label names the action ("Reboot now"), never "Yes".

## 6. Log

**Pilot:** `status_logs.php` (via `status_logs_common.inc`).

- Filter controls (today a toggled `#filter-form`) become an always-visible toolbar row: search, an interface/action/time filter and the row count. "Advanced filter" expands below it.
- Log table: the time column is mono, action is an `fs_badge()`, the message wraps, and rows are 32px.
- "Manage log" (wrench) becomes the page action **Log settings** (`fa-sliders`).

---

## Special pages: visual layer only

Apply tokens, cards, toolbar styling, badges and row actions. **Keep their structure**,
and don't restructure them without a separate decision:

`firewall_rules.php` (interface tabs, separators, drag), `vpn_ipsec.php` (P1/P2 nesting),
`firewall_shaper*.php` (tree), `interfaces_assign.php`, `interfaces.php` (very long settings;
sections only), `index.php` and widgets, `wizard.php`, `pkg.php` / `pkg_edit.php` (the XML
package renderer: every XML package changes with it, so change it last and test 3 packages),
`system_restapi_explorer.php`.
