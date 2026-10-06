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
 * vpn_ipsec_phase1.php, vpn_ipsec_phase2.php, vpn_ipsec_mobile.php,
 * vpn_ipsec_keys.php, vpn_ipsec_keys_edit.php, vpn_ipsec_settings.php and the
 * REST API, moved out of the pages with the form fields as parameters.
 * Tunnels are stored in ipsec/phase1 (identified by ikeid) and ipsec/phase2
 * (identified by uniqid, linked to their phase 1 by ikeid), pre-shared keys in
 * ipsec/mobilekey, the mobile client settings in ipsec/client and the advanced
 * settings directly in ipsec. Tunnel, key and mobile client changes are staged
 * with the "ipsec" dirty flag until applied; the advanced settings are applied
 * at once (like their page).
 */

require_once("auth.inc");
require_once("certs.inc");
require_once("config.gui.inc");
require_once("filter.inc");
require_once("gwlb.inc");
require_once("interfaces.inc");
require_once("ipsec.inc");
require_once("system.inc");
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

/* ------------------------------------------------------------------ */
/* Phase 1                                                             */
/* ------------------------------------------------------------------ */

/*
 * The phase 1 edit page's form for the stored entry $p1 (null: a new entry,
 * a mobile one when $mobile is set). $dup: the form duplicates $p1 (its ikeid
 * is not copied).
 */
function ipsec_p1_form($p1, $dup = false, $mobile = false) {
	$pconfig = array();

	if ($p1) {
		// don't copy the ikeid on dup
		if (!$dup) {
			$pconfig['ikeid'] = $p1['ikeid'];
		}

		$pconfig['disabled'] = isset($p1['disabled']);

		if ($p1['interface']) {
			$pconfig['interface'] = $p1['interface'];
		} else {
			$pconfig['interface'] = "wan";
		}

		list($pconfig['remotenet'], $pconfig['remotebits']) = explode("/", $p1['remote-subnet']);

		if (isset($p1['mobile'])) {
			$pconfig['mobile'] = 'true';
		} else {
			$pconfig['remotegw'] = $p1['remote-gateway'];
			$pconfig['ikeport'] = $p1['ikeport'];
			$pconfig['nattport'] = $p1['nattport'];
		}

		if (empty($p1['iketype'])) {
			$pconfig['iketype'] = "ikev1";
		} else {
			$pconfig['iketype'] = $p1['iketype'];
		}
		$pconfig['mode'] = $p1['mode'];
		$pconfig['protocol'] = $p1['protocol'];
		$pconfig['myid_type'] = $p1['myid_type'];
		$pconfig['myid_data'] = $p1['myid_data'];
		$pconfig['peerid_type'] = $p1['peerid_type'];
		$pconfig['peerid_data'] = $p1['peerid_data'];
		$pconfig['encryption'] = $p1['encryption'];
		$pconfig['lifetime'] = $p1['lifetime'];
		$pconfig['rekey_time'] = $p1['rekey_time'];
		$pconfig['reauth_time'] = $p1['reauth_time'];
		$pconfig['rand_time'] = $p1['rand_time'];
		$pconfig['authentication_method'] = $p1['authentication_method'];

		if (($pconfig['authentication_method'] == "pre_shared_key") ||
		    ($pconfig['authentication_method'] == "xauth_psk_server")) {
			$pconfig['pskey'] = $p1['pre-shared-key'];
		} else {
			$pconfig['pkcs11certref'] = $p1['pkcs11certref'];
			$pconfig['pkcs11pin'] = $p1['pkcs11pin'];
			$pconfig['certref'] = $p1['certref'];
			$pconfig['caref'] = $p1['caref'];
		}

		$pconfig['descr'] = $p1['descr'];
		$pconfig['nat_traversal'] = $p1['nat_traversal'];
		$pconfig['mobike'] = $p1['mobike'];

		if (isset($p1['gw_duplicates'])) {
			$pconfig['gw_duplicates'] = true;
		}

		$pconfig['startaction'] = $p1['startaction'];
		$pconfig['closeaction'] = $p1['closeaction'];

		if ($p1['dpd_delay'] && $p1['dpd_maxfail']) {
			$pconfig['dpd_enable'] = true;
			$pconfig['dpd_delay'] = $p1['dpd_delay'];
			$pconfig['dpd_maxfail'] = $p1['dpd_maxfail'];
		}

		if (isset($p1['prfselect_enable'])) {
			$pconfig['prfselect_enable'] = 'yes';
		}

		if (isset($p1['splitconn'])) {
			$pconfig['splitconn'] = true;
		}

		if (isset($p1['tfc_enable'])) {
			$pconfig['tfc_enable'] = true;
		}

		if (isset($p1['tfc_bytes'])) {
			$pconfig['tfc_bytes'] = $p1['tfc_bytes'];
		}
	} else {
		/* defaults */
		$pconfig['interface'] = "wan";
		if (config_get_path('interfaces/lan')) {
			$pconfig['localnet'] = "lan";
		}
		$pconfig['mode'] = "main";
		$pconfig['protocol'] = "inet";
		$pconfig['myid_type'] = "myaddress";
		$pconfig['peerid_type'] = "peeraddress";
		$pconfig['authentication_method'] = "pre_shared_key";
		$pconfig['lifetime'] = "28800";
		$pconfig['nat_traversal'] = 'on';
		$pconfig['mobike'] = 'off';
		$pconfig['prfselect_enable'] = false;
		$pconfig['dpd_enable'] = true;
		$pconfig['iketype'] = "ikev2";

		/* mobile client */
		if ($mobile) {
			$pconfig['mobile'] = true;
			$pconfig['mode'] = "aggressive";
		}
	}
	// default value for new P1 and failsafe to always have at least 1 encryption item for the Form_ListItem

	if (count(array_get_path($pconfig, 'encryption/item', [])) == 0) {
		$item = array();
		$item['encryption-algorithm'] = array('name' => "aes", 'keylen' => 128);
		$item['hash-algorithm'] = "sha256";
		$item['prf-algorithm'] = "sha256";
		$item['dhgroup'] = "14";
		array_set_path($pconfig, 'encryption/item', [$item]);
	}

	return $pconfig;
}

/* The key lengths the phase 1/2 pages offer for an encryption algorithm ($algodata from $p1_ealgos or $p2_ealgos). */
function ipsec_ealgo_keylens(array $algodata) {
	$list = array();
	if (is_array($algodata['keysel'] ?? null)) {
		for ($keylen = $algodata['keysel']['hi']; $keylen >= $algodata['keysel']['lo']; $keylen -= $algodata['keysel']['step']) {
			$list[] = (string)$keylen;
		}
	}
	return $list;
}

/*
 * Validate and save the phase 1 edit form ($pconfig: the form post, changed
 * in place like the page did, so a refused form is shown again exactly as
 * before) and mark IPsec dirty. $p1index: the position of the entry being
 * edited (null: append a new entry); $old_ph1ent: the entry the form was
 * opened with (the edited or duplicated one), else null. On success
 * $pconfig['ikeid'] is the saved entry's ikeid. Returns the input errors.
 */
