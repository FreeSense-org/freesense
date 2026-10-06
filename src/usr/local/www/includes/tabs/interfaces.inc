<?php
/*
 * tabs/interfaces.inc
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
 * Tab groups for the Interfaces menu (loaded by fs_tab_groups(); labels are plain text).
 * Most used first: the tab bar moves the tail into "More" when it does not fit.
 */

$fs_tab_groups['interfaces'] = [
	[gettext('Interface Assignments'), 'interfaces_assign.php'],
	[gettext('Interface Groups'), 'interfaces_groups.php'],
	[gettext('VLANs'), 'interfaces_vlan.php'],
	[gettext('Bridges'), 'interfaces_bridge.php'],
	[gettext('LAGGs'), 'interfaces_lagg.php'],
	[gettext('Wireless'), 'interfaces_wireless.php'],
	[gettext('QinQs'), 'interfaces_qinq.php'],
	[gettext('PPPs'), 'interfaces_ppps.php'],
	[gettext('GREs'), 'interfaces_gre.php'],
	[gettext('GIFs'), 'interfaces_gif.php'],
	[gettext('VXLANs'), 'interfaces_vxlan.php'],
];
