<?php
/*
 * system_certificates.inc
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
 * Copyright (c) 2008 Shrew Soft Inc
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
 * Certificate manager logic shared by system_camanager.php (certificate
 * authorities), system_certmanager.php (certificates),
 * system_crlmanager.php (certificate revocation lists) and the REST API,
 * moved out of the pages with the form fields as parameters. Functions are
 * prefixed pki_. The helpers in the first section (key types and lengths,
 * descriptive name and subject checks, subject building, OpenSSL errors) are
 * shared by every certificate manager page.
 *
 * CAs are stored in ca, certificates in cert, CRLs in crl, all identified by
 * refid. Like the pages, every change is applied at once: a saved or deleted
 * CA rebuilds the operating system trust store; CRL changes refresh the
 * OpenVPN servers' CRLs and reconfigure IPsec; certificates are only stored
 * (the services using one are not touched).
 */

require_once("certs.inc");
require_once("freesense-utils.inc");
require_once("openvpn.inc");
require_once("util.inc");
require_once("vpn.inc");

/* ------------------------------------------------------------------ */
/* Shared by the certificate manager pages                             */
/* ------------------------------------------------------------------ */

/* The RSA key lengths the pages offer. */
function pki_key_lengths() {
	return array("1024", "2048", "3072", "4096", "6144", "7680", "8192", "15360", "16384");
}

/* The key types the pages offer. */
function pki_key_types() {
	return array("RSA", "ECDSA");
}

/* Whether a descriptive name contains characters the pages refuse (?, >, <, &, /, \, ", '). */
function pki_descr_is_invalid($descr) {
	return (bool)preg_match("/[\?\>\<\&\/\\\"\']/", $descr);
}

/*
 * Check the optional subject fields of a form ($prefix "dn_" or "csr_dn_":
 * commonname, state, city, organization, organizationalunit) for characters
 * the pages refuse; adds the pages' messages to $input_errors.
 */
function pki_dn_validate(array $post, array &$input_errors, $prefix = 'dn_') {
	$pattern = '/[^a-zA-Z0-9\ \'\/~`\!@#\$%\^&\*\(\)_\-\+=\{\}\[\]\|;:"\<\>,\.\?\\\]/';
	if (!empty($post["{$prefix}commonname"]) && preg_match($pattern, $post["{$prefix}commonname"])) {
		$input_errors[] = gettext("The field 'Common Name' contains invalid characters.");
	}
	if (!empty($post["{$prefix}state"]) && preg_match($pattern, $post["{$prefix}state"])) {
		$input_errors[] = gettext("The field 'State or Province' contains invalid characters.");
	}
	if (!empty($post["{$prefix}city"]) && preg_match($pattern, $post["{$prefix}city"])) {
		$input_errors[] = gettext("The field 'City' contains invalid characters.");
	}
	if (!empty($post["{$prefix}organization"]) && preg_match($pattern, $post["{$prefix}organization"])) {
		$input_errors[] = gettext("The field 'Organization' contains invalid characters.");
	}
	if (!empty($post["{$prefix}organizationalunit"]) && preg_match($pattern, $post["{$prefix}organizationalunit"])) {
		$input_errors[] = gettext("The field 'Organizational Unit' contains invalid characters.");
	}
}

/* The subject (OpenSSL DN array) of a form's subject fields ($prefix "dn_" or "csr_dn_"). */
function pki_dn_from_form(array $pconfig, $prefix = 'dn_') {
	$dn = array('commonName' => $pconfig["{$prefix}commonname"]);
	if (!empty($pconfig["{$prefix}country"])) {
		$dn['countryName'] = $pconfig["{$prefix}country"];
	}
	if (!empty($pconfig["{$prefix}state"])) {
		$dn['stateOrProvinceName'] = $pconfig["{$prefix}state"];
	}
	if (!empty($pconfig["{$prefix}city"])) {
		$dn['localityName'] = $pconfig["{$prefix}city"];
	}
	if (!empty($pconfig["{$prefix}organization"])) {
		$dn['organizationName'] = $pconfig["{$prefix}organization"];
	}
	if (!empty($pconfig["{$prefix}organizationalunit"])) {
		$dn['organizationalUnitName'] = $pconfig["{$prefix}organizationalunit"];
	}
	return $dn;
}

/* The pending OpenSSL errors as the pages show them after a failed key or certificate creation. */
function pki_openssl_errors() {
	$input_errors = array();
	while ($ssl_err = openssl_error_string()) {
		if (strpos($ssl_err, 'NCONF_get_string:no value') === false) {
			array_push($input_errors, "openssl library returns: " . $ssl_err);
		}
	}
	return $input_errors;
}

/* The package usage lists of the certificate plugins ($event: used_ca, used_certificates or used_crl). */
function pki_package_usage($event) {
	$pluginparams = array();
	$pluginparams['type'] = 'certificates';
	$pluginparams['event'] = $event;
	return pkg_call_plugins('plugin_certificates', $pluginparams);
}

/* The names of the packages that use $refid (from pki_package_usage()). */
function pki_packages_using($refid, $certificates_used_by_packages) {
	$names = array();
	if (is_array($certificates_used_by_packages)) {
		foreach ($certificates_used_by_packages as $package) {
			if (isset($package['certificatelist'][$refid])) {
				$names[] = (string)$package['pkgname'];
			}
		}
	}
	return $names;
}

/* ------------------------------------------------------------------ */
/* Certificate authorities (system_camanager.php)                      */
/* ------------------------------------------------------------------ */

function pki_ca_methods() {
	return array(
		"internal" => gettext("Create an internal Certificate Authority"),
		"existing" => gettext("Import an existing Certificate Authority"),
		"intermediate" => gettext("Create an intermediate Certificate Authority"));
}

/* The lifetime the page proposes for a new CA. */
function pki_ca_default_lifetime() {
	return min(3650, cert_get_max_lifetime());
}

/* The CAs that can sign an intermediate CA (those with a private key): refid => name. */
function pki_ca_signing_list() {
	$allCas = array();
	foreach (config_get_path('ca', []) as $ca) {
		if (!$ca['prv']) {
			continue;
		}

		$allCas[ $ca['refid'] ] = $ca['descr'];
	}
	return $allCas;
}

/* What uses the CA $refid, as the page's "In Use" column lists it. */
function pki_ca_used_by($refid, $certificates_used_by_packages = array()) {
	$used = array();
	if (is_ipsec_peer_ca($refid)) {
		$used[] = gettext("IPsec Tunnel");
	}
	if (is_ldap_peer_ca($refid)) {
		$used[] = gettext("LDAP Server");
	}
	if (is_openvpn_client_ca($refid)) {
		$used[] = gettext("OpenVPN Client");
	}
	if (is_openvpn_server_ca($refid)) {
		$used[] = gettext("OpenVPN Server");
	}
	return array_merge($used, pki_packages_using($refid, $certificates_used_by_packages));
}

/*
 * The page's form for a new CA ($act "new"; $method: the posted method) or
 * for editing the CA $thisca ($act "edit"). The edit form holds the CA's
 * private key in clear ("key"); the API never returns it.
 */
