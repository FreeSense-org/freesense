# FreeSense

> **FreeSense** is a community rebuild and rebrand of the open-source
> [pfSense® CE](https://github.com/pfsense/pfsense) firewall distribution,
> built from source on FreeBSD and released under the Apache License 2.0.
> It is **not** pfSense, is **not** affiliated with or endorsed by Netgate or
> Electric Sheep Fencing, LLC, and does **not** use the pfSense® trademark as
> its product name.

## Overview

FreeSense is a free network firewall distribution based on the FreeBSD operating
system with a custom kernel, including third-party free software packages for
additional functionality. It provides a web interface for configuring all
included components.

FreeSense is derived from the pfSense CE source tree. The goal of this project is
a fully self-buildable, cleanly rebranded firewall distribution that can be built
outside of the original vendor's build infrastructure.

## Attribution & License

FreeSense is a derivative work of **pfSense® CE**, which is
Copyright 2004–2026 [Rubicon Communications, LLC (Netgate)](https://www.netgate.com/)
and was originally published under the Apache License, Version 2.0.

This project remains under the **Apache License 2.0** (see [`LICENSE`](LICENSE)).
In accordance with that license, the original copyright notices are retained and
modifications relative to upstream pfSense are documented in [`NOTICE`](NOTICE).

"pfSense" is a registered trademark of Electric Sheep Fencing, LLC, licensed to
Netgate. FreeSense uses the pfSense source code under Apache 2.0 but does not use
the pfSense name or marks as its own branding. Any remaining "pfSense" strings in
this tree are an in-progress rebrand and will be removed.

## Status

Work in progress. The distribution builds from source and boots to a working
installer and web GUI; the rebrand (removing all upstream branding from strings,
assets, namespaces, and boot artifacts) is ongoing.
