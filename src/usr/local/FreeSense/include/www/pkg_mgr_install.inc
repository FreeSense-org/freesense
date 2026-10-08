<?php
/*
 * pkg_mgr_install.inc
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
 * Copyright (c) 2005 Colin Smith
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
 * The Package Installer / System Update page (pkg_mgr_install.php): start a
 * package installation, reinstallation or removal or the system update
 * (FreeSense-upgrade in the background) and read its progress.
 */

require_once("util.inc");
require_once("config.inc");
require_once("pkg-utils.inc");

/* The files the page uses to follow the FreeSense-upgrade process it started. */
function pkg_mgr_install_paths() {
	return array(
		'gui_pidfile' => g_get('varrun_path') . '/' . g_get('product_name') . '-upgrade-GUI.pid',
		'gui_mode' => g_get('varrun_path') . '/' . g_get('product_name') . '-upgrade-GUI.mode',
		'sock_file' => g_get('tmp_path') . '/' . g_get('product_name') . '-upgrade.sock',
		'upgrade' => '/usr/local/sbin/' . g_get('product_name') . '-upgrade',
	);
}

/*
 * The package name is also used to build log file paths; accept package-name
 * characters only so it cannot point outside the log directory.
 */
function pkg_mgr_install_name_ok($pkgname) {
	return (is_string($pkgname) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._+-]*$/', $pkgname));
}

/* The log file (without .txt/.json) of an operation. */
function pkg_mgr_install_logfile($firmwareupdate, $pkgname) {
	if ($firmwareupdate) {
		return g_get('cf_conf_path') . '/upgrade_log';
	}
	return g_get('cf_conf_path') . '/pkg_log_' . $pkgname;
}

/* The log file (without .txt/.json) for the poll's logfilename ("UPGR" or "PKG"); "" otherwise. */
function pkg_mgr_install_postlog($logfilename, $pkgname) {
	$postlog = "";
	if ($logfilename == "UPGR") {
		$postlog = pkg_mgr_install_logfile(true, $pkgname);
	}
	if ($logfilename == "PKG") {
		$postlog = pkg_mgr_install_logfile(false, $pkgname);
	}
	return $postlog;
}

/*
 * The operation recorded in the mode file: array(mode, package). The mode is
 * installpkg, reinstallpkg, delete, reinstallall or firmwareupdate (the page
 * assumes firmwareupdate without the file). The file holds one item per line;
 * older versions wrote the items without a separator ("installpkgFreeSense-pkg-x").
 */
function pkg_mgr_install_read_mode($gui_mode) {
	if (!file_exists($gui_mode)) {
		return array(null, null);
	}
	$lines = @file($gui_mode, FILE_IGNORE_NEW_LINES);
	if (!is_array($lines) || !isset($lines[0])) {
		return array(null, null);
	}
	$mode = $lines[0];
	$pkgname = $lines[1] ?? null;
	if ($pkgname === null) {
		foreach (array('installpkg', 'reinstallpkg', 'delete') as $known) {
			if ((strpos($mode, $known) === 0) && (strlen($mode) > strlen($known))) {
				$pkgname = substr($mode, strlen($known));
				$mode = $known;
				break;
			}
		}
	}
	if (($pkgname !== null) && !pkg_mgr_install_name_ok($pkgname)) {
		$pkgname = null;
	}
	return array($mode, $pkgname);
}

/*
 * Whether the process the page started is still running. When we do a
 * reinstallall, it is technically possible that we might catch the system
 * in-between packages, hence the de-bounce here.
 */
function pkg_mgr_install_running($gui_pidfile) {
	for ($idx=0;$idx<5 && !isvalidpid($gui_pidfile); $idx++) {
		usleep(200000);
	}
	return isvalidpid($gui_pidfile);
}

function waitfor_string_in_file($filename, $string, $timeout) {
	$start = $now = time();

	while (($now - $start) < $timeout) {
		$testfile = @fopen($filename, "r");

		if ($testfile != FALSE) {
			while (($line = fgets($testfile)) !== false) {
				if (strpos($line, $string) !== false) {
					fclose($testfile);
					return(true);
				}
			}

			fclose($testfile);
		}

	usleep(100000);
	$now = time();
	}

	return(false);
}

/*
 * Read the log of an operation ($postlog without .txt). Returns null while
 * there is no log, otherwise array('log' => text, 'status' => array) where
 * the return code line ("__RC=n [__REBOOT_AFTER=...]") is replaced by
 * "Success" or "Failed" and status has 'exitstatus' and 'reboot_needed'
 * ("yes"/"no") once the line is there. Log lines are HTML-escaped for the
 * page unless $html is false.
 */
