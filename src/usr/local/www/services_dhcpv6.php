<?php
/*
 * services_dhcpv6.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
 * Copyright (c) 2010 Seth Mos <seth.mos@dds.nl>
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
##|*IDENT=page-services-dhcpv6server
##|*NAME=Services: DHCPv6 Server
##|*DESCR=Allow access to the 'Services: DHCPv6 Server' page.
##|*MATCH=services_dhcpv6.php*
##|-PRIV

require_once('guiconfig.inc');
require_once('filter.inc');
require_once('services_dhcp.inc');

$dnsregpolicy_values = dhcp_server_dnsregpolicy_values();

if (!g_get('services_dhcp_server_enable')) {
	header("Location: /");
	exit;
}

$if = $_REQUEST['if'];
$iflist = dhcp6_server_iflist();

/* set the starting interface */
if (!$if || !isset($iflist[$if])) {
	foreach ($iflist as $ifent => $ifname) {
		$ifaddr = config_get_path("interfaces/{$ifent}/ipaddrv6");

		if (!config_path_enabled("dhcpdv6/{$ifent}") &&
		    !(($ifaddr == 'track6') ||
		    (is_ipaddrv6($ifaddr) &&
		    !is_linklocal($ifaddr)))) {
			continue;
		}
		$if = $ifent;
		break;
	}
}

$act = $_REQUEST['act'];

if (!empty(config_get_path("dhcpdv6/{$if}"))) {
	$pool = $_REQUEST['pool'];
	if (is_numeric($_POST['pool'])) {
		$pool = $_POST['pool'];
	}

	if (is_numeric($pool) && empty($if)) {
		header('Location: services_dhcpv6.php');
		exit;
	}

	$dhcpdconf = dhcp6_server_conf($if, $pool, $act);
}

$pconfig = dhcp6_server_form($dhcpdconf ?? null, (is_numeric($pool ?? null) || ($act === 'newpool')));

$prefix = dhcp6_server_prefix((string)$if);
$ifcfgip = $prefix['ip'];
$ifcfgsn = $prefix['sn'];
$trackifname = $prefix['trackifname'];

/*	 set the enabled flag which will tell us if DHCP relay is enabled
 *	 on any interface. We will use this to disable DHCP server since
 *	 the two are not compatible with each other.
 */
$dhcrelay_enabled = dhcp6_server_relay_enabled($iflist);

if (isset($_POST['apply'])) {
	$changes_applied = true;
	$retval = dhcp6_apply_changes();
} elseif (isset($_POST['save'])) {
	unset($input_errors);

	$rv = dhcp6_server_save((string)$if, $pool ?? null, $act, $_POST);
	if ($rv['missing_pool']) {
		header("Location: services_dhcpv6.php");
		exit;
	}
	$input_errors = $rv['input_errors'];
	$pconfig = $rv['pconfig'];
	if ($rv['saved']) {
		$dhcpdconf = $rv['dhcpdconf'];
		if (is_numeric($pool) || ($act === 'newpool')) {
			header('Location: /services_dhcpv6.php?if='.$if);
		}
	}
}

if ($act == "delpool") {
	if (dhcp6_pool_delete((string)$if, $_POST['id'])) {
		header("Location: services_dhcpv6.php?if={$if}");
		exit;
	}
}

if ($_POST['act'] == "del") {
	if (dhcp6_staticmap_delete($if, $_POST['id'])) {
		header("Location: services_dhcpv6.php?if={$if}&view=mappings");
		exit;
	}
}

/* static mappings are a view of each interface (docs/webui/PLAN.md, rule R1) */
$editing_pool = (is_numeric($pool ?? null) || ($act === 'newpool'));
$view = $editing_pool ? 'settings' : fs_view_param(['settings', 'mappings'], 'settings');

$pgtitle = [gettext('Services'), gettext('DHCPv6 Server')];
$pglinks = [null, 'services_dhcpv6.php'];

if (!empty($if) && isset($iflist[$if])) {
	$pgtitle[] = $iflist[$if];
	$pglinks[] = '/services_dhcpv6.php?if='.$if;

	if (is_numeric($pool) || ($act === 'newpool')) {
		$pgtitle[] = gettext('Address Pool');
		$pglinks[] = '@self';
		$pgtitle[] = gettext('Edit');
		$pglinks[] = '@self';
	}
}

if (($view === 'mappings') && !empty($if)) {
	$pgtitle[] = gettext('Static Mappings');
	$pglinks[] = '@self';
	fs_page_action(gettext('Add static mapping'), 'services_dhcpv6_edit.php?if=' . urlencode($if), 'fa-plus');
}

$shortcut_section = "dhcp6";
if (dhcp_is_backend('kea')) {
	$shortcut_section = 'kea-dhcp6';
}

include('head.inc');

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($changes_applied) {
	print_apply_result_box($retval);
}

if (is_subsystem_dirty('dhcpd6')) {
	print_apply_box(
		gettext('The DHCP Server configuration has been changed.') . "<br />" .
		gettext('The changes must be applied for them to take effect.') . "<br />" .
		gettext('If the prefix has changed, remember to STOP then START the ' .
			'Router Advertisement service to inform clients of the prefix change.')
	);
}

$is_stateless_dhcp = in_array(config_get_path('dhcpdv6/'.$if.'/ramode', 'disabled'), ['stateless_dhcp']);

