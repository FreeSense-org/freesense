#!/bin/sh
#
# ping_hosts.sh
#
# part of FreeSense (https://www.freesense.org)
# Copyright (c) 2006-2013 BSD Perimeter
# Copyright (c) 2013-2016 Electric Sheep Fencing
# Copyright (c) 2014-2026 Rubicon Communications, LLC (Netgate)
# Copyright (c) 2025-2026 The FreeSense Project
# All rights reserved.
#
# Licensed under the Apache License, Version 2.0 (the "License");
# you may not use this file except in compliance with the License.
# You may obtain a copy of the License at
#
# http://www.apache.org/licenses/LICENSE-2.0
#
# Unless required by applicable law or agreed to in writing, software
# distributed under the License is distributed on an "AS IS" BASIS,
# WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
# See the License for the specific language governing permissions and
# limitations under the License.

# Format of file should be delimited by |
#  Field 1:  Source IP
#  Field 2:  Destination IP
#  Field 3:  Ping count
#  Fields 4-7: no longer used; the field positions are kept
#  Field 8:  Address family

# Read in ipsec ping hosts and check the CARP status
# Only perform this check if there are IPsec hosts to ping, see
#   upstream issue 8172
if [ -s /var/db/ipsecpinghosts ]; then
	IPSECHOSTS="/var/db/ipsecpinghosts"
	CURRENTIPSECHOSTS="/var/db/currentipsecpinghosts"
	if [ "$( ifconfig "$IFVPN" | grep -c "carp: BACKUP vhid" )" -gt 1 ]; then
		echo "CARP interface in BACKUP (not pinging ipsec hosts)"
		rm -f "$CURRENTIPSECHOSTS"
		touch "$CURRENTIPSECHOSTS"
	else
		echo "CARP interface is MASTER or non CARP (pinging ipsec hosts)"
		cat < "$IPSECHOSTS" > "$CURRENTIPSECHOSTS"
	fi
fi

# General file meant for user consumption
if [ -f /var/db/hosts ]; then
	HOSTS="/var/db/hosts"
fi

# Package specific ping requests
if [ -f /var/db/pkgpinghosts ]; then
	PKGHOSTS="/var/db/pkgpinghosts"
fi

# Make sure at least one of these has contents, otherwise cat will be stuck waiting on input
if [ -n "${PKGHOSTS}" ] || [ -n "${HOSTS}" ] || [ -n "${CURRENTIPSECHOSTS}" ]; then
	# A fixed name in /tmp could be a file another user planted, so this is a
	# private temporary file.
	TMPHOSTS=$(/usr/bin/mktemp /var/run/pinghosts.XXXXXX) || exit 1
	trap '/bin/rm -f "${TMPHOSTS}"' EXIT
	eval cat "$PKGHOSTS" "$HOSTS" "$CURRENTIPSECHOSTS" >"${TMPHOSTS}"
else
	# Nothing to do!
	exit
fi

if [ ! -d /var/db/pingstatus ]; then
	/bin/mkdir -p /var/db/pingstatus
fi

if [ ! -d /var/db/pingmsstatus ]; then
	/bin/mkdir -p /var/db/pingmsstatus
fi

PINGHOSTS=`cat "${TMPHOSTS}"`

if [ "$( wc -l < "${TMPHOSTS}" )" -lt "1" ]; then
	exit
fi

for TOPING in $PINGHOSTS ; do
	echo "PROCESSING $TOPING"
	SRCIP=`echo "$TOPING" | cut -d"|" -f1`
	DSTIP=`echo "$TOPING" | cut -d"|" -f2`
	COUNT=`echo "$TOPING" | cut -d"|" -f3`
	AF=`echo "$TOPING" | cut -d"|" -f8`
	if [ "$AF" = "inet6" ]; then
		PINGCMD=ping6
	else
		PINGCMD=ping
	fi
	echo "Processing $DSTIP"
	# Look for a service being down
	# Read in previous status
	PREVIOUSSTATUS=""
	if [ -f "/var/db/pingstatus/${DSTIP}" ]; then
		PREVIOUSSTATUS=`cat "/var/db/pingstatus/$DSTIP"`
	fi
	if $PINGCMD -c "$COUNT" -S "$SRCIP" "$DSTIP"; then
		# Host is up
		if [ "$PREVIOUSSTATUS" != "UP" ]; then
			# Service restored
			echo "UP" > "/var/db/pingstatus/$DSTIP"
		fi
	else
		# Host is down
		if [ "$PREVIOUSSTATUS" != "DOWN" ]; then
			# Service is down
			echo "DOWN" > "/var/db/pingstatus/$DSTIP"
		fi
	fi
	echo "Checking ping time $DSTIP"
	# Look at ping values themselves
	PINGTIME=`$PINGCMD -c 1 -S "$SRCIP" "$DSTIP" | awk '{ print $7 }' | grep time | cut -d "=" -f2`
	echo "Ping returned $?"
	echo "$PINGTIME" > "/var/db/pingmsstatus/$DSTIP"
	sleep 1
done

exit 0
