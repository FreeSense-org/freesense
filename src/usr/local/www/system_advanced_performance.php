<?php
/*
 * system_advanced_performance.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
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

/*
 * System > Advanced > Performance: packet processing, network buffers, a
 * summary of the network adapter tuning, and the CPU power, crypto, thermal
 * and mitigation settings that used to sit on the Miscellaneous tab (their
 * form code moved here as it was).
 */

##|+PRIV
##|*IDENT=page-system-advanced-performance
##|*NAME=System: Advanced: Performance
##|*DESCR=Allow access to the 'System: Advanced: Performance' page.
##|*MATCH=system_advanced_performance.php*
##|-PRIV

require_once('guiconfig.inc');
require_once('functions.inc');
require_once('filter.inc');
require_once('system.inc');
require_once('util.inc');
require_once('system_advanced_performance.inc');

global $hwpstate_status_descr, $hwpstate_control_levels;
$hwpstate_status = system_has_hwpstate();
$hwpstate_status_descr = $hwpstate_status_descr[$hwpstate_status];

$powerd_modes = array(
	'hadp' => gettext('Hiadaptive'),
	'adp' => gettext('Adaptive'),
	'min' => gettext('Minimum'),
	'max' => gettext('Maximum'),
);

$mds_modes = array(
	'' => gettext('Default'),
	0 => gettext('Mitigation disabled'),
	1 => gettext('VERW instruction (microcode) mitigation enabled'),
	2 => gettext('Software sequence mitigation enabled (not recommended)'),
	3 => gettext('Automatic VERW or Software selection'),
);

$crypto_modules = getSystemAdvancedMiscCryptoModules();

$thermal_hardware_modules = array(
	'coretemp' => gettext("Intel Core* CPU on-die thermal sensor"),
	'amdtemp' => gettext("AMD CPU on-die thermal sensor (K8 to Zen)")
);

$reboot = array();
if ($_POST) {
	$rv = saveSystemAdvancedPerformance($_POST);
	$input_errors = $rv['input_errors'];
	$retval = $rv['retval'];
	$changes_applied = $rv['changes_applied'];
	$reboot = $rv['reboot'];
	$pconfig = $input_errors ? $_POST : getSystemAdvancedPerformance();
} else {
	$pconfig = getSystemAdvancedPerformance();
}

$rss = netperf_rss_state();
$pending = netperf_pending(netperf_current_loader_lines(), netperf_booted_values(), netperf_loader_keys());
$mbuf = netperf_mbuf_usage();

$pgtitle = array(gettext("System"), gettext("Advanced"), gettext("Performance"));
$pglinks = array("", "system_advanced_admin.php", "@self");
include("head.inc");

if ($input_errors) {
	print_input_errors($input_errors);
}

if ($changes_applied) {
	print_apply_result_box($retval);
}

if ($pending && !in_array('network', $reboot, true)) {
	print_info_box(gettext('Packet processing or network buffer settings differ from the running system. They take effect after a reboot.'), 'warning', false);
}

fs_tabs('system-advanced', 'system_advanced_performance.php');

$form = new Form;

$section = new Form_Section('Packet Processing');

$section->addInput(new Form_Select(
	'netisr_threads',
	'Receive threads',
	$pconfig['netisr_threads'] ?? 'auto',
	array(
		'auto' => gettext('Automatic: one thread per CPU (recommended)'),
		'single' => gettext('Single thread (FreeBSD default)'),
	)
))->setHelp('How received packets are spread over the CPUs. The kernel uses Receive Side Scaling (RSS): each flow belongs to ' .
	'one CPU, and its packets are processed there. With a single thread, the packets for every other CPU queue up behind ' .
	'it, which loses most small packets under load. Takes effect after a reboot.%1$s%1$s' .
	'Receive Side Scaling: %2$s. Running now: %3$s thread(s)%4$s.',
	'<br/>',
	htmlspecialchars($rss['enabled'] ? sprintf(gettext('built into the kernel, %d hash buckets'), 1 << (int)$rss['bits']) : gettext('not in this kernel (packets are processed on the receiving CPU)')),
	htmlspecialchars((string)get_single_sysctl('net.isr.numthreads')),
	(get_single_sysctl('net.isr.bindthreads') === '1') ? htmlspecialchars(gettext(', bound to their CPUs')) : '');

$form->add($section);

$section = new Form_Section('Network Buffers');

