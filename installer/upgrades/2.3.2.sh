#!/usr/bin/env bash
# 2.3.2
#   - The "/" menu of the chat lists the skills a folder or two down too (the ones Claude Code
#     syncs into ~/.claude/skills/synced): the bridges read them, restart them on the new code.
set -euo pipefail

case "${1:-}" in
    before) ;;
    after) systemctl try-restart 'studio-bridge-*' >/dev/null 2>&1 || true ;;
esac
