<?php
/*
 * system.php
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
##|*IDENT=page-system-generalsetup
##|*NAME=System: General Setup
##|*DESCR=Allow access to the 'System: General Setup' page.
##|*MATCH=system.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("functions.inc");
require_once("filter.inc");
require_once("shaper.inc");
require_once("system.inc");
require_once("system_general.inc");

$pconfig = system_general_settings();

$arr_gateways = get_gateways();

function is_timezone($elt) {
	return !preg_match("/\/$/", $elt);
}

if ($pconfig['timezone'] <> $_POST['timezone']) {
	filter_pflog_start();
}

$timezonelist = system_get_timezone_list();
$timezonedesc = system_general_timezone_descr($timezonelist);

$multiwan = 0;
$multiwan6 = 0;
foreach ($arr_gateways as $gw) {
	if ($gw['ipprotocol'] == 'inet') {
		$multiwan++;
		if ($multiwan > 1) {
			break;
		}
	} else {
		$multiwan6++;
		if ($multiwan6 > 1) {
			break;
		}
	}
}

if ($_POST) {
	$rv = system_general_save($_POST);

	$pconfig = $rv['pconfig'];
	$input_errors = $rv['input_errors'];
	$changes_applied = $rv['changes_applied'];
	$retval = $rv['retval'];
}

$pgtitle = array(gettext("System"), gettext("General Setup"));
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($changes_applied) {
	print_apply_result_box($retval);
}
?>
<div id="container">
<?php

$form = new Form;
$section = new Form_Section('System');
$section->addInput(new Form_Input(
	'hostname',
	'*Hostname',
	'text',
	$pconfig['hostname'],
	['placeholder' => 'FreeSense']
))->setHelp('Name of the firewall host, without domain part.');

$section->addInput(new Form_Input(
	'domain',
	'*Domain',
	'text',
	$pconfig['domain'],
	['placeholder' => 'home.arpa, example.com, home, office, private, etc.']
))->setHelp('Domain name for the firewall.%1$s%1$s' .
	'Do not end the domain name with \'.local\' as the final part (Top Level Domain, TLD). ' .
	'The \'local\' TLD is widely used by mDNS (e.g. Avahi, Bonjour, Rendezvous, Airprint, Airplay) ' .
	'and some Windows systems and networked devices. ' .
	'These will not network correctly if the router uses \'local\' as its TLD. ' .
	'Alternatives such as \'home.arpa\', \'local.lan\', or \'mylocal\' are safe.',
	'<br/>'
);

$form->add($section);

$section = new Form_Section('DNS Server Settings');

if (!is_array($pconfig['dnsserver'])) {
	$pconfig['dnsserver'] = array();
}

$dnsserver_count = count($pconfig['dnsserver']);
$dnsserver_num = 0;
$dnsserver_help = gettext("Address") . '<br/>' . gettext("Enter IP addresses to be used by the system for DNS resolution.") . " " .
	gettext("These are also used for the DHCP service, DNS Forwarder and DNS Resolver when it has DNS Query Forwarding enabled.");
$dnshost_help = gettext("Hostname") . '<br/>' . gettext("Enter the DNS Server Hostname for TLS Verification in the DNS Resolver (optional).");
$dnsgw_help = gettext("Gateway") . '<br/>'. gettext("Optionally select the gateway for each DNS server.") . " " .
	gettext("When using multiple WAN connections there should be at least one unique DNS server per gateway.");

// If there are no DNS servers, make an empty entry for initial display.
if ($dnsserver_count == 0) {
	$pconfig['dnsserver'][] = '';
}

foreach ($pconfig['dnsserver'] as $dnsserver) {

	$is_last_dnsserver = (($dnsserver_num == $dnsserver_count - 1) || $dnsserver_count == 0);
	$group = new Form_Group($dnsserver_num == 0 ? 'DNS Servers':'');
	$group->addClass('repeatable');

	$group->add(new Form_Input(
		'dns' . $dnsserver_num,
		'DNS Server',
		'text',
		$dnsserver
	))->setHelp(($is_last_dnsserver) ? $dnsserver_help:null);

	$group->add(new Form_Input(
		'dnshost' . $dnsserver_num,
		'DNS Hostname',
		'text',
		$pconfig['dnshost' . $dnsserver_num]
	))->setHelp(($is_last_dnsserver) ? $dnshost_help:null);

	if (($multiwan > 1) || ($multiwan6 > 1)) {
		$options = array('none' => 'none');

		foreach ($arr_gateways as $gwname => $gwitem) {
			if ((is_ipaddrv4(lookup_gateway_ip_by_name($pconfig[$dnsgw])) && (is_ipaddrv6($gwitem['gateway'])))) {
				continue;
			}

			if ((is_ipaddrv6(lookup_gateway_ip_by_name($pconfig[$dnsgw])) && (is_ipaddrv4($gwitem['gateway'])))) {
				continue;
			}

			$options[$gwname] = $gwname.' - '.$gwitem['friendlyiface'].' - '.$gwitem['gateway'];
		}

		$group->add(new Form_Select(
			'dnsgw' . $dnsserver_num,
			'Gateway',
			$pconfig['dnsgw' . $dnsserver_num],
			$options
		))->setWidth(4)->setHelp(($is_last_dnsserver) ? $dnsgw_help:null);
	}

	$group->add(new Form_Button(
		'deleterow' . $dnsserver_num,
		'Delete',
		null,
		'fa-solid fa-trash-can'
	))->setWidth(2)->addClass('btn-warning');

	$section->add($group);
	$dnsserver_num++;
}

$section->addInput(new Form_Button(
	'addrow',
	'Add DNS Server',
	null,
	'fa-solid fa-plus'
))->addClass('btn-success addbtn');

$section->addInput(new Form_Checkbox(
	'dnsallowoverride',
	'DNS Server Override',
	'Allow DNS server list to be overridden by DHCP/PPP on WAN or remote OpenVPN server',
	$pconfig['dnsallowoverride']
))->setHelp('If this option is set, %s will use DNS servers '.
	'assigned by a DHCP/PPP server on WAN or a remote OpenVPN server (if Pull DNS ' .
	'option is enabled) for its own purposes (including the DNS Forwarder/DNS Resolver). '.
        'However, they will not be assigned to DHCP clients.', g_get('product_label'));

$section->addInput(new Form_Select(
	'dnslocalhost',
	'DNS Resolution Behavior',
	$pconfig['dnslocalhost'],
	array(
		''       => 'Use local DNS (127.0.0.1), fall back to remote DNS Servers (Default)',
		'local'  => 'Use local DNS (127.0.0.1), ignore remote DNS Servers',
		'remote' => 'Use remote DNS Servers, ignore local DNS',
	)
))->setHelp('By default the firewall will use local DNS service (127.0.0.1, DNS '.
	'Resolver or Forwarder) as the first DNS server when possible, and it '.
	'will fall back to remote DNS servers otherwise. Use this option to '.
	'choose alternate behaviors.');

$form->add($section);

$section = new Form_Section('Localization');

$section->addInput(new Form_Select(
	'timezone',
	'*Timezone',
	$pconfig['timezone'],
	array_combine($timezonelist, $timezonedesc)
))->setHelp('Select a geographic region name (Continent/Location) to determine the timezone for the firewall. %1$s' .
	'Choose a special or "Etc" zone only in cases where the geographic zones do not properly handle the clock offset required for this firewall.', '<br/>');

$section->addInput(new Form_Input(
	'timeservers',
	'Timeservers',
	'text',
	$pconfig['timeservers']
))->setHelp('Use a space to separate multiple hosts (only one required). '.
	'Remember to set up at least one DNS server if a host name is entered here!');

$section->addInput(new Form_Select(
	'language',
	'*Language',
	$pconfig['language'],
	get_locale_list()
))->setHelp('Choose a language for the webConfigurator');

$form->add($section);

$section = new Form_Section('webConfigurator');

gen_webguicss_field($section, $pconfig['webguicss']);
gen_webguifixedmenu_field($section, $pconfig['webguifixedmenu']);
gen_webguihostnamemenu_field($section, $pconfig['webguihostnamemenu']);
gen_dashboardcolumns_field($section, $pconfig['dashboardcolumns']);
gen_interfacessort_field($section, $pconfig['interfacessort']);
gen_associatedpanels_fields(
	$section,
	$pconfig['dashboardavailablewidgetspanel'],
	$pconfig['systemlogsfilterpanel'],
	$pconfig['systemlogsmanagelogpanel'],
	$pconfig['statusmonitoringsettingspanel']);
gen_requirestatefilter_field($section, $pconfig['requirestatefilter']);
gen_requirefirewallinterface_field($section, $pconfig['requirefirewallinterface']);
gen_webguileftcolumnhyper_field($section, $pconfig['webguileftcolumnhyper']);
gen_disablealiaspopupdetail_field($section, $pconfig['disablealiaspopupdetail']);

$section->addInput(new Form_Checkbox(
	'roworderdragging',
	'Disable dragging',
	'Disable dragging of firewall/NAT rules',
	$pconfig['roworderdragging']
))->setHelp('Disables dragging rows to allow selecting and copying row contents and avoid accidental changes.');

$section->addInput(new Form_Select(
	'logincss',
	'Login page color',
	$pconfig['logincss'],
	["1e3f75;" => gettext("Dark Blue"), "003300" => gettext("Dark green"), "770101" => gettext("Crimson red"),
	 "4b1263" => gettext("Purple"), "424142" => gettext("Gray"), "333333" => gettext("Dark gray"),
	 "000000" => gettext("Black"), "633215" => gettext("Dark brown"), "bf7703" => gettext("Brown"), 
	 "008000" => gettext("Green"), "007faa" => gettext("Light Blue"), "dc2a2a" => gettext("Red"),
	 "9b59b6" => gettext("Violet")]
))->setHelp('Choose a color for the login page');

$section->addInput(new Form_Checkbox(
	'loginshowhost',
	'Login hostname',
	'Show hostname on login banner',
	$pconfig['loginshowhost']
));

$section->addInput(new Form_Textarea(
	'login_message',
	'Login Message',
	$pconfig['login_message']
))->setHelp('Message to display on the login page, such as a warning or disclaimer. Plain text only.');

/*
$section->addInput(new Form_Input(
	'dashboardperiod',
	'Dashboard update period',
	'number',
	$pconfig['dashboardperiod'],
	['min' => '5', 'max' => '600']
))->setHelp('Time in seconds between dashboard widget updates. Small values cause ' .
			'more frequent updates but increase the load on the web server. ' .
			'Minimum is 5 seconds, maximum 600 seconds');
*/
$form->add($section);

print $form;

$csswarning = sprintf(gettext("%sUser-created themes are unsupported, use at your own risk."), "<br />");

?>
</div>

<script type="text/javascript">
//<![CDATA[
events.push(function() {

	function setThemeWarning() {
		if ($('#webguicss').val().startsWith("FreeSense")) {
			$('#csstxt').html("").addClass("text-default");
		} else {
			$('#csstxt').html("<?=$csswarning?>").addClass("text-danger");
		}
	}

	$('#webguicss').change(function() {
		setThemeWarning();
	});

	setThemeWarning();

	// Suppress "Delete row" button if there are fewer than two rows
	checkLastRow();
});
//]]>
</script>

<?php
include("foot.inc");
?>
