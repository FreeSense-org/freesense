<?php
/*
 * services_ntpd.inc
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

/*
 * NTP server logic shared by services_ntpd.php, services_ntpd_acls.php and
 * the REST API, moved out of the pages with the form fields as parameters.
 * Like the pages, a save reconfigures ntpd at once; there is nothing to
 * apply. (The Serial GPS and PPS tabs keep their logic in their pages.)
 */

require_once("config.gui.inc");
require_once("interfaces.inc");
require_once("rrd.inc");
require_once("system.inc");
require_once("util.inc");

if (!defined('NUMTIMESERVERS')) {
	define('NUMTIMESERVERS', 10);		// The maximum number of configurable time servers
}
if (!defined('NUMACLS')) {
	define('NUMACLS', 50); // The maximum number of configurable ACLs
}

/* The page's limits of the "Max candidate pool peers" field. */
function ntpd_candidate_peer_limits() {
	return array('min' => 4, 'max' => 25);
}

function ntpd_auto_pool_suffix() {
	return "pool.ntp.org";
}

function ntpd_dnsresolv_choices() {
	return array(
		'auto' => 'Auto',
		'inet' => 'IPv4',
		'inet6' => 'IPv6',
	);
}

/*
 * Settings page load: move the interfaces of the old OpenNTPD package
 * (writes the configuration once when there are any).
 */
function ntpd_migrate_openntpd() {
	if (empty(config_get_path('ntpd/interface'))) {
		$old_ifs = config_get_path('installedpackages/openntpd/config/0/interface');
		if (!empty($old_ifs)) {
			config_set_path('ntpd/interface', $old_ifs);
			config_del_path('installedpackages/openntpd');
			write_config(gettext("Upgraded settings from openntpd"));
		}
	}
}

/* The settings form's fields as the page preselects them (time servers are read from system/timeservers). */
function ntpd_settings() {
	$pconfig = config_get_path('ntpd', []);
	$pconfig['enable'] = ($pconfig['enable'] != 'disabled') ? 'enabled' : 'disabled';
	if (empty($pconfig['interface'])) {
		$pconfig['interface'] = array();
	} else {
		$pconfig['interface'] = explode(",", $pconfig['interface']);
	}
	return $pconfig;
}

/*
 * The time server rows as the page shows them: server, prefer, noselect,
 * auth and type (server, pool or peer).
 */
function ntpd_timeserver_rows() {
	$auto_pool_suffix = ntpd_auto_pool_suffix();
	$rows = array();
	$timeservers = explode(' ', config_get_path('system/timeservers'));
	$maxrows = max(count($timeservers), 1);
	for ($counter=0; $counter < $maxrows; $counter++) {
		if ((substr_compare($timeservers[$counter], $auto_pool_suffix, strlen($timeservers[$counter]) - strlen($auto_pool_suffix), strlen($auto_pool_suffix)) === 0) ||
		    ((config_get_path('ntpd/ispool') !== null) && isset($timeservers[$counter]) &&
		    substr_count(config_get_path('ntpd/ispool'), $timeservers[$counter]))) {
			$servertype = 'pool';
		} elseif ((config_get_path('ntpd/ispeer') !== null) && isset($timeservers[$counter]) && substr_count(config_get_path('ntpd/ispeer'), $timeservers[$counter])) {
			$servertype = 'peer';
		} else {
			$servertype = 'server';
		}
		$rows[] = array(
			'server' => $timeservers[$counter],
			'prefer' => config_path_enabled('ntpd', 'prefer') && isset($timeservers[$counter]) && substr_count(config_get_path('ntpd/prefer'), $timeservers[$counter]),
			'noselect' => config_path_enabled('ntpd', 'noselect') && isset($timeservers[$counter]) && substr_count(config_get_path('ntpd/noselect'), $timeservers[$counter]),
			'auth' => config_path_enabled('ntpd', 'isauth') && isset($timeservers[$counter]) && substr_count(config_get_path('ntpd/isauth', ''), $timeservers[$counter]),
			'type' => $servertype,
		);
	}
	return $rows;
}

/* The page's "Interface" select (renamed from the page's build_interface_list()). */
function ntpd_build_interface_list($selected) {
	$iflist = array('options' => array(), 'selected' => array());

	$interfaces = get_configured_interface_with_descr();
	$interfaces['lo0'] = "Localhost";

	foreach ($interfaces as $iface => $ifacename) {
		if (!is_ipaddr(get_interface_ip($iface)) &&
		    !is_ipaddrv6(get_interface_ipv6($iface))) {
			continue;
		}

		$iflist['options'][$iface] = $ifacename;

		if (in_array($iface, $selected)) {
			array_push($iflist['selected'], $iface);
		}
	}

	return($iflist);
}

