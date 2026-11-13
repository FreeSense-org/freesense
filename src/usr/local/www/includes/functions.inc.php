<?php
/*
 * functions.inc.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2013-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
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

if (Connection_Aborted()) {
	exit;
}

require_once("config.inc");
require_once("freesense-utils.inc");

function disk_usage($slice = '/') {
	$dfout = "";
	exec("/bin/df -h {$slice} | /usr/bin/tail -n 1 | /usr/bin/awk '{ print $5 }' | /usr/bin/cut -d '%' -f 1", $dfout);
	$diskusage = trim($dfout[0]);

	return $diskusage;
}

define("INTEL_C2000_IQIA_PHYS", "0x1f188086");
define("INTEL_200XX_QAT", "0x18ee8086");
define("INTEL_200XX_QAT_VF", "0x18ef8086");
define("INTEL_C3K_QAT", "0x19e28086");
define("INTEL_C3K_QAT_VF", "0x19e38086");
define("INTEL_C620_QAT", "0x37c88086");
define("INTEL_C620_QAT_VF", "0x37c98086");
define("INTEL_XEOND_QAT", "0x6f548086");
define("INTEL_XEOND_QAT_VF", "0x6f558086");
define("INTEL_DH895XCC_QAT", "0x04358086");
define("INTEL_DH895XCC_QAT_VF", "0x04438086");
define("INTEL_4XXX_QAT", "0x49408086");
define("INTEL_4XXX_QAT_VF", "0x49418086");
define("INTEL_401XX_QAT", "0x49428086");
define("INTEL_401XX_QAT_VF", "0x49438086");
/* AMD Crypto Co-Processor PCI IDs, as matched by FreeBSD's ccp(4) driver */
define("AMD_CCP_5A", "0x14561022");
define("AMD_CCP_5B", "0x14681022");
define("AMD_CCP_5A_RAVEN", "0x15df1022");
define("AESNI_ALGS", "AES-CBC,AES-CCM,AES-GCM,AES-ICM,AES-XTS");
define("AESNI_ALGS_SHA", "SHA1,SHA256");
define("QAT_ALGS", "AES-CBC,AES-CCM,AES-GCM,AES-ICM,AES-XTS,SHA1,SHA256,SHA384,SHA512");
define("OSSL_ALGS", "AES-CBC,AES-GCM,CHACHA20-POLY1305,SHA1,SHA256,SHA384,SHA512");
define("IPSECMB_ALGS", "AES-CBC,AES-GCM,CHACHA20-POLY1305,SHA1,SHA256,SHA384,SHA512");
define("CCP_ALGS", "AES-CBC,AES-GCM,AES-ICM,AES-XTS,SHA1,SHA256,SHA384,SHA512");
define("CCR_ALGS", "AES-CBC,AES-CCM,AES-GCM,AES-ICM,AES-XTS,SHA1,SHA256,SHA384,SHA512");
define("ARMV8_ALGS", "AES-CBC,AES-GCM,AES-XTS");
define("SAFEXCEL_ALGS", "AES-CBC,AES-CCM,AES-GCM,AES-ICM,AES-XTS,SHA1,SHA256,SHA384,SHA512");

function crypto_accel_new($name = "", $algs = "") {
	return (array("name" => $name, "present" => false, "enabled" => false, "algs" => explode(",", $algs)));
}

function crypto_accel_init() {
	$machine = get_single_sysctl('hw.machine');

	/* Defaults */
	$crypto = array();
	$crypto["accel"] = array();

	switch ($machine) {
	case 'amd64':
		$crypto["accel"][] = crypto_accel_new("AESNI", AESNI_ALGS);
		$crypto["accel"][] = crypto_accel_new("OSSL", OSSL_ALGS);
		$crypto["accel"][] = crypto_accel_new("IPSECMB", IPSECMB_ALGS);
		$crypto["accel"][] = crypto_accel_new("QAT", QAT_ALGS);
		$crypto["accel"][] = crypto_accel_new("CCP", CCP_ALGS);
		$crypto["accel"][] = crypto_accel_new("CCR", CCR_ALGS);
		break;
	case 'arm64':
		$crypto["accel"][] = crypto_accel_new("ARMV8", ARMV8_ALGS);
		$crypto["accel"][] = crypto_accel_new("OSSL", OSSL_ALGS);
		$crypto["accel"][] = crypto_accel_new("SAFEXCEL", SAFEXCEL_ALGS);
		break;
	}

	return ($crypto);
}

/* A software accelerator module: present when the kernel ships it, active while it is loaded. */
function crypto_accel_set_module_flags($crypto, $name, $module) {
	return (crypto_accel_set_flags($crypto, $name, crypto_module_available($module), is_module_loaded($module)));
}

/* One "Name: Yes (active)" line of the System Information widget. */
function crypto_accel_status_line($crypto, $name, $label) {
	$line = "{$label}: ";
	if ($crypto && crypto_accel_get($crypto, $name, 'present')) {
		$line .= 'Yes ';
		$line .= crypto_accel_get($crypto, $name, 'enabled') ? '(active)' : '(inactive)';
	} else {
		$line .= 'No';
	}
	return ($line);
}

/* True when a driver has attached to at least one device (its dev.<driver>.0 node exists). */
function crypto_device_attached($driver) {
	if (!preg_match('/^[a-z0-9]+$/', $driver)) {
		return (false);
	}
	$out = [];
	exec('/sbin/sysctl -n ' . escapeshellarg("dev.{$driver}.0.%desc") . ' 2>/dev/null', $out, $rc);
	return ($rc == 0 && isset($out[0]) && strlen(trim($out[0])) > 0);
}

