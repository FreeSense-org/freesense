<?php
/*
 * system_update_settings.inc
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

require_once("config.gui.inc");
require_once("pkg-utils.inc");
require_once("util.inc");

// Functions included by system_update_settings.php (System > Update > Update Settings)

/*
 * Validate and save the Update Settings form ($post: the page's fields,
 * $repos: pkg_list_repos()). The firmware branch must be one of the listed
 * repositories; selecting one switches the package repository configuration
 * (pkg_switch_repo()). With $gitsync false the GitSync section (repository
 * URL, branch and sync options) is left exactly as stored: those fields run
 * code on the next upgrade and are only changed from the GUI page.
 * Returns the input errors and the page's success message.
 */
function system_update_settings_save(array $post, array $repos, $gitsync = true) {
	$input_errors = array();
	$savemsg = null;

	if (!empty($post['fwbranch']) &&
	    !in_array($post['fwbranch'], array_column($repos, 'name'), true)) {
		$input_errors[] = gettext('That firmware branch would downgrade the running system and cannot be selected.');
	}

	if (!$input_errors) {

		if ($post['disablecheck'] == "yes") {
			config_set_path('system/firmware/disablecheck', true);
		} elseif (config_path_enabled('system/firmware', 'disablecheck')) {
			config_del_path('system/firmware/disablecheck');
		}

		if ($gitsync) {
			if ($post['synconupgrade'] == "yes") {
				config_set_path('system/gitsync/synconupgrade', true);
			} elseif (config_path_enabled('system/gitsync', 'synconupgrade')) {
				config_del_path('system/gitsync/synconupgrade');
			}

			config_set_path('system/gitsync/repositoryurl', $post['repositoryurl']);
			config_set_path('system/gitsync/branch', $post['branch']);
		}

		foreach ($repos as $repo) {
			if ($repo['name'] == $post['fwbranch']) {
				config_set_path('system/pkg_repo_conf_path', $repo['name']);
				pkg_switch_repo();
				break;
			}
		}

		if ($gitsync) {
			if ($post['minimal'] == "yes") {
				config_set_path('system/gitsync/minimal', true);
			} else {
				config_del_path('system/gitsync/minimal');
			}

			if ($post['diff'] == "yes") {
				config_set_path('system/gitsync/diff', true);
			} else {
				config_del_path('system/gitsync/diff');
			}

			if ($post['show_files'] == "yes") {
				config_set_path('system/gitsync/show_files', true);
			} else {
				config_del_path('system/gitsync/show_files');
			}

			if ($post['show_command'] == "yes") {
				config_set_path('system/gitsync/show_command', true);
			} else {
				config_del_path('system/gitsync/show_command');
			}

			if ($post['dryrun'] == "yes") {
				config_set_path('system/gitsync/dryrun', true);
			} else {
				config_del_path('system/gitsync/dryrun');
			}
		}

		if (empty(config_get_path('system/firmware'))) {
			config_del_path('system/firmware');
		}
		if ($gitsync && empty(config_get_path('system/gitsync'))) {
			config_del_path('system/gitsync');
		}
		write_config(gettext("Saved system update settings."));

		$savemsg = gettext("Changes have been saved successfully");
	}

	return array('input_errors' => $input_errors, 'savemsg' => $savemsg);
}
