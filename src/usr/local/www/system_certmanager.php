<?php
/*
 * system_certmanager.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
 * Copyright (c) 2008 Shrew Soft Inc
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
##|*IDENT=page-system-certmanager
##|*NAME=System: Certificate Manager
##|*DESCR=Allow access to the 'System: Certificate Manager' page.
##|*MATCH=system_certmanager.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("certs.inc");
require_once("freesense-utils.inc");
require_once("system_certificates.inc");

$cert_keylens = pki_key_lengths();
$cert_keytypes = pki_key_types();
$cert_types = pki_cert_types();

global $cert_altname_types;
global $openssl_digest_algs;
global $cert_strict_values;
global $p12_encryption_levels;

$max_lifetime = cert_get_max_lifetime();
$default_lifetime = pki_cert_default_lifetime();
$openssl_ecnames = cert_build_curve_list();
$class = "success";

if (isset($_REQUEST['userid']) && is_numericint($_REQUEST['userid'])) {
	$userid = $_REQUEST['userid'];
}

/* Internal CAs (with a private key) can sign certificates (the page counted certificates with a key). */
$internal_ca_count = count(pki_ca_signing_list());

if ($_REQUEST['exportp12']) {
	$act = 'p12';
} elseif ($_REQUEST['exportpkey']) {
	$act = 'key';
} else {
	$act = $_REQUEST['act'];
}

$cert_methods = pki_cert_methods($userid ?? null, $act);

if (isset($_REQUEST['id']) && ctype_alnum($_REQUEST['id'])) {
	$id = $_REQUEST['id'];
}
if (!empty($id)) {
	$cert_item_config = lookup_cert($id);
	$thiscert = &$cert_item_config['item'];
}

/* Actions other than 'new' require an ID.
 * 'del' action must be submitted via POST. */
if ((!empty($act) &&
    ($act != 'new') &&
    !$thiscert) ||
    (($act == 'del') && empty($_POST))) {
	FreeSenseHeader("system_certmanager.php");
	exit;
}

switch ($act) {
	case 'del':
		$rv = pki_cert_delete($id);
		$savemsg = $rv['savemsg'];
		$class = $rv['class'];
		unset($act);
		break;
	case 'new':
		/* New certificate, so set default values */
		$pconfig = pki_cert_new_form($_POST['method']);
		break;
	case 'edit':
		/* Editing a certificate, so populate values */
		$pconfig = pki_cert_form('edit', $thiscert);
		break;
	case 'csr':
		/* Editing a CSR, so populate values */
		$pconfig = pki_cert_form('csr', $thiscert);
		break;
	case 'exp':
		/* Exporting a certificate */
		send_user_download('data', base64_decode($thiscert['crt']), "{$thiscert['descr']}.crt");
		break;
	case 'req':
		/* Exporting a certificate signing request */
		send_user_download('data', base64_decode($thiscert['csr']), "{$thiscert['descr']}.req");
		break;
	case 'key':
		/* Exporting a private key */
		$keyout = base64_decode($thiscert['prv']);
		if (isset($_POST['exportpass']) && !empty($_POST['exportpass'])) {
			if ((strlen($_POST['exportpass']) < 4) or (strlen($_POST['exportpass']) > 1023)) {
				$savemsg = gettext("Export password must be in 4 to 1023 characters.");
				$class = 'danger';
				break;
			} else {
				$res_key = openssl_pkey_get_private($keyout);
				if ($res_key) {
					$args = array('encrypt_key_cipher' => OPENSSL_CIPHER_AES_256_CBC);
					openssl_pkey_export($res_key, $keyout, $_POST['exportpass'], $args);
				} else {
					$savemsg = gettext("Unable to export password-protected private key.");
					$class = 'danger';
				}
			}
		}
		if (!empty($keyout)) {
			send_user_download('data', $keyout, "{$thiscert['descr']}.key");
		}
		break;
	case 'p12':
		/* Exporting a PKCS#12 file containing the certificate, key, and (if present) CA */
		if (isset($_POST['exportpass']) && !empty($_POST['exportpass'])) {
			if ((strlen($_POST['exportpass']) < 4) or (strlen($_POST['exportpass']) > 1023)) {
				$savemsg = gettext("Export password must be in 4 to 1023 characters.");
				$class = 'danger';
				break;
			} else {
				$password = $_POST['exportpass'];
			}
		} else {
			$password = null;
		}
		if (isset($_POST['p12encryption']) &&
		    array_key_exists($_POST['p12encryption'], $p12_encryption_levels)) {
			$encryption = $_POST['p12encryption'];
		} else {
			$encryption = 'high';
		}
		cert_pkcs12_export($thiscert, $encryption, $password, true, 'download');
		break;
	default:
		break;
}

