# main is the source of truth; target branches are generated.
$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest
$mode = 'all'; $release = $false; $dry = $false; $noDeploy = $false
foreach ($option in $args) {
    switch ($option) {
        '--website' { if ($mode -ne 'all') { throw 'Choose one target.' }; $mode = 'website' }
        '--mobile' { if ($mode -ne 'all') { throw 'Choose one target.' }; $mode = 'mobile' }
        '--deploy' { if ($mode -ne 'all') { throw 'Choose one target.' }; $mode = 'deploy'; $release = $true }
        '--release' { $release = $true }
        '--status' { if ($mode -ne 'all') { throw 'Choose one target.' }; $mode = 'status' }
        '--dry-run' { $dry = $true }
        '--no-deploy' { $noDeploy = $true }
        '--help' {
            Write-Output 'sync.ps1 [--website|--mobile|--deploy|--status] [--release] [--dry-run] [--no-deploy]'
            Write-Output 'Automatically commit changes on main, integrate origin/main, and push selected targets.'
            Write-Output '--mobile builds only; add --release for a versioned release.'
            exit 0
        }
        default { throw "Unknown option: $option" }
    }
}
if ($noDeploy -and ($release -or $mode -eq 'deploy')) {
    throw '--no-deploy cannot be combined with deployment or release creation.'
}
function Invoke-Git {
    $output = & git @args
    if ($LASTEXITCODE -ne 0) { throw "git $($args -join ' ') failed ($LASTEXITCODE)." }
    return $output
}
function Resolve-Ref([string]$ref) {
    $value = & git rev-parse --verify --quiet $ref 2>$null
    if ($LASTEXITCODE -eq 0) { return "$value" }
    return $null
}
function Write-Utf8([string]$path, [string]$content) {
    [IO.File]::WriteAllText((Join-Path $PSScriptRoot $path), $content, (New-Object Text.UTF8Encoding($false)))
}
Push-Location $PSScriptRoot
try {
    if (!(Test-Path -LiteralPath (Join-Path $PSScriptRoot '.git'))) {
        throw 'This folder has no Git repository. Restore/clone the root repository and configure origin first.'
    }
    $root = & git rev-parse --show-toplevel 2>$null
    if ($LASTEXITCODE -ne 0 -or [IO.Path]::GetFullPath("$root") -ne [IO.Path]::GetFullPath($PSScriptRoot)) {
        throw 'This folder must be the root main Git repository. Restore/clone it and configure origin first.'
    }
    $branch = Invoke-Git symbolic-ref --short HEAD
    $versions = Get-Content versions.json -Raw | ConvertFrom-Json
    foreach ($product in @('website', 'mobile')) {
        if ($versions.$product -notmatch '^\d+\.\d+\.\d+$') { throw "Invalid $product version." }
    }
    $products = @(
        @{ Name = 'website'; Folder = 'Website'; Branches = @('website', 'deploy') },
        @{ Name = 'mobile'; Folder = 'Mobile'; Branches = @('mobile_app') }
    )
    if ($mode -eq 'website') { $products = @($products[0]); $products[0].Branches = @('website') }
    if ($mode -eq 'deploy') { $products = @($products[0]) }
    if ($mode -eq 'mobile') { $products = @($products[1]) }
    if ($noDeploy) {
        foreach ($product in $products) {
            $product.Branches = @($product.Branches | Where-Object { $_ -ne 'deploy' })
        }
    }
    $status = @(Invoke-Git status --porcelain)
    if ($mode -eq 'status') {
        Write-Output "Branch: $branch; website: $($versions.website); mobile: $($versions.mobile)"
        Write-Output 'Remote state uses the last fetch; status does not contact the network.'
        $status | Write-Output
        foreach ($product in $products) {
            $tree = Resolve-Ref "HEAD:$($product.Folder)"
            foreach ($target in $product.Branches) {
                $tip = Resolve-Ref "refs/remotes/origin/$target"
                $state = 'missing'
                if ($tip) { $state = 'changed'; if ((Resolve-Ref "$tip^{tree}") -eq $tree) { $state = 'unchanged' } }
                Write-Output "${target}: $state"
            }
        }
        exit 0
    }
    if ($branch -ne 'main') { throw 'Run from main. Generated branches must never be edited.' }
    foreach ($operation in @('MERGE_HEAD', 'CHERRY_PICK_HEAD', 'REVERT_HEAD', 'rebase-merge', 'rebase-apply', 'sequencer')) {
        $operationPath = Invoke-Git rev-parse --git-path $operation
        if (Test-Path -LiteralPath $operationPath) { throw 'Finish or abort the current Git operation before syncing.' }
    }
    if (@(Invoke-Git ls-files --unmerged).Count) { throw 'Resolve existing conflicts before syncing.' }
    $null = Invoke-Git remote get-url origin
    if (!$dry) { Invoke-Git fetch --prune origin '+refs/heads/*:refs/remotes/origin/*' --tags | Out-Host }
    # Commit each source area separately, including tracked deletions and untracked files.
    # --only keeps staged changes from other areas out of each area's commit.
    $groups = @(
        @{ Scope = 'website'; Paths = @(':(top)Website') },
        @{ Scope = 'mobile'; Paths = @(':(top)Mobile') },
        @{ Scope = 'repo'; Paths = @(':(top)**', ':(top,exclude)Website/**', ':(top,exclude)Mobile/**') }
    )
    foreach ($group in $groups) {
        $paths = $group.Paths
        if (@(Invoke-Git status --porcelain --untracked-files=all -- @paths).Count) {
            if ($dry) { Write-Output "Would commit pending $($group.Scope) changes"; continue }
            Invoke-Git add -A -- @paths | Out-Host
            $summary = @(Invoke-Git diff --cached --stat -- @paths) -join "`n"
            $message = "chore($($group.Scope)): sync pending changes"
            if ($noDeploy) { $message += ' [skip ci]' }
            Invoke-Git commit --only -m $message -m $summary -- @paths | Out-Host
        }
    }
    if ($dry -and $status.Count) {
        Write-Output 'Dry run: target trees and versions below reflect committed work only; rerun after automatic commits/pull for the final plan.'
    }
    $remoteMain = Resolve-Ref refs/remotes/origin/main
    if ($remoteMain) {
        & git merge-base --is-ancestor $remoteMain HEAD
        if ($LASTEXITCODE -eq 1) {
            if ($dry) { Write-Output 'Would integrate origin/main before publishing (remote state is from the last fetch).' }
            else {
                Write-Output 'Integrating origin/main before publishing...'
                # Merge preserves existing commits and release tags, unlike rebasing.
                & git -c merge.ff=true merge --no-edit $remoteMain
                if ($LASTEXITCODE -ne 0) {
                    throw 'Could not integrate origin/main. Local commits are saved; resolve/commit conflicts or abort the merge, then rerun sync. Nothing was pushed.'
                }
            }
        } elseif ($LASTEXITCODE -ne 0) { throw 'Could not compare main with origin/main.' }
    }
    # A remote update may have changed release metadata.
    $versions = Get-Content versions.json -Raw | ConvertFrom-Json
    foreach ($product in @('website', 'mobile')) {
        if ($versions.$product -notmatch '^\d+\.\d+\.\d+$') { throw "Invalid $product version." }
    }
    $plans = @()
    foreach ($product in $products) {
        $name = $product.Name; $folder = $product.Folder
        $tree = Resolve-Ref "HEAD:$folder"
        if (!$tree -or (Invoke-Git cat-file -t $tree) -ne 'tree') { throw "Missing tracked folder: $folder" }
        $currentTag = "$name-v$($versions.$name)"
        $baseline = Resolve-Ref "refs/tags/$currentTag^{commit}"
        $bump = $null; $next = $versions.$name
        if ($release) {
            if ($baseline) {
                & git merge-base --is-ancestor $baseline HEAD
                if ($LASTEXITCODE -ne 0) { throw "$currentTag is not an ancestor of main." }
            } elseif (@(Invoke-Git tag --list "$name-v*").Count) {
                throw "versions.json has no matching tag $currentTag. Repair version metadata first."
            }
            $logArgs = @('log', '--format=%B%x1e')
            if ($baseline) { $logArgs += "$baseline..HEAD" }
            $logArgs += @('--', $folder)
            $messages = @(Invoke-Git @logArgs) -join "`n"
            if ($messages.Trim()) {
                $bump = 'patch'
                if ($messages -match '(?m)^feat(?:\([^\r\n)]+\))?:') { $bump = 'minor' }
                if ($messages -match '(?m)^[a-zA-Z]+(?:\([^\r\n)]+\))?!:|^BREAKING[ -]CHANGE:') { $bump = 'major' }
                $parts = @($next.Split('.') | ForEach-Object { [int]$_ })
                switch ($bump) {
                    major { $parts[0]++; $parts[1] = 0; $parts[2] = 0 }
                    minor { $parts[1]++; $parts[2] = 0 }
                    patch { $parts[2]++ }
                }
                $next = $parts -join '.'
                if (Resolve-Ref "refs/tags/$name-v$next") { throw "Tag $name-v$next already exists." }
            }
        }
        $plans += @{ Product = $product; Next = $next; Bump = $bump }
        Write-Output "$name $($versions.$name) -> $next$(if ($bump) { " ($bump)" })"
        if ($name -eq 'website' -and $bump -eq 'major') {
            Write-Warning 'Breaking website/API release: explicitly verify mobile compatibility before releasing mobile.'
        }
    }
    $changed = @($plans | Where-Object { $_.Bump })
    if ($changed.Count -and !$dry) {
        $paths = @('versions.json')
        foreach ($plan in $changed) {
            $name = $plan.Product.Name; $versions.$name = $plan.Next
            if ($name -eq 'website') {
                Write-Utf8 'Website/version.json' ((@{ version = $plan.Next } | ConvertTo-Json) + "`n")
                $paths += 'Website/version.json'
            } else {
                $pubspec = Get-Content Mobile/pubspec.yaml -Raw
                if ($pubspec -notmatch '(?m)^version: (\d+\.\d+\.\d+)(?:\+(\d+))?\s*$') { throw 'Missing Flutter version.' }
                $build = 1
                if ($Matches[2]) { $build = [int]$Matches[2] + 1 }
                $pubspec = [regex]::Replace($pubspec, '(?m)^version: [^\r\n]+', "version: $($plan.Next)+$build")
                Write-Utf8 'Mobile/pubspec.yaml' $pubspec
                $paths += 'Mobile/pubspec.yaml'
            }
        }
        Write-Utf8 'versions.json' (($versions | ConvertTo-Json) + "`n")
        Invoke-Git add -- @paths | Out-Host
        Invoke-Git commit -m 'chore(release): update product versions' | Out-Host
    }
    $push = @('HEAD:refs/heads/main')
    foreach ($plan in $plans) {
        $product = $plan.Product
        $tree = Invoke-Git rev-parse "HEAD:$($product.Folder)"
        foreach ($target in $product.Branches) {
            $parent = Resolve-Ref "refs/remotes/origin/$target"
            $local = Resolve-Ref "refs/heads/$target"
            if ($parent -and $local -and $parent -ne $local) {
                & git merge-base --is-ancestor $parent $local
                if ($LASTEXITCODE -ne 0) { throw "Local $target diverges from origin/$target; resolve it first." }
            }
            if (!$parent) { $parent = $local }
            if (!$parent -or (Resolve-Ref "$parent^{tree}") -ne $tree) {
                Write-Output "Rebuild $target from $($product.Folder)/"
                if (!$dry) {
                    $commitArgs = @('commit-tree', $tree)
                    if ($parent) { $commitArgs += @('-p', $parent) }
                    $message = "$target`: sync from main@$(Invoke-Git rev-parse --short HEAD)"
                    if ($noDeploy) { $message += ' [skip ci]' }
                    $commitArgs += @('-m', $message)
                    $tip = Invoke-Git @commitArgs
                    Invoke-Git update-ref "refs/heads/$target" $tip | Out-Host
                }
            } elseif (!$dry) { Invoke-Git update-ref "refs/heads/$target" $parent | Out-Host }
            $push += "refs/heads/${target}:refs/heads/$target"
        }
        if ($plan.Bump) {
            $tag = "$($product.Name)-v$($plan.Next)"
            Write-Output "Create release tag $tag on main"
            if (!$dry) { Invoke-Git tag -a $tag -m "$($product.Name) $($plan.Next)" | Out-Host }
        }
        # Retry unpublished tags after a failed atomic push.
        foreach ($tag in @(Invoke-Git tag --list "$($product.Name)-v*")) {
            if ($noDeploy) { continue }
            $push += "refs/tags/${tag}:refs/tags/$tag"
        }
    }
    if ($dry) { Write-Output 'Dry run: would atomically push main and selected targets/tags to origin.' }
    else { Invoke-Git push --atomic origin @push | Out-Host }
} finally { Pop-Location }