function ipsec_p1_save(array &$pconfig, $p1index, $old_ph1ent) {
	global $ipsec_startactions, $ipsec_closeactions, $p1_ealgos, $p1_halgos, $p1_dhgroups, $my_identifier_list,
	    $peer_identifier_list;

	$input_errors = array();
	$reqdfields = array();
	$reqdfieldsn = array();
	$vtidisablecheck = false;
	$vtigwcheck = false;

	$eitems = array_get_path($pconfig, 'encryption/item', []);
	for($i = 0; $i < 100; $i++) {
		if (isset($pconfig['ealgo_algo'.$i])) {
			$item = [];
			array_set_path($item, 'encryption-algorithm', []);
			array_set_path($item, 'encryption-algorithm/name', $pconfig['ealgo_algo'.$i]);
			array_set_path($item, 'encryption-algorithm/keylen', $pconfig['ealgo_keylen'.$i]);
			array_set_path($item, 'hash-algorithm', $pconfig['halgo'.$i]);
			array_set_path($item, 'prf-algorithm', $pconfig['prfalgo'.$i]);
			array_set_path($item, 'dhgroup', $pconfig['dhgroup'.$i]);
			$eitems[] = $item;
		}
	}
	array_set_path($pconfig, 'encryption/item', $eitems);

	/* input validation */

	if (!array_key_exists($pconfig['interface'], ipsec_p1_interface_list())) {
		$input_errors[] = gettext("Invalid interface.");
	}

	$method = $pconfig['authentication_method'];

	if (!array_key_exists((string)$method, ipsec_p1_auth_method_list(isset($pconfig['mobile'])))) {
		$input_errors[] = gettext("A valid authentication method must be selected.");
	}

	// Unset ca and cert if not required to avoid storing in config
	if ($method == "pre_shared_key" || $method == "xauth_psk_server") {
		unset($pconfig['certref']);
		unset($pconfig['pkcs11certref']);
	}

	if (!in_array($method, array('cert', 'eap-tls', 'xauth_cert_server', 'pkcs11'))) {
		unset($pconfig['caref']);
	}

	// Only require PSK here for normal PSK tunnels (not mobile) or xauth.
	// For certificate methods, require the CA/Cert.
	switch ($method) {
		case 'eap-mschapv2':
			if ($pconfig['iketype'] != 'ikev2') {
				$input_errors[] = gettext("EAP-MSChapv2 can only be used with IKEv2 type VPNs.");
			}
			break;
		case "eap-tls":
			if ($pconfig['iketype'] != 'ikev2') {
				$input_errors[] = gettext("EAP-TLS can only be used with IKEv2 type VPNs.");
			}
			break;
		case "eap-radius":
			if ($pconfig['iketype'] != 'ikev2') {
				$input_errors[] = gettext("EAP-RADIUS can only be used with IKEv2 type VPNs.");
			}
			break;
		case "pre_shared_key":
			// If this is a mobile PSK tunnel the user PSKs go on
			//	  the PSK tab, not here, so skip the check.
			if (isset($pconfig['mobile'])) {
				break;
			}
		case "xauth_psk_server":
			$reqdfields = explode(" ", "pskey");
			$reqdfieldsn = array(gettext("Pre-Shared Key"));
			$validate_pskey = true;
			break;
		case "xauth_cert_server":
		case "cert":
			$reqdfields = explode(" ", "caref certref");
			$reqdfieldsn = array(gettext("Certificate Authority"), gettext("Certificate"));
			break;
		case "pkcs11":
			$reqdfields = explode(" ", "caref pkcs11certref pkcs11pin");
			$reqdfieldsn = array(gettext("Certificate Authority"), gettext("Token Certificate"), gettext("Token PIN"));
			break;
		default:
			/* Other types do not use this validation mechanism. */
	}

	if (!isset($pconfig['mobile'])) {
		$reqdfields[] = "remotegw";
		$reqdfieldsn[] = gettext("Remote gateway");
	}

	do_input_validation($pconfig, $reqdfields, $reqdfieldsn, $input_errors);

	if (isset($validate_pskey) && isset($pconfig['pskey']) && !preg_match('/^[[:ascii:]]*$/', $pconfig['pskey'])) {
		unset($validate_pskey);
		$input_errors[] = gettext("Pre-Shared Key contains invalid characters.");
	}

	/* the page's selects: only their choices are accepted (anything was stored) */
	if (!in_array((string)$pconfig['protocol'], array('inet', 'inet6', 'both'), true)) {
		$input_errors[] = gettext("A valid Internet Protocol must be selected.");
	}

	if (($pconfig['iketype'] != 'ikev2') && !in_array((string)$pconfig['mode'], array('main', 'aggressive'), true)) {
		$input_errors[] = gettext("A valid negotiation mode must be selected.");
	}

	if (!array_key_exists((string)$pconfig['myid_type'], $my_identifier_list)) {
		$input_errors[] = gettext("A valid type must be selected for 'My identifier'.");
	}

	if (!array_key_exists((string)$pconfig['peerid_type'], $peer_identifier_list)) {
		$input_errors[] = gettext("A valid type must be selected for 'Peer identifier'.");
	}

	if (!in_array((string)$pconfig['nat_traversal'], array('on', 'force'), true)) {
		$input_errors[] = gettext("A valid NAT Traversal option must be selected.");
	}

	if (!in_array((string)$pconfig['mobike'], array('on', 'off'), true)) {
		$input_errors[] = gettext("A valid MOBIKE option must be selected.");
	}

	if (count($eitems) == 0) {
		$input_errors[] = gettext("At least one encryption algorithm must be selected.");
	}
	foreach ($eitems as $p1algo) {
		$algo = (string)array_get_path($p1algo, 'encryption-algorithm/name', '');
		if (!array_key_exists($algo, $p1_ealgos)) {
			$input_errors[] = gettext("A valid encryption algorithm must be selected.");
			break;
		}
		$keylens = ipsec_ealgo_keylens($p1_ealgos[$algo]);
		if (!empty($keylens) && !in_array((string)array_get_path($p1algo, 'encryption-algorithm/keylen', ''), $keylens, true)) {
			$input_errors[] = sprintf(gettext("A valid key length must be selected for encryption algorithm %s."), $p1_ealgos[$algo]['name']);
			break;
		}
		if (!array_key_exists((string)$p1algo['hash-algorithm'], $p1_halgos) || !array_key_exists((string)$p1algo['prf-algorithm'], $p1_halgos)) {
			$input_errors[] = gettext("A valid hash and PRF algorithm must be selected.");
			break;
		}
		if (!array_key_exists((string)$p1algo['dhgroup'], $p1_dhgroups)) {
			$input_errors[] = gettext("A valid DH group must be selected.");
			break;
		}
	}

	if (!empty($pconfig['lifetime'])) {
		if (!is_numericint($pconfig['lifetime'])) {
			$input_errors[] = gettext("Life Time must be an integer.");
		}
		if ((!empty($pconfig['rekey_time']) && ($pconfig['lifetime'] == $pconfig['rekey_time'])) ||
		    (!empty($pconfig['reauth_time']) && ($pconfig['lifetime'] == $pconfig['reauth_time']))) {
			$input_errors[] = gettext("Life Time cannot be set to the same value as Rekey Time or Reauth Time.");
		}
		if (max($pconfig['rekey_time'], $pconfig['reauth_time']) > $pconfig['lifetime']) {
			$input_errors[] = gettext("Life Time must be larger than Rekey Time and Reauth Time.");
		}
	}
	if (!empty($pconfig['rekey_time']) && !is_numericint($pconfig['rekey_time'])) {
		$input_errors[] = gettext("Rekey Time must be an integer.");
	}
	if (!empty($pconfig['reauth_time']) && !is_numericint($pconfig['reauth_time'])) {
		$input_errors[] = gettext("Reauth Time must be an integer.");
	}
	if (!empty($pconfig['rand_time']) && !is_numericint($pconfig['rand_time'])) {
		$input_errors[] = gettext("Rand Time must be an integer.");
	}

	if (!empty($pconfig['startaction']) && !array_key_exists($pconfig['startaction'], $ipsec_startactions)) {
		$input_errors[] = gettext("Invalid Child SA Start Action.");
	} elseif (isset($pconfig['mobile']) && !empty($pconfig['startaction'])) {
		/* Start action cannot be set for mobile tunnels */
		$input_errors[] = gettext("Child SA Start Action cannot be set for Mobile Phase 1 entries.");
	}

	if (!empty($pconfig['closeaction']) && !array_key_exists($pconfig['closeaction'], $ipsec_closeactions)) {
		$input_errors[] = gettext("Invalid Child SA Close Action.");
	}

	if ($pconfig['remotegw']) {
		if (!is_ipaddr($pconfig['remotegw']) && !is_domain($pconfig['remotegw'])) {
			$input_errors[] = gettext("A valid remote gateway address or host name must be specified.");
		} elseif (is_ipaddrv4($pconfig['remotegw']) && ($pconfig['protocol'] == "inet6")) {
			$input_errors[] = gettext("A valid remote gateway IPv4 address must be specified or protocol needs to be changed to IPv6");
		} elseif (is_ipaddrv6($pconfig['remotegw']) && ($pconfig['protocol'] == "inet")) {
			$input_errors[] = gettext("A valid remote gateway IPv6 address must be specified or protocol needs to be changed to IPv4");
		}
	}

	if ($pconfig['ikeport']) {
		if (!is_port($pconfig['ikeport'])) {
			$input_errors[] = gettext("The IKE port number is invalid.");
		}
	} else {
		unset($pconfig['ikeport']);
	}

	if ($pconfig['nattport']) {
		if (!is_port($pconfig['nattport'])) {
			$input_errors[] = gettext("The NAT-T port number is invalid.");
		}
	} else {
		unset($pconfig['nattport']);
	}

	if (isset($pconfig['ikeport']) && isset($pconfig['nattport']) && $pconfig['ikeport'] == $pconfig['nattport']) {
		$input_errors[] = gettext("IKE and NAT-T port numbers must be different.");
	}

	if ($pconfig['remotegw'] && !isset($pconfig['disabled'])) {
		foreach (config_get_path('ipsec/phase1', []) as $ph1tmp) {
			if ($pconfig['ikeid'] != $ph1tmp['ikeid']) {
				$tremotegw = $pconfig['remotegw'];
				if (($ph1tmp['remote-gateway'] == $tremotegw) && ($ph1tmp['remote-gateway'] != '0.0.0.0') &&
				    ($ph1tmp['remote-gateway'] != '::') && !isset($ph1tmp['disabled']) &&
				    (!isset($pconfig['gw_duplicates']) || !isset($ph1tmp['gw_duplicates']) ||
				    !is_ipaddr($tremotegw))) {
					$input_errors[] = sprintf(gettext('The remote gateway "%1$s" is already used by phase1 "%2$s".'), $tremotegw, $ph1tmp['descr']);
				}
			}
		}
	}

	if (($pconfig['remotegw'] == '0.0.0.0') || ($pconfig['remotegw'] == '::')) {
		if ($pconfig['startaction'] != 'none') {
			$input_errors[] = gettext('The remote gateway "0.0.0.0" or "::" address can only be used with a Child SA Start Action of "None (Responder Only)".');
		}
		if ($pconfig['peerid_type'] == "peeraddress") {
			$input_errors[] = gettext('The remote gateway "0.0.0.0" or "::" address can not be used with IP address peer identifier.');
		}

	}

	/* My identity */

	if ($pconfig['myid_type'] == "myaddress") {
		$pconfig['myid_data'] = "";
	}

	if ($pconfig['myid_type'] == "address" and $pconfig['myid_data'] == "") {
		$input_errors[] = gettext("Please enter an address for 'My Identifier'");
	}

	if ($pconfig['myid_type'] == "keyid tag" and $pconfig['myid_data'] == "") {
		$input_errors[] = gettext("Please enter a keyid tag for 'My Identifier'");
	}

	if ($pconfig['myid_type'] == "fqdn" and $pconfig['myid_data'] == "") {
		$input_errors[] = gettext("Please enter a fully qualified domain name for 'My Identifier'");
	}

	if ($pconfig['myid_type'] == "user_fqdn" and $pconfig['myid_data'] == "") {
		$input_errors[] = gettext("Please enter a user and fully qualified domain name for 'My Identifier'");
	}

	if ($pconfig['myid_type'] == "dyn_dns" and $pconfig['myid_data'] == "") {
		$input_errors[] = gettext("Please enter a dynamic domain name for 'My Identifier'");
	}

	if (($pconfig['myid_type'] == "address") && !is_ipaddr($pconfig['myid_data'])) {
		$input_errors[] = gettext("A valid IP address for 'My identifier' must be specified.");
	}

	if (($pconfig['myid_type'] == "fqdn") && !is_domain($pconfig['myid_data'])) {
		$input_errors[] = gettext("A valid domain name for 'My identifier' must be specified.");
	}

	if ($pconfig['myid_type'] == "fqdn") {
		if (is_domain($pconfig['myid_data']) == false) {
			$input_errors[] = gettext("A valid FQDN for 'My identifier' must be specified.");
		}
	}

	if ($pconfig['myid_type'] == "user_fqdn") {
		$user_fqdn = explode("@", $pconfig['myid_data']);
		if (is_domain($user_fqdn[1]) == false) {
			$input_errors[] = gettext("A valid User FQDN in the form of user@my.domain.com for 'My identifier' must be specified.");
		}
	}

	if ($pconfig['myid_type'] == "dyn_dns") {
		if (is_domain($pconfig['myid_data']) == false) {
			$input_errors[] = gettext("A valid Dynamic DNS address for 'My identifier' must be specified.");
		}
	}

	/* Peer identity */

	/* the peer address needs no identifier data (this tested myid_type, which is never "peeraddress") */
	if ($pconfig['peerid_type'] == "peeraddress") {
		$pconfig['peerid_data'] = "";
	}

	// Only enforce peer ID if we are not dealing with a pure-psk mobile config.
	if (!(($pconfig['authentication_method'] == "pre_shared_key") && (isset($pconfig['mobile'])))) {
		if ($pconfig['peerid_type'] == "address" and $pconfig['peerid_data'] == "") {
			$input_errors[] = gettext("Please enter an address for 'Peer Identifier'");
		}

		if ($pconfig['peerid_type'] == "keyid tag" and $pconfig['peerid_data'] == "") {
			$input_errors[] = gettext("Please enter a keyid tag for 'Peer Identifier'");
		}

		if ($pconfig['peerid_type'] == "fqdn" and $pconfig['peerid_data'] == "") {
			$input_errors[] = gettext("Please enter a fully qualified domain name for 'Peer Identifier'");
		}

		if ($pconfig['peerid_type'] == "user_fqdn" and $pconfig['peerid_data'] == "") {
			$input_errors[] = gettext("Please enter a user and fully qualified domain name for 'Peer Identifier'");
		}

		if ((($pconfig['peerid_type'] == "address") && !is_ipaddr($pconfig['peerid_data']))) {
			$input_errors[] = gettext("A valid IP address for 'Peer identifier' must be specified.");
		}

		if ((($pconfig['peerid_type'] == "fqdn") && !is_domain($pconfig['peerid_data']))) {
			$input_errors[] = gettext("A valid domain name for 'Peer identifier' must be specified.");
		}

		if ($pconfig['peerid_type'] == "fqdn") {
			if (is_domain($pconfig['peerid_data']) == false) {
				$input_errors[] = gettext("A valid FQDN for 'Peer identifier' must be specified.");
			}
		}

		if ($pconfig['peerid_type'] == "user_fqdn") {
			$user_fqdn = explode("@", $pconfig['peerid_data']);
			if (is_domain($user_fqdn[1]) == false) {
				$input_errors[] = gettext("A valid User FQDN in the form of user@my.domain.com for 'Peer identifier' must be specified.");
			}
		}
	}

	if ($pconfig['dpd_enable']) {
		if (!is_numericint($pconfig['dpd_delay'])) {
			$input_errors[] = gettext("A numeric value must be specified for DPD delay.");
		}

		if (!is_numericint($pconfig['dpd_maxfail'])) {
			$input_errors[] = gettext("A numeric value must be specified for DPD retries.");
		}
	}

	if ($pconfig['tfc_bytes'] && !is_numericint($pconfig['tfc_bytes'])) {
		$input_errors[] = gettext("A numeric value must be specified for TFC bytes.");
	}

	if (!empty($pconfig['iketype']) && $pconfig['iketype'] != "ikev1" && $pconfig['iketype'] != "ikev2" && $pconfig['iketype'] != "auto") {
		$input_errors[] = gettext("Valid arguments for IKE type are v1, v2 or auto");
	}

	foreach(array_get_path($pconfig, 'encryption/item', []) as $p1algo) {
		if (preg_match("/aes\d+gcm/", array_get_path($p1algo, 'encryption-algorithm/name', '')) && $pconfig['iketype'] != "ikev2") {
			$input_errors[] = gettext("Encryption Algorithm AES-GCM can only be used with IKEv2");
		}
	}
	/* auth backend for mobile eap-radius VPNs should be a RADIUS server */
	if (($pconfig['authentication_method'] == 'eap-radius') && isset($pconfig['mobile'])) {
		if (!empty(config_get_path('ipsec/client/user_source'))) {
			$auth_server_list  = explode(',', config_get_path('ipsec/client/user_source'));
			foreach ($auth_server_list as $auth_server_name) {
				$auth_server       = auth_get_authserver($auth_server_name);
				if (!is_array($auth_server) || ($auth_server['type'] != 'radius')) {
					$input_errors[] = gettext("A valid RADIUS server must be selected for user authentication on the Mobile Clients tab in order to set EAP-RADIUS as the authentication method.");
				}
			}
		}
	}
	if (is_array($old_ph1ent) && ipsec_vti($old_ph1ent, false, false)) {
		foreach (config_get_path('ipsec/phase2', []) as $ph2tmp) {
			if ($ph2tmp['ikeid'] == $old_ph1ent['ikeid']) {
				if (!$vtidisablecheck && $pconfig['disabled'] &&
				    is_interface_ipsec_vti_assigned($ph2tmp)) {
					$input_errors[] = gettext("Cannot disable a Phase 1 with a child Phase 2 while the interface is assigned. Remove the interface assignment before disabling this P2.");
					$vtidisablecheck = true;
				}
				if (!$vtigwcheck && (($pconfig['remotegw'] == '0.0.0.0') ||
				    (is_ipaddrv6($pconfig['remotegw']) && (text_to_compressed_ip6($pconfig['remotegw']) == '::')))) {
					$input_errors[] = gettext("A remote gateway address of \"0.0.0.0\" or \"::\" is not compatible with a child Phase 2 in VTI mode.");
					$vtigwcheck = true;
				}
			}
		}
	}

	if (!empty($pconfig['certref'])) {
		$errchkcert = lookup_cert($pconfig['certref']);
		$errchkcert = $errchkcert['item'];
		if (is_array($errchkcert)) {
			if (!cert_check_pkey_compatibility($errchkcert['prv'], 'IPsec')) {
				$input_errors[] = gettext("The selected ECDSA certificate does not use a curve compatible with IKEv2");
			}
			$cert_sans = cert_get_sans($errchkcert['crt']);
			if ((count($cert_sans) > 0) &&
			    (count($cert_sans) == count(preg_grep('/\*/', $cert_sans)))) {
				$input_errors[] = gettext("The selected certificate only contains wildcard SAN entries, which are not supported. The certificate must contain at least one non-wildcard SAN.");
			}
			$purpose = cert_get_purpose($errchkcert['crt']);
			if (isset($pconfig['mobile']) && ($purpose['server'] == 'No')) {
				$input_errors[] = gettext("The selected certificate must be a Server Certificate for Mobile IPsec mode.");
			}
		}
	}

	if (!$input_errors) {
		$ph1ent = array();
		$ph1ent['ikeid'] = $pconfig['ikeid'];
		$ph1ent['iketype'] = $pconfig['iketype'];
		if ($pconfig['iketype'] == 'ikev2') {
			unset($ph1ent['mode']);
		} else {
			$ph1ent['mode'] = $pconfig['mode'];
		}

		$ph1ent['disabled'] = $pconfig['disabled'] ? true : false;
		$ph1ent['interface'] = $pconfig['interface'];
		/* if the remote gateway changed and the interface is not WAN then remove route */
		/* the ipsec_configure() handles adding the route */
		if ($pconfig['interface'] <> "wan") {
			if ($old_ph1ent['remote-gateway'] <> $pconfig['remotegw']) {
				route_del($old_ph1ent['remote-gateway']);
			}
		}

		if (isset($pconfig['mobile'])) {
			$ph1ent['mobile'] = true;
		} else {
			$ph1ent['remote-gateway'] = $pconfig['remotegw'];
			if ( !empty($pconfig['ikeport']) ) {
				$ph1ent['ikeport'] = $pconfig['ikeport'];
			} else {
				unset($ph1ent['ikeport']);
			}
			if ( !empty($pconfig['nattport']) ) {
				$ph1ent['nattport'] = $pconfig['nattport'];
			} else {
				unset($ph1ent['nattport']);
			}
		}

		$ph1ent['protocol'] = $pconfig['protocol'];

		$ph1ent['myid_type'] = $pconfig['myid_type'];
		$ph1ent['myid_data'] = $pconfig['myid_data'];
		$ph1ent['peerid_type'] = $pconfig['peerid_type'];
		$ph1ent['peerid_data'] = $pconfig['peerid_data'];

		$ph1ent['encryption'] = $pconfig['encryption'];
		$ph1ent['lifetime'] = $pconfig['lifetime'];
		$ph1ent['rekey_time'] = $pconfig['rekey_time'];
		$ph1ent['reauth_time'] = $pconfig['reauth_time'];
		$ph1ent['rand_time'] = $pconfig['rand_time'];
		$ph1ent['pre-shared-key'] = $pconfig['pskey'];
		$ph1ent['private-key'] = base64_encode($pconfig['privatekey']);
		$ph1ent['certref'] = $pconfig['certref'];
		$ph1ent['pkcs11certref'] = $pconfig['pkcs11certref'];
		$ph1ent['pkcs11pin'] = $pconfig['pkcs11pin'];
		$ph1ent['caref'] = $pconfig['caref'];
		$ph1ent['authentication_method'] = $pconfig['authentication_method'];
		$ph1ent['descr'] = $pconfig['descr'];
		$ph1ent['nat_traversal'] = $pconfig['nat_traversal'];
		$ph1ent['mobike'] = $pconfig['mobike'];

		if ( isset($pconfig['gw_duplicates'])) {
			$ph1ent['gw_duplicates'] = true;
		} else {
			unset($ph1ent['gw_duplicates']);
		}

		$ph1ent['startaction'] = $pconfig['startaction'];
		$ph1ent['closeaction'] = $pconfig['closeaction'];

		if (isset($pconfig['prfselect_enable'])) {
			$ph1ent['prfselect_enable'] = 'yes';
		} else {
			unset($ph1ent['prfselect_enable']);
		}

		if (isset($pconfig['dpd_enable'])) {
			$ph1ent['dpd_delay'] = $pconfig['dpd_delay'];
			$ph1ent['dpd_maxfail'] = $pconfig['dpd_maxfail'];
		}

		if (isset($pconfig['splitconn'])) {
			$ph1ent['splitconn'] = true;
		} else {
			unset($ph1ent['splitconn']);
		}

		if (isset($pconfig['tfc_enable'])) {
			$ph1ent['tfc_enable'] = true;
		}

		if (isset($pconfig['tfc_bytes'])) {
			$ph1ent['tfc_bytes'] = $pconfig['tfc_bytes'];
		}

		/* generate unique phase1 ikeid */
		if (empty($ph1ent['ikeid'])) {
			$ph1ent['ikeid'] = ipsec_ikeid_next();
		}

		if ($p1index !== null) {
			config_set_path('ipsec/phase1/' . $p1index, $ph1ent);
		} else {
			config_set_path('ipsec/phase1/', $ph1ent);
		}

		write_config(gettext("Saved IPsec tunnel Phase 1 configuration."));
		mark_subsystem_dirty('ipsec');
		$pconfig['ikeid'] = $ph1ent['ikeid'];
	}

	return $input_errors;
}

