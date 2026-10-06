<?php
/*
 * services_dhcp.php
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
##|*IDENT=page-services-dhcpserver
##|*NAME=Services: DHCP Server
##|*DESCR=Allow access to the 'Services: DHCP Server' page.
##|*MATCH=services_dhcp.php*
##|-PRIV

require_once('guiconfig.inc');
require_once('filter.inc');
require_once('rrd.inc');
require_once('shaper.inc');
require_once('util.inc');
require_once('services_dhcp.inc');

global $ddnsdomainkeyalgorithms;

$dnsregpolicy_values = dhcp_server_dnsregpolicy_values();

if (!g_get('services_dhcp_server_enable')) {
	header("Location: /");
	exit;
}

$if = $_REQUEST['if'];
$iflist = get_configured_interface_with_descr();

/* set the starting interface */
if (!$if || !isset($iflist[$if])) {
	$found_starting_if = false;
	// First look for an interface with DHCP already enabled.
	foreach (array_keys($iflist) as $ifent) {
		if (config_path_enabled("dhcpd/{$ifent}") &&
		    is_ipaddrv4(config_get_path("interfaces/{$ifent}/ipaddr")) &&
		    ((int) config_get_path("interfaces/{$ifent}/subnet", 0) < 31)) {
			$if = $ifent;
			$found_starting_if = true;
			break;
		}
	}

	/*
	 * If there is no DHCP-enabled interface and LAN is a candidate,
	 * then choose LAN.
	 */
	if (!$found_starting_if &&
	    !empty(array_get_path($iflist, 'lan')) &&
	    is_ipaddrv4(config_get_path("interfaces/lan/ipaddr")) &&
	    ((int) config_get_path("interfaces/lan/subnet", 0) < 31)) {
		$if = 'lan';
		$found_starting_if = true;
	}

	// At the last select whatever can be found.
	$fallback = "";
	if (!$found_starting_if) {
		foreach (array_keys($iflist) as $ifent) {
			/* Not static IPv4 or subnet >= 31 */
			if (!is_ipaddrv4(config_get_path("interfaces/{$ifent}/ipaddr")) ||
			    empty(config_get_path("interfaces/{$ifent}/subnet")) ||
			    ((int) config_get_path("interfaces/{$ifent}/subnet", 0) >= 31)) {
				continue;
			} elseif (empty($fallback)) {
				/* First potential fallback in case no interfaces
				 * have DHCP enabled. */
				$fallback = $ifent;
			}

			/* If this interface has does not have DHCP enabled,
			 * skip it for now. */
			if (!config_path_enabled("dhcpd/{$ifent}")) {
				continue;
			}

			$if = $ifent;
			break;
		}
		if (empty($if) || !empty($fallback)) {
			$if = $fallback;
		}
	}
}

$act = $_REQUEST['act'];

if (!empty(config_get_path("dhcpd/{$if}"))) {
	$pool = $_REQUEST['pool'];
	if (is_numeric($_POST['pool'])) {
		$pool = $_POST['pool'];
	}

	// If we have a pool but no interface name, that's not valid. Redirect away.
	if (is_numeric($pool) && empty($if)) {
		header("Location: services_dhcp.php");
		exit;
	}

	$dhcpdconf = dhcp_server_conf($if, $pool, $act);
}

$pconfig = dhcp_server_form($dhcpdconf ?? null, (is_numeric($pool ?? null) || ($act == "newpool")));

$ifcfgip = config_get_path("interfaces/{$if}/ipaddr");
$ifcfgsn = config_get_path("interfaces/{$if}/subnet");

$subnet_start = gen_subnetv4($ifcfgip, $ifcfgsn);
$subnet_end = gen_subnetv4_max($ifcfgip, $ifcfgsn);

if (isset($_POST['save'])) {
	unset($input_errors);
	$rv = dhcp_server_save($if, $pool ?? null, $act, $_POST);
	if ($rv['missing_pool']) {
		// Someone specified a pool but it doesn't exist. Punt.
		header("Location: services_dhcp.php");
		exit;
	}
	$input_errors = $rv['input_errors'];
	$pconfig = $rv['pconfig'];
	if ($rv['saved']) {
		$dhcpdconf = $rv['dhcpdconf'];
		/* redirect back to the primary pool when creating/saving an additional address pool */
		if (is_numeric($pool) || ($act === 'newpool')) {
			header('Location: /services_dhcp.php?if='.$if);
		}
	}
}

if (isset($_POST['apply'])) {
	$changes_applied = true;
	$retval = dhcp_apply_changes();
}

if ($act == "delpool") {
	if (dhcp_pool_delete($if, $_POST['id'])) {
		header("Location: services_dhcp.php?if={$if}");
		exit;
	}
}

if ($act == "del") {
	if (dhcp_staticmap_delete($if, $_POST['id'])) {
		header("Location: services_dhcp.php?if={$if}");
		exit;
	}
}

$pgtitle = array(gettext("Services"), gettext("DHCP Server"));
$pglinks = array("", "services_dhcp_settings.php");

