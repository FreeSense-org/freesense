<?php
/*
 * services_igmpproxy.inc
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
 * IGMP proxy logic shared by services_igmpproxy.php,
 * services_igmpproxy_edit.php and the REST API, moved out of the pages with
 * the form fields as parameters. Every change is staged (the "igmpproxy"
 * dirty flag) until igmpproxy_apply() runs (the page's "Apply Changes").
 */

require_once("config.gui.inc");
require_once("interfaces.inc");
require_once("services.inc");
require_once("util.inc");

/* Apply the staged changes. Returns 0 on success, 1 otherwise. */
function igmpproxy_apply() {
	/* reload all components that use igmpproxy */
	if (services_igmpproxy_configure()) {
		$retval = 0;
	} else {
		$retval = 1;
	}

	clear_subsystem_dirty('igmpproxy');
	return $retval;
}

/* The general settings form's fields: enable, igmpxverbose. */
function igmpproxy_settings() {
	$pconfig = array();
	if (config_path_enabled('igmpproxy')) {
		$pconfig['enable'] = true;
	}
	$pconfig['igmpxverbose'] = config_path_enabled('syslog', 'igmpxverbose');
	return $pconfig;
}

/*
 * Save the general settings ($post: enable, igmpxverbose). Enabling needs an
 * upstream and a downstream entry. Returns the input errors.
 */
function igmpproxy_save_settings($post) {
	$input_errors = array();

	if (isset($post['enable'])) {
		$upstream = $downstream = false;
		foreach (config_get_path('igmpproxy/igmpentry', []) as $igmpcf) {
			if ($igmpcf['type'] == 'upstream') {
				$upstream = true;
			} else {
				$downstream = true;
			}
		}
		if (!$upstream || !$downstream) {
			$input_errors[] = gettext("At least one upstream and one downstream interface must be added.");
		}
	}

	if (!$input_errors) {
		if (isset($post['enable'])) {
			config_set_path('igmpproxy/enable', true);
		} else {
			config_del_path('igmpproxy/enable');
		}
		config_set_path('syslog/igmpxverbose', $post['igmpxverbose'] ? true : false);
		write_config("IGMP Proxy settings saved");
		mark_subsystem_dirty('igmpproxy');
	}

	return $input_errors;
}

/* Delete the entry at position $id. Returns true when it existed. */
function igmpproxy_delete_entry($id) {
	if (is_numericint($id) && config_get_path("igmpproxy/igmpentry/{$id}")) {
		config_del_path("igmpproxy/igmpentry/{$id}");
		write_config("IGMP Proxy item deleted");
		mark_subsystem_dirty('igmpproxy');
		return true;
	}
	return false;
}

/* The interfaces the edit page offers: configured interfaces with an address. */
function igmpproxy_interface_list() {
	$optionlist = array();
	$iflist = get_configured_interface_with_descr();

	$if_config = config_get_path('interfaces', []);
	foreach ($iflist as $ifnam => $ifdescr) {
		if (!empty($if_config[$ifnam]['ipaddr'])) {
			$optionlist[$ifnam] = $ifdescr;
		}
	}
	return $optionlist;
}

function igmpproxy_types() {
	return array('upstream' => gettext('Upstream Interface'), 'downstream' => gettext('Downstream Interface'));
}

/*
 * Save the edit form ($post: ifname, type, threshold, descr and the network
 * rows address0/address_subnet0, ...) as the entry at position $id, or as a
 * new entry when $id is not set or does not exist. Returns input_errors
 * (empty when saved) and "address", the valid networks to show again.
 */
function igmpproxy_save_entry($post, $id) {
	$input_errors = array();
	$this_igmpproxy_config = isset($id) ? config_get_path("igmpproxy/igmpentry/{$id}") : null;

	if (!array_key_exists($post['ifname'] ?? '', igmpproxy_interface_list())) {
		$input_errors[] = gettext("A valid interface must be selected.");
	}

	if (!array_key_exists($post['type'] ?? '', igmpproxy_types())) {
		$input_errors[] = gettext("A valid type must be selected.");
	}

	if ($post['type'] == "upstream") {
		foreach (config_get_path('igmpproxy/igmpentry', []) as $pid => $proxyentry) {
			if (isset($id) && $id == $pid) {
				continue;
			}

			/* Only one upstream entry, on any interface. */
			if ($proxyentry['type'] == "upstream") {
				$input_errors[] = gettext("Only one 'upstream' interface can be configured.");
			}
		}
	}

	if (!empty($post['threshold']) && (!is_numeric($post['threshold']) ||
	    ($post['threshold'] < -1) || ($post['threshold'] > 256))) {
		$input_errors[] = gettext("Threshold value should be between -1 and 256.");
	}

	$igmpentry = array();
	$igmpentry['ifname'] = $post['ifname'];
	$igmpentry['threshold'] = $post['threshold'];
	$igmpentry['type'] = $post['type'];
	$address = "";
	$isfirst = 0;

	/* item is a normal igmpentry type */
	$x = 0;
	while ($post["address{$x}"]) {

		if ($isfirst > 0) {
			$address .= " ";
		}

		$this_addr =  $post["address{$x}"] . "/" . $post["address_subnet{$x}"];
		if (is_subnetv4($this_addr)) {
			$address .= $this_addr;
			$isfirst++;
		} else {
			$input_errors[] = sprintf(gettext("The following submitted address is invalid: %s"), $this_addr);
		}

		$x++;
	}

	if (!$input_errors) {
		$igmpentry['address'] = $address;
		$igmpentry['descr'] = $post['descr'];

		if ($this_igmpproxy_config) {
			config_set_path("igmpproxy/igmpentry/{$id}", $igmpentry);
		} else {
			config_set_path('igmpproxy/igmpentry/', $igmpentry);
		}

		write_config("IGMP Proxy item saved");

		mark_subsystem_dirty('igmpproxy');
	}

	return array('input_errors' => $input_errors, 'address' => $address);
}
