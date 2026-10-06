<?php
/*
 * fs_ui.inc
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
 * All rights reserved.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/*
 * WebUI component helpers (docs/webui/03-components.md).
 *
 * Every helper escapes its arguments: pass raw strings, already passed
 * through gettext(). Inline helpers (fs_badge, fs_row_actions) return a
 * string for <?=...?>; block helpers (fs_tabs, fs_view_switch,
 * fs_table_toolbar, fs_empty_row, fs_tile) echo.
 */

function fs_h($s) {
	return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function fs_attrs(array $attrs) {
	$out = '';
	foreach ($attrs as $name => $value) {
		if ($value === null || $value === false) {
			continue;
		}
		$out .= ' ' . fs_h($name);
		if ($value !== true) {
			$out .= '="' . fs_h($value) . '"';
		}
	}
	return $out;
}

/* ---------------------------------------------------------------- page actions */

/*
 * Queue a labelled button for the page header. Call before including head.inc.
 * $variant: 'primary' (one per page; later primaries render as secondary),
 * 'secondary' or 'danger'. $icon is a Font Awesome solid icon name ("fa-plus")
 * or a full class string ("fa-regular fa-clone").
 */
function fs_page_action($label, $href, $icon = null, $variant = 'primary', array $attrs = []) {
	global $page_actions;

	if (!is_array($page_actions)) {
		$page_actions = [];
	}
	$page_actions[] = [
		'label' => $label,
		'href' => $href,
		'icon' => $icon,
		'variant' => $variant,
		'attrs' => $attrs,
	];
}

function fs_icon_class($icon) {
	if (empty($icon)) {
		return '';
	}
	return (strpos($icon, ' ') === false) ? 'fa-solid ' . $icon : $icon;
}

function fs_render_page_actions() {
	global $page_actions;

	if (empty($page_actions) || !is_array($page_actions)) {
		return '';
	}

	$html = '';
	$have_primary = false;
	foreach ($page_actions as $a) {
		$variant = $a['variant'];
		if ($variant === 'primary') {
			if ($have_primary) {
				$variant = 'secondary';
			}
			$have_primary = true;
		}
		$class = [
			'primary' => 'btn btn-sm btn-primary',
			'danger' => 'btn btn-sm btn-outline-danger',
		][$variant] ?? 'btn btn-sm btn-outline-secondary';

		$attrs = array_merge(['href' => $a['href'], 'class' => $class], $a['attrs']);
		$icon = $a['icon'] ? '<i class="' . fs_h(fs_icon_class($a['icon'])) . ' icon-embed-btn" aria-hidden="true"></i>' : '';
		$html .= '<a' . fs_attrs($attrs) . '>' . $icon . fs_h($a['label']) . '</a>';
	}

	return $html;
}

/* ------------------------------------------------------------------ page title */

/*
 * Page title from $pgtitle / $pglinks: the leading elements become a
 * breadcrumb trail, the last one the <h1>. Elements are emitted as given
 * (callers pass gettext strings or already-escaped values), matching
 * genhtmltitle(). '@self' links to the current URL.
 */
function fs_page_title($title, $links = true) {
	if (is_array($title)) {
		$crumbs = array_values($title);
	} else {
		$crumbs = (($title === null) || ($title === '')) ? [] : [$title];
	}
	$links = is_array($links) ? array_values($links) : [];

	/* some pages end $pgtitle with an optional, empty element (e.g. no tab selected) */
	while (!empty($crumbs) && (trim((string)end($crumbs)) === '')) {
		array_pop($crumbs);
	}
	if (empty($crumbs)) {
		return '';
	}
	$links = array_slice($links, 0, count($crumbs));
	$href = function ($idx) use ($links) {
		$h = $links[$idx] ?? '';
		if ($h === '') {
			return '';
		}
		if ($h === '@self') {
			$h = $_SERVER['REQUEST_URI'];
		}
		return (substr($h, 0, 1) === '/') ? $h : '/' . $h;
	};

	$last = count($crumbs) - 1;
	$html = '';
	if ($last > 0) {
		$html .= '<ol class="breadcrumb">';
		for ($i = 0; $i < $last; $i++) {
			$h = $href($i);
			$html .= '<li>' . (($h !== '') ? '<a href="' . htmlentities($h) . '">' . $crumbs[$i] . '</a>' : $crumbs[$i]) . '</li>';
		}
		$html .= '</ol>';
	}

	$h = (($links[$last] ?? '') === '@self') ? '' : $href($last);
	$html .= '<h1 class="fs-pagehead-title">'
	    . (($h !== '') ? '<a href="' . htmlentities($h) . '">' . $crumbs[$last] . '</a>' : $crumbs[$last])
	    . '</h1>';

	return $html;
}

/* ------------------------------------------------------------------------ tabs */

/*
 * Tab groups are registered in includes/tabs/<area>.inc as
 *   $fs_tab_groups['group-id'] = [[label, url], ...];
 * or, when installed packages add tabs to the group,
 *   $fs_tab_groups['group-id'] = ['package_group' => 'NAT', 'tabs' => [[label, url], ...]];
 */
function fs_tab_groups() {
	static $groups = null;

	if ($groups === null) {
		$fs_tab_groups = [];
		foreach (glob(__DIR__ . '/tabs/*.inc') ?: [] as $file) {
			include $file;
		}
		$groups = $fs_tab_groups;
	}

	return $groups;
}

/*
 * Render a registered tab group. $active_url is the registry URL of the
 * current tab. $query is appended to every tab URL (e.g. ['zone' => $cpzone]).
 * Privilege filtering stays in display_top_tabs().
 */
function fs_tabs($group, $active_url, array $query = []) {
	$groups = fs_tab_groups();

	if (!isset($groups[$group])) {
		return;
	}

	$def = $groups[$group];
	$tabs = isset($def['tabs']) ? $def['tabs'] : $def;
	$suffix = empty($query) ? '' : http_build_query($query);

	$tab_array = [];
	foreach ($tabs as $tab) {
		$url = $tab[1];
		if ($suffix !== '') {
			$url .= ((strpos($url, '?') === false) ? '?' : '&') . $suffix;
		}
		/* registry labels are plain text; display_top_tabs() emits markup as given */
		$tab_array[] = [fs_h($tab[0]), ($tab[1] === $active_url), fs_h($url)];
	}

	if (!empty($def['package_group'])) {
		add_package_tabs($def['package_group'], $tab_array);
	}

	display_top_tabs($tab_array);
}

/* ------------------------------------------------------------------ view switch */

/* Validated view name from the request; unknown values fall back to $default. */
function fs_view_param(array $allowed, $default, $key = 'view') {
	$view = $_REQUEST[$key] ?? $default;
	return (is_string($view) && in_array($view, $allowed, true)) ? $view : $default;
}

/* Segmented control linking to ?view=<name>, keeping the other GET parameters. */
function fs_view_switch(array $views, $current, $key = 'view') {
	$params = $_GET;
	unset($params[$key]);
	$base = strtok($_SERVER['REQUEST_URI'] ?? '', '?');

	echo '<nav class="fs-viewswitch" aria-label="' . fs_h(gettext('View')) . '">';
	foreach ($views as $name => $label) {
		$q = http_build_query(array_merge($params, [$key => $name]));
		$attrs = ['href' => $base . '?' . $q];
		if ($name === $current) {
			$attrs['aria-current'] = 'page';
		}
		echo '<a' . fs_attrs($attrs) . '>' . fs_h($label) . '</a>';
	}
	echo '</nav>';
}

/* ------------------------------------------------------------------ data table */

/*
 * Toolbar for a .fs-table card (enhanced by js/freesense-ui.js).
 * Options:
 *   title    string   card title shown at the left
 *   search   string|false  placeholder text; false hides the search box
 *   noun     string   plural noun for the count and no-results message ("schedules")
 *   noun_one string   singular noun for a count of one ("schedule")
 *   filters  [key => [label, value => text, ...]]  first element = "all" label;
 *            matches data-fs-filter-<key> on each <tr>
 *   custom   string   raw HTML (already escaped) placed after the filters
 *   actions  string   raw HTML (already escaped) placed at the right
 *   bulk     [[name, label, icon, variant, confirm, value], ...]  submit
 *            buttons shown while rows are selected; they post the page's
 *            existing form with the existing field names
 */
function fs_table_toolbar(array $opts = []) {
	$noun = $opts['noun'] ?? gettext('items');
	$attrs = [
		'class' => 'fs-toolbar',
		'data-fs-noresults' => sprintf(gettext('No %1$s match “%2$s”.'), $noun, '%s'),
		'data-fs-noresults-filtered' => sprintf(gettext('No %s match the current filter.'), $noun),
		'data-fs-clear-label' => gettext('Clear search'),
		'data-fs-count-format' => gettext('%1$s of %2$s'),
		'data-fs-total-format' => sprintf('%%s %s', $noun),
		'data-fs-total-format-one' => sprintf('%%s %s', $opts['noun_one'] ?? $noun),
		'data-fs-selected-format' => gettext('%s selected'),
	];

	echo '<div' . fs_attrs($attrs) . '><div class="fs-toolbar-default">';

	if (!empty($opts['title'])) {
		echo '<h2 class="fs-toolbar-title">' . fs_h($opts['title']) . '</h2>';
	}

	$search = $opts['search'] ?? gettext('Search…');
	if ($search !== false) {
		echo '<div class="fs-search" role="search">'
		    . '<i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>'
		    . '<input type="search" class="form-control" data-fs-search-input autocomplete="off"'
		    . ' placeholder="' . fs_h($search) . '" aria-label="' . fs_h($search) . '">'
		    . '</div>';
	}

	foreach ($opts['filters'] ?? [] as $key => $choices) {
		$all = array_shift($choices);
		echo '<select class="form-select form-select-sm" data-fs-filter="' . fs_h($key) . '" aria-label="' . fs_h($all) . '">';
		echo '<option value="">' . fs_h($all) . '</option>';
		foreach ($choices as $value => $text) {
			echo '<option value="' . fs_h($value) . '">' . fs_h($text) . '</option>';
		}
		echo '</select>';
	}

	if (!empty($opts['custom'])) {
		echo $opts['custom'];
	}

	echo '<span class="fs-toolbar-spacer"></span>';
	echo '<span class="fs-toolbar-count" data-fs-count aria-live="polite"></span>';

	if (!empty($opts['actions'])) {
		echo $opts['actions'];
	}

	echo '</div>';

	if (!empty($opts['bulk'])) {
		echo '<div class="fs-bulkbar">';
		echo '<span class="fs-bulkbar-count" data-fs-selected-count aria-live="polite"></span>';
		foreach ($opts['bulk'] as $b) {
			$variant = $b['variant'] ?? 'secondary';
			$battrs = [
				'type' => 'submit',
				'name' => $b['name'],
				'value' => $b['value'] ?? $b['label'],
				'class' => 'btn btn-sm ' . (($variant === 'danger') ? 'btn-danger' : 'btn-outline-secondary'),
				'data-fs-confirm' => $b['confirm'] ?? null,
			];
			$icon = !empty($b['icon']) ? '<i class="' . fs_h(fs_icon_class($b['icon'])) . ' icon-embed-btn" aria-hidden="true"></i>' : '';
			echo '<button' . fs_attrs($battrs) . '>' . $icon . fs_h($b['label']) . '</button>';
		}
		echo '<button type="button" class="btn btn-sm btn-link" data-fs-clear-selection>' . fs_h(gettext('Clear selection')) . '</button>';
		echo '</div>';
	}

	echo '</div>';
}

/*
 * Row action icons. Each action is [type, href, object_name, extra = []]:
 *   edit, copy          GET links
 *   toggle              extra['enabled'] => bool; posts (usepost)
 *   delete              posts and confirms; extra['thing'] names the object type
 *                       ("schedule"), extra['detail'] adds a consequence line
 *   custom              extra['icon'], extra['label'], extra['post'] => bool,
 *                       extra['confirm'] => question
 * Any extra['attrs'] are added to the anchor.
 */
function fs_row_actions(array $actions) {
	$html = '<div class="fs-actions">';

	foreach ($actions as $a) {
		$type = $a[0];
		$href = $a[1];
		$name = (string)($a[2] ?? '');
		$extra = $a[3] ?? [];
		$post = false;
		$confirm = null;
		$class = 'fs-action';

		switch ($type) {
			case 'edit':
				$icon = 'fa-solid fa-pencil';
				$label = sprintf(gettext('Edit %s'), $name);
				break;
			case 'copy':
				$icon = 'fa-regular fa-clone';
				$label = sprintf(gettext('Copy %s'), $name);
				break;
			case 'toggle':
				$enabled = !empty($extra['enabled']);
				$icon = $enabled ? 'fa-solid fa-ban' : 'fa-regular fa-square-check';
				$label = sprintf($enabled ? gettext('Disable %s') : gettext('Enable %s'), $name);
				$post = true;
				break;
			case 'delete':
				$icon = 'fa-solid fa-trash-can';
				$label = sprintf(gettext('Delete %s'), $name);
				$post = true;
				$class .= ' fs-action--delete';
				$confirm = empty($extra['thing'])
				    ? sprintf(gettext('Delete “%s”?'), $name)
				    : sprintf(gettext('Delete %1$s “%2$s”?'), $extra['thing'], $name);
				break;
			default:
				$icon = fs_icon_class($extra['icon'] ?? 'fa-circle');
				$label = $extra['label'] ?? $name;
				$post = !empty($extra['post']);
				$confirm = $extra['confirm'] ?? null;
		}

		$attrs = [
			'class' => $class,
			'href' => $href,
			'title' => $label,
			'aria-label' => $label,
			'usepost' => $post ? true : null,
			'data-fs-confirm' => $confirm,
			'data-fs-confirm-detail' => $extra['detail'] ?? null,
			'data-fs-confirm-action' => ($type === 'delete') ? gettext('Delete') : ($extra['confirm_action'] ?? null),
		];
		$attrs = array_merge($attrs, $extra['attrs'] ?? []);
		$html .= '<a' . fs_attrs($attrs) . '><i class="' . fs_h($icon) . '" aria-hidden="true"></i></a>';
	}

	return $html . '</div>';
}

/* ---------------------------------------------------------------------- badges */

function fs_badge($state, $label = null, $title = null) {
	static $map = null;

	if ($map === null) {
		$map = [
			'pass' => ['pass', 'fa-check', gettext('Pass')],
			'up' => ['pass', 'fa-circle-check', gettext('Up')],
			'online' => ['pass', 'fa-circle-check', gettext('Online')],
			'enabled' => ['pass', 'fa-circle-check', gettext('Enabled')],
			'active' => ['pass', 'fa-circle-check', gettext('Active')],
			'block' => ['block', 'fa-xmark', gettext('Block')],
			'down' => ['block', 'fa-circle-xmark', gettext('Down')],
			'offline' => ['block', 'fa-circle-xmark', gettext('Offline')],
			'error' => ['block', 'fa-circle-xmark', gettext('Error')],
			'expired' => ['block', 'fa-circle-xmark', gettext('Expired')],
			'reject' => ['reject', 'fa-hand', gettext('Reject')],
			'warn' => ['warn', 'fa-triangle-exclamation', gettext('Warning')],
			'degraded' => ['warn', 'fa-triangle-exclamation', gettext('Degraded')],
			'pending' => ['warn', 'fa-hourglass-half', gettext('Pending')],
			'info' => ['info', 'fa-circle-info', gettext('Info')],
			'neutral' => ['neutral', 'fa-circle-minus', gettext('Unknown')],
			'disabled' => ['neutral', 'fa-ban', gettext('Disabled')],
			'unknown' => ['neutral', 'fa-circle-question', gettext('Unknown')],
			'idle' => ['neutral', 'fa-circle-minus', gettext('Idle')],
		];
	}

	list($variant, $icon, $default) = $map[$state] ?? $map['neutral'];
	$attrs = ['class' => 'fs-badge fs-badge--' . $variant, 'title' => $title];

	return '<span' . fs_attrs($attrs) . '><i class="fa-solid ' . $icon . '" aria-hidden="true"></i>'
	    . fs_h($label ?? $default) . '</span>';
}

/* --------------------------------------------------------------- form helpers */

/* Cancel link in a Form's sticky action bar (Editor pages return to their list). */
function fs_form_cancel($form, $href, $label = 'Cancel') {
	$btn = new Form_Button('cancel', $label, $href);
	$btn->removeClass('btn-secondary')->addClass('btn-outline-secondary', 'fs-actionbar-cancel');
	$form->addGlobal($btn);

	return $btn;
}

/* ---------------------------------------------------------------- modal forms */

/*
 * Small create / edit forms (up to ~3 fields) open in a modal from a header or
 * row action instead of sitting under the list (docs/webui/PLAN.md, rule R8).
 *
 *   fs_modal_form_begin('be-clone', gettext('Clone boot environment'));
 *   ... fields: <div class="mb-3"><label class="form-label" for="x">…</label><input id="x" name="…" class="form-control">
 *               <div class="form-text">help</div></div>
 *   fs_modal_form_end(gettext('Clone'), 'action', 'clone', 'fa-regular fa-clone');
 *
 * A trigger names the modal and pre-fills fields by name (js/freesense-ui.js):
 *   data-fs-modal="#be-clone" data-fs-fill='{"target":"default"}' data-fs-modal-title="Clone “default”"
 */
function fs_modal_form_begin($id, $title, $action = '', array $hidden = []) {
	echo '<div class="modal fade fs-modal-form" id="' . fs_h($id) . '" tabindex="-1" aria-labelledby="' . fs_h($id) . '-title" aria-hidden="true">'
	    . '<div class="modal-dialog modal-dialog-centered"><div class="modal-content">'
	    . '<form method="post"' . (($action !== '') ? ' action="' . fs_h($action) . '"' : '') . '>'
	    . '<div class="modal-header"><h2 class="modal-title" id="' . fs_h($id) . '-title">' . fs_h($title) . '</h2>'
	    . '<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="' . fs_h(gettext('Close')) . '"></button></div>'
	    . '<div class="modal-body">';
	foreach ($hidden as $name => $value) {
		echo '<input type="hidden" name="' . fs_h($name) . '" value="' . fs_h($value) . '">';
	}
}

function fs_modal_form_end($submit_label, $submit_name = '', $submit_value = '', $icon = null) {
	$icon_html = $icon ? '<i class="' . fs_h(fs_icon_class($icon)) . ' icon-embed-btn" aria-hidden="true"></i>' : '';
	echo '</div><div class="modal-footer">'
	    . '<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">' . fs_h(gettext('Cancel')) . '</button>'
	    . '<button type="submit" class="btn btn-primary"' . (($submit_name !== '') ? ' name="' . fs_h($submit_name) . '" value="' . fs_h($submit_value) . '"' : '')
	    . '>' . $icon_html . fs_h($submit_label) . '</button>'
	    . '</div></form></div></div></div>';
}

/* ----------------------------------------------------------------- empty state */

function fs_empty_row($colspan, $message, $add_href = null, $add_label = null) {
	echo '<tr class="fs-empty"><td colspan="' . (int)$colspan . '">';
	echo '<span class="fs-empty-message">' . fs_h($message) . '</span>';
	if ($add_href !== null && $add_label !== null) {
		echo '<a class="btn btn-sm btn-primary" href="' . fs_h($add_href) . '">'
		    . '<i class="fa-solid fa-plus icon-embed-btn" aria-hidden="true"></i>' . fs_h($add_label) . '</a>';
	}
	echo '</td></tr>';
}

/* ----------------------------------------------------------------------- tiles */

/* One summary tile; place several inside <div class="fs-tiles">. */
function fs_tile($label, $value, $state = null, $hint = null) {
	echo '<div class="fs-tile"><div class="fs-tile-label">' . fs_h($label);
	if ($state !== null) {
		echo ' ' . fs_badge($state);
	}
	echo '</div><div class="fs-tile-value">' . fs_h($value) . '</div>';
	if ($hint !== null) {
		echo '<div class="fs-tile-hint">' . fs_h($hint) . '</div>';
	}
	echo '</div>';
}