/* The interfaces the phase 1 page offers (was the page's build_interface_list()). */
function ipsec_p1_interface_list() {
	$interfaces = get_configured_interface_with_descr();

	$viplist = get_configured_vip_list();
	foreach ($viplist as $vip => $address) {
		$interfaces[$vip] = $address;
		if (get_vip_descr($address)) {
			$interfaces[$vip] .= " (". get_vip_descr($address) .")";
		}
	}

	$grouplist = return_gateway_groups_array();

	foreach ($grouplist as $name => $group) {
		if ($group[0]['vip'] != "") {
			$vipif = $group[0]['vip'];
		} else {
			$vipif = $group[0]['int'];
		}

		$interfaces[$name] = sprintf(gettext("GW Group %s"), $name);
	}

	return($interfaces);

}

/* The authentication methods the phase 1 page offers for a mobile or a site-to-site entry (was build_auth_method_list()). */
function ipsec_p1_auth_method_list($mobile) {
	global $p1_authentication_methods;

	$list = array();

	foreach ($p1_authentication_methods as $method_type => $method_params) {
		if (!$mobile && $method_params['mobile']) {
			continue;
		}
		if (!config_path_enabled('ipsec', 'pkcs11support') &&
		    ($method_type == 'pkcs11')) {
			continue;
		}

		$list[$method_type] = htmlspecialchars($method_params['name']);
	}

	return($list);
}

/* The "My identifier" types (was the page's build_myid_list()). */
function ipsec_p1_myid_list() {
	global $my_identifier_list;

	$list = array();

	foreach ($my_identifier_list as $id_type => $id_params) {
		$list[$id_type] = htmlspecialchars($id_params['desc']);
	}

	return($list);
}

/* The "Peer identifier" types (was the page's build_peerid_list()). */
function ipsec_p1_peerid_list() {
	global $peer_identifier_list;

	$list = array();

	foreach ($peer_identifier_list as $id_type => $id_params) {
		$list[$id_type] = htmlspecialchars($id_params['desc']);
	}

	return($list);
}

/* The certificates on an attached PKCS#11 token (was the page's build_pkcs11cert_list()). */
function ipsec_p1_pkcs11cert_list() {
	$list = array();
	$p11_cn = array();
	$p11_id = array();
	$output = shell_exec('/usr/local/bin/pkcs15-tool -c');

	preg_match_all('/X\.509\ Certificate\ \[(.*)\]/', $output, $p11_cn);
	preg_match_all('/ID\s+: (.*)/', $output, $p11_id);

	if (is_array($p11_id)) {
		for ($i = 0; $i < count($p11_id[1]); $i++) {
			$list[$p11_id[1][$i]] = "{$p11_cn[1][$i]} " . "({$p11_id[1][$i]})";
		}
	}
	return($list);
}

/* The phase 1 encryption algorithms (was the page's build_eal_list()). */
function ipsec_p1_eal_list() {
	global $p1_ealgos;

	$list = array();

	if (is_array($p1_ealgos)) {
		foreach ($p1_ealgos as $algo => $algodata) {
			$list[$algo] = htmlspecialchars($algodata['name']);
		}
	}

	return($list);
}

/* ------------------------------------------------------------------ */
/* Phase 2                                                             */
/* ------------------------------------------------------------------ */

/* The local network types the phase 2 page offers. */
function ipsec_p2_localid_types() {
	return get_specialnet('', [SPECIALNET_ADDR, SPECIALNET_NET, SPECIALNET_IFSUB]);
}

/* The NAT/BINAT translation types the phase 2 page offers. */
function ipsec_p2_natlocalid_types() {
	return get_specialnet('', [SPECIALNET_NONE, SPECIALNET_ADDR, SPECIALNET_NET]);
}

/* The position of the phase 2 entry with uniqid $uniqid, or null. */
function ipsec_p2_index($uniqid) {
	foreach (config_get_path('ipsec/phase2', []) as $idx => $ph2) {
		if ($ph2['uniqid'] == $uniqid) {
			return $idx;
		}
	}
	return null;
}

/*
 * The phase 2 edit page's form for the stored entry $ph2 (null: a new entry
 * of the phase 1 $ikeid, a mobile one when $mobile is set).
 */
function ipsec_p2_form($ph2, $ikeid = null, $mobile = false) {
	$pconfig = array();

	if ($ph2 !== null) {
		$pconfig['ikeid'] = $ph2['ikeid'];
		$pconfig['disabled'] = isset($ph2['disabled']);
		$pconfig['mode'] = $ph2['mode'];
		$pconfig['descr'] = $ph2['descr'];
		$pconfig['uniqid'] = $ph2['uniqid'];

		if (!empty($ph2['natlocalid'])) {
			ipsec_p2_idinfo_to_pconfig("natlocal", $ph2['natlocalid'], $pconfig);
		}
		ipsec_p2_idinfo_to_pconfig("local", $ph2['localid'], $pconfig);
		ipsec_p2_idinfo_to_pconfig("remote", $ph2['remoteid'], $pconfig);

		$pconfig['proto'] = $ph2['protocol'];
		ipsec_p2_ealgos_to_pconfig($ph2['encryption-algorithm-option'], $pconfig);
		$pconfig['halgos'] = $ph2['hash-algorithm-option'];
		$pconfig['pfsgroup'] = $ph2['pfsgroup'];
		$pconfig['lifetime'] = $ph2['lifetime'];
		$pconfig['rekey_time'] = $ph2['rekey_time'];
		$pconfig['rand_time'] = $ph2['rand_time'];
		$pconfig['pinghost'] = $ph2['pinghost'];
		$pconfig['keepalive'] = ($ph2['keepalive'] == 'enabled');
		$pconfig['reqid'] = $ph2['reqid'];

		if (isset($ph2['mobile'])) {
			$pconfig['mobile'] = true;
			$pconfig['remoteid_type'] = "mobile";
		}
	} else {
		$pconfig['ikeid'] = $ikeid;

		/* defaults */
		$pconfig['localid_type'] = "lan";
		$pconfig['remoteid_type'] = "network";
		$pconfig['proto'] = "esp";
		$pconfig['ealgos'] = explode(",", "aes,aes128gcm");
		$pconfig['keylen_aes'] = 128;
		$pconfig['keylen_aes128gcm'] = 128;
		$pconfig['halgos'] = explode(",", "hmac_sha256");
		$pconfig['pfsgroup'] = "14";
		$pconfig['lifetime'] = "3600";
		$pconfig['uniqid'] = uniqid();

		/* mobile client */
		if ($mobile) {
			$pconfig['mobile']=true;
			$pconfig['remoteid_type'] = "mobile";
		}
	}

	return $pconfig;
}