if (!empty($if) && isset($iflist[$if])) {
	$pgtitle[] = $iflist[$if];
	$pglinks[] = '/services_dhcp.php?if='.$if;

	if (is_numeric($pool) || ($act === 'newpool')) {
		$pgtitle[] = gettext('Address Pool');
		$pglinks[] = '@self';
		$pgtitle[] = gettext('Edit');
		$pglinks[] = '@self';
	}
}

$shortcut_section = 'dhcp';
if (dhcp_is_backend('kea')) {
	$shortcut_section = 'kea-dhcp4';
}

include('head.inc');

if (config_path_enabled('dhcrelay')) {
	print_info_box(gettext('DHCP Relay is currently enabled. DHCP Server canot be enabled while the DHCP Relay is enabled on any interface.'), 'danger', false);
}

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($changes_applied) {
	print_apply_result_box($retval);
}

if (is_subsystem_dirty('dhcpd')) {
	print_apply_box(gettext('The DHCP Server configuration has changed.') . '<br />' . gettext('The changes must be applied for them to take effect.'));
}

display_isc_warning();

/* active tabs */
$tab_array = array();
$tabscounter = 0;
$i = 0;
$have_small_subnet = false;

if (dhcp_is_backend('kea')) {
	$tab_array[] = [gettext('Settings'), false, 'services_dhcp_settings.php'];
}

foreach ($iflist as $ifent => $ifname) {
	$oc = config_get_path("interfaces/{$ifent}", []);

	/* Not static IPv4 or subnet >= 31 */
	if ($oc['subnet'] >= 31) {
		$have_small_subnet = true;
		$example_name = $ifname;
		$example_cidr = $oc['subnet'];
		continue;
	}
	if (!is_ipaddrv4($oc['ipaddr']) || empty($oc['subnet'])) {
		continue;
	}

	if ($ifent == $if) {
		$active = true;
	} else {
		$active = false;
	}

	$tab_array[] = array($ifname, $active, "services_dhcp.php?if={$ifent}");
	$tabscounter++;
}

if ($tabscounter == 0) {
	if ($have_small_subnet) {
		$sentence2 = sprintf(gettext('%1$s has a CIDR mask of %2$s, which does not contain enough addresses.'), htmlspecialchars($example_name), htmlspecialchars($example_cidr));
	} else {
		$sentence2 = gettext("This system has no interfaces configured with a static IPv4 address.");
	}
	print_info_box(gettext("The DHCP Server requires a static IPv4 subnet large enough to serve addresses to clients.") . " " . $sentence2);
	include("foot.inc");
	exit;
}

display_top_tabs($tab_array);

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

if (!is_numeric($pool) && !($act == "newpool")) {
	$kea_section = 'subnet';
	if (config_path_enabled('dhcrelay')) {
		$section->addInput(new Form_Checkbox(
			'enable',
			gettext('Enable'),
			gettext("DHCP Relay is currently enabled. DHCP Server canot be enabled while the DHCP Relay is enabled on any interface."),
			$pconfig['enable']
		))->setAttribute('disabled', true);
	} else {
		$section->addInput(new Form_Checkbox(
			'enable',
			gettext('Enable'),
			sprintf(gettext("Enable DHCP server on %s interface"), $iflist[$if]),
			$pconfig['enable']
		));
	}
} else {
	$kea_section = 'pool';
	print_info_box(gettext('Editing pool-specific options. To return to the Interface, click its tab above.'), 'info', false);
}

if (dhcp_is_backend('isc')):
$section->addInput(new Form_Checkbox(
	'ignorebootp',
	'BOOTP',
	'Ignore BOOTP queries',
	$pconfig['ignorebootp']
));
endif; /* dhcp_is_backend('isc') */

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
	'If set to %3$sAllow known clients from any interface%4$s, any DHCP client with a MAC address listed in a static mapping on %1$s%3$sany%4$s%2$s scope(s)/interface(s) will get an IP address. ' .
	'If set to %3$sAllow known clients from only this interface%4$s, only MAC addresses listed in static mappings on this interface will get an IP address within this scope/range.'),
	'<i>', '</i>', '<b>', '</b>');

if (dhcp_is_backend('isc')):
$section->addInput(new Form_Checkbox(
	'nonak',
	gettext('Ignore Denied Clients'),
	'Ignore denied clients rather than reject',
	$pconfig['nonak']
))->setHelp(gettext('This option is not compatible with failover and cannot be enabled when a Failover Peer IP address is configured.'));
endif; /* dhcp_is_backend('isc') */

if (dhcp_is_backend('isc') ||
    (dhcp_is_backend('kea') && (!is_numeric($pool) && !($act === 'newpool')))):
$section->addInput(new Form_Checkbox(
	'ignoreclientuids',
	gettext('Ignore Client Identifiers'),
	gettext('Do not record a unique identifier (UID) in client lease data if present in the client DHCP request'),
	$pconfig['ignoreclientuids']
))->setHelp(gettext('This option may be useful when a client can dual boot using different client identifiers but the same hardware (MAC) address.  Note that the resulting server behavior violates the official DHCP specification.'));
endif;

