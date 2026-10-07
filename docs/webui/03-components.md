# 03 — Components and helpers

Implemented in `includes/fs_ui.inc` (PHP), `css/_freesense-tokens.css`,
`css/_freesense-components.css` and `js/freesense-ui.js`. **The signatures below are
the contract**; workers use only these. All helpers escape their arguments: pass
raw strings, already passed through `gettext()`. Inline helpers (`fs_badge`,
`fs_row_actions`) return a string for `<?=…?>`; block helpers (`fs_tabs`,
`fs_view_switch`, `fs_table_toolbar`, `fs_empty_row`, `fs_tile`) echo.

Pilot pages to copy: `firewall_schedule.php` (List), `firewall_schedule_edit.php`
(Editor), `system_advanced_misc.php` (Settings), `status_gateways.php` (Status),
`diag_ping.php` (Tool), `services_unbound.php` (List views split out of a Settings
page, rule R1).

## Page title

`head.inc` renders `$pgtitle` as a breadcrumb plus an `<h1>`: every element but the
last is a crumb, the last one is the `<h1>`. `$pglinks` links crumbs as before
(`'@self'` = current URL). A trailing empty element is dropped. Editors end with
**"Add {thing}"** or **"Edit {thing}"** and put the record name in the crumb before it:

```php
$pgtitle = [gettext('Firewall'), gettext('Schedules'), htmlspecialchars($name), gettext('Edit schedule')];
$pglinks = ['', 'firewall_schedule.php', '', '@self'];
```

---

## Page actions (header buttons)

Set these **before** `include("head.inc")`.

```php
fs_page_action(gettext('Add schedule'), 'firewall_schedule_edit.php', 'fa-plus');            // primary
fs_page_action(gettext('Import'), 'firewall_aliases_import.php', 'fa-upload', 'secondary');
fs_page_action(gettext('Log settings'), '#', 'fa-sliders', 'secondary',
               ['data-bs-toggle' => 'collapse', 'data-bs-target' => '#manage-log-form']);
```

`fs_page_action(string $label, string $href, string $icon, string $variant = 'primary', array $attrs = [])`
appends to the global `$page_actions`, which `head.inc` renders right-aligned beside the `<h1>`.
The first `primary` wins; there can only be one. Check privileges before adding an action
(`isAllowedPage()`), exactly like the current code does for its links.

The legacy flags (`$system_logs_filter_form_hidden`, `$monitoring_settings_form_hidden`, …) and the
service/shortcut/help icons keep rendering as icon buttons after the page actions
(`.context-links`, now with accessible names). Pages converting to the standard replace their
flag with an explicit `fs_page_action()`.

## Tabs

```php
// includes/tabs/firewall.inc   (one file per menu area, loaded by fs_ui.inc)
$fs_tab_groups['firewall-nat'] = [
	[gettext('Port Forward'), 'firewall_nat.php'],
	[gettext('1:1'),          'firewall_nat_1to1.php'],
	[gettext('Outbound'),     'firewall_nat_out.php'],
	[gettext('NPt'),          'firewall_nat_npt.php'],
];

// in the page, after head.inc:
fs_tabs('firewall-nat', 'firewall_nat_out.php');
```

`fs_tabs(string $group, string $active_url, array $query = [])` builds the classic
`[label, active, url]` array and calls `display_top_tabs()`, so privilege filtering
(`isAllowedPage`) is unchanged. `$query` appends parameters to every tab URL (e.g. `['zone' => $cpzone]`).
Registry labels are plain text (`fs_tabs` escapes them). A group whose packages add tabs is
declared as `['package_group' => 'NAT', 'tabs' => [...]]`.

A list split out of a settings page (rule R1) is a **peer tab** pointing at a view of the same
file, e.g. `[gettext('Host Overrides'), 'services_unbound.php?view=hosts']`, and the page passes
the same URL as `$active_url`. No new file and no new privilege are needed.

Dynamic groups (one tab per interface or per zone) keep building `$tab_array` in the page
and call `display_top_tabs($tab_array)` directly. They get the same rendering.