$valid_ra = in_array(config_get_path('dhcpdv6/'.$if.'/ramode', 'disabled'), ['managed', 'assist', 'stateless_dhcp']);
if (config_path_enabled('dhcpdv6/'.$if) && !$valid_ra) {
	print_info_box(sprintf(gettext('DHCPv6 is enabled but not being advertised to clients on %1$s. Router Advertisement must be enabled and Router Mode set to "Managed", "Assisted" or "Stateless DHCP."'), $iflist[$if]), 'danger', false);
}

display_isc_warning();

/* active tabs */
$tab_array = array();
$tabscounter = 0;
$i = 0;

if (dhcp_is_backend('kea')) {
	$tab_array[] = [gettext('Settings'), false, 'services_dhcpv6_settings.php'];
}

foreach ($iflist as $ifent => $ifname) {
	$oc = config_get_path("interfaces/{$ifent}", []);
	$valid_if_ipaddrv6 = (bool) ($oc['ipaddrv6'] == 'track6' ||
	    (is_ipaddrv6($oc['ipaddrv6']) &&
	    !is_linklocal($oc['ipaddrv6'])));

	if (!config_path_enabled("dhcpdv6/{$ifent}") && !$valid_if_ipaddrv6) {
		continue;
	}

	if ($ifent == $if) {
		$active = true;
	} else {
		$active = false;
	}

	$tab_array[] = array($ifname, $active, "services_dhcpv6.php?if={$ifent}");
	$tabscounter++;
}

if ($tabscounter == 0) {
	print_info_box(gettext("The DHCPv6 Server can only be enabled on interfaces configured with a static IPv6 address. This system has none."), 'danger');
	include("foot.inc");
	exit;
}

if ($dhcrelay_enabled) {
	print_info_box(gettext('DHCPv6 Relay is currently enabled. DHCPv6 Server canot be enabled while the DHCPv6 Relay is enabled on any interface.'), 'danger', false);
}

display_top_tabs($tab_array);

if (!$editing_pool) {
	fs_view_switch([
		'settings' => gettext('Settings'),
		'mappings' => sprintf(gettext('Static Mappings (%d)'), count(config_get_path("dhcpdv6/{$if}/staticmap", []))),
	], $view);
}

if (is_null($pconfig) || !is_array($pconfig)) {
	$pconfig = [];
}

$form = new Form();

$section = new Form_Section(gettext('General Settings'));

$section->addInput(new Form_StaticText(
	gettext('DHCP Backend'),
	match (dhcp_get_backend()) {
		'isc' => gettext('ISC DHCP'),
		'kea' => gettext('Kea DHCP'),
		default => gettext('Unknown')
	}
));

if (!is_numeric($pool) && !($act === 'newpool')) {
	$kea_section = 'subnet';
	if ($dhcrelay_enabled) {
		$section->addInput(new Form_Checkbox(
			'enable',
			gettext('Enable'),
			gettext("DHCPv6 Relay is currently enabled. DHCPv6 Server canot be enabled while the DHCPv6 Relay is enabled on any interface."),
			$pconfig['enable']
		))->setAttribute('disabled', true);
	} else {
		$section->addInput(new Form_Checkbox(
			'enable',
			gettext('Enable'),
			sprintf(gettext('Enable DHCPv6 server on %s interface'), $iflist[$if]),
			$pconfig['enable']
		));
	}
} else {
	$kea_section = 'pool';
	print_info_box(gettext('Editing pool-specific options. To return to the Interface, click its tab above.'), 'info', false);
}

$section->addInput(new Form_Select(
	'denyunknown',
	gettext('Deny Unknown Clients'),
	$pconfig['denyunknown'],
	[
		'disabled' => gettext('Allow all clients'),
		'enabled' => gettext('Allow known clients from any interface'),
		'class' => gettext('Allow known clients from only this interface'),
	]
))->setHelp(gettext('When set to %3$sAllow all clients%4$s, any DHCP client will get an IP address within this scope/range on this interface. '.
	'If set to %3$sAllow known clients from any interface%4$s, any DHCP client with a DUID listed in a static mapping on %1$s%3$sany%4$s%2$s scope(s)/interface(s) will get an IP address. ' .
	'If set to %3$sAllow known clients from only this interface%4$s, only DUIDs listed in static mappings on this interface will get an IP address within this scope/range.'),
	'<i>', '</i>', '<b>', '</b>');

if (dhcp_is_backend('kea') && (!is_numeric($pool) && !($act === 'newpool'))):
	$section->addInput(new Form_Select(
		'dnsregpolicy',
		gettext('DNS Registration'),
		array_get_path($pconfig, 'dnsregpolicy', 'default'),
		$dnsregpolicy_values
	))->setHelp(gettext('Optionally overides the DHCPv6 server default DNS registration policy to force a specific policy.'));
	$section->addInput(new Form_Select(
		'earlydnsregpolicy',
		gettext('Early DNS Registration'),
		array_get_path($pconfig, 'earlydnsregpolicy', 'default'),
		$dnsregpolicy_values
	))->setHelp(gettext('Optionally overides the DHCPv6 server default early DNS registration policy to force a specific policy.'));
endif;

