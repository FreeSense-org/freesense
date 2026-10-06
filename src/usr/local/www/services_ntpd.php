<?php
/*
 * services_ntpd.php
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
##|*IDENT=page-services-ntpd
##|*NAME=Services: NTP Settings
##|*DESCR=Allow access to the 'Services: NTP Settings' page.
##|*MATCH=services_ntpd.php*
##|-PRIV

require_once("guiconfig.inc");
require_once('rrd.inc');
require_once("shaper.inc");
require_once("services_ntpd.inc");

global $ntp_poll_min_default, $ntp_poll_max_default, $ntp_server_types;
$ntp_poll_values = system_ntp_poll_values();
$max_candidate_peers = ntpd_candidate_peer_limits()['max'];
$min_candidate_peers = ntpd_candidate_peer_limits()['min'];

ntpd_migrate_openntpd();

if ($_POST) {
	unset($input_errors);
	$leapfile = is_uploaded_file($_FILES['leapfile']['tmp_name']) ? file_get_contents($_FILES['leapfile']['tmp_name']) : null;
	$rv = ntpd_save_settings($_POST, $leapfile);
	$input_errors = $rv['input_errors'];
	if ($rv['changes_applied']) {
		$changes_applied = true;
		$retval = $rv['retval'];
	}
}

$pconfig = ntpd_settings();
if (config_get_path('ntpd/enable') != $pconfig['enable']) {
	config_set_path('ntpd/enable', $pconfig['enable']);
}
if (empty($pconfig['interface'])) {
	config_set_path('ntpd/interface', '');
}

$pgtitle = array(gettext("Services"), gettext("NTP"), gettext("Settings"));
$pglinks = array("", "@self", "@self");
$shortcut_section = "ntp";
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($changes_applied) {
	print_apply_result_box($retval);
}

$tab_array = array();
$tab_array[] = array(gettext("Settings"), true, "services_ntpd.php");
$tab_array[] = array(gettext("ACLs"), false, "services_ntpd_acls.php");
$tab_array[] = array(gettext("Serial GPS"), false, "services_ntpd_gps.php");
$tab_array[] = array(gettext("PPS"), false, "services_ntpd_pps.php");
display_top_tabs($tab_array);

$form = new Form;
$form->setMultipartEncoding();	// Allow file uploads

$section = new Form_Section('NTP Server Configuration');

$section->addInput(new Form_Checkbox(
	'enable',
	'Enable',
	'Enable NTP Server',
	($pconfig['enable'] == 'enabled')
))->setHelp('You may need to disable NTP if %1$s is running in a virtual machine and the host is responsible for the clock.', g_get('product_label'));

$iflist = ntpd_build_interface_list($pconfig['interface']);

$section->addInput(new Form_Select(
	'interface',
	'Interface',
	$iflist['selected'],
	$iflist['options'],
	true
))->setHelp('Interfaces without an IP address will not be shown.%1$s' .
			'Selecting no interfaces will listen on all interfaces with a wildcard.%1$s' .
			'Selecting all interfaces will explicitly listen on only the interfaces/IPs specified.', '<br />');

$timeserver_rows = ntpd_timeserver_rows();
foreach ($timeserver_rows as $counter => $row) {
	$group = new Form_Group($counter == 0 ? 'Time Servers':'');
	$group->addClass('repeatable');
	$group->setAttribute('max_repeats', NUMTIMESERVERS);
	$group->setAttribute('max_repeats_alert', sprintf(gettext('%d is the maximum number of configured servers.'), NUMTIMESERVERS));

	$group->add(new Form_Input(
		'server' . $counter,
		null,
		'text',
		$row['server'],
		['placeholder' => 'Hostname']
	 ))->setWidth(3);

	 $group->add(new Form_Checkbox(
		'servprefer' . $counter,
		null,
		null,
		$row['prefer']
	 ))->sethelp('Prefer');

	 $group->add(new Form_Checkbox(
		'servselect' . $counter,
		null,
		null,
		$row['noselect']
	 ))->sethelp('No Select');

	 $group->add(new Form_Checkbox(
		'servauth' . $counter,
		null,
		null,
		$row['auth']
	 ))->setHelp('Authenticated');

	$group->add(new Form_Select(
		'servistype' . $counter,
		null,
		$row['type'],
		$ntp_server_types
	 ))->sethelp('Type')->setWidth(2);

	$group->add(new Form_Button(
		'deleterow' . $counter,
		'Delete',
		null,
		'fa-solid fa-trash-can'
	))->addClass('btn-warning');

	 $section->add($group);
}

$section->addInput(new Form_Button(
	'addrow',
	'Add',
	null,
	'fa-solid fa-plus'
))->addClass('btn-success');

$section->addInput(new Form_StaticText(
	null,
	$btnaddrow
))->setHelp(
	'NTP will only sync if a majority of the servers agree on the time.  For best results you should configure between 3 and 5 servers ' .
	'(%4$sNTP support pages recommend at least 4 or 5%5$s), or a pool. If only one server is configured, it %2$swill%3$s be believed, and if 2 servers ' .
	'are configured and they disagree, %2$sneither%3$s will be believed. Options:%1$s' .
	'%2$sPrefer%3$s - NTP should favor the use of this server more than all others.%1$s' .
	'%2$sNo Select%3$s - NTP should not use this server for time, but stats for this server will be collected and displayed.%1$s' .
	'%2$sType%3$s - Server, Peer or a Pool of NTP servers and not a single address. This is assumed for *.pool.ntp.org.',
	'<br />',
	'<b>',
	'</b>',
	'<a target="_blank" href="https://support.ntp.org/bin/view/Support/ConfiguringNTP">',
	'</a>'
	);

$section->addInput(new Form_Input(
	'ntpmaxpeers',
	'Max candidate pool peers',
	'number',
	$pconfig['ntpmaxpeers'],
	['min' => $min_candidate_peers, 'max' => $max_candidate_peers]
))->setHelp('Maximum number of candidate peers in the NTP pool. This value should be set low enough to provide sufficient alternate sources ' .
	    'while not contacting an excessively large number of peers. ' .
	    'Many servers inside public pools are provided by volunteers, ' .
	    'and a large candidate pool places unnecessary extra load ' .
	    'on the volunteer time servers for little to no added benefit. (Default: 5).');

$section->addInput(new Form_Input(
	'ntporphan',
	'Orphan Mode',
	'text',
	$pconfig['orphan'],
	['placeholder' => "12"]
))->setHelp('Orphan mode allows the system clock to be used when no other clocks are available. ' .
			'The number here specifies the stratum reported during orphan mode and should normally be set to a number high enough ' .
			'to insure that any other servers available to clients are preferred over this server (default: 12).');

$section->addInput(new Form_Select(
	'ntpminpoll',
	'Minimum Poll Interval',
	$pconfig['ntpminpoll'],
	$ntp_poll_values
))->setHelp('Minimum poll interval for NTP messages. If set, must be less than or equal to Maximum Poll Interval.');

$section->addInput(new Form_Select(
	'ntpmaxpoll',
	'Maximum Poll Interval',
	$pconfig['ntpmaxpoll'],
	$ntp_poll_values
))->setHelp('Maximum poll interval for NTP messages. If set, must be greater than or equal to Minimum Poll Interval.');

$section->addInput(new Form_Checkbox(
	'statsgraph',
	'NTP Graphs',
	'Enable RRD graphs of NTP statistics (default: disabled).',
	$pconfig['statsgraph']
));

$section->addInput(new Form_Checkbox(
	'logpeer',
	'Logging',
	'Log peer messages (default: disabled).',
	$pconfig['logpeer']
));

$section->addInput(new Form_Checkbox(
	'logsys',
	null,
	'Log system messages (default: disabled).',
	$pconfig['logsys']
))->setHelp('These options enable additional messages from NTP to be written to the System Log %1$sStatus > System Logs > NTP%2$s',
			'<a href="status_logs.php?logfile=ntpd">', '</a>.');

// Statistics logging section
$btnadv = new Form_Button(
	'btnadvstats',
	gettext('Display Advanced'),
	null,
	'fa-solid fa-gear'
);

$btnadv->setAttribute('type','button')->addClass('btn-info btn-sm');

$section->addInput(new Form_StaticText(
	'Statistics Logging',
	$btnadv
))->setHelp('Warning: These options will create persistent daily log files in /var/log/ntp.');

$section->addInput(new Form_Checkbox(
	'clockstats',
	null,
	'Log reference clock statistics (default: disabled).',
	$pconfig['clockstats']
));

$section->addInput(new Form_Checkbox(
	'loopstats',
	null,
	'Log clock discipline statistics (default: disabled).',
	$pconfig['loopstats']
));

$section->addInput(new Form_Checkbox(
	'peerstats',
	null,
	'Log NTP peer statistics (default: disabled).',
	$pconfig['peerstats']
));

// Leap seconds section
$btnadv = new Form_Button(
	'btnadvleap',
	gettext('Display Advanced'),
	null,
	'fa-solid fa-gear'
);

$btnadv->setAttribute('type','button')->addClass('btn-info btn-sm');

$section->addInput(new Form_StaticText(
	'Leap seconds',
	$btnadv
))->setHelp(
	'Leap seconds may be added or subtracted at the end of June or December. Leap seconds are administered by the ' .
	'%1$sIERS%2$s, who publish them in their Bulletin C approximately 6 - 12 months in advance.  Normally this correction ' .
	'should only be needed if the server is a stratum 1 NTP server, but many NTP servers do not advertise an upcoming leap ' .
	'second when other NTP servers synchronise to them.%3$s%4$sIf the leap second is important to your network services, ' .
	'it is %6$sgood practice%2$s to download and add the leap second file at least a day in advance of any time correction%5$s.%3$s ' .
	'More information and files for downloading can be found on their %1$swebsite%2$s, and also on the %7$sNIST%2$s and %8$sNTP%2$s websites.',
	'<a target="_blank" href="https://www.iers.org">',
	'</a>',
	'<br />',
	'<b>',
	'</b>',
	'<a target="_blank" href="https://support.ntp.org/bin/view/Support/ConfiguringNTP">',
	'<a target="_blank" href="https://www.nist.gov">',
	'<a target="_blank" href="https://www.ntp.org">'
);

$section->addInput(new Form_Textarea(
	'leaptext',
	null,
	base64_decode(chunk_split($pconfig['leapsec']))
))->setHelp('Enter Leap second configuration as text OR select a file to upload.');

$section->addInput(new Form_Input(
	'leapfile',
	null,
	'file'
))->addClass('btn-secondary');

$section->addInput(new Form_Select(
	'dnsresolv',
	'DNS Resolution',
	$pconfig['dnsresolv'],
	ntpd_dnsresolv_choices()
))->setHelp('Force NTP peer DNS resolution IP protocol.');

$section->addInput(new Form_Checkbox(
	'serverauth',
	'Enable NTP Server Authentication',
	'Enable NTPv3 authentication (RFC 1305)',
	$pconfig['serverauth']
))->setHelp('Authentication allows the NTP client to confirm it is communicating with the intended server, ' .
	    'which protects against man-in-the-middle attacks.');

$group = new Form_Group('Authentication key');
$group->addClass('ntpserverauth');

$group->add(new Form_Input(
	'serverauthkeyid',
	'Key ID',
	null,
	$pconfig['serverauthkeyid'],
	['placeholder' => 'Key ID', 'type' => 'number', 'min' => 1, 'max' => 65535, 'step' => 1]
))->setWidth(2)->setHelp('ID associated with the authentication key');

$group->add(new Form_Input(
	'serverauthkey',
	'NTP Authentication key',
	'text',
	base64_decode($pconfig['serverauthkey']),
	['placeholder' => 'NTP Authentication key']
))->setHelp(
	'Key format: %1$s MD5 - The key is 1 to 20 printable characters %1$s' .
	'SHA1 - The key is a hex-encoded ASCII string of 40 characters %1$s' .
	'SHA256 - The key is a hex-encoded ASCII string of 64 characters',
	'<br />'
);

$group->add(new Form_Select(
	'serverauthalgo',
	null,
	$pconfig['serverauthalgo'],
	$ntp_auth_halgos
))->setWidth(2)->setHelp('Digest algorithm');

$section->add($group);

$form->add($section);

print($form);

?>

<script type="text/javascript">
//<![CDATA[
	// If this variable is declared, any help text will not be deleted when rows are added
	// IOW the help text will appear on every row
	retainhelp = true;
</script>

<script type="text/javascript">
//<![CDATA[
events.push(function() {

	// Show advanced stats options ============================================
	var showadvstats = false;

	function show_advstats(ispageload) {
		var text;
		// On page load decide the initial state based on the data.
		if (ispageload) {
<?php
			if (!$pconfig['clockstats'] && !$pconfig['loopstats'] && !$pconfig['peerstats']) {
				$showadv = false;
			} else {
				$showadv = true;
			}
?>
			showadvstats = <?php if ($showadv) {echo 'true';} else {echo 'false';} ?>;
		} else {
			// It was a click, swap the state.
			showadvstats = !showadvstats;
		}

		hideCheckbox('clockstats', !showadvstats);
		hideCheckbox('loopstats', !showadvstats);
		hideCheckbox('peerstats', !showadvstats);

		if (showadvstats) {
			text = "<?=gettext('Hide Advanced');?>";
		} else {
			text = "<?=gettext('Display Advanced');?>";
		}
		var children = $('#btnadvstats').children();
		$('#btnadvstats').text(text).prepend(children);
	}

	$('#btnadvstats').click(function(event) {
		show_advstats();
	});

	// Show advanced leap second options ======================================
	var showadvleap = false;

	function show_advleap(ispageload) {
		var text;
		// On page load decide the initial state based on the data.
		if (ispageload) {
<?php
			// Note: leapfile is not a field saved in the config, so no need to test for it here.
			// leapsec is the encoded text in the config, leaptext is not a pconfig[] key.
			if (empty($pconfig['leapsec'])) {
				$showadv = false;
			} else {
				$showadv = true;
			}
?>
			showadvleap = <?php if ($showadv) {echo 'true';} else {echo 'false';} ?>;
		} else {
			// It was a click, swap the state.
			showadvleap = !showadvleap;
		}

		hideInput('leaptext', !showadvleap);
		hideInput('leapfile', !showadvleap);

		if (showadvleap) {
			text = "<?=gettext('Hide Advanced');?>";
		} else {
			text = "<?=gettext('Display Advanced');?>";
		}
		var children = $('#btnadvleap').children();
		$('#btnadvleap').text(text).prepend(children);
	}

	function change_serverauth() {
		hideClass('ntpserverauth', !($('#serverauth').prop('checked')));
	}

	$('#btnadvleap').click(function(event) {
		show_advleap();
	});

	$('#serverauth').change(function () {
		change_serverauth();
	});

	// Set initial states
	show_advstats(true);
	show_advleap(true);
	change_serverauth();

	// Suppress "Delete row" button if there are fewer than two rows
	checkLastRow();
});
//]]>
</script>

<?php include("foot.inc");
