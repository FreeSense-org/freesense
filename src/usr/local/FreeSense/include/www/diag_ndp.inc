<?php
/*
 * diag_ndp.inc
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
 * Copyright (c) 2011 Seth Mos <seth.mos@dds.nl>
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

require_once("util.inc");

/*
 * The NDP table as Diagnostics > NDP Table shows it: one entry per neighbor
 * (ipv6, mac, interface, expiration) with the reverse DNS name in
 * 'dnsresolve' ("Z_ " when there is none), sorted by name.
 */
function diag_ndp_table() {
	$rawdata = array();
	exec("/usr/sbin/ndp -na", $rawdata);

	/*
	 * Key map for each element in $rawdata
	 * 0 => Neighbor IP
	 * 1 => Physical address (MAC)
	 * 2 => Interface
	 * 3 => Expiration
	 * 4 => State
	 * 5 => Flags
	 */
	$data = array();
	array_shift($rawdata);
	foreach ($rawdata as $line) {
		$elements = preg_split('/[ ]+/', $line);

		$ndpent = array();
		$ndpent['ipv6'] = trim($elements[0]);
		$ndpent['mac'] = trim($elements[1]);
		$ndpent['interface'] = trim($elements[2]);
		$ndpent['expiration'] = trim($elements[3]);
		$data[] = $ndpent;
	}

	// Resolve hostnames and replace Z_ with "".  The intention
	// is to sort the list by hostnames, alpha and then the non
	// resolvable addresses will appear last in the list.
	$dnsavailable = get_dnsavailable(AF_INET6);
	foreach ($data as &$entry) {
		$dns="";
		if (!empty($entry['ipv6']) && $dnsavailable) {
			$dns = resolve_address($entry['ipv6']);
		}

		if (trim($dns)) {
			$entry['dnsresolve'] = "$dns";
		} else {
			$entry['dnsresolve'] = "Z_ ";
		}
	}
	unset($entry);

	// Sort the data alpha first
	$data = msort($data, "dnsresolve");

	return $data;
}