if (dhcp_is_backend('kea') && (!is_numeric($pool) && !($act === 'newpool'))):
	$section->addInput(new Form_Select(
		'dnsregpolicy',
		gettext('DNS Registration'),
		array_get_path($pconfig, 'dnsregpolicy', 'default'),
		$dnsregpolicy_values
	))->setHelp(gettext('Optionally overides the DHCP server default DNS registration policy to force a specific policy.'));
	$section->addInput(new Form_Select(
		'earlydnsregpolicy',
		gettext('Early DNS Registration'),
		array_get_path($pconfig, 'earlydnsregpolicy', 'default'),
		$dnsregpolicy_values
	))->setHelp(gettext('Optionally overides the DHCP server default early DNS registration policy to force a specific policy.'));
endif;

if (is_numeric($pool) || ($act == "newpool")) {
	$section->addInput(new Form_Input(
		'descr',
		gettext('Description'),
		'text',
		$pconfig['descr']
	))->setHelp(gettext('Description for administrative reference (not parsed).'));
}

$form->add($section);

$pool_title = gettext('Primary Address Pool');
if (is_numeric($pool) || ($act === 'newpool')) {
	$pool_title = gettext('Additional Address Pool');
}

$section = new Form_Section($pool_title);

$section->addInput(new Form_StaticText(
	gettext('Subnet'),
	gen_subnet($ifcfgip, $ifcfgsn) . '/' . $ifcfgsn
));

$section->addInput(new Form_StaticText(
	gettext('Subnet Range'),
	sprintf('%s - %s', ip_after($subnet_start), ip_before($subnet_end))
));

if (is_numeric($pool) || ($act === 'newpool')) {
	$ranges = [];
	$subnet_range = config_get_path('dhcpd/'.$if.'/range', []);
	if (!empty($subnet_range)) {
		$subnet_range['descr'] = gettext('Primary Pool');
		$ranges[] = $subnet_range;
	}

	foreach (config_get_path("dhcpd/{$if}/pool", []) as $p) {
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

$group = new Form_Group('*'.gettext('Address Pool Range'));

$group->add(new Form_IpAddress(
	'range_from',
	null,
	$pconfig['range_from'],
	'V4'
))->addClass('autotrim')
  ->setHelp(gettext('From'));

$group->add(new Form_IpAddress(
	'range_to',
	null,
	$pconfig['range_to'],
	'V4'
))->addClass('autotrim')
  ->setHelp(gettext('To'));

$group->setHelp(gettext('The specified range for this pool must not be within the range configured on any other address pool for this interface.'));
$section->add($group);

if (!is_numeric($pool) && !($act == "newpool")) {
	$has_pools = false;
	if (isset($if) && (count(config_get_path("dhcpd/{$if}/pool", [])) > 0)) {
		$section->addInput(new Form_StaticText(
			gettext('Additional Pools'),
			dhcp_build_pooltable($if)
		));
		$has_pools = true;
	}

	$btnaddpool = new Form_Button(
		'btnaddpool',
		gettext('Add Address Pool'),
		'services_dhcp.php?if=' . $if . '&act=newpool',
		'fa-solid fa-plus'
	);
	$btnaddpool->addClass('btn-success');

	$section->addInput(new Form_StaticText(
		(!$has_pools ? gettext('Additional Pools') : null),
		$btnaddpool
	))->setHelp(gettext('If additional pools of addresses are needed inside of this subnet outside the above range, they may be specified here.'));
}

$form->add($section);

$section = new Form_Section(gettext('Server Options'));

$section->addInput(new Form_IpAddress(
	'wins1',
	gettext('WINS Servers'),
	$pconfig['wins1'],
	'V4'
))->addClass('autotrim')
  ->setAttribute('placeholder', gettext('WINS Server 1'));

$section->addInput(new Form_IpAddress(
	'wins2',
	null,
	$pconfig['wins2'],
	'V4'
))->addClass('autotrim')
  ->setAttribute('placeholder', gettext('WINS Server 2'));

$ifip = get_interface_ip($if);

/* Only consider DNS servers with IPv4 addresses for the IPv4 DHCP server. */
$dns_arrv4 = [];
foreach (config_get_path('system/dnsserver', []) as $dnsserver) {
	if (is_ipaddrv4($dnsserver)) {
		$dns_arrv4[] = $dnsserver;
	}
}

/* prefer the interface IP if dnsmasq or unbound is enabled */
if (config_path_enabled('dnsmasq') ||
    config_path_enabled('unbound')) {
    	$dns_arrv4 = [$ifip];
}

/* additional pools should inherit from the subnet/primary pool */
if (is_numeric($pool) || ($act === 'newpool')) {
	$subnet_dnsservers = config_get_path('dhcpd/'.$if.'/dnsserver', []);
	if (!empty($subnet_dnsservers)) {
		$dns_arrv4 = $subnet_dnsservers;
	}
}

for ($idx = 1; $idx <= 4; $idx++) {
	$last = $section->addInput(new Form_IpAddress(
		'dns' . $idx,
		($idx == 1) ? gettext('DNS Servers') : null,
		$pconfig['dns' . $idx],
		'V4'
	))->addClass('autotrim')
	  ->setAttribute('placeholder', $dns_arrv4[$idx - 1] ?? sprintf(gettext('DNS Server %s'), $idx));
}
$last->setHelp(($idx == 4) ? gettext('Leave blank to use the IP address of this firewall interface if DNS Resolver or Forwarder is enabled, the servers configured in General settings or those obtained dynamically.') : '');

$form->add($section);

// OMAPI
if (dhcp_is_backend('isc')):
$section = new Form_Section('OMAPI');

$section->addInput(new Form_Input(
	'omapi_port',
	'OMAPI Port',
	'text',
	$pconfig['omapi_port']
))->setAttribute('placeholder', 'OMAPI Port')
  ->setHelp('Set the port that OMAPI will listen on. The default port is 7911, leave blank to disable.' .
	    'Only the first OMAPI configuration is used.');

$group = new Form_Group('OMAPI Key');

$group->add(new Form_Input(
	'omapi_key',
	'OMAPI Key',
	'text',
	$pconfig['omapi_key']
))->setAttribute('placeholder', 'OMAPI Key')
  ->setHelp('Enter a key matching the selected algorithm<br />to secure connections to the OMAPI endpoint.');

$group->add(new Form_Checkbox(
	'omapi_gen_key',
	'',
	'Generate New Key',
	$pconfig['omapi_gen_key']
))->setHelp('Generate a new key based<br />on the selected algorithm.');

$section->add($group);

$section->addInput(new Form_Select(
	'omapi_key_algorithm',
	'Key Algorithm',
	empty($pconfig['omapi_key_algorithm']) ? 'hmac-sha256' : $pconfig['omapi_key_algorithm'], // Set the default algorithm if not previous defined
	array(
		'hmac-md5' => 'HMAC-MD5 (legacy default)',
		'hmac-sha1' => 'HMAC-SHA1',
		'hmac-sha224' => 'HMAC-SHA224',
		'hmac-sha256' => 'HMAC-SHA256 (current bind9 default)',
		'hmac-sha384' => 'HMAC-SHA384',
		'hmac-sha512' => 'HMAC-SHA512 (most secure)',
	)
))->setHelp('Set the algorithm that OMAPI key will use.');

$form->add($section);
endif; /* dhcp_is_backend('isc') */

$section = new Form_Section(gettext('Other DHCP Options'));

/* the interface address has lowest priority */
$gateway_holder = $ifip;

/* additional pools should inherit from subnet/primary pool */
if (is_numeric($pool) || ($act === 'newpool')) {
	$subnet_gateway = config_get_path('dhcpd/'.$if.'/gateway');
	if (!empty($subnet_gateway)) {
		$gateway_holder = $subnet_gateway;
	}
}

$section->addInput(new Form_IpAddress(
	'gateway',
	gettext('Gateway'),
	$pconfig['gateway'],
	'V4'
))->addClass('autotrim')
  ->setPattern('[.a-zA-Z0-9_]+')
  ->setAttribute('placeholder', $gateway_holder)
  ->setHelp(gettext('The default is to use the IP address of this firewall interface as the gateway. Specify an alternate gateway here if this is not the correct gateway for the network. Enter "none" for no gateway assignment.'));

/* the system domain name has lowest priority */
$domain_holder = config_get_path('system/domain');

/* additional pools should inherit from subnet/primary pool */
if (is_numeric($pool) || ($act === 'newpool')) {
	$subnet_domain = config_get_path('dhcpd/'.$if.'/domain');
	if (!empty($subnet_domain)) {
		$domain_holder = $subnet_domain;
	}
}

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
	'number',
	$pconfig['deftime']
))->setAttribute('placeholder', '7200')
  ->setHelp(gettext('This is used for clients that do not ask for a specific expiration time. The default is 7200 seconds.'));

