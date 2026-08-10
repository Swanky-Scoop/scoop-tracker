<#
.SYNOPSIS
  Import a WP Migrate DB (.sql.gz) export from production into the local
  "swank-tracker" Local by Flywheel site, with a serialization-safe domain
  rewrite so post content / options built on production's URL still resolve.

.USAGE
  # Import the newest *.sql.gz sitting in data-exports/
  .\import-prod-db.ps1

  # Import a specific file
  .\import-prod-db.ps1 -DumpPath 'C:\Users\gusre\Downloads\swanktracker-migrate-....sql.gz'

  # Skip the domain rewrite (leaves post content etc. pointing at production)
  .\import-prod-db.ps1 -SkipRewrite

.NOTES
  Local site paths/ports below are specific to this machine's Local install
  (site id uBXwpxgAI). If the Local site gets recreated, these will change -
  check C:\Users\<you>\AppData\Roaming\Local\run\<siteId>\conf\mysql\my.cnf
  for the new port, and sites.json for the new site id.

  The dump is a full DROP TABLE + CREATE TABLE + data replace (WP Migrate DB
  format) - it fully overwrites the local `local` database's track_* tables,
  which is why this always takes a backup first.

  Known gotcha: the export includes Wordfence (track_wf*) and 2FA
  (track_wfls_2fa_secrets) tables from production. If those enforce rules
  the local site doesn't expect, you can get locked out of /wp-admin/ after
  import - deactivate Wordfence locally (or truncate its tables) if that
  happens.
#>

param(
    [string]$DumpPath,
    [switch]$SkipRewrite,
    [switch]$SkipBackup
)

$ErrorActionPreference = 'Stop'

# --- Local site config (swank-tracker) ---
$MysqlExe     = 'C:\Users\gusre\AppData\Roaming\Local\lightning-services\mysql-8.4.0\bin\win64\bin\mysql.exe'
$MysqldumpExe = 'C:\Users\gusre\AppData\Roaming\Local\lightning-services\mysql-8.4.0\bin\win64\bin\mysqldump.exe'
$PhpExe       = 'C:\Users\gusre\AppData\Roaming\Local\lightning-services\php-8.1.29+0\bin\win64\php.exe'
$PhpIni       = 'C:\Users\gusre\AppData\Roaming\Local\run\uBXwpxgAI\conf\php\php.ini'
$GzipExe      = 'C:\Program Files\Git\usr\bin\gzip.exe'
$DbHost       = '127.0.0.1'
$DbPort       = 10004
$DbUser       = 'root'
$DbPass       = 'root'
$DbName       = 'local'
$TablePrefix  = 'track_'
$LocalDomain  = 'ops.swanky.local'   # bare host, from wp-config.php WP_HOME

$ScriptDir   = $PSScriptRoot
$DataExports = Split-Path -Parent $ScriptDir
$BackupDir   = Join-Path $DataExports 'backups'
$ReplaceScript = Join-Path $ScriptDir 'serialize-safe-replace.php'

foreach ($exe in @($MysqlExe, $MysqldumpExe, $PhpExe, $PhpIni, $GzipExe)) {
    if (-not (Test-Path $exe)) { throw "Required binary not found: $exe" }
}

if (-not $DumpPath) {
    $latest = Get-ChildItem -Path $DataExports -Filter '*.sql.gz' -File |
        Sort-Object LastWriteTime -Descending | Select-Object -First 1
    if (-not $latest) { throw "No *.sql.gz found in $DataExports and -DumpPath not given." }
    $DumpPath = $latest.FullName
}
if (-not (Test-Path $DumpPath)) { throw "Dump not found: $DumpPath" }

Write-Host "Using dump: $DumpPath"

