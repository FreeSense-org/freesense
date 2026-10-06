<?php
/*
 * services_unbound_acls.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
 * Copyright (c) 2014 Warren Baker (warren@freesense.org)
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
##|*IDENT=page-services-dnsresolver-acls
##|*NAME=Services: DNS Resolver: Access Lists
##|*DESCR=Allow access to the 'Services: DNS Resolver: Access Lists' page.
##|*MATCH=services_unbound_acls.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("freesense-utils.inc");
require_once("unbound.inc");
require_once("services_unbound.inc");

$id = $_REQUEST['id'];

if (isset($_POST['aclid'])) {
	$id = $_POST['aclid'];
}

if (!empty($id) && !is_numeric($id)) {
	FreeSenseHeader("services_unbound_acls.php");
	exit;
}

$act = $_REQUEST['act'];

if ($_POST['act'] == "del") {
	if (!unbound_delete_acl($id)) {
		FreeSenseHeader("services_unbound_acls.php");
		exit;
	}
}

if ($act == "new") {
	$id = unbound_get_next_id();
}

if ($act == "edit") {
	if (isset($id) && config_get_path('unbound/acls/' . $id)) {
		$pconfig = config_get_path('unbound/acls/' . $id);
		$networkacl = config_get_path('unbound/acls/' . $id . '/row');
	}
}

if (!is_array($networkacl)) {
	$networkacl = array();
}

// Add a row to the networks table
if ($act == 'new') {
	$networkacl = array('0' => array('acl_network' => '', 'mask' => '', 'description' => ''));
}

if ($_POST['apply']) {
	$retval = unbound_apply_changes();
}
if ($_POST['save']) {
	unset($input_errors);
	$pconfig = $_POST;

	if (strtolower($pconfig['save']) == strtolower(gettext("Save"))) {
		/* An edit form posts the access list's position as "aclid"; a new one is added. */
		$rv = unbound_save_acl($_POST, ($act == "edit") ? $id : null);
		$input_errors = $rv['input_errors'];
		$networkacl = $rv['networkacl'];

		if (!$input_errors) {
			FreeSenseHeader("/services_unbound_acls.php");
			exit;
		}
	}
}

$actionHelp =
	sprintf(gettext('%1$sDeny:%2$s Stops queries from hosts within the netblock defined below.%3$s'), '<span class="text-success"><strong>', '</strong></span>', '<br />') .
	sprintf(gettext('%1$sRefuse:%2$s Stops queries from hosts within the netblock defined below, but sends a DNS rcode REFUSED error message back to the client.%3$s'), '<span class="text-success"><strong>', '</strong></span>', '<br />') .
	sprintf(gettext('%1$sAllow:%2$s Allow queries from hosts within the netblock defined below.%3$s'), '<span class="text-success"><strong>', '</strong></span>', '<br />') .
	sprintf(gettext('%1$sAllow Snoop:%2$s Allow recursive and nonrecursive access from hosts within the netblock defined below. Used for cache snooping and ideally should only be configured for the administrative host.%3$s'), '<span class="text-success"><strong>', '</strong></span>', '<br />') .
	sprintf(gettext('%1$sDeny Nonlocal:%2$s Allow only authoritative local-data queries from hosts within the netblock defined below. Messages that are disallowed are dropped.%3$s'), '<span class="text-success"><strong>', '</strong></span>', '<br />') .
	sprintf(gettext('%1$sRefuse Nonlocal:%2$s Allow only authoritative local-data queries from hosts within the netblock defined below. Sends a DNS rcode REFUSED error message back to the client for messages that are disallowed.'), '<span class="text-success"><strong>', '</strong></span>');

$pgtitle = array(gettext("Services"), gettext("DNS Resolver"), gettext("Access Lists"));
$pglinks = array("", "services_unbound.php", "@self");

if ($act == "new" || $act == "edit") {
	$pgtitle[] = gettext('Edit');
}
$shortcut_section = "resolver";
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($_POST['apply']) {
	print_apply_result_box($retval);
}

if (is_subsystem_dirty('unbound')) {
	print_apply_box(gettext("The DNS resolver configuration has been changed.") . "<br />" . gettext("The changes must be applied for them to take effect."));
}