function pki_ca_form($act, $thisca, $method = null) {
	$pconfig = array();
	if ($act == 'edit') {
		/* Editing an existing CA, so populate values. */
		$pconfig['method'] = 'existing';
		$pconfig['descr']  = $thisca['descr'];
		$pconfig['refid']  = $thisca['refid'];
		$pconfig['cert']   = base64_decode($thisca['crt']);
		$pconfig['serial'] = $thisca['serial'];
		$pconfig['trust']  = ($thisca['trust'] == 'enabled');
		$pconfig['randomserial']  = ($thisca['randomserial'] == 'enabled');
		if (!empty($thisca['prv'])) {
			$pconfig['key'] = base64_decode($thisca['prv']);
		}
	} elseif ($act == 'new') {
		/* New CA, so set default values */
		$pconfig['method'] = $method;
		$pconfig['keytype'] = "RSA";
		$pconfig['keylen'] = "2048";
		$pconfig['ecname'] = "prime256v1";
		$pconfig['digest_alg'] = "sha256";
		$pconfig['lifetime'] = pki_ca_default_lifetime();
		$pconfig['dn_commonname'] = "internal-ca";
	}
	return $pconfig;
}

/*
 * Delete the CA $id (refid) like the page: refused while it is in use
 * (ca_in_use(): OpenVPN servers and clients, IPsec phase 1 entries, LDAP
 * servers). Certificates it signed are kept without their CA reference (a
 * matching CA imported later reconnects them); its CRLs are deleted. The trust
 * store is rebuilt. Returns null for an unknown CA, otherwise
 * array('deleted' => bool, 'savemsg' => message, 'class' => info box class).
 */
function pki_ca_delete($id) {
	$ca_item_config = lookup_ca($id);
	$thisca = $ca_item_config['item'];
	if (empty($id) || !$thisca) {
		return null;
	}
	$class = "success";
	$deleted = false;
	$name = htmlspecialchars($thisca['descr']);
	if (ca_in_use($id)) {
		$savemsg = sprintf(gettext("Certificate %s is in use and cannot be deleted"), $name);
		$class = "danger";
	} else {
		/* Only remove CA reference when deleting. It can be reconnected if a new matching CA is imported */
		foreach (config_get_path('cert', []) as $cid => $acrt) {
			if ($acrt['caref'] == $thisca['refid']) {
				config_del_path("cert/{$cid}/caref");
			}
		}
		/* Remove any CRLs for this CA, there is no way to recover the connection once the CA has been removed. */
		foreach (config_get_path('crl', []) as $cid => $acrl) {
			if ($acrl['caref'] == $thisca['refid']) {
				config_del_path("crl/{$cid}");
			}
		}
		/* Delete the CA */
		foreach (config_get_path('ca', []) as $cid => $aca) {
			if ($aca['refid'] == $thisca['refid']) {
				config_del_path("ca/{$cid}");
			}
		}
		$savemsg = sprintf(gettext("Deleted Certificate Authority %s and associated CRLs"), htmlspecialchars($name));
		write_config($savemsg);
		ca_setup_trust_store();
		$deleted = true;
	}
	return array('deleted' => $deleted, 'savemsg' => $savemsg, 'class' => $class);
}

/*
 * Validate and save the CA form $pconfig like the page: a new CA ($act not
 * "edit") is imported (method "existing"), created (internal) or signed by
 * another CA (intermediate); editing ($act "edit", $id the CA's refid) stores
 * the form's name, flags, serial, certificate and private key. On success the
 * configuration is written and the trust store rebuilt; $savemsg is set.
 * Returns the input errors (empty on success).
 */
function pki_ca_save(array &$pconfig, $id, $act, &$savemsg = null) {
	global $openssl_digest_algs;

	$ca_keylens = pki_key_lengths();
	$ca_keytypes = pki_key_types();
	$max_lifetime = cert_get_max_lifetime();
	$openssl_ecnames = cert_build_curve_list();

	$thisca = null;
	if (!empty($id)) {
		$ca_item_config = lookup_ca($id);
		$thisca = $ca_item_config['item'];
	}

	$input_errors = array();
	$reqdfields = null;
	$reqdfieldsn = null;

	/* input validation */
	switch ($pconfig['method']) {
		case 'existing':
			$reqdfields = explode(" ", "descr cert");
			$reqdfieldsn = array(
				gettext("Descriptive name"),
				gettext("Certificate data"));
			/* Make sure we do not have invalid characters in the fields for the certificate */
			if (pki_descr_is_invalid($pconfig['descr'])) {
				array_push($input_errors, gettext("The field 'Descriptive Name' contains invalid characters."));
			}
			if ($pconfig['cert'] && (!strstr($pconfig['cert'], "BEGIN CERTIFICATE") || !strstr($pconfig['cert'], "END CERTIFICATE"))) {
				$input_errors[] = gettext("This certificate does not appear to be valid.");
			}
			if ($pconfig['key'] && strstr($pconfig['key'], "ENCRYPTED")) {
				$input_errors[] = gettext("Encrypted private keys are not yet supported.");
			}
			if (!$input_errors && !empty($pconfig['key']) && cert_get_publickey($pconfig['cert'], false) != cert_get_publickey($pconfig['key'], false, 'prv')) {
				$input_errors[] = gettext("The submitted private key does not match the submitted certificate data.");
			}
			/* we must ensure the certificate is capable of acting as a CA
			 * upstream issue 7885
			 */
			if (!$input_errors) {
				$purpose = cert_get_purpose($pconfig['cert'], false);
				if ($purpose['ca'] != 'Yes') {
					$input_errors[] = gettext("The submitted certificate does not appear to be a Certificate Authority, import it on the Certificates tab instead.");
				}
			}
			break;
		case 'internal':
			$reqdfields = explode(" ",
				"descr keylen ecname keytype lifetime dn_commonname");
			$reqdfieldsn = array(
				gettext("Descriptive name"),
				gettext("Key length"),
				gettext("Elliptic Curve Name"),
				gettext("Key type"),
				gettext("Lifetime"),
				gettext("Common Name"));
			break;
		case 'intermediate':
			$reqdfields = explode(" ",
				"descr caref keylen ecname keytype lifetime dn_commonname");
			$reqdfieldsn = array(
				gettext("Descriptive name"),
				gettext("Signing Certificate Authority"),
				gettext("Key length"),
				gettext("Elliptic Curve Name"),
				gettext("Key type"),
				gettext("Lifetime"),
				gettext("Common Name"));
			/* The signing CA must be one of the page's choices (an unknown one stored a CA without a certificate). */
			if (!empty($pconfig['caref']) && !array_key_exists($pconfig['caref'], pki_ca_signing_list())) {
				$input_errors[] = gettext("Please select a valid Signing Certificate Authority.");
			}
			break;
		default:
			/* The method must be one of the page's choices (anything else stored an empty CA). */
			$input_errors[] = gettext("Please select a valid Method.");
			break;
	}

	do_input_validation($pconfig, $reqdfields, $reqdfieldsn, $input_errors);
	if ($pconfig['method'] != "existing") {
		/* Make sure we do not have invalid characters in the fields for the certificate */
		if (pki_descr_is_invalid($pconfig['descr'])) {
			array_push($input_errors, gettext("The field 'Descriptive Name' contains invalid characters."));
		}
		pki_dn_validate($pconfig, $input_errors);
		if (!in_array($pconfig["keytype"], $ca_keytypes)) {
			array_push($input_errors, gettext("Please select a valid Key Type."));
		}
		if (!in_array($pconfig["keylen"], $ca_keylens)) {
			array_push($input_errors, gettext("Please select a valid Key Length."));
		}
		if (!in_array($pconfig["ecname"], array_keys($openssl_ecnames))) {
			array_push($input_errors, gettext("Please select a valid Elliptic Curve Name."));
		}
		if (!in_array($pconfig["digest_alg"], $openssl_digest_algs)) {
			array_push($input_errors, gettext("Please select a valid Digest Algorithm."));
		}
		if ($pconfig['lifetime'] > $max_lifetime) {
			$input_errors[] = gettext("Lifetime is longer than the maximum allowed value. Use a shorter lifetime.");
		}
	}

	if (!empty($pconfig['serial']) && !cert_validate_serial($pconfig['serial'])) {
		$input_errors[] = gettext("Please enter a valid integer serial number.");
	}

	/* save modifications */
	if (!$input_errors) {
		$ca = array();
		if (!isset($pconfig['refid']) || empty($pconfig['refid'])) {
			$ca['refid'] = uniqid();
		} else {
			$ca['refid'] = $pconfig['refid'];
		}

		if (isset($id) && $thisca) {
			$ca = $thisca;
		}

		$ca['descr'] = $pconfig['descr'];
		$ca['trust'] = ($pconfig['trust'] == 'yes') ? "enabled" : "disabled";
		$ca['randomserial'] = ($pconfig['randomserial'] == 'yes') ? "enabled" : "disabled";

		if ($act == "edit") {
			$ca['descr']  = $pconfig['descr'];
			$ca['refid']  = $pconfig['refid'];
			$ca['serial'] = $pconfig['serial'];
			$ca['crt'] = base64_encode($pconfig['cert']);
			$ca['prv'] = base64_encode($pconfig['key']);
			$savemsg = sprintf(gettext("Updated Certificate Authority %s"), $ca['descr']);
		} else {
			$old_err_level = error_reporting(0); /* otherwise openssl_ functions throw warnings directly to a page screwing menu tab */
			if ($pconfig['method'] == "existing") {
				ca_import($ca, $pconfig['cert'], $pconfig['key'], $pconfig['serial']);
				$savemsg = sprintf(gettext("Imported Certificate Authority %s"), $ca['descr']);
			} else if ($pconfig['method'] == "internal") {
				$dn = pki_dn_from_form($pconfig);
				if (!ca_create($ca, $pconfig['keylen'], $pconfig['lifetime'], $dn, $pconfig['digest_alg'], $pconfig['keytype'], $pconfig['ecname'])) {
					$input_errors = pki_openssl_errors();
				}
				$savemsg = sprintf(gettext("Created internal Certificate Authority %s"), $ca['descr']);
			} else if ($pconfig['method'] == "intermediate") {
				$dn = pki_dn_from_form($pconfig);
				if (!ca_inter_create($ca, $pconfig['keylen'], $pconfig['lifetime'], $dn, $pconfig['caref'], $pconfig['digest_alg'], $pconfig['keytype'], $pconfig['ecname'])) {
					$input_errors = pki_openssl_errors();
				}
				$savemsg = sprintf(gettext("Created internal intermediate Certificate Authority %s"), $ca['descr']);
			}
			error_reporting($old_err_level);
		}

		if (isset($id) && $thisca) {
			config_set_path("ca/{$ca_item_config['idx']}", $ca);
		} else {
			config_set_path('ca/', $ca);
		}

		if (!$input_errors) {
			write_config($savemsg);
			ca_setup_trust_store();
		}
	}

	return $input_errors;
}

