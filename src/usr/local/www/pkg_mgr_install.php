<?php
/*
 * pkg_mgr_install.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
 * Copyright (c) 2005 Colin Smith
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
##|*IDENT=page-system-packagemanager-installpackage
##|*NAME=System: Package Manager: Install Package
##|*DESCR=Allow access to the 'System: Package Manager: Install Package' page.
##|*MATCH=pkg_mgr_install.php*
##|-PRIV

ini_set('max_execution_time', '0');

require_once("guiconfig.inc");
require_once("functions.inc");
require_once("filter.inc");
require_once("shaper.inc");
require_once("pkg-utils.inc");
require_once("package_catalog.inc");
require_once("pkg_mgr_install.inc");

$failmsg = "";
$sendto = "output";
$start_polling = false;
$firmwareupdate = false;
$guitimeout = 90;	// Seconds to wait before reloading the page after reboot
$guiretry = 20;		// Seconds to try again if $guitimeout was not long enough
//---------------------------------------------------------------------------------------------------------------------
// After an installation or removal has been started (mwexec(/usr/local/sbin/FreeSense-upgrade-GUI.sh . . . )) AJAX calls
// are made to get status.
// The log file is read and the newest progress record retrieved. The data is formatted
// as JSON before being returned to the AJAX caller (at the bottom of this file)
//
// Arguments received here:
//		logfilename = Passed to installation script to tell it how to name the log file we will parse
//		next_log_line = Send log file entries that come after this line number
//
// JSON items returned
//		log:
//		exitcode:
//		data:{current:, total}
//		notice:
//
// Todo:
//		Respect next_log_line and append log to output window rather than writing it

$install_paths = pkg_mgr_install_paths();
$gui_pidfile = $install_paths['gui_pidfile'];
$gui_mode = $install_paths['gui_mode'];
$sock_file = $install_paths['sock_file'];
$repos = pkg_list_repos();

if (!empty($_POST['fwbranch']) &&
    !in_array($_POST['fwbranch'], array_column($repos, 'name'), true)) {
	$input_errors[] = gettext('That firmware branch would downgrade the running system and cannot be selected.');
}

$pkgname = '';

/* The package name is also used to build log file paths; accept package-name
 * characters only so it cannot point outside the log directory. */
if (!empty($_REQUEST['pkg']) && pkg_mgr_install_name_ok($_REQUEST['pkg'])) {
	$pkgname = $_REQUEST['pkg'];
}

$pkgname_vital = is_vital_system_default_package($pkgname);
$pkgname_vital_message = gettext("This package is vital to system operation and cannot be removed.");

if ($_REQUEST['ajax']) {
	$response = "";
	$code = 0;
	$postlog = "";

	if (isset($_REQUEST['logfilename'])) {
		$postlog = pkg_mgr_install_postlog($_REQUEST['logfilename'], $pkgname);
	}

	// If this is an ajax call to get the installed and newest versions, call that function,
	// JSON encode the result, print it and exit
	if ($_REQUEST['getversion']) {
		$firmwareversions = get_system_pkg_version(false);
		$channel = pkg_get_repo_name(config_get_path('system/pkg_repo_conf_path'));
		if (preg_match('/^[A-Za-z0-9_.-]+$/D', $channel)) {
			// Release notes travel with the canonical release document used by
			// the website and download publisher. This prevents the appliance
			// and website from showing different changelogs for the same build.
			$notes_url = 'https://pkg.freesense.org/v1/releases/' . rawurlencode($channel) . '.json';
			$notes_raw = shell_exec('/usr/bin/fetch -qo - -T 5 ' . escapeshellarg($notes_url) . ' 2>/dev/null');
			$notes = json_decode($notes_raw, true);
			if (is_array($notes)) {
				$firmwareversions['release_notes'] = $notes;
			}
		}
		print(json_encode($firmwareversions));
		exit;
	}

	// Check to see if our process is still running
	$running = "running";

	if (!pkg_mgr_install_running($gui_pidfile)) {
		$running = "stopped";
		pkg_mgr_install_finish($postlog);
	}

	$pidarray = array('pid' => $running);

	// Process log file -----------------------------------------------------------------------------------------------
	$readlog = pkg_mgr_install_read_log($postlog);

	if ($readlog !== null) {
		$resparray = array();
		$resparray['log'] = $readlog['log'];
		$statusarray = $readlog['status'];
		$notice = array('notice' => "");
	} else {
		$resparray['log'] = "not_ready";
		print(json_encode($resparray));
		exit;
	}

	// Process progress file ------------------------------------------------------------------------------------------
	$progarray = pkg_mgr_install_read_progress($postlog);

	//
	$notice['notice'] = pkg_mgr_install_ui_notice();

	// Glob all the arrays we have made together, and convert to JSON
	print(json_encode($resparray + $pidarray + $statusarray + $progarray + $notice));

	exit;
}

$pkgmode = '';

if (!empty($_REQUEST['mode'])) {
	$valid_modes = array(
		'reinstallall',
		'reinstallpkg',
		'delete',
		'installed'
	);

	if (!in_array($_REQUEST['mode'], $valid_modes)) {
		header("Location: pkg_mgr_installed.php");
		return;
	}

	$pkgmode = $_REQUEST['mode'];
}

// After a successful installation/removal/update the page is reloaded so that any menu changes show up
// immediately. These values passed as POST arguments tell the page the state it was in before the reload.
$confirmed = isset($_POST['confirmed']) && $_POST['confirmed'] == 'true';
if ($input_errors) {
	$confirmed = false;
}
$completed = isset($_POST['completed']) && $_POST['completed'] == 'true';
$reboot_needed = isset($_POST['reboot_needed']) && $_POST['reboot_needed'] == "yes";

$postlog = "";

if (isvalidpid($gui_pidfile) && file_exists($sock_file)) {
	$progbar = true;
	$mode = "firmwareupdate";
	if (file_exists($gui_mode)) {
		list($mode, $mode_pkgname) = pkg_mgr_install_read_mode($gui_mode);
		if (isset($mode_pkgname)) {
			$pkgname = $mode_pkgname;
		}
	}
	switch ($mode) {
	case 'firmwareupdate':
		$logfilename = pkg_mgr_install_logfile(true, $pkgname);
		$postlog = "UPGR";
		break;
	case 'reinstallall':
		$progbar = false;
	default:
		$logfilename = pkg_mgr_install_logfile(false, $pkgname);
		$postlog = "PKG";
	}

	$start_polling = true;
	$confirmed = true;
}

