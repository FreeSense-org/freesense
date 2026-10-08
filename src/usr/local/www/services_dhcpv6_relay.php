<?php
/*
 * services_dhcpv6_relay.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
 * Copyright (c) 2003-2004 Justin Ellison <justin@techadvise.com>
 * Copyright (c) 2010 Seth Mos
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
##|*IDENT=page-services-dhcpv6relay
##|*NAME=Services: DHCPv6 Relay
##|*DESCR=Allow access to the 'Services: DHCPv6 Relay' page.
##|*MATCH=services_dhcpv6_relay.php*
##|-PRIV

require_once('guiconfig.inc');
require_once("services_dhcp_relay.inc");

$pconfig = dhcp_relay_settings(true);

$iflist = dhcp_relay_interface_list(true);

$carpiflist = dhcp_relay_carp_list(true);

/*   set the enabled flag which will tell us if DHCP server is enabled
 *   on any interface.   We will use this to disable dhcp-relay since
 *   the two are not compatible with each other.
 */
$dhcpd_enabled = dhcp_relay_dhcpd_enabled(true);

if ($_POST) {

	unset($input_errors);

	$pconfig = $_POST;

	$rv = dhcp_relay_save($_POST, true);
	$input_errors = $rv['input_errors'];

	// Now $svrlist is a comma separated list of servers ready to save to the config system
	$pconfig['server'] = $rv['server'];

	if (!$input_errors) {
		$changes_applied = true;
		$retval = $rv['retval'];
	}
}

$pgtitle = [gettext('Services'), gettext('DHCPv6 Relay')];
$shortcut_section = 'dhcp6-relay';
include('head.inc');

if ($dhcpd_enabled) {
	print_info_box(gettext('DHCPv6 Relay cannot be enabled while the DHCPv6 Server is enabled on any interface.'), 'danger', false);
}

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($changes_applied && !$dhcpd_enabled) {
	print_apply_result_box($retval);
}

$form = new Form;

$section = new Form_Section(gettext('DHCPv6 Relay Configuration'));

$group = new Form_Group(gettext('Enable'));
$enable_cb = new Form_Checkbox(
	'enable',
	gettext('Enable'),
	gettext('Enable DHCPv6 Relay'),
	($dhcpd_enabled ? false : $pconfig['enable'])
);

if ($dhcpd_enabled) {
	$enable_cb->setAttribute('disabled', true);
}

$group->add($enable_cb);
$section->add($group);

$section->addInput(new Form_Select(
	'interface',
	'*'.gettext('Downstream Interfaces'),
	$pconfig['interface'],
	$iflist,
	true
))->setHelp(gettext('Interfaces without an IPv6 address will not be shown.'));

$section->addInput(new Form_Select(
	'carpstatusvip',
	'*'.gettext('CARP Status VIP'),
	$pconfig['carpstatusvip'],
	$carpiflist,
))->setHelp(gettext('DHCPv6 Relay will be stopped when the ' .
	    'chosen VIP is in BACKUP status, and started in MASTER status.'));

$section->addInput(new Form_Checkbox(
	'agentoption',
	null,
	gettext('Append circuit ID and agent ID to requests'),
	$pconfig['agentoption']
))->setHelp(gettext('Append the circuit ID (interface number) and the agent ID to the DHCPv6 request.'));

$counter = 0;
foreach (explode(',', $pconfig['server']) as $server) {
	$group = new Form_Group((($counter === 0) ? '*' . gettext('Upstream Servers') : ''));
	$group->addClass('repeatable');

	$group->add(new Form_IpAddress(
		'server' . $counter,
		gettext('Upstream Server'),
		$server,
		'V6'
	))->addClass('autotrim');

	$group->add(new Form_Button(
		'deleterow' . $counter,
		gettext('Delete'),
		null,
		'fa-solid fa-trash-can'
	))->addClass('btn-sm btn-outline-secondary');

	$section->add($group);
	$counter++;
}

$group = new Form_Group(null);
$group->add(new Form_Button(
	'addrow',
	gettext('Add Upstream Server'),
	null,
	'fa-solid fa-plus',
))->addClass('btn-outline-secondary addbtn')
  ->setHelp(gettext('The IPv6 addresses of the servers to which DHCPv6 requests are relayed.'));
$section->add($group);

$form->add($section);

print $form;
?>
<script type="text/javascript">
events.push(function() {
	// Suppress "Delete row" button if there are fewer than two rows
	checkLastRow();
});
//]]>
</script>

<?php
include('foot.inc');