/* ------------------------------------------------------------------ */
/* Certificate revocation lists (system_crlmanager.php)                */
/* ------------------------------------------------------------------ */

function pki_crl_methods() {
	return array(
		"internal" => gettext("Create an internal Certificate Revocation List"),
		"existing" => gettext("Import an existing Certificate Revocation List"));
}

/* The lifetime the page proposes for a new internal CRL. */
function pki_crl_default_lifetime() {
	return min(730, crl_get_max_lifetime());
}

/* The page's method choices ($importonly for a CA without a private key: import only). */
function pki_crl_method_list($importonly = false) {
	$list = array();

	foreach (pki_crl_methods() as $method => $desc) {
		if ($importonly && ($method != "existing")) {
			continue;
		}

		$list[$method] = $desc;
	}

	return($list);
}

/* The CAs a new CRL can be created for: refid => name. */
function pki_crl_ca_list() {
	$list = array();

	foreach (config_get_path('ca', []) as $ca) {
		$list[$ca['refid']] = $ca['descr'];
	}

	return($list);
}

/* The certificates of the CRL's CA that the CRL $id does not revoke yet: refid => name. */
function pki_crl_cert_list($crl, $id) {
	$list = array();
	foreach (config_get_path('cert', []) as $cert) {
		if ((isset($cert['caref']) && !empty($cert['caref'])) &&
		    ($cert['caref'] == $crl['caref']) &&
		    !is_cert_revoked($cert, $id)) {
			$list[$cert['refid']] = $cert['descr'];
		}
	}

	return($list);
}

/* What uses the CRL $refid, as the page's "In Use" column lists it. */
function pki_crl_used_by($refid, $certificates_used_by_packages = array()) {
	$used = array();
	if (is_openvpn_server_crl($refid)) {
		$used[] = gettext("OpenVPN Server");
	}
	return array_merge($used, pki_packages_using($refid, $certificates_used_by_packages));
}

/* Remove CRL entries without a reference ID (the page cleans them up whenever it loads; not written). */
function pki_crl_cleanup() {
	foreach (config_get_path('crl', []) as $cid => $acrl) {
		if (!isset($acrl['refid'])) {
			config_del_path("crl/{$cid}");
		}
	}
}

/*
 * The page's form for a new CRL of the CA $caref ($method: the requested
 * method). Returns array('pconfig' => form, 'ca' => the CA or null,
 * 'input_errors' => errors (an unknown CA)).
 */
function pki_crl_new_form($method, $caref) {
	$input_errors = array();
	$pconfig = array();
	$pconfig['method'] = $method;
	$pconfig['caref'] = $caref;
	$pconfig['lifetime'] = pki_crl_default_lifetime();
	$pconfig['serial'] = "0";
	$crlca = lookup_ca($pconfig['caref']);
	$crlca = $crlca['item'];
	if (!$crlca) {
		$input_errors[] = gettext('Invalid CA');
	}
	return array('pconfig' => $pconfig, 'ca' => $crlca, 'input_errors' => $input_errors);
}

/*
 * Delete the CRL $id (refid) like the page: refused while it is in use
 * (crl_in_use(): OpenVPN servers, packages). Returns null for an unknown CRL,
 * otherwise array('deleted' => bool, 'savemsg' => message, 'class' => info box class).
 */
function pki_crl_delete($id) {
	$crl_item_config = lookup_crl($id);
	$thiscrl = $crl_item_config['item'];
	if (empty($id) || !$thiscrl) {
		return null;
	}
	$deleted = false;
	$name = htmlspecialchars($thiscrl['descr']);
	if (crl_in_use($id)) {
		$savemsg = sprintf(gettext("Certificate Revocation List %s is in use and cannot be deleted."), $name);
		$class = "danger";
	} else {
		foreach (config_get_path('crl', []) as $cid => $acrl) {
			if ($acrl['refid'] == $thiscrl['refid']) {
				config_del_path("crl/{$cid}");
			}
		}
		write_config("Deleted CRL {$name}.");
		$savemsg = sprintf(gettext("Certificate Revocation List %s successfully deleted."), $name);
		$class = "success";
		$deleted = true;
	}
	return array('deleted' => $deleted, 'savemsg' => $savemsg, 'class' => $class);
}

/*
 * The internal CRL edit form's post (act "addcert"; $pconfig: the request,
 * $post: the posted fields for the required field check): saves the CRL's
 * name, lifetime and serial ("save" set) and revokes the selected
 * certificates ("certref" list) and serials ("revokeserial", separated by
 * spaces) with the reason "crlreason". Revocations refresh the OpenVPN CRLs
 * and reconfigure IPsec. Returns null when the post names no CRL ("crlref"),
 * otherwise the input errors (empty on success: the configuration is written).
 */
