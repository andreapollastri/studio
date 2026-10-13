#!/usr/bin/env bash
#
# The parts of installer/studio-admin that can run without root: the person's Claude config
# directories (one per subscription, sharing .claude's transcripts and settings), the managed
# policy, the git identity a workspace gets, and git-cli's answer. Each function is taken out
# of the helper as it is and run against a temporary directory.
#
#   bash tests/installer/studio-admin.test.sh
#
# The functions under test arrive through eval: the variables they read look unused to shellcheck.
# shellcheck disable=SC2034
set -euo pipefail
HERE=$(cd "$(dirname "$0")" && pwd)
ADMIN="$HERE/../../installer/studio-admin"
T=$(mktemp -d "${TMPDIR:-/tmp}/studio-admin-test.XXXXXX")
trap 'rm -rf "${T:?}"' EXIT

fail() { printf '\033[31mFAIL\033[0m %s\n' "$*"; exit 1; }
ok() { printf '\033[32mok\033[0m   %s\n' "$*"; }

bash -n "$ADMIN" || fail "bash -n installer/studio-admin"
ok "the helper parses"

# extract NAME — the definition of one function of the helper, as written there (a here-document
# inside it is skipped over: its closing brace is not the function's)
extract() {
    awk -v n="$1" '
        !on && index($0, n "() {") == 1 { print; if ($0 ~ /}$/) exit; on = 1; next }
        on && doc != "" { print; if ($0 == doc) doc = ""; next }
        on { print; if (match($0, /<<.?[A-Z]+/)) { doc = substr($0, RSTART + 2, RLENGTH - 2); gsub(/[^A-Z]/, "", doc) } else if ($0 == "}") exit }
    ' "$ADMIN"
}
for fn in valid_claude_dir claude_dir_layout claude_dir_remove ensure_claude_dir ensure_claude_policy field has_field git_identity cmd_git_cli set_env unset_env write_workspace_env workspace_agent_env; do
    def=$(extract "$fn"); [ -n "$def" ] || fail "no function $fn in studio-admin"
    eval "$def"
done

# the helper's fail answers on its stdout and exits: here, in a subshell, the same answer
admin_fail() { printf '{"ok":false,"error":"%s"}\n' "$1"; exit 1; }

# ---------------------------------------------------------------- the Claude directories

for d in .claude .claude-work .claude-a1 ".claude-$(printf 'a%.0s' $(seq 40))"; do
    ( fail() { admin_fail "$@"; }; valid_claude_dir "$d" ) >/dev/null || fail "valid_claude_dir refused $d"
done
for d in .claude- .claude-Work claude ../.claude .claude/x .claudex .claude-a.b ".claude-$(printf 'a%.0s' $(seq 41))" ""; do
    if ( fail() { admin_fail "$@"; }; valid_claude_dir "$d" ) >/dev/null 2>&1; then fail "valid_claude_dir accepted '$d'"; fi
done
ok "a Claude directory is .claude or .claude-<a-z0-9, 1-40>"

H="$T/home/ws-maria"; mkdir -p "$H"
claude_dir_layout "$H" .claude
[ -d "$H/.claude" ] && [ ! -e "$H/.claude-work" ] || fail ".claude alone for the default account"
ok "the default account is .claude, nothing linked"

mkdir -p "$H/.claude/projects/-srv-app"; echo transcript > "$H/.claude/projects/-srv-app/s1.jsonl"
echo '{"theme":"dark"}' > "$H/.claude/settings.json"
# what the CLI may have written in the other directory before it was linked
mkdir -p "$H/.claude-work/projects/-srv-other" "$H/.claude-work/skills/mine"
echo old > "$H/.claude-work/projects/-srv-other/s0.jsonl"
echo '{"theme":"light"}' > "$H/.claude-work/settings.json"
echo '{"oauth":"work"}' > "$H/.claude-work/.credentials.json"

claude_dir_layout "$H" .claude-work
claude_dir_layout "$H" .claude-work   # idempotent
for e in projects todos shell-snapshots agents commands skills plugins settings.json CLAUDE.md; do
    [ -L "$H/.claude-work/$e" ] || fail "$e is not a link in .claude-work"
    [ "$(readlink "$H/.claude-work/$e")" = "../.claude/$e" ] || fail "$e points to $(readlink "$H/.claude-work/$e")"
done
for e in projects todos shell-snapshots agents commands skills plugins; do
    [ -d "$H/.claude/$e" ] && [ ! -L "$H/.claude/$e" ] || fail "$e is not a directory in .claude"
