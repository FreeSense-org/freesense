# Input validation: the shared library and what it replaces

`src/etc/inc/validate.inc` is the one place for the input checks that the
WebUI pages, the REST API and the system libraries had each written for
themselves. This document says what the survey of the tree found, what the
library covers today, which behaviour changed on purpose, and what is worth
moving next.

## What the survey found

The validation code is spread over three generations: the pfSense-era pages
under `src/usr/local/www`, the page logic moved to
`src/usr/local/FreeSense/include/www` so the GUI and the API share one save
function, and the newer Fleet and REST libraries. Each generation solved the
same small problems again.

| Pattern | Copies before | Where |
| --- | --- | --- |
| `preg_match('/[\x00-\x1f\x7f]/', …)` "no control characters" | 23 | Fleet, REST routes, menu, prefs, auth, L2TP |
| `preg_match("/[^a-zA-Z0-9\.\-_]/", …)` "only safe name characters" | 7 | user manager, auth servers, DHCP DDNS key, OpenVPN status |
| `strlen($x) > N \|\| preg_match(control chars)` "bounded printable text" | 15 | same files as the first row |
| hand-written "is required" checks instead of `do_input_validation()` | 228 lines | pages |
| `is_numeric()` on a form field that must be a whole number | 349 calls | pages (accepts `1e3`, `" 1"`, `1.5`) |
| anchored regex (`/^…$/`) without the `D` modifier | 108 | `etc/inc` 63, `include/www` 24, `www` 21 |
| `mwexec("… {$var} …")` with a variable not passed through `escapeshellarg()` | 123 | mostly `$g[]` paths, some device and address strings |
| `htmlspecialchars()` / `fs_h()` / `htmlentities()` mixed for output | 1861 / 323 / 35 | pages |

Two of these are security-relevant on their own:

- **A regex anchored with `$` but without `D`** matches a value that ends in
  a newline. `is_domain("example.com\n")`, `is_subnet("10.0.0.0/8\n")` and
  `is_mcastv4("224.0.0.1\n")` all returned true. Such a value then reaches a
  configuration file or a `pf` rule with the newline in it. The coding standard
  already requires `/^…$/D`; the oldest validators predate it.
- **`do_input_validation()` allowed DEL (0x7f)** in every form field while
  refusing the other control characters, and read missing required fields with
  a loose `==` that warns under PHP 8.

The rest is duplication: each copy is a place where a later fix is forgotten,
and each one reads differently so a reviewer has to check it again.

## What the library covers

Every function is pure and typed, takes `mixed` so a tampered request
(an array where a string was expected) is refused rather than cast, and
anchors each regex with `D`. The file loads on its own in `php-cli`;
`util.inc` loads it for the appliance so every page and route has it.

| Function | Replaces |
| --- | --- |
| `validate_has_control_chars($text, $allow_whitespace)` | the control-character regex |
| `validate_strip_control_chars($text, $replacement)` | `preg_replace` of the same class before logging |
| `validate_text($value, $max, $min, $multiline)` | "is a string, N to M bytes, printable" |
| `validate_name_chars($value, $extra, $max)` | the `[^a-zA-Z0-9.-_]` class for user, group, host and key names |
| `validate_in_list($value, $allowed)` | allow-list checks written as `in_array` with loose types |
| `validate_int($value, $min, $max)` | `is_numeric()` + range on a whole number |
| `validate_port_number($value)` | `is_numeric()` + `>= 1 && <= 65535` |
| `validate_hex($value, $length)` | `/^[0-9a-f]{64}$/` and friends |
| `validate_base64($value, $min_bytes)` | `base64_decode($x, true) !== false` |
| `validate_required($post, $fields)` | the required loop in `do_input_validation()` and 228 hand-written copies |
| `validate_post_control_chars($post)` | the control-character loop in `do_input_validation()` |

Address, host, port-with-name and alias checks stay in `util.inc`
(`is_ipaddr()`, `is_hostname()`, `is_port()`, `is_validaliasname()`, …); the
library does not repeat them. It also does not know the WebUI's `input_errors`
or the API's `RestApiError`: a page calls a check and adds its own message, as
the coding standard asks.

### Using it in a page

```php
if (!validate_name_chars($post['usernamefld'] ?? '')) {
	$input_errors[] = gettext('The username contains invalid characters.');
}
if (!validate_int($post['maxproc'] ?? '', 1, 1024)) {
	$input_errors[] = gettext('Maximum processes must be a whole number from 1 to 1024.');
}
$input_errors = array_merge($input_errors, validate_required($post, array(
	'name' => gettext('Name'),
	'interface' => gettext('Interface'),
)));
```