$section->addInput(new Form_Input(
	'nmbclusters',
	'Buffer clusters',
	'number',
	$pconfig['nmbclusters'] ?? '',
	array('min' => NETPERF_NMBCLUSTERS_MIN, 'max' => NETPERF_NMBCLUSTERS_MAX, 'placeholder' => (string)get_single_sysctl('kern.ipc.nmbclusters'))
))->setHelp('The most 2 KiB packet buffers (mbuf clusters) the kernel may allocate. Empty lets FreeBSD size it from the memory. ' .
	'Raise it only when allocation failures appear below, for example with many fast interfaces or large descriptor rings. ' .
	'Takes effect after a reboot.%1$s%1$s%2$s',
	'<br/>',
	htmlspecialchars($mbuf === null ? gettext('Buffer use could not be read.') :
	    sprintf(gettext('In use now: %1$s of %2$s clusters, %3$s allocation failures since boot.'),
	    number_format($mbuf['used'] + $mbuf['free']), number_format($mbuf['limit']), number_format($mbuf['failures']))));

$form->add($section);

$section = new Form_Section('Network Adapters');

$nic_profiles = array('firewall' => gettext('Firewall Balanced'), 'throughput' => gettext('Maximum Throughput'),
    'latency' => gettext('Low Latency'), 'capture' => gettext('Packet Capture / Troubleshooting'));
$nic_profile = config_get_path('system/nicsettings/profile', 'firewall');
$nic_tuned = 0;
foreach (config_get_path('system/nicsettings/adapters', array()) as $adapter) {
	if (is_array($adapter) && nic_tuning_saved($adapter)) {
		$nic_tuned++;
	}
}
$section->addInput(new Form_StaticText(
	'Offloads and tuning',
	sprintf('<a class="btn btn-outline-secondary" href="interfaces_nic_settings.php"><i class="fa-solid fa-microchip icon-embed-btn" aria-hidden="true"></i>%s</a>', gettext('Open NIC Settings'))
))->setHelp('Profile: %1$s. Adapters with driver tuning: %2$d. Checksum, segmentation and receive offloads, flow control, ' .
	'interrupt moderation, queues and descriptor rings are set per adapter, where the driver supports them.',
	htmlspecialchars($nic_profiles[$nic_profile] ?? $nic_profiles['firewall']), $nic_tuned);

$form->add($section);

if ($hwpstate_status !== false) {
	/* Intel Speed Shift or AMD CPPC: the same settings, named after the CPU's own feature */
	$hwpstate_amd = (system_hwpstate_vendor() === 'amd') || (cpu_vendor() === 'amd');
	$hwpstate_name = $hwpstate_amd ? 'AMD CPPC' : 'Intel Speed Shift';
	$section = new Form_Section('Power Savings - ' . $hwpstate_name);
	$section->addInput(new Form_Checkbox(
		'hwpstate',
		$hwpstate_amd ? 'CPPC' : 'Speed Shift',
		$hwpstate_amd ? 'Enable AMD CPPC' : 'Enable Speed Shift',
		$pconfig['hwpstate']
	))->setHelp('%3$s configures hardware-controlled performance states '.
		'on systems with compatible hardware. This can allow the system to consume ' .
		'less power and generate less heat when it is lightly loaded, and increase ' .
		'power as needed. Changing this setting requires a reboot.%1$s%1$s' .
		'It takes precedence over Speed Step and Cool\'n\'Quiet (BIOS and/or PowerD) so the best ' .
		'practice is to only enable one or the other.' .
		'%1$s%1$sCurrent State: %2$s', '<br/>', $hwpstate_status_descr, $hwpstate_name);

	if (!$hwpstate_amd) {
	$section->addInput(new Form_Select(
		'hwpstate_control_level',
		'Control Level',
		$pconfig['hwpstate_control_level'],
		$hwpstate_control_levels
	))->setHelp('Chooses between per-core or per-package frequency control. When set ' .
		'to core-level control, each CPU core can run at a different frequency. ' .
		'When set to package-level control, all cores on the same physical CPU ' .
		'package are locked to the same speed, but each package may run at a ' .
		'different speed.%1$s%1$sCore-level control is the best practice in most ' .
		'cases, especially for hardware with only a single physical CPU. ' .
		'Changing this setting requires a reboot.' .
		'%1$s%1$sCurrent Active Level: %2$s',
		'<br/>', ((get_single_sysctl('machdep.hwpstate_pkg_ctrl') == '0') ? 'Core' : 'Package'));
	}

	$section->addInput(new Form_Input(
		'hwpstate_epp',
		'Power Preference',
		'range',
		$pconfig['hwpstate_epp'],
		array (
			'min' => 0,
			'max' => 100,
			'step' => 5,
			'label-start' => gettext('Performance'),
			'label-end' => gettext('Energy Efficiency'),
			'show-output' => true,
			)
	))->setHelp('This influences the bias of the hardware performance state toward ' .
		'performance (left, lower values) or energy efficiency (right, higher values).');

	$form->add($section);
}

