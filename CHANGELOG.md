# Changelog

Every release is a `vX.Y.Z` tag whose `VERSION` says the same; servers install it on their next
update (*Administration → System*, or every night). What a release needs done as root on every
server is in `installer/upgrades/<version>.sh`.

## 2.3.4

Long Larapilot runs with subagents in the background, such as an inception reading a legacy codebase.

- **A conversation no longer stays on "Claude is working…" after Claude woke up on its own.** When subagents left
  running in the background finish, Claude Code resumes the session by itself, writes (which marks the conversation
  working) and ends that turn with a result the bridge used to drop, so nothing set the conversation free: Send stayed
  off and messages were refused. That result now ends the turn like any other.
- **Interrupt frees a conversation the bridge has nothing running for**, so one stuck that way can be used again.
- **Background work is not cut off.** The bridge released an idle Claude Code after `BRIDGE_IDLE_TTL` even with
  subagents still at work, and could release it right after they finished, while Claude was writing what came of
  them. It now waits while a background task runs, and counts the idle time from the CLI's last line.
- **No crash on a late line.** Output a released process still flushed (the answer to the usage question, for one)
  could throw in the bridge, or reach the parser of the next process; it is now ignored.
- **A short prompt in the Terminal.** The shell starts at `~/<workspace>`, a link to the workspace the bridge makes
  in the person's home the first time (`ws-ada@lab:~/loy-ezrvu7$` instead of the full `/srv/studio/workspaces/…`
  path); the link works over SSH too. Something else of that name in the home is left alone. A sandbox's shell keeps
  its full path.
- **Six shortcuts** above the Terminal: Backlog, Quality, Tests, Migrate, Fresh + seed and Optimize Clear
  (`optimize:clear`); the git, Composer, npm and inspection ones go, the shell has them all.
- The update restarts the workspace bridges (`installer/upgrades/2.3.4.sh`).

## 2.3.3

- **New projects get Larapilot's skills.** Boost publishes a package's skills and guidelines only when `boost.json`
  lists it under `"packages"`, and the non-interactive `boost:install` Studio runs lists none: a project created by
  Studio had Boost's five skills and none of Larapilot's, so `/larapilot-inception` reached Claude as plain words —
  no skill, no questions, nothing in the "/" menu. The bootstrap now lists `andreapollastri/larapilot` there and runs
  `boost:update` before the first commit, and stops if `.claude/skills/larapilot-inception` is not there.
- **A project created before 2.3.3** needs it once. In its workspace's Terminal:

  ```
  jq '.packages = ((.packages // []) + ["andreapollastri/larapilot"] | unique)' boost.json > boost.json.new && mv boost.json.new boost.json
  php artisan boost:update --no-interaction
  git add -A && git commit -m "Publish Larapilot's skills" && git push
  ```

## 2.3.2

- **The "/" menu finds the skills Claude Code syncs from your account.** They sit a folder down, in
  `~/.claude/skills/synced/<name>/SKILL.md`, and the menu only looked one level deep: Larapilot's skills could run
  in the chat and still be missing from the list. Skills are now looked for up to two folders down, in the workspace
  and in your Claude folder; a folder that is a skill is not looked into further.