done
[ ! -e "$H/.claude/CLAUDE.md" ] || fail "a file .claude does not have is left absent"
[ -f "$H/.claude-work/projects/-srv-app/s1.jsonl" ] || fail "the transcripts of .claude are seen from .claude-work"
[ "$(cat "$H/.claude/projects/-srv-other/s0.jsonl")" = old ] || fail "what .claude-work had in projects/ is merged into .claude"
[ -d "$H/.claude/skills/mine" ] || fail "skills of .claude-work merged"
[ "$(cat "$H/.claude-work/settings.json")" = '{"theme":"dark"}' ] || fail "settings.json is .claude's"
[ "$(cat "$H/.claude-work/settings.json.local")" = '{"theme":"light"}' ] || fail "its own settings.json is kept aside"
[ ! -L "$H/.claude-work/.credentials.json" ] && [ "$(cat "$H/.claude-work/.credentials.json")" = '{"oauth":"work"}' ] || fail "the login stays in its own directory"
mode=$(stat -c %a "$H/.claude-work" 2>/dev/null || stat -f %Lp "$H/.claude-work")
[ "$mode" = 700 ] || fail ".claude-work is $mode, not 700"
# a transcript written on one account is there for --resume on the other
echo new > "$H/.claude-work/projects/-srv-app/s2.jsonl"
[ -f "$H/.claude/projects/-srv-app/s2.jsonl" ] || fail "a session written in .claude-work lands in .claude"
ok "another subscription links the transcripts, agents, skills, commands, plugins and settings of .claude"

# a link the person pointed elsewhere is put back
ln -sfn /tmp "$H/.claude-work/agents"
claude_dir_layout "$H" .claude-work
[ "$(readlink "$H/.claude-work/agents")" = ../.claude/agents ] || fail "a redirected link is restored"
ok "a link pointed elsewhere is put back"

if claude_dir_remove "$H" .claude 2>/dev/null; then fail "claude_dir_remove must refuse .claude"; fi
claude_dir_remove "$H" .claude-work
[ ! -e "$H/.claude-work" ] || fail ".claude-work is still there"
[ -f "$H/.claude/projects/-srv-app/s1.jsonl" ] && [ -f "$H/.claude/projects/-srv-app/s2.jsonl" ] || fail "rm -rf followed the links: transcripts gone"
for e in projects todos shell-snapshots agents commands skills plugins; do [ -d "$H/.claude/$e" ] || fail "$e gone from .claude"; done
[ -f "$H/.claude/settings.json" ] || fail "settings.json gone from .claude"
ok "forgetting a subscription removes its directory, not what it shared"

# ensure_claude_dir runs the layout as the person, through sudo, in a clean shell: here sudo is
# left out (sudo -u USER …) and the same command line runs as whoever runs the tests
H2="$T/people/ws-ada"; mkdir -p "$H2"
out=$( ( HOMES="$T/people"; sudo() { shift 2; "$@"; }; fail() { admin_fail "$@"; }; ensure_claude_dir ada .claude-side ) 2>&1 )
[ -z "$out" ] || fail "ensure_claude_dir said: $out"
[ "$(readlink "$H2/.claude-side/projects")" = ../.claude/projects ] && [ -d "$H2/.claude/projects" ] || fail "ensure_claude_dir laid out .claude-side"
out=$( ( HOMES="$T/people"; sudo() { shift 2; "$@"; }; fail() { admin_fail "$@"; }; ensure_claude_dir ada ../etc ) 2>&1 || true )
case "$out" in *'"ok":false'*) ;; *) fail "ensure_claude_dir took ../etc: $out" ;; esac
ok "ensure_claude_dir lays the directory out in a clean shell as the person"

# ---------------------------------------------------------------- the policy

CLAUDE_POLICY="$T/etc/claude-code/managed-settings.json"
ensure_claude_policy
jq -e '.permissions.deny | index("Read(/home/*/.claude-*/**)") != null' "$CLAUDE_POLICY" >/dev/null || fail "a new policy denies the other subscriptions"
jq -e '.permissions.deny | index("Read(/home/*/.claude/**)") != null' "$CLAUDE_POLICY" >/dev/null || fail "a new policy denies .claude"
cat > "$CLAUDE_POLICY" <<'JSON'
{"forceLoginMethod":"claudeai","permissions":{"deny":["Read(/etc/studio/**)","Read(/home/*/.claude/**)"],"disableBypassPermissionsMode":"disable"}}
JSON
ensure_claude_policy; ensure_claude_policy
[ "$(jq '[.permissions.deny[] | select(. == "Read(/home/*/.claude-*/**)")] | length' "$CLAUDE_POLICY")" = 1 ] || fail "the rule is added to an older policy, once"
[ "$(jq '.permissions.deny | length' "$CLAUDE_POLICY")" = 3 ] || fail "the other rules stay"
jq -e 'has("forceLoginMethod") | not' "$CLAUDE_POLICY" >/dev/null || fail "forceLoginMethod is still taken out"
[ ! -e "$CLAUDE_POLICY.new" ] || fail "a temporary file is left behind"
ok "the policy denies reading every Claude directory, an older one patched once"

# ---------------------------------------------------------------- git identity