function pki_crl_revoke(array &$pconfig, array $post) {
	global $openssl_crl_status;

	$max_lifetime = crl_get_max_lifetime();
	$input_errors = array();

	/* input validation */
	$reqdfields = explode(" ", "descr id");
	$reqdfieldsn = array(
		gettext("Descriptive name"),
		gettext("CRL ID"));

	do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);

	if (pki_descr_is_invalid($pconfig['descr'])) {
		array_push($input_errors, "The field 'Descriptive Name' contains invalid characters.");
	}
	if ($pconfig['lifetime'] > $max_lifetime) {
		$input_errors[] = gettext("Lifetime is longer than the maximum allowed value. Use a shorter lifetime.");
	}
	if ((strlen($pconfig['serial']) > 0) && !cert_validate_serial($pconfig['serial'])) {
		$input_errors[] = gettext("Please enter a valid integer serial number.");
	}

	$revoke_list = array();
	if (!$pconfig['crlref']) {
		return null;
	}
	$crl_item_config = lookup_crl($pconfig['crlref']);
	$crl = &$crl_item_config['item'];

	if (!is_array($pconfig['certref'])) {
		$pconfig['certref'] = array();
	}
	if (!is_crl_internal($crl)) {
		$input_errors[] = gettext("Cannot revoke certificates for an imported/external CRL.");
	}
	if (!empty($pconfig['revokeserial'])) {
		foreach (explode(' ', $pconfig['revokeserial']) as $serial) {
			$vserial = cert_validate_serial($serial, true, true);
			if ($vserial != null) {
				$revoke_list[] = $vserial;
			} else {
				$input_errors[] = gettext("Invalid serial in list (Must be ASN.1 integer compatible decimal or hex string).");
			}
		}
	}
	if (empty($pconfig['save']) && empty($pconfig['certref']) && empty($revoke_list)) {
		$input_errors[] = gettext("Select one or more certificates or enter a serial number to revoke.");
	}
	/* The reason must be one of the page's choices (anything was stored). */
	if (!empty($pconfig['crlreason']) && (!is_scalar($pconfig['crlreason']) || !array_key_exists($pconfig['crlreason'], $openssl_crl_status))) {
		$input_errors[] = gettext("Please select a valid revocation reason.");
	}
	foreach ($pconfig['certref'] as $rcert) {
		$cert = lookup_cert($rcert);
		$cert = $cert['item'];
		if ($crl['caref'] == $cert['caref']) {
			$revoke_list[] = $cert;
		} else {
			$input_errors[] = gettext("CA mismatch between the Certificate and CRL. Unable to Revoke.");
		}
	}

	if (!$input_errors) {
		$crl['descr'] = $pconfig['descr'];
		$crl['lifetime'] = $pconfig['lifetime'];
		$crl['serial'] = $pconfig['serial'];
		if (!empty($revoke_list)) {
			$savemsg = "Revoked certificate(s) in CRL {$crl['descr']}.";
			$reason = (empty($pconfig['crlreason'])) ? 0 : $pconfig['crlreason'];
			foreach ($revoke_list as $cert) {
				cert_revoke($cert, $crl_item_config, $reason);
			}
			// refresh IPsec and OpenVPN CRLs
			openvpn_refresh_crls();
			ipsec_configure();
		} else {
			$savemsg = "Saved CRL {$crl['descr']}.";
			/* Store the changed name, lifetime and serial (only revocations stored them). */
			if ($crl_item_config['idx'] !== null) {
				config_set_path("crl/{$crl_item_config['idx']}", $crl);
			}
		}
		write_config($savemsg);
	}
	return $input_errors;
}

/*
 * Remove a revoked certificate from the CRL (the page's delcert action):
 * the entry with the certificate refid $certref or, when $serial is given,
 * the entry revoked by that serial (without a certificate). $crl_item_config
 * (lookup_crl()) is updated in place. Refreshes the OpenVPN CRLs and
 * reconfigures IPsec. Returns null when the CRL has no such entry, otherwise
 * array('removed' => bool, 'savemsg' => message, 'class' => info box class).
 */
function pki_crl_unrevoke(array &$crl_item_config, $certref, $serial = null) {
	$thiscrl = &$crl_item_config['item'];
	if (!is_array($thiscrl['cert'])) {
		return null;
	}
	$found = false;
	foreach ($thiscrl['cert'] as $acert) {
		if ($serial !== null) {
			if (empty($acert['refid']) && ((string)crl_get_entry_serial($acert) === (string)$serial)) {
				$found = true;
				$thiscert = $acert;
			}
		} elseif ($acert['refid'] == $certref) {
			$found = true;
			$thiscert = $acert;
		}
	}
	if (!$found) {
		return null;
	}
	$certname = htmlspecialchars($thiscert['descr']);
	$crlname = htmlspecialchars($thiscrl['descr']);
	if (cert_unrevoke($thiscert, $crl_item_config)) {
		$savemsg = sprintf(gettext('Deleted Certificate %1$s from CRL %2$s.'), $certname, $crlname);
		$class = "success";
		$removed = true;
		// refresh IPsec and OpenVPN CRLs
		openvpn_refresh_crls();
		ipsec_configure();
		write_config($savemsg);
	} else {
		$savemsg = sprintf(gettext('Failed to delete Certificate %1$s from CRL %2$s.'), $certname, $crlname);
		$class = "danger";
		$removed = false;
	}
	return array('removed' => $removed, 'savemsg' => $savemsg, 'class' => $class);
}

/* The CRL in PEM format as the page exports it (an internal CRL is signed again first; not written). */
function pki_crl_export(array &$crl_item_config) {
	crl_update($crl_item_config);
	return base64_decode($crl_item_config['item']['text']);
}

/*
 * Validate and save the new CRL form or the imported CRL edit form ($act
 * "editimported") like the page; $crl_item_config is the edited CRL
 * (lookup_crl(), updated in place) or null for a new one. On success the
 * configuration is written, the OpenVPN CRLs are refreshed and IPsec is
 * reconfigured. Returns the input errors (empty on success).
 */