/*
 * Validate and save the phase 2 edit form ($pconfig: the form post, changed in
 * place like the page did) and mark IPsec dirty. $p2index: the position of the
 * entry being edited (null: append a new entry). Returns the input errors.
 */
function ipsec_p2_save(array &$pconfig, $p2index) {
	global $p2_modes, $p2_protos, $p2_ealgos, $p2_halgos, $p2_pfskeygroups;

	$input_errors = array();

	/* Check if the user is switching away from VTI */
	$vti_switched = (($p2index !== null) && (config_get_path('ipsec/phase2/' . $p2index . '/mode') == "vti") && ($pconfig['mode'] != "vti"));

	if (isset($pconfig['mobile'])) {
		$pconfig['remoteid_type'] = 'mobile';
	}

	if (!isset($pconfig['ikeid'])) {
		$input_errors[] = gettext("A valid ikeid must be specified.");
	} elseif (!is_array(ipsec_get_phase1($pconfig['ikeid']))) {
		/* a phase 2 of a missing phase 1 was stored */
		$input_errors[] = gettext("A valid ikeid must be specified.");
	}

	/* input validation */
	$reqdfields = explode(" ", "localid_type uniqid");
	$reqdfieldsn = array(gettext("Local network type"), gettext("Unique Identifier"));
	if (!isset($pconfig['mobile'])) {
		$reqdfields[] = "remoteid_type";
		$reqdfieldsn[] = gettext("Remote network type");
	}

	do_input_validation($pconfig, $reqdfields, $reqdfieldsn, $input_errors);

	/* the page's selects: only their choices are accepted (anything was stored) */
	if (!array_key_exists((string)$pconfig['mode'], $p2_modes)) {
		$input_errors[] = gettext("A valid mode must be selected.");
	}
	if (!array_key_exists((string)$pconfig['proto'], $p2_protos)) {
		$input_errors[] = gettext("A valid protocol must be selected.");
	}
	if (($pconfig['mode'] == "tunnel") || ($pconfig['mode'] == "tunnel6") || ($pconfig['mode'] == "vti")) {
		if (!empty($pconfig['localid_type']) && !array_key_exists((string)$pconfig['localid_type'], ipsec_p2_localid_types())) {
			$input_errors[] = gettext("A valid local network type must be selected.");
		}
		if (!empty($pconfig['natlocalid_address']) && !array_key_exists((string)$pconfig['natlocalid_type'], ipsec_p2_natlocalid_types())) {
			$input_errors[] = gettext("A valid NAT/BINAT translation type must be selected.");
		}
		if (!isset($pconfig['mobile']) && !empty($pconfig['remoteid_type']) &&
		    !in_array((string)$pconfig['remoteid_type'], array('address', 'network'), true)) {
			$input_errors[] = gettext("A valid remote network type must be selected.");
		}
	}
	if (isset($pconfig['ealgos']) && is_array($pconfig['ealgos'])) {
		foreach ($pconfig['ealgos'] as $algo) {
			if (!array_key_exists((string)$algo, $p2_ealgos)) {
				$input_errors[] = gettext("A valid encryption algorithm must be selected.");
				break;
			}
			$keylens = ipsec_ealgo_keylens($p2_ealgos[$algo]);
			if (!empty($keylens) && !in_array((string)$pconfig['keylen_' . $algo], array_merge(array('auto'), $keylens), true)) {
				$input_errors[] = sprintf(gettext("A valid key length must be selected for encryption algorithm %s."), $p2_ealgos[$algo]['name']);
				break;
			}
		}
	}
	if (!empty($pconfig['halgos'])) {
		foreach ((array)$pconfig['halgos'] as $algo) {
			if (!array_key_exists((string)$algo, $p2_halgos)) {
				$input_errors[] = gettext("A valid hash algorithm must be selected.");
				break;
			}
		}
	}
	if (isset($pconfig['pfsgroup']) && ((string)$pconfig['pfsgroup'] !== '') && !array_key_exists((string)$pconfig['pfsgroup'], $p2_pfskeygroups)) {
		$input_errors[] = gettext("A valid PFS key group must be selected.");
	}

	if (($pconfig['mode'] == "tunnel") || ($pconfig['mode'] == "tunnel6") || ($pconfig['mode'] == "vti")) {
		switch ($pconfig['localid_type']) {
			case "network":
				if (($pconfig['localid_netbits'] != 0 && !$pconfig['localid_netbits']) || !is_numericint($pconfig['localid_netbits'])) {
					$input_errors[] = gettext("A valid local network bit count must be specified.");
				}
			case "address":
				if (!$pconfig['localid_address'] || !is_ipaddr($pconfig['localid_address'])) {
					$input_errors[] = gettext("A valid local network IP address must be specified.");
				} elseif ($pconfig['mode'] == "vti") {
					if (!is_ipaddr($pconfig['localid_address'])) {
						$input_errors[] = gettext("VTI requires a valid local address");
					}
				} elseif (is_ipaddrv4($pconfig['localid_address']) && ($pconfig['mode'] != "tunnel")) {
					$input_errors[] = gettext("An IPv4 local address was specified but the mode is not set to tunnel");
				} elseif (is_ipaddrv6($pconfig['localid_address']) && ($pconfig['mode'] != "tunnel6")) {
					$input_errors[] = gettext("An IPv6 local address was specified but the mode is not set to tunnel6");
				}
				break;
			default:
				if (($pconfig['mode'] == "vti") && !is_ipaddr($pconfig['localid_address'])) {
					$input_errors[] = gettext("VTI requires a valid local network or IP address for its endpoint address, it cannot use a network macro for a different interface (e.g. LAN).");
				}

		}
		/* Check if the localid_type is an interface, to confirm if it has a valid subnet. */
		if (is_array(config_get_path('interfaces/' . $pconfig['localid_type']))) {
			// Don't let an empty subnet into racoon.conf, it can cause parse errors. Ticket #2201.
			if ($pconfig['mode'] == 'tunnel6') {
				$address = get_interface_ipv6($pconfig['localid_type']);
				$netbits = get_interface_subnetv6($pconfig['localid_type']);
			} else {
				$address = get_interface_ip($pconfig['localid_type']);
				$netbits = get_interface_subnet($pconfig['localid_type']);
			}

			if (empty($address) || empty($netbits)) {
				$input_errors[] = gettext("Invalid Local Network.") . " " . sprintf(gettext("%s has no subnet."), convert_friendly_interface_to_friendly_descr($pconfig['localid_type']));
			}
		}

		if (!empty($pconfig['natlocalid_address'])) {
			switch ($pconfig['natlocalid_type']) {
				case "network":
					if (($pconfig['natlocalid_netbits'] != 0 && !$pconfig['natlocalid_netbits']) || !is_numericint($pconfig['natlocalid_netbits'])) {
						$input_errors[] = gettext("A valid NAT local network bit count must be specified.");
					}
					if ($pconfig['localid_type'] == "address") {
						$input_errors[] = gettext("A network type address cannot be configured for NAT while only an address type is selected for local source.");
					}
					if (((($pconfig['mode'] == "tunnel") && ($pconfig['natlocalid_netbits'] != 32)) ||
					    (($pconfig['mode'] == "tunnel6") && ($pconfig['natlocalid_netbits'] != 128))) &&
					    ((is_numeric($pconfig['localid_netbits']) &&
					    ($pconfig['natlocalid_netbits'] != $pconfig['localid_netbits'])) ||
					    (is_numeric($netbits) &&
					    ($pconfig['natlocalid_netbits'] != $netbits)))) {
						$input_errors[] = gettext("Local network subnet size and NAT local network subnet size cannot be different.");
					}
				case "address":
					if (!empty($pconfig['natlocalid_address']) && !is_ipaddr($pconfig['natlocalid_address'])) {
						$input_errors[] = gettext("A valid NAT local network IP address must be specified.");
					} elseif (is_ipaddrv4($pconfig['natlocalid_address']) && ($pconfig['mode'] != "tunnel")) {
						$input_errors[] = gettext("A valid NAT local network IPv4 address must be specified or Mode needs to be changed to IPv6");
					} elseif (is_ipaddrv6($pconfig['natlocalid_address']) && ($pconfig['mode'] != "tunnel6")) {
						$input_errors[] = gettext("A valid NAT local network IPv6 address must be specified or Mode needs to be changed to IPv4");
					}
					break;
			}

			if (is_array(config_get_path('interfaces/' . $pconfig['natlocalid_type']))) {
				// Don't let an empty subnet into racoon.conf, it can cause parse errors. Ticket #2201.
				$address = get_interface_ip($pconfig['natlocalid_type']);
				$netbits = get_interface_subnet($pconfig['natlocalid_type']);

				if (empty($address) || empty($netbits)) {
					$input_errors[] = gettext("Invalid Local Network.") . " " . sprintf(gettext("%s has no subnet."), convert_friendly_interface_to_friendly_descr($pconfig['natlocalid_type']));
				}
			}
		}

		switch ($pconfig['remoteid_type']) {
			case "network":
				if (($pconfig['remoteid_netbits'] != 0 && !$pconfig['remoteid_netbits']) || !is_numericint($pconfig['remoteid_netbits'])) {
					$input_errors[] = gettext("A valid remote network bit count must be specified.");
				}
			case "address":
				if (!$pconfig['remoteid_address'] || !is_ipaddr($pconfig['remoteid_address'])) {
					$input_errors[] = gettext("A valid remote network IP address must be specified.");
				} elseif ($pconfig['mode'] == "vti") {
					if (!is_ipaddr($pconfig['remoteid_address'])) {
						$input_errors[] = gettext("VTI requires a valid remote address");
					}
				} elseif (is_ipaddrv4($pconfig['remoteid_address']) && ($pconfig['mode'] != "tunnel")) {
					$input_errors[] = gettext("An IPv4 remote network was specified but the mode is not set to tunnel");
				} elseif (is_ipaddrv6($pconfig['remoteid_address']) && ($pconfig['mode'] != "tunnel6")) {
					$input_errors[] = gettext("An IPv6 remote network was specified but the mode is not set to tunnel6");
				}
				break;
		}
	}
	/* Validate enabled phase2's are not duplicates */
	if (isset($pconfig['mobile'])) {
		/* User is adding phase 2 for mobile phase1 */
		if ($pconfig['mode'] == "vti") {
			$input_errors[] = gettext("VTI is not compatible with mobile IPsec.");
		}

		foreach (config_get_path('ipsec/phase2', []) as $name) {
			if (isset($name['mobile']) && $name['uniqid'] != $pconfig['uniqid']) {
				/* check duplicate localids only for mobile clients */
				$localid_data = ipsec_idinfo_to_cidr($name['localid'], false, $name['mode']);
				$entered = array();
				$entered['type'] = $pconfig['localid_type'];

				if (isset($pconfig['localid_address'])) {
					$entered['address'] = $pconfig['localid_address'];
				}

				if (isset($pconfig['localid_netbits'])) {
					$entered['netbits'] = $pconfig['localid_netbits'];
				}

				$entered_localid_data = ipsec_idinfo_to_cidr($entered, false, $pconfig['mode']);
				if ($localid_data == $entered_localid_data) {
					/* adding new p2 entry */
					$input_errors[] = gettext("Phase2 with this Local Network is already defined for mobile clients.");
					break;
				}
			}
		}
	} else {
		/* User is adding phase 2 for site-to-site phase1 */
		$input_error = 0;
		foreach (config_get_path('ipsec/phase2', []) as $name) {
			if (!isset($name['mobile']) && $pconfig['ikeid'] == $name['ikeid'] && $pconfig['uniqid'] != $name['uniqid']) {
				/* check duplicate subnets only for given phase1 */
				$localid_data = ipsec_idinfo_to_cidr($name['localid'], false, $name['mode']);
				$remoteid_data = ipsec_idinfo_to_cidr($name['remoteid'], false, $name['mode']);
				$entered_local = array();
				$entered_local['type'] = $pconfig['localid_type'];
				if (isset($pconfig['localid_address'])) {
					$entered_local['address'] = $pconfig['localid_address'];
				}
				if (isset($pconfig['localid_netbits'])) {
					$entered_local['netbits'] = $pconfig['localid_netbits'];
				}
				$entered_localid_data = ipsec_idinfo_to_cidr($entered_local, false, $pconfig['mode']);
				$entered_remote = array();
				$entered_remote['type'] = $pconfig['remoteid_type'];
				if (isset($pconfig['remoteid_address'])) {
					$entered_remote['address'] = $pconfig['remoteid_address'];
				}
				if (isset($pconfig['remoteid_netbits'])) {
					$entered_remote['netbits'] = $pconfig['remoteid_netbits'];
				}
				$entered_remoteid_data = ipsec_idinfo_to_cidr($entered_remote, false, $pconfig['mode']);
				if ($localid_data == $entered_localid_data && $remoteid_data == $entered_remoteid_data) {
					/* adding new p2 entry */
					$input_errors[] = gettext("Phase2 with this Local/Remote networks combination is already defined for this Phase1.");
					break;
				}
			}
		}
		foreach (config_get_path('ipsec/phase1', []) as $phase1) {
			if ($phase1['ikeid'] == $pconfig['ikeid']) {
				/* This is the P1 for this entry */
				if ($vti_switched) {
					/* Determine what this P2 interface would be (this looked up the uniqid as a position) */
					if (is_interface_ipsec_vti_assigned(config_get_path('ipsec/phase2/' . $p2index))) {
						$input_errors[] = gettext("Cannot switch away from VTI while the interface is assigned. Remove the interface assignment before switching away from VTI.");
					}
				}
				/* validate its remote-gateway and local interface isn't within tunnel */
				$entered_local = array();
				$entered_local['type'] = $pconfig['localid_type'];
				if (isset($pconfig['localid_address'])) {
					$entered_local['address'] = $pconfig['localid_address'];
				}
				if (isset($pconfig['localid_netbits'])) {
					$entered_local['netbits'] = $pconfig['localid_netbits'];
				}
				$entered_localid_data = ipsec_idinfo_to_cidr($entered_local, false, $pconfig['mode']);
				list($entered_local_network, $entered_local_mask) = explode('/', $entered_localid_data);
				$entered_remote = array();
				$entered_remote['type'] = $pconfig['remoteid_type'];
				if (isset($pconfig['remoteid_address'])) {
					$entered_remote['address'] = $pconfig['remoteid_address'];
				}
				if (isset($pconfig['remoteid_netbits'])) {
					$entered_remote['netbits'] = $pconfig['remoteid_netbits'];
				}
				$entered_remoteid_data = ipsec_idinfo_to_cidr($entered_remote, false, $pconfig['mode']);
				list($entered_remote_network, $entered_remote_mask) = explode('/', $entered_remoteid_data);
				if ($phase1['protocol'] == "inet6") {
					$if = get_failover_interface($phase1['interface'], "inet6");
					$interfaceip = get_interface_ipv6($if);
				} else {
					$if = get_failover_interface($phase1['interface']);
					$interfaceip = get_interface_ip($if);
				}
				/* skip validation for hostnames, they're subject to change anyway */
				if (is_ipaddr($phase1['remote-gateway'])) {
					if ($pconfig['mode'] == "tunnel") {
						if (check_subnets_overlap($interfaceip, 32, $entered_local_network, $entered_local_mask) && check_subnets_overlap($phase1['remote-gateway'], 32, $entered_remote_network, $entered_remote_mask)) {
							$input_errors[] = gettext("The local and remote networks of a phase 2 entry cannot overlap the outside of the tunnel (interface and remote gateway) configured in its phase 1.");
							break;
						}
					} elseif ($pconfig['mode'] == "tunnel6") {
						if (check_subnetsv6_overlap($interfaceip, 128, $entered_local_network, $entered_local_mask) && check_subnets_overlap($phase1['remote-gateway'], 128, $entered_remote_network, $entered_remote_mask)) {
							$input_errors[] = gettext("The local and remote networks of a phase 2 entry cannot overlap the outside of the tunnel (interface and remote gateway) configured in its phase 1.");
							break;
						}
					} elseif ($pconfig['mode'] == "vti") {
						if (($phase1['remote-gateway'] == '0.0.0.0') ||
						    (is_ipaddrv6($phase1['remote-gateway']) &&
						    (text_to_compressed_ip6($phase1['remote-gateway']) == '::'))) {
							$input_errors[] = gettext("A remote gateway address of \"0.0.0.0\" or \"::\" is not compatible with a child Phase 2 in VTI mode.");
							break;
						}
					}
				}
			}
		}
	}

	/* For ESP protocol, handle encryption algorithms */
	if ($pconfig['proto'] == "esp") {
		$ealgos = ipsec_p2_pconfig_to_ealgos($pconfig);

		if (!count($ealgos)) {
			$input_errors[] = gettext("At least one encryption algorithm must be selected.");
		} else {
			foreach ($ealgos as $ealgo) {
				if (empty($pconfig['halgos'])) {
					if (!(strpos($ealgo['name'], "gcm") || $ealgo['name'] == "chacha20poly1305")) {
						$input_errors[] = gettext("At least one hashing algorithm needs to be selected.");
						break;
					}
				}
			}
		}
	}

	if (!empty($pconfig['lifetime'])) {
		if (!is_numericint($pconfig['lifetime'])) {
			$input_errors[] = gettext("Life Time must be an integer.");
		}
		if (!empty($pconfig['rekey_time']) && ($pconfig['lifetime'] == $pconfig['rekey_time'])) {
			$input_errors[] = gettext("Life Time cannot be set to the same value as Rekey Time.");
		}
		if ($pconfig['rekey_time'] > $pconfig['lifetime']) {
			$input_errors[] = gettext("Life Time must be larger than Rekey Time.");
		}
	}
	if (!empty($pconfig['rekey_time']) && !is_numericint($pconfig['rekey_time'])) {
		$input_errors[] = gettext("Rekey Time must be an integer.");
	}
	if (!empty($pconfig['rand_time']) && !is_numericint($pconfig['rand_time'])) {
		$input_errors[] = gettext("Rand Time must be an integer.");
	}

	if (($pconfig['mode'] == "vti") && $pconfig['disabled']) {
		$input_errors[] = gettext("Cannot disable a VTI Phase 2 while the interface is assigned. Remove the interface assignment before disabling this P2.");
	}

	if (!$input_errors) {

		$ph2ent = array();
		$ph2ent['ikeid'] = $pconfig['ikeid'];
		$ph2ent['uniqid'] = $pconfig['uniqid'];
		$ph2ent['mode'] = $pconfig['mode'];
		$ph2ent['disabled'] = $pconfig['disabled'] ? true : false;
		if (!isset($pconfig['reqid'])) {
			$ph2ent['reqid'] = ipsec_new_reqid();
		} else {
			$ph2ent['reqid'] = $pconfig['reqid'];
		}

		if (($ph2ent['mode'] == "tunnel") || ($ph2ent['mode'] == "tunnel6") || ($ph2ent['mode'] == "vti")) {
			if (!empty($pconfig['natlocalid_address'])) {
				$ph2ent['natlocalid'] = ipsec_p2_pconfig_to_idinfo("natlocal", $pconfig);
			}
			$ph2ent['localid'] = ipsec_p2_pconfig_to_idinfo("local", $pconfig);
			$ph2ent['remoteid'] = ipsec_p2_pconfig_to_idinfo("remote", $pconfig);
		}

		$ph2ent['protocol'] = $pconfig['proto'];
		$ph2ent['encryption-algorithm-option'] = $ealgos;
		if (!empty($pconfig['halgos'])) {
			$ph2ent['hash-algorithm-option'] = $pconfig['halgos'];
		} else {
			unset($ph2ent['hash-algorithm-option']);
		}
		$ph2ent['pfsgroup'] = $pconfig['pfsgroup'];
		$ph2ent['lifetime'] = $pconfig['lifetime'];
		$ph2ent['rekey_time'] = $pconfig['rekey_time'];
		$ph2ent['rand_time'] = $pconfig['rand_time'];
		$ph2ent['pinghost'] = $pconfig['pinghost'];
		$ph2ent['keepalive'] = ($pconfig['keepalive']) ? 'enabled' : 'disabled';
		$ph2ent['descr'] = $pconfig['descr'];

		if (isset($pconfig['mobile'])) {
			$ph2ent['mobile'] = true;
		}

		if ($p2index !== null && config_get_path('ipsec/phase2/' . $p2index)) {
			config_set_path('ipsec/phase2/' . $p2index, $ph2ent);
		} else {
			config_set_path('ipsec/phase2/', $ph2ent);
		}

		write_config(gettext("Saved IPsec tunnel Phase 2 configuration."));
		mark_subsystem_dirty('ipsec');
	}

	return $input_errors;
}

