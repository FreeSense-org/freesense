<?php
/*
 * diag_backup.php
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

##|+PRIV
##|*IDENT=page-diagnostics-backup-restore
##|*NAME=Diagnostics: Backup & Restore
##|*DESCR=Allow access to the 'Diagnostics: Backup & Restore' page.
##|*WARN=standard-warning-root
##|*MATCH=diag_backup.php*
##|-PRIV

/* Allow additional execution time 0 = no limit. */
ini_set('max_execution_time', '0');
ini_set('max_input_time', '0');

/* omit no-cache headers because it confuses IE with file downloads */
$omit_nocacheheaders = true;
require_once("guiconfig.inc");
require_once("backup.inc");

$rrddbpath = "/var/db/rrd";
$rrdtool = "/usr/bin/nice -n20 /usr/local/bin/rrdtool";

if ($_POST['apply']) {
	ob_flush();
	flush();
	clear_subsystem_dirty("restore");
	exit;
}

if ($_POST) {
	if ($_POST['package_quarantine_download']) {
		$path = freesense_package_restore_quarantine_path(
		    (string)($_POST['quarantine_id'] ?? ''));
		if ($path === null) {
			$input_errors[] = gettext('The selected quarantine record is invalid.');
		} else {
			send_user_download('data', file_get_contents($path), basename($path));
			exit;
		}
	} else if ($_POST['package_quarantine_delete']) {
		if (freesense_package_restore_delete_quarantine(
		    (string)($_POST['quarantine_id'] ?? ''))) {
			$savemsg = gettext('The package-settings quarantine record was deleted.');
		} else {
			$input_errors[] = gettext('The quarantine record could not be deleted.');
		}
	} else if ($_POST['package_restore_retry']) {
		touch("{$g['conf_path']}/needs_package_sync");
		mwexec_bg("{$g['etc_path']}/rc.package_reinstall_all");
		$savemsg = gettext('Package restore reconciliation was started in the background.');
	} else if ($_POST['package_restore_apply']) {
		$staged = freesense_package_restore_apply_preview(
		    (string)($_POST['package_restore_token'] ?? ''),
		    $_POST['restore_packages'] ?? array());
		$input_errors = $staged['input_errors'];
		if (empty($input_errors)) {
			$previous_pending = freesense_package_restore_read_json(
			    freesense_package_restore_pending_path());
			$saved = freesense_package_restore_save(
			    $staged['result'], 'webgui-restore');
			if ($saved === false) {
				$input_errors[] = gettext(
				    'Package settings could not be staged safely; the restore was not applied.');
			} else {
				$restore_post = array(
					'restore' => 'restore',
					'restorearea' => $staged['restorearea'],
					'package_restore_prepared' => true,
				);
				$restore_files = array('conffile' => array(
					'tmp_name' => $staged['path'],
				));
				$execpost_return = execPost($restore_post, $restore_files, false);
				$input_errors = $execpost_return['input_errors'];
				$savemsg = $execpost_return['savemsg'];
				if (empty($input_errors)) {
					touch("{$g['conf_path']}/needs_package_sync");
					mark_subsystem_dirty("restore");
					@unlink($staged['path']);
					@unlink($staged['metadata_path']);
					$savemsg .= ' ' . sprintf(gettext(
					    '%1$d package(s) are pending verification; %2$d package setting set(s) were quarantined.'),
					    $saved['pending'], $saved['quarantined']);
				} else {
					/*
					 * The package state was staged before config_install() so
					 * settings cannot be lost if the restore succeeds. Roll it
					 * back only when execPost reports that the restore failed.
					 */
					if (is_array($previous_pending)) {
						freesense_package_restore_atomic_json(
						    freesense_package_restore_pending_path(),
						    $previous_pending);
					} else {
						@unlink(freesense_package_restore_pending_path());
					}
					if (is_string($saved['quarantine_path'] ?? null)) {
						@unlink($saved['quarantine_path']);
					}
				}
			}
		}
	} else if ($_POST['remote_restore']) {
		/* A full restore of a backup fetched from a remote backup target. It
		 * goes through the same package-restore preview as an upload. */
		require_once('remote_backup.inc');
		list(, $remote_target) = remote_backup_find_target((string)($_POST['target'] ?? ''));
		$remote_name = (string)($_POST['name'] ?? '');
		if ($remote_target === null) {
			$input_errors[] = gettext('The remote backup target no longer exists.');
		} else {
			$fetched = remote_backup_fetch($remote_target, $remote_name);
			if (isset($fetched['error'])) {
				$input_errors[] = sprintf(gettext('Could not download %1$s: %2$s'),
				    htmlspecialchars($remote_name), htmlspecialchars($fetched['error']));
			} else {
				$old_umask = umask(0077);
				$remote_tmp = tempnam('/tmp', 'remote-restore-');
				umask($old_umask);
				file_put_contents($remote_tmp, $fetched['data']);
				$package_restore_preview = freesense_package_restore_create_preview(array(
					'restore' => 'restore',
					'restorearea' => '',
					'decrypt' => 'yes',
					'decrypt_password' => config_get_path('remotebackup/passphrase', ''),
				), array(), $remote_tmp);
				@unlink($remote_tmp);
				$input_errors = $package_restore_preview['input_errors'];
				if (empty($input_errors)) {
					$savemsg = sprintf(gettext('Downloaded %s from the remote backup target. Review the packages below and apply the restore.'),
					    htmlspecialchars($remote_name));
				}
			}
		}
	} else if ($_POST['restore'] &&
	    (empty($_POST['restorearea']) ||
	    $_POST['restorearea'] === 'installedpackages')) {
		$package_restore_preview =
		    freesense_package_restore_create_preview($_POST, $_FILES);
		$input_errors = $package_restore_preview['input_errors'];
	} else if ($_POST['reinstallpackages']) {
		header("Location: pkg_mgr_install.php?mode=reinstallall");
		exit;
	} else if ($_POST['clearpackagelock']) {
		clear_subsystem_dirty('packagelock');
		$savemsg = "Package lock cleared.";
	} else {
		$execpost_return = execPost($_POST, $_FILES);
		$input_errors = $execpost_return['input_errors'];
		$savemsg = $execpost_return['savemsg'];
	}

}

