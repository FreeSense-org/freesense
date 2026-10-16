<?php
/*
 * fs_menu.inc
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
 * Top navigation (docs/webui/PLAN.md, Phase N).
 *
 * Large menus (System, Services, Status, Diagnostics) render as a grouped
 * panel with a filter box; the others stay a single column. Items never
 * change their top-level menu.
 *
 * A menu item is the classic [label, url] array with an optional 'group'
 * key. Core items set it in head.inc. Package items take it from the
 * <group> element of their package XML <menu> block; packages that do not
 * declare one are placed by fs_menu_fallback_group(), and anything still
 * unknown lands in "other".
 */

/* group id => [label, icon], in display order, per grouped menu */
function fs_menu_groups() {
	static $groups = null;

	if ($groups === null) {
		$groups = [
			'System' => [
				'general' => [gettext('General'), 'fa-gear'],
				'access' => [gettext('Access'), 'fa-user-shield'],
				'network' => [gettext('Network'), 'fa-network-wired'],
				'other' => [gettext('Other'), 'fa-ellipsis'],
			],
			'Services' => [
				'network' => [gettext('Network'), 'fa-network-wired'],
				'routing' => [gettext('Routing'), 'fa-route'],
				'security' => [gettext('Security'), 'fa-shield-halved'],
				'proxy' => [gettext('Proxy'), 'fa-right-left'],
				'monitoring' => [gettext('Monitoring'), 'fa-chart-line'],
				'other' => [gettext('Other'), 'fa-ellipsis'],
			],
			'Status' => [
				'overview' => [gettext('Overview'), 'fa-gauge-high'],
				'network' => [gettext('Network'), 'fa-network-wired'],
				'vpn' => [gettext('VPN'), 'fa-lock'],
				'security' => [gettext('Security'), 'fa-shield-halved'],
				'traffic' => [gettext('Traffic'), 'fa-chart-line'],
				'other' => [gettext('Other'), 'fa-ellipsis'],
			],
			'Diagnostics' => [
				'tools' => [gettext('Network Tools'), 'fa-screwdriver-wrench'],
				'tables' => [gettext('Tables & State'), 'fa-table-list'],
				'system' => [gettext('System'), 'fa-server'],
				'power' => [gettext('Power & Reset'), 'fa-power-off'],
				'other' => [gettext('Other'), 'fa-ellipsis'],
			],
		];
	}

	return $groups;
}

/*
 * Group for a package menu entry that does not declare <group>, matched on
 * the entry name. Kept until every package XML carries its own <group>.
 */
function fs_menu_fallback_group($menu, $label) {
	static $rules = [
		'Services' => [
			'/^(bind|avahi|mdns|tftp)|dns server|mdns bridge|tftp server/i' => 'network',
			'/^frr|igmp|multicast bridge|udp broadcast relay/i' => 'routing',
			'/captive|freeradius|crowdsec|suricata|snort|threat shield|acme|pfblocker/i' => 'security',
			'/haproxy|web gateway|squid|proxy/i' => 'proxy',
			'/snmp|prometheus|telegraf|zabbix|softflowd|syslog|andwatch|lldp|ups|netflow|ntopng/i' => 'monitoring',
		],
		'Status' => [
			'/tailscale|zerotier|wireguard|openvpn|ipsec/i' => 'vpn',
			'/crowdsec|threat shield|web gateway|suricata|captive/i' => 'security',
			'/traffic|haproxy|queues|bandwidth|monitoring/i' => 'traffic',
			'/frr|andwatch|dhcp|ntp|carp/i' => 'network',
		],
		'Diagnostics' => [
			'/network tools|ntopng|iperf|nmap|arping/i' => 'tools',
		],
	];

	foreach ($rules[$menu] ?? [] as $pattern => $group) {
		if (preg_match($pattern, strip_tags(html_entity_decode((string)$label)))) {
			return $group;
		}
	}

	return 'other';
}

/* Path part of a menu URL without leading slash ("/a.php?x=1" -> "a.php"). */
function fs_menu_path($url) {
	return ltrim((string)parse_url((string)$url, PHP_URL_PATH), '/');
}