function pkg_mgr_install_read_log($postlog, $html = true) {
	$logfile = @fopen($postlog . '.txt', "r");

	if ($logfile == FALSE) {
		return null;
	}

	$response = "";
	$statusarray = array();
	$code = array();

	// Log file is read a line at a time so that we can detect/modify certain entries
	while (($logline = fgets($logfile)) !== false) {
		// Check for return codes and replace with suitable strings
		$rc_pos = strpos($logline, "__RC=");
		if ($rc_pos !== false) {
			$rc_string = substr($logline, $rc_pos);
			$code = explode(" ", $rc_string);

			$rc = str_replace("__RC=", "", $code[0]);

			if (count($code) > 1 &&
			    strpos($code[1], "REBOOT_AFTER") !== false) {
				$statusarray['reboot_needed'] = "yes";
			} else {
				$statusarray['reboot_needed'] = "no";
			}

			if ($rc == 0) {
				$logline = gettext("Success") . "\n";
			} else {
				$logline = gettext("Failed") . "\n";
			}

			$response .= $logline;
			$statusarray['exitstatus'] = $rc;
		} else {
			$response .= $html ? htmlspecialchars($logline) : $logline;
		}
	}

	fclose($logfile);
	return array('log' => $response, 'status' => $statusarray);
}

/* The newest progress record of an operation (array with 'data' => {current, total}), or an empty array. */
function pkg_mgr_install_read_progress($postlog) {
	$progress = "";
	$progarray = array();

	$JSONfile = @fopen($postlog . '.json', "r");

	if ($JSONfile != FALSE) {
		while (($logline = fgets($JSONfile)) !== false) {
			if (!feof($JSONfile) && (strpos($logline, 'INFO_PROGRESS_TICK') !== false)) {
				if (strpos($logline, '}}') !== false) {
					$progress = $logline;
				}
			}
		}

		fclose($JSONfile);

		if (strlen($progress) > 0) {
			$progarray = json_decode($progress, true);
		}
	}

	return is_array($progarray) ? $progarray : array();
}

/* A notice the package installer left for the user interface ("" when none). */
function pkg_mgr_install_ui_notice() {
	$ui_notice = "/tmp/package_ui_notice";

	if (file_exists($ui_notice)) {
		return file_get_contents($ui_notice);
	}
	return "";
}

/*
 * What the page does when the process it polls has stopped (on every poll
 * after that): wait for the return code in the log, reload the filter and
 * restart the package services.
 */
function pkg_mgr_install_finish($postlog) {
	// The log files may not be complete when the process terminates so we need wait until we see the
	// exit status (__RC=x)
	waitfor_string_in_file($postlog . '.txt', "__RC=", 10);
	filter_configure();
	send_event("service restart packages");
}

/*
 * Start an operation like the page's confirmation does: write a restore
 * point, then run FreeSense-upgrade in the background (retried while another
 * instance holds its lock) and record its pid and mode for the polls.
 *   $pkgmode         installed (install), reinstallpkg, delete, reinstallall
 *   $pkgname         the package (validated by the caller)
 *   $firmwareupdate  true for the system update ($pkgmode is then ignored
 *                    unless it is delete, reinstallpkg or reinstallall)
 *   $fwbranch        a repository name to switch to first (system update only;
 *                    the page no longer posts it)
 * Returns array('started' => bool, 'reason' => why nothing was started
 * ('' when started; 'vital': a vital package's removal; 'nothing': a
 * reinstallation of all packages without packages; 'another_instance':
 * another FreeSense-upgrade kept its lock through 30 attempts; 'failed': it
 * ended at once, its log is in failmsg), 'failmsg' => string (lines
 * separated by "\n"), 'progbar' => bool, 'logfilename' => string,
 * 'pid' => int|null).
 */
