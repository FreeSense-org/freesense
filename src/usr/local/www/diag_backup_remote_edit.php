<?php
/*
 * diag_backup_remote_edit.php
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

/* Privilege: page-diagnostics-backup-restore-remote (declared in diag_backup_remote.php). */

require_once("guiconfig.inc");
require_once("remote_backup.inc");

$id = isset($_REQUEST['id']) ? (string)$_REQUEST['id'] : null;
$existing = null;
if ($id !== null) {
	list(, $existing) = remote_backup_find_target($id);
	if ($existing === null) {
		header("Location: diag_backup_remote.php");
		exit;
	}
}

$hostkeys = array();
if ($_POST['save'] || $_POST['scan']) {
	$pconfig = $_POST;
} elseif ($existing) {
	$pconfig = remote_backup_target_for_display($existing);
} else {
	$pconfig = array(
		'enable' => true,
		'type' => 's3',
		'retention' => REMOTE_BACKUP_DEFAULT_RETENTION,
		's3_region' => 'auto',
		's3_prefix' => 'freesense',
		'sftp_port' => '22',
	);
}

if ($_POST['scan']) {
	/* Fetch the SFTP server's host keys so the user can choose one to trust. */
	if (!is_hostname($_POST['sftp_host'] ?? '') && !is_ipaddr($_POST['sftp_host'] ?? '')) {
		$input_errors[] = gettext('Enter the SFTP host before fetching its host key.');
	} elseif (!is_port($_POST['sftp_port'] ?? '', false)) {
		$input_errors[] = gettext('Enter a valid SFTP port before fetching the host key.');
	} else {
		$hostkeys = remote_backup_sftp_scan($_POST['sftp_host'], $_POST['sftp_port']);
		if (!is_array($hostkeys)) {
			$input_errors[] = sprintf(gettext('Could not fetch the host key: %s'), htmlspecialchars($hostkeys));
			$hostkeys = array();
		}
	}
} elseif ($_POST['save']) {
	$result = remote_backup_save_target($_POST, $id);
	$input_errors = $result['input_errors'];
	if (empty($input_errors)) {
		header("Location: diag_backup_remote.php?saved=" . urlencode($result['id']));
		exit;
	}
}

$pgtitle = array(gettext('Diagnostics'), htmlspecialchars(gettext('Backup & Restore')), gettext('Remote Backup'), gettext('Edit Target'));
$pglinks = array('', 'diag_backup.php', 'diag_backup_remote.php', '@self');
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

$form = new Form;

$section = new Form_Section(gettext('Backup Target'));
$section->addInput(new Form_Checkbox(
	'enable',
	gettext('Enable'),
	gettext('Upload backups to this target'),
	!empty($pconfig['enable'])
));
$section->addInput(new Form_Select(
	'type',
	'*' . gettext('Storage type'),
	$pconfig['type'],
	remote_backup_types()
));
$section->addInput(new Form_Input(
	'descr',
	gettext('Description'),
	'text',
	$pconfig['descr']
))->setHelp(gettext('A name for this target, used in the status table and alerts.'));
$section->addInput(new Form_Input(
	'retention',
	gettext('Keep'),
	'number',
	$pconfig['retention'],
	array('min' => 1, 'max' => REMOTE_BACKUP_MAX_RETENTION)
))->setHelp(gettext('Number of backups of this firewall to keep on the target. Older backups of this firewall are deleted after each upload; other files are never touched.'));
$section->addInput(new Form_Checkbox(
	'include_rrd',
	gettext('RRD data'),
	gettext('Include graph (RRD) data'),
	!empty($pconfig['include_rrd'])
))->setHelp(gettext('Graph data makes each backup much larger and changes constantly.'));
$section->addInput(new Form_Checkbox(
	'include_sshkeys',
	gettext('SSH host keys'),
	gettext('Include the firewall\'s SSH host keys'),
	!empty($pconfig['include_sshkeys'])
));
$section->addInput(new Form_Checkbox(
	'include_extra',
	gettext('Extra data'),
	gettext('Include extra data such as DHCP leases and captive portal databases'),
	!empty($pconfig['include_extra'])
));
$form->add($section);

/* S3-compatible */
$section = new Form_Section(gettext('S3-compatible Storage'));
$section->addClass('type-s3');
$section->addInput(new Form_Input(
	's3_endpoint',
	'*' . gettext('Endpoint URL'),
	'text',
	$pconfig['s3_endpoint'],
	array('placeholder' => 'https://<account-id>.r2.cloudflarestorage.com')
))->setHelp(gettext('AWS: https://s3.&lt;region&gt;.amazonaws.com. Cloudflare R2: https://&lt;account-id&gt;.r2.cloudflarestorage.com. MinIO and others: the server URL.'));
$section->addInput(new Form_Input(
	's3_region',
	'*' . gettext('Region'),
	'text',
	$pconfig['s3_region']
))->setHelp(gettext('For example us-east-1. Use "auto" for Cloudflare R2.'));
$section->addInput(new Form_Input(
	's3_bucket',
	'*' . gettext('Bucket'),
	'text',
	$pconfig['s3_bucket']
));
$section->addInput(new Form_Input(
	's3_prefix',
	gettext('Prefix'),
	'text',
	$pconfig['s3_prefix']
))->setHelp(gettext('Folder inside the bucket, for example "freesense/site-a".'));
$section->addInput(new Form_Checkbox(
	's3_path_style',
	gettext('Path-style URLs'),
	gettext('Use path-style addressing (needed by most MinIO setups)'),
	!empty($pconfig['s3_path_style'])
));
$section->addInput(new Form_Input(
	's3_access_key',
	'*' . gettext('Access key ID'),
	'text',
	$pconfig['s3_access_key'],
	array('autocomplete' => 'off')
));
$section->addPassword(new Form_Input(
	's3_secret_key',
	'*' . gettext('Secret access key'),
	'password',
	$pconfig['s3_secret_key']
), false)->setHelp(gettext('Use a key limited to this bucket with read, write, list and delete permissions.'));
$form->add($section);