/* The checked encryption algorithms of the phase 2 form with their key lengths (was the page's pconfig_to_ealgos()). */
function ipsec_p2_pconfig_to_ealgos(& $pconfig) {
	global $p2_ealgos;

	$ealgos = array();
	if (is_array($pconfig['ealgos'])) {
		foreach ($p2_ealgos as $algo_name => $algo_data) {
			if (in_array($algo_name, $pconfig['ealgos'])) {
				$ealg = array();
				$ealg['name'] = $algo_name;
				if (is_array($algo_data['keysel'])) {
					$ealg['keylen'] = $pconfig["keylen_".$algo_name];
				}
				$ealgos[] = $ealg;
			}
		}
	}

	return $ealgos;
}

/* Stored encryption algorithms into the phase 2 form (was the page's ealgos_to_pconfig()). */
function ipsec_p2_ealgos_to_pconfig(& $ealgos, & $pconfig) {

	$pconfig['ealgos'] = array();
	foreach ($ealgos as $algo_data) {
		$pconfig['ealgos'][] = $algo_data['name'];
		if (isset($algo_data['keylen'])) {
			$pconfig["keylen_".$algo_data['name']] = $algo_data['keylen'];
		}
	}

	return $ealgos;
}

/* A local/remote/NAT network of the phase 2 form as stored (was the page's pconfig_to_idinfo()). */
function ipsec_p2_pconfig_to_idinfo($prefix, & $pconfig) {

	$type = $pconfig[$prefix."id_type"];
	$address = $pconfig[$prefix."id_address"];
	$netbits = $pconfig[$prefix."id_netbits"];

	switch ($type) {
		case "address":
			return array('type' => $type, 'address' => $address);
		case "network":
			return array('type' => $type, 'address' => $address, 'netbits' => $netbits);
		default:
			return array('type' => $type);
	}
}

/* A stored local/remote/NAT network into the phase 2 form (was the page's idinfo_to_pconfig()). */
function ipsec_p2_idinfo_to_pconfig($prefix, & $idinfo, & $pconfig) {

	switch ($idinfo['type']) {
		case "address":
			$pconfig[$prefix."id_type"] = $idinfo['type'];
			$pconfig[$prefix."id_address"] = $idinfo['address'];
			break;
		case "network":
			$pconfig[$prefix."id_type"] = $idinfo['type'];
			$pconfig[$prefix."id_address"] = $idinfo['address'];
			$pconfig[$prefix."id_netbits"] = $idinfo['netbits'];
			break;
		default:
			$pconfig[$prefix."id_type"] = $idinfo['type'];
			break;
	}
}

/* ------------------------------------------------------------------ */
/* Mobile clients                                                      */
/* ------------------------------------------------------------------ */

/* The groups the mobile clients page offers for group authentication (groups with the IPsec dial-in or all-pages privilege). */
function ipsec_mobile_auth_groups() {
	$auth_groups = array();
	foreach (config_get_path('system/group', []) as $group) {
		if (isset($group['priv'])) {
			foreach ($group['priv'] as $priv) {
				if (($priv == 'page-all') || ($priv == 'user-ipsec-xauth-dialin')) {
					if (!empty($group['description'])) {
						$auth_groups[$group['name']] = $group['description'] . " (" . $group['name'] . ")";
					} else {
						$auth_groups[$group['name']] = $group['name'];
					}
					continue 2;
				}
			}
		}
	}
	return $auth_groups;
}

/* The authentication servers the mobile clients page offers as user source (value and label: the server name). */
function ipsec_mobile_user_sources() {
	$authServers = array();

	foreach (auth_get_authserver_list() as $key => $authServer) {
		$authServers[$key] = $authServer['name']; // Value == name
	}

	return $authServers;
}

