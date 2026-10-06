# 00 — Worker brief (read first)

You are converting FreeSense WebUI pages to the standard defined in this folder.
The pages live in `repositories/active/freesense/src/usr/local/www`.

Read in this order:

1. this brief
2. `01-tokens.md`: colors, type, spacing and icons (what things look like)
3. `02-page-types.md`: the six page types (how a page is laid out)
4. `03-components.md`: markup and PHP helpers (copy from here)
5. `04-page-map.md`: what to do with *your* pages

Then open the **pilot page** for your page type (listed in `02-page-types.md`) and copy it.

## Hard rules

1. **Change presentation, not behavior.** Don't touch config reads/writes, validation, privileges, `write_config()`, or service reloads. The only exceptions are tasks that `04-page-map.md` explicitly marks **logic**.
2. **Never rename or delete a page, and never change a form field `name`.** URLs, POST field names and GET parameters are used by menus, widgets, `shortcuts/`, `help.php`, the REST API, packages, docs and bookmarks.
3. **A new view of a page** uses `?view=<name>` in the same file. If you really must add a file, add it to the **existing** privilege's `match` list in `src/etc/inc/priv.defs.inc`. Never create a new privilege.
4. **Stable API: do not rename** `.panel*`, `.table`, `.form-group`, `.content`, `.contains-table`, `table-rowdblclickedit`, `usepost`, `do-confirm`, `display_top_tabs()` or any public `Form_*` method. Packages depend on them.
5. **No new libraries and no CDNs.** The appliance runs offline. Use Bootstrap 5.3, jQuery 4, Font Awesome 6 (`fa-solid`) and the `fs_*` helpers.
6. **No raw colors or pixel literals in page files.** Use tokens and component classes. No new inline `style=""`; remove the ones you touch.
7. **Stay in your lane.** Edit only the files in your assignment. If you need a change to a shared file (`head.inc`, `guiconfig.inc`, `includes/fs_ui.inc`, `css/_freesense-*.css`, `js/freesense-ui.js`, `classes/Form/*`), don't make it. Write it up in your PR description under "Shared change needed" and stop.
8. **Escape output.** Every value you print goes through `htmlspecialchars()` (the helpers do this for you). Keep `gettext()` around every user-visible string.
9. **Remove copy-paste instead of adding to it.** If you add a search box by writing jQuery, you did it wrong; use `data-fs-table`.

## Workflow per PR

1. `git switch main && git pull`, then `git switch -c webui/<area>-<what>`, e.g. `webui/firewall-lists`.
2. Convert the pages, one commit per page or a small group of pages.
3. Run local checks:
   - `docker run --rm -v "$PWD:/src" -w /src php:8.5-cli sh tools/ci/php-lint.sh`
   - the smoke tests from `.github/workflows/quality.yml` if you touched anything under `src/etc/inc` or `classes/`
4. Check every page you changed on the 1.1 test VM (copy the files onto the VM, or wait for the nightly build). Use **both themes** at **375px and 1440px**. Do every action on the page once: add, edit, copy, toggle, delete with cancel, delete with confirm, search, sort, bulk select, and save with a validation error.
5. Open a PR. The description includes the page list, before/after screenshots (dark theme, 1440px), the checklist below with each item ticked, and any "Shared change needed" notes.
6. CI (`quality`, `semgrep`) must be green. **Do not trigger a manual build.** A merge produces a System build only, through the nightly Development build.

## Review checklist (copy into the PR)

- [ ] Page matches its page type in `02-page-types.md` and looks like its pilot.
- [ ] Primary action (Add) is in the page header, and only there.
- [ ] Tabs come from `fs_tabs()` / registry (or `display_top_tabs()` for dynamic groups).
- [ ] List: toolbar with search and count; the empty state renders; the no-results row renders.
- [ ] Row actions use `fs_row_actions()`; each has an `aria-label`; delete opens the confirm modal naming the object.
- [ ] Status shown with `fs_badge()` (icon + text, never color alone).
- [ ] Addresses, ports, MACs and counters use `.fs-mono`.
- [ ] No new inline styles, `onclick=`, raw hex or `!important`.
- [ ] Every form field `name`, URL and GET/POST parameter is unchanged.
- [ ] Both themes, 375px (no page-level horizontal scroll) and 1440px checked.
- [ ] Keyboard: Tab reaches every control, focus is visible, Esc closes the modal and clears search.
- [ ] `php-lint` passes; CI is green.

## Definition of done for a page

The page shows no leftovers: no grey `.panel-heading` strips, no bottom Add buttons, no icon-only links without labels, no `confirm()` text dialogs, no hand-rolled search JS, and no BS3 classes (`col-sm-offset-*`, `.well`, `.hidden`, `.close`, `.dl-horizontal`).