function Invoke-Native([string]$CommandLine) {
    # Route through a temp .cmd file rather than `cmd /c "<string>"` directly -
    # avoids PowerShell's parser/quoting fights over embedded < > " when the
    # command line itself contains shell redirection.
    $batFile = Join-Path $env:TEMP "scoop-cmd-$([guid]::NewGuid()).cmd"
    Set-Content -Path $batFile -Value $CommandLine -Encoding Ascii
    try {
        $p = Start-Process -FilePath 'cmd.exe' -ArgumentList @('/c', "`"$batFile`"") -NoNewWindow -PassThru -Wait
        if ($p.ExitCode -ne 0) { throw "Command failed (exit $($p.ExitCode)): $CommandLine" }
    } finally {
        Remove-Item $batFile -Force -ErrorAction SilentlyContinue
    }
}

function Expand-GZipFile([string]$InFile, [string]$OutFile) {
    # NOT System.IO.Compression.GZipStream: .NET Framework's implementation (what
    # Windows PowerShell 5.1 uses) silently stops after the FIRST member of a
    # multi-member gzip stream. WP Migrate DB streams its export in chunks and
    # concatenates them, producing exactly that - GZipStream read only ~2.8KB of
    # a multi-MB file with no error, and every earlier test run of this script
    # silently imported almost nothing. Git's gzip.exe handles multi-member
    # streams correctly, so shell out to it instead.
    $cmd = '"{0}" -dc "{1}" > "{2}"' -f $GzipExe, $InFile, $OutFile
    Invoke-Native $cmd
}

function Compress-GZipFile([string]$InFile, [string]$OutFile) {
    $cmd = '"{0}" -c "{1}" > "{2}"' -f $GzipExe, $InFile, $OutFile
    Invoke-Native $cmd
}

# --- 1. Decompress the export to a temp file ---
$tempSql = Join-Path $env:TEMP "scoop-import-$(Get-Date -Format 'yyyyMMddHHmmss').sql"
Write-Host "Decompressing to $tempSql ..."
Expand-GZipFile -InFile $DumpPath -OutFile $tempSql

# Pull the production domain(s) out of the dump itself rather than hardcoding
# one. Two sources, because this site's `home` and `siteurl` options have been
# observed to disagree (a leftover from a domain rename that didn't fully
# propagate) - relying on only one leaves the other domain's references stale.
$header = Get-Content -Path $tempSql -TotalCount 40
$urlLine = $header | Where-Object { $_ -match '^#\s*URL:\s*(?:https?:)?//(\S+)' }
$homeDomain = $null
if ($urlLine -and $Matches) { $homeDomain = $Matches[1] }

$siteurlMatch = Select-String -Path $tempSql -Pattern "'siteurl',\s*'https?://([^'/]+)" | Select-Object -First 1
$siteurlDomain = $null
if ($siteurlMatch) { $siteurlDomain = $siteurlMatch.Matches[0].Groups[1].Value }

$prodDomains = @($homeDomain, $siteurlDomain) | Where-Object { $_ } | Select-Object -Unique
if (-not $SkipRewrite -and $prodDomains.Count -eq 0) {
    Write-Warning "Couldn't find a production domain in the dump - skipping domain rewrite."
    $SkipRewrite = $true
}
foreach ($d in $prodDomains) { Write-Host "Detected production domain: $d" }
if ($prodDomains.Count -gt 1) {
    Write-Warning "home ($homeDomain) and siteurl ($siteurlDomain) disagree in this export - rewriting both."
}

# --- 2. Back up the current local DB before overwriting it ---
if (-not $SkipBackup) {
    New-Item -ItemType Directory -Force -Path $BackupDir | Out-Null
    $stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
    $backupSql = Join-Path $BackupDir "local-pre-import-$stamp.sql"
    $backupGz  = "$backupSql.gz"
    Write-Host "Backing up current local DB to $backupGz ..."
    $backupCmd = '"{0}" -h {1} -P {2} -u {3} --password={4} {5} > "{6}"' -f `
        $MysqldumpExe, $DbHost, $DbPort, $DbUser, $DbPass, $DbName, $backupSql
    Invoke-Native $backupCmd
    Compress-GZipFile -InFile $backupSql -OutFile $backupGz
    Remove-Item $backupSql
    Write-Host "Backup saved."
} else {
    Write-Host "Skipping backup (-SkipBackup)."
}

# --- 3. Import ---
Write-Host "Importing into local DB '$DbName' (this replaces all $TablePrefix* tables) ..."
$importCmd = '"{0}" -h {1} -P {2} -u {3} --password={4} {5} < "{6}"' -f `
    $MysqlExe, $DbHost, $DbPort, $DbUser, $DbPass, $DbName, $tempSql
Invoke-Native $importCmd
Write-Host "Import complete."

Remove-Item $tempSql -Force

# --- 4. Serialization-safe domain rewrite (once per distinct old domain found) ---
if (-not $SkipRewrite) {
    foreach ($d in $prodDomains) {
        Write-Host "Rewriting '$d' -> '$LocalDomain' (serialization-safe) ..."
        & $PhpExe -c $PhpIni $ReplaceScript $DbHost $DbPort $DbUser $DbPass $DbName $TablePrefix $d $LocalDomain
    }
} else {
    Write-Host "Skipping domain rewrite."
}

# --- 5. Sanity check ---
Write-Host "`nRow counts after import:"
$checkSql = "SELECT 'tub', COUNT(*) FROM {0}pods_tub UNION ALL SELECT 'flavor', COUNT(*) FROM {0}pods_flavor UNION ALL SELECT 'options', COUNT(*) FROM {0}options" -f $TablePrefix
$checkCmd = '"{0}" -h {1} -P {2} -u {3} --password={4} -N -e "{5}" {6}' -f `
    $MysqlExe, $DbHost, $DbPort, $DbUser, $DbPass, $checkSql, $DbName
Invoke-Native $checkCmd

Write-Host "`nDone. Heads up: this also pulled in production's Wordfence (${TablePrefix}wf*) and"
Write-Host "2FA (${TablePrefix}wfls_2fa_secrets) tables. If you get locked out of /wp-admin/ on"
Write-Host "https://$LocalDomain/, deactivate Wordfence locally (Plugins screen or rename its"
Write-Host "folder in wp-content/plugins/) and that'll clear it."
