<?php
/*
 * services_snmp.inc
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
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

/*
 * SNMP daemon logic shared by services_snmp.php and the REST API, moved
 * out of the page with the form fields as parameters. Like the page, a
 * save reconfigures bsnmpd at once; there is nothing to apply.
 */

require_once("config.gui.inc");
require_once("interfaces.inc");
require_once("services.inc");
require_once("util.inc");

/* The page's defaults for a missing configuration (in memory only; a save writes them). */
function snmp_init_config() {
	if (!is_array(config_get_path('snmpd'))) {
		config_set_path('snmpd', array());
		config_set_path('snmpd/rocommunity', "public");
		config_set_path('snmpd/pollport', "161");
	}

	if (!is_array(config_get_path('snmpd/modules'))) {
		config_set_path('snmpd/modules', array());
		config_set_path('snmpd/modules/mibii', true);
		config_set_path('snmpd/modules/netgraph', true);
		config_set_path('snmpd/modules/pf', true);
		config_set_path('snmpd/modules/hostres', true);
		config_set_path('snmpd/modules/bridge', true);
		config_set_path('snmpd/modules/ucd', true);
		config_set_path('snmpd/modules/regex', true);
	}
}

/* The settings form's fields, as the page preselects them. */
function snmp_settings() {
	$pconfig = array();
	$pconfig['enable'] = config_path_enabled('snmpd');
	$pconfig['pollport'] = config_get_path('snmpd/pollport');
	$pconfig['syslocation'] = config_get_path('snmpd/syslocation');
	$pconfig['syscontact'] = config_get_path('snmpd/syscontact');
	$pconfig['rocommunity'] = config_get_path('snmpd/rocommunity');
	/* disabled until some docs show up on what this does.
	$pconfig['rwenable'] = isset($config['snmpd']['rwenable']);
	$pconfig['rwcommunity'] = $config['snmpd']['rwcommunity'];
	*/
	$pconfig['trapenable'] = config_path_enabled('snmpd', 'trapenable');
	$pconfig['trapserver'] = config_get_path('snmpd/trapserver');
	$pconfig['trapserverport'] = config_get_path('snmpd/trapserverport');
	$pconfig['trapstring'] = config_get_path('snmpd/trapstring');

	$pconfig['mibii'] = config_path_enabled('snmpd/modules', 'mibii');
	$pconfig['netgraph'] = config_path_enabled('snmpd/modules', 'netgraph');
	$pconfig['pf'] = config_path_enabled('snmpd/modules', 'pf');
	$pconfig['hostres'] = config_path_enabled('snmpd/modules', 'hostres');
	$pconfig['bridge'] = config_path_enabled('snmpd/modules', 'bridge');
	$pconfig['ucd'] = config_path_enabled('snmpd/modules', 'ucd');
	$pconfig['regex'] = config_path_enabled('snmpd/modules', 'regex');

	$pconfig['ipprotocol'] = config_get_path('snmpd/ipprotocol');
	if (empty(config_get_path('snmpd/bindip'))) {
		$pconfig['bindip'] = array();
	} else {
		$pconfig['bindip'] = explode(",", config_get_path('snmpd/bindip'));
	}
	return $pconfig;
}

function snmp_ip_protocols() {
	return array("inet4" => "IPv4", "inet6" => "IPv6", "inet46" => "IPv4+IPv6");
}

/* The page's "Bind Interfaces" select (renamed from the page's build_if_list()). */
function snmp_build_if_list($selectedifs) {
	$interface_addresses = get_possible_listen_ips(true);
	$iflist = array('options' => array(), 'selected' => array());

	$iflist['options']['all']	= gettext("All");
	if (empty($selectedifs) || empty($selectedifs[0]) || in_array("all", $selectedifs)) {
		array_push($iflist['selected'], "all");
	}

	foreach ($interface_addresses as $laddr => $ldescr) {
		if (is_ipaddr(get_interface_ip($laddr))) {
			$iflist['options'][$laddr] = htmlspecialchars($ldescr);
		}

		if ($selectedifs && in_array($laddr, $selectedifs)) {
			array_push($iflist['selected'], $laddr);
		}
	}

	unset($interface_addresses);

	return($iflist);
}

/*
 * Save the settings form and reconfigure bsnmpd at once. Returns
 * input_errors and "retval" (null when nothing was saved, 0 when bsnmpd was
 * reconfigured without errors).
 */