Rendering (Phase A changes `display_top_tabs()` but keeps its signature): an underline tab bar.
The active tab has `aria-current="page"` and a 2px coral underline. When tabs don't fit, the
rest move into a **More ▾** dropdown (the active tab always stays visible); without JS the bar
scrolls horizontally. The old `<select>` fallback is removed.

Conditional tabs: a third element hides the tab when false (it still shows on its own page):

```php
[gettext('Source Tracking'), 'diag_dump_states_sources.php', config_path_enabled('system', 'lb_use_sticky')],
```

## View switch (second level inside one tab)

Use it only for a second level *below* a tab, e.g. DHCP's interface tabs, then
General / Address pools / Static mappings. Peer-level views belong in the tab registry (above).

```php
$view = fs_view_param(['general', 'hosts', 'domains'], 'general');   // validated $_GET['view']
fs_view_switch([
	'general' => gettext('General'),
	'hosts'   => gettext('Host overrides'),
	'domains' => gettext('Domain overrides'),
], $view);
```

This renders a segmented control of links (`?view=…`, preserving the other GET params).
`fs_view_param()` falls back to the default for unknown values; never echo `$_GET['view']` directly.
POST handlers redirect back to the same view, and the view's delete/toggle links carry `view=`
so the POST keeps it. Editors reached from a view return to it (redirect after save and Cancel).

## Card (section)

Keep the stable class names. They are restyled, not renamed:

```html
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title">Title <span class="fs-count">12</span></h2></div>
	<div class="panel-body">…</div>
</div>
```

`.panel` renders as a flat card: surface background, 1px border, 8px radius, no tinted heading.
`Form_Section` already outputs this markup. For list pages the toolbar **replaces** the heading:
see the data table below.

## Data table (list pattern)

The example shows every option. Schedules itself has no copy and no bulk handler, so
its real conversion drops the select column, the bulk config and the copy action. Only
render a feature when the page's server code supports it.

```php
<div class="panel panel-default fs-table" data-fs-table="schedules">
<?php fs_table_toolbar([
	'search'      => gettext('Search schedules…'),
	'filters'     => ['type' => [gettext('All types'), 'host' => 'Host', 'network' => 'Network']], // optional
	'bulk'        => [                                                            // optional
		['name' => 'del_x', 'label' => gettext('Delete'), 'icon' => 'fa-trash-can', 'variant' => 'danger',
		 'confirm' => gettext('Delete the selected schedules?')],
	],
]); ?>
	<div class="table-responsive">
	<table class="table table-hover table-rowdblclickedit" data-sortable>
		<thead><tr>
			<th class="fs-col-select"><input type="checkbox" data-fs-select-all aria-label="<?=gettext('Select all')?>"></th>
			<th class="fs-col-status"><?=gettext('Status')?></th>
			<th data-fs-search><?=gettext('Name')?></th>
			<th data-fs-search class="fs-mono"><?=gettext('Range')?></th>
			<th data-fs-search><?=gettext('Description')?></th>
			<th class="fs-col-actions"><span class="visually-hidden"><?=gettext('Actions')?></span></th>
		</tr></thead>
		<tbody>
		<?php foreach ($items as $i => $s): ?>
			<tr data-fs-filter-type="<?=htmlspecialchars($s['type'])?>" <?=$s['disabled'] ? 'class="fs-row-disabled"' : ''?>>
				<td><input type="checkbox" name="rule[]" value="<?=$i?>" data-fs-select aria-label="<?=sprintf(gettext('Select %s'), htmlspecialchars($s['name']))?>"></td>
				<td><?=fs_badge($active ? 'pass' : 'neutral', $active ? gettext('Active') : gettext('Idle'))?></td>
				<td><a href="firewall_schedule_edit.php?id=<?=$i?>"><?=htmlspecialchars($s['name'])?></a></td>
				<td class="fs-mono">…</td>
				<td><?=htmlspecialchars($s['descr'])?></td>
				<td><?=fs_row_actions([
					['edit',   "firewall_schedule_edit.php?id={$i}", $s['name']],
					// ['copy', "x_edit.php?dup={$i}", …] only where the editor handles ?dup= (see 04-page-map)
					['delete', "firewall_schedule.php?act=del&id={$i}", $s['name']],
				])?></td>
			</tr>
		<?php endforeach; ?>
		<?php if (empty($items)) fs_empty_row(6, gettext('No schedules yet.'),
			'firewall_schedule_edit.php', gettext('Add schedule')); ?>
		</tbody>
	</table>
	</div>
</div>
```

