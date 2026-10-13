# Studio

One Ubuntu server, one script. A self-hosted web app that gives a product team a human interface
over Laravel workspaces that live on that server: a chat with the agent, the preview of your branch,
the changes, the backlog, approvals. Each conversation is **the person's own Claude Code** running
inside their workspace with the Larapilot skills of the project. Nothing to install on a laptop but
a browser.

**Version 1.1** · Laravel 13 · Livewire 4 · Flux · Fortify (2FA, passkeys) · Reverb · Caddy · PHP 8.3/8.4/8.5 · MariaDB and PostgreSQL.

**Documentation:** [studio.web.ap.it](https://studio.web.ap.it) — the static site in `docs/`
(Netlify publishes the `docs/` folder as it is, see `netlify.toml`).

## Install on a VPS

```bash
wget -qO- https://raw.githubusercontent.com/andreapollastri/studio/refs/heads/main/installer/install.sh | sudo bash
```

Ubuntu 24.04 or 26.04 LTS (on 26.04, until `ppa:ondrej/php` serves it, PHP comes from Ubuntu's archive:
8.5 only). apt's output goes to `/var/log/studio-install.log`; an installation that stopped halfway starts
again from the questions when the script is run again. The wizard asks for the domain, shows the one DNS record to create
(`*.<domain>` → the server), checks it, asks for the first administrator (name and email: the password is
generated and shown, in a yellow box to save, in the summary and at the end), the git provider and the
administrator's token there, then installs the newest release of Studio, the latest published `vX.Y.Z` tag
(the head of `main` only while no release exists), with everything it needs and serves it at
`https://studio.<domain>`. Beta testers install the head
of `main` instead and keep following it: `… | sudo STUDIO_CHANNEL=beta bash`.

What lives where on the server:

| Host | What |
| --- | --- |
| `studio.<domain>` | this app |
| `<project>.<domain>` | the deploy branch of a project, redeployed on every push by the git provider's webhook |
| `<user>-<project>.<domain>` | one person's workspace: their clone, their database, their Claude Code |

**Any git host, everyone's own accounts.** Projects live on GitHub, GitLab (gitlab.com or self-managed),
Bitbucket Cloud or Azure DevOps, the same remote forges Larapilot integrates with; the installer picks the default
one (`php artisan studio:git-provider` changes it), *Administration → Connectors* turns the others on. A project is
an existing repository, or one Studio creates on the spot with the latest Laravel, Laravel Boost and Larapilot, with
one of the creating administrator's accounts, which also registers the webhook and deploys the site. People sign in
with email and password (plus two-factor), then bring their own accounts, as many as they work with:

- **Git** (*Tools → GitHub, GitLab…*): a personal GitHub, the company's Azure DevOps, a client's GitLab, each with the
  name and email its commits carry. A workspace clones, commits and pushes as the account on the host of its
  repository, with that identity (`git config` in the clone), and the agent's CLI (gh, glab, az) gets that token.
- **Claude** (*Settings → Connections*): subscriptions and Anthropic API keys side by side. *Sign in with Claude* runs
  Claude Code's official `claude auth login` in the person's own home on the server, in a folder of its own for each
  subscription (`~/.claude`, `~/.claude-<slug>`, sharing the sessions, agents and skills), so a Pro, Max or Team plan is
  used the way Anthropic allows on hosted platforms and Studio never holds it; a key is stored encrypted and goes with
  each message. One account is the default.
- **Groups** (*Projects*): each person puts their projects in groups of their own (Personal, Open source, Work,
  Clients…). A group picks the Claude account and the git account its projects use; a project may pick others. Each
  card shows what it runs on and why. A Claude change applies to the next message; a git change restarts only the
  workspaces whose account moves.

**Updates.** `studio-update` (root) installs every new build of its channel by itself, nightly or from
*Administration → System*: **stable** follows the release tags, **beta** (beta tester mode) the head of `main`;
neither goes backwards, and back on stable a main build stays until a release contains it. *Force update*
builds and installs the channel's newest build again even when it is installed. Each update installs what
the build needs on the server (PHP versions, Node, packages, the provider's CLI, from
`installer/requirements.env` and `installer/upgrades/`), builds it next to the running one, dumps the
database, migrates, switches `/opt/studio/current`, checks `/up`, and puts the previous release and the
database back when a step fails. Signed tags (and, on beta, signed commits) are enforced when
`/etc/studio/update-signers` exists. Releasing is: set `VERSION`, push a `vX.Y.Z` tag.

**Server updates.** An hour before, `studio-update system` brings every package and tool on the server to its
newest version, every night or from *Administration → System*: `apt full-upgrade` (Ubuntu, every PHP and its
extensions, Caddy, MariaDB, PostgreSQL, redis, Node within its major, the provider's CLI), Composer, Claude Code,
glab; then needrestart, Studio's services and its health check, and every TLS certificate: Caddy renews them by
itself, the check renews one it left late and lists one it could not renew. The page lists what changed and whether a new
kernel needs a reboot (`SYSTEM_REBOOT=1` in `/etc/studio/studio.env` reboots by itself). A new Ubuntu release or
a new major of a database stays a decision for a person.

Inside a conversation the chat is the full Claude Code CLI: subagents, plan mode, skills and slash commands,
MCP servers from the repository, hooks. Studio adds a browser signed in to the preview (screenshots show in the
chat), subagent cards with `code-reviewer` and `browser-qa`, and conversations in a sandbox of their own
(worktree, branch, database and preview) that run side by side and merge back into the workspace. *Model & mode* picks the model, the effort and the permission mode
(ask, ask with edits, autopilot, plan only); the *Terminal* tab runs allowlisted commands in the workspace; the *Files* tab is a file manager with the editor of VS Code; the *Changes* tab shows the working tree, the commit graph and the branches. Project settings switch the scheduler, queue workers and Reverb per project, narrow who can open its hosts (every address of a project is behind the Studio login and the project assignment, in any role; address lists per site, previews and branch), and add remote MCP servers to the project's chats.

**Infrastructure.** Studio also drives the clouds and services the team runs its servers on, through their APIs:
AWS (EC2, every kind of Lightsail resource, RDS, S3, Route 53, CloudFront), DigitalOcean, Hetzner Cloud, Cloudflare,
[Cipi](https://cipi.sh) servers, [Boogle](https://boogle.web.ap.it) and [Hackly](https://hackly.web.ap.it). It is for
administrators only: no other role sees the menu, the pages or the accounts.

1. *Administration → Connectors*: switch on the connectors the team uses. Only those show in the menu, under
   *Infrastructure*; turning one off hides it again and keeps its accounts.
2. On the connector's page, *Accounts → Add an account*: a name and the credentials (an IAM access key, a team or project
   token, the address and token of a Cipi server or of a Boogle or Hackly instance). Studio checks them against the
   provider before saving and stores them encrypted; any number of accounts per connector.
3. Pick the account at the top and a tab: lists with search and filters (the region for AWS), a button to create, a menu
   of actions on every row. Secrets the provider hands back once (root passwords, private keys) show once, with *Copy*.

The pages ask for the password again, as the SSH terminal does, and every action is logged with who did it
(`grep '"infrastructure"' storage/logs/laravel.log`). Which token to create for each provider, where, with which
permissions, plus step-by-step recipes: [the docs](https://studio.web.ap.it/#infra).

**Git hosting.** *Tools → GitHub* (GitLab, Bitbucket, Azure DevOps) is a dashboard of what waits for you on the git
hosts: pull requests asking for your review, your own, issues and work items assigned to you, notifications, and every
repository with its branches, pipelines and releases. Read the conversation and the diff, comment, approve, merge,
close, create branches, pull requests, issues and releases, start or retry pipelines. Everyone works with their own
accounts, as many as they need (personal, work, a client's), each with the name and email its commits carry; the
installation's provider is on from the start, *Administration → Connectors* turns the others on. Tokens and scopes per
provider: [the docs](https://studio.web.ap.it/#git).

## Status (1.1)

What changed in each release: [CHANGELOG.md](CHANGELOG.md).

| Area | State |
| --- | --- |
| Accounts: invite-only, roles (admin, PM, dev, client), password with 2FA and passkeys, git provider and Claude connections | done, tested |
| Git providers: GitHub, GitLab, Bitbucket Cloud, Azure DevOps (one per installation): token check, owners, new repositories, push webhooks and their verification, agent environment | done, tested against each API's documented requests and answers (HTTP fakes); not yet run against the live services |
| Projects with members; project site created on save, deployed by webhook or by hand | done, tested with a stand-in helper |
| Workspaces on the server through `studio-admin` (`native` driver); `local` driver for development | native tested with a stand-in helper; local tested |
| Chat: messages, slash commands for skills, permission cards, cards for Claude's questions (AskUserQuestion), streaming text, Reverb with polling fallback | done, tested with a fake bridge |
| Per conversation: model, effort, mode (ask, ask with edits, autopilot, plan only) | done, tested |
| Terminal tab: allowlisted commands run in the workspace through the bridge | done, tested (allowlist in `bridge/src/commands.js`) |
| Files tab: workspace tree, Monaco editor (the editor of VS Code), save with conflict check, create/rename/delete | done, tested (`bridge/src/files.js` against real directories) |
| Changes tab: working tree, history graph, branches with switch and fetch | done, tested (`bridge/src/git.js` against real repositories) |
| MCP servers per project: remote servers added in the project settings, by role, header stored encrypted, connection test; each chat gets them from the next message | done, tested |
| Project settings: scheduler and queues on every host by default, extra queues, Horizon, Reverb, Pulse; every project host behind the Studio login and the project assignment (a per-host pass), narrowed by address lists per site, previews and branch | done in Studio and the helper, tested with stand-ins; the helper's services block exercised in a sandbox with systemctl stubbed; units, cron and Caddy `forward_auth` not yet run on a live server |
| New project from scratch: repository created on the git provider, latest Laravel + Laravel Boost + Larapilot (with its forge integration on) pushed by the server | done, tested with stand-ins for the provider and the helper |
| Bridge (Node, `bridge/`): drives `claude -p` stream-json, permissions, resume, skills, git | done, tested with a fake CLI |
| Updater (`installer/studio-update`): stable and beta channels, force, system step, upgrade scripts, dump, migrate, switch, health check, rollback, signatures; nightly server update (`studio-update system`) | done, tested end to end in a sandbox with a real git repository and stubbed system commands (`tests/installer/`, 24 checks); the server update's apt run not yet on a live server |
| Installer (`installer/install.sh`), system steps (`installer/lib/system.sh`) and helper (`installer/studio-admin`) | written for Ubuntu 24.04/26.04 and shellchecked, **not yet run on a live server** |
| SSH terminal (*Tools → SSH terminal*, admins and developers): saved connections with favourites, several terminals at once (tabs or side by side), Ed25519/RSA keys generated or imported (OpenSSH, PEM, PuTTY, with passphrase), passwords, host keys accepted on first use; a connection tied to a project shows up for its admins and developers, who connect with their own key or password | done, tested (Pest, and `ssh-gateway/` against a real SSH server); the `studio-ssh` unit and its Caddy route not yet run on a live server |
| Password manager (*Tools → Password manager*): nested personal folders, items encrypted (username, password, notes, TOTP with live codes), password generator; an item is tied to a project for the roles ticked (client, PM, dev) and shared with single people to view or edit, with a table of who sees it and why | done, tested |
| Drive (*Tools → Drive*): folders and files on Studio's disk, upload by button or drag and drop; a folder tied to a project opens by role to view or to view and edit, down the tree; administrators see everything, clients create nothing; *Copy / Move into the workspace* sends files or whole folders to the person's workspace of that project through the bridge | done, tested (the bridge's `PUT /files/upload` against real directories) |
| Claude: several accounts per person, subscriptions (the official `claude auth login` in a folder of their own in the person's home, through the SSH gateway) and API keys; a default, a choice per group of projects and per project, sent with every turn | done, tested (helper, gateway and bridge with stand-ins for the CLI); not yet run with the real CLI on a live server |
| Groups of projects per person, with their Claude and git accounts; projects on any enabled git host, each workspace with the git account and commit identity of its repository's host | done, tested |
| Agent browser (Playwright MCP signed in to the preview with the person's pass), screenshots in the chat, subagents as cards, Studio's `code-reviewer` and `browser-qa` | done, tested (bridge against the fake CLI; Playwright MCP 0.0.83 run for real against a site behind a pass cookie: navigate, screenshot, console); not yet run on a live server |
| Conversations in a sandbox: worktree on its own branch, database copy, build and preview host; merge, push, remove; the side pane follows it | done, tested (bridge against real git repositories, Studio with stand-ins for the bridge and the helper); `sandbox-create`/`sandbox-delete` shellchecked, not yet run on a live server |
| Infrastructure connectors (*Administration → Connectors*, menu *Infrastructure*): AWS (EC2, all of Lightsail, RDS, S3, Route 53, CloudFront, cost), DigitalOcean, Hetzner Cloud, Cloudflare, Cipi servers, Boogle, Hackly; multi-account, credentials encrypted and checked on save, enabled per connector, administrators only | done, tested against each API's documented requests and answers (HTTP fakes, AWS SigV4 against the AWS test suite); not yet run against the live services |
| Git hosting dashboards (*Tools*): GitHub, GitLab, Bitbucket Cloud, Azure DevOps; personal accounts, several per person, each with its commit identity | done, tested against each API's documented requests and answers (HTTP fakes); not yet run against the live services |
| Not started | atomic releases for project sites, notifications, mobile layout |

## Run it locally (no server)

```bash
composer setup                   # install, .env, key, migrate, npm install + build
php artisan studio:make-admin    # first account
composer run dev                 # server, queue, logs, vite · add: php artisan reverb:start
```

Locked out of the dashboard (lost password or 2FA, the only administrator demoted)? `php artisan studio:reset-admin` resets the main administrator; on a server, root runs `studio-recover admin` over SSH (see the [docs](https://studio.web.ap.it/#setup-recovery)).

The SSH page needs the gateway: `cd ssh-gateway && npm install`, then
`APP_KEY=… SSH_GATEWAY_REDEEM_URL=http://127.0.0.1:8000/api/ssh/redeem SSH_GATEWAY_ORIGIN= node bin/gateway.js`
(the `APP_KEY` of Studio's `.env`), and `STUDIO_SSH_URL=ws://127.0.0.1:8082/ssh/ws` in `.env`.

Then run the bridge inside any Laravel project with Larapilot on this machine:

```bash
BRIDGE_TOKEN=local-bridge-token WORKSPACE_DIR=/path/to/a-laravel-app node /path/to/studio/bridge/bin/bridge.js
```

`.env` carries `STUDIO_WORKSPACE_DRIVER=local` and the matching bridge address and token. Create a
project in *Administration → Projects*, add yourself, open it: the workspace is "running" at once and
the chat talks to that bridge, which talks to the `claude` on your machine with your login.

## Demo data

```bash
php artisan db:seed --class=DemoSeeder   # an agency, three projects, a live conversation; password "password"
```

The screenshots in `docs/img/` are regenerated from that demo with `tools/screenshots/` (see its README).

## Checks

```bash
composer test                               # pint --test, phpstan, pest
cd bridge && npm test                       # node:test with a fake Claude CLI
cd ssh-gateway && npm test                  # node:test against a real (in-process) SSH server
bash tests/installer/studio-update.test.sh  # the updater in a sandbox (also system-lib.test.sh)
```

## How the pieces talk

```
browser ──WebSocket/HTTP──▶ Studio (Laravel) ──HTTP──▶ bridge (in the workspace) ──stdin/stdout──▶ claude -p
   ▲                            ▲                          │
   └── Reverb broadcasts ───────┴── POST /api/bridge/events ┘   (per-workspace callback token)

Studio ──sudo──▶ studio-admin        create sites and workspaces, deploy, start, stop
Git provider ──webhook──▶ Studio     push on the deploy branch → deploy the project site
studio-update (root) ──▶ /opt/studio newest release tag: system step, build, migrate, switch (or roll back)
Caddy  ──ask──▶ Studio               may I issue a certificate for this host?
browser ──wss /ssh/ws──▶ ssh-gateway ──SSH──▶ any host
                             └── POST /api/ssh/redeem ──▶ Studio   one-time ticket → host, key or password
```

- `App\Bridge\BridgeClient` sends turns, permission answers and interrupts to the bridge, and tells it
  where to call back.
- `App\Bridge\IngestBridgeEvents` turns the bridge's events into messages, permission requests and
  `ConversationUpdated` broadcasts.
- `App\Server\StudioAdmin` is the client of the privileged helper; `App\Server\ProjectSites` the
  lifecycle of a project site; `App\Workspaces\NativeDriver` the workspaces.
- `App\Git\Providers\{GitHub,GitLab,Bitbucket,AzureDevOps}` behind `GitProvider`: token check, owners,
  new repositories, the push webhook and its verification, the agent's environment.
- `App\Drive\Access` decides who reads and writes where in the drive (the whole tree at once); `App\Drive\WorkspaceTransfer`
  streams drive files into a workspace through the bridge. `App\Models\VaultItem::scopeVisibleTo` is the same for the password manager.
- `App\Ssh\Gateway` issues the SSH page's one-time tickets and redeems them for the gateway (its token is
  an HMAC of `APP_KEY`, so the `studio-ssh` unit reads Studio's `.env`); `App\Ssh\Keys` generates and imports keys.
- `App\Server\Updates` reads the updater's state and asks the helper to check, update or switch the nightly timer.
- `App\Larapilot\LarapilotApi` reads the backlog from the project site.

## Configuration

See `config/studio.php` (drivers, permission presets, models) and `.env.example`.