/*
 * Is the menu item the page being shown? Matches the page or its tab siblings (same stem).
 * An item with a query ("/interfaces.php?if=wan") only matches that page with the same
 * parameters, so WAN and LAN are not both current, nor current on interfaces_assign.php.
 */
function fs_menu_item_current($url, $current) {
	$path = fs_menu_path($url);
	if (($path === '') || (substr($path, -4) !== '.php')) {
		return false;
	}
	$query = (string)parse_url((string)$url, PHP_URL_QUERY);
	if ($query !== '') {
		if ($path !== $current) {
			return false;
		}
		parse_str($query, $params);
		foreach ($params as $key => $value) {
			if (!isset($_GET[$key]) || ((string)$_GET[$key] !== (string)$value)) {
				return false;
			}
		}
		return true;
	}
	if ($path === $current) {
		return true;
	}
	$stem = substr($path, 0, -4);
	return (strpos($current, $stem . '_') === 0);
}

/* Top-level menu a core page belongs to by its file name (highlight when no item matches). */
function fs_menu_owns_page($name, $current) {
	static $prefixes = [
		'System' => ['system_', 'pkg_mgr', 'wizard.php'],
		'Interfaces' => ['interfaces'],
		'Firewall' => ['firewall_'],
		'Services' => ['services_'],
		'VPN' => ['vpn_'],
		'Status' => ['status_'],
		'Diagnostics' => ['diag_'],
	];
	foreach ($prefixes[$name] ?? [] as $p) {
		if (strpos($current, $p) === 0) {
			return true;
		}
	}
	return false;
}

/* Visible items of a menu, each with its label, url, target, current flag and group. */
function fs_menu_visible_items($name, $items, $target, $current) {
	$out = [];
	foreach ($items as $item) {
		if (($item[0] ?? '') === '-DIVIDER-') {
			$out[] = ['-DIVIDER-'];
			continue;
		}
		$url = (string)($item[1] ?? '');
		/* If the user has access to help pages, also show the full help menu. See #5909 */
		if (!(isAllowedPage($url) || ($url == "/index.php?logout") ||
		    (($name == "Help") && isAllowedPage("help.php")) ||
		    (substr($url, 0, 8) == "https://"))) {
			continue;
		}
		$out[] = [
			'label' => $item[0],
			'url' => $url,
			'target' => $target,
			'post' => ($url == "/index.php?logout"),
			'class' => $item['class'] ?? '',
			'current' => ($url != "/index.php?logout") && fs_menu_item_current($url, $current),
			'group' => $item['group'] ?? null,
		];
	}
	return $out;
}

function fs_menu_link($it, $extra_class = '') {
	$attrs = [
		'href' => $it['url'],
		'class' => trim('dropdown-item navlnk ' . $extra_class . ' ' . $it['class'] . ($it['current'] ? ' active' : '')),
		'target' => $it['target'] ?: null,
		'rel' => $it['target'] ? 'noopener' : null,
		'usepost' => $it['post'] ? true : null,
		'aria-current' => $it['current'] ? 'page' : null,
	];
	/* labels are emitted as given (callers pass gettext strings or escaped values) */
	return '<a' . fs_attrs($attrs) . '>' . $it['label'] . '</a>';
}

/*
 * One top-level menu as a navbar <li>. Returns '' when the user can see none
 * of its items. $current is the page file name (e.g. "services_unbound.php").
 */