if (dhcp_is_backend('kea')):
if (is_numeric($pool) || ($act == "newpool")) {
	$section->addInput(new Form_Input(
		'descr',
		gettext('Description'),
		'text',
		$pconfig['descr']
	))->setHelp(gettext('Description for administrative reference (not parsed).'));
}
endif; /* dhcp_is_backend('kea') */

$form->add($section);

$pool_title = gettext('Primary Address Pool');
if (dhcp_is_backend('kea')):
if (is_numeric($pool) || ($act === 'newpool')) {
	$pool_title = gettext('Additional Address Pool');
}
endif;

$section = new Form_Section($pool_title);

if (is_ipaddrv6($ifcfgip)) {
	if ($ifcfgip == "::") {
		$sntext = gettext("Delegated Prefix") . ':';
		$sntext .= ' ' . convert_friendly_interface_to_friendly_descr(config_get_path("interfaces/{$if}/track6-interface"));
		$sntext .= '/' . config_get_path("interfaces/{$if}/track6-prefix-id");
		if (get_interface_track6ip($if)) {
			$track6ip = get_interface_track6ip($if);
			$pdsubnet = gen_subnetv6($track6ip[0], $track6ip[1]);
			$sntext .= " ({$pdsubnet}/{$track6ip[1]})";
		}
	} else {
		$sntext = gen_subnetv6($ifcfgip, $ifcfgsn);
	}
	$section->addInput(new Form_StaticText(
		gettext('Prefix'),
		$sntext . '/' . $ifcfgsn
		));

	$section->addInput(new Form_StaticText(
		gettext('Prefix Range'),
		$range_from = gen_subnetv6($ifcfgip, $ifcfgsn) . ' to ' . gen_subnetv6_max($ifcfgip, $ifcfgsn)
	))->setHelp($trackifname ? gettext('Prefix Delegation subnet will be appended to the beginning of the defined range'):'');

	if (is_numeric($pool) || ($act === 'newpool')) {
		$ranges = [];
		$subnet_range = config_get_path('dhcpdv6/'.$if.'/range', []);
		if (!empty($subnet_range)) {
			$subnet_range['descr'] = gettext('Primary Pool');
			$ranges[] = $subnet_range;
		}

		foreach (config_get_path("dhcpdv6/{$if}/pool", []) as $p) {
			$pa = array_get_path($p, 'range', []);
			if (!empty($pa)) {
				$pa['descr'] = trim($p['descr']);
				$ranges[] = $pa;
			}
		}

		$first = true;
		foreach ($ranges as $range) {
			$section->addInput(new Form_StaticText(
				($first ? ((count($ranges) > 1) ? gettext('In-use Ranges') : gettext('In-use Range')) : null),
				sprintf('%s - %s%s',
					htmlspecialchars((string)array_get_path($range, 'from')),
					htmlspecialchars((string)array_get_path($range, 'to')),
					!empty($range['descr']) ? ' ('.htmlspecialchars($range['descr']).')' : null
				)
			));
			$first = false;
		}
	}
}

$f1 = new Form_Input(
	'range_from',
	null,
	'text',
	$pconfig['range_from']
);

$f1->addClass('autotrim')
   ->setHelp(gettext('From'));

$f2 = new Form_Input(
	'range_to',
	null,
	'text',
	$pconfig['range_to']
);

$f2->addClass('autotrim')
   ->setHelp(gettext('To'));

/* address pool is optional when stateless */
$group = new Form_Group((!$is_stateless_dhcp ? '*' : '').gettext('Address Pool Range'));

$group->add($f1);
$group->add($f2);

$group->setHelp(gettext('The specified range for this pool must not be within the range configured on any other address pool for this interface.'));

$section->add($group);

if (dhcp_is_backend('kea')):
if (!is_numeric($pool) && !($act === 'newpool')) {
	$has_pools = false;
	if (isset($if) && (count(config_get_path("dhcpdv6/{$if}/pool", [])) > 0)) {
		$section->addInput(new Form_StaticText(
			gettext('Additional Pools'),
			dhcp6_build_pooltable($if)
		));
		$has_pools = true;
	}

	$btnaddpool = new Form_Button(
		'btnaddpool',
		gettext('Add Address Pool'),
		'services_dhcpv6.php?if=' . $if . '&act=newpool',
		'fa-solid fa-plus'
	);
	$btnaddpool->addClass('btn-success');

	$section->addInput(new Form_StaticText(
		(!$has_pools ? gettext('Additional Pools') : null),
		$btnaddpool
	))->setHelp(gettext('If additional pools of addresses are needed inside of this prefix outside the above range, they may be specified here.'));
}
endif; /* dhcp_is_backend('kea') */

$form->add($section);

if (!is_numeric($pool) && !($act === 'newpool')):
$section = new Form_Section(gettext('Prefix Delegation Pool'));
if (dhcp_is_backend('isc')):
$f1 = new Form_Input(
	'prefixrange_from',
	null,
	'text',
	$pconfig['prefixrange_from']
);

$f1->addClass('trim')
   ->setHelp(gettext('From'));

$f2 = new Form_Input(
	'prefixrange_to',
	null,
	'text',
	$pconfig['prefixrange_to']
);

$f2->addClass('trim')
   ->setHelp(gettext('To'));

$group = new Form_Group(gettext('Prefix Delegation Range'));

$group->add($f1);
$group->add($f2);

$section->add($group);