/* The mobile clients page's form (empty when there are no mobile client settings). */
function ipsec_mobile_form() {
	$pconfig = array();

	if (count(config_get_path('ipsec/client', []))) {

		$pconfig['enable'] = config_get_path('ipsec/client/enable');
		$pconfig['radiusaccounting'] = (config_get_path('ipsec/client/radiusaccounting') == 'enabled');
		$pconfig['radius_groups'] = config_get_path('ipsec/client/radius_groups');

		$pconfig['user_source'] = config_get_path('ipsec/client/user_source');
		$pconfig['group_source'] = config_get_path('ipsec/client/group_source');
		$pconfig['auth_groups'] = config_get_path('ipsec/client/auth_groups');

		$pconfig['pool_address'] = config_get_path('ipsec/client/pool_address');
		$pconfig['pool_netbits'] = config_get_path('ipsec/client/pool_netbits');
		$pconfig['pool_address_v6'] = config_get_path('ipsec/client/pool_address_v6');
		$pconfig['pool_netbits_v6'] = config_get_path('ipsec/client/pool_netbits_v6');
		$pconfig['radius_retransmit_base'] = config_get_path('ipsec/client/radius_retransmit_base');
		$pconfig['radius_retransmit_timeout'] = config_get_path('ipsec/client/radius_retransmit_timeout');
		$pconfig['radius_retransmit_tries'] = config_get_path('ipsec/client/radius_retransmit_tries');
		$pconfig['radius_sockets'] = config_get_path('ipsec/client/radius_sockets');
		$pconfig['net_list'] = config_get_path('ipsec/client/net_list');
		$pconfig['save_passwd'] = config_get_path('ipsec/client/save_passwd');
		$pconfig['dns_domain'] = config_get_path('ipsec/client/dns_domain');
		$pconfig['dns_split'] = config_get_path('ipsec/client/dns_split');
		$pconfig['dns_server1'] = config_get_path('ipsec/client/dns_server1');
		$pconfig['dns_server2'] = config_get_path('ipsec/client/dns_server2');
		$pconfig['dns_server3'] = config_get_path('ipsec/client/dns_server3');
		$pconfig['dns_server4'] = config_get_path('ipsec/client/dns_server4');
		$pconfig['wins_server1'] = config_get_path('ipsec/client/wins_server1');
		$pconfig['wins_server2'] = config_get_path('ipsec/client/wins_server2');
		$pconfig['pfs_group'] = config_get_path('ipsec/client/pfs_group');
		$pconfig['login_banner'] = config_get_path('ipsec/client/login_banner');
		$pconfig['radius_ip_priority_enable'] = config_get_path('ipsec/client/radius_ip_priority_enable');

		if (isset($pconfig['enable'])) {
			$pconfig['enable'] = true;
		}

		if ($pconfig['group_source'] == 'enabled') {
			$pconfig['group_source'] = true;
			$pconfig['auth_groups'] = config_get_path('ipsec/client/auth_groups');
		}

		if ($pconfig['pool_address'] && $pconfig['pool_netbits']) {
			$pconfig['pool_enable'] = true;
		} else {
			$pconfig['pool_netbits'] = 24;
		}

		if (isset($pconfig['radius_ip_priority_enable'])) {
			$pconfig['radius_ip_priority_enable'] = true;
		}

		if ($pconfig['radius_retransmit_base'] || $pconfig['radius_retransmit_timeout'] ||
		    $pconfig['radius_retransmit_tries'] || $pconfig['radius_sockets']) {
			$pconfig['radius_advanced'] = true;
		}

		if ($pconfig['pool_address_v6'] && $pconfig['pool_netbits_v6']) {
			$pconfig['pool_enable_v6'] = true;
		} else {
			$pconfig['pool_netbits_v6'] = 120;
		}

		if (isset($pconfig['net_list'])) {
			$pconfig['net_list_enable'] = true;
		}

		if (isset($pconfig['save_passwd'])) {
			$pconfig['save_passwd_enable'] = true;
		}

		if ($pconfig['dns_domain']) {
			$pconfig['dns_domain_enable'] = true;
		}

		if ($pconfig['dns_split']) {
			$pconfig['dns_split_enable'] = true;
		}

		if ($pconfig['dns_server1'] || $pconfig['dns_server2'] || $pconfig['dns_server3'] || $pconfig['dns_server4']) {
			$pconfig['dns_server_enable'] = true;
		}

		if ($pconfig['wins_server1'] || $pconfig['wins_server2']) {
			$pconfig['wins_server_enable'] = true;
		}

		if (isset($pconfig['pfs_group'])) {
			$pconfig['pfs_group_enable'] = true;
		}

		if ($pconfig['login_banner']) {
			$pconfig['login_banner_enable'] = true;
		}
	}

	return $pconfig;
}

/*
 * Apply pending IPsec changes like the mobile clients page's Apply button:
 * IPsec is always restarted when mobile client settings change (#4353).
 * Returns 0 (the page reports the result of nothing else).
 */
function ipsec_mobile_apply() {
	$retval = 0;
	/* NOTE: #4353 Always restart ipsec when mobile clients settings change */
	$ipsec_dynamic_hosts = ipsec_configure(true);
	if ($ipsec_dynamic_hosts >= 0) {
		if (is_subsystem_dirty('ipsec')) {
			clear_subsystem_dirty('ipsec');
		}
	}
	return $retval;
}

/*
 * Validate and save the mobile clients form ($pconfig: the form post, changed
 * in place like the page did) and mark IPsec dirty. Returns the input errors.
 */
function ipsec_mobile_save(array &$pconfig) {
	global $p2_pfskeygroups;

	$input_errors = array();

	foreach (config_get_path('ipsec/phase1', []) as $ph1ent) {
		if (isset($ph1ent['mobile'])) {
			$mobileph1 = $ph1ent;
		}
	}
	/* input consolidation */

	/* input validation */

	$reqdfields = explode(" ", "user_source");
	$reqdfieldsn = array(gettext("User Authentication Source"));

	do_input_validation($pconfig, $reqdfields, $reqdfieldsn, $input_errors);

	/* the page's selects: only their choices are accepted (anything was stored) */
	if (!empty($pconfig['user_source'])) {
		if (!is_array($pconfig['user_source']) ||
		    count(array_diff(array_map('strval', $pconfig['user_source']), array_keys(ipsec_mobile_user_sources())))) {
			$input_errors[] = gettext("A valid User Authentication Source must be selected.");
		}
	}
	if (($pconfig['group_source'] == 'yes') && !empty($pconfig['auth_groups'])) {
		if (!is_array($pconfig['auth_groups']) ||
		    count(array_diff(array_map('strval', $pconfig['auth_groups']), array_map('strval', array_keys(ipsec_mobile_auth_groups()))))) {
			$input_errors[] = gettext("A valid Authentication Group must be selected.");
		}
	}
	if ($pconfig['pool_enable'] && (!is_numericint((string)$pconfig['pool_netbits']) || ($pconfig['pool_netbits'] > 32))) {
		$input_errors[] = gettext("A valid mask for 'Virtual Address Pool Network' must be selected.");
	}
	if ($pconfig['pool_enable_v6'] && (!is_numericint((string)$pconfig['pool_netbits_v6']) || ($pconfig['pool_netbits_v6'] > 128))) {
		$input_errors[] = gettext("A valid mask for 'Virtual IPv6 Address Pool Network' must be selected.");
	}
	if ($pconfig['pfs_group_enable'] && !array_key_exists((string)$pconfig['pfs_group'], $p2_pfskeygroups)) {
		$input_errors[] = gettext("A valid Phase2 PFS Group must be selected.");
	}

	if ($pconfig['pool_enable']) {
		if (!is_ipaddr($pconfig['pool_address'])) {
			$input_errors[] = gettext("A valid IP address for 'Virtual Address Pool Network' must be specified.");
		}
	}
	if ($pconfig['pool_enable_v6']) {
		if (!is_ipaddrv6($pconfig['pool_address_v6'])) {
			$input_errors[] = gettext("A valid IPv6 address for 'Virtual IPv6 Address Pool Network' must be specified.");
		}
	}
	if (!isset($pconfig['radius_advanced'])) {
		unset($pconfig['radius_retransmit_base']);
		unset($pconfig['radius_retransmit_timeout']);
		unset($pconfig['radius_retransmit_tries']);
		/* this unset a misspelled field, so the sockets were kept without the advanced parameters */
		unset($pconfig['radius_sockets']);
	}
	if ($pconfig['radius_retransmit_base'] && !is_numeric($pconfig['radius_retransmit_base'])) {
		$input_errors[] = gettext("An integer must be specified for RADIUS Retransmit Base.");
	}
	if ($pconfig['radius_retransmit_timeout'] && !is_numeric($pconfig['radius_retransmit_timeout'])) {
		$input_errors[] = gettext("An integer must be specified for RADIUS Retransmit Timeout.");
	}
	if ($pconfig['radius_retransmit_tries'] && !is_numericint($pconfig['radius_retransmit_tries'])) {
		$input_errors[] = gettext("An integer must be specified for RADIUS Retransmit Tries.");
	}
	if ($pconfig['radius_sockets'] && !is_numericint($pconfig['radius_sockets'])) {
		$input_errors[] = gettext("An integer must be specified for RADIUS Sockets.");
	}
	if ($pconfig['dns_domain_enable']) {
		if (!is_domain($pconfig['dns_domain'])) {
			$input_errors[] = gettext("A valid value for 'DNS Default Domain' must be specified.");
		}
	}
	if ($pconfig['dns_split_enable']) {
		if (!empty($pconfig['dns_split'])) {
			/* Replace multiple spaces by single */
			$pconfig['dns_split'] = preg_replace('/\s+/', ' ', trim($pconfig['dns_split']));
			$domain_array = explode(' ', $pconfig['dns_split']);
			foreach ($domain_array as $curdomain) {
				if (!is_domain($curdomain)) {
					$input_errors[] = gettext("A valid split DNS domain list must be specified.");
					break;
				}
			}
		}
	}

	if ($pconfig['dns_server_enable']) {
		if (!$pconfig['dns_server1'] && !$pconfig['dns_server2'] &&
		    !$pconfig['dns_server3'] && !$pconfig['dns_server4']) {
			$input_errors[] = gettext("At least one DNS server must be specified to enable the DNS Server option.");
		}
		if ($pconfig['dns_server1'] && (!is_ipaddr($pconfig['dns_server1']) ||
		    is_ipaddrv6_v4map($pconfig['dns_server1']))) {
			$input_errors[] = gettext("A valid IP address for 'DNS Server #1' must be specified.");
		}
		if ($pconfig['dns_server2'] && (!is_ipaddr($pconfig['dns_server2']) ||
		    is_ipaddrv6_v4map($pconfig['dns_server2']))) {
			$input_errors[] = gettext("A valid IP address for 'DNS Server #2' must be specified.");
		}
		if ($pconfig['dns_server3'] && (!is_ipaddr($pconfig['dns_server3']) ||
		    is_ipaddrv6_v4map($pconfig['dns_server3']))) {
			$input_errors[] = gettext("A valid IP address for 'DNS Server #3' must be specified.");
		}
		if ($pconfig['dns_server4'] && (!is_ipaddr($pconfig['dns_server4']) ||
		    is_ipaddrv6_v4map($pconfig['dns_server4']))) {
			$input_errors[] = gettext("A valid IP address for 'DNS Server #4' must be specified.");
		}
	}

	if ($pconfig['wins_server_enable']) {
		if (!$pconfig['wins_server1'] && !$pconfig['wins_server2']) {
			$input_errors[] = gettext("At least one WINS server must be specified to enable the DNS Server option.");
		}
		if ($pconfig['wins_server1'] && !is_ipaddr($pconfig['wins_server1'])) {
			$input_errors[] = gettext("A valid IP address for 'WINS Server #1' must be specified.");
		}
		if ($pconfig['wins_server2'] && !is_ipaddr($pconfig['wins_server2'])) {
			$input_errors[] = gettext("A valid IP address for 'WINS Server #2' must be specified.");
		}
	}

	if ($pconfig['login_banner_enable']) {
		if (!strlen($pconfig['login_banner'])) {
			$input_errors[] = gettext("A valid value for 'Login Banner' must be specified.");
		}
	}

	/* the refilled form takes the sources as a list or as text; implode() of the text was a TypeError */
	if ($pconfig['user_source']) {
		if (isset($mobileph1) && $mobileph1['authentication_method'] == 'eap-radius') {
			foreach ((array)$pconfig['user_source'] as $auth_server_name) {
				$auth_server       = auth_get_authserver($auth_server_name);
				if (!is_array($auth_server) || ($auth_server['type'] != 'radius')) {
					$input_errors[] = gettext("Only valid RADIUS servers may be selected as a user source when using EAP-RADIUS for authentication on the Mobile IPsec VPN.");
					if (is_array($pconfig['user_source'])) {
						$pconfig['user_source'] = implode(',', $pconfig['user_source']);
					}
				}
			}
		}
	}

	if ($pconfig['radius_ip_priority_enable']) {
		if (!(isset($mobileph1) && ($mobileph1['authentication_method'] == 'eap-radius'))) {
			$input_errors[] = gettext("RADIUS IP may only take priority when using EAP-RADIUS for authentication on the Mobile IPsec VPN.");
			if (is_array($pconfig['user_source'])) {
				$pconfig['user_source'] = implode(',', $pconfig['user_source']);
			}
		}
	}

	if (!$input_errors) {
		if ($pconfig['enable']) {
			config_set_path('ipsec/client/enable', true);
		} else {
			config_del_path('ipsec/client/enable');
		}

		config_set_path('ipsec/client/radiusaccounting', ($pconfig['radiusaccounting'] == 'yes') ? "enabled" : "disabled");

		if (!empty($pconfig['user_source'])) {
			config_set_path('ipsec/client/user_source', implode(",", $pconfig['user_source']));
		} else {
			config_del_path('ipsec/client/user_source');
		}

		config_set_path('ipsec/client/group_source', (($pconfig['group_source'] == 'yes') ? "enabled" : "disabled"));
		if (($pconfig['group_source'] == 'yes') && !empty($pconfig['auth_groups'])) {
			config_set_path('ipsec/client/auth_groups', implode(",", $pconfig['auth_groups']));
		} else {
			config_del_path('ipsec/client/group_source');
			config_del_path('ipsec/client/auth_groups');
		}

		if ($pconfig['pool_enable']) {
			config_set_path('ipsec/client/pool_address', $pconfig['pool_address']);
			config_set_path('ipsec/client/pool_netbits', $pconfig['pool_netbits']);
		} else {
			config_del_path('ipsec/client/pool_address');
			config_del_path('ipsec/client/pool_netbits');
		}

		if ($pconfig['radius_ip_priority_enable']) {
			config_set_path('ipsec/client/radius_ip_priority_enable', true);
		} else {
			config_del_path('ipsec/client/radius_ip_priority_enable');
		}

		if ($pconfig['pool_enable_v6']) {
			config_set_path('ipsec/client/pool_address_v6', $pconfig['pool_address_v6']);
			config_set_path('ipsec/client/pool_netbits_v6', $pconfig['pool_netbits_v6']);
		} else {
			config_del_path('ipsec/client/pool_address_v6');
			config_del_path('ipsec/client/pool_netbits_v6');
		}

		if ($pconfig['radius_retransmit_base']) {
			config_set_path('ipsec/client/radius_retransmit_base', $pconfig['radius_retransmit_base']);
		} else {
			config_del_path('ipsec/client/radius_retransmit_base');
		}
		if ($pconfig['radius_retransmit_timeout']) {
			config_set_path('ipsec/client/radius_retransmit_timeout', $pconfig['radius_retransmit_timeout']);
		} else {
			config_del_path('ipsec/client/radius_retransmit_timeout');
		}
		if ($pconfig['radius_retransmit_tries']) {
			config_set_path('ipsec/client/radius_retransmit_tries', $pconfig['radius_retransmit_tries']);
		} else {
			config_del_path('ipsec/client/radius_retransmit_tries');
		}
		if ($pconfig['radius_sockets']) {
			config_set_path('ipsec/client/radius_sockets', $pconfig['radius_sockets']);
		} else {
			config_del_path('ipsec/client/radius_sockets');
		}

		if ($pconfig['net_list_enable']) {
			config_set_path('ipsec/client/net_list', true);
		} else {
			config_del_path('ipsec/client/net_list');
		}

		if ($pconfig['save_passwd_enable']) {
			config_set_path('ipsec/client/save_passwd', true);
		} else {
			config_del_path('ipsec/client/save_passwd');
		}

		if ($pconfig['dns_domain_enable']) {
			config_set_path('ipsec/client/dns_domain', $pconfig['dns_domain']);
		} else {
			config_del_path('ipsec/client/dns_domain');
		}

		if ($pconfig['dns_split_enable']) {
			config_set_path('ipsec/client/dns_split', $pconfig['dns_split']);
		} else {
			config_del_path('ipsec/client/dns_split');
		}

		if ($pconfig['dns_server_enable']) {
			if ($pconfig['dns_server1']) {
				config_set_path('ipsec/client/dns_server1', $pconfig['dns_server1']);
			} else {
				config_del_path('ipsec/client/dns_server1');
			}
			if ($pconfig['dns_server2']) {
				config_set_path('ipsec/client/dns_server2', $pconfig['dns_server2']);
			} else {
				config_del_path('ipsec/client/dns_server2');
			}
			if ($pconfig['dns_server3']) {
				config_set_path('ipsec/client/dns_server3', $pconfig['dns_server3']);
			} else {
				config_del_path('ipsec/client/dns_server3');
			}
			if ($pconfig['dns_server4']) {
				config_set_path('ipsec/client/dns_server4', $pconfig['dns_server4']);
			} else {
				config_del_path('ipsec/client/dns_server4');
			}
		} else {
			config_del_path('ipsec/client/dns_server1');
			config_del_path('ipsec/client/dns_server2');
			config_del_path('ipsec/client/dns_server3');
			config_del_path('ipsec/client/dns_server4');
		}

		if ($pconfig['wins_server_enable']) {
			if ($pconfig['wins_server1']) {
				config_set_path('ipsec/client/wins_server1', $pconfig['wins_server1']);
			} else {
				config_del_path('ipsec/client/wins_server1');
			}
			if ($pconfig['wins_server2']) {
				config_set_path('ipsec/client/wins_server2', $pconfig['wins_server2']);
			} else {
				config_del_path('ipsec/client/wins_server2');
			}
		} else {
			config_del_path('ipsec/client/wins_server1');
			config_del_path('ipsec/client/wins_server2');
		}

		if ($pconfig['pfs_group_enable']) {
			config_set_path('ipsec/client/pfs_group', $pconfig['pfs_group']);
		} else {
			config_del_path('ipsec/client/pfs_group');
		}

		if ($pconfig['login_banner_enable']) {
			config_set_path('ipsec/client/login_banner', $pconfig['login_banner']);
		} else {
			config_del_path('ipsec/client/login_banner');
		}

		write_config(gettext("Saved IPsec Mobile Clients configuration."));
		mark_subsystem_dirty('ipsec');
	}

	return $input_errors;
}

