#!/usr/bin/env bash
# ==============================================================================
# Maa Sarada — Sync & Release Tool (sync.sh)
#
# main is the source of truth; target branches (website, mobile_app, deploy)
# are generated directly from main subtrees using Git plumbing.
#
# Default behavior:
#   1. Verifies repository state and checks you are on branch 'main'.
#   2. Pulls and checks upstream changes from origin/main.
#      If a merge conflict occurs, aborts immediately without touching branches.
#   3. Auto-adds and commits all pending local changes.
#   4. Generates target branches: 'website' and 'mobile_app'.
#   5. Pushes 'main', 'website', and 'mobile_app' to origin.
#   6. DOES NOT deploy by default ('deploy' branch is not pushed unless --deploy is passed).
# ==============================================================================

set -euo pipefail

show_help() {
  cat <<'EOF'
Maa Sarada — Sync & Release Tool (sync.sh)

Usage:
  ./sync.sh [options]

Commands & Options:
  -h, --help, help    Show this help message and exit
  --website           Sync and push 'main' and 'website' branch only
  --mobile            Sync and push 'main' and 'mobile_app' branch only
  --deploy            Include 'deploy' branch to trigger Hostinger deployment
  --release           Automatically bump versions and create release tags
  --status            Check local & remote sync status without making changes
  --dry-run           Preview actions without committing, merging, or pushing
  -m, --message MSG   Custom commit message for pending local changes
  --no-deploy         Explicitly exclude deploy branch (already default)

Target Branches:
  main                Root source containing Website/, Mobile/, Docs/, tooling
  website             Subtree branch of Website/ (for web hosting / previews)
  mobile_app          Subtree branch of Mobile/ (triggers GitHub Actions APK build)
  deploy              Subtree branch of Website/ connected to Hostinger webhook
                      * ONLY updated and pushed when --deploy is explicitly passed *

Workflow Details:
  1. Verifies you are on 'main' with no active Git merge/rebase in progress.
  2. Fetches origin and checks if origin/main has updates.
  3. If pending uncommitted changes exist, stages (git add -A) and commits them.
  4. Integrates origin/main. If a MERGE CONFLICT occurs, stops immediately:
     no branches are updated, no push is made, and local commits remain safe.
  5. Rebuilds target branches from subtrees preserving remote branch parents.
  6. Atomically pushes main and active target branches to origin.
EOF
}

# --- Option parsing ---
mode="all"
release=false
dry=false
deploy=false
no_deploy=false
custom_message=""

while [ $# -gt 0 ]; do
  case "$1" in
    -h|--help|help)
      show_help
      exit 0
      ;;
    --website)
      if [ "$mode" != "all" ]; then
        echo "Error: Choose only one target mode (--website, --mobile, or default all)." >&2
        exit 1
      fi
      mode="website"
      shift
      ;;
    --mobile)
      if [ "$mode" != "all" ]; then
        echo "Error: Choose only one target mode (--website, --mobile, or default all)." >&2
        exit 1
      fi
      mode="mobile"
      shift
      ;;
    --deploy)
      deploy=true
      shift
      ;;
    --no-deploy)
      no_deploy=true
      shift
      ;;
    --release)
      release=true
      shift
      ;;
    --status)
      if [ "$mode" != "all" ]; then
        echo "Error: Choose only one target mode." >&2
        exit 1
      fi
      mode="status"
      shift
      ;;
    --dry-run)
      dry=true
      shift
      ;;
    -m|--message)
      if [ $# -lt 2 ]; then
        echo "Error: -m/--message requires an argument." >&2
        exit 1
      fi
      custom_message="$2"
      shift 2
      ;;
    *)
      echo "Error: Unknown option '$1'. Use './sync.sh --help' for usage." >&2
      exit 1
      ;;
  esac
done

if [ "$no_deploy" = true ] && [ "$deploy" = true ]; then
  echo "Error: --no-deploy cannot be combined with --deploy." >&2
  exit 1
fi

# --- Helper functions ---
resolve_ref() {
  local ref="$1"
  git rev-parse --verify --quiet "$ref" 2>/dev/null || true
}

