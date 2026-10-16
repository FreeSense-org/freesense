<?php
/* Standalone CI regression test for Fleet connection paths (fleet_paths.inc); run with `php tests/FleetPathsSmokeTest.php`. */

$root = dirname(__DIR__);
set_include_path(get_include_path() . PATH_SEPARATOR . realpath($root . '/src/etc/inc'));
if (!function_exists('gettext')) {
	function gettext($t) { return $t; }
}
require_once('fleet_paths.inc');

function check($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

$choices = array(
	'lan' => array('label' => 'LAN', 'addresses' => array('192.168.1.1'), 'wan' => false),
	'opt2' => array('label' => 'MPLS', 'addresses' => array(), 'wan' => false),
	'wan' => array('label' => 'WAN', 'addresses' => array('203.0.113.5'), 'wan' => true),
	'tailscale' => array('label' => 'Tailscale', 'addresses' => array('100.101.1.2'), 'wan' => false),
);
$ctl = array('192.168.1.10');
check(fleet_path_problem('direct', 'lan', $choices, $ctl, false) === null, 'a direct path on LAN for the controller\'s address');
check(fleet_path_problem('tailscale', 'tailscale', $choices, array('100.64.0.1'), false) === null, 'a Tailscale path on the tailnet address');
check(strpos((string)fleet_path_problem('direct', 'wan', $choices, $ctl, false), 'FLEET-E135') === 0 && fleet_path_problem('direct', 'wan', $choices, $ctl, true) === null,
    'a direct path on WAN needs confirming (FLEET-E135)');
check(fleet_path_problem('direct', 'tailscale', $choices, $ctl, false) !== null && fleet_path_problem('tailscale', 'lan', $choices, $ctl, false) !== null,
    'direct never on tailscale0, Tailscale only there');
check(strpos((string)fleet_path_problem('tailscale', 'tailscale', array(), $ctl, false), 'FLEET-E131') === 0, 'no Tailscale running: FLEET-E131');
check(fleet_path_problem('direct', 'opt2', $choices, $ctl, false) !== null && fleet_path_problem('direct', 'opt9', $choices, $ctl, false) !== null,
    'an interface without an address, or one that does not exist, is refused');
check(fleet_path_problem('direct', 'lan', $choices, array(), false) !== null && fleet_path_problem('direct', 'lan', $choices, array('192.168.1.0/24'), false) !== null &&
    fleet_path_problem('direct', 'lan', $choices, array('1.1.1.1', '1.1.1.2', '1.1.1.3', '1.1.1.4', '1.1.1.5'), false) !== null && fleet_path_problem('ssh', 'lan', $choices, $ctl, false) !== null,
    'only single controller addresses (1 to 4) and only known methods');
$post = fleet_path_post('direct', 'lan', $ctl, 'abc123');
check($post['descr'] === 'FreeSense Fleet path: direct' && $post['port'] === FLEET_NODE_API_PORT && $post['allowednetworks'] === '192.168.1.10' && $post['firewall'] === true &&
    $post['certref'] === 'abc123', 'a path is a listener on the Fleet port, with its own rule, open only to the controller');
check(fleet_path_method_of($post) === 'direct' && fleet_path_method_of(array('descr' => 'FreeSense Fleet')) === null && fleet_path_method_of(array('descr' => 'FreeSense Fleet path: ssh')) === null,
    'a path listener is recognised by its description');
check(fleet_listener_is_fleet($post) && fleet_listener_is_fleet(array('descr' => FLEET_LISTENER_DESCR)) && !fleet_listener_is_fleet(array('descr' => 'Monitoring')),
    'leaving and restores treat paths like the tunnel listener (Fleet owns them)');

echo "FleetPathsSmokeTest: OK\n";
