#!/usr/bin/env bash
# 2.4.8
#   - A workspace bridge started under a release that an update later removed (only the
#     newest three stay) could not open its Terminal tab: "can't open file
#     …/releases/v2.4.3/bridge/src/terminal.py". The bridge now finds terminal.py in the live
#     release; restart the bridges once so that each runs the new code.
set -euo pipefail

case "${1:-}" in
    before) ;;
    after) systemctl try-restart 'studio-bridge-*' >/dev/null 2>&1 || true ;;
esac
