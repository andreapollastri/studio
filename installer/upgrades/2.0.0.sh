#!/usr/bin/env bash
# 2.0.0
#   - The agents' browser: the system step installs Playwright MCP and its Chromium; every
#     workspace's bridge learns where they are (BRIDGE_BROWSER_MCP, PLAYWRIGHT_BROWSERS_PATH).
#   - Sandboxes: each bridge learns where its sandboxes' worktrees go (BRIDGE_SANDBOX_ROOT).
#   - The bridges speak the new protocol (browser and sandbox per turn, images in tool results,
#     subagent tasks, the sandbox routes): restart them on the new code.
set -euo pipefail

case "${1:-}" in
    before) ;;
    after)
        for env in "${STUDIO_ETC:-/etc/studio}"/workspaces/*--*.env; do
            [ -f "$env" ] || continue
            name=$(basename "$env" .env); handle=${name%%--*}; slug=${name#*--}
            # the helper writes the variables (and leaves everything else as it is)
            echo '{}' | /usr/local/sbin/studio-admin workspace-env "$handle" "$slug" >/dev/null \
                || echo "2.0.0: the environment of $handle/$slug could not be updated" >&2
        done
        systemctl try-restart 'studio-bridge-*' >/dev/null 2>&1 || true
        ;;
esac
