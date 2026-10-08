<?php
/*
 * system_crlmanager.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
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
##|*IDENT=page-system-crlmanager
##|*NAME=System: CRL Manager
##|*DESCR=Allow access to the 'System: CRL Manager' page.
##|*MATCH=system_crlmanager.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("certs.inc");
require_once("openvpn.inc");
require_once("freesense-utils.inc");
require_once("vpn.inc");
require_once("system_certificates.inc");

$max_lifetime = crl_get_max_lifetime();
$default_lifetime = pki_crl_default_lifetime();

global $openssl_crl_status;

$crl_methods = pki_crl_methods();

if (isset($_REQUEST['id']) && ctype_alnum($_REQUEST['id'])) {
	$id = $_REQUEST['id'];
}


/* Clean up blank entries missing a reference ID */
pki_crl_cleanup();

$act = $_REQUEST['act'];

$cacert_list = array();

/* act=new needs a CA. Without one (an old bookmark, a typed URL) show the list,
 * whose toolbar has the CA picker, instead of an "Invalid CA" error. */
if (($act == 'new') && empty($_POST) && empty($_REQUEST['caref'])) {
	header("Location: system_crlmanager.php");
	exit;
}

if (!empty($id)) {
	$crl_item_config = lookup_crl($id);
	$thiscrl = &$crl_item_config['item'];
}

/* Actions other than 'new' require a CRL to act upon.
 * 'del' action must be submitted via POST. */
if ((!empty($act) &&
    ($act != 'new') &&
    !$thiscrl) ||
    (($act == 'del') && empty($_POST))) {
	FreeSenseHeader("system_camanager.php");
	$act="";
	$savemsg = gettext("Invalid CRL reference.");
	$class = "danger";
}

switch ($act) {
	case 'del':
		$rv = pki_crl_delete($id);
		$savemsg = $rv['savemsg'];
		$class = $rv['class'];
		break;
	case 'new':
		$rv = pki_crl_new_form($_REQUEST['method'], $_REQUEST['caref']);
		$pconfig = $rv['pconfig'];
		$crlca = $rv['ca'];
		if (!$crlca) {
			$input_errors = $rv['input_errors'];
			unset($act);
		}
		break;
	case 'addcert':
		unset($input_errors);
		$pconfig = $_REQUEST;

		/* null: no CRL posted; no errors: saved */
		$input_errors = pki_crl_revoke($pconfig, $_POST);
		if (($input_errors === null) || !$input_errors) {
			FreeSenseHeader("system_crlmanager.php");
			exit;
		} else {
			$act = 'edit';
		}
		break;
	case 'delcert':
		$rv = pki_crl_unrevoke($crl_item_config, $_REQUEST['certref']);
		if ($rv === null) {
			FreeSenseHeader("system_crlmanager.php");
			exit;
		}
		$savemsg = $rv['savemsg'];
		$class = $rv['class'];
		$act="edit";
		break;
	case 'exp':
		/* Exporting the CRL contents*/
		send_user_download('data', pki_crl_export($crl_item_config), "{$thiscrl['descr']}.crl");
		break;
	default:
		break;
}

if ($_POST['save'] && empty($input_errors)) {
	$input_errors = array();
	$pconfig = $_POST;

	$input_errors = pki_crl_save($pconfig, $crl_item_config, $act);
	if (!$input_errors) {
		FreeSenseHeader("system_crlmanager.php");
	}
}

$pgtitle = array(gettext('System'), gettext('Certificates'), gettext('Revocation'));
$pglinks = array("", "system_camanager.php", "system_crlmanager.php");

if ($act == "new" || $act == gettext("Save") || $input_errors || $act == "edit") {
	$pgtitle[] = gettext('Edit');
	$pglinks[] = "@self";
}
include("head.inc");
?>

<script type="text/javascript">
//<![CDATA[

function method_change() {

	method = document.iform.method.value;

	switch (method) {
		case "internal":
			document.getElementById("existing").style.display="none";
			document.getElementById("internal").style.display="";
			break;
		case "existing":
			document.getElementById("existing").style.display="";
			document.getElementById("internal").style.display="none";
			break;
	}
}