get_json_version() {
  local file="$1"
  local key="$2"
  if command -v jq >/dev/null 2>&1; then
    jq -r ".$key" "$file" 2>/dev/null
  elif command -v python3 >/dev/null 2>&1; then
    python3 -c "import json, sys; d=json.load(open('$file')); sys.stdout.write(str(d.get('$key','')))"
  else
    grep -oP "\"$key\"\s*:\s*\"\K[^\"]+" "$file" 2>/dev/null || true
  fi
}

set_json_version() {
  local file="$1"
  local key="$2"
  local val="$3"
  if command -v jq >/dev/null 2>&1; then
    local tmp
    tmp="$(mktemp)"
    jq --arg v "$val" ".$key = \$v" "$file" > "$tmp" && mv "$tmp" "$file"
  elif command -v python3 >/dev/null 2>&1; then
    python3 -c "import json; f='$file'; d=json.load(open(f)); d['$key']='$val'; json.dump(d,open(f,'w'),indent=2); open(f,'a').write('\n')"
  else
    sed -i "s/\"$key\"[[:space:]]*:[[:space:]]*\"[^\"]*\"/\"$key\": \"$val\"/" "$file"
  fi
}

# --- Pre-flight repository checks ---
if ! command -v git >/dev/null 2>&1; then
  echo "Error: Git is required but not installed." >&2
  exit 1
fi

root_dir="$(git rev-parse --show-toplevel 2>/dev/null || true)"
if [ -z "$root_dir" ]; then
  echo "Error: This directory is not inside a Git repository." >&2
  exit 1
fi

cd "$root_dir"

if [ ! -d ".git" ] && [ ! -f ".git" ]; then
  echo "Error: .git directory not found at repository root ($root_dir)." >&2
  exit 1
fi

branch="$(git symbolic-ref --short HEAD 2>/dev/null || true)"
if [ -z "$branch" ]; then
  echo "Error: HEAD is detached. Switch to branch 'main' first." >&2
  exit 1
fi

# Check for in-progress operations
git_dir="$(git rev-parse --git-dir)"
for op in MERGE_HEAD CHERRY_PICK_HEAD REVERT_HEAD rebase-merge rebase-apply sequencer; do
  if [ -e "$git_dir/$op" ]; then
    echo "Error: Git operation ($op) in progress. Finish or abort it before syncing." >&2
    exit 1
  fi
done

# Check for existing unmerged conflict markers
if [ -n "$(git ls-files --unmerged)" ]; then
  echo "Error: Resolve existing merge conflicts before syncing." >&2
  exit 1
fi

# Ensure origin remote is configured
if ! git remote get-url origin >/dev/null 2>&1; then
  echo "Error: Remote 'origin' is not configured." >&2
  exit 1
fi

# Validate versions.json
if [ ! -f "versions.json" ]; then
  echo "Error: versions.json not found." >&2
  exit 1
fi

ver_website="$(get_json_version "versions.json" "website")"
ver_mobile="$(get_json_version "versions.json" "mobile")"

