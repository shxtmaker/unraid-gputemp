#Requires -Version 5.1
# One-shot machine verification of dist/gputemp.plg (task #9).
$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot
$plg  = Join-Path $root 'dist\gputemp.plg'
$txz  = Join-Path $root 'dist\gputemp-2026.08.10-x86_64-1.txz'

# --- byte-level: no BOM, no CR -------------------------------------------
$bytes = [System.IO.File]::ReadAllBytes($plg)
$bom = ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF)
$cr  = 0; foreach ($b in $bytes) { if ($b -eq 0x0D) { $cr++ } }
Write-Host ("[verify] BOM present: {0} | CR bytes: {1}" -f $bom, $cr)

$raw = [System.IO.File]::ReadAllText($plg, [System.Text.Encoding]::UTF8)

# --- 1. strict .NET XML parse (DTD internal entities expanded) ----------
$xml = New-Object System.Xml.XmlDocument
$xml.LoadXml($raw)
Write-Host "[verify] XmlDocument.LoadXml: OK (root = $($xml.DocumentElement.LocalName))"
Write-Host ("[verify] expanded PLUGIN attrs: name={0} version={1}" -f $xml.DocumentElement.GetAttribute('name'), $xml.DocumentElement.GetAttribute('version'))

# --- 2. auxiliary: regex entity substitution then re-parse ---------------
$ent = @{}
foreach ($m in [regex]::Matches($raw, '<!ENTITY\s+(\w+)\s+"([^"]*)">')) { $ent[$m.Groups[1].Value] = $m.Groups[2].Value }
# expand entity refs inside entity values to a fixpoint: entity values are
# mutually nested (pkgURL -> plgPATH/plgNAME -> name/version), so a single
# pass is order-dependent and may leave unresolved refs behind.
do {
    $expanded = $false
    foreach ($k in @($ent.Keys)) {
        foreach ($k2 in @($ent.Keys)) {
            if ($ent[$k].Contains("&$k2;")) { $ent[$k] = $ent[$k].Replace("&$k2;", $ent[$k2]); $expanded = $true }
        }
    }
} while ($expanded)
$body = [regex]::Replace($raw, '<!DOCTYPE[\s\S]*?]\s*>', '')
$body = [regex]::Replace($body, '&(\w+);', { param($mm) $ent[$mm.Groups[1].Value] })
$xml2 = New-Object System.Xml.XmlDocument
$xml2.LoadXml($body)
Write-Host "[verify] entity-substituted re-parse: OK"
Write-Host ("[verify] sha256 entity value : {0}" -f $ent['sha256'])
Write-Host ("[verify] pkgURL expanded     : {0}" -f $ent['pkgURL'])

# --- 3. independent SHA256 recompute & compare ---------------------------
$sha = (Get-FileHash -Algorithm SHA256 -Path $txz).Hash.ToLower()
Write-Host ("[verify] recomputed txz sha  : {0}" -f $sha)
if ($sha -ceq $ent['sha256']) { Write-Host '[verify] SHA256 backfill MATCH' } else { Write-Host '[verify] SHA256 MISMATCH'; exit 1 }

# --- 4. INLINE bash must contain literal 2>&1 after entity decode --------
$inline = $xml.DocumentElement.SelectNodes('//INLINE') | ForEach-Object { $_.InnerText }
$inlineAll = $inline -join "`n"
if ($inlineAll.Contains('2>&1')) { Write-Host '[verify] decoded INLINE contains literal 2>&1 (bash-correct)' } else { Write-Host '[verify] WARNING: 2>&1 not found in decoded INLINE'; exit 1 }
if ($raw.Contains('2>&1')) { Write-Host '[verify] raw plg still contains literal 2>&1 - BAD'; exit 1 } else { Write-Host '[verify] raw plg has no bare 2>&1 (only escaped form)' }

Write-Host '[verify] ALL CHECKS PASSED'
