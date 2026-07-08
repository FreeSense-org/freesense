# FreeSense — Full Bootstrap 3 → 5 Migration Plan

Status: DRAFT (populated from the automated BS3-leftover audit of freesense-src
and freesense-ports). Goal: **complete** the BS3.4.1 → BS5.3.8 migration with no
leftovers, across the base webConfigurator AND all add-on package GUIs.

## Why this exists
The GUI was migrated BS3.4.1 → BS5.3.8 but the migration is partial. Confirmed
bugs already fixed:
- Dashboard widgets rendered collapsed: `collapse in` (BS3) vs `collapse show`
  (BS5). Fixed in index.php (commit b82c85d).

## Vendor / shim architecture (verified by audit)
- Bundle in use: `bootstrap.bundle.min.js` = **v5.3.8** (loaded from foot.inc).
- `vendor/bootstrap/js/bootstrap.min.js` = **v3.4.1** — stale file present on
  disk. Audit found NO page in freesense-src includes it (foot.inc uses the 5.x
  bundle), so it is dead weight, not a live BS3 loader. Still: delete it to
  prevent accidental reference.
- **Active compat shim (site-wide, intentional):**
  - `css/_freesense-bs5-shim.css` (306 lines) — back-fills BS3 CSS classes
    (panel*, form-group/control-label/help-block, table-condensed, pull-*,
    col-xs-*, btn-default/btn-xs, caret, center-block, hidden-*/visible-*,
    checkbox/radio, has-error/success/warning, .close, etc.). Loaded head.inc:72
    AFTER bootstrap.min.css. Does NOT define `.in`.
  - `js/freesense-bs5-compat.js` (165 lines) — jQuery→vanilla plugin bridge +
    `data-*`→`data-bs-*` rewriter (with MutationObserver for ajax) + the
    `.collapse.in`→`.collapse.show` converter. Loaded foot.inc:43 AFTER the
    bundle, BEFORE FreeSense.js.
  - Per its header, the shim exists mainly for third-party PACKAGE pages that
    still ship BS3 markup. The base system is native BS5 apart from the items
    below.

---

## Part A — freesense-src (base webConfigurator)  [AUDIT COMPLETE]

Bundled BS: **v5.3.8**. Base JS/framework is fully BS5-native (all Bootstrap JS
uses `bootstrap.X.getOrCreateInstance(...)`; zero jQuery plugin calls in base).
Most "BS3" classes are cosmetically correct because the CSS shim back-fills them.

### GENUINELY-BROKEN source leftovers (masked only by the runtime shim — fix these)

**A1. `addClass('in')` collapse markers → should be `show` (15 sites / 6 files).**
Same bug family as the dashboard fix. Kept alive ONLY by the JS `.in`→`.show`
rewrite (the most fragile part of the shim — runtime DOM patching, no CSS
back-fill). Sites:
- vpn_ipsec_mobile.php: 625, 665, 702, 745, 811, 834, 858, 883, 908, 931 (10×)
- vpn_ipsec_settings.php:477
- system_authservers.php:581
- system_gateways_edit.php:215
- system_advanced_firewall.php:223
(Correctly-migrated for contrast — already emit `show`: Form/Section.class.php:109-113,
index.php dashboard, status_logs_common.inc, status_logs_vpn.php.)

**A2. Mixed `data-toggle="popover"` (3 sites) → normalize to `data-bs-toggle`.**
- firewall_rules.php:115, guiconfig.inc:1122, status_captiveportal.php:69
(Function today via dual selector + compat rewrite; still BS3 source.)

Note: index.php `data-toggle="close"` (409/559) and FreeSense.js `data-toggle="disable"`
are CUSTOM handlers, NOT Bootstrap — leave them.