identity() { # JSON -> "name|email" or the error
    ( INPUT=$1; fail() { admin_fail "$@"; }; git_identity; printf '%s|%s\n' "$GIT_ID_NAME" "$GIT_ID_EMAIL" ) 2>/dev/null
}
[ "$(identity '{"git_name":"Maria Rossi","git_email":"maria@agency.test"}')" = "Maria Rossi|maria@agency.test" ] || fail "a name and an address"
[ "$(identity '{}')" = "|" ] || fail "nothing sent, nothing checked"
for bad in '{"git_email":"maria agency.test"}' '{"git_email":"maria@@agency.test"}' '{"git_email":"a b@agency.test"}' \
           '{"git_email":"m@x\ny"}' '{"git_name":"Maria\nRossi"}' "{\"git_name\":\"$(printf 'x%.0s' $(seq 256))\"}" \
           "{\"git_email\":\"$(printf 'x%.0s' $(seq 250))@a.test\"}"; do
    out=$(identity "$bad" || true)
    case "$out" in *'"ok":false'*) ;; *) fail "accepted $bad: $out" ;; esac
done
ok "the git name and address are one line, 255 characters at most, the address with one @"
INPUT='{"git_name":""}'
has_field git_name && ! has_field git_email || fail "has_field tells sent-empty from absent"
ok "a git field sent empty is told apart from one not sent"

# ---------------------------------------------------------------- git-cli

mkdir -p "$T/release/installer/lib"
cat > "$T/release/installer/lib/system.sh" <<'EOF'
ensure_git_cli() { echo "installing the $1 tool"; echo "$1" >> "$INSTALLED"; }
EOF
export INSTALLED="$T/installed"
out=$( ( STUDIO_DIR="$T/release"; fail() { admin_fail "$@"; }; cmd_git_cli gitlab ) 2>/dev/null )
[ "$out" = '{"ok":true}' ] || fail "git-cli answers one JSON line, got: $out"
[ "$(cat "$INSTALLED")" = gitlab ] || fail "git-cli ran ensure_git_cli gitlab"
out=$( ( STUDIO_DIR="$T/release"; fail() { admin_fail "$@"; }; cmd_git_cli sourcehut ) 2>/dev/null || true )
case "$out" in *'"ok":false'*) ;; *) fail "git-cli accepted an unknown provider: $out" ;; esac
echo 'ensure_git_cli() { return 1; }' > "$T/release/installer/lib/system.sh"
out=$( ( STUDIO_DIR="$T/release"; fail() { admin_fail "$@"; }; cmd_git_cli azure ) 2>/dev/null || true )
case "$out" in *'"ok":false'*'azure'*) ;; *) fail "a failed install is an error: $out" ;; esac
ok "git-cli installs the forge's CLI through the release's ensure_git_cli and answers {\"ok\":true}"

grep -qE '^    git-cli\)' "$ADMIN" && grep -qE '^    claude-forget\)' "$ADMIN" || fail "git-cli and claude-forget are dispatched"
ok "the new commands are dispatched"

# ---------------------------------------------------------------- the workspace's environment

# Studio sends claude_api_key empty (a key goes with each turn): the helper ran under set -e and
# stopped at the empty test that ended write_workspace_env ("stopped at: [ -n "$claude_api_key" ]").
GIT_AGENT_VARS=(GITHUB_TOKEN GH_TOKEN); ASKPASS=/askpass; BROWSERS="$T/no-browsers"
W_ENV="$T/ws.env"; W_USER=ws-maria; W_HOME="$T/home/ws-maria"; W_DIR="$T/ws"; W_SANDBOXES="$T/sandboxes"
chown() { :; }
# the helper runs on Ubuntu with GNU sed; BSD sed (macOS) wants the suffix of -i as an argument of its own
if ! sed --version >/dev/null 2>&1; then
    sed() { if [ "$1" = -i ]; then shift; command sed -i '' "$@"; else command sed "$@"; fi; }
fi
for input in '{"bridge_token":"b","bridge_port":"42001","claude_api_key":null}' '{"bridge_token":"b","claude_api_key":""}' '{"bridge_token":"b"}'; do
    rm -f "$W_ENV"
    ( set -euo pipefail; INPUT=$input; write_workspace_env ) || fail "write_workspace_env stopped with $input"
    grep -q '^BRIDGE_TOKEN=b$' "$W_ENV" || fail "the bridge token is written with $input"
    if grep -q STUDIO_ANTHROPIC_API_KEY "$W_ENV"; then fail "no key is written with $input"; fi
done
( set -euo pipefail; INPUT='{"claude_api_key":"sk-ant-x"}'; write_workspace_env ) || fail "write_workspace_env with a key"
grep -q '^STUDIO_ANTHROPIC_API_KEY=sk-ant-x$' "$W_ENV" || fail "a key sent is written"
( set -euo pipefail; INPUT='{"claude_api_key":null}'; write_workspace_env ) || fail "write_workspace_env dropping a key"
if grep -q STUDIO_ANTHROPIC_API_KEY "$W_ENV"; then fail "a key sent empty is removed"; fi
ok "the workspace's environment is written under set -e whether a Claude key is sent, sent empty or not sent"

printf '\nall studio-admin tests passed\n'