/* ------------------------------------------------------------------ */
/* Advanced settings                                                   */
/* ------------------------------------------------------------------ */

/* The advanced settings page's form. */
function ipsec_settings_form() {
	$pconfig = array();

	$pconfig['logging'] = ipsec_get_loglevels();
	$pconfig['unityplugin'] = config_path_enabled('ipsec', 'unityplugin');
	$pconfig['strictcrlpolicy'] = config_path_enabled('ipsec', 'strictcrlpolicy');
	$pconfig['makebeforebreak'] = config_path_enabled('ipsec', 'makebeforebreak');
	$pconfig['noshuntlaninterfaces'] = config_path_enabled('ipsec', 'noshuntlaninterfaces');
	$pconfig['compression'] = config_path_enabled('ipsec', 'compression');
	$pconfig['pkcs11support'] = config_path_enabled('ipsec', 'pkcs11support');
	$pconfig['enableinterfacesuse'] = config_path_enabled('ipsec', 'enableinterfacesuse');
	$pconfig['acceptunencryptedmainmode'] = config_path_enabled('ipsec', 'acceptunencryptedmainmode');
	$pconfig['maxexchange'] = config_get_path('ipsec/maxexchange');
	$pconfig['uniqueids'] = config_get_path('ipsec/uniqueids');
	$pconfig['filtermode'] = config_get_path('ipsec/filtermode');
	$pconfig['dns-interval'] = config_get_path('ipsec/dns-interval');
	$pconfig['ikev2_retransmit_tries'] = config_get_path('ipsec/ikev2_retransmit_tries');
	$pconfig['ikev2_retransmit_timeout'] = config_get_path('ipsec/ikev2_retransmit_timeout');
	$pconfig['ikev2_retransmit_base'] = config_get_path('ipsec/ikev2_retransmit_base');
	$pconfig['ikev2_retransmit_jitter'] = config_get_path('ipsec/ikev2_retransmit_jitter');
	$pconfig['ikev2_retransmit_limit'] = config_get_path('ipsec/ikev2_retransmit_limit');
	$pconfig['ipsecbypass'] = config_path_enabled('ipsec', 'ipsecbypass');
	$pconfig['bypassrules'] = config_get_path('ipsec/bypassrules');
	$pconfig['port'] = config_get_path('ipsec/port');
	$pconfig['port_nat_t'] = config_get_path('ipsec/port_nat_t');

	if ($pconfig['ikev2_retransmit_tries'] || $pconfig['ikev2_retransmit_timeout'] ||
	    $pconfig['ikev2_retransmit_base'] || $pconfig['ikev2_retransmit_jitter'] ||
	    $pconfig['ikev2_retransmit_limit']) {
		$pconfig['ikev2_retransmit_enable'] = true;
	}

	return $pconfig;
}

/*
 * Validate and save the advanced settings form ($post) and apply them at once
 * (filter reload, IPsec reconfigured or restarted, sysctls set; the "sysctl"
 * dirty flag is cleared like the page did), like the page's Save. Returns
 * 'input_errors', 'pconfig' (the form to show again: the post as the page
 * kept it), 'changes_applied' and 'retval' (the filter reload result).
 */
