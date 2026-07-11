<?php
/*
 * config_import_guard.inc
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
 * Foreign-config import guard + base-OS sanitizer.
 *
 * Importing a configuration from another firewall distribution (OPNsense, or an
 * older/foreign pfSense) can leave a FreeSense box unbootable or lock you out:
 * OPNsense's restore overwrites the FreeBSD base user/group/shells database and
 * carries schema-incompatible sections and empty flag tags that FreeSense
 * misinterprets. This module makes restore safe:
 *
 *   - freesense_config_detect_lineage()  identify pfsense / opnsense / freesense
 *   - freesense_config_convert_foreign()  rewrite a foreign config into a
 *                                          FreeSense-compatible one
 *   - freesense_config_sanitize_base_os() defence-in-depth run after ANY restore
 *                                          so the box always boots and is
 *                                          loginable regardless of what was
 *                                          imported.
 *
 * The package side (auto-map + suggest) lives in config_import_pkgmap.inc and
 * shares the language-neutral table /etc/config_import_pkgmap.map with the
 * installer importer so the two flows never drift.
 */

/*
 * Detect the lineage of a config.xml given as a string.
 * Returns 'freesense', 'pfsense', 'opnsense', or 'unknown'.
 *
 * Primary signal is the XML root element; corroborating markers guard against a
 * mis-tagged file (e.g. an OPNsense config whose root was hand-renamed).
 */
function freesense_config_detect_lineage($xml) {
	if (!is_string($xml) || $xml === '') {
		return 'unknown';
	}

	/* Strong OPNsense markers (present even if the root was renamed). */
	if (preg_match('/<OPNsense>/', $xml) ||
	    preg_match('/<theme>\s*opnsense\s*<\/theme>/i', $xml) ||
	    preg_match('/<system>.*<firmware>.*<plugins>/is', $xml)) {
		return 'opnsense';
	}

	/* Root element. */
	if (preg_match('/<\s*(freesense|pfsense|opnsense)\s*>/', $xml, $m)) {
		return strtolower($m[1]);
	}

	return 'unknown';
}

/*
 * Top-level config sections that are schema-incompatible or OPNsense-only and
 * must be dropped when importing an OPNsense config, so the parser and the
 * FreeSense subsystems are not fed foreign structures.
 *
 * Intentionally NOT dropped: system, ca, cert, users (PKI + accounts survive),
 * interfaces, dhcpd/dhcpdv6, gateways, staticroutes, vlans, sysctl.
 */
function freesense_config_opnsense_drop_sections() {
	return array(
		'OPNsense',       /* the whole MVC tree */
		'filter',         /* firewall rules - different schema */
		'nat',
		'trafficshaper',
		'load_balancer',
		'ipsec',          /* Swanctl format != pfSense phase1/phase2 */
		'openvpn',        /* different schema */
		'dhcrelay',
		'widgets',        /* OPNsense widget names */
		'installedpackages',
		'bootup',
		'version',        /* let FreeSense apply its own config upgrade */
		'trust',          /* OPNsense CA store lives here too; ca/cert kept above */
		'OPNsense_firmware',
	);
}

/*
 * Empty "flag" tags that FreeSense's config_path_enabled() treats as ENABLED by
 * mere presence. A foreign config that carries these as empty elements silently
 * changes behaviour (the OPNsense import that shipped <disableconsolemenu></>
 * turned the console menu off). Strip empty occurrences on import.
 */
function freesense_config_toxic_empty_flags() {
	return array(
		'disableconsolemenu',
		'disablebeep',
		'disablechecksumoffloading',
		'disablesegmentationoffloading',
		'disablelargereceiveoffloading',
	);
}

/*
 * Convert a foreign config.xml string into a FreeSense-compatible one.
 * Returns array('xml' => <converted string>, 'notes' => array of human notes).
 *
 * pfSense: rename root only (FreeSense is rebranded pfSense; keep everything
 *          including <installedpackages> so package settings survive).
 * OPNsense: rename root, drop the incompatible sections above, strip toxic
 *          empty flag tags. Keeps ca/cert/system/users.
 *
 * This mirrors the installer's convert_pfsense()/convert_opnsense() so the GUI
 * and installer produce equivalent output.
 */