$section->addInput(new Form_Input(
	'maxtime',
	gettext('Maximum Lease Time'),
	'number',
	$pconfig['maxtime']
))->setAttribute('placeholder', '86400')
  ->setHelp(gettext('This is the maximum lease time for clients that ask for a specific expiration time. The default is 86400 seconds.'));
endif;

if (!is_numeric($pool) && !($act == "newpool")) {
if (dhcp_is_backend('isc')):
	$section->addInput(new Form_IpAddress(
		'failover_peerip',
		'Failover peer IP',
		$pconfig['failover_peerip'],
		'V4'
	))->setHelp('Leave blank to disable. Enter the interface IP address of the other firewall (failover peer) in this subnet. Firewalls must be using CARP. ' .
			'Advertising skew of the CARP VIP on this interface determines whether the DHCP daemon is Primary or Secondary. ' .
			'Ensure the advertising skew for the VIP on one firewall is &lt; 20 and the other is &gt; 20.');
endif; /* dhcp_is_backend('isc') */
	$section->addInput(new Form_Checkbox(
		'staticarp',
		gettext('Static ARP'),
		gettext('Enable Static ARP'),
		$pconfig['staticarp']
	))->setHelp('Restricts communication with the firewall to only hosts listed in static mappings containing both IP addresses and MAC addresses. ' .
			'No other hosts will be able to communicate with the firewall on this interface. ' .
			'This behavior is enforced even when DHCP server is disabled.');
if (dhcp_is_backend('isc')):
	$section->addInput(new Form_Checkbox(
		'dhcpleaseinlocaltime',
		'Time format change',
		'Change DHCP display lease time from UTC to local time',
		$pconfig['dhcpleaseinlocaltime']
	))->setHelp('By default DHCP leases are displayed in UTC time.	By checking this box DHCP lease time will be displayed in local time and set to the time zone selected.' .
				' This will be used for all DHCP interfaces lease time.');

	$section->addInput(new Form_Checkbox(
		'statsgraph',
		'Statistics graphs',
		'Enable monitoring graphs for DHCP lease statistics',
		$pconfig['statsgraph']
	))->setHelp('Enable this to add DHCP leases statistics to the Monitoring graphs. Disabled by default.');

	$section->addInput(new Form_Checkbox(
		'disablepingcheck',
		'Ping check',
		'Disable ping check',
		$pconfig['disablepingcheck']
	))->setHelp('When enabled dhcpd sends a ping to the address being assigned, and if no response has been heard, it assigns the address. Enabled by default.');
endif; /* dhcp_is_backend('isc') */
}

