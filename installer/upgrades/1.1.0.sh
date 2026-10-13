#!/usr/bin/env bash
# 1.1.0
#   - Claude: a subscription is now the person's own `claude auth login` in their home,
#     never a token Studio keeps. Old setup-tokens leave every workspace's environment;
#     an API key moves to STUDIO_ANTHROPIC_API_KEY, which the bridge hands over only to
#     the conversations that run on it.
#   - Claude Code's policy must not force the claude.ai sign-in (forceLoginMethod): it would
#     refuse the API key a person may choose instead.
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
        # A beta build of 1.1 forced the claude.ai sign-in in Claude Code's policy, which refuses
        # an API key: take it out, so both accounts work.
        policy=/etc/claude-code/managed-settings.json
        if [ -f "$policy" ] && jq -e 'has("forceLoginMethod")' "$policy" >/dev/null 2>&1; then
            jq 'del(.forceLoginMethod)' "$policy" > "$policy.new" && mv -f "$policy.new" "$policy" && chmod 644 "$policy"
        fi
        systemctl try-restart 'studio-bridge-*' >/dev/null 2>&1 || true
        ;;
esac
