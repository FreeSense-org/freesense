<?php
/*
 * pkg_mgr_installed.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
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
require_once("package_catalog.inc");

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

/* Repository descriptions may carry <br /> markup; show them as plain text. */
function pkg_mgr_installed_plain_text($text) {
	$text = preg_replace('/<br\s*\/?>/i', ' ', (string)$text);
	return trim(preg_replace('/\s+/', ' ', strip_tags($text)));
}

function get_pkg_table() {
	$installed_packages = get_pkg_info('all', false, true);

	if (empty($installed_packages)) {
		print ("nopkg");
		exit;
	}

	$rows = '';
	$count = array('installed' => 0, 'current' => 0, 'updates' => 0, 'missing' => 0);

	foreach ($installed_packages as $pkg) {
		if (!$pkg['name']) {
			continue;
		}
		$meta = $pkg['freesense'];
		$name = $meta['display_name'];
		$count['installed']++;

		#check package version
		$upgradeavail = false;
		$missing = false;
		$vergetstr = "";

		if (isset($pkg['broken'])) {
			// package is configured, but does not exist in the system
			$missing = true;
			$state = 'missing';
			$status = gettext('Package is configured, but not installed!');
			$badge = fs_badge('error', gettext('Missing'), $status);
		} else if (isset($pkg['obsolete'])) {
			// package is installed, but no longer in the remote repository
			$missing = true;
			$state = 'missing';
			$status = gettext('Package is installed, but is not available on remote repository!');
			$badge = fs_badge('warn', gettext('Not in repository'), $status);
		} else if (isset($pkg['installed_version']) && isset($pkg['version'])) {
			$version_compare = pkg_version_compare($pkg['installed_version'], $pkg['version']);

			if ($version_compare == '>') {
				// we're running a newer version of the package
				$state = 'current';
				$status = sprintf(gettext('Newer than available (%s)'), $pkg['version']);
				$badge = fs_badge('info', gettext('Newer'), $status);
			} else if ($version_compare == '<') {
				// we're running an older version of the package
				$state = 'update';
				$status = sprintf(gettext('Upgrade available to %s'), $pkg['version']);
				$badge = fs_badge('warn', gettext('Update available'), $status);
				$upgradeavail = true;
				$vergetstr = '&from=' . rawurlencode($pkg['installed_version']) . '&to=' . rawurlencode($pkg['version']);
			} else if ($version_compare == '=') {
				// we're running the current version
				$state = 'current';
				$status = gettext('Up-to-date');
				$badge = fs_badge('pass', gettext('Up to date'));
			} else {
				$state = 'other';
				$status = gettext('Error comparing version');
				$badge = fs_badge('unknown', gettext('Unknown'), $status);
			}
		} else {
			// unknown available package version
			$state = 'other';
			$status = gettext('Unknown');
			$badge = fs_badge('unknown', gettext('Unknown'));
		}
		if ($missing) {
			$count['missing']++;
		} elseif ($upgradeavail) {
			$count['updates']++;
		} elseif ($state === 'current') {
			$count['current']++;
		}

		$rows .= '<tr data-fs-filter-state="' . $state . '" data-fs-filter-category="' . fs_h($meta['category']) . '">';

		/* package */
		$rows .= '<td><strong' . ($missing ? ' class="text-danger"' : '') . '>' . fs_h($name) . '</strong>' .
		    '<div class="fs-muted small">' . fs_h($meta['category']) . ' · ' . fs_h(ucfirst($meta['resource_profile'])) . '</div></td>';

		/* status */
		$rows .= '<td class="text-nowrap align-middle">' . $badge . '</td>';

		/* version: installed -> available */
		$rows .= '<td class="text-nowrap align-middle"><span class="fs-mono">';
		if (!g_get('disablepackagehistory')) {
			$rows .= '<a target="_blank" rel="noopener" title="' . fs_h(gettext("View changelog")) . '" href="' . fs_h($pkg['changeloglink']) . '">' .
			    fs_h($pkg['installed_version']) . '</a>';
		} else {
			$rows .= fs_h($pkg['installed_version']);
		}
		if ($upgradeavail) {
			$rows .= ' <span class="fs-muted" aria-hidden="true">→</span><span class="visually-hidden">' . fs_h(gettext('available')) . '</span> ' .
			    '<span class="fs-pkg-newver">' . fs_h($pkg['version']) . '</span>';
		}
		$rows .= '</span></td>';

		/* description and dependencies */
		$desc = pkg_mgr_installed_plain_text($pkg['desc']);
		$rows .= '<td><div class="fs-pkg-desc" title="' . fs_h($desc) . '">' . fs_h($desc) . '</div>';
		if (is_array($pkg['deps']) && count($pkg['deps'])) {
			$rows .= '<div class="fs-pkg-deps"><span class="fs-muted">' . fs_h(gettext('Requires')) . ':</span>';
			foreach ($pkg['deps'] as $pdep) {
				$dependency = pkg_dependency_presentation($pdep);
				$rows .= ' <a target="_blank" rel="noopener" class="fs-mono" href="' . fs_h($dependency['url']) . '">' . fs_h($dependency['label']) . '</a>';
			}
			$rows .= '</div>';
		}
		$rows .= '</td>';

		/* actions: manage, status, update / reinstall, info, remove (last) */
		$pkgarg = 'pkg=' . rawurlencode($pkg['name']);
		$actions = array();
		if (!empty($meta['configure_path'])) {
			$actions[] = array('custom', 'pkg_control.php?pkg=' . rawurlencode($pkg['shortname']), $name,
			    array('icon' => 'fa-sliders', 'label' => sprintf(gettext('Manage %s'), $name)));
		}
		if (!empty($meta['status_path']) && $meta['status_path'] !== $meta['configure_path']) {
			$actions[] = array('custom', $meta['status_path'], $name,
			    array('icon' => 'fa-chart-line', 'label' => sprintf(gettext('Status of %s'), $name)));
		}
		if ($upgradeavail) {
			$actions[] = array('custom', 'pkg_mgr_install.php?mode=reinstallpkg&' . $pkgarg . $vergetstr, $name,
			    array('icon' => 'fa-arrows-rotate', 'label' => sprintf(gettext("Update package %s"), $pkg['name']),
			    'confirm' => sprintf(gettext('Update %1$s to %2$s?'), $name, $pkg['version']),
			    'detail' => gettext('You review the update on the next page before it starts.'),
			    'confirm_action' => gettext('Continue')));
		} else if (!isset($pkg['obsolete'])) {
			$actions[] = array('custom', 'pkg_mgr_install.php?mode=reinstallpkg&' . $pkgarg, $name,
			    array('icon' => 'fa-retweet', 'label' => sprintf(gettext("Reinstall package %s"), $pkg['name']),
			    'confirm' => sprintf(gettext('Reinstall %s?'), $name),
			    'detail' => gettext('You review the reinstallation on the next page before it starts.'),
			    'confirm_action' => gettext('Continue')));
		}
		if (!g_get('disablepackageinfo') && $pkg['www'] && $pkg['www'] != 'UNKNOWN') {
			$actions[] = array('custom', $pkg['www'], $name,
			    array('icon' => 'fa-circle-info', 'label' => sprintf(gettext('More information about %s'), $name),
			    'attrs' => array('target' => '_blank', 'rel' => 'noopener')));
		}
		$actions[] = array('custom', 'pkg_mgr_install.php?mode=delete&' . $pkgarg, $name,
		    array('icon' => 'fa-trash-can', 'label' => sprintf(gettext("Remove package %s"), $pkg['name']),
		    'confirm' => sprintf(gettext('Remove %s?'), $name),
		    'detail' => gettext('You review the removal on the next page before it starts.'),
		    'confirm_action' => gettext('Continue'),
		    'attrs' => array('class' => 'fs-action fs-action--delete')));
		$rows .= '<td class="fs-col-actions">' . fs_row_actions($actions) . '</td>';
		$rows .= '</tr>' . "\n";
	}

	$pkgtbl = '<table id="pkgtable" class="table table-hover" data-sortable' .
	    ' data-pkg-installed="' . $count['installed'] . '"' .
	    ' data-pkg-current="' . $count['current'] . '"' .
	    ' data-pkg-updates="' . $count['updates'] . '"' .
	    ' data-pkg-missing="' . $count['missing'] . '">' . "\n";
	$pkgtbl .= '<thead><tr>' .
	    '<th data-fs-search>' . fs_h(gettext("Package")) . '</th>' .
	    '<th>' . fs_h(gettext("Status")) . '</th>' .
	    '<th>' . fs_h(gettext("Version")) . '</th>' .
	    '<th data-fs-search>' . fs_h(gettext("Description")) . '</th>' .
	    '<th class="fs-col-actions" data-sortable="false"><span class="visually-hidden">' . fs_h(gettext("Actions")) . '</span></th>' .
	    '</tr></thead>' . "\n";
	$pkgtbl .= '<tbody>' . "\n" . $rows . '</tbody>' . "\n";
	$pkgtbl .= '</table>' . "\n";

	return $pkgtbl;
}

