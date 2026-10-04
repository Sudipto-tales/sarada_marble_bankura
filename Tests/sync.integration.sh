#!/usr/bin/env bash
# ==============================================================================
# Integration tests for sync.sh
# Tests run in an isolated temp directory against a local bare Git repository.
# Never contacts GitHub or remote servers.
# ==============================================================================

set -euo pipefail

test_dir="$(mktemp -d -t sarada-sync-test-XXXXXX)"
script_src="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

cleanup() {
  rm -rf "$test_dir"
}
trap cleanup EXIT

echo "Running sync.sh integration tests in $test_dir..."

# 1. Setup isolated test environment
mkdir -p "$test_dir/Website" "$test_dir/Mobile"
cp "$script_src/sync.sh" "$test_dir/sync.sh"
chmod +x "$test_dir/sync.sh"

echo '{"website":"1.0.0","mobile":"1.0.0"}' > "$test_dir/versions.json"
echo '{"version":"1.0.0"}' > "$test_dir/Website/version.json"
cat <<'EOF' > "$test_dir/Mobile/pubspec.yaml"
name: test_app
version: 1.0.0+1
environment:
  sdk: ^3.10.7
EOF

# Initialize local repo and remote bare repo
cd "$test_dir"
git init -b main
git config user.email "tester@example.com"
git config user.name "Tester"
git config commit.gpgsign false

git init --bare origin.git
git remote add origin origin.git

echo "origin.git/" > .gitignore
git add .gitignore
echo "initial" > Website/index.html
echo "initial mobile" > Mobile/main.dart
echo "repo root" > README.md

git add .
git commit -m "feat: initial commit"
git push origin main

pass_count=0
fail_count=0

assert_true() {
  local cond="$1"
  local desc="$2"
  if eval "$cond"; then
    echo "  PASS: $desc"
    pass_count=$((pass_count + 1))
  else
    echo "  FAIL: $desc"
    fail_count=$((fail_count + 1))
    exit 1
  fi
}

assert_fails() {
  local cmd="$1"
  local desc="$2"
  if eval "$cmd" >/dev/null 2>&1; then
    echo "  FAIL (expected failure, but succeeded): $desc"
    fail_count=$((fail_count + 1))
    exit 1
  else
    echo "  PASS: $desc"
    pass_count=$((pass_count + 1))
  fi
}

# --- TEST 1: Help command works ---
assert_true "./sync.sh --help | grep 'Maa Sarada' >/dev/null" "Help command outputs usage instructions"
assert_true "./sync.sh help | grep 'Maa Sarada' >/dev/null" "help argument outputs usage instructions"
assert_true "./sync.sh -h | grep 'Maa Sarada' >/dev/null" "-h argument outputs usage instructions"

# --- TEST 2: Status command works ---
assert_true "./sync.sh --status | grep 'Branch: main' >/dev/null" "Status command shows main branch and versions"

# --- TEST 3: Sync auto-commits and pushes WITHOUT deploy by default ---
echo "website edit 1" >> Website/index.html
echo "mobile edit 1" >> Mobile/main.dart
echo "root edit 1" >> README.md

./sync.sh

assert_true "[ -z \"\$(git status --porcelain)\" ]" "Auto-add and commit clean the working tree"
assert_true "git ls-remote --heads origin website | grep 'refs/heads/website' >/dev/null" "Website branch was pushed to remote"
assert_true "git ls-remote --heads origin mobile_app | grep 'refs/heads/mobile_app' >/dev/null" "Mobile_app branch was pushed to remote"
assert_true "! git ls-remote --heads origin deploy | grep 'refs/heads/deploy' >/dev/null" "Deploy branch was NOT pushed by default"

# --- TEST 4: Subtree contents on generated branches ---
origin_website_tree="$(git -C origin.git rev-parse refs/heads/website^{tree})"
local_website_tree="$(git rev-parse HEAD:Website)"
assert_true "[ '$origin_website_tree' = '$local_website_tree' ]" "Website branch tree exactly matches HEAD:Website"

origin_mobile_tree="$(git -C origin.git rev-parse refs/heads/mobile_app^{tree})"
local_mobile_tree="$(git rev-parse HEAD:Mobile)"
assert_true "[ '$origin_mobile_tree' = '$local_mobile_tree' ]" "Mobile branch tree exactly matches HEAD:Mobile"

# --- TEST 5: Deploy branch IS pushed when --deploy is specified ---
echo "website deploy edit" >> Website/index.html
./sync.sh --deploy
assert_true "git ls-remote --heads origin deploy | grep 'refs/heads/deploy' >/dev/null" "Deploy branch was pushed when --deploy was passed"

origin_deploy_tree="$(git -C origin.git rev-parse refs/heads/deploy^{tree})"
local_website_tree="$(git rev-parse HEAD:Website)"
assert_true "[ '$origin_deploy_tree' = '$local_website_tree' ]" "Deploy branch tree exactly matches HEAD:Website"

# --- TEST 6: Must run from main branch ---
git checkout -b feature-test
assert_fails "./sync.sh" "Refuses to run when not on main branch"
git checkout main

# --- TEST 7: Pull integrates remote updates cleanly when no conflict ---
peer_dir="$(mktemp -d -t sarada-peer-XXXXXX)"
git clone origin.git "$peer_dir"
git -C "$peer_dir" config user.email "peer@example.com"
git -C "$peer_dir" config user.name "Peer"
echo "remote update by peer" >> "$peer_dir/Website/peer.txt"
git -C "$peer_dir" add .
git -C "$peer_dir" commit -m "feat: peer update"
git -C "$peer_dir" push origin main
rm -rf "$peer_dir"

echo "local change in Mobile" >> Mobile/local.txt
./sync.sh

assert_true "[ -f Website/peer.txt ]" "Pulls and incorporates peer update automatically"
assert_true "[ -f Mobile/local.txt ]" "Preserves local changes and commits them"

# --- TEST 8: Merge conflict causes immediate abort with no pushes ---
peer_dir="$(mktemp -d -t sarada-peer-conflict-XXXXXX)"
git clone origin.git "$peer_dir"
git -C "$peer_dir" config user.email "peer@example.com"
git -C "$peer_dir" config user.name "Peer"
echo "peer conflict line" > "$peer_dir/Website/index.html"
git -C "$peer_dir" commit -am "fix: conflicting change from peer"
git -C "$peer_dir" push origin main
rm -rf "$peer_dir"

echo "local conflict line" > Website/index.html
remote_heads_before="$(git -C origin.git show-ref)"

assert_fails "./sync.sh" "Stops immediately when merge conflict occurs"
assert_true "[ -n \"\$(git ls-files --unmerged)\" ] || [ -f .git/MERGE_HEAD ]" "Conflict state retained for user resolution"
remote_heads_after="$(git -C origin.git show-ref)"
assert_true "[ '$remote_heads_before' = '$remote_heads_after' ]" "Remote was NOT modified when conflict occurred"

# Abort conflict to clean up
git merge --abort || git checkout -f HEAD

echo ""
echo "======================================================================"
echo "ALL TESTS PASSED ($pass_count tests passed)"
echo "======================================================================"