$id = rand() . '.' . time();

$mth = ini_get('upload_progress_meter.store_method');
$dir = ini_get('upload_progress_meter.file.filename_template');

function build_area_list($showall) {
	$areas = array(
		"aliases" => gettext("Aliases"),
		"captiveportal" => gettext("Captive Portal"),
		"voucher" => gettext("Captive Portal Vouchers"),
		"widgets" => gettext("Dashboard Widgets"),
		"dnsmasq" => gettext("DNS Forwarder"),
		"unbound" => gettext("DNS Resolver"),
		"dhcpd" => gettext("DHCP Server"),
		"dhcpdv6" => gettext("DHCPv6 Server"),
		"dyndnses" => gettext("Dynamic DNS"),
		"filter" => gettext("Firewall Rules"),
		"interfaces" => gettext("Interfaces"),
		"ipsec" => gettext("IPSEC"),
		"dnshaper" => gettext("Limiters"),
		"nat" => gettext("NAT"),
		"openvpn" => gettext("OpenVPN"),
		"installedpackages" => gettext("Package Manager"),
		"rrddata" => gettext("RRD Data"),
		"cron" => gettext("Scheduled Tasks"),
		"syslog" => gettext("Syslog"),
		"system" => gettext("System"),
		"staticroutes" => gettext("Static routes"),
		"sysctl" => gettext("System tunables"),
		"snmpd" => gettext("SNMP Server"),
		"shaper" => gettext("Traffic Shaper"),
		"vlans" => gettext("VLANS"),
		"wol" => gettext("Wake-on-LAN")
		);

	$list = array("" => gettext("All"));

	if ($showall) {
		return($list + $areas);
	} else {
		foreach ($areas as $area => $areaname) {
			if ($area === "rrddata" || check_and_returnif_section_exists($area) == true) {
				$list[$area] = $areaname;
			}
		}

		return($list);
	}
}

$pgtitle = [gettext('Diagnostics'), htmlspecialchars(gettext('Backup & Restore')), htmlspecialchars(gettext('Backup & Restore'))];
$pglinks = ['', '@self', '@self'];
include("head.inc");

fs_tabs('diagnostics-backup', 'diag_backup.php');

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($savemsg) {
	print_info_box($savemsg, 'success');
}

/* Status of a package in the restore preview */
$package_states = [
	'available' => ['pass', gettext('Available')],
	'unknown' => ['warn', gettext('Not verified')],
	'missing' => ['block', gettext('Not available')],
	'orphan' => ['neutral', gettext('No package')],
];
?>

