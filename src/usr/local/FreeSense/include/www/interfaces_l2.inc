<?php
/*
 * interfaces_l2.inc
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
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

/*
 * LAGG, QinQ, interface group and bridge logic shared by the Interfaces
 * pages and the REST API. Moved out of
 * interfaces_{lagg,qinq,groups,bridge}(_edit).php with the form fields and
 * ids as parameters instead of the request arrays. The functions never
 * redirect; the pages do that.
 *
 * Like the pages, a save configures the live interface first and writes the
 * configuration afterwards; nothing is configured while there are
 * validation errors. Changes take effect at once (no apply step).
 */

require_once("config.gui.inc");
require_once("functions.inc");
require_once("interfaces.inc");
require_once("filter.inc");
/* interfaces_gui_read_only(), interfaces_vlan_parent_list(), interfaces_vlan_tag_types() */
require_once("interfaces_tunnels.inc");

/* ------------------------------------------------------------------ */
/* LAGG                                                                */
/* ------------------------------------------------------------------ */

function interfaces_lagg_protos() {
	return array("none", "lacp", "failover", "loadbalance", "roundrobin");
}

/*
 * The ports the LAGG edit page offers as members: active ports that are
 * not a LAGG, not assigned and not a member of another LAGG. The members
 * of the LAGG at position $id stay available to it.
 */
function interfaces_lagg_port_list($id = null) {
	$portlist = get_interface_list();

	$realifchecklist = array();
	/* add LAGG interfaces */
	foreach (config_get_path('laggs/lagg', []) as $lagg) {
		unset($portlist[$lagg['laggif']]);
		$laggiflist = array_filter(explode(",", $lagg['members']));
		foreach ($laggiflist as $tmpif) {
			$realifchecklist[get_real_interface($tmpif)] = $tmpif;
		}
	}

	$checklist = get_configured_interface_list(true);

	foreach ($checklist as $tmpif) {
		$realifchecklist[get_real_interface($tmpif)] = $tmpif;
	}

	$this_lagg_config = isset($id) ? config_get_path("laggs/lagg/{$id}") : null;
	if ($this_lagg_config) {
		$laggiflist = array_filter(explode(",", $this_lagg_config['members']));
		foreach ($laggiflist as $tmpif) {
			unset($realifchecklist[get_real_interface($tmpif)]);
		}
	}

	$list = array();
	foreach ($portlist as $ifn => $ifinfo) {
		if (array_key_exists($ifn, $realifchecklist)) {
			continue;
		}
		$list[$ifn] = $ifinfo;
	}
	return $list;
}

/*
 * Returns why the LAGG at position $num cannot be deleted, or false when
 * it is unused. Besides an assignment and VLANs this also covers QinQs on
 * the LAGG and bridges it is a member of (which were not checked before).
 */
function lagg_inuse($num) {
	$laggif = config_get_path("laggs/lagg/{$num}/laggif");
	if (empty($laggif)) {
		return false;
	}

	$if_config = config_get_path('interfaces', []);
	$iflist = get_configured_interface_list(true);
	foreach ($iflist as $if) {
		if ($if_config[$if]['if'] == $laggif) {
			if (!empty(link_interface_to_bridge($if))) {
				return gettext("This LAGG interface cannot be deleted because it is a bridge member.");
			}
			return gettext("This LAGG interface cannot be deleted because it is still being used.");
		}
	}

	foreach (config_get_path('vlans/vlan', []) as $vlan) {
		if ($vlan['if'] == $laggif) {
			return gettext("This LAGG interface cannot be deleted because it is still being used.");
		}
	}

	foreach (config_get_path('qinqs/qinqentry', []) as $qinq) {
		if ($qinq['if'] == $laggif) {
			return gettext("This LAGG interface cannot be deleted because it is the parent of a QinQ.");
		}
	}
	return false;
}

/*
 * Save the LAGG edit form. $id is the position of the LAGG being edited,
 * or null for a new one. Returns the input errors; empty on success.
 */
