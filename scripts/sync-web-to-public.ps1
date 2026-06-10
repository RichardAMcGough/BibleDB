param(
    [string]$SourceRoot = "",
    [string]$TargetRoot = "",
    [switch]$Apply,
    [switch]$IncludeConfig
)

$ErrorActionPreference = 'Stop'

$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
if (-not $SourceRoot) {
    $SourceRoot = (Resolve-Path (Join-Path $scriptDir "..\web")).Path
}
if (-not $TargetRoot) {
    $TargetRoot = (Resolve-Path (Join-Path $scriptDir "..\..\public_html\bible")).Path
}

if (-not (Test-Path $SourceRoot)) {
    throw "SourceRoot not found: $SourceRoot"
}
if (-not (Test-Path $TargetRoot)) {
    throw "TargetRoot not found: $TargetRoot"
}

# Keep runtime environment-specific files out of auto-sync by default.
$excludeExact = @(
    'config.php'
)
if ($IncludeConfig) {
    $excludeExact = @()
}

function Get-RelPath([string]$base, [string]$full) {
    $baseUri = [Uri]((Resolve-Path $base).Path + [IO.Path]::DirectorySeparatorChar)
    $fullUri = [Uri]((Resolve-Path $full).Path)
    $rel = $baseUri.MakeRelativeUri($fullUri).ToString()
    return [Uri]::UnescapeDataString($rel).Replace('/', [IO.Path]::DirectorySeparatorChar)
}

$srcFiles = Get-ChildItem -Path $SourceRoot -Recurse -File | ForEach-Object {
    $rel = Get-RelPath $SourceRoot $_.FullName
    [PSCustomObject]@{
        Source = $_.FullName
        Rel    = $rel
        Skip   = ($excludeExact -contains $rel)
    }
} | Where-Object { -not $_.Skip }

$toCopy = @()
foreach ($f in $srcFiles) {
    $dst = Join-Path $TargetRoot $f.Rel
    if (-not (Test-Path $dst)) {
        $toCopy += [PSCustomObject]@{ Rel = $f.Rel; Reason = 'missing in target'; Source = $f.Source; Dest = $dst }
        continue
    }

    $srcHash = (Get-FileHash -Algorithm SHA256 -Path $f.Source).Hash
    $dstHash = (Get-FileHash -Algorithm SHA256 -Path $dst).Hash
    if ($srcHash -ne $dstHash) {
        $toCopy += [PSCustomObject]@{ Rel = $f.Rel; Reason = 'content differs'; Source = $f.Source; Dest = $dst }
    }
}

Write-Host ""
Write-Host "Source : $SourceRoot"
Write-Host "Target : $TargetRoot"
Write-Host "Mode   : " -NoNewline
if ($Apply) { Write-Host 'APPLY (copy changes)' -ForegroundColor Yellow }
else { Write-Host 'DRY RUN (no files copied)' -ForegroundColor Cyan }
Write-Host ""

if ($toCopy.Count -eq 0) {
    Write-Host "No changes detected. Target is in sync." -ForegroundColor Green
    exit 0
}

Write-Host "Files to sync: $($toCopy.Count)" -ForegroundColor Magenta
$toCopy | Sort-Object Rel | ForEach-Object {
    Write-Host (" - {0} [{1}]" -f $_.Rel, $_.Reason)
}

if (-not $Apply) {
    Write-Host ""
    Write-Host "Dry run only. Re-run with -Apply to copy files." -ForegroundColor Cyan
    exit 0
}

$copied = 0
foreach ($item in $toCopy) {
    $dstDir = Split-Path -Parent $item.Dest
    if (-not (Test-Path $dstDir)) {
        New-Item -ItemType Directory -Path $dstDir -Force | Out-Null
    }
    Copy-Item -Path $item.Source -Destination $item.Dest -Force
    $copied++
}

Write-Host ""
Write-Host "Sync complete. Copied $copied file(s)." -ForegroundColor Green
