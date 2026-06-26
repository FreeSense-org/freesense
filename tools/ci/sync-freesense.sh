#!/bin/sh
# Pull latest FreeSense source from GitHub, then refresh the build distfile.
set -e
git -C /root/freesense-src pull --ff-only
rm -f /usr/ports/distfiles/freesense-src.tar.gz
tar czf /usr/ports/distfiles/freesense-src.tar.gz -C /root --exclude='freesense-src/.git' freesense-src
echo "synced + re-tarred: $(ls -lh /usr/ports/distfiles/freesense-src.tar.gz)"