$section->addInput(new Form_Select(
	'prefixrange_length',
	gettext('Prefix Delegation Size'),
	$pconfig['prefixrange_length'],
	array(
		'48' => '48',
		'52' => '52',
		'56' => '56',
		'59' => '59',
		'60' => '60',
		'61' => '61',
		'62' => '62',
		'63' => '63',
		'64' => '64'
		)
))->setHelp(gettext('A prefix range can be defined here for DHCP Prefix Delegation. This allows for assigning networks to subrouters. The start and end of the range must end on boundaries of the prefix delegation size.'));
else:
$section->addInput(new Form_IpAddress(
	'pdprefix',
	gettext('Delegated Prefix'),
	$pconfig['pdprefix'],
	'V6'
))->addClass('trim')
  ->addMask('pdprefixlen', $pconfig['pdprefixlen'], 48, 64, false)
  ->setWidth(5)
  ->setHelp(gettext('Starting address range from which delegated prefixes will be assigned. The prefix length here determines the fixed portion of the address, and it must be smaller than or equal to the delegated length below.' ));
$section->addInput(new Form_Select(
	'pddellen',
	gettext('Delegated Length'),
	$pconfig['pddellen'],
	[] /* filled by `update_delegated_length` on page load and on changes to pdprefix */
))->setWidth(5)
  ->setHelp(gettext('The length of the prefixes that will be delegated to clients.'));
endif;
$form->add($section);
endif;

$section = new Form_Section(gettext('Server Options'));

if (!is_numeric($pool) && !($act === 'newpool')):
$section->addInput(new Form_Checkbox(
	'dhcp6c-dns',
	gettext('Enable DNS'),
	gettext('Provide DNS servers to DHCPv6 clients'),
	(($pconfig['dhcp6c-dns'] == 'enabled') || ($pconfig['dhcp6c-dns'] == 'yes'))
))->setHelp(gettext('Unchecking this box disables the dhcp6.name-servers option. ' .
	'Use with caution, as the resulting behavior may violate RFCs and lead to unintended client behavior.'));
endif;

$ifipv6 = get_interface_ipv6($if);

$dns_arrv6 = [];
foreach (config_get_path('system/dnsserver', []) as $dnsserver) {
	if (is_ipaddrv6($dnsserver)) {
		$dns_arrv6[] = $dnsserver;
	}
}

if (config_path_enabled('dnsmasq') ||
    config_path_enabled('unbound')) {
	$dns_arrv6 = [$ifipv6];
}

if (is_numeric($pool) || ($act === 'newpool')) {
	$subnet_dnsservers = config_get_path('dhcpdv6/'.$if.'/dnsserver', []);
	if (!empty($subnet_dnsservers)) {
		$dns_arrv6 = $subnet_dnsservers;
	}
}

for ($idx = 1; $idx <= 4; $idx++) {
	$last = $section->addInput(new Form_IpAddress(
		'dns' . $idx,
		(($idx === 1) ? gettext('DNS Servers') : null),
		$pconfig['dns' . $idx],
		'V6'
	))->addClass('autotrim')
	  ->setAttribute('placeholder', $dns_arrv6[$idx - 1] ?? sprintf('DNS Server %s', $idx));
}
$last->setHelp(gettext('Leave blank to use the IP address of this firewall interface if DNS Resolver or Forwarder is enabled, the servers configured in General settings or those obtained dynamically.'));

$form->add($section);

$section = new Form_Section(gettext('Other DHCPv6 Options'));

/* the system domain name has lowest priority */
$domain_holder = config_get_path('system/domain');

$section->addInput(new Form_Input(
	'domain',
	gettext('Domain Name'),
	'text',
	$pconfig['domain']
))->addClass('autotrim')
  ->setAttribute('placeholder', $domain_holder)
  ->setHelp(gettext('The default is to use the domain name of this firewall as the default domain name provided by DHCP. An alternate domain name may be specified here.'));

$section->addInput(new Form_Input(
	'domainsearchlist',
	gettext('Domain Search List'),
	'text',
	$pconfig['domainsearchlist']
))->addClass('autotrim')
  ->setAttribute('placeholder', 'example.com;sub.example.com')
  ->setHelp(gettext('The DHCP server can optionally provide a domain search list. Use the semicolon character as separator.'));

if (dhcp_is_backend('isc') ||
    (dhcp_is_backend('kea') && (!is_numeric($pool) && !($act === 'newpool')))):
$section->addInput(new Form_Input(
	'deftime',
	gettext('Default Lease Time'),
	'text',
	$pconfig['deftime']
))->setAttribute('placeholder', '7200')
  ->setHelp(gettext('This is used for clients that do not ask for a specific expiration time. The default is 7200 seconds.'));

$section->addInput(new Form_Input(
	'maxtime',
	gettext('Maximum Lease Time'),
	'text',
	$pconfig['maxtime']
))->setAttribute('placeholder', '86400')
  ->setHelp(gettext('This is the maximum lease time for clients that ask for a specific expiration time. The default is 86400 seconds.'));
endif;

