<?php
/*
 * system_fleet.php
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
 * System > Central Management: whether a FreeSense Fleet controller manages
 * this firewall, and what it keeps here. This page's privilege also grants
 * the /api/v1/management endpoints (restapi/routes_management.inc).
 */

##|+PRIV
##|*IDENT=page-system-fleet
##|*NAME=System: Central Management
##|*DESCR=Allow access to the 'System: Central Management' page and the matching REST API endpoints, used by a FreeSense Fleet controller.
##|*WARN=standard-warning-root
##|*MATCH=system_fleet.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("fleet_node.inc");
require_once("system_boot_environments.inc");

$pgtitle = array(gettext("System"), gettext("Central Management"));

/* The replicas this box keeps, as one file for Recover fleet on a new controller (they stay encrypted). */
if (($_POST['vault_download'] ?? '') === '1') {
	$name = 'fleet-vault-' . preg_replace('/[^A-Za-z0-9.-]/', '_', (string)config_get_path('system/hostname', 'firewall')) . '-' . date('Ymd-His') . '.fsfa';
	header('Content-Type: application/gzip');
	header('Content-Disposition: attachment; filename="' . $name . '"');
	header('Cache-Control: no-store');
	log_error(sprintf(gettext('Fleet: the backup vault was downloaded by %s.'), $_SESSION['Username'] ?? '?'));
	fleet_vault_export(fopen('php://output', 'w'));
	exit;
}

$m = fleet_node_membership();
$bundle = fleet_policy_bundle_read();
$vault = fleet_vault_usage($m);
$be = be_load();
$protected = fleet_rollback_protection($be['available'] && $be['compatible'], (array)config_get_path('system/bootenv', []));
$methods = array('wireguard' => gettext('Fleet WireGuard'), 'tailscale' => gettext('Tailscale'), 'direct' => gettext('Direct'));
$roles = array('monitor' => gettext('Monitor only'), 'managed' => gettext('Managed'));

include("head.inc");

if (!$m['enrolled']):
?>
<div class="panel panel-default">
	<div class="fs-tool-empty">
		<i class="fa-solid fa-network-wired" aria-hidden="true"></i>
		<span><?=gettext('This firewall is not managed by a FreeSense Fleet controller.')?></span>
	</div>
</div>
<?php
else:
	fs_summary_card([
		'icon' => 'fa-network-wired',
		'title' => $m['controller']['url'],
		'subtitle' => gettext('Fleet controller'),
		'badges' => [fs_badge('online', gettext('Managed'))],
		'meta' => $m['node_id'],
		'label' => gettext('Fleet membership'),
		'facts' => [
			[gettext('Connection'), $methods[$m['method']] ?? ''],
			[gettext('Role'), $roles[$m['role']]],
			[gettext('Overlay address'), $m['overlay_address'], 'mono' => true],
			[gettext('Controller fingerprint'), $m['controller']['fingerprint'], 'mono' => true],
			[gettext('Joined'), ($m['joined'] === null) ? '' : date('Y-m-d H:i', $m['joined'])],
		],
	]);
?>
<div class="fs-tiles">
<?php
	fs_tile(gettext('Policy bundle'), ($bundle === null) ? gettext('None') : sprintf(gettext('Version %d'), (int)$bundle['bundle']['version']),
	    null, ($bundle === null) ? null : sprintf(gettext('Received %s'), date('Y-m-d H:i', (int)$bundle['received'])));
	fs_tile(gettext('Backup replicas'), $vault['replicas'], null,
	    sprintf(gettext('%1$s of %2$s (%3$d%% of free space)'), format_bytes($vault['used_bytes']), format_bytes($vault['quota_bytes']),
	    $vault['quota_pct']));
	fs_tile(gettext('Rollback protection'), $protected ? gettext('Yes') : gettext('No'), $protected ? null : 'warn',
	    $protected ? gettext('ZFS boot environments') : gettext('A failed update cannot roll back by itself. ZFS is recommended.'));
?>
</div>
<?php
endif;
if ($vault['replicas'] > 0):
?>
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('Fleet backup copies')?></h2></div>
	<div class="panel-body">
		<p><?=sprintf(gettext('This firewall keeps %1$d encrypted backup copies for its fleet (%2$s). It cannot read them. If the controller is lost, download them here and use Recover fleet on a new controller with the fleet\'s recovery key.'),
		    $vault['replicas'], format_bytes($vault['used_bytes']))?></p>
		<form method="post">
			<button class="btn btn-sm btn-outline-secondary" type="submit" name="vault_download" value="1"><i class="fa-solid fa-download icon-embed-btn" aria-hidden="true"></i><?=gettext('Download the copies (.fsfa)')?></button>
		</form>
	</div>
</div>
<?php
endif;

include("foot.inc");