if (dhcp_is_backend('isc')):
// DDNS
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
	gettext('Enable'),
	gettext('Enable DDNS registration of DHCP clients'),
	$pconfig['ddnsupdate']
));

$section->addInput(new Form_Input(
	'ddnsdomain',
	gettext('DDNS Domain'),
	'text',
	$pconfig['ddnsdomain']
))->setAttribute('placeholder', $domain_holder)
  ->setHelp(gettext('Enter the dynamic DNS domain which will be used to register client names in the DNS server.'));

$section->addInput(new Form_Checkbox(
	'ddnsforcehostname',
	gettext('DDNS Hostnames'),
	gettext('Force dynamic DNS hostname to be the same as configured hostname for Static Mappings'),
	$pconfig['ddnsforcehostname']
))->setHelp(gettext('Default registers host name option supplied by DHCP client.'));

$group = new Form_Group(gettext('Primary DDNS Server'));

$group->add(new Form_IpAddress(
	'ddnsdomainprimary',
	gettext('Primary DDNS Server'),
	$pconfig['ddnsdomainprimary'],
	'BOTH'
))->setHelp('Primary domain name server IPv4 address.');

$group->add(new Form_Input(
	'ddnsdomainprimaryport',
	'53',
	'text',
	$pconfig['ddnsdomainprimaryport'],
))->setHelp(gettext('The port on which the server listens for DDNS requests.'));

$section->add($group);

$group = new Form_Group(gettext('Secondary DDNS Server'));
$group->add(new Form_IpAddress(
	'ddnsdomainsecondary',
	gettext('Secondary DDNS Server'),
	$pconfig['ddnsdomainsecondary'],
	'BOTH'
))->setHelp(gettext('Secondary domain name server IPv4 address.'));

$group->add(new Form_Input(
	'ddnsdomainsecondaryport',
	'53',
	'text',
	$pconfig['ddnsdomainsecondaryport'],
))->setHelp(gettext('The port on which the server listens for DDNS requests.'));

$section->add($group);

$section->addInput(new Form_Input(
	'ddnsdomainkeyname',
	gettext('DNS Domain Key'),
	'text',
	$pconfig['ddnsdomainkeyname']
))->setHelp(gettext('Dynamic DNS domain key name which will be used to register client names in the DNS server.'));

$section->addInput(new Form_Select(
	'ddnsdomainkeyalgorithm',
	gettext('Key Algorithm'),
	$pconfig['ddnsdomainkeyalgorithm'],
	$ddnsdomainkeyalgorithms
));

$section->addInput(new Form_Input(
	'ddnsdomainkey',
	gettext('DNS Domain Key Secret'),
	'text',
	$pconfig['ddnsdomainkey']
))->setAttribute('placeholder', gettext('base64 encoded string'))
->setHelp(gettext('Dynamic DNS domain key secret which will be used to register client names in the DNS server.'));

$section->addInput(new Form_Select(
	'ddnsclientupdates',
	gettext('DDNS Client Updates'),
	$pconfig['ddnsclientupdates'],
	array(
	    'allow' => gettext('Allow'),
	    'deny' => gettext('Deny'),
	    'ignore' => gettext('Ignore'))
))->setHelp(gettext('How Forward entries are handled when client indicates they wish to update DNS.  ' .
	    'Allow prevents DHCP from updating Forward entries, Deny indicates that DHCP will ' .
	    'do the updates and the client should not, Ignore specifies that DHCP will do the ' .
	    'update and the client can also attempt the update usually using a different domain name.'));
endif; /* dhcp_is_backend('isc') */

// Advanced MAC
$btnadv = new Form_Button(
	'btnadvmac',
	gettext('Display Advanced'),
	null,
	'fa-solid fa-gear'
);

$btnadv->setAttribute('type','button')->addClass('btn-info btn-sm');

$section->addInput(new Form_StaticText(
	gettext('MAC Address Control'),
	$btnadv
));

$mac_placeholder = '00:11:22:33:44:55,66:77:88:99,AA';
$section->addInput(new Form_Input(
	'mac_allow',
	gettext('MAC Allow'),
	'text',
	$pconfig['mac_allow']
))->addClass('autotrim')
  ->setAttribute('placeholder', $mac_placeholder)
  ->setHelp(gettext('List of full or partial MAC addresses to allow access in this scope/pool. Implicitly denies any MACs not listed. Does not define known/unknown clients. Enter addresses as comma separated without spaces.'));

