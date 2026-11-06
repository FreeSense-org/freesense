#!/bin/sh -

TEXT=`/sbin/kldstat | /usr/bin/awk 'BEGIN {print "16i 0";} NR>1 {print toupper($4) "+"} END {print "p"}' | /usr/bin/dc`
# The Memory column is third from the end, because a type's name can contain spaces. FreeBSD 16
# prints it in bytes. Older releases printed kilobytes with a K suffix.
DATA=`/usr/bin/vmstat -m | /usr/bin/awk 'NR>1 { v = $(NF-2); if (v ~ /K$/) { sub(/K$/, "", v); v *= 1024 } total += v } END { printf "%d\n", total }'`
TOTAL=$((DATA + TEXT))

echo TEXT=$TEXT, `echo $TEXT | /usr/bin/awk '{print $1/1048576 " MB"}'`
echo DATA=$DATA, `echo $DATA | /usr/bin/awk '{print $1/1048576 " MB"}'`
echo TOTAL=$TOTAL, `echo $TOTAL | /usr/bin/awk '{print $1/1048576 " MB"}'`
