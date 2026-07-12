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

// The content for the table of packages is created here and fetched by Ajax. This allows us to draw the page and display
// any required messages while the table is being downloaded/populated. On very small/slow systems, that can take a while
function get_pkg_table() {
	$pkg_info = get_pkg_info('all', true, false);

	if (!$pkg_info) {
		print("error");
		exit;
	}

	$pkgtbl = 	'<table id="pkgtable" class="table table-striped table-hover align-middle">' . "\n";
	$pkgtbl .= 		'<thead>' . "\n";
	$pkgtbl .= 			'<tr>' . "\n";
	$pkgtbl .= 				'<th>' . gettext("Name") . "</th>\n";
	$pkgtbl .= 				'<th>' . gettext("Version") . "</th>\n";
	$pkgtbl .= 				'<th>' . gettext("Description") . "</th>\n";
	$pkgtbl .= 				'<th></th>' . "\n";
	$pkgtbl .= 			'</tr>' . "\n";
	$pkgtbl .= 		'</thead>' . "\n";
	$pkgtbl .= 		'<tbody>' . "\n";

	foreach ($pkg_info as $index) {
		//AutoConfigBackup not to be installed >= v 2.4.4
		if (isset($index['installed']) || ($index['shortname'] == "AutoConfigBackup")) {
			continue;
		}

		$meta = $index['freesense'];
		$category = htmlspecialchars($meta['category']);
		$searchtext = htmlspecialchars(strtolower($meta['display_name'] . ' ' . $index['desc'] . ' ' . implode(' ', $meta['capabilities'])));
		$pkgtbl .= 	'<tr data-category="' . $category . '" data-search="' . $searchtext . '">' . "\n";
		$pkgtbl .= 	'<td>' . "\n";

		if (($index['www']) && ($index['www'] != "UNKNOWN")) {
			$pkgtbl .= 	'<a title="' . gettext("Visit official website") . '" target="_blank" href="' . htmlspecialchars($index['www']) . '">' . "\n";
			$pkgtbl .= htmlspecialchars($meta['display_name']) . '</a>' . "\n";
		} else {
			$pkgtbl .= htmlspecialchars($meta['display_name']);
		}
		$pkgtbl .= '<div class="small text-body-secondary">' . $category .
		    ' · ' . htmlspecialchars(ucfirst($meta['resource_profile'])) . '</div>';
		$pkgtbl .= 	'</td>' . "\n";
		$pkgtbl .= 	'<td>' . "\n";

		if (!g_get('disablepackagehistory')) {
			$pkgtbl .= '<a target="_blank" title="' . gettext("View changelog") . '" href="' . htmlspecialchars($index['changeloglink']) . '">' . "\n";
			$pkgtbl .= htmlspecialchars($index['version']) . '</a>' . "\n";
		} else {
			$pkgtbl .= htmlspecialchars($index['version']);
		}

		$pkgtbl .= 	'</td>' . "\n";
		$pkgtbl .= 	'<td>' . "\n";
		$pkgtbl .= 		$index['desc'];
		if (!empty($meta['capabilities'])) {
			$pkgtbl .= '<div class="mt-2">';
			foreach ($meta['capabilities'] as $capability) {
				$pkgtbl .= '<span class="badge text-bg-secondary me-1">' .
				    htmlspecialchars(str_replace('-', ' ', $capability)) . '</span>';
			}
			$pkgtbl .= '</div>';
		}

		if (is_array($index['deps']) && count($index['deps'])) {
			$pkgtbl .= 	'<br /><br />' . gettext("Package Dependencies") . ":<br/>\n";

			foreach ($index['deps'] as $pdep) {
				$pkgtbl .= '<a target="_blank" href="https://freshports.org/' . $pdep['origin'] . '">&nbsp;<i class="fa-solid fa-paperclip"></i> ' . basename($pdep['origin']) . '-' . $pdep['version'] . '</a>&emsp;' . "\n";
			}

			$pkgtbl .= "\n";
		}

		$pkgtbl .= 	'</td>' . "\n";
		$pkgtbl .= '<td>' . "\n";
		$pkgtbl .= '<a title="' . gettext("Review and install") . '" href="pkg_mgr_install.php?pkg=' . rawurlencode($index['name']) . '" class="btn btn-success btn-sm"><i class="fa-solid fa-plus icon-embed-btn"></i>' . gettext('Review') . '</a>' . "\n";

		if (!g_get('disablepackageinfo') && $index['pkginfolink'] && $index['pkginfolink'] != $index['www']) {
			$pkgtbl .= '<a target="_blank" title="' . gettext("View more information") . '" href="' . htmlspecialchars($index['pkginfolink']) . '" class="btn btn-secondary btn-sm">info</a>' . "\n";
		}

		$pkgtbl .= 	'</td>' . "\n";
		$pkgtbl .= 	'</tr>' . "\n";
	}

	$pkgtbl .= 	'</tbody>' . "\n";
	$pkgtbl .= '</table>' . "\n";

	return ($pkgtbl);
}