if (dhcp_is_backend('isc')):
$section->addInput(new Form_Checkbox(
	'dhcpv6leaseinlocaltime',
	'Time Format Change',
	'Change DHCPv6 display lease time from UTC to local time',
	($pconfig['dhcpv6leaseinlocaltime'] == 'yes')
))->setHelp('By default DHCPv6 leases are displayed in UTC time. ' .
			'By checking this box DHCPv6 lease time will be displayed in local time and set to time zone selected. ' .
			'This will be used for all DHCPv6 interfaces lease time.');

$btnadv = new Form_Button(
	'btnadvdns',
	gettext('Display Advanced'),
	null,
	'fa-solid fa-gear'
);

$btnadv->setAttribute('type','button')->addClass('btn-info btn-sm');

$section->addInput(new Form_StaticText(
	gettext('Dynamic DNS'),
	$btnadv
));

$section->addInput(new Form_Checkbox(
	'ddnsupdate',
	'DHCP Registration',
	'Enable registration of DHCP client names in DNS.',
	$pconfig['ddnsupdate']
));

$section->addInput(new Form_Input(
	'ddnsdomain',
	'DDNS Domain',
	'text',
	$pconfig['ddnsdomain']
))->setHelp('Enter the dynamic DNS domain which will be used to register client names in the DNS server.');

$section->addInput(new Form_Checkbox(
	'ddnsforcehostname',
	'DDNS Hostnames',
	'Force dynamic DNS hostname to be the same as configured hostname for Static Mappings',
	$pconfig['ddnsforcehostname']
))->setHelp('Default registers host name option supplied by DHCP client.');

$section->addInput(new Form_IpAddress(
	'ddnsdomainprimary',
	'Primary DDNS address',
	$pconfig['ddnsdomainprimary'],
	'BOTH'
))->setHelp('Enter the primary domain name server IP address for the dynamic domain name.');

$section->addInput(new Form_IpAddress(
	'ddnsdomainsecondary',
	'Secondary DDNS address',
	$pconfig['ddnsdomainsecondary'],
	'BOTH'
))->setHelp('Enter the secondary domain name server IP address for the dynamic domain name.');

$section->addInput(new Form_Input(
	'ddnsdomainkeyname',
	'DDNS Domain Key name',
	'text',
	$pconfig['ddnsdomainkeyname']
))->setHelp('Enter the dynamic DNS domain key name which will be used to register client names in the DNS server.');

$section->addInput(new Form_Select(
	'ddnsdomainkeyalgorithm',
	'Key algorithm',
	$pconfig['ddnsdomainkeyalgorithm'],
	array(
		'hmac-md5' => 'HMAC-MD5 (legacy default)',
		'hmac-sha1' => 'HMAC-SHA1',
		'hmac-sha224' => 'HMAC-SHA224',
		'hmac-sha256' => 'HMAC-SHA256 (current bind9 default)',
		'hmac-sha384' => 'HMAC-SHA384',
		'hmac-sha512' => 'HMAC-SHA512 (most secure)',
	)
));

$section->addInput(new Form_Input(
	'ddnsdomainkey',
	'DDNS Domain Key secret',
	'text',
	$pconfig['ddnsdomainkey']
))->setAttribute('placeholder', 'Base64 encoded string')
->setHelp('Enter the dynamic DNS domain key secret which will be used to register client names in the DNS server.');

$section->addInput(new Form_Select(
	'ddnsclientupdates',
	'DDNS Client Updates',
	$pconfig['ddnsclientupdates'],
	array(
	    'allow' => gettext('Allow'),
	    'deny' => gettext('Deny'),
	    'ignore' => gettext('Ignore'))
))->setHelp('How Forward entries are handled when client indicates they wish to update DNS.  ' .
	    'Allow prevents DHCP from updating Forward entries, Deny indicates that DHCP will ' .
	    'do the updates and the client should not, Ignore specifies that DHCP will do the ' .
	    'update and the client can also attempt the update usually using a different domain name.');

$section->addInput(new Form_Checkbox(
	'ddnsreverse',
	'DDNS Reverse',
	'Add reverse dynamic DNS entries.',
	$pconfig['ddnsreverse']
));
endif; /* dhcp_is_backend('isc') */

$btnadv = new Form_Button(
	'btnadvntp',
	gettext('Display Advanced'),
	null,
	'fa-solid fa-gear'
);

$btnadv->setAttribute('type','button')->addClass('btn-info btn-sm');

$section->addInput(new Form_StaticText(
	gettext('NTP'),
	$btnadv
));

$ntp_holder = [];
if (is_numeric($pool) || ($act === 'newpool')) {
	$subnet_ntp = config_get_path('dhcpd/'.$if.'/ntpserver', []);
	if (!empty($subnet_ntp)) {
		$ntp_holder = $subnet_ntp;
	}
}

$section->addInput(new Form_IpAddress(
	'ntp1',
	gettext('NTP Server 1'),
	$pconfig['ntp1'],
	'HOSTV6'
))->addClass('autotrim')
  ->setAttribute('placeholder', $ntp_holder[0] ?? gettext('NTP Server 1'));

$section->addInput(new Form_IpAddress(
	'ntp2',
	gettext('NTP Server 2'),
	$pconfig['ntp2'],
	'HOSTV6'
))->addClass('autotrim')
  ->setAttribute('placeholder', $ntp_holder[1] ?? gettext('NTP Server 2'));

$section->addInput(new Form_IpAddress(
	'ntp3',
	gettext('NTP Server 3'),
	$pconfig['ntp3'],
	'HOSTV6'
))->addClass('autotrim')
  ->setAttribute('placeholder', $ntp_holder[2] ?? gettext('NTP Server 3'));