/*
 * Save the settings form and reconfigure ntpd at once. $leapfile is the
 * content of an uploaded leap seconds file (it replaces the text field), or
 * null. Returns input_errors, changes_applied and retval.
 */
function ntpd_save_settings($post, $leapfile = null) {
	global $ntp_auth_halgos;

	$ntp_poll_values = system_ntp_poll_values();
	$auto_pool_suffix = ntpd_auto_pool_suffix();
	$min_candidate_peers = ntpd_candidate_peer_limits()['min'];
	$max_candidate_peers = ntpd_candidate_peer_limits()['max'];
	$input_errors = array();
	$pconfig = $post;

	if (!empty($post['ntpmaxpeers']) && (!is_numericint($post['ntpmaxpeers']) ||
	    ($post['ntpmaxpeers'] < $min_candidate_peers) || ($post['ntpmaxpeers'] > $max_candidate_peers))) {
		$input_errors[] = sprintf(gettext("Max candidate pool peers must be a number between %d and %d"), $min_candidate_peers, $max_candidate_peers);
	}

	if ((strlen($pconfig['ntporphan']) > 0) && (!is_numericint($pconfig['ntporphan']) || ($pconfig['ntporphan'] < 1) || ($pconfig['ntporphan'] > 15))) {
		$input_errors[] = gettext("The supplied value for NTP Orphan Mode is invalid.");
	}

	if (!array_key_exists($pconfig['ntpminpoll'], $ntp_poll_values)) {
		$input_errors[] = gettext("The supplied value for Minimum Poll Interval is invalid.");
	}

	if (!array_key_exists($pconfig['ntpmaxpoll'], $ntp_poll_values)) {
		$input_errors[] = gettext("The supplied value for Maximum Poll Interval is invalid.");
	}

	for ($i = 0; $i < NUMTIMESERVERS; $i++) {
		if (isset($pconfig["servselect{$i}"]) && (($pconfig["servistype{$i}"] == 'pool') ||
		    (substr_compare($pconfig["server{$i}"], $auto_pool_suffix, strlen($pconfig["server{$i}"]) - strlen($auto_pool_suffix), strlen($auto_pool_suffix)) === 0))) {
			$input_errors[] = gettext("It is not possible to use 'No Select' for pools.");
		}
		if (!empty($pconfig["server{$i}"]) && !is_domain($pconfig["server{$i}"]) &&
		    !is_ipaddr($pconfig["server{$i}"])) {
			$input_errors[] = gettext("NTP Time Server names must be valid domain names, IPv4 addresses, or IPv6 addresses");
		}
		if (isset($pconfig["servauth{$i}"]) && (($pconfig["servistype{$i}"] == 'pool') ||
		    (substr_compare($pconfig["server{$i}"], $auto_pool_suffix, strlen($pconfig["server{$i}"]) - strlen($auto_pool_suffix), strlen($auto_pool_suffix)) === 0))) {
			$input_errors[] = gettext("It is not possible to use 'Authenticated' for pools.");
		}
		if (isset($pconfig["servauth{$i}"]) && empty($pconfig['serverauth'])) {
			$input_errors[] = gettext("The NTP authentication key information must be set to use 'Authenticated' for a server or peer.");
		}
	}

	if (is_numericint($pconfig['ntpminpoll']) &&
	    is_numericint($pconfig['ntpmaxpoll']) &&
	    ($pconfig['ntpmaxpoll'] < $pconfig['ntpminpoll'])) {
		$input_errors[] = gettext("The supplied value for Minimum Poll Interval is higher than NTP Maximum Poll Interval.");
	}

	if (isset($pconfig['serverauth'])) {
		if (empty($pconfig['serverauthkey'])) {
			$input_errors[] = gettext("The supplied value for NTP Authentication key can't be empty.");
		} elseif (empty($pconfig['serverauthkeyid'])) {
			$input_errors[] = gettext("The authentication Key ID can't be empty.");
		} elseif (!ctype_digit($pconfig['serverauthkeyid'])) {
			$input_errors[] = gettext("The authentication Key ID must be a positive integer.");
		} elseif ($pconfig['serverauthkeyid'] < 1 || $pconfig['serverauthkeyid'] > 65535) {
			$input_errors[] = gettext("The authentication Key ID must be between 1-65535.");
		} elseif (!array_key_exists($pconfig['serverauthalgo'], $ntp_auth_halgos)) {
			/* The algorithm is written into ntp.keys; the key checks below depend on it. */
			$input_errors[] = gettext("The supplied value for the NTP authentication digest algorithm is invalid.");
		} elseif (($pconfig['serverauthalgo'] == 'md5') && ((strlen($pconfig['serverauthkey']) > 20) ||
		    !ctype_print($pconfig['serverauthkey']))) {
			$input_errors[] = gettext("The supplied value for NTP Authentication key for MD5 digest must be from 1 to 20 printable characters.");
		} elseif (($pconfig['serverauthalgo'] == 'sha1') && ((strlen($pconfig['serverauthkey']) != 40) ||
		    !ctype_xdigit($pconfig['serverauthkey']))) {
			$input_errors[] = gettext("The supplied value for NTP Authentication key for SHA1 digest must be hex-encoded string of 40 characters.");
		} elseif (($pconfig['serverauthalgo'] == 'sha256') && ((strlen($pconfig['serverauthkey']) != 64) ||
		    !ctype_xdigit($pconfig['serverauthkey']))) {
			$input_errors[] = gettext("The supplied value for NTP Authentication key for SHA256 digest must be hex-encoded string of 64 characters.");
		}
	}

	/* The interfaces are written into ntpd.conf: only those the page offers. */
	if (is_array($post['interface'])) {
		$ifoptions = ntpd_build_interface_list(array())['options'];
		foreach ($post['interface'] as $if) {
			if (!array_key_exists($if, $ifoptions)) {
				$input_errors[] = sprintf(gettext("%s is not an interface NTP can listen on."), $if);
			}
		}
	}
	if (!empty($post['dnsresolv']) && !array_key_exists($post['dnsresolv'], ntpd_dnsresolv_choices())) {
		$input_errors[] = gettext("The supplied value for DNS Resolution is invalid.");
	}

	$changes_applied = false;
	$retval = null;
	if (!$input_errors) {
		config_set_path('ntpd/enable', isset($post['enable']) ? 'enabled' : 'disabled');
		if (is_array($post['interface'])) {
			config_set_path('ntpd/interface', implode(",", $post['interface']));
		} else {
			config_del_path('ntpd/interface');
		}

		config_del_path('ntpd/prefer');
		config_del_path('ntpd/noselect');
		config_del_path('ntpd/ispool');
		config_del_path('ntpd/ispeer');
		config_del_path('ntpd/isauth');
		$timeservers = '';

		for ($i = 0; $i < NUMTIMESERVERS; $i++) {
			$tserver = trim($post["server{$i}"]);
			if (!empty($tserver)) {
				$timeservers .= "{$tserver} ";
				if (isset($post["servprefer{$i}"])) {
					config_set_path('ntpd/prefer', (config_get_path('ntpd/prefer') . "{$tserver} "));
				}
				if (isset($post["servselect{$i}"])) {
					config_set_path('ntpd/noselect', (config_get_path('ntpd/noselect') . "{$tserver} "));
				}
				if (isset($post["servauth{$i}"])) {
					config_set_path('ntpd/isauth', (config_get_path('ntpd/isauth') . "{$tserver} "));
				}
				if ($post["servistype{$i}"] == 'pool') {
					config_set_path('ntpd/ispool', (config_get_path('ntpd/ispool') . "{$tserver} "));
				} elseif ($post["servistype{$i}"] == 'peer') {
					config_set_path('ntpd/ispeer', (config_get_path('ntpd/ispeer') . "{$tserver} "));
				}
			}
		}
		if (trim($timeservers) == "") {
			$timeservers = "pool.ntp.org";
		}
		config_set_path('system/timeservers', trim($timeservers));

		if (!empty($pconfig['ntpmaxpeers'])) {
			config_set_path('ntpd/ntpmaxpeers', $pconfig['ntpmaxpeers']);
		} else {
			config_del_path('ntpd/ntpmaxpeers');
		}
		config_set_path('ntpd/orphan', trim($pconfig['ntporphan']));
		config_set_path('ntpd/ntpminpoll', $pconfig['ntpminpoll']);
		config_set_path('ntpd/ntpmaxpoll', $pconfig['ntpmaxpoll']);
		config_set_path('ntpd/dnsresolv', $pconfig['dnsresolv']);

		if (!empty($post['logpeer'])) {
			config_set_path('ntpd/logpeer', $post['logpeer']);
		} elseif (config_path_enabled('ntpd', 'logpeer')) {
			config_del_path('ntpd/logpeer');
		}

		if (!empty($post['logsys'])) {
			config_set_path('ntpd/logsys', $post['logsys']);
		} elseif (config_path_enabled('ntpd', 'logsys')) {
			config_del_path('ntpd/logsys');
		}

		if (!empty($post['clockstats'])) {
			config_set_path('ntpd/clockstats', $post['clockstats']);
		} elseif (config_path_enabled('ntpd', 'clockstats')) {
			config_del_path('ntpd/clockstats');
		}

		if (!empty($post['loopstats'])) {
			config_set_path('ntpd/loopstats', $post['loopstats']);
		} elseif (config_path_enabled('ntpd', 'loopstats')) {
			config_del_path('ntpd/loopstats');
		}

		if (!empty($post['peerstats'])) {
			config_set_path('ntpd/peerstats', $post['peerstats']);
		} elseif (config_path_enabled('ntpd', 'peerstats')) {
			config_del_path('ntpd/peerstats');
		}

		if ((empty($post['statsgraph'])) == (config_path_enabled('ntpd', 'statsgraph'))) {
			$enable_rrd_graphing = true;
		}
		if (!empty($post['statsgraph'])) {
			config_set_path('ntpd/statsgraph', $post['statsgraph']);
		} elseif (config_path_enabled('ntpd', 'statsgraph')) {
			config_del_path('ntpd/statsgraph');
		}
		if (isset($enable_rrd_graphing)) {
			enable_rrd_graphing();
		}

		if (!empty($post['leaptext'])) {
			config_set_path('ntpd/leapsec', base64_encode($post['leaptext']));
		} elseif (config_path_enabled('ntpd', 'leapsec')) {
			config_del_path('ntpd/leapsec');
		}

		if ($leapfile !== null) {
			config_set_path('ntpd/leapsec', base64_encode($leapfile));
		}

		if (!empty($post['serverauth'])) {
			config_set_path('ntpd/serverauth', $post['serverauth']);
			config_set_path('ntpd/serverauthkey', base64_encode(trim($post['serverauthkey'])));
			config_set_path('ntpd/serverauthkeyid', $post['serverauthkeyid']);
			config_set_path('ntpd/serverauthalgo', $post['serverauthalgo']);
		} elseif (config_path_enabled('ntpd', 'serverauth')) {
			config_del_path('ntpd/serverauth');
			config_del_path('ntpd/serverauthkey');
			config_del_path('ntpd/serverauthkeyid');
			config_del_path('ntpd/serverauthalgo');
		}

		write_config("Updated NTP Server Settings");

		$changes_applied = true;
		$retval = 0;
		$retval |= system_ntp_configure();
	}

	return array('input_errors' => $input_errors, 'changes_applied' => $changes_applied, 'retval' => $retval);
}

