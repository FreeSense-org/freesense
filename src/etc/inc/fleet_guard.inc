<?php
/*
 * fleet_guard.inc
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2026 The FreeSense Project
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
 * What keeps a managed firewall reachable and compliant:
 *
 *  - Commit-confirm: before a risky change the controller asks for a
 *    snapshot and a deadline. If it does not confirm in time (because the
 *    change cut it off), a watchdog on the box restores the snapshot (E310).
 *  - Lock: a policy rule in mode "lock" refuses a local save that would make
 *    the box break it. The rules come from the controller-signed bundle the
 *    box holds, so Lock works while the controller is offline. A save is only
 *    refused when it introduces the violation; a box that already breaks a
 *    rule can still be saved (and fixed).
 *  - Break-glass: at the console an admin suspends Lock for 1 to 24 hours,
 *    with a reason. It raises an alert and shows in the heartbeat (E321).
 *  - The "Managed by" banner, including what the controller is doing now.
 *
 * Rules are config-path predicates, never code: {id, title, mode: audit |
 * remediate | lock, path: "system/webgui/protocol", op, value}. Functions
 * marked "pure" touch neither the system nor config.xml.
 */

require_once('fleet_node.inc');

define('FLEET_LOCK_BREAKGLASS', FLEET_NODE_DIR . '/breakglass.json');
define('FLEET_CONFIRM_DIR', FLEET_NODE_DIR . '/confirm');
define('FLEET_ACTIVITY_FILE', '/var/run/fleet-activity.json');
define('FLEET_RULE_MODES', array('audit', 'remediate', 'lock'));
define('FLEET_RULE_OPS', array('equals', 'not_equals', 'in', 'not_in', 'regex', 'present', 'absent', 'min', 'max'));
define('FLEET_CONFIRM_MIN', 30);
define('FLEET_CONFIRM_MAX', 3600);
define('FLEET_BREAKGLASS_MAX_HOURS', 24);
define('FLEET_ACTIVITY_MAX_SECONDS', 3600);

/* ------------------------------------------------------------------ */
/* Policy rules                                                        */
/* ------------------------------------------------------------------ */

/* pure: a rule the box can evaluate. */
function fleet_rule_valid($rule): bool {
	if (!is_array($rule) || !preg_match('/^[A-Z][A-Z0-9]{1,5}-[0-9]{3}$/D', (string)($rule['id'] ?? '')) ||
	    !in_array($rule['mode'] ?? '', FLEET_RULE_MODES, true) || !in_array($rule['op'] ?? '', FLEET_RULE_OPS, true) ||
	    !preg_match('~^[a-z0-9_]+(/[A-Za-z0-9_.-]+)*$~D', (string)($rule['path'] ?? '')) ||
	    (strlen((string)($rule['title'] ?? '')) > 160)) {
		return false;
	}
	$op = $rule['op'];
	if (in_array($op, array('present', 'absent'), true)) {
		return true;
	}
	if (!array_key_exists('value', $rule)) {
		return false;
	}
	if (in_array($op, array('in', 'not_in'), true)) {
		return is_array($rule['value']) && !empty($rule['value']);
	}
	if (in_array($op, array('min', 'max'), true)) {
		return is_numeric($rule['value']);
	}
	if ($op === 'regex') {
		return is_string($rule['value']) && (strlen($rule['value']) <= 200) && (@preg_match('~' . str_replace('~', '\~', $rule['value']) . '~D', '') !== false);
	}
	return is_scalar($rule['value']);
}

/*
 * pure: whether $rule holds. $get($path) gives the configuration value as
 * text, or null when the path is absent (an empty flag element is "").
 */
