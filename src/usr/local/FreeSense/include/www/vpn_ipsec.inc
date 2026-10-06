<?php
/*
 * vpn_ipsec.inc
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
 * IPsec logic shared by vpn_ipsec.php (the tunnel list actions and Apply),
 * vpn_ipsec_keys.php, vpn_ipsec_keys_edit.php and the REST API, moved out of
 * the pages with the form fields as parameters. Tunnels are stored in
 * ipsec/phase1 (identified by ikeid) and ipsec/phase2 (identified by uniqid,
 * linked to their phase 1 by ikeid), pre-shared keys in ipsec/mobilekey.
 * Changes are staged with the "ipsec" dirty flag until applied.
 */

require_once("config.gui.inc");
require_once("filter.inc");
require_once("interfaces.inc");
require_once("ipsec.inc");
require_once("util.inc");
require_once("vpn.inc");

/*
 * Apply pending IPsec changes (the Apply button of the tunnel and pre-shared
 * key lists): reconfigure IPsec, run the packages' IPsec reload hooks and
 * reload the filter. Returns the filter reload result.
 */
function ipsec_apply_changes() {
	$ipsec_dynamic_hosts = ipsec_configure();
	ipsec_reload_package_hook();
	/* reload the filter in the background */
	$retval = 0;
	$retval |= filter_configure();
	if ($ipsec_dynamic_hosts >= 0) {
		if (is_subsystem_dirty('ipsec')) {
			clear_subsystem_dirty('ipsec');
		}
	}
	return $retval;
}

/* ------------------------------------------------------------------ */
/* Pre-shared keys                                                     */
/* ------------------------------------------------------------------ */

/* The stored pre-shared key at position $id, or null. */
function ipsec_psk_conf($id) {
	if (($id === null) || !is_numericint((string)$id)) {
		return null;
	}
	$key = config_get_path('ipsec/mobilekey/' . $id);
	return $key ? $key : null;
}

/* The key edit page's form for the key at position $id (empty for a new key). */
function ipsec_psk_form($id) {
	$pconfig = array();
	if (ipsec_psk_conf($id)) {
		$pconfig['ident'] = config_get_path('ipsec/mobilekey/' . $id . '/ident');
		$pconfig['type'] = config_get_path('ipsec/mobilekey/' . $id . '/type');
		$pconfig['psk'] = config_get_path('ipsec/mobilekey/' . $id . '/pre-shared-key');
		$pconfig['ident_type'] = config_get_path('ipsec/mobilekey/' . $id . '/ident_type');
		$pconfig['pool_address'] = config_get_path('ipsec/mobilekey/' . $id . '/pool_address');
		$pconfig['pool_netbits'] = config_get_path('ipsec/mobilekey/' . $id . '/pool_netbits');
		$pconfig['dns_address'] = config_get_path('ipsec/mobilekey/' . $id . '/dns_address');
	}
	return $pconfig;
}

/* The identifier types the key edit page offers (was the page's build_ipsecid_list()). */
function ipsec_psk_ident_type_list() {
	global $ipsec_identifier_list;

	$list = array();

	foreach ($ipsec_identifier_list as $id_type => $id_params) {
		$list[$id_type] = htmlspecialchars($id_params['desc']);
	}

	return($list);
}

/*
 * Validate and save the key edit form ($post) for the key at position $id
 * (null or a missing position: a new key) and mark IPsec dirty, like the
 * page's Save. Returns the input errors.
 */
