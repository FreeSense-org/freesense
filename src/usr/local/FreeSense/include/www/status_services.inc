<?php
/*
 * status_services.inc
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2026 The FreeSense Project
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

require_once("service-utils.inc");

/*
 * The start, stop and restart buttons of Status > Services (and of the
 * service links elsewhere in the GUI, which post to that page): run the
 * action $mode ("startservice", "stopservice" or "restartservice") for the
 * service $service_name. $extras are the posted fields the service control
 * functions read (vpnmode, id and zone for OpenVPN instances and captive
 * portal zones). Returns the message of the service control function, or
 * null for an unknown action (nothing is done).
 */
function status_services_control($mode, $service_name, $extras) {
	$savemsg = null;
	switch ($mode) {
		case "restartservice":
			$savemsg = service_control_restart($service_name, $extras);
			break;
		case "startservice":
			$savemsg = service_control_start($service_name, $extras);
			break;
		case "stopservice":
			$savemsg = service_control_stop($service_name, $extras);
			break;
	}
	return $savemsg;
}