function fleet_rule_compliant(array $rule, callable $get): bool {
	$v = $get($rule['path']);
	switch ($rule['op']) {
	case 'present':
		return $v !== null;
	case 'absent':
		return $v === null;
	}
	if ($v === null) {
		/* A missing value is only fine for the negative tests. */
		return in_array($rule['op'], array('not_equals', 'not_in'), true);
	}
	$v = (string)$v;
	switch ($rule['op']) {
	case 'equals':
		return $v === (string)$rule['value'];
	case 'not_equals':
		return $v !== (string)$rule['value'];
	case 'in':
		return in_array($v, array_map('strval', $rule['value']), true);
	case 'not_in':
		return !in_array($v, array_map('strval', $rule['value']), true);
	case 'regex':
		return (bool)preg_match('~' . str_replace('~', '\~', $rule['value']) . '~D', $v);
	case 'min':
		return is_numeric($v) && ((float)$v >= (float)$rule['value']);
	case 'max':
		return is_numeric($v) && ((float)$v <= (float)$rule['value']);
	}
	return false;
}

/*
 * pure: the lock rules a save would newly break: compliant in the saved
 * configuration ($old) and not in the one about to be saved ($new).
 */
function fleet_lock_new_violations(array $rules, callable $new, callable $old): array {
	$out = array();
	foreach ($rules as $rule) {
		if (fleet_rule_valid($rule) && ($rule['mode'] === 'lock') && fleet_rule_compliant($rule, $old) && !fleet_rule_compliant($rule, $new)) {
			$out[] = $rule;
		}
	}
	return $out;
}

/* pure: what the admin reads when a save is refused. */
function fleet_lock_message(array $violations, string $controller): string {
	$parts = array();
	foreach ($violations as $rule) {
		$parts[] = $rule['id'] . ((($rule['title'] ?? '') !== '') ? ': ' . $rule['title'] : '');
	}
	return 'Blocked by Fleet policy ' . implode('; ', $parts) . ' (managed by ' . $controller . ').' .
	    ' A console administrator can suspend Lock with break-glass: freesense-join breakglass.';
}

/* ------------------------------------------------------------------ */
/* Break-glass                                                         */
/* ------------------------------------------------------------------ */

/* pure: a break-glass record, or an InvalidArgumentException naming the problem. */
function fleet_breakglass_record(int $hours, string $reason, int $now, string $who): array {
	if (($hours < 1) || ($hours > FLEET_BREAKGLASS_MAX_HOURS)) {
		throw new InvalidArgumentException('Break-glass lasts from 1 to ' . FLEET_BREAKGLASS_MAX_HOURS . ' hours.');
	}
	$reason = trim($reason);
	if ((strlen($reason) < 3) || (strlen($reason) > 200) || preg_match('/[\x00-\x1f\x7f]/', $reason)) {
		throw new InvalidArgumentException('Give a reason of 3 to 200 characters.');
	}
	return array('since' => $now, 'until' => $now + $hours * 3600, 'reason' => $reason, 'by' => $who);
}

/* pure: break-glass is in force. */
function fleet_breakglass_active(?array $record, int $now): bool {
	return is_array($record) && ((int)($record['until'] ?? 0) > $now);
}

/* ------------------------------------------------------------------ */
/* Commit-confirm                                                      */
/* ------------------------------------------------------------------ */

/* pure: a confirm id. */
function fleet_confirm_valid_id($id): bool {
	return is_string($id) && (bool)preg_match('/^c-[0-9a-f]{16}$/D', $id);
}

/* pure: a pending commit-confirm record, or an InvalidArgumentException. */
function fleet_confirm_record(string $id, int $seconds, string $label, int $now, string $who): array {
	if (!fleet_confirm_valid_id($id)) {
		throw new InvalidArgumentException('id: invalid');
	}
	if (($seconds < FLEET_CONFIRM_MIN) || ($seconds > FLEET_CONFIRM_MAX)) {
		throw new InvalidArgumentException('seconds: from ' . FLEET_CONFIRM_MIN . ' to ' . FLEET_CONFIRM_MAX);
	}
	if ((strlen($label) > 120) || preg_match('/[\x00-\x1f\x7f]/', $label)) {
		throw new InvalidArgumentException('label: up to 120 characters and no control characters');
	}
	return array('id' => $id, 'label' => $label, 'started' => $now, 'deadline' => $now + $seconds, 'by' => $who);
}