<style>
.fs-backup-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--fs-sp-4); align-items: start; margin-bottom: var(--fs-sp-4); }
.fs-backup-grid .panel { margin-bottom: 0; }
.fs-backup-grid .panel-title > i, .fs-backup-card-title > i { margin-right: var(--fs-sp-2); color: var(--fs-text-muted); }
.fs-backup-checks { display: flex; flex-direction: column; gap: var(--fs-sp-2); }
.fs-backup-checks .form-check { margin: 0; }
.fs-backup-checks .form-text { margin-top: 0; }
.fs-backup-extra { margin: var(--fs-sp-1) 0 0; padding-left: 1.1rem; }
.fs-backup-note { display: flex; gap: var(--fs-sp-2); margin: 0; color: var(--fs-text-muted); font-size: var(--fs-fs-sm); }
.fs-backup-note > i { margin-top: .2rem; }
.fs-backup-footer-hint { align-self: center; color: var(--fs-text-muted); font-size: var(--fs-fs-sm); }
.fs-backup-packages .panel-body { display: flex; flex-direction: row; flex-wrap: wrap; gap: var(--fs-sp-4); }
.fs-backup-packages .panel-body > div { flex: 1 1 18rem; display: flex; flex-direction: column; align-items: flex-start; gap: var(--fs-sp-1); }
.fs-backup-pkgs { min-width: 16rem; }
.fs-backup-intro { padding: var(--fs-sp-3) var(--fs-sp-4) 0; }
.fs-backup-intro > p:last-child { margin-bottom: 0; }
@media (max-width: 991.98px) {
	.fs-backup-grid { grid-template-columns: minmax(0, 1fr); }
}
</style>

<?php
if (!empty($package_restore_preview) && empty($input_errors)):
?>
<form method="post" action="diag_backup.php" id="package-restore-form">
	<input type="hidden" name="package_restore_token"
	    value="<?=htmlspecialchars($package_restore_preview['token'])?>" />
	<input type="hidden" name="restorearea"
	    value="<?=htmlspecialchars($package_restore_preview['restorearea'])?>" />
	<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Review Optional Packages'),
	'search' => false,
	'noun' => gettext('packages'),
	'noun_one' => gettext('package'),
]); ?>
		<div class="fs-backup-intro">
			<p><?=gettext('Package menus and services are never restored from the backup. Select the available packages whose settings should be restored after the current package installs successfully.')?></p>
<?php if (!$package_restore_preview['catalog_available']): ?>
			<?php print_info_box(gettext(
			    'The current package repository could not be reached. Selected packages will remain isolated and pending until repository verification succeeds.'), 'warning'); ?>
<?php endif; ?>
		</div>
		<div class="panel-body table-responsive">
			<table class="table table-hover">
				<thead><tr>
					<th class="fs-col-select"><?=gettext('Restore')?></th>
					<th><?=gettext('Package')?></th>
					<th class="fs-col-status"><?=gettext('Status')?></th>
					<th><?=gettext('Settings')?></th>
				</tr></thead>
				<tbody>
<?php foreach ($package_restore_preview['packages'] as $package):
	$available = ($package['status'] !== 'missing');
	/* badge from the fixed status list only */
	$bstate = 'neutral';
	$blabel = gettext('Unknown');
	foreach ($package_states as $pkey => $pstate) {
		if ($package['status'] === $pkey) {
			list($bstate, $blabel) = $pstate;
		}
	}
?>
					<tr<?=$available ? '' : ' class="fs-row-disabled"'?>>
						<td>
							<input type="checkbox" class="form-check-input" name="restore_packages[]"
							    value="<?=htmlspecialchars($package['target'])?>"
							    aria-label="<?=htmlspecialchars(sprintf(gettext('Restore the settings of %s'), $package['name']))?>"
							    <?=$available ? 'checked' : 'disabled'?> />
						</td>
						<td><?=htmlspecialchars($package['name'])?></td>
						<td><?=fs_badge($bstate, $blabel)?></td>
						<td>
							<div class="fs-chips">
<?php foreach ($package['setting_roots'] as $root): ?>
								<span class="fs-chip fs-chip--mono"><?=htmlspecialchars($root)?></span>
<?php endforeach; ?>
							</div>
						</td>
					</tr>