Use `do_input_validation()` where a page already builds the `$reqdfields` and
`$reqdfieldsn` arrays; it now runs on `validate_required()` and
`validate_post_control_chars()` and gives the same messages.

### Using it in a REST route

```php
if (!validate_text($operator, 80, 1)) {
	throw restapi_field_error('operator', 'Who asks for the update, 1 to 80 characters.');
}
```

## What moved in the first change

- `do_input_validation()` in `util.inc` delegates to the library.
- `is_domain()`, `is_hostname()`, `is_unqualified_hostname()`, `is_subnet()`,
  `is_ipaddrv6_v4map()`, `is_mcastv4()`, `is_mcastv6()` and
  `is_valid_shaperbw()` anchor with `D`.
- The seven name-character copies (user manager, auth servers, DHCP, OpenVPN
  status) call `validate_name_chars()`.
- The control-character copies in Fleet (`fleet_guard`, `fleet_node`,
  `fleet_handoff`), the REST routes (management, diagnostics, logs, update,
  request log), the menu, the WebUI preferences, `auth.inc` and L2TP call
  `validate_text()`, `validate_has_control_chars()` or
  `validate_strip_control_chars()`.
- `tests/ValidateSmokeTest.php` covers every function, every refusal path,
  the hardened `util.inc` validators (read out of the source, since `util.inc`
  cannot load outside the appliance) and fails if a hand-written copy of a
  replaced pattern comes back.

Two sanitising calls in `fleet_accounts.inc` keep their own `preg_replace`
because they run in UTF-8 mode (`/u`), which changes what happens to invalid
byte sequences; the library works on bytes.

### Behaviour that changed on purpose

- A hostname, domain, subnet, multicast address or shaper bandwidth that ends
  in a newline is refused. Nothing legitimate produces one.
- A form field holding DEL (0x7f) is refused like the other control
  characters.
- `do_input_validation()` no longer warns when a required field is absent
  from the post, and starts the error list itself when the caller passes
  something that is not an array.
- `validate_name_chars()` on a missing field (`null`) passes; the required
  check reports it. The old `preg_match()` on `null` passed too (and warned
  under PHP 8.1 and later), so pages behave the same without the notice.

## What to move next

In order of value for the effort:

1. **`is_numeric()` on whole-number fields (349 calls).** Each is a place
   where `1e3` or `1.5` is accepted and later cast. Replace with
   `validate_int($v, $min, $max)` page by page, keeping the page's message.
   Pages that store the value with `(int)` or `intval()` are safe to switch
   first.
2. **The 228 hand-written required checks.** Most are
   `if (empty($_POST['x'])) { $input_errors[] = gettext('… is required.'); }`.
   Where the page already has a required list, add the field to it; otherwise
   `validate_required()` with a label map. Keep the exact message text where
   the REST schema maps messages to fields (`restapi_errors_to_fields()`).
3. **The remaining 108 regexes without `D`.** Mechanical: add `D` to every
   `/^…$/` that validates input. Interface-name matching in `util.inc`
   (`is_interface_mismatch()`) is not validation and can stay.
4. **`mwexec()` with interpolated variables (123).** The `$g[]` paths are
   constants and safe. The ones to look at first take a device name, address
   or filename from configuration: `system.inc` (`camcontrol … {$harddisk}`,
   `cat … > {$serialport}`), `openvpn.inc` (`route delete {$ip_to_clear}`),
   `config.console.inc` (`ifconfig {$if}`). Each wants `escapeshellarg()` on
   the variable, which is a one-line change per site.
5. **Output escaping.** `fs_h()` is the newer helper; `htmlspecialchars()` is
   the older spelling of the same call. `htmlentities()` (35 sites) and
   `strip_tags()` (43 sites, which is not an escaping function) are worth a
   read each. No change to the library is needed; this is a page-by-page
   review.
6. **Packages.** `freesense-packages` calls `is_numericint()` 175 times and
   `do_input_validation()` 13 times through `util.inc`, so it gets the
   hardening for free. Its own `validate_input_*` and `validate_form_*`
   functions can adopt the library the same way once it ships.

A migration keeps the page's own message text and the order of its checks.
Tests belong in the page's smoke test, not in `ValidateSmokeTest.php`, which
covers the library alone.