The `data-fs-table` enhancer (JS, progressive; the page works without it) adds:

| Feature | Behavior |
|---|---|
| Search | case-insensitive substring over `th[data-fs-search]` columns (all columns if none are marked); 150ms debounce; `/` focuses the field, Esc clears it; the term is kept in `?q=` via `history.replaceState` so filtered lists can be linked |
| Filters | each `filters` key matches the `data-fs-filter-<key>` value on `<tr>` |
| Count | "40 schedules" unfiltered, "12 of 40" while filtered (`aria-live="polite"`); pass `noun` and `noun_one` to the toolbar |
| No results | inserts a "No {things} match “term”. Clear search" row; never a blank table |
| Bulk | the select-all checkbox is tri-state; while ≥ 1 row is selected the toolbar swaps to the bulk bar ("3 selected" + actions + Clear); hidden rows are deselected when search hides them |
| Sort | keeps `data-sortable`; sets `aria-sort` on the sorted `<th>` |
| Static rows | rows with `data-fs-static` (e.g. rule separators) are never hidden or counted |

A sticky `thead` is **not** implemented yet: `.table-responsive` scrolls horizontally, which disables
`position: sticky` for its descendants. It needs a different scroll container and is tracked for Phase B.

Bulk buttons are `type="submit"` with the **existing** `name` (`del_x`, `toggle_x`, …) inside the page's existing
`<form>`. The server handlers are unchanged. Only offer bulk for actions whose handler already accepts arrays.

Lists whose `<table>` arrives later (loaded over AJAX, e.g. `pkg_mgr.php`) render the `.fs-table` card and toolbar
with the page and insert only the table. After inserting it, call the public re-init hook:

```js
$('#pkgtbl').html(data);
FreeSenseUI.initTables(document.getElementById('pkg-list'));   // or initTables() for the whole document
```

`FreeSenseUI.initTables(root)` enhances every `.fs-table` inside `root` (or `root` itself) that has a table and is
not enhanced yet (`root._fsTable.table`), makes a `data-sortable` table sortable and keeps its `aria-sort` in step.
Calling it again is harmless; the page-load enhancement is unchanged.

Server-filtered lists (logs, states, leases, ARP/NDP) keep their server filter. Pass `'search' => false`,
put their controls into the same toolbar markup (`fs_table_toolbar(['custom' => $html])`) and keep the count.

## Row actions

`fs_row_actions(array $actions): string`. Each entry is `[type, href, object_name, extra = []]`.

| type | icon | label | method |
|---|---|---|---|
| `edit` | `fa-pencil` | "Edit {name}" | GET link |
| `copy` | `fa-clone` | "Copy {name}" | GET link |
| `toggle` | `fa-toggle-on` / `-off` (`extra['enabled']`) | "Disable/Enable {name}" | `usepost` |
| `delete` | `fa-trash-can` | "Delete {name}" | `usepost` + confirm modal "Delete {thing} “{name}”?" (`extra['detail']` adds a consequence line) |
| `custom` | `extra['icon']` | `extra['label']` | `extra['post']` → `usepost` |

Output: `<div class="fs-actions">` containing `<a class="fs-action" href aria-label title><i class="fa-solid …" aria-hidden="true"></i></a>`.
Each hit area is 32×32px. Actions stay visible at 60% opacity and go to full opacity on row hover or focus; they never fully hide (touch screens have no hover).
Order is always **edit, copy, toggle, custom…, delete** (delete last, separated by 8px).

## Confirm modal

A single modal lives in `foot.inc`. `interceptGET()` opens it instead of `window.confirm()` for any `usepost` anchor with
`data-fs-confirm`, `.do-confirm` or `.fa-trash-can`. Legacy anchors get a generic "Delete this item?" message,
so package pages improve without edits. Submit buttons with `data-fs-confirm` use it too.

