#!/usr/bin/env bash
# 2.3.6
#   - The Changes tab's wand asks the bridge for a commit message (POST /git/commit-message): restart
#     the bridges on the new code. The SSH gateway, which opens the Develop shell, restarts with every update.
set -euo pipefail

case "${1:-}" in
    before) ;;
    after) systemctl try-restart 'studio-bridge-*' >/dev/null 2>&1 || true ;;
esac