$section = new Form_Section('Power Savings - PowerD');

$section->addInput(new Form_Checkbox(
	'powerd_enable',
	'PowerD',
	'Enable PowerD',
	$pconfig['powerd_enable']
))->setHelp('The powerd utility monitors '.
	'the system state and sets various power control options accordingly.  It offers '.
	'four modes (maximum, minimum, adaptive and hiadaptive) that can be individually '.
	'selected while on AC power or batteries. The modes maximum, minimum, adaptive '.
	'and hiadaptive may be abbreviated max, min, adp, hadp.	 Maximum mode chooses the '.
	'highest performance values.  Minimum mode selects the lowest performance values '.
	'to get the most power savings. Adaptive mode attempts to strike a balance by '.
	'degrading performance when the system appears idle and increasing it when the '.
	'system is busy.  It offers a good balance between a small performance loss for '.
	'greatly increased power savings.  Hiadaptive mode is alike adaptive mode, but '.
	'tuned for systems where performance and interactivity are more important than '.
	'power consumption.	 It raises frequency faster, drops slower and keeps twice '.
	'lower CPU load.');

$section->addInput(new Form_Select(
	'powerd_ac_mode',
	'AC Power',
	$pconfig['powerd_ac_mode'],
	$powerd_modes
));

$section->addInput(new Form_Select(
	'powerd_battery_mode',
	'Battery Power',
	$pconfig['powerd_battery_mode'],
	$powerd_modes
));

$section->addInput(new Form_Select(
	'powerd_normal_mode',
	'Unknown Power',
	$pconfig['powerd_normal_mode'],
	$powerd_modes
));

$form->add($section);
$section = new Form_Section('Cryptographic & Thermal Hardware');

$section->addInput(new Form_Select(
	'crypto_hardware',
	'Cryptographic Hardware',
	$pconfig['crypto_hardware'],
	['' => gettext('Automatic (recommended)')] + $crypto_modules
))->setHelp('A cryptographic accelerator speeds up the kernel cryptography used by IPsec and OpenVPN DCO. '.
	'Automatic loads OpenSSL (ossl), which uses the CPU\'s AES and SHA instructions where present; choose None to use none. '.
	'AES-NI uses the CPU\'s AES instructions. OpenSSL (ossl) uses OpenSSL\'s optimized routines for AES-GCM, '.
	'AES-CBC, ChaCha20-Poly1305 and SHA, and works on amd64 and arm64. '.
	'Intel IPsec-MB, where offered (amd64), processes several packets at once and is experimental. '.
	'Loading the BSD Crypto Device module allows access to acceleration devices using drivers built into the kernel. '.
	'If the CPU does not support the chosen accelerator, it has no effect. '.
	'Choose one accelerator; a module stays loaded until the next reboot, so reboot after changing it.');

if (crypto_qat_available()) {
	$section->addInput(new Form_Checkbox(
		'crypto_qat',
		'Intel QAT',
		'Load the Intel QuickAssist (QAT) drivers (experimental)',
		$pconfig['crypto_qat']
	))->setHelp('Offloads IPsec and OpenVPN DCO cryptography to an Intel QuickAssist device, as found on Atom C2000 and '.
		'C3000, Xeon D and C62x chipsets and QAT add-in cards. The drivers attach only when such a device is present, '.
		'and can run next to the accelerator above. Experimental: not yet tested on QAT hardware. '.
		'To unload the drivers, clear this option and reboot.');
}

if (crypto_offload_available('ccp')) {
	$section->addInput(new Form_Checkbox(
		'crypto_ccp',
		'AMD CCP',
		'Load the AMD Crypto Co-Processor driver (experimental)',
		$pconfig['crypto_ccp']
	))->setHelp('Offloads IPsec and OpenVPN DCO cryptography to the AMD Crypto Co-Processor found on EPYC and Ryzen Embedded '.
		'processors, and adds it as a hardware random number source. The driver attaches only when the device is present, '.
		'and can run next to the accelerator above. For small packets it is often slower than the CPU\'s AES instructions, '.
		'but it frees the CPU. Experimental: not yet tested on CCP hardware. To unload the driver, clear this option and reboot.');
}

