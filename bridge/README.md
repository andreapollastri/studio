# Studio bridge

A small Node service that runs **inside** a workspace and drives Claude Code for Studio.

- Starts `claude -p --output-format stream-json --input-format stream-json --permission-prompt-tool stdio …`
  once per conversation and keeps its stdin open; the next turn is one more line.
- Normalises the CLI's stream into a handful of events (`init`, `text_delta`, `text`, `thinking`,
  `tool_use`, `tool_result`, `permission_request`, `permission_resolved`, `result`, `status`,
  `error`, `raw`) and posts them to Studio in small batches.
- Holds a permission question (`control_request` / `can_use_tool`) until Studio answers it.
- Releases the process after an idle period; the next turn starts a new one with `--resume`.
- Exposes the repository's custom skills (`.larapilot/skills`, `.claude/skills`) and the git state.

Zero dependencies, Node 20+.

## Run

```bash
BRIDGE_TOKEN=secret WORKSPACE_DIR=/path/to/laravel-project node bin/bridge.js
# optional: CLAUDE_BIN, BRIDGE_PORT (4455), BRIDGE_IDLE_TTL (600s), BRIDGE_FLUSH_MS (200),
# BRIDGE_BROWSER_MCP (playwright-mcp), BRIDGE_BROWSER_EXECUTABLE, BRIDGE_BROWSER_NO_SANDBOX, BRIDGE_SANDBOX_ROOT
```

Studio passes the callback (URL and token) with every turn, so the bridge knows nothing about
Studio in advance.

## HTTP API (bearer `BRIDGE_TOKEN`, except `/health`)

| Method | Path | Body | Answer |
| --- | --- | --- | --- |
| GET | `/health` | | version, uptime (open) |
| GET | `/info` | | version, workspace, sessions |
| POST | `/conversations/{key}/turns` | `{text, session_id?, permission_mode?, model?, callback: {url, token}}` | 202, session snapshot; 409 while a turn runs |
| POST | `/conversations/{key}/permissions/{request_id}` | `{behavior: allow\|deny, message?}` | snapshot |
| POST | `/conversations/{key}/interrupt` | | snapshot |
| GET | `/conversations[/{key}]` | | snapshots |
| GET | `/skills` | | `{skills: [{name, description, source}]}` |
| GET | `/git` | | `{branch, status[], stat, diff}` |
| PUT | `/files/upload?path=…&overwrite=0\|1` | raw bytes | 201 `{path, size}`; 409 when it exists (without overwrite), 413 over `BRIDGE_UPLOAD_MAX` (512 MB) |
| GET | `/files/download?path=…` | | the file's bytes; 400 for a folder |
| POST | `/files/zip` | `{paths: [...]}` (one folder's items) | 201 `{path, size}`: `<name>.zip` or `Archive.zip` next to them, numbered when taken |
| POST | `/files/unzip` | `{path}` | 201 `{path}`: the one item at the archive's top, or a folder named after it; 413 over `BRIDGE_UNZIP_MAX` (2 GB unpacked) |
| GET | `/shell?cols=…&rows=…` | `Upgrade: studio-shell` | 101, then one socket of NDJSON: `data`, `resize` in; `data`, `exit` out. The login shell on a terminal (`src/terminal.py`, python3); at most `BRIDGE_MAX_SHELLS` (8) |
| POST | `/sandboxes` | `{name: "c<id>", branch, base?}` | 201, the sandbox's state: a worktree on `branch` under `BRIDGE_SANDBOX_ROOT` |
| GET | `/sandboxes/{name}` | | `{path, branch, workspace_branch, ahead, behind, files, status, stat}` |
| POST | `/sandboxes/{name}/commit` | `{message}` | everything committed on its branch (nothing to commit is fine) |
| DELETE | `/sandboxes/{name}?branch=0\|1` | | stops the agent in it, removes the worktree (and the branch) |

With `X-Studio-Sandbox: c<id>`, the `/git`, `/files`, `/run` and `/shell` routes work in that sandbox's worktree.
A turn may carry `browser: {url, cookies}` (the agent gets Playwright MCP as the MCP server `browser`,
signed in with those cookies, when `BRIDGE_BROWSER_MCP` names its command) and `sandbox: "c<id>"`
(Claude Code runs in that worktree). Studio's subagents (`--agents`) and context (`--append-system-prompt`)
go with every process; `BRIDGE_STUDIO_AGENTS=0` leaves them out. Tool results carry their images
(`images: [{media_type, data}]`), and subagents their `task` events.

## Tests

```bash
npm test
```

`test/fake-claude.js` is a stand-in for the CLI that speaks the same protocol, so the session,
the permission round trip, resume and crash paths run without a real Claude.
The protocol notes this follows are Spatie Bloom's `docs/PROTOCOL.md`; `--permission-prompt-tool stdio`
is not documented by Anthropic, so pin the Claude Code version in the workspace image.
