# Studio SSH gateway

A small Node service next to Studio that backs the *SSH terminal* page: every terminal in the
browser (xterm.js) is a WebSocket to this gateway, which opens the SSH session with
[ssh2](https://github.com/mscdex/ssh2).

- The page asks Studio for a one-time ticket (valid 60 s) and opens `/ssh/ws?ticket=…&cols=…&rows=…`.
- The gateway redeems the ticket at `POST /api/ssh/redeem` (bearer: HMAC-SHA256 of `APP_KEY` with
  `studio-ssh-gateway`) and only then gets host, user and the private key or password.
- A host seen for the first time waits for the person to accept its fingerprint; a host whose key
  differs from the accepted one is refused.
- At most `SSH_GATEWAY_MAX_PER_USER` (12) sessions per person, the waiting ones included; only the page's origin may connect.
- A shell outlives its page. When the WebSocket goes without a `close` (another page of Studio, a
  reload, a closed tab), the session waits `SSH_GATEWAY_LINGER` seconds (1800; 0 ends a shell with its
  page), keeping the last `SSH_GATEWAY_REPLAY_CHARS` (200000) characters it wrote. A page takes it back
  with `&resume=<token>` (the token of its `session` message) and a fresh ticket of the same person for
  the same connection: the ticket is still redeemed, so the person must still be signed in and allowed.
  The page that comes back gets the session, the one before it is let go (4409).

## Run

```bash
npm ci --omit=dev
APP_KEY=base64:… APP_URL=https://studio.example.com node bin/gateway.js
# optional: SSH_GATEWAY_HOST (127.0.0.1), SSH_GATEWAY_PORT (8082),
# SSH_GATEWAY_REDEEM_URL (http://127.0.0.1:8081/api/ssh/redeem), SSH_GATEWAY_ORIGIN (APP_URL's origin, empty: any)
```

On a server the `studio-ssh` unit runs it with Studio's `.env`; Caddy forwards
`https://studio.<domain>/ssh/ws` to it.

## Protocol (JSON text frames)

| Direction | Message |
| --- | --- |
| page → gateway | `{type: 'data', data}` · `{type: 'resize', cols, rows}` · `{type: 'hostkey', accept}` · `{type: 'close'}` (ends the session for good) |
| gateway → page | `{type: 'status', state: 'connecting'\|'ready'}` · `{type: 'data', data}` · `{type: 'hostkey', fingerprint, host}` · `{type: 'session', resume}` · `{type: 'replay', data}` (what the shell wrote, to a page that takes it back) · `{type: 'error', message}` · `{type: 'closed'}` |

Close codes: 4401 unknown or spent ticket, 4403 another origin, 4409 taken back on another page, 4410 nothing to take back (ended, or not this person's), 4429 too many sessions, 4502 Studio unreachable.

## Tests

```bash
npm test
```
