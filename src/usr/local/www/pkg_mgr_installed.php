<?php
/*
 * pkg_mgr_installed.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
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
##|*IDENT=page-system-packagemanager-installed
##|*NAME=System: Package Manager: Installed
##|*DESCR=Allow access to the 'System: Package Manager: Installed' page.
##|*MATCH=pkg_mgr_installed.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("pkg-utils.inc");

/* if upgrade in progress, alert user */
if (is_subsystem_dirty('packagelock')) {
	$pgtitle = array(gettext("System"), gettext("Package Manager"));
	$pglinks = array("", "@self");
	include("head.inc");
	print_info_box("Please wait while packages are reinstalled in the background.");
	include("foot.inc");
	exit;
}

// We are being called only to get the package data, not to display anything
if (($_REQUEST) && ($_REQUEST['ajax'])) {
	print(get_pkg_table());
	exit;
}

function get_pkg_table() {
	$installed_packages = get_pkg_info('all', false, true);

	if (empty($installed_packages)) {
		print ("nopkg");
		exit;
	}

	$pkgtbl = "";
	$pkgtbl .='		<div class="table-responsive">';
	$pkgtbl .='		<table class="table table-striped table-hover table-sm">';
	$pkgtbl .='			<thead>';
	$pkgtbl .='				<tr>';
	$pkgtbl .='					<th><!-- Status icon --></th>';
	$pkgtbl .='					<th>' . gettext("Name") . '</th>';
	$pkgtbl .='					<th>' . gettext("Category") . '</th>';
	$pkgtbl .='					<th>' . gettext("Version") . '</th>';
	$pkgtbl .='					<th>' . gettext("Description") . '</th>';
	$pkgtbl .='					<th>' . gettext("Actions") . '</th>';
	$pkgtbl .='				</tr>';
	$pkgtbl .='			</thead>';
	$pkgtbl .='			<tbody>';

	foreach ($installed_packages as $pkg) {
		if (!$pkg['name']) {
			continue;
		}
		$meta = $pkg['freesense'];

		#check package version
		$txtcolor = "";
		$upgradeavail = false;
		$missing = false;
		$vergetstr = "";

		if (isset($pkg['broken'])) {
			// package is configured, but does not exist in the system
			$txtcolor = "text-danger";
			$missing = true;
			$status = gettext('Package is configured, but not installed!');
		} else if (isset($pkg['obsolete'])) {
			// package is configured, but does not exist in the system
			$txtcolor = "text-danger";
			$missing = true;
			$status = gettext('Package is installed, but is not available on remote repository!');
		} else if (isset($pkg['installed_version']) && isset($pkg['version'])) {
			$version_compare = pkg_version_compare($pkg['installed_version'], $pkg['version']);

			if ($version_compare == '>') {
				// we're running a newer version of the package
				$status = sprintf(gettext('Newer than available (%s)'), $pkg['version']);
			} else if ($version_compare == '<') {
				// we're running an older version of the package
				$status = sprintf(gettext('Upgrade available to %s'), $pkg['version']);
				$txtcolor = "text-warning";
				$upgradeavail = true;
				$vergetstr = '&amp;from=' . $pkg['installed_version'] . '&amp;to=' . $pkg['version'];
			} else if ($version_compare == '=') {
				// we're running the current version
				$status = gettext('Up-to-date');
			} else {
				$status = gettext('Error comparing version');
			}
		} else {
			// unknown available package version
			$status = gettext('Unknown');
			$statusicon = 'question';
		}

		$pkgtbl .='				<tr>';
		$pkgtbl .='					<td>';
		if (!empty($meta['configure_path'])) {
			$pkgtbl .= '<a title="' . gettext('Package integration and health') . '" href="pkg_control.php?pkg=' . rawurlencode($pkg['shortname']) . '" class="btn btn-primary btn-sm me-1"><i class="fa-solid fa-sliders me-1"></i>' . gettext('Manage') . '</a>';
		}
		if (!empty($meta['status_path']) && $meta['status_path'] !== $meta['configure_path']) {
			$pkgtbl .= '<a title="' . gettext('Package status') . '" href="' . htmlspecialchars($meta['status_path']) . '" class="btn btn-outline-secondary btn-sm me-1"><i class="fa-solid fa-chart-line"></i></a>';
		}

		if ($upgradeavail) {
			$pkgtbl .='						<a title="' . $status . '" href="pkg_mgr_install.php?mode=reinstallpkg&amp;pkg=' . $pkg['name'] . $vergetstr . '" class="fa-solid fa-arrows-rotate"></a>';
		} elseif ($missing) {
			$pkgtbl .='						<span class="text-danger"><i title="' . $status . '" class="fa-solid fa-exclamation"></i></span>';
		} else {
			$pkgtbl .='						<i title="' . $status . '" class="fa-solid fa-check"></i>';
		}
		$pkgtbl .='					</td>';
		$pkgtbl .='					<td>';
		$pkgtbl .='						<span class="' . $txtcolor . '">' . htmlspecialchars($meta['display_name']) . '</span>';
		$pkgtbl .='<div class="small text-body-secondary">' . htmlspecialchars(ucfirst($meta['resource_profile'])) . '</div>';
		$pkgtbl .='					</td>';
		$pkgtbl .='					<td>';
		$pkgtbl .='						' . htmlspecialchars($meta['category']);
		$pkgtbl .='					</td>';
		$pkgtbl .='					<td>';

		if (!g_get('disablepackagehistory')) {
			$pkgtbl .='						<a target="_blank" title="' . gettext("View changelog") . '" href="' . htmlspecialchars($pkg['changeloglink']) . '">' .
		    htmlspecialchars($pkg['installed_version']) . '</a>';
		} else {
			$pkgtbl .='						' . htmlspecialchars($pkg['installed_version']);
		}

		$pkgtbl .='					</td>';
		$pkgtbl .='					<td>';
		$pkgtbl .='						' . $pkg['desc'];

		if (is_array($pkg['deps']) && count($pkg['deps'])) {
			$pkgtbl .='						<br /><br />' . gettext("Package Dependencies") . ':<br/>';
			foreach ($pkg['deps'] as $pdep) {
				$pkgtbl .='						<a target="_blank" href="https://freshports.org/' . $pdep['origin'] . '">&nbsp;' .
				    '<i class="fa-solid fa-paperclip"></i> ' . basename($pdep['origin']) . '-' . $pdep['version'] . '</a>&emsp;';
			}
		}
		$pkgtbl .='					</td>';
		$pkgtbl .='					<td>';
		$pkgtbl .='							<a title="' . sprintf(gettext("Remove package %s"), $pkg['name']) .
		    '" href="pkg_mgr_install.php?mode=delete&amp;pkg=' . $pkg['name'] . '" class="fa-solid fa-trash-can"></a>';

		if ($upgradeavail) {
			$pkgtbl .='						<a title="' . sprintf(gettext("Update package %s"), $pkg['name']) .
			    '" href="pkg_mgr_install.php?mode=reinstallpkg&amp;pkg=' . $pkg['name'] . $vergetstr . '" class="fa-solid fa-arrows-rotate"></a>';
		} else if (!isset($pkg['obsolete'])) {
			$pkgtbl .='						<a title="' . sprintf(gettext("Reinstall package %s"), $pkg['name']) .
			    '" href="pkg_mgr_install.php?mode=reinstallpkg&amp;pkg=' . $pkg['name'] . '" class="fa-solid fa-retweet"></a>';
		}

		if (!isset($g['disablepackageinfo']) && $pkg['www'] != 'UNKNOWN') {
			$pkgtbl .='						<a target="_blank" title="' . gettext("View more information") . '" href="' .
			    htmlspecialchars($pkg['www']) . '" class="fa-solid fa-info"></a>';
		}
		$pkgtbl .='					</td>';
		$pkgtbl .='				</tr>';
	}

	$pkgtbl .='			</tbody>';
	$pkgtbl .='		</table>';
	$pkgtbl .='		</div>';
	$pkgtbl .='	</div>';

	return $pkgtbl;
}

