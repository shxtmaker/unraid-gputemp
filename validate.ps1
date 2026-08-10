#Requires -Version 5.1
<#
.SYNOPSIS
  gputemp static gate (pre-build / pre-verification checks).

.DESCRIPTION
  Checks performed (all must pass, otherwise exit code 1):
    1. PHP syntax (php -l) for every .php and .page file, when PHP is
       available locally (skipped with a notice otherwise).
    2. Encoding hygiene: no CRLF (zero CR bytes) and no UTF-8 BOM in any
       text source file.
    3. Blacklist grep — every pattern must yield zero hits:
         - tile-header                      (hand-written tile headers)
         - $_REQUEST                        (only $_POST/$_GET allowed)
         - hwmon<digit>                     (hardcoded hwmon indices)
         - display_errors = 1/On            (errors must never leak to UI)
         - chmod ... /boot                  (no chmod on the flash drive)
         - file_put_contents(...gputemp.cfg (direct cfg overwrite)
         - parse_ini_file(...gputemp.cfg    (self-parsing the cfg)
    4. default.cfg completeness: all six configuration keys present.
    5. plg integrity: zero <MD5> elements; zero /boot deletion statements
       inside Method="remove" sections; zero bare '&' (every ampersand must
       start a valid entity or character reference); full machine XML parse
       of the manifest with internal DTD entity expansion.

.EXAMPLE
  powershell -NoProfile -ExecutionPolicy Bypass -File .\validate.ps1
#>

$ErrorActionPreference = 'Stop'
$root        = (Get-Item $PSCommandPath).Directory.FullName
$archiveDir  = Join-Path $root 'archive'
$failures    = New-Object System.Collections.Generic.List[string]
$blacklistHit = $false

function Section([string]$title) { Write-Host ""; Write-Host "=== $title ===" -ForegroundColor Cyan }
function Pass([string]$msg)      { Write-Host ("  [PASS] " + $msg) -ForegroundColor Green }
function Warn([string]$msg)      { Write-Host ("  [WARN] " + $msg) -ForegroundColor Yellow }
function Fail([string]$msg)      { Write-Host ("  [FAIL] " + $msg) -ForegroundColor Red; $script:failures.Add($msg) }

# --------------------------------------------------------------------------
# Collect text source files (dist/ is build output and never gated here)
# --------------------------------------------------------------------------
$textExt = '.php','.css','.js','.cfg','.page','.plg','.tmpl','.md','.txt','.sh','.gitattributes'

$files = New-Object System.Collections.Generic.List[System.IO.FileInfo]
if (Test-Path $archiveDir) {
    Get-ChildItem -Path $archiveDir -Recurse -File |
        Where-Object { $textExt -contains $_.Extension.ToLower() } |
        ForEach-Object { $files.Add($_) }
}
foreach ($extra in @((Join-Path $root 'gputemp.plg.tmpl'), (Join-Path $root 'build.ps1'), (Join-Path $root 'validate.ps1'), (Join-Path $root '.gitattributes'))) {
    if (Test-Path $extra) { $files.Add((Get-Item $extra)) }
}
if ($files.Count -eq 0) { Fail "no source files found under $archiveDir" }
Write-Host "[validate] scanning $($files.Count) text file(s)"

# --------------------------------------------------------------------------
# 1. PHP syntax check (php -l) — .php files and .page files (PHP body)
# --------------------------------------------------------------------------
Section "PHP syntax (php -l)"
$phpCmd = Get-Command php -ErrorAction SilentlyContinue
$phpFiles = @($files | Where-Object { $_.Extension -in @('.php', '.page') })
if (-not $phpCmd) {
    Warn "php not found in PATH; skipping php -l (install PHP locally to enable this check)"
} elseif ($phpFiles.Count -eq 0) {
    Warn "no .php/.page files found to lint"
} else {
    $tmpDir = Join-Path ([System.IO.Path]::GetTempPath()) ("gputemp-lint-" + [guid]::NewGuid().ToString('N'))
    New-Item -ItemType Directory -Path $tmpDir | Out-Null
    try {
        foreach ($f in $phpFiles) {
            $tmpFile = Join-Path $tmpDir ($f.Name + '.php')
            Copy-Item -LiteralPath $f.FullName -Destination $tmpFile -Force
            $output = & php -l $tmpFile 2>&1
            if ($LASTEXITCODE -ne 0) {
                Fail ("php -l failed: " + $f.FullName + " -> " + (($output | Out-String).Trim()))
            } else {
                Pass ("php -l ok: " + $f.FullName)
            }
        }
    } finally {
        Remove-Item -Path $tmpDir -Recurse -Force -ErrorAction SilentlyContinue
    }
}

# --------------------------------------------------------------------------
# 2. Encoding hygiene: no CR bytes, no UTF-8 BOM
# --------------------------------------------------------------------------
Section "Line endings / encoding (LF only, no BOM)"
$crFiles  = New-Object System.Collections.Generic.List[string]
$bomFiles = New-Object System.Collections.Generic.List[string]
foreach ($f in $files) {
    $bytes = [System.IO.File]::ReadAllBytes($f.FullName)
    for ($i = 0; $i -lt $bytes.Length; $i++) {
        if ($bytes[$i] -eq 0x0D) { $crFiles.Add($f.FullName); break }
    }
    if ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) {
        $bomFiles.Add($f.FullName)
    }
}
if ($crFiles.Count -eq 0) { Pass "no CRLF line endings (CR count = 0)" } else { foreach ($p in $crFiles) { Fail "CRLF found: $p" } }
if ($bomFiles.Count -eq 0) { Pass "no UTF-8 BOM" } else { foreach ($p in $bomFiles) { Fail "UTF-8 BOM found: $p" } }

# --------------------------------------------------------------------------
# 3. Blacklist grep — every pattern must have zero hits
# --------------------------------------------------------------------------
Section "Blacklist patterns (each must yield 0 hits)"
$patterns = @(
    @{ Name = "hand-written tile header";              Regex = 'tile-header' },
    @{ Name = "superglobal `$_REQUEST";                Regex = '\$_REQUEST' },
    @{ Name = "hardcoded hwmon index";                 Regex = 'hwmon[0-9]' },
    @{ Name = "display_errors enabled";                Regex = 'display_errors\s*=\s*(1|On|on)' },
    @{ Name = "chmod on /boot";                        Regex = 'chmod[^\n]*\/boot' },
    @{ Name = "direct file_put_contents on target cfg"; Regex = 'file_put_contents\s*\([^)]*gputemp\.cfg' },
    @{ Name = "self-parsing cfg via parse_ini_file";   Regex = 'parse_ini_file\s*\([^\n]*gputemp\.cfg' }
)
$selfPath = (Get-Item $PSCommandPath).FullName
foreach ($p in $patterns) {
    $hits = 0
    foreach ($f in $files) {
        # The gate script itself literally contains every blacklist pattern;
        # scanning it would always self-trip, so it is excluded here.
        if ($f.FullName -eq $selfPath) { continue }
        $m = Select-String -LiteralPath $f.FullName -Pattern $p.Regex -AllMatches
        foreach ($line in $m) {
            $hits += $line.Matches.Count
            Write-Host ("    hit: " + $f.FullName + ":" + $line.LineNumber + " -> " + $line.Line.Trim()) -ForegroundColor Red
        }
    }
    if ($hits -eq 0) { Pass ("'$($p.Regex)' ($($p.Name)): 0 hits") } else { Fail ("'$($p.Regex)' ($($p.Name)): $hits hit(s)") ; $blacklistHit = $true }
}

# --------------------------------------------------------------------------
# 4. default.cfg completeness — all six configuration keys
# --------------------------------------------------------------------------
Section "default.cfg key completeness"
$requiredKeys = @('REFRESH_INTERVAL', 'TEMP_WARN', 'TEMP_CRIT', 'ENABLED_GPUS', 'COLLECT_TIMEOUT', 'FAIL_THRESHOLD')
$defaultCfg = Join-Path $archiveDir (Join-Path 'usr' (Join-Path 'local' (Join-Path 'emhttp' (Join-Path 'plugins' (Join-Path 'gputemp' 'default.cfg')))))
if (-not (Test-Path $defaultCfg)) {
    Fail "default.cfg not found at $defaultCfg"
} else {
    # PS 5.1 Get-Content decodes BOM-less UTF-8 as ANSI and garbles CJK
    # comments, so read explicitly as UTF-8.
    $cfgLines = @([System.IO.File]::ReadAllLines($defaultCfg, [System.Text.Encoding]::UTF8))
    $missing = @()
    foreach ($key in $requiredKeys) {
        $found = $false
        foreach ($line in $cfgLines) {
            if ($line -match "^\s*$key\s*=") { $found = $true; break }
        }
        if (-not $found) { $missing += $key }
    }
    if ($missing.Count -eq 0) { Pass "all 6 required keys present (missing = 0)" } else { Fail ("default.cfg missing keys: " + ($missing -join ', ')) }
}

# --------------------------------------------------------------------------
# 5. plg integrity — zero MD5, zero /boot deletion in remove sections
# --------------------------------------------------------------------------
Section "PLG manifest integrity"
$plgFiles = @($files | Where-Object { $_.Name -like '*.plg' -or $_.Name -like '*.plg.tmpl' })
if ($plgFiles.Count -eq 0) {
    Fail "no .plg / .plg.tmpl file found to validate"
}
foreach ($plg in $plgFiles) {
    $text = [System.IO.File]::ReadAllText($plg.FullName, [System.Text.Encoding]::UTF8)

    # 5a. MD5 must not appear at all (SHA256-only policy).
    $md5Count = ([regex]::Matches($text, '<MD5>', 'IgnoreCase')).Count
    if ($md5Count -eq 0) { Pass ("$($plg.Name): <MD5> count = 0") } else { Fail ("$($plg.Name): <MD5> count = $md5Count") }

    # 5b. Inside Method="remove" sections: /boot deletion statements must be 0.
    $removeBlocks = [regex]::Matches($text, '<FILE[^>]*Method="remove"[^>]*>[\s\S]*?</FILE>', 'IgnoreCase')
    if ($removeBlocks.Count -eq 0) {
        Warn ("$($plg.Name): no Method=`"remove`" section found")
    }
    foreach ($block in $removeBlocks) {
        $body = $block.Value
        $body = [regex]::Replace($body, '<!--[\s\S]*?-->', '')
        $bootDeletes = 0
        foreach ($line in ($body -split "`n")) {
            $t = $line.Trim()
            if ($t -eq '' -or $t.StartsWith('#')) { continue }
            if ($t -match 'rm[^\n]*?/boot') {
                $bootDeletes++
                Write-Host ("    hit: " + $plg.Name + " remove section -> " + $t) -ForegroundColor Red
            }
        }
        if ($bootDeletes -eq 0) { Pass ("$($plg.Name): remove section /boot deletion statements = 0") } else { Fail ("$($plg.Name): remove section /boot deletion statements = $bootDeletes") }
    }

    # 5c. Bare '&' gate: a bare ampersand (e.g. bash "2>&1") breaks the XML
    #     parse of the manifest on the Unraid side. Every '&' must start a
    #     valid entity reference (&name;) or character reference (&#38;).
    $bareAmp = [regex]::Matches($text, '&(?!(?:#[0-9]+|#x[0-9a-fA-F]+|[A-Za-z_][A-Za-z0-9._-]*);)')
    if ($bareAmp.Count -eq 0) {
        Pass ("$($plg.Name): bare '&' count = 0")
    } else {
        foreach ($m in $bareAmp) {
            $lineNo = ([regex]::Matches($text.Substring(0, $m.Index), "`n")).Count + 1
            Write-Host ("    hit: " + $plg.Name + ":" + $lineNo + " -> " + $text.Substring([Math]::Max(0, $m.Index - 20), [Math]::Min(40, $text.Length - [Math]::Max(0, $m.Index - 20))).Replace("`n", " ")) -ForegroundColor Red
        }
        Fail ("$($plg.Name): bare '&' count = $($bareAmp.Count) (escape as &amp;)")
    }

    # 5d. Machine XML parse: the manifest must be well-formed XML including
    #     its internal DTD entity declarations (expanded during parsing).
    try {
        $doc = New-Object System.Xml.XmlDocument
        $doc.LoadXml($text)
        Pass ("$($plg.Name): XML well-formed (machine parse, DTD entities expanded)")
    } catch {
        Fail ("$($plg.Name): XML parse error -> " + $_.Exception.Message)
    }
}

# --------------------------------------------------------------------------
# Summary
# --------------------------------------------------------------------------
Section "Summary"
if ($failures.Count -eq 0) {
    Write-Host "  all gate checks passed." -ForegroundColor Green
    exit 0
} else {
    Write-Host ("  $($failures.Count) check(s) failed:") -ForegroundColor Red
    foreach ($msg in $failures) { Write-Host ("   - " + $msg) -ForegroundColor Red }
    exit 1
}