if (!empty($_REQUEST['id'])) {
	if ($_REQUEST['id'] != "firmware") {
		header("Location: pkg_mgr_installed.php");
		return;
	}

	$firmwareupdate = true;

	// If the user changes the firmware branch to sync to, switch to the newly selected repo
	// and save their choice
	if ($_REQUEST['refrbranch']) {
		foreach ($repos as $repo) {
			if ($repo['name'] == $_POST['fwbranch']) {
				config_set_path('system/pkg_repo_conf_path', $repo['name']);
				write_config(gettext("Saved firmware branch setting."));
				pkg_switch_repo();
				break;
			}
		}
	}
} elseif (!$completed && empty($_REQUEST['pkg']) && $pkgmode != 'reinstallall') {
	header("Location: pkg_mgr_installed.php");
	return;
}

if (!empty($_REQUEST['pkg'])) {
	if (!pkg_valid_name($pkgname)) {
		header("Location: pkg_mgr_installed.php");
		return;
	}
}

$tab_array = array();

if ($firmwareupdate) {
	$pgtitle = array(gettext("System"), gettext("Update"), gettext("System Update"));
	$pglinks = array("", "@self", "@self");
} else {
	$pgtitle = array(gettext("System"), gettext("Package Manager"), gettext("Package Installer"));
	$pglinks = array("", "pkg_mgr_installed.php", "@self");
	$tab_array[] = array(gettext("Installed Packages"), false, "pkg_mgr_installed.php");
	$tab_array[] = array(gettext("Available Packages"), false, "pkg_mgr.php");
	$tab_array[] = array(gettext("Package Installer"), true, "");
}

/* what the page acts on, for the review and progress headers */
$catalog_shortname = $pkgname;
pkg_remove_prefix($catalog_shortname);
$package_meta = freesense_package_catalog_entry($catalog_shortname);
if ($firmwareupdate) {
	$subject_name = gettext('FreeSense system');
	$back_href = 'pkg_mgr_install.php?id=firmware';
	$back_label = gettext('Back to system update');
} elseif ($pkgmode == 'reinstallall') {
	$subject_name = gettext('All packages');
	$back_href = 'pkg_mgr_installed.php';
	$back_label = gettext('Back to installed packages');
} else {
	$subject_name = $package_meta['display_name'] ?: $catalog_shortname;
	$back_href = ($pkgmode == 'delete' || $pkgmode == 'reinstallpkg') ? 'pkg_mgr_installed.php' : 'pkg_mgr.php';
	$back_label = ($pkgmode == 'delete' || $pkgmode == 'reinstallpkg') ? gettext('Back to installed packages') : gettext('Back to available packages');
}

include("head.inc");

if ($firmwareupdate) {
	fs_tabs('system-update', 'pkg_mgr_install.php?id=firmware');
} else {
	display_top_tabs($tab_array);
}

if ($input_errors) {
	print_input_errors($input_errors);
}

?>
<style>
.fs-pkg-head { display: flex; align-items: center; gap: 1rem; padding: 1rem 1.25rem; border-bottom: 1px solid var(--fs-border); }
.fs-pkg-head-icon { display: inline-flex; align-items: center; justify-content: center; flex: 0 0 auto; width: 2.75rem; height: 2.75rem; border-radius: var(--fs-r-md); background: var(--fs-accent-tint); color: var(--fs-coral-text); font-size: 1.25rem; }
.fs-pkg-head-text { min-width: 0; flex: 1 1 auto; }
.fs-pkg-eyebrow { color: var(--fs-text-muted); font-size: var(--fs-fs-xs); font-weight: 600; letter-spacing: .04em; text-transform: uppercase; }
.fs-pkg-title { margin: 0; color: var(--fs-text-strong); font-size: 1.25rem; font-weight: 600; overflow-wrap: anywhere; }
.fs-pkg-sub { color: var(--fs-text-muted); font-size: var(--fs-fs-sm); overflow-wrap: anywhere; }
.fs-pkg-body { padding: 1.25rem; }
.fs-pkg-body > .fs-tiles { margin-bottom: 1rem; }
#installed_version, #version { font-size: 1.15rem; overflow-wrap: anywhere; }
.fs-pkg-section { margin: 1.25rem 0 .5rem; color: var(--fs-text-strong); font-size: 1rem; font-weight: 600; }
.fs-pkg-caps { display: flex; flex-wrap: wrap; gap: 6px; }
.fs-pkg-cap { display: inline-flex; align-items: center; gap: .35rem; padding: .1rem .55rem; border: 1px solid var(--fs-border); border-radius: 999px; font-size: var(--fs-fs-sm); }
.fs-pkg-cap > i { color: var(--fs-pass); font-size: var(--fs-fs-xs); }
.fs-pkg-notes { margin: 0; padding: 0; list-style: none; }
.fs-pkg-notes li { padding: .45rem 0; border-top: 1px solid var(--fs-border); }
.fs-pkg-notes li:first-child { border-top: 0; }
.fs-pkg-foot { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: .5rem; padding: .85rem 1.25rem; border-top: 1px solid var(--fs-border); }
.fs-pkg-status { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: .75rem 1rem; padding: .85rem 1rem; border: 1px solid var(--fs-border); border-radius: var(--fs-r-md); }
.fs-pkg-status-label { color: var(--fs-text-muted); font-size: var(--fs-fs-xs); font-weight: 600; letter-spacing: .04em; text-transform: uppercase; }
.fs-pkg-status .fs-pkg-status-text { display: flex; align-items: center; gap: .75rem; }
.fs-pkg-state { flex: 0 0 auto; }
.fs-pkg-state > .fs-badge[hidden], .fs-pkg-head-icon > i[hidden] { display: none; }
#pkg-run.is-success .fs-pkg-head-icon { background: color-mix(in srgb, var(--fs-pass) 14%, transparent); color: var(--fs-pass); }
#pkg-run.is-failure .fs-pkg-head-icon { background: color-mix(in srgb, var(--fs-block) 14%, transparent); color: var(--fs-block); }
#pkg-run .progress { height: .6rem; margin: 0 0 1rem; }
#pkg-run #final { margin-bottom: 1rem; }
#pkg-run #final p:last-child { margin-bottom: 0; }
#countdown h4 { margin: 0 0 1rem; font-size: 1rem; text-align: center; }
.fs-pkg-done { display: flex; flex-wrap: wrap; gap: .5rem; }
.fs-pkg-log .panel-heading { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem 1rem; }
.fs-pkg-log .panel-title { margin-right: auto; }
.fs-pkg-log .fs-console { min-height: 12rem; }
@media (max-width: 575.98px) { .fs-pkg-head { align-items: flex-start; flex-wrap: wrap; } .fs-pkg-body { padding: 1rem; } }
</style>