<?php endforeach; ?>
<?php if (empty($package_restore_preview['packages'])) {
	fs_empty_row(4, gettext('The backup holds no package settings.'));
} ?>
				</tbody>
			</table>
		</div>
		<div class="panel-footer d-flex flex-wrap gap-2">
			<button type="submit" name="package_restore_apply" value="1" class="btn btn-danger"
			    data-fs-confirm="<?=gettext('Restore this configuration now?')?>"
			    data-fs-confirm-detail="<?=gettext('The current configuration is replaced and the firewall reboots.')?>"
			    data-fs-confirm-action="<?=gettext('Restore')?>">
				<i class="fa-solid fa-arrow-rotate-left icon-embed-btn" aria-hidden="true"></i><?=gettext('Apply Sanitized Restore')?>
			</button>
			<a class="btn btn-outline-secondary" href="diag_backup.php"><?=gettext('Cancel')?></a>
		</div>
	</div>
</form>
<?php
endif;

if (is_subsystem_dirty('restore')):
?>
	<form action="diag_reboot.php" method="post">
		<input name="Submit" type="hidden" value="Yes" />
		<?php print_info_box(gettext("The firewall configuration has been changed.") . "<br />" . gettext("The firewall is now rebooting.")); ?>
	</form>
<?php
endif;

$has_installed_packages = !empty(config_get_path('installedpackages/package', []));
$package_lock = is_subsystem_dirty("packagelock");
?>

<form method="post" action="/diag_backup.php" enctype="multipart/form-data" class="fs-tool-form" id="backup-form">
<div class="fs-backup-grid">
	<div class="panel panel-default">
		<div class="panel-heading"><h2 class="panel-title"><i class="fa-solid fa-download" aria-hidden="true"></i><?=gettext('Back up configuration')?></h2></div>
		<div class="panel-body">
			<div>
				<label class="form-label" for="backuparea"><?=gettext('Backup area')?></label>
				<select class="form-select" id="backuparea" name="backuparea">
<?php foreach (build_area_list(false) as $k => $v): ?>
					<option value="<?=htmlspecialchars($k)?>"><?=htmlspecialchars($v)?></option>
<?php endforeach; ?>
				</select>
				<div class="form-text"><?=gettext('All, or a single part of the configuration.')?></div>
			</div>
			<div class="fs-backup-checks" role="group" aria-label="<?=gettext('Backup options')?>">
				<div class="form-check">
					<input class="form-check-input" type="checkbox" name="nopackages" id="nopackages" value="yes">
					<label class="form-check-label" for="nopackages"><?=gettext('Skip packages')?></label>
					<div class="form-text"><?=gettext('Do not backup package information.')?></div>
				</div>
				<div class="form-check">
					<input class="form-check-input" type="checkbox" name="donotbackuprrd" id="donotbackuprrd" value="yes" checked>
					<label class="form-check-label" for="donotbackuprrd"><?=gettext('Skip RRD data')?></label>
					<div class="form-text"><?=gettext('RRD graph data can add 4 MB or more to the file.')?></div>
				</div>
				<div class="form-check">
					<input class="form-check-input" type="checkbox" name="backupdata" id="backupdata" value="yes">
					<label class="form-check-label" for="backupdata"><?=gettext('Include extra data')?></label>
					<div class="form-text">
						<?=gettext('Backup extra data files for some services:')?>
						<ul class="fs-backup-extra">
							<li><?=gettext('Captive Portal - Captive Portal DB and UsedMACs DB')?></li>
							<li><?=gettext('Captive Portal Vouchers - Used Vouchers DB')?></li>
							<li><?=gettext('DHCP Server - DHCP leases DB')?></li>
							<li><?=gettext('DHCPv6 Server - DHCPv6 leases DB')?></li>
						</ul>
					</div>
				</div>
				<div class="form-check">
					<input class="form-check-input" type="checkbox" name="backupssh" id="backupssh" value="yes" checked>
					<label class="form-check-label" for="backupssh"><?=gettext('Backup SSH keys')?></label>
					<div class="form-text"><?=gettext('Otherwise SSH clients do not recognize the host keys after a restore.')?></div>
				</div>
				<div class="form-check">
					<input class="form-check-input" type="checkbox" name="encrypt" id="encrypt" value="yes">
					<label class="form-check-label" for="encrypt"><?=gettext('Encrypt this configuration file.')?></label>
				</div>
			</div>
			<div class="fs-tool-row" id="encrypt-passwords">
				<div>
					<label class="form-label" for="encrypt_password"><?=gettext('Password')?></label>
					<input class="form-control" type="password" id="encrypt_password" name="encrypt_password" autocomplete="new-password">
				</div>
				<div>
					<label class="form-label" for="encrypt_password_confirm"><?=gettext('Confirm')?></label>
					<input class="form-control" type="password" id="encrypt_password_confirm" name="encrypt_password_confirm" autocomplete="new-password">
				</div>
			</div>
		</div>
		<div class="panel-footer">
			<button type="submit" class="btn btn-primary" name="download" value="<?=gettext('Download configuration as XML')?>">
				<i class="fa-solid fa-download icon-embed-btn" aria-hidden="true"></i><?=gettext('Download configuration as XML')?>
			</button>
		</div>
	</div>

	<div class="panel panel-default">
		<div class="panel-heading"><h2 class="panel-title"><i class="fa-solid fa-arrow-rotate-left" aria-hidden="true"></i><?=gettext('Restore Backup')?></h2></div>
		<div class="panel-body">
			<p class="fs-backup-note">
				<i class="fa-solid fa-circle-info" aria-hidden="true"></i>
				<span><?=sprintf(gettext("Open a %s configuration XML file and click the button below to restore the configuration."), htmlspecialchars(g_get('product_label')))?>
				<?=gettext('OPNsense and pfSense configurations are detected and converted: certificates, users and basic networking are kept; firewall, NAT and VPN settings must be set up again. Detected packages are mapped to FreeSense packages.')?></span>
			</p>
			<div>
				<label class="form-label" for="restorearea"><?=gettext('Restore area')?></label>
				<select class="form-select" id="restorearea" name="restorearea">
