# Studio

One Ubuntu server, one script. A self-hosted web app that gives a product team a human interface
over Laravel workspaces that live on that server: a chat with the agent, the preview of your branch,
the changes, the backlog, approvals. Each conversation is **the person's own Claude Code** running
inside their workspace with the Larapilot skills of the project. Nothing to install on a laptop but
a browser.

**Version 1.0** · Laravel 13 · Livewire 4 · Flux · Fortify (2FA, passkeys) · Reverb · Caddy · PHP 8.3/8.4/8.5 · MariaDB and PostgreSQL.

**Documentation:** [studio.web.ap.it](https://studio.web.ap.it) — the static site in `docs/`
(Netlify publishes the `docs/` folder as it is, see `netlify.toml`). The interface is in English;
`APP_LOCALE=it` switches it to Italian through `lang/it.json`.

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

**One git provider per Studio**, chosen in the installer: GitHub, GitLab (gitlab.com or self-managed),
Bitbucket Cloud or Azure DevOps (one organization), the same remote forges Larapilot integrates with.
Every project is a repository there; Studio never mixes providers and keeps no history of a previous one
(`php artisan studio:git-provider` changes it only while there are no projects). People sign in with email
and password (plus two-factor), then connect their own token for the provider and their own Claude credential
(`claude setup-token` or an API key) in *Settings → Connections*, which explains for each provider how to
create the token and how organizations, groups, workspaces or projects work. A project is an existing
repository, or one Studio creates on the spot with the latest Laravel, Laravel Boost and Larapilot.

**Updates.** `studio-update` (root) installs every new build of its channel by itself, nightly or from
*Administration → System*: **stable** follows the release tags, **beta** (beta tester mode) the head of `main`;
neither goes backwards, and back on stable a main build stays until a release contains it. *Force update*
builds and installs the channel's newest build again even when it is installed. Each update installs what
the build needs on the server (PHP versions, Node, packages, the provider's CLI, from
`installer/requirements.env` and `installer/upgrades/`), builds it next to the running one, dumps the
database, migrates, switches `/opt/studio/current`, checks `/up`, and puts the previous release and the
database back when a step fails. Signed tags (and, on beta, signed commits) are enforced when
`/etc/studio/update-signers` exists. Releasing is: set `VERSION`, push a `vX.Y.Z` tag.

Inside a conversation the chat is the full Claude Code CLI: subagents, plan mode, skills and slash commands,
MCP servers from the repository, hooks. *Model & mode* picks the model, the effort and the permission mode
(ask, ask with edits, autopilot, plan only); the *Terminal* tab runs allowlisted commands in the workspace; the *Files* tab is a file manager with the editor of VS Code; the *Changes* tab shows the working tree, the commit graph and the branches. Project settings switch the scheduler, queue workers and Reverb per project, narrow who can open its hosts (every address of a project is behind the Studio login and the project assignment, in any role; address lists per site, previews and branch), and add remote MCP servers to the project's chats.

## Status (1.0, 2026-10-05)

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
| Updater (`installer/studio-update`): stable and beta channels, force, system step, upgrade scripts, dump, migrate, switch, health check, rollback, signatures | done, tested end to end in a sandbox with a real git repository and stubbed system commands (`tests/installer/`, 18 checks) |
| Installer (`installer/install.sh`), system steps (`installer/lib/system.sh`) and helper (`installer/studio-admin`) | written for Ubuntu 24.04/26.04 and shellchecked, **not yet run on a live server** |
| Not started | atomic releases for project sites, SSH access from the dashboard, notifications, mobile layout |

## Run it locally (no server)

```bash
composer setup                   # install, .env, key, migrate, npm install + build
php artisan studio:make-admin    # first account
composer run dev                 # server, queue, logs, vite · add: php artisan reverb:start
```

Locked out of the dashboard (lost password or 2FA, the only administrator demoted)? `php artisan studio:reset-admin` resets the main administrator; on a server, root runs `studio-recover admin` over SSH (see the [docs](https://studio.web.ap.it/#setup-recovery)).

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
```

- `App\Bridge\BridgeClient` sends turns, permission answers and interrupts to the bridge, and tells it
  where to call back.
- `App\Bridge\IngestBridgeEvents` turns the bridge's events into messages, permission requests and
  `ConversationUpdated` broadcasts.
- `App\Server\StudioAdmin` is the client of the privileged helper; `App\Server\ProjectSites` the
  lifecycle of a project site; `App\Workspaces\NativeDriver` the workspaces.
- `App\Git\Providers\{GitHub,GitLab,Bitbucket,AzureDevOps}` behind `GitProvider`: token check, owners,
  new repositories, the push webhook and its verification, the agent's environment.
- `App\Server\Updates` reads the updater's state and asks the helper to check, update or switch the nightly timer.
- `App\Larapilot\LarapilotApi` reads the backlog from the project site.

## Configuration

See `config/studio.php` (drivers, permission presets, models) and `.env.example`.