$pgtitle = array(gettext("System"), gettext("Package Manager"), gettext("Installed Packages"));
$pglinks = array("", "@self", "@self");
include("head.inc");

fs_tabs('system-packages', 'pkg_mgr_installed.php');

$categories = array(gettext('All categories'));
foreach (freesense_package_catalog_categories() as $category) {
	$categories[$category] = $category;
}
?>

<style>
.fs-pkg-desc { display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; overflow: hidden; max-width: 46rem; }
.fs-pkg-deps { margin-top: .35rem; font-size: var(--fs-fs-xs); }
#pkgtable th { white-space: nowrap; vertical-align: bottom; }
#pkgtable td:not(.fs-col-actions) a { text-decoration: underline dotted; text-underline-offset: 2px; }
#pkgtable td:not(.fs-col-actions) a:not(:hover) { color: inherit; }
#pkgtable td .fs-pkg-deps a:not(:hover) { color: var(--fs-text-muted); }
.fs-pkg-newver { color: var(--fs-text-strong); font-weight: 600; }
.fs-pkg-loading { display: flex; align-items: center; gap: .6rem; padding: 1.25rem 1rem; color: var(--fs-text-muted); }
.fs-pkg-empty { display: flex; flex-direction: column; align-items: center; gap: .75rem; padding: 2.5rem 1rem; text-align: center; color: var(--fs-text-muted); }
.fs-pkg-empty > i { font-size: 2rem; }
.fs-tiles .fs-tile-value .fa-ellipsis { color: var(--fs-text-muted); }
</style>