function interfaces_lagg_save(array $post, $id) {
	global $lagg_hash_list;

	$input_errors = array();
	$this_lagg_config = isset($id) ? config_get_path("laggs/lagg/{$id}") : null;
	$laggprotos = interfaces_lagg_protos();

	/* input validation */
	$reqdfields = explode(" ", "members proto");
	$reqdfieldsn = array(gettext("Member interfaces"), gettext("Lagg protocol"));

	do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);

	/* only the ports the edit page offers: never an assigned interface or another LAGG's member */
	$ports = interfaces_lagg_port_list($id);
	if (is_array($post['members'] ?? null)) {
		foreach ($post['members'] as $member) {
			if (!does_interface_exist($member) || !array_key_exists($member, $ports)) {
				$input_errors[] = sprintf(gettext("Interface supplied as member (%s) is invalid"), $member);
			}
		}
	} else if (!does_interface_exist($post['members']) || !array_key_exists($post['members'], $ports)) {
		$input_errors[] = gettext("Interface supplied as member is invalid");
	}

	if (!in_array($post['proto'], $laggprotos)) {
		$input_errors[] = gettext("Protocol supplied is invalid");
	}

	if (is_array($post['members'] ?? null) && ($post['proto'] == 'failover') && isset($post['failovermaster']) &&
	    ($post['failovermaster'] != 'auto') && (array_search($post['failovermaster'], $post['members']) === false)) {
			$input_errors[] = sprintf(gettext("Failover Master Interface must be selected as member."));
	}

	if ((($post['proto'] == 'lacp') || ($post['proto'] == 'loadbalance')) &&
	    isset($post['lagghash']) && !array_key_exists($post['lagghash'], $lagg_hash_list)) {
		$input_errors[] = gettext("Hash Algorithm is invalid.");
	}

	if ($input_errors) {
		return $input_errors;
	}

	$lagg = array();
	$lagg['members'] = implode(',', (array)$post['members']);
	$lagg['descr'] = $post['descr'];
	/* Keep the interface name of an existing LAGG; never take it from the form. */
	$lagg['laggif'] = $this_lagg_config['laggif'] ?? '';
	$lagg['proto'] = $post['proto'];
	if (($post['proto'] == 'failover') && isset($post['failovermaster'])) {
		$lagg['failovermaster'] = $post['failovermaster'];
	} else {
		unset($lagg['failovermaster']);
	}
	if (($post['proto'] == 'lacp') && isset($post['lacptimeout'])) {
		$lagg['lacptimeout'] = $post['lacptimeout'];
	} else {
		unset($lagg['lacptimeout']);
	}
	if ((($post['proto'] == 'lacp') || ($post['proto'] == 'loadbalance')) &&
	    isset($post['lagghash']) && array_key_exists($post['lagghash'], $lagg_hash_list)) {
		$lagg['lagghash'] = $post['lagghash'];
	} else {
		unset($lagg['lagghash']);
	}

	$lagg['laggif'] = interface_lagg_configure($lagg);
	if ($lagg['laggif'] == "" || !stristr($lagg['laggif'], "lagg")) {
		$input_errors[] = gettext("Error occurred creating interface, please retry.");
		return $input_errors;
	}

	if ($this_lagg_config) {
		config_set_path("laggs/lagg/{$id}", $lagg);
	} else {
		config_set_path('laggs/lagg/', $lagg);
	}

	write_config("LAGG interface added");

	$confif = convert_real_interface_to_friendly_interface_name($lagg['laggif']);
	if ($confif != "") {
		interface_configure($confif);
	}

	// reconfigure any VLANs with this lagg as their parent
	foreach (config_get_path('vlans/vlan', []) as $vlan) {
		if ($vlan['if'] == $lagg['laggif']) {
			interface_vlan_configure($vlan);
			$confif = convert_real_interface_to_friendly_interface_name($vlan['vlanif']);
			if ($confif != "") {
				interface_configure($confif);
			}
		}
	}
	return array();
}

/*
 * Delete the LAGG at position $id. Returns true when it was deleted;
 * otherwise $input_errors says why (for example, it is assigned).
 */
function interfaces_lagg_delete($id, &$input_errors) {
	$input_errors = array();
	if (!isset($id)) {
		$input_errors[] = gettext("Wrong parameters supplied");
	} else if (empty(config_get_path("laggs/lagg/{$id}"))) {
		$input_errors[] = gettext("Wrong index supplied");
	/* check if still in use */
	} else if (($inuse = lagg_inuse($id)) !== false) {
		$input_errors[] = $inuse;
	}
	if ($input_errors) {
		return false;
	}

	FreeSense_interface_destroy(config_get_path("laggs/lagg/{$id}/laggif"));
	config_del_path("laggs/lagg/{$id}");

	write_config("LAGG interface deleted");
	return true;
}

/* ------------------------------------------------------------------ */
/* QinQ                                                                */
/* ------------------------------------------------------------------ */

/* The member tag rows (member0, member1, ...) as posted, space separated; the page refills the form with these. */
function interfaces_qinq_posted_members(array $post) {
	$posted = array();
	for ($i = 0; isset($post["member{$i}"]); $i++) {
		$posted[] = $post["member{$i}"];
	}
	return implode(' ', $posted);
}

/*
 * Save the QinQ edit form. $id is the position of the entry being edited,
 * or null for a new one. Returns the input errors; empty on success.
 */
