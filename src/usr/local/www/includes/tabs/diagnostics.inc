<?php
/*
 * tabs/diagnostics.inc
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

/* Tab groups for the Diagnostics menu (loaded by fs_tab_groups(); labels are plain text). */

$fs_tab_groups['diagnostics-backup'] = [
	[gettext('Backup & Restore'), 'diag_backup.php'],
	[gettext('Configuration History'), 'diag_confbak.php'],
	[gettext('Remote Backup'), 'diag_backup_remote.php'],
];

$fs_tab_groups['diagnostics-states'] = [
	[gettext('States'), 'diag_dump_states.php'],
	[gettext('Source Tracking'), 'diag_dump_states_sources.php', config_path_enabled('system', 'lb_use_sticky')],
	[gettext('Reset States'), 'diag_resetstate.php'],
];
