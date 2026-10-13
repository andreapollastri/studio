# Changelog

Every release is a `vX.Y.Z` tag whose `VERSION` says the same; servers install it on their next
update (*Administration → System*, or every night). What a release needs done as root on every
server is in `installer/upgrades/<version>.sh`.

## 1.2.0 (unreleased)

### The agent sees, delegates and works apart
- **A browser for the agent**: every chat gets Playwright MCP, a headless Chromium on the server,
  as the MCP server `browser`, signed in to the preview it works on with the person's own pass for
  that host (the same gatekeeper and access rules as a person). Claude opens pages, takes
  screenshots, reads the console and failed requests; looking never asks, clicking and typing ask
  unless edits are allowed. On by default, per project in *Project settings → Agent*.
- **Screenshots in the chat**: images in tool results (a browser screenshot, a picture Claude read)
  are kept on Studio's disk, shown as thumbnails under their tool call and full size on a click,
  served only to who may read the conversation, and removed with it.
- **Subagents as cards**: each subagent is a card with its status, steps, time, screenshots and
  report; those started together sit side by side. Studio adds two of its own (`code-reviewer`,
  and `browser-qa` with the browser) and a paragraph of context about the preview and the sandbox;
  the repository's `.claude/agents` still apply.
- **Conversations in a sandbox** (*New → Conversation in a sandbox*): a git worktree of the
  person's workspace on its own branch (`sandbox/<handle>-<id>`), with a copy of the workspace's
  database, its own build and preview host (`<handle>-<project>--c<id>.<domain>`). Several run at
  once without touching the workspace or each other; the Preview, Changes, Terminal and Files tabs
  follow the open conversation. *Merge into my workspace* commits and merges the branch (all or
  nothing), *Push the branch* pushes it for a pull request, *Remove* keeps the conversation to read.
  Queues run inline there, no scheduler or Reverb. Up to 3 per person by default.
- **A more readable Changes tab**: each file has an icon in the colour of its kind (PHP, Blade,
  JS/TS, CSS, config, docs, images, data, scripts…), its name in the colour of what happened to it
  (modified, new, deleted and struck through, renamed, in conflict) and the lines it adds and
  removes, with a five-block bar as on GitHub (needs the bridge of this release).

### Infrastructure
- **Connectors** for the clouds and services the servers run on, driven through their APIs, each
  with any number of accounts: **AWS** (EC2 instances, key pairs, security groups, Elastic IPs,
  EBS volumes, AMIs; every kind of Lightsail resource: instances, snapshots, static IPs, disks, key
  pairs, databases, load balancers, container services, buckets, DNS, distributions, certificates,
  alarms, operations; RDS, S3, Route 53, CloudFront, the cost of the month), **DigitalOcean**
  (Droplets, domains, firewalls, volumes, reserved IPs, load balancers, databases, App Platform,
  Kubernetes), **Hetzner** Cloud (servers, firewalls, volumes, IPs, networks, load balancers,
  snapshots, certificates, DNS), **Cloudflare** (zones, DNS, purge, SSL and security settings,
  Workers, Pages, R2, tunnels), **Cipi** servers (status, apps with deploy, rollback, SSL, aliases,
  `.env`, Artisan and logs, databases, services, SSH keys, PHP), **Boogle** (exceptions, projects,
  groups) and **Hackly** (posture, findings and triage, targets, repositories, scans, penetration
  tests).
- *Administration → Connectors* turns each one on. Connectors are for the administrators only:
  enabled ones show under *Infrastructure* in their menu, and no other role sees them. Administrators add the
  accounts, checked against the provider and stored encrypted; the pages ask for the password
  again, and every action is logged with who did it. AWS requests are signed with Signature
  Version 4 by Studio itself, no SDK. The docs (*Infrastructure*) say for each provider which token
  to create, where and with which permissions, and walk through the usual tasks. Nothing to do by
  hand on update: the migration runs with it and every connector starts off.

### Git hosting
- **Tools → GitHub, GitLab, Bitbucket, Azure DevOps**: a dashboard of what waits for you on the
  git hosts (pull requests asking for your review, your own, issues and work items assigned to
  you, notifications and to-dos) and every repository with its pull requests, issues, branches,
  pipelines (Actions, GitLab CI, Bitbucket Pipelines, Azure Pipelines) and releases. Read a pull
  request with its conversation and its diff, comment, approve or request changes, ask for
  reviewers, merge (merge commit, squash, rebase; GitLab auto-merge, Azure auto-complete), close
  and reopen; create repositories, branches, pull requests, issues and releases; start, retry and
  cancel pipelines. Titles open the provider in a new tab.
- **Personal accounts**: everyone adds their own, as many as they work with and on any host,
  each with the name and email its commits carry (from the provider when left empty). Nobody
  else sees them. The token saved in *Settings → Connections* becomes the first account on the
  installation's provider, which is on from the start; *Administration → Connectors → Git
  hosting* turns the others on.
- Lists that follow a provider's "next page" link no longer read the first page again (Boogle,
  DigitalOcean, Hackly).

