# Studio

**Your whole studio on one server.** Claude does the work. Your team decides.

One Ubuntu server, one script, one wildcard DNS record. Studio is where an agency or a product team runs its
Laravel projects. Administrators create them, put each person on them with a role *of that project* (the PM here,
a developer there, the client elsewhere) and decide what every role sees and does. Each project is a hub: the
conversation with **the person's own Claude Code**, running in their workspace with the Larapilot skills of the
project, which writes the story, plans it, implements it and opens the pull request; the live preview of that
branch, on the client's domain if they want; the repository on any git host; the servers, zones and apps attached
to it; its passwords, files, SSH connections and backups. People read, decide and approve. Nothing to install on a
laptop but a browser.

<p align="center">
<img src="docs/img/chat-permission.png" alt="A permission card asking to run php artisan migrate:fresh --seed, with Allow, Always allow and Deny, the preview of the branch on the right" width="100%">
</p>

**Version 2.1** · Laravel 13 · Livewire 4 · Flux · Fortify (2FA, passkeys) · Reverb · Caddy · PHP 8.5 (8.4, 8.3 on request) · MariaDB and PostgreSQL.

**Documentation:** [studio.web.ap.it](https://studio.web.ap.it) — the static site in `docs/`
(Netlify publishes the `docs/` folder as it is, see `netlify.toml`).

## Install on a VPS

```bash
wget -qO- https://raw.githubusercontent.com/andreapollastri/studio/refs/heads/main/installer/install.sh | sudo bash
```

Ubuntu 24.04 or 26.04 LTS (PHP 8.5 from `packages.sury.org` on both; 8.4 and 8.3 from *Administration → System* when a project needs them). apt's output goes to `/var/log/studio-install.log`; an installation that stopped halfway starts
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

**The administrators run it, the team works in projects.** Everything starts from the administrators: they invite
people, create the projects, put each person on them with a role *of that project* (a developer here may be the PM
there and a client elsewhere), keep the team's accounts, and decide, project by project and role by role, who sees and
does what: the passwords, folders and SSH connections tied to a project, and the servers, zones and apps of the
infrastructure connectors attached to it. Every project gets a key of its own (`api-9usaf9`), so two clients may
both have an "API".

**Any git host, everyone's own accounts.** Projects live on GitHub, GitLab (gitlab.com or self-managed),
Bitbucket Cloud or Azure DevOps, the same remote forges Larapilot integrates with; all four are always there. A project
is an existing repository, or one Studio creates on the spot with the latest Laravel, Laravel Boost and Larapilot,
with Boost's skills, guidelines and MCP server published for Claude Code and for Cursor. A
*service account* of the team (*Administration → Connectors → Git hosting*: a bot's token) or an administrator's own
account creates or reads it, registers the webhook and deploys the site; a service account keeps deploying when an
administrator leaves. People sign in with email and password (plus two-factor), then bring their own accounts, as many
as they work with, all in *Settings → Accounts*:

- **Git**: a personal GitHub, the company's Azure DevOps, a client's GitLab, each with the
  name and email its commits carry. A workspace clones, commits and pushes as the account on the host of its
  repository, with that identity (`git config` in the clone), and the agent's CLI (gh, glab, az) gets that token.
  *Tools → Git* is one dashboard across all of them; a project's *Repository* tab opens its own repository with it.
- **SSH keys**: generated (Ed25519, RSA) or imported, for the SSH terminal.
- **Claude**: subscriptions and Anthropic API keys side by side, each person's own. *Sign in to Claude Code* runs
  Claude Code's official `claude auth login` in a terminal in the person's own home on the server, in a folder of its
  own for each subscription (`~/.claude`, `~/.claude-<slug>`, sharing the sessions, agents and skills); the person
  pastes Anthropic's code into that terminal, so a Pro, Max or Team plan is used the way Anthropic allows on hosted
  platforms and Studio never sees or holds it; a key is stored encrypted and goes with each message. One account is
  the default. See [Claude Code and Anthropic's terms](#claude-code-and-anthropics-terms).
- **Cursor**: subscriptions, each person's own. *Sign in to Cursor* runs the official `agent login` in a terminal
  in the person's own home on the server, in a folder of its own for each subscription (`~/.cursor`,
  `~/.cursor-<slug>`); the person approves in the browser, so the plan is used as their own and Studio never sees
  or holds the login. An API key is not one of these: it is billed apart from the subscription. Each person
  chooses Claude or Cursor as the default for new conversations, and can switch a conversation in *Model & mode*.
  See [Cursor and its terms](#cursor-and-its-terms).
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
(ask, ask with edits, autopilot, plan only); the *Terminal* tab is a shell in the workspace, on a real terminal; the *Files* tab is a file manager (uploads, moves, zips) with the editor of VS Code; the *Git* tab shows the working tree, the commit graph and the branches; *Doc* shows the README as a page; *Db* opens the workspace's database, the project site's or the project's connections in the database client. Project settings switch the scheduler, queue workers and Reverb per project, narrow who can open its hosts (every address of a project is behind the Studio login and the project assignment, in any role, and the site's public address on a client's domain, per project, either public or behind the browser's sign-in with a Studio account; address lists per site, previews and branch), and add remote MCP servers to the project's chats.

**A project is a hub.** Its page has tabs: *Workspace* (the chat, the preview, the changes, the terminal, the files),
*Repository* (pull requests, issues, branches, pipelines and releases of its repository, with the person's own account),
*Infrastructure* (what is attached to it, for the person's role), *Access* (its passwords, SSH connections and folders,
for the person's role) and, for administrators, *Settings* (general, members and roles, server, who opens the hosts,
agent, MCP servers). *Home* is an inbox across every project: conversations that ask something, failed or finished
since last opened, failed workspaces and sites, and what waits on each git account.

**Infrastructure.** Studio also drives the clouds and services the team runs its servers on, through their APIs:
AWS (EC2, every kind of Lightsail resource, RDS, S3, Route 53, CloudFront), DigitalOcean, Hetzner Cloud, Cloudflare,
[Cipi](https://cipi.sh) servers, [Boogle](https://boogle.web.ap.it), [Hackly](https://hackly.web.ap.it) and [Johnny](https://johnny.web.ap.it) storage. It is for
administrators only: no other role sees the menu, the pages or the accounts.

1. *Administration → Connectors*: switch on the connectors the team uses. Only those show in the menu, under
   *Infrastructure*; turning one off hides it again and keeps its accounts.
2. On the connector's page, *Accounts → Add an account*: a name and the credentials (an IAM access key, a team or project
   token, the address and token of a Cipi server or of a Boogle, Hackly or Johnny instance). Studio checks them against the
   provider before saving and stores them encrypted; any number of accounts per connector.
3. Pick the account at the top and a tab: lists with search and filters (the region for AWS), a button to create, a menu
   of actions on every row. Secrets the provider hands back once (root passwords, private keys) show once, with *Copy*.
4. *Attach to a project…* in a row's menu puts that Droplet, zone or app in the project's *Infrastructure* tab: the roles
   that see it, the roles that act on it, and which of its actions (and of the lists it opens, like a zone's records)
   they may run. Members see that row alone, read with the administrators' account, which they never see.

The pages ask for the password again, as the SSH terminal does, and every action is logged with who did it
(`grep '"infrastructure"' storage/logs/laravel.log`). Which token to create for each provider, where, with which
permissions, plus step-by-step recipes: [the docs](https://studio.web.ap.it/#infra).

**Git hosting.** *Tools → Git* is one dashboard of what waits for you on every git host you have an account on
(GitHub, GitLab, Bitbucket, Azure DevOps; the picker moves between them): pull requests asking for your review, your
own, issues and work items assigned to you, notifications, and every repository with its branches, pipelines and
releases. Read the conversation and the diff, comment, approve, merge, close, create branches, pull requests, issues
and releases, start or retry pipelines. Everyone works with their own accounts, kept in *Settings → Accounts*, as many
as they need (personal, work, a client's), each with the name and email its commits carry. Tokens and scopes per
provider: [the docs](https://studio.web.ap.it/#git).

## Claude Code and Anthropic's terms

Studio runs the unmodified Claude Code that Anthropic publishes, and nothing stands between the person, that binary and
Anthropic: the way Anthropic's [Legal and compliance](https://code.claude.com/docs/en/legal-and-compliance) page allows
Claude Code on a hosted platform. In short:

- **The operator accepts Anthropic's Commercial Terms.** Running Claude Code in a service of yours, for a team, is covered
  by them; the author of Studio is not a party to it, whoever runs each installation is.
- **Each person authenticates with their own credentials, billed to them**: a subscription signed in to Claude Code in
  their own home, or an API key of their own (or their organisation's) Console account. Studio never pays for, resells or
  brokers Claude on anyone's behalf, holds no subscription and accepts no `setup-token`.
- **A subscription is personal.** One Pro or Max plan shared by several people through Studio would be brokering and a
  breach of the consumer terms. For a team, a Claude Team or Enterprise plan (a seat per person) or API keys are the
  natural choice; Pro and Max limits assume ordinary individual use, and parallel sandboxes, subagents and autopilot
  spend them fast.
- **Nothing in Claude Code is modified or restricted**: both sign-in methods stay available, the server updates it from
  npm, and the login files in `~/.claude*` are read by the CLI alone (denied to the agent, never read, copied or logged by
  Studio, its bridge or its root helper; Studio only asks `claude auth status`).
- **Studio is an independent project**, not affiliated with or endorsed by Anthropic. Claude and Claude Code are
  trademarks of Anthropic, PBC, named here to say what Studio runs.

Not legal advice: for an installation others will use, read Anthropic's page and, in doubt, ask them.

## Cursor and its terms

Studio runs the unmodified Cursor CLI that Cursor publishes (`agent login`, `agent status`, `agent logout`), and
nothing stands between the person, that binary and Cursor. In short:

- **Each person authenticates with their own account, billed to them.** The sign-in is their own `agent login` in
  their own home. Studio never pays for, resells or brokers Cursor on anyone's behalf, and it holds no subscription
  and no API key.
- **A subscription is personal.** One plan shared by several people through Studio would be sharing the login.
  For a team, a seat per person.
- **Nothing in the CLI is modified.** The server installs it from Cursor's own installer, and the login files in
  `~/.cursor*` are read by the CLI alone (denied to the agent, never read, copied or logged by Studio, its bridge
  or its root helper; Studio only asks `agent status`).
- **Studio is an independent project**, not affiliated with or endorsed by Anysphere. Cursor is a trademark of
  Anysphere, Inc., named here to say what Studio runs.

Cursor has not published a page that says, as Anthropic does for Claude Code, that the CLI may run on a hosted
platform. The CLI is documented for the machine it runs on, including a remote one, and its use draws on the
subscription. That is the shape Studio follows.

Not legal advice: for an installation others will use, read [Cursor's terms](https://cursor.com/terms-of-service)
and, in doubt, ask them.

## Screenshots

Every image comes from the demo data (see below) and is also in [the docs](https://studio.web.ap.it), with the
story around it. The one at the top of this page is a PM approving a command before the preview on the right
shows the result on her own branch.

**A request, from words to a pull request**

<table>
<tr>
<td width="50%"><img src="docs/img/chat-top.png" alt="The chat: a request in plain words, the story written and planned"><br><sub><strong>Ask in plain words.</strong> Claude reads the PRD and the backlog through the Larapilot skills, writes the story, plans it and answers with an estimate.</sub></td>
<td width="50%"><img src="docs/img/chat-permission.png" alt="A permission card before php artisan migrate:fresh --seed, with Always allow, the preview of the branch on the right"><br><sub><strong>Approve what matters.</strong> Tests and builds run on their own; the rest asks once, or never again with <em>Always allow</em>. The preview on the right follows her branch.</sub></td>
</tr>
<tr>
<td width="50%"><img src="docs/img/chat-changes.png" alt="The Git tab: branch, modified files and the diff"><br><sub><strong>See the change.</strong> The Git tab reads the working tree of the workspace: branch, files, diff.</sub></td>
<td width="50%"><img src="docs/img/chat-plan.png" alt="A Plan only conversation with two subagent cards side by side"><br><sub><strong>Plan only, with subagents.</strong> Two subagents at once, each a card with its status, steps and report; the answer is a plan, nothing was changed.</sub></td>
</tr>
<tr>
<td width="50%"><img src="docs/img/chat-tools-open.png" alt="The chat with Details on: every command and its result expanded"><br><sub><strong>Details.</strong> Each tool call is a row; Details expands them all with their results and the agent's reasoning.</sub></td>
<td width="50%"><img src="docs/img/chat-settings.png" alt="The Model and mode dialog: model, effort, four permission modes, what runs without asking"><br><sub><strong>Model &amp; mode.</strong> Model, effort and the permission mode of this conversation; below, what runs without asking and the MCP servers it gets from the project.</sub></td>
</tr>
<tr>
<td width="50%"><img src="docs/img/chat-skills.png" alt="The chat input with /lara typed and the menu of the Larapilot skills above it"><br><sub><strong>"/" lists the skills.</strong> The repository's skills and commands and your own, filtered as you type; Enter starts one, Tab writes it so a request can follow.</sub></td>
<td width="50%"><img src="docs/img/chat-dark.png" alt="The same conversation in dark mode"><br><sub><strong>Light or dark.</strong> Studio follows the system appearance, or the choice in Settings → Appearance.</sub></td>
</tr>
</table>

**Home, projects and roles**

<table>
<tr>
<td width="50%"><img src="docs/img/home.png" alt="Home: conversations waiting for an answer, projects with roles, the GitHub card"><br><sub><strong>Home.</strong> The inbox across every project: conversations that ask something or finished, the projects with your role on each, what waits on each git account.</sub></td>
<td width="50%"><img src="docs/img/projects.png" alt="The Projects page: a card per project with the role and the workspace state"><br><sub><strong>Projects.</strong> A card per project with your role there and the state of your workspace; opening one creates it the first time.</sub></td>
</tr>
<tr>
<td width="50%"><img src="docs/img/projects-roles.png" alt="Luca's projects: developer on some, PM on another"><br><sub><strong>One person, several roles.</strong> Luca develops four projects and is the PM of a fifth; the chat, the presets and the tabs follow the role.</sub></td>
<td width="50%"><img src="docs/img/client-view.png" alt="A client's view: no chat, the project site in the preview pane"><br><sub><strong>A client's view.</strong> No workspace, no chat: the project site in the preview pane, and what is tied to the project for clients.</sub></td>
</tr>
</table>

**The project hub**

<table>
<tr>
<td width="50%"><img src="docs/img/project-repository.png" alt="The Repository tab: the pull requests of the repository, read with the person's own account"><br><sub><strong>Repository.</strong> Pull requests, issues, branches, pipelines and releases, read with your own account on the git host.</sub></td>
<td width="50%"><img src="docs/img/project-overview.png" alt="The Infrastructure tab overview: cards for every attached resource, MCP servers, terminal and backups"><br><sub><strong>Infrastructure, overview.</strong> Everything the project runs on at a glance, with what needs attention.</sub></td>
</tr>
<tr>
<td width="50%"><img src="docs/img/project-infrastructure.png" alt="A developer on the Infrastructure tab: only the attached Droplet with the actions handed to his role"><br><sub><strong>What a member sees.</strong> A developer sees what is attached for developers, with the actions handed over, and nothing else of the accounts.</sub></td>
<td width="50%"><img src="docs/img/project-access.png" alt="The Access tab: passwords, SSH connections and drive folders tied to the project"><br><sub><strong>Access.</strong> Passwords, SSH connections and folders tied to the project for your role; each opens in its own tool.</sub></td>
</tr>
<tr>
<td width="50%"><img src="docs/img/project-settings-members.png" alt="Settings, Members and roles: a role select per person"><br><sub><strong>Settings → Members &amp; roles.</strong> One select per person: not on the project, or their role on it.</sub></td>
<td width="50%"><img src="docs/img/project-settings-server.png" alt="Settings, Server: addresses, scheduler, queues, Horizon, Reverb, Pulse"><br><sub><strong>Settings → Server.</strong> What runs next to the app, on the site and on every preview.</sub></td>
</tr>
<tr>
<td width="50%"><img src="docs/img/project-settings-access.png" alt="Settings, Who opens the hosts: address lists for the site and the previews"><br><sub><strong>Settings → Who opens the hosts.</strong> The Studio login and the project; the public address open, or behind the browser's sign-in; address lists per site, previews and branch.</sub></td>
<td width="50%"><img src="docs/img/project-settings-mcp.png" alt="Settings, MCP servers: two servers with URL, roles and last test"><br><sub><strong>Settings → MCP servers.</strong> Each with its URL, who gets it, its header and the answer of the last test.</sub></td>
</tr>
</table>

**The workspace**

<table>
<tr>
<td width="50%"><img src="docs/img/files.png" alt="The Files tab: the workspace tree and a PHP file open in the editor"><br><sub><strong>Files.</strong> The tree reads one folder at a time; the editor is Monaco, the component VS Code is built on.</sub></td>
<td width="50%"><img src="docs/img/history.png" alt="The History view: a commit graph with lanes and merges"><br><sub><strong>History.</strong> Every branch in one graph, with lanes and merges, refs as badges; a click opens the commit and its patch.</sub></td>
</tr>
<tr>
<td width="50%"><img src="docs/img/terminal.png" alt="The Terminal tab: shortcut buttons over a shell, where an artisan command asked for a password twice and added the user"><br><sub><strong>Terminal.</strong> A shell in the workspace on a real terminal: prompts and passwords work; shortcuts type the useful commands.</sub></td>
<td width="50%"><img src="docs/img/files-menu.png" alt="The row menu of a folder in the Files tree: new file, new folder, uploads, rename, move, compress, delete"><br><sub><strong>Upload, move, zip.</strong> Files and whole folders upload by dropping them on the tree; rows move by dragging; zips are made and extracted in place.</sub></td>
</tr>
<tr>
<td width="50%"><img src="docs/img/chat-doc.png" alt="The Doc tab: the project's README as a page, with its picture, a code block and a table"><br><sub><strong>Doc.</strong> The README of the repository as a page, its pictures from the workspace; the other Markdown files a click away.</sub></td>
<td width="50%"><img src="docs/img/chat-db.png" alt="The Db tab on Remote: a project's database copy, read only, its tables and rows"><br><sub><strong>Db.</strong> The database client in the pane: your workspace's database, the project site's, or the project's connections, as your role allows.</sub></td>
</tr>
</table>

**Tools**

<table>
<tr>
<td width="50%"><img src="docs/img/git.png" alt="Tools, Git: the account picker, pull requests waiting for review, issues assigned"><br><sub><strong>Git.</strong> One dashboard of what waits for you on every git host you have an account on; the picker moves between them.</sub></td>
<td width="50%"><img src="docs/img/databases.png" alt="Tools, Databases: connections in coloured groups with engine, environment, tunnel and project"><br><sub><strong>Databases.</strong> Every connection you reach, in groups of your own, favourites first, the environment on each.</sub></td>
</tr>
<tr>
<td width="50%"><img src="docs/img/database-data.png" alt="The SQL client: tables on the left, the rows of a table with filters, pages and export"><br><sub><strong>Rows.</strong> Tables and views on the left; rows sorted, filtered and paged, edited in place.</sub></td>
<td width="50%"><img src="docs/img/database-sql.png" alt="The SQL editor with two statements run, saved queries and history on the side"><br><sub><strong>SQL.</strong> One result per statement; saved queries and history. Structure, new tables, import and export are the other tabs.</sub></td>
</tr>
<tr>
<td width="50%"><img src="docs/img/ssh-files.png" alt="The SSH page with a session switched to Files: the server's home in a tree, README.md open in the editor"><br><sub><strong>SSH: Terminal ⇄ Files.</strong> A session turns into the file manager of its host, over SFTP with your own key or password; the shell comes back as it was.</sub></td>
<td width="50%"><img src="docs/img/accounts-ssh.png" alt="Settings, Accounts, SSH keys: keys with their type and public half"><br><sub><strong>SSH keys.</strong> Ed25519 or RSA, generated here or imported; the public half and the command that adds it to the server.</sub></td>
</tr>
<tr>
<td width="50%"><img src="docs/img/vault.png" alt="The password manager: a shared folder with its items"><br><sub><strong>Password manager.</strong> Nested folders, items encrypted, TOTP with live codes; a folder tied to a project opens by role.</sub></td>
<td width="50%"><img src="docs/img/vault-folder-sharing.png" alt="Sharing a folder: the project and its roles, single people, who sees it and why"><br><sub><strong>Sharing a folder.</strong> A project with its roles, single people to view or to edit, and who reaches it and through what.</sub></td>
</tr>
<tr>
<td width="50%"><img src="docs/img/backups.png" alt="Tools, Backups: backups with source and destination, schedules, retention and last run"><br><sub><strong>Backups.</strong> From → to, when, what is kept and how much, the last run; Run now on each.</sub></td>
<td width="50%"><img src="docs/img/backups-schedule.png" alt="The Schedule tab: the coming seven days hour by hour and every schedule with its cron"><br><sub><strong>Schedule.</strong> The week ahead at a glance, every schedule with its cron expression and next run.</sub></td>
</tr>
</table>

**Administration**

<table>
<tr>
<td width="50%"><img src="docs/img/admin-projects.png" alt="Administration, Projects: each project with name and key, repository, deploy account, site status and team"><br><sub><strong>Projects.</strong> Name and key (two projects called API, told apart by it), repository and stack, the account that deploys, the site's state, members and attached resources.</sub></td>
<td width="50%"><img src="docs/img/admin-project-new.png" alt="The New project form: name, git account, repository, deploy branch, PHP, database, members and roles"><br><sub><strong>New project.</strong> The git account that deploys it, the repository (or one created on the spot), branch and stack, each person's role on it.</sub></td>
</tr>
<tr>
<td width="50%"><img src="docs/img/admin-users.png" alt="Administration, People: name, email, access, git and Claude badges, projects with roles"><br><sub><strong>People.</strong> Access as a dropdown, each project with the person's role there, badges for git and Claude accounts and two-factor.</sub></td>
<td width="50%"><img src="docs/img/admin-connectors.png" alt="Administration, Connectors: a card per connector with its switch, and the git hosting service accounts"><br><sub><strong>Connectors.</strong> A card and a switch per infrastructure connector; below, the team's service accounts on the git hosts.</sub></td>
</tr>
<tr>
<td width="50%"><img src="docs/img/infra-console.png" alt="The DigitalOcean page: the account picker, a list of Droplets, a project badge on one row"><br><sub><strong>A connector page.</strong> The lists of the account picked at the top, with search, create and a menu of actions on every row.</sub></td>
<td width="50%"><img src="docs/img/infra-attach.png" alt="The Attach to a project dialog: project, name, who sees it, who acts on it, the actions"><br><sub><strong>Attach to a project.</strong> Which roles see the row, which act on it, and which of its actions they may run.</sub></td>
</tr>
<tr>
<td width="50%"><img src="docs/img/admin-system.png" alt="Administration, System: installed and newest release, update buttons, nightly switch, channel, last log, the server's PHP versions"><br><sub><strong>System.</strong> The installed build and the newest one, the nightly switch, the channel, the last run with its log, the git hosts.</sub></td>
<td width="50%"><img src="docs/img/admin-domains.png" alt="Administration, Domains: a verified domain used by a project, one waiting for its DNS"><br><sub><strong>Domains.</strong> The records to create, what the DNS answered, the projects that use each domain.</sub></td>
</tr>
</table>

**Your accounts, a Cipi app, a phone**

<table>
<tr>
<td width="50%"><img src="docs/img/accounts.png" alt="Settings, Accounts, Claude tab: subscriptions and an API key, one marked default"><br><sub><strong>Settings → Accounts → Claude.</strong> Subscriptions and API keys side by side, which one is the default, whether each is signed in.</sub></td>
<td width="50%"><img src="docs/img/accounts-git.png" alt="Settings, Accounts, Git tab: accounts with host, login and commit identity"><br><sub><strong>Settings → Accounts → Git.</strong> Each account with its host, its login, the name and email its commits carry, the state of its token.</sub></td>
</tr>
<tr>
<td width="50%"><img src="docs/img/cipi-hub.png" alt="A Cipi app in its project: releases, deploy history, server load and disk"><br><sub><strong>A Cipi app.</strong> Releases on the server with the live one and Roll back here, the deploy history, the server's load.</sub></td>
<td width="50%"><img src="docs/img/cipi-anonymizer.png" alt="The Database tab of a Cipi app: an anonymized copy and the anonymization file from the schema"><br><sub><strong>Its database.</strong> An anonymized copy for developers, and the anonymization file from the live schema, edited and written back.</sub></td>
</tr>
<tr>
<td width="50%" align="center"><img src="docs/img/phone-project.png" alt="The Access tab on a phone: the project's tabs on their own row, scrolling sideways" width="300"><br><sub><strong>On a phone.</strong> The tabs keep their row and scroll sideways.</sub></td>
<td width="50%"><img src="docs/img/login.png" alt="The login page: email, password, the invite-only notice"><br><sub><strong>Sign in.</strong> Email and password, then two-factor or a passkey; accounts are invite-only.</sub></td>
</tr>
</table>

## Status (2.3)

What changed in each release: [CHANGELOG.md](CHANGELOG.md).

| Area | State |
| --- | --- |
| Accounts: invite-only, administrators and members, a role per project (PM, developer, client), password with 2FA and passkeys; git, SSH and Claude accounts in Settings → Accounts | done, tested |
| Hardening (2.1): two-factor required of admins or everyone, long and unleaked passwords, per-address sign-in limits with fail2ban behind them, a security log, security headers on every page, Caddy's admin API on a root-only socket, the internal endpoints on the loopback only; see [Security in the docs](https://studio.web.ap.it/#setup-security) | done, tested |
| Git hosts: GitHub, GitLab, Bitbucket Cloud, Azure DevOps, all at once: token check, owners, new repositories, push webhooks and their verification, agent environment; service accounts for deploying | done, tested against each API's documented requests and answers (HTTP fakes); not yet run against the live services |
| Projects with a random key, members with their roles; project site created on save, deployed by webhook or by hand | done, tested with a stand-in helper |
| Project hub: Workspace, Repository (the person's own account), Infrastructure and Access by role, Settings for administrators; Home inbox across projects and git accounts | done, tested (git hosts and connectors with HTTP fakes) |
| Infrastructure attached to projects: any row of a connector's list, seen and acted on by the roles chosen, with the actions handed over, logged | done, tested against HTTP fakes |
| Workspaces on the server through `studio-admin` (`native` driver); `local` driver for development | native tested with a stand-in helper; local tested |
| Chat: messages, a "/" menu of the skills and commands, Enter to send, permission cards with *Always allow* and the role's everyday commands allowed, cards for Claude's questions (AskUserQuestion), text streamed in the browser over Reverb with polling only without it, the CLI warmed when the input is focused | done, tested with a fake bridge; streaming and warming checked in a browser against a stand-in CLI |
| Per conversation: model, effort, mode (ask, ask with edits, autopilot, plan only) | done, tested |
| Terminal tab: a shell in the workspace on a real terminal (the bridge's `src/terminal.py`), carried by the SSH gateway | done, tested end to end (gateway → bridge → shell) on a local machine; not yet run on a live server |
| Files tab: workspace tree, Monaco editor (the editor of VS Code), save with conflict check, create/rename/move/delete, uploads of files and folders, download, zip and unzip | done, tested (`bridge/src/files.js` against real directories) |
| Git tab (once Changes): working tree, history graph, branches with switch and fetch | done, tested (`bridge/src/git.js` against real repositories) |
| Doc tab: the repository's README and top-level Markdown as pages (GitHub's flavour, raw HTML stripped), pictures from the workspace | done, tested with a fake bridge |
| Db tab: the database client in the pane on the workspace's database (from its `.env`), the project site's (`studio-admin project-db`) or the project's Databases connections | done, tested (SQLite end to end, the helper with a stand-in); Local and Develop not yet run on a live server |
| SSH page, Files: each session switches to the file manager of its host over SFTP (`app/Ssh/SftpFiles.php`), with uploads, moves, zips, download | done, tested against a local-disk SFTP double; not yet run against a live server |
| MCP servers per project: remote servers added in the project settings, by role, header stored encrypted, connection test; each chat gets them from the next message | done, tested |
| Project settings: scheduler and queues on every host by default, extra queues, Horizon, Reverb, Pulse; every project host behind the Studio login and the project assignment (a per-host pass) the site's public address on a client's domain public per project, or behind the browser's sign-in (basic authentication with a Studio account; the credentials never reach the app); previews never public, narrowed by address lists per site, previews and branch | done in Studio and the helper, tested with stand-ins; the helper's services block exercised in a sandbox with systemctl stubbed; units, cron and Caddy `forward_auth` not yet run on a live server |
| New project from scratch: repository created on the git provider, latest Laravel + Laravel Boost + Larapilot (with its forge integration on, and Boost's skills, guidelines and MCP server for Claude Code and Cursor) pushed by the server | done, tested with stand-ins for the provider and the helper |
| Bridge (Node, `bridge/`): drives `claude -p` stream-json, permissions, resume, skills, git | done, tested with a fake CLI |
| Updater (`installer/studio-update`): stable and beta channels, force, system step, upgrade scripts, dump, migrate, switch, health check, rollback, signatures; nightly server update (`studio-update system`) | done, tested end to end in a sandbox with a real git repository and stubbed system commands (`tests/installer/`, 24 checks); the server update's apt run not yet on a live server |
| Installer (`installer/install.sh`), system steps (`installer/lib/system.sh`) and helper (`installer/studio-admin`) | written for Ubuntu 24.04/26.04 and shellchecked, **not yet run on a live server** |
| SSH terminal (*Tools → SSH terminal*, admins and developers): saved connections with favourites, several terminals at once (tabs or side by side), Ed25519/RSA keys generated or imported (OpenSSH, PEM, PuTTY, with passphrase), passwords, host keys accepted on first use; a connection tied to a project shows up for its admins and developers, who connect with their own key or password | done, tested (Pest, and `ssh-gateway/` against a real SSH server); the `studio-ssh` unit and its Caddy route not yet run on a live server |
| Password manager (*Tools → Password manager*): nested personal folders, items encrypted (username, password, notes, TOTP with live codes), password generator; an item is tied to a project for the roles ticked (client, PM, dev) and shared with single people to view or edit, with a table of who sees it and why | done, tested |
| Drive (*Tools → Drive*): folders and files on Studio's disk, upload by button or drag and drop; a folder tied to a project opens by role to view or to view and edit, down the tree; administrators see everything, clients create nothing; *Copy / Move into the workspace* sends files or whole folders to the person's workspace of that project through the bridge | done, tested (the bridge's `PUT /files/upload` against real directories) |
| Claude: several accounts per person, subscriptions (the official `claude auth login` in a folder of their own in the person's home, through the SSH gateway) and API keys; a default, a choice per group of projects and per project, sent with every turn | done, tested (helper, gateway and bridge with stand-ins for the CLI); not yet run with the real CLI on a live server |
| Cursor: several subscriptions per person, each the official `agent login` in a folder of its own; a conversation can run on that CLI, and each person chooses the default | done, tested (helper, gateway and bridge with stand-ins for the CLI); not yet run with the real CLI on a live server |
| Groups of projects per person, with their Claude and git accounts; projects on any enabled git host, each workspace with the git account and commit identity of its repository's host | done, tested |
| Agent browser (Playwright MCP signed in to the preview with the person's pass), screenshots in the chat, subagents as cards, Studio's `code-reviewer` and `browser-qa` | done, tested (bridge against the fake CLI; Playwright MCP 0.0.83 run for real against a site behind a pass cookie: navigate, screenshot, console); not yet run on a live server |
| Conversations in a sandbox: worktree on its own branch, database copy, build and preview host; merge, push, remove; the side pane follows it | done, tested (bridge against real git repositories, Studio with stand-ins for the bridge and the helper); `sandbox-create`/`sandbox-delete` shellchecked, not yet run on a live server |
| Infrastructure connectors (*Administration → Connectors*, menu *Infrastructure*): AWS (EC2, all of Lightsail, RDS, S3, Route 53, CloudFront, cost), DigitalOcean, Hetzner Cloud, Cloudflare, Cipi servers, Boogle, Hackly, Johnny; multi-account, credentials encrypted and checked on save, enabled per connector, administrators only (members see what is attached to their projects) | done, tested against each API's documented requests and answers (HTTP fakes, AWS SigV4 against the AWS test suite); not yet run against the live services |
| Git dashboard (*Tools → Git*): GitHub, GitLab, Bitbucket Cloud, Azure DevOps in one page; personal accounts, several per person on any host, each with its commit identity | done, tested against each API's documented requests and answers (HTTP fakes); not yet run against the live services |
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
php artisan db:seed --class=DemoSeeder   # an agency, five projects with roles per project, a live conversation; password "password"
```

The screenshots in `docs/img/` (the docs and this page) are regenerated from that demo with `tools/screenshots/` (see its README).

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