function pki_crl_save(array &$pconfig, &$crl_item_config, $act) {
	$max_lifetime = crl_get_max_lifetime();
	$default_lifetime = pki_crl_default_lifetime();
	$thiscrl = null;
	if (is_array($crl_item_config) && $crl_item_config['item']) {
		$thiscrl = &$crl_item_config['item'];
	}

	$input_errors = array();
	$reqdfields = null;
	$reqdfieldsn = null;

	/* input validation */
	if (($pconfig['method'] == "existing") || ($act == "editimported")) {
		$reqdfields = explode(" ", "descr crltext");
		$reqdfieldsn = array(
			gettext("Descriptive name"),
			gettext("Certificate Revocation List data"));
	}
	if (($pconfig['method'] == "internal") ||
	    ($act == "addcert")) {
		$reqdfields = explode(" ", "descr caref");
		$reqdfieldsn = array(
			gettext("Descriptive name"),
			gettext("Certificate Authority"));
	}

	do_input_validation($pconfig, $reqdfields, $reqdfieldsn, $input_errors);

	if (pki_descr_is_invalid($pconfig['descr'])) {
		array_push($input_errors, "The field 'Descriptive Name' contains invalid characters.");
	}
	if ($pconfig['lifetime'] > $max_lifetime) {
		$input_errors[] = gettext("Lifetime is longer than the maximum allowed value. Use a shorter lifetime.");
	}

	if ((strlen($pconfig['serial']) > 0) && !cert_validate_serial($pconfig['serial'])) {
		$input_errors[] = gettext("Please enter a valid integer serial number.");
	}

	/* The method must be one of the page's choices (import only for a CA without a private key; anything was stored). */
	if (($act != "editimported") && !$thiscrl) {
		$crlca = lookup_ca($pconfig['caref']);
		$crlca = $crlca['item'];
		if (!array_key_exists($pconfig['method'], pki_crl_method_list((!isset($crlca['prv']) || empty($crlca['prv']))))) {
			$input_errors[] = gettext("Please select a valid Method.");
		}
	}

	/* save modifications */
	if (!$input_errors) {
		$result = false;

		if ($thiscrl) {
			$crl =& $thiscrl;
		} else {
			$crl = array();
			$crl['refid'] = uniqid();
		}

		$crl['descr'] = $pconfig['descr'];
		if ($act != "editimported") {
			$crl['caref'] = $pconfig['caref'];
			$crl['method'] = $pconfig['method'];
		}

		if (($pconfig['method'] == "existing") || ($act == "editimported")) {
			$crl['text'] = base64_encode($pconfig['crltext']);
		}

		if ($pconfig['method'] == "internal") {
			$crl['serial'] = empty($pconfig['serial']) ? '0' : $pconfig['serial'];
			$crl['lifetime'] = empty($pconfig['lifetime']) ? $default_lifetime : $pconfig['lifetime'];
			$crl['cert'] = array();
		}

		if (!$thiscrl) {
			config_set_path('crl/', $crl);
		} else {
			config_set_path("crl/{$crl_item_config['idx']}", $crl);
		}

		write_config("Saved CRL {$crl['descr']}");
		// refresh IPsec and OpenVPN CRLs
		openvpn_refresh_crls();
		ipsec_configure();
	}
	return $input_errors;
}

/* ------------------------------------------------------------------ */
/* Certificates (system_certmanager.php)                               */
/* ------------------------------------------------------------------ */

/*
 * The page's method choices: create, import, create a signing request, sign a
 * signing request; also "existing" (add an existing certificate) when adding a
 * certificate to the user $userid; only "edit" when editing ($act "edit").
 */
function pki_cert_methods($userid = null, $act = null) {
	$cert_methods = array(
		"internal" => gettext("Create an internal Certificate"),
		"import" => gettext("Import an existing Certificate"),
		"external" => gettext("Create a Certificate Signing Request"),
		"sign" => gettext("Sign a Certificate Signing Request")
	);

	if (isset($userid)) {
		$cert_methods["existing"] = gettext("Choose an existing certificate");
	}

	if ($act == 'edit') {
		$cert_methods = array(
			'edit' => gettext("Edit an existing certificate")
		);
	}
	return $cert_methods;
}

/* The certificate types the page offers. */
function pki_cert_types() {
	return array(
		"server" => "Server Certificate",
		"user" => "User Certificate");
}

/* The lifetime the page proposes for a new or signed certificate. */
function pki_cert_default_lifetime() {
	return min(3650, cert_get_max_lifetime());
}

/* The signing requests the sign form offers ("new": paste one, then the pending requests): refid => name. */
function pki_cert_csr_list() {
	$allCsrs = array();

	foreach (config_get_path('cert', []) as $cert) {
		if ($cert['csr']) {
			$allCsrs[$cert['refid']] = $cert['descr'];
		}
	}

	return ['new' => gettext('New CSR (Paste below)')] + $allCsrs;
}

/* The certificates that can be added to the user $userid (all but the user's own): refid => name with CA, use and revocation notes. */
function pki_cert_existing_list($userid = null) {
	$existCerts = array();

	foreach (config_get_path('cert', []) as $cert) {
		if (!is_array($cert) || empty($cert)) {
			continue;
		}

		if (isset($userid) &&
		    in_array($cert['refid'], config_get_path("system/user/{$userid}/cert", []))) {
			continue;
		}

		$ca = lookup_ca($cert['caref']);
		$ca = $ca['item'];
		if ($ca) {
			$cert['descr'] .= " (CA: {$ca['descr']})";
		}

		if (cert_in_use($cert['refid'])) {
			$cert['descr'] .= " (In Use)";
		}
		if (is_cert_revoked($cert)) {
			$cert['descr'] .= " (Revoked)";
		}

		$existCerts[ $cert['refid'] ] = $cert['descr'];
	}
	return $existCerts;
}

/* What uses the certificate $refid, as the page's "In Use" column lists it (packages by name). */
function pki_cert_used_by($refid, $certificates_used_by_packages = array()) {
	$used = array();
	if (is_captiveportal_cert($refid)) {
		$used[] = gettext("Captive Portal");
	}
	if (is_unbound_cert($refid)) {
		$used[] = gettext("DNS Resolver");
	}
	if (is_ipsec_cert($refid)) {
		$used[] = gettext("IPsec Tunnel");
	}
	if (is_kea_cert((string)$refid)) {
		$used[] = gettext("Kea");
	}
	if (is_openvpn_client_cert($refid)) {
		$used[] = gettext("OpenVPN Client");
	}
	if (is_openvpn_server_cert($refid)) {
		$used[] = gettext("OpenVPN Server");
	}
	if (is_user_cert($refid)) {
		$used[] = gettext("User Cert");
	}
	if (is_webgui_cert($refid)) {
		$used[] = gettext("webConfigurator");
	}
	return array_merge($used, pki_packages_using($refid, $certificates_used_by_packages));
}

/* The page's form for a new certificate ($method: the posted method). */
function pki_cert_new_form($method) {
	$pconfig = array();
	$pconfig['method'] = $method;
	$pconfig['keytype'] = "RSA";
	$pconfig['keylen'] = "2048";
	$pconfig['ecname'] = "prime256v1";
	$pconfig['digest_alg'] = "sha256";
	$pconfig['csr_keytype'] = "RSA";
	$pconfig['csr_keylen'] = "2048";
	$pconfig['csr_ecname'] = "prime256v1";
	$pconfig['csr_digest_alg'] = "sha256";
	$pconfig['csrsign_digest_alg'] = "sha256";
	$pconfig['type'] = "user";
	$pconfig['lifetime'] = pki_cert_default_lifetime();
	return $pconfig;
}

/*
 * The page's form for editing the certificate $thiscert ($act "edit": name,
 * auto renewal, certificate and private key in clear - the API never returns
 * it) or for completing its signing request ($act "csr": name and request).
 */
function pki_cert_form($act, $thiscert) {
	$pconfig = array();
	if ($act == 'edit') {
		/* Editing a certificate, so populate values */
		$pconfig['descr'] = $thiscert['descr'];
		$pconfig['autorenew']  = ($thiscert['autorenew'] == 'enabled');
		$pconfig['cert'] = base64_decode($thiscert['crt']);
		$pconfig['key'] = base64_decode($thiscert['prv']);
	} elseif ($act == 'csr') {
		/* Editing a CSR, so populate values */
		$pconfig['descr'] = $thiscert['descr'];
		$pconfig['csr'] = base64_decode($thiscert['csr']);
	}
	return $pconfig;
}

/*
 * Delete the certificate $id (refid) like the page: refused while it is in
 * use (cert_in_use(): webConfigurator, users, OpenVPN, IPsec, captive portal,
 * DNS Resolver, Kea, packages). Returns null for an unknown certificate,
 * otherwise array('deleted' => bool, 'savemsg' => message, 'class' => info box class).
 */