function freesense_config_convert_foreign($xml, $lineage) {
	$notes = array();

	if ($lineage == 'pfsense') {
		$xml = preg_replace('/<\s*pfsense\s*>/', '<freesense>', $xml, 1);
		$xml = preg_replace('/<\s*\/\s*pfsense\s*>/', '</freesense>', $xml, 1);
		$notes[] = gettext('Renamed pfSense config root to FreeSense (full configuration kept).');
		return array('xml' => $xml, 'notes' => $notes);
	}

	if ($lineage == 'opnsense') {
		/* rename root */
		$xml = preg_replace('/<\s*opnsense\s*>/', '<freesense>', $xml, 1);
		$xml = preg_replace('/<\s*\/\s*opnsense\s*>/', '</freesense>', $xml, 1);

		/* drop each incompatible top-level section (greedy-safe, ungreedy). */
		foreach (freesense_config_opnsense_drop_sections() as $sec) {
			$sq = preg_quote($sec, '/');
			/* paired <sec>...</sec> possibly multiline */
			$n = 0;
			$xml = preg_replace('/[ \t]*<' . $sq . '>.*?<\/' . $sq . '>[ \t]*\r?\n?/is', '', $xml, -1, $n);
			/* self-closing <sec/> */
			$xml = preg_replace('/[ \t]*<' . $sq . '\s*\/>[ \t]*\r?\n?/i', '', $xml);
			if ($n > 0) {
				$notes[] = sprintf(gettext('Dropped OPNsense-specific section <%s> (reconfigure after import).'), $sec);
			}
		}

		/* strip toxic empty flag tags */
		foreach (freesense_config_toxic_empty_flags() as $flag) {
			$fq = preg_quote($flag, '/');
			$xml = preg_replace('/[ \t]*<' . $fq . '>\s*<\/' . $fq . '>[ \t]*\r?\n?/i', '', $xml);
			$xml = preg_replace('/[ \t]*<' . $fq . '\s*\/>[ \t]*\r?\n?/i', '', $xml);
		}

		/* OPNsense keeps Unbound in the dropped MVC tree. Ensure the converted
		 * system can resolve package/update hosts immediately after import. */
		if (!preg_match('/<\s*unbound\s*>/i', $xml)) {
			$resolver = "\t<unbound>\n\t\t<enable></enable>\n\t</unbound>\n";
			$xml = preg_replace('/<\s*\/\s*freesense\s*>/i', $resolver . '</freesense>', $xml, 1);
			$notes[] = gettext('Enabled the FreeSense DNS Resolver because OPNsense resolver settings are not portable.');
		}

		$notes[] = gettext('Imported OPNsense basic settings, certificates and users. Firewall, NAT and VPN must be reconfigured.');
		return array('xml' => $xml, 'notes' => $notes);
	}

	/* already freesense or unknown - no conversion */
	return array('xml' => $xml, 'notes' => $notes);
}

/*
 * The canonical FreeBSD base users a FreeSense box relies on. Keyed by name;
 * value is array(uid, gid, gecos, home, shell). Kept in sync with the base
 * system; only the accounts whose absence actually breaks the box are required
 * (sshd is the critical one), but we restore the standard set for hygiene.
 *
 * This is the same set proven to repair a shredded master.passwd on a live box.
 */
function freesense_config_base_users() {
	return array(
		/* name         => array(uid, gid, gecos,                         home,                       shell) */
		'daemon'         => array(1,   1,   'Owner of many system processes', '/root',                '/usr/sbin/nologin'),
		'operator'       => array(2,   5,   'System &',                    '/usr/guest/operator',      '/usr/sbin/nologin'),
		'bin'            => array(3,   7,   'Binaries Commands and Source','/',                        '/usr/sbin/nologin'),
		'tty'            => array(4,   65533, 'Tty Sandbox',               '/',                        '/usr/sbin/nologin'),
		'kmem'           => array(5,   65533, 'KMem Sandbox',              '/',                        '/usr/sbin/nologin'),
		'games'          => array(7,   13,  'Games pseudo-user',           '/usr/games',               '/usr/sbin/nologin'),
		'news'           => array(8,   8,   'News Subsystem',              '/',                        '/usr/sbin/nologin'),
		'man'            => array(9,   9,   'Mister Man Pages',            '/usr/share/man',           '/usr/sbin/nologin'),
		'sshd'           => array(22,  22,  'Secure Shell Daemon',         '/var/empty',               '/usr/sbin/nologin'),
		'smmsp'          => array(25,  25,  'Sendmail Submission User',    '/var/spool/clientmqueue',  '/usr/sbin/nologin'),
		'mailnull'       => array(26,  26,  'Sendmail Default User',       '/var/spool/mqueue',        '/usr/sbin/nologin'),
		'bind'           => array(53,  53,  'Bind Sandbox',                '/',                        '/usr/sbin/nologin'),
		'proxy'          => array(62,  62,  'Packet Filter pseudo-user',   '/nonexistent',             '/usr/sbin/nologin'),
		'_pflogd'        => array(64,  64,  'pflogd privsep user',         '/var/empty',               '/usr/sbin/nologin'),
		'_dhcp'          => array(65,  65,  'dhcp programs',               '/var/empty',               '/usr/sbin/nologin'),
		'uucp'           => array(66,  66,  'UUCP pseudo-user',            '/var/spool/uucppublic',    '/usr/local/libexec/uucp/uucico'),
		'auditdistd'     => array(78,  77,  'Auditdistd unprivileged user','/var/empty',               '/usr/sbin/nologin'),
		'_ypldap'        => array(160, 160, 'YP LDAP unprivileged user',   '/var/empty',               '/usr/sbin/nologin'),
		'_tss'           => array(601, 601, 'TrouSerS user',               '/var/empty',               '/usr/sbin/nologin'),
		'_ntp'           => array(123, 123, 'NTP Daemon',                  '/var/empty',               '/usr/sbin/nologin'),
	);
}

