<?php
/*
 * dashboard.inc
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
 * The dashboard's widget catalogue (index.php).
 *
 * A widget is two files in /usr/local/www/widgets/, from core or a package:
 *   <type>.widget.json  the manifest: title, description, icon, tone,
 *                       category, page (the GUI page whose privilege the
 *                       widget needs; its data routes check the same one),
 *                       sizes, size, every (seconds), allow_copies, link,
 *                       settings and strings (English text the script shows)
 *   <type>.js           the script: FSDashboard.define('<type>', {...})
 * The layout lives per user outside config.xml (webui_prefs.inc,
 * GET/PUT /api/v1/dashboard/layout); this file owns no config.xml section.
 */

define('DASHBOARD_WIDGET_DIR', '/usr/local/www/widgets');
define('DASHBOARD_SIZES', ['sm', 'md', 'lg', 'xl', 'full']);
define('DASHBOARD_SETTING_TYPES', ['select', 'checkbox', 'number', 'text', 'interface', 'interfaces']);

/*
 * pure: a manifest's JSON → the normalised manifest, or null when it is not
 * a usable widget (bad JSON, a type that does not match the file, no page).
 */
function dashboard_manifest_parse(string $json, string $type): ?array {
	if (!preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $type)) {
		return null;
	}
	$m = json_decode($json, true);
	if (!is_array($m) || (($m['type'] ?? $type) !== $type) || !is_string($m['title'] ?? null) || ($m['title'] === '') ||
	    !is_string($m['page'] ?? null) || !preg_match('/^[A-Za-z0-9_.\/-]+\.php(\?[A-Za-z0-9_.=&-]+)?$/', $m['page'])) {
		return null;
	}
	$sizes = array_values(array_intersect(DASHBOARD_SIZES, (array)($m['sizes'] ?? ['md'])));
	$sizes = empty($sizes) ? ['md'] : $sizes;
	$size = in_array($m['size'] ?? '', $sizes, true) ? $m['size'] : $sizes[0];
	$settings = [];
	foreach ((array)($m['settings'] ?? []) as $s) {
		if (!is_array($s) || !preg_match('/^[a-z][a-z0-9_]{0,31}$/', (string)($s['key'] ?? '')) || ($s['key'] === 'title') || ($s['key'] === 'every') ||
		    !in_array($s['type'] ?? '', DASHBOARD_SETTING_TYPES, true) || !is_string($s['label'] ?? null)) {
			continue;
		}
		$out = ['key' => $s['key'], 'type' => $s['type'], 'label' => $s['label']];
		foreach (['value', 'min', 'max', 'help', 'placeholder'] as $k) {
			if (array_key_exists($k, $s)) {
				$out[$k] = $s[$k];
			}
		}
		if ($s['type'] === 'select') {
			$out['options'] = array_values(array_filter((array)($s['options'] ?? []), function ($o) {
				return is_array($o) && (count($o) === 2) && is_scalar($o[0]) && is_string($o[1]);
			}));
		}
		$settings[] = $out;
	}
	$tone = (string)($m['tone'] ?? '');
	return [
		'type' => $type,
		'title' => $m['title'],
		'description' => (string)($m['description'] ?? ''),
		'icon' => preg_match('/^fa-[a-z0-9-]+$/', (string)($m['icon'] ?? '')) ? $m['icon'] : 'fa-puzzle-piece',
		'tone' => preg_match('/^(series-[1-8]|pass|block|warn|info|neutral)$/', $tone) ? $tone : '',
		'category' => (string)($m['category'] ?? 'Other'),
		'page' => ltrim($m['page'], '/'),
		'sizes' => $sizes,
		'size' => $size,
		'every' => max(0, min(3600, (int)($m['every'] ?? 5))),
		'allow_copies' => !empty($m['allow_copies']),
		'link' => (is_string($m['link'] ?? null) && preg_match('/^\/[A-Za-z0-9_.\/?=&-]*$/', $m['link'])) ? $m['link'] : '',
		'settings' => $settings,
		'strings' => array_values(array_filter((array)($m['strings'] ?? []), 'is_string')),
		'order' => (int)($m['order'] ?? 100),
	];
}