function fs_render_nav_menu($name, $title, $icon, $items, $target, $current, $menuclass_dropdown = '') {
	$visible = fs_menu_visible_items($name, $items, $target, $current);
	$links = array_filter($visible, function ($it) { return !isset($it[0]); });
	if (empty($links)) {
		return '';
	}

	$groups = fs_menu_groups()[$name] ?? null;
	$active = (bool)array_filter($links, function ($it) { return $it['current']; }) ||
	    fs_menu_owns_page($name, $current);
	$id = 'fs-menu-' . strtolower(preg_replace('/[^A-Za-z0-9]+/', '-', $name));

	$html = '<li class="nav-item dropdown' . ($groups ? ' fs-mega' : '') . '">';
	$html .= '<a href="#" class="nav-link dropdown-toggle' . ($active ? ' active' : '') . '" id="' . $id . '-toggle"'
	    . ' data-bs-toggle="dropdown" data-bs-auto-close="outside" role="button" aria-expanded="false"'
	    . ($active ? ' aria-current="true"' : '') . ' title="' . fs_h(strip_tags($title)) . '">'
	    . '<i class="fa-solid ' . fs_h($icon) . ' fs-menu-icon" aria-hidden="true"></i><span class="fs-menu-label">' . $title . '</span></a>';

	if (!$groups) {
		$html .= '<ul class="dropdown-menu' . $menuclass_dropdown . '" aria-labelledby="' . $id . '-toggle" data-fs-title="' . fs_h(strip_tags($title)) . '">';
		foreach ($visible as $it) {
			$html .= isset($it[0]) ? '<li><hr class="dropdown-divider"></li>' : '<li>' . fs_menu_link($it) . '</li>';
		}
		return $html . '</ul></li>';
	}

	/* grouped panel */
	$by_group = array_fill_keys(array_keys($groups), []);
	$trailing = [];
	foreach ($links as $it) {
		if ($it['post']) {          // Logout stays last, outside the groups
			$trailing[] = $it;
			continue;
		}
		$g = $it['group'];
		if (($g === null) || !isset($groups[$g])) {
			$g = fs_menu_fallback_group($name, $it['label']);
		}
		$by_group[isset($groups[$g]) ? $g : 'other'][] = $it;
	}

	$html .= '<div class="dropdown-menu fs-megamenu' . $menuclass_dropdown . '" aria-labelledby="' . $id . '-toggle" data-fs-title="' . fs_h(strip_tags($title)) . '">';
	$html .= '<div class="fs-megamenu-head">'
	    . '<span class="fs-megamenu-title">' . $title . '</span>'
	    . '<label class="fs-megamenu-search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>'
	    . '<input type="search" class="form-control form-control-sm" data-fs-menu-filter autocomplete="off"'
	    . ' placeholder="' . fs_h(sprintf(gettext('Filter %s…'), strip_tags($title))) . '"'
	    . ' aria-label="' . fs_h(sprintf(gettext('Filter %s'), strip_tags($title))) . '"></label>'
	    . '</div>';
	$html .= '<div class="fs-megamenu-grid"><div class="fs-megamenu-columns">';
	foreach ($by_group as $g => $group_items) {
		if (empty($group_items)) {
			continue;
		}
		usort($group_items, function ($a, $b) {
			return strnatcasecmp(strip_tags($a['label']), strip_tags($b['label']));
		});
		$gid = $id . '-' . $g;
		$html .= '<section class="fs-megamenu-group" aria-labelledby="' . $gid . '">';
		$html .= '<h3 class="fs-megamenu-group-title" id="' . $gid . '">'
		    . '<i class="fa-solid ' . fs_h($groups[$g][1]) . '" aria-hidden="true"></i>' . fs_h($groups[$g][0]) . '</h3>';
		$html .= '<ul>';
		foreach ($group_items as $it) {
			$html .= '<li>' . fs_menu_link($it) . '</li>';
		}
		$html .= '</ul></section>';
	}
	$html .= '</div></div>';
	$html .= '<p class="fs-megamenu-empty" hidden>' . fs_h(gettext('No matching items.')) . '</p>';
	if (!empty($trailing)) {
		$html .= '<div class="fs-megamenu-foot">';
		foreach ($trailing as $it) {
			$html .= fs_menu_link($it);
		}
		$html .= '</div>';
	}

	return $html . '</div></li>';
}

