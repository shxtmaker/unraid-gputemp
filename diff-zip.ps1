#Requires -Version 5.1
<#
.SYNOPSIS
  Release zip consistency check: compare every file of a distribution zip
  against the working tree of this repository.

.DESCRIPTION
  Extracts the given zip into a throwaway directory, hash-compares each
  entry with its counterpart next to this script, reports entries that are
  missing or differ, then removes the throwaway directory. Exit code 0
  only when the zip mirrors the repository exactly.

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File .\diff-zip.ps1 -ZipPath .\gputemp-github-2026.08.10.zip
#>
param(
    [string]$ZipPath = (Join-Path $PSScriptRoot 'gputemp-github-2026.08.10.zip')
)

$ErrorActionPreference = 'Stop'
$root = $PSScriptRoot
$ZipPath = Resolve-Path $ZipPath
$tmp = Join-Path ([System.IO.Path]::GetTempPath()) ('gputemp-zip-' + [guid]::NewGuid().ToString('N'))

Expand-Archive -LiteralPath $ZipPath -DestinationPath $tmp -Force
$diffs = 0
Get-ChildItem $tmp -Recurse -File | ForEach-Object {
    $rel = $_.FullName.Substring($tmp.Length)
    $counterpart = Join-Path $root $rel
    if (-not (Test-Path $counterpart)) {
        Write-Host ('ONLY-IN-ZIP: ' + $rel)
        $script:diffs++
    } else {
        $h1 = (Get-FileHash $_.FullName).Hash
        $h2 = (Get-FileHash $counterpart).Hash
        if ($h1 -ne $h2) {
            Write-Host ('DIFFERS: ' + $rel)
            $script:diffs++
        }
    }
}
Remove-Item $tmp -Recurse -Force -ErrorAction SilentlyContinue
Write-Host ('total diffs: ' + $diffs)
if ($diffs -gt 0) { exit 1 }
exit 0