/*
 * pure: the layout a user starts with, from the widget types they may use.
 * It follows the 1.x default (two columns: System Information and Traffic
 * Graphs on the left; Interfaces, Gateways and Services on the right), with
 * the System widget in place of System Information (CPU load included;
 * Resources stays in the catalogue). The grid packs each
 * half like a column (masonry, js/fs-dashboard.js).
 */
function dashboard_default_layout(array $catalogue): array {
	$want = [
		['system', 'lg', []], ['interfaces', 'lg', []],
		['traffic', 'lg', ['interface' => 'wan']], ['gateways', 'lg', []],
		['services', 'lg', []],
	];
	$out = [];
	foreach ($want as $i => list($type, $size, $settings)) {
		if (isset($catalogue[$type])) {
			$out[] = ['id' => "d{$i}", 'type' => $type, 'size' => in_array($size, $catalogue[$type]['sizes'], true) ? $size : $catalogue[$type]['size'],
			    'settings' => (object)$settings, 'collapsed' => false];
		}
	}
	return $out;
}

/* Every widget installed whose page the signed-in user may open, translated, by order then title. */
function dashboard_catalogue(): array {
	$out = [];
	foreach (glob(DASHBOARD_WIDGET_DIR . '/*.widget.json') ?: [] as $file) {
		$type = basename($file, '.widget.json');
		if (!is_file(DASHBOARD_WIDGET_DIR . "/{$type}.js")) {
			continue;
		}
		$m = dashboard_manifest_parse((string)@file_get_contents($file), $type);
		if (($m === null) || !isAllowedPage($m['page'])) {
			continue;
		}
		$m['title'] = gettext($m['title']);
		$m['description'] = ($m['description'] === '') ? '' : gettext($m['description']);
		$m['category'] = gettext($m['category']);
		$out[$type] = $m;
	}
	uasort($out, function ($a, $b) {
		return [$a['order'], $a['title']] <=> [$b['order'], $b['title']];
	});
	return $out;
}

/* The English strings of the runtime and the widgets, translated, for FS.t(). */
function dashboard_i18n(array $catalogue): array {
	$strings = [
		'Small', 'Medium', 'Large', 'Wide', 'Full width', 'Off', 'Every 2 seconds', 'Every 5 seconds', 'Every 10 seconds',
		'Every 30 seconds', 'Every minute', 'Refresh', 'Size', 'Settings', 'Move earlier', 'Move later', 'Expand', 'Collapse',
		'Remove widget', 'Refresh now', 'Widget options', 'Up to date', 'Loading', 'The data is out of date', 'Update failed',
		'The data could not be loaded.', 'Retry', 'The dashboard layout could not be saved.', '%s settings', 'Title', 'Done',
		'Edit layout', 'Paused', 'Live', 'The dashboard is back to the default layout.', '%s on the dashboard', '%s added',
		'%s removed. Add it again from Add widget.', 'Reset the dashboard?', 'Every widget goes back to the default layout and settings.',
		'Reset', 'The dashboard is empty. Use Add widget to place some.', 'Reset to default', 'Other', 'Request failed', 'Request failed (HTTP %s)',
		'The firewall did not answer', 'Your session has ended, so live data stopped updating.', 'Sign in again', 'Close',
		'just now', '%ss ago', '%sm ago', '%sh ago', '%sd ago', 'Chart', 'Charts are not available.', 'Copy', 'Copied', 'Every interface',
	];
	foreach ($catalogue as $m) {
		$strings = array_merge($strings, $m['strings']);
		foreach ($m['settings'] as $s) {
			$strings[] = $s['label'];
			if (isset($s['help'])) {
				$strings[] = (string)$s['help'];
			}
			foreach ($s['options'] ?? [] as $o) {
				$strings[] = $o[1];
			}
		}
	}
	$out = [];
	foreach (array_unique($strings) as $s) {
		$t = gettext($s);
		if ($t !== $s) {
			$out[$s] = $t;
		}
	}
	return $out;
}