/* The stored custom access restriction rows, or the page's one empty row. */
function ntpd_acl_rows() {
	if (is_array(config_get_path('ntpd/restrictions/row'))) {
		return config_get_path('ntpd/restrictions/row');
	}
	return array('0' => array('acl_network' => '', 'mask' => ''));
}

/* The flags of a custom access restriction row ("yes" when set). */
function ntpd_acl_flags() {
	return array('kod', 'nomodify', 'noquery', 'noserve', 'nopeer', 'notrap');
}

/*
 * Save the ACL form and reconfigure ntpd at once. Returns input_errors,
 * changes_applied, retval and the posted rows ("networkacl", which the page
 * shows again after an error).
 */
function ntpd_save_acls($post) {
	$networkacl = ntpd_acl_rows();
	$input_errors = array();
	$pconfig = $post;

	for ($x=0, $numacls=0; $x < NUMACLS; $x++) {
		if (array_key_exists("acl_network{$x}", $post)) {
			$numacls++;
		}
	}

	for ($x = 0; $x < NUMACLS; $x++) {
		if (isset($pconfig["acl_network{$x}"])) {
			$networkacl[$x] = array();
			$networkacl[$x]['acl_network'] = $pconfig["acl_network{$x}"];
			$networkacl[$x]['mask'] = $pconfig["mask{$x}"];

			/* ACL Flags */
			if (array_key_exists("kod{$x}", $pconfig)) {
				$networkacl[$x]['kod'] = "yes";
			} elseif (isset($networkacl[$x]['kod'])) {
				unset($networkacl[$x]['kod']);
			}

			if (array_key_exists("nomodify{$x}", $pconfig)) {
				$networkacl[$x]['nomodify'] = "yes";
			} elseif (isset($networkacl[$x]['nomodify'])) {
				unset($networkacl[$x]['nomodify']);
			}

			if (array_key_exists("noquery{$x}", $pconfig)) {
				$networkacl[$x]['noquery'] = "yes";
			} elseif (isset($networkacl[$x]['noquery'])) {
				unset($networkacl[$x]['noquery']);
			}

			if (array_key_exists("noserve{$x}", $pconfig)) {
				$networkacl[$x]['noserve'] = "yes";
			} elseif (isset($networkacl[$x]['noserve'])) {
				unset($networkacl[$x]['noserve']);
			}

			if (array_key_exists("nopeer{$x}", $pconfig)) {
				$networkacl[$x]['nopeer'] = "yes";
			} elseif (isset($networkacl[$x]['nopeer'])) {
				unset($networkacl[$x]['nopeer']);
			}

			if (array_key_exists("notrap{$x}", $pconfig)) {
				$networkacl[$x]['notrap'] = "yes";
			} elseif (isset($networkacl[$x]['notrap'])) {
				unset($networkacl[$x]['notrap']);
			}
			/* End ACL Flags */

			/*
			 * A row with a network is written into ntpd.conf with or
			 * without flags (a row without flags allows everything),
			 * so its network and mask must be valid either way.
			 */
			if (isset($networkacl[$x]['notrap']) || isset($networkacl[$x]['kod']) || isset($networkacl[$x]['nomodify'])
			   || isset($networkacl[$x]['noquery']) || isset($networkacl[$x]['nopeer']) || isset($networkacl[$x]['noserve'])
			   || (strlen($networkacl[$x]['acl_network']) > 0)) {
				if (!is_ipaddr($networkacl[$x]['acl_network'])) {
					$input_errors[] = sprintf(gettext("A valid IP address must be entered for row %s under Networks."), $networkacl[$x]['acl_network']);
				} else {
					if (is_ipaddrv4($networkacl[$x]['acl_network'])) {
						if (!is_subnetv4($networkacl[$x]['acl_network']."/".$networkacl[$x]['mask'])) {
							$input_errors[] = sprintf(gettext("A valid IPv4 netmask must be entered for IPv4 row %s under Networks."), $networkacl[$x]['acl_network']);
						}
					} else if (!is_subnetv6($networkacl[$x]['acl_network']."/".$networkacl[$x]['mask'])) {
						$input_errors[] = sprintf(gettext("A valid IPv6 netmask must be entered for IPv6 row %s under Networks."), $networkacl[$x]['acl_network']);
					}
				}
			} else if ((strlen($networkacl[$x]['acl_network']) == 0) && ($numacls > 1)) {
				unset($networkacl[$x]);
			}
		} else if (isset($networkacl[$x])) {
			unset($networkacl[$x]);
		}
	}

	$changes_applied = false;
	$retval = null;
	if (!$input_errors) {
		/* Default Access Restrictions */
		if (empty($post['kod'])) {
			config_set_path('ntpd/kod', 'on');
		} elseif (config_path_enabled('ntpd', 'kod')) {
			config_del_path('ntpd/kod');
		}

		if (empty($post['nomodify'])) {
			config_set_path('ntpd/nomodify', 'on');
		} elseif (config_path_enabled('ntpd', 'nomodify')) {
			config_del_path('ntpd/nomodify');
		}

		if (!empty($post['noquery'])) {
			config_set_path('ntpd/noquery', $post['noquery']);
		} elseif (config_path_enabled('ntpd', 'noquery')) {
			config_del_path('ntpd/noquery');
		}

		if (!empty($post['noserve'])) {
			config_set_path('ntpd/noserve', $post['noserve']);
		} elseif (config_path_enabled('ntpd', 'noserve')) {
			config_del_path('ntpd/noserve');
		}

		if (empty($post['nopeer'])) {
			config_set_path('ntpd/nopeer', 'on');
		} elseif (config_path_enabled('ntpd', 'nopeer')) {
			config_del_path('ntpd/nopeer');
		}

		if (empty($post['notrap'])) {
			config_set_path('ntpd/notrap', 'on');
		} elseif (config_path_enabled('ntpd', 'notrap')) {
			config_del_path('ntpd/notrap');
		}
		/* End Default Access Restrictions */
		config_set_path('ntpd/restrictions/row', array());
		foreach ($networkacl as $acl) {
			config_set_path('ntpd/restrictions/row/', $acl);
		}

		write_config("Updated NTP ACL Settings");

		$changes_applied = true;
		$retval = 0;
		$retval |= system_ntp_configure();
	}

	return array('input_errors' => $input_errors, 'changes_applied' => $changes_applied, 'retval' => $retval,
	    'networkacl' => $networkacl);
}