<form action="pkg_mgr_install.php" method="post" class="">
<?php

if (!isvalidpid($gui_pidfile) && !$confirmed && !$completed &&
    ($firmwareupdate || $pkgmode == 'reinstallall' || !empty($pkgname))):
	if ($pkgmode === 'delete') {
		$confirm_button_class = 'btn-danger';
		$confirm_button_icon = 'trash-can';
		$confirm_button_label = gettext('Remove package');
	} elseif ($pkgmode === 'reinstallpkg' || $pkgmode === 'reinstallall') {
		$confirm_button_class = 'btn-primary';
		$confirm_button_icon = 'arrows-rotate';
		$confirm_button_label = ($pkgmode === 'reinstallall') ? gettext('Reinstall all packages') : gettext('Reinstall package');
	} else {
		$confirm_button_class = 'btn-primary';
		$confirm_button_icon = 'download';
		$confirm_button_label = gettext('Install package');
	}
	$is_upgrade = ($pkgmode == 'reinstallpkg') && is_string($_REQUEST['from'] ?? null) && is_string($_REQUEST['to'] ?? null) &&
	    preg_match('/^[A-Za-z0-9._,+-]{1,64}$/D', $_REQUEST['from']) && preg_match('/^[A-Za-z0-9._,+-]{1,64}$/D', $_REQUEST['to']);
	if ($is_upgrade) {
		$confirm_button_label = gettext('Update package');
	}
	$category_icons = ['Security'=>'shield-halved', 'VPN'=>'lock', 'Monitoring'=>'chart-line', 'Routing'=>'route', 'Services'=>'layer-group', 'System'=>'gear', 'Authentication'=>'user-shield', 'Diagnostics'=>'stethoscope'];
	$package_icon = $category_icons[$package_meta['category']] ?? 'box-open';
?>
	<div class="panel panel-default">
<?php
	if ($firmwareupdate):
		// Check to see if any new repositories have become available. This data is cached and
		// refreshed every 24 hours. Still needed below for the upgrade path + messages.
		$repos = update_repos();
?>
		<div class="fs-pkg-head">
			<span class="fs-pkg-head-icon"><i class="fa-solid fa-arrows-rotate" aria-hidden="true"></i></span>
			<div class="fs-pkg-head-text">
				<div class="fs-pkg-eyebrow"><?=gettext('System Update')?></div>
				<h2 class="fs-pkg-title"><?=gettext('Check for and install system updates')?></h2>
			</div>
		</div>
		<div class="fs-pkg-body">
			<input type="hidden" name="mode" value="<?=htmlspecialchars($pkgmode)?>" />
<?php
		if (isset($repos['messages']) && count($repos['messages']) > 0) {
			print('<div class="mb-3" id="netgate_messages">');
			foreach ($repos['messages'] as $message) {
				print(gettext($message));
			}
			print('</div>');
		}
?>
			<div class="fs-tiles">
				<div class="fs-tile"><div class="fs-tile-label"><i class="fa-solid fa-server" aria-hidden="true"></i><?=gettext('Installed system')?></div><div class="fs-tile-value fs-mono" id="installed_version"><i class="fa-solid fa-ellipsis fa-fade" aria-hidden="true"></i></div></div>
				<div class="fs-tile"><div class="fs-tile-label"><i class="fa-solid fa-cloud-arrow-down" aria-hidden="true"></i><?=gettext('Available system')?></div><div class="fs-tile-value fs-mono" id="version"><i class="fa-solid fa-ellipsis fa-fade" aria-hidden="true"></i></div></div>
			</div>

			<div class="fs-pkg-status mb-3" id="confirm">
				<div class="fs-pkg-status-text">
					<div>
						<div class="fs-pkg-status-label" id="confirmlabel"><?=gettext('Update status')?></div>
						<input type="hidden" name="id" value="firmware" />
						<input type="hidden" name="confirmed" id="confirmed" value="true" />
						<span id="uptodate">
							<i class="fa-solid fa-rotate fa-spin text-warning" aria-hidden="true"></i>
							<span class="text-muted"><?=gettext("Checking for updates…")?></span>
						</span>
					</div>
				</div>
				<button type="submit" class="btn btn-primary" name="pkgconfirm" id="pkgconfirm" value="<?=gettext("Confirm")?>" style="display: none"><i class="fa-solid fa-download icon-embed-btn" aria-hidden="true"></i><?=gettext("Install update")?></button>
			</div>

			<div class="d-flex justify-content-end mb-3" id="release_info">
				<a target="_blank" rel="noopener" href="https://docs.freesense.org/guides/updates-and-channels/"><i class="fa-solid fa-arrow-up-right-from-square me-1" aria-hidden="true"></i><?=gettext("Release channels and update documentation")?></a>
			</div>
			<div class="panel panel-default mb-0" id="update_notes_card" style="display:none">
				<div class="panel-heading d-flex justify-content-between align-items-center"><h3 class="panel-title"><i class="fa-solid fa-wand-magic-sparkles me-2" aria-hidden="true"></i><?=gettext('What is new')?></h3><span class="badge text-bg-primary" id="update_notes_count"></span></div>
				<div class="panel-body p-3">
					<ul class="nav nav-tabs mb-3" id="update_notes_tabs" role="tablist">
						<li class="nav-item" role="presentation"><button class="nav-link active" id="update_notes_freesense_tab" data-bs-toggle="tab" data-bs-target="#update_notes_freesense" type="button" role="tab" aria-controls="update_notes_freesense" aria-selected="true"><i class="fa-solid fa-shield-halved me-1" aria-hidden="true"></i><?=gettext('FreeSense')?><span class="badge text-bg-secondary ms-2" id="update_notes_freesense_count"></span></button></li>
						<li class="nav-item" role="presentation"><button class="nav-link" id="update_notes_platform_tab" data-bs-toggle="tab" data-bs-target="#update_notes_platform" type="button" role="tab" aria-controls="update_notes_platform" aria-selected="false"><i class="fa-brands fa-freebsd me-1" aria-hidden="true"></i><?=gettext('Platform & packages')?><span class="badge text-bg-secondary ms-2" id="update_notes_platform_count"></span></button></li>
					</ul>
					<div class="tab-content">
						<div class="tab-pane fade show active" id="update_notes_freesense" role="tabpanel" aria-labelledby="update_notes_freesense_tab"><div class="d-flex flex-wrap gap-2 mb-3" id="update_notes_freesense_summary"></div><div class="list-group list-group-flush" id="update_notes_freesense_list"></div></div>
						<div class="tab-pane fade" id="update_notes_platform" role="tabpanel" aria-labelledby="update_notes_platform_tab"><div class="d-flex flex-wrap gap-2 mb-3" id="update_notes_platform_summary"></div><div class="list-group list-group-flush" id="update_notes_platform_list"></div></div>
					</div>
				</div>
			</div>
		</div>