### Accounts and groups
- **Projects on any git host that is on**: the project form picks one of the administrator's git
  accounts (the address typed in chooses the one on its host); that account creates or reads the
  repository, registers the push webhook and deploys the site. Webhooks are verified by the host of
  each project's repository. `studio:git-provider` now sets the default host and keeps everybody's
  accounts, with projects too.
- **The git identity follows the account**: a workspace clones, commits and pushes with the
  person's account on the host of its repository (a client's GitLab, the company's GitHub, a
  personal one) and its name and email in the clone's `git config`; the agent's CLI gets that
  token, and the CLI of a host other than the installation's is installed the first time it is
  needed.
- **Several Claude accounts per person**: subscriptions (each signed in with Claude Code's own
  sign-in in a folder of its own in the person's home, sharing sessions, agents and skills) and
  API keys, side by side in *Settings → Connections*, one of them the default; the plan's usage is
  kept per account. The account goes with every message, so a change needs no restart, and an API
  key is no longer left in the workspace.
- **Groups of projects**, each person's own (Personal, Open source, Work, Clients…), in the order
  they give them: a group picks the Claude account and the git account of its projects (a git
  account only where the repository lives on its host), a project may pick others. Every card says
  what the project runs on and why; changing a git account restarts only the workspaces it moves.
- The account a person had in *Settings → Connections* becomes their first Claude account and
  their default; their git token, their first git account (see Git hosting). Their handle no
  longer depends on a git login: it is given once, from the first git account or the e-mail.

### Server
- The system step installs Playwright MCP (pinned in `requirements.env`) and its Chromium in
  `/usr/local/lib/studio/browsers`, with an AppArmor profile so Chromium keeps its sandbox on
  Ubuntu 24.04+. New helper commands `sandbox-create` and `sandbox-delete`; `workspace-delete`
  removes the workspace's sandboxes. `installer/upgrades/1.2.0.sh` gives every bridge its new
  variables and restarts it.
- The helper's `claude-status`, `claude-login` and `claude-logout` take the folder of a Claude
  account; new `claude-forget` (removes one, never following its links) and `git-cli <provider>`;
  `workspace-env` also re-applies the commit identity. Claude Code's managed settings deny reading
  `~/.claude-*` too (patched in place on existing servers).

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
  the estimated cost of the last turn; a subscription shows the plan's usage as `/usage` does, the
  five-hour window and the week in percent with their resets, never a cost), the model as people say it (*Default · Haiku 4.5*),
  the effort and the mode; a click opens *Model & mode*. The model a conversation chose is no longer
  overwritten by the one Claude Code reports.
  The percentages are asked of Claude Code after a turn (`get_usage`, at most once a minute per
  conversation), which reads them with its own credentials; its rate-limit events alone carry a
  percentage only near a threshold.
- **Conversation dots** follow the conversations: the list on the left was drawn once and never
  moved. Amber and pulsing while Claude works, blue with a ring when it asks a question or a
  permission, red when it failed, green when it finished since you last opened it, grey otherwise.
- **Delete a conversation**: the bin next to it in the list, after a confirmation that names it. Its
  messages go with it; a Claude Code still running for it is stopped (the bridge's new
  `DELETE /conversations/{id}`). Only its owner can: not even an administrator.
- **Phones and tablets**: the project opens one panel at a time on a phone (*Conversations*, *Chat*,
  *Workspace* in a tab bar), and on a tablet the workspace pane (preview, changes, terminal, files)
  slides over the chat instead of being missing below 1280px. Tables become cards on a phone, the
  controls that appeared on hover stay visible on touch screens, the SSH terminal gets a key bar (Esc,
  Tab, Ctrl, arrows…). The desktop layout is unchanged.
- **Terminal tab**: colours (ANSI) instead of escape codes; git, Composer, artisan and the test tools
  are asked for colour; characters split across chunks arrive whole.

### Server
- **Nightly server update** (`studio-update system`, an hour before Studio's own update, or
  *Administration → System → Update the server now*): every package and tool to its newest version,
  `apt full-upgrade` (Ubuntu, every PHP and its extensions, Caddy, MariaDB, PostgreSQL, redis, Node
  within its major, the provider's CLI), Composer, Claude Code, glab; then needrestart, Studio's
  services and its health check. The System page lists what changed and whether a new kernel needs a
  reboot; `SYSTEM_REBOOT=1` in `/etc/studio/studio.env` reboots by itself. A switch turns it off.
- **Project timezone** (*Administration → Projects → Settings → Server*): `APP_TIMEZONE` of the site
  and of every preview, `TZ` of the workspaces (Claude and the terminal), UTC by default. Repositories
  Studio creates read it in `config/app.php`; an existing one needs `env('APP_TIMEZONE', 'UTC')` there.
- **TLS certificates** are checked in the same run (`studio-update certificates` alone): one Caddy left
  late is renewed on the spot, one it could not renew shows up on the System page.

### Upgrading from 1.0
Nothing to do by hand: the update installs the SSH gateway and its Caddy route, migrates, moves the
workspaces' Claude variables and restarts their bridges (`installer/upgrades/1.1.0.sh`). Whoever used
a subscription signs in again in *Settings → Connections*.

## 1.0.0

First release.