//]]>
</script>

<?php

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($savemsg) {
	print_info_box($savemsg, $class);
}

fs_tabs('system-certificates', 'system_crlmanager.php');

if ($act == "new" || $act == gettext("Save")) {
	$form = new Form();

	$section = new Form_Section('Create new Revocation List');

	$section->addInput(new Form_StaticText(
		'Certificate Authority',
		$crlca['descr']
	));

	if (!isset($id)) {
		$section->addInput(new Form_Select(
			'method',
			'*Method',
			$pconfig['method'],
			pki_crl_method_list((!isset($crlca['prv']) || empty($crlca['prv'])))
		));
	}

	$section->addInput(new Form_Input(
		'descr',
		'*Descriptive name',
		'text',
		$pconfig['descr']
	));

	$form->addGlobal(new Form_Input(
		'caref',
		null,
		'hidden',
		$pconfig['caref']
	));

	$form->add($section);

	$section = new Form_Section('Existing Certificate Revocation List');
	$section->addClass('existing');

	$section->addInput(new Form_Textarea(
		'crltext',
		'*CRL data',
		$pconfig['crltext']
		))->setHelp('Paste a Certificate Revocation List in X.509 CRL format here.');

	$form->add($section);

	$section = new Form_Section('Internal Certificate Revocation List');
	$section->addClass('internal');

	$section->addInput(new Form_Input(
		'lifetime',
		'Lifetime (Days)',
		'number',
		$pconfig['lifetime'],
		['max' => $max_lifetime]
	));

	$section->addInput(new Form_Input(
		'serial',
		'Serial',
		'number',
		$pconfig['serial'],
		['min' => '0']
	));

	$form->add($section);

	if (isset($id) && $thiscrl) {
		$form->addGlobal(new Form_Input(
			'id',
			null,
			'hidden',
			$id
		));
	}

	print($form);

} elseif ($act == "editimported") {

	$form = new Form();

	$section = new Form_Section('Edit Imported Certificate Revocation List');

	$section->addInput(new Form_Input(
		'descr',
		'*Descriptive name',
		'text',
		$thiscrl['descr']
	));

	$section->addInput(new Form_Textarea(
		'crltext',
		'*CRL data',
		!empty($thiscrl['text']) ? base64_decode($thiscrl['text']) : ''
	))->setHelp('Paste a Certificate Revocation List in X.509 CRL format here.');

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
		'editimported'
	));

	$form->add($section);

	print($form);

} elseif ($act == "edit") {
	$crl = $thiscrl;

	$form = new Form();

	$section = new Form_Section('Edit Internal Certificate Revocation List');

	$section->addInput(new Form_Input(
		'descr',
		'*Descriptive name',
		'text',
		$crl['descr']
	));

	$section->addInput(new Form_Input(
		'lifetime',
		'CRL Lifetime (Days)',
		'number',
		$crl['lifetime'],
		['max' => $max_lifetime]
	));

	$section->addInput(new Form_Input(
		'serial',
		'CRL Serial',
		'number',
		$crl['serial'],
		['min' => '0']
	));

	$form->add($section);
?>

	<div class="panel panel-default">
		<div class="panel-heading"><h2 class="panel-title"><?=gettext("Revoked Certificates in CRL") . ': ' . $crl['descr']?></h2></div>
		<div class="panel-body table-responsive">
<?php
	if (!is_array($crl['cert']) || (count($crl['cert']) == 0)) {
		print_info_box(gettext("No certificates found in this CRL."), 'danger');
	} else {
?>
			<table class="table table-striped table-hover table-sm sortable-theme-bootstrap" data-sortable>
				<thead>
					<tr>
						<th><?=gettext("Serial")?></th>
						<th><?=gettext("Certificate Name")?></th>
						<th><?=gettext("Revocation Reason")?></th>
						<th><?=gettext("Revoked At")?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
<?php
		foreach ($crl['cert'] as $cert):
			$name = empty($cert['descr']) ? gettext('Revoked by Serial') : htmlspecialchars($cert['descr']);
			$serial = crl_get_entry_serial($cert);
			if (strlen($serial) == 0) {
				$serial = gettext("Invalid");
			} ?>
					<tr>
						<td><?=htmlspecialchars($serial);?></td>
						<td><?=$name; ?></td>
						<td><?=$openssl_crl_status[$cert['reason']]; ?></td>
						<td><?=date("D M j G:i:s T Y", $cert['revoke_time']); ?></td>
						<td class="list">
							<a href="system_crlmanager.php?act=delcert&amp;id=<?=$crl['refid']; ?>&amp;certref=<?=$cert['refid']; ?>" usepost>
								<i class="fa-solid fa-trash-can" title="<?=gettext("Delete this certificate from the CRL")?>" alt="<?=gettext("Delete this certificate from the CRL")?>"></i>
							</a>
						</td>
					</tr>
<?php
		endforeach;
?>
				</tbody>
			</table>
<?php
	}
?>
		</div>
	</div>
<?php

	$section = new Form_Section('Revoke Certificates');

	$section->addInput(new Form_Select(
		'crlreason',
		'Reason',
		-1,
		$openssl_crl_status
		))->setHelp('Select the reason for which the certificates are being revoked.');

	$cacert_list = pki_crl_cert_list($crl, $id);
	if (count($cacert_list) == 0) {
		print_info_box(gettext("No certificates found for this CA."), 'danger');
	} else {
		$section->addInput(new Form_Select(
			'certref',
			'Revoke Certificates',
			$pconfig['certref'],
			$cacert_list,
			true
			))->addClass('multiselect')
			->setHelp('Hold down CTRL (PC)/COMMAND (Mac) key to select multiple items.');
	}

	$section->addInput(new Form_Input(
		'revokeserial',
		'Revoke by Serial',
		'text',
		$pconfig['revokeserial']
	))->setHelp('List of certificate serial numbers to revoke (separated by spaces)');

	$form->addGlobal(new Form_Button(
		'submit',
		'Add',
		null,
		'fa-solid fa-plus'
		))->addClass('btn-primary');

	$form->addGlobal(new Form_Input(
		'id',
		null,
		'hidden',
		$crl['refid']
	));

	$form->addGlobal(new Form_Input(
		'act',
		null,
		'hidden',
		'addcert'
	));

	$form->addGlobal(new Form_Input(
		'crlref',
		null,
		'hidden',
		$crl['refid']
	));

	$form->add($section);

	print($form);
} else {
	/* create: pick the CA in the toolbar; the CRL editor (act=new) takes it from there */
	$crl_cas = pki_crl_ca_list();
	$crl_add = '';
	if (!empty($crl_cas)) {
		$crl_add = '<form method="post" action="system_crlmanager.php" class="d-flex flex-wrap gap-2 align-items-center">'
		    . '<input type="hidden" name="act" value="new">'
		    . '<select name="caref" class="form-select form-select-sm" aria-label="' . htmlspecialchars(gettext('Certificate Authority')) . '">';
		foreach ($crl_cas as $refid => $descr) {
			$crl_add .= '<option value="' . htmlspecialchars($refid) . '">' . htmlspecialchars($descr) . '</option>';
		}
		$crl_add .= '</select>'
		    . '<button type="submit" name="submit" value="Add" class="btn btn-sm btn-primary">'
		    . '<i class="fa-solid fa-plus icon-embed-btn" aria-hidden="true"></i>' . htmlspecialchars(gettext('Add CRL')) . '</button>'
		    . '</form>';
	}
?>

	<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext("Certificate Revocation Lists"),
	'search' => gettext('Search revocation lists…'),
	'noun' => gettext('revocation lists'),
	'noun_one' => gettext('revocation list'),
	'actions' => $crl_add,
]); ?>
		<div class="panel-body table-responsive">
			<table class="table table-hover table-rowdblclickedit">
				<thead>
					<tr>
						<th data-fs-search><?=gettext("CA")?></th>
						<th data-fs-search><?=gettext("Name")?></th>
						<th data-fs-search><?=gettext("Internal")?></th>
						<th data-fs-search><?=gettext("Certificates")?></th>
						<th data-fs-search><?=gettext("In Use")?></th>
						<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
					</tr>
				</thead>
				<tbody>