<?php
	elseif (($pkgmode == 'delete') && $pkgname_vital):
?>
		<div class="fs-pkg-body">
			<?php print_info_box($pkgname_vital_message); ?>
			<a class="btn btn-outline-secondary" href="pkg_mgr_installed.php"><i class="fa-solid fa-arrow-left icon-embed-btn" aria-hidden="true"></i><?=gettext('Back to installed packages')?></a>
		</div>
<?php
	elseif ($pkgmode == 'reinstallall'):
?>
		<div class="fs-pkg-head">
			<span class="fs-pkg-head-icon"><i class="fa-solid fa-arrows-rotate" aria-hidden="true"></i></span>
			<div class="fs-pkg-head-text">
				<div class="fs-pkg-eyebrow"><?=gettext('Review')?></div>
				<h2 class="fs-pkg-title"><?=gettext('Reinstall all packages')?></h2>
				<div class="fs-pkg-sub"><?=gettext('Every installed package is reinstalled from the package repository. This can take several minutes.')?></div>
			</div>
		</div>
		<input type="hidden" name="mode" value="<?=htmlspecialchars($pkgmode)?>" />
		<input type="hidden" name="confirmed" value="true" />
		<div class="fs-pkg-foot">
			<a class="btn btn-outline-secondary" href="pkg_mgr_installed.php"><?=gettext('Cancel')?></a>
			<button type="submit" class="btn <?=htmlspecialchars($confirm_button_class)?>" name="pkgconfirm" id="pkgconfirm" value="<?=gettext("Confirm")?>"><i class="fa-solid fa-<?=htmlspecialchars($confirm_button_icon)?> icon-embed-btn" aria-hidden="true"></i><?=htmlspecialchars($confirm_button_label)?></button>
		</div>
<?php
	else:
		$package_version = gettext('Current repository');
		$package_summary = '';
		$package_description = '';
		if (pkg_exec('rquery %v ' . escapeshellarg($pkgname), $detail_out, $detail_err) === 0 && trim($detail_out) !== '') {
			$package_version = trim($detail_out);
		}
		if (pkg_exec('rquery %c ' . escapeshellarg($pkgname), $detail_out, $detail_err) === 0) {
			$package_summary = trim(preg_replace('/\s+/', ' ', $detail_out));
		}
		if (pkg_exec('rquery %e ' . escapeshellarg($pkgname), $detail_out, $detail_err) === 0) {
			$package_description = trim(preg_replace('/\s+/', ' ', strip_tags(preg_replace('/<br\s*\/?>/i', ' ', $detail_out))));
		}
		$package_notes = [];
		$product_version = trim((string)@file_get_contents('/etc/version'));
		$package_train = '';
		if (preg_match('/^([0-9]+\.[0-9]+)\./D', $product_version, $train_match)) {
			$package_train = $train_match[1];
		}
		if ($package_train !== '') {
			$notes_url = 'https://pkg.freesense.org/package-notes/' . rawurlencode($package_train) . '.json';
			$notes_raw = shell_exec('/usr/bin/fetch -qo - -T 4 ' . escapeshellarg($notes_url) . ' 2>/dev/null');
			$notes_doc = json_decode($notes_raw, true);
			if (is_array($notes_doc['packages'][$catalog_shortname] ?? null)) {
				$package_notes = array_slice($notes_doc['packages'][$catalog_shortname], 0, 3);
			}
		}
		if ($pkgmode === 'delete') {
			$review_title = gettext('Review the removal');
		} elseif ($is_upgrade) {
			$review_title = gettext('Review the update');
		} elseif ($pkgmode === 'reinstallpkg') {
			$review_title = gettext('Review the reinstallation');
		} else {
			$review_title = gettext('Review the installation');
		}
?>
		<input type="hidden" name="mode" value="<?=htmlspecialchars($pkgmode)?>" />
		<input type="hidden" name="pkg" value="<?=htmlspecialchars($pkgname)?>" />
		<input type="hidden" name="confirmed" value="true" />
		<div class="fs-pkg-head">
			<span class="fs-pkg-head-icon"><i class="fa-solid fa-<?=htmlspecialchars($package_icon)?>" aria-hidden="true"></i></span>
			<div class="fs-pkg-head-text">
				<div class="fs-pkg-eyebrow"><?=htmlspecialchars($review_title)?></div>
				<h2 class="fs-pkg-title"><?=htmlspecialchars($subject_name)?></h2>
				<div class="fs-pkg-sub"><span class="fs-mono"><?=htmlspecialchars($pkgname)?></span> · <?=htmlspecialchars($package_meta['category'])?></div>
			</div>
		</div>
		<div class="fs-pkg-body">
			<?php if ($package_summary): ?><p class="mb-1"><strong><?=htmlspecialchars($package_summary)?></strong></p><?php endif; ?>
			<?php if ($package_description): ?><p class="fs-muted"><?=htmlspecialchars($package_description)?></p><?php endif; ?>
			<div class="fs-tiles">
<?php
		if ($is_upgrade) {
			fs_tile(gettext('Version'), $_REQUEST['from'] . ' → ' . $_REQUEST['to']);
		} else {
			fs_tile(gettext('Version'), $package_version);
		}
		fs_tile(gettext('Resource use'), ucfirst($package_meta['resource_profile']));
		fs_tile(gettext('Support'), ucfirst($package_meta['support']));
		fs_tile(gettext('Tested on'), ucfirst($package_meta['last_tested_release']));
