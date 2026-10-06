<?php
/*
 * system_authservers.php
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
##|*IDENT=page-system-authservers
##|*NAME=System: Authentication Servers
##|*DESCR=Allow access to the 'System: Authentication Servers' page.
##|*WARN=standard-warning-root
##|*MATCH=system_authservers.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("auth.inc");
require_once("freesense-utils.inc");
require_once("system_authservers.inc");

// Have we been called to populate the "Select a container" modal?
if ($_REQUEST['ajax']) {

	print(authsrv_ldap_containers_html($_REQUEST));

	exit;
}

$id = is_numericint($_REQUEST['id']) ? $_REQUEST['id'] : null;

$a_server = authsrv_list();

$act = $_REQUEST['act'];

if ($act == 'dup') {
	$dup = true;
	$act = 'edit';
}

/* The page had no read-only check: a read-only user could add, change and delete servers. */
phpsession_begin();
$guiuser = getUserEntry($_SESSION['Username']);
$guiuser = $guiuser['item'];
$read_only = (is_array($guiuser) && userHasPrivilege($guiuser, "user-config-readonly"));
phpsession_end();

if (!empty($_POST) && $read_only && !$_POST['ajax']) {
	$delete_errors = array(gettext("Insufficient privileges to make the requested change (read only)."));
}

if (($_POST['act'] == "del") && !$read_only) {

	$rv = authsrv_delete($_POST['id'], $guiuser);
	if ($rv === null) {
		FreeSenseHeader("system_authservers.php");
		exit;
	}
	if ($rv['deleted']) {
		$savemsg = $rv['savemsg'];
	} else {
		$delete_errors = $rv['errors'];
	}

	/* The list used later on this page. */
	$a_server = authsrv_list();
}

if ($act == "edit") {
	if (isset($id) && $a_server[$id]) {
		$pconfig = authsrv_form($a_server[$id], $dup ?? false);
	}
}

if ($act == "new") {
	$pconfig = authsrv_new_form();
}

if ($dup) {
	unset($id);
}

if ($_POST['save'] && !$read_only) {
	unset($input_errors);
	$pconfig = $_POST;

	$input_errors = authsrv_save($_POST, $id, $guiuser);
	if (!$input_errors) {
		FreeSenseHeader("system_authservers.php");
	}
}

// On error, restore the form contents so the user doesn't have to re-enter too much
if ($_POST && $input_errors) {
	$pconfig = $_POST;
	$pconfig['ldap_authcn'] = $_POST['ldapauthcontainers'];
	$pconfig['ldap_template'] = $_POST['ldap_tmpltype'];
}

$pgtitle = array(gettext("System"), gettext("User Manager"), gettext("Authentication Servers"));
$pglinks = array("", "system_usermanager.php", "system_authservers.php");

if ($act == "new" || $act == "edit" || $input_errors) {
	$pgtitle[] = gettext('Edit');
	$pglinks[] = "@self";
}
$shortcut_section = "authentication";
if (!($act == "new" || $act == "edit" || $input_errors)) {
	fs_page_action(gettext('Add server'), '?act=new', 'fa-plus');
}
include("head.inc");

if ($delete_errors) {
	print_input_errors($delete_errors);
}

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($savemsg) {
	print_info_box($savemsg, 'success');
}

fs_tabs('system-usermanager', 'system_authservers.php');

if (!($act == "new" || $act == "edit" || $input_errors)) {
?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Authentication Servers'),
	'search' => gettext('Search authentication servers…'),
	'noun' => gettext('authentication servers'),
	'noun_one' => gettext('authentication server'),
]); ?>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-hover table-rowdblclickedit" data-sortable>
				<thead>
					<tr>
						<th data-fs-search><?=gettext("Server Name")?></th>
						<th data-fs-search><?=gettext("Type")?></th>
						<th data-fs-search><?=gettext("Host Name")?></th>
						<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
					</tr>
				</thead>
				<tbody>
			<?php foreach ($a_server as $i => $server): ?>
					<tr>
						<td><?=htmlspecialchars($server['name'])?></td>
						<td><?=htmlspecialchars($auth_server_types[$server['type']])?></td>
						<td><?=htmlspecialchars($server['host'])?></td>
						<td class="fs-col-actions">
