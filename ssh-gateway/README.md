# Studio SSH gateway

A small Node service next to Studio that backs the *SSH terminal* page: every terminal in the
browser (xterm.js) is a WebSocket to this gateway, which opens the SSH session with
[ssh2](https://github.com/mscdex/ssh2).

- The page asks Studio for a one-time ticket (valid 60 s) and opens `/ssh/ws?ticket=…&cols=…&rows=…`.
- The gateway redeems the ticket at `POST /api/ssh/redeem` (bearer: HMAC-SHA256 of `APP_KEY` with
  `studio-ssh-gateway`) and only then gets host, user and the private key or password.
- A host seen for the first time waits for the person to accept its fingerprint; a host whose key
  differs from the accepted one is refused.
- At most `SSH_GATEWAY_MAX_PER_USER` (12) sessions per person; only the page's origin may connect.

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
| page → gateway | `{type: 'data', data}` · `{type: 'resize', cols, rows}` · `{type: 'hostkey', accept}` |
| gateway → page | `{type: 'status', state: 'connecting'\|'ready'}` · `{type: 'data', data}` · `{type: 'hostkey', fingerprint, host}` · `{type: 'error', message}` · `{type: 'closed'}` |

Close codes: 4401 unknown or spent ticket, 4403 another origin, 4429 too many sessions, 4502 Studio unreachable.

## Tests

```bash
npm test
```
