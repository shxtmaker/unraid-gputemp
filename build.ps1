#Requires -Version 5.1
<#
.SYNOPSIS
  gputemp build pipeline: LF normalisation -> txz package -> SHA256 -> plg.

.DESCRIPTION
  Steps:
    1. Run validate.ps1 as the pre-build gate (disable with -SkipValidate).
    2. Normalise every source text file to LF line endings, UTF-8 without BOM.
    3. Pack archive/usr into dist/gputemp-2026.08.10-x86_64-1.txz with the
       Windows bundled bsdtar (xz compression). Falls back to a WSL hint when
       tar is unavailable.
    4. Compute the SHA256 of the package and fill the &sha256; placeholder of
       gputemp.plg.tmpl to produce dist/gputemp.plg.

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File .\build.ps1
#>

[CmdletBinding()]
param(
    [switch]$SkipValidate
)

$ErrorActionPreference = 'Stop'
$repoRoot   = $PSScriptRoot
$archiveDir = Join-Path $repoRoot 'archive'
$distDir    = Join-Path $repoRoot 'dist'
$version    = '2026.08.10'
$pkgName    = "gputemp-$version-x86_64-1.txz"
$txzPath    = Join-Path $distDir $pkgName
$plgTmpl    = Join-Path $repoRoot 'gputemp.plg.tmpl'
$plgOut     = Join-Path $distDir 'gputemp.plg'

function Fail([string]$msg) {
    Write-Host "[build] ERROR: $msg" -ForegroundColor Red
    exit 1
}

# --------------------------------------------------------------------------
# Step 0: pre-build gate
# --------------------------------------------------------------------------
if (-not $SkipValidate) {
    $validate = Join-Path $repoRoot 'validate.ps1'
    if (Test-Path $validate) {
        Write-Host "[build] running validate.ps1 gate..." -ForegroundColor Cyan
        & powershell -NoProfile -ExecutionPolicy Bypass -File $validate
        if ($LASTEXITCODE -ne 0) { Fail "validate.ps1 gate failed (exit $LASTEXITCODE); build aborted." }
        Write-Host "[build] gate passed." -ForegroundColor Green
    } else {
        Fail "validate.ps1 not found next to build.ps1."
    }
}

if (-not (Test-Path $archiveDir)) { Fail "archive directory not found: $archiveDir" }
if (-not (Test-Path $plgTmpl))    { Fail "plg template not found: $plgTmpl" }

# --------------------------------------------------------------------------
# Step 1: normalise line endings (CRLF -> LF) and strip UTF-8 BOM
# --------------------------------------------------------------------------
$textExt = '.php','.css','.js','.cfg','.page','.plg','.tmpl','.md','.txt','.sh','.ps1','.gitattributes','.plg.tmpl'

$textFiles = @(
    Get-ChildItem -Path $archiveDir -Recurse -File -ErrorAction SilentlyContinue |
        Where-Object { $textExt -contains $_.Extension.ToLower() }
)
foreach ($extra in @($plgTmpl, (Join-Path $repoRoot 'build.ps1'), (Join-Path $repoRoot 'validate.ps1'), (Join-Path $repoRoot '.gitattributes'))) {
    if (Test-Path $extra) { $textFiles += Get-Item $extra }
}

$utf8NoBom = New-Object System.Text.UTF8Encoding($false)
$normalized = 0
foreach ($file in $textFiles) {
    $bytes = [System.IO.File]::ReadAllBytes($file.FullName)
    $start = 0
    if ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) { $start = 3 }
    $text = [System.Text.Encoding]::UTF8.GetString($bytes, $start, $bytes.Length - $start)
    $text = $text.Replace("`r`n", "`n").Replace("`r", "`n")
    $newBytes = $utf8NoBom.GetBytes($text)
    $changed = ($newBytes.Length -ne $bytes.Length)
    if (-not $changed) {
        for ($i = 0; $i -lt $newBytes.Length; $i++) { if ($newBytes[$i] -ne $bytes[$i]) { $changed = $true; break } }
    }
    if ($changed) {
        [System.IO.File]::WriteAllBytes($file.FullName, $newBytes)
        $normalized++
        Write-Host "[build] normalised: $($file.FullName)"
    }
}
Write-Host "[build] line-ending normalisation done ($normalized file(s) changed)."

# --------------------------------------------------------------------------
# Step 2: build the txz package with bsdtar
# --------------------------------------------------------------------------
New-Item -ItemType Directory -Path $distDir -Force | Out-Null

$tarCmd = Get-Command tar -ErrorAction SilentlyContinue
if (-not $tarCmd) {
    Fail "tar not found. On Windows 10/11 it is bundled as bsdtar; otherwise build inside WSL: cd <repo> && tar -cJf dist/$pkgName -C archive usr"
}
if (Test-Path $txzPath) { Remove-Item $txzPath -Force }

# Verify xz support before creating an empty/broken artifact.
& tar --version | Out-Null
Push-Location $repoRoot
try {
    & tar -cJf $txzPath -C archive usr
    if ($LASTEXITCODE -ne 0) {
        Fail "tar failed (exit $LASTEXITCODE). If bsdtar lacks xz support, build via WSL: cd <repo> && tar -cJf dist/$pkgName -C archive usr"
    }
} finally {
    Pop-Location
}
if (-not (Test-Path $txzPath)) { Fail "package was not created: $txzPath" }
Write-Host "[build] package created: $txzPath"

# --------------------------------------------------------------------------
# Step 3: SHA256 backfill -> dist/gputemp.plg
# --------------------------------------------------------------------------
$hash = (Get-FileHash -Algorithm SHA256 -Path $txzPath).Hash.ToLower()
$tmplText = [System.IO.File]::ReadAllText($plgTmpl, [System.Text.Encoding]::UTF8)
$placeholder = '<!ENTITY sha256    "0000000000000000000000000000000000000000000000000000000000000000">'
if (-not $tmplText.Contains($placeholder)) { Fail "sha256 placeholder entity not found in gputemp.plg.tmpl" }
$plgText = $tmplText.Replace($placeholder, "<!ENTITY sha256    `"$hash`">")
$plgText = $plgText.Replace("`r`n", "`n").Replace("`r", "`n")
[System.IO.File]::WriteAllText($plgOut, $plgText, $utf8NoBom)

Write-Host ""
Write-Host "[build] ===== build summary =====" -ForegroundColor Green
Write-Host "[build] package : $txzPath"
Write-Host "[build] sha256  : $hash"
Write-Host "[build] plg     : $plgOut"
Write-Host "[build] offline install: copy BOTH artifacts from dist/ to the flash drive:"
Write-Host "[build]   gputemp.plg              -> /boot/config/plugins/gputemp.plg"
Write-Host "[build]   $pkgName -> /boot/config/plugins/gputemp/$pkgName"
Write-Host "[build] (the plugin manager skips the fetch when the pre-staged txz SHA256"
Write-Host "[build]  matches; see README section 2 for the full offline procedure)"
Write-Host "[build] done." -ForegroundColor Green
