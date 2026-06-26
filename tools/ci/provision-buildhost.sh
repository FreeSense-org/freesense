#!/bin/sh
# provision-buildhost.sh — set up a FreeBSD host to build FreeSense entirely from git.
#
# Reproducible replacement for the old hand-built /root/prep16.sh. Run as root on a
# fresh FreeBSD 16-CURRENT amd64 host (poudriere builds the jail on the host kernel and
# needs host __FreeBSD_version >= jail; master/devel-main = FreeBSD 16).
#
# Design (see memory freesense-infra-plan / freesense-buildbox-ci):
#  - SINGLE rebranded tree: clone FreeSense-org/freesense to /root/freesense-src and use it
#    as BOTH the build framework (build.sh, tools/) AND the source (its src/ is already
#    rebranded; we do NOT run rebrand-src.sh, do NOT clone vanilla pfSense).
#  - Ports come from UPSTREAM FreeBSD ports + our overlay (FreeSense-org/freesense-ports);
#    NO dependency on pfSense's freebsd-ports fork.
#  - Signing uses the CI key (FREESENSE_REPO_SIGNING_KEY); its fingerprint already ships
#    in the OS source (src/.../keys/pkg/trusted/freesense).
#
# Usage:
#   env FREESENSE_SIGN_KEY=/path/to/repo.key sh provision-buildhost.sh
# or place the private key at /root/sign/repo.key beforehand.
#
# Idempotent-ish: safe to re-run; clones are refreshed, builds are resumable.
set -eu

# ---- config (override via env) ----
FREESENSE_REPO="${FREESENSE_REPO:-https://github.com/FreeSense-org/freesense.git}"
PORTS_OVERLAY_REPO="${PORTS_OVERLAY_REPO:-https://github.com/FreeSense-org/freesense-ports.git}"
FREEBSD_PORTS_URL="${FREEBSD_PORTS_URL:-https://github.com/freebsd/freebsd-ports.git}"
SRC_DIR="${SRC_DIR:-/root/freesense-src}"          # the single rebranded tree (framework+source)
OVERLAY_DIR="${OVERLAY_DIR:-/root/freesense-ports}" # our ports overlay clone
POUDRIERE_PORTS_NAME="${POUDRIERE_PORTS_NAME:-FreeSense_devel}"
JAIL_NAME="${JAIL_NAME:-FreeSense_master_amd64}"
SIGN_DIR="${SIGN_DIR:-/root/sign}"
FREESENSE_SIGN_KEY="${FREESENSE_SIGN_KEY:-}"        # path to the CI private key to install

log() { echo ">>> [provision] $*"; }

# ---- 1. swap (buildworld is memory-hungry; add swap if low) ----
if ! swapinfo 2>/dev/null | grep -q swapfile; then
	if [ "$(sysctl -n hw.physmem)" -lt 34359738368 ]; then  # <32G RAM
		log "adding 16G swapfile"
		dd if=/dev/zero of=/swapfile bs=1m count=16384 status=none
		chmod 0600 /swapfile
		grep -q '^swapfile=' /etc/rc.conf || echo 'swapfile="/swapfile"' >> /etc/rc.conf
		u=$(mdconfig -a -t vnode -f /swapfile); swapon /dev/$u || true
	fi
fi

# ---- 2. toolchain ----
log "installing build toolchain"
export ASSUME_ALWAYS_YES=yes
pkg bootstrap -y >/dev/null 2>&1 || true
pkg update -q
pkg install -y git nginx poudriere-devel rsync sudo curl gtar xmlstarlet pkgconf bash \
	vmdktool screen python3 openssl

# ---- 3. signing key ----
log "setting up signing key in ${SIGN_DIR}"
mkdir -p "${SIGN_DIR}"
if [ -n "${FREESENSE_SIGN_KEY}" ] && [ -f "${FREESENSE_SIGN_KEY}" ]; then
	cp "${FREESENSE_SIGN_KEY}" "${SIGN_DIR}/repo.key"
fi
if [ ! -f "${SIGN_DIR}/repo.key" ]; then
	echo "!!! ERROR: no signing key at ${SIGN_DIR}/repo.key and FREESENSE_SIGN_KEY not given." >&2
	echo "    Provide the CI private key (fingerprint must match src/.../keys/pkg/trusted/freesense)." >&2
	exit 1
fi
chmod 0400 "${SIGN_DIR}/repo.key"
openssl rsa -in "${SIGN_DIR}/repo.key" -pubout -out "${SIGN_DIR}/repo.pub" 2>/dev/null
printf 'function: sha256\nfingerprint: "%s"\n' "$(sha256 -q ${SIGN_DIR}/repo.pub)" > "${SIGN_DIR}/fingerprint"
log "signing fingerprint: $(awk -F'\"' '/fingerprint/{print $2}' ${SIGN_DIR}/fingerprint)"
# standard pkg-repo signing wrapper
fetch -qo "${SIGN_DIR}/sign.sh" https://raw.githubusercontent.com/freebsd/pkg/master/scripts/sign.sh
sed -i '' "s+ repo\.+ ${SIGN_DIR}/repo.+g" "${SIGN_DIR}/sign.sh"
chmod +x "${SIGN_DIR}/sign.sh"
# sanity: does the key's fingerprint match what the OS source ships as trusted?
_trusted="${SRC_DIR}/src/usr/local/share/FreeSense/keys/pkg/trusted/freesense"

