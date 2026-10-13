#!/usr/bin/env bash
# 1.1.0
#   - Claude: a subscription is now the person's own `claude auth login` in their home,
#     never a token Studio keeps. Old setup-tokens leave every workspace's environment;
#     an API key moves to STUDIO_ANTHROPIC_API_KEY, which the bridge hands over only to
#     the conversations that run on it.
#   - The bridges speak the new protocol (the account per turn, binary uploads from the
#     drive): restart them on the new code.
set -euo pipefail

case "${1:-}" in
    before) ;;
    after)
        for env in "${STUDIO_ETC:-/etc/studio}"/workspaces/*.env; do
            [ -f "$env" ] || continue
            sed -i -e '/^CLAUDE_CODE_OAUTH_TOKEN=/d' -e 's/^ANTHROPIC_API_KEY=/STUDIO_ANTHROPIC_API_KEY=/' "$env"
        done
        systemctl try-restart 'studio-bridge-*' >/dev/null 2>&1 || true
        ;;
esac
