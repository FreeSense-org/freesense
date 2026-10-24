<?php
$root = dirname(__DIR__);
$page = file_get_contents($root . '/src/usr/local/www/interfaces_nic_settings.php');
$utils = file_get_contents($root . '/src/etc/inc/freesense-utils.inc');
$advanced = file_get_contents($root . '/src/usr/local/www/system_advanced_network.php');
$menu = file_get_contents($root . '/src/usr/local/www/head.inc');

foreach (['adapters', 'profile', 'save_nic', 'save_profile', 'nic_settings_profiles()', 'hardware_offloading_applyflags', 'hn_altq_enable'] as $required) {
	if (strpos($page, $required) === false) {
		fwrite(STDERR, "NIC Settings page is missing {$required}.\n");
		exit(1);
	}
}

foreach (['nic_settings_profiles', 'nic_settings_effective', "'firewall'", "'throughput'", "'latency'", "'capture'"] as $required) {
	if (strpos($utils, $required) === false) {
		fwrite(STDERR, "NIC policy backend is missing {$required}.\n");
		exit(1);
	}
}

foreach (['disablechecksumoffloading', 'disablesegmentationoffloading', 'disablelargereceiveoffloading'] as $removed) {
	if (strpos($advanced, "new Form_Checkbox(\n\t'{$removed}'") !== false) {
		fwrite(STDERR, "Advanced Networking still renders the legacy {$removed} control.\n");
		exit(1);
	}
}

/* adapter ids are XML tag names in config.xml: they must start with a letter */
if (strpos($utils, "return 'nic_' . strtolower(") === false) {
	fwrite(STDERR, "NIC adapter ids must be valid XML tag names.
");
	exit(1);
}

if (strpos($menu, '/interfaces_nic_settings.php') === false) {
	fwrite(STDERR, "Interfaces menu does not link to NIC Settings.\n");
	exit(1);
}

/* Inline IPsec offload: the parser and the profiles run as written in freesense-utils.inc. */
function nic_fail($message) {
	fwrite(STDERR, "{$message}\n");
	exit(1);
}
$code = '';
foreach (['nic_settings_profiles', 'interface_ipsec_offload_parse'] as $fn) {
	if (!preg_match('/^function ' . $fn . '\(.*?^}\n/ms', $utils, $m)) {
		nic_fail("freesense-utils.inc must define {$fn}().");
	}
	$code .= $m[0];
}
eval($code);

foreach (nic_settings_profiles() as $name => $profile) {
	if (($profile['ipsec'] ?? null) !== 'off') {
		nic_fail("The {$name} profile must leave inline IPsec offload off.");
	}
}

/* ifconfig -m on FreeBSD 16 prints the nvlist capability names without a hex mask. */
$mlx5_off = [
	"mce0: flags=1008843<UP,BROADCAST,RUNNING,SIMPLEX,MULTICAST,LOWER_UP> metric 0 mtu 1500",
	"\toptions=RXCSUM,TXCSUM,VLAN_MTU,VLAN_HWTAGGING,JUMBO_MTU,VLAN_HWCSUM,TSO4,TSO6,LRO,VLAN_HWFILTER,VLAN_HWTSO,LINKSTATE,RXCSUM_IPV6,TXCSUM_IPV6,TXRTLMT,HWRXTSTMP,VXLAN_HWCSUM,VXLAN_HWTSO",
	"\tcapabilities=RXCSUM,TXCSUM,VLAN_MTU,VLAN_HWTAGGING,JUMBO_MTU,VLAN_HWCSUM,TSO4,TSO6,LRO,VLAN_HWFILTER,VLAN_HWTSO,LINKSTATE,RXCSUM_IPV6,TXCSUM_IPV6,TXRTLMT,HWRXTSTMP,VXLAN_HWCSUM,VXLAN_HWTSO,IPSEC",
	"\tether 0c:42:a1:00:00:01",
];
$mlx5_on = $mlx5_off;
$mlx5_on[1] .= ',IPSEC';
/* the older format with a hex mask, and an adapter without the capability */
$vmx = [
	"vmx0: flags=1008843<UP,BROADCAST,RUNNING,SIMPLEX,MULTICAST,LOWER_UP> metric 0 mtu 1500",
	"\toptions=e403bb<RXCSUM,TXCSUM,VLAN_MTU,VLAN_HWTAGGING,JUMBO_MTU,VLAN_HWCSUM,TSO4,TSO6,VLAN_HWTSO,RXCSUM_IPV6,TXCSUM_IPV6>",
	"\tcapabilities=f507bb<RXCSUM,TXCSUM,VLAN_MTU,VLAN_HWTAGGING,JUMBO_MTU,VLAN_HWCSUM,TSO4,TSO6,LRO,VLAN_HWFILTER,VLAN_HWTSO,RXCSUM_IPV6,TXCSUM_IPV6>",
];
/* "IPSEC" must be a whole token: an interface named ipsec1 or a description must not count */
$lookalike = ["ipsec1: flags=8051<UP,POINTOPOINT,RUNNING,MULTICAST> metric 0 mtu 1400", "\tdescription: IPSEC_TUNNEL", "\toptions=0"];

if (interface_ipsec_offload_parse($mlx5_off) !== false) {
	nic_fail('A capable adapter with offload off must parse as false.');
}
if (interface_ipsec_offload_parse($mlx5_on) !== true) {
	nic_fail('A capable adapter with offload on must parse as true.');
}
if (interface_ipsec_offload_parse($vmx) !== null || interface_ipsec_offload_parse($lookalike) !== null ||
    interface_ipsec_offload_parse([]) !== null) {
	nic_fail('An adapter without the IPSEC capability must parse as unsupported (null).');
}

/* Applying: only capable adapters, only on a change, and the interface name is escaped. */
foreach (["\$ipsec = interface_ipsec_offload_state(\$iface);", "if (\$want !== \$ipsec) {",
    "mwexec('/sbin/ifconfig ' . escapeshellarg(\$iface) . (\$want ? ' ipsec' : ' -ipsec'));"] as $required) {
	if (strpos($utils, $required) === false) {
		nic_fail("hardware_offloading_applyflags() is missing: {$required}");
	}
}
foreach (["'ipsec' => ['ipsec']", "'ipsec' => gettext('Inline IPsec offload (experimental)')",
    "if (\$setting === 'ipsec' && !\$supported) {", "\$info['caps']['ipsec'] = true;"] as $required) {
	if (strpos($page, $required) === false) {
		nic_fail("NIC Settings page is missing: {$required}");
	}
}

echo "NIC Settings smoke test passed.\n";
