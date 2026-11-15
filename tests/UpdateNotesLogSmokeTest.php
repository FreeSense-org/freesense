<?php
/*
 * Standalone CI regression test for the Development "What is new" notes; run with
 * `php tests/UpdateNotesLogSmokeTest.php`. The functions live in pkg-utils.inc, which
 * needs a whole firewall to load, so the test takes just that block of the file.
 */
$source = file_get_contents(dirname(__DIR__) . '/src/etc/inc/pkg-utils.inc');
$start = strpos($source, "/*\n * What is new on a Development system.");
$end = strrpos($source, '?>');
if ($start === false || $end === false || $end < $start) {
	fwrite(STDERR, "FAIL: the Development notes block is missing from pkg-utils.inc\n");
	exit(1);
}
eval(substr($source, $start, $end - $start));

function check($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

function entry($generation, array $changes, $platform = null, $date = '2026-11-15T10:00:00Z') {
	return array('generation' => $generation, 'version' => '1.2.0', 'published_at' => $date, 'system' => str_repeat('1', 64),
	    'provenance' => array('freebsd' => str_repeat('b', 40), 'ports' => str_repeat('c', 40)),
	    'changes' => $changes, 'platform' => $platform);
}

function change($title, $scope = 'System', $type = 'fix') {
	return array('type' => $type, 'title' => $title, 'scope' => $scope);
}

function platform($updated = array(), $added = array(), $removed = array(), $from = 'a', $to = 'b') {
	return array(
		'freebsd' => array('changed' => $from !== $to, 'ports_changed' => false, 'from_commit' => str_repeat($from, 40),
		    'to_commit' => str_repeat($to, 40), 'from_ports_commit' => str_repeat('c', 40), 'to_ports_commit' => str_repeat('c', 40)),
		'packages' => array('available' => true, 'updated' => $updated, 'added' => $added, 'removed' => $removed,
		    'counts' => array('updated' => count($updated), 'added' => count($added), 'removed' => count($removed)),
		    'truncated' => false),
	);
}

$log = array('schema_version' => PKG_NOTES_LOG_SCHEMA, 'channel' => 'devel', 'entries' => array(
	entry(300, array(change('WebUI: three'), change('Same title')), platform(array(array('name' => 'curl', 'from' => '8.2', 'to' => '8.3', 'origin' => 'ftp/curl')), array(), array(), 'b', 'c'), '2026-11-15T10:00:00Z'),
	entry(200, array(change('WebUI: two'), change('Same title')), platform(array(array('name' => 'curl', 'from' => '8.1', 'to' => '8.2', 'origin' => 'ftp/curl')), array(array('name' => 'uplot', 'version' => '1.6', 'origin' => 'www/uplot')), array(), 'a', 'b'), '2026-11-14T10:00:00Z'),
	entry(100, array(change('WebUI: one')), platform(), '2026-11-13T10:00:00Z'),
));

/* ---- the installed generation ---- */
check(pkg_build_generation('1.2.0.a.20261114.1340.1794665047') === 1794665047, 'generation of a package version');
check(pkg_build_generation('1.2.0_a_20261114_1340_1794665047') === 1794665047, 'generation with underscores');
check(pkg_build_generation('1.2.0') === null && pkg_build_generation('') === null && pkg_build_generation(null) === null,
    'no generation in a plain version');

/* ---- a system that is behind sees exactly what it has not installed ---- */
$doc = pkg_update_notes_from_log($log, 100);
check($doc !== null && $doc['schema_version'] === 'freesense.download/v4' && $doc['channel'] === 'devel',
    'a release document the card and REST route already read');
check($doc['notes_builds'] === 2 && $doc['generation'] === 300 && $doc['release_id'] === '1.2.0-g300' &&
    $doc['notes_since'] === '2026-11-14T10:00:00Z', 'the two builds newer than the installed one');
$titles = array_column($doc['release_notes']['freesense'], 'title');
check($titles === array('WebUI: three', 'Same title', 'WebUI: two'), 'newest first, a repeated title once: ' . implode('|', $titles));
$freebsd = $doc['release_notes']['platform']['freebsd'];
check($freebsd['changed'] === true && $freebsd['from_commit'] === str_repeat('a', 40) && $freebsd['to_commit'] === str_repeat('c', 40),
    'FreeBSD goes from the oldest build\'s start to the newest build\'s end');
$packages = $doc['release_notes']['platform']['packages'];
check($packages['updated'] === array(array('name' => 'curl', 'from' => '8.1', 'to' => '8.3', 'origin' => 'ftp/curl')),
    'an update keeps its first from and last to');
check($packages['added'] === array(array('name' => 'uplot', 'version' => '1.6', 'origin' => 'www/uplot')) &&
    $packages['counts'] === array('updated' => 1, 'added' => 1, 'removed' => 0), 'added packages and counts');

/* ---- an up to date system sees the latest builds ---- */
$latest = pkg_update_notes_from_log($log, 300);
check($latest['notes_builds'] === 3 && $latest['generation'] === 300, 'up to date: the latest three builds');
check(pkg_update_notes_from_log($log, null, 1)['notes_builds'] === 1, 'unknown installed build: the latest one');
check(pkg_update_notes_from_log($log, 999999)['generation'] === 300, 'a newer installed build than the log: the latest builds');

/* ---- package changes across builds cancel out ---- */
$a = entry(2, array(), platform(array(), array(array('name' => 'x', 'version' => '1', 'origin' => 'a/x'))));
$r = entry(3, array(), platform(array(), array(), array(array('name' => 'x', 'version' => '1', 'origin' => 'a/x'))));
$gone = pkg_notes_merge_packages(array($a, $r));
check($gone['counts'] === array('updated' => 0, 'added' => 0, 'removed' => 0), 'added then removed is nothing');
$swap = pkg_notes_merge_packages(array(
	entry(2, array(), platform(array(), array(), array(array('name' => 'y', 'version' => '1', 'origin' => 'a/y')))),
	entry(3, array(), platform(array(), array(array('name' => 'y', 'version' => '2', 'origin' => 'a/y')))),
));
check($swap['updated'] === array(array('name' => 'y', 'from' => '1', 'to' => '2', 'origin' => 'a/y')), 'removed then added is an update');

/* ---- long lists are capped and say so ---- */
$many = array();
for ($i = 0; $i < 250; $i++) {
	$many[] = array('name' => sprintf('p%03d', $i), 'from' => '1', 'to' => '2', 'origin' => 'a/p');
}
$capped = pkg_notes_merge_packages(array(entry(2, array(), platform($many))));
check(count($capped['updated']) === 200 && $capped['counts']['updated'] === 250 && $capped['truncated'] === true,
    'package lists are capped at 200 and flagged');
$long = array();
for ($i = 0; $i < 60; $i++) {
	$long[] = change("Change {$i}");
}
check(count(pkg_update_notes_from_log(array('schema_version' => PKG_NOTES_LOG_SCHEMA, 'entries' => array(entry(5, $long))))
    ['release_notes']['freesense']) === 30, 'the change list is capped');

/* ---- anything unusable falls back to the release document ---- */
check(pkg_update_notes_from_log(null) === null && pkg_update_notes_from_log(array()) === null, 'no log: null');
check(pkg_update_notes_from_log(array('schema_version' => 'other', 'entries' => $log['entries'])) === null, 'a foreign schema: null');
check(pkg_update_notes_from_log(array('schema_version' => PKG_NOTES_LOG_SCHEMA, 'entries' => array())) === null, 'an empty log: null');
check(pkg_update_notes_from_log(array('schema_version' => PKG_NOTES_LOG_SCHEMA, 'entries' => array('x', array('generation' => 'y')))) === null,
    'broken entries are ignored');

/* ---- the first entry of a log has no platform diff yet ---- */
$first = pkg_update_notes_from_log(array('schema_version' => PKG_NOTES_LOG_SCHEMA, 'entries' => array(entry(7, array(change('First'))))));
check($first['release_notes']['platform']['freebsd']['changed'] === false &&
    $first['release_notes']['platform']['packages']['counts'] === array('updated' => 0, 'added' => 0, 'removed' => 0),
    'a first entry shows its changes and no invented platform diff');

/* ---- what is new in 1.2 beyond Stable 1.1 ---- */
check(array_key_exists('since_stable', $doc) && $doc['since_stable'] === null, 'no section in the log: none in the document');
$withStable = $log;
$withStable['since_stable'] = array('baseline' => 'RELENG_1_1', 'total' => 280, 'generation' => 300, 'changes' => array(
	array('type' => 'feature', 'title' => 'VPN: WireGuard peers can be disabled', 'scope' => 'System', 'date' => '2026-11-10'),
	array('type' => 'fix', 'title' => '', 'scope' => 'System'),
	'junk',
));
$both = pkg_update_notes_from_log($withStable, 300);
check($both['since_stable']['baseline'] === 'RELENG_1_1' && $both['since_stable']['total'] === 280 &&
    count($both['since_stable']['changes']) === 1 &&
    $both['since_stable']['changes'][0]['title'] === 'VPN: WireGuard peers can be disabled',
    'the 1.2 list passes through without empty or broken lines, with the publisher total');
$broken = $log;
$broken['since_stable'] = 'x';
check(pkg_update_notes_from_log($broken, 300)['since_stable'] === null, 'a broken section is ignored');

echo "UpdateNotesLogSmokeTest passed\n";
