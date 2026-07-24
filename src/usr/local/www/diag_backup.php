<?php
/*
 * diag_backup.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
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

$tab_array[] = [htmlspecialchars(gettext('Backup & Restore')), true, 'diag_backup.php'];
$tab_array[] = [gettext('Configuration History'), false, 'diag_confbak.php'];

display_top_tabs($tab_array);

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($savemsg) {
	print_info_box($savemsg, 'success');
}

if (!empty($package_restore_preview) && empty($input_errors)):
?>
	<section class="card mb-3">
		<div class="card-header">
			<h2 class="h5 mb-0"><?=gettext('Review Optional Packages')?></h2>
		</div>
		<div class="card-body">
			<p><?=gettext('Package menus and services are never restored from the backup. Select the available packages whose settings should be restored after the current package installs successfully.')?></p>
			<?php if (!$package_restore_preview['catalog_available']): ?>
				<?php print_info_box(gettext(
				    'The current package repository could not be reached. Selected packages will remain isolated and pending until repository verification succeeds.'), 'warning'); ?>
			<?php endif; ?>
			<form method="post" action="diag_backup.php">
				<input type="hidden" name="package_restore_token"
				    value="<?=htmlspecialchars($package_restore_preview['token'])?>" />
				<input type="hidden" name="restorearea"
				    value="<?=htmlspecialchars($package_restore_preview['restorearea'])?>" />
				<table class="table table-striped">
					<thead><tr>
						<th><?=gettext('Restore')?></th>
						<th><?=gettext('Package')?></th>
						<th><?=gettext('Status')?></th>
						<th><?=gettext('Settings')?></th>
					</tr></thead>
					<tbody>
					<?php foreach ($package_restore_preview['packages'] as $package):
						$available = ($package['status'] !== 'missing');
					?>
						<tr>
							<td>
								<input type="checkbox" name="restore_packages[]"
								    value="<?=htmlspecialchars($package['target'])?>"
								    <?=$available ? 'checked' : 'disabled'?> />
							</td>
							<td><?=htmlspecialchars($package['name'])?></td>
							<td><?=htmlspecialchars($package['status'])?></td>
							<td><?=htmlspecialchars(implode(', ',
							    $package['setting_roots']))?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<button type="submit" name="package_restore_apply" value="1"
				    class="btn btn-danger">
					<i class="fa-solid fa-undo"></i>
					<?=gettext('Apply Sanitized Restore')?>
				</button>
			</form>
		</div>
	</section>
<?php
endif;

if (is_subsystem_dirty('restore')):
?>
	<br/>
	<form action="diag_reboot.php" method="post">
		<input name="Submit" type="hidden" value="Yes" />
		<?php print_info_box(gettext("The firewall configuration has been changed.") . "<br />" . gettext("The firewall is now rebooting.")); ?>
		<br />
	</form>
<?php
endif;

$form = new Form(false);
$form->setMultipartEncoding();	// Allow file uploads

$section = new Form_Section('Backup Configuration');

$section->addInput(new Form_Select(
	'backuparea',
	'Backup area',
	'',
	build_area_list(false)
));

$section->addInput(new Form_Checkbox(
	'nopackages',
	'Skip packages',
	'Do not backup package information.',
	false
));

$section->addInput(new Form_Checkbox(
	'donotbackuprrd',
	'Skip RRD data',
	'Do not backup RRD data (NOTE: RRD Data can consume 4+ megabytes of config.xml space!)',
	true
));

$section->addInput(new Form_Checkbox(
	'backupdata',
	'Include extra data',
	'Backup extra data.',
	false
))->setHelp('Backup extra data files for some services.%1$s' .
	    '%2$s%3$sCaptive Portal - Captive Portal DB and UsedMACs DB%4$s' .
	    '%3$sCaptive Portal Vouchers - Used Vouchers DB%4$s' .
	    '%3$sDHCP Server - DHCP leases DB%4$s' .
	    '%3$sDHCPv6 Server - DHCPv6 leases DB%4$s%5$s',
	    '<div class="infoblock">', '<ul>', '<li>', '</li>', '</ul></div>'
);

$section->addInput(new Form_Checkbox(
	'backupssh',
	'Backup SSH keys',
	'Backup SSH keys (otherwise clients would fail to recognize the host keys after restore)',
	true
));

$section->addInput(new Form_Checkbox(
	'encrypt',
	'Encryption',
	'Encrypt this configuration file.',
	false
));

$section->addPassword(new Form_Input(
	'encrypt_password',
	'Password',
	'password',
	null
));

$group = new Form_Group('');
// Note: ID attribute of each element created is to be unique.  Not being used, suppressing it.
$group->add(new Form_Button(
	'download',
	'Download configuration as XML',
	null,
	'fa-solid fa-download'
))->setAttribute('id')->addClass('btn-primary');

$section->add($group);
$form->add($section);

$section = new Form_Section('Restore Backup');

$section->addInput(new Form_StaticText(
	null,
	sprintf(gettext("Open a %s configuration XML file and click the button below to restore the configuration."), g_get('product_label'))
));

$section->addInput(new Form_StaticText(
	null,
	'<div class="infoblock">' .
	print_info_box(gettext('A full-restore of an OPNsense or pfSense configuration is ' .
	    'automatically detected and converted to FreeSense. Certificates, users ' .
	    'and basic networking are kept; OPNsense-specific firewall, NAT and VPN ' .
	    'settings are not carried and must be reconfigured. Detected packages are ' .
	    'auto-mapped to FreeSense packages and listed after the restore.'),
	    'info', false) .
	'</div>'
));

$section->addInput(new Form_Select(
	'restorearea',
	'Restore area',
	'',
	build_area_list(true)
));

$section->addInput(new Form_Input(
	'conffile',
	'Configuration file',
	'file',
	null
));

$section->addInput(new Form_Checkbox(
	'decrypt',
	'Encryption',
	'Configuration file is encrypted.',
	false
));

$section->addInput(new Form_Input(
	'decrypt_password',
	'Password',
	'password',
	null,
	['placeholder' => 'Password']
));

$group = new Form_Group('');
// Note: ID attribute of each element created is to be unique.  Not being used, suppressing it.
$group->add(new Form_Button(
	'restore',
	'Review / Restore Configuration',
	null,
	'fa-solid fa-undo'
))->setHelp('The firewall will reboot after restoring the configuration.')->addClass('btn-danger restore')->setAttribute('id');

$section->add($group);

$form->add($section);

$has_installed_packages = !empty(config_get_path('installedpackages/package', []));

if ($has_installed_packages || (is_subsystem_dirty("packagelock"))) {
	$section = new Form_Section('Package Functions');

	if ($has_installed_packages) {
		$group = new Form_Group('');
		// Note: ID attribute of each element created is to be unique.  Not being used, suppressing it.
		$group->add(new Form_Button(
			'reinstallpackages',
			'Reinstall Packages',
			null,
			'fa-solid fa-retweet'
		))->setHelp('Click this button to reinstall all system packages.  This may take a while.')->addClass('btn-success')->setAttribute('id');

		$section->add($group);
	}

	if (is_subsystem_dirty("packagelock")) {
		$group = new Form_Group('');
		// Note: ID attribute of each element created is to be unique.  Not being used, suppressing it.
		$group->add(new Form_Button(
			'clearpackagelock',
			'Clear Package Lock',
			null,
			'fa-solid fa-wrench'
		))->setHelp('Click this button to clear the package lock if a package fails to reinstall properly after an upgrade.')->addClass('btn-warning')->setAttribute('id');

		$section->add($group);
	}

	$form->add($section);
}

print($form);

$quarantine_records = freesense_package_restore_list_quarantine();
if (!empty($quarantine_records) || is_readable(
    freesense_package_restore_pending_path())):
?>
	<section class="card mt-3">
		<div class="card-header">
			<h2 class="h5 mb-0"><?=gettext('Restored Package Settings')?></h2>
		</div>
		<div class="card-body">
			<?php if (is_readable(freesense_package_restore_pending_path())): ?>
				<?php print_info_box(gettext(
				    'Some restored package settings remain isolated pending package verification or installation.'), 'warning'); ?>
				<form method="post" action="diag_backup.php" class="mb-3">
					<button type="submit" name="package_restore_retry" value="1"
					    class="btn btn-primary">
						<i class="fa-solid fa-rotate"></i>
						<?=gettext('Retry Package Restore')?>
					</button>
				</form>
			<?php endif; ?>
			<?php if (!empty($quarantine_records)): ?>
				<table class="table table-striped">
					<thead><tr>
						<th><?=gettext('Created')?></th>
						<th><?=gettext('Source')?></th>
						<th><?=gettext('Packages')?></th>
						<th><?=gettext('Actions')?></th>
					</tr></thead>
					<tbody>
					<?php foreach ($quarantine_records as $record): ?>
						<tr>
							<td><?=htmlspecialchars($record['created_at'])?></td>
							<td><?=htmlspecialchars($record['source'])?></td>
							<td><?=htmlspecialchars(implode(', ', $record['packages']))?></td>
							<td>
								<form method="post" action="diag_backup.php"
								    class="d-inline">
									<input type="hidden" name="quarantine_id"
									    value="<?=htmlspecialchars($record['id'])?>" />
									<button type="submit" name="package_quarantine_download"
									    value="1" class="btn btn-sm btn-secondary">
										<?=gettext('Download')?>
									</button>
								</form>
								<form method="post" action="diag_backup.php"
								    class="d-inline"
								    onsubmit="return confirm('<?=htmlspecialchars(
								        gettext('Delete this quarantine record?'),
								        ENT_QUOTES)?>');">
									<input type="hidden" name="quarantine_id"
									    value="<?=htmlspecialchars($record['id'])?>" />
									<button type="submit" name="package_quarantine_delete"
									    value="1" class="btn btn-sm btn-danger">
										<?=gettext('Delete')?>
									</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
	</section>
<?php
endif;
?>
<script type="text/javascript">
//<![CDATA[
events.push(function() {

	// ------- Show/hide sections based on checkbox settings --------------------------------------

	function hideSections(hide) {
		hidePasswords();
	}

	function hidePasswords() {

		encryptHide = !($('input[name="encrypt"]').is(':checked'));
		decryptHide = !($('input[name="decrypt"]').is(':checked'));

		hideInput('encrypt_password', encryptHide);
		hideInput('decrypt_password', decryptHide);
	}

	// ---------- Click handlers ------------------------------------------------------------------

	$('input[name="encrypt"]').on('change', function() {
		hidePasswords();
	});

	$('input[name="decrypt"]').on('change', function() {
		hidePasswords();
	});

	$('#conffile').change(function () {
		if (document.getElementById("conffile").value) {
			$('.restore').prop('disabled', false);
		} else {
			$('.restore').prop('disabled', true);
		}
	});

	$('#backuparea').change(function () {
		if (document.getElementById("backuparea").value == 0) {
			disableInput('donotbackuprrd', false);
			disableInput('nopackages', false);
			disableInput('backupdata', false);
			disableInput('backupssh', false);
		} else {
			disableInput('donotbackuprrd', true);
			disableInput('nopackages', true);
			disableInput('backupdata', true);
			disableInput('backupssh', true);
			if (['captiveportal', 'dhcpd', 'dhcpdv6', 'voucher'].includes(document.getElementById("backuparea").value)) {
				disableInput('backupdata', false);
			}
		}
	});

	// ---------- On initial page load ------------------------------------------------------------

	hideSections();
	$('.restore').prop('disabled', true);
});
//]]>
</script>

<?php
include("foot.inc");

if (is_subsystem_dirty('restore')) {
	print('<span style="display: none;">');
	system_reboot();
	print('</span>');
}