/* pure: the window has passed. */
function fleet_confirm_due(array $record, int $now): bool {
	return $now >= (int)$record['deadline'];
}

/* ------------------------------------------------------------------ */
/* Banner                                                              */
/* ------------------------------------------------------------------ */

/*
 * pure: the "Managed by" banner. $activity is {text, step, steps, by, until}
 * or null; $breakglass an active record or null. Everything is escaped.
 */
function fleet_banner_html(array $m, ?array $activity, ?array $breakglass, int $lock_rules, int $now): string {
	$h = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES); };
	$html = '<div class="alert alert-info fs-fleet-banner" role="status"><i class="fa-solid fa-network-wired" aria-hidden="true"></i> ' .
	    '<strong>Managed by FreeSense Fleet</strong> (' . $h($m['controller']['url']) . '). Some changes made here may be blocked or reverted by policy.';
	if ($lock_rules > 0) {
		$html .= ' <span class="fs-muted">' . (int)$lock_rules . ' locked rule' . ($lock_rules === 1 ? '' : 's') . '.</span>';
	}
	if (is_array($activity) && ((int)($activity['until'] ?? 0) > $now)) {
		$step = '';
		if (((int)($activity['steps'] ?? 0)) > 0) {
			$step = ', step ' . (int)($activity['step'] ?? 0) . ' of ' . (int)$activity['steps'];
		}
		$html .= '<div><i class="fa-solid fa-spinner" aria-hidden="true"></i> <strong>Fleet is working on this firewall:</strong> ' .
		    $h($activity['text'] ?? '') . $h($step) . (($activity['by'] ?? '') !== '' ? ' (started by ' . $h($activity['by']) . ')' : '') .
		    '. Please do not make changes until it is done.</div>';
	}
	if (is_array($breakglass)) {
		$html .= '<div class="text-danger"><i class="fa-solid fa-triangle-exclamation" aria-hidden="true"></i> <strong>Break-glass is on</strong> until ' .
		    $h(gmdate('Y-m-d H:i', (int)$breakglass['until'])) . ' UTC: policy Lock is suspended (' . $h($breakglass['reason'] ?? '') . ').</div>';
	}
	return $html . '</div>';
}

/* pure: an activity record from the controller, or an InvalidArgumentException. */
function fleet_activity_record(array $body, int $now): array {
	foreach (array_keys($body) as $field) {
		if (!in_array($field, array('text', 'step', 'steps', 'by', 'seconds'), true)) {
			throw new InvalidArgumentException("{$field}: unknown field");
		}
	}
	$text = (string)($body['text'] ?? '');
	$by = (string)($body['by'] ?? '');
	if (($text === '') || (strlen($text) > 160) || preg_match('/[\x00-\x1f\x7f]/', $text)) {
		throw new InvalidArgumentException('text: 1 to 160 characters and no control characters');
	}
	if ((strlen($by) > 80) || preg_match('/[\x00-\x1f\x7f]/', $by)) {
		throw new InvalidArgumentException('by: up to 80 characters and no control characters');
	}
	foreach (array('step', 'steps') as $f) {
		if (isset($body[$f]) && (!is_int($body[$f]) || ($body[$f] < 0) || ($body[$f] > 99))) {
			throw new InvalidArgumentException("{$f}: an integer from 0 to 99");
		}
	}
	$seconds = $body['seconds'] ?? 300;
	if (!is_int($seconds) || ($seconds < 10) || ($seconds > FLEET_ACTIVITY_MAX_SECONDS)) {
		throw new InvalidArgumentException('seconds: from 10 to ' . FLEET_ACTIVITY_MAX_SECONDS);
	}
	return array('text' => $text, 'step' => (int)($body['step'] ?? 0), 'steps' => (int)($body['steps'] ?? 0), 'by' => $by, 'until' => $now + $seconds);
}