function interfaces_qinq_save(array $post, $id, $read_only = false) {
	$input_errors = array();
	$this_qinq_config = isset($id) ? config_get_path("qinqs/qinqentry/{$id}") : null;

	if ($read_only) {
		$input_errors = array(gettext("Insufficient privileges to make the requested change (read only)."));
	}

	if (!array_key_exists($post['tag_type'], interfaces_vlan_tag_types())) {
		$input_errors[] = gettext("The selected VLAN Tag Type is invalid.");
	}

	if (empty($post['tag'])) {
		$input_errors[] = gettext("First level tag cannot be empty.");
	} elseif (!vlan_valid_tag($post['tag'])) {
		$input_errors[] = gettext("The first level tag must be an integer between 1 and 4094.");
	}
	if ($this_qinq_config && $this_qinq_config['tag'] != $post['tag']) {
		$input_errors[] = gettext("Modifying the first level tag of an existing entry is not allowed.");
	}
	if ($this_qinq_config && $this_qinq_config['if'] != $post['if']) {
		$input_errors[] = gettext("Modifying the interface of an existing entry is not allowed.");
	}
	if (!isset($id)) {
		/* only the parents the edit page offers */
		if (!array_key_exists($post['if'], interfaces_vlan_parent_list())) {
			$input_errors[] = gettext("Interface supplied as parent is invalid");
		}
		foreach (config_get_path('qinqs/qinqentry', []) as $qinqentry) {
			if ($qinqentry['tag'] == $post['tag'] && $qinqentry['if'] == $post['if']) {
				$input_errors[] = gettext("QinQ level already exists for this interface, edit it!");
			}
		}
		foreach (config_get_path('vlans/vlan', []) as $vlan) {
			if ($vlan['tag'] == $post['tag'] && $vlan['if'] == $post['if']) {
				$input_errors[] = gettext("A normal VLAN exists with this tag please remove it to use this tag for QinQ first level.");
			}
		}
	}

	$qinqentry = array();
	$qinqentry['if'] = $post['if'];
	$qinqentry['tag_type'] = $post['tag_type'];
	$qinqentry['tag'] = $post['tag'];

	if (($post['autogroup'] ?? '') == "yes") {
		$qinqentry['autogroup'] = true;
	}

	$tag_min = 1;
	$tag_max = 4094;
	$tag_format_error = false;
	$members = "";

	// Read the POSTed member array into a space separated list translating any ranges
	// into their included values
	$membercounter = 0;
	$membername = "member{$membercounter}";
	$valid_members = array();

	while (isset($post[$membername])) {
		if (is_intrange($post[$membername], $tag_min, $tag_max)) {
			$sep = (strpos($post[$membername], ":") === false) ? "-" : ":";
			$member = explode($sep, $post[$membername]);
			for ($i = intval($member[0]); $i <= intval($member[1]); $i++) {
				$valid_members[] = $i;
			}
		} elseif (is_numericint($post[$membername]) && ($post[$membername] >= $tag_min) && ($post[$membername] <= $tag_max)) {
			$valid_members[] = intval($post[$membername]);
		} elseif ($post[$membername] != "") {
			$tag_format_error = true;
		} // else ignore empty rows

		$membercounter++;
		$membername = "member{$membercounter}";
	}

	if ($tag_format_error) {
		$input_errors[] = sprintf(gettext('Tags can contain only numbers or a range  (in format #-#) from %1$s to %2$s.'), $tag_min, $tag_max);
	}

	// Just use the unique valid members. There could have been overlap in the ranges or repeat of numbers entered.
	$members = implode(" ", array_unique($valid_members));

	if ($members == "") {
		$input_errors[] = gettext("At least one tag must be entered.");
	}

	$nmembers = explode(" ", $members);
	if ($this_qinq_config) {
		$omembers = explode(" ", $this_qinq_config['members']);
		$delmembers = array_diff($omembers, $nmembers);
		foreach ($delmembers as $tag) {
			if (qinq_inuse($this_qinq_config, $tag)) {
				$input_errors[] = gettext("This QinQ tag cannot be deleted because it is still being used as an interface.");
				break;
			}
		}
	}

	if ($input_errors) {
		return $input_errors;
	}

	$qinqentry['members'] = $members;
	$qinqentry['descr'] = $post['descr'];
	$qinqentry['vlanif'] = vlan_interface($post);
	$nmembers = explode(" ", $members);

	if ($this_qinq_config) {
		$omembers = explode(" ", $this_qinq_config['members']);
		$delmembers = array_diff($omembers, $nmembers);
		$addmembers = array_diff($nmembers, $omembers);

		if ((count($delmembers) > 0) || (count($addmembers) > 0)) {
			$parent = $qinqentry['vlanif'];
			foreach ($delmembers as $tag) {
				exec("/sbin/ifconfig " . escapeshellarg("{$parent}.{$tag}") . " destroy");
			}

			foreach ($addmembers as $member) {
				$qinq = array();
				$qinq['if'] = $qinqentry['vlanif'];
				$qinq['tag'] = $member;
				interface_qinq2_configure($qinq);
			}
		}
		config_set_path("qinqs/qinqentry/{$id}", $qinqentry);

		interface_vlan_set_tag_type($qinqentry['vlanif'], $qinqentry['tag_type']);
	} else {
		interface_qinq_configure($qinqentry);
		config_set_path('qinqs/qinqentry/', $qinqentry);
	}
	if (($post['autogroup'] ?? '') == "yes") {
		$gid = null;
		foreach (config_get_path('ifgroups/ifgroupentry', []) as $idx => $group) {
			if ($group['ifname'] == "QinQ") {
				$gid = $idx;
				break;
			}
		}
		$additions = array();
		foreach ($nmembers as $qtag) {
			$additions[] = qinq_interface($qinqentry, $qtag);
		}
		$additions[] = "{$qinqentry['vlanif']}";
		if ($gid !== null) {
			/* add only what the group does not hold yet (every edit used to append all of them again) */
			$gmembers = config_get_path("ifgroups/ifgroupentry/{$gid}/members");
			$additions = array_diff($additions, explode(" ", (string)$gmembers));
			if (!empty($additions)) {
				config_set_path("ifgroups/ifgroupentry/{$gid}/members", $gmembers . " " . implode(" ", $additions));
			}
		} else {
			$gentry = array();
			$gentry['ifname'] = "QinQ";
			$gentry['members'] = implode(" ", $additions);
			$gentry['descr'] = gettext("QinQ VLANs group");
			config_set_path('ifgroups/ifgroupentry/', $gentry);
		}
	}

	write_config("QinQ interface added");
	return array();
}

/*
 * Delete the QinQ at position $id. Returns true when it was deleted;
 * otherwise $input_errors says why.
 */
