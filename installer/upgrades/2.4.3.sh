#!/usr/bin/env bash
# 2.4.3
#   - A conversation can run on Cursor's CLI (`agent -p`), and the sign-in terminal
#     in development runs `agent login` on this computer. Restart the bridges on the
#     new code. The SSH gateway, which opens that terminal, restarts with every update.
set -euo pipefail

case "${1:-}" in
    before) ;;
    after) systemctl try-restart 'studio-bridge-*' >/dev/null 2>&1 || true ;;
esac