<?php
	$pluginparams = array();
	$pluginparams['type'] = 'certificates';
	$pluginparams['event'] = 'used_crl';
	$certificates_used_by_packages = pkg_call_plugins('plugin_certificates', $pluginparams);
	// Map CRLs to CAs in one pass
	$ca_crl_map = array();
	foreach (config_get_path('crl', []) as $crl) {
		$ca_crl_map[$crl['caref']][] = $crl['refid'];
	}

	$i = 0;
	foreach (config_get_path('ca', []) as $ca):
		$caname = htmlspecialchars($ca['descr']);
		if (is_array($ca_crl_map[$ca['refid']])):
			foreach ($ca_crl_map[$ca['refid']] as $crl):
				$tmpcrl = lookup_crl($crl);
				$tmpcrl = $tmpcrl['item'];
				$internal = is_crl_internal($tmpcrl);
				if ($internal && (!isset($tmpcrl['cert']) || empty($tmpcrl['cert'])) ) {
					$tmpcrl['cert'] = array();
				}
				$inuse = crl_in_use($tmpcrl['refid']);
?>
					<tr>
						<td><?=$caname?></td>
						<td><?=htmlspecialchars($tmpcrl['descr'])?></td>
						<td><?=$internal ? fs_badge('pass', gettext('Yes')) : fs_badge('neutral', gettext('No'))?></td>
						<td><?=($internal) ? count($tmpcrl['cert']) : "Unknown (imported)"; ?></td>
						<td>
						<?php if (is_openvpn_server_crl($tmpcrl['refid'])): ?>
							<?=gettext("OpenVPN Server")?>
						<?php endif?>
						<?php echo cert_usedby_description($tmpcrl['refid'], $certificates_used_by_packages); ?>
						</td>
						<td class="fs-col-actions">