<div class="fs-tiles" id="pkg-tiles">
	<div class="fs-tile"><div class="fs-tile-label"><?=gettext('Installed')?></div><div class="fs-tile-value" data-pkg-tile="installed"><i class="fa-solid fa-ellipsis fa-fade" aria-hidden="true"></i></div></div>
	<div class="fs-tile"><div class="fs-tile-label"><?=gettext('Up to date')?></div><div class="fs-tile-value" data-pkg-tile="current"><i class="fa-solid fa-ellipsis fa-fade" aria-hidden="true"></i></div></div>
	<div class="fs-tile"><div class="fs-tile-label"><?=gettext('Updates available')?></div><div class="fs-tile-value" data-pkg-tile="updates"><i class="fa-solid fa-ellipsis fa-fade" aria-hidden="true"></i></div></div>
	<div class="fs-tile"><div class="fs-tile-label"><?=gettext('Need attention')?></div><div class="fs-tile-value" data-pkg-tile="missing"><i class="fa-solid fa-ellipsis fa-fade" aria-hidden="true"></i></div></div>
</div>

<div class="panel panel-default fs-table" id="pkg-list">
<?php fs_table_toolbar([
	'title' => gettext('Installed packages'),
	'search' => gettext('Search installed packages…'),
	'noun' => gettext('packages'),
	'noun_one' => gettext('package'),
	'filters' => [
		'state' => [gettext('Any status'), 'current' => gettext('Up to date'), 'update' => gettext('Update available'), 'missing' => gettext('Need attention')],
		'category' => $categories,
	],
	'actions' => '<a class="btn btn-sm btn-outline-secondary" href="pkg_mgr.php"><i class="fa-solid fa-plus icon-embed-btn" aria-hidden="true"></i>' . fs_h(gettext('Available packages')) . '</a>',
]); ?>
	<div id="pkgtbl" class="panel-body table-responsive">
		<div id="waitmsg" class="fs-pkg-loading" role="status">
			<i class="fa-solid fa-gear fa-spin" aria-hidden="true"></i><?=gettext("Retrieving the list of installed packages…")?>
		</div>

		<div id="errmsg" style="display: none;">
			<?php print_info_box(gettext("Unable to retrieve package information."), 'danger', false); ?>
		</div>

		<div id="nopkg" class="fs-pkg-empty" style="display: none;">
			<i class="fa-solid fa-box-open" aria-hidden="true"></i>
			<span><?=gettext("There are no packages currently installed.")?></span>
			<a class="btn btn-sm btn-primary" href="pkg_mgr.php"><i class="fa-solid fa-plus icon-embed-btn" aria-hidden="true"></i><?=gettext('Browse available packages')?></a>
		</div>
	</div>
</div>

<script type="text/javascript">
//<![CDATA[

events.push(function() {

	// Retrieve the installed package table and enhance it (search, filters, count, sort).
	$.ajax({
		url: "/pkg_mgr_installed.php",
		type: "post",
		data: { ajax: "ajax"},
		success: function(data) {
			if (data == "error") {
				$('#waitmsg').hide();
				$('#errmsg').show();
				$('[data-pkg-tile]').text('-');
			} else if (data == "nopkg") {
				$('#waitmsg').hide();
				$('#nopkg').show();
				$('#errmsg').hide();
				$('[data-pkg-tile]').text('0');
			} else {
				$('#pkgtbl').html(data);
				var table = document.getElementById('pkgtable');
				$('[data-pkg-tile]').each(function () {
					$(this).text(table ? (table.getAttribute('data-pkg-' + this.getAttribute('data-pkg-tile')) || '0') : '-');
				});
				if (window.FreeSenseUI && window.FreeSenseUI.initTables) {
					window.FreeSenseUI.initTables(document.getElementById('pkg-list'));
				}
			}
		},
		error: function() {
			$('#waitmsg').hide();
			$('#errmsg').show();
			$('[data-pkg-tile]').text('-');
		}
	});

});
//]]>
</script>

<?php include("foot.inc")?>
