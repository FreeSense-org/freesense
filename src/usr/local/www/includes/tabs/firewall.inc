<?php
/*
 * tabs/firewall.inc
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

/* Tab groups for the Firewall menu (loaded by fs_tab_groups(); labels are plain text). */

$fs_tab_groups['firewall-aliases'] = [
	[gettext('IP'), 'firewall_aliases.php?tab=ip'],
	[gettext('Ports'), 'firewall_aliases.php?tab=port'],
	[gettext('URLs'), 'firewall_aliases.php?tab=url'],
	[gettext('All'), 'firewall_aliases.php?tab=all'],
];

$fs_tab_groups['firewall-nat'] = [
	[gettext('Port Forward'), 'firewall_nat.php'],
	[gettext('1:1'), 'firewall_nat_1to1.php'],
	[gettext('Outbound'), 'firewall_nat_out.php'],
	[gettext('NPt'), 'firewall_nat_npt.php'],
];

