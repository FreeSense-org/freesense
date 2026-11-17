<?php
/*
 * index.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
 * All rights reserved.
 *
 * originally based on m0n0wall (http://m0n0.ch/wall)
 * Copyright (c) 2003-2004 Manuel Kasper <mk@neon1.net>.
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

##|+PRIV
##|*IDENT=page-system-login-logout
##|*NAME=System: Login / Logout / Dashboard
##|*DESCR=Allow access to the 'System: Login / Logout' page and Dashboard.
##|*MATCH=index.php*
##|-PRIV

/*
 * The dashboard: a thin shell. Widgets are JavaScript modules
 * (www/widgets/<type>.js + .widget.json, see dashboard.inc) that read JSON
 * from /api/v1 through the session bridge and draw in the browser
 * (js/fs-dashboard.js). The layout is per user, outside config.xml.
 */

require_once('guiconfig.inc');
require_once('functions.inc');
require_once('notices.inc');
require_once('pkg-utils.inc');
require_once('dashboard.inc');

/* Clearing notices needs the same privilege that shows the button (head.inc). */
if (isset($_POST['closenotice'])) {
	$notice_user = getUserEntry($_SESSION['Username'])['item'] ?? null;
	if (isAdminUID($_SESSION['Username']) || (is_array($notice_user) &&
	    (userHasPrivilege($notice_user, "user-view-clear-notices") || userHasPrivilege($notice_user, "page-all")))) {
		close_notice($_POST['closenotice']);
		sleep(1);
	}
	exit;
}

/* "Add to fleet" hint dismissed: remembered for this user (in the session for users without a local account). */
if (($_POST['fleet_hint'] ?? '') === 'dismiss') {
	$_SESSION['fleet_hint_dismissed'] = true;
	$fleet_user = getUserEntry($_SESSION['Username'] ?? '');
	if (isset($fleet_user['idx'])) {
		config_set_path("system/user/{$fleet_user['idx']}/fleet_hint_dismissed", '');
		write_config(sprintf(gettext('Dashboard: the Add to fleet hint was dismissed by %s.'), $_SESSION['Username']));
	}
	header('Location: /index.php');
	exit;
}

if ((g_get('disablecrashreporter') != true) && (system_has_crash_data() || system_has_php_errors())) {
	$savemsg = sprintf(gettext("%s has detected a crash report or programming bug."), g_get('product_label')) . " ";
	if (isAllowedPage("/crash_reporter.php")) {
		$savemsg .= sprintf(gettext('Click %1$shere%2$s for more information.'), '<a href="crash_reporter.php">', '</a>');
	} else {
		$savemsg .= sprintf(gettext("Contact a firewall administrator for more information."));
	}
	$class = "warning";
}

/* The first visit after installation starts the setup wizard. */
if (file_exists('/conf/trigger_initial_wizard')) {
?>
<!DOCTYPE html>
<html lang="en">
	<head>
		<link rel="stylesheet" href="/css/FreeSense.css" />
		<title><?=g_get('product_label')?>.home.arpa - <?=g_get('product_label')?> first time setup</title>
		<meta http-equiv="refresh" content="1;url=wizard.php?xml=setup_wizard.xml" />
	</head>
	<body id="loading-wizard" class="no-menu">
		<div id="jumbotron">
			<div class="container">
				<div class="col-sm-offset-3 col-sm-6 col-12">
					<font color="white">
					<p><h3><?=sprintf(gettext("Welcome to %s!") . "\n", g_get('product_label'))?></h3></p>
					<p><?=gettext("One moment while the initial setup wizard starts.")?></p>
					<p><?=gettext("Embedded platform users: Please be patient, the wizard takes a little longer to run than the normal GUI.")?></p>
					<p><?=sprintf(gettext("To bypass the wizard, click on the %s logo on the initial page."), g_get('product_label'))?></p>
					</font>
				</div>
			</div>
		</div>
	</body>
</html>
<?php
	exit;
}

$catalogue = dashboard_catalogue();
$interfaces = [];
foreach (get_configured_interface_with_descr() as $ifname => $ifdescr) {
	$interfaces[] = ['name' => $ifname, 'description' => (string)$ifdescr];
}
$dash_config = [
	'catalogue' => $catalogue,
	'defaults' => dashboard_default_layout($catalogue),
	'interfaces' => $interfaces,
	'i18n' => dashboard_i18n($catalogue),
];

$pgtitle = [gettext('Dashboard')];
$fs_page_css = ['/vendor/uplot/uPlot.min.css', '/css/_freesense-dashboard.css'];
$fs_page_scripts = ['/js/fs-core.js', '/vendor/uplot/uPlot.iife.min.js', '/js/fs-chart.js', '/js/fs-dashboard.js'];
foreach (array_keys($catalogue) as $type) {
	$fs_page_scripts[] = "/widgets/{$type}.js";
}
fs_page_action(gettext('Add widget'), '#', 'fa-plus', 'primary',
    ['data-bs-toggle' => 'offcanvas', 'data-bs-target' => '#fs-dash-catalogue', 'role' => 'button', 'aria-controls' => 'fs-dash-catalogue']);