if ($_POST['save'] == gettext("Save")) {
	/* Creating a new entry */
	$input_errors = array();
	$pconfig = $_POST;
	$pkcs12_file = null;
	if (!empty($_FILES['pkcs12_cert']) && is_uploaded_file($_FILES['pkcs12_cert']['tmp_name'])) {
		$pkcs12_file = file_get_contents($_FILES['pkcs12_cert']['tmp_name']);
	}

	$input_errors = pki_cert_save($pconfig, $id ?? null, $act, $userid ?? null, $pkcs12_file, $savemsg, $unset_act);
	if ($unset_act) {
		unset($act);
	}

	if ((isset($userid) && is_numeric($userid)) && !$input_errors) {
		post_redirect("system_usermanager.php", array('act' => 'edit', 'userid' => $userid));
		exit;
	}
} elseif ($_POST['save'] == gettext("Update")) {
	/* Updating a certificate signing request */
	unset($input_errors);
	$pconfig = $_POST;

	$input_errors = pki_cert_csr_complete($pconfig, $cert_item_config, $savemsg);
	if (!$input_errors) {
		FreeSenseHeader("system_certmanager.php");
	}
}

$pgtitle = array(gettext('System'), gettext('Certificates'), gettext('Certificates'));
$pglinks = array("", "system_camanager.php", "system_certmanager.php");

if (($act == "new" || ($_POST['save'] == gettext("Save") && $input_errors)) ||
    ($act == "csr" || ($_POST['save'] == gettext("Update") && $input_errors))) {
	$pgtitle[] = gettext('Edit');
	$pglinks[] = "@self";
}
if (!(in_array($act, ['new', 'edit', 'csr']) ||
    ((($_POST['save'] == gettext("Save")) || ($_POST['save'] == gettext("Update"))) && $input_errors))) {
	fs_page_action(gettext('Add/Sign'), '?act=new', 'fa-plus');
}
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($savemsg) {
	print_info_box($savemsg, $class);
}

fs_tabs('system-certificates', 'system_certmanager.php');

