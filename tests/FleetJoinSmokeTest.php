<?php
/* Standalone CI regression test for the Fleet join protocol (fleet_join.inc); run with `php tests/FleetJoinSmokeTest.php`. */

$root = dirname(__DIR__);
set_include_path(get_include_path() . PATH_SEPARATOR . realpath($root . '/src/etc/inc'));
if (!function_exists('gettext')) {
	function gettext($t) { return $t; }
}
require_once('fleet_join.inc');

function check($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

function join_error_of(callable $fn) {
	try {
		$fn();
	} catch (FleetJoinError $e) {
		return $e->fleet_code;
	}
	return null;
}

/* ---- reference vectors (the controller side must produce the same bytes) ---- */
$data = 'K7Q2M9XH4T1RZ8VB3NC';
$cert = hash('sha256', 'example-controller-cert-der');
check(fleet_join_key_check($data) === 'S', 'key check character');
check(fleet_join_key_normalize('FLT-K7Q2-M9XH-4T1R-Z8VB-3NCS') === $data, 'a key as shown');
check(fleet_join_key_normalize('flt k7q2 m9xh 4t1r z8vb 3ncs') === $data && fleet_join_key_normalize('K7Q2M9XH4T1RZ8VB3NCS') === $data,
    'case, spaces, dashes and the FLT prefix do not matter');
check(fleet_join_key_normalize('FLT-K7Q2-M9XH-4TlR-Z8VB-3NCS') === $data, 'l reads as 1');
check(fleet_join_key_normalize('FLT-K7Q2-M9XH-4T1R-Z8VB-3NCT') === null, 'a wrong check character');
check(fleet_join_key_normalize('FLT-K7Q2-M9XH-4T1R-Z8VB') === null && fleet_join_key_normalize('') === null &&
    fleet_join_key_normalize('FLT-K7Q2-M9XH-4T1R-Z8VB-3NC!') === null, 'wrong length and characters');
list($shown, $gen) = fleet_join_key_generate();
check(preg_match('/^FLT(-[0-9A-Z]{4}){5}$/D', $shown) === 1 && fleet_join_key_normalize($shown) === $gen, 'a generated key round-trips');
check(fleet_join_key_generate()[0] !== $shown, 'keys are random');

$okm = fleet_join_okm($data, $cert);
check(bin2hex($okm) === '7b150f6c3682a6ace539ee2a979140704e90ea99d72091e70371f691ca818336', 'HKDF vector');
$challenge = str_repeat("\x11", 32);
$nonce = str_repeat("\x22", 32);
$node = str_repeat("\x33", 32);
$ctrl = str_repeat("\x44", 32);
check(fleet_join_proof($okm, $challenge, $nonce, 'a1b2c3d4e5f6', $node, $cert) ===
    '739ea95dbfe2f3e543032057b251527871efaa0d29684b5cfa7e2442cffeccdc', 'enroll proof vector');
check(fleet_join_code($node, $ctrl, $challenge, $nonce) === '634403', 'verification code vector');
check(fleet_join_complete_proof($okm, 'e-00000000000000000001', 'fsk_0123456789ab_' . str_repeat('A', 43)) ===
    'a54f6182a5e2c3b07477c236324eceffb292335c04cdfce5bdb93a0d52f68faf', 'complete proof vector');
check(fleet_join_proof(fleet_join_okm('K7Q2M9XH4T1RZ8VB3ND', $cert), $challenge, $nonce, 'a1b2c3d4e5f6', $node, $cert) !==
    fleet_join_proof($okm, $challenge, $nonce, 'a1b2c3d4e5f6', $node, $cert), 'another key gives another proof');
check(fleet_join_proof($okm, $challenge, $nonce, 'a1b2c3d4e5f6', $node, str_repeat('0', 64)) !==
    fleet_join_proof($okm, $challenge, $nonce, 'a1b2c3d4e5f6', $node, $cert), 'the proof is bound to the pinned certificate');

/* ---- links ---- */
$secret = str_repeat('A', 43);
$link = fleet_join_link_build('hq.example.net:8443', 'a1b2c3d4e5f6', $secret, strtoupper($cert));
$p = fleet_join_link_parse($link);
check($p['host'] === 'hq.example.net' && $p['port'] === 8443 && $p['token_id'] === 'a1b2c3d4e5f6' &&
    $p['token_secret'] === $secret && $p['cert_sha256'] === $cert, 'a link round-trips (the fingerprint is lower-cased)');
check(fleet_join_link_parse(fleet_join_link_build('[fd12:3456:789a:f1::1]:8443', 'a1b2c3d4e5f6', $secret, $cert))['host'] === 'fd12:3456:789a:f1::1',
    'an IPv6 controller');
check(fleet_join_link_parse(fleet_join_link_build('10.0.0.5', 'a1b2c3d4e5f6', $secret, $cert))['port'] === 443, 'the default port');
$frag = '#a1b2c3d4e5f6.' . $secret . '.' . $cert;
foreach (array('http://hq/fleet/join' . $frag, 'https://hq/fleet/join', 'https://hq/other' . $frag,
    'https://hq/fleet/join#short.' . $secret . '.' . $cert, 'https://hq:99999/fleet/join' . $frag,
    'https://u@hq/fleet/join' . $frag, 'https://hq/fleet/join#a1b2c3d4e5f6.' . $secret . '.xyz') as $bad) {
	check(join_error_of(function () use ($bad) { fleet_join_link_parse($bad); }) === 'E102', "refused link: {$bad}");
}

/* ---- certificate fingerprint ---- */
$pk = openssl_pkey_new(array('private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1'));
$csr = openssl_csr_new(array('commonName' => 'hq'), $pk, array('digest_alg' => 'sha256'));
openssl_x509_export(openssl_csr_sign($csr, null, $pk, 1), $pem);
$der = base64_decode(preg_replace('/\s+|-----[A-Z ]+-----/', '', $pem));
check(fleet_cert_fingerprint($pem) === hash('sha256', $der) && fleet_cert_fingerprint('nonsense') === '', 'certificate fingerprint');


/* ---- HTTP answers and the certificate pin ---- */
list($st, $js) = fleet_http_parse_response("HTTP/1.0 200 OK\r\nContent-Type: application/json\r\n\r\n{\"a\":1}");
check($st === 200 && $js === array('a' => 1), 'an HTTP answer parses');
list($st, $js) = fleet_http_parse_response("HTTP/1.1 401 Unauthorized\r\nX: y\r\n\r\nnot json");
check($st === 401 && $js === array(), 'a body that is not JSON is empty');
check(join_error_of(function () { fleet_http_parse_response('garbage'); }) === 'E105', 'garbage is E105');
check(fleet_pin_matches($pem, hash('sha256', $der)) && !fleet_pin_matches($pem, str_repeat('0', 64)) &&
    !fleet_pin_matches('', str_repeat('0', 64)), 'the pin compares the certificate fingerprint');

/* ---- pinned TLS against a loopback server ---- */
$tmp = sys_get_temp_dir() . '/fleetjoin' . getmypid();
mkdir($tmp);
openssl_pkey_export($pk, $pk_pem);
file_put_contents("{$tmp}/s.pem", $pem . $pk_pem);
file_put_contents("{$tmp}/server.php", '<?php
$ctx = stream_context_create(array("ssl" => array("local_cert" => "' . $tmp . '/s.pem", "verify_peer" => false)));
$srv = stream_socket_server("ssl://127.0.0.1:0", $e, $s, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $ctx);
echo stream_socket_get_name($srv, false), "\n";
fflush(STDOUT);
for ($i = 0; $i < 2; $i++) {
	$c = @stream_socket_accept($srv, 20);
	if ($c) {
		$req = (string)fread($c, 8192);
		file_put_contents("' . $tmp . '/req" . $i, $req);
		fwrite($c, "HTTP/1.0 200 OK\r\nContent-Type: application/json\r\n\r\n{\"challenge\":\"ok\"}");
		fclose($c);
	}
}
');
$proc = proc_open(array(PHP_BINARY, "{$tmp}/server.php"), array(1 => array('pipe', 'w'), 2 => array('file', '/dev/null', 'a')), $pipes);
$addr = trim((string)fgets($pipes[1]));
$port = (int)substr(strrchr($addr, ':'), 1);
check($port > 0, 'the loopback TLS server started');
$tl = array('authority' => "127.0.0.1:{$port}", 'host' => '127.0.0.1', 'port' => $port);
list($st, $js) = fleet_http_pinned($tl + array('cert_sha256' => hash('sha256', $der)), 'POST', '/fleet/join/begin', array('token_id' => 'a1b2c3d4e5f6'), $secret);
check($st === 200 && $js === array('challenge' => 'ok'), 'a pinned request is answered');
$sent = (string)file_get_contents("{$tmp}/req0");
check(strpos($sent, "POST /fleet/join/begin HTTP/1.0") === 0 && strpos($sent, "Authorization: Bearer {$secret}") !== false &&
    strpos($sent, '{"token_id":"a1b2c3d4e5f6"}') !== false, 'the request carries the bearer token and the body');
$mismatch = join_error_of(function () use ($tl, $secret) {
	fleet_http_pinned($tl + array('cert_sha256' => str_repeat('0', 64)), 'POST', '/fleet/join/begin', array('token_id' => 'a1b2c3d4e5f6'), $secret);
});
check($mismatch === 'E101', 'a certificate that does not match the link is E101');
usleep(200000);
check(!is_file("{$tmp}/req1") || strpos((string)file_get_contents("{$tmp}/req1"), $secret) === false, 'nothing secret was sent to the wrong certificate');
proc_terminate($proc);
foreach (glob("{$tmp}/*") as $f) {
	unlink($f);
}
rmdir($tmp);
check(join_error_of(function () { fleet_http_pinned(array('authority' => '127.0.0.1:1', 'host' => '127.0.0.1', 'port' => 1, 'cert_sha256' => str_repeat('0', 64)),
    'GET', '/', null, 'x'); }) === 'E105', 'an unreachable controller is E105');
/* ---- the client against a fake controller ---- */
class FakeController {
	public $okm;
	public $cert;
	public $log = array();
	public $approve_after = 1;
	public $polls = 0;
	public $fail = array();
	public $challenge;
	public $ctrl_pub;
	public $eid = 'e-0123456789abcdef0123';
	public $node_id = 'node-0123456789';
	public $time;

	public function __construct($okm, $cert, $time) {
		$this->okm = $okm;
		$this->cert = $cert;
		$this->time = $time;
		$this->challenge = str_repeat("\x11", 32);
		$this->ctrl_pub = str_repeat("\x44", 32);
	}

	public function post($path, $body, $bearer) {
		$this->log[] = "POST {$path}";
		if (isset($this->fail[$path])) {
			return $this->fail[$path];
		}
		if ($path === '/fleet/join/begin') {
			return array(200, array('challenge' => base64_encode($this->challenge),
			    'controller_wg_pub' => base64_encode($this->ctrl_pub), 'time' => $this->time));
		}
		if ($path === '/fleet/join/enroll') {
			$want = fleet_join_proof($this->okm, $this->challenge, base64_decode($body['nonce']), $body['token_id'],
			    base64_decode($body['node_wg_pub']), $this->cert);
			if (!hash_equals($want, $body['proof'])) {
				return array(401, array('error' => array('code' => 'bad_proof')));
			}
			return array(200, array('enrollment_id' => $this->eid));
		}
		if ($path === '/fleet/join/complete') {
			$want = fleet_join_complete_proof($this->okm, $body['enrollment_id'], $body['api_key']);
			if (!hash_equals($want, $body['proof'])) {
				return array(401, array('error' => array('code' => 'bad_proof')));
			}
			return array(200, array('node_id' => $this->node_id, 'signing_key' => 'PEM', 'method' => 'wireguard',
			    'overlay_address' => 'fd12:3456:789a:f1::10', 'role' => 'managed', 'hostname' => 'dk-cph-edge01', 'peers' => array()));
		}
		return array(404, array());
	}

	public function get($path, $bearer) {
		$this->log[] = "GET {$path}";
		$this->polls++;
		return array(200, array('state' => ($this->polls > $this->approve_after) ? 'approved' : 'pending'));
	}
}

function make_io(FakeController $c, array &$events, array &$undone, array $opts = array()) {
	$now = $c->time;
	return array(
		'post' => function ($p, $b, $bearer) use ($c) { return $c->post($p, $b, $bearer); },
		'get' => function ($p, $bearer) use ($c) { return $c->get($p, $bearer); },
		'wg_keypair' => function () { return array('PRIVATE', str_repeat("\x33", 32)); },
		'clock' => function () use (&$now, $opts) { return $now += ($opts['tick'] ?? 0); },
		'create_access' => function () use (&$undone) {
			return array('fsk_0123456789ab_' . str_repeat('A', 43), function () use (&$undone) { $undone[] = 'access'; });
		},
		'apply' => function ($m) use (&$undone, $opts) {
			if (!empty($opts['apply_fails'])) {
				throw new RuntimeException('wg-fleet0 could not be created');
			}
			return function () use (&$undone) { $undone[] = 'membership'; };
		},
		'inventory' => function () { return array('serial' => 'X1'); },
		'step' => function ($n, $s, $t) use (&$events) { $events[] = "{$n}:{$s}" . ($t !== '' ? ":{$t}" : ''); },
		'sleep' => function ($s) {},
	);
}

function run_join(array $parsed, $key, FakeController $c, array &$events, array &$undone, array $opts = array(), int $timeout = 900) {
	return fleet_join_run($parsed, $key, make_io($c, $events, $undone, $opts), $timeout);
}

$parsed = fleet_join_link_parse($link);
$events = array();
$undone = array();
$c = new FakeController($okm, $cert, 1760000000);
$m = run_join($parsed, $data, $c, $events, $undone);
check($m['node_id'] === 'node-0123456789' && $m['controller_url'] === 'https://hq.example.net:8443' && $m['role'] === 'managed' &&
    $m['hostname'] === 'dk-cph-edge01' && $m['wg_private'] === 'PRIVATE', 'a join returns the membership');
check($undone === array(), 'nothing is undone on success');
check(count(preg_grep('/^approval:wait:\d{6}$/', $events)) === 1, 'the approval code is shown while waiting');
check($events[0] === 'connect:start' && end($events) === 'heartbeat:done', 'steps run in order');
check($c->log[0] === 'POST /fleet/join/begin' && in_array('POST /fleet/join/complete', $c->log, true), 'the exchange order');

/* a wrong key: the controller rejects the proof */
$events = array();
$undone = array();
$c = new FakeController(fleet_join_okm('K7Q2M9XH4T1RZ8VB3ND', $cert), $cert, 1760000000);
check(join_error_of(function () use ($parsed, $data, $c, &$events, &$undone) { run_join($parsed, $data, $c, $events, $undone); }) === 'E103',
    'a wrong key is E103');
check($undone === array() && end($events) === 'fail:fail:E103: The join key is wrong.', 'a wrong key changes nothing on the box');

/* burned, expired, wrong instance, wrong binding */
foreach (array(array('invitation_burned', 'E104'), array('token_expired', 'E102'), array('instance_mismatch', 'E111'),
    array('binding_mismatch', 'E121')) as list($api, $want)) {
	$c = new FakeController($okm, $cert, 1760000000);
	$c->fail['/fleet/join/enroll'] = array(403, array('error' => array('code' => $api)));
	$events = array();
	$undone = array();
	check(join_error_of(function () use ($parsed, $data, $c, &$events, &$undone) { run_join($parsed, $data, $c, $events, $undone); }) === $want,
	    "{$api} is {$want}");
}

/* clock skew */
$c = new FakeController($okm, $cert, 1760000000 + 3600);
$events = array();
$undone = array();
$io = make_io($c, $events, $undone);
$io['clock'] = function () { return 1760000000; };
check(join_error_of(function () use ($parsed, $data, $io) { fleet_join_run($parsed, $data, $io); }) === 'E108', 'clock skew over 5 minutes is E108');

/* approval never comes */
$c = new FakeController($okm, $cert, 1760000000);
$c->approve_after = 1000000;
$events = array();
$undone = array();
check(join_error_of(function () use ($parsed, $data, $c, &$events, &$undone) {
	run_join($parsed, $data, $c, $events, $undone, array('tick' => 100), 900);
}) === 'E102', 'no approval in time is refused');

/* a failure after the access exists is rolled back (E110) */
$c = new FakeController($okm, $cert, 1760000000);
$events = array();
$undone = array();
check(join_error_of(function () use ($parsed, $data, $c, &$events, &$undone) {
	run_join($parsed, $data, $c, $events, $undone, array('apply_fails' => true));
}) === 'E110', 'a failing tunnel is E110');
check($undone === array('access'), 'the fleet-controller access created before the failure is removed');

/* the controller sends rubbish */
$c = new FakeController($okm, $cert, 1760000000);
$c->node_id = '../x';
$events = array();
$undone = array();
check(join_error_of(function () use ($parsed, $data, $c, &$events, &$undone) { run_join($parsed, $data, $c, $events, $undone); }) === 'E105',
    'an invalid node id is E105');
check($undone === array('access'), 'and the access is removed');

echo "FleetJoinSmokeTest: OK\n";