if [[ ! "$ver_website" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "Error: Invalid website version in versions.json: '$ver_website'" >&2
  exit 1
fi
if [[ ! "$ver_mobile" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
  echo "Error: Invalid mobile version in versions.json: '$ver_mobile'" >&2
  exit 1
fi

# --- Status mode ---
if [ "$mode" = "status" ]; then
  echo "Branch: $branch; website: $ver_website; mobile: $ver_mobile"
  echo "Deploy on sync: $deploy"
  echo "Remote state uses last fetch; status does not contact the network."
  git status --short
  echo "--- Subtree sync state ---"
  for item in "Website:website" "Website:deploy" "Mobile:mobile_app"; do
    folder="${item%%:*}"
    target="${item##*:}"
    tree="$(resolve_ref "HEAD:$folder")"
    tip="$(resolve_ref "refs/remotes/origin/$target")"
    state="missing"
    if [ -n "$tip" ]; then
      tip_tree="$(resolve_ref "$tip^{tree}")"
      if [ "$tip_tree" = "$tree" ]; then
        state="unchanged"
      else
        state="changed"
      fi
    fi
    echo "${target}: $state"
  done
  exit 0
fi

# Branch must be main for sync/release
if [ "$branch" != "main" ]; then
  echo "Error: Run from 'main'. Current branch is '$branch'. Generated branches must never be edited directly." >&2
  exit 1
fi

# --- Fetch origin ---
if [ "$dry" = false ]; then
  echo "Fetching remote refs from origin..."
  git fetch --prune origin '+refs/heads/*:refs/remotes/origin/*' --tags
fi

# --- Auto-add and commit pending changes ---
# Changes are committed before pulling remote updates so that Git can perform
# a standard 3-way merge and accurately identify any conflicts.
has_worktree_changes() {
  [ -n "$(git status --porcelain)" ]
}

if has_worktree_changes; then
  if [ "$dry" = true ]; then
    echo "Dry run: would commit pending changes."
  else
    echo "Staging and committing pending local changes..."
    if [ -n "$custom_message" ]; then
      git add -A
      git commit -m "$custom_message"
    else
      # Commit scoped areas separately for clean, structured commit history
      # 1. Website
      if [ -n "$(git status --porcelain --untracked-files=all -- 'Website' 2>/dev/null)" ]; then
        git add -A -- 'Website'
        summary="$(git diff --cached --stat -- 'Website')"
        git commit --only -m "chore(website): sync pending changes" -m "$summary" -- 'Website'
      fi

      # 2. Mobile
      if [ -n "$(git status --porcelain --untracked-files=all -- 'Mobile' 2>/dev/null)" ]; then
        git add -A -- 'Mobile'
        summary="$(git diff --cached --stat -- 'Mobile')"
        git commit --only -m "chore(mobile): sync pending changes" -m "$summary" -- 'Mobile'
      fi

      # 3. Root repository files (excluding Website and Mobile)
      if [ -n "$(git status --porcelain --untracked-files=all -- . ':!Website' ':!Mobile' 2>/dev/null)" ]; then
        git add -A -- . ':!Website' ':!Mobile'
        summary="$(git diff --cached --stat -- . ':!Website' ':!Mobile')"
        git commit --only -m "chore(repo): sync pending changes" -m "$summary" -- . ':!Website' ':!Mobile'
      fi
    fi
  fi
fi

# --- Pull / Integrate origin/main ---
# Check if upstream origin/main has updates that must be merged
remote_main="$(resolve_ref "refs/remotes/origin/main")"
if [ -n "$remote_main" ]; then
  if ! git merge-base --is-ancestor "$remote_main" HEAD; then
    if [ "$dry" = true ]; then
      echo "Dry run: would integrate origin/main before publishing."
    else
      echo "Checking upstream origin/main and merging updates..."
      # Merge preserves existing commits and release tags
      if ! git -c merge.ff=true merge --no-edit "$remote_main"; then
        echo "" >&2
        echo "======================================================================" >&2
        echo "ERROR: MERGE CONFLICT DETECTED WITH origin/main!" >&2
        echo "A conflict occurred while integrating upstream changes." >&2
        echo "Action stopped immediately: nothing has been pushed and no target" >&2
        echo "branches were updated." >&2
        echo "Your local commits are safely preserved." >&2
        echo "Please resolve the conflicts and commit, or abort with:" >&2
        echo "  git merge --abort" >&2
        echo "Then rerun ./sync.sh." >&2
        echo "======================================================================" >&2
        exit 1
      fi
      echo "Successfully integrated origin/main."
    fi
  else
    echo "Local branch is up to date with origin/main."
  fi
fi

# Safety check: verify no conflicts exist before proceeding
if [ -n "$(git ls-files --unmerged)" ] || [ -e "$git_dir/MERGE_HEAD" ]; then
  echo "Error: Active merge conflicts detected. Stopping sync." >&2
  exit 1
fi

# Refresh versions after potential merge from remote
ver_website="$(get_json_version "versions.json" "website")"
ver_mobile="$(get_json_version "versions.json" "mobile")"

# --- Determine Target Products & Branches ---
# By default, deploy branch is EXCLUDED!
# Only if --deploy is explicitly provided will the deploy branch be targeted.
products=()
if [ "$mode" = "website" ]; then
  products+=("website:Website:website")
elif [ "$mode" = "mobile" ]; then
  products+=("mobile:Mobile:mobile_app")
else
  # mode == "all"
  if [ "$deploy" = true ]; then
    products+=("website:Website:website deploy")
  else
    products+=("website:Website:website")
  fi
  products+=("mobile:Mobile:mobile_app")
fi

# --- Release Version Bumping (if --release) ---
bump_website=""
bump_mobile=""
next_website="$ver_website"
next_mobile="$ver_mobile"

if [ "$release" = true ]; then
  echo "Scanning commits for release version bumps..."
  for p in "${products[@]}"; do
    p_name="$(echo "$p" | cut -d: -f1)"
    p_folder="$(echo "$p" | cut -d: -f2)"
    p_ver="$ver_website"
    [ "$p_name" = "mobile" ] && p_ver="$ver_mobile"

    tag_name="$p_name-v$p_ver"
    baseline="$(resolve_ref "refs/tags/$tag_name^{commit}")"

    if [ -n "$baseline" ]; then
      if ! git merge-base --is-ancestor "$baseline" HEAD; then
        echo "Error: Tag $tag_name is not an ancestor of main." >&2
        exit 1
      fi
      messages="$(git log "$baseline..HEAD" --format='%B' -- "$p_folder")"
    else
      messages="$(git log --format='%B' -- "$p_folder")"
    fi

    bump=""
    if [ -n "$(echo "$messages" | tr -d '[:space:]')" ]; then
      bump="patch"
      if echo "$messages" | grep -qE '^[a-zA-Z]+(\([^\)]+\))?!:|^BREAKING[ -]CHANGE:'; then
        bump="major"
      elif echo "$messages" | grep -qE '^feat(\([^\)]+\))?:'; then
        bump="minor"
      fi
    fi

    if [ -n "$bump" ]; then
      IFS='.' read -r major minor patch <<< "$p_ver"
      case "$bump" in
        major)
          major=$((major + 1))
          minor=0
          patch=0
          ;;
        minor)
          minor=$((minor + 1))
          patch=0
          ;;
        patch)
          patch=$((patch + 1))
          ;;
      esac
      next_ver="$major.$minor.$patch"

      if [ -n "$(resolve_ref "refs/tags/$p_name-v$next_ver")" ]; then
        echo "Error: Tag $p_name-v$next_ver already exists." >&2
        exit 1
      fi

      if [ "$p_name" = "website" ]; then
        bump_website="$bump"
        next_website="$next_ver"
      else
        bump_mobile="$bump"
        next_mobile="$next_ver"
      fi
      echo "  $p_name: $p_ver -> $next_ver ($bump)"
    else
      echo "  $p_name: $p_ver (no changes)"
    fi
  done

  # Apply release version files and commit
  if [ -n "$bump_website" ] || [ -n "$bump_mobile" ]; then
    if [ "$dry" = true ]; then
      echo "Dry run: would update version metadata and commit chore(release)."
    else
      changed_files=()
      if [ -n "$bump_website" ]; then
        set_json_version "versions.json" "website" "$next_website"
        set_json_version "Website/version.json" "version" "$next_website"
        changed_files+=("Website/version.json")
      fi
      if [ -n "$bump_mobile" ]; then
        set_json_version "versions.json" "mobile" "$next_mobile"
        # Update pubspec.yaml version and increment build number
        if [ -f "Mobile/pubspec.yaml" ]; then
          current_build="$(grep -oP '^version:\s*[0-9\.]+\+\K[0-9]+' Mobile/pubspec.yaml || echo "1")"
          next_build=$((current_build + 1))
          sed -i -E "s/^version:[[:space:]]*[0-9\.]+(\+[0-9]+)?/version: $next_mobile+$next_build/" Mobile/pubspec.yaml
          changed_files+=("Mobile/pubspec.yaml")
        fi
      fi
      changed_files+=("versions.json")
      git add "${changed_files[@]}"
      git commit -m "chore(release): update product versions"
      echo "Committed version updates to main."
    fi
  fi
fi

# --- Rebuild Target Branches from Subtrees ---
push_refs=("HEAD:refs/heads/main")
created_tags=()

for p in "${products[@]}"; do
  p_name="$(echo "$p" | cut -d: -f1)"
  p_folder="$(echo "$p" | cut -d: -f2)"
  p_branches="$(echo "$p" | cut -d: -f3)"

  tree="$(resolve_ref "HEAD:$p_folder")"
  if [ -z "$tree" ]; then
    echo "Error: Subtree folder '$p_folder' not found in HEAD." >&2
    exit 1
  fi

  for target in $p_branches; do
    parent="$(resolve_ref "refs/remotes/origin/$target")"
    local_tip="$(resolve_ref "refs/heads/$target")"

    if [ -n "$parent" ] && [ -n "$local_tip" ] && [ "$parent" != "$local_tip" ]; then
      if ! git merge-base --is-ancestor "$parent" "$local_tip"; then
        echo "Error: Local branch '$target' has diverged from 'origin/$target'. Resolve it first." >&2
        exit 1
      fi
    fi

    if [ -z "$parent" ]; then
      parent="$local_tip"
    fi

    parent_tree=""
    if [ -n "$parent" ]; then
      parent_tree="$(resolve_ref "$parent^{tree}")"
    fi

    if [ -z "$parent" ] || [ "$parent_tree" != "$tree" ]; then
      echo "Rebuilding target branch '$target' from $p_folder/..."
      if [ "$dry" = false ]; then
        commit_msg="$target: sync from main@$(git rev-parse --short HEAD)"
        if [ -n "$parent" ]; then
          new_tip="$(git commit-tree "$tree" -p "$parent" -m "$commit_msg")"
        else
          new_tip="$(git commit-tree "$tree" -m "$commit_msg")"
        fi
        git update-ref "refs/heads/$target" "$new_tip"
      fi
    elif [ "$dry" = false ]; then
      git update-ref "refs/heads/$target" "$parent"
    fi

    push_refs+=("refs/heads/$target:refs/heads/$target")
  done

  # Create release tags if bumped
  cur_bump=""
  cur_ver=""
  if [ "$p_name" = "website" ] && [ -n "$bump_website" ]; then
    cur_bump="$bump_website"
    cur_ver="$next_website"
  elif [ "$p_name" = "mobile" ] && [ -n "$bump_mobile" ]; then
    cur_bump="$bump_mobile"
    cur_ver="$next_mobile"
  fi

  if [ -n "$cur_bump" ]; then
    new_tag="$p_name-v$cur_ver"
    echo "Creating release tag '$new_tag' on main..."
    if [ "$dry" = false ]; then
      git tag -a "$new_tag" -m "$p_name $cur_ver"
      created_tags+=("$new_tag")
    fi
  fi

  # Add existing product tags to push if needed
  if [ "$no_deploy" = false ]; then
    while IFS= read -r tag; do
      [ -n "$tag" ] && push_refs+=("refs/tags/$tag:refs/tags/$tag")
    done < <(git tag --list "$p_name-v*")
  fi
done

# Deduplicate push refs
declare -A seen_refs
unique_push_refs=()
for ref in "${push_refs[@]}"; do
  if [ -z "${seen_refs[$ref]:-}" ]; then
    seen_refs[$ref]=1
    unique_push_refs+=("$ref")
  fi
done

# --- Push to Origin ---
echo ""
echo "Target branches to push:"
for ref in "${unique_push_refs[@]}"; do
  echo "  - $ref"
done

if [ "$deploy" = false ]; then
  echo "Note: 'deploy' branch is NOT included (use --deploy to deploy)."
else
  echo "Notice: 'deploy' branch IS included (Hostinger auto-deployment will trigger)."
fi

if [ "$dry" = true ]; then
  echo ""
  echo "Dry run complete: no changes were committed, merged, or pushed."
  exit 0
fi

echo ""
echo "Pushing atomically to origin..."
git push --atomic origin "${unique_push_refs[@]}"

echo ""
echo "======================================================================"
echo "SUCCESS: Sync completed successfully."
if [ "$deploy" = true ]; then
  echo "Hostinger deployment triggered via 'deploy' branch push."
else
  echo "Branches synced. Deploy branch was NOT pushed (safe by default)."
fi
echo "======================================================================"
