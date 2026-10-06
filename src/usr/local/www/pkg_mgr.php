<?php
/*
 * pkg_mgr.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
 * Copyright (c) 2013 Marcello Coutinho
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
##|*IDENT=page-system-packagemanager
##|*NAME=System: Package Manager
##|*DESCR=Allow access to the 'System: Package Manager' page.
##|*MATCH=pkg_mgr.php*
##|-PRIV

ini_set('max_execution_time', '0');

require_once("globals.inc");
require_once("guiconfig.inc");
require_once("pkg-utils.inc");
require_once("package_catalog.inc");

// if upgrade in progress, alert user
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
function pkg_mgr_plain_text($text) {
	$text = preg_replace('/<br\s*\/?>/i', ' ', (string)$text);
	return trim(preg_replace('/\s+/', ' ', strip_tags($text)));
}

// The content for the table of packages is created here and fetched by Ajax. This allows us to draw the page and display
// any required messages while the table is being downloaded/populated. On very small/slow systems, that can take a while
function get_pkg_table() {
	$pkg_info = get_pkg_info('all', true, false);

	if (!$pkg_info) {
		print("error");
		exit;
	}

	$rows = '';
	$count = array('available' => 0, 'installed' => 0, 'updates' => 0);

	foreach ($pkg_info as $index) {
		//AutoConfigBackup not to be installed >= v 2.4.4
		if ($index['shortname'] == "AutoConfigBackup") {
			continue;
		}

		$meta = $index['freesense'];
		$name = $meta['display_name'];
		$installed = isset($index['installed']) || isset($index['broken']);
		$update = isset($index['installed'], $index['installed_version'], $index['version']) &&
		    (pkg_version_compare($index['installed_version'], $index['version']) == '<');

		if ($update) {
			$state = 'update';
			$badge = fs_badge('warn', gettext('Update available'),
			    sprintf(gettext('Installed %1$s, available %2$s'), $index['installed_version'], $index['version']));
			$count['installed']++;
			$count['updates']++;
		} elseif ($installed) {
			$state = 'installed';
			$badge = fs_badge('pass', gettext('Installed'));
			$count['installed']++;
		} else {
			$state = 'available';
			$badge = fs_badge('idle', gettext('Not installed'));
			$count['available']++;
		}

		$rows .= '<tr data-fs-filter-category="' . fs_h($meta['category']) . '" data-fs-filter-state="' . $state . '">';

		/* package */
		$rows .= '<td><strong>' . fs_h($name) . '</strong>' .
		    '<div class="fs-muted small">' . fs_h($meta['category']) . ' · ' . fs_h(ucfirst($meta['resource_profile'])) . '</div></td>';

		/* version */
		$rows .= '<td class="fs-mono text-nowrap">';
		if (!g_get('disablepackagehistory')) {
			$rows .= '<a target="_blank" rel="noopener" title="' . fs_h(gettext("View changelog")) . '" href="' . fs_h($index['changeloglink']) . '">' . fs_h($index['version']) . '</a>';
		} else {
			$rows .= fs_h($index['version']);
		}
		$rows .= '</td>';

		/* description, capabilities and dependencies */
		$desc = pkg_mgr_plain_text($index['desc']);
		$rows .= '<td><div class="fs-pkg-desc" title="' . fs_h($desc) . '">' . fs_h($desc) . '</div>';
		if (!empty($meta['capabilities'])) {
			$rows .= '<div class="fs-chips fs-pkg-caps">';
			foreach ($meta['capabilities'] as $capability) {
				$rows .= '<span class="fs-chip fs-chip--muted">' . fs_h(str_replace('-', ' ', $capability)) . '</span>';
			}
			$rows .= '</div>';
		}
		if (is_array($index['deps']) && count($index['deps'])) {
			$rows .= '<div class="fs-pkg-deps"><span class="fs-muted">' . fs_h(gettext('Requires')) . ':</span>';
			foreach ($index['deps'] as $pdep) {
				$dependency = pkg_dependency_presentation($pdep);
				$rows .= ' <a target="_blank" rel="noopener" class="fs-mono" href="' . fs_h($dependency['url']) . '">' . fs_h($dependency['label']) . '</a>';
			}
			$rows .= '</div>';
		}
		$rows .= '</td>';

		/* status */
		$rows .= '<td class="text-nowrap">' . $badge . '</td>';

		/* actions */
		$actions = array();
		if (!$installed) {
			$actions[] = array('custom', 'pkg_mgr_install.php?pkg=' . rawurlencode($index['name']), $name,
			    array('icon' => 'fa-download', 'label' => sprintf(gettext('Review and install %s'), $name)));
		} else {
			$actions[] = array('custom', 'pkg_mgr_installed.php?q=' . rawurlencode($name), $name,
			    array('icon' => 'fa-list-check', 'label' => sprintf(gettext('Show %s in installed packages'), $name)));
		}
		if (!g_get('disablepackageinfo') && $index['pkginfolink'] && $index['pkginfolink'] != $index['www']) {
			$actions[] = array('custom', $index['pkginfolink'], $name,
			    array('icon' => 'fa-circle-info', 'label' => sprintf(gettext('More information about %s'), $name),
			    'attrs' => array('target' => '_blank', 'rel' => 'noopener')));
		}
		if (($index['www']) && ($index['www'] != "UNKNOWN")) {
			$actions[] = array('custom', $index['www'], $name,
			    array('icon' => 'fa-arrow-up-right-from-square', 'label' => sprintf(gettext('Visit the %s website'), $name),
			    'attrs' => array('target' => '_blank', 'rel' => 'noopener')));
		}
		$rows .= '<td class="fs-col-actions">' . fs_row_actions($actions) . '</td>';
		$rows .= '</tr>' . "\n";
	}

	$pkgtbl = '<table id="pkgtable" class="table table-hover" data-sortable' .
	    ' data-pkg-available="' . $count['available'] . '"' .
	    ' data-pkg-installed="' . $count['installed'] . '"' .
	    ' data-pkg-updates="' . $count['updates'] . '">' . "\n";
	$pkgtbl .= '<thead><tr>' .
	    '<th data-fs-search>' . fs_h(gettext("Package")) . '</th>' .
	    '<th>' . fs_h(gettext("Version")) . '</th>' .
	    '<th data-fs-search>' . fs_h(gettext("Description")) . '</th>' .
	    '<th>' . fs_h(gettext("Status")) . '</th>' .
	    '<th class="fs-col-actions" data-sortable="false"><span class="visually-hidden">' . fs_h(gettext("Actions")) . '</span></th>' .
	    '</tr></thead>' . "\n";
	$pkgtbl .= '<tbody>' . "\n" . $rows . '</tbody>' . "\n";
	$pkgtbl .= '</table>' . "\n";

	return ($pkgtbl);
}