$section->addInput(new Form_Input(
	'mac_deny',
	gettext('MAC Deny'),
	'text',
	$pconfig['mac_deny']
))->addClass('autotrim')
  ->setAttribute('placeholder', $mac_placeholder)
  ->setHelp(gettext('List of full or partial MAC addresses to deny access in this scope/pool. Implicitly allows any MACs not listed. Does not define known/unknown clients. Enter addresses as comma separated without spaces.'));

// Advanced NTP
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
	'HOSTV4'
))->addClass('autotrim')
  ->setAttribute('placeholder', $ntp_holder[0] ?? gettext('NTP Server 1'));

$section->addInput(new Form_IpAddress(
	'ntp2',
	gettext('NTP Server 2'),
	$pconfig['ntp2'],
	'HOSTV4'
))->addClass('autotrim')
  ->setAttribute('placeholder', $ntp_holder[1] ?? gettext('NTP Server 2'));

$section->addInput(new Form_IpAddress(
	'ntp3',
	gettext('NTP Server 3'),
	$pconfig['ntp3'],
	'HOSTV4'
))->addClass('autotrim')
  ->setAttribute('placeholder', $ntp_holder[2] ?? gettext('NTP Server 3'));

$section->addInput(new Form_IpAddress(
	'ntp4',
	gettext('NTP Server 4'),
	$pconfig['ntp4'],
	'HOSTV4'
))->addClass('autotrim')
  ->setAttribute('placeholder', $ntp_holder[3] ?? gettext('NTP Server 4'));

// Advanced TFTP
$btnadv = new Form_Button(
	'btnadvtftp',
	gettext('Display Advanced'),
	null,
	'fa-solid fa-gear'
);

$btnadv->setAttribute('type','button')->addClass('btn-info btn-sm');

$section->addInput(new Form_StaticText(
	gettext('TFTP'),
	$btnadv
));

$section->addInput(new Form_Input(
	'tftp',
	gettext('TFTP Server'),
	'text',
	$pconfig['tftp']
))->addClass('autotrim')
  ->setAttribute('placeholder', gettext('TFTP Server'))
  ->setHelp(gettext('Leave blank to disable. Enter a valid IP address, hostname or URL for the TFTP server.'));

// Advanced LDAP
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

