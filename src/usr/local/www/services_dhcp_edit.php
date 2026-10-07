<?php
/*
 * services_dhcp_edit.php
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
##|*IDENT=page-services-dhcpserver-editstaticmapping
##|*NAME=Services: DHCP Server: Edit static mapping
##|*DESCR=Allow access to the 'Services: DHCP Server: Edit static mapping' page.
##|*MATCH=services_dhcp_edit.php*
##|-PRIV

global $ddnsdomainkeyalgorithms;

require_once('globals.inc');

if (!g_get('services_dhcp_server_enable')) {
	header("Location: /");
	exit;
}

require_once("guiconfig.inc");
require_once('services_dhcp.inc');

$dnsregpolicy_values = dhcp_staticmap_dnsregpolicy_values();

$if = $_REQUEST['if'];

if (!$if) {
	header("Location: services_dhcp.php");
	exit;
}

$id = is_numericint($_REQUEST['id']) ? $_REQUEST['id'] : null;

$pconfig = dhcp_staticmap_form($if, $id, $_REQUEST);

if ($_POST['save']) {
	unset($input_errors);
	$rv = dhcp_staticmap_save($if, $id, $_POST);
	foreach ($rv['warnings'] as $warning) {
		set_flash_message('alert-info', $warning);
	}
	$input_errors = $rv['input_errors'];
	$pconfig = $rv['pconfig'];

	if (!$input_errors) {
		header("Location: services_dhcp.php?if={$if}&view=mappings");
		exit;
	}
}

// Get our MAC address
$ip = $_SERVER['REMOTE_ADDR'];
$mymac = arp_get_mac_by_ip($ip, false);

$iflist = get_configured_interface_with_descr();
$ifname = '';

if (!empty($if) && isset($iflist[$if])) {
	$ifname = $iflist[$if];
}
$pgtitle = [gettext('Services'), gettext('DHCP Server'), $ifname, gettext('Static Mapping'), gettext('Edit')];
$pglinks = ['', 'services_dhcp.php', 'services_dhcp.php?if='.$if, 'services_dhcp.php?if='.$if.'&view=mappings', '@self'];
$shortcut_section = 'dhcp';
if (dhcp_is_backend('kea')) {
	$shortcut_section = 'kea-dhcp4';
}

include('head.inc');

if ($input_errors) {
	print_input_errors($input_errors);
}

display_isc_warning();

$form = new Form();

$section = new Form_Section(gettext('Static DHCP Mapping'));

if (!dhcp_is_backend('kea')):
$section->addInput(new Form_StaticText(
	gettext('DHCP Backend'),
	match (dhcp_get_backend()) {
		'isc' => gettext('ISC DHCP'),
		'kea' => gettext('Kea DHCP'),
		default => gettext('Unknown')
	}
));
endif;

$macaddress = new Form_Input(
	'mac',
	gettext('MAC Address'),
	'text',
	$pconfig['mac'],
	['placeholder' => 'xx:xx:xx:xx:xx:xx']
);

$macaddress->addClass('autotrim');

$btnmymac = new Form_Button(
	'btnmymac',
	gettext('Copy My MAC'),
	null,
	'fa-regular fa-clone'
	);

$btnmymac->setAttribute('type','button')->removeClass('btn-primary')->addClass('btn-outline-secondary btn-sm');

$group = new Form_Group(gettext('MAC Address'));
$group->add($macaddress);
if (!empty($mymac)) {
	$group->add($btnmymac);
}
$group->setHelp(gettext('MAC address of the client to match (6 hex octets separated by colons).'));
$section->add($group);

$cid_help = gettext('An optional identifier to match based on the value sent by the client (RFC 2132).');
if (dhcp_is_backend('kea')) {
	$cid_help .= '<br /><br />';
	$cid_help .= gettext('Kea DHCP will match on MAC address if both MAC address and client identifier are set for a static mapping.');
}

$section->addInput(new Form_Input(
	'cid',
	gettext('Client Identifier'),
	'text',
	$pconfig['cid']
))->addClass('autotrim')
  ->setHelp($cid_help);

$section->addInput(new Form_IpAddress(
	'ipaddr',
	gettext('IP Address'),
	$pconfig['ipaddr'],
	'V4'
))->addClass('autotrim')
  ->setHelp(gettext('IPv4 address to assign this client.%1$s%1$s' .
		'Address must be outside of any defined pools. ' .
		'If no IPv4 address is given, one will be dynamically allocated from a pool.%1$s' .
		'The same IP address may be assigned to multiple mappings.'), '<br />');

$section->addInput(new Form_Checkbox(
	'arp_table_static_entry',
	gettext('Static ARP Entry'),
	gettext('Create a static ARP table entry for this MAC & IP Address pair.'),
	$pconfig['arp_table_static_entry']
));

$section->addInput(new Form_Input(
	'hostname',
	gettext('Hostname'),
	'text',
	$pconfig['hostname']
))->addClass('autotrim')
  ->setHelp(gettext('Name of the client host without the domain part.'));

$section->addInput(new Form_Input(
	'descr',
	gettext('Description'),
	'text',
	$pconfig['descr']
))->setHelp(gettext('A description for administrative reference (not parsed).'));

$section->addInput(new Form_Select(
	'earlydnsregpolicy',
	gettext('Early DNS Registration'),
	array_get_path($pconfig, 'earlydnsregpolicy', 'default'),
	$dnsregpolicy_values
))->setHelp(gettext('Optionally overides the subnet early DNS registration policy to force a specific policy.'));

$form->add($section);

$section = new Form_Section(gettext('Server Options'));

$winsserver_holder = config_get_path('dhcpd/'.$if.'/winsserver', []);

$section->addInput(new Form_IpAddress(
	'wins1',
	gettext('WINS Servers'),
	$pconfig['wins1'],
	'V4'
))->addClass('autotrim')
  ->setAttribute('placeholder', ($winsserver_holder[0] ?? gettext('WINS Server 1')));

$section->addInput(new Form_IpAddress(
	'wins2',
	null,
	$pconfig['wins2'],
	'V4'
))->addClass('autotrim')
  ->setAttribute('placeholder', ($winsserver_holder[1] ?? gettext('WINS Server 2')));

$dns_holder = [];
foreach (config_get_path('system/dnsserver', []) as $dnsserver) {
	if (is_ipaddrv4($dnsserver)) {
		$dns_holder[] = $dnsserver;
	}
}

if (config_path_enabled('dnsmasq') ||
    config_path_enabled('unbound')) {
    $dns_holder = [get_interface_ip($if)];
}

$subnet_dnsservers = config_get_path('dhcpd/'.$if.'/dnsserver', []);
if (!empty($subnet_dnsservers)) {
	$dns_holder = $subnet_dnsservers;
}

for ($idx=1; $idx<=4; $idx++) {
	$section->addInput(new Form_IpAddress(
		'dns' . $idx,
		($idx == 1) ? gettext('DNS Servers') : null,
		$pconfig['dns' . $idx],
		'V4'
	))->addClass('autotrim')
	  ->setAttribute('placeholder', ($dns_holder[$idx - 1] ?? sprintf(gettext('DNS Server %s'), $idx)))->setHelp(($idx == 4) ? 'Leave blank to use the system default DNS servers: The IP address of this firewall interface if DNS Resolver or Forwarder is enabled, otherwise the servers configured in General settings or those obtained dynamically.':'');
}

$form->add($section);

$section = new Form_Section(gettext('Other DHCP Options'));

$ifip = get_interface_ip($if);

/* interface ip has lowest priority */
$gateway_holder = $ifip;

