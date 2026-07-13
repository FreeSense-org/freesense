#!/bin/sh
# Print the optional-package compatibility train for a FreeSense version.
# Examples: 1.1.0-DEVELOPMENT -> 1.1, 1.2.4-RELEASE -> 1.2.
set -eu

_version_file=${1:-"${PRODUCT_SRC:-$(pwd)/src}/etc/version"}
_train=${FREESENSE_PACKAGE_TRAIN:-}

if [ -z "${_train}" ]; then
	[ -r "${_version_file}" ] || {
		echo "cannot derive package train: missing ${_version_file}" >&2
		exit 1
	}
	_version=$(head -n 1 "${_version_file}" | tr -d '[:space:]')
	_train=$(printf '%s\n' "${_version}" | sed -nE 's/^([0-9]+\.[0-9]+)\..*$/\1/p')
fi

case "${_train}" in
	''|*[!0-9.]*|*.*.*)
		echo "invalid FreeSense package train '${_train}' (expected major.minor)" >&2
		exit 1
		;;
esac
_major=${_train%%.*}
_minor=${_train#*.}
[ -n "${_major}" ] && [ -n "${_minor}" ] || {
	echo "invalid FreeSense package train '${_train}' (expected major.minor)" >&2
	exit 1
}

printf '%s\n' "${_train}"
