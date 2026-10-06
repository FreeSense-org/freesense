<?php
/*
 * services_upnp.inc
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
 * UPnP IGD & PCP (miniupnpd) logic shared by status_upnp.php and the REST
 * API. The settings page is the package form pkg_edit.php?xml=miniupnpd.xml;
 * upnp_save_settings() saves a form post the way pkg_edit.php does for this
 * package (required fields, validate_form_miniupnpd(), the stored field
 * shape, write_config() and sync_package_miniupnpd()), so both apply at once.
 */

require_once("config.gui.inc");
require_once("util.inc");
require_once("xmlparse.inc");
require_once("services.inc");
require_once("/usr/local/pkg/miniupnpd.inc");

define('UPNP_PKG_XML', '/usr/local/pkg/miniupnpd.xml');
define('UPNP_CONFIG_PATH', 'installedpackages/miniupnpd/config');

/* The package form definition (pkg_edit.php parses the same file). */
function upnp_pkg() {
	return parse_xml_config_pkg(UPNP_PKG_XML, "packagegui");
}

/* The stored settings (installedpackages/miniupnpd/config/0), or null when never saved. */
function upnp_config() {
	$cfg = config_get_path(UPNP_CONFIG_PATH . '/0');
	return is_array($cfg) ? $cfg : null;
}

/*
 * Validate and save a settings form post ($post: the package form fields,
 * checkboxes "on" when ticked, iface_array as a list, ACL rows as
 * permuser0, permuser1, ...), then resync miniupnpd and the filter like
 * pkg_edit.php. Returns the input errors (empty when saved).
 */
function upnp_save_settings($post) {
	global $input_errors;

	$pkg = upnp_pkg();
	$input_errors = array();
	$reqfields = array();
	$reqfieldsn = array();
	foreach ($pkg['fields']['field'] as $field) {
		if (isset($field['required'])) {
			if ($field['fieldname']) {
				$reqfields[] = $field['fieldname'];
			}
			if ($field['fielddescr']) {
				$reqfieldsn[] = $field['fielddescr'];
			}
		}
	}
	do_input_validation($post, $reqfields, $reqfieldsn, $input_errors);
	validate_form_miniupnpd($post, $input_errors);
	if ($input_errors) {
		return $input_errors;
	}

	/* store values in xml configuration file (pkg_edit.php). */
	$pkgarr = array();
	foreach ($pkg['fields']['field'] as $fields) {
		switch ($fields['type']) {
			case "rowhelper":
				$rowhelpername = "row";
				foreach ($fields['rowhelper']['rowhelperfield'] as $rowhelperfield) {
					foreach ($post as $key => $value) {
						$matches = array();
						if (preg_match("/^{$rowhelperfield['fieldname']}(\d+)$/", $key, $matches)) {
							if ($rowhelperfield['type'] == "textarea") {
								$pkgarr[$rowhelpername][$matches[1]][$rowhelperfield['fieldname']] = unixnewlines($value);
							} else {
								$pkgarr[$rowhelpername][$matches[1]][$rowhelperfield['fieldname']] = $value;
							}
						}
					}
				}
				break;
			default:
				/* Headings and help texts have no field name. */
				$fieldname = $fields['fieldname'] ?? '';
				if ($fieldname == '') {
					break;
				}
				if (is_array($post[$fieldname] ?? null)) {
					$fieldvalue = implode(',', $post[$fieldname]);
				} else {
					$fieldvalue = trim((string)($post[$fieldname] ?? ''));
					if (($fields['encoding'] ?? '') == 'base64') {
						$fieldvalue = base64_encode($fieldvalue);
					}
				}
				$pkgarr[$fieldname] = $fieldvalue;
		}
	}

	/* pkg_edit.php drops an empty first entry and then saves entry 0. */
	$pkg_config = config_get_path(UPNP_CONFIG_PATH, []);
	if ((count($pkg_config) > 0) && (empty($pkg_config[0]))) {
		array_shift($pkg_config);
		config_set_path(UPNP_CONFIG_PATH, $pkg_config);
	}
	config_set_path(UPNP_CONFIG_PATH . '/0', $pkgarr);

	write_config($pkg['addedit_string']);
	sync_package_miniupnpd();
	return array();
}

/*
 * The active port maps (status_upnp.php): one array per pf rdr rule in the
 * miniupnpd anchor with iface (real interface), extport, intaddr, intport,
 * proto, srcaddr, srcport and descr.
 */
function upnp_port_maps() {
	$rdr_entries = array();
	exec("/sbin/pfctl -a miniupnpd -s nat -P", $rdr_entries, $pf_ret);

	$maps = array();
	foreach ($rdr_entries as $rdr_entry) {
		/* rdr log quick on igb2 inet proto tcp from any to any port = xxxxx keep state label "xxxxx" rtable 0 -> xxx.xxx.xxx.xxx port xxxxx */
		/* rdr log quick on igb2 inet proto udp from any to xxx.xxx.xxx.xxx port = xxxxxx keep state label "xxxxx" rtable 0 -> xxx.xxx.xxx.xxx port xxxxx */
		if (preg_match("/on (?P<iface>.*) inet proto (?P<proto>.*) from (?P<srcaddr>.*) (port (?P<srcport>.*) )?to (?P<extaddr>.*) port = (?P<extport>.*) keep state (label \"(?P<descr>.*)\" )?rtable [0-9] -> (?P<intaddr>.*) port (?P<intport>.*)/", $rdr_entry, $matches)) {
			$maps[] = array(
				'iface' => $matches['iface'],
				'extport' => $matches['extport'],
				'intaddr' => $matches['intaddr'],
				'intport' => $matches['intport'],
				'proto' => $matches['proto'],
				'srcaddr' => $matches['srcaddr'],
				'srcport' => $matches['srcport'],
				'descr' => strval(preg_replace('/^' . RULE_LABEL_KEY_DESCRIPTION . RULE_LABEL_DELIMITER . '/', '', $matches['descr'], 1)),
			);
		}
	}
	return $maps;
}
