<?php
/*
 * autoload.inc.php
 *
 * part of FreeSense (https://www.freesense.org)
 * Copyright (c) 2004-2013 BSD Perimeter
 * Copyright (c) 2013-2016 Electric Sheep Fencing
 * Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
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

function freesense_www_class_autoloader($classname) {
	// Convert classname to match filename conventions
	$filename = str_replace('_', '/', $classname);

	// Build the full path, load it if it exists
	$filepath = "/usr/local/www/classes/{$filename}.class.php";
	if (file_exists($filepath)) {
		require_once($filepath);
	}
}
spl_autoload_register('freesense_www_class_autoloader');