- **The menu says why it is empty.** A failure to read the skills (the bridge, the conversation's Claude account)
  shows its reason and goes to the log, instead of an empty list; an empty list says where Studio looked. The page
  no longer keeps an empty or failed answer until it is reloaded: the next "/" asks again.
- The update restarts the workspace bridges, which read the skills (`installer/upgrades/2.3.2.sh`).

## 2.3.1

- The bridge checks the rules a turn may run without asking (`allowed_tools`) before it checks that no other turn
  is running: a rule that is not one is refused as a bad request whatever the conversation is doing, like an unknown
  mode or effort. Its test waits for the turn before it, which made it fail on slower machines.

## 2.3.0

Fewer "Claude wants to run Bash", a chat input that knows its skills, and a faster chat.

- **Always allow** on a permission card: the command runs, and is not asked again on that project, for that person.
  The rule is the one Claude Code suggests (`composer require …`, any package) or, for a plain command, its runner
  and name with any arguments; a line with `&&`, pipes or redirections gets no such button and asks each time. The
  running conversation stops asking at once (the rule goes back with the answer), and every later turn carries it as
  `--allowedTools`. *Model & mode* lists them under *Runs without asking*, where × takes one back.
- **The everyday commands run without asking** for admins, developers and PMs, in every mode but *Plan only*: the
  tests, Pint, PHPStan, Rector, `composer install`, `npm ci`, `npm run build`, `git add` and `git commit`, and a few
  read-only artisan commands (`allowed_tools` in `config/studio.php`). Nothing that leaves the workspace: a push or a
  `migrate:fresh` still asks.
- A card with no rule to offer (a file edit in *Ask before actions*) says where to let edits run instead.

### The chat input
- **"/" lists the skills and commands** the conversation can start, filtered as you type: the repository's
  (`.larapilot/skills`, `.claude/skills`, `.claude/commands`, a folder giving `folder:name`) and the person's own in
  their Claude folder on the server, with their description and argument hint; in a sandbox, the sandbox's. Enter or
  a click starts the highlighted one, Tab writes it so a request can follow, Esc closes the list.
- **Enter sends**, Shift+Enter starts a new line (it was ⌘⏎ / Ctrl+⏎).

### Questions that cannot reach you are said
- When Claude Code refuses one of Claude's questions on its own (in *Autopilot* nothing waits for a person), the chat
  now says so in a notice, with what to do, instead of Claude going on in silence; in *Autopilot* it also names the
  tools the classifier stopped. The bridge forwards Claude Code's `permission_denied` messages and the result's
  `permission_denials`; a refusal of a call that had a card (the person's Deny or Skip) is not repeated.
- A control request the bridge does not handle gets an error answer, so the turn goes on instead of waiting, and it
  is logged.

### A faster chat
- **No queue between a message and Claude.** The hand-off to the bridge (and the answer to a permission card) ran on
  Studio's one queue worker, after up to two seconds of its sleep and behind whatever was there: a workspace being
  built, a site deploying. It now goes right after the page's response.
- **Text streams in the browser.** Each slice of text Claude typed made a Livewire round trip that rendered the whole
  conversation again, five times a second, one after the other: on a long chat the text came late and in jumps. The
  page now reads the conversation's channel itself, grows the text in place, and asks for one refresh per burst of
  stored events. The bridge sends text every 100 ms (`BRIDGE_FLUSH_MS`, was 200), and the chat polls only when the
  websocket is down instead of every four seconds on top of it.
- **Claude Code is ready before you press Enter.** Focusing the input starts the CLI of the next turn on the bridge
  (`POST /conversations/{key}/warm`), with the model, mode, account and MCP servers it will run on, so the first
  message after a pause or a change of settings does not wait for it to boot and resume.
- The update restarts the workspace bridges, which carry the rules (`installer/upgrades/2.3.0.sh`).

## 2.2.0

A real terminal, and a file manager that does what one expects.

### Terminal
- **The Terminal tab is a shell**: your login shell on a real terminal, in the workspace (or the sandbox of the open
  conversation), as the workspace's Linux user, the one Claude Code runs as. A command that asks for a password gets
  it (`php artisan … add` with its prompts no longer fails with "run the command in an interactive shell"), and
  colours, Ctrl-C, `tinker`, `vim`, `htop` and pipes work. It was a list of allowed commands run without a terminal.
- The bridge runs it on a pseudo-terminal of Python's standard library (`bridge/src/terminal.py`, nothing native to
  build; the system step installs `python3`), and the SSH gateway carries it to the browser over its WebSocket, with
  a one-time ticket Studio checks again when it is redeemed. The session survives switching tabs; *New shell* starts
  another when it ends; phones get the key bar of the SSH page. The shell's environment leaves out the bridge's
  token and the API key it hands to conversations; git still answers with the person's token.
- The shortcuts stay, and type their command into the shell. At most `BRIDGE_MAX_SHELLS` (8) shells per workspace.

### Files
- **Upload** files or a whole folder (its tree kept) from the arrow at the top of the tree, from a folder's menu, or
  by dropping them from the computer onto the tree or onto a folder: up to `STUDIO_DRIVE_MAX_UPLOAD_MB` (100) a file
  and a thousand files at a time, in batches. A file already there waits for *Replace* or *Keep the old*.
- **Move** a file or folder by dragging it onto another folder, or with *Move to…* and a folder picker; open editor
  tabs follow it.
- **Compress to zip** next to a file or folder, **Extract here** next to a zip (into the one folder at its top, or a
  folder named after it; never over anything, nothing outside the workspace, refused past `BRIDGE_UNZIP_MAX`, 2 GB
  unpacked), and **Download** a file.

### Updating
- The update restarts every workspace's bridge on the new code (`installer/upgrades/2.2.0.sh`): open conversations
  resume on their next message.

## 2.1.2

Fresh installs work again, on Ubuntu 26.04 too.

- **A fresh install stopped at `composer install`** (`package:discover … returned with error code 1`): 2.1 set how
  long "remember me" lasts by building the sign-in guard as Studio booted, and with it the encrypted session store,
  which needs `APP_KEY`; the installer generates the key only after Composer, so every `artisan` command before it
  failed. The thirty days are now the `remember` option of the web guard (`config/auth.php`, still
  `STUDIO_REMEMBER_MINUTES`), read when the guard is first used. A test boots Studio with no key.
- **A false error about SSH's ports**: on a server whose `/etc/ssh/sshd_config.d/` has no `.conf` file (Ubuntu 26.04),
  the installer printed `ERROR … system.sh:327` twice while reading which ports sshd listens on. It carried on and the
  firewall was right; it no longer says so.
- **Why Composer or npm failed is on screen**: the installer sends their output to `/var/log/studio-install.log`
  like apt's, and shows its last lines when one fails; the updater shows them in its log. Their own `--quiet` used to
  hide the reason, leaving only the exit code.
- **postcss-selector-parser 7.1.6** in place of the 6.0.10 that `@tailwindcss/typography` pins (quadratic parsing of
  long flat selectors, advisory for every version before 7.1.6). It runs only when the assets are built, on Studio's own CSS,
  so no installation was exposed; the built CSS is byte for byte the same.

## 2.1.1

Claude Code's sign-in, kept to the letter of Anthropic's terms for a platform that hosts it.

- **The code goes into the terminal, not into Studio.** The sign-in dialog no longer has a field of its own for
  Anthropic's code: the person pastes it into the terminal where `claude auth login` waits for it (⌘V or Ctrl+V), so
  Studio is the terminal and nothing more, and no part of the exchange passes through a Studio control. The link to
  Anthropic's page is still offered as a button (the one Claude Code prints; clicking it in the terminal works too).
- **Plain words, no social-login look**: the button is *Sign in to Claude Code*, with a terminal icon, and the dialog
  says what runs where. The form says a subscription is the person's own, used by them alone and billed to them.
- **Docs and README** gain *Anthropic's terms*: the operator of an installation accepts the Commercial Terms, one
  account per person billed to that person, a subscription is never shared (a Team or Enterprise plan or API keys for a
  team), nothing in Claude Code is modified or restricted, and Studio is an independent project with no affiliation to
  Anthropic. The installer's summary says the same in a line.
- Error messages of the bridge send people to *Settings → Accounts*, where the page now is.

## 2.1.0

Hardening, for a Studio on the open internet with every token, key and password of the team in it.

### Signing in
- **Two-factor can be required**: *Administration → System → Security* asks it of the administrators or of
  everyone. Whoever must and has not set it up is sent to *Settings → Security* and nowhere else (the two-factor
  components alone answer Livewire), and cannot turn it off; *Administration → People* flags who still has to.
- **Passwords** of twelve characters at least, refused when they appear in the leaked lists (Have I Been Pwned's
  range API: five characters of the hash leave the server, never the password). Changing yours closes your other
  sessions and kills every other remember-me cookie; a session also ends by itself when the password changes
  elsewhere (an administrator's reset).
- **Sign-in attempts**: five a minute per account and address as before, and twenty a minute per address whatever
  the accounts tried. "Remember me" lasts thirty days, not Laravel's four hundred. *Forgot your password?* answers
  the same whether the address is an account or not.
- **The security log**, `storage/logs/security.log`: every sign-in, failed attempt, second factor, passkey, password
  change, door and policy, with the address and browser it came from. The latest of it in *Administration →
  System → Security*.

### The server
- **fail2ban** reads the security log and bans an address from the web ports after ten refusals in a quarter of an
  hour; sshd's jail through the journal. **logrotate** keeps the log half a year.
- **Caddy's admin API** moves from `127.0.0.1:2019`, where any process of the server could reconfigure what serves
  every host, to a socket in `/run/caddy` that only root and caddy enter. The update restarts Caddy once for it.
- **The endpoints for Caddy, the bridges and the SSH gateway** (`/api/tls/ask`, `/api/site-auth`,
  `/api/bridge/*`, `/api/ssh/redeem`) answer the loopback only: 404 from Caddy on `https://studio.<domain>`, and
  404 from the app from any other address (`STUDIO_INTERNAL_LOOPBACK_ONLY=false` for a bridge in a container).
- **Every page** carries `Strict-Transport-Security`, `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options`,
  `Referrer-Policy` and a `Content-Security-Policy` (`frame-ancestors 'self'`, `object-src 'none'`, `base-uri` and
  `form-action` to Studio; scripts and styles from Studio; previews from any https host). No `Server` or
  `X-Powered-By` header. The preview pane keeps working: the project hosts' own `frame-ancestors` still let Studio in.
- **Sessions** encrypted in the database, the session cookie named `__Host-studio_session` (a project site on a
  sibling host cannot plant one), the password asked again after an hour on the sensitive pages
  (`AUTH_PASSWORD_TIMEOUT`). The upgrade adds the three to `.env` where missing: everyone signs in again once.

## 2.0

### Databases
- **Tools → Databases**, a database client in the spirit of TablePlus: MySQL, MariaDB, PostgreSQL, SQLite, Redis and
  Valkey, reached from Studio's server directly or through an SSH tunnel. Administrators and developers add
  connections; anybody reaches those shared with their role on a project. It asks for the password again, as the SSH
  terminal does.
- **Connections from a link**: paste `mysql://…`, `postgres://…`, `redis://…`, `rediss://…`, `sqlite+ssh://…` or a
  TablePlus `mariadb+ssh://ssh_user:ssh_pw@ssh_host/user:pw@127.0.0.1/db` (with `?name=…&env=…&tls=…`), or the `DB_` and
  `REDIS_` lines of a Laravel `.env`: the form fills in, and the SSH host of the link becomes one of your SSH
  connections (or reuses the one with the same host, port and user). Each connection copies its own link back.
- **Groups with colours and favourites**, each person's own: the connections shared with you go in your groups too.
  An environment tag on each (local, development, testing, staging, production), as a bar across the client.
- **SSH tunnels** through the SSH page's connections: one OpenSSH master per connection and credential, kept open while
  used, each database forwarded to a Unix socket only Studio's user can open (never a port of 127.0.0.1 a workspace could
  reach); the host key pinned, a new one shown to accept on *Test*. The owner's SSH credential opens the tunnel for the
  members (who reach that database only), or each member's own.
- **Sharing by role**: no access, read, or read and write for clients, PMs and developers of a project; members use the
  owner's database credentials without seeing them. Read only: only statements that read, in a read-only transaction.
  Production: changes locked until unlocked for the session, and every statement that writes asks first. Changes on
  shared and production connections go to Studio's log with who made them.
- **The client**: databases and schemas, tables and views with their size; rows sorted, filtered (=, contains, in,
  null, raw SQL…) and paged; cells edited in place, rows added, edited, copied and deleted (by primary key, `ctid` or
  `rowid`); structure (columns, indexes, foreign keys, checks) and DDL; new tables, columns and indexes, renames, drops
  and truncates as SQL to review before it runs; new and dropped databases.
- **SQL editor**: the selection or everything, one result per statement (1,000 rows shown, all of them exported), the
  failing statement named; history and saved queries per person.
- **Import & export** on a queue of their own (`studio-databases` worker): SQL dumps with `mariadb-dump`/`mysqldump`,
  `pg_dump` or `sqlite3 .dump`, else written by Studio; tables or a query result as CSV or JSON (a zip for several);
  `.sql`/`.sql.gz` run with `mariadb`, `psql` or the host's `sqlite3`, else statement by statement; CSV and JSON rows
  into a table by column name; Redis keys to and from JSON. Exports kept a week to download or save to the drive;
  files from the drive importable.
- **Redis and Valkey**: keys by pattern and type (SCAN), each value by its type to change, TTL, rename, delete; a
  console like `redis-cli`; the server's INFO.
- The installer adds `sqlite3`, `mariadb-client`, `postgresql-client` and the `studio-databases` worker; the demo seeds a
  shop database and connections in groups; docs and screenshots.

### Backups
- **Tools → Backups**, for administrators: every backup in one place, from a folder over SSH or a bucket to a bucket or
  a folder on another host, in any direction (SSH → S3, S3 → S3, S3 → SSH). Each project has the same panel, filtered
  to its own, in a new *Backups* tab.
- **Storage**: S3 buckets (Amazon S3, Johnny, MinIO, Garage, any S3-compatible; the keys of an AWS account of the
  connectors fill the form), folders over SSH reached with the system keys shared with projects (the host key accepted
  on the first check, then required), and a new Johnny bucket with a key of its own in one click. Keys stored encrypted.
- **Three ways**: an *archive* (`.tar.gz` made on the SSH host and streamed to the destination), a *snapshot* (a dated
  copy of every file) or a *mirror* (kept identical). A command before the copy (a database dump), patterns left out.
- **Unlimited schedules** per backup: hourly, daily, some weekdays, monthly or any cron, in the backup's timezone, with
  ready-made plans. The *Schedule* tab: every schedule with its cron and next run, the coming week hour by hour, what
  starts next.
- **Retention**: the latest *n*, plus the newest of each of the last days, weeks, months and years; after each run what
  no rule keeps is deleted at the destination, and each snapshot says which rules keep it.
- **Runs**: live log, size and files, download of an archive (a link valid one hour), restore into any storage, delete.
- Run from Studio's server: `studio:backups` every minute starts what is due; a `studio-backups` worker serves a queue
  of its own (hours per run, one at a time per backup). The installer adds `rclone` and the worker.

### A project's infrastructure, together
- **Overview**: the *Infrastructure* tab opens on a card for each thing attached (a Cipi app's release, load, disk and
  agent; a zone's records; a Boogle project's open exceptions; a Hackly target's findings; a bucket's size; any other
  row's columns), with what needs attention, the MCP servers the chats get, the SSH hosts and the backups.
- **Attach from the project**: *Attach → Pick from an account…* finds the row of any account (Cloudflare, Cipi,
  Boogle, Hackly, Johnny, the clouds) without leaving the project; *Everything but destructive* ticks the usual actions.
- **A Cipi app as a hub**: releases on disk with the live one (from the Cipi agent), deploy history, deploy and roll
  back with the job's output, logs (filtered, paged, refreshed), the `.env` (secrets hidden until shown; set and
  remove keys), the server's load and the app's disk, a database backup, and every other action of the connector.
- **Anonymized database**: a developer asks the Cipi agent's anonymizer for a copy of the production database with the
  personal data replaced, mailed as a download link. The anonymization file (`~/.db/anonymization.json`) is edited in
  a table, started from the server, from the live schema or from a Laravel start, written back over SFTP as the app's
  user (or downloaded, or generated by Cipi from the schema), and audited.
- **MCP, native**: the Cipi agent's MCP server reaches the project's chats as `cipi-<app>` for those who act on the
  app, its token read from the app's `.env` (or typed in); the Cipi server's own MCP server, when an administrator turns
  it on. Both listed in *Settings → MCP servers*. New Cipi actions, grantable per role: releases, disk usage, database
  backup, anonymized copy, generate and audit `anonymization.json`.
- **Remote terminal**: the workspace's *Terminal* switches between *Local* (commands in the workspace) and *Remote*: a
  real shell on the project's SSH hosts through the SSH gateway, with your own credential, after your password, kept
  open while the pane shows something else. An administrator adds a Cipi app's user as an SSH connection of the
  project from the app's *Agent & access*.

## 2.0.0

### The team works in projects, the administrators decide who sees what
- **A role per project**: a person is an administrator, who sees and runs everything, or a member whose role (PM,
  developer, client) is the project's, set in its *Settings → Members & roles*: a developer here may be the PM there
  and a client elsewhere. The chat and the workspace, a new conversation's preset and modes, the project's MCP
  servers, the passwords, folders and SSH connections shared by role, and the infrastructure attached to the project
  all read the role on that project. *Administration → People* says *Administrator* or *Member · <role> by default*,
  the role a new membership starts with; every membership keeps the role its person had until now.
- **Infrastructure attached to projects**: on any connector's page, *Attach to a project…* in a row's menu puts that
  Droplet, server, zone, Cipi app or Boogle project in the project's *Infrastructure* tab, with the roles that see it,
  the roles that act on it and the actions they may run, its child lists' too (a zone's DNS records). Members see that
  row alone, read with the administrators' account, which they never see, and run only what was handed to their role;
  every action is logged with the project resource. A badge on the row says where it is attached.