function crypto_accel_set_flags($crypto, $name, $present = false, $enabled = false) {

	foreach ($crypto["accel"] as &$accel) {
		if ($accel["name"] != $name) {
			continue;
		}
		$accel["present"] = $present;
		$accel["enabled"] = $enabled;
	}

	return ($crypto);
}

function crypto_accel_get($crypto, $name, $key) {

	foreach ($crypto["accel"] as $accel) {
		if ($accel["name"] != $name) {
			continue;
		}
                return ($accel[$key]);
	}

	return ("");
}

function crypto_accel_set_algs($crypto, $name, $algs) {

	foreach ($crypto["accel"] as &$accel) {
		if ($accel["name"] != $name) {
			continue;
		}
		$a = explode(",", $algs);
		$m = array_merge($accel["algs"], $a);
		$accel["algs"] = array_unique($m, SORT_STRING);
	}

	return ($crypto);
}

function crypto_accel_get_algs($crypto) {
	$algs = [];

	foreach ($crypto['accel'] as $accel) {
		/* skip these... */
		if (!$accel["present"] || !$accel["enabled"]) {
			continue;
		}
		/* $algs is indexed by number, array_merge will automatically
		 * append values for entries with duplicate numerical keys so no
		 * need to manually avoid duplicate index values here. */
		$algs = array_unique(array_merge($algs, $accel['algs']), SORT_STRING);
	}

	/* return early */
	if (empty($algs)) {
		return (gettext('Inactive'));
	}

	/* Sort values, ignoring keys */
	sort($algs, SORT_STRING);

	/* format and return the algs string */
	return (implode(', ', $algs));
}

function get_cpu_crypto_support() {
	global $g;
	$machine = get_single_sysctl('hw.machine');
	$QATIDS = [
		INTEL_C2000_IQIA_PHYS, INTEL_200XX_QAT, INTEL_200XX_QAT_VF,
		INTEL_C3K_QAT, INTEL_C3K_QAT_VF, INTEL_C620_QAT, INTEL_C620_QAT_VF,
		INTEL_XEOND_QAT, INTEL_XEOND_QAT_VF, INTEL_DH895XCC_QAT, INTEL_DH895XCC_QAT_VF,
		INTEL_4XXX_QAT, INTEL_4XXX_QAT_VF, INTEL_401XX_QAT, INTEL_401XX_QAT_VF
	];

	/* Defaults */
	$crypto = crypto_accel_init();

	switch ($machine) {
	case 'amd64':
		$fd = @fopen("{$g['varlog_path']}/dmesg.boot", "r");
		while ($fd && !feof($fd)) {
			$dmesgl = fgets($fd);
			if (preg_match("/^  Features2.*AESNI/", $dmesgl, $matches)) {
				$crypto = crypto_accel_set_flags($crypto, "AESNI", true, (is_module_loaded('aesni')) ? true : false);
			}
			if (preg_match("/^  Structured Extended Features.*SHA/", $dmesgl, $matches)) {
				$crypto = crypto_accel_set_algs($crypto, "AESNI", AESNI_ALGS_SHA);
			}
		}
		if ($fd) {
			fclose($fd);
		}
		$crypto = crypto_accel_set_module_flags($crypto, "OSSL", 'ossl');
		$crypto = crypto_accel_set_module_flags($crypto, "IPSECMB", 'ipsecmb');
		exec("/usr/sbin/pciconf -l -l | /usr/bin/awk '{ printf \"%s|0x%s%s\\n\", $1, $6, $5 }'", $pciids);
		if (isset($pciids) && is_array($pciids)) {
			foreach ($pciids as $pciid) {
				list($devid, $pid) = explode('|', $pciid, 2);
				list($devname, $busloc) = explode('@', $devid);
				if (in_array($pid, $QATIDS) && !crypto_accel_get($crypto, "QAT", 'present')) {
					$active = ((substr($devname, 0, 3) == 'qat') &&
					    (is_module_loaded('qat') || is_module_loaded('qat_c2xxx')));
					$crypto = crypto_accel_set_flags($crypto, "QAT", true, $active);
				}
				if (in_array($pid, [AMD_CCP_5A, AMD_CCP_5B, AMD_CCP_5A_RAVEN]) &&
				    !crypto_accel_get($crypto, "CCP", 'enabled')) {
					/* the device carries a ccp* name once the driver has attached */
					$active = ((substr($devname, 0, 3) == 'ccp') && is_module_loaded('ccp'));
					$crypto = crypto_accel_set_flags($crypto, "CCP", true, $active);
				}
			}
		}
		/* Chelsio crypto: a T6 adapter (t6nex) is present; ccr attaches on top of it. */
		$crypto = crypto_accel_set_flags($crypto, "CCR", crypto_device_attached('t6nex'),
		    crypto_device_attached('ccr'));
		break;
	case 'arm64':
		/* armv8crypto and safexcel are compiled into the arm64 kernel and attach by themselves. */
		$armv8 = crypto_device_attached('armv8crypto');
		$crypto = crypto_accel_set_flags($crypto, "ARMV8", $armv8, $armv8);
		$crypto = crypto_accel_set_module_flags($crypto, "OSSL", 'ossl');
		$safexcel = crypto_device_attached('safexcel');
		$crypto = crypto_accel_set_flags($crypto, "SAFEXCEL", $safexcel, $safexcel);
		break;
	}

	return ($crypto);
}

function get_cpu_count($show_detail = false) {
	$cpucount = get_single_sysctl('kern.smp.cpus');

	if ($show_detail) {
		$cpudetail = "";
		exec("/usr/bin/grep 'FreeBSD/SMP:.*package' /var/log/dmesg.boot | /usr/bin/cut -f2- -d' '", $cpudetail);
		if (!empty($cpudetail[0]))  {
			$cpucount = $cpudetail[0];
		}
	}
	return $cpucount;
}