if (in_array($act, array('new', 'edit')) || (($_POST['save'] == gettext("Save")) && $input_errors)) {
	$form = new Form();
	$form->setAction('system_certmanager.php')->setMultipartEncoding();

	if (isset($userid) && config_get_path("system/user")) {
		$form->addGlobal(new Form_Input(
			'userid',
			null,
			'hidden',
			$userid
		));
	}

	if (isset($id) && $thiscert) {
		$form->addGlobal(new Form_Input(
			'id',
			null,
			'hidden',
			$id
		));
	}

	if ($act) {
		$form->addGlobal(new Form_Input(
			'act',
			null,
			'hidden',
			$act
		));
	}

	switch ($act) {
		case 'edit':
			$maintitle = gettext('Edit an Existing Certificate');
			break;
		case 'new':
		default:
			$maintitle = gettext('Add/Sign a New Certificate');
			break;
	}

	$section = new Form_Section($maintitle);

	if (!isset($id) || ($act == 'edit')) {
		$section->addInput(new Form_Select(
			'method',
			'*Method',
			$pconfig['method'],
			$cert_methods
		))->toggles();
	}

	$section->addInput(new Form_Input(
		'descr',
		'*Descriptive name',
		'text',
		(isset($userid) && config_get_path('system/user') && empty($pconfig['descr'])) ? config_get_path("system/user/{$userid}/name") : $pconfig['descr']
	))->addClass('toggle-internal toggle-import toggle-edit toggle-external toggle-sign toggle-existing collapse')
	->setHelp('The name of this entry as displayed in the GUI for reference.%s' .
		'This name can contain spaces but it cannot contain any of the ' .
		'following characters: %s', '<br/>', "?, >, <, &, /, \, \", '");

	if (!empty($pconfig['cert'])) {
		$section->addInput(new Form_StaticText(
			"Identity",
			htmlspecialchars(cert_get_identity($pconfig['cert'], false, true))
		))->addClass('toggle-edit collapse');
	}

	$form->add($section);

	$section = new Form_Section("Automatic Renewal");
	$section->addClass('toggle-internal');
	if (($act == 'edit') &&
	    is_cert_locally_renewable($thiscert)) {
		$section->addClass('toggle-edit');
	}
	$section->addClass('collapse');

	$section->addInput(new Form_Checkbox(
		'autorenew',
		'Auto Renew',
		'Automatically renew this certificate before it would expire.',
		$pconfig['autorenew']
	))->setHelp('When enabled, the certificate will renew automatically once it reaches ' .
		'approximately 1/3 of its remaining lifetime using current recommended ' .
		'Strict Security settings.%1$s%1$s' .
		'This is the best practice for server certificates as the change should be ' .
		'seamless to clients in most cases, such as for OpenVPN or Mobile IPsec.%1$s%1$s' .
		'In most cases this should not be used on user certificates as those need further ' .
		'action after being renewed, such as delivering the new certificates to users.', '<br/>');

	$form->add($section);

	$section = new Form_Section('Sign CSR');
	$section->addClass('toggle-sign collapse');

	$section->AddInput(new Form_Select(
		'catosignwith',
		'*CA to sign with',
		$pconfig['catosignwith'],
		pki_ca_signing_list()
	));

	$section->AddInput(new Form_Select(
		'csrtosign',
		'*CSR to sign',
		isset($pconfig['csrtosign']) ? $pconfig['csrtosign'] : 'new',
		pki_cert_csr_list()
	));

	$section->addInput(new Form_Textarea(
		'csrpaste',
		'CSR data',
		$pconfig['csrpaste']
	))->setHelp('Paste a Certificate Signing Request in X.509 PEM format here.');

	$section->addInput(new Form_Textarea(
		'keypaste',
		'Key data',
		$pconfig['keypaste']
	))->setHelp('Optionally paste a private key here. The key will be associated with the newly signed certificate in %1$s', g_get('product_label'));

	$section->addInput(new Form_Input(
		'csrsign_lifetime',
		'*Certificate Lifetime (days)',
		'number',
		$pconfig['csrsign_lifetime'] ? $pconfig['csrsign_lifetime']:$default_lifetime,
		['max' => $max_lifetime]
	))->setHelp('The length of time the signed certificate will be valid, in days. %1$s' .
		'Server certificates should not have a lifetime over %2$s days or some platforms ' .
		'may consider the certificate invalid.', '<br/>', $cert_strict_values['max_server_cert_lifetime']);
	$section->addInput(new Form_Select(
		'csrsign_digest_alg',
		'*Digest Algorithm',
		$pconfig['csrsign_digest_alg'],
		array_combine($openssl_digest_algs, $openssl_digest_algs)
	))->setHelp('The digest method used when the certificate is signed. %1$s' .
		'The best practice is to use SHA256 or higher. '.
		'Some services and platforms, such as the GUI web server and OpenVPN, consider weaker digest algorithms invalid.', '<br/>');

	$form->add($section);

	if ($act == 'edit') {
		$editimport = gettext("Edit Certificate");
	} else {
		$editimport = gettext("Import Certificate");
	}

	$section = new Form_Section($editimport);
	$section->addClass('toggle-import toggle-edit collapse');

	$group = new Form_Group('Certificate Type');

	$group->add(new Form_Checkbox(
		'import_type',
		'Certificate Type',
		'X.509 (PEM)',
		(!isset($pconfig['import_type']) || $pconfig['import_type'] == 'x509'),
		'x509'
	))->displayAsRadio()->addClass('import_type_toggle');

	$group->add(new Form_Checkbox(
		'import_type',
		'Certificate Type',
		'PKCS #12 (PFX)',
		(isset($pconfig['import_type']) && $pconfig['import_type'] == 'pkcs12'),
		'pkcs12'
	))->displayAsRadio()->addClass('import_type_toggle');

	$section->add($group);

	$section->addInput(new Form_Textarea(
		'cert',
		'*Certificate data',
		$pconfig['cert']
	))->setHelp('Paste a certificate in X.509 PEM format here.');

	$section->addInput(new Form_Textarea(
		'key',
		'Private key data',
		$pconfig['key']
	))->setHelp('Paste a private key in X.509 PEM format here. This field may remain empty in certain cases, such as when the private key is stored on a PKCS#11 token.');

	$section->addInput(new Form_Input(
		'pkcs12_cert',
		'PKCS #12 certificate',
		'file',
		$pconfig['pkcs12_cert']
	))->setHelp('Select a PKCS #12 certificate store.');

	$section->addInput(new Form_Input(
		'pkcs12_pass',
		'PKCS #12 certificate password',
		'password',
		$pconfig['pkcs12_pass']
	))->setHelp('Enter the password to unlock the PKCS #12 certificate store.');

	$section->addInput(new Form_Checkbox(
		'pkcs12_intermediate',
		'Intermediates',
		'Import intermediate CAs',
		isset($pconfig['pkcs12_intermediate'])
	))->setHelp('Import any intermediate certificate authorities found in the PKCS #12 certificate store.');

	if ($act == 'edit') {
		$section->addInput(new Form_Input(
			'exportpass',
			'Export Password',
			'password',
			null,
			['placeholder' => gettext('Export Password'), 'autocomplete' => 'new-password']
		))->setHelp('Enter the password to use when using the export buttons below (not stored)')->addClass('toggle-edit collapse');
		$section->addInput(new Form_Select(
		'p12encryption',
		'PKCS#12 Encryption',
		'high',
		$p12_encryption_levels
		))->setHelp('Select the level of encryption to use when exporting a PKCS#12 archive. ' .
				'Encryption support varies by Operating System and program');
	}

	$form->add($section);
	$section = new Form_Section('Internal Certificate');
	$section->addClass('toggle-internal collapse');

	if (!$internal_ca_count) {
		$section->addInput(new Form_StaticText(
			'*Certificate authority',
			gettext('No internal Certificate Authorities have been defined. ') .
			gettext('An internal CA must be defined in order to create an internal certificate. ') .
			sprintf(gettext('%1$sCreate%2$s an internal CA.'), '<a href="system_camanager.php?act=new&amp;method=internal"> ', '</a>')
		));
	} else {
		$section->addInput(new Form_Select(
			'caref',
			'*Certificate authority',
			$pconfig['caref'],
			pki_ca_signing_list()
		));
	}

	$section->addInput(new Form_Select(
		'keytype',
		'*Key type',
		$pconfig['keytype'],
		array_combine($cert_keytypes, $cert_keytypes)
	));

	$group = new Form_Group($i == 0 ? '*Key length':'');
	$group->addClass('rsakeys');
	$group->add(new Form_Select(
		'keylen',
		null,
		$pconfig['keylen'],
		array_combine($cert_keylens, $cert_keylens)
	))->setHelp('The length to use when generating a new RSA key, in bits. %1$s' .
		'The Key Length should not be lower than 2048 or some platforms ' .
		'may consider the certificate invalid.', '<br/>');
	$section->add($group);

	$group = new Form_Group($i == 0 ? '*Elliptic Curve Name':'');
	$group->addClass('ecnames');
	$group->add(new Form_Select(
		'ecname',
		null,
		$pconfig['ecname'],
		$openssl_ecnames
	))->setHelp('Curves may not be compatible with all uses. Known compatible curve uses are denoted in brackets.');
	$section->add($group);

	$section->addInput(new Form_Select(
		'digest_alg',
		'*Digest Algorithm',
		$pconfig['digest_alg'],
		array_combine($openssl_digest_algs, $openssl_digest_algs)
	))->setHelp('The digest method used when the certificate is signed. %1$s' .
		'The best practice is to use SHA256 or higher. '.
		'Some services and platforms, such as the GUI web server and OpenVPN, consider weaker digest algorithms invalid.', '<br/>');

	$section->addInput(new Form_Input(
		'lifetime',
		'*Lifetime (days)',
		'number',
		$pconfig['lifetime'],
		['max' => $max_lifetime]
	))->setHelp('The length of time the signed certificate will be valid, in days. %1$s' .
		'Server certificates should not have a lifetime over %2$s days or some platforms ' .
		'may consider the certificate invalid.', '<br/>', $cert_strict_values['max_server_cert_lifetime']);

	$section->addInput(new Form_Input(
		'dn_commonname',
		'*Common Name',
		'text',
		$pconfig['dn_commonname'],
		['placeholder' => 'e.g. www.example.com']
	));

	$section->addInput(new Form_StaticText(
		null,
		gettext('The following certificate subject components are optional and may be left blank.')
	));

	$section->addInput(new Form_Select(
		'dn_country',
		'Country Code',
		$pconfig['dn_country'],
		get_cert_country_codes()
	));

	$section->addInput(new Form_Input(
		'dn_state',
		'State or Province',
		'text',
		$pconfig['dn_state'],
		['placeholder' => 'e.g. Texas']
	));

	$section->addInput(new Form_Input(
		'dn_city',
		'City',
		'text',
		$pconfig['dn_city'],
		['placeholder' => 'e.g. Austin']
	));

	$section->addInput(new Form_Input(
		'dn_organization',
		'Organization',
		'text',
		$pconfig['dn_organization'],
		['placeholder' => 'e.g. My Company Inc']
	));

	$section->addInput(new Form_Input(
		'dn_organizationalunit',
		'Organizational Unit',
		'text',
		$pconfig['dn_organizationalunit'],
		['placeholder' => 'e.g. My Department Name (optional)']
	));

	$form->add($section);
	$section = new Form_Section('External Signing Request');
	$section->addClass('toggle-external collapse');

	$section->addInput(new Form_Select(
		'csr_keytype',
		'*Key type',
		$pconfig['csr_keytype'],
		array_combine($cert_keytypes, $cert_keytypes)
	));

	$group = new Form_Group($i == 0 ? '*Key length':'');
	$group->addClass('csr_rsakeys');
	$group->add(new Form_Select(
		'csr_keylen',
		null,
		$pconfig['csr_keylen'],
		array_combine($cert_keylens, $cert_keylens)
	))->setHelp('The length to use when generating a new RSA key, in bits. %1$s' .
		'The Key Length should not be lower than 2048 or some platforms ' .
		'may consider the certificate invalid.', '<br/>');
	$section->add($group);

	$group = new Form_Group($i == 0 ? '*Elliptic Curve Name':'');
	$group->addClass('csr_ecnames');
	$group->add(new Form_Select(
		'csr_ecname',
		null,
		$pconfig['csr_ecname'],
		$openssl_ecnames
	));
	$section->add($group);

	$section->addInput(new Form_Select(
		'csr_digest_alg',
		'*Digest Algorithm',
		$pconfig['csr_digest_alg'],
		array_combine($openssl_digest_algs, $openssl_digest_algs)
	))->setHelp('The digest method used when the certificate is signed. %1$s' .
		'The best practice is to use SHA256 or higher. '.
		'Some services and platforms, such as the GUI web server and OpenVPN, consider weaker digest algorithms invalid.', '<br/>');

	$section->addInput(new Form_Input(
		'csr_dn_commonname',
		'*Common Name',
		'text',
		$pconfig['csr_dn_commonname'],
		['placeholder' => 'e.g. internal-ca']
	));

	$section->addInput(new Form_StaticText(
		null,
		gettext('The following certificate subject components are optional and may be left blank.')
	));

	$section->addInput(new Form_Select(
		'csr_dn_country',
		'Country Code',
		$pconfig['csr_dn_country'],
		get_cert_country_codes()
	));

	$section->addInput(new Form_Input(
		'csr_dn_state',
		'State or Province',
		'text',
		$pconfig['csr_dn_state'],
		['placeholder' => 'e.g. Texas']
	));

	$section->addInput(new Form_Input(
		'csr_dn_city',
		'City',
		'text',
		$pconfig['csr_dn_city'],
		['placeholder' => 'e.g. Austin']
	));

	$section->addInput(new Form_Input(
		'csr_dn_organization',
		'Organization',
		'text',
		$pconfig['csr_dn_organization'],
		['placeholder' => 'e.g. My Company Inc']
	));

	$section->addInput(new Form_Input(
		'csr_dn_organizationalunit',
		'Organizational Unit',
		'text',
		$pconfig['csr_dn_organizationalunit'],
		['placeholder' => 'e.g. My Department Name (optional)']
	));

	$form->add($section);
	$section = new Form_Section('Choose an Existing Certificate');
	$section->addClass('toggle-existing collapse');

	$existCerts = pki_cert_existing_list($userid ?? null);

	$section->addInput(new Form_Select(
		'certref',
		'*Existing Certificates',
		$pconfig['certref'],
		$existCerts
	));

	$form->add($section);

	$section = new Form_Section('Certificate Attributes');
	$section->addClass('toggle-external toggle-internal toggle-sign collapse');

	$section->addInput(new Form_StaticText(
		gettext('Attribute Notes'),
		'<span class="help-block">'.
		gettext('The following attributes are added to certificates and ' .
		'requests when they are created or signed. These attributes behave ' .
		'differently depending on the selected mode.') .
		'<br/><br/>' .
		'<span class="toggle-internal collapse">' . gettext('For Internal Certificates, these attributes are added directly to the certificate as shown.') . '</span>' .
		'<span class="toggle-external collapse">' .
		gettext('For Certificate Signing Requests, These attributes are added to the request but they may be ignored or changed by the CA that signs the request. ') .
		'<br/><br/>' .
		gettext('If this CSR will be signed using the Certificate Manager on this firewall, set the attributes when signing instead as they cannot be carried over.') . '</span>' .
		'<span class="toggle-sign collapse">' . gettext('When Signing a Certificate Request, existing attributes in the request cannot be copied. The attributes below will be applied to the resulting certificate.') . '</span>' .
		'</span>'
	));

	$section->addInput(new Form_Select(
		'type',
		'*Certificate Type',
		$pconfig['type'],
		$cert_types
	))->setHelp('Add type-specific usage attributes to the signed certificate.' .
		' Used for placing usage restrictions on, or granting abilities to, ' .
		'the signed certificate.');

	if (empty($pconfig['altnames']['item'])) {
		$pconfig['altnames']['item'] = array(
			array('type' => null, 'value' => null)
		);
	}

	$counter = 0;
	$numrows = count($pconfig['altnames']['item']) - 1;

	foreach ($pconfig['altnames']['item'] as $item) {

		$group = new Form_Group($counter == 0 ? 'Alternative Names':'');

		$group->add(new Form_Select(
			'altname_type' . $counter,
			'Type',
			$item['type'],
			$cert_altname_types
		))->setHelp(($counter == $numrows) ? 'Type':null);

		$group->add(new Form_Input(
			'altname_value' . $counter,
			null,
			'text',
			$item['value']
		))->setHelp(($counter == $numrows) ? 'Value':null);

		$group->add(new Form_Button(
			'deleterow' . $counter,
			'Delete',
			null,
			'fa-solid fa-trash-can'
		))->addClass('btn-warning');

		$group->addClass('repeatable');

		$group->setHelp('Enter additional identifiers for the certificate ' .
			'in this list. The Common Name field is automatically ' .
			'added to the certificate as an Alternative Name. ' .
			'The signing CA may ignore or change these values.');

		$section->add($group);

		$counter++;
	}

	$section->addInput(new Form_Button(
		'addrow',
		'Add SAN Row',
		null,
		'fa-solid fa-plus'
	))->addClass('btn-success');

	$form->add($section);

	if (($act == 'edit') && !empty($pconfig['key'])) {
		$form->addGlobal(new Form_Button(
			'exportpkey',
			'Export Private Key',
			null,
			'fa-solid fa-key'
		))->addClass('btn-primary');
		$form->addGlobal(new Form_Button(
			'exportp12',
			'Export PKCS#12',
			null,
			'fa-solid fa-box-archive'
		))->addClass('btn-primary');
	}

	print $form;

} elseif ($act == "csr" || (($_POST['save'] == gettext("Update")) && $input_errors)) {
	$form = new Form(false);
	$form->setAction('system_certmanager.php?act=csr');

	$section = new Form_Section("Complete Signing Request for " . $pconfig['descr']);

	$section->addInput(new Form_Input(
		'descr',
		'*Descriptive name',
		'text',
		$pconfig['descr']
	))->setHelp('The name of this entry as displayed in the GUI for reference.%s' .
		'This name can contain spaces but it cannot contain any of the ' .
		'following characters: %s', '<br/>', "?, >, <, &, /, \, \", '");

	$section->addInput(new Form_Textarea(
		'csr',
		'Signing request data',
		$pconfig['csr']
	))->setReadonly()
	  ->setWidth(7)
	  ->setHelp('Copy the certificate signing data from here and forward it to a certificate authority for signing.');

	$section->addInput(new Form_Textarea(
		'cert',
		'*Final certificate data',
		$pconfig['cert']
	))->setWidth(7)
	  ->setHelp('Paste the certificate received from the certificate authority here.');

	if (isset($id) && $thiscert) {
		$form->addGlobal(new Form_Input(
			'id',
			null,
			'hidden',
			$id
		));

		$form->addGlobal(new Form_Input(
			'act',
			null,
			'hidden',
			'csr'
		));
	}

	$form->add($section);

	$form->addGlobal(new Form_Button(
		'save',
		'Update',
		null,
		'fa-solid fa-floppy-disk'
	))->addClass('btn-primary');

	print($form);
} else {
?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Certificates'),
	'search' => gettext('Search certificates…'),
	'noun' => gettext('certificates'),
	'noun_one' => gettext('certificate'),
]); ?>
	<div class="panel-body">
		<div class="table-responsive">
		<table class="table table-hover" data-sortable>
			<thead>
				<tr>
					<th data-fs-search><?=gettext("Name")?></th>
					<th data-fs-search><?=gettext("Issuer")?></th>
					<th data-fs-search><?=gettext("Identity")?></th>
					<th data-fs-search><?=gettext("In Use")?></th>

					<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
				</tr>
			</thead>
			<tbody>