- **The project is a hub**: tabs in its header for the *Workspace* (as before), the *Repository* (pull requests,
  issues, branches, pipelines and releases of its repository, with the person's own account on its host), the
  *Infrastructure* and the *Access* (passwords, SSH connections and folders tied to it) of the person's role, and the
  *Settings*, for administrators, which leave the dialog of *Administration → Projects* for a page of their own.
- **A key for every project**: new projects get one from their name and six random characters (`api-9usaf9`); the
  name is free and two clients may both have an "API". The key names the site, the previews, the Linux user, the
  databases and the folders, and never changes; existing projects keep theirs.
- **Home**: an inbox across every project: conversations that ask something or failed, finished since last opened,
  working; failed workspaces and sites; and, read after the page, what waits on each git account. New people get the
  three steps that make Studio work for them.

### Accounts and git, simpler
- **Settings → Accounts** (was *Connections*): Claude, Git and SSH keys in one place. Git accounts are added, checked
  and removed there; *Tools → Git* is one dashboard across all of them, whatever their host.
- **Every git host is there**: no installation provider and no git switches any more; `studio:git-provider` is gone.
  `STUDIO_GIT_PROVIDER` only tells the installer the host of the first administrator's token.
- **Service accounts**: in *Administration → Connectors → Git hosting*, the team's own tokens create repositories,
  register webhooks and deploy sites, and keep doing it when an administrator leaves. A project says which account
  deploys it (*Settings → General → Deployed with*); the list of projects shows a personal token in amber.
- **Navigation**: the menu keeps its groups, with *Home* and *Projects* under Studio and one *Git* under
  Tools. A project's tabs sit on a row of their own under its header and scroll sideways on a phone; the
  button that slides the workspace pane over the chat on a tablet, and the phone's tab bar, say *Preview*.
  The conversation list is as tight as in 1.0 again (the delete button's tooltip took a line under each).


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
- **Tools → Git**: one dashboard, across every account and host, of what waits for you on the
  git hosts (pull requests asking for your review, your own, issues and work items assigned to
  you, notifications and to-dos) and every repository with its pull requests, issues, branches,
  pipelines (Actions, GitLab CI, Bitbucket Pipelines, Azure Pipelines) and releases. Read a pull
  request with its conversation and its diff, comment, approve or request changes, ask for
  reviewers, merge (merge commit, squash, rebase; GitLab auto-merge, Azure auto-complete), close
  and reopen; create repositories, branches, pull requests, issues and releases; start, retry and
  cancel pipelines. Titles open the provider in a new tab.
- **Personal accounts**: everyone adds their own, as many as they work with and on any host,
  each with the name and email its commits carry (from the provider when left empty). Nobody
  else sees them; they live in *Settings → Accounts → Git*. The token a person had saved in 1.1
  becomes their first account, on the host the installer was given.
- Lists that follow a provider's "next page" link no longer read the first page again (Boogle,
  DigitalOcean, Hackly).

### Accounts and groups
- **Projects on any git host**: the project form picks a service account or one of the
  administrator's git accounts (the address typed in chooses the one on its host); that account
  creates or reads the repository, registers the push webhook and deploys the site. Webhooks are
  verified by the host of each project's repository.
- **The git identity follows the account**: a workspace clones, commits and pushes with the
  person's account on the host of its repository (a client's GitLab, the company's GitHub, a
  personal one) and its name and email in the clone's `git config`; the agent's CLI gets that
  token, and the CLI of a host other than the installer's is installed the first time it is
  needed.
