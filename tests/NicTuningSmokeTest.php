<?php
/*
 * NIC detection and tuning (src/etc/inc/nic_tuning.inc): the pure functions,
 * run against a real FreeBSD 16 em(4) sysctl dump (tests/fixtures/nic/em0.sysctl).
 */
$root = dirname(__DIR__);
set_include_path($root . '/src/etc/inc' . PATH_SEPARATOR . get_include_path());

function gettext($text) {
	return $text;
}
function localize_text($format, ...$args) {
	return vsprintf($format, $args);
}
function logger($level, $message) {
}
function check($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "NIC tuning: {$message}\n");
		exit(1);
	}
}

require_once('nic_tuning.inc');

/* interface names */
check(nic_tuning_split('igb0') === ['driver' => 'igb', 'unit' => '0'], 'igb0 must split into igb and 0.');
check(nic_tuning_split('ix10') === ['driver' => 'ix', 'unit' => '10'], 'ix10 must split into ix and 10.');
check(nic_tuning_split('mce1')['driver'] === 'mce', 'mce1 must split into mce.');
foreach (['em0.10', 'Ix0', "em0\n", 'em', '0em', 'em0;reboot', ''] as $bad) {
	check(nic_tuning_split($bad) === null, 'Not a physical adapter name: ' . json_encode($bad));
}

/* parsing, including a multi-line value */
$lines = file($root . '/tests/fixtures/nic/em0.sysctl', FILE_IGNORE_NEW_LINES);
$nodes = nic_tuning_parse_sysctl('dev.em.0', $lines);
check(($nodes['%desc'] ?? '') === 'Intel(R) Legacy PRO/1000 MT 82545EM (Copper)', 'The %desc node must parse.');
check(strpos($nodes['reg_dump'] ?? '', "\tCTRL_EXT\t 00000cc0") !== false, 'A multi-line value must keep its continuation lines.');
check(($nodes['fc'] ?? '') === '3', 'The node after a multi-line value must parse on its own.');
check(nic_tuning_parse_sysctl('dev.em.1', $lines) === [], 'Another unit must not match.');
check(nic_tuning_parse_pnp($nodes['%pnpinfo']) === ['vendor' => '8086', 'device' => '100f', 'subvendor' => '15ad', 'subdevice' => '0750'],
    'PCI ids must parse from %pnpinfo.');

/* detection */
$em = nic_tuning_detect('em0', $nodes);
check($em['driver'] === 'em' && $em['unit'] === '0' && $em['found'] && $em['iflib'], 'em0 must be detected as an iflib adapter.');
check($em['rxqs'] === 1 && $em['txqs'] === 1 && $em['queue_cpus'] === [0 => 3] && $em['msix'] === 1, 'em0 queue layout must be read.');
check($em['controls'] === ['fc' => '3', 'aim' => '1', 'tx_abdicate' => '0', 'queues' => '0', 'rxds' => '0', 'txds' => '0'],
    'em0 must offer flow control, AIM, TX abdicate, queues and descriptors with their current values: ' . json_encode($em['controls']));
check(($em['counters']['dropped'] ?? null) === 0 && ($em['counters']['rx_missed'] ?? null) === 0 && isset($em['counters']['tx_ring_drops']),
    'em0 counters must be read.');
check(nic_tuning_detect('em9', [])['found'] === false && nic_tuning_detect('em9', [])['controls'] === [], 'A missing adapter offers nothing.');

/* vmx keeps three receive rings per queue set: a single descriptor number cannot express that */
$vmx = nic_tuning_detect('vmx0', ['%driver' => 'vmx', 'iflib.override_nrxqs' => '0', 'iflib.override_nrxds' => '0,0,0',
    'iflib.override_ntxds' => '0', 'iflib.tx_abdicate' => '0', 'iflib.allocated_msix_vectors' => '1',
    'iflib.rxq0.cpu' => '0', 'iflib.txq0.cpu' => '0']);
check(!isset($vmx['controls']['rxds']) && isset($vmx['controls']['txds']), 'A multi-ring descriptor list must not be offered.');
check(!isset($vmx['controls']['fc']) && !isset($vmx['controls']['aim']), 'Controls the driver lacks must not be offered.');

/* recommendations */
check(nic_tuning_recommend($em, 'firewall') === ['fc' => '0', 'tx_abdicate' => '1'], 'Firewall: flow control off, TX abdicate on.');
check(nic_tuning_recommend($em, 'latency') === ['fc' => '0', 'aim' => '2'], 'Low latency: flow control off, low-latency AIM on em.');
check(nic_tuning_recommend($em, 'throughput') === ['fc' => '0', 'tx_abdicate' => '1', 'rxds' => '2048', 'txds' => '2048'],
    'Throughput: also larger descriptor rings.');
