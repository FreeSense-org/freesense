<?php
/*
 * Standalone CI regression test for top-bar items from packages
 * (plugin_navbar in src/usr/local/www/includes/fs_menu.inc);
 * run with `php tests/NavPluginSmokeTest.php`.
 */

$root = dirname(__DIR__);
$src = file_get_contents("{$root}/src/usr/local/www/includes/fs_menu.inc");
require_once("{$root}/src/etc/inc/validate.inc");

if (!function_exists('gettext')) { function gettext($s) { return $s; } }
function check($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

function fn_source($code, $name) {
	$start = strpos($code, "function {$name}(");
	check($start !== false, "function {$name}() exists");
	return substr($code, $start, strpos($code, "\n}\n", $start) - $start) . "\n}\n";
}

eval(fn_source($src, 'fs_nav_plugin_item'));
eval(fn_source($src, 'fs_nav_plugin_html'));
eval(fn_source($src, 'fs_nav_toasts'));

$ok = array('id' => 'activity', 'icon' => 'fa-server', 'label' => 'Fleet: 2 actions running', 'href' => '/fleet/fleet_activity.php',
    'badge' => 2, 'kind' => 'info', 'busy' => true, 'poll' => '/fleet/fleet_nav.php');
$item = fs_nav_plugin_item('Fleet', $ok);
check($item !== null && $item['id'] === 'fleet-activity' && $item['badge'] === 2 && $item['busy'] && $item['poll'] === '/fleet/fleet_nav.php', 'a good item is kept');
check(fs_nav_plugin_item('fleet', array('href' => 'https://evil.example/') + $ok) === null &&
    fs_nav_plugin_item('fleet', array('href' => '//evil.example/x') + $ok) === null &&
    fs_nav_plugin_item('fleet', array('href' => 'javascript:alert(1)') + $ok) === null, 'only pages of this WebUI');
check(fs_nav_plugin_item('fleet', array('icon' => 'fa-x" onmouseover="x') + $ok) === null && fs_nav_plugin_item('fleet', array('id' => 'A B') + $ok) === null &&
    fs_nav_plugin_item('fleet', array('label' => '') + $ok) === null && fs_nav_plugin_item('fleet', 'nope') === null && fs_nav_plugin_item('bad name', $ok) === null,
    'wrong icons, ids, labels and packages are dropped');
check(fs_nav_plugin_item('fleet', array('poll' => 'http://x/') + $ok)['poll'] === '' && fs_nav_plugin_item('fleet', array('kind' => 'party') + $ok)['kind'] === 'info' &&
    fs_nav_plugin_item('fleet', array('badge' => -5) + $ok)['badge'] === 0, 'a foreign poll address, unknown kind and negative count are made safe');
check((fs_nav_plugin_item('fleet', array('panel' => '/fleet/fleet_nav.php?panel=1') + $ok)['panel'] === '/fleet/fleet_nav.php?panel=1') &&
    (fs_nav_plugin_item('fleet', array('panel' => 'https://evil.example/') + $ok)['panel'] === '') && (fs_nav_plugin_item('fleet', array('kind' => 'pass') + $ok)['kind'] === 'pass'),
    'a panel only from this WebUI; pass is a kind');
$phtml = fs_nav_plugin_html(fs_nav_plugin_item('fleet', array('panel' => '/fleet/fleet_nav.php?panel=1') + $ok));
check((strpos($phtml, 'data-fs-nav-panel="/fleet/fleet_nav.php?panel=1"') !== false) && (strpos($phtml, 'class="dropdown-menu dropdown-menu-end fs-navpanel"') !== false) &&
    (strpos(fs_nav_plugin_html(fs_nav_plugin_item('fleet', $ok)), 'dropdown') === false), 'an item with a panel opens a dropdown; one without stays a link');
$html = fs_nav_plugin_html(fs_nav_plugin_item('fleet', array('label' => '<b>"x"</b>', 'badge' => 0, 'hidden' => true) + $ok));
check(strpos($html, '<b>') === false && strpos($html, '&lt;b&gt;') !== false && strpos($html, ' hidden>') !== false &&
    preg_match('/fs-nav-badge--info" hidden>0</', $html) === 1, 'the label is escaped, a zero badge and a hidden item stay hidden');

$toasts = fs_nav_toasts(array(array('text' => 'Reboot Lab: done', 'kind' => 'pass', 'href' => '/fleet/fleet_activity.php?op=9'),
    array('text' => '', 'kind' => 'pass'), array('text' => "bad\x07", 'kind' => 'pass'), array('text' => 'x', 'kind' => 'evil', 'href' => 'https://evil.example/', 'sticky' => 1), 'junk'));
check(count($toasts) === 2 && $toasts[0]['href'] === '/fleet/fleet_activity.php?op=9' && $toasts[1] === array('text' => 'x', 'kind' => 'info', 'href' => '', 'sticky' => true),
    'notices: plain text only, known kinds, local links');
check(count(fs_nav_toasts(array_fill(0, 9, array('text' => 'a')))) === 5 && fs_nav_toasts('nope') === array(), 'at most five notices per poll');
$js = file_get_contents("{$root}/src/usr/local/www/js/freesense-ui.js");
$toast_js = substr($js, strpos($js, 'function toast(t)'), 2000);
check(strpos($toast_js, 'body.textContent = text') !== false && strpos($toast_js, 'innerHTML') === false, 'the script puts notice text in as text, never as markup');

echo "NavPluginSmokeTest: OK\n";
