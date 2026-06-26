if [ "${PRODUCT_NAME}" != "pfSense" ]; then
  for f in ${FREEBSD_SRC_DIR}/release/conf/pfSense* ${FREEBSD_SRC_DIR}/sys/amd64/conf/pfSense*; do
    [ -e "$f" ] || continue
    nf=$(echo "$f" | sed "s,/pfSense,/${PRODUCT_NAME},")
    mv "$f" "$nf"
    sed -i '' "s,pfSense,${PRODUCT_NAME},g" "$nf" 2>/dev/null || true
  done
  echo ">>> FreeSense: renamed FreeBSD release+kernel confs to ${PRODUCT_NAME}"
  # rebrand bsdinstall installer chrome (backtitle/menu/copyright) pfSense -> FreeSense
  [ -x /root/fs-rebrand-installer.sh ] && /root/fs-rebrand-installer.sh "${FREEBSD_SRC_DIR}/usr.sbin/bsdinstall/startbsdinstall"
fi
