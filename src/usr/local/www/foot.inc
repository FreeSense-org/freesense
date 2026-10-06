<?php
/*
 * foot.inc
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

/*
 * We put jquery in the footer; it is the preferred position for speed
 * and helps developers to use events[] instead of executing scripts directly.
 */
?>
	</div>
	</main>

	<!-- shared confirmation dialog (js/freesense-ui.js: fsConfirm) -->
	<div class="modal fade" id="fs-confirm" tabindex="-1" aria-labelledby="fs-confirm-title" aria-hidden="true">
		<div class="modal-dialog modal-dialog-centered">
			<div class="modal-content">
				<div class="modal-header">
					<h2 class="modal-title" id="fs-confirm-title"></h2>
					<button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?=gettext('Close')?>"></button>
				</div>
				<div class="modal-body">
					<p class="fs-confirm-detail"></p>
				</div>
				<div class="modal-footer">
					<button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?=gettext('Cancel')?></button>
					<button type="button" class="btn btn-danger" data-fs-confirm-ok data-default-label="<?=gettext('Confirm')?>"><?=gettext('Confirm')?></button>
				</div>
			</div>
		</div>
	</div>

	<footer class="footer">
		<div class="container fs-page">
			<p class="text-muted">
				<a id="tpl" style="display: none;" href="#" title="<?=gettext('Top of page')?>"><i class="fa-regular fa-square-caret-up float-start"></i></a>
				<?=print_credit();?>
				<a id="tpr" style="display: none;" href="#" title="<?=gettext('Top of page')?>"><i class="fa-regular fa-square-caret-up float-end"></i></a>
			</p>
		</div>
	</footer>

	<!-- This use of filemtime() is intended to fool the browser into reloading the file (not using the cached version) if the file is changed -->

	<script src="/vendor/jquery/jquery-4.0.0.min.js?v=<?=filemtime('/usr/local/www/vendor/jquery/jquery-4.0.0.min.js')?>"></script>
 	<script src="/vendor/jquery-ui/jquery-ui-1.14.2.min.js?v=<?=filemtime('/usr/local/www/vendor/jquery-ui/jquery-ui-1.14.2.min.js')?>"></script>
	<script src="/vendor/bootstrap/js/bootstrap.bundle.min.js?v=<?=filemtime('/usr/local/www/vendor/bootstrap/js/bootstrap.bundle.min.js')?>"></script>
	<script src="/js/freesense-bs5-compat.js?v=<?=filemtime('/usr/local/www/js/freesense-bs5-compat.js')?>"></script>
	<script src="/js/FreeSense.js?v=<?=filemtime('/usr/local/www/js/FreeSense.js')?>"></script>
	<script src="/js/FreeSenseHelpers.js?v=<?=filemtime('/usr/local/www/js/FreeSenseHelpers.js')?>"></script>
	<script src="/js/polyfills.js?v=<?=filemtime('/usr/local/www/js/polyfills.js')?>"></script>
	<script src="/vendor/sortable/sortable.js?v=<?=filemtime('/usr/local/www/vendor/sortable/sortable.js')?>"></script>
	<script src="/js/freesense-ui.js?v=<?=filemtime('/usr/local/www/js/freesense-ui.js')?>"></script>

	<script type="text/javascript" src="/vendor/jquery-treegrid/js/jquery.treegrid.min.js?v=<?=filemtime('/usr/local/www/vendor/jquery-treegrid/js/jquery.treegrid.min.js')?>"></script>
	<script type="text/javascript" src="/vendor/js-cookie/js.cookie.min.js?v=<?=filemtime('/usr/local/www/vendor/js-cookie/js.cookie.min.js')?>"></script>
	<script type="text/javascript" src="/js/jquery-cookie-shim.js?v=<?=filemtime('/usr/local/www/js/jquery-cookie-shim.js')?>"></script>

	<script type="text/javascript">
	//<![CDATA[
		// Un-hide the "Top of page" icons if the page is larger than the window
		if ($(document).height() > $(window).height()) {
		    $('[id^=tp]').show();
		}
	//]]>
	</script>
</body>
</html>
