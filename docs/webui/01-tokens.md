# 01 — Tokens, color, type, icons

All values live in `css/_freesense-tokens.css`, which Phase A creates. Page files and
component CSS reference **tokens only**. Existing `--fs-coral*`, `--fs-chrome-*` and
`--fs-page-bg` keep their names and values; everything below extends them.

## The look in one paragraph

Dark ink chrome, flat surfaces separated by 1px hairlines, and **coral as the single
accent**: active tab underline, primary button, focus ring, selected row edge. Dense
36px table rows, monospace data, pill badges that pair an icon with text, sticky
toolbars and save bars, and quick 120–180ms transitions. No gradients, no shadows on
resting cards (overlays only), no colored panel headings.

## Surfaces and text

| Token | Light | Dark | Use |
|---|---|---|---|
| `--fs-page-bg` | `#eceff3` | `#14181f` | behind everything (exists) |
| `--fs-surface` | `#ffffff` | `#1b2029` | cards, tables, forms |
| `--fs-surface-raised` | `#f6f7f9` | `#222833` | toolbar, table header, hover row |
| `--fs-border` | `#dde2e8` | `rgba(255,255,255,.08)` | hairlines, card edge |
| `--fs-text` | `#1a1f27` | `#e8ecf1` | body text |
| `--fs-text-muted` | `#5b6573` | `#9aa4b2` | help text, secondary columns, counts |

## Accent

| Token | Value | Rule |
|---|---|---|
| `--fs-coral` | `#ea4f2d` | **non-text only**: tab underline, focus ring, selected-row edge, icons ≥ 3:1 |
| `--fs-coral-down` | `#c93d25` | primary button background with white text (5.0:1) |
| `--fs-coral-text` | light `#b8371f` / dark `#ff6b4a` | coral-colored **text** and links (≥ 4.8:1) |

Never put white text on `--fs-coral` (3.7:1, fails).

## Status colors

Used only through `fs_badge()` / `.fs-badge--*`. A badge is the text color on a 12% tint of the same color, plus an icon and a word. All pairs below are ≥ 4.5:1 in both themes (checked 2026-10-06 on surface, page and tint).

| State key | Meaning | Light | Dark | Icon |
|---|---|---|---|---|
| `pass` / `up` / `enabled` / `online` | good / allowed | `#156b2e` | `#4ade80` | `fa-check` / `fa-circle-check` |
| `block` / `down` / `offline` / `error` | blocked / failed | `#c62828` | `#f87171` | `fa-xmark` / `fa-circle-xmark` |
| `reject` | rejected | `#a1400a` | `#fb923c` | `fa-hand` |
| `warn` / `degraded` / `pending` | attention | `#8a5a00` | `#facc15` | `fa-triangle-exclamation` |
| `info` / `match` | informational | `#0b63c4` | `#60a5fa` | `fa-circle-info` |
| `neutral` / `disabled` / `unknown` | inactive | `#5b6573` | `#9aa4b2` | `fa-circle-minus` / `fa-ban` |

Color is never the only signal: the badge always has text.

## Chart series

Charts (pie, line, bar) color their series with `--fs-series-1` … `--fs-series-8`, never with hex
values in the page. Each token has a light and a dark step of the same hue; read them at draw time
(`getComputedStyle(document.body).getPropertyValue('--fs-series-1')`) so the chart follows the theme.

| Token | Hue | Light | Dark |
|---|---|---|---|
| `--fs-series-1` | blue | `#2a78d6` | `#3987e5` |
| `--fs-series-2` | orange | `#eb6834` | `#d95926` |
| `--fs-series-3` | aqua | `#1baf7a` | `#199e70` |
| `--fs-series-4` | yellow | `#eda100` | `#c98500` |
| `--fs-series-5` | magenta | `#e87ba4` | `#d55181` |
| `--fs-series-6` | green | `#008300` | `#008300` |
| `--fs-series-7` | violet | `#4a3aa7` | `#9085e9` |
| `--fs-series-8` | red | `#e34948` | `#e66767` |
| `--fs-series-other` | grey | `#9aa3ae` | `#6b7380` |

Rules: assign the tokens in order and never cycle them; a ninth and later series fold into
"Other" (`--fs-series-other`). A series keeps its color when a filter hides others. The adjacent
pairs pass a color-vision-deficiency check (ΔE ≥ 8) in both themes; slots 3–5 are below 3:1 on the
light surface, so a chart always has a legend or table with the values (`status_logs_filter_summary.php`
is the reference). Status colors (`--fs-pass`, `--fs-block`, …) are never used as series colors.

