<?php
/* FreeSense ZFS Storage dashboard widget — SPDX-License-Identifier: Apache-2.0 */
require_once('guiconfig.inc');
require_once('notices.inc');

if ($_POST['widgetkey'] || $_GET['widgetkey']) {
	$rwidgetkey = $_POST['widgetkey'] ?? $_GET['widgetkey'];
	if (!is_valid_widgetkey($rwidgetkey, $user_settings, __FILE__)) {
		print gettext('Invalid Widget Key');
		exit;
	}
	$widgetkey = $rwidgetkey;
}

$defaults = ['refreshinterval' => 30, 'compact' => false];
$widget_config = array_replace($defaults, (array)($user_settings['widgets'][$widgetkey] ?? []));

if (isset($_POST['save'])) {
	$interval = (int)($_POST['refreshinterval'] ?? 30);
	if (!in_array($interval, [10, 30, 60, 300], true)) $interval = 30;
	$user_settings['widgets'][$widgetkey] = ['refreshinterval' => $interval, 'compact' => isset($_POST['compact'])];
	save_widget_settings($_SESSION['Username'], $user_settings['widgets'], gettext('Updated ZFS Storage widget settings.'));
	header('Location: /');
	exit;
}

function zfs_widget_status(): array {
	$cache = '/tmp/freesense-zfs-status.json';
	if (!is_file($cache) || filemtime($cache) < time() - 8) {
		$output = [];
		exec('/usr/local/sbin/freesense-zfs-status 2>/dev/null', $output, $rc);
		if ($rc === 0) @file_put_contents($cache, implode("\n", $output), LOCK_EX);
	}
	$data = json_decode(@file_get_contents($cache), true);
	return is_array($data) ? $data : ['zfs_active' => false, 'pools' => []];
}

function zfs_widget_notice(string $id, ?string $message): void {
	$existingMessage = null;
	foreach ((array)get_notices() as $notice) {
		if (html_entity_decode($notice['id'] ?? '') === $id) {
			$existingMessage = html_entity_decode($notice['notice'] ?? '');
			break;
		}
	}
	if ($message === null) {
		if ($existingMessage !== null) close_notice($id);
		return;
	}
	if ($existingMessage === $message) return;
	if ($existingMessage !== null) close_notice($id);
	file_notice($id, $message, gettext('ZFS Storage'), '/system_boot_environments.php', 1, true);
}

function zfs_widget_render(array $data, bool $compact): string {
	ob_start();
	if (!$data['zfs_active']): ?>
		<div class="alert alert-info"><?=gettext('ZFS is not active on this system.')?></div>
	<?php else:
		foreach ($data['pools'] as $pool):
			$health = strtoupper($pool['health']);
			$healthy = $health === 'ONLINE';
			$warning = in_array($health, ['DEGRADED', 'OFFLINE', 'REMOVED'], true);
			$class = $healthy ? 'success' : ($warning ? 'warning' : 'danger');
			$errorCount = 0;
			foreach ($pool['devices'] as $device) $errorCount += $device['read_errors'] + $device['write_errors'] + $device['checksum_errors'];
			if (!$healthy || $errorCount > 0 || ($pool['errors'] && stripos($pool['errors'], 'No known data errors') === false)) {
				zfs_widget_notice('zfs-' . $pool['name'], sprintf(gettext('ZFS pool %s requires attention: health %s, device errors %d.'), $pool['name'], $health, $errorCount));
			} else {
				zfs_widget_notice('zfs-' . $pool['name'], null);
			}
		?>
		<div class="panel panel-<?=$class?>">
			<div class="panel-heading"><strong><?=htmlspecialchars($pool['name'])?></strong><span class="pull-right"><?=htmlspecialchars($health)?></span></div>
			<div class="panel-body">
				<div class="progress"><div class="progress-bar progress-bar-<?=$class?>" style="width:<?=min(100, (float)$pool['capacity_percent'])?>%"></div></div>
				<div><?=sprintf(gettext('%1$s%% used — %2$s free — %3$s%% fragmented'), htmlspecialchars((string)$pool['capacity_percent']), htmlspecialchars(format_bytes($pool['free'])), htmlspecialchars((string)$pool['fragmentation']))?></div>
				<?php if ($pool['scan']): ?><small><?=htmlspecialchars($pool['scan'])?></small><?php endif; ?>
				<?php if (!$compact): ?><table class="table table-condensed table-sm"><thead><tr><th><?=gettext('Device')?></th><th><?=gettext('State')?></th><th>R/W/C</th></tr></thead><tbody>
				<?php foreach ($pool['devices'] as $device): ?><tr><td><?=htmlspecialchars($device['name'])?></td><td><?=htmlspecialchars($device['state'])?></td><td><?=intval($device['read_errors'])?>/<?=intval($device['write_errors'])?>/<?=intval($device['checksum_errors'])?></td></tr><?php endforeach; ?>
				</tbody></table><?php endif; ?>
			</div>
		</div>
		<?php endforeach;
		$be = $data['boot_environment'] ?? [];
		if ($be['supported'] ?? false): ?>
		<div><i class="fa-solid fa-box-archive"></i> <?=sprintf(gettext('Boot environment: %1$s (%2$d total)%3$s'), htmlspecialchars($be['current'] ?? '-'), intval($be['count']), !empty($be['pending']) ? ' — ' . gettext('verification pending') : '')?> — <a href="/system_boot_environments.php"><?=gettext('Manage')?></a></div>
		<?php endif;
	endif;
	return ob_get_clean();
}

$data = zfs_widget_status();
if (isset($_POST['ajax'])) {
	echo zfs_widget_render($data, (bool)$widget_config['compact']);
	exit;
}
?>
<div id="<?=htmlspecialchars($widgetkey)?>-body"><?=zfs_widget_render($data, (bool)$widget_config['compact'])?></div>
</div>
<div id="widget-<?=htmlspecialchars($widgetkey)?>_panel-footer" class="panel-footer collapse">
	<form method="post" action="/widgets/widgets/zfs_storage.widget.php" class="form-horizontal">
		<input type="hidden" name="widgetkey" value="<?=htmlspecialchars($widgetkey)?>"><input type="hidden" name="save" value="1">
		<div class="form-group"><label class="col-sm-5 control-label"><?=gettext('Refresh interval')?></label><div class="col-sm-7"><select class="form-control" name="refreshinterval"><?php foreach([10,30,60,300] as $value): ?><option value="<?=$value?>" <?=$widget_config['refreshinterval']===$value?'selected':''?>><?=$value?> <?=gettext('seconds')?></option><?php endforeach; ?></select></div></div>
		<div class="checkbox"><label><input type="checkbox" name="compact" <?=$widget_config['compact']?'checked':''?>> <?=gettext('Compact view')?></label></div>
		<button class="btn btn-primary" type="submit"><i class="fa-solid fa-save icon-embed-btn"></i><?=gettext('Save')?></button>
	</form>
</div>
<script>
events.push(function() {
	var obj = {name: 'zfs_storage', url: '/widgets/widgets/zfs_storage.widget.php', parms: {ajax: '1', widgetkey: <?=json_encode($widgetkey)?>}, freq: <?=intval($widget_config['refreshinterval'])?>};
	obj.callback = function(data) { $('#<?=htmlspecialchars($widgetkey)?>-body').html(data); };
	register_ajax(obj);
});
</script>
