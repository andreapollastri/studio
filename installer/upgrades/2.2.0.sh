#!/usr/bin/env bash
# 2.2.0
#   - The Terminal tab is a real shell: each workspace's bridge runs it on a terminal
#     (bridge/src/terminal.py, python3 from the system step) behind a new route, GET /shell.
#   - The Files tab uploads, moves, downloads, zips and unzips: new bridge routes too.
#   Restart the bridges on the new code; the SSH gateway, which carries the shell, restarts
#   with every update.
set -euo pipefail

case "${1:-}" in
    before) ;;
    after) systemctl try-restart 'studio-bridge-*' >/dev/null 2>&1 || true ;;
esac
