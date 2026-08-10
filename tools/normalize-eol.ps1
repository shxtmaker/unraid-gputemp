#Requires -Version 5.1
<#
.SYNOPSIS
  One-shot repository hygiene pass: force every text source file to LF line
  endings and UTF-8 without BOM (UR-4.8 / AC-COMP-03 triple defence layer 3).
#>
$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
$textExt = '.php','.css','.js','.cfg','.page','.plg','.tmpl','.md','.txt','.sh','.ps1','.gitattributes'
$textNames = 'LICENSE','.gitignore'
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)

$files = New-Object System.Collections.Generic.List[System.IO.FileInfo]
Get-ChildItem -Path $root -Recurse -File |
    Where-Object { ($textExt -contains $_.Extension.ToLower()) -or ($textNames -contains $_.Name) } |
    Where-Object { $_.FullName -notmatch '\\dist\\' } |
    ForEach-Object { $files.Add($_) }

$changed = 0
foreach ($f in $files) {
    $bytes = [System.IO.File]::ReadAllBytes($f.FullName)
    $start = 0
    if ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) { $start = 3 }
    $text = [System.Text.Encoding]::UTF8.GetString($bytes, $start, $bytes.Length - $start)
    $text = $text.Replace("`r`n", "`n").Replace("`r", "`n")
    $newBytes = $utf8NoBom.GetBytes($text)
    $isSame = ($newBytes.Length -eq $bytes.Length)
    if ($isSame) {
        for ($i = 0; $i -lt $newBytes.Length; $i++) { if ($newBytes[$i] -ne $bytes[$i]) { $isSame = $false; break } }
    }
    if (-not $isSame) {
        [System.IO.File]::WriteAllBytes($f.FullName, $newBytes)
        Write-Host ("normalized: " + $f.FullName)
        $changed++
    }
}
Write-Host ("done; $changed file(s) normalized out of $($files.Count)")