function interfaces_qinq_delete($id, $read_only, &$input_errors) {
	$input_errors = array();
	if ($read_only) {
		$input_errors = array(gettext("Insufficient privileges to make the requested change (read only)."));
	}

	$this_qinq_config = isset($id) ? config_get_path("qinqs/qinqentry/{$id}") : null;
	/* check if still in use */
	if ((config_get_path('qinqs/qinqentry') !== null) && vlan_inuse($this_qinq_config)) {
		$input_errors[] = gettext("This QinQ cannot be deleted because it is still being used as an interface.");
	} elseif (empty($this_qinq_config['vlanif']) || !does_interface_exist($this_qinq_config['vlanif'])) {
		$input_errors[] = gettext("QinQ interface does not exist");
	} else {
		$delmembers = explode(" ", $this_qinq_config['members']);
		foreach ($delmembers as $tag) {
			if (qinq_inuse($this_qinq_config, $tag)) {
				$input_errors[] = gettext("This QinQ cannot be deleted because one of it tags is still being used as an interface.");
				break;
			}
		}
	}

	if ($input_errors) {
		return false;
	}

	$delmembers = explode(" ", $this_qinq_config['members']);
	foreach ($delmembers as $tag) {
		exec("/sbin/ifconfig " . escapeshellarg("{$this_qinq_config['vlanif']}.{$tag}") . " destroy");
	}
	FreeSense_interface_destroy($this_qinq_config['vlanif']);
	config_del_path("qinqs/qinqentry/{$id}");

	write_config("QinQ interface deleted");
	return true;
}

/* ------------------------------------------------------------------ */
/* Interface groups                                                    */
/* ------------------------------------------------------------------ */

/* The interfaces the group edit page offers as members (assigned, no IPsec VTI; upstream issue 11134). */
function interfaces_group_member_list() {
	$interface_list = get_configured_interface_with_descr(true);
	foreach ($interface_list as $if => $ifdescr) {
		if (substr(get_real_interface($if), 0, 5) == "ipsec") {
			unset($interface_list[$if]);
		}
	}
	return $interface_list;
}

/* The members of $members the group edit page offers (deleted interfaces etc. dropped; upstream issue 15778). */
function interfaces_group_valid_members(array $members) {
	$interface_list = interfaces_group_member_list();
	$validmembers = array();
	foreach ($members as $ifname) {
		if (array_key_exists($ifname, $interface_list)) {
			$validmembers[] = $ifname;
		}
	}
	return $validmembers;
}

/*
 * Where an interface group is referenced by name, as a list of
 * descriptions: the interface of firewall rules (a comma separated list on
 * floating rules) and NAT rules, and rule sources/destinations that use the
 * group's networks.
 */
function interfaces_group_users($name) {
	$name = (string)$name;
	$users = array();
	$refers = function ($rule, $listif) use ($name) {
		if (!is_array($rule)) {
			return false;
		}
		$ifs = $listif ? explode(",", (string)($rule['interface'] ?? '')) : array((string)($rule['interface'] ?? ''));
		return in_array($name, $ifs, true) ||
		    ((string)($rule['source']['network'] ?? '') === $name) ||
		    ((string)($rule['destination']['network'] ?? '') === $name);
	};
	foreach (config_get_path('filter/rule', []) as $idx => $rule) {
		if ($refers($rule, true)) {
			$users[] = sprintf(gettext('firewall rule "%s"'), ($rule['descr'] ?? '') !== '' ? $rule['descr'] : $idx);
		}
	}
	$nat = array(
		'nat/rule' => gettext('port forward'),
		'nat/onetoone' => gettext('1:1 NAT rule'),
		'nat/outbound/rule' => gettext('outbound NAT rule'),
		'nat/npt' => gettext('NPt rule'),
	);
	foreach ($nat as $path => $label) {
		foreach (config_get_path($path, []) as $idx => $rule) {
			if ($refers($rule, false)) {
				$users[] = sprintf('%s "%s"', $label, ($rule['descr'] ?? '') !== '' ? $rule['descr'] : $idx);
			}
		}
	}
	return $users;
}

/*
 * Save the interface group edit form. $id is the position of the group
 * being edited, or null for a new one. Renaming a group also renames it in
 * the firewall rules and port forwards that use it. Returns the input
 * errors; empty on success.
 */
