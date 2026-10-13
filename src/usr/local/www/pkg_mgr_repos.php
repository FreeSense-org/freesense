<?php
/*
 * pkg_mgr_repos.php
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

##|+PRIV
##|*IDENT=page-system-packagemanager-repositories
##|*NAME=System: Package Manager: Repositories
##|*DESCR=Allow access to the 'System: Package Manager: Repositories' page.
##|*WARN=standard-warning-root
##|*MATCH=pkg_mgr_repos.php*
##|*MATCH=pkg_mgr_repos_edit.php*
##|-PRIV

require_once("guiconfig.inc");
require_once("pkg-utils.inc");
require_once("pkg_custom_repos.inc");

$savemsg = '';
$refresh_result = null;

if (!empty($_GET['saved'])) {
	$savemsg = gettext('The repository was saved. Use "Refresh" to fetch its package list.');
}

if (($_POST['act'] ?? '') === 'freebsd') {
	pkgrepo_set_freebsd(!pkgrepo_freebsd_enabled());
	header('Location: pkg_mgr_repos.php');
	exit;
} elseif (in_array($_POST['act'] ?? '', array('del', 'toggle', 'up', 'down', 'refresh'), true)) {
	$id = (string)($_POST['id'] ?? '');
	list(, $repo) = pkgrepo_find($id);
	if ($repo !== null) {
		switch ($_POST['act']) {
		case 'del':
			pkgrepo_delete($id);
			header('Location: pkg_mgr_repos.php');
			exit;
		case 'toggle':
			pkgrepo_toggle($id);
			header('Location: pkg_mgr_repos.php');
			exit;
		case 'up':
		case 'down':
			pkgrepo_move($id, $_POST['act']);
			header('Location: pkg_mgr_repos.php');
			exit;
		case 'refresh':
			list($ok, $message) = pkgrepo_refresh($repo['name']);
			$refresh_result = array($ok, $repo['name'], $message);
			break;
		}
	}
} elseif (($_POST['act'] ?? '') === 'refresh_freebsd' && pkgrepo_freebsd_enabled()) {
	list($ok, $message) = pkgrepo_refresh('FreeBSD-ports');
	$refresh_result = array($ok, 'FreeBSD-ports', $message);
}

$repos = pkgrepo_list();
$priorities = pkgrepo_priorities($repos);
$builtin = pkgrepo_builtin();
$freebsd = pkgrepo_freebsd_enabled();

/* the repository whose packages are shown */
$browse = (string)($_GET['browse'] ?? '');
$browse_packages = null;
$browse_total = 0;
$browse_query = (string)($_GET['q'] ?? '');
if ($browse !== '' && (in_array($browse, array_column($repos, 'name'), true) || ($browse === 'FreeBSD-ports' && $freebsd))) {
	list($browse_packages, $browse_total) = pkgrepo_catalog($browse, $browse_query);
} else {
	$browse = '';
}

$pgtitle = array(gettext('System'), gettext('Package Manager'), gettext('Repositories'));
$pglinks = array('', 'pkg_mgr_installed.php', '@self');
if (count($repos) < PKGREPO_MAX_CUSTOM) {
	fs_page_action(gettext('Add repository'), 'pkg_mgr_repos_edit.php', 'fa-plus');
}
include("head.inc");

fs_tabs('system-packages', 'pkg_mgr_repos.php');

if ($input_errors) {
	print_input_errors($input_errors);
}
if ($savemsg) {
	print_info_box($savemsg, 'success');
}
if (is_array($refresh_result)) {
	print_info_box(sprintf(gettext('%1$s: %2$s'), htmlspecialchars($refresh_result[1]), htmlspecialchars($refresh_result[2] !== '' ? $refresh_result[2] :
	    ($refresh_result[0] ? gettext('Package list updated.') : gettext('Could not fetch the package list.')))),
	    $refresh_result[0] ? 'success' : 'danger');
}
?>
<div class="alert alert-warning" role="alert">
	<strong><?=gettext('Custom repositories are third-party software sources.')?></strong>
	<?=gettext('Their packages are installed as root and are not tested with FreeSense. The FreeSense repositories always take priority: a custom repository can add packages, but it can never replace one FreeSense provides, and the system updater never reads it. Your repositories and installed packages are kept when you update the firmware, but an update can change system libraries, so a package from a custom repository may need to be reinstalled afterwards. FreeSense never upgrades them for you.')?>
</div>

<div class="panel panel-default fs-table" id="repo-list">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('Repositories')?></h2></div>
	<div class="panel-body table-responsive">
		<table class="table table-hover">
			<thead><tr>
				<th><?=gettext('Repository')?></th>
				<th><?=gettext('Address')?></th>
				<th><?=gettext('Verification')?></th>
				<th><?=gettext('Priority')?></th>
				<th><?=gettext('Status')?></th>
				<th class="fs-col-actions"><span class="visually-hidden"><?=gettext('Actions')?></span></th>
			</tr></thead>
			<tbody>