<?php if ($i < (count($a_server) - 1)): /* the last entry is the built-in Local Database */ ?>
							<?=fs_row_actions([
								['edit', "system_authservers.php?act=edit&id={$i}", $server['name']],
								['copy', "system_authservers.php?act=dup&id={$i}", $server['name']],
								['delete', "system_authservers.php?act=del&id={$i}", $server['name'], ['thing' => gettext('authentication server')]],
							])?>
						<?php endif?>
						</td>
					</tr>
			<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	</div>
</div>

<?php
	include("foot.inc");
	exit;
}

$form = new Form;
$form->setAction('system_authservers.php?act=edit');

$form->addGlobal(new Form_Input(
	'userid',
	null,
	'hidden',
	$id
));

$section = new Form_Section('Server Settings');

$section->addInput($input = new Form_Input(
	'name',
	'*Descriptive name',
	'text',
	$pconfig['name']
));

$section->addInput($input = new Form_Select(
	'type',
	'*Type',
	$pconfig['type'],
	$auth_server_types
))->toggles();

$form->add($section);

// ==== LDAP settings =========================================================
$section = new Form_Section('LDAP Server Settings');
$section->addClass('toggle-ldap collapse');

if (!isset($pconfig['type']) || $pconfig['type'] == 'ldap')
	$section->addClass('show');

$section->addInput(new Form_Input(
	'ldap_host',
	'*Hostname or IP address',
	'text',
	$pconfig['ldap_host']
))->setHelp('NOTE: When using SSL/TLS or STARTTLS, this hostname MUST match a Subject '.
	'Alternative Name (SAN) or the Common Name (CN) of the LDAP server SSL/TLS Certificate.');

$section->addInput(new Form_Input(
	'ldap_port',
	'*Port value',
	'number',
	$pconfig['ldap_port']
));

$section->addInput(new Form_Select(
	'ldap_urltype',
	'*Transport',
	$pconfig['ldap_urltype'],
	array_combine(array_keys($ldap_urltypes), array_keys($ldap_urltypes))
));

$ldapCaRef = array('global' => 'Global Root CA List');
foreach (config_get_path('ca', []) as $ca) {
	$ldapCaRef[$ca['refid']] = $ca['descr'];
}

$section->addInput(new Form_Select(
	'ldap_caref',
	'Peer Certificate Authority',
	$pconfig['ldap_caref'],
	$ldapCaRef
))->setHelp('This CA is used to validate the LDAP server certificate when '.
	'\'SSL/TLS Encrypted\' or \'STARTTLS Encrypted\' Transport is active. '.
	'This CA must match the CA used by the LDAP server.');

$section->addInput(new Form_Select(
	'ldap_protver',
	'*Protocol version',
	$pconfig['ldap_protver'],
	array_combine($ldap_protvers, $ldap_protvers)
));

$section->addInput(new Form_Input(
	'ldap_timeout',
	'Server Timeout',
	'number',
	$pconfig['ldap_timeout'],
	['placeholder' => 25]
))->setHelp('Timeout for LDAP operations (seconds)');

$group = new Form_Group('Search scope');

$SSF = new Form_Select(
	'ldap_scope',
	'*Level',
	$pconfig['ldap_scope'],
	$ldap_scopes
);

$SSB = new Form_Input(
	'ldap_basedn',
	'Base DN',
	'text',
	$pconfig['ldap_basedn']
);


$section->addInput(new Form_StaticText(
	'Search scope',
	'Level ' . $SSF . '<br />' . 'Base DN' . $SSB
));

$group = new Form_Group('*Authentication containers');
$group->add(new Form_Input(
	'ldapauthcontainers',
	'Containers',
	'text',
	$pconfig['ldap_authcn']
))->setHelp('Note: Semi-Colon separated. This will be prepended to the search '.
	'base dn above or the full container path can be specified containing a dc= '.
	'component.%1$sExample: CN=Users;DC=example,DC=com or OU=Staff;OU=Freelancers', '<br/>');

$group->add(new Form_Button(
	'Select',
	'Select a container',
	null,
	'fa-solid fa-magnifying-glass'
))->setAttribute('type','button')->addClass('btn-info');

$section->add($group);

$section->addInput(new Form_Checkbox(
	'ldap_extended_enabled',
	'Extended query',
	'Enable extended query',
	$pconfig['ldap_extended_enabled']
));

$group = new Form_Group('Query');
$group->addClass('extended');