- The title names the object; the body states the consequence; buttons are **Cancel** (focused by default) and **{Verb}** (danger).
- Esc and a backdrop click cancel. Focus returns to the trigger. Esc works from the moment the dialog opens (also
  during the fade-in) and is not passed on, so an underlying modal form or list search stays as it was.
- Danger tools (reboot, halt, defaults) use the inline **danger confirm card** instead, because those pages are already a confirmation step.

## Danger confirm card

For pages that are themselves the confirmation step (reboot, halt, factory defaults) and for the warning cards of
the command prompt and file editor. A red-edged card with a reading width of 48rem.

```html
<div class="panel panel-default fs-danger-card">
	<div class="panel-heading">
		<h2 class="panel-title"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i>Reboot the system</h2>
	</div>
	<div class="panel-body fs-danger-body">
		<p>What happens:</p>
		<ul class="fs-danger-list"><li>…</li></ul>
	</div>
	<div class="panel-footer">
		<button class="btn btn-danger" …>Reboot now</button>
		<a class="btn btn-outline-secondary" href="/">Cancel</a>
	</div>
</div>
```

| Class | Use |
|---|---|
| `.fs-danger-card` | red edge, title icon in red, footer as a wrapping button row, max-width 48rem |
| `.fs-danger-card--wide` | full width (command prompt, edit file) |
| `.fs-danger-body` | padded body; `.fs-danger-list` for the consequences (no bottom margin when last) |
| `.fs-danger-wait` | centered "Rebooting…" state after the action (icon, `h2`, muted `p`); the icon is coral, override per page |

The action button names the verb ("Reboot now", "Halt", "Reset"), never "Yes". Page-specific parts (reboot
method radio cards, the defaults hint) stay in the page's `<style>`.

## Tool layout

Tool pages (ping, traceroute, DNS lookup, test port, authentication, S.M.A.R.T.) use two columns: an options card
on the left (22rem) and the results card on the right. Below 992px they stack.

```html
<div class="fs-tool">
	<form method="post" action="diag_ping.php" class="fs-tool-form">
		<div class="panel panel-default">
			<div class="panel-heading"><h2 class="panel-title">Options</h2></div>
			<div class="panel-body">
				<div><label class="form-label" for="host">…</label><input class="form-control fs-mono" id="host" …></div>
				<div class="fs-tool-row"><div>…</div><div>…</div></div>   <!-- two short fields side by side -->
			</div>
			<div class="panel-footer"><button type="submit" class="btn btn-primary" data-fs-busy="true">…</button></div>
		</div>
	</form>
	<div class="panel panel-default">
		<div class="panel-heading"><h2 class="panel-title">Results</h2><!-- Copy button once there is output --></div>
		<pre class="fs-console" id="…">…</pre>
		<!-- or, before the first run: -->
		<div class="fs-tool-empty"><i class="fa-solid fa-…" aria-hidden="true"></i><span>Enter a host and run …</span></div>
	</div>
</div>
```

| Class | Use |
|---|---|
| `.fs-tool` | the two-column grid; cards inside lose their bottom margin |
| `.fs-tool-form` | the options form: stacked fields, labels, help text, footer button row |
| `.fs-tool-row` | two fields side by side inside the options card |
| `.fs-tool-stack` | several result cards stacked in the right column (DNS lookup) |
| `.fs-tool-empty` | empty state of the results card before the first run (icon + one sentence) |
| `.fs-tool-verdict` | a badge/summary line above the output (test port, authentication); a following `.fs-console` gets a top border |

## Badge

`fs_badge(string $state, ?string $label = null, ?string $title = null): string` produces
`<span class="fs-badge fs-badge--pass"><i class="fa-solid fa-check" aria-hidden="true"></i>Pass</span>`.
For the states and colors, see `01-tokens.md`. It is used for rule actions, gateway/service/tunnel status,
enabled/disabled, lease state and log action. Don't use bare colored icons anymore.

## Modal form (rule R8)

Small create / edit forms (up to ~3 fields) open in a modal from a header or row action instead of
sitting under the list:

```php
fs_page_action(gettext('Create snapshot'), '#', 'fa-camera', 'primary', ['data-fs-modal' => '#be-create']);

fs_modal_form_begin('be-edit', gettext('Edit boot environment'));   // after the list
?>
	<input type="hidden" name="name" value="">
	<div class="mb-3">
		<label class="form-label" for="be-edit-target"><?=gettext('Name')?></label>
		<input class="form-control" id="be-edit-target" name="target" required>
	</div>
<?php
fs_modal_form_end(gettext('Save'), 'action', 'edit', 'fa-floppy-disk');
```

A row trigger (a `<button type="button" class="fs-action">` or a `custom` row action) carries
`data-fs-modal="#be-edit"`, `data-fs-modal-title="Edit “x”"` and `data-fs-fill` (JSON of field name → value).
The form is reset before filling, the first field gets focus, and the form posts to the page itself, so the
existing POST handler stays the source of truth. Use the Editor page for anything bigger.

After a failed save, pass the posted values as the fifth argument so the modal reopens with them
(the errors show above the list):

```php
fs_modal_form_begin('sysctl-edit', gettext('Add tunable'), '', [], $input_errors ? ['tunable' => $_POST['tunable'] ?? '', /* … */] : null);
```

When adding or editing has its own privilege (e.g. `services_wol_edit.php`), render the modal and
accept its POST only when `isAllowedPage()` allows that page.

## Empty state

`fs_empty_row(int $colspan, string $message, ?string $add_href = null, ?string $add_label = null)` renders a centered
muted row with an optional Add button. When the user can't add (no privilege), pass `null`.

## Entry grid (repeated simple values inside a form)

It keeps the existing repeatable-row mechanics (`addrowbutton`, `deleterow` in `FreeSenseHelpers.js`)
and the existing field names. Phase D restyles them in the Form classes:

```
Entries                                            4 entries
┌─────────────────────┬──────────────────────────┬────┐
│ Address (mono)      │ Description              │    │   ← one header row, not a label per row
├─────────────────────┼──────────────────────────┼────┤
│ [10.0.0.0/8       ] │ [RFC1918               ] │ 🗑 │
└─────────────────────┴──────────────────────────┴────┘
[＋ Add entry]
```

Use it when each row has ≤ 3 simple fields and no per-row options. Otherwise, convert to List + Editor (rule R2).

**Implemented** in `js/freesense-ui.js` (`initEntryGrid`), applied to every page automatically:

- The header row is built from each column's help title: the text before the first `<br>` in
  `setHelp()`, or the whole help if it is short, else the field's placeholder. Explanations after the
  `<br>` are shown once under the grid as notes, so write help as `'Address<br>Enter IP addresses …'`.
- Per-row help is hidden; the delete button is icon-only; the Add button (`addrow`, or any button
  whose id ends in `addrow`) moves directly under the rows. It keeps `.btn-success` / `.addbtn`,
  which some pages use to find it.
- Column visibility (`.hidden`) and placeholder-based titles follow the page on change, click and load
  (alias type, IPsec PRF). Row labels, field names, numbering and `add_row()` / `delete_row()` are untouched.
- More than 20 rows at load: a filter field and "n of m shown" count above the header. It matches the
  rows' text fields and selected options; non-matching rows are only hidden (`.fs-entrygrid-filtered`), so
  the page still posts every row. Empty rows and a row just added always stay visible; Enter does not submit.

## Advanced toggle

"Display Advanced" / "Hide Advanced" buttons (a `Form_Button` with the `fa-solid fa-gear` icon,
whose text the page flips while it shows / hides its own fields) are restyled by `js/freesense-ui.js`
(`initAdvancedToggles`) as quiet disclosure buttons with a chevron and `aria-expanded`. The button is found by
the gear icon plus one of: `btn-info` (legacy), a `data-fs-advanced` attribute, or an id starting with `btn`
that contains `adv` or `toggle` (`btnadvopts`, `btnsrctoggle`, `btnsrcadv` …). New toggles should use a
neutral class (`btn-outline-secondary`) with such an id or `data-fs-advanced`; the open state is read from the
translated "Hide Advanced" / "Hide Advanced Options" text.

## Form markup and focus (shared)

