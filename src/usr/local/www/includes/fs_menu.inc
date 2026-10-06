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

/* Is the menu item the page being shown? Matches the page or its tab siblings (same stem). */
function fs_menu_item_current($url, $current) {
	$path = fs_menu_path($url);
	if (($path === '') || (substr($path, -4) !== '.php')) {
		return false;
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
		'Status' => ['status_', 'index.php'],
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
			'current' => fs_menu_item_current($url, $current),
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
	    . ($active ? ' aria-current="true"' : '') . '>'
	    . '<i class="fa-solid ' . fs_h($icon) . ' fs-menu-icon" aria-hidden="true"></i>' . $title . '</a>';

	if (!$groups) {
		$html .= '<ul class="dropdown-menu' . $menuclass_dropdown . '" aria-labelledby="' . $id . '-toggle">';
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

	$html .= '<div class="dropdown-menu fs-megamenu' . $menuclass_dropdown . '" aria-labelledby="' . $id . '-toggle">';
	$html .= '<div class="fs-megamenu-head">'
	    . '<span class="fs-megamenu-title">' . $title . '</span>'
	    . '<label class="fs-megamenu-search"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>'
	    . '<input type="search" class="form-control form-control-sm" data-fs-menu-filter autocomplete="off"'
	    . ' placeholder="' . fs_h(sprintf(gettext('Filter %s…'), strip_tags($title))) . '"'
	    . ' aria-label="' . fs_h(sprintf(gettext('Filter %s'), strip_tags($title))) . '"></label>'
	    . '</div>';
	$html .= '<div class="fs-megamenu-grid">';
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
	$html .= '</div>';
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