?>
			</div>
			<?php if (!empty($package_meta['capabilities'])): ?>
			<h3 class="fs-pkg-section"><?=gettext('What it adds')?></h3>
			<div class="fs-pkg-caps"><?php foreach ($package_meta['capabilities'] as $capability): ?><span class="fs-pkg-cap"><i class="fa-solid fa-check" aria-hidden="true"></i><?=htmlspecialchars(ucwords(str_replace('-', ' ', $capability)))?></span><?php endforeach; ?></div>
			<?php endif; ?>
			<?php if (!empty($package_meta['services'])): ?>
			<h3 class="fs-pkg-section"><?=gettext('Background services')?></h3>
			<div class="fs-mono small"><?=htmlspecialchars(implode(', ', $package_meta['services']))?></div>
			<?php endif; ?>
			<?php if ($package_notes): ?>
			<h3 class="fs-pkg-section"><?=gettext('Recent updates')?></h3>
			<ul class="fs-pkg-notes"><?php foreach ($package_notes as $note): ?><li><div class="fw-semibold"><?=htmlspecialchars($note['title'] ?? '')?></div><small class="fs-muted"><?=htmlspecialchars($note['date'] ?? '')?></small></li><?php endforeach; ?></ul>
			<?php endif; ?>
		</div>
		<div class="fs-pkg-foot">
			<a class="btn btn-outline-secondary" href="<?=htmlspecialchars($back_href)?>"><?=gettext('Cancel')?></a>
			<button type="submit" class="btn <?=htmlspecialchars($confirm_button_class)?>" name="pkgconfirm" id="pkgconfirm" value="<?=gettext("Confirm")?>"><i class="fa-solid fa-<?=htmlspecialchars($confirm_button_icon)?> icon-embed-btn" aria-hidden="true"></i><?=htmlspecialchars($confirm_button_label)?></button>
		</div>
<?php
	endif;
?>
	</div>
<?php
endif;
?>
	<div id="unable" style="display: none">
		<?=print_info_box(gettext("Unable to retrieve system versions."), 'danger')?>
	</div>
<?php

if ($_POST) {
	$logfilename = pkg_mgr_install_logfile($firmwareupdate, $pkgname);
	$postlog = $firmwareupdate ? "UPGR" : "PKG";
}

$pkgname_bold = '<b>' . $pkgname . '</b>';

if ($firmwareupdate) {
	$panel_heading_txt = gettext("Updating the system");
	$pkg_success_txt = gettext('Upgrade will continue after the system restarts. Please do not reset or power off.');
	$pkg_fail_txt = gettext('System update failed!');
	$pkg_wait_txt = gettext('Please wait while the system update completes.');
} else if ($pkgmode == 'delete') {
	$panel_heading_txt = gettext("Removing package");
	$pkg_success_txt = sprintf(gettext('%1$s removal successfully completed.'), $pkgname_bold);
	$pkg_fail_txt = sprintf(gettext('%1$s removal failed!'), $pkgname_bold);
	$pkg_wait_txt = sprintf(gettext('Please wait while the removal of %1$s completes.'), $pkgname_bold);
} else if ($pkgmode == 'reinstallall') {
	$panel_heading_txt = gettext("Reinstalling all packages");
	$pkg_success_txt = gettext('All packages reinstallation successfully completed.');
	$pkg_fail_txt = gettext('All packages reinstallation failed!');
	$pkg_wait_txt = gettext('Please wait while the reinstallation of all packages completes.');
} else if ($pkgmode == 'reinstallpkg') {
	$panel_heading_txt = gettext("Reinstalling package");
	$pkg_success_txt = sprintf(gettext('%1$s reinstallation successfully completed.'), $pkgname_bold);
	$pkg_fail_txt = sprintf(gettext('%1$s reinstallation failed!'), $pkgname_bold);
	$pkg_wait_txt = sprintf(gettext('Please wait while the reinstallation of %1$s completes.'), $pkgname_bold);
} else {
	$panel_heading_txt = gettext("Installing package");
	$pkg_success_txt = sprintf(gettext('%1$s installation successfully completed.'), $pkgname_bold);
	$pkg_fail_txt = sprintf(gettext('%1$s installation failed!'), $pkgname_bold);
	$pkg_wait_txt = sprintf(gettext('Please wait while the installation of %1$s completes.'), $pkgname_bold);
}

if ($confirmed || isvalidpid($gui_pidfile)):
	if (isvalidpid($gui_pidfile)) {
		$start_polling = true;
	}
?>
	<input type="hidden" name="id" value="<?=htmlspecialchars($_REQUEST['id'] ?? '')?>" />
	<input type="hidden" name="mode" value="<?=htmlspecialchars($pkgmode)?>" />
	<input type="hidden" name="pkg" value="<?=htmlspecialchars($pkgname)?>" />
	<input type="hidden" name="completed" value="true" />
	<input type="hidden" name="confirmed" value="true" />
	<input type="hidden" id="reboot_needed" name="reboot_needed" value="no" />

	<div class="panel panel-default is-running" id="pkg-run">
		<div class="fs-pkg-head">
			<span class="fs-pkg-head-icon">
				<i class="fa-solid fa-gear fa-spin" data-run-state="running" aria-hidden="true"></i>
				<i class="fa-solid fa-check" data-run-state="success" aria-hidden="true" hidden></i>
				<i class="fa-solid fa-xmark" data-run-state="failure" aria-hidden="true" hidden></i>
			</span>
			<div class="fs-pkg-head-text">
				<div class="fs-pkg-eyebrow" id="status"><?=htmlspecialchars($panel_heading_txt)?></div>
				<h2 class="fs-pkg-title"><?=htmlspecialchars($subject_name)?></h2>
<?php if (!$firmwareupdate && $pkgname !== ''): ?>
				<div class="fs-pkg-sub fs-mono"><?=htmlspecialchars($pkgname)?></div>
<?php endif; ?>
			</div>
			<span class="fs-pkg-state" aria-live="polite">
				<?=fs_badge('pending', gettext('In progress'))?>
				<span hidden><?=fs_badge('pass', gettext('Completed'))?></span>
				<span hidden><?=fs_badge('error', gettext('Failed'))?></span>
			</span>
		</div>
		<div class="fs-pkg-body">
			<div id="countdown"></div>
			<div class="progress" role="progressbar" aria-label="<?=gettext('Progress')?>" style="display: none;">
				<div id="progressbar" class="progress-bar progress-bar-striped progress-bar-animated" aria-valuemin="0" aria-valuemax="100" style="width: 1%"></div>
			</div>
			<div id="final" class="alert" role="alert" style="display: none;"></div>
			<div class="fs-pkg-done" id="run-done" style="display: none;">
				<a class="btn btn-sm btn-primary" href="<?=htmlspecialchars($back_href)?>"><i class="fa-solid fa-arrow-left icon-embed-btn" aria-hidden="true"></i><?=htmlspecialchars($back_label)?></a>
