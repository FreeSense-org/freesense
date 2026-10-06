# 03 — Components and helpers

Phase A builds these in `includes/fs_ui.inc` (PHP), `css/_freesense-components.css`
and `js/freesense-ui.js`. **The signatures below are the contract.** Phase A
implements them exactly, and workers use only these. All helpers escape their
arguments; you pass raw strings, already passed through `gettext()`.

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

The legacy flags (`$system_logs_filter_form_hidden`, `$monitoring_settings_form_hidden`, …) keep
working, and Phase A re-implements them as page actions inside `head.inc`.

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

Dynamic groups (one tab per interface or per zone) keep building `$tab_array` in the page
and call `display_top_tabs($tab_array)` directly. They get the same rendering.

Rendering (Phase A changes `display_top_tabs()` but keeps its signature): an underline tab bar.
The active tab has `aria-current="page"` and a 2px coral underline. When tabs don't fit, the
rest move into a **More ▾** dropdown; on phones the bar scrolls horizontally. The old
`<select>` fallback is removed.

## View switch (second level inside one page)

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
POST handlers redirect back to the same view.

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
| Count | "12 of 40" (`aria-live="polite"`) |
| No results | inserts a "No {things} match “term”. Clear search" row; never a blank table |
| Bulk | the select-all checkbox is tri-state; while ≥ 1 row is selected the toolbar swaps to the bulk bar ("3 selected" + actions + Clear); hidden rows are deselected when search hides them |
| Sort | keeps `data-sortable`; sets `aria-sort` on the sorted `<th>` |
| Sticky header | `thead` sticks below the navbar |

Bulk buttons are `type="submit"` with the **existing** `name` (`del_x`, `toggle_x`, …) inside the page's existing
`<form>`. The server handlers are unchanged. Only offer bulk for actions whose handler already accepts arrays.

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
- Esc and a backdrop click cancel. Focus returns to the trigger.
- Danger tools (reboot, halt, defaults) use the inline **danger confirm card** instead, because those pages are already a confirmation step.

## Badge

`fs_badge(string $state, ?string $label = null, ?string $title = null): string` produces
`<span class="fs-badge fs-badge--pass"><i class="fa-solid fa-check" aria-hidden="true"></i>Pass</span>`.
For the states and colors, see `01-tokens.md`. It is used for rule actions, gateway/service/tunnel status,
enabled/disabled, lease state and log action. Don't use bare colored icons anymore.

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

## Sticky action bar

The Form classes render the global buttons (`$form->addGlobal(...)`, default Save) in `.fs-actionbar`, which
sticks to the viewport bottom when the form is taller than the screen. **Workers add nothing.** Don't add your own
Save buttons mid-form. Secondary page buttons (Cancel, Test, Download) go into the same bar via `addGlobal`.

## Alerts

`print_info_box()`, `print_callout()` and `print_apply_box()` keep their signatures and are restyled centrally
(icon, message, optional action button). Use `'success'`, `'info'`, `'warning'` and `'danger'` only. Don't build
your own `.alert` markup.

## Console output

Use `<pre class="fs-console">` for command and tool output: mono, surface-raised background, scrolls inside the card
(max 60vh), with a Copy button in the card header (`data-fs-copy="#id"`).

## Summary tile

```php
fs_tile(gettext('Online'), 3, 'pass');                  // label, value, optional badge state
fs_tile(gettext('RTT'), '12 ms', null, 'Average of all gateways');
```

Tiles sit in a `.fs-tiles` row: 2–4 per row on desktop, 2 per row on phones.
