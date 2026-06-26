mkdir -p ${STAGE_CHROOT_DIR}/tmp/pkg/pkg-repos ${STAGE_CHROOT_DIR}/etc
cat > ${STAGE_CHROOT_DIR}/tmp/pkg/pkg-repos/repo.conf <<EOF
FreeBSD: { enabled: no }
FreeBSD-kmods: { enabled: no }
FreeSense-core: { url: "http://127.0.0.1:8081/core", enabled: yes, signature_type: "none", priority: 10 }
FreeSense: { url: "http://127.0.0.1:8081/bulk", enabled: yes, signature_type: "none", priority: 5 }
EOF
# seed a nobody user so pkg can drop privileges (chroot starts ~empty; base pkg replaces these)
printf 'root:*:0:0::0:0:Charlie &:/root:/bin/sh\nnobody:*:65534:65534::0:0:Unprivileged user:/nonexistent:/usr/sbin/nologin\n' > ${STAGE_CHROOT_DIR}/etc/master.passwd
printf 'wheel:*:0:root\nnobody:*:65534:\nnogroup:*:65533:\n' > ${STAGE_CHROOT_DIR}/etc/group
pwd_mkdb -p -d ${STAGE_CHROOT_DIR}/etc ${STAGE_CHROOT_DIR}/etc/master.passwd 2>/dev/null
echo ">>> FreeSense: chroot local repos + nobody user seeded"