/* subnet/primary pool has highest priority */
$subnet_gateway = config_get_path('dhcpd/'.$if.'/gateway');
if (!empty($subnet_gateway)) {
	$gateway_holder = $subnet_gateway;
}

$section->addInput(new Form_Input(
	'gateway',
	gettext('Gateway'),
	'text',
	$pconfig['gateway']
))->addClass('autotrim')
  ->setAttribute('placeholder', $gateway_holder)
  ->setHelp(gettext('The default is to use the IP address of this firewall interface as the gateway. Specify an alternate gateway here if this is not the correct gateway for the network.'));

$domain_holder = config_get_path('system/domain');

$subnet_domain = config_get_path('dhcpd/'.$if.'/domain');
if (!empty($subnet_domain)) {
	$domain_holder = $subnet_domain;
}

$section->addInput(new Form_Input(
	'domain',
	gettext('Domain Name'),
	'text',
	$pconfig['domain']
))->addClass('autotrim')
  ->setAttribute('placeholder', $domain_holder)
  ->setHelp(gettext('The default is to use the domain name of this firewall as the default domain name provided by DHCP. An alternate domain name may be specified here.'));

$searchlist_holder = config_get_path('dhcpd/'.$if.'/domainsearchlist');
if (empty($searchlist_holder)) {
	$searchlist_holder = 'example.com;sub.example.com';
}

$section->addInput(new Form_Input(
	'domainsearchlist',
	gettext('Domain Search List'),
	'text',
	$pconfig['domainsearchlist']
))->addClass('autotrim')
  ->setAttribute('placeholder', $searchlist_holder)
  ->setHelp(gettext('The DHCP server can optionally provide a domain search list. Use the semicolon character as separator.'));

if (dhcp_is_backend('isc')):
$section->addInput(new Form_Input(
	'deftime',
	gettext('Default Lease Time'),
	'text',
	$pconfig['deftime']
))->setHelp(gettext('Used for clients that do not ask for a specific expiration time. The default is 7200 seconds.'));

$section->addInput(new Form_Input(
	'maxtime',
	gettext('Maximum Lease Time'),
	'text',
	$pconfig['maxtime']
))->setHelp(gettext('This is the maximum lease time for clients that ask for a specific expiration time. The default is 86400 seconds.'));
endif;