<?php if (!$firmwareupdate && $back_href !== 'pkg_mgr_installed.php'): ?>
				<a class="btn btn-sm btn-outline-secondary" href="pkg_mgr_installed.php"><?=gettext('Installed packages')?></a>
<?php endif; ?>
			</div>
		</div>
	</div>

	<div class="panel panel-default fs-pkg-log">
		<div class="panel-heading">
			<h2 class="panel-title"><?=gettext('Output')?></h2>
			<div class="form-check form-switch mb-0">
				<input class="form-check-input" type="checkbox" role="switch" checked id="autoscroll" />
				<label class="form-check-label" for="autoscroll"><?=gettext('Auto-scroll')?></label>
			</div>
			<button type="button" class="btn btn-sm btn-outline-secondary" data-fs-copy="#output-view"><i class="fa-regular fa-copy icon-embed-btn" aria-hidden="true"></i><?=gettext('Copy')?></button>
		</div>
		<pre class="fs-console" id="output-view" aria-live="off"></pre>
		<textarea id="output" name="output" hidden spellcheck="false"><?=($completed ? htmlspecialchars($_POST['output']) : gettext("Please wait while the update system initializes"))?></textarea>
	</div>


	<!-- Modal used to display installation notices -->
	<div id="notice" name="notice" class="modal fade" role="dialog" tabindex="-1" aria-labelledby="notice-title">
		<div class="modal-dialog modal-dialog-centered">
			<div class="modal-content">
				<div class="modal-header"><h2 class="modal-title" id="notice-title"><?=gettext('Notice')?></h2></div>
				<div class="modal-body" id="noticebody" name="noticebody">
				</div>
				<div class="modal-footer">
					<button type="button" id="modalbtn" name="modalbtn" class="btn btn-primary" data-bs-dismiss="modal"><?=gettext('Accept')?></button>
				</div>
			</div>
		</div>
	</div>
<?php
endif;
?>
</form>

<?php

ob_flush();

if (!isvalidpid($gui_pidfile) && $confirmed && !$completed) {
	$started = pkg_mgr_install_start($pkgmode, $pkgname, $firmwareupdate, $_POST['fwbranch'] ?? '', $repos);
	$progbar = $started['progbar'];
	$logfilename = $started['logfilename'];
	if ($started['started']) {
		$start_polling = true;
	}
	$failmsg = $started['failmsg'];
	if ($started['reason'] == 'failed') {
		/* Make javascript happy not sending any \n */
		$failmsg = preg_replace("/\n/", '%%', $failmsg);
	}
}

$uptodatemsg = gettext("Up to date.");
$newerversionmsg = gettext("Running a newer version.");
$confirmlabel = gettext("Update available");
$sysmessage = gettext("Update status");

// $completed just means that we are refreshing the page to update any new menu items
// that were installed
if ($completed):
	unlink_if_exists($logfilename . ".json");
	unlink_if_exists($gui_mode);

	// If this was a firmware update and a reboot was initiated, display the "Rebooting" message
	// and start the countdown timer
	if ($firmwareupdate && $reboot_needed):

?>
<script type="text/javascript">
//<![CDATA[
events.push(function() {
	time = "<?=$guitimeout?>";
	startCountdown();
});
//]]>
</script>
<?php
	endif;
endif;

?>

<script type="text/javascript">
//<![CDATA[
// Update the progress indicator
// transition = true allows the bar to move at default speed, false = instantaneous
function setProgress(barName, percent, transition) {
	$('.progress').show()
	if (!transition) {
		$('#' + barName).css('transition', 'width 0s ease-in-out');
	}

	$('#' + barName).css('width', percent + '%').attr('aria-valuenow', percent);
}

// Header icon, badge and the "back" links follow the run state
function setRunState(state) {
	var run = $('#pkg-run');
	run.removeClass('is-running is-success is-failure').addClass('is-' + state);
	run.find('[data-run-state]').each(function () {
		this.hidden = (this.getAttribute('data-run-state') !== state);
	});
	var badges = run.find('.fs-pkg-state > .fs-badge, .fs-pkg-state > span');
	badges.each(function (i) {
		this.hidden = (i !== ['running', 'success', 'failure'].indexOf(state));
	});
	if (state !== 'running') {
		$('#progressbar').removeClass('progress-bar-animated progress-bar-striped');
		$('#run-done').show();
	}
}

// Mirror the log (decoded by the textarea, which is also posted on reload) into the console
function showOutput(force) {
	var view = $('#output-view');
	view.text($('#output').val());
	if (force || $('#autoscroll').prop('checked')) {
		view.scrollTop(view.prop('scrollHeight'));
	}
}

// Display a success banner
function show_success() {
	setRunState('success');
	$('#progressbar').addClass('bg-success');
	if (!"<?=$firmwareupdate?>") {
		$('#final').removeClass("alert-info").addClass("alert-success");
	} else {
		$('#final').addClass("alert-warning");
	}
	if ("<?=$pkgmode?>" != "reinstallall") {
		$('#final').html("<?=$pkg_success_txt?>");
	} else {
		$('#final').html("<?=gettext('Reinstallation of all packages successfully completed.')?>");
	}

	$('#final').show();
}

// Display a failure banner
function show_failure() {
	setRunState('failure');
	$('#progressbar').addClass('bg-danger');
	$('#final').removeClass("alert-info");
	$('#final').addClass("alert-danger");
	if ("<?=$pkgmode?>" != "reinstallall") {
		$('#final').html("<?=$pkg_fail_txt?>");
	} else {
		$('#final').html("<?=gettext('Reinstallation of all packages failed.')?>");
	}
	$('#final').show();
}

// Ask the user to wait a bit
function show_info() {
	$('#final').addClass("alert-info");
	if ("<?=$pkgmode?>" != "reinstallall") {
		$('#final').html("<p><?=$pkg_wait_txt?>" + "</p><p>" +
			"<?=gettext("This may take several minutes. Do not leave or refresh the page!")?>" + "</p>");
	} else {
		$('#final').html("<p><?=gettext('Please wait while the reinstallation of all packages completes.')?>" + "</p><p>" +
			"<?=gettext("This may take several minutes!")?>" + "</p>");
	}
	$('#final').show();
}