/* ------------------------------------------------------------------ */
/* System access: Lock                                                 */
/* ------------------------------------------------------------------ */

/* Lock is skipped for the agent's own saves (joining, leaving, rolling back). */
function fleet_lock_bypass(?bool $set = null): bool {
	static $bypass = false;
	if ($set !== null) {
		$bypass = $set;
	}
	return $bypass;
}

function fleet_breakglass_read(): ?array {
	$r = @json_decode((string)@file_get_contents(FLEET_LOCK_BREAKGLASS), true);
	return (is_array($r) && fleet_breakglass_active($r, time())) ? $r : null;
}

/*
 * Called from write_config() when the box holds a policy bundle: the reason
 * the save in progress must be refused, or null. Never throws.
 */
function fleet_lock_refusal(): ?string {
	try {
		if (fleet_lock_bypass() || (config_get_path('fleet/node_id', '') === '')) {
			return null;
		}
		$stored = fleet_policy_bundle_read();
		if (($stored === null) || fleet_breakglass_read() !== null) {
			return null;
		}
		$rules = array_values(array_filter((array)$stored['bundle']['rules'], function ($r) {
			return is_array($r) && (($r['mode'] ?? '') === 'lock');
		}));
		if (empty($rules)) {
			return null;
		}
		require_once('xmlparse.inc');
		$old_config = parse_xml_config(g_get('conf_path') . '/config.xml', array(g_get('xml_rootobj')));
		if (!is_array($old_config)) {
			return null;
		}
		$text = function ($value) {
			return ($value === null) ? null : (is_array($value) ? (empty($value) ? '' : null) : (string)$value);
		};
		$violations = fleet_lock_new_violations($rules,
		    function ($path) use ($text) { return $text(config_get_path($path)); },
		    function ($path) use ($text, &$old_config) { return $text(array_get_path($old_config, $path)); });
		return empty($violations) ? null : fleet_lock_message($violations, (string)config_get_path('fleet/controller_url'));
	} catch (Throwable $e) {
		/* A broken guard must never stop the box from saving. */
		return null;
	}
}

/* Suspend Lock; alerts at once. */
function fleet_breakglass_set(int $hours, string $reason, string $who): array {
	$record = fleet_breakglass_record($hours, $reason, time(), $who);
	if (!fleet_node_dir(FLEET_NODE_DIR) || !fleet_node_write(FLEET_LOCK_BREAKGLASS, json_encode($record))) {
		throw new RuntimeException('Break-glass could not be saved.');
	}
	$msg = "FLEET-E321: break-glass is on for {$hours} h by {$who}: {$record['reason']}. Policy Lock is suspended.";
	logger(LOG_ALERT, localize_text('%s', $msg));
	file_notice('fleet-breakglass', $msg, 'Fleet', '', 2, true);
	return $record;
}

function fleet_breakglass_clear(): void {
	@unlink(FLEET_LOCK_BREAKGLASS);
	foreach ((array)get_notices() as $time => $n) {
		if (($n['id'] ?? '') === 'fleet-breakglass') {
			close_notice($time);
		}
	}
	logger(LOG_NOTICE, localize_text('Fleet: break-glass ended; policy Lock is active again.'));
}

/* ------------------------------------------------------------------ */
/* System access: commit-confirm                                       */
/* ------------------------------------------------------------------ */

function fleet_confirm_paths(string $id): array {
	return array('xml' => FLEET_CONFIRM_DIR . "/{$id}.xml", 'json' => FLEET_CONFIRM_DIR . "/{$id}.json");
}

function fleet_confirm_read(string $id): ?array {
	$r = fleet_confirm_valid_id($id) ? @json_decode((string)@file_get_contents(fleet_confirm_paths($id)['json']), true) : null;
	return is_array($r) ? $r : null;
}

function fleet_confirm_pending(): array {
	$out = array();
	foreach (glob(FLEET_CONFIRM_DIR . '/c-*.json') ?: array() as $file) {
		$r = fleet_confirm_read(basename($file, '.json'));
		if ($r !== null) {
			$out[] = $r;
		}
	}
	return $out;
}

