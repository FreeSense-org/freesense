<?php
/*
 * services_ntpd_acls.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
 * Copyright (c) 2013 Dagorlad
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
##|*IDENT=page-services-ntpd-acls
##|*NAME=Services: NTP ACL Settings
##|*DESCR=Allow access to the 'Services: NTP ACL Settings' page.
##|*MATCH=services_ntpd_acls.php*
##|-PRIV

require_once("guiconfig.inc");
require_once('rrd.inc');
require_once("shaper.inc");
require_once("services_ntpd.inc");

$networkacl = ntpd_acl_rows();

if ($_POST) {

	unset($input_errors);
	$rv = ntpd_save_acls($_POST);
	$input_errors = $rv['input_errors'];
	$networkacl = $rv['networkacl'];
	if ($rv['changes_applied']) {
		$changes_applied = true;
		$retval = $rv['retval'];
	}
}

$pconfig = config_get_path('ntpd', []);

$pgtitle = array(gettext("Services"), gettext("NTP"), gettext("ACLs"));
$pglinks = array("", "services_ntpd.php", "@self");
$shortcut_section = "ntp";
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($changes_applied) {
	print_apply_result_box($retval);
}

$tab_array = array();
$tab_array[] = array(gettext("Settings"), false, "services_ntpd.php");
$tab_array[] = array(gettext("ACLs"), true, "services_ntpd_acls.php");
$tab_array[] = array(gettext("Serial GPS"), false, "services_ntpd_gps.php");
$tab_array[] = array(gettext("PPS"), false, "services_ntpd_pps.php");
display_top_tabs($tab_array);

$form = new Form;

$section = new Form_Section('Default Access Restrictions');

$section->addInput(new Form_Checkbox(
	'kod',
	'Kiss-o\'-death',
	'Enable KOD packets.',
	!$pconfig['kod']
));

$section->addInput(new Form_Checkbox(
	'nomodify',
	"Modifications",
	'Deny run-time Configuration (nomodify) by ntpq and ntpdc.',
	!$pconfig['nomodify']
));

$section->addInput(new Form_Checkbox(
	'noquery',
	'Queries',
	'Disable ntpq and ntpdc queries (noquery).',
	$pconfig['noquery']
));

$section->addInput(new Form_Checkbox(
	'noserve',
	'Service',
	'Disable all except ntpq and ntpdc queries (noserve).',
	$pconfig['noserve']
));

$section->addInput(new Form_Checkbox(
	'nopeer',
	'Peer Association',
	'Deny packets that attempt a peer association (nopeer).',
	!$pconfig['nopeer']
));

$section->addInput(new Form_Checkbox(
	'notrap',
	'Trap Service',
	'Deny mode 6 control message trap service (notrap).',
	!$pconfig['notrap']
));

/* End Default Restrictions*/
$form->add($section);

$section = new Form_Section('Custom Access Restrictions');

$numrows = count($networkacl) - 1;
$counter = 0;

foreach ($networkacl as $item) {
	$group = new Form_Group($counter == 0 ? 'Networks':'');

	$helptext = ($counter == $numrows) ? gettext('Network/mask'):"";

	$group->add(new Form_IpAddress(
		'acl_network' . $counter,
		null,
		$item['acl_network']
	))->addMask('mask' . $counter, $item['mask'])->setWidth(3)->setHelp($helptext);

	$group->add(new Form_Checkbox(
		'kod' . $counter,
		null,
		null,
		$item['kod']
	))->setHelp('KOD');

	$group->add(new Form_Checkbox(
		'nomodify' . $counter,
		null,
		null,
		$item['nomodify']
	))->setHelp('nomodify');

	$group->add(new Form_Checkbox(
		'noquery' . $counter,
		null,
		null,
		$item['noquery']
	))->setHelp('noquery');

	$group->add(new Form_Checkbox(
		'noserve' . $counter,
		null,
		null,
		$item['noserve']
	))->setHelp('noserve');

	$group->add(new Form_Checkbox(
		'nopeer' . $counter,
		null,
		null,
		$item['nopeer']
	))->setHelp('nopeer');

	$group->add(new Form_Checkbox(
		'notrap' . $counter,
		null,
		null,
		$item['notrap']
	))->setHelp('notrap');

	$group->add(new Form_Button(
		'deleterow' . $counter,
		'Delete',
		null,
		'fa-solid fa-trash-can'
	))->addClass('btn-warning btn-sm')->addClass("nowarn");

	$group->addClass('repeatable');

	$section->add($group);

	$counter++;
}

$section->addInput(new Form_Button(
	'addrow',
	'Add',
	null,
	'fa-solid fa-plus'
))->addClass('btn-success');

$form->add($section);

print($form);

?>

<script type="text/javascript">
//<![CDATA[
events.push(function(){
	retainhelp = false;
});
//]]>
</script>

<?php include("foot.inc");