# ---- 4. clone the FreeSense source+framework tree (single rebranded tree) ----
log "cloning FreeSense repo -> ${SRC_DIR}"
if [ -d "${SRC_DIR}/.git" ]; then
	git -C "${SRC_DIR}" fetch --depth 1 origin && git -C "${SRC_DIR}" reset --hard origin/main
else
	rm -rf "${SRC_DIR}"
	git clone --depth 1 "${FREESENSE_REPO}" "${SRC_DIR}"
fi
# write build.conf from the tracked sample (build.conf is gitignored)
cp "${SRC_DIR}/build.conf.sample" "${SRC_DIR}/build.conf"
# fix SRCCONF's leading path to the actual SRC_DIR (sample hardcodes /root/freesense-src)
sed -i '' "s,^export SRCCONF=\"/root/freesense-src/,export SRCCONF=\"${SRC_DIR}/," "${SRC_DIR}/build.conf"
# point poudriere at UPSTREAM FreeBSD ports (NOT pfSense's fork) + our overlay name
{
	echo "export POUDRIERE_PORTS_GIT_URL=\"${FREEBSD_PORTS_URL}\""
	echo "export POUDRIERE_PORTS_NAME=\"${POUDRIERE_PORTS_NAME}\""
} >> "${SRC_DIR}/build.conf"

# verify trusted fingerprint matches our signing key
if [ -f "${_trusted}" ]; then
	_kfp="$(awk -F'\"' '/fingerprint/{print $2}' ${SIGN_DIR}/fingerprint)"
	_tfp="$(awk -F'\"' '/fingerprint/{print $2}' ${_trusted})"
	if [ "${_kfp}" != "${_tfp}" ]; then
		echo "!!! WARNING: signing key fingerprint (${_kfp}) != OS trusted fingerprint (${_tfp})." >&2
		echo "    Installed systems will REJECT packages signed by this key. Fix before publishing." >&2
	else
		log "signing key matches OS trusted fingerprint (${_kfp}) — trust chain OK"
	fi
fi

# ---- 5. clone the ports overlay ----
log "cloning ports overlay -> ${OVERLAY_DIR}"
if [ -d "${OVERLAY_DIR}/.git" ]; then
	git -C "${OVERLAY_DIR}" fetch --depth 1 origin && git -C "${OVERLAY_DIR}" reset --hard origin/main
else
	rm -rf "${OVERLAY_DIR}"
	git clone --depth 1 "${PORTS_OVERLAY_REPO}" "${OVERLAY_DIR}"
fi

# ---- 6. local chroot-install repo server (the :8081 the build's freesense-localrepo.sh expects) ----
# freesense-localrepo.sh writes a chroot repo.conf pointing at http://127.0.0.1:8081/{core,bulk}.
# poudriere publishes its built repo under /usr/local/poudriere/data/packages; we serve that.
log "installing the 127.0.0.1:8081 chroot-install repo server (rc.d service)"
install -d /tmp/freesense-repos
cat > /usr/local/etc/rc.d/freesense_localrepo <<'RC'
#!/bin/sh
# PROVIDE: freesense_localrepo
# REQUIRE: NETWORKING
# KEYWORD: shutdown
. /etc/rc.subr
name=freesense_localrepo
rcvar=freesense_localrepo_enable
pidfile="/var/run/${name}.pid"
command="/usr/sbin/daemon"
command_args="-p ${pidfile} /usr/local/bin/python3 -m http.server 8081 --bind 127.0.0.1 --directory /tmp/freesense-repos"
load_rc_config $name
: ${freesense_localrepo_enable:=no}
run_rc_command "$1"
RC
chmod +x /usr/local/etc/rc.d/freesense_localrepo
sysrc freesense_localrepo_enable=YES >/dev/null
service freesense_localrepo restart || service freesense_localrepo start || true

# ---- 7. poudriere dashboard (nginx, optional — watch builds at http://<host>/) ----
log "configuring poudriere nginx dashboard"
cat > /usr/local/etc/nginx/nginx.conf <<'NGINX'
worker_processes 1;
events { worker_connections 1024; }
http {
    include       mime.types;
    default_type  application/octet-stream;
    sendfile on;
    server {
        listen 80 default_server;
        server_name _;
        root /usr/local/share/poudriere/html;
        index index.html;
        location /data { alias /usr/local/poudriere/data/logs/bulk; autoindex on; }
        location /packages { alias /usr/local/poudriere/data/packages; autoindex on; }
    }
}
NGINX
sysrc nginx_enable=YES >/dev/null
service nginx restart 2>/dev/null || service nginx start 2>/dev/null || true

log "PROVISION_BASE_DONE."
log "Next (long, unattended): cd ${SRC_DIR} && nohup ./build.sh --setup-poudriere ... (see run-build.sh)"