<?php foreach ($builtin as $b): ?>
				<tr>
					<td><strong><?=htmlspecialchars($b['name'])?></strong>
						<div class="fs-muted small"><?=htmlspecialchars($b['descr'])?></div></td>
					<td class="fs-mono small text-break"><?=htmlspecialchars($b['url'])?></td>
					<td><?=fs_badge('pass', gettext('Signed'))?></td>
					<td class="fs-mono"><?=(int)$b['priority']?></td>
					<td><i class="fa-solid fa-lock" aria-hidden="true"></i> <?=gettext('Built in')?></td>
					<td class="fs-col-actions"></td>
				</tr>
<?php endforeach; ?>
				<tr<?=$freebsd ? '' : ' class="fs-row-disabled"'?>>
					<td><strong>FreeBSD-ports</strong>
						<div class="fs-muted small"><?=gettext('FreeBSD optional packages (rolling “latest” branch)')?></div></td>
					<td class="fs-mono small">pkg.FreeBSD.org</td>
					<td><?=fs_badge('pass', gettext('Signed'))?></td>
					<td class="fs-mono"><?=PKGREPO_PRIORITY_FREEBSD?></td>
					<td><?=$freebsd ? fs_badge('pass', gettext('Enabled')) : fs_badge('disabled')?></td>
					<td class="fs-col-actions">
						<?=fs_row_actions(array_values(array_filter(array(
							$freebsd ? array('custom', 'pkg_mgr_repos.php?act=refresh_freebsd', 'FreeBSD-ports', array('icon' => 'fa-rotate', 'label' => gettext('Refresh the FreeBSD package list'), 'post' => true)) : null,
							$freebsd ? array('custom', 'pkg_mgr_repos.php?browse=FreeBSD-ports', 'FreeBSD-ports', array('icon' => 'fa-box-open', 'label' => gettext('Browse the FreeBSD packages'))) : null,
							array('toggle', 'pkg_mgr_repos.php?act=freebsd', 'FreeBSD-ports', array('enabled' => $freebsd)),
						))))?>
					</td>
				</tr>
<?php foreach ($repos as $i => $r):
	$url = 'pkg_mgr_repos.php?id=' . urlencode($r['id']) . '&act=';
	$signed = ($r['signature_type'] !== 'none');
?>
				<tr<?=empty($r['enable']) ? ' class="fs-row-disabled"' : ''?>>
					<td><a href="pkg_mgr_repos_edit.php?id=<?=htmlspecialchars(urlencode($r['id']))?>"><strong><?=htmlspecialchars($r['name'])?></strong></a>
						<div class="fs-muted small"><?=htmlspecialchars($r['descr'] ?? '')?></div></td>
					<td class="fs-mono small text-break"><?=htmlspecialchars($r['url'])?></td>
					<td><?=$signed ? fs_badge('pass', $r['signature_type'] === 'pubkey' ? gettext('Public key') : gettext('Fingerprint')) : fs_badge('warn', gettext('Unsigned'))?></td>
					<td class="fs-mono"><?=(int)$priorities[$r['name']]?></td>
					<td><?=empty($r['enable']) ? fs_badge('disabled') : fs_badge('pass', gettext('Enabled'))?></td>
					<td class="fs-col-actions">
						<?=fs_row_actions(array_values(array_filter(array(
							!empty($r['enable']) ? array('custom', $url . 'refresh', $r['name'], array('icon' => 'fa-rotate', 'label' => sprintf(gettext('Refresh the package list of %s'), $r['name']), 'post' => true)) : null,
							!empty($r['enable']) ? array('custom', 'pkg_mgr_repos.php?browse=' . urlencode($r['name']), $r['name'], array('icon' => 'fa-box-open', 'label' => sprintf(gettext('Browse the packages of %s'), $r['name']))) : null,
							$i > 0 ? array('custom', $url . 'up', $r['name'], array('icon' => 'fa-arrow-up', 'label' => sprintf(gettext('Move %s up (higher priority)'), $r['name']), 'post' => true)) : null,
							$i < count($repos) - 1 ? array('custom', $url . 'down', $r['name'], array('icon' => 'fa-arrow-down', 'label' => sprintf(gettext('Move %s down (lower priority)'), $r['name']), 'post' => true)) : null,
							array('edit', 'pkg_mgr_repos_edit.php?id=' . urlencode($r['id']), $r['name']),
							array('toggle', $url . 'toggle', $r['name'], array('enabled' => !empty($r['enable']))),
							array('delete', $url . 'del', $r['name'], array('thing' => gettext('repository'), 'detail' => gettext('Packages already installed from it stay installed.'))),
						))))?>
					</td>
				</tr>
