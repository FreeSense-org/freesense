#!/usr/bin/env python3
"""
serial-inject-sshkey.py — inject an SSH public key into a booting FreeBSD VM over its
SERIAL CONSOLE, so the host never has to write the guest filesystem.

Why: the FreeBSD cloud image roots on ZFS/UFS that a Linux host often can't write
(no ZFS kernel module; UFS write is experimental/dangerous). Instead of mounting the
image, we boot the VM (BASIC-CLOUDINIT image: root has NO password, serial getty is
`onifconsole secure`, sshd_enable is preset) and drive the serial console: log in as
root, drop the key into /root/.ssh/authorized_keys, set PermitRootLogin, (re)start sshd.
The GUEST writes its own filesystem — works on any host, ZFS or UFS, no host deps.

Usage:
  serial-inject-sshkey.py <serial-device> <pubkey-file> [--timeout SECONDS]

<serial-device> is the pty/device qemu's `-serial` is connected to (e.g. the path
printed by `-serial pty` on stderr, or a socket-backed pty you created). The VM must
already be booting; this script only reads/writes that serial line — it does NOT own
the qemu process, so qemu (started with -daemonize) keeps running after this exits.

Exit 0 on success (key injected, sshd (re)started), non-zero otherwise.
"""
import os, sys, time, select, argparse, stat, socket

def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("serial", help="serial device path OR unix socket path")
    ap.add_argument("pubkey")
    ap.add_argument("--timeout", type=int, default=300,
                    help="seconds to wait for the login: prompt (default 300)")
    a = ap.parse_args()

    pub = open(a.pubkey).read().strip()
    if not pub.startswith(("ssh-", "ecdsa-", "sk-")):
        print(f"!! {a.pubkey} does not look like an SSH public key", file=sys.stderr)
        return 2

    # Serial can be a device/pty (open()) or a Unix socket (qemu -serial unix:...,server).
    use_sock = os.path.exists(a.serial) and stat.S_ISSOCK(os.stat(a.serial).st_mode)
    if use_sock:
        sk = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
        sk.connect(a.serial)
        sk.setblocking(False)
        _rfd = sk.fileno()
        def _read(n):
            return sk.recv(n)
        def _write(b):
            sk.sendall(b)
    else:
        fd = os.open(a.serial, os.O_RDWR | os.O_NOCTTY)
        os.set_blocking(fd, False)
        _rfd = fd
        def _read(n):
            return os.read(fd, n)
        def _write(b):
            os.write(fd, b)

    def rd(t=1.0):
        out = b""
        end = time.time() + t
        while time.time() < end:
            r, _, _ = select.select([_rfd], [], [], 0.3)
            if r:
                try:
                    c = _read(4096)
                except (BlockingIOError, OSError):
                    break
                if c:
                    out += c
        return out.decode("utf-8", "replace")

    def snd(s):
        _write(s.encode())

    # 1) wait for the login: prompt (nudge with newlines so a quiet getty wakes up)
    print(f">>> waiting up to {a.timeout}s for the serial login: prompt", flush=True)
    buf = ""
    end = time.time() + a.timeout
    seen = False
    while time.time() < end:
        buf += rd(3)
        if "login:" in buf[-400:]:
            seen = True
            break
        snd("\r")
    if not seen:
        print("!! never saw a login: prompt. last serial bytes:", file=sys.stderr)
        print(buf[-800:], file=sys.stderr)
        return 1

    # 2) log in as root (no password on the cloud image) and set a unique prompt
    print(">>> login: seen -> logging in as root", flush=True)
    snd("root\r"); time.sleep(2); rd(2)
    snd("PS1=INJ%%\r"); time.sleep(1); rd(2)

    # 3) inject the key + enable root key-login + (re)start sshd. Guest writes its own fs.
    pub_q = "'" + pub.replace("'", "'\\''") + "'"
    cmds = [
        "mkdir -p /root/.ssh",
        "chmod 700 /root/.ssh",
        f"printf '%s\\n' {pub_q} > /root/.ssh/authorized_keys",
        "chmod 600 /root/.ssh/authorized_keys",
        "grep -q '^PermitRootLogin prohibit-password' /etc/ssh/sshd_config || echo 'PermitRootLogin prohibit-password' >> /etc/ssh/sshd_config",
        "sysrc sshd_enable=YES",
        "service sshd onestart 2>/dev/null; service sshd start 2>/dev/null; service sshd restart 2>/dev/null; true",
        "echo INJDONE$?",
    ]
    for c in cmds:
        snd(c + "\r")
        time.sleep(1.5)
    out = rd(5)
    print(">>> shell output after inject:", flush=True)
    print(out[-600:], flush=True)

    if "INJDONE0" in out:
        print(">>> SSH key injected + sshd (re)started OK", flush=True)
        return 0
    print("!! did not see INJDONE0 — injection may have failed", file=sys.stderr)
    return 1

if __name__ == "__main__":
    sys.exit(main())
