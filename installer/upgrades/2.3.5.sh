#!/usr/bin/env bash
# 2.3.5
#   - The bridges keep Claude Code while a question of a turn it started on its own waits for the
#     person, and a session whose process is gone is free again: restart them on the new code.
set -euo pipefail

case "${1:-}" in
    before) ;;
    after) systemctl try-restart 'studio-bridge-*' >/dev/null 2>&1 || true ;;
esac