function interfaces_group_save(array $post, $id) {
	$input_errors = array();
	$interface_list = interfaces_group_member_list();
	$interface_list_disabled = get_configured_interface_with_descr(true);
	$this_ifgroup_config = isset($id) ? config_get_path("ifgroups/ifgroupentry/{$id}") : null;
	if ($this_ifgroup_config) {
		/* Cleanup invalid group members (Deleted interfaces, etc.)
		 * upstream issue 15778 */
		array_set_path($this_ifgroup_config, 'members',
		    implode(" ", interfaces_group_valid_members(explode(" ", array_get_path($this_ifgroup_config, 'members', "")))));
	}

	/* input validation */
	$reqdfields = explode(" ", "ifname");
	$reqdfieldsn = array(gettext("Group Name"));
	do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);

	if (!$input_errors) {
		/* Reserved name? Allow the reserved interface-network suffix since it is based on the interface name. */
		if ((get_pf_reserved($post['ifname'], false) && !str_ends_with($post['ifname'], '__NETWORK')) ||
		    (get_pf_reserved(strtoupper($post['ifname']), false) && !str_ends_with(strtoupper($post['ifname']), '__NETWORK'))) {
			$input_errors[] = sprintf(gettext("Cannot use a reserved keyword as an interface name: %s"), $post['ifname']);
		}

		foreach (config_get_path('ifgroups/ifgroupentry', []) as $groupid => $groupentry) {
			if ((!isset($id) || ($groupid != $id)) && ($groupentry['ifname'] == $post['ifname'])) {
				$input_errors[] = gettext("Group name already exists!");
			}
		}

		if (strlen($post['ifname']) > 15) {
			$input_errors[] = gettext("Group name cannot have more than 15 characters.");
		}

		if (preg_match("/([^a-zA-Z0-9_])+/", $post['ifname'])) {
			$input_errors[] = gettext("Only letters (A-Z), digits (0-9) and '_' are allowed.") . " " . gettext("Please choose another group name.");
		}

		if (preg_match("/(^[0-9])|([0-9]$)/", $post['ifname'])) {
			$input_errors[] = gettext("The group name cannot start or end with a digit.");
		}

		/*
		 * Packages (e.g. tinc) create interface groups, reserve this
		 * namespace pkg_ for them
		 */
		if (substr($post['ifname'], 0, 4) == 'pkg_') {
			$input_errors[] = gettext("Group name cannot start with pkg_");
		}

		foreach ($interface_list_disabled as $gif => $gdescr) {
			if ((strcasecmp($gdescr, $post['ifname']) == 0) || (strcasecmp($gif, $post['ifname']) == 0)) {
				$input_errors[] = "The specified group name is already used by an interface. Please choose another name.";
			}
		}

		/* Is the description already used as an alias name? */
		foreach(config_get_path('aliases/alias', []) as $alias) {
			if ($alias['name'] == $post['ifname']) {
				$input_errors[] = gettext("An alias with this name already exists.");
			}
		}

	}

	/* Ensure submitted interfaces exist in the configuration, filter
	 * invalid entries from selected interface list.
	 * upstream issue 15778 */
	$validmembers = [];
	foreach ((array)($post['members'] ?? []) as $ifname) {
		if (array_key_exists($ifname, $interface_list)) {
			$validmembers[] = $ifname;
		} else {
			$input_errors[] = gettext("Submission contained an invalid interface");
		}
	}

	if (!empty($validmembers)) {
		$members = implode(" ", $validmembers);
	} else {
		$members = "";
	}

	if ($input_errors) {
		return $input_errors;
	}

	$ifgroupentry = array();
	$ifgroupentry['members'] = $members;
	$ifgroupentry['descr'] = $post['descr'];

	// Edit group name
	if ($this_ifgroup_config && $post['ifname'] != $this_ifgroup_config['ifname']) {
		$filter_rule_config = get_filter_rules_list();
		if (is_array($filter_rule_config)) {
			foreach ($filter_rule_config as &$rule) {
				if (isset($rule['floating'])) {
					$rule_ifs = array_filter(explode(",", $rule['interface']));
					$rule_changed = false;
					foreach ($rule_ifs as $rule_if_id => $rule_if) {
						if ($rule_if == $this_ifgroup_config['ifname']) {
							$rule_ifs[$rule_if_id] = $post['ifname'];
							$rule_changed = true;
						}
					}
					if ($rule_changed) {
						$rule['interface'] = implode(",", $rule_ifs);
					}
				} else {
					if ($rule['interface'] == $this_ifgroup_config['ifname']) {
						$rule['interface'] = $post['ifname'];
					}
				}
			}
			unset($rule);
			set_filter_rules_list($filter_rule_config);
		}
		$nat_rule_config = get_anynat_rules_list('rdr');
		if (is_array($nat_rule_config)) {
			foreach ($nat_rule_config as $ridx => &$rule) {
				if ($rule['interface'] == $this_ifgroup_config['ifname']) {
					$rule['interface'] = $post['ifname'];
				}
			}
			unset($rule);
			set_anynat_rules_list('rdr', $nat_rule_config);
		}
		$omembers = explode(" ", $this_ifgroup_config['members']);
		if (count($omembers) > 0) {
			foreach ($omembers as $ifs) {
				$realif = get_real_interface($ifs);
				if ($realif) {
					mwexec("/sbin/ifconfig {$realif} -group " . $this_ifgroup_config['ifname']);
				}
			}
		}
		$ifgroupentry['ifname'] = $post['ifname'];
		config_set_path("ifgroups/ifgroupentry/{$id}", $ifgroupentry);

	// Edit old group
	} else if ($this_ifgroup_config) {
		$omembers = explode(" ", $this_ifgroup_config['members']);
		$nmembers = explode(" ", $members);
		$delmembers = array_diff($omembers, $nmembers);
		if (count($delmembers) > 0) {
			foreach ($delmembers as $ifs) {
				$realif = get_real_interface($ifs);
				if ($realif) {
					mwexec("/sbin/ifconfig {$realif} -group " . $this_ifgroup_config['ifname']);
				}
			}
		}
		$ifgroupentry['ifname'] = $post['ifname'];
		config_set_path("ifgroups/ifgroupentry/{$id}", $ifgroupentry);

	// Create new group
	} else {
		$ifgroupentry['ifname'] = $post['ifname'];
		config_set_path('ifgroups/ifgroupentry/', $ifgroupentry);
	}

	write_config("Interface Group added");
	interface_group_setup($ifgroupentry);
	return array();
}

/*
 * Delete the interface group at position $id. Refused while firewall or
 * NAT rules use it (deleting it used to leave them dangling). Returns true
 * when deleted; otherwise $input_errors says why (empty for an unknown id,
 * which the page ignores).
 */
