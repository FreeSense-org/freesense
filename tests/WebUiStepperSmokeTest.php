<?php
/* Standalone CI regression test for step-by-step guides (fs_stepper, fs_steps_begin in fs_ui.inc); run with `php tests/WebUiStepperSmokeTest.php`. */

$root = dirname(__DIR__);
if (!function_exists('gettext')) {
	function gettext($t) { return $t; }
}
require_once($root . '/src/usr/local/www/includes/fs_ui.inc');

function check($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

function out(callable $f) {
	ob_start();
	$f();
	return ob_get_clean();
}

$steps = array('paste' => 'Paste', 'check' => 'Check', 'key' => 'Join key', 'review' => 'Review');

$h = out(function () use ($steps) { fs_stepper($steps, 'review', array('key'), 'Add to fleet'); });
check(substr_count($h, '<li class="fs-step') === 4, 'one item per step');
check(strpos($h, 'aria-label="Add to fleet"') !== false, 'the stepper is labelled');
check(preg_match('/is-done" data-step="paste"/', $h) && preg_match('/is-done" data-step="check"/', $h), 'steps before the current one are done');
check(preg_match('/is-skipped" data-step="key"/', $h) === 1, 'a skipped step says so');
check(preg_match('/is-current" data-step="review" aria-current="step"/', $h) === 1, 'the current step has aria-current');
check(substr_count($h, 'aria-current') === 1, 'only one current step');
check(strpos($h, '<span class="fs-step-num" aria-hidden="true">4</span>') !== false, 'steps are numbered');

$h = out(function () { fs_stepper(array('a' => '<b>x</b>'), 'a'); });
check(strpos($h, '<b>') === false && strpos($h, '&lt;b&gt;') !== false, 'labels are escaped');

$h = out(function () use ($steps) {
	fs_steps_begin($steps, 'check');
	fs_step_begin('paste', 'Copy it from the controller.');
	echo '<textarea required></textarea>';
	fs_step_end();
	fs_step_begin('check');
	fs_step_end();
	fs_steps_end();
});
check(strpos($h, 'data-fs-steps data-fs-steps-start="check"') !== false, 'a guide in one form says where it starts');
check(preg_match('/is-current" data-step="check"/', $h) === 1, 'its stepper starts at that step');
check(substr_count($h, 'data-fs-step="') === 2, 'one panel per step');
check(strpos($h, '<h3 class="fs-step-title" id="fs-step-check" tabindex="-1">2. Check</h3>') !== false, 'a panel has its numbered title');
check(strpos($h, '<p class="fs-step-lead">Copy it from the controller.</p>') !== false, 'a panel can have a lead');
check(strpos($h, 'data-fs-step-nav') !== false, 'room for Back and Next');
check(strpos($h, '&quot;next&quot;:&quot;Next&quot;') !== false, 'Back and Next labels come from the server (translated)');
check(!isset($GLOBALS['fs_steps_open']), 'the guide is closed');

/* freesense-ui.js wires the guides up */
$js = file_get_contents($root . '/src/usr/local/www/js/freesense-ui.js');
check(strpos($js, "document.querySelectorAll('[data-fs-steps]').forEach(initSteps)") !== false, 'guides are initialised');
check(strpos($js, 's._fsSteps.reset()') !== false, 'a modal guide starts over when opened');

echo "WebUiStepperSmokeTest: OK\n";
