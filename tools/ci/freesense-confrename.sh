if [ "${PRODUCT_NAME}" != "pfSense" ]; then
  for f in ${FREEBSD_SRC_DIR}/release/conf/pfSense* ${FREEBSD_SRC_DIR}/sys/amd64/conf/pfSense*; do
    [ -e "$f" ] || continue
    nf=$(echo "$f" | sed "s,/pfSense,/${PRODUCT_NAME},")
    mv "$f" "$nf"
    sed -i '' "s,pfSense,${PRODUCT_NAME},g" "$nf" 2>/dev/null || true
  done
  echo ">>> FreeSense: renamed FreeBSD release+kernel confs to ${PRODUCT_NAME}"
  # rebrand bsdinstall installer chrome (backtitle/menu/copyright) pfSense -> FreeSense
  [ -x ${BUILDER_TOOLS}/ci/fs-rebrand-installer.sh ] && ${BUILDER_TOOLS}/ci/fs-rebrand-installer.sh "${FREEBSD_SRC_DIR}/usr.sbin/bsdinstall/startbsdinstall"
  # CRITICAL boot hook: pfSense's freebsd-src patches /etc/rc to `. /etc/pfSense-rc; exit 0`
  # so the product's rc hijacks FreeBSD boot. We renamed that script to ${PRODUCT_NAME}-rc,
  # so the hook in freebsd-src must match or the box boots raw FreeBSD (Amnesiac, no console
  # menu, no GUI). Re-point the hook each build (freebsd-src is re-fetched every run).
  for rcf in ${FREEBSD_SRC_DIR}/etc/rc ${FREEBSD_SRC_DIR}/etc/rc.shutdown; do
    [ -e "$rcf" ] && sed -i '' "s,/etc/pfSense-rc,/etc/${PRODUCT_NAME}-rc,g" "$rcf" 2>/dev/null || true
  done
  echo ">>> FreeSense: re-pointed freebsd-src /etc/rc boot hook to /etc/${PRODUCT_NAME}-rc"
fi