<?php

$pluginparams = array();
$pluginparams['type'] = 'certificates';
$pluginparams['event'] = 'used_certificates';
$certificates_used_by_packages = pkg_call_plugins('plugin_certificates', $pluginparams);
foreach (config_get_path('cert', []) as $cert):
	if (!is_array($cert) || empty($cert)) {
		continue;
	}
	$name = htmlspecialchars($cert['descr']);
	if ($cert['crt']) {
		$subj = cert_get_subject($cert['crt']);
		$issuer = cert_get_issuer($cert['crt']);
		$purpose = cert_get_purpose($cert['crt']);

		if ($subj == $issuer) {
			$caname = '<i>'. gettext("self-signed") .'</i>';
		} else {
			$caname = '<i>'. gettext("external").'</i>';
		}

		$identity = cert_get_identity($cert['crt'], true, true);
	} else {
		$subj = "";
		$identity = "";
		$issuer = "";
		$purpose = "";
		$startdate = "";
		$enddate = "";
		$caname = "<em>" . gettext("private key only") . "</em>";
	}

	if ($cert['csr']) {
		$identity = cert_get_identity($cert['csr'], true, true);
		$caname = "<em>" . gettext("external - signature pending") . "</em>";
	}

	$ca = lookup_ca($cert['caref']);
	$ca = $ca['item'];
	if ($ca) {
		$caname = htmlspecialchars($ca['descr']);
	}