/* Base groups (name => gid). */
function freesense_config_base_groups() {
	return array(
		'daemon' => 1, 'kmem' => 2, 'sys' => 3, 'tty' => 4, 'operator' => 5,
		'mail' => 6, 'bin' => 7, 'news' => 8, 'man' => 9, 'games' => 13,
		'staff' => 20, 'sshd' => 22, 'smmsp' => 25, 'mailnull' => 26,
		'guest' => 31, 'bind' => 53, 'proxy' => 62, 'authpf' => 63,
		'_pflogd' => 64, '_dhcp' => 65, 'uucp' => 66, 'dialer' => 68,
		'network' => 69, 'audit' => 77, '_ypldap' => 160, '_ntp' => 123,
		'_tss' => 601, 'nogroup' => 65533,
	);
}

/*
 * Defence-in-depth: run after ANY config restore. Idempotent and cheap.
 * Guarantees:
 *   - all FreeBSD base users/groups exist (sshd is the critical one);
 *   - /etc/rc.initial is a valid login shell (console menu works);
 *   - root's shell is /etc/rc.initial;
 *   - the password DB is rebuilt.
 * Returns array of actions taken (empty = nothing needed).
 */
function freesense_config_sanitize_base_os() {
	$actions = array();

	/* 1. base groups */
	foreach (freesense_config_base_groups() as $name => $gid) {
		exec('/usr/sbin/pw groupshow ' . escapeshellarg($name) . ' 2>/dev/null', $o, $rc);
		unset($o);
		if ($rc != 0) {
			mwexec('/usr/sbin/pw groupadd -n ' . escapeshellarg($name) . ' -g ' . (int)$gid);
			$actions[] = "group +{$name}({$gid})";
		}
	}

	/* 2. base users */
	foreach (freesense_config_base_users() as $name => $u) {
		exec('/usr/sbin/pw usershow ' . escapeshellarg($name) . ' 2>/dev/null', $o, $rc);
		unset($o);
		if ($rc != 0) {
			list($uid, $gid, $gecos, $home, $shell) = $u;
			mwexec('/usr/sbin/pw useradd -n ' . escapeshellarg($name) .
			    ' -u ' . (int)$uid . ' -g ' . (int)$gid .
			    ' -c ' . escapeshellarg($gecos) .
			    ' -d ' . escapeshellarg($home) .
			    ' -s ' . escapeshellarg($shell) . ' -w no');
			$actions[] = "user +{$name}({$uid})";
		}
	}

	/* 3. /etc/rc.initial must be a valid login shell (console menu). */
	$shells_file = '/etc/shells';
	$want_shells = array('/etc/rc.initial', '/etc/rc.initial.setlanip', '/usr/sbin/nologin');
	$shells = @file_get_contents($shells_file);
	if ($shells === false) {
		$shells = "";
	}
	$append = "";
	foreach ($want_shells as $s) {
		if (!preg_match('/^' . preg_quote($s, '/') . '\s*$/m', $shells)) {
			$append .= $s . "\n";
			$actions[] = "shell +{$s}";
		}
	}
	if ($append !== "") {
		@file_put_contents($shells_file, rtrim($shells, "\n") . "\n" . $append, LOCK_EX);
	}

	/* 4. root shell must be /etc/rc.initial. */
	$mp = @file_get_contents('/etc/master.passwd');
	if (is_string($mp) && preg_match('/^root:[^:]*:0:0:.*:(\S+)\s*$/m', $mp, $rm)) {
		if ($rm[1] !== '/etc/rc.initial') {
			mwexec('/usr/sbin/pw usermod root -s /etc/rc.initial');
			$actions[] = 'root shell -> /etc/rc.initial';
		}
	}

	/* 5. rebuild the password DB so PAM/login see the changes. */
	if (!empty($actions)) {
		mwexec('/usr/sbin/pwd_mkdb -p /etc/master.passwd');
	}

	if (!empty($actions)) {
		logger(LOG_WARNING,
		    'config import sanitize: ' . implode(', ', $actions),
		    'CONFIG');
	}

	return $actions;
}
