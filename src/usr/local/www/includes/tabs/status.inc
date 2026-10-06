<?php
/*
 * tabs/status.inc
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

/* Tab groups for the Status menu (loaded by fs_tab_groups(); labels are plain text). */

$fs_tab_groups['status-gateways'] = [
	[gettext('Gateways'), 'status_gateways.php'],
	[gettext('Gateway Groups'), 'status_gateway_groups.php'],
];

$fs_tab_groups['status-ipsec'] = [
	[gettext('Overview'), 'status_ipsec.php'],
	[gettext('Leases'), 'status_ipsec_leases.php'],
	[gettext('SADs'), 'status_ipsec_sad.php'],
	[gettext('SPDs'), 'status_ipsec_spd.php'],
];

/* fs_tabs(..., ['zone' => $cpzone]) */
$fs_tab_groups['status-captiveportal'] = [
	[gettext('Active Users'), 'status_captiveportal.php'],
	[gettext('Active Vouchers'), 'status_captiveportal_vouchers.php'],
	[gettext('Voucher Rolls'), 'status_captiveportal_voucher_rolls.php'],
	[gettext('Test Vouchers'), 'status_captiveportal_test.php'],
	[gettext('Expire Vouchers'), 'status_captiveportal_expire.php'],
];