$group->add(new Form_Input(
	'ldap_extended_query',
	'Query',
	'text',
	$pconfig['ldap_extended_query']
))->setHelp('Example (MSAD): memberOf=CN=Groupname,OU=MyGroups,DC=example,DC=com<br>Example (2307): |(&(objectClass=posixGroup)(cn=Groupname)(memberUid=*))(&(objectClass=posixGroup)(cn=anotherGroup)(memberUid=*))');

$section->add($group);

$section->addInput(new Form_Checkbox(
	'ldap_anon',
	'Bind anonymous',
	'Use anonymous binds to resolve distinguished names',
	$pconfig['ldap_anon']
));

$group = new Form_Group('*Bind credentials');
$group->addClass('ldapanon');

$group->add(new Form_Input(
	'ldap_binddn',
	'User DN:',
	'text',
	$pconfig['ldap_binddn']
));

$group->add(new Form_Input(
	'ldap_bindpw',
	'Password',
	'password',
	$pconfig['ldap_bindpw']
));
$section->add($group);

if (!isset($id)) {
	$template_list = array();

	foreach ($ldap_templates as $option => $template) {
		$template_list[$option] = $template['desc'];
	}

	$section->addInput(new Form_Select(
		'ldap_tmpltype',
		'Initial Template',
		$pconfig['ldap_template'],
		$template_list
	));
}

$section->addInput(new Form_Input(
	'ldap_attr_user',
	'*User naming attribute',
	'text',
	$pconfig['ldap_attr_user']
));

$section->addInput(new Form_Input(
	'ldap_attr_group',
	'*Group naming attribute',
	'text',
	$pconfig['ldap_attr_group']
));

$section->addInput(new Form_Input(
	'ldap_attr_member',
	'*Group member attribute',
	'text',
	$pconfig['ldap_attr_member']
));

$section->addInput(new Form_Checkbox(
	'ldap_rfc2307',
	'RFC 2307 Groups',
	'LDAP Server uses RFC 2307 style group membership',
	$pconfig['ldap_rfc2307']
))->setHelp('RFC 2307 style group membership has members listed on the group '.
	'object rather than using groups listed on user object. Leave unchecked '.
	'for Active Directory style group membership (RFC 2307bis).');

$group = new Form_Group('');
$group->addClass('ldap_rfc2307');

$group->add(new Form_Checkbox(
	'ldap_rfc2307_userdn',
	null,
	'RFC 2307 user DN',
	$pconfig['ldap_rfc2307_userdn']
))->setHelp('Use DN for username search, i.e. "(member=CN=Username,CN=Users,DC=example,DC=com)".');

$group->add(new Form_Checkbox(
	'ldap_rfc2307_basedn_groups',
	null,
	'RFC 2307 group Base DN',
	$pconfig['ldap_rfc2307_basedn_groups']
))->setHelp('Use Base DN for group search.');

$section->add($group);

$section->addInput(new Form_Input(
	'ldap_attr_groupobj',
	'Group Object Class',
	'text',
	$pconfig['ldap_attr_groupobj'],
	['placeholder' => 'posixGroup']
))->setHelp('Object class used for groups in RFC2307 mode. '.
	'Typically "posixGroup" or "group".');

$section->addInput(new Form_Input(
	'ldap_pam_groupdn',
	'Shell Authentication Group DN',
	'text',
	$pconfig['ldap_pam_groupdn']
))->setHelp('If LDAP server is used for shell authentication, user must be a member ' .
	    'of this group and have a valid posixAccount attributes to be able to login.%s Example: CN=Remoteshellusers,CN=Users,DC=example,DC=com',
	    '<br/>');

$section->addInput(new Form_Checkbox(
	'ldap_utf8',
	'UTF8 Encode',
	'UTF8 encode LDAP parameters before sending them to the server.',
	$pconfig['ldap_utf8']
))->setHelp('Required to support international characters, but may not be '.
	'supported by every LDAP server.');

$section->addInput(new Form_Checkbox(
	'ldap_nostrip_at',
	'Username Alterations',
	'Do not strip away parts of the username after the @ symbol',
	$pconfig['ldap_nostrip_at']
))->setHelp('e.g. user@host becomes user when unchecked.');

$section->addInput(new Form_Checkbox(
	'ldap_allow_unauthenticated',
	'Allow unauthenticated bind',
	'Allow unauthenticated bind',
	$pconfig['ldap_allow_unauthenticated']
))->setHelp('Unauthenticated binds are bind with an existing login but with an empty password. '.
         'Some LDAP servers (Microsoft AD) allow this type of bind without any possibility to disable it.');