function get_firmware_versions() {
	var ajaxVersionRequest;

	// Retrieve the version information
	ajaxVersionRequest = $.ajax({
			url: "pkg_mgr_install.php",
			type: "post",
			data: {
					ajax: "ajax",
					getversion: "yes"
			}
		});

	// Deal with the results of the above ajax call
	ajaxVersionRequest.done(function (response, textStatus, jqXHR) {
		var json = new Object;

		json = JSON.parse(response);

		if (json && json.pkg_busy == '1') {
			$('#uptodate').html('<i class="fa-solid fa-hourglass-half text-warning"></i> <span class="text-warning">' + '<?=gettext("Another update is already running. Please try again in a few moments.")?>' + "</span>");
		} else if (json && !json.pkg_version_error) {
			$('#installed_version').text(json.installed_version);
			$('#version').text(json.version);
			render_update_notes(json.release_notes || null);

			// If the installed and latest versions are the same, print an "Up to date" message
			if (json.pkg_version_compare == '=') {
				$('#confirmlabel').text("<?=$sysmessage?>");
				$('#uptodate').html('<i class="fa-solid fa-circle-check text-success"></i> <span class="text-success">' + '<?=$uptodatemsg?>' + "</span>");
			} else if (json.pkg_version_compare == '>') {
				$('#confirmlabel').text("<?=$sysmessage?>");
				$('#uptodate').html('<i class="fa-solid fa-circle-check text-success"></i> <span class="text-success">' + '<?=$newerversionmsg?>' + "</span>");
			} else { // If they differ display the "Confirm" button
				$('#uptodate').html('<i class="fa-solid fa-circle-arrow-up text-info"></i> <span class="text-info">' + '<?=gettext("An update is available.")?>' + "</span>");
				$('#confirmlabel').text( "<?=$confirmlabel?>");
				$('#pkgconfirm').show();
			}
		} else if (json && json.pkg_version_error) {
			$('#uptodate').html('<i class="fa-solid fa-triangle-exclamation text-danger"></i> <span class="text-danger">' + '<?=gettext("Unable to check for updates")?>' + "</span>" + "<br/>" + json.pkg_version_error);
		} else {
			$('#uptodate').html('<i class="fa-solid fa-triangle-exclamation text-danger"></i> <span class="text-danger">' + '<?=gettext("Unable to check for updates")?>' + "</span>");
		}
	});
}

function render_update_notes(notes) {
	var structured = notes && notes.release_notes && notes.release_notes.schema_version === 'freesense.release-notes/v2' ? notes.release_notes : null;
	var changes = structured && Array.isArray(structured.freesense) ? structured.freesense : (notes && Array.isArray(notes.changes) ? notes.changes : []);
	var platform = structured && structured.platform ? structured.platform : null;
	var freebsd = platform && platform.freebsd ? platform.freebsd : null;
	var packages = platform && platform.packages ? platform.packages : null;
	var packageCounts = packages && packages.counts ? packages.counts : {updated:0, added:0, removed:0};
	var platformCount = (freebsd && freebsd.changed ? 1 : 0) + (freebsd && freebsd.ports_changed ? 1 : 0) + (packageCounts.updated || 0) + (packageCounts.added || 0) + (packageCounts.removed || 0);
	if (!changes.length && !platformCount) { $('#update_notes_card').hide(); return; }
	var list = $('#update_notes_freesense_list').empty();
	var summary = $('#update_notes_freesense_summary').empty();
	var platformList = $('#update_notes_platform_list').empty();
	var platformSummary = $('#update_notes_platform_summary').empty();
	var styles = {security:'danger', fix:'warning', feature:'success', ui:'primary', package:'info', documentation:'secondary', build:'dark', other:'secondary'};
	var icons = {security:'shield-halved', fix:'screwdriver-wrench', feature:'star', ui:'palette', package:'box-open', documentation:'book', build:'gears', other:'code-commit'};
	var counts = {};
	changes.slice(0, 30).forEach(function(change) {
		var type = styles[change.type] ? change.type : 'other'; counts[type] = (counts[type] || 0) + 1;
		var row = $('<div>').addClass('list-group-item bg-transparent px-0 d-flex gap-3');
		row.append($('<span>').addClass('text-' + styles[type]).append($('<i>').addClass('fa-solid fa-' + icons[type])));
		var body = $('<div>'); body.append($('<div>').addClass('fw-semibold').text(change.title || ''));
		if (change.scope) body.append($('<div>').addClass('small text-body-secondary').text(change.scope));
		row.append(body); list.append(row);
	});
	Object.keys(counts).forEach(function(type) {
		summary.append($('<span>').addClass('badge text-bg-' + styles[type]).append($('<i>').addClass('fa-solid fa-' + icons[type] + ' me-1')).append(document.createTextNode(type.charAt(0).toUpperCase() + type.slice(1) + ' ' + counts[type])));
	});
	if (!changes.length) list.append($('<div>').addClass('text-body-secondary py-3').text('<?=gettext('No FreeSense changes in this firmware.')?>'));

	function platformRow(icon, color, title, detail) {
		var row = $('<div>').addClass('list-group-item bg-transparent px-0 d-flex gap-3');
		row.append($('<span>').addClass('text-' + color).append($('<i>').addClass('fa-solid fa-' + icon)));
		var body = $('<div>'); body.append($('<div>').addClass('fw-semibold').text(title));
		if (detail) body.append($('<div>').addClass('small text-body-secondary').text(detail));
		row.append(body); platformList.append(row);
	}
	function shortCommit(value) { return typeof value === 'string' && /^[0-9a-f]{40}$/.test(value) ? value.slice(0, 12) : 'unknown'; }
	if (freebsd && freebsd.changed) platformRow('server', 'primary', '<?=gettext('FreeBSD source snapshot updated')?>', shortCommit(freebsd.from_commit) + ' → ' + shortCommit(freebsd.to_commit));
	if (freebsd && freebsd.ports_changed) platformRow('code-branch', 'info', '<?=gettext('FreeBSD ports snapshot updated')?>', shortCommit(freebsd.from_ports_commit) + ' → ' + shortCommit(freebsd.to_ports_commit));
	[['updated','arrow-up','info'], ['added','plus','success'], ['removed','minus','warning']].forEach(function(kind) {
		var items = packages && Array.isArray(packages[kind[0]]) ? packages[kind[0]] : [];
		items.forEach(function(item) {
			var version = kind[0] === 'updated' ? item.from + ' → ' + item.to : item.version;
			platformRow(kind[1], kind[2], item.name || '', version + (item.origin ? ' · ' + item.origin : ''));
		});
	});
	if (freebsd && freebsd.changed) platformSummary.append($('<span>').addClass('badge text-bg-primary').text('<?=gettext('FreeBSD source updated')?>'));
	if (freebsd && freebsd.ports_changed) platformSummary.append($('<span>').addClass('badge text-bg-info').text('<?=gettext('Ports updated')?>'));
	[['updated','info'], ['added','success'], ['removed','warning']].forEach(function(kind) {
		var count = packageCounts[kind[0]] || 0;
		if (count) platformSummary.append($('<span>').addClass('badge text-bg-' + kind[1]).text(kind[0].charAt(0).toUpperCase() + kind[0].slice(1) + ' ' + count));
	});
	if (packages && packages.truncated) platformList.append($('<div>').addClass('small text-body-secondary py-2').text('<?=gettext('Only the first 200 package changes are shown.')?>'));
	if (!platformCount) platformList.append($('<div>').addClass('text-body-secondary py-3').text(structured ? '<?=gettext('FreeBSD platform and System packages are unchanged.')?>' : '<?=gettext('Platform details are not available for this older release.')?>'));

	$('#update_notes_freesense_count').text(changes.length);
	$('#update_notes_platform_count').text(platformCount);
	$('#update_notes_count').text((changes.length + platformCount) + ' <?=gettext('changes')?>');
	var showPlatform = !changes.length && platformCount > 0;
	$('#update_notes_freesense_tab').toggleClass('active', !showPlatform).attr('aria-selected', showPlatform ? 'false' : 'true');
	$('#update_notes_platform_tab').toggleClass('active', showPlatform).attr('aria-selected', showPlatform ? 'true' : 'false');
	$('#update_notes_freesense').toggleClass('show active', !showPlatform);
	$('#update_notes_platform').toggleClass('show active', showPlatform);
	$('#update_notes_card').show();
}