$section->addInput(new Form_IpAddress(
	'ntp4',
	gettext('NTP Server 4'),
	$pconfig['ntp4'],
	'HOSTV6'
))->addClass('autotrim')
  ->setAttribute('placeholder', $ntp_holder[3] ?? gettext('NTP Server 4'));

if (dhcp_is_backend('isc')):
$btnadv = new Form_Button(
	'btnadvldap',
	gettext('Display Advanced'),
	null,
	'fa-solid fa-gear'
);

$btnadv->setAttribute('type','button')->addClass('btn-info btn-sm');

$section->addInput(new Form_StaticText(
	gettext('LDAP'),
	$btnadv
));


$ldap_example = 'ldap://ldap.example.com/dc=example,dc=com';
$section->addInput(new Form_Input(
	'ldap',
	gettext('LDAP Server URI'),
	'text',
	$pconfig['ldap']
))->setAttribute('placeholder', sprintf(gettext('LDAP Server URI (e.g. %s)'), $ldap_example))
  ->setHelp(gettext('Leave blank to disable. Enter a full URI for the LDAP server in the form %s'), $ldap_example);
endif; /* dhcp_is_backend('isc') */

$btnadv = new Form_Button(
	'btnadvnetboot',
	gettext('Display Advanced'),
	null,
	'fa-solid fa-gear'
);

$btnadv->setAttribute('type','button')->addClass('btn-info btn-sm');

$section->addInput(new Form_StaticText(
	gettext('Network Booting'),
	$btnadv
));

$section->addInput(new Form_Checkbox(
	'netboot',
	gettext('Enable'),
	gettext('Enable Network Booting'),
	$pconfig['netboot']
));

$section->addInput(new Form_Input(
	'bootfile_url',
	gettext('Bootfile URL'),
	'text',
	$pconfig['bootfile_url']
));

if (dhcp_is_backend('isc')):
$btnadv = new Form_Button(
	'btnadvopts',
	gettext('Display Advanced'),
	null,
	'fa-solid fa-gear'
);

$btnadv->setAttribute('type','button')->addClass('btn-info btn-sm');

$section->addInput(new Form_StaticText(
	'Additional BOOTP/DHCP Options',
	$btnadv
));

$form->add($section);

$title = 'Show Additional BOOTP/DHCP Options';

if (!$pconfig['numberoptions']) {
	$noopts = true;
	$pconfig['numberoptions'] = array();
	$pconfig['numberoptions']['item'] = array(0 => array('number' => "", 'value' => ""));
} else {
	$noopts = false;
}

$counter = 0;
if (!is_array($pconfig['numberoptions'])) {
	$pconfig['numberoptions'] = array();
}
if (!is_array($pconfig['numberoptions']['item'])) {
	$pconfig['numberoptions']['item'] = array();
}
$last = count($pconfig['numberoptions']['item']) - 1;

foreach ($pconfig['numberoptions']['item'] as $item) {
	$group = new Form_Group(null);
	$group->addClass('repeatable');
	$group->addClass('adnloptions');

	$group->add(new Form_Input(
		'number' . $counter,
		null,
		'text',
		$item['number']
	))->setHelp($counter == $last ? 'Number':null);

	$group->add(new Form_Input(
		'value' . $counter,
		null,
		'text',
		base64_decode($item['value'])
	))->setHelp($counter == $last ? 'Value':null);

	$btn = new Form_Button(
		'deleterow' . $counter,
		'Delete',
		null,
		'fa-solid fa-trash-can'
	);

	$btn->addClass('btn-warning');
	$group->add($btn);
	$section->add($group);
	$counter++;
}


$btnaddopt = new Form_Button(
	'addrow',
	'Add Option',
	null,
	'fa-solid fa-plus'
);

$btnaddopt->removeClass('btn-primary')->addClass('btn-success btn-sm');

$section->addInput($btnaddopt);
endif; /* dhcp_is_backend('isc') */

if (dhcp_is_backend('kea')):
$form->add($section);
endif; /* dhcp_is_backend('kea') */

if (dhcp_is_backend('kea')):
$section = new Form_Section(gettext('Custom Configuration'));
$kea_custom_input = $section->addInput(new Form_Textarea(
	'custom_kea_config',
	gettext('JSON Configuration'),
	array_get_path($pconfig, 'custom_kea_config')
))->setWidth(8)->setHelp(gettext('JSON to be merged into the "%1$s" section of the generated Kea DHCPv6 configuration.%2$sThe input must be a well formed JSON object and should not include the "%1$s" key itself.'), $kea_section, '<br/>');
if (!kea_custom_config_editable()) {
	$kea_custom_input->setReadonly();
}
$form->add($section);
endif;	

if ($act === 'newpool') {
	$form->addGlobal(new Form_Input(
		'act',
		null,
		'hidden',
		'newpool'
	));
}

if (is_numeric($pool)) {
	$form->addGlobal(new Form_Input(
		'pool',
		null,
		'hidden',
		$pool
	));
}

$form->addGlobal(new Form_Input(
	'if',
	null,
	'hidden',
	$if
));

if ($view === 'settings') {
	print($form);
}