<?php
	$crl_actions = [
		['edit', "system_crlmanager.php?act=" . ($internal ? 'edit' : 'editimported') . "&id={$tmpcrl['refid']}", $tmpcrl['descr']],
		['custom', "system_crlmanager.php?act=exp&id={$tmpcrl['refid']}", $tmpcrl['descr'], ['icon' => 'fa-solid fa-download', 'label' => gettext('Export CRL')]],
	];
	if (!$inuse) {
		$crl_actions[] = ['delete', "system_crlmanager.php?act=del&id={$tmpcrl['refid']}", $tmpcrl['descr'], ['thing' => gettext('CRL')]];
	}
?>
						<?=fs_row_actions($crl_actions)?>
						</td>
					</tr>
<?php
				$i++;
				endforeach;
			endif;
			$i++;
		endforeach;
?>
<?php if (empty(config_get_path('crl', []))) {
	fs_empty_row(6, gettext('No certificate revocation lists yet.'), null, null);
} ?>
				</tbody>
			</table>
		</div>
	</div>

<?php
}

?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {

	// Hides all elements of the specified class. This will usually be a section or group
	function hideClass(s_class, hide) {
		if (hide) {
			$('.' + s_class).hide();
		} else {
			$('.' + s_class).show();
		}
	}

	// When the 'method" selector is changed, we show/hide certain sections
	$('#method').on('change', function() {
		hideClass('internal', ($('#method').val() == 'existing'));
		hideClass('existing', ($('#method').val() == 'internal'));
	});

	hideClass('internal', ($('#method').val() == 'existing'));
	hideClass('existing', ($('#method').val() == 'internal'));
	$('.multiselect').attr("size","<?= max(3, min(15, count($cacert_list))) ?>");
});
//]]>
</script>

<?php include("foot.inc");