$pgtitle = array(gettext("System"), gettext("Package Manager"), gettext("Installed Packages"));
$pglinks = array("", "@self", "@self");
include("head.inc");

$tab_array = array();
$tab_array[] = array(gettext("Installed Packages"), true, "pkg_mgr_installed.php");
$tab_array[] = array(gettext("Available Packages"), false, "pkg_mgr.php");
display_top_tabs($tab_array);

?>

<div class="card mb-3">
	<div class="card-header"><h2 class="h5 mb-0"><?=gettext('Installed Packages')?></h2></div>
	<div id="pkgtbl" class="card-body">
		<div id="waitmsg">
			<?php print_info_box(gettext("Please wait while the list of packages is retrieved and formatted.") . '&nbsp;<i class="fa-solid fa-cog fa-spin"></i>'); ?>
		</div>

		<div id="errmsg" style="display: none;">
			<?php print_info_box("<ul><li>" . gettext("Unable to retrieve package information.") . "</li></ul>", 'danger'); ?>
		</div>

		<div id="nopkg" style="display: none;">
			<?php print_info_box(gettext("There are no packages currently installed."), 'warning', false); ?>
		</div>
	</div>

	<div id="legend" class="alert-info text-center">
		<p>
		<i class="fa-solid fa-arrows-rotate"></i> = <?=gettext('Update')?>  &nbsp;
		<i class="fa-solid fa-check"></i> = <?=gettext('Current')?> &nbsp;
		</p>
		<p>
		<i class="fa-solid fa-trash-can"></i> = <?=gettext('Remove')?> &nbsp;
		<i class="fa-solid fa-info"></i> = <?=gettext('Information')?> &nbsp;
		<i class="fa-solid fa-retweet"></i> = <?=gettext('Reinstall')?>
		</p>
		<p>
		<span class="text-warning"><?=gettext("Newer version available")?></span>
		</p>
		<span class="text-danger"><?=gettext("Package is configured but not (fully) installed or deprecated")?></span>
	</div>
</div>

<script type="text/javascript">
//<![CDATA[

events.push(function() {

	// Retrieve the table formatted package information and display it in the "Packages" panel
	// (Or display an appropriate error message)
	var ajaxRequest;

	$('#legend').hide();
	$('#nopkg').hide();

	$.ajax({
		url: "/pkg_mgr_installed.php",
		type: "post",
		data: { ajax: "ajax"},
		success: function(data) {
			if (data == "error") {
				$('#waitmsg').hide();
				$('#errmsg').show();
			} else if (data == "nopkg") {
				$('#waitmsg').hide();
				$('#nopkg').show();
				$('#errmsg').hide();
			} else {
				$('#pkgtbl').html(data);
				$('#legend').show();
			}
		},
		error: function() {
			$('#waitmsg').hide();
			$('#errmsg').show();
		}
	});

});
//]]>
</script>

<?php include("foot.inc")?>