?>
				<tr>
					<td>
						<?=$name?>
						<?php if ($cert['autorenew'] == 'enabled'): ?>
							<i class="fa-solid fa-rotate" title="<?=gettext('Auto-Renews')?>"></i>
						<?php endif ?>
						<br />
						<?php if ($cert['type']): ?>
							<i><?=$cert_types[$cert['type']]?></i><br />
						<?php endif ?>
						<?php if (is_array($purpose)): ?>
							CA: <b><?=$purpose['ca']?></b><br/>
							<?=gettext("Server")?>: <b><?=$purpose['server']?></b><br/>
						<?php endif ?>
					</td>
					<td><?=$caname?></td>
					<td>
						<?=htmlspecialchars($identity)?>
						<?= cert_print_infoblock($cert); ?>
						<?php cert_print_dates($cert);?>
					</td>
					<td>
						<?php if (is_cert_revoked($cert)): ?>
							<i><?=gettext("Revoked")?></i><br/>
						<?php endif?>
						<?php if (is_captiveportal_cert($cert['refid'])): ?>
							<?=gettext("Captive Portal")?><br/>
						<?php endif?>
						<?php if (is_unbound_cert($cert['refid'])): ?>
							<?=gettext("DNS Resolver")?><br/>
						<?php endif?>
						<?php if (is_ipsec_cert($cert['refid'])): ?>
							<?=gettext("IPsec Tunnel")?><br/>
						<?php endif?>
						<?php if (is_kea_cert($cert['refid'])): ?>
							<?=gettext("Kea")?><br/>
						<?php endif?>
						<?php if (is_openvpn_client_cert($cert['refid'])): ?>
							<?=gettext("OpenVPN Client")?><br/>
						<?php endif?>
						<?php if (is_openvpn_server_cert($cert['refid'])): ?>
							<?=gettext("OpenVPN Server")?><br/>
						<?php endif?>
						<?php if (is_user_cert($cert['refid'])): ?>
							<?=gettext("User Cert")?><br/>
						<?php endif?>
						<?php if (is_webgui_cert($cert['refid'])): ?>
							<?=gettext("webConfigurator")?><br/>
						<?php endif?>
						<?php echo cert_usedby_description($cert['refid'], $certificates_used_by_packages); ?>
					</td>
					<td class="fs-col-actions">
