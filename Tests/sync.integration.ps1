# Runs against an isolated local bare remote. Does not contact GitHub or Hostinger.
$ErrorActionPreference = 'Stop'
$sourceRoot = Split-Path $PSScriptRoot -Parent
$powerShell = (Get-Process -Id $PID).Path
$fixtureRoot = Join-Path ([IO.Path]::GetTempPath()) ('sarada-sync-test-' + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $fixtureRoot | Out-Null
New-Item -ItemType Directory -Path (Join-Path $fixtureRoot 'Website'), (Join-Path $fixtureRoot 'Mobile') | Out-Null
Copy-Item (Join-Path $sourceRoot 'sync.ps1'), (Join-Path $sourceRoot 'versions.json') $fixtureRoot
Copy-Item (Join-Path $sourceRoot 'Website/version.json') (Join-Path $fixtureRoot 'Website')
Copy-Item (Join-Path $sourceRoot 'Mobile/pubspec.yaml') (Join-Path $fixtureRoot 'Mobile')
# Tests use a stable version baseline, independent of real product releases.
[IO.File]::WriteAllText((Join-Path $fixtureRoot 'versions.json'), '{"website":"1.0.0","mobile":"1.0.0"}')
[IO.File]::WriteAllText((Join-Path $fixtureRoot 'Website/version.json'), '{"version":"1.0.0"}')
$fixtureSpec = Get-Content (Join-Path $fixtureRoot 'Mobile/pubspec.yaml') -Raw
$fixtureSpec = [regex]::Replace($fixtureSpec, '(?m)^version: [^\r\n]+', 'version: 1.0.0+1')
[IO.File]::WriteAllText((Join-Path $fixtureRoot 'Mobile/pubspec.yaml'), $fixtureSpec)
'origin.git/' | Set-Content (Join-Path $fixtureRoot '.gitignore')
Write-Host "Test fixture retained at $fixtureRoot"
Push-Location $fixtureRoot
try {
$ErrorActionPreference = 'Stop'
function Check([bool]$condition, [string]$message) { if (!$condition) { throw $message }; Write-Host "PASS $message" }
function Run-Sync([string[]]$options) {
    & $powerShell -NoProfile -ExecutionPolicy Bypass -File ./sync.ps1 @options
    if ($LASTEXITCODE -ne 0) { throw 'sync failed' }
}
function Reject-Sync([string[]]$options) {
    $savedPreference = $ErrorActionPreference
    try {
        $ErrorActionPreference = 'Continue'
        & $powerShell -NoProfile -ExecutionPolicy Bypass -File ./sync.ps1 @options 2>&1 | Out-Host
        $rejected = $LASTEXITCODE -ne 0
    } finally { $ErrorActionPreference = $savedPreference }
    Check $rejected 'unsafe or rejected operation returns a failure'
}
git init -b main
git config user.email test@example.com
git config user.name 'Release test'
git config core.autocrlf false
git init --bare origin.git
git remote add origin origin.git
git add .
git commit -m 'fix: baseline'
Run-Sync @()
Check ((git rev-parse 'main:Website') -eq (git rev-parse 'website^{tree}')) 'website is the source folder tree'
Check ((git rev-parse 'main:Mobile') -eq (git rev-parse 'mobile_app^{tree}')) 'mobile is the source folder tree'
Check (@(git tag).Count -eq 0) 'ordinary sync creates no tags'
$before = git show-ref
Run-Sync @('--release','--dry-run')
Check (($before -join "`n") -eq ((git show-ref) -join "`n")) 'dry run changes no refs'
Check (@(git status --porcelain).Count -eq 0) 'dry run changes no files'
Run-Sync @('--release')
$v = Get-Content versions.json -Raw | ConvertFrom-Json
Check ($v.website -eq '1.0.1' -and $v.mobile -eq '1.0.1') 'first fix releases bump patch'
$before = git rev-parse HEAD
Run-Sync @('--release')
Check ($before -eq (git rev-parse HEAD)) 'repeat release is idempotent'
'new screen' | Set-Content Mobile/screen.txt
git add .
git commit -m 'feat(mobile): add screen'
Run-Sync @('--mobile')
Check ((Get-Content versions.json -Raw | ConvertFrom-Json).mobile -eq '1.0.1') 'mobile build does not create release'
Run-Sync @('--mobile','--release')
$v = Get-Content versions.json -Raw | ConvertFrom-Json
Check ($v.mobile -eq '1.1.0' -and $v.website -eq '1.0.1') 'mobile minor release keeps website version'
Check ((Get-Content Mobile/pubspec.yaml -Raw) -match 'version: 1.1.0\+3') 'Flutter build number increases'
'API v2' | Set-Content Website/api.txt
git add .
git commit -m 'feat!: replace API'
Run-Sync @('--deploy')
$v = Get-Content versions.json -Raw | ConvertFrom-Json
Check ($v.website -eq '2.0.0' -and $v.mobile -eq '1.1.0') 'breaking website release preserves mobile'
Check ((git rev-parse 'website^{tree}') -eq (git rev-parse 'deploy^{tree}')) 'deploy equals website'
'fix' | Add-Content Website/api.txt
git add .
git commit -m 'fix: repair API'
$deployBefore = git rev-parse deploy
Run-Sync @('--website')
Check ($deployBefore -eq (git rev-parse deploy)) 'website only excludes deploy'
$before = git show-ref
Run-Sync @('--status')
Check (($before -join "`n") -eq ((git show-ref) -join "`n")) 'status changes no refs'
# Auto-commit includes staged, unstaged, untracked and deleted files per area.
$before = git rev-parse HEAD
$deployBefore = git rev-parse deploy
'dirty' | Set-Content Website/dirty.txt
'mobile edit' | Add-Content Mobile/screen.txt
'shared' | Set-Content shared.txt
git add Mobile/screen.txt shared.txt
Remove-Item Website/api.txt
$dirtyBefore = @(git status --porcelain) -join "`n"
Run-Sync @('--website', '--dry-run')
Run-Sync @('--status')
Check ($before -eq (git rev-parse HEAD)) 'dirty previews do not commit'
Check ($dirtyBefore -eq (@(git status --porcelain) -join "`n")) 'dirty previews preserve index and worktree'
Run-Sync @('--website', '--no-deploy')
Check (@(git status --porcelain).Count -eq 0) 'sync commits all pending changes'
Check ((git rev-list --count "$before..HEAD") -eq 3) 'one separate commit per changed area'
Check ((git show --format= --name-only HEAD) -eq 'shared.txt') 'shared commit excludes staged product changes'
Check ((git show --format= --name-only 'HEAD~1') -eq 'Mobile/screen.txt') 'mobile commit contains only mobile files'
Check ((git log -1 --format=%s) -match '\[skip ci\]') 'no-deploy marks automatic commits to skip CI'
Check ($deployBefore -eq (git rev-parse deploy)) 'auto-commit preserves selected target behavior'
Check ((git --git-dir=origin.git rev-parse main) -eq (git rev-parse HEAD)) 'auto commits pushed to main'

# Use a second checkout outside the source worktree to simulate remote updates.
$peer = "$fixtureRoot-peer"
git clone --branch main origin.git $peer
git -C $peer config user.email test@example.com
git -C $peer config user.name 'Remote test'
'remote' | Set-Content (Join-Path $peer 'Website/remote.txt')
git -C $peer add .
git -C $peer commit -m 'fix: remote update'
git -C $peer push origin main
$remoteTip = git -C $peer rev-parse HEAD
Run-Sync @('--website')
Check ((git rev-parse HEAD) -eq $remoteTip) 'behind main fast-forwards automatically'
'second remote' | Add-Content (Join-Path $peer 'Website/remote.txt')
git -C $peer commit -am 'fix: second remote update'
git -C $peer push origin main
'local' | Set-Content Mobile/local.txt
Run-Sync @('--mobile')
Check (@((git rev-list --parents -n 1 HEAD) -split ' ').Count -eq 3) 'diverged main merges automatically'
Check (Test-Path Website/remote.txt) 'remote changes retained alongside local changes'
Check ((git --git-dir=origin.git rev-parse main) -eq (git rev-parse HEAD)) 'merged main pushed'

# Conflicts keep the automatic local commit and do not publish anything.
git -C $peer pull --ff-only origin main
'remote conflict' | Set-Content (Join-Path $peer 'Website/remote.txt')
git -C $peer commit -am 'fix: conflicting remote update'
git -C $peer push origin main
'local conflict' | Set-Content Website/remote.txt
$remoteBeforeConflict = @(git --git-dir=origin.git show-ref) -join "`n"
Reject-Sync @('--website')
Check (@(git ls-files --unmerged).Count -gt 0) 'conflicts are retained for resolution'
Check ($remoteBeforeConflict -eq (@(git --git-dir=origin.git show-ref) -join "`n")) 'conflicts prevent all pushes'
Reject-Sync @('--website')
git checkout --theirs Website/remote.txt
git add Website/remote.txt
git commit --no-edit
Run-Sync @('--website')
git switch -c test-feature
Reject-Sync @()
git switch main
Reject-Sync @('--unknown')
# A rejecting server must receive no partial refs, and rerun must not bump again.
$remoteBefore = git --git-dir=origin.git show-ref
$hook = Join-Path $PWD 'origin.git/hooks/pre-receive'
[IO.File]::WriteAllText($hook, "#!/bin/sh`nexit 1`n", (New-Object Text.UTF8Encoding($false)))
if ([IO.Path]::DirectorySeparatorChar -eq '/') { & chmod +x $hook }
Reject-Sync @('--deploy')
Check (($remoteBefore -join "`n") -eq ((git --git-dir=origin.git show-ref) -join "`n")) 'failed atomic push leaves remote unchanged'
$versionAfterFailure = (Get-Content versions.json -Raw | ConvertFrom-Json).website
$headAfterFailure = git rev-parse HEAD
Remove-Item -LiteralPath $hook
Run-Sync @('--deploy')
Check ((Get-Content versions.json -Raw | ConvertFrom-Json).website -eq $versionAfterFailure) 'retry does not bump again'
Check ((git rev-parse HEAD) -eq $headAfterFailure) 'retry keeps release commit'
Check ((git rev-parse "refs/tags/website-v$versionAfterFailure") -eq (git --git-dir=origin.git rev-parse "refs/tags/website-v$versionAfterFailure")) 'retry publishes pending release tag'
Write-Host 'ALL RELEASE INTEGRATION CHECKS PASSED'

} finally {
    Pop-Location
}