### SHIMMED / cosmetically-correct (fix only in the final shim-removal pass)
- `panel*` — 528× / 124 files (CSS-shimmed)
- `form-group`/`control-label`/`help-block` — ~190× / 59 files (emitted by the
  Form_* renderers in classes/Form/*; CSS-shimmed)
- `table-condensed` 135×, `pull-left/right` ~14×, `btn-default`/`btn-xs` ~10×,
  `caret` 9×, `col-xs-12` 1×, `center-block` 3× (404/50x/csrf_error), `checkbox`/
  `radio` wrappers ~11× — all CSS-shimmed.

### ALREADY FULLY MIGRATED (no action)
`label label-*` badges (0), `glyphicon` (0), `input-lg/sm` (0), `sr-only` (0),
BS3 dropdown `<li>` structure (0), jQuery Bootstrap plugin API (0 in base),
modal markup (BS5), `data-ride` (0 in markup). `data-bs-*` used across 24 files.

### Highest-leverage fix targets (base)
1. classes/Form/Group.class.php + Section.class.php — shared Form renderers; make
   the `in`→`show` fix framework-wide so page-level addClass('in') can follow.
2. vpn_ipsec_mobile.php — 10 of the 15 addClass('in') sites (heaviest offender).
3. system_authservers.php, system_certmanager.php, system_advanced_firewall.php,
   system_gateways_edit.php, vpn_ipsec_settings.php — remaining collapse sites.
4. guiconfig.inc — popover data-toggle + pull-* used across many pages.
5. js/freesense-bs5-compat.js — the shim; goal is to make its `.in`→`.show`
   rewrite removable once A1 is done.

## Part B — freesense-ports (add-on package GUIs)  [AUDIT COMPLETE]

471 GUI files / ~54 packages. **No package bundles its own Bootstrap** (verified
twice) and **package XML is clean** (no BS3 HTML in field descriptions). All
packages inherit the base BS5.3.8 AND the site-wide compat shim.

### Raw BS3-ism inventory (~840 total, heavily concentrated)
- snort + suricata + pfBlockerNG(×2) = ~677 of ~840.
- Dominant pattern: the standard pfSense `panel panel-default` page-box (~500
  hits) — **CSS-shimmed, cosmetically fine** (see reframing below).
- Category 1 (`data-toggle`/`-dismiss`/`-target`): 52 across 20 files.
- Category 2 (`collapse in` / JS `.addClass('in')`): 8 + 2 files.
- Category 3 genuine utilities: `pull-*` (75 in pfblockerng_threats alone),
  `label label-*`→badge (23/5 files), `btn-default`, `caret`, `control-label`/
  `help-block`.
- Category 4 (jQuery plugin calls `.modal()/.collapse()/.popover()/.tooltip()`):
  49 across 14 files — worst: snort_preprocessors.php (22 `.collapse()`).
- Category 5 self-bundled BS: NONE. Category 6 XML: NONE.

### Per-package raw ranking (worst first)
snort (227) · suricata (180) · pfBlockerNG-devel (135) · pfBlockerNG (135) ·
haproxy/-devel (57 each) · Status_Monitoring (37) · squid (34) · WireGuard (32) ·
acme (28) · Status_Traffic_Totals (22) · apcupsd (19) · tftpd/freeradius3 (12) ·
openvpn-client-export (13) · ANDwatch (12) · ~17 more at 3-10 (cosmetic) ·
~20 packages with ZERO GUI isms.

snort & suricata share near-identical GUI code → one codemod fixes both.
pfBlockerNG & -devel are duplicate code → keep in sync.

---

## ★ CRITICAL REFRAMING (verified against the base shim) ★

The compat shim is loaded on EVERY webConfigurator page, INCLUDING package pages.
Verified coverage:
- **CSS shim** (`_freesense-bs5-shim.css`) back-fills `.panel*`, `.pull-*`,
  `.well`, `.caret`, `.btn-default`, AND `.label`/`.label-*` (explicit
  "BS3 .label == BS5 .badge"). → all Category-3 cosmetic classes render correctly.
- **JS shim** (`freesense-bs5-compat.js`): (1) jQuery bridge so `$(el).modal()/
  .collapse()/.tab()/.tooltip()/.popover()/.dropdown()` call the vanilla BS5 API;
  (2) rewrites `data-toggle`→`data-bs-toggle` (+ all data-* incl. dismiss/target/
  dropdown/tooltip option attrs) at load AND via MutationObserver for ajax; (3)
  converts `.collapse.in`→`.collapse.show`.

**Therefore essentially ALL of the ~840 ports BS3-isms and the base A1/A2 items
are MASKED AT RUNTIME by the shim — nothing is actually broken today.** The
dashboard bug was the lone exception because its `.in`→`.show` path depends on
the *JS* rewrite, which is the fragile part (timing/ajax-rendered content can
render before the observer upgrades it).

### What this means for the project
This is NOT a "fix broken UIs" job — it's a "remove the dependency on the shim so
the source is clean BS5, then delete the shim" cleanup. Priority is therefore
LOW-URGENCY / hygiene, EXCEPT:
- **The `.collapse.in` class (base A1: 15 sites; ports: 8+2)** is the one category
  masked only by fragile JS runtime rewriting, not CSS. This is the highest-value
  fix and the most likely to produce real bugs (like the dashboard). Do this first.
- Everything else can stay shimmed indefinitely with zero user impact and be
  converted opportunistically.

---

## Migration strategy (reframed: shim-removal cleanup, not bug-fixing)

Since the shim masks ~everything, order by RISK, not by raw count. The only
category with real bug potential is `.collapse.in` (JS-only masking).

### Phase 1 — the fragile category: `.in` → `.show`  ✅ DONE
Commits: base 5267671, packages afa20cc. All `collapse in` / `addClass('in')`
converted to `show`; grep of both repos returns 0. The compat.js `.in`->`.show`
rewrite is now dead code (can be removed in Phase 6).

### Phase 1 (original notes) — the fragile category: `.in` → `.show`  (DO FIRST; real bug risk)
Convert every BS3 shown-collapse marker to BS5 so it no longer depends on the
JS runtime rewrite (the exact failure mode behind the dashboard bug).
- **Base (15 sites / 6 files):** vpn_ipsec_mobile.php (10), vpn_ipsec_settings.php,
  system_authservers.php, system_gateways_edit.php, system_advanced_firewall.php.
  Best done at the source: audit classes/Form/Group.class.php — if the page-level
  `->addClass('in')` funnels through a Form helper, fix it once there.
- **Ports (8 markup + 2 JS):** snort_preprocessors.php (5), status_andwatch.php,
  vpn_openvpn_export.php, acme_certificates.php; JS `.removeClass('out').addClass('in')`
  in pfBlockerNG.js (×2 copies).
- Verify: grep for `collapse in` and `addClass('in')` returns 0; then the
  `.collapse.in`→`.show` block in compat.js can be deleted.

### Phase 2 — normalize `data-toggle` → `data-bs-toggle`  ✅ DONE
Commits: base 0683b28 (3 popover sites), packages e420d42 (58 attrs / 21 files).
All BS3 declarative data-* renamed to data-bs-* (toggle/dismiss/target/parent +
popover option attrs). grep of both repos clean, no double-prefixes. The
compat.js data-* rewriter is now unneeded for these (removable in Phase 6).

### Phase 2 (original notes) — normalize `data-toggle` → `data-bs-toggle`
Removes reliance on the JS attribute-rewriter (works today, but rewriting on
every DOM mutation is overhead + a race for ajax content).
- Base: 3 mixed popover sites (firewall_rules.php:115, guiconfig.inc:1122,
  status_captiveportal.php:69). (Leave the CUSTOM `data-toggle="close"/"disable"`
  handlers — not Bootstrap.)
- Ports: 52 sites / 20 files. snort+suricata share code → one codemod for both;
  pfBlockerNG×2 in sync. Map: data-toggle→data-bs-toggle, data-dismiss→
  data-bs-dismiss, data-target→data-bs-target (+ option attrs per compat.js map).
- Verify: no non-`-bs` `data-(toggle|dismiss|target)=` on real BS components.

### Phase 3 — jQuery plugin calls → vanilla BS5 API  ⏸ DEFERRED (reasoned)
~44 calls / 12 files in ports (snort_preprocessors.php has 22 `.collapse()`).
A mechanical regex codemod was ATTEMPTED and REVERTED: the call sites have
varied, multi-line/nested forms (e.g. a `.collapse('show')` split across an
`if (...)` line in snort_preprocessors.php) that a text-replacement corrupts.
Simple `$('#id').modal('show')` cases convert fine; the complex ones need
per-line manual review.

Decision: DEFER. These calls WORK TODAY via the base compat shim's `$.fn.modal/
.collapse/...` jQuery bridge (tiny, stable), so there is zero user-facing benefit
to a risky manual rewrite of 44 sites. This is the highest-risk / lowest-reward
phase. Do it opportunistically, per-file, WITH a browser test of each page's
modals/accordions — NOT as a bulk codemod. Until then the jQuery bridge in
compat.js MUST stay (it is the only thing Phase 6 cannot remove yet).

Safe conversion pattern when done manually, per call:
  $('#id').modal('show')  ->  bootstrap.Modal.getOrCreateInstance(document.getElementById('id')).show()
  $('#id').collapse('toggle') -> bootstrap.Collapse.getOrCreateInstance(document.getElementById('id')).toggle()
(Use document.getElementById / querySelector — NOT `$(sel)[0]` inside a regex —
and hand-check each surrounding statement.)

### Phase 4 — cosmetic class renames (pure hygiene; shim-covered, zero urgency)
Batch codemod, any time. `pull-left/right`→`float-start/end`,
`label label-X`→`badge bg-X`, `btn-default`→`btn-secondary`, `caret`→FA chevron,
`well`→card, `control-label`→`form-label`, `help-block`→`form-text`. The big
`panel*` set (528 base + ~500 ports) can stay as-is or convert to `card*` later —
lowest priority since the CSS shim styles it correctly.

### Phase 5 — dropdown structure (pfBlockerNG chart date-range)  ✅ DONE
Commit a91a3da. Converted the alerts-chart date-range dropdown (both pfBlockerNG
and -devel) from BS3 (navbar-right, navbar-nav wrapper, caret span, plain
li>a) to a BS5 btn-group dropdown (float-end, dropdown-toggle button,
dropdown-item links). JS hooks (#chartEvent, .navlnk) preserved.

### Phase 6 — retire the shim + verify
Only after Phases 1-3 (the JS-masked categories) are done:
- Delete `js/freesense-bs5-compat.js` + its foot.inc include; delete the stale
  `vendor/bootstrap/js/bootstrap.min.js` (v3.4.1, unreferenced).
- Keep `_freesense-bs5-shim.css` until Phase 4 cosmetics are done, then delete.
- Verify: grep both repos returns 0 of `collapse in`, `addClass('in')`,
  non-`-bs` `data-toggle`, jQuery `.modal(/.collapse(/.popover(/.tooltip(`.
- Browser-test: dashboard, all Status/*, *_edit forms, and the top-10 package
  pages (snort preprocessors+rules+alerts+sid, suricata equivalents, pfBlockerNG
  alerts chart dropdown, Status_Monitoring, tftpd upload modal, acme, WireGuard).

## Additional BS3->BS5 regression found & fixed: button spacing
BS3 gave adjacent `.btn` elements intrinsic spacing; BS5 removed it. FreeSense's
theme only patched this with `form .btn + .btn { margin-left: 5px }` in
`css/_freesense-core.css` — too narrow: it missed (a) buttons OUTSIDE a `<form>`
(toolbar/action buttons on diag_arp, diag_gmirror, status pages) and (b)
non-direct-sibling buttons — so those touched/overlapped inconsistently.
Fixed by generalizing to `.btn + .btn { margin-left: 5px }` with exclusions for
`.btn-group`/`.btn-group-vertical`/`.input-group` (joined by design) plus
`.btn-toolbar` row spacing. Applied to css/_freesense-core.css (committed) and
hot-patched on the live box. This is a theme-CSS fix, independent of the shim.

## Bottom line
Nothing is broken today; the shim carries it (button spacing was the exception,
now fixed). The *high-value* work is Phases
1-3 (un-mask the JS-shimmed categories, especially `.in`) — modest, scriptable,
concentrated in snort/suricata/pfBlockerNG + a handful of base VPN/system pages.
Phase 4 cosmetics are optional hygiene. Only after 1-3 can the JS shim be safely
removed.
