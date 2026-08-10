#Requires -Version 5.1
<#
.SYNOPSIS
  Deterministic pure-.NET generator for the gputemp tile icon (48x48 PNG).
  Draws a flat GPU card silhouette: board, golden edge-connector pins,
  cooling fan ring and hub. No external tools or libraries required.
#>

param(
    [string]$OutFile = (Join-Path $PSScriptRoot '..\archive\usr\local\emhttp\plugins\gputemp\images\gputemp.png')
)

$ErrorActionPreference = 'Stop'
$w = 48; $h = 48

# ---- CRC32 (needed for PNG chunk checksums) ------------------------------
$crcTable = New-Object 'uint32[]' 256
for ($n = 0; $n -lt 256; $n++) {
    $c = [uint32]$n
    for ($k = 0; $k -lt 8; $k++) {
        if ($c -band 1) { $c = 0xEDB88320 -bxor ($c -shr 1) } else { $c = $c -shr 1 }
    }
    $crcTable[$n] = $c
}
function Crc32([byte[]]$data) {
    $c = [uint32]::MaxValue
    foreach ($b in $data) { $c = $crcTable[($c -bxor $b) -band 0xFF] -bxor ($c -shr 8) }
    return $c -bxor 0xFFFFFFFF
}
function Adler32([byte[]]$data) {
    $a = [uint32]1; $b = [uint32]0
    foreach ($x in $data) { $a = ($a + $x) % 65521; $b = ($b + $a) % 65521 }
    return ($b -shl 16) -bor $a
}
function Be32([byte[]]$buf, [int]$off, [uint32]$v) {
    $buf[$off]   = [byte](($v -shr 24) -band 0xFF)
    $buf[$off+1] = [byte](($v -shr 16) -band 0xFF)
    $buf[$off+2] = [byte](($v -shr 8) -band 0xFF)
    $buf[$off+3] = [byte]($v -band 0xFF)
}
function MakeChunk([string]$type, [byte[]]$data) {
    $typeBytes = [System.Text.Encoding]::ASCII.GetBytes($type)
    $len = New-Object byte[] 4
    Be32 $len 0 ([uint32]$data.Length)
    $body = New-Object byte[] ($typeBytes.Length + $data.Length)
    [Array]::Copy($typeBytes, 0, $body, 0, $typeBytes.Length)
    [Array]::Copy($data, 0, $body, $typeBytes.Length, $data.Length)
    $crc = New-Object byte[] 4
    Be32 $crc 0 (Crc32 $body)
    $out = New-Object byte[] ($len.Length + $body.Length + $crc.Length)
    [Array]::Copy($len, 0, $out, 0, 4)
    [Array]::Copy($body, 0, $out, 4, $body.Length)
    [Array]::Copy($crc, 0, $out, 4 + $body.Length, 4)
    return $out
}

# ---- rasterise ------------------------------------------------------------
$rgba = New-Object byte[] ($w * $h * 4)   # flat RGBA buffer
function PutPx([int]$x, [int]$y, [byte]$r, [byte]$g, [byte]$b, [byte]$a) {
    $i = ($y * $w + $x) * 4
    $rgba[$i] = $r; $rgba[$i+1] = $g; $rgba[$i+2] = $b; $rgba[$i+3] = $a
}
function Dist([double]$x, [double]$y, [double]$cx, [double]$cy) {
    return [Math]::Sqrt(($x - $cx) * ($x - $cx) + ($y - $cy) * ($y - $cy))
}

$bg      = @(0, 0, 0, 0)          # transparent background
$board   = @(44, 51, 63, 255)     # dark slate card body
$accent  = @(118, 196, 77, 255)   # Unraid-style green accent
$pinGold = @(216, 173, 62, 255)   # edge connector pins
$fanDark = @(27, 32, 40, 255)     # fan recess
$hub     = @(150, 158, 168, 255)  # fan hub