$form->add($section);

// ==== RADIUS section ========================================================
$section = new Form_Section('RADIUS Server Settings');
$section->addClass('toggle-radius collapse');

$section->addInput(new Form_Select(
	'radius_protocol',
	'*Protocol',
	$pconfig['radius_protocol'],
	$radius_protocol
));

$section->addInput(new Form_Input(
	'radius_host',
	'*Hostname or IP address',
	'text',
	$pconfig['radius_host']
));

$section->addInput(new Form_Input(
	'radius_secret',
	'*Shared Secret',
	'password',
	$pconfig['radius_secret']
));

$section->addInput(new Form_Checkbox(
	'disable_radius_msg_auth',
	'Disable Message Authenticator',
	'Omit the RADIUS Message Authenticator attribute',
	$pconfig['disable_radius_msg_auth']
))->setHelp('Removes the RADIUS Message Authenticator attribute from Access Requests. This option may be necessary for legacy systems (default: unchecked).');

$section->addInput(new Form_Select(
	'radius_srvcs',
	'*Services offered',
	$pconfig['radius_srvcs'],
	$radius_srvcs
));

$section->addInput(new Form_Input(
	'radius_auth_port',
	'Authentication port',
	'number',
	$pconfig['radius_auth_port']
));

$section->addInput(new Form_Input(
	'radius_acct_port',
	'Accounting port',
	'number',
	$pconfig['radius_acct_port']
));

$section->addInput(new Form_Input(
	'radius_timeout',
	'Authentication Timeout',
	'number',
	$pconfig['radius_timeout']
))->setHelp('This value controls how long, in seconds, that the RADIUS '.
	'server may take to respond to an authentication request. If left blank, the '.
	'default value is 5 seconds. NOTE: If using an interactive two-factor '.
	'authentication system, increase this timeout to account for how long it will '.
	'take the user to receive and enter a token.');

$section->addInput(new Form_Select(
	'radius_nasip_attribute',
	'RADIUS NAS IP Attribute',
	$pconfig['radius_nasip_attribute'],
	authsrv_radiusnas_list()
))->setHelp('Enter the IP to use for the "NAS-IP-Address" attribute during RADIUS Access-Requests.<br />'.
			'Please note that this choice won\'t change the interface used for contacting the RADIUS server.');

if (isset($id) && $a_server[$id])
{
	$form->addGlobal(new Form_Input(
		'id',
		null,
		'hidden',
		$id
	));
}

$form->add($section);

// Create a largely empty modal to show the available containers. We will populate it via AJAX later
$modal = new Modal("LDAP containers", "containers", true);

$form->add($modal);

