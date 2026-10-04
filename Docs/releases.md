# Sync and release

Develop on the root `main` branch; sync commits pending changes automatically. `Website/` and `Mobile/` are the
actual source folders. Never edit `website`, `deploy`, or `mobile_app` branches.
All commands require Git and PowerShell; `sync.cmd` also allows `sync` in this
directory. `sync.sh` forwards to the same PowerShell implementation.

| Command | Result |
| --- | --- |
| `./sync.ps1` | Publish main and changed website, deploy, mobile_app trees; no version bump |
| `./sync.ps1 --no-deploy` | Publish main, website and mobile_app; leave deploy/tags untouched and skip CI on generated commits |
| `./sync.ps1 --website` | Publish main and website only |
| `./sync.ps1 --mobile` | Publish main and mobile_app; build a preview APK artifact |
| `./sync.ps1 --deploy` | Release website and publish website + deploy |
| `./sync.ps1 --release` | Release changed products and publish all targets |
| `./sync.ps1 --mobile --release` | Release mobile only, build signed APK/AAB and publish GitHub Release |
| `./sync.ps1 --website --release` | Release website without updating deploy |
| `./sync.ps1 --status` | Read local branch, versions, changes and last-fetched target state |
| `./sync.ps1 --release --dry-run` | Preview release using last-fetched refs; no fetch or writes |

Release creation is explicit. Ordinary sync and `--mobile` do not create releases.
This resolves the conflicting mobile examples in the proposed workflow.

For an initial push without deployment use `--no-deploy`, and include `[skip ci]`
in your main commit message to skip any main-branch CI too. This option cannot be
combined with `--release` or `--deploy`. Hostinger must remain connected only to
the `deploy` branch; external integrations targeting other branches are outside
the script's control.

`versions.json` starts both products at 1.0.0. Website version metadata lives in
`Website/version.json`; mobile version and monotonically increasing build number
live in `Mobile/pubspec.yaml`. Each release scans commits touching that product
since its current version tag. `feat:` / `feat(scope):` mean minor; `type!:` /
`type(scope)!:` or a `BREAKING CHANGE:` footer mean major. Other changes mean patch.
The highest bump wins. A commit touching both products contributes to both.
On the first release, with no product tags, the full product history is scanned.
Version-only releases are not repeated when there are no later product commits.
Tags point to main so release workflows can access the complete source tree.

Run `powershell -NoProfile -ExecutionPolicy Bypass -File Tests/sync.integration.ps1`
to verify sync against an isolated local bare remote. Fixtures are retained under
the temporary directory for inspection; the test never contacts GitHub/Hostinger.

Sync automatically stages additions, edits and deletions, creating separate
`chore(website)`, `chore(mobile)` and `chore(repo)` commits with a change summary.
All pending changes are committed to main, even with a single-target option;
the option controls which generated branches are published. Ignored files stay
ignored. `--no-deploy` adds `[skip ci]` to automatic commit messages.
Make a conventional commit yourself before sync when you need a `feat:` or
breaking-change message; automatic commits count as patch changes on release.

Sync fetches origin, saves local changes, then fast-forwards or merges origin/main
automatically before generating targets. Existing commits and release tags are
preserved. Conflicts stop publication and leave local commits saved; resolve and
commit the merge (or abort it), then rerun. Other branches and unfinished Git
operations are refused. Status and dry-run never commit, fetch, merge or push;
dry-run plans use committed files and last-fetched refs. Generated commits preserve the remote branch
parent, so pushes fast-forward without force. Main, selected branches and tags
are pushed atomically. A failed push leaves local commits/tags for retry.

## Repository setup

The root repository uses `main` and remote
`https://github.com/Sudipto-tales/sarada_marble_bankura.git`.
The initial push uses `--no-deploy`: it publishes main, website and mobile_app,
without creating deploy or release tags. Generated initial commits skip CI.
On another machine, clone this repository and configure Git author identity.
Do not initialize/push unrelated history over an existing remote.

## Hostinger

Connect Hostinger's Git deployment to the repository's `deploy` branch and enable
its automatic deployment/webhook. The branch contains Website contents at root.
Configure server `.env`, PHP/database settings and `composer install` as required
by the existing site. The script pushes the branch; deployment completion is
reported by Hostinger, not verified by sync. No Hostinger account or webhook is
configured in this workspace.

## Android signing

For mobile releases add GitHub repository secrets `ANDROID_KEYSTORE_BASE64`,
`ANDROID_KEYSTORE_PASSWORD`, `ANDROID_KEY_PASSWORD`, and `ANDROID_KEY_ALIAS`.
Use one persistent upload keystore; never commit it. The release workflow fails
if signing secrets are absent. Local builds retain the existing debug signing
fallback when `ANDROID_KEYSTORE_PATH` is unset. Mobile branch pushes build debug
preview APKs; version tags build signed release APK and AAB. Flutter is pinned to
3.38.7 (Dart 3.10) and Java to 17. Validate your app's tests/analyzer before release.

The script warns for a breaking website release. Verify the mobile app against
the changed API explicitly; it does not bump mobile just because website changed.

References: [GitHub workflow syntax](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax),
[Flutter Android releases](https://docs.flutter.dev/deployment/android).
