#!/bin/sh
# Merge immutable GitHub stock-snapshot shards and publish only after the
# canonical root manifest, package bytes, distfiles, and ports pin agree.
set -u

BULK="${FREESENSE_BULK:-}"
PORTS="${FREESENSE_PORTS_NAME:-}"
REV="${FREESENSE_REV:-}"
CHAN="${FREESENSE_CHANNEL:-main}"
SNAPSHOT_ID="${FREESENSE_SNAPSHOT_ID:-$REV}"
WORKER_COUNT="${FREESENSE_SNAPSHOT_WORKER_COUNT:-0}"
KIND="${REPO_KIND:-system}"
TREE="/usr/local/poudriere/ports/${PORTS}"
SNAPBASE="R2:freesense-pkg/ports-cache/stock/${CHAN}"
SNAPDIR="${SNAPBASE}/${SNAPSHOT_ID}"
DIAG="/tmp/stock-snapshot-merge.diag"
: >"${DIAG}"

say(){ echo ">>> stock-merge: $*"; printf '%s\n' "$*" >>"${DIAG}"; }
finish(){ rclone copyto "${DIAG}" "R2:freesense-pkg/debug/stock-snapshot-merge-${KIND}.diag" --s3-no-check-bucket >/dev/null 2>&1 || true; }
trap finish EXIT
bail(){ say "ABORT - $1"; exit 1; }
snap_has(){ [ -n "$(rclone lsf "$1" 2>/dev/null)" ]; }

[ -n "${BULK}" ] && [ -s "${BULK}" ] || bail "canonical bulk manifest is missing"
[ -n "${PORTS}" ] && [ -d "${TREE}" ] || bail "pinned ports tree is missing"
[ -n "${REV}" ] || bail "FreeBSD revision is missing"
[ "${WORKER_COUNT}" -gt 0 ] 2>/dev/null || bail "worker count must be positive"
command -v rclone >/dev/null 2>&1 || bail "rclone is missing"

if snap_has "${SNAPDIR}/meta"; then
	say "snapshot ${SNAPSHOT_ID} is already complete"
	exit 0
fi

MERGED=/tmp/stock-merged
STAGE=/tmp/stock-stage
rm -rf "${MERGED}" "${STAGE}" /tmp/stock-worker
mkdir -p "${MERGED}/All" "${MERGED}/distfiles" "${STAGE}"
: >/tmp/stock-roots.actual
: >/tmp/stock-conflicts
PIN=""

