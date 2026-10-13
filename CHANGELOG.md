# Changelog

Every release is a `vX.Y.Z` tag whose `VERSION` says the same; servers install it on their next
update (*Administration → System*, or every night). What a release needs done as root on every
server is in `installer/upgrades/<version>.sh`.

## 1.1.0

### Tools
- **SSH terminal** (*Tools → SSH terminal*, administrators and developers): saved connections with
  favourites, any number of terminals at once (tabs or side by side), Ed25519 and RSA keys generated
  or imported (OpenSSH, PEM, PuTTY, with passphrase), passwords, host keys accepted on first use and
  refused when they change. A connection tied to a project shows up for its administrators and
  developers, who connect with a key or password of their own: credentials are never shared.
  Runs through a new service, `studio-ssh` (`ssh-gateway/`), behind Caddy at `/ssh/ws`.
- **Password manager** (*Tools → Password manager*): nested folders, items encrypted (username,
  password, notes, TOTP with the live code), a password generator. An item is tied to a project for
  the roles ticked (client, PM, developer) and shared with single people, to view or to edit; a
  table shows who sees it and why.
- **Drive** (*Tools → Drive*): folders and files, upload by button or drag and drop with progress.
  A folder tied to a project opens by role, to view or to view and edit, down the tree;
  administrators see everything, clients create nothing. *Copy / Move into the workspace* sends
  files, a selection or whole folders into the person's workspace of that project.

### Claude
- **Sign in with Claude**: a Pro or Max subscription is the person's own `claude auth login` of the
  unmodified Claude Code, in their own home on the server (`/home/ws-<handle>`, shared by all their
  workspaces). Studio never sees, stores or forwards it, as Anthropic's terms require of a platform
  that hosts Claude Code. Stored `setup-token`s are dropped: people sign in again once.
- **One account or the other**, chosen in *Settings → Connections*: the subscription or an API key.
  Signing in replaces a key, saving a key signs the subscription out on the server. An API key is kept
  apart from Claude Code's own variable and handed over by the bridge; with the subscription no key
  is left in the workspace.

### Everywhere
- **Public uuids**: links, buttons, pickers and broadcast channels carry uuids, never sequential ids.
  Old links with a number (`/p/<project>/c/5`) no longer open.
- **Live pages**: the workspace badge follows the status (Reverb, and polling while it moves); the
  chat gets its messages live (the Echo event name was wrong: it only polled); the projects list,
  *Administration → Projects* and *Administration → System* refresh on their own; System follows an
  update through the maintenance of its switch and reloads onto the new release.
- **Chat status bar**: compact chips for the state, the Claude account (an API key stands out, with
  the estimated cost of the last turn; a subscription shows the plan's usage and when it resets, from
  Claude Code's own rate-limit events, never a cost), the model as people say it (*Default · Haiku 4.5*),
  the effort and the mode; a click opens *Model & mode*. The model a conversation chose is no longer
  overwritten by the one Claude Code reports.
- **Conversation dots** follow the conversations: the list on the left was drawn once and never
  moved. Amber and pulsing while Claude works, blue with a ring when it asks a question or a
  permission, red when it failed, green when it finished since you last opened it, grey otherwise.
- **Terminal tab**: colours (ANSI) instead of escape codes; git, Composer, artisan and the test tools
  are asked for colour; characters split across chunks arrive whole.

### Server
- **Nightly server update** (`studio-update system`, an hour before Studio's own update, or
  *Administration → System → Update the server now*): every package and tool to its newest version,
  `apt full-upgrade` (Ubuntu, every PHP and its extensions, Caddy, MariaDB, PostgreSQL, redis, Node
  within its major, the provider's CLI), Composer, Claude Code, glab; then needrestart, Studio's
  services and its health check. The System page lists what changed and whether a new kernel needs a
  reboot; `SYSTEM_REBOOT=1` in `/etc/studio/studio.env` reboots by itself. A switch turns it off.
- **TLS certificates** are checked in the same run (`studio-update certificates` alone): one Caddy left
  late is renewed on the spot, one it could not renew shows up on the System page.

### Upgrading from 1.0
Nothing to do by hand: the update installs the SSH gateway and its Caddy route, migrates, moves the
workspaces' Claude variables and restarts their bridges (`installer/upgrades/1.1.0.sh`). Whoever used
a subscription signs in again in *Settings → Connections*.

## 1.0.0

First release.