$pgtitle = array(gettext("System"), gettext("Package Manager"), gettext("Available Packages"));
$pglinks = array("", "pkg_mgr_installed.php", "@self");
include("head.inc");

$tab_array = array();
$tab_array[] = array(gettext("Installed Packages"), false, "pkg_mgr_installed.php");
$tab_array[] = array(gettext("Available Packages"), true, "pkg_mgr.php");
display_top_tabs($tab_array);
?>
<div class="card mb-3" id="search-panel">
	<div class="card-header">
		<h2 class="h5 mb-0">
			<?=gettext('Search')?>
			<span class="widget-heading-icon float-end">
				<a data-bs-toggle="collapse" href="#search-panel_panel-body">
					<i class="fa-solid fa-plus-circle"></i>
				</a>
			</span>
		</h2>
	</div>
	<div id="search-panel_panel-body" class="card-body collapse show">
		<div class="row mb-3">
			<label class="col-sm-2 col-form-label">
				<?=gettext("Search term")?>
			</label>
			<div class="col-sm-5"><input class="form-control" name="searchstr" id="searchstr" type="text"/></div>
			<div class="col-sm-2">
				<select id="category" class="form-control">
					<option value=""><?=gettext("All categories")?></option>
					<?php foreach (freesense_package_catalog_categories() as $category): ?>
					<option value="<?=htmlspecialchars($category)?>"><?=htmlspecialchars($category)?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="col-sm-3">
				<a id="btnsearch" title="<?=gettext("Search")?>" class="btn btn-primary btn-sm"><i class="fa-solid fa-search icon-embed-btn"></i><?=gettext("Search")?></a>
				<a id="btnclear" title="<?=gettext("Clear")?>" class="btn btn-info btn-sm"><i class="fa-solid fa-undo icon-embed-btn"></i><?=gettext("Clear")?></a>
			</div>
			<div class="col-sm-10 offset-sm-2">
				<span class="help-block"><?=gettext('Search package names, descriptions, and capabilities. Search text is treated literally.')?></span>
			</div>
		</div>
	</div>
</div>

<div class="card mb-3">
	<div class="card-header"><h2 class="h5 mb-0"><?=gettext('Packages')?></h2></div>
	<div id="pkgtbl" class="card-body table-responsive">
		<div id="waitmsg">
			<?php print_info_box(gettext("Please wait while the list of packages is retrieved and formatted.") . '&nbsp;<i class="fa-solid fa-cog fa-spin"></i>'); ?>
		</div>

		<div id="errmsg" style="display: none;">
			<?php print_info_box("<ul><li>" . gettext("Unable to retrieve package information.") . "</li></ul>", 'danger'); ?>
		</div>
	</div>
</div>

<script type="text/javascript">
//<![CDATA[

events.push(function() {

	// Initial state & toggle icons of collapsed panel
	$('.card-header a[data-bs-toggle="collapse"]').each(function (idx, el) {
		var body = $(el).parents('.card').children('.card-body')
		var isOpen = body.hasClass('show');

		$(el).children('i').toggleClass('fa-plus-circle', !isOpen);
		$(el).children('i').toggleClass('fa-minus-circle', isOpen);

		body.on('shown.bs.collapse', function() {
			$(el).children('i').toggleClass('fa-minus-circle', true);
			$(el).children('i').toggleClass('fa-plus-circle', false);
		});
	});

	// Make these controls plain buttons
	$("#btnsearch").prop('type', 'button');
	$("#btnclear").prop('type', 'button');

	// Search for a term in the package name and/or description
	function filterPackages() {
		var searchstr = $('#searchstr').val().trim().toLowerCase();
		var category = $('#category').val();
		$("#pkgtable tbody tr").each(function () {
			var matchesText = !searchstr || ($(this).data('search') || '').indexOf(searchstr) !== -1;
			var matchesCategory = !category || $(this).data('category') === category;
			$(this).toggle(matchesText && matchesCategory);
		});
	}

	$("#btnsearch").click(filterPackages);
	$("#category").on('change', filterPackages);

	// Clear the search term and unhide all rows (that were hidden during a previous search)
	$("#btnclear").click(function() {
		var table = $("table tbody");

		$('#searchstr').val("");
		$('#category').val('');
		filterPackages();
	});

	// Hitting the enter key will do the same as clicking the search button
	$("#searchstr").on("keyup", function (event) {
	    if (event.keyCode == 13) {
	        $("#btnsearch").get(0).click();
	    }
	});

	// Retrieve the table formatted package information and display it in the "Packages" panel
	// (Or display an appropriate error message)
	var ajaxRequest;

	$.ajax({
		url: "/pkg_mgr.php",
		type: "post",
		data: { ajax: "ajax"},
		success: function(data) {
			if (data == "error") {
				$('#waitmsg').hide();
				$('#errmsg').show();
			} else {
				$('#pkgtbl').html(data);
				$('#search-panel').show();
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

<?php include("foot.inc");
?>
