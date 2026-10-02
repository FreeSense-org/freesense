#!/bin/sh
# Lint FreeSense PHP sources with the PHP CLI on PATH and fail on syntax
# errors or compile-time deprecations.
#
# Usage: tools/ci/php-lint.sh [file-list]
#   file-list: newline-separated paths to lint. Without it, every *.php and
#   *.inc file under src/ and tools/ plus every extensionless script starting
#   with a PHP shebang or open tag is linted (Composer vendor directories and
#   tools/rector/tests are skipped).
#
# PHP_LINT_DEPRECATION_ALLOW: space-separated paths whose deprecation notices
# are reported as warnings instead of failing the run (temporary carve-outs).

set -eu

allow=${PHP_LINT_DEPRECATION_ALLOW:-}
list=$(mktemp)
log=$(mktemp)
trap 'rm -f "$list" "$log"' EXIT

if [ "$#" -gt 0 ]; then
	cat "$1" >"$list"
else
	{
		find src tools -type f \( -name '*.php' -o -name '*.inc' \) \
		    ! -path 'tools/rector/tests/*' ! -path '*/vendor/*'
		find src tools -type f ! -name '*.php' ! -name '*.inc' \
		    ! -path '*/vendor/*' ! -path 'tools/rector/tests/*' |
		    while IFS= read -r f; do
			head -n 1 "$f" | grep -qIE '^(#!.*php|<\?php)' &&
			    printf '%s\n' "$f"
		done
		true
	} | sort -u >"$list"
fi

php -v | head -n 1

failed=0
count=0
while IFS= read -r file; do
	[ -n "$file" ] || continue
	count=$((count + 1))
	if ! php -d error_reporting=E_ALL -d display_errors=stderr \
	    -d display_startup_errors=1 -l "$file" >"$log" 2>&1; then
		cat "$log" >&2
		failed=1
		continue
	fi
	if grep -q '^\(PHP \)\{0,1\}Deprecated:' "$log"; then
		allowed=0
		for a in $allow; do
			[ "$a" = "$file" ] && allowed=1
		done
		if [ "$allowed" -eq 1 ]; then
			grep '^\(PHP \)\{0,1\}Deprecated:' "$log" |
			    sed 's/^/::warning::(allowlisted) /'
		else
			grep '^\(PHP \)\{0,1\}Deprecated:' "$log" |
			    sed 's/^/::error::/' >&2
			failed=1
		fi
	fi
done <"$list"

echo "Linted ${count} PHP files."
exit "$failed"