<?php
	$item_label = $cert['descr'];
	$cert_actions = [];
	if (!$cert['csr']) {
		$cert_actions[] = ['edit', "system_certmanager.php?act=edit&id={$cert['refid']}", $item_label];
		$cert_actions[] = ['custom', "system_certmanager.php?act=exp&id={$cert['refid']}", $item_label, ['icon' => 'fa-solid fa-certificate', 'label' => gettext('Export Certificate')]];
		if ($cert['prv']) {
			$cert_actions[] = ['custom', "system_certmanager.php?act=key&id={$cert['refid']}", $item_label, ['icon' => 'fa-solid fa-key', 'label' => gettext('Export Key')]];
			$cert_actions[] = ['custom', "system_certmanager.php?act=p12&id={$cert['refid']}", $item_label, ['icon' => 'fa-solid fa-box-archive', 'label' => gettext('Export PKCS#12 Archive without Encryption')]];
		}
		if (is_cert_locally_renewable($cert)) {
			$cert_actions[] = ['custom', "system_certmanager_renew.php?type=cert&refid={$cert['refid']}", $item_label, ['icon' => 'fa-solid fa-arrow-rotate-right', 'label' => gettext('Reissue/Renew')]];
		}
	} else {
		$cert_actions[] = ['custom', "system_certmanager.php?act=csr&id={$cert['refid']}", $item_label, ['icon' => 'fa-solid fa-pencil', 'label' => gettext('Update CSR')]];
		$cert_actions[] = ['custom', "system_certmanager.php?act=req&id={$cert['refid']}", $item_label, ['icon' => 'fa-solid fa-right-to-bracket', 'label' => gettext('Export Request')]];
		$cert_actions[] = ['custom', "system_certmanager.php?act=key&id={$cert['refid']}", $item_label, ['icon' => 'fa-solid fa-key', 'label' => gettext('Export Key')]];
	}
	if (!cert_in_use($cert['refid'])) {
		$cert_actions[] = ['delete', "system_certmanager.php?act=del&id={$cert['refid']}", $item_label, ['thing' => gettext('certificate')]];
	}