function ipsec_settings_save(array $post) {
	global $ipsec_log_cats, $ipsec_log_sevs, $ipsec_idhandling, $ipsec_filtermodes;

	$input_errors = array();
	$changes_applied = false;
	$retval = 0;
	$pconfig = $post;
	$bypassrules = null;
	$needsfilterdnsrestart = false;

	foreach ($ipsec_log_cats as $cat => $desc) {
		if (!in_array(intval($pconfig['logging_' . $cat]), array_keys($ipsec_log_sevs), true)) {
			$input_errors[] = sprintf(gettext("A valid value must be specified for %s debug."), $desc);
		} else {
			$pconfig['logging'][$cat] = $pconfig['logging_' . $cat];
		}
	}

	if ($post['maxexchange'] && (!is_numeric($post['maxexchange']) ||
	    ($post['maxexchange'] < 3) || ($post['maxexchange'] > 64))) {
		$input_errors[] = gettext("The number of IKEv1 phase 2 exchanges must be between 3 and 64.");
	}

	if ($post['dns-interval'] && (!is_numeric($post['dns-interval']) ||
	    ($post['dns-interval'] < 1))) {
		$input_errors[] = gettext("The FQDN Endpoints Resolve Interval must be more than 0.");
	}

	/* the page's selects: only their choices are accepted (anything was stored) */
	if (!empty($post['uniqueids']) && !array_key_exists((string)$post['uniqueids'], $ipsec_idhandling)) {
		$input_errors[] = gettext("A valid Unique IDs setting must be selected.");
	}
	if (!empty($post['filtermode']) && !array_key_exists((string)$post['filtermode'], $ipsec_filtermodes)) {
		$input_errors[] = gettext("A valid IPsec Filter Mode must be selected.");
	}

	if (!isset($post['ikev2_retransmit_enable'])) {
		unset($post['ikev2_retransmit_tries']);
		unset($post['ikev2_retransmit_timeout']);
		unset($post['ikev2_retransmit_base']);
		unset($post['ikev2_retransmit_jitter']);
		unset($post['ikev2_retransmit_limit']);
	}

	if ($post['ikev2_retransmit_tries'] && !is_numericint($post['ikev2_retransmit_tries'])) {
		$input_errors[] = gettext("An integer must be specified for IKEv2 Retransmit Tries.");
	}
	if ($post['ikev2_retransmit_timeout'] && !is_numeric($post['ikev2_retransmit_timeout'])) {
		$input_errors[] = gettext("A number must be specified for IKEv2 Retransmit Timeout.");
	}
	if ($post['ikev2_retransmit_base'] && !is_numeric($post['ikev2_retransmit_base'])) {
		$input_errors[] = gettext("A number must be specified for IKEv2 Retransmit Base.");
	}
	if ($post['ikev2_retransmit_jitter'] && !is_numericint($post['ikev2_retransmit_jitter'])) {
		$input_errors[] = gettext("An integer must be specified for IKEv2 Retransmit Jitter.");
	}
	if ($post['ikev2_retransmit_limit'] && !is_numericint($post['ikev2_retransmit_limit'])) {
		$input_errors[] = gettext("An integer must be specified for IKEv2 Retransmit Limit.");
	}

	if ($post['ipsecbypass']) {
		$bypassrules = array();
		for ($x = 0; $x < 99; $x++) {
			if (isset($post["source{$x}"]) && isset($post["destination{$x}"]) &&
			    isset($post["srcmask{$x}"]) && isset($post["dstmask{$x}"])) {
				$source = $post["source{$x}"] . '/' . $post["srcmask{$x}"];
				$destination = $post["destination{$x}"] . '/' . $post["dstmask{$x}"];
				if (!is_subnetv4($source) && !is_subnetv6($source)) {
					$input_errors[] = sprintf(gettext('%s is not valid source IP address for
					       	IPsec bypass rule'), htmlspecialchars($source));
				}
				if (!is_subnetv4($destination) && !is_subnetv6($destination)) {
					$input_errors[] = sprintf(gettext('%s is not valid destination IP address
						for IPsec bypass rule'), htmlspecialchars($destination));
				}
				if ((is_subnetv4($source) && is_subnetv6($destination)) ||
				    (is_subnetv6($source) && is_subnetv4($destination))) {
					$input_errors[] = gettext('IPsec bypass source and destination addresses
						must belong to the same address family.');
				}
				$bypassrules['rule'][] = array(
					'source' => $post["source{$x}"],
				       	'srcmask' => $post["srcmask{$x}"],
					'destination' => $post["destination{$x}"],
					'dstmask' => $post["dstmask{$x}"]
				);
			}
		}
	}

	if ($post['port']) {
		if (!is_port($pconfig['port'])) {
			$input_errors[] = gettext("The IKE port number is invalid.");
		}
	} else {
		unset($pconfig['port']);
	}

	if ($post['port_nat_t']) {
		if (!is_port($pconfig['port_nat_t'])) {
			$input_errors[] = gettext("The NAT-T port number is invalid.");
		}
	} else {
		unset($pconfig['port_nat_t']);
	}

	if (isset($pconfig['port']) && isset($pconfig['port_nat_t']) && $pconfig['port'] == $pconfig['port_nat_t']) {
		$input_errors[] = gettext("IKE and NAT-T port numbers must be different.");
	}

	/* PKCS#11 support cannot be disabled while an enabled phase 1 uses it (this was found after saving, and everything else was still saved and applied) */
	if (($post['pkcs11support'] != "yes") && config_path_enabled('ipsec', 'pkcs11support')) {
		foreach (config_get_path('ipsec/phase1', []) as $ph1ent) {
			if (($ph1ent['authentication_method'] == 'pkcs11') &&
			    !isset($ph1ent['disabled'])) {
				$input_errors[] = gettext("Unable to disable PKCS#11 support,
				       	already in use for Phase 1 authentication.");
				break;
			}
		}
	}

	$pconfig['bypassrules'] = $bypassrules;

	if (!$input_errors) {

		/* log levels aren't set initially and use default. They all
		 * get set when we save, even if it's to the default level.
		 */
		foreach (array_keys($ipsec_log_cats) as $cat) {
			if (!isset($pconfig['logging'][$cat])) {
				continue;
			}
			if ($pconfig['logging'][$cat] != config_get_path('ipsec/logging/' . $cat)) {
				config_set_path('ipsec/logging/' . $cat, $pconfig['logging'][$cat]);
			}
		}

		$needsrestart = false;

		if ($post['compression'] == "yes") {
			if (!config_path_enabled('ipsec', 'compression')) {
				$needsrestart = true;
				config_set_path('ipsec/compression', true);
			}
		} elseif (config_path_enabled('ipsec', 'compression')) {
			$needsrestart = true;
			config_del_path('ipsec/compression');
		}

		if ($post['pkcs11support'] == "yes") {
			if (!config_path_enabled('ipsec', 'pkcs11support')) {
				$needsrestart = true;
				config_set_path('ipsec/pkcs11support', true);
			}
		} elseif (config_path_enabled('ipsec', 'pkcs11support')) {
			/* refused above while an enabled phase 1 uses it */
			$needsrestart = true;
			config_del_path('ipsec/pkcs11support');
		}

		if (config_path_enabled('ipsec', 'enableinterfacesuse')) {
			if ($post['enableinterfacesuse'] != "yes") {
				$needsrestart = true;
				config_del_path('ipsec/enableinterfacesuse');
			}
		} else {
			if ($post['enableinterfacesuse'] == "yes") {
				$needsrestart = true;
				config_set_path('ipsec/enableinterfacesuse', true);
			}
		}

		if ($post['unityplugin'] == "yes") {
			if (!config_path_enabled('ipsec', 'unityplugin')) {
				$needsrestart = true;
				config_set_path('ipsec/unityplugin', true);
			}
		} elseif (!empty(config_get_path('ipsec/unityplugin'))) {
			$needsrestart = true;
			config_del_path('ipsec/unityplugin');
		}

		if ($post['strictcrlpolicy'] == "yes") {
			config_set_path('ipsec/strictcrlpolicy', true);
		} else {
			config_del_path('ipsec/strictcrlpolicy');
		}

		if ($post['makebeforebreak'] == "yes") {
			config_set_path('ipsec/makebeforebreak', true);
		} else {
			config_del_path('ipsec/makebeforebreak');
		}

		// The UI deals with "Auto-exclude LAN address" but in the back-end we work with
		// noshuntlaninterfaces which is the reverse true/false logic setting - #4655
		if ($post['autoexcludelanaddress'] == "yes") {
			config_del_path('ipsec/noshuntlaninterfaces');
		} else {
			config_set_path('ipsec/noshuntlaninterfaces', true);
		}

		if ($post['ipsecbypass'] == "yes") {
			config_set_path('ipsec/ipsecbypass', true);
		} else {
			config_del_path('ipsec/ipsecbypass');
		}

		if ($post['async_crypto'] == "yes") {
			config_set_path('ipsec/async_crypto', "enabled");
		} else {
			config_set_path('ipsec/async_crypto', "disabled");
		}

		if ($post['acceptunencryptedmainmode'] == "yes") {
			if (!config_path_enabled('ipsec', 'acceptunencryptedmainmode')) {
				$needsrestart = true;
				config_set_path('ipsec/acceptunencryptedmainmode', true);
			}
		} elseif (config_path_enabled('ipsec', 'acceptunencryptedmainmode')) {
			/* this tested !config_path_enabled(), so the option could not be turned off */
			$needsrestart = true;
			config_del_path('ipsec/acceptunencryptedmainmode');
		}

		if (isset($post['maxexchange']) && (strlen($post['maxexchange']) > 0)) {
			if (empty(config_get_path('ipsec/maxexchange')) || ($pconfig['maxexchange'] != config_get_path('ipsec/maxexchange'))) {
				$needsrestart = true;
			}
			config_set_path('ipsec/maxexchange', (int)$post['maxexchange']);
			$pconfig['maxexchange'] = config_get_path('ipsec/maxexchange');
		} elseif (!empty(config_get_path('ipsec/maxexchange'))) {
			$needsrestart = true;
			config_del_path('ipsec/maxexchange');
		}

		if (isset($post['dns-interval']) && !empty($post['dns-interval'])) {
			if (empty(config_get_path('ipsec/dns-interval')) || ($pconfig['dns-interval'] != config_get_path('ipsec/dns-interval'))) {
				$needsfilterdnsrestart = true;
			}
			config_set_path('ipsec/dns-interval', (int)$post['dns-interval']);
			$pconfig['dns-interval'] = config_get_path('ipsec/dns-interval');
		} elseif (!empty(config_get_path('ipsec/dns-interval'))) {
			$needsfilterdnsrestart = true;
			config_del_path('ipsec/dns-interval');
		}

		if (!empty($post['uniqueids'])) {
			config_set_path('ipsec/uniqueids', $post['uniqueids']);
		} else {
			config_del_path('ipsec/uniqueids');
		}

		if (!empty($post['filtermode'])) {
			config_set_path('ipsec/filtermode', $post['filtermode']);
		} else {
			config_del_path('ipsec/filtermode');
		}

		/* the retransmission parameters no longer cancel a restart another change needs (they set $needsrestart = false) */
		if (!empty($post['ikev2_retransmit_tries'])) {
			config_set_path('ipsec/ikev2_retransmit_tries', $post['ikev2_retransmit_tries']);
		} else if (!empty(config_get_path('ipsec/ikev2_retransmit_tries'))) {
			config_del_path('ipsec/ikev2_retransmit_tries');
		}

		if (!empty($post['ikev2_retransmit_timeout'])) {
			config_set_path('ipsec/ikev2_retransmit_timeout', $post['ikev2_retransmit_timeout']);
		} else if (!empty(config_get_path('ipsec/ikev2_retransmit_timeout'))) {
			config_del_path('ipsec/ikev2_retransmit_timeout');
		}

		if (!empty($post['ikev2_retransmit_base'])) {
			config_set_path('ipsec/ikev2_retransmit_base', $post['ikev2_retransmit_base']);
		} else if (!empty(config_get_path('ipsec/ikev2_retransmit_base'))) {
			config_del_path('ipsec/ikev2_retransmit_base');
		}

		if (!empty($post['ikev2_retransmit_jitter'])) {
			/* this was config_get_path(), so the jitter was never saved */
			config_set_path('ipsec/ikev2_retransmit_jitter', $post['ikev2_retransmit_jitter']);
		} else if (!empty(config_get_path('ipsec/ikev2_retransmit_jitter'))) {
			config_del_path('ipsec/ikev2_retransmit_jitter');
		}

		if (!empty($post['ikev2_retransmit_limit'])) {
			config_set_path('ipsec/ikev2_retransmit_limit', $post['ikev2_retransmit_limit']);
		} else if (!empty(config_get_path('ipsec/ikev2_retransmit_limit'))) {
			config_del_path('ipsec/ikev2_retransmit_limit');
		}

		config_del_path('ipsec/bypassrules/rule');

		config_set_path('ipsec/bypassrules', $bypassrules);

		if (!empty($post['port_nat_t'])) {
			config_set_path('ipsec/port_nat_t', $post['port_nat_t']);
		} else {
			config_del_path('ipsec/port_nat_t');
			$pconfig['port_nat_t'] = '';
		}

		if (!empty($post['port'])) {
			config_set_path('ipsec/port', $post['port']);
		} else {
			config_del_path('ipsec/port');
			$pconfig['port'] = '';
		}

		write_config(gettext("Saved IPsec advanced settings."));

		$changes_applied = true;
		$retval = 0;
		$retval |= filter_configure();

		ipsec_configure($needsrestart, $needsfilterdnsrestart);
		system_setup_sysctl();
		clear_subsystem_dirty('sysctl');
	}

	// The logic value sent by the form for autoexcludelanaddress is opposite to
	// the way it is stored in the config as noshuntlaninterfaces.
	// Reset the $pconfig value so it reflects the opposite of what was $POSTed.
	// This helps a redrawn UI page after Save to correctly display the most recently entered setting.
	if ($post['autoexcludelanaddress'] == "yes") {
		$pconfig['noshuntlaninterfaces'] = false;
	} else {
		$pconfig['noshuntlaninterfaces'] = true;
	}

	return array('input_errors' => $input_errors, 'pconfig' => $pconfig, 'changes_applied' => $changes_applied,
	    'retval' => $retval);
}

/*
 * The form's timers for the life/rekey/reauth/rand time placeholders of the
 * phase 1 and phase 2 pages: a value that is not a number counts as empty
 * (a refused save with e.g. a Life Time of "abc" crashed the page, the
 * placeholders compute with it).
 */
function ipsec_timer_entry($pconfig) {
	if (!is_array($pconfig)) {
		return $pconfig;
	}
	foreach (array('lifetime', 'rekey_time', 'reauth_time', 'rand_time') as $timer) {
		if (isset($pconfig[$timer]) && (!is_scalar($pconfig[$timer]) || ((strlen((string)$pconfig[$timer]) > 0) && !is_numeric($pconfig[$timer])))) {
			$pconfig[$timer] = '';
		}
	}
	return $pconfig;
}
