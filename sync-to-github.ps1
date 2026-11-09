# Watches this folder and pushes every change to GitHub (NotRed1776/LDP).
# Started at Windows sign-in by the "Lycan Site Sync" shortcut in the Startup folder.
# To stop syncing: delete that shortcut and end this PowerShell process (or restart).
# Activity log: %LOCALAPPDATA%\LycanSiteSync\sync.log

$git = "$env:ProgramFiles\Git\cmd\git.exe"
$checkEverySeconds = 15
Set-Location $PSScriptRoot

$logDir = Join-Path $env:LOCALAPPDATA 'LycanSiteSync'
New-Item -ItemType Directory -Force -Path $logDir | Out-Null
$logFile = Join-Path $logDir 'sync.log'
function Write-SyncLog($message) { Add-Content -Path $logFile -Value "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')  $message" }

# only one copy of this script runs at a time
$mutex = New-Object System.Threading.Mutex($false, 'Local\LycanSiteSync')
if (-not $mutex.WaitOne(0)) { exit }
Write-SyncLog 'Sync started'

$previousStatus = ''
$pushFailing = $false

while ($true) {
  $status = (& $git status --porcelain) -join "`n"

  # commit once the folder has looked the same for a full check, so half-saved files aren't captured
  if ($status -and $status -eq $previousStatus) {
    & $git add -A
    & $git commit -q -m "Auto-sync: $(Get-Date -Format 'yyyy-MM-dd HH:mm')"
    if ($LASTEXITCODE -eq 0) { Write-SyncLog "Committed: $($status -replace "`n", '; ')" }
    $status = ''
  }
  $previousStatus = $status

  # push anything not yet on GitHub; this also catches up after being offline
  $ahead = & $git rev-list --count '@{u}..HEAD' 2>$null
  if ($LASTEXITCODE -eq 0 -and [int]$ahead -gt 0) {
    $output = & $git push -q 2>&1 | Out-String
    $pushSucceeded = $LASTEXITCODE -eq 0
    if (-not $pushSucceeded) {
      # GitHub has commits this computer doesn't (for example, an edit on the website): merge them in first
      $output += & $git pull -q --rebase 2>&1 | Out-String
      if ($LASTEXITCODE -eq 0) {
        $output = & $git push -q 2>&1 | Out-String
        $pushSucceeded = $LASTEXITCODE -eq 0
      } else {
        & $git rebase --abort 2>$null
      }
    }
    if ($pushSucceeded) {
      Write-SyncLog "Pushed $ahead commit(s) to GitHub"
      $pushFailing = $false
    } elseif (-not $pushFailing) {
      Write-SyncLog "Push failed, will keep retrying: $output"
      $pushFailing = $true
    }
  }

  Start-Sleep -Seconds $checkEverySeconds
}
