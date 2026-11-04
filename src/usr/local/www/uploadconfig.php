#!/usr/local/bin/php
<?php
/*
 * uploadconfig.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
 * Copyright (c) 2025-2026 The FreeSense Project
 * All rights reserved.
 *
 * originally based on m0n0wall (http://m0n0.ch/wall)
 * Copyright (c) 2003-2004 Manuel Kasper <mk@neon1.net>.
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
##|*IDENT=page-hidden-uploadconfiguration
##|*NAME=Hidden: Upload Configuration
##|*DESCR=Allow access to the 'Hidden: Upload Configuration' page.
##|*WARN=standard-warning-root
##|*MATCH=uploadconfig.php*
##|-PRIV


require_once("guiconfig.inc");

header("Content-Type: text/plain");

/* config_install() replaces config.xml without the "Deny Config Write" check write_config() makes. */
$guiuser = getUserEntry($_SESSION['Username'])['item'] ?? null;
if (is_array($guiuser) && userHasPrivilege($guiuser, 'user-config-readonly')) {
	echo gettext("ERR Permission denied.")."\n";
	exit(0);
}

/* get config.xml in POST variable "config" */
if ($_POST['config']) {
	/* A root-only directory: a fixed name in /tmp could be a planted link. */
	$upload = tempnam(g_get('varrun_path'), 'uploadconfig.');
	if (($upload === false) || (@file_put_contents($upload, $_POST['config']) === false)) {
		echo gettext("ERR Could not save configuration.")."\n";
		exit(0);
	}
	$installed = config_install($upload);
	@unlink($upload);
	if ($installed == 0) {
		echo gettext("OK")."\n";
		system_reboot();
	} else {
		echo gettext("ERR Could not install configuration.")."\n";
	}
} else {
	echo gettext("ERR Invalid configuration received.")."\n";
}

exit(0);
?>
