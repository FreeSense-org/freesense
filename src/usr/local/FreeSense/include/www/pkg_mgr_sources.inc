<?php
/*
 * pkg_mgr_sources.inc
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
 * The Source column and filter of the Available Packages and Installed Packages
 * lists. FreeSense's own packages are "Official" and are all the lists show on
 * load; packages from the repositories added under Package Manager >
 * Repositories carry the repository's name and are listed when the Source
 * filter is switched to it.
 */

require_once('pkg_custom_repos.inc');

const PKG_MGR_SOURCE_OFFICIAL = 'official';
/* The most packages one source lists at a time (the FreeBSD repository has tens of thousands). */
const PKG_MGR_SOURCE_LIMIT = 300;

function pkg_mgr_official_badge() {
	return fs_badge('pass', gettext('Official'), gettext('A FreeSense package from the signed FreeSense repository'));
}

/* The badge of a non-official source (FreeBSD's repository is shown as "FreeBSD"). */
function pkg_mgr_source_badge($source) {
	$label = ($source === 'FreeBSD-ports') ? 'FreeBSD' : $source;
	return fs_badge('idle', $label, sprintf(gettext('From the %s repository, not tested with FreeSense'), $source));
}

/*
 * The Source filter for a toolbar: array(label, 'official' => 'Official', name =>
 * name ...), or null when there is only the official source. $with_all adds the
 * "All sources" choice (the Installed list; Available lists one source at a time).
 */
function pkg_mgr_source_filter($with_all) {
	$sources = pkgrepo_source_options();
	if (empty($sources)) {
		return null;
	}
	/* the first choice is the toolbar's "no filter" entry: all sources on the Installed list; on the Available list it is the official packages, the others are loaded one at a time */
	$filter = $with_all ? array(gettext('All sources'), PKG_MGR_SOURCE_OFFICIAL => gettext('Official')) : array(gettext('Official'));
	foreach ($sources as $name => $label) {
		$filter[$name] = $label;
	}
	return $filter;
}

/* The status badge of a package from another source. */
function pkg_mgr_source_status_badge($state, $title = null) {
	switch ($state) {
	case 'update':
		return fs_badge('warn', gettext('Update available'), $title);
	case 'installed':
	case 'current':
		return fs_badge('pass', ($state === 'current') ? gettext('Up to date') : gettext('Installed'), $title);
	case 'missing':
		return fs_badge('warn', gettext('Not in repository'), $title);
	}
	return fs_badge('idle', gettext('Not installed'), $title);
}

/* One row of the Available Packages list for a package of $source. */
function pkg_mgr_available_source_row($source, array $p) {
	list($name, $version, $comment, $state, $installed_version) = $p;
	$actions = array();
	if ($state === 'available') {
		$actions[] = array('custom', 'pkg_mgr_install.php?mode=installcustom&pkg=' . rawurlencode($name), $name,
		    array('icon' => 'fa-download', 'label' => sprintf(gettext('Review and install %s'), $name)));
	} elseif ($state === 'update') {
		$actions[] = array('custom', 'pkg_mgr_install.php?mode=upgradecustom&pkg=' . rawurlencode($name), $name,
		    array('icon' => 'fa-arrows-rotate', 'label' => sprintf(gettext('Review and update %s'), $name)));
	} else {
		$actions[] = array('custom', 'pkg_mgr_installed.php?q=' . rawurlencode($name), $name,
		    array('icon' => 'fa-list-check', 'label' => sprintf(gettext('Show %s in installed packages'), $name)));
	}
	$title = ($state === 'update') ? sprintf(gettext('Installed %1$s, available %2$s'), $installed_version, $version) : null;
	return '<tr class="fs-source-row" data-fs-filter-category="" data-fs-filter-state="' . fs_h($state) .
	    '" data-fs-filter-source="' . fs_h($source) . '">' .
	    '<td><strong>' . fs_h($name) . '</strong></td>' .
	    '<td>' . pkg_mgr_source_badge($source) . '</td>' .
	    '<td class="fs-mono text-nowrap">' . fs_h($version) . '</td>' .
	    '<td><div class="fs-pkg-desc" title="' . fs_h($comment) . '">' . fs_h($comment) . '</div></td>' .
	    '<td class="text-nowrap">' . pkg_mgr_source_status_badge($state, $title) . '</td>' .
	    '<td class="fs-col-actions">' . fs_row_actions($actions) . '</td></tr>' . "\n";
}

/*
 * The rows of the Installed Packages list for the packages that came from
 * another source: array(html, count). Same columns as the official rows.
 */
function pkg_mgr_installed_source_rows() {
	$html = '';
	$count = 0;
	foreach (pkgrepo_installed_foreign_detail() as $p) {
		list($name, $version, $source, $available, $state, $comment) = $p;
		$actions = array();
		if ($state === 'update') {
			$actions[] = array('custom', 'pkg_mgr_install.php?mode=upgradecustom&pkg=' . rawurlencode($name), $name,
			    array('icon' => 'fa-arrows-rotate', 'label' => sprintf(gettext('Update package %s'), $name)));
		}
		$actions[] = array('custom', 'pkg_mgr_install.php?mode=removecustom&pkg=' . rawurlencode($name), $name,
		    array('icon' => 'fa-trash-can', 'label' => sprintf(gettext('Remove package %s'), $name),
		    'attrs' => array('class' => 'fs-action fs-action--delete')));
		$version_cell = '<span class="fs-mono">' . fs_h($version);
		if ($state === 'update') {
			$version_cell .= ' <span class="fs-muted" aria-hidden="true">→</span><span class="visually-hidden">' . fs_h(gettext('available')) .
			    '</span> <span class="fs-pkg-newver">' . fs_h($available) . '</span>';
		}
		$version_cell .= '</span>';
		$html .= '<tr class="fs-source-row" data-fs-filter-category="" data-fs-filter-state="' . fs_h($state) .
		    '" data-fs-filter-source="' . fs_h($source) . '">' .
		    '<td><strong>' . fs_h($name) . '</strong></td>' .
		    '<td>' . pkg_mgr_source_badge($source) . '</td>' .
		    '<td class="text-nowrap align-middle">' . pkg_mgr_source_status_badge($state) . '</td>' .
		    '<td class="text-nowrap align-middle">' . $version_cell . '</td>' .
		    '<td><div class="fs-pkg-desc" title="' . fs_h($comment) . '">' . fs_h($comment) . '</div></td>' .
		    '<td class="fs-col-actions">' . fs_row_actions($actions) . '</td></tr>' . "\n";
		$count++;
	}
	return array($html, $count);
}