- Help text is emitted as `<span class="form-text help-block">` and the IP/mask separator as
  `input-group-text input-group-addon`; the legacy classes stay for page and package scripts that select them.
- `print_info_box()` and the notices modal use `.btn-close`; `print_apply_box()` uses a primary button.
- Form columns (`.form-group > [class*=col-]`) have `min-width: 0`, so a `.table-responsive` inside a form row
  scrolls inside the card on phones instead of widening the page.
- The first text field of a form page is focused on load on desktop only; on touch / coarse-pointer devices
  nothing is focused (and an `autofocus` field is blurred) so phones do not pop up the keyboard.
- Light theme: `.text-info`, `.link-info` and `.btn-outline-info` use `--fs-info` (Bootstrap's cyan is 1.7:1
  on white). Core pages should still prefer `.fs-muted` / `btn-outline-secondary`.

## Collapsible section

For whole groups of rarely changed settings (lifetimes, advanced options), use a collapsible `Form_Section`
instead of a per-field toggle:

```php
$state = COLLAPSIBLE | (!empty($input_errors) ? SEC_OPEN : SEC_CLOSED);
$section = new Form_Section('Advanced Options', 'ph1-advanced', $state);   // an id is required
```

The heading becomes one disclosure button (chevron, `aria-expanded`, keyboard). Closed sections still post
their fields. Open them after a failed save as above; if the browser rejects a field inside a closed
section, `js/freesense-ui.js` opens that section so the field can be shown.

## Searchable checklist

For a long multiple choice (privileges): `<select multiple data-fs-checklist>` becomes a searchable
checklist grouped by the label prefix before " - ", with a "Selected only" toggle and a live count.
Ticking an item selects its option, so the form posts exactly as before.

```php
$section->addInput(new Form_Select('sysprivs', '*Assigned privileges', null, $choices, true))
    ->setAttribute('data-fs-checklist', '')
    ->setAttribute('data-fs-descs', json_encode($descs))   // value => description (searched too)
    ->setAttribute('data-fs-warn', json_encode($admin))    // values flagged "Admin-level"
    ->setAttribute('data-fs-text-search', gettext('Search privileges…'));
```

Other labels: `data-fs-text-only`, `-count` (`%d selected`), `-empty`, `-warn`.

## Sticky action bar

The Form classes render the global buttons (`$form->addGlobal(...)`, default Save) in `.fs-actionbar`, which
sticks to the viewport bottom when the form is taller than the screen. Don't add your own Save buttons mid-form.
Secondary page buttons (Test, Download) go into the same bar via `addGlobal`. Editors add Cancel with:

```php
fs_form_cancel($form, 'firewall_schedule.php');   // before print($form)
```

## Alerts

`print_info_box()`, `print_callout()` and `print_apply_box()` keep their signatures and are restyled centrally
(icon, message, optional action button). Use `'success'`, `'info'`, `'warning'` and `'danger'` only. Don't build
your own `.alert` markup.

`print_callout($msg, $class = 'info', $heading = '')` renders a themed alert card
(`.alert.alert-{info|warning|danger|secondary}.fs-callout`, `role="note"`) with a leading icon, an optional
heading (plain text, escaped) and the message (HTML from the caller). `'default'` maps to the neutral style.

## Console output

Use `<pre class="fs-console">` for command and tool output: mono, surface-raised background, scrolls inside the card
(max 60vh), with a Copy button in the card header (`data-fs-copy="#id"`).

The Run button of a Tool page gets `->setAttribute('data-fs-busy', 'true')`: on submit it shows a spinner
and the form is marked `aria-busy`; the button stays enabled so its name/value is still posted.

## Summary tile

```php
fs_tile(gettext('Online'), 3, 'pass');                  // label, value, optional badge state
fs_tile(gettext('RTT'), '12 ms', null, 'Average of all gateways');
```

Tiles sit in a `.fs-tiles` row: 2–4 per row on desktop, 2 per row on phones.

## Summary card (Editor header)

An Editor page opens with one card that names the item, shows its state and a few saved facts
(reference: `vpn_ipsec_phase1.php`, `vpn_openvpn_server.php`). Build it with `fs_summary_card()`;
never copy its markup or CSS into a page.

