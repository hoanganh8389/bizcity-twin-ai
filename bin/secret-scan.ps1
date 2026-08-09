<#
.SYNOPSIS
    Secret leak scanner for bizcity-twin-ai before public push.

.DESCRIPTION
    Scans current working tree (and optionally git history) for high-risk
    credential patterns: BizCity gateway keys, OpenAI keys, Google API keys,
    Anthropic, Tavily, Kling, generic Bearer tokens.

    NOT a replacement for gitleaks/trufflehog — quick local sanity check.
    Run before every public release.

.PARAMETER WithHistory
    Also grep `git log --all -p` for historical leaks. Slow but thorough.

.PARAMETER Path
    Root path to scan. Defaults to script's parent (plugin root).

.EXAMPLE
    .\bin\secret-scan.ps1
    .\bin\secret-scan.ps1 -WithHistory

.NOTES
    Author: Johnny Chu (Chu Hoàng Anh) <hoanganh.itm@gmail.com>
    Phase:  0.98 IP Protection
    License: GPL-2.0-or-later
#>

[CmdletBinding()]
param(
    [switch]$WithHistory,
    [string]$Path
)

if (-not $Path) {
    $Path = Split-Path $PSScriptRoot -Parent
}

$ErrorActionPreference = 'Continue'

# ── Patterns ──────────────────────────────────────────────────────────────
# (label, regex, severity)
$patterns = @(
    @{ Label='BizCity gateway key'; Regex='biz-[A-Za-z0-9]{20,}'; Severity='CRITICAL' },
    @{ Label='OpenAI key';          Regex='sk-(proj-)?[A-Za-z0-9]{32,}'; Severity='CRITICAL' },
    @{ Label='Anthropic key';       Regex='sk-ant-[A-Za-z0-9-]{32,}';     Severity='CRITICAL' },
    @{ Label='Google API key';      Regex='AIza[0-9A-Za-z_-]{30,}';        Severity='HIGH' },
    @{ Label='OpenRouter key';      Regex='sk-or-v1-[A-Za-z0-9]{32,}';     Severity='CRITICAL' },
    @{ Label='Tavily key';          Regex='tvly-[A-Za-z0-9]{20,}';         Severity='HIGH' },
    @{ Label='Kling/PiAPI key';     Regex='[a-f0-9]{32}-[a-f0-9]{32}';     Severity='MEDIUM' },
    @{ Label='Generic Bearer';      Regex='Bearer\s+[A-Za-z0-9_\-\.]{30,}'; Severity='MEDIUM' },
    @{ Label='AWS access key';      Regex='AKIA[0-9A-Z]{16}';              Severity='CRITICAL' },
    @{ Label='Slack token';         Regex='xox[baprs]-[A-Za-z0-9-]{10,}';   Severity='HIGH' }
)

# Files / folders to skip (matched against full path)
$skipDirs = @('node_modules', 'vendor', '.git', 'dist', 'build', '_archived', '_library', '_research', '.rollup.cache', '.pnpm', '.next', '.cache')
$skipExt  = @('.png','.jpg','.jpeg','.gif','.webp','.ico','.zip','.gz','.woff','.woff2','.ttf','.mp4','.mp3','.pdf','.map','.lock')

# Known-public values that match high-entropy patterns but are NOT secrets.
# (e.g. YouTube InnerTube web API key shipped in the public web bundle.)
$allowlist = @(
    'AIzaSyAO_FJ2SlqU8Q4STEHLGCilw_Y9_11qcW8'  # YouTube InnerTube web client key (public)
)

Write-Host "[scan] BizCity secret scanner" -ForegroundColor Cyan
Write-Host "   Path:    $Path" -ForegroundColor Gray
Write-Host "   History: $($WithHistory.IsPresent)" -ForegroundColor Gray
Write-Host ""

# ── Working tree scan ─────────────────────────────────────────────────────
$findings = New-Object System.Collections.Generic.List[object]