/*
 * Top-bar items from packages (plugin_navbar). A package's
 * <pkgname>_plugin_navbar() returns one item, or null for none:
 *   id     a-z, 0-9 and dashes (the element is fs-nav-<package>-<id>)
 *   icon   a Font Awesome solid icon name, e.g. fa-server
 *   label  what the item is, for screen readers and the tooltip
 *   href   a page of this WebUI (starts with /); the item is shown only to
 *          users allowed to open it
 *   badge  a count, 0 hides the badge; kind info, warn, block or neutral
 *   busy   true spins the icon (something is running)
 *   hidden true keeps the item out of sight until a poll shows it
 *   poll   a page of this WebUI answering the same fields as JSON;
 *          freesense-ui.js asks it every 15 s (60 s in a hidden tab) with
 *          ?since=<cursor>. The answer may also carry
 *            cursor  a number the next poll sends back as since
 *            toasts  up to 5 notices: { text, kind, href, sticky }
 *          shown at the bottom right of any page (see fs_nav_toasts()).
 * Anything else, or a wrong value, is dropped: a package cannot put
 * markup or another site's address into the top bar.
 */

/* pure: a package's top-bar item made safe, or null. */
function fs_nav_plugin_item(string $pkg, $raw): ?array {
	if (!is_array($raw) || !preg_match('/^[a-z0-9_-]{1,40}$/D', strtolower($pkg))) {
		return null;
	}
	$id = (string)($raw['id'] ?? '');
	$icon = (string)($raw['icon'] ?? '');
	$label = trim((string)($raw['label'] ?? ''));
	$href = (string)($raw['href'] ?? '');
	$local = function (string $url): bool {
		return (bool)preg_match('#^/(?!/)[A-Za-z0-9_./?=&%-]*$#D', $url);
	};
	if (!preg_match('/^[a-z0-9-]{1,32}$/D', $id) || !preg_match('/^fa-[a-z0-9-]{1,40}$/D', $icon) ||
	    ($label === '') || (strlen($label) > 80) || preg_match('/[\x00-\x1f\x7f]/', $label) || !$local($href)) {
		return null;
	}
	$poll = (string)($raw['poll'] ?? '');
	return array(
		'id' => strtolower($pkg) . '-' . $id,
		'icon' => $icon,
		'label' => $label,
		'href' => $href,
		'badge' => max(0, min(9999, (int)($raw['badge'] ?? 0))),
		'kind' => in_array($raw['kind'] ?? '', array('info', 'pass', 'warn', 'block', 'neutral'), true) ? $raw['kind'] : 'info',
		'busy' => !empty($raw['busy']),
		'hidden' => !empty($raw['hidden']),
		'poll' => ($poll !== '' && $local($poll)) ? $poll : '',
		/* optional: a page of this WebUI whose HTML fills a dropdown under the item, fetched when it opens */
		'panel' => (($raw['panel'] ?? '') !== '' && $local((string)$raw['panel'])) ? (string)$raw['panel'] : '',
	);
}

/* pure: the <li> for an item made by fs_nav_plugin_item(). */
function fs_nav_plugin_html(array $item): string {
	$e = function ($v) {
		return htmlspecialchars((string)$v, ENT_QUOTES);
	};
	$panel = (string)($item['panel'] ?? '');
	return '<li class="nav-item' . (($panel !== '') ? ' dropdown' : '') . '" id="fs-nav-' . $e($item['id']) . '"' . ($item['hidden'] ? ' hidden' : '') . '>' .
	    '<a class="nav-link fs-nav-plugin" href="' . $e($item['href']) . '" title="' . $e($item['label']) . '" aria-label="' . $e($item['label']) . '"' .
	    ($item['poll'] !== '' ? ' data-fs-nav-poll="' . $e($item['poll']) . '"' : '') . ' data-busy="' . ($item['busy'] ? 'true' : 'false') . '" data-kind="' . $e($item['kind']) . '"' .
	    (($panel !== '') ? ' data-fs-nav-panel="' . $e($panel) . '" data-bs-toggle="dropdown" data-bs-auto-close="outside" role="button" aria-expanded="false"' : '') . '>' .
	    '<i class="fa-solid ' . $e($item['icon']) . '" aria-hidden="true"></i>' .
	    '<span class="badge fs-nav-badge fs-nav-badge--' . $e($item['kind']) . '"' . ($item['badge'] > 0 ? '' : ' hidden') . '>' . (int)$item['badge'] . '</span>' .
	    '</a>' . (($panel !== '') ? '<div class="dropdown-menu dropdown-menu-end fs-navpanel"><div class="fs-navpanel-body"><p class="fs-navpanel-wait">' .
	    $e(gettext('Loading...')) . '</p></div></div>' : '') . '</li>';
}