check(nic_tuning_recommend($em, 'capture') === [], 'Packet capture recommends nothing.');
check(nic_tuning_recommend($vmx, 'latency') === [], 'Only controls the adapter has are recommended.');
check(nic_tuning_differences($em, nic_tuning_recommend($em, 'firewall')) ===
    ['fc' => ['current' => '3', 'recommended' => '0'], 'tx_abdicate' => ['current' => '0', 'recommended' => '1']],
    'Differences list current and recommended values.');

/* validation */
check(nic_tuning_validate('fc', '', $em, 4) === null, 'Empty keeps the driver default.');
check(nic_tuning_validate('fc', '0', $em, 4) === null && nic_tuning_validate('fc', '4', $em, 4) !== null, 'Flow control takes 0-3.');
check(nic_tuning_validate('aim', '2', $em, 4) === null, 'em has a low-latency AIM mode.');
$ix = ['driver' => 'ix', 'controls' => ['aim' => '1']];
check(nic_tuning_validate('aim', '2', $ix, 4) !== null, 'ix has no low-latency AIM mode.');
check(nic_tuning_validate('queues', '4', $em, 4) === null && nic_tuning_validate('queues', '5', $em, 4) !== null &&
    nic_tuning_validate('queues', '0', $em, 4) !== null && nic_tuning_validate('queues', '1 ', $em, 4) !== null,
    'Queues take 1 to the CPU count.');
check(nic_tuning_validate('rxds', '2048', $em, 4) === null && nic_tuning_validate('rxds', '1000', $em, 4) !== null &&
    nic_tuning_validate('rxds', '32', $em, 4) !== null && nic_tuning_validate('rxds', '32768', $em, 4) !== null,
    'Descriptors take a power of two from 64 to 16384.');
check(nic_tuning_validate('rxds', '1024', $vmx, 4) !== null, 'A setting the adapter lacks is refused.');
check(nic_tuning_validate('nope', '1', $em, 4) !== null, 'An unknown setting is refused.');

/* loader.conf lines from config.xml, which must not be able to inject anything */
$adapters = [
	'nic_a' => ['device' => 'igb1', 'tuning' => ['queues' => '4', 'rxds' => '2048', 'fc' => '0']],
	'nic_b' => ['device' => "igb2\nkern.evil=1", 'tuning' => ['queues' => '2']],
	'nic_c' => ['device' => 'ix0', 'tuning' => ['txds' => "1024\"\nkern.evil=\"1"]],
	'nic_d' => ['device' => 'em0'],
	'nic_e' => 'garbage',
];
check(nic_tuning_loader_lines($adapters) === [
	'dev.igb.1.iflib.override_nrxqs="4"',
	'dev.igb.1.iflib.override_ntxqs="4"',
	'dev.igb.1.iflib.override_nrxds="2048"',
], 'Loader lines must cover boot settings only and skip unsafe names or values: ' . json_encode(nic_tuning_loader_lines($adapters)));
check(nic_tuning_saved(['tuning' => ['fc' => '0', 'aim' => ['x'], 'queues' => 'four', 'bogus' => '1']]) === ['fc' => '0'],
    'Only known settings with numeric values are read back.');
check(nic_tuning_is_loader_key('dev.igb.1.iflib.override_nrxqs') && nic_tuning_is_loader_key(' dev.ix.0.iflib.override_ntxds ') &&
    !nic_tuning_is_loader_key('dev.igb.1.iflib.tx_abdicate') && !nic_tuning_is_loader_key('net.isr.maxthreads'),
    'Only the iflib overrides FreeSense writes are managed loader keys.');

/* advice */
check(count(nic_tuning_notes($vmx, 4, 'vmware')) === 1, 'vmx with one vector on VMware gets the MSI-X note.');
check(nic_tuning_notes($em, 4, 'vmware') === [], 'em0 with one vector gets no queue note.');
check(count(nic_tuning_notes(['driver' => 'ix', 'iflib' => true, 'rxqs' => 1, 'msix' => 9], 8, 'none')) === 1,
    'A multi-vector adapter left on one queue gets the queue note.');

/* boot settings need loader.conf and a reboot; live ones do not */
check(nic_tuning_boot_changed(['fc' => '0'], ['fc' => '3']) === false, 'A live change needs no reboot.');
check(nic_tuning_boot_changed([], ['queues' => '4']) && nic_tuning_boot_changed(['rxds' => '2048'], []), 'A boot change needs a reboot.');

echo "NIC tuning smoke test passed.\n";
