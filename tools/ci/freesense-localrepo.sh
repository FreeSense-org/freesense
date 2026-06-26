mkdir -p ${STAGE_CHROOT_DIR}/tmp/pkg/pkg-repos ${STAGE_CHROOT_DIR}/etc
cat > ${STAGE_CHROOT_DIR}/tmp/pkg/pkg-repos/repo.conf <<EOF
FreeBSD: { enabled: no }
FreeBSD-kmods: { enabled: no }
FreeSense-core: { url: "http://127.0.0.1:8081/core", enabled: yes, signature_type: "none", priority: 10 }
FreeSense: { url: "http://127.0.0.1:8081/bulk", enabled: yes, signature_type: "none", priority: 5 }
EOF

# Populate the :8081 docroot so the chroot can actually fetch packages. The repo.conf above
# points at /core (the base/kernel/rc/default-config built by build.sh into tmp/<jail>-core)
# and /bulk (the full signed poudriere repo). Symlink both into the docroot the
# freesense_localrepo http.server serves (/tmp/freesense-repos). Without this the docroot is
# empty -> 'Installing built ports in chroot... Failed!'.
_lr_docroot="${FREESENSE_LOCALREPO_DOCROOT:-/tmp/freesense-repos}"
_lr_core="${SCRATCHDIR}/${PRODUCT_NAME}_${POUDRIERE_BRANCH}_amd64-core"
_lr_bulk="/usr/local/poudriere/data/packages/$(poudriere_jail_name amd64 2>/dev/null || echo ${PRODUCT_NAME}_${POUDRIERE_BRANCH}_amd64)-${POUDRIERE_PORTS_NAME}"
mkdir -p "${_lr_docroot}"
# Point at the .latest subdir (modern pkg wants packagesite.PKG, which lives under
# .latest/.real_*; the repo top level only has the legacy .txz catalog). Fall back to
# the dir itself if there's no .latest.
[ -d "${_lr_core}/.latest" ] && ln -sfn "${_lr_core}/.latest" "${_lr_docroot}/core" || ln -sfn "${_lr_core}" "${_lr_docroot}/core"
[ -d "${_lr_bulk}/.latest" ] && ln -sfn "${_lr_bulk}/.latest" "${_lr_docroot}/bulk" || ln -sfn "${_lr_bulk}" "${_lr_docroot}/bulk"
# seed a nobody user so pkg can drop privileges (chroot starts ~empty; base pkg replaces these)
printf 'root:*:0:0::0:0:Charlie &:/root:/bin/sh\nnobody:*:65534:65534::0:0:Unprivileged user:/nonexistent:/usr/sbin/nologin\n' > ${STAGE_CHROOT_DIR}/etc/master.passwd
printf 'wheel:*:0:root\nnobody:*:65534:\nnogroup:*:65533:\n' > ${STAGE_CHROOT_DIR}/etc/group
pwd_mkdb -p -d ${STAGE_CHROOT_DIR}/etc ${STAGE_CHROOT_DIR}/etc/master.passwd 2>/dev/null
echo ">>> FreeSense: chroot local repos + nobody user seeded"