function interfaces_group_delete($id, &$input_errors) {
	$input_errors = array();
	if (!is_numericint($id) || !config_get_path("ifgroups/ifgroupentry/{$id}")) {
		return false;
	}
	$name = config_get_path("ifgroups/ifgroupentry/{$id}/ifname");
	$users = interfaces_group_users($name);
	if (!empty($users)) {
		$input_errors[] = sprintf(gettext('Interface group "%1$s" cannot be deleted because it is in use by %2$s.'),
		    $name, implode(', ', $users));
		return false;
	}

	$members = explode(" ", config_get_path("ifgroups/ifgroupentry/{$id}/members"));
	foreach ($members as $ifs) {
		$realif = get_real_interface($ifs);
		if ($realif) {
			mwexec("/sbin/ifconfig {$realif} -group " . $name);
		}
	}
	config_del_path("ifgroups/ifgroupentry/{$id}");
	write_config("Interface Group deleted");
	return true;
}

/* ------------------------------------------------------------------ */
/* Bridges                                                             */
/* ------------------------------------------------------------------ */

/* The interfaces the bridge edit page lists (enabled assignments, no GRE). */
function interfaces_bridge_iface_list() {
	$ifacelist = get_configured_interface_with_descr();

	foreach ($ifacelist as $bif => $bdescr) {
		if (substr(get_real_interface($bif), 0, 3) == "gre") {
			unset($ifacelist[$bif]);
		}
	}
	return $ifacelist;
}

/* Port list with the exception of assigned bridge interfaces to prevent invalid configs. */
function interfaces_bridge_port_list($selection) {
	$portlist = array('list' => array(), 'selected' => array());

	$if_config = config_get_path('interfaces', []);
	foreach (interfaces_bridge_iface_list() as $ifn => $ifdescr) {
		if (substr($if_config[$ifn]['if'], 0, 6) != "bridge") {
			$portlist['list'][$ifn] = $ifdescr;

			if (in_array($ifn, explode(',', $selection))) {
				array_push($portlist['selected'], $ifn);
			}
		}
	}

	return($portlist);
}

/* Whether the edit page opens with its advanced options shown (was is_aoadv_used()). */
function bridge_advanced_used($pconfig) {
	if (($pconfig['static'] !="") ||
	    ($pconfig['private'] != "") ||
	    $pconfig['ip6linklocal'] ||
	    ($pconfig['stp'] != "") ||
	    ($pconfig['span'] != "") ||
	    ($pconfig['edge'] != "") ||
	    ($pconfig['autoedge'] != "") ||
	    ($pconfig['ptp'] != "") ||
	    ($pconfig['autoptp'] != "") ||
	    ($pconfig['maxaddr'] != "") ||
	    ($pconfig['timeout'] != "") ||
	    ($pconfig['maxage'] != "") ||
	    ($pconfig['fwdelay'] != "") ||
	    ($pconfig['hellotime'] != "") ||
	    ($pconfig['priority'] != "") ||
	    (($pconfig['proto'] != "") && ($pconfig['proto'] != "rstp")) ||
	    ($pconfig['holdcnt'] != "")) {
		return true;
	}

	return false;
}

/* The bridge form fields as the page refills them after an error (the port lists comma separated). */
function interfaces_bridge_form_values(array $post) {
	$pconfig = $post;
	foreach (array('static', 'private', 'stp', 'span', 'edge', 'autoedge', 'ptp', 'autoptp', 'members') as $field) {
		if (isset($post[$field]) && is_array($post[$field])) {
			$pconfig[$field] = implode(',', $post[$field]);
		}
	}
	return $pconfig;
}

function bridge_inuse($num) {
	$a_bridges = config_get_path('bridges/bridged', []);
	$iflist = get_configured_interface_list(true);
	$if_config = config_get_path('interfaces', []);
	foreach ($iflist as $if) {
		if ($if_config[$if]['if'] == $a_bridges[$num]['bridgeif']) {
			return true;
		}
	}

	return false;
}

/*
 * Save the bridge edit form. $id is the position of the bridge being
 * edited, or null for a new one. The per-interface STP priority and path
 * cost fields are named after the interface ("lan", "lan0"). Returns the
 * input errors; empty on success.
 */