if (crypto_offload_available('ccr')) {
	$section->addInput(new Form_Checkbox(
		'crypto_ccr',
		'Chelsio crypto',
		'Load the Chelsio T6 crypto driver (experimental)',
		$pconfig['crypto_ccr']
	))->setHelp('Offloads IPsec and OpenVPN DCO cryptography to the crypto engine of a Chelsio T6 network adapter. '.
		'The driver attaches only when such an adapter is present, and can run next to the accelerator above. '.
		'Experimental: not yet tested on Chelsio hardware. To unload the driver, clear this option and reboot.');
}

$section->addInput(new Form_Select(
	'thermal_hardware',
	'Thermal Sensors',
	$pconfig['thermal_hardware'],
	array('' => 'None/ACPI') + $thermal_hardware_modules
))->setHelp('With a '.
	'supported CPU, selecting a thermal sensor will load the appropriate driver to '.
	'read its temperature. Setting this to "None" will attempt to read the '.
	'temperature from an ACPI-compliant motherboard sensor instead, if one is '.
	'present. If there is not a supported thermal sensor chip in the system, this '.
	'option will have no effect. To unload the selected module, set this option to '.
	'"none" and then reboot.');

if (get_single_sysctl('hw.machine') == 'amd64') {
	$section->addInput(new Form_Checkbox(
		'microcode_early',
		'CPU Microcode',
		'Load CPU microcode updates early in boot (recommended)',
		$pconfig['microcode_early']
	))->setHelp('Applies the Intel or AMD microcode update from the boot loader, before the kernel starts, so the kernel ' .
		'chooses its CPU vulnerability mitigations with the updated microcode. When it is off, or the loader finds no ' .
		'newer update, the microcode is still updated near the end of boot. Virtual machines are skipped: their microcode ' .
		'comes from the host. The dashboard shows the result. Takes effect at the next reboot.');
}

$form->add($section);

$pti = get_single_sysctl('vm.pmap.pti');
if (strlen($pti) > 0) {
	$section = new Form_Section('Kernel Page Table Isolation');
	$section->addInput(new Form_Checkbox(
		'pti_disabled',
		'Kernel PTI',
		'Forcefully disable the kernel PTI',
		$pconfig['pti_disabled']
	))->setHelp('Meltdown workaround. If disabled the kernel memory can be accessed by unprivileged users on affected CPUs. ' .
		    'This option forces the workaround off, and requires a reboot to activate. %1$s%1$s' .
		    'PTI is active by default only on affected CPUs, if PTI is disabled by default then this option will have no effect. %1$s' .
		    'Current PTI status: %2$s', "<br/>", ($pti == "1") ? "Enabled" : "Disabled");
	$form->add($section);
}

$mds = get_single_sysctl('hw.mds_disable_state');
if (strlen($mds) > 0) {
	$section = new Form_Section('Microarchitectural Data Sampling Mitigation');
	$section->addInput(new Form_Select(
		'mds_disable',
		'MDS Mode',
		$pconfig['mds_disable'],
		$mds_modes
	))->setHelp('Microarchitectural Data Sampling mitigation. If disabled the kernel memory can be accessed by unprivileged users on affected CPUs. ' .
		    'This option controls which method of MDS mitigation is used, if any. %1$s%1$s' .
		    'Current MDS status: %2$s', "<br/>", ucwords(htmlspecialchars($mds)));
	$form->add($section);
}

print $form;

$reboot_msg = array();
if (in_array('network', $reboot, true)) {
	$reboot_msg[] = gettext('The packet processing or network buffer settings take effect at the next reboot.');
}
if (in_array('speed_shift', $reboot, true)) {
	$reboot_msg[] = gettext('The Speed Shift / CPPC configuration has changed in a way which requires the firewall to reboot.');
}
if (in_array('microcode', $reboot, true)) {
	$reboot_msg[] = gettext('The CPU microcode loading setting takes effect at the next reboot.');
}
if (in_array('pti', $reboot, true)) {
	$reboot_msg[] = gettext('The kernel PTI setting takes effect at the next reboot.');
}
$reboot_confirm_prompt = '';
if (!empty($reboot_msg)) {
	$reboot_msg[] = gettext('Reboot now?');
	$reboot_confirm_prompt = implode('\n\n', $reboot_msg);
}
?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	if (<?= (int) !empty($reboot_confirm_prompt) ?> && confirm("<?=$reboot_confirm_prompt?>")) {
		postSubmit({rebootmode : 'Reboot', Submit: 'Yes'}, 'diag_reboot.php')
	}
});
//]]>
</script>

<?php
include("foot.inc");
