<#
.SYNOPSIS
  Swap which git worktree the Local WP site's scoop_rest plugin symlink
  points at, so live browser testing (https://ops.swanky.local) actually
  exercises a specific branch's code instead of always the main checkout.

.DESCRIPTION
  The Local site's plugin directory is a directory reparse point:
    C:\Users\gusre\Local Sites\swank-tracker\app\public\wp-content\plugins\scoop_rest
  normally pointing at the main checkout root (see CLAUDE.md). This script
  repoints that ONE link at a different worktree, or back to main, without
  touching git state or file contents anywhere.

  Uses a directory JUNCTION (mklink /J), not a symbolic link -- creating a
  symlink requires admin privileges on Windows unless Developer Mode is on;
  a junction needs neither, and PHP/WordPress can't tell the difference for
  ordinary file reads. The original link this replaces may itself be either
  kind (it's a real symlink today) -- this script accepts and repoints both.

  Safe by construction:
    - Refuses to run unless the plugin path is already a symlink or junction.
    - Removes only the reparse point itself (rmdir with no /S flag) --
      never recurses into, and so can never delete, whatever it currently
      points at.
    - Verifies the new link resolves to the intended target before
      reporting success.

.PARAMETER To
  Which worktree to point Local at. Matches, in order:
    - "main"                            the main checkout (original state)
    - a worktree directory name         e.g. "performance-refactor"
    - a branch name, exact or partial   e.g. "worktree-performance-refactor"
  Omit to just print current status and available targets, unchanged.

.EXAMPLE
  .\local-plugin-link.ps1
  .\local-plugin-link.ps1 -To performance-refactor
  .\local-plugin-link.ps1 -To main
#>
param(
  [string]$To
)

$ErrorActionPreference = 'Stop'

$RepoRoot = 'C:\Users\gusre\OneDrive\Documents\git\scoop-tracker'
$LinkPath = 'C:\Users\gusre\Local Sites\swank-tracker\app\public\wp-content\plugins\scoop_rest'

function Get-Worktrees {
  Push-Location $RepoRoot
  try {
    $raw = git worktree list --porcelain
  } finally {
    Pop-Location
  }

  $entries = New-Object System.Collections.Generic.List[object]
  $cur = @{}
  foreach ($line in $raw) {
    if ($line -eq '') {
      if ($cur.Count -gt 0) { $entries.Add([pscustomobject]$cur) }
      $cur = @{}
      continue
    }
    if ($line -match '^worktree (.+)$') {
      $cur.Path = ($Matches[1] -replace '/', '\')
    } elseif ($line -match '^branch refs/heads/(.+)$') {
      $cur.Branch = $Matches[1]
    } elseif ($line -eq 'bare') {
      $cur.Branch = '(bare)'
    }
  }
  if ($cur.Count -gt 0) { $entries.Add([pscustomobject]$cur) }
  return $entries
}

function Get-LinkItem {
  Get-Item -LiteralPath $LinkPath -Force
}

function Show-Status {
  param($Worktrees)

  $item = Get-LinkItem
  $isLink = $item.LinkType -eq 'SymbolicLink' -or $item.LinkType -eq 'Junction'
  Write-Host "Local plugin dir: $LinkPath"
  if ($isLink) {
    Write-Host ("  points at ({0}): {1}" -f $item.LinkType, $item.Target[0])
  } else {
    Write-Host "  NOT a link (real directory/file -- this script will refuse to touch it)" -ForegroundColor Yellow
  }
  Write-Host ""
  Write-Host "Available targets:"
  foreach ($w in $Worktrees) {
    $name = Split-Path $w.Path -Leaf
    $isActive = $isLink -and ($item.Target[0] -eq $w.Path)
    $marker = ''
    if ($isActive) { $marker = '  [ACTIVE]' }
    Write-Host ("  {0,-24} branch={1,-32} {2}{3}" -f $name, $w.Branch, $w.Path, $marker)
  }
}

$worktrees = Get-Worktrees

if ([string]::IsNullOrEmpty($To)) {
  Show-Status -Worktrees $worktrees
  Write-Host ""
  Write-Host "Usage: .\local-plugin-link.ps1 -To <worktree-name|main|branch-name>"
  return
}

$target = $null
if ($To -eq 'main') {
  $mainEntry = $worktrees | Where-Object { $_.Path -eq $RepoRoot } | Select-Object -First 1
  if ($mainEntry) { $target = $mainEntry.Path }
} else {
  $match = $worktrees | Where-Object {
    (Split-Path $_.Path -Leaf) -eq $To -or $_.Branch -eq $To -or $_.Branch -like "*$To*"
  } | Select-Object -First 1
  if ($match) { $target = $match.Path }
}

if ([string]::IsNullOrEmpty($target)) {
  Write-Host "No worktree matched '$To'. Available:" -ForegroundColor Red
  foreach ($w in $worktrees) { Write-Host ("  {0}  branch={1}" -f $w.Path, $w.Branch) }
  exit 1
}

$item = Get-LinkItem
if ($item.LinkType -ne 'SymbolicLink' -and $item.LinkType -ne 'Junction') {
  Write-Host "REFUSING: $LinkPath is not a symlink or junction -- won't touch a real directory." -ForegroundColor Red
  exit 1
}

if ($item.Target[0] -eq $target) {
  Write-Host "Already pointing at $target -- nothing to do."
  exit 0
}

Write-Host "Repointing $LinkPath"
Write-Host "  from ($($item.LinkType)): $($item.Target[0])"
Write-Host "  to:   $target"

# Remove ONLY the reparse point itself. rmdir with no /S on a
# symlink/junction removes just the link, never the target's contents.
# This is the one step here that would be catastrophic to get wrong, so
# it deliberately uses the most conservative Windows-native option rather
# than a PowerShell cmdlet whose recursive behavior can vary by version.
& cmd /c "rmdir `"$LinkPath`""
if ($LASTEXITCODE -ne 0) {
  throw "Failed to remove existing link (exit $LASTEXITCODE) -- nothing else changed."
}

# Junction (mklink /J), not a symlink -- needs no admin/Developer Mode
# privilege, and behaves identically to a symlink for PHP/WordPress file
# reads (see .DESCRIPTION above). Recreate immediately even on failure so
# the plugin path is never left missing.
& cmd /c "mklink /J `"$LinkPath`" `"$target`""
if ($LASTEXITCODE -ne 0) {
  throw "Failed to create junction to $target (exit $LASTEXITCODE) -- $LinkPath is now MISSING, fix this before doing anything else (e.g. re-run with -To main)."
}

$verify = Get-LinkItem
if (($verify.LinkType -ne 'SymbolicLink' -and $verify.LinkType -ne 'Junction') -or $verify.Target[0] -ne $target) {
  throw "Link created but doesn't verify as expected -- check manually before trusting the site."
}

Write-Host "Done. Local site now serves the plugin from: $target" -ForegroundColor Green
Write-Host "Hard-refresh the browser -- JS loads as ES modules and the browser will cache the old files."