<?php foreach (build_area_list(true) as $k => $v): ?>
					<option value="<?=htmlspecialchars($k)?>"><?=htmlspecialchars($v)?></option>
<?php endforeach; ?>
				</select>
			</div>
			<div>
				<label class="form-label" for="conffile"><?=gettext('Configuration file')?></label>
				<input class="form-control" type="file" id="conffile" name="conffile">
			</div>
			<div class="form-check">
				<input class="form-check-input" type="checkbox" name="decrypt" id="decrypt" value="yes">
				<label class="form-check-label" for="decrypt"><?=gettext('Configuration file is encrypted.')?></label>
			</div>
			<div id="decrypt-password">
				<label class="form-label" for="decrypt_password"><?=gettext('Password')?></label>
				<input class="form-control" type="password" id="decrypt_password" name="decrypt_password" placeholder="<?=gettext('Password')?>">
			</div>
		</div>
		<div class="panel-footer">
			<button type="submit" class="btn btn-danger restore" name="restore" value="<?=gettext('Review / Restore Configuration')?>" disabled
			    data-fs-confirm="<?=gettext('Restore the configuration from this file?')?>"
			    data-fs-confirm-detail="<?=gettext('A restore of one area is applied right away and the firewall reboots. A full restore shows the package review first.')?>"
			    data-fs-confirm-action="<?=gettext('Restore')?>">
				<i class="fa-solid fa-arrow-rotate-left icon-embed-btn" aria-hidden="true"></i><?=gettext('Review / Restore Configuration')?>
			</button>
			<span class="fs-backup-footer-hint"><?=gettext('The firewall will reboot after restoring the configuration.')?></span>
		</div>
	</div>
</div>

<?php if ($has_installed_packages || $package_lock): ?>
<div class="panel panel-default fs-backup-packages">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('Package Functions')?></h2></div>
	<div class="panel-body">
<?php if ($has_installed_packages): ?>
		<div>
			<button type="submit" class="btn btn-outline-secondary" name="reinstallpackages" value="<?=gettext('Reinstall Packages')?>">
				<i class="fa-solid fa-retweet icon-embed-btn" aria-hidden="true"></i><?=gettext('Reinstall Packages')?>
			</button>
			<span class="form-text"><?=gettext('Reinstalls all installed packages. This may take a while.')?></span>
		</div>
<?php endif; ?>
<?php if ($package_lock): ?>
		<div>
			<button type="submit" class="btn btn-outline-secondary" name="clearpackagelock" value="<?=gettext('Clear Package Lock')?>">
				<i class="fa-solid fa-wrench icon-embed-btn" aria-hidden="true"></i><?=gettext('Clear Package Lock')?>
			</button>
			<span class="form-text"><?=gettext('Clears the package lock if a package failed to reinstall properly after an upgrade.')?></span>
		</div>