function pki_cert_delete($id) {
	$cert_item_config = lookup_cert($id);
	$thiscert = $cert_item_config['item'];
	if (empty($id) || !$thiscert) {
		return null;
	}
	$class = "success";
	$deleted = false;
	$name = htmlspecialchars($thiscert['descr']);
	if (cert_in_use($id)) {
		$savemsg = sprintf(gettext("Certificate %s is in use and cannot be deleted"), $name);
		$class = "danger";
	} else {
		foreach (config_get_path('cert', []) as $cid => $acrt) {
			if ($acrt['refid'] == $thiscert['refid']) {
				config_del_path("cert/{$cid}");
			}
		}
		$savemsg = sprintf(gettext("Deleted certificate %s"), $name);
		write_config($savemsg);
		$deleted = true;
	}
	return array('deleted' => $deleted, 'savemsg' => $savemsg, 'class' => $class);
}

/*
 * Validate and save the certificate form $pconfig (the page's Save) like the
 * page. Methods: internal (create, signed by an internal CA), import (PEM
 * certificate and key, or the PKCS #12 store $pkcs12_file with its password),
 * external (create a key and signing request), sign (sign a pasted or pending
 * request with an internal CA: a new certificate), edit ($act "edit", $id the
 * certificate's refid: name, auto renewal, certificate and key) and, for the
 * user $userid (position in system/user), existing (add a certificate to the
 * user). A certificate created for $userid is also added to the user. On
 * success the configuration is written and $savemsg set; $unset_act tells
 * whether the page leaves its current action (shows the list, or re-renders
 * the form without it). Returns the input errors (empty on success).
 */