function pkg_mgr_install_start($pkgmode, $pkgname, $firmwareupdate, $fwbranch = '', $repos = array()) {
	$paths = pkg_mgr_install_paths();
	$gui_pidfile = $paths['gui_pidfile'];
	$gui_mode = $paths['gui_mode'];
	$sock_file = $paths['sock_file'];
	$freesense_upgrade = $paths['upgrade'];
	$logfilename = pkg_mgr_install_logfile($firmwareupdate, $pkgname);
	$pkgname_vital = is_vital_system_default_package((string)$pkgname);
	$pkgname_vital_message = gettext("This package is vital to system operation and cannot be removed.");
	$failmsg = "";
	$start_polling = false;
	$execpid = null;

	/* Write out configuration to create a backup prior to pkg install. */
	if ($firmwareupdate) {
		// The branch is chosen on Update Settings, so system/pkg_repo_conf_path is
		// already set — no fwbranch selector to honour here anymore. If a fwbranch
		// value is somehow still posted, respect it for backwards compatibility.
		if (!empty($fwbranch)) {
			foreach ($repos as $repo) {
				if ($repo['name'] == $fwbranch) {
					config_set_path('system/pkg_repo_conf_path', $repo['name']);
					break;
				}
			}
		}
		write_config(gettext("Creating restore point before upgrade."));
	} else {
		write_config(gettext("Creating restore point before package installation."));
	}

	$progbar = true;

	// Remove the log file before starting
	unlink_if_exists($logfilename . ".txt");

	unset($params);
	$mode = array();
	switch ($pkgmode) {
		case 'delete':
			$params = "-r {$pkgname}";
			$mode[] = "delete";
			$mode[] = $pkgname;
			break;

		case 'reinstallall':
			if (is_array(config_get_path('installedpackages/package'))) {
				/*
				 * We don't show the progress bar for
				 * reinstallall. It would be far too confusing
				 */
				$progbar = false;
				$params = "-i ALL_PACKAGES -f";
				$mode[] = "reinstallall";
			}

			break;
		case 'reinstallpkg':
			$params =  "-i {$pkgname} -f";
			$mode[] = "reinstallpkg";
			$mode[] = $pkgname;
			break;

		case 'installed':
		default:	// Updating system
			if ($firmwareupdate) {
				$params = "";
				$mode[] = "firmwareupdate";
			} else {
				$params = "-i {$pkgname}";
				$mode[] = "installpkg";
				$mode[] = $pkgname;
			}
			break;
	}

	if (($pkgmode == 'delete') && $pkgname_vital) {
		$failmsg = $pkgname_vital_message;
	} elseif (isset($params)) {
		$another_instance = true;
		$log = array();
		$upgrade_script = "{$freesense_upgrade} -y -l {$logfilename}.txt -p {$sock_file}";

		for ($idx = 0; $idx < 30; $idx++) {
			unlink_if_exists($sock_file);
			$execpid = mwexec_bg("{$upgrade_script} {$params}");

			// Make sure the upgrade process starts
			while (posix_kill($execpid, 0) && !file_exists($sock_file)) {
				usleep(100000);
			}

			// Collect log output earlier
			if (!file_exists($logfilename . '.txt')) {
				touch($logfilename . '.txt');
			}
			$log = file($logfilename . '.txt',
			    FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

			/*
			 * If it is not running anymore, read return code from
			 * logfile and decide what to do next
			 */
			if (!posix_kill($execpid, 0)) {
				$last = preg_match('/^__RC=(\d+)/', end($log),
				    $matches);

				if (!empty($matches[1])) {
					/*
					 * return code 75 means another process
					 * is running.  In this case we try
					 * again
					 */
					if ((int)$matches[1] != 75) {
						$another_instance = false;
						break;
					}
				}
			} elseif (file_exists($sock_file)) {
				$another_instance = false;
				$start_polling = true;
				@file_put_contents($gui_pidfile, $execpid);
				/* One item per line: the page reads the mode and the package back with file(). */
				@file_put_contents($gui_mode, implode("\n", $mode));
				break;
			}
		}

		/*
		 * If FreeSense-upgrade failed to run, present log to user
		 */
		if ($another_instance) {
			$failmsg = gettext(sprintf("Another instance of %s " .
			    "is running.  Try again later",
			    g_get('product_name') . "-upgrade"));
		} elseif (!$start_polling) {
			/* Remove last line, used to provide return code */
			unset($log[count($log)-1]);
			$failmsg = implode("\n", $log);
		}
	}

	if ($start_polling) {
		$reason = '';
	} elseif (($pkgmode == 'delete') && $pkgname_vital) {
		$reason = 'vital';
	} elseif (!isset($params)) {
		$reason = 'nothing';
	} else {
		$reason = $another_instance ? 'another_instance' : 'failed';
	}

	return array('started' => $start_polling, 'reason' => $reason, 'failmsg' => $failmsg, 'progbar' => $progbar,
	    'logfilename' => $logfilename, 'pid' => $start_polling ? (int)$execpid : null);
}