function ipsec_psk_save(array $post, $id) {
	global $ipsec_preshared_key_type, $ipsec_identifier_list;

	$input_errors = array();
	$existing = (ipsec_psk_conf($id) !== null);

	$userids = array();
	foreach (config_get_path('system/user', []) as $uid => $user) {
		$userids[$user['name']] = $uid;
	}

	/* input validation */
	$reqdfields = explode(" ", "ident psk");
	$reqdfieldsn = array(gettext("Identifier"), gettext("Pre-Shared Key"));

	do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);

	if (preg_match("/[^a-zA-Z0-9@\.\-]/", $post['ident'])) {
		$input_errors[] = gettext("The identifier contains invalid characters.");
	}

	if (array_key_exists($post['ident'], $userids)) {
		$input_errors[] = gettext("A user with this name already exists. Add the key to the user instead.");
	}
	unset($userids);

	if (!array_key_exists((string)$post['type'], $ipsec_preshared_key_type)) {
		$input_errors[] = gettext("A valid secret type must be selected.");
	}

	if (isset($post['psk']) && !preg_match('/^[[:ascii:]]*$/', $post['psk'])) {
		$input_errors[] = gettext("Pre-Shared Key contains invalid characters.");
	}

	if (!empty($post['ident_type']) && !array_key_exists((string)$post['ident_type'], $ipsec_identifier_list)) {
		$input_errors[] = gettext("A valid identifier type must be selected.");
	}

	if (isset($post['pool_address']) && strlen($post['pool_address']) > 0 && !is_ipaddr($post['pool_address'])) {
		$input_errors[] = gettext("A valid IP address for 'Virtual Address Pool' must be specified.");
	}

	if (isset($post['pool_netbits']) && strlen($post['pool_netbits']) > 0 &&
	    (!is_numericint($post['pool_netbits']) || ($post['pool_netbits'] > 32))) {
		$input_errors[] = gettext("A valid mask for 'Virtual Address Pool' must be specified.");
	}

	if (isset($post['dns_address']) && strlen($post['dns_address']) > 0 && !is_ipaddr($post['dns_address'])) {
		$input_errors[] = gettext("A valid IP address for 'DNS Server' must be specified.");
	}

	if (!$input_errors) {
		/* make sure there are no dupes (other than the key being edited) */
		foreach (config_get_path('ipsec/mobilekey', []) as $idx => $secretent) {
			if ($existing && ((string)$idx === (string)$id)) {
				continue;
			}
			if ($secretent['ident'] == $post['ident']) {
				$input_errors[] = gettext("Another entry with the same identifier already exists.");
				break;
			}
		}
	}

	if (!$input_errors) {

		$secretent = array();
		if ($existing) {
			$secretent = config_get_path('ipsec/mobilekey/' . $id);
		}

		$secretent['ident'] = $post['ident'];
		$secretent['type'] = $post['type'];
		$secretent['pre-shared-key'] = $post['psk'];
		$secretent['ident_type'] = $post['ident_type'];
		$secretent['pool_address'] = $post['pool_address'];
		$secretent['pool_netbits'] = $post['pool_netbits'];
		$secretent['dns_address'] = $post['dns_address'];
		$text = "";

		if ($existing) {
			config_set_path('ipsec/mobilekey/' . $id, $secretent);
			$text = gettext("Edited IPsec Pre-Shared Keys");
		} else {
			config_set_path('ipsec/mobilekey/', $secretent);
			$text = gettext("Added IPsec Pre-Shared Keys");
		}

		write_config($text);
		mark_subsystem_dirty('ipsec');
	}

	return $input_errors;
}

/* Delete the key at position $id and mark IPsec dirty; false when there is no such key. */
function ipsec_psk_delete($id) {
	if (ipsec_psk_conf($id) === null) {
		return false;
	}
	config_del_path('ipsec/mobilekey/' . $id);
	write_config(gettext("Deleted IPsec Pre-Shared Key"));
	mark_subsystem_dirty('ipsec');
	return true;
}

/* ------------------------------------------------------------------ */
/* Tunnel list actions                                                 */
/* ------------------------------------------------------------------ */

/*
 * delete_p1_and_children() for the phase 1 entries at the existing positions
 * in $p1list; its errors (a VTI phase 2 with an assigned interface) are
 * appended to $input_errors. Returns the number of entries removed (the
 * caller writes the configuration).
 */
function ipsec_delete_p1_entries(array $p1list, array &$input_errors) {
	$p1list = array_values(array_filter($p1list, function ($idx) {
		return is_numericint((string)$idx) && is_array(config_get_path('ipsec/phase1/' . $idx));
	}));
	/* delete_p1_and_children() reports through the global $input_errors */
	$saved = $GLOBALS['input_errors'] ?? null;
	$GLOBALS['input_errors'] = array();
	$delcount = delete_p1_and_children($p1list);
	$input_errors = array_merge($input_errors, (array)$GLOBALS['input_errors']);
	$GLOBALS['input_errors'] = $saved;
	return $delcount;
}

/*
 * The tunnel list's actions other than Apply, from the page's form post
 * ($post): "del" deletes the selected phase 1 entries (p1entry[]) with their
 * phase 2 entries, "delp2" the selected phase 2 entries (p2entry[]); the row
 * buttons are detected by name: del_N / delp2_N delete, toggle_N / togglep2_N
 * enable or disable, move_N / movep2_N move the selected entries before entry
 * N (N is the position in ipsec/phase1 or ipsec/phase2). A phase 2 in VTI mode
 * whose interface is assigned cannot be deleted or disabled, nor can its
 * phase 1. Saves and marks IPsec dirty; returns the input errors.
 */