function pki_cert_save(array &$pconfig, $id, $act, $userid = null, $pkcs12_file = null, &$savemsg = null, &$unset_act = null) {
	global $openssl_digest_algs;

	$cert_keylens = pki_key_lengths();
	$cert_keytypes = pki_key_types();
	$max_lifetime = cert_get_max_lifetime();
	$openssl_ecnames = cert_build_curve_list();
	$unset_act = false;

	$thiscert = null;
	$cert_item_config = null;
	if (!empty($id)) {
		$cert_item_config = lookup_cert($id);
		$thiscert = $cert_item_config['item'];
	}

	/* The posted fields (the page read them from the request; $pconfig started as a copy). */
	$post = $pconfig;
	$input_errors = array();
	$reqdfields = null;
	$reqdfieldsn = null;

	/* The method must be one of the page's choices (an unknown one stored an empty certificate). */
	if (!is_scalar($pconfig['method']) || !array_key_exists((string)$pconfig['method'], pki_cert_methods($userid, $act))) {
		$input_errors[] = gettext("Please select a valid Method.");
	}
	/* The user must exist (a certificate added to an unknown user position created a user without a name). */
	if (isset($userid) && !is_array(config_get_path("system/user/{$userid}"))) {
		$input_errors[] = gettext("The user to add the certificate to does not exist.");
	}

	switch ($pconfig['method']) {
		case 'sign':
			$reqdfields = explode(" ",
				"descr catosignwith");
			$reqdfieldsn = array(
				gettext("Descriptive name"),
				gettext("CA to sign with"));

			if (($post['csrtosign'] === "new") &&
			    ((!strstr($post['csrpaste'], "BEGIN CERTIFICATE REQUEST") || !strstr($post['csrpaste'], "END CERTIFICATE REQUEST")) &&
			    (!strstr($post['csrpaste'], "BEGIN NEW CERTIFICATE REQUEST") || !strstr($post['csrpaste'], "END NEW CERTIFICATE REQUEST")))) {
				$input_errors[] = gettext("This signing request does not appear to be valid.");
			}

			if ( (($post['csrtosign'] === "new") && (strlen($post['keypaste']) > 0)) &&
			    ((!strstr($post['keypaste'], "BEGIN PRIVATE KEY") && !strstr($post['keypaste'], "BEGIN EC PRIVATE KEY")) ||
			    (strstr($post['keypaste'], "BEGIN PRIVATE KEY") && !strstr($post['keypaste'], "END PRIVATE KEY")) ||
			    (strstr($post['keypaste'], "BEGIN EC PRIVATE KEY") && !strstr($post['keypaste'], "END EC PRIVATE KEY")))) {
				$input_errors[] = gettext("This private does not appear to be valid.");
				$input_errors[] = gettext("Key data field should be blank, or a valid x509 private key");
			}

			/* The signed certificate's lifetime is csrsign_lifetime (the internal certificate's field was checked). */
			if ($post['csrsign_lifetime'] > $max_lifetime) {
				$input_errors[] = gettext("Lifetime is longer than the maximum allowed value. Use a shorter lifetime.");
			}
			/* The CA and the request must be the page's choices (an unknown CA appended an empty CA, an unknown
			 * request wrote the CA's next serial without a certificate). */
			if (!empty($post['catosignwith']) && (!is_scalar($post['catosignwith']) ||
			    !array_key_exists((string)$post['catosignwith'], pki_ca_signing_list()))) {
				$input_errors[] = gettext("Please select a valid CA to sign with.");
			}
			if (!is_scalar($post['csrtosign']) || !array_key_exists((string)$post['csrtosign'], pki_cert_csr_list())) {
				$input_errors[] = gettext("Please select a valid CSR to sign.");
			}
			break;
		case 'edit':
		case 'import':
			/* Make sure we do not have invalid characters in the fields for the certificate */
			if (pki_descr_is_invalid($post['descr'])) {
				$input_errors[] = gettext("The field 'Descriptive Name' contains invalid characters.");
			}
			$pkcs12_data = '';
			if ($post['import_type'] == 'x509') {
				$reqdfields = explode(" ",
					"descr cert");
				$reqdfieldsn = array(
					gettext("Descriptive name"),
					gettext("Certificate data"));
				if ($post['cert'] && (!strstr($post['cert'], "BEGIN CERTIFICATE") || !strstr($post['cert'], "END CERTIFICATE"))) {
					$input_errors[] = gettext("This certificate does not appear to be valid.");
				}

				if ($post['key'] && (cert_get_publickey($post['cert'], false) != cert_get_publickey($post['key'], false, 'prv'))) {
					$input_errors[] = gettext("The submitted private key does not match the submitted certificate data.");
				}
			} else {
				$reqdfields = array('descr');
				$reqdfieldsn = array(gettext("Descriptive name"));
				if (($pkcs12_file !== null) && ($pkcs12_file !== false)) {
					if (!openssl_pkcs12_read($pkcs12_file, $pkcs12_data, $post['pkcs12_pass'])) {
						$input_errors[] = gettext("The submitted password does not unlock the submitted PKCS #12 certificate or the bundle uses unsupported encryption ciphers.");
					}
				} else {
					$input_errors[] = gettext("A PKCS #12 certificate store was not uploaded.");
				}
			}
			break;
		case 'internal':
			$reqdfields = explode(" ",
				"descr caref keylen ecname keytype type lifetime dn_commonname");
			$reqdfieldsn = array(
				gettext("Descriptive name"),
				gettext("Certificate authority"),
				gettext("Key length"),
				gettext("Elliptic Curve Name"),
				gettext("Key type"),
				gettext("Certificate Type"),
				gettext("Lifetime"),
				gettext("Common Name"));
			if ($post['lifetime'] > $max_lifetime) {
				$input_errors[] = gettext("Lifetime is longer than the maximum allowed value. Use a shorter lifetime.");
			}
			/* The CA must be one of the page's choices (an unknown CA stored an entry without a certificate). */
			if (!empty($post['caref']) && (!is_scalar($post['caref']) || !array_key_exists((string)$post['caref'], pki_ca_signing_list()))) {
				$input_errors[] = gettext("Please select a valid Certificate Authority.");
			}
			break;
		case 'external':
			$reqdfields = explode(" ",
				"descr csr_keylen csr_ecname csr_keytype csr_dn_commonname");
			$reqdfieldsn = array(
				gettext("Descriptive name"),
				gettext("Key length"),
				gettext("Elliptic Curve Name"),
				gettext("Key type"),
				gettext("Common Name"));
			break;
		case 'existing':
			$reqdfields = array("certref");
			$reqdfieldsn = array(gettext("Existing Certificate Choice"));
			break;
		default:
			break;
	}

	$altnames = array();
	do_input_validation($post, $reqdfields, $reqdfieldsn, $input_errors);

	if (!in_array($pconfig['method'], array('edit', 'import', 'existing'))) {
		/* subjectAltNames */
		$san_typevar = 'altname_type';
		$san_valuevar = 'altname_value';
		// This is just the blank alternate name that is added for display purposes. We don't want to validate/save it
		if ($post["{$san_valuevar}0"] == "") {
			unset($post["{$san_typevar}0"]);
			unset($post["{$san_valuevar}0"]);
		}
		foreach ($post as $key => $value) {
			$entry = '';
			if (!substr_compare($san_typevar, $key, 0, strlen($san_typevar))) {
				$entry = substr($key, strlen($san_typevar));
				$field = 'type';
			} elseif (!substr_compare($san_valuevar, $key, 0, strlen($san_valuevar))) {
				$entry = substr($key, strlen($san_valuevar));
				$field = 'value';
			}

			if (ctype_digit(strval($entry))) {
				$entry++;	// Pre-bootstrap code is one-indexed, but the bootstrap code is 0-indexed
				$altnames[$entry][$field] = $value;
			}
		}

		$pconfig['altnames']['item'] = $altnames;

		/* Input validation for subjectAltNames */
		foreach ($altnames as $idx => $altname) {
			/* Skip SAN entries with empty values
			 * upstream issue 14183
			 */
			if (empty($altname['value'])) {
				unset($altnames[$idx]);
				continue;
			}
			switch ($altname['type']) {
				case "DNS":
					if (!is_hostname($altname['value'], true) || is_ipaddr($altname['value'])) {
						$input_errors[] = gettext("DNS subjectAltName values must be valid hostnames, FQDNs or wildcard domains.");
					}
					break;
				case "IP":
					if (!is_ipaddr($altname['value'])) {
						$input_errors[] = gettext("IP subjectAltName values must be valid IP Addresses");
					}
					break;
				case "email":
					if (empty($altname['value'])) {
						$input_errors[] = gettext("An e-mail address must be provided for this type of subjectAltName");
					}
					if (preg_match("/[\!\#\$\%\^\(\)\~\?\>\<\&\/\\\,\"\']/", $altname['value'])) {
						$input_errors[] = gettext("The e-mail provided in a subjectAltName contains invalid characters.");
					}
					break;
				case "URI":
					/* Close enough? */
					if (!is_URL($altname['value'])) {
						$input_errors[] = gettext("URI subjectAltName types must be a valid URI");
					}
					break;
				default:
					$input_errors[] = gettext("Unrecognized subjectAltName type.");
			}
		}

		/* Make sure we do not have invalid characters in the fields for the certificate */
		if (pki_descr_is_invalid($post['descr'])) {
			$input_errors[] = gettext("The field 'Descriptive Name' contains invalid characters.");
		}
		pki_dn_validate($post, $input_errors);

		switch ($pconfig['method']) {
			case "internal":
				if (isset($post["keytype"]) && !in_array($post["keytype"], $cert_keytypes)) {
					$input_errors[] = gettext("Please select a valid Key Type.");
				}
				if (isset($post["keylen"]) && !in_array($post["keylen"], $cert_keylens)) {
					$input_errors[] = gettext("Please select a valid Key Length.");
				}
				if (isset($post["ecname"]) && !in_array($post["ecname"], array_keys($openssl_ecnames))) {
					$input_errors[] = gettext("Please select a valid Elliptic Curve Name.");
				}
				if (!in_array($post["digest_alg"], $openssl_digest_algs)) {
					$input_errors[] = gettext("Please select a valid Digest Algorithm.");
				}
				break;
			case "external":
				if (isset($post["csr_keytype"]) && !in_array($post["csr_keytype"], $cert_keytypes)) {
					$input_errors[] = gettext("Please select a valid Key Type.");
				}
				if (isset($post["csr_keylen"]) && !in_array($post["csr_keylen"], $cert_keylens)) {
					$input_errors[] = gettext("Please select a valid Key Length.");
				}
				if (isset($post["csr_ecname"]) && !in_array($post["csr_ecname"], array_keys($openssl_ecnames))) {
					$input_errors[] = gettext("Please select a valid Elliptic Curve Name.");
				}
				if (!in_array($post["csr_digest_alg"], $openssl_digest_algs)) {
					$input_errors[] = gettext("Please select a valid Digest Algorithm.");
				}
				break;
			case "sign":
				if (!in_array($post["csrsign_digest_alg"], $openssl_digest_algs)) {
					$input_errors[] = gettext("Please select a valid Digest Algorithm.");
				}
				break;
			default:
				break;
		}
		/* The type must be one of the page's choices (any other value created a CA or a self-signed certificate). */
		if (in_array($pconfig['method'], array('internal', 'external', 'sign'), true) && ($post['type'] != '') &&
		    (!is_scalar($post['type']) || !array_key_exists((string)$post['type'], pki_cert_types()))) {
			$input_errors[] = gettext("Please select a valid Certificate Type.");
		}
	}

	/* save modifications */
	if (!$input_errors) {
		$old_err_level = error_reporting(0); /* otherwise openssl_ functions throw warnings directly to a page breaking menu tabs */

		if (isset($id) && $thiscert) {
			$cert = $thiscert;
		} else {
			$cert = array();
			$cert['refid'] = uniqid();
		}

		$cert['descr'] = $pconfig['descr'];
		$cert['autorenew'] = ($pconfig['autorenew'] == 'yes') ? "enabled" : "disabled";

		switch($pconfig['method']) {
			case 'existing':
				/* Add an existing certificate to a user */
				$ucert = lookup_cert($pconfig['certref']);
				$ucert = $ucert['item'];
				if ($ucert && config_get_path("system/user")) {
					config_set_path("system/user/{$userid}/cert/", $ucert['refid']);
					$savemsg = sprintf(gettext("Added certificate %s to user %s"), htmlspecialchars($ucert['descr']), config_get_path("system/user/{$userid}/name"));
				}
				unset($cert);
				break;
			case 'sign':
				/* Sign a CSR */
				$csrid = lookup_cert($pconfig['csrtosign']);
				$csrid = $csrid['item'];
				$ca_item_config = lookup_ca($pconfig['catosignwith']);
				$ca = &$ca_item_config['item'];
				// Read the CSR from config array, or if a new one, from the textarea
				if ($pconfig['csrtosign'] === "new") {
					$csr = $pconfig['csrpaste'];
				} else {
					$csr = base64_decode($csrid['csr']);
				}
				$altname_str = null;
				if (count($altnames)) {
					foreach ($altnames as $altname) {
						$altnames_tmp[] = "{$altname['type']}:" . $altname['value'];
					}
					$altname_str = implode(",", $altnames_tmp);
				}
				$n509 = csr_sign($csr, $ca, $pconfig['csrsign_lifetime'], $pconfig['type'], $altname_str, $pconfig['csrsign_digest_alg']);
				config_set_path("ca/{$ca_item_config['idx']}", $ca);
				if ($n509) {
					// Gather the details required to save the new cert
					$newcert = array();
					$newcert['refid'] = uniqid();
					$newcert['caref'] = $pconfig['catosignwith'];
					$newcert['descr'] = $pconfig['descr'];
					$newcert['type'] = $pconfig['type'];
					$newcert['crt'] = base64_encode($n509);
					if ($pconfig['csrtosign'] === "new") {
						$newcert['prv'] = base64_encode($pconfig['keypaste']);
					} else {
						$newcert['prv'] = $csrid['prv'];
					}
					// Add it to the config file
					config_set_path('cert/', $newcert);
					$savemsg = sprintf(gettext("Signed certificate %s"), htmlspecialchars($newcert['descr']));
					$unset_act = true;
				} else {
					/* A request that cannot be signed is refused (the CA's next serial was written without a certificate). */
					$input_errors[] = gettext("The certificate signing request could not be signed.");
				}
				unset($cert);
				break;
			case 'edit':
				cert_import($cert, $pconfig['cert'], $pconfig['key']);
				$savemsg = sprintf(gettext("Edited certificate %s"), htmlspecialchars($cert['descr']));
				$unset_act = true;
				break;
			case 'import':
				/* Import an external certificate+key */
				if ($pkcs12_data) {
					$pconfig['cert'] = $pkcs12_data['cert'];
					$pconfig['key'] = $pkcs12_data['pkey'];
					if ($post['pkcs12_intermediate'] && is_array($pkcs12_data['extracerts'])) {
						foreach ($pkcs12_data['extracerts'] as $intermediate) {
							$int_data = openssl_x509_parse($intermediate);
							if (!$int_data) continue;
							$cn = $int_data['subject']['CN'];
							$int_ca = array('descr' => $cn, 'refid' => uniqid());
							if (ca_import($int_ca, $intermediate)) {
								config_set_path('ca/', $int_ca);
							}
						}
					}
				}
				cert_import($cert, $pconfig['cert'], $pconfig['key']);
				$savemsg = sprintf(gettext("Imported certificate %s"), htmlspecialchars($cert['descr']));
				$unset_act = true;
				break;
			case 'internal':
				/* Create an internal certificate */
				$dn = pki_dn_from_form($pconfig);
				$altnames_tmp = array();
				$cn_altname = cert_add_altname_type($pconfig['dn_commonname']);
				if (!empty($cn_altname)) {
					$altnames_tmp[] = $cn_altname;
				}
				if (count($altnames)) {
					foreach ($altnames as $altname) {
						// The CN is added as a SAN automatically, do not add it again.
						if ($altname['value'] != $pconfig['dn_commonname']) {
							$altnames_tmp[] = "{$altname['type']}:" . $altname['value'];
						}
					}
				}
				if (!empty($altnames_tmp)) {
					$dn['subjectAltName'] = implode(",", $altnames_tmp);
				}
				if (!cert_create($cert, $pconfig['caref'], $pconfig['keylen'], $pconfig['lifetime'], $dn, $pconfig['type'], $pconfig['digest_alg'], $pconfig['keytype'], $pconfig['ecname'])) {
					$input_errors = array();
					while ($ssl_err = openssl_error_string()) {
						if (strpos($ssl_err, 'NCONF_get_string:no value') === false) {
							$input_errors[] = sprintf(gettext("OpenSSL Library Error: %s"), $ssl_err);
						}
					}
				}
				$savemsg = sprintf(gettext("Created internal certificate %s"), htmlspecialchars($cert['descr']));
				$unset_act = true;
				break;
			case 'external':
				/* Create a certificate signing request */
				$dn = pki_dn_from_form($pconfig, 'csr_dn_');
				$altnames_tmp = array();
				$cn_altname = cert_add_altname_type($pconfig['csr_dn_commonname']);
				if (!empty($cn_altname)) {
					$altnames_tmp[] = $cn_altname;
				}
				if (count($altnames)) {
					foreach ($altnames as $altname) {
						// The CN is added as a SAN automatically, do not add it again.
						if ($altname['value'] != $pconfig['csr_dn_commonname']) {
							$altnames_tmp[] = "{$altname['type']}:" . $altname['value'];
						}
					}
				}
				if (!empty($altnames_tmp)) {
					$dn['subjectAltName'] = implode(",", $altnames_tmp);
				}
				if (!csr_generate($cert, $pconfig['csr_keylen'], $dn, $pconfig['type'], $pconfig['csr_digest_alg'], $pconfig['csr_keytype'], $pconfig['csr_ecname'])) {
					$input_errors = array();
					while ($ssl_err = openssl_error_string()) {
						if (strpos($ssl_err, 'NCONF_get_string:no value') === false) {
							$input_errors[] = sprintf(gettext("OpenSSL Library Error: %s"), $ssl_err);
						}
					}
				}
				$savemsg = sprintf(gettext("Created certificate signing request %s"), htmlspecialchars($cert['descr']));
				$unset_act = true;
				break;
			default:
				break;
		}
		error_reporting($old_err_level);

		if (isset($id) && $thiscert) {
			config_set_path("cert/{$cert_item_config['idx']}", $cert);
		} elseif ($cert) {
			config_set_path('cert/', $cert);
		}

		/* A certificate created here is added ($cert is unset for an existing or a signed one). */
		if (isset($userid) && (config_get_path('system/user') !== null) && isset($cert) && !empty($cert['refid'])) {
			config_set_path("system/user/{$userid}/cert/", $cert['refid']);
		}

		if (!$input_errors) {
			write_config($savemsg);
		}
	}
	return $input_errors;
}