## Typography

| Token | Value |
|---|---|
| `--fs-font-ui` | Roboto today; Fira Sans if that decision is taken. Self-hosted only |
| `--fs-font-mono` | `ui-monospace, SFMono-Regular, Menlo, Consolas, monospace` (or Fira Code / Roboto Mono when chosen) |
| scale | `--fs-fs-xs 12px`, `--fs-fs-sm 13px`, `--fs-fs-base 14px` (body), `--fs-fs-md 16px`, `--fs-fs-lg 20px` (h1), `--fs-fs-xl 24px` (dashboard KPI) |
| weights | 400 body, 500 table headers / labels, 600 headings |
| numbers | `font-variant-numeric: tabular-nums` on all tables |

`.fs-mono` is used for IPs, CIDRs, ports, MACs, interface names (`igb0`), hashes, counters and log lines.

## Spacing, size, radius, motion

| Token | Value |
|---|---|
| spacing | `--fs-sp-1 4px`, `-2 8px`, `-3 12px`, `-4 16px`, `-5 24px`, `-6 32px` |
| section gap | 24px between cards; 16px card padding; 8px 12px table cell padding |
| table row | min-height 36px |
| control height | 32px (small), 36px (default) |
| hit area | icon actions ≥ 32×32px (WCAG 2.5.8 target ≥ 24px) |
| radius | `--fs-r-sm 6px` controls, `--fs-r-md 8px` cards, `--fs-r-lg 12px` modals |
| shadow | `--fs-shadow-overlay` only (dropdowns, modals, sticky bars) |
| motion | `--fs-t-fast 120ms`, `--fs-t 180ms`, `ease-out`; data refreshes do not animate; `prefers-reduced-motion` turns off all transitions |

Use Bootstrap utilities (`d-flex`, `gap-2`, `ms-auto`, `text-truncate`) for layout. Don't use `mt-*` hacks to fix component spacing; fix the component instead.

## Icons (Font Awesome 6 Solid only)

One meaning maps to one icon. Workers must not pick alternatives.

| Action | Icon | Label (`aria-label` / tooltip) |
|---|---|---|
| Add | `fa-plus` | "Add {thing}" |
| Edit | `fa-pencil` | "Edit {name}" |
| Copy | `fa-regular fa-clone` | "Copy {name}" |
| Delete | `fa-trash-can` | "Delete {name}" |
| Disable / enable (toggle) | `fa-ban` / `fa-regular fa-square-check` | "Disable {name}" / "Enable {name}" (already the convention on 20+ pages; kept) |
| Save | `fa-floppy-disk` | "Save" |
| Apply changes | `fa-check` | "Apply changes" |
| Cancel / back | none (text button) | "Cancel" |
| Search | `fa-magnifying-glass` | (input has visible placeholder plus `aria-label`) |
| Filter | `fa-filter` | "Filter" |
| Refresh | `fa-arrow-rotate-right` | "Refresh" |
| Download / export | `fa-download` | "Download …" |
| Import / upload | `fa-upload` | "Import …" |
| Start / stop / restart service | `fa-play` / `fa-stop` / `fa-arrow-rotate-right` | "Start {svc}" … |
| Settings for this page | `fa-sliders` | "Settings" |
| Drag handle | `fa-grip-vertical` | "Drag to reorder" |
| Info / help | `fa-circle-info` | — |

**Canonical names only.** Legacy aliases (`fa-cog`, `fa-undo`, `fa-save`, `fa-times`, `fa-plus-circle`,
`fa-search`, `fa-exclamation-triangle`, …) and Font Awesome 4 syntax (`fa fa-x`) were renamed across core
in one pass, and the `Quality` CI job rejects them. Font Awesome still renders the aliases, so package pages keep working.

Meaning changes happen per page as it is converted: "page settings" links move from `fa-gear` / `fa-wrench` to
`fa-sliders`, and add buttons move from `fa-circle-plus` to `fa-plus`. Loading states use `fa-spinner fa-spin`.
The bare `<a class="fa-solid fa-x">` anchors with no inner `<i>` are replaced by `fs_row_actions()`. Decorative
icons get `aria-hidden="true"`.