```php
fs_summary_card([
	'icon' => 'fa-server',                         // Font Awesome name or full class
	'title' => $server['description'],             // text; '' shows 'placeholder' muted
	'placeholder' => gettext('New server'),
	'subtitle' => gettext('Remote access'),        // optional
	'badges' => [fs_badge('enabled')],             // fs_badge() output only
	'meta' => 'ovpns1',                            // short id beside the badges (mono, muted)
	'label' => gettext('Server summary'),          // region name for screen readers
	'facts' => [
		[gettext('Mode'), $mode],
		[gettext('Server'), $addr, 'mono' => true],                       // '' → "Not set" (muted)
		[gettext('Tunnel network'), $net, 'mono' => true, 'empty' => gettext('From server')],
		[gettext('Phase 1'), $p1, 'href' => $url, 'note' => 'IKE ID 1'], // link + muted second line
		[gettext('Protocol'), '', 'chips' => ['UDP4', 'TUN']],            // value as chips
		[gettext('Networks'), '', 'html' => $escaped_html],               // escape hatch, caller escapes
	],
	'actions' => [[gettext('Status'), 'status_openvpn.php', 'fa-chart-line']],   // optional small buttons
]);
```

- Every string is escaped by the helper; pass raw text. Only `'html'` is output as is.
- Show **saved** values (the stored item, or the defaults of a new one), not live form input.
- A new item gets an `info` or `pending` badge ("New", "Not saved yet"); a saved one `enabled` / `disabled`.
- 3–5 facts. The facts sit in a grid under a hairline; two columns on phones. Omit `facts` when there
  is nothing useful to show (e.g. a new user).

## Chips

Small outlined labels for algorithms, capabilities, protocols or group names. Several sit in a
`.fs-chips` row (a `<div>`, `<span>` or `<ul>` — list bullets are removed).

```html
<div class="fs-chips">
	<span class="fs-chip fs-chip--mono">AES-256-GCM</span>
	<span class="fs-chip fs-chip--mono is-warn" title="weak algorithm">3DES<i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i></span>
	<span class="fs-chip is-on">TLS crypt</span>
</div>
```

| Class | Use |
|---|---|
| `.fs-chip--mono` | technical tokens: algorithms, capability names |
| `.fs-chip--strong` | short codes that should read as tags (`UDP4`, `TSO`) |
| `.fs-chip--muted` / `.is-off` | secondary or switched-off values |
| `.is-on` | enabled / active (pass color on a light tint) |
| `.is-warn` | needs attention (weak algorithm); add a warning icon and a tooltip |
| `.is-na` | not supported (struck through, faded) |

A chip is not a badge: state of the item itself is a `fs_badge()`. When a chip carries meaning by
color (`is-on`, `is-warn`), the meaning is also in its text, title or a legend.

## Navigation and package menus

`head.inc` renders the top menus through `includes/fs_menu.inc`. System, Services, Status and
Diagnostics are **grouped panels** with a filter box. The others are single columns. `Ctrl+K` (or the
navbar Search button) opens a palette that searches every menu. Items never change their top-level menu.

Core items carry their group in `head.inc`:

```php
$services_menu[] = array(gettext("DNS Resolver"), "/services_unbound.php", 'group' => 'network');
```

**Packages** declare the group in their `<menu>` block:

```xml
<menu>
	<name>BIND DNS Server</name>
	<section>Services</section>
	<group>network</group>
	<url>/pkg_edit.php?xml=bind.xml</url>
</menu>
```

| Menu | Group ids (display order) |
|---|---|
| System | `general`, `access`, `network`, `other` |
| Services | `network`, `routing`, `security`, `proxy`, `monitoring`, `other` |
| Status | `overview`, `network`, `vpn`, `security`, `traffic`, `other` |
| Diagnostics | `tools`, `tables`, `system`, `power`, `other` |

An entry without `<group>` (or with an unknown id) is placed by `fs_menu_fallback_group()` from its
name, and otherwise lands in **Other**, so third-party packages always appear. Menus in Interfaces,
Firewall, VPN and Help ignore `<group>`.

