<?php
/*
 * Optional-package generated metadata reconciliation.
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

/*
 * config.xml treats <package> as a list tag. Generated menu and service
 * metadata therefore returns its single owner as array('name') after a
 * config reload, even though it was written as a scalar. Accept only that
 * single-value form so malformed metadata remains untrusted.
 */
function freesense_package_generated_entry_owner($value): string {
	if (is_array($value)) {
		if (count($value) !== 1) {
			return '';
		}
		$value = reset($value);
	}
	if (!is_scalar($value)) {
		return '';
	}
	return trim((string)$value);
}

function freesense_package_generated_identity_matches(
    $left, $right, array $identity_fields): bool {
	if (!is_array($left) || !is_array($right)) {
		return false;
	}
	foreach ($identity_fields as $field) {
		if (trim((string)($left[$field] ?? '')) !==
		    trim((string)($right[$field] ?? ''))) {
			return false;
		}
	}
	return true;
}

/*
 * Replace generated entries owned by this package with the current XML
 * declarations. An ownerless legacy entry with the same logical identity is
 * upgraded in place, while entries owned by other packages are preserved.
 */
function freesense_package_reconcile_generated_entries(
    $stored, $declared, string $owner, array $identity_fields): array {
	$result = array();
	foreach ((array)$stored as $entry) {
		if (!is_array($entry)) {
			continue;
		}
		$entry_owner = freesense_package_generated_entry_owner(
		    $entry['package'] ?? '');
		if ($entry_owner !== '' && strcasecmp($entry_owner, $owner) === 0) {
			continue;
		}
		$result[] = $entry;
	}

	foreach ((array)$declared as $entry) {
		if (!is_array($entry) || empty($entry)) {
			continue;
		}
		$entry['package'] = $owner;
		$replaced = false;
		foreach ($result as $key => $existing) {
			if (freesense_package_generated_entry_owner(
			    $existing['package'] ?? '') !== '' ||
			    !freesense_package_generated_identity_matches(
			        $existing, $entry, $identity_fields)) {
				continue;
			}
			$result[$key] = $entry;
			$replaced = true;
			break;
		}
		if (!$replaced) {
			$result[] = $entry;
		}
	}

	return array_values($result);
}
