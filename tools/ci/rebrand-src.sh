#!/bin/sh
# Produce a FreeSense-rebranded copy of the pfSense source for the -system port.
set -e
SRC=/root/freesense-src
rm -rf "$SRC"
cp -a /root/pfsense "$SRC"
rm -rf "$SRC/.git"
echo "rebranding paths..."
# rename any file/dir whose NAME contains pfSense -> FreeSense (deepest first)
find "$SRC" -depth -name '*pfSense*' | while IFS= read -r p; do
  np=$(printf '%s\n' "$p" | sed 's,pfSense,FreeSense,g')
  [ "$p" != "$np" ] && mv "$p" "$np"
done
echo "rebranding contents of src/ text files..."
# substitute pfSense->FreeSense inside text files under src/ (the part -system packages)
find "$SRC/src" -type f | while IFS= read -r f; do
  if LC_ALL=C grep -Iq . "$f" 2>/dev/null; then
    sed -i '' 's,pfSense,FreeSense,g' "$f" 2>/dev/null || true
  fi
done
echo "verify: share dir renamed?"
ls -d "$SRC/src/usr/local/share/FreeSense" 2>/dev/null && echo "OK share/FreeSense" || echo "MISSING share/FreeSense"
echo "REBRAND_SRC_DONE"
