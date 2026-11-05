<?php
/*
 * services_ntpd_pps.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
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
##|*IDENT=page-services-ntpd-pps
##|*NAME=Services: NTP PPS
##|*DESCR=Allow access to the 'Services: NTP PPS' page.
##|*MATCH=services_ntpd_pps.php*
##|-PRIV

require_once("guiconfig.inc");

global $ntp_poll_min_default_pps, $ntp_poll_max_default_pps;
$ntp_poll_values = system_ntp_poll_values();

$serialports = get_serial_ports(true);

if ($_POST) {
	unset($input_errors);

	list($clean, $input_errors) = input_check($_POST, [
		'ppsport' => ['enum', 'label' => gettext('Serial Port'), 'values' => array_keys($serialports)],
		'ppsfudge1' => ['decimal', 'label' => gettext('Fudge Time')],
		'ppsstratum' => ['int', 'label' => gettext('Stratum'), 'max' => 16],
		'ppsrefid' => ['ntp_refid', 'label' => gettext('Clock ID')],
		'ppsselect' => ['flag'],
		'ppsflag2' => ['flag'],
		'ppsflag3' => ['flag'],
		'ppsflag4' => ['flag'],
		'ppsminpoll' => ['enum', 'label' => gettext('Minimum Poll Interval'), 'values' => array_keys($ntp_poll_values)],
		'ppsmaxpoll' => ['enum', 'label' => gettext('Maximum Poll Interval'), 'values' => array_keys($ntp_poll_values)],
	]);

	if (is_numericint($clean['ppsminpoll'] ?? '') && is_numericint($clean['ppsmaxpoll'] ?? '') &&
	    ((int)$clean['ppsmaxpoll'] < (int)$clean['ppsminpoll'])) {
		$input_errors[] = gettext("The supplied value for Minimum Poll Interval is higher than Maximum Poll Interval.");
	}

	if (!$input_errors) {
		if (!empty($clean['ppsport']) && array_key_exists($clean['ppsport'], $serialports)) {
			config_set_path('ntpd/pps/port', $clean['ppsport']);
		} else {
			config_del_path('ntpd/pps');
		}

		if (!empty($clean['ppsfudge1'])) {
			config_set_path('ntpd/pps/fudge1', $clean['ppsfudge1']);
		} else {
			config_del_path('ntpd/pps/fudge1');
		}

		if (!empty($clean['ppsstratum'])) {
			config_set_path('ntpd/pps/stratum', $clean['ppsstratum']);
		} else {
			config_del_path('ntpd/pps/stratum');
		}

		if (!empty($clean['ppsselect'])) {
			config_set_path('ntpd/pps/noselect', $clean['ppsselect']);
		} else {
			config_del_path('ntpd/pps/noselect');
		}

		if (!empty($clean['ppsflag2'])) {
			config_set_path('ntpd/pps/flag2', $clean['ppsflag2']);
		} else {
			config_del_path('ntpd/pps/flag2');
		}

		if (!empty($clean['ppsflag3'])) {
			config_set_path('ntpd/pps/flag3', $clean['ppsflag3']);
		} else {
			config_del_path('ntpd/pps/flag3');
		}

		if (!empty($clean['ppsflag4'])) {
			config_set_path('ntpd/pps/flag4', $clean['ppsflag4']);
		} else {
			config_del_path('ntpd/pps/flag4');
		}

		if (!empty($clean['ppsrefid'])) {
			config_set_path('ntpd/pps/refid', $clean['ppsrefid']);
		} else {
			config_del_path('ntpd/pps/refid');
		}
		config_set_path('ntpd/pps/ppsminpoll', $clean['ppsminpoll'] ?? '');
		config_set_path('ntpd/pps/ppsmaxpoll', $clean['ppsmaxpoll'] ?? '');

		write_config("Updated NTP PPS Settings");

		$changes_applied = true;
		$retval = 0;
		$retval |= system_ntp_configure();
	}
}

$pconfig = config_get_path('ntpd/pps', []);
$pgtitle = array(gettext("Services"), gettext("NTP"), gettext("PPS"));
$pglinks = array("", "services_ntpd.php", "@self");
$shortcut_section = "ntp";
include("head.inc");

if ($input_errors) {
    print_input_errors($input_errors);
}

if ($changes_applied) {
	print_apply_result_box($retval);
}

fs_tabs('services-ntp', 'services_ntpd_pps.php');

$form = new Form;

$section = new Form_Section('NTP Serial PPS Configuration');

$section->addInput(new Form_StaticText(
	'Notes',
	'Devices with a Pulse Per Second output such as radios that receive a time signal from DCF77 (DE), JJY (JP), MSF (GB) or WWVB (US) may be used as a PPS reference for NTP. ' .
	'A serial GPS may also be used, but the serial GPS driver would usually be the better option. ' .
	'A PPS signal only provides a reference to the change of a second, so at least one other source to number the seconds is required.' . '<br /><br />' .
	'At least 3 additional time sources should be configured under ' .
	'<a href="services_ntpd.php">' . 'Services > NTP > Settings' . '</a>' . ' to reliably supply the time of each PPS pulse.'
));

if (!empty($serialports)) {
	$section->addInput(new Form_Select(
		'ppsport',
		'Serial Port',
		$pconfig['port'],
		['' => gettext('None')] + $serialports
	))->setHelp('All serial ports are listed, be sure to pick the port with the PPS source attached. ');
}

$section->addInput(new Form_Input(
	'ppsfudge1',
	'Fudge Time',
	'text',
	$pconfig['fudge1']
))->setHelp('Fudge time is used to specify the PPS signal offset from the actual second such as the transmission delay between the transmitter and the receiver (default: 0.0).');

$section->addInput(new Form_Input(
	'ppsstratum',
	'Stratum',
	'text',
	$pconfig['stratum']
))->setHelp('This may be used to change the PPS Clock stratum (default: 0). This may be useful to, for some reason, have ntpd prefer a different clock and just monitor this source.');

$section->addInput(new Form_Select(
	'ppsminpoll',
	'Minimum Poll Interval',
	$pconfig['ppsminpoll'],
	$ntp_poll_values
))->setHelp('Minimum poll interval for NTP messages. If set, must be less than or equal to Maximum Poll Interval.');

$section->addInput(new Form_Select(
	'ppsmaxpoll',
	'Maximum Poll Interval',
	$pconfig['ppsmaxpoll'],
	$ntp_poll_values
))->setHelp('Maximum poll interval for NTP messages. If set, must be greater than or equal to Minimum Poll Interval.');

$section->addInput(new Form_Checkbox(
	'ppsflag2',
	'Flags',
	'Enable falling edge PPS signal processing (default: unchecked, rising edge).',
	$pconfig['flag2']
));

$section->addInput(new Form_Checkbox(
	'ppsflag3',
	null,
	'Enable kernel PPS clock discipline (default: unchecked).',
	$pconfig['flag3']
));

$section->addInput(new Form_Checkbox(
	'ppsflag4',
	null,
	'Record a timestamp once for each second, useful for constructing Allan deviation plots (default: unchecked).',
	$pconfig['flag4']
));

$section->addInput(new Form_Input(
	'ppsrefid',
	'Clock ID',
	'text',
	$pconfig['refid'],
	['placeholder' => '1 to 4 characters']
))->setHelp('This may be used to change the PPS Clock ID (default: PPS).');

$form->add($section);
print($form);

include("foot.inc");
