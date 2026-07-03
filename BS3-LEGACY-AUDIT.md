# BS3 legacy audit — webConfigurator (2026-07-03)

Systematic grep sweep of `src/usr/local/www` + `src/etc/inc` (php/inc/js, vendor
excluded) for every Bootstrap-3-era class, data-attribute and jQuery-plugin call.
Companion to the compat layer (`css/_freesense-bs5-shim.css` +
`js/freesense-bs5-compat.js`). Re-run the battery after page migrations to track
progress.

## A. Was BROKEN — fixed in this commit

| Pattern | Files | Defect | Fix |
| --- | --- | --- | --- |
| `progress-bar-{success,info,warning,danger}` | 5 (dashboard system_information + disks widgets, dhcp_leases, pkg installer, wizard) | BS5 dropped the variants → every bar rendered brand-coral regardless of semantic state | shim maps them to `--bs-{success,…}`; `.progress-bar.active` → BS5 stripes animation |
| `data-toggle`/`data-*` in **ajax-injected** fragments | any refreshed widget / pkg page | the attr upgrade ran once at DOMContentLoaded — tooltips/collapses inside re-rendered fragments went dead | MutationObserver in the compat bridge re-upgrades added subtrees |
| `data-original-title` | 4 | BS5 reads `title`/`data-bs-original-title`; tooltip text ignored | mapped in `upgradeMarkup()` |

## B. Legacy but WORKING via the compat layer (migration roadmap)

| Pattern | Files | Covered by |
| --- | --- | --- |
| `panel-*` (panel/heading/title/body/footer) | **126** | shim panels-as-cards + core styling |
| `table-condensed` | **95** | shim (`table-sm` equivalent) |
| `form-group` / `control-label` / `help-block` | 33 / 24 / 27 | shim (emitted centrally by `classes/Form/*`) |
| `col-{sm,md,lg}-offset-*` | 30 | shim offset classes (Form framework column math) |
| `data-toggle=` (static markup) | 23 | JS bridge attr rewrite |
| `btn-xs` | 20 | shim |
| `pull-{right,left}` | 12 | shim |
| jQuery `.collapse()/.modal()/.tab()` | 11 | JS plugin bridge |
| `btn-default` | 8 | shim (themed secondary look) |
| `dl-horizontal` | 7 | shim (status/diag label columns) |
| `collapse in` / `panel-collapse` | 7 | bridge converts `.in`→`.show` (now also for ajax content) |
| jQuery `.tooltip()/.popover()` | 5 | JS plugin bridge |
| `data-dismiss` / `data-placement` / `data-target` | 4 / 2 / 1 | JS bridge |
| `bg-{success,…}"` | 4 | still native in BS5 — fine |
| `col-xs-*` | 3 (index.php, auth.inc, shaper.inc) | shim |
| `class="close"` | 2 | shim (legacy close buttons) |
| `center-block` / `img-responsive` / `page-header` | 2 | shim |
| `input-group-addon` | 1 (`Form/IpAddress.class.php`) | shim + core corner fixes |
| `class="checkbox"` wrapper | 1 (`captiveportal.inc` portal template) | shim |
| `select` stamped `form-control` (not `form-select`) | all selects (`Form_Input` default) | core CSS targets both |

## C. CLEAN — zero hits (BS3 vocabulary that simply isn't used)

`label-*`, `.well`, `hidden-*`/`visible-*`, `sr-only`, `glyphicon`,
`form-inline`, `checkbox-inline`/`radio-inline`, `media-*`, `navbar-default/
inverse/fixed-top`, `caret` spans, `form-control-static`, `$.fn.button()`,
`affix`/`scrollspy`, `btn-group-justified`, `nav-stacked`, `modal fade in`,
`row-fluid`, `list-group-item-*`, `text-left/right"`.

## D. Migration plan (payoff order)

1. **`classes/Form/*.class.php`** — the generator emits most of column B
   (form-group, control-label, help-block, offsets, checkbox wrappers,
   `form-control` on selects). Migrating it to BS5 grid + `form-check`/
   `form-switch` + `form-select` upgrades ~200 pages at once and lets the
   form half of the shim be deleted. Includes the boolean-settings →
   **switches** win, and removes the `display:block` class collisions with
   customizable selects.
2. **Mechanical seds, page-scoped, then delete shim blocks**: `table-condensed`
   → `table-sm` (95 files), `panel-*` → `card` markup (126 files — biggest but
   dumbest), `pull-*` → `float-end/start`, `btn-xs` → `btn-sm` (or keep as a
   FreeSense size), `data-toggle` → `data-bs-toggle` (23 files) after which the
   MutationObserver can go too.
3. **Vendor jquery-treegrid** ships bootstrap2/3 variants — check which pages
   still load it and swap to a maintained tree renderer eventually.
4. Optional polish: Tom Select on long selects (search-in-select), micro-caps
   widget headers, `status_graph.php` d3 restyle.