function interfaces_bridge_save(array $post, $id) {
	$input_errors = array();
	$ifacelist = interfaces_bridge_iface_list();
	$this_bridge_config = isset($id) ? config_get_path("bridges/bridged/{$id}") : null;
	/* Keep the interface name of an existing bridge; never take it from the form. */
	$bridgeif = $this_bridge_config['bridgeif'] ?? '';

	/* input validation */
	$reqdfields = explode(" ", "members");
	$reqdfieldsn = array(gettext("Member Interfaces"));

	do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);

	if ($post['maxage'] && !is_numericint($post['maxage'])) {
		$input_errors[] = gettext("Maxage needs to be an integer between 6 and 40.");
	}
	if ($post['maxaddr'] && !is_numericint($post['maxaddr'])) {
		$input_errors[] = gettext("Maxaddr needs to be an integer.");
	}
	if ($post['timeout'] && !is_numericint($post['timeout'])) {
		$input_errors[] = gettext("Timeout needs to be an integer.");
	}
	if ($post['fwdelay'] && !is_numericint($post['fwdelay'])) {
		$input_errors[] = gettext("Forward Delay needs to be an integer between 4 and 30.");
	}
	if ($post['hellotime'] && !is_numericint($post['hellotime'])) {
		$input_errors[] = gettext("Hello time for STP needs to be an integer between 1 and 2.");
	}
	if ($post['priority'] && !is_numericint($post['priority'])) {
		$input_errors[] = gettext("Priority for STP needs to be an integer between 0 and 61440.");
	}
	if ($post['holdcnt'] && !is_numericint($post['holdcnt'])) {
		$input_errors[] = gettext("Transmit Hold Count for STP needs to be an integer between 1 and 10.");
	}
	foreach ($ifacelist as $ifn => $ifdescr) {
		if (($post[$ifn] ?? '') <> "" && !is_numericint($post[$ifn])) {
			$input_errors[] = sprintf(gettext("%s interface priority for STP needs to be an integer between 0 and 240."), $ifdescr);
		}
	}

	/* the path cost fields are "<interface>0" (the check used to look at "<interface><n>") */
	foreach ($ifacelist as $ifn => $ifdescr) {
		if (($post["{$ifn}0"] ?? '') <> "" && !is_numeric($post["{$ifn}0"])) {
			$input_errors[] = sprintf(gettext("%s interface path cost for STP needs to be an integer between 1 and 200000000."), $ifdescr);
		}
	}

	if (!is_array($post['members'] ?? null) || count($post['members']) < 1) {
		$input_errors[] = gettext("At least one member interface must be selected for a bridge.");
	}

	if (is_array($post['members'] ?? null) && is_array(config_get_path('captiveportal'))) {
		foreach ($post['members'] as $member) {
			foreach (config_get_path('captiveportal', []) as $cp) {
				if (isset($cp['enable']) && in_array($member, explode(',', $cp['interface']))) {
					$input_errors[] = sprintf(gettext('The interface (%s) is part of ' .
						'the Captive Portal and cannot be part of the bridge. ' .
						'Remove the interface to continue.'), $ifacelist[$member] ?? $member);
				}
			}
		}
	}

	if (is_array($post['static'] ?? null)) {
		foreach ($post['static'] as $ifstatic) {
			if (is_array($post['members'] ?? null) && !in_array($ifstatic, $post['members'])) {
				$input_errors[] = sprintf(gettext('Sticky interface (%s) is not part of the bridge. Remove the sticky interface to continue.'), $ifacelist[$ifstatic]);
			}
		}
	}
	if (is_array($post['private'] ?? null)) {
		foreach ($post['private'] as $ifprivate) {
			if (is_array($post['members'] ?? null) && !in_array($ifprivate, $post['members'])) {
				$input_errors[] = sprintf(gettext('Private interface (%s) is not part of the bridge. Remove the private interface to continue.'), $ifacelist[$ifprivate]);
			}
		}
	}
	if (is_array($post['stp'] ?? null)) {
		foreach ($post['stp'] as $ifstp) {
			if (is_array($post['members'] ?? null) && !in_array($ifstp, $post['members'])) {
				$input_errors[] = sprintf(gettext('STP interface (%s) is not part of the bridge. Remove the STP interface to continue.'), $ifacelist[$ifstp]);
			}
			$realif = get_real_interface($ifstp);
			if (is_pseudo_interface($realif) || interface_is_vlan($realif)) {
				$input_errors[] = sprintf(gettext('STP interface (%s) must not be a pseudo-interface or a VLAN interface.'), $ifacelist[$ifstp]);
			}
		}
	}
	if (is_array($post['edge'] ?? null)) {
		foreach ($post['edge'] as $ifedge) {
			if (is_array($post['members'] ?? null) && !in_array($ifedge, $post['members'])) {
				$input_errors[] = sprintf(gettext('Edge interface (%s) is not part of the bridge. Remove the edge interface to continue.'), $ifacelist[$ifedge]);
			}
		}
	}
	if (is_array($post['autoedge'] ?? null)) {
		foreach ($post['autoedge'] as $ifautoedge) {
			if (is_array($post['members'] ?? null) && !in_array($ifautoedge, $post['members'])) {
				$input_errors[] = sprintf(gettext('Auto Edge interface (%s) is not part of the bridge. Remove the auto edge interface to continue.'), $ifacelist[$ifautoedge]);
			}
		}
	}
	if (is_array($post['ptp'] ?? null)) {
		foreach ($post['ptp'] as $ifptp) {
			if (is_array($post['members'] ?? null) && !in_array($ifptp, $post['members'])) {
				$input_errors[] = sprintf(gettext('PTP interface (%s) is not part of the bridge. Remove the PTP interface to continue.'), $ifacelist[$ifptp]);
			}
		}
	}
	if (is_array($post['autoptp'] ?? null)) {
		foreach ($post['autoptp'] as $ifautoptp) {
			if (is_array($post['members'] ?? null) && !in_array($ifautoptp, $post['members'])) {
				$input_errors[] = sprintf(gettext('Auto PTP interface (%s) is not part of the bridge. Remove the auto PTP interface to continue.'), $ifacelist[$ifautoptp]);
			}
		}
	}
	if (is_array($post['members'] ?? null)) {
		$if_config = config_get_path('interfaces', []);
		foreach ($post['members'] as $ifmembers) {
			if (empty($if_config[$ifmembers])) {
				$input_errors[] = gettext("A member interface passed does not exist in configuration");
			}
			if (substr($if_config[$ifmembers]['if'], 0, 6) == "bridge") {
				$input_errors[] = gettext("A bridge interface cannot be a member of a bridge.");
			}
			if (is_array($if_config[$ifmembers]['wireless']) &&
			    $if_config[$ifmembers]['wireless']['mode'] != "hostap") {
				$input_errors[] = gettext("Bridging a wireless interface is only possible in hostap mode.");
			}
			if (is_array($post['span'] ?? null) && in_array($ifmembers, $post['span'])) {
				$input_errors[] = sprintf(gettext('Span interface (%s) cannot be part of the bridge. Remove the span interface from bridge members to continue.'), $ifacelist[$ifmembers]);
			}
			foreach (config_get_path('bridges/bridged', []) as $a_bridge) {
				if ($bridgeif === $a_bridge['bridgeif']) {
					continue;
				}
				$a_members = explode(',', $a_bridge['members']);
				foreach ($a_members as $a_member) {
					if ($ifmembers === $a_member) {
						$input_errors[] = sprintf(gettext("%s is part of another bridge. Remove the interface from bridge members to continue."), $ifacelist[$ifmembers]);
					}
				}
			}
		}
	}

	if ($input_errors) {
		return $input_errors;
	}

	$bridge = array();
	$bridge['members'] = implode(',', $post['members']);
	$bridge['enablestp'] = !empty($post['enablestp']);
	$bridge['descr'] = $post['descr'];
	$bridge['maxaddr'] = $post['maxaddr'];
	$bridge['timeout'] = $post['timeout'];
	$bridge['maxage'] = $post['maxage'];
	$bridge['fwdelay'] = $post['fwdelay'];
	$bridge['hellotime'] = $post['hellotime'];
	$bridge['priority'] = $post['priority'];
	$bridge['proto'] = $post['proto'];
	$bridge['holdcnt'] = $post['holdcnt'];
	$i = 0;
	$j = 0;
	$ifpriority = "";
	$ifpathcost = "";

	if (!empty($post['ip6linklocal'])) {
		$bridge['ip6linklocal'] = true;
	}

	foreach ($ifacelist as $ifn => $ifdescr) {
		if (($post[$ifn] ?? '') <> "") {
			if ($i > 0) {
				$ifpriority .= ",";
			}
			$ifpriority .= $ifn.":".$post[$ifn];
			$i++;
		}
		if (($post["{$ifn}0"] ?? '') <> "") {
			if ($j > 0) {
				$ifpathcost .= ",";
			}
			$ifpathcost .= $ifn.":".$post["{$ifn}0"];
			$j++;
		}
	}

	$bridge['ifpriority'] = $ifpriority;
	$bridge['ifpathcost'] = $ifpathcost;

	if (isset($post['static'])) {
		$bridge['static'] = implode(',', $post['static']);
	}
	if (isset($post['private'])) {
		$bridge['private'] = implode(',', $post['private']);
	}
	if (isset($post['stp'])) {
		$bridge['stp'] = implode(',', $post['stp']);
	}
	if (isset($post['span'])) {
		$bridge['span'] = implode(',', $post['span']);
	}
	if (isset($post['edge'])) {
		$bridge['edge'] = implode(',', $post['edge']);
	}
	if (isset($post['autoedge'])) {
		$bridge['autoedge'] = implode(',', $post['autoedge']);
	}
	if (isset($post['ptp'])) {
		$bridge['ptp'] = implode(',', $post['ptp']);
	}
	if (isset($post['autoptp'])) {
		$bridge['autoptp'] = implode(',', $post['autoptp']);
	}

	if (empty($bridgeif) ||
	    preg_match("/^bridge[0-9]+$/", $bridgeif)) {
		/* Attempt initial configuration of the bridge if the
		 * interface is empty or looks like a bridge interface. */
		$bridge['bridgeif'] = $bridgeif;
		interface_bridge_configure($bridge);
	} else {
		$input_errors[] = gettext("Invalid bridge interface.");
	}

	if (empty($bridge['bridgeif']) ||
	    !preg_match("/^bridge[0-9]+$/", $bridge['bridgeif'])) {
		$input_errors[] = gettext("Error occurred creating interface, please retry.");
		return $input_errors;
	}

	if ($this_bridge_config) {
		config_set_path("bridges/bridged/{$id}", $bridge);
	} else {
		config_set_path('bridges/bridged/', $bridge);
	}

	write_config("Bridge interface created");

	$confif = convert_real_interface_to_friendly_interface_name($bridge['bridgeif']);
	if ($confif <> "") {
		interface_configure($confif);
	}
	return array();
}

/* Delete the bridge at position $id; see interfaces_lagg_delete(). */
function interfaces_bridge_delete($id, &$input_errors) {
	$input_errors = array();
	if (!isset($id)) {
		$input_errors[] = gettext("Wrong parameters supplied");
	} else if (empty(config_get_path("bridges/bridged/{$id}"))) {
		$input_errors[] = gettext("Wrong index supplied");
	/* check if still in use */
	} else if (bridge_inuse($id)) {
		$input_errors[] = gettext("This bridge cannot be deleted because it is assigned as an interface.");
	}
	if ($input_errors) {
		return false;
	}

	if (!does_interface_exist(config_get_path("bridges/bridged/{$id}/bridgeif"))) {
		logger(LOG_NOTICE, localize_text("Bridge interface does not exist, skipping ifconfig destroy."));
	} else {
		FreeSense_interface_destroy(config_get_path("bridges/bridged/{$id}/bridgeif"));
	}

	config_del_path("bridges/bridged/{$id}");

	write_config("Bridge deleted");
	return true;
}