// DHCPv6 Static Mappings table (a view of the interface, docs/webui/PLAN.md rule R1)
if ($view === 'mappings'):
	$staticmaps = config_get_path("dhcpdv6/{$if}/staticmap", []);
	$kea = dhcp_is_backend('kea');
	$shown = 0;
?>
<div class="panel panel-default fs-table">
<?php fs_table_toolbar([
	'title' => gettext('Static Mappings'),
	'search' => gettext('Search address, hostname, DUID…'),
	'noun' => gettext('static mappings'),
	'noun_one' => gettext('static mapping'),
]); ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover table-rowdblclickedit" data-sortable>
			<thead>
				<tr>
					<th class="fs-col-icon"><span class="visually-hidden"><?=gettext('Flags')?></span></th>
					<th data-fs-search><?=gettext("IPv6 Address")?></th>
<?php if ($kea): ?>
					<th data-fs-search><?=gettext('Delegated Prefix')?></th>
<?php endif; ?>
					<th data-fs-search><?=gettext("Hostname")?></th>
					<th data-fs-search><?=gettext("DUID")?></th>
					<th data-fs-search><?=gettext("Description")?></th>
					<th class="fs-col-actions"><span class="visually-hidden"><?=gettext("Actions")?></span></th>
				</tr>
			</thead>
			<tbody>
<?php
	foreach ($staticmaps as $i => $mapent):
		if ($mapent['duid'] == "" && $mapent['ipaddrv6'] == "") {
			continue;
		}
		$shown++;
		$edit_url = 'services_dhcpv6_edit.php?if=' . urlencode($if) . '&id=' . $i;
		$map_name = $mapent['hostname'] ?: ($mapent['ipaddrv6'] ?: $mapent['duid']);
?>
				<tr>
					<td class="fs-col-icon"><?=dhcp6_static_mapping_icons($dhcpdconf, $mapent)?></td>
					<td class="fs-mono"><a href="<?=htmlspecialchars($edit_url)?>"><?=htmlspecialchars($mapent['ipaddrv6'] ?: '-')?></a></td>
<?php if ($kea): ?>
					<td class="fs-mono"><?=htmlspecialchars($mapent['pdprefix'])?></td>
<?php endif; ?>
					<td><?=htmlspecialchars($mapent['hostname'])?></td>
					<td class="fs-mono small"><?=htmlspecialchars($mapent['duid'])?></td>
					<td><?=htmlspecialchars($mapent['descr'])?></td>
					<td class="fs-col-actions">
						<?=fs_row_actions([
							['edit', $edit_url, $map_name],
							['delete', 'services_dhcpv6.php?if=' . urlencode($if) . '&act=del&view=mappings&id=' . $i, $map_name, ['thing' => gettext('static mapping')]],
						])?>
					</td>
				</tr>
<?php
	endforeach;
	if ($shown === 0) {
		fs_empty_row($kea ? 7 : 6, gettext('No static mappings on this interface yet.'), 'services_dhcpv6_edit.php?if=' . urlencode($if), gettext('Add static mapping'));
	}
?>
			</tbody>
		</table>
	</div>
</div>
<?php endif; ?>

