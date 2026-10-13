<?php
/*
 * pkg_mgr_repos_edit.php
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

/* Privilege: page-system-packagemanager-repositories (declared in pkg_mgr_repos.php). */

require_once("guiconfig.inc");
require_once("pkg-utils.inc");
require_once("pkg_custom_repos.inc");

$id = isset($_REQUEST['id']) ? (string)$_REQUEST['id'] : null;
$existing = null;
if ($id !== null) {
	list(, $existing) = pkgrepo_find($id);
	if ($existing === null) {
		header("Location: pkg_mgr_repos.php");
		exit;
	}
}

if ($_POST['save']) {
	$pconfig = $_POST;
	$result = pkgrepo_save($_POST, $id ?? '');
	$input_errors = $result['input_errors'];
	if (empty($input_errors)) {
		header("Location: pkg_mgr_repos.php?saved=" . urlencode($result['id']));
		exit;
	}
} elseif ($existing) {
	$pconfig = $existing;
	/* the stored key is shown blank for a public key and kept unless retyped */
	if (($pconfig['signature_type'] ?? '') === 'pubkey') {
		$pconfig['key'] = '';
	}
} else {
	$pconfig = array('enable' => true, 'signature_type' => 'fingerprints');
}

$pgtitle = array(gettext('System'), gettext('Package Manager'), gettext('Repositories'), gettext('Edit Repository'));
$pglinks = array('', 'pkg_mgr_installed.php', 'pkg_mgr_repos.php', '@self');
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}
?>
<div class="alert alert-warning" role="alert">
	<?=gettext('Only add a repository you trust. Its packages are installed as root and can do anything on this firewall. FreeSense repositories always keep a higher priority than this one, so it can add packages but never replace FreeSense ones.')?>
</div>
<?php
$form = new Form;

$section = new Form_Section(gettext('Repository'));
$section->addInput(new Form_Checkbox(
	'enable',
	gettext('Enable'),
	gettext('Use this repository'),
	!empty($pconfig['enable'])
));
$section->addInput(new Form_Input(
	'name',
	'*' . gettext('Name'),
	'text',
	$pconfig['name'] ?? '',
	array('maxlength' => 32, 'pattern' => '[a-z][a-z0-9_-]{1,31}')
))->setHelp(gettext('Lowercase letters, digits, "-" and "_". Names starting with "freesense" or "freebsd" are reserved.'));
$section->addInput(new Form_Input(
	'descr',
	gettext('Description'),
	'text',
	$pconfig['descr'] ?? '',
	array('maxlength' => 120)
));
$section->addInput(new Form_Input(
	'url',
	'*' . gettext('Address'),
	'url',
	$pconfig['url'] ?? '',
	array('placeholder' => 'https://pkg.example.org/FreeBSD:16:amd64/latest')
))->setHelp(gettext('The https:// address of the repository for FreeBSD:16:amd64 (the folder that holds meta.conf or packagesite.pkg).'));
$form->add($section);

$section = new Form_Section(gettext('Verification'));
$section->addInput(new Form_Select(
	'signature_type',
	'*' . gettext('Signed with'),
	$pconfig['signature_type'] ?? 'fingerprints',
	array(
		'fingerprints' => gettext('A fingerprint of the repository key (recommended)'),
		'pubkey' => gettext('The repository public key'),
		'none' => gettext('Nothing: unsigned'),
	)
));
$section->addInput(new Form_Textarea(
	'key',
	gettext('Key'),
	$pconfig['key'] ?? ''
))->setHelp(gettext('Fingerprint: 64 hexadecimal digits (SHA-256 of the public key). Public key: a PEM "BEGIN PUBLIC KEY" block. Leave blank to keep the stored key.'));
$section->addInput(new Form_Checkbox(
	'ack_unsigned',
	gettext('Unsigned'),
	gettext('I understand an unsigned repository cannot be verified: anyone who can alter it, or the connection to it, can install code on this firewall.'),
	!empty($pconfig['ack_unsigned'])
));
$form->add($section);

if ($id !== null) {
	$form->addGlobal(new Form_Input('id', null, 'hidden', $id));
}
fs_form_cancel($form, 'pkg_mgr_repos.php');
print($form);
?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	function showKey() {
		var type = $('#signature_type').val();
		hideInput('key', type === 'none');
		hideCheckbox('ack_unsigned', type !== 'none');
	}
	$('#signature_type').on('change', showKey);
	showKey();
});
//]]>
</script>

<?php
include("foot.inc");
