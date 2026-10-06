<?php
/*
 * tabs/system.inc
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

/* Tab groups for the System menu (loaded by fs_tab_groups(); labels are plain text). */

$fs_tab_groups['system-advanced'] = [
	[gettext('Admin Access'), 'system_advanced_admin.php'],
	[gettext('Firewall & NAT'), 'system_advanced_firewall.php'],
	[gettext('Networking'), 'system_advanced_network.php'],
	[gettext('Miscellaneous'), 'system_advanced_misc.php'],
	[gettext('System Tunables'), 'system_advanced_sysctl.php'],
	[gettext('Notifications'), 'system_advanced_notifications.php'],
];