# Custom recursive walker that prunes skipDirs early (avoids pnpm long-path errors)
function Get-ScanFiles {
    param([string]$Root, [string[]]$SkipDirs, [string[]]$SkipExt)
    $stack = New-Object System.Collections.Generic.Stack[string]
    $stack.Push($Root)
    while ($stack.Count -gt 0) {
        $dir = $stack.Pop()
        try {
            foreach ($entry in [System.IO.Directory]::EnumerateFileSystemEntries($dir)) {
                $name = [System.IO.Path]::GetFileName($entry)
                if ($SkipDirs -contains $name) { continue }
                try {
                    $attr = [System.IO.File]::GetAttributes($entry)
                    if ($attr -band [System.IO.FileAttributes]::Directory) {
                        $stack.Push($entry)
                    } else {
                        $ext = [System.IO.Path]::GetExtension($entry).ToLower()
                        if ($SkipExt -contains $ext) { continue }
                        $entry
                    }
                } catch { continue }
            }
        } catch { continue }
    }
}

$files = @(Get-ScanFiles -Root $Path -SkipDirs $skipDirs -SkipExt $skipExt)

$total = $files.Count
$i = 0
foreach ($f in $files) {
    $i++
    if ($i % 500 -eq 0) {
        Write-Progress -Activity "Scanning files" -Status "$i / $total" -PercentComplete (($i/$total)*100)
    }
    try {
        $content = [System.IO.File]::ReadAllText($f)
    } catch { continue }
    foreach ($p in $patterns) {
        $hits = [regex]::Matches($content, $p.Regex)
        foreach ($m in $hits) {
            # Skip obvious placeholders / docs
            $val = $m.Value
            if ($val -match 'xxx|example|placeholder|YOUR_|REPLACE|<.*>|123456') { continue }
            if ($allowlist -contains $val) { continue }
            $rel = $f
            if ($f.StartsWith($Path)) { $rel = $f.Substring($Path.Length + 1) }
            $findings.Add([pscustomobject]@{
                Severity = $p.Severity
                Label    = $p.Label
                File     = $rel
                Match    = $val.Substring(0, [Math]::Min($val.Length, 60))
            }) | Out-Null
        }
    }
}
Write-Progress -Activity "Scanning files" -Completed

# ── Git history scan (optional) ──────────────────────────────────────────
if ($WithHistory) {
    Write-Host "[history] Scanning git log (slow)..." -ForegroundColor Cyan
    Push-Location $Path
    try {
        $combined = ($patterns | ForEach-Object { $_.Regex }) -join '|'
        $log = git log --all -p -G $combined 2>$null
        if ($log) {
            foreach ($p in $patterns) {
                $hist = [regex]::Matches($log, $p.Regex)
                foreach ($m in $hist) {
                    $val = $m.Value
                    if ($val -match 'xxx|example|placeholder|YOUR_|REPLACE|<.*>') { continue }
                    $findings.Add([pscustomobject]@{
                        Severity = $p.Severity
                        Label    = $p.Label + ' (git history)'
                        File     = '<history>'
                        Match    = $val.Substring(0, [Math]::Min($val.Length, 60))
                    }) | Out-Null
                }
            }
        }
    } finally { Pop-Location }
}

# ── Report ────────────────────────────────────────────────────────────────
Write-Host ""
if ($findings.Count -eq 0) {
    Write-Host "[OK] No secrets detected." -ForegroundColor Green
    exit 0
}

$grouped = $findings | Group-Object Severity | Sort-Object @{e={
    switch ($_.Name) { 'CRITICAL' {0} 'HIGH' {1} 'MEDIUM' {2} default {3} }
}}

foreach ($g in $grouped) {
    $color = switch ($g.Name) { 'CRITICAL' {'Red'} 'HIGH' {'Yellow'} default {'DarkYellow'} }
    Write-Host ("[{0}] {1} finding(s)" -f $g.Name, $g.Count) -ForegroundColor $color
    $g.Group | Select-Object Label, File, Match -Unique | Format-Table -AutoSize
}

$crit = ($findings | Where-Object Severity -eq 'CRITICAL').Count
if ($crit -gt 0) {
    Write-Host "[FAIL] $crit CRITICAL secret(s) detected. DO NOT push." -ForegroundColor Red
    exit 2
}

Write-Host "[WARN] Findings present but none CRITICAL. Review before push." -ForegroundColor Yellow
exit 1
