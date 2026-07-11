<?php
##|+PRIV
##|*IDENT=page-system-packagemanager-installed
##|*NAME=System: Package Manager: Installed
##|*DESCR=Allow access to installed package controls.
##|*MATCH=pkg_control.php*
##|-PRIV

require_once('guiconfig.inc');
require_once('pkg-utils.inc');
require_once('package_catalog.inc');
require_once('service-utils.inc');

$shortname = (string)($_REQUEST['pkg'] ?? '');
if (!preg_match('/^[A-Za-z0-9_.+-]{1,96}$/D', $shortname)) {
	header('Location: /pkg_mgr_installed.php');
	exit;
}

$full_name = g_get('pkg_prefix') . $shortname;
$packages = get_pkg_info([$full_name], true, true);
if (empty($packages)) {
	header('Location: /pkg_mgr_installed.php');
	exit;
}
$package = reset($packages);
$meta = freesense_package_catalog_entry($shortname);
$input_errors = [];
$savemsg = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['service'], $_POST['action'])) {
	$service = (string)$_POST['service'];
	$action = (string)$_POST['action'];
	if (!in_array($service, $meta['services'], true) || !in_array($action, ['start', 'stop', 'restart'], true)) {
		$input_errors[] = gettext('Invalid package service action.');
	} else {
		$extras = ['notifies' => false];
		switch ($action) {
			case 'start': $result = service_control_start($service, $extras); break;
			case 'stop': $result = service_control_stop($service, $extras); break;
			default: $result = service_control_restart($service, $extras); break;
		}
		$savemsg = strip_tags((string)$result);
	}
}

$pgtitle = [gettext('System'), gettext('Package Manager'), $meta['display_name']];
$pglinks = ['', '/pkg_mgr_installed.php', '@self'];
include('head.inc');
if ($input_errors) print_input_errors($input_errors);
if ($savemsg) print_info_box(htmlspecialchars($savemsg), 'info');
?>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
	<div><h1 class="h3 mb-1"><?=htmlspecialchars($meta['display_name'])?></h1><div class="text-body-secondary"><?=htmlspecialchars($package['desc'])?></div></div>
	<?php if (!empty($meta['configure_path'])): ?><a class="btn btn-primary" href="<?=htmlspecialchars($meta['configure_path'])?>"><i class="fa-solid fa-sliders me-1"></i><?=gettext('Open configuration')?></a><?php endif; ?>
</div>
<div class="row g-3 mb-3">
	<div class="col-md-4"><div class="card h-100"><div class="card-body"><div class="text-body-secondary"><?=gettext('Integration version')?></div><div class="fs-4 fw-semibold"><?=htmlspecialchars($package['installed_version'] ?? $package['version'] ?? gettext('Unknown'))?></div></div></div></div>
	<div class="col-md-4"><div class="card h-100"><div class="card-body"><div class="text-body-secondary"><?=gettext('Category')?></div><div class="fs-4 fw-semibold"><?=htmlspecialchars($meta['category'])?></div><span class="badge text-bg-secondary"><?=htmlspecialchars(ucfirst($meta['resource_profile']))?></span></div></div></div>
	<div class="col-md-4"><div class="card h-100"><div class="card-body"><div class="text-body-secondary"><?=gettext('Support')?></div><div class="fs-4 fw-semibold"><?=htmlspecialchars(ucfirst($meta['support']))?></div><div class="small"><?=gettext('Last tested')?>: <?=htmlspecialchars($meta['last_tested_release'])?></div></div></div></div>
</div>
<div class="card mb-3"><div class="card-header"><h2 class="h5 mb-0"><?=gettext('Security capabilities')?></h2></div><div class="card-body"><?php if ($meta['capabilities']): foreach ($meta['capabilities'] as $capability): ?><span class="badge text-bg-warning me-1 mb-1"><?=htmlspecialchars(str_replace('-', ' ', $capability))?></span><?php endforeach; else: ?><span class="text-body-secondary"><?=gettext('No elevated capabilities declared.')?></span><?php endif; ?></div></div>
<div class="card mb-3"><div class="card-header"><h2 class="h5 mb-0"><?=gettext('Services')?></h2></div><div class="card-body table-responsive"><?php if ($meta['services']): ?><table class="table table-hover align-middle"><thead><tr><th><?=gettext('Service')?></th><th><?=gettext('Status')?></th><th><?=gettext('Actions')?></th></tr></thead><tbody><?php foreach ($meta['services'] as $service): $running=is_service_running($service); ?><tr><td><code><?=htmlspecialchars($service)?></code></td><td><span class="badge <?=$running?'text-bg-success':'text-bg-secondary'?>"><?=$running?gettext('Running'):gettext('Stopped')?></span></td><td><form method="post" class="btn-group"><input type="hidden" name="service" value="<?=htmlspecialchars($service)?>"><?php if (!$running): ?><button class="btn btn-success btn-sm" name="action" value="start"><?=gettext('Start')?></button><?php else: ?><button class="btn btn-secondary btn-sm" name="action" value="restart"><?=gettext('Restart')?></button><button class="btn btn-danger btn-sm" name="action" value="stop"><?=gettext('Stop')?></button><?php endif; ?></form></td></tr><?php endforeach; ?></tbody></table><?php else: ?><span class="text-body-secondary"><?=gettext('This integration does not own a background service.')?></span><?php endif; ?></div></div>
<div class="card"><div class="card-header"><h2 class="h5 mb-0"><?=gettext('Runtime dependencies')?></h2></div><div class="card-body"><?php if (!empty($package['deps'])): ?><div class="d-flex flex-wrap gap-2"><?php foreach ($package['deps'] as $dependency): ?><span class="badge text-bg-light border text-dark"><?=htmlspecialchars(basename($dependency['origin']) . ' ' . $dependency['version'])?></span><?php endforeach; ?></div><?php else: ?><span class="text-body-secondary"><?=gettext('No external runtime dependencies.')?></span><?php endif; ?></div></div>
<?php include('foot.inc'); ?>
