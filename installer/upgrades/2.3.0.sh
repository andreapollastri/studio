#!/usr/bin/env bash
# 2.3.0
#   - "Always allow": each turn carries what runs without asking (allowed_tools: the role's
#     everyday commands and the person's own rules), and a permission answer may carry the rule
#     for the running session. The bridges speak it: restart them on the new code.
set -euo pipefail

case "${1:-}" in
    before) ;;
    after) systemctl try-restart 'studio-bridge-*' >/dev/null 2>&1 || true ;;
esac