function ipsec_tunnels_action(array $post) {
	$input_errors = array();
	$items_deleted = false;

	if (isset($post['del'])) {
		/* delete selected p1 entries */
		if (is_array($post['p1entry']) && count($post['p1entry'])) {
			$delcount = ipsec_delete_p1_entries($post['p1entry'], $input_errors);

			if ($delcount > 0) {
				if (write_config(gettext("Deleted selected IPsec Phase 1 and related Phase 2 entries."))) {
					mark_subsystem_dirty('ipsec');
				}
			}
		}
	} else if (isset($post['delp2'])) {
		/* delete selected p2 entries */
		if (is_array($post['p2entry']) && count($post['p2entry'])) {
			foreach ($post['p2entry'] as $p2entrydel) {
				if (!is_numericint((string)$p2entrydel) || !is_array(config_get_path('ipsec/phase2/' . $p2entrydel))) {
					continue;
				}
				if (is_interface_ipsec_vti_assigned(config_get_path('ipsec/phase2/' . $p2entrydel)) && (config_get_path('ipsec/phase2/' . $p2entrydel . '/mode') == 'vti')) {
					$input_errors[] = gettext("Cannot delete a VTI Phase 2 while the interface is assigned. Remove the interface assignment before deleting this P2.");
				} else {
					config_del_path('ipsec/phase2/' . $p2entrydel);
					$items_deleted = true;
				}
			}
			if ($items_deleted) {
				if (write_config(gettext("Deleted selected IPsec Phase 2 entries."))) {
					mark_subsystem_dirty('ipsec');
				}
			}
		}
	} else  {
		/* yuck - IE won't send value attributes for image buttons, while Mozilla does - so we use .x/.y to find move button clicks instead... */

		// TODO: this. is. nasty.
		unset($delbtn, $delbtnp2, $movebtn, $movebtnp2, $togglebtn, $togglebtnp2);
		foreach ($post as $pn => $pd) {
			if (preg_match("/del_(\d+)/", $pn, $matches)) {
				$delbtn = $matches[1];
			} else if (preg_match("/delp2_(\d+)/", $pn, $matches)) {
				$delbtnp2 = $matches[1];
			} else if (preg_match("/move_(\d+)/", $pn, $matches)) {
				$movebtn = $matches[1];
			} else if (preg_match("/movep2_(\d+)/", $pn, $matches)) {
				$movebtnp2 = $matches[1];
			} else if (preg_match("/toggle_(\d+)/", $pn, $matches)) {
				$togglebtn = $matches[1];
			} else if (preg_match("/togglep2_(\d+)/", $pn, $matches)) {
				$togglebtnp2 = $matches[1];
			}
		}

		$save = 1;

		/* move selected p1 entries before this */
		if (isset($movebtn) && is_array($post['p1entry']) && count($post['p1entry'])) {
			$a_phase1_new = array();

			/* copy all p1 entries < $movebtn and not selected */
			for ($i = 0; $i < $movebtn; $i++) {
				if (!in_array($i, $post['p1entry'])) {
					$a_phase1_new[] = config_get_path('ipsec/phase1/' . $i);
				}
			}

			/* copy all selected p1 entries */
			for ($i = 0; $i < count(config_get_path('ipsec/phase1', [])); $i++) {
				if ($i == $movebtn) {
					continue;
				}
				if (in_array($i, $post['p1entry'])) {
					$a_phase1_new[] = config_get_path('ipsec/phase1/' . $i);
				}
			}

			/* copy $movebtn p1 entry */
			if ($movebtn < count(config_get_path('ipsec/phase1', []))) {
				$a_phase1_new[] = config_get_path('ipsec/phase1/' . $movebtn);
			}

			/* copy all p1 entries > $movebtn and not selected */
			for ($i = $movebtn+1; $i < count(config_get_path('ipsec/phase1', [])); $i++) {
				if (!in_array($i, $post['p1entry'])) {
					$a_phase1_new[] = config_get_path('ipsec/phase1/' . $i);
				}
			}
			if (count($a_phase1_new) > 0) {
				config_set_path('ipsec/phase1', $a_phase1_new);
			}

		} else if (isset($movebtnp2) && is_array($post['p2entry']) && count($post['p2entry'])) {
			/* move selected p2 entries before this */
			$a_phase2_new = array();

			/* copy all p2 entries < $movebtnp2 and not selected */
			for ($i = 0; $i < $movebtnp2; $i++) {
				if (!in_array($i, $post['p2entry'])) {
					$a_phase2_new[] = config_get_path('ipsec/phase2/' . $i);
				}
			}

			/* copy all selected p2 entries */
			for ($i = 0; $i < count(config_get_path('ipsec/phase2', [])); $i++) {
				if ($i == $movebtnp2) {
					continue;
				}
				if (in_array($i, $post['p2entry'])) {
					$a_phase2_new[] = config_get_path('ipsec/phase2/' . $i);
				}
			}

			/* copy $movebtnp2 p2 entry */
			if ($movebtnp2 < count(config_get_path('ipsec/phase2', []))) {
				$a_phase2_new[] = config_get_path('ipsec/phase2/' . $movebtnp2);
			}

			/* copy all p2 entries > $movebtnp2 and not selected */
			for ($i = $movebtnp2+1; $i < count(config_get_path('ipsec/phase2', [])); $i++) {
				if (!in_array($i, $post['p2entry'])) {
					$a_phase2_new[] = config_get_path('ipsec/phase2/' . $i);
				}
			}
			if (count($a_phase2_new) > 0) {
				config_set_path('ipsec/phase2', $a_phase2_new);
			}

		} else if (isset($togglebtn)) {
			if (!is_array(config_get_path('ipsec/phase1/' . $togglebtn))) {
				/* no such entry */
				$save = 0;
			} else if (config_path_enabled('ipsec/phase1/' . $togglebtn, 'disabled')) {
				config_del_path('ipsec/phase1/' . $togglebtn . '/disabled');
			} else {
				if (ipsec_vti(config_get_path('ipsec/phase1/' . $togglebtn), false, false)) {
					/* disable all phase2 entries that match the ikeid */
					$ikeid = config_get_path('ipsec/phase1/' . $togglebtn . '/ikeid');
					$p1_has_vti = false;
					$disablep2ids = array();
					foreach (config_get_path('ipsec/phase2', []) as $p2index => $ph2tmp) {
						if ($ph2tmp['ikeid'] == $ikeid) {
							if (is_interface_ipsec_vti_assigned($ph2tmp)) {
								$p1_has_vti = true;
							} else {
								$disablep2ids[] = $p2index;
							}
						}
					}

					if ($p1_has_vti) {
						$input_errors[] = gettext("Cannot disable a Phase 1 which contains an active VTI Phase 2 with an interface assigned. Remove the interface assignment before deleting this P1.");
					} else {
						foreach ($disablep2ids as $dp2idx) {
							config_set_path('ipsec/phase2/' . $dp2idx . '/disabled', true);
						}
						config_set_path('ipsec/phase1/' . $togglebtn . '/disabled', true);
					}
				} else {
					config_set_path('ipsec/phase1/' . $togglebtn . '/disabled', true);
				}
			}
		} else if (isset($togglebtnp2)) {
			if (!is_array(config_get_path('ipsec/phase2/' . $togglebtnp2))) {
				/* no such entry */
				$save = 0;
			} else if (config_path_enabled('ipsec/phase2/' . $togglebtnp2,  'disabled')) {
				config_del_path('ipsec/phase2/' . $togglebtnp2 . '/disabled');
			} else {
				if (is_interface_ipsec_vti_assigned(config_get_path('ipsec/phase2/' . $togglebtnp2)) && (config_get_path('ipsec/phase2/' . $togglebtnp2 . '/mode') == 'vti')) {
					$input_errors[] = gettext("Cannot disable a VTI Phase 2 while the interface is assigned. Remove the interface assignment before disabling this P2.");
				} else {
					config_set_path('ipsec/phase2/' . $togglebtnp2 . '/disabled', true);
				}
			}
		} else if (isset($delbtn)) {
			$delcount = ipsec_delete_p1_entries([$delbtn], $input_errors);

			if ($delcount > 0) {
				/* Use a better description than generic save below */
				$save = 0;
				if (write_config(gettext("Deleted selected IPsec Phase 1 and related Phase 2 entries."))) {
					mark_subsystem_dirty('ipsec');
				}
			} else if (empty($input_errors)) {
				/* no such entry */
				$save = 0;
			}
		} else if (isset($delbtnp2)) {
			if (!is_array(config_get_path('ipsec/phase2/' . $delbtnp2))) {
				/* no such entry */
				$save = 0;
			} else if (is_interface_ipsec_vti_assigned(config_get_path('ipsec/phase2/' . $delbtnp2)) && (config_get_path('ipsec/phase2/' . $delbtnp2 . '/mode') == 'vti')) {
				$input_errors[] = gettext("Cannot delete a VTI Phase 2 while the interface is assigned. Remove the interface assignment before deleting this P2.");
			} else {
				config_del_path('ipsec/phase2/' . $delbtnp2);
				$items_deleted = true;
			}
		} else {
			$save = 0;
		}

		if (empty($input_errors) && ($save === 1)) {
			if (write_config(gettext("Saved configuration changes for IPsec tunnels."))) {
				mark_subsystem_dirty('ipsec');
			}
		}
	}

	return $input_errors;
}