<?php endif; ?>
	</div>
</div>
<?php endif; ?>
</form>

<?php
$quarantine_records = freesense_package_restore_list_quarantine();
$has_pending = is_readable(freesense_package_restore_pending_path());
if (!empty($quarantine_records) || $has_pending):
	$retry = '';
	if ($has_pending) {
		$retry = '<form method="post" action="diag_backup.php">'
		    . '<button type="submit" name="package_restore_retry" value="1" class="btn btn-sm btn-primary">'
		    . '<i class="fa-solid fa-rotate icon-embed-btn" aria-hidden="true"></i>' . fs_h(gettext('Retry Package Restore')) . '</button></form>';
	}
?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Restored Package Settings'),
	'search' => false,
	'noun' => gettext('records'),
	'noun_one' => gettext('record'),
	'actions' => $retry,
]); ?>
<?php if ($has_pending): ?>
	<div class="fs-backup-intro">
		<?php print_info_box(gettext(
		    'Some restored package settings remain isolated pending package verification or installation.'), 'warning'); ?>
	</div>
<?php endif; ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover">
			<thead><tr>
				<th><?=gettext('Created')?></th>
				<th><?=gettext('Source')?></th>
				<th><?=gettext('Packages')?></th>
				<th class="fs-col-actions"><span class="visually-hidden"><?=gettext('Actions')?></span></th>
			</tr></thead>
			<tbody>
<?php foreach ($quarantine_records as $record):
	$ts = strtotime($record['created_at']);
	$created = ($ts !== false) ? date('Y-m-d H:i', $ts) : $record['created_at'];
	$qid = rawurlencode($record['id']);
?>
				<tr>
					<td class="fs-mono text-nowrap" title="<?=htmlspecialchars($record['created_at'])?>"><?=htmlspecialchars($created)?></td>
					<td class="text-nowrap"><?=htmlspecialchars($record['source'])?></td>
					<td class="fs-backup-pkgs">
						<div class="fs-chips">
<?php foreach ($record['packages'] as $pkg): ?>
							<span class="fs-chip"><?=htmlspecialchars($pkg)?></span>
<?php endforeach; ?>
						</div>
					</td>
					<td class="fs-col-actions"><?=fs_row_actions([
						['custom', "diag_backup.php?package_quarantine_download=1&quarantine_id={$qid}", $created,
						    ['icon' => 'fa-download', 'label' => sprintf(gettext('Download the quarantine record of %s'), $created), 'post' => true]],
						['delete', "diag_backup.php?package_quarantine_delete=1&quarantine_id={$qid}", $created,
						    ['thing' => gettext('quarantine record'), 'detail' => gettext('The isolated package settings of this restore are removed for good.')]],
					])?></td>
				</tr>
<?php endforeach; ?>
<?php if (empty($quarantine_records)) {
	fs_empty_row(4, gettext('No quarantine records.'));
} ?>
			</tbody>
		</table>
	</div>
</div>
<?php
endif;
?>
<script type="text/javascript">
//<![CDATA[
events.push(function() {
	// Password fields only while the matching checkbox is on (hidden fields are still posted, as before)
	function hidePasswords() {
		$('#encrypt-passwords').prop('hidden', !$('#encrypt').is(':checked'));
		$('#decrypt-password').prop('hidden', !$('#decrypt').is(':checked'));
	}

	$('#encrypt, #decrypt').on('change', hidePasswords);

	$('#conffile').on('change', function () {
		$('.restore').prop('disabled', !this.value);
	});

	// A single area has no packages, RRD or SSH keys; extra data only for some areas
	$('#backuparea').on('change', function () {
		var area = this.value;
		var all = (area === '');
		$('#donotbackuprrd, #nopackages, #backupssh').prop('disabled', !all);
		$('#backupdata').prop('disabled', !all && ['captiveportal', 'dhcpd', 'dhcpdv6', 'voucher'].indexOf(area) === -1);
	});

	hidePasswords();
	$('.restore').prop('disabled', !$('#conffile').val());
});
//]]>
</script>

<?php
include("foot.inc");

if (is_subsystem_dirty('restore')) {
	print('<span hidden>');
	system_reboot();
	print('</span>');
}