fs_page_action(gettext('Edit layout'), '#', 'fa-table-cells-large', 'secondary', ['data-fs-dash-edit' => '', 'role' => 'button', 'aria-pressed' => 'false']);
fs_page_action(gettext('Live'), '#', 'fa-pause', 'secondary',
    ['data-fs-live-toggle' => '', 'role' => 'button', 'aria-pressed' => 'false', 'class' => 'btn btn-sm btn-outline-secondary fs-live-toggle']);

include('head.inc');

if ($savemsg) {
	print_info_box($savemsg, $class);
}

/* A firewall in no fleet: point once to System > Central Management > Add to fleet. */
$fleet_user = getUserEntry($_SESSION['Username'] ?? '');
if ((config_get_path('fleet/node_id', '') === '') && empty(config_get_path('installedpackages/fleet/config/0/enable')) && isAllowedPage('system_fleet.php') && empty($_SESSION['fleet_hint_dismissed']) &&
    !isset($fleet_user['item']['fleet_hint_dismissed'])) {
	print_callout('<p>' . htmlspecialchars(gettext('A FreeSense Fleet controller can monitor, back up and update this firewall together with others. Paste an invitation from the controller and the guide does the rest.')) .
	    '</p><form method="post" class="d-flex flex-wrap gap-2"><a class="btn btn-sm btn-primary" href="/system_fleet.php"><i class="fa-solid fa-network-wired icon-embed-btn" aria-hidden="true"></i>' .
	    htmlspecialchars(gettext('Add to fleet')) . '</a><button class="btn btn-sm btn-outline-secondary" type="submit" name="fleet_hint" value="dismiss">' .
	    htmlspecialchars(gettext('Not now')) . '</button></form>', 'info', gettext('Not in a fleet'));
}

FreeSense_handle_custom_code("/usr/local/pkg/dashboard/pre_dashboard");
?>

<div class="fs-dash">
	<div class="fs-dash-editbar" role="region" aria-label="<?=gettext('Edit layout')?>">
		<i class="fa-solid fa-table-cells-large" aria-hidden="true"></i>
		<span><?=gettext('Drag a widget by its title to move it. Change its size from its menu.')?></span>
		<button type="button" class="btn btn-sm btn-outline-danger" data-fs-dash-reset><i class="fa-solid fa-arrow-rotate-left icon-embed-btn" aria-hidden="true"></i><?=gettext('Reset to default')?></button>
		<button type="button" class="btn btn-sm btn-primary" data-fs-dash-edit><?=gettext('Done')?></button>
	</div>
	<div id="fs-dash-grid" class="fs-grid" aria-live="off">
		<div class="fs-dash-loading" role="status" aria-label="<?=gettext('Loading the dashboard')?>"><i></i><i></i><i></i><i></i></div>
	</div>
</div>

<?php $dash_config['live'] = (($fs_user_prefs['live'] ?? 'on') !== 'off'); ?>
<script type="application/json" id="fs-dash-config"><?=json_encode($dash_config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES)?></script>

<div class="offcanvas offcanvas-end fs-dash" tabindex="-1" id="fs-dash-catalogue" aria-labelledby="fs-dash-catalogue-title">
	<div class="offcanvas-header">
		<h2 class="offcanvas-title fs-5" id="fs-dash-catalogue-title"><?=gettext('Add widget')?></h2>
		<button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="<?=gettext('Close')?>"></button>
	</div>
	<div class="offcanvas-body">
		<div data-fs-catalogue-list></div>
		<hr>
		<button type="button" class="btn btn-sm btn-outline-danger" data-fs-dash-reset><i class="fa-solid fa-arrow-rotate-left icon-embed-btn" aria-hidden="true"></i><?=gettext('Reset to the default layout')?></button>
	</div>
</div>

<div class="modal fade" id="fs-widget-settings" tabindex="-1" aria-labelledby="fs-widget-settings-title" aria-hidden="true">
	<div class="modal-dialog modal-dialog-centered">
		<form class="modal-content" novalidate>
			<div class="modal-header">
				<h2 class="modal-title fs-5" id="fs-widget-settings-title"></h2>
				<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?=gettext('Close')?>"></button>
			</div>
			<div class="modal-body"></div>
			<div class="modal-footer">
				<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?=gettext('Cancel')?></button>
				<button type="submit" class="btn btn-primary"><?=gettext('Save')?></button>
			</div>
		</form>
	</div>
</div>

<?php
/* The copyright notice after an upgrade (the trigger file is created when /etc/version changes). */
if (file_exists("{$g['cf_conf_path']}/copynotice_display")) {
	require_once("copynotice.inc");
	@unlink("{$g['cf_conf_path']}/copynotice_display");
}

/* HTML a package wants to show after it was installed or removed. */
$ui_notice = "/tmp/package_ui_notice";
if (file_exists($ui_notice)) {
	require_once("{$g['www_path']}/upgrnotice.inc");
}

include('foot.inc');