/*
 * Complete the signing request of the certificate $cert_item_config
 * (lookup_cert(), updated in place) with the signed certificate (the page's
 * Update; $pconfig: descr, cert). The certificate must match the stored
 * request. On success the configuration is written and $savemsg set. Returns
 * the input errors (empty on success).
 */
function pki_cert_csr_complete(array &$pconfig, &$cert_item_config, &$savemsg = null) {
	$input_errors = array();
	$thiscert = is_array($cert_item_config) ? $cert_item_config['item'] : null;

	/* input validation */
	$reqdfields = explode(" ", "descr cert");
	$reqdfieldsn = array(
		gettext("Descriptive name"),
		gettext("Final Certificate data"));

	do_input_validation($pconfig, $reqdfields, $reqdfieldsn, $input_errors);

	if (pki_descr_is_invalid($pconfig['descr'])) {
		$input_errors[] = gettext("The field 'Descriptive Name' contains invalid characters.");
	}

	/* Compare with the stored request (the posted copy of the read-only request field was compared, so a certificate
	 * that does not match the stored private key, or completing an entry without a request, was accepted). */
	if (empty($thiscert['csr'])) {
		$input_errors[] = gettext("This certificate has no pending signing request.");
	} else {
		$mod_csr = cert_get_publickey(base64_decode($thiscert['csr']), false, 'csr');
		$mod_cert = cert_get_publickey($pconfig['cert'], false);

		if (strcmp($mod_csr, $mod_cert)) {
			// simply: if the moduli don't match, then the private key and public key won't match
			$input_errors[] = gettext("The certificate public key does not match the signing request public key.");
		}
	}

	/* save modifications */
	if (!$input_errors) {
		$cert = $thiscert;
		$cert['descr'] = $pconfig['descr'];
		csr_complete($cert, $pconfig['cert']);
		$cert_item_config['item'] = $cert;
		config_set_path("cert/{$cert_item_config['idx']}", $cert);
		$savemsg = sprintf(gettext("Updated certificate signing request %s"), htmlspecialchars($pconfig['descr']));
		write_config($savemsg);
	}
	return $input_errors;
}
