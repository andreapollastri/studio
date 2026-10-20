#!/usr/bin/env bash
# 2.4.0
#   - Cursor subscriptions sign in through the SSH gateway, which restarts with the update.
#     The agents must not read those logins: add the deny rules to Claude Code's policy now,
#     rather than on the next sign-in. The Cursor CLI itself comes from the system step.
set -euo pipefail

case "${1:-}" in
    before) ;;
    after)
        f=/etc/claude-code/managed-settings.json
        [ -f "$f" ] || exit 0
        rule=
        for rule in 'Read(/home/*/.cursor/**)' 'Read(/home/*/.cursor-*/**)'; do
            jq -e --arg r "$rule" '(.permissions.deny // []) | index($r) != null' "$f" >/dev/null 2>&1 && continue
            jq --arg r "$rule" '.permissions.deny = ((.permissions.deny // []) + [$r])' "$f" > "$f.new"
            mv -f "$f.new" "$f"
        done
        chmod 644 "$f"
        ;;
esac