fs_tabs('services-dnsresolver', 'services_unbound_acls.php');

if ($act == "new" || $act == "edit") {

	$form = new Form();

	$section = new Form_Section('New Access List');

	$form->addGlobal(new Form_Input(
		'aclid',
		null,
		'hidden',
		$id
	));

	$form->addGlobal(new Form_Input(
		'act',
		null,
		'hidden',
		$act
	));

	$section->addInput(new Form_Input(
		'aclname',
		'Access List name',
		'text',
		$pconfig['aclname']
	))->setHelp('Provide an Access List name.');

	$section->addInput(new Form_Select(
		'aclaction',
		'*Action',
		strtolower($pconfig['aclaction']),
		unbound_acl_actions()
	))->setHelp($actionHelp);

	$section->addInput(new Form_Input(
		'description',
		'Description',
		'text',
		$pconfig['description']
	))->setHelp('A description may be entered here for administrative reference.');

	$numrows = count($networkacl) - 1;
	$counter = 0;

	foreach ($networkacl as $item) {
		$network = $item['acl_network'];
		$cidr = $item['mask'];
		$description = $item['description'];

		$group = new Form_Group($counter == 0 ? '*Networks':'');

		$group->add(new Form_IpAddress(
			'acl_network'.$counter,
			null,
			$network
		))->addMask('mask' . $counter, $cidr, 128, 0)->setWidth(4)->setHelp(($counter == $numrows) ? 'Network/mask':null);

		$group->add(new Form_Input(
			'description' . $counter,
			null,
			'text',
			$description
		))->setHelp(($counter == $numrows) ? 'Description':null);

		$group->add(new Form_Button(
			'deleterow' . $counter,
			'Delete',
			null,
			'fa-solid fa-trash-can'
		))->addClass('btn-warning');

		$group->addClass('repeatable');
		$section->add($group);

		$counter++;
	}

	$form->addGlobal(new Form_Button(
		'addrow',
		'Add Network',
		null,
		'fa-solid fa-plus'
	))->addClass('btn-success');

	$form->add($section);
	print($form);
} else {
	// NOT 'edit' or 'add'
?>
<div class="panel panel-default">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('Access Lists to Control Access to the DNS Resolver')?></h2></div>
	<div class="panel-body">
		<div class="table-responsive">
			<table class="table table-striped table-hover table-sm sortable-theme-bootstrap" data-sortable>
				<thead>
					<tr>
						<th><?=gettext("Access List Name")?></th>
						<th><?=gettext("Action")?></th>
						<th><?=gettext("Description")?></th>
						<th><?=gettext("Actions")?></th>
					</tr>
				</thead>
				<tbody>
<?php
	$i = 0;
	foreach (config_get_path('unbound/acls', []) as $acl):
?>
					<tr ondblclick="document.location='services_unbound_acls.php?act=edit&amp;id=<?=$i?>'">
						<td>
							<?=htmlspecialchars($acl['aclname'])?>
						</td>
						<td>
							<?=htmlspecialchars($acl['aclaction'])?>
						</td>
						<td>
							<?=htmlspecialchars($acl['description'])?>
						</td>
						<td>
							<a class="fa-solid fa-pencil"	title="<?=gettext('Edit ACL')?>" href="services_unbound_acls.php?act=edit&amp;id=<?=$i?>"></a>
							<a class="fa-solid fa-trash-can"	title="<?=gettext('Delete ACL')?>" href="services_unbound_acls.php?act=del&amp;id=<?=$i?>" usepost></a>
						</td>
					</tr>
<?php
		$i++;
	endforeach;
?>
				</tbody>
			</table>
		</div>
	</div>
</div>

<nav class="action-buttons">
	<a href="services_unbound_acls.php?act=new" class="btn btn-sm btn-success">
		<i class="fa-solid fa-plus icon-embed-btn"></i>
		<?=gettext("Add")?>
	</a>
</nav>

<?php
}

?>
<script type="text/javascript">
//<![CDATA[
events.push(function() {

	// Suppress "Delete row" button if there are fewer than two rows
	checkLastRow();

});
//]]>
</script>

<?php
include("foot.inc");