print $form;
?>
<script type="text/javascript">
//<![CDATA[
events.push(function() {

	// Create an AJAX request (to this page) to get the container list and controls
	function select_clicked() {
		if (document.getElementById("ldap_port").value == '' ||
			document.getElementById("ldap_host").value == '' ||
			document.getElementById("ldap_scope").value == '' ||
			document.getElementById("ldap_basedn").value == '' ||
			document.getElementById("ldapauthcontainers").value == '') {
			alert("<?=gettext("Please fill the required values.");?>");
			return;
		}

		if (!document.getElementById("ldap_anon").checked) {
			if (document.getElementById("ldap_binddn").value == '' ||
				document.getElementById("ldap_bindpw").value == '') {
				alert("<?=gettext("Please fill the bind username/password.");?>");
				return;
			}
		}

		var ajaxRequest;
		var authserver = $('#authmode').val();
		var cert;

<?php if (count(config_get_path('ca', [])) > 0): ?>
			cert = $('#ldap_caref').val();
<?php else: ?>
			cert = '';
<?php endif; ?>
/*
		bootstrap.Modal.getOrCreateInstance(document.getElementById('containers')).show();
		$('#serverlist').parent('div').prev('label').remove();
		$('#serverlist').parent('div').removeClass("col-sm-10");
		$('#serverlist').parent('div').addClass("col-sm-12");
*/
		ajaxRequest = $.ajax(
			{
				url: "/system_authservers.php",
				type: "post",
				data: {
					ajax: 	"ajax",
					port: 	$('#ldap_port').val(),
					host: 	$('#ldap_host').val(),
					scope: 	$('#ldap_scope').val(),
					basedn: $('#ldap_basedn').val(),
					binddn: $('#ldap_binddn').val(),
					bindpw: $('#ldap_bindpw').val(),
					urltype:$('#ldap_urltype').val(),
					proto:  $('#ldap_protver').val(),
					authcn: $('#ldapauthcontainers').val(),
					cert:   cert
				}
			}
		);

		// Deal with the results of the above ajax call
		ajaxRequest.done(function (response, textStatus, jqXHR) {
			$('#containers').replaceWith(response);

			bootstrap.Modal.getOrCreateInstance(document.getElementById('containers')).show();

			// The button handler needs to be here because until the modal has been populated
			// the controls we need to attach handlers to do not exist
			$('#svcontbtn').prop("type", "button");
			$('#svcontbtn').removeAttr("href");

			$('#svcontbtn').click(function () {
				var ous = $('[id^=ou]').length;
				var i;

				$('#ldapauthcontainers').val("");

				for (i = 0; i < ous; i++) {
					if ($('#ou' + i).prop("checked")) {
						if ($('#ldapauthcontainers').val() != "") {
							$('#ldapauthcontainers').val($('#ldapauthcontainers').val() +";");
						}

						$('#ldapauthcontainers').val($('#ldapauthcontainers').val() + $('#ou' + i).val());
					}
				}

				bootstrap.Modal.getOrCreateInstance(document.getElementById('containers')).hide();
			});
		});

	}

	function set_ldap_port() {
		if ($('#ldap_urltype').find(":selected").index() == 2)
			$('#ldap_port').val('636');
		else
			$('#ldap_port').val('389');
	}

	function set_required_port_fields() {
		if (document.getElementById("radius_srvcs").value == 'auth') {
			setRequired('radius_auth_port', true);
			setRequired('radius_acct_port', false);
		} else if (document.getElementById("radius_srvcs").value == 'acct') {
			setRequired('radius_auth_port', false);
			setRequired('radius_acct_port', true);
		} else { // both
			setRequired('radius_auth_port', true);
			setRequired('radius_acct_port', true);
		}
	}

	// Hides all elements of the specified class. This will usually be a section
	function hideClass(s_class, hide) {
		if (hide)
			$('.' + s_class).hide();
		else
			$('.' + s_class).show();
	}

	function ldap_tmplchange() {
		switch ($('#ldap_tmpltype').find(":selected").index()) {
<?php
		$index = 0;
		foreach ($ldap_templates as $tmpldata):
?>
			case <?=$index;?>:
				$('#ldap_attr_user').val("<?=$tmpldata['attr_user'];?>");
				$('#ldap_attr_group').val("<?=$tmpldata['attr_group'];?>");
				$('#ldap_attr_member').val("<?=$tmpldata['attr_member'];?>");
				$("#ldap_allow_unauthenticated").attr("checked", <?=$tmpldata['allow_unauthenticated'];?>);
				break;
<?php
			$index++;
		endforeach;
?>
		}
	}

	// ---------- On initial page load ------------------------------------------------------------

<?php if ($act != 'edit') : ?>
	ldap_tmplchange();
<?php endif; ?>

	hideClass('ldapanon', $('#ldap_anon').prop('checked'));
	hideClass('extended', !$('#ldap_extended_enabled').prop('checked'));
	hideClass('ldap_rfc2307', !$('#ldap_rfc2307').prop('checked'));
	set_required_port_fields();

	if ($('#ldap_port').val() == "")
		set_ldap_port();

<?php
	if ($act == 'edit') {
?>
		$('#type option:not(:selected)').each(function(){
			$(this).attr('disabled', 'disabled');
		});

<?php
		if (!$input_errors && !$dup) {
?>
		$('#name').prop("readonly", true);
<?php
		}
	}
?>
	// ---------- Click checkbox handlers ---------------------------------------------------------

	$('#ldap_tmpltype').on('change', function() {
		ldap_tmplchange();
	});

	$('#ldap_anon').click(function () {
		hideClass('ldapanon', this.checked);
	});

	$('#ldap_urltype').on('change', function() {
		set_ldap_port();
	});

	$('#Select').click(function () {
		select_clicked();
	});

	$('#ldap_extended_enabled').click(function () {
		hideClass('extended', !this.checked);
	});

	$('#ldap_rfc2307').click(function () {
		hideClass('ldap_rfc2307', !this.checked);
	});

	$('#radius_srvcs').on('change', function() {
		set_required_port_fields();
	});

});
//]]>
</script>
<?php
include("foot.inc");