<?php if ($view === 'settings'): ?>
<script type="text/javascript">
//<![CDATA[
events.push(function() {

	// Show advanced DNS options ======================================================================================
	var showadvdns = false;

	function show_advdns(ispageload) {
		var text;
		// On page load decide the initial state based on the data.
		if (ispageload) {
<?php
			if (!$pconfig['ddnsupdate'] &&
			    !$pconfig['ddnsforcehostname'] &&
			    empty($pconfig['ddnsdomain']) &&
			    empty($pconfig['ddnsdomainprimary']) &&
			    empty($pconfig['ddnsdomainsecondary']) &&
			    empty($pconfig['ddnsdomainkeyname']) &&
			    (empty($pconfig['ddnsdomainkeyalgorithm'])  || ($pconfig['ddnsdomainkeyalgorithm'] == "hmac-md5")) &&
			    empty($pconfig['ddnsdomainkey']) &&
			    (empty($pconfig['ddnsclientupdates']) || ($pconfig['ddnsclientupdates'] == "allow")) &&
			    !$pconfig['ddnsreverse']) {
				$showadv = false;
			} else {
				$showadv = true;
			}
?>
			showadvdns = <?php if ($showadv) {echo 'true';} else {echo 'false';} ?>;
		} else {
			// It was a click, swap the state.
			showadvdns = !showadvdns;
		}

		hideCheckbox('ddnsupdate', !showadvdns);
		hideInput('ddnsdomain', !showadvdns);
		hideCheckbox('ddnsforcehostname', !showadvdns);
		hideInput('ddnsdomainprimary', !showadvdns);
		hideInput('ddnsdomainsecondary', !showadvdns);
		hideInput('ddnsdomainkeyname', !showadvdns);
		hideInput('ddnsdomainkeyalgorithm', !showadvdns);
		hideInput('ddnsdomainkey', !showadvdns);
		hideInput('ddnsclientupdates', !showadvdns);
		hideCheckbox('ddnsreverse', !showadvdns);

		if (showadvdns) {
			text = "<?=gettext('Hide Advanced');?>";
		} else {
			text = "<?=gettext('Display Advanced');?>";
		}
		var children = $('#btnadvdns').children();
		$('#btnadvdns').text(text).prepend(children);
	}

	$('#btnadvdns').click(function(event) {
		show_advdns();
	});

	// Show advanced NTP options ======================================================================================
	var showadvntp = false;

	function show_advntp(ispageload) {
		var text;
		// On page load decide the initial state based on the data.
		if (ispageload) {
<?php
			if (empty($pconfig['ntp1']) && empty($pconfig['ntp2']) && empty($pconfig['ntp3'])) {
				$showadv = false;
			} else {
				$showadv = true;
			}
?>
			showadvntp = <?php if ($showadv) {echo 'true';} else {echo 'false';} ?>;
		} else {
			// It was a click, swap the state.
			showadvntp = !showadvntp;
		}

		hideInput('ntp1', !showadvntp);
		hideInput('ntp2', !showadvntp);
		hideInput('ntp3', !showadvntp);
		hideInput('ntp4', !showadvntp);

		if (showadvntp) {
			text = "<?=gettext('Hide Advanced');?>";
		} else {
			text = "<?=gettext('Display Advanced');?>";
		}
		var children = $('#btnadvntp').children();
		$('#btnadvntp').text(text).prepend(children);
	}

	$('#btnadvntp').click(function(event) {
		show_advntp();
	});

	// Show advanced LDAP options ======================================================================================
	var showadvldap = false;

	function show_advldap(ispageload) {
		var text;
		// On page load decide the initial state based on the data.
		if (ispageload) {
<?php
			if (empty($pconfig['ldap'])) {
				$showadv = false;
			} else {
				$showadv = true;
			}
?>
			showadvldap = <?php if ($showadv) {echo 'true';} else {echo 'false';} ?>;
		} else {
			// It was a click, swap the state.
			showadvldap = !showadvldap;
		}

		hideInput('ldap', !showadvldap);

		if (showadvldap) {
			text = "<?=gettext('Hide Advanced');?>";
		} else {
			text = "<?=gettext('Display Advanced');?>";
		}
		var children = $('#btnadvldap').children();
		$('#btnadvldap').text(text).prepend(children);
	}

	$('#btnadvldap').click(function(event) {
		show_advldap();
	});

	// Show advanced Netboot options ======================================================================================
	var showadvnetboot = false;

	function show_advnetboot(ispageload) {
		var text;
		// On page load decide the initial state based on the data.
		if (ispageload) {
<?php
			if (!$pconfig['netboot'] && empty($pconfig['bootfile_url'])) {
				$showadv = false;
			} else {
				$showadv = true;
			}
?>
			showadvnetboot = <?php if ($showadv) {echo 'true';} else {echo 'false';} ?>;
		} else {
			// It was a click, swap the state.
			showadvnetboot = !showadvnetboot;
		}

		hideCheckbox('netboot', !showadvnetboot);
		hideInput('bootfile_url', !showadvnetboot);

		if (showadvnetboot) {
			text = "<?=gettext('Hide Advanced');?>";
		} else {
			text = "<?=gettext('Display Advanced');?>";
		}
		var children = $('#btnadvnetboot').children();
		$('#btnadvnetboot').text(text).prepend(children);
	}

	$('#btnadvnetboot').click(function(event) {
		show_advnetboot();
	});

	// Show advanced additional opts options ===========================================================================
	var showadvopts = false;

	function show_advopts(ispageload) {
		var text;
		// On page load decide the initial state based on the data.
		if (ispageload) {
<?php
			if (empty($pconfig['numberoptions']) ||
			    (empty($pconfig['numberoptions']['item'][0]['number']) && (empty($pconfig['numberoptions']['item'][0]['value'])))) {
				$showadv = false;
			} else {
				$showadv = true;
			}
?>
			showadvopts = <?php if ($showadv) {echo 'true';} else {echo 'false';} ?>;
		} else {
			// It was a click, swap the state.
			showadvopts = !showadvopts;
		}

		hideClass('adnloptions', !showadvopts);
		hideInput('addrow', !showadvopts);

		if (showadvopts) {
			text = "<?=gettext('Hide Advanced');?>";
		} else {
			text = "<?=gettext('Display Advanced');?>";
		}
		var children = $('#btnadvopts').children();
		$('#btnadvopts').text(text).prepend(children);
	}

	$('#btnadvopts').click(function(event) {
		show_advopts();
		checkLastRow();
	});

	function update_delegated_length() {
		let start = parseInt($('#pdprefixlen').val(), 10);
		let dellen = $('#pddellen');
		dellen.empty();
		for (let i = start; i <= 128; i++) {
			let selected = (i.toString() === "<?=intval($pconfig['pddellen'])?>");
			dellen.append(new Option(i, i, false, selected));
		}
	}

	$('#pdprefixlen').on('change', update_delegated_length);

	// On initial load
	show_advdns(true);
	show_advntp(true);
	show_advldap(true);
	show_advnetboot(true);
	show_advopts(true);
	if ($('#enable').prop('checked')) {
		hideClass('adnloptions', <?php echo json_encode($noopts); ?>);
		hideInput('addrow', <?php echo json_encode($noopts); ?>);
	} else {
		hideClass('adnloptions', true);
		hideInput('addrow', true);
	}
	update_delegated_length();
});
//]]>
</script>
<?php endif; /* settings */ ?>

<?php
include('foot.inc');
