<?php
/* Standalone CI regression test; run with `php tests/GlobalsRegistrySmokeTest.php`. */

/*
 * The global $g registry (globals.inc) must survive page code that reuses the
 * name $g in the global scope, and no WebGUI page or include may do that.
 * A page-scope "foreach (... as $g)" once made the footer and the shutdown
 * handler fail with "array_key_exists(): Argument #2 must be of type array,
 * int given in globals.inc".
 */

set_include_path(get_include_path() . PATH_SEPARATOR . realpath(__DIR__ . '/../src/etc/inc'));
ini_set('error_log', '/dev/null');
require_once('globals.inc');

function check_g($condition, $message) {
	if (!$condition) {
		fwrite(STDERR, "FAIL: {$message}\n");
		exit(1);
	}
}

/* Runtime: a clobbered registry is restored instead of throwing a TypeError. */
$label = g_get('product_label');
check_g(is_string($label) && $label !== '', 'product_label must be set');
g_set('fs_guard_test', 'kept', true);

$g = 4;
try {
	$got = g_get('product_label');
} catch (TypeError $e) {
	check_g(false, 'g_get() must not throw when $g was replaced: ' . $e->getMessage());
}
check_g($got === $label, 'g_get() must return the registry value after $g was replaced');
check_g(is_array($g), '$g must be an array again');
check_g(g_get('fs_guard_test') === 'kept', 'values set with g_set() must survive the restore');
check_g(g_has('xml_rootobj'), 'g_has() must work after the restore');

$g = null;
g_unset('fs_guard_test');
check_g(is_array($g) && !g_has('fs_guard_test'), 'g_unset() must work after $g was replaced');

/* Static: no WebGUI page or include writes $g outside a function or class. */
function page_scope_g_writes($file) {
	$tokens = token_get_all(file_get_contents($file));
	$n = count($tokens);
	$hits = [];
	$sig = function ($i, $step) use ($tokens, $n) {
		for ($i += $step; $i >= 0 && $i < $n; $i += $step) {
			if (!is_array($tokens[$i]) || !in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
				return $i;
			}
		}
		return -1;
	};
	$is = function ($i, $what) use ($tokens) {
		if ($i < 0) {
			return false;
		}
		$t = $tokens[$i];
		return is_array($t) ? in_array($t[0], (array) $what, true) : in_array($t, (array) $what, true);
	};
	$assign = ['=', T_PLUS_EQUAL, T_MINUS_EQUAL, T_MUL_EQUAL, T_DIV_EQUAL, T_CONCAT_EQUAL, T_MOD_EQUAL,
	    T_AND_EQUAL, T_OR_EQUAL, T_XOR_EQUAL, T_SL_EQUAL, T_SR_EQUAL, T_POW_EQUAL, T_COALESCE_EQUAL, T_INC, T_DEC];
	for ($i = 0; $i < $n; $i++) {
		$t = $tokens[$i];
		/* Skip function, method, closure and class bodies (their $g is local or a property). */
		if (is_array($t) && in_array($t[0], [T_FUNCTION, T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
			if ($t[0] === T_CLASS && $is($sig($i, -1), T_DOUBLE_COLON)) {
				continue;
			}
			for ($j = $i + 1; $j < $n && $tokens[$j] !== '{' && $tokens[$j] !== ';'; $j++);
			if ($j >= $n || $tokens[$j] === ';') {
				$i = $j;
				continue;
			}
			for ($depth = 0; $j < $n; $j++) {
				$tj = $tokens[$j];
				if ($tj === '{' || (is_array($tj) && in_array($tj[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
					$depth++;
				} elseif ($tj === '}' && --$depth === 0) {
					break;
				}
			}
			$i = $j;
			continue;
		}
		if (!is_array($t) || $t[0] !== T_VARIABLE || $t[1] !== '$g') {
			continue;
		}
		$prev = $sig($i, -1);
		$next = $sig($i, 1);
		if ($is($prev, '&')) {
			$prev = $sig($prev, -1);
		}
		if ($is($next, $assign) || $is($prev, [T_INC, T_DEC, T_AS]) ||
		    ($is($prev, T_DOUBLE_ARROW) && $is($next, ')'))) {
			$hits[] = $t[2];
		}
	}
	return $hits;
}

$root = realpath(__DIR__ . '/../src/usr/local/www');
$offenders = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
	$path = str_replace('\\', '/', $f->getPathname());
	if (!preg_match('/\.(php|inc)$/', $path) || strpos($path, '/vendor/') !== false) {
		continue;
	}
	foreach (page_scope_g_writes($path) as $line) {
		$offenders[] = substr($path, strlen($root) + 1) . ':' . $line;
	}
}
check_g(empty($offenders), 'WebGUI code must not assign $g in the page scope (it is the global registry): ' .
    implode(', ', $offenders));

/* The scanner itself: it must catch the pattern that caused the fatal. */
$probe = tempnam(sys_get_temp_dir(), 'fs-g-');
file_put_contents($probe, "<?php\nforeach (array(1, 2) as \$g) {}\nfunction f() { \$g = 1; }\n\$c = function () { \$g = 2; };\n\$g['x'] = 1;\n");
$hits = page_scope_g_writes($probe);
unlink($probe);
check_g($hits === [2], 'the scanner must flag only the page-scope loop variable');

echo "Globals registry smoke test passed.\n";