function snmp_save($post) {
	$input_errors = array();

	/* input validation */
	if ($post['enable']) {
		if (strstr($post['syslocation'], "#")) {
			$input_errors[] = gettext("Invalid character '#' in system location");
		}
		if (strstr($post['syscontact'], "#")) {
			$input_errors[] = gettext("Invalid character '#' in system contact");
		}
		if (strstr($post['rocommunity'], "#")) {
			$input_errors[] = gettext("Invalid character '#' in read community string");
		}

		$reqdfields = explode(" ", "rocommunity");
		$reqdfieldsn = array(gettext("Community"));
		do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);

		$reqdfields = explode(" ", "pollport");
		$reqdfieldsn = array(gettext("Polling Port"));
		do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);


	}

	if ($post['trapenable']) {
		if (strstr($post['trapstring'], "#")) {
			$input_errors[] = gettext("Invalid character '#' in SNMP trap string");
		}

		$reqdfields = explode(" ", "trapserver");
		$reqdfieldsn = array(gettext("Trap server"));
		do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);

		$reqdfields = explode(" ", "trapserverport");
		$reqdfieldsn = array(gettext("Trap server port"));
		do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);

		$reqdfields = explode(" ", "trapstring");
		$reqdfieldsn = array(gettext("Trap string"));
		do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);
	}

/* disabled until some docs show up on what this does.
	if ($post['rwenable']) {
		$reqdfields = explode(" ", "rwcommunity");
		$reqdfieldsn = explode(",", "Write community string");
		do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);
	}
*/

	/*
	 * The values are written into bsnmpd.conf: quotes and line breaks
	 * would break out of their setting, ports and the trap server must be
	 * what they claim to be.
	 */
	foreach (array('syslocation' => gettext("system location"), 'syscontact' => gettext("system contact"),
	    'rocommunity' => gettext("read community string"), 'trapstring' => gettext("SNMP trap string")) as $field => $name) {
		if (preg_match('/["\\\\\r\n]/', (string)$post[$field])) {
			$input_errors[] = sprintf(gettext("Invalid character (quote, backslash or line break) in %s"), $name);
		}
	}
	if (($post['pollport'] != '') && !is_port($post['pollport'])) {
		$input_errors[] = gettext("The polling port must be a valid port number.");
	}
	if ($post['trapenable']) {
		if (($post['trapserver'] != '') && !is_ipaddr($post['trapserver']) && !is_hostname($post['trapserver'])) {
			$input_errors[] = gettext("The trap server must be an IP address or a host name.");
		}
		if (($post['trapserverport'] != '') && !is_port($post['trapserverport'])) {
			$input_errors[] = gettext("The trap server port must be a valid port number.");
		}
	}
	if (($post['ipprotocol'] != '') && !array_key_exists($post['ipprotocol'], snmp_ip_protocols())) {
		$input_errors[] = gettext("A valid Internet Protocol must be selected.");
	}
	if (is_array($post['bindip'])) {
		$bindoptions = snmp_build_if_list(array())['options'];
		foreach ($post['bindip'] as $bindip) {
			if (!array_key_exists($bindip, $bindoptions)) {
				$input_errors[] = sprintf(gettext("%s is not an interface SNMP can bind to."), $bindip);
			}
		}
	}

	$retval = null;
	if (!$input_errors) {
		config_set_path('snmpd/enable', $post['enable'] ? true : false);
		config_set_path('snmpd/pollport', $post['pollport']);
		config_set_path('snmpd/syslocation', $post['syslocation']);
		config_set_path('snmpd/syscontact', $post['syscontact']);
		config_set_path('snmpd/rocommunity', $post['rocommunity']);
		/* disabled until some docs show up on what this does.
		$config['snmpd']['rwenable'] = $post['rwenable'] ? true : false;
		$config['snmpd']['rwcommunity'] = $post['rwcommunity'];
		*/
		config_set_path('snmpd/trapenable', $post['trapenable'] ? true : false);
		config_set_path('snmpd/trapserver', $post['trapserver']);
		config_set_path('snmpd/trapserverport', $post['trapserverport']);
		config_set_path('snmpd/trapstring', $post['trapstring']);

		config_set_path('snmpd/modules/mibii', $post['mibii'] ? true : false);
		config_set_path('snmpd/modules/netgraph', $post['netgraph'] ? true : false);
		config_set_path('snmpd/modules/pf', $post['pf'] ? true : false);
		config_set_path('snmpd/modules/hostres', $post['hostres'] ? true : false);
		config_set_path('snmpd/modules/bridge', $post['bridge'] ? true : false);
		config_set_path('snmpd/modules/ucd', $post['ucd'] ? true : false);
		config_set_path('snmpd/modules/regex', $post['regex'] ? true : false);

		config_set_path('snmpd/ipprotocol', $post['ipprotocol']);
		if (is_array($post['bindip']) && !empty($post['bindip'])) {
			config_set_path('snmpd/bindip', implode(",", $post['bindip']));
		}

		write_config("SNMP settings saved");

		$retval = 0;
		$retval |= services_snmpd_configure();
	}

	return array('input_errors' => $input_errors, 'retval' => $retval);
}