/* The top-bar items of the installed packages that this user may open. */
function fs_nav_plugin_items(): array {
	$items = array();
	try {
		foreach (pkg_call_plugins('plugin_navbar', array('event' => 'items')) as $pkg => $raw) {
			$item = fs_nav_plugin_item((string)$pkg, $raw);
			if (($item !== null) && isAllowedPage(ltrim(parse_url($item['href'], PHP_URL_PATH), '/'))) {
				$items[] = $item;
			}
		}
	} catch (Throwable $e) {
		/* A broken package never breaks the top bar of every page. */
		log_error('plugin_navbar: ' . $e->getMessage());
	}
	return $items;
}

/*
 * pure: the notices in a poll answer, made safe the same way as the item:
 * plain text up to 200 characters, a known kind, a local link, at most 5.
 * freesense-ui.js applies the same rules; this is the reference and the test.
 */
function fs_nav_toasts($raw): array {
	$out = array();
	foreach (is_array($raw) ? array_slice($raw, 0, 5) : array() as $t) {
		$text = is_array($t) ? trim((string)($t['text'] ?? '')) : '';
		if (($text === '') || (strlen($text) > 200) || preg_match('/[\x00-\x1f\x7f]/', $text)) {
			continue;
		}
		$href = (string)($t['href'] ?? '');
		$out[] = array('text' => $text, 'kind' => in_array($t['kind'] ?? '', array('info', 'pass', 'warn', 'block'), true) ? $t['kind'] : 'info',
		    'href' => preg_match('#^/(?!/)[A-Za-z0-9_./?=&%-]*$#D', $href) ? $href : '', 'sticky' => !empty($t['sticky']));
	}
	return $out;
}


/*
 * pure: one notice for the notifications panel: the text decoded, a short
 * title, a detail line, a tone and icon, and for a PHP error a GitHub issue
 * link with the error filled in (the person reviews it before submitting).
 */
function fs_notice_view($time, array $n, $version = '') {
	$text = htmlspecialchars_decode(htmlspecialchars_decode((string)($n['notice'] ?? '')));
	$category = htmlspecialchars_decode((string)($n['category'] ?? ''));
	$prio = (int)($n['priority'] ?? 1);
	$php = (stripos($category, 'php') !== false) || (strpos($text, 'PHP ERROR') === 0);
	$title = trim(strtok($text, "\n"));
	$where = '';
	if ($php && preg_match('/Message:\s*(.+?)(?:\s+in\s+(\/\S+?:\d+))?\s*(?:\n|Stack trace|$)/s', $text, $m)) {
		$title = trim(preg_replace('/^Uncaught\s+/', '', $m[1]));
		$where = $m[2] ?? '';
		if (($where === '') && preg_match('/File:\s*(\S+?),\s*Line:\s*(\d+)/', $text, $f)) {
			$where = $f[1] . ':' . $f[2];
		}
	}
	if (mb_strlen($title) > 140) {
		$title = mb_substr($title, 0, 137) . '...';
	}
	$v = array(
		'time' => (int)$time,
		'title' => $title,
		'where' => $where,
		'category' => $category,
		'text' => $text,
		'url' => htmlspecialchars_decode((string)($n['url'] ?? '')),
		'tone' => $php ? 'block' : (($prio >= 2) ? 'block' : (($prio === 1) ? 'warn' : 'info')),
		'icon' => $php ? 'fa-bug' : (($prio >= 2) ? 'fa-circle-exclamation' : (($prio === 1) ? 'fa-triangle-exclamation' : 'fa-circle-info')),
		'report' => '',
	);
	if ($php) {
		$body = "**FreeSense version:** " . (($version !== '') ? $version : 'unknown') . "\n" .
		    "**Where:** " . (($where !== '') ? '`' . $where . '`' : 'unknown') . "\n" .
		    "**When:** " . date('Y-m-d H:i:s T', (int)$time) . "\n\n" .
		    "**What I was doing:**\n\n\n**The error:**\n```\n" . mb_substr($text, 0, 5000) . "\n```\n";
		$v['report'] = 'https://github.com/FreeSense-org/freesense/issues/new?' . http_build_query(array(
		    'title' => 'PHP error: ' . mb_substr($title, 0, 120), 'body' => $body, 'labels' => 'bug'));
	}
	return $v;
}