// Advanced Network Booting options
$btnadv = new Form_Button(
	'btnadvnwkboot',
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

if (dhcp_is_backend('isc') ||
    (dhcp_is_backend('kea') && (!is_numeric($pool) && !($act === 'newpool')))):
$section->addInput(new Form_IpAddress(
	'nextserver',
	gettext('Next Server'),
	$pconfig['nextserver'],
	'V4'
))->setHelp(gettext('Enter the IPv4 address of the next server'));
endif;

$section->addInput(new Form_Input(
	'filename',
	gettext('Default BIOS File Name'),
	'text',
	$pconfig['filename']
));

$section->addInput(new Form_Input(
	'filename32',
	gettext('UEFI 32 bit File Name'),
	'text',
	$pconfig['filename32']
));

$section->addInput(new Form_Input(
	'filename64',
	gettext('UEFI 64 bit File Name'),
	'text',
	$pconfig['filename64']
));

$section->addInput(new Form_Input(
	'filename32arm',
	gettext('ARM 32 bit File Name'),
	'text',
	$pconfig['filename32arm']
));

$section->addInput(new Form_Input(
	'filename64arm',
	gettext('ARM 64 bit File Name'),
	'text',
	$pconfig['filename64arm']
))->setHelp(gettext('Both a filename and a boot server must be configured for this to work! ' .
			'All five filenames and a configured boot server are necessary for UEFI & ARM to work! '));

$section->addInput(new Form_Input(
	'uefihttpboot',
	gettext('UEFI HTTPBoot URL'),
	'text',
	$pconfig['uefihttpboot']
))->setHelp('string-format: http://(servername)/(firmwarepath)');

$section->addInput(new Form_Input(
	'rootpath',
	gettext('Root Path'),
	'text',
	$pconfig['rootpath']
))->setHelp('string-format: iscsi:(servername):(protocol):(port):(LUN):targetname ');

if (dhcp_is_backend('isc')):
// Advanced Additional options
$btnadv = new Form_Button(
	'btnadvopts',
	gettext('Display Advanced'),
	null,
	'fa-solid fa-gear'
);

$btnadv->setAttribute('type','button')->addClass('btn-info btn-sm');

$section->addInput(new Form_StaticText(
	gettext('Custom DHCP Options'),
	$btnadv
));

$form->add($section);

$section = new Form_Section(gettext('Custom DHCP Options'));
$section->addClass('adnlopts');

if (!$pconfig['numberoptions']) {
	$pconfig['numberoptions'] = array();
	$pconfig['numberoptions']['item']  = array(array('number' => '', 'type' => 'text', 'value' => ''));
}

$customitemtypes = array(
	'text' => gettext('Text'), 'string' => gettext('String'), 'boolean' => gettext('Boolean'),
	'unsigned integer 8' => gettext('Unsigned 8-bit integer'), 'unsigned integer 16' => gettext('Unsigned 16-bit integer'), 'unsigned integer 32' => gettext('Unsigned 32-bit integer'),
	'signed integer 8' => gettext('Signed 8-bit integer'), 'signed integer 16' => gettext('Signed 16-bit integer'), 'signed integer 32' => gettext('Signed 32-bit integer'), 'ip-address' => gettext('IP address or host')
);

$numrows = count($item) -1;
$counter = 0;

$numrows = count($pconfig['numberoptions']['item']) -1;

foreach ($pconfig['numberoptions']['item'] as $item) {
	$number = $item['number'];
	$itemtype = $item['type'];
	$value = base64_decode($item['value']);

	$group = new Form_Group(($counter == 0) ? gettext('Custom Option') : null);
	$group->addClass('repeatable');

	$group->add(new Form_Input(
		'number' . $counter,
		null,
		'number',
		$number,
		['min'=>'1', 'max'=>'254']
	))->setHelp($numrows == $counter ? 'Number':null);


	$group->add(new Form_Select(
		'itemtype' . $counter,
		null,
		$itemtype,
		$customitemtypes
	))->setWidth(3)->setHelp($numrows == $counter ? 'Type':null);

	$group->add(new Form_Input(
		'value' . $counter,
		null,
		'text',
		$value
	))->setHelp($numrows == $counter ? 'Value':null);

	$group->add(new Form_Button(
		'deleterow' . $counter,
		'Delete',
		null,
		'fa-solid fa-trash-can'
	))->addClass('btn-sm btn-warning');

	$section->add($group);

	$counter++;
}

$group = new Form_Group(null);
$group->add(new Form_Button(
	'addrow',
	gettext('Add Custom Option'),
	null,
	'fa-solid fa-plus'
))->addClass('btn-success')
  ->setHelp(gettext('Enter the DHCP option number, type and the value for each item to include in the DHCP lease information.'));
$section->add($group);
endif; /* dhcp_is_backend(isc') */

$form->add($section);

if (dhcp_is_backend('kea')):
$section = new Form_Section(gettext('Custom Configuration'));
$kea_custom_input = $section->addInput(new Form_Textarea(
	'custom_kea_config',
	gettext('JSON Configuration'),
	array_get_path($pconfig, 'custom_kea_config')
))->setWidth(8)->setHelp(gettext('JSON to be merged into the "%1$s" section of the generated Kea DHCPv4 configuration.%2$sThe input must be a well formed JSON object and should not include the "%1$s" key itself.'), $kea_section, '<br/>');
if (!kea_custom_config_editable()) {
	$kea_custom_input->setReadonly();
}
$form->add($section);
endif;

if ($act == "newpool") {
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

print($form);

// DHCP Static Mappings table

if (!is_numeric($pool) && !($act == "newpool")) {

	// Decide whether display of the Client Id column is needed.
	$got_cid = false;
	foreach (config_get_path("dhcpd/{$if}/staticmap", []) as $map) {
		if (!empty($map['cid'])) {
			$got_cid = true;
			break;
		}
	}
?>

<div class="panel panel-default">
<?php
	$title = gettext('DHCP Static Mappings');
?>
	<div class="panel-heading"><h2 class="panel-title"><?=$title?></h2></div>
	<div class="table-responsive">
			<table class="table table-striped table-hover table-sm sortable-theme-bootstrap" data-sortable>
				<thead>
					<tr>
						<th><!-- status icons --></th>
						<th><?=gettext("IP Address")?></th>
						<th><?=gettext("Hostname")?></th>
						<th><?=gettext("MAC Address")?></th>
						<th><?=gettext("Description")?></th>
						<th><?=gettext("Actions")?></th>
					</tr>
				</thead>
<?php
	$i = 0;
?>
			<tbody>
<?php
	foreach (config_get_path("dhcpd/{$if}/staticmap", []) as $mapent) {
?>
				<tr ondblclick="document.location='services_dhcp_edit.php?if=<?=htmlspecialchars($if)?>&amp;id=<?=$i?>';">
					<td>
						<?=dhcp_static_mapping_icons($dhcpdconf, $mapent)?>
					</td>
					<td>
						<?=htmlspecialchars($mapent['ipaddr'])?>
					</td>
					<td>
						<?=htmlspecialchars($mapent['hostname'])?>
					</td>
					<td <?php if ($mapent['cid']): ?>style="cursor: help;" data-bs-toggle="popover" data-bs-container="body" data-bs-trigger="hover focus" data-bs-content="<?=gettext('Client ID')?>: <span class=&quot;cid&quot;><?=htmlspecialchars($mapent['cid'])?></span>" data-bs-html="true" data-bs-title="<?=gettext('DHCP Client Information')?>"<?php endif; ?>>
						<?=htmlspecialchars($mapent['mac'])?>
					</td>
					<td>
						<?=htmlspecialchars($mapent['descr'])?>
					</td>
					<td>
						<a class="fa-solid fa-pencil" title="<?=gettext('Edit static mapping')?>"	href="services_dhcp_edit.php?if=<?=htmlspecialchars(urlencode($if))?>&amp;id=<?=$i?>"></a>
						<a class="fa-solid fa-trash-can text-danger" title="<?=gettext('Delete static mapping')?>"	href="services_dhcp.php?if=<?=htmlspecialchars(urlencode($if))?>&amp;act=del&amp;id=<?=$i?>" usepost></a>
					</td>
				</tr>
<?php
		$i++;
	}
?>
			</tbody>
		</table>
	</div>
</div>

<nav class="action-buttons">
	<a href="services_dhcp_edit.php?if=<?=htmlspecialchars(urlencode($if))?>" class="btn btn-success">
		<i class="fa-solid fa-plus icon-embed-btn"></i>
		<?=gettext('Add Static Mapping')?>
	</a>
</nav>
<?php
}
?>

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
			    (empty($pconfig['ddnsdomainkeyalgorithm']) || ($pconfig['ddnsdomainkeyalgorithm'] == "hmac-md5")) &&
			    (empty($pconfig['ddnsclientupdates']) || ($pconfig['ddnsclientupdates'] == "allow")) &&
			    empty($pconfig['ddnsdomainkey'])) {
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

	// Show advanced MAC options ======================================================================================
	var showadvmac = false;

	function show_advmac(ispageload) {
		var text;
		// On page load decide the initial state based on the data.
		if (ispageload) {
<?php
			if (empty($pconfig['mac_allow']) && empty($pconfig['mac_deny'])) {
				$showadv = false;
			} else {
				$showadv = true;
			}
?>
			showadvmac = <?php if ($showadv) {echo 'true';} else {echo 'false';} ?>;
		} else {
			// It was a click, swap the state.
			showadvmac = !showadvmac;
		}

		hideInput('mac_allow', !showadvmac);
		hideInput('mac_deny', !showadvmac);

		if (showadvmac) {
			text = "<?=gettext('Hide Advanced');?>";
		} else {
			text = "<?=gettext('Display Advanced');?>";
		}
		var children = $('#btnadvmac').children();
		$('#btnadvmac').text(text).prepend(children);
	}

	$('#btnadvmac').click(function(event) {
		show_advmac();
	});

	// Show advanced NTP options ======================================================================================
	var showadvntp = false;

	function show_advntp(ispageload) {
		var text;
		// On page load decide the initial state based on the data.
		if (ispageload) {
<?php
			if (empty($pconfig['ntp1']) && empty($pconfig['ntp2']) && empty($pconfig['ntp3']) ) {
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

	// Show advanced TFTP options ======================================================================================
	var showadvtftp = false;

	function show_advtftp(ispageload) {
		var text;
		// On page load decide the initial state based on the data.
		if (ispageload) {
<?php
			if (empty($pconfig['tftp'])) {
				$showadv = false;
			} else {
				$showadv = true;
			}
?>
			showadvtftp = <?php if ($showadv) {echo 'true';} else {echo 'false';} ?>;
		} else {
			// It was a click, swap the state.
			showadvtftp = !showadvtftp;
		}

		hideInput('tftp', !showadvtftp);

		if (showadvtftp) {
			text = "<?=gettext('Hide Advanced');?>";
		} else {
			text = "<?=gettext('Display Advanced');?>";
		}
		var children = $('#btnadvtftp').children();
		$('#btnadvtftp').text(text).prepend(children);
	}

	$('#btnadvtftp').click(function(event) {
		show_advtftp();
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

		hideClass('adnlopts', !showadvopts);

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
	});

	// Show advanced Network Booting options ===========================================================================
	var showadvnwkboot = false;

	function show_advnwkboot(ispageload) {
		var text;
		// On page load decide the initial state based on the data.
		if (ispageload) {
<?php
			if (empty($pconfig['netboot'])) {
				$showadv = false;
			} else {
				$showadv = true;
			}
?>
			showadvnwkboot = <?php if ($showadv) {echo 'true';} else {echo 'false';} ?>;
		} else {
			// It was a click, swap the state.
			showadvnwkboot = !showadvnwkboot;
		}

		hideCheckbox('netboot', !showadvnwkboot);
		hideInput('nextserver', !showadvnwkboot);
		hideInput('filename', !showadvnwkboot);
		hideInput('filename32', !showadvnwkboot);
		hideInput('filename64', !showadvnwkboot);
		hideInput('filename32arm', !showadvnwkboot);
		hideInput('filename64arm', !showadvnwkboot);
		hideInput('uefihttpboot', !showadvnwkboot);
		hideInput('rootpath', !showadvnwkboot);

		if (showadvnwkboot) {
			text = "<?=gettext('Hide Advanced');?>";
		} else {
			text = "<?=gettext('Display Advanced');?>";
		}
		var children = $('#btnadvnwkboot').children();
		$('#btnadvnwkboot').text(text).prepend(children);
	}

	$('#btnadvnwkboot').click(function(event) {
		show_advnwkboot();
	});

	// ---------- On initial page load ------------------------------------------------------------

	show_advdns(true);
	show_advmac(true);
	show_advntp(true);
	show_advtftp(true);
	show_advldap(true);
	show_advopts(true);
	show_advnwkboot(true);

	// Suppress "Delete row" button if there are fewer than two rows
	checkLastRow();
});
//]]>
</script>

<?php
include('foot.inc');