$pgtitle = array(gettext("System"), gettext("Package Manager"), gettext("Available Packages"));
$pglinks = array("", "pkg_mgr_installed.php", "@self");
include("head.inc");

fs_tabs('system-packages', 'pkg_mgr.php');

$categories = array(gettext('All categories'));
foreach (freesense_package_catalog_categories() as $category) {
	$categories[$category] = $category;
}
?>

<style>
.fs-pkg-desc { display: -webkit-box; -webkit-box-orient: vertical; -webkit-line-clamp: 2; overflow: hidden; max-width: 46rem; }
.fs-pkg-caps { margin-top: .35rem; }
.fs-pkg-deps { margin-top: .35rem; font-size: var(--fs-fs-xs); }
#pkgtable th { white-space: nowrap; vertical-align: bottom; }
#pkgtable td:not(.fs-col-actions) a { text-decoration: underline dotted; text-underline-offset: 2px; }
#pkgtable td:not(.fs-col-actions) a:not(:hover) { color: inherit; }
#pkgtable td .fs-pkg-deps a:not(:hover) { color: var(--fs-text-muted); }
.fs-pkg-loading { display: flex; align-items: center; gap: .6rem; padding: 1.25rem 1rem; color: var(--fs-text-muted); }
.fs-tiles .fs-tile-value .fa-ellipsis { color: var(--fs-text-muted); }
</style>

<div class="fs-tiles" id="pkg-tiles">
	<div class="fs-tile"><div class="fs-tile-label"><?=gettext('Available to install')?></div><div class="fs-tile-value" data-pkg-tile="available"><i class="fa-solid fa-ellipsis fa-fade" aria-hidden="true"></i></div></div>
	<div class="fs-tile"><div class="fs-tile-label"><?=gettext('Installed')?></div><div class="fs-tile-value" data-pkg-tile="installed"><i class="fa-solid fa-ellipsis fa-fade" aria-hidden="true"></i></div></div>
	<div class="fs-tile"><div class="fs-tile-label"><?=gettext('Updates available')?></div><div class="fs-tile-value" data-pkg-tile="updates"><i class="fa-solid fa-ellipsis fa-fade" aria-hidden="true"></i></div></div>
</div>

<div class="panel panel-default fs-table" id="pkg-list">
<?php fs_table_toolbar([
	'title' => gettext('Packages'),
	'search' => gettext('Search names, descriptions, capabilities…'),
	'noun' => gettext('packages'),
	'noun_one' => gettext('package'),
	'filters' => [
		'category' => $categories,
		'state' => [gettext('Any status'), 'available' => gettext('Not installed'), 'installed' => gettext('Installed'), 'update' => gettext('Update available')],
	],
]); ?>
	<div id="pkgtbl" class="panel-body table-responsive">
		<div id="waitmsg" class="fs-pkg-loading" role="status">
			<i class="fa-solid fa-gear fa-spin" aria-hidden="true"></i><?=gettext("Retrieving the list of packages…")?>
		</div>

		<div id="errmsg" style="display: none;">
			<?php print_info_box(gettext("Unable to retrieve package information."), 'danger', false); ?>
		</div>
	</div>
</div>

<script type="text/javascript">
//<![CDATA[

events.push(function() {

	// Retrieve the package table and enhance it (search, filters, count, sort).
	$.ajax({
		url: "/pkg_mgr.php",
		type: "post",
		data: { ajax: "ajax"},
		success: function(data) {
			if (data == "error") {
				$('#waitmsg').hide();
				$('#errmsg').show();
				$('[data-pkg-tile]').text('-');
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

<?php include("foot.inc");
?>