/*
 * The notifications panel under the bell: newest first, each notice with
 * its icon, title, time and the full text behind Details; a PHP error can
 * be reported on GitHub. $notices is get_notices() (time => notice).
 */
function fs_notices_panel($notices, $can_clear) {
	$version = trim((string)@file_get_contents('/etc/version'));
	$items = is_array($notices) ? $notices : array();
	krsort($items);
	$h = '<div class="dropdown-menu dropdown-menu-end fs-notif" aria-labelledby="fs-notif-toggle" data-empty="' . fs_h(gettext('No notifications. Everything is quiet.')) . '">';
	$h .= '<div class="fs-notif-head"><strong>' . fs_h(gettext('Notifications')) . '</strong>';
	if (!empty($items) && $can_clear) {
		$h .= '<button type="button" class="btn btn-sm btn-link fs-notif-clear" data-fs-notice-close="all">' . fs_h(gettext('Mark all as read')) . '</button>';
	}
	$h .= '</div>';
	if (empty($items)) {
		return $h . '<div class="fs-notif-empty"><i class="fa-solid fa-circle-check" aria-hidden="true"></i><span>' .
		    fs_h(gettext('No notifications. Everything is quiet.')) . '</span></div></div>';
	}
	$h .= '<ul class="fs-notif-list">';
	foreach ($items as $time => $n) {
		$v = fs_notice_view($time, $n, $version);
		$h .= '<li class="fs-notif-item" data-time="' . $v['time'] . '">';
		$h .= '<span class="fs-notif-icon fs-tone-' . $v['tone'] . '"><i class="fa-solid ' . $v['icon'] . '" aria-hidden="true"></i></span><div class="fs-notif-body">';
		$h .= '<div class="fs-notif-title">' . (($v['url'] !== '') ? '<a href="' . fs_h($v['url']) . '">' . fs_h($v['title']) . '</a>' : fs_h($v['title'])) . '</div>';
		$h .= '<div class="fs-notif-meta">' . fs_h($v['category']) . ', <time datetime="' . date('c', $v['time']) . '" title="' . date('Y-m-d H:i:s', $v['time']) . '">' .
		    fs_h(date((date('Y-m-d', $v['time']) === date('Y-m-d')) ? 'H:i' : 'M j, H:i', $v['time'])) . '</time>' .
		    (($v['where'] !== '') ? '<span class="fs-notif-where">' . fs_h($v['where']) . '</span>' : '') . '</div>';
		if ($v['text'] !== $v['title']) {
			$h .= '<details class="fs-notif-details"><summary>' . fs_h(gettext('Details')) . '</summary><pre>' . fs_h($v['text']) . '</pre></details>';
		}
		$h .= '<div class="fs-notif-actions">';
		if ($v['report'] !== '') {
			$h .= '<a class="btn btn-sm btn-outline-secondary" href="' . fs_h($v['report']) . '" target="_blank" rel="noopener"><i class="fa-brands fa-github" aria-hidden="true"></i>' .
			    fs_h(gettext('Report on GitHub')) . '</a>';
		}
		if ($can_clear) {
			$h .= '<button type="button" class="btn btn-sm btn-link" data-fs-notice-close="' . $v['time'] . '">' . fs_h(gettext('Dismiss')) . '</button>';
		}
		$h .= '</div></div></li>';
	}
	return $h . '</ul></div>';
}

