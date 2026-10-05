# Upgrade scripts

`studio-update` installs a new release of Studio in seven steps (see the
header of `installer/studio-update`). Most releases need nothing here: the
system step (`installer/lib/system.sh`, driven by `installer/requirements.env`)
already installs a new PHP or Node, the provider's CLI and missing packages,
and the migrations change the database.

When a release needs something else done as root on every server (move a
file, change a system setting, restart every workspace bridge because their
protocol changed, re-render the Caddy host of every project…), add
`installer/upgrades/<version>.sh` to that release, named after the version
without the `v` (`1.4.0.sh` for `v1.4.0`).

- The updater runs every script whose version is after the installed build's
  `VERSION` and up to the new one's, in version order, so a server that skips
  releases still runs each script once. On the beta channel several builds of
  `main` share a version: a script of that version runs again when its content
  changed since it last ran (the updater keeps a hash of each script it ran).
- Each script runs twice, as root, with the phase as its only argument:
  - `before`: the new release is built but not live yet; the old one still
    serves. Prepare what the new code will need.
  - `after`: the new release is live, migrations are done, Studio is still in
    maintenance mode. Adjust what depended on the old code.
- Environment: `RELEASE` (the new build's directory), `FROM` and `TO` (the
  builds: `v1.2.0`, `main-1a2b3c4`), `FROM_VERSION` and `TO_VERSION`,
  `STUDIO_HOME`, `STUDIO_ETC`.
- A script must be idempotent and exit non-zero on failure: a failure in
  `before` stops the update with nothing changed; a failure in `after` puts
  the previous release and the database dump back.

```bash
#!/usr/bin/env bash
# 1.4.0: workspace bridges speak protocol 2; restart them on the new code.
set -euo pipefail
case "$1" in
    before) ;;
    after) systemctl try-restart 'studio-bridge-*' ;;
esac
```