?>
						<?=fs_row_actions($cert_actions)?>
					</td>
				</tr>
<?php
	endforeach; ?>
<?php if (empty(config_get_path('cert', []))) {
	fs_empty_row(5, gettext('No certificates yet.'), '?act=new', gettext('Add/Sign'));
} ?>
			</tbody>
		</table>
		</div>
	</div>
</div>

<?php
	include("foot.inc");
	exit;
}


?>
<script type="text/javascript">
//<![CDATA[
events.push(function() {

	$('.import_type_toggle').click(function() {
		var x509 = (this.value === 'x509');
		hideInput('cert', !x509);
		setRequired('cert', x509);
		hideInput('key', !x509);
		setRequired('key', x509);
		hideInput('pkcs12_cert', x509);
		setRequired('pkcs12_cert', !x509);
		hideInput('pkcs12_pass', x509);
		hideCheckbox('pkcs12_intermediate', x509);
	});
	if ($('input[name=import_type]:checked').val() == 'x509') {
		hideInput('pkcs12_cert', true);
		setRequired('pkcs12_cert', false);
		hideInput('pkcs12_pass', true);
		hideCheckbox('pkcs12_intermediate', true);
		hideInput('cert', false);
		setRequired('cert', true);
		hideInput('key', false);
		setRequired('key', true);
	} else if ($('input[name=import_type]:checked').val() == 'pkcs12') {
		hideInput('cert', true);
		setRequired('cert', false);
		hideInput('key', true);
		setRequired('key', false);
		setRequired('pkcs12_cert', false);
	}

	function internalca_change() {

		caref = $('#caref').val();

		switch (caref) {
<?php
			foreach (config_get_path('ca', []) as $ca):
				if (!$ca['prv']) {
					continue;
				}

				$subject = @cert_get_subject_hash($ca['crt']);
				if (!is_array($subject) || empty($subject)) {
					continue;
				}
?>
				case "<?=$ca['refid'];?>":
					$('#dn_country').val(<?=json_encode(cert_escape_x509_chars($subject['C'], true));?>);
					$('#dn_state').val(<?=json_encode(cert_escape_x509_chars($subject['ST'], true));?>);
					$('#dn_city').val(<?=json_encode(cert_escape_x509_chars($subject['L'], true));?>);
					$('#dn_organization').val(<?=json_encode(cert_escape_x509_chars($subject['O'], true));?>);
					$('#dn_organizationalunit').val(<?=json_encode(cert_escape_x509_chars($subject['OU'], true));?>);
					break;
<?php
			endforeach;
?>
		}
	}

	function set_csr_ro() {
		var newcsr = ($('#csrtosign').val() == "new");

		$('#csrpaste').attr('readonly', !newcsr);
		$('#keypaste').attr('readonly', !newcsr);
		setRequired('csrpaste', newcsr);
	}

	function check_lifetime() {
		var maxserverlife = <?= $cert_strict_values['max_server_cert_lifetime'] ?>;
		var ltid = '#lifetime';
		if ($('#method').val() == "sign") {
			ltid = '#csrsign_lifetime';
		}
		if (($('#type').val() == "server") && (parseInt($(ltid).val()) > maxserverlife)) {
			$(ltid).parent().parent().removeClass("text-normal").addClass("text-warning");
			$(ltid).removeClass("text-normal").addClass("text-warning");
		} else {
			$(ltid).parent().parent().removeClass("text-warning").addClass("text-normal");
			$(ltid).removeClass("text-warning").addClass("text-normal");
		}
	}
	function check_keylen() {
		var min_keylen = <?= $cert_strict_values['min_private_key_bits'] ?>;
		var klid = '#keylen';
		if ($('#method').val() == "external") {
			klid = '#csr_keylen';
		}
		/* Color the Parent/Label */
		if (parseInt($(klid).val()) < min_keylen) {
			$(klid).parent().parent().removeClass("text-normal").addClass("text-warning");
		} else {
			$(klid).parent().parent().removeClass("text-warning").addClass("text-normal");
		}
		/* Color individual options */
		$(klid + " option").filter(function() {
			return parseInt($(this).val()) < min_keylen;
		}).removeClass("text-normal").addClass("text-warning").siblings().removeClass("text-warning").addClass("text-normal");
	}

	function check_digest() {
		var weak_algs = <?= json_encode($cert_strict_values['digest_blacklist']) ?>;
		var daid = '#digest_alg';
		if ($('#method').val() == "external") {
			daid = '#csr_digest_alg';
		} else if ($('#method').val() == "sign") {
			daid = '#csrsign_digest_alg';
		}
		/* Color the Parent/Label */
		if (jQuery.inArray($(daid).val(), weak_algs) > -1) {
			$(daid).parent().parent().removeClass("text-normal").addClass("text-warning");
		} else {
			$(daid).parent().parent().removeClass("text-warning").addClass("text-normal");
		}
		/* Color individual options */
		$(daid + " option").filter(function() {
			return (jQuery.inArray($(this).val(), weak_algs) > -1);
		}).removeClass("text-normal").addClass("text-warning").siblings().removeClass("text-warning").addClass("text-normal");
	}

	// ---------- Click checkbox handlers ---------------------------------------------------------

	$('#type').on('change', function() {
		check_lifetime();
	});
	$('#method').on('change', function() {
		check_lifetime();
		check_keylen();
		check_digest();
	});
	$('#lifetime').on('change', function() {
		check_lifetime();
	});
	$('#csrsign_lifetime').on('change', function() {
		check_lifetime();
	});

	$('#keylen').on('change', function() {
		check_keylen();
	});
	$('#csr_keylen').on('change', function() {
		check_keylen();
	});

	$('#digest_alg').on('change', function() {
		check_digest();
	});
	$('#csr_digest_alg').on('change', function() {
		check_digest();
	});

	$('#caref').on('change', function() {
		internalca_change();
	});

	$('#csrtosign').change(function () {
		set_csr_ro();
	});

	function change_keytype() {
		hideClass('rsakeys', ($('#keytype').val() != 'RSA'));
		hideClass('ecnames', ($('#keytype').val() != 'ECDSA'));
	}

	$('#keytype').change(function () {
		change_keytype();
	});

	function change_csrkeytype() {
		hideClass('csr_rsakeys', ($('#csr_keytype').val() != 'RSA'));
		hideClass('csr_ecnames', ($('#csr_keytype').val() != 'ECDSA'));
	}

	$('#csr_keytype').change(function () {
		change_csrkeytype();
	});

	// ---------- On initial page load ------------------------------------------------------------

	internalca_change();
	set_csr_ro();
	change_keytype();
	change_csrkeytype();
	check_lifetime();
	check_keylen();
	check_digest();

	// Suppress "Delete row" button if there are fewer than two rows
	checkLastRow();




});
//]]>
</script>
<?php
include('foot.inc');
