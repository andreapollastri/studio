#!/usr/bin/env bash
# 2.3.4
#   - The bridges end the turns Claude starts on its own (background subagents finishing), keep the
#     CLI alive while background tasks run and while it writes after them, and ignore what a process
#     they let go still flushes: restart them on the new code.
set -euo pipefail

case "${1:-}" in
    before) ;;
    after) systemctl try-restart 'studio-bridge-*' >/dev/null 2>&1 || true ;;
esac