/* Snapshot the configuration and start the watchdog. Returns the record. */
function fleet_confirm_begin(int $seconds, string $label, string $who): array {
	$id = 'c-' . bin2hex(random_bytes(8));
	$record = fleet_confirm_record($id, $seconds, $label, time(), $who);
	$paths = fleet_confirm_paths($id);
	if (!fleet_node_dir(FLEET_CONFIRM_DIR) || !@copy(g_get('conf_path') . '/config.xml', $paths['xml']) ||
	    !fleet_node_write($paths['json'], json_encode($record))) {
		@unlink($paths['xml']);
		throw new RuntimeException('The configuration snapshot could not be saved.');
	}
	@chmod($paths['xml'], 0600);
	mwexec_bg('/usr/local/bin/php /usr/local/sbin/freesense-fleet-watchdog ' . escapeshellarg($id));
	return $record;
}

/* The change worked: keep it. */
function fleet_confirm_accept(string $id): bool {
	if (fleet_confirm_read($id) === null) {
		return false;
	}
	$paths = fleet_confirm_paths($id);
	@unlink($paths['json']);
	@unlink($paths['xml']);
	return true;
}

/* Put the snapshot back (E310): the deadline passed or the controller asked. */
function fleet_confirm_rollback(string $id, string $why): bool {
	$record = fleet_confirm_read($id);
	$paths = fleet_confirm_paths($id);
	if (($record === null) || !is_file($paths['xml'])) {
		return false;
	}
	@unlink($paths['json']);
	fleet_lock_bypass(true);
	$ok = config_restore($paths['xml'], 'Fleet commit-confirm ' . $id);
	fleet_lock_bypass(false);
	@unlink($paths['xml']);
	$msg = "FLEET-E310: the change \"{$record['label']}\" was reverted ({$why}).";
	logger(LOG_WARNING, localize_text('%s', $msg));
	file_notice('fleet-confirm', $msg, 'Fleet', '', 1, true);
	if ($ok) {
		mwexec_bg('/etc/rc.reload_all');
	}
	return $ok;
}

/* The watchdog: wait for the deadline; revert unless confirmed. Runs in the background. */
function fleet_confirm_watch(string $id): int {
	while (true) {
		$record = fleet_confirm_read($id);
		if ($record === null) {
			return 0;
		}
		if (fleet_confirm_due($record, time())) {
			return fleet_confirm_rollback($id, 'not confirmed in time') ? 0 : 1;
		}
		sleep(1);
	}
}

/* At boot: a change nobody confirmed before the box went down is reverted. */
function fleet_confirm_boot(): void {
	foreach (fleet_confirm_pending() as $record) {
		if (fleet_confirm_due($record, time())) {
			fleet_confirm_rollback($record['id'], 'the firewall restarted before it was confirmed');
		} else {
			mwexec_bg('/usr/local/bin/php /usr/local/sbin/freesense-fleet-watchdog ' . escapeshellarg($record['id']));
		}
	}
}

/* ------------------------------------------------------------------ */
/* System access: activity and banner                                  */
/* ------------------------------------------------------------------ */

function fleet_activity_read(): ?array {
	$r = @json_decode((string)@file_get_contents(FLEET_ACTIVITY_FILE), true);
	return (is_array($r) && ((int)($r['until'] ?? 0) > time())) ? $r : null;
}

/* Print the banner on every WebUI page of a managed firewall. */
function fleet_banner_print(): void {
	$m = fleet_node_membership();
	if (!$m['enrolled']) {
		return;
	}
	$stored = fleet_policy_bundle_read();
	$locks = ($stored === null) ? 0 : count(array_filter((array)$stored['bundle']['rules'], function ($r) {
		return is_array($r) && (($r['mode'] ?? '') === 'lock');
	}));
	echo fleet_banner_html($m, fleet_activity_read(), fleet_breakglass_read(), $locks, time());
}