for ($y = 0; $y -lt $h; $y++) {
    for ($x = 0; $x -lt $w; $x++) {
        $c = $bg
        # card body with rounded corners
        $inBoard = $false
        if ($x -ge 4 -and $x -le 43 -and $y -ge 9 -and $y -le 35) {
            $inBoard = $true
            # only pixels inside a corner zone get the radius test
            if     ($x -le 7  -and $y -le 12) { if ((Dist $x $y 7  12) -gt 3.2) { $inBoard = $false } }
            elseif ($x -ge 40 -and $y -le 12) { if ((Dist $x $y 40 12) -gt 3.2) { $inBoard = $false } }
            elseif ($x -le 7  -and $y -ge 32) { if ((Dist $x $y 7  32) -gt 3.2) { $inBoard = $false } }
            elseif ($x -ge 40 -and $y -ge 32) { if ((Dist $x $y 40 32) -gt 3.2) { $inBoard = $false } }
        }
        if ($inBoard) {
            $c = $board
            # golden edge-connector pins along the bottom edge
            if ($y -ge 33 -and $y -le 34 -and $x -ge 8 -and $x -le 39 -and (($x - 8) % 4) -lt 2) {
                $c = $pinGold
            }
            # fan recess / accent ring / hub
            $d = Dist $x $y 23.5 22
            if ($d -le 9.2) {
                $c = $fanDark
                if ($d -ge 7.0 -and $d -le 9.2) { $c = $accent }
                if ($d -le 2.6) { $c = $hub }
            }
        }
        PutPx $x $y ([byte]$c[0]) ([byte]$c[1]) ([byte]$c[2]) ([byte]$c[3])
    }
}

# ---- encode PNG -----------------------------------------------------------
$raw = New-Object byte[] ($h * (1 + $w * 4))
$p = 0
for ($y = 0; $y -lt $h; $y++) {
    $raw[$p] = 0; $p++   # filter type 0 (None) per scanline
    for ($x = 0; $x -lt $w; $x++) {
        $i = ($y * $w + $x) * 4
        $raw[$p] = $rgba[$i];   $p++
        $raw[$p] = $rgba[$i+1]; $p++
        $raw[$p] = $rgba[$i+2]; $p++
        $raw[$p] = $rgba[$i+3]; $p++
    }
}

$ms = New-Object System.IO.MemoryStream
$ms.WriteByte(0x78); $ms.WriteByte(0x01)                     # zlib header (CMF/FLG)

$deflate = New-Object System.IO.Compression.DeflateStream($ms, [System.IO.Compression.CompressionMode]::Compress, $true)
$deflate.Write($raw, 0, $raw.Length)
$deflate.Dispose()
$adler = New-Object byte[] 4
Be32 $adler 0 (Adler32 $raw)
$ms.Write($adler, 0, 4)
$zlibBytes = $ms.ToArray()
$ms.Dispose()

$ihdr = New-Object byte[] 13
Be32 $ihdr 0 ([uint32]$w)
Be32 $ihdr 4 ([uint32]$h)
$ihdr[8] = 8   # bit depth
$ihdr[9] = 6   # color type RGBA
$ihdr[10] = 0; $ihdr[11] = 0; $ihdr[12] = 0

$png = New-Object System.IO.MemoryStream
$png.Write([byte[]](0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A), 0, 8)
$i = MakeChunk 'IHDR' $ihdr; $png.Write($i, 0, $i.Length)
$d = MakeChunk 'IDAT' $zlibBytes; $png.Write($d, 0, $d.Length)
$e = MakeChunk 'IEND' ([byte[]]@()); $png.Write($e, 0, $e.Length)

$outDir = Split-Path -Parent $OutFile
if (-not (Test-Path $outDir)) { New-Item -ItemType Directory -Path $outDir -Force | Out-Null }
[System.IO.File]::WriteAllBytes($OutFile, $png.ToArray())
$png.Dispose()

Write-Host ("[icon] wrote " + (Resolve-Path $OutFile) + " (" + (Get-Item $OutFile).Length + " bytes)")