i=0
while [ "${i}" -lt "${WORKER_COUNT}" ]; do
	WROOT="${SNAPDIR}/workers/${i}"
	COMPLETE=$(rclone cat "${WROOT}/complete" 2>/dev/null | tr -d '\r\n')
	[ "${COMPLETE}" = "${SNAPSHOT_ID} worker-${i}/${WORKER_COUNT}" ] \
		|| bail "worker-${i} completion marker is missing or belongs to another plan"
	rm -rf /tmp/stock-worker
	mkdir -p /tmp/stock-worker/pkg /tmp/stock-worker/dist
	rclone copyto "${WROOT}/packages.tar" /tmp/stock-worker/packages.tar >>"${DIAG}" 2>&1 \
		|| bail "worker-${i} packages archive is missing"
	rclone copyto "${WROOT}/distfiles.tar.zst" /tmp/stock-worker/distfiles.tar.zst >>"${DIAG}" 2>&1 \
		|| bail "worker-${i} distfiles archive is missing"
	rclone copyto "${WROOT}/roots.txt" /tmp/stock-worker/roots.txt >>"${DIAG}" 2>&1 \
		|| bail "worker-${i} roots manifest is missing"
	WORKER_PIN=$(rclone cat "${WROOT}/ports_top_git_hash" 2>/dev/null | tr -dc '0-9a-f')
	[ -n "${WORKER_PIN}" ] || bail "worker-${i} has no ports commit"
	[ -z "${PIN}" ] && PIN="${WORKER_PIN}"
	[ "${PIN}" = "${WORKER_PIN}" ] || bail "worker-${i} used a different ports commit"

	tar -xf /tmp/stock-worker/packages.tar -C /tmp/stock-worker/pkg >>"${DIAG}" 2>&1 \
		|| bail "worker-${i} package archive is corrupt"
	tar --zstd -xf /tmp/stock-worker/distfiles.tar.zst -C /tmp/stock-worker/dist >>"${DIAG}" 2>&1 \
		|| bail "worker-${i} distfile archive is corrupt"

	for p in /tmp/stock-worker/pkg/*.pkg; do
		[ -e "${p}" ] || continue
		dst="${MERGED}/All/$(basename "${p}")"
		if [ -e "${dst}" ]; then
			[ "$(sha256 -q "${dst}")" = "$(sha256 -q "${p}")" ] \
				|| echo "package $(basename "${p}")" >>/tmp/stock-conflicts
		else
			cp "${p}" "${dst}"
		fi
	done

	find /tmp/stock-worker/dist -type f | while IFS= read -r p; do
		rel=${p#/tmp/stock-worker/dist/}
		dst="${MERGED}/distfiles/${rel}"
		mkdir -p "$(dirname "${dst}")"
		if [ -e "${dst}" ]; then
			[ "$(sha256 -q "${dst}")" = "$(sha256 -q "${p}")" ] \
				|| echo "distfile ${rel}" >>/tmp/stock-conflicts
		else
			cp "${p}" "${dst}"
		fi
	done
	cat /tmp/stock-worker/roots.txt >>/tmp/stock-roots.actual
	i=$((i + 1))
done

if [ -s /tmp/stock-conflicts ]; then
	cat /tmp/stock-conflicts >>"${DIAG}"
	bail "worker outputs contain byte-different duplicates"
fi

sed -e '/^[[:space:]]*#/d' -e '/^[[:space:]]*$/d' "${BULK}" | sort -u >/tmp/stock-roots.expected
sort -u /tmp/stock-roots.actual -o /tmp/stock-roots.actual
if ! cmp -s /tmp/stock-roots.expected /tmp/stock-roots.actual; then
	diff -u /tmp/stock-roots.expected /tmp/stock-roots.actual >>"${DIAG}" 2>&1 || true
	bail "worker root union does not match the canonical manifest"
fi

NSTOCK=$(find "${MERGED}/All" -name '*.pkg' -type f | wc -l | tr -d ' ')
[ "${NSTOCK:-0}" -gt 0 ] || bail "worker union contains no packages"
for p in "${MERGED}/All"/*.pkg; do
	pkg query -F "${p}" '%n|%v|%o' >/dev/null 2>&1 || bail "invalid package $(basename "${p}")"
done

tar -cf "${STAGE}/packages.tar" -C "${MERGED}/All" . >>"${DIAG}" 2>&1 || bail "failed to merge packages"
tar --zstd -cf "${STAGE}/distfiles.tar.zst" -C "${MERGED}/distfiles" . >>"${DIAG}" 2>&1 || bail "failed to merge distfiles"
tar --zstd -cf "${STAGE}/ports-src.tar.zst" -C "$(dirname "${TREE}")" "$(basename "${TREE}")" >>"${DIAG}" 2>&1 \
	|| bail "failed to archive the pinned ports tree"
printf '%s\n' "${PIN}" >"${STAGE}/ports_top_git_hash"
{
	echo "rev=${REV}"
	echo "snapshot_id=${SNAPSHOT_ID}"
	echo "channel=${CHAN}"
	echo "repository_kind=${KIND}"
	echo "ports_top_git_hash=${PIN}"
	echo "stock_pkgs=${NSTOCK}"
	echo "workers=${WORKER_COUNT}"
	echo "built_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ 2>/dev/null)"
} >"${STAGE}/meta"

UPLOAD_OK=1
for f in packages.tar ports-src.tar.zst ports_top_git_hash distfiles.tar.zst; do
	rclone copyto --s3-no-check-bucket --transfers 8 --retries 10 --low-level-retries 20 \
		"${STAGE}/${f}" "${SNAPDIR}/${f}" >>"${DIAG}" 2>&1 || { say "upload of ${f} failed"; UPLOAD_OK=0; }
done
[ "${UPLOAD_OK}" = 1 ] || bail "one or more final artifacts failed to upload"

OLD=$(rclone cat "${SNAPBASE}/current" 2>/dev/null | tr -d '\r\n')
if [ -n "${OLD}" ] && [ "${OLD}" != "${SNAPSHOT_ID}" ]; then
	printf '%s\n' "${OLD}" >/tmp/stock-previous
	rclone copyto --s3-no-check-bucket /tmp/stock-previous "${SNAPBASE}/previous" >>"${DIAG}" 2>&1 || true
fi
rclone copyto --s3-no-check-bucket "${STAGE}/meta" "${SNAPDIR}/meta" >>"${DIAG}" 2>&1 || bail "final metadata upload failed"
printf '%s\n' "${SNAPSHOT_ID}" >/tmp/stock-current
rclone copyto --s3-no-check-bucket /tmp/stock-current "${SNAPBASE}/current" >>"${DIAG}" 2>&1 \
	|| say "warning: artifacts are complete but the compatibility current pointer was not updated"
say "PUBLISHED ${SNAPSHOT_ID}: ${NSTOCK} packages from ${WORKER_COUNT} verified GitHub workers"
