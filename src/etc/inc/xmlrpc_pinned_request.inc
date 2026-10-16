<?php
/*
 * xmlrpc_pinned_request.inc
 *
 * part of FreeSense (https://freesense.org)
 * Copyright (c) 2025-2026 FreeSense
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

require_once('HTTP/Request2.php');

/*
 * An HTTP_Request2 that also passes the TLS peer_fingerprint option, so HA
 * configuration sync connects only when the peer shows exactly the pinned
 * certificate (hasync/syncpeerpin), checked on the connection itself.
 */
class FreeSensePinnedRequest extends HTTP_Request2 {
	public function __construct(string $sha256) {
		$this->config['ssl_peer_fingerprint'] = array('sha256' => $sha256);
		parent::__construct();
	}
}