<?php endforeach; ?>
<?php if (empty($repos)): ?>
				<tr class="fs-empty"><td colspan="6"><span class="fs-empty-message"><?=gettext('No custom repositories yet.')?></span>
					<a class="btn btn-sm btn-outline-secondary" href="pkg_mgr_repos_edit.php"><?=gettext('Add repository')?></a></td></tr>
<?php endif; ?>
			</tbody>
		</table>
	</div>
	<div class="panel-footer small fs-muted">
		<i class="fa-solid fa-scale-balanced" aria-hidden="true"></i>
		<?=gettext('When two repositories offer the same package, the one with the higher priority wins, whatever the versions are. FreeSense repositories use 10-100; custom repositories use 1-5 and the FreeBSD repository 0.')?>
	</div>
</div>
<?php
if (is_array($browse_packages)):
?>
<div class="panel panel-default fs-table" id="browse-list">
<div class="panel-heading d-flex flex-wrap align-items-center gap-2">
	<h2 class="panel-title me-auto"><?=sprintf(gettext('Packages in %s'), htmlspecialchars($browse))?></h2>
	<form method="get" action="pkg_mgr_repos.php" class="d-flex gap-2">
		<input type="hidden" name="browse" value="<?=htmlspecialchars($browse)?>" />
		<input type="search" class="form-control form-control-sm" name="q" value="<?=htmlspecialchars($browse_query)?>" placeholder="<?=gettext('Search package names…')?>" maxlength="64" aria-label="<?=gettext('Search package names')?>" />
		<button type="submit" class="btn btn-sm btn-primary"><?=gettext('Search')?></button>
	</form>
</div>
<?php if ($browse_total > count($browse_packages)): ?>
<div class="px-3 pt-2 small fs-muted"><?=htmlspecialchars(sprintf(gettext('Showing %1$d of %2$d packages. Search to narrow the list.'), (int)count($browse_packages), (int)$browse_total))?></div>
<?php endif; ?>
	<div class="panel-body table-responsive">
		<table class="table table-hover">
			<thead><tr>
				<th data-fs-search><?=gettext('Package')?></th>
				<th><?=gettext('Version')?></th>
				<th data-fs-search><?=gettext('Description')?></th>
				<th class="fs-col-actions"><span class="visually-hidden"><?=gettext('Actions')?></span></th>
			</tr></thead>
			<tbody>
<?php foreach ($browse_packages as $p): ?>
				<tr>
					<td class="fs-mono"><strong><?=htmlspecialchars($p[0])?></strong></td>
					<td class="fs-mono text-nowrap"><?=htmlspecialchars($p[1])?></td>
					<td><?=htmlspecialchars($p[2])?></td>
					<td class="fs-col-actions">
						<?=fs_row_actions(array( // nosemgrep: php.lang.security.injection.printed-request.printed-request (fs_row_actions escapes every attribute and label)
							array('custom', 'pkg_mgr_install.php?mode=installcustom&pkg=' . urlencode($p[0]), $p[0], array('icon' => 'fa-download', 'label' => sprintf(gettext('Review and install %s'), $p[0]))),
						))?>
					</td>
				</tr>
<?php endforeach; ?>
<?php if (empty($browse_packages)): ?>
				<tr class="fs-empty"><td colspan="4"><span class="fs-empty-message"><?=gettext('No packages listed. Refresh the repository first.')?></span></td></tr>
<?php endif; ?>
			</tbody>
		</table>
	</div>
</div>
<?php
endif;

$installed = pkgrepo_installed_foreign();
if (!empty($installed)):
?>
<div class="panel panel-default fs-table" id="installed-list">
	<div class="panel-heading"><h2 class="panel-title"><?=gettext('Installed from these repositories')?></h2></div>
	<div class="panel-body table-responsive">
		<table class="table table-hover">
			<thead><tr>
				<th><?=gettext('Package')?></th>
				<th><?=gettext('Version')?></th>
				<th><?=gettext('Repository')?></th>
				<th class="fs-col-actions"><span class="visually-hidden"><?=gettext('Actions')?></span></th>
			</tr></thead>
			<tbody>
<?php foreach ($installed as $p): ?>
				<tr>
					<td class="fs-mono"><strong><?=htmlspecialchars($p[0])?></strong></td>
					<td class="fs-mono text-nowrap"><?=htmlspecialchars($p[1])?></td>
					<td><?=htmlspecialchars($p[2])?></td>
					<td class="fs-col-actions">
						<?=fs_row_actions(array(
							array('custom', 'pkg_mgr_install.php?mode=removecustom&pkg=' . urlencode($p[0]), $p[0], array('icon' => 'fa-trash-can', 'label' => sprintf(gettext('Remove %s'), $p[0]))),
						))?>
					</td>
				</tr>
<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</div>
<?php
endif;

include("foot.inc");
