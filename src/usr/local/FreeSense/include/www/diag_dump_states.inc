<?php
/*
 * diag_dump_states.inc
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
 * Copyright (c) 2005 Colin Smith
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

require_once("interfaces.inc");
require_once("config.gui.inc");
require_once('/etc/inc/interfaces_fast.inc');

function get_ip($addr) {
	$parts = explode(":", $addr);
	if (count($parts) == 2) {
		return (trim($parts[0]));
	} else {
		/* IPv6 */
		$parts = explode("[", $addr);
		if (count($parts) == 2) {
			return (trim($parts[0]));
		}
	}

	return ("");
}

/* The interfaces the state filter offers: the assigned interfaces, IPsec, lo0 and "all". */
function diag_states_interfaces() {
	$iflist = get_configured_interface_with_descr();
	$iflist['enc0'] = "IPsec";
	$iflist['lo0'] = "lo0";
	$iflist['all'] = "all";
	return $iflist;
}

/* Input errors of a state filter request (the form fields interface, filter and ruleid). */
function diag_states_filter_errors($post) {
	$input_errors = [];

	if (!empty($post['interface']) &&
	    ($post['interface'] != 'all') &&
	    !empty($post['ruleid'])) {
		$input_errors[] = gettext("Interface and Rule ID filters cannot be used at the same time.");
	}
	return $input_errors;
}

/*
 * The network whose states "Kill States" removes for a filter: an address
 * (as a host network) or a subnet; "" when the filter is neither.
 */
function diag_states_kill_target($filter) {
	if (is_ipaddrv4($filter)) {
		$tokill = $filter . "/32";
	} elseif (is_ipaddrv6($filter)) {
		/* An IPv6 address was killed as a /32, i.e. with its whole /32 network. */
		$tokill = $filter . "/128";
	} elseif (is_subnet($filter)) {
		$tokill = $filter;
	} else {
		// Invalid filter
		$tokill = "";
	}
	return $tokill;
}

/*
 * The page's "Kill States" button: remove all states from and to the
 * filtered address or subnet. Returns the network killed, or false when the
 * filter is neither an address nor a subnet.
 */
function diag_states_kill_filter($filter) {
	$tokill = diag_states_kill_target($filter);
	if (empty($tokill)) {
		return false;
	}
	FreeSense_kill_states($tokill);
	/* The states towards an IPv6 network were looked up from 0.0.0.0/0. */
	FreeSense_kill_states(is_subnetv6($tokill) ? "::/0" : "0.0.0.0/0", $tokill);
	return $tokill;
}

/*
 * The remove button of a state: remove all states from one address to
 * another. Returns false (nothing done) unless both are addresses.
 */
function diag_states_kill_pair($srcip, $dstip) {
	if (!is_ipaddr($srcip) || !is_ipaddr($dstip)) {
		return false;
	}
	FreeSense_kill_states($srcip, $dstip);
	return true;
}

/*
 * Process web request and return an array of filtered states. With $details
 * each state also holds the unformatted fields of the state table
 * ('details': interface, addresses, direction, age, counters, rule).
 */
function process_state_req($post, $request, $json = false, $details = false) {
	$arr = array();
	/* RuleId filter. */
	if (isset($post['ruleid']) && strlen($post['ruleid']) > 0) {
		$ids = explode(",", $post['ruleid']);
		for ($i = 0; $i < count($ids); $i++) {
			$arr[] = array("ruleid" => intval($ids[$i]));
		}
	}

	/* Interface filter. */
	$interface_selected = null;
	if (isset($post['interface']) && $post['interface'] != "all") {
		$interface_selected = get_real_interface($post['interface']);
		$arr[] = array("interface" => $interface_selected);
	}

	if (isset($post['filter']) && strlen($post['filter']) > 0) {
		$arr[] = array("filter" => $post['filter']);
	}

	if (isset($post['filter']) || isset($post['ruleid']) ||
	    !config_path_enabled('system/webgui', 'requirestatefilter')) {
		if (count($arr) > 0) {
			$res = FreeSense_get_pf_states($arr);
		} else {
			$res = FreeSense_get_pf_states();
		}
	} else {
		$res = NULL;
	}

	$statedisp = array();

	if (is_array($res) && count($res)) {
		$interface_names = convert_real_interface_to_friendly_interface_name_fast();
		$interface_descriptions = get_configured_interface_with_descr(true);
		$interfaces = [];
		foreach ($interface_names as $port => $name) {
			$interfaces[$port] = $interface_descriptions[$name];
		}

		foreach ($res as $state) {
			if (isset($interface_selected) && ($state['if'] !== $interface_selected)) {
				continue;
			}
			$statedisp[] = [
				'interface' => $interfaces[$state['if']] ?? $state['if'],
				'proto' => $state['proto'],
				'display' => $state['src'] . (!empty($state['src-orig']) ? " ({$state['src-orig']})" : '') . ' -> ' . $state['dst'] . (!empty($state['dst-orig']) ? " ({$state['dst-orig']})" : ''),
				'srcip' => get_ip($state['src']),
				'dstip' => get_ip($state['dst']),
				'state' => $state['state'],
				'packets' => format_number($state['packets in']) . ' / ' . format_number($state['packets out']),
				'bytes' => format_bytes($state['bytes in']) . ' / ' . format_bytes($state['bytes out'])
			];
			if ($details) {
				$statedisp[count($statedisp) - 1]['details'] = [
					'if' => $state['if'],
					'src' => $state['src'],
					'src_orig' => $state['src-orig'] ?? null,
					'dst' => $state['dst'],
					'dst_orig' => $state['dst-orig'] ?? null,
					'direction' => $state['direction'] ?? null,
					'age' => $state['age'] ?? null,
					'expires_in' => $state['expires in'] ?? null,
					'packets_in' => (int)($state['packets in'] ?? 0),
					'packets_out' => (int)($state['packets out'] ?? 0),
					'bytes_in' => (int)($state['bytes in'] ?? 0),
					'bytes_out' => (int)($state['bytes out'] ?? 0),
					'rule' => isset($state['rule']) ? (int)$state['rule'] : null,
					'id' => $state['id'] ?? null,
					'creatorid' => $state['creatorid'] ?? null,
				];
			}
		}
	}

	return $json ? json_encode($statedisp) : $statedisp;
}
?>