function getLogsStatus() {
	var ajaxRequest;
	var repeat;
	var progress;
	var overrideScroll;

	repeat = true;
	overrideScroll = false;

	ajaxRequest = $.ajax({
			url: "pkg_mgr_install.php",
			type: "post",
			data: { ajax: "ajax",
					logfilename: "<?=$postlog?>",
					next_log_line: "0",
					pkg: "<?=$pkgname?>"
			}
		});

	// Deal with the results of the above ajax call
	ajaxRequest.done(function (response, textStatus, jqXHR) {
		var json = new Object;

		json = JSON.parse(response);

//		alert("JSON data: " + JSON.stringify(json));

		if (json.log != "not_ready") {
			var _o = $('#output');
			// Write the log file to the "output" textarea and show it in the console
			_o.html(json.log);
			showOutput(false);

			// Update the progress bar
			progress = 0;

			if ("<?=$progbar?>") {
				if (json.data) {
					/*
					 * XXX: There appears to be a bug in pkg that can cause "total"
					 * to be reported as zero
					 *
					 * https://github.com/freebsd/pkg/issues/1336
					 */
					if (json.data.total > 0) {
						setProgress('progressbar', ((json.data.current * 100) / json.data.total), true);
					}

					progress = json.data.total - json.data.current
					if (progress < 0) {
						progress = 0;
					}

				}
			}
			// Now we need to determine if the installation/removal was successful, and tell the user. Not as easy as it sounds :)
			if ((json.pid == "stopped") && (progress == 0) && (json.exitstatus == 0)) {
				show_success();
				repeat = false;

				// The package has been installed/removed successfully but any menu changes that result will not be visible
				// Reloading the page will cause the menu items to be visible and setting reboot_needed will tell the page
				// that the firewall needs to be rebooted if required.

				if (json.reboot_needed == "yes") {
					$('#reboot_needed').val("yes");
				}

				// Display any UI notice the package installer may have created
				if (json.notice.length > 0) {
					$('#noticebody').html(json.notice);
					bootstrap.Modal.getOrCreateInstance(document.getElementById('notice')).show();
				} else {
					$('form').submit();
				}
			}

			if ((json.pid == "stopped") && ((progress != 0) || (json.exitstatus != 0))) {
				show_failure();
				repeat = false;
			}
			// ToDo: There are more end conditions we need to catch
		}

		// And maybe do it again
		if (repeat)
			setTimeout(getLogsStatus, 500);
	});
}

function scrollToBottom(force) {
	showOutput(force);
}

var time = 0;

function checkonline() {
	$.ajax({
		url : "/index.php", // or other resource
		type : "HEAD"
	})
	.done(function() {
		window.location="/index.php";
	});
}

function startCountdown() {
	setInterval(function() {
		if (time == "<?=$guitimeout?>") {
			$('#countdown').html('<h4><?=sprintf(gettext('Rebooting%1$sPage will automatically reload in %2$s seconds'), "<br />", "<span id=\"secs\"></span>");?></h4>');
		}

		if (time > 0) {
			$('#secs').html(time);
			time--;
		} else {
			time = "<?=$guiretry?>";
			$('#countdown').html('<h4><?=sprintf(gettext('Not yet ready%1$s Retrying in another %2$s seconds'), "<br />", "<span id=\"secs\"></span>");?></h4>');
			$('#secs').html(time);
			checkonline();
		}
	}, 1000);
}

events.push(function() {
	showOutput(true);

	// If the update has a message to be displayed, do that here
	var failmsg = "<?=$failmsg?>".replace(/%%/g, "\n");

	if (failmsg.length > 0) {
		$('#output').html(failmsg);
		showOutput(true);
		show_failure();
	}

	// Start polling the system to obtain progress information
	if ("<?=$start_polling?>") {
		setTimeout(getLogsStatus, 3000);
		show_info();
	}

	// If we are just re-drawing the page after a successful install/remove/reinstall,
	// we only need to re-populate the progress indicator and the status banner
	if ("<?=$completed?>") {
		setProgress('progressbar', 100, false);
		show_success();
		setTimeout(scrollToBottom, 200, true); /* force scroll */
	}

	if ("<?=$firmwareupdate?>") {
		get_firmware_versions();
	}

	// The firmware branch is now read-only here (chosen under Update Settings), so the
	// old #fwbranch change-handler that reloaded the page on selection is gone.

	$('#modalbtn').click(function() {
		$('form').submit();
	});
});

//]]>
</script>

<?php
include('foot.inc');
