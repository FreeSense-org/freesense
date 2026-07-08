# FreeSense — Full de-shim plan (remove the BS3→BS5 compat layer)

Goal: delete `css/_freesense-bs5-shim.css` and `js/freesense-bs5-compat.js`
entirely, so the GUI is 100% native Bootstrap 5 with no compat layer — WITHOUT
regressing the coral/ink look or breaking JS behavior.

## Key realization (drives the whole strategy)
`.panel*` is NOT a disposable BS3 leftover. FreeSense has adopted it as its own
component: the core theme (`_freesense-core.css:228+`) styles `.panel`,
`.panel-heading`, `.panel-body`, `.panel-title` with the coral/ink look, the
Form framework (`classes/Form/Section.class.php`) emits panel markup on hundreds
of pages, and JS (`FreeSenseHelpers.js:479-491`) targets panel classes for the
collapse/config toggles. Renaming panel→card would mean rewriting the theme, the
Form framework, the JS, and ~1100 markup sites — huge and pointless.

So we DO NOT rename panels. Instead:
- **Keep `.panel*` as a permanent FreeSense component** (styled by core theme,
  layered on BS5 primitives). It stops being "a shimmed BS3 class".
- **Migrate only the genuinely-removable BS3 compat classes** to their BS5
  equivalents.
- **Then delete the shim** (its remaining job is gone).

## Current shim contents (what to reckon with)
CSS shim `_freesense-bs5-shim.css` back-fills: panel* (DUP with core theme!),
form-group/control-label/help-block/checkbox/radio, has-error/success/warning,
input-group-addon, col-xs-*, col-sm/md/lg float helpers, pull-*, center-block,
hidden/visible-*, well, thumbnail, page-header, list-inline, caret, btn-default,
btn-xs, label*, progress-bar-*, table-condensed, dl-horizontal, .close.
JS shim `freesense-bs5-compat.js`: jQuery plugin bridge + data-*→data-bs-*
rewriter + .collapse.in→.show converter.

## Phases

### D1 — Consolidate panels into core theme; drop panel rules from the shim
- The shim's `.panel*` block (lines ~19-44) DUPLICATES/conflicts with the core
  theme's (which loads later and wins anyway). Move any core-theme-missing bits
  (panel-footer, panel-group, panel-collapse) into `_freesense-core.css`, then
  DELETE the panel block from the shim. No markup/JS change. Verify panels look
  identical.

### D2 — Migrate removable compat CLASSES to BS5 (markup sweeps)
Safe 1:1, scriptable, per class (do + verify each):
- `pull-left`→`float-start`, `pull-right`→`float-end`  (14 base + 182 ports)
- `center-block`→`mx-auto d-block`  (404/50x/csrf_error)
- `img-responsive`→`img-fluid`, `table-condensed`→`table-sm`
- `well`→ (card or a kept `.fs-well`), `caret`→ remove (BS5 draws it)
- `hidden`→`d-none`, `hidden-xs/sm/..`→`d-*-none`, `visible-*`→ inverse
- `col-xs-*`→`col-*`
- `label label-X`→`badge bg-X`  (if any remain)
- `btn-default`→`btn-secondary`, `btn-xs`→`btn-sm`
Then remove each migrated class's rule from the shim CSS.

### D3 — Migrate Form-framework-emitted BS3 form classes
`form-group`/`control-label`/`help-block`/`checkbox`/`radio` are emitted by
`classes/Form/*` on nearly every form. Options:
 (a) KEEP them as FreeSense component classes (move their CSS to core theme,
     like panels) — lowest risk, no framework rewrite. RECOMMENDED.
 (b) Rewrite the Form framework to emit native BS5 (`mb-3`/`form-label`/
     `form-text`/`form-check`) — cleaner but touches every form; higher risk.
Decision: (a) — treat them as FreeSense components, move CSS to core, drop from
shim. Same rationale as panels.

### D4 — Retire the JS shim
Blocked on BS5-migration Phase 3 (jQuery `$(el).modal()/.collapse()` → vanilla).
Until those ~44 ports calls are converted, the `$.fn.*` bridge must stay. The
data-*→data-bs-* rewriter and .in→.show converter are already unneeded (BS5
Phase 1+2 done) — remove those two parts of compat.js now; keep only the jQuery
bridge until D-Phase-3.

### D5 — Delete the shim + verify
When D1-D4 done: delete `_freesense-bs5-shim.css` + its head.inc include, and
`freesense-bs5-compat.js` + its foot.inc include. Grep for every migrated class
returns 0 (or only core-theme definitions). Browser-test the top pages.

## Sequencing
1. D1 (panels→core) — safe, immediate, removes the biggest shim chunk.
2. D2 (class sweeps) — mechanical, per-class verify.
3. D3 (form classes→core) — like panels.
4. Trim compat.js (data-*/in→show parts) now; keep jQuery bridge.
5. BS5 Phase 3 (jQuery→vanilla) — the remaining blocker for full JS de-shim.
6. D5 delete + verify.

## Status
- [ ] D1 panels → core theme
- [ ] D2 compat class sweeps
- [ ] D3 form classes → core theme
- [ ] D4 trim compat.js
- [ ] D5 delete shim + verify