if (dhcp_is_backend('isc')):
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
	gettext('DHCP Registration'),
	gettext('Enable registration of DHCP client names in DNS.'),
	$pconfig['ddnsupdate']
));

$section->addInput(new Form_Checkbox(
	'ddnsforcehostname',
	gettext('DDNS Hostname'),
	gettext('Make dynamic DNS registered hostname the same as Hostname above.'),
	$pconfig['ddnsforcehostname']
));

$section->addInput(new Form_Input(
	'ddnsdomain',
	gettext('DDNS Domain'),
	'text',
	$pconfig['ddnsdomain']
))->setHelp(gettext('Leave blank to disable dynamic DNS registration. Enter the dynamic DNS domain which will ' .
	    'be used to register client names in the DNS server. Only the first defined set of option for each ' .
	    'domain will be honored if it is used for multiple interfaces/entries.'));

$section->addInput(new Form_IpAddress(
	'ddnsdomainprimary',
	gettext('Primary DDNS Address'),
	$pconfig['ddnsdomainprimary'],
	'BOTH'
))->setHelp(gettext('Primary domain name server IP address for the dynamic domain name.'));

$section->addInput(new Form_IpAddress(
	'ddnsdomainsecondary',
	gettext('Secondary DDNS Address'),
	$pconfig['ddnsdomainsecondary'],
	'BOTH'
))->setHelp(gettext('Secondary domain name server IP address for the dynamic domain name.'));

$section->addInput(new Form_Input(
	'ddnsdomainkeyname',
	gettext('DDNS Domain Key Name'),
	'text',
	$pconfig['ddnsdomainkeyname']
))->setHelp(gettext('Enter the dynamic DNS domain key name which will be used to register client names in the DNS server.'));

$section->addInput(new Form_Select(
	'ddnsdomainkeyalgorithm',
	gettext('Key Algorithm'),
	$pconfig['ddnsdomainkeyalgorithm'],
	$ddnsdomainkeyalgorithms
));

$section->addInput(new Form_Input(
	'ddnsdomainkey',
	gettext('DDNS Domain Key Secret'),
	'text',
	$pconfig['ddnsdomainkey']
))->setHelp(gettext('Enter the dynamic DNS domain key secret which will be used to register client names in the DNS server.'));
endif;

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


for ($idx = 1; $idx <= 4; $idx++) {
	$section->addInput(new Form_IpAddress(
		'ntp'.$idx,
		sprintf(gettext('NTP Server %s'), $idx),
		$pconfig['ntp'.$idx],
		'HOSTV4'
	))->addClass('autotrim')
	  ->setAttribute('placeholder', sprintf(gettext('NTP Server %s'), $idx));
}

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
  ->setHelp(gettext('Leave blank to disable. Enter a full hostname or IP for the TFTP server.'));

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

if (dhcp_is_backend('isc')):
$section->addInput(new Form_IpAddress(
	'nextserver',
	gettext('Next Server'),
	$pconfig['nextserver'],
	'V4'
))->setHelp(gettext('Enter the IP address of the next server'));
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
			'All five filenames and a configured boot server are necessary for UEFI & ARM to work!'));

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
	gettext('Add Custom Option'),
	null,
	'fa-solid fa-plus'
))->addClass('btn-outline-secondary')
  ->setHelp(gettext('Enter the DHCP option number, type and the value for each item to include in the DHCP lease information.'));
$section->add($group);
endif;


$form->add($section);

if (dhcp_is_backend('kea')):
$section = new Form_Section(gettext('Custom Configuration'));
$kea_custom_input = $section->addInput(new Form_Textarea(
	'custom_kea_config',
	gettext('JSON Configuration'),
	array_get_path($pconfig, 'custom_kea_config')
))->setWidth(8)->setHelp(gettext('JSON to be merged into the "%1$s" section of the generated Kea DHCPv4 configuration.%2$sThe input must be a well formed JSON object and should not include the "%1$s" key itself.'), 'reservation', '<br/>');
if (!kea_custom_config_editable()) {
	$kea_custom_input->setReadonly();
}
$form->add($section);
endif;

fs_form_cancel($form, 'services_dhcp.php?if=' . urlencode($if) . '&view=mappings');
print($form);
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
		hideCheckbox('ddnsforcehostname', !showadvdns);
		hideInput('ddnsdomain', !showadvdns);
		hideInput('ddnsdomainprimary', !showadvdns);
		hideInput('ddnsdomainsecondary', !showadvdns);
		hideInput('ddnsdomainkeyname', !showadvdns);
		hideInput('ddnsdomainkey', !showadvdns);
		hideInput('ddnsdomainkeyalgorithm', !showadvdns);

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

	// On click, copy the hidden 'mymac' text to the 'mac' input
	$("#btnmymac").click(function() {
		$('#mac').val('<?=$mymac?>');
	});

	// On initial load
	show_advdns(true);
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
include("foot.inc");