/* WebDAV */
$section = new Form_Section(gettext('WebDAV'));
$section->addClass('type-webdav');
$section->addInput(new Form_Input(
	'dav_url',
	'*' . gettext('Folder URL'),
	'text',
	$pconfig['dav_url'],
	array('placeholder' => 'https://cloud.example.com/remote.php/dav/files/user/freesense/')
));
$section->addInput(new Form_Input(
	'dav_username',
	gettext('Username'),
	'text',
	$pconfig['dav_username'],
	array('autocomplete' => 'off')
));
$section->addPassword(new Form_Input(
	'dav_password',
	gettext('Password'),
	'password',
	$pconfig['dav_password']
), false)->setHelp(gettext('Prefer an app password limited to this folder.'));
$form->add($section);

/* SFTP */
$section = new Form_Section(gettext('SFTP'));
$section->addClass('type-sftp');
$section->addInput(new Form_Input(
	'sftp_host',
	'*' . gettext('Host'),
	'text',
	$pconfig['sftp_host']
));
$section->addInput(new Form_Input(
	'sftp_port',
	'*' . gettext('Port'),
	'number',
	$pconfig['sftp_port'],
	array('min' => 1, 'max' => 65535)
));
$section->addInput(new Form_Input(
	'sftp_username',
	'*' . gettext('Username'),
	'text',
	$pconfig['sftp_username']
));
$section->addInput(new Form_Input(
	'sftp_path',
	gettext('Remote directory'),
	'text',
	$pconfig['sftp_path']
))->setHelp(gettext('An existing directory, relative to the login directory unless it starts with "/".'));

$hostkey_options = array();
foreach ($hostkeys as $hk) {
	$hostkey_options[$hk['key']] = $hk['fingerprint'] . ' (' . strtok($hk['key'], ' ') . ')';
}
if (!empty($pconfig['sftp_hostkey']) && !isset($hostkey_options[$pconfig['sftp_hostkey']])) {
	$fp = remote_backup_sftp_fingerprint($pconfig['sftp_hostkey']);
	if ($fp !== null) {
		$hostkey_options = array($pconfig['sftp_hostkey'] => $fp . ' (' . gettext('currently trusted') . ')') + $hostkey_options;
	}
}
if (!empty($hostkey_options)) {
	$section->addInput(new Form_Select(
		'sftp_hostkey',
		'*' . gettext('Trusted host key'),
		$pconfig['sftp_hostkey'] ?: array_key_first($hostkey_options),
		$hostkey_options
	))->setHelp(gettext('Compare this fingerprint with the server (ssh-keygen -lf /etc/ssh/ssh_host_ed25519_key.pub) before saving. Connections fail if the key ever changes.'));
} else {
	$section->addInput(new Form_StaticText(
		gettext('Trusted host key'),
		gettext('No host key trusted yet. Use "Fetch host key" below.')
	));
}
$group = new Form_Group('');
$group->add(new Form_Button(
	'scan',
	gettext('Fetch host key'),
	null,
	'fa-solid fa-key'
))->addClass('btn-outline-secondary btn-sm');
$section->add($group);

if (!empty($existing['sftp_pubkey'])) {
	$section->addInput(new Form_Textarea(
		'sftp_pubkey_display',
		gettext('Firewall public key'),
		$existing['sftp_pubkey']
	))->setReadonly()->setHelp(gettext('Add this line to ~/.ssh/authorized_keys of the backup user on the server.'));
} else {
	$section->addInput(new Form_StaticText(
		gettext('Firewall public key'),
		gettext('An SSH key pair is generated when the target is saved. Its public key is shown here afterwards.')
	));
}
$form->add($section);

if ($id !== null) {
	$form->addGlobal(new Form_Input('id', null, 'hidden', $id));
}
fs_form_cancel($form, 'diag_backup_remote.php');
print($form);
?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	function showType() {
		var type = $('#type').val();
		hideClass('type-s3', type !== 's3');
		hideClass('type-webdav', type !== 'webdav');
		hideClass('type-sftp', type !== 'sftp');
	}
	$('#type').on('change', showType);
	showType();
});
//]]>
</script>

<?php
include("foot.inc");