- **Several Claude accounts per person**: subscriptions (each signed in with Claude Code's own
  sign-in in a folder of its own in the person's home, sharing sessions, agents and skills) and
  API keys, side by side in *Settings → Accounts*, one of them the default; the plan's usage is
  kept per account. The account goes with every message, so a change needs no restart, and an API
  key is no longer left in the workspace.
- **Groups of projects**, each person's own (Personal, Open source, Work, Clients…), in the order
  they give them: a group picks the Claude account and the git account of its projects (a git
  account only where the repository lives on its host), a project may pick others. Every card says
  what the project runs on and why; changing a git account restarts only the workspaces it moves.
- The account a person had in *Settings → Connections* (1.1) becomes their first Claude account and
  their default; their git token, their first git account (see Git hosting). Their handle no
  longer depends on a git login: it is given once, from the first git account or the e-mail.

### Server
- The system step installs Playwright MCP (pinned in `requirements.env`) and its Chromium in
  `/usr/local/lib/studio/browsers`, with an AppArmor profile so Chromium keeps its sandbox on
  Ubuntu 24.04+. New helper commands `sandbox-create` and `sandbox-delete`; `workspace-delete`
  removes the workspace's sandboxes. `installer/upgrades/2.0.0.sh` gives every bridge its new
  variables and restarts it.
- The helper's `claude-status`, `claude-login` and `claude-logout` take the folder of a Claude
  account; new `claude-forget` (removes one, never following its links) and `git-cli <provider>`;
  `workspace-env` also re-applies the commit identity. Claude Code's managed settings deny reading
  `~/.claude-*` too (patched in place on existing servers).

### Password manager
- **Shared folders**: *Sharing* in a folder's menu shares it as an item is shared, with a project for the
  roles ticked and with single people, to view or to edit; its items, the folders inside it and what is added
  later go with it. Who receives it finds it under *Shared folders*; who may edit changes the items and adds
  new ones, which stay the owner's. A project's folder shows under the project and in its *Access* tab, and an
  item's sharing table says which folder brings it to whom.

### Johnny
- **A connector for [Johnny](https://johnny.web.ap.it)**, S3-compatible storage on one's own server, through its
  panel's API, any number of panels: buckets, the keys that reach each with their permissions (give or take read,
  write, owner), keys (the secret shows once), and a bucket with its own key and the `.env` lines of a Laravel `s3`
  disk in one step. Turned on in *Administration → Connectors*; a bucket attaches to a project like anything else.

### Domain and visibility
- **`studio-domain`**, root's tool for the domain on the server: `status` (the domain, whether the wildcard
  points here, every certificate Caddy keeps with its days left, issuer and whether a host is still served),
  `check [domain]`, `change <domain>`, `renew [host|all]`, `prune`. `change` moves the dashboard and every
  site, preview and sandbox to the new domain (their Caddy hosts, the `APP_URL`/`REVERB_HOST` of their `.env`,
  the rows of the database) and registers every push webhook again; a backup of every file goes to
  `/var/backups/studio-domain`, and a step that fails puts everything back. Passkeys belong to the old host:
  people add them again.
