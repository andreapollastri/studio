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
# optional: CLAUDE_BIN, BRIDGE_PORT (4455), BRIDGE_IDLE_TTL (600s), BRIDGE_FLUSH_MS (200)
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

## Tests

```bash
npm test
```

`test/fake-claude.js` is a stand-in for the CLI that speaks the same protocol, so the session,
the permission round trip, resume and crash paths run without a real Claude.
The protocol notes this follows are Spatie Bloom's `docs/PROTOCOL.md`; `--permission-prompt-tool stdio`
is not documented by Anthropic, so pin the Claude Code version in the workspace image.
