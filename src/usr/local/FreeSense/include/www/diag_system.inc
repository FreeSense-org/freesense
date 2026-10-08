<?php
/*
 * diag_system.inc
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

/*
 * The actions of Diagnostics > Reboot, Halt System and Reset States. The
 * callers check g_get('debug') (no reboot or halt while it is set).
 */

require_once("util.inc");
require_once("system.inc");
require_once("filter.inc");
require_once("notices.inc");

/* "Reboot with Filesystem Check" is offered except on ARM and on ZFS. */
function diag_reboot_fsck_available() {
	return ((php_uname('m') != 'arm') && !is_module_loaded("zfs.ko"));
}

/* The reboot methods of the page (mode => label) and their help lines. */
function diag_reboot_modes() {
	$help = array();
	$modeslist = array();

	$help[] = gettext('Select "Normal reboot" to reboot the system immediately.');
	$modeslist['reboot'] = gettext('Normal Reboot');

	if (diag_reboot_fsck_available()) {
		$help[] = gettext('Select "Reboot with Filesystem Check" to reboot and run filesystem check.');
		$modeslist['fsckreboot'] = gettext('Reboot with Filesystem Check');
	}

	$help[] = gettext('Select "Reroot" to stop processes, remount disks and re-run startup sequence.');
	$modeslist['reroot'] = gettext('Reroot');

	return array('modes' => $modeslist, 'help' => $help);
}

/*
 * Reboot the system with the page's method: "reboot" and "fsckreboot" start
 * the reboot in the background and return; "reroot" runs synchronously (it
 * does not return before the reroot). Returns false for an unknown method
 * (nothing is done). "fsckreboot" does nothing where it is not offered.
 */
function diag_reboot_run($rebootmode) {
	switch ($rebootmode) {
		case 'fsckreboot':
			if (diag_reboot_fsck_available()) {
				mwexec('/sbin/nextboot -e "freesense.fsck.force=5"');
				notify_all_remote(sprintf(gettext("%s is rebooting for a filesystem check now."), g_get('product_label')));
				system_reboot();
			}
			break;
		case 'reroot':
			notify_all_remote(sprintf(gettext("%s is rerooting now."), g_get('product_label')));
			system_reboot_sync(true);
			break;
		case 'reboot':
			notify_all_remote(sprintf(gettext("%s is rebooting now."), g_get('product_label')));
			system_reboot();
			break;
		default:
			return false;
	}
	return true;
}

/* Halt the system (in the background). */
function diag_halt_run() {
	notify_all_remote(sprintf(gettext("%s will shutdown and halt system now."), g_get('product_label')));
	system_halt();
}

/* The page offers to reset the source tracking table only with sticky connections enabled. */
function diag_resetstate_sourcetracking_available() {
	return config_path_enabled('system', 'lb_use_sticky');
}

/*
 * Diagnostics > States > Reset States: flush the state table and/or the
 * source tracking table. Returns the page's message ("" when nothing was
 * selected).
 */
function diag_resetstate_run($statetable, $sourcetracking) {
	$savemsg = "";

	if ($statetable) {
		filter_flush_state_table();
		if ($savemsg) {
			$savemsg .= " ";
		}
		$savemsg .= gettext("The state table has been flushed successfully.");
	}

	if ($sourcetracking) {
		mwexec("/sbin/pfctl -F Sources");
		if ($savemsg) {
			$savemsg .= " <br />";
		}
		$savemsg .= gettext("The source tracking table has been flushed successfully.");
	}

	return $savemsg;
}