- **A hidden dashboard**: *Administration → System → Domain & visibility* switches `studio.<domain>` from public
  to hidden. Hidden, whoever is not signed in gets the page of an unknown address, on every path, and the
  welcome page of unknown names no longer links to Studio. People come in through a secret link (shown there,
  with a new account's password, and to root by `studio-recover door`), once per browser; a signed-in session
  and a good password reset link are enough too. `studio-recover public|hidden` switches it from the server.

- **Clients' domains**: *Administration → Domains* takes any other domain (with its subdomains or not), shows the
  records to create, and accepts it once its DNS points at this server; checked again every day, one that stops
  pointing here gets no new certificate. In a project's *Settings → Server → Addresses*, the site goes at such a
  domain or a subdomain of it (`staging.client.com`), with `<key>.<domain>` kept for the team, and the previews and
  sandboxes under it (`<person>-<key>.client.com`); the server moves the site, every workspace and their `APP_URL`.
  Studio now finds a host by the addresses it keeps, on whatever domain, and the helper checks every host it is given.
- **Public sites**: a project's *Settings → Who opens the hosts* makes the site public, or the site and every
  workspace preview and sandbox: no Studio login, anyone with the address gets in. Off by default; the address lists
  still apply, and `/larapilot` always asks for the login. A public host shows no debug page: the site gets
  `APP_DEBUG=false`, public previews and sandboxes run as `APP_ENV=staging`, `APP_DEBUG=false`, and go back to
  `local` with debug behind the login.

### Fixed
- Creating a workspace stopped on the server with `studio-admin stopped (exit 1) at: [ -n "$claude_api_key" ]`
  for everyone without an API key: Studio sends the key empty since it goes with each turn, and the empty test
  ended the helper's function under `set -e`. The helper of this release writes the workspace's environment
  whether a key is sent, sent empty or not sent (`tests/installer/studio-admin.test.sh`).

### Upgrading from 1.1
Nothing to do by hand: the update migrates (every membership keeps the role its person had, the
installation's git provider becomes nothing more than the host of the first administrator's token) and
gives every bridge its new variables (`installer/upgrades/2.0.0.sh`). Existing projects keep their keys
and their hosts. Worth doing once it is live: add a service account in *Administration → Connectors →
Git hosting* and choose it in each project's *Settings → General*, then attach the servers and zones
of each project from the connectors' pages.

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
