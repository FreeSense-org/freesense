<?php
/*
 * copynotice.inc
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
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
##|*IDENT=page-system-copyright
##|*NAME=System: Copyright notice
##|*DESCR=Copyright and usage notice.
##|*MATCH=copynotice.inc*
##|-PRIV

/*
 * This file displays the copyright modal when required. (New version installed or completion of the setup wizard)
 * The copyright text may have been downloaded from the Netgate server, but if not the default text defined here
 * is used
 */

$copyrightfile = "{$g['cf_conf_path']}/copyright";

?>
<div id="usage" class="modal fade" role="dialog">
	<div class="modal-dialog modal-lg">
		<div class="modal-content">
<?php
			if (file_exists($copyrightfile) && (filesize($copyrightfile) > 0)) {
				require_once($copyrightfile);
			} else {
?>
			<div id="main1" class="modal-body modal-lg modal-dialog-scrollable" style="background-color:#1e3f75; color:white;">
				<p style="font-size:16px">
					<b>Copyright and License Notice</b>
				</p>
				<p>
					FreeSense is a community rebuild and rebrand of the open-source
					pfSense<sup>&reg;</sup> CE firewall distribution. It is licensed under the
					Apache License, Version 2.0.
				</p>
				<p>
					FreeSense is a derivative work of pfSense CE, which is
					Copyright<sup>&copy;</sup> 2004-2016 Electric Sheep Fencing, LLC and
					Copyright<sup>&copy;</sup> 2014-2026 Rubicon Communications, LLC (Netgate),
					and was originally published under the Apache License 2.0. The original
					copyright notices are retained in accordance with that license, and
					modifications relative to upstream pfSense are documented in the project
					NOTICE file. Portions are originally based on m0n0wall,
					Copyright<sup>&copy;</sup> 2003-2004 Manuel Kasper.
				</p>
				<p>
					"pfSense" is a registered trademark of Electric Sheep Fencing, LLC,
					licensed to Netgate. FreeSense is <b>not</b> pfSense and is not affiliated
					with or endorsed by Netgate or Electric Sheep Fencing. The pfSense name is
					used only to identify the upstream project from which FreeSense is derived.
				</p>
				<p>
					This software is distributed under the Apache License 2.0 on an "AS IS"
					BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or
					implied. You may obtain a copy of the License at
					http://www.apache.org/licenses/LICENSE-2.0
				</p>
			</div>

			<div id="f1" class="modal-footer modal-lg" style="background-color:#1e3f75; color:white;">
				<button type="button" class="btn btn-xs btn-success" aria-label="Close" onClick="$('.modal').modal('hide');">
					<span aria-hidden="true">
						Accept
					</span>
				</button>
			</div>

<?php
			}
?>

		</div>
	</div>
</div>

<?php

if (!file_exists("/tmp/nofile")) :
?>

<script type="text/javascript">
//<![CDATA[
events.push(function() {
	$('#usage').modal({backdrop: 'static', keyboard: false});
	$('#usage').modal('show');
});
//]]>
</script>

<?php
endif;
