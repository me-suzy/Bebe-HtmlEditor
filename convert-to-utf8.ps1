# ============================================================
#  Converteste fisiere HTML din orice encoding cu BOM -> UTF-8
#  Ruleaza din PowerShell:
#    .\convert-to-utf8.ps1
#  sau cu alt folder:
#    .\convert-to-utf8.ps1 -Folder "e:\Carte\BB\17 - Site Leadership\Principal\ro"
#  sau recursiv (subfoldere):
#    .\convert-to-utf8.ps1 -Recurse
# ============================================================

param(
    [string]$Folder  = "d:\Teste cursor\docs",
    [string]$Filter  = "*.html",
    [switch]$Recurse,
    [switch]$DryRun   # Afiseaza ce ar face, fara sa modifice fisierele
)

$files = if ($Recurse) {
    Get-ChildItem $Folder -Filter $Filter -Recurse
} else {
    Get-ChildItem $Folder -Filter $Filter
}

$total     = $files.Count
$converted = 0
$skipped   = 0
$errors    = 0

Write-Host ""
Write-Host "Folder : $Folder"
Write-Host "Fisiere: $total"
if ($DryRun) { Write-Host "*** DRY RUN - nicio modificare ***" -ForegroundColor Yellow }
Write-Host ""

foreach ($file in $files) {
    try {
        $bytes = [System.IO.File]::ReadAllBytes($file.FullName)

        # Detecteaza BOM
        $encoding  = $null
        $skipBytes = 0
        $bomType   = ""

        if ($bytes.Length -ge 3 -and
            $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) {
            $encoding  = [System.Text.Encoding]::UTF8
            $skipBytes = 3
            $bomType   = "UTF-8 BOM"
        }
        elseif ($bytes.Length -ge 4 -and
            $bytes[0] -eq 0xFF -and $bytes[1] -eq 0xFE -and
            $bytes[2] -eq 0x00 -and $bytes[3] -eq 0x00) {
            $encoding  = [System.Text.Encoding]::UTF32
            $skipBytes = 4
            $bomType   = "UTF-32 LE BOM"
        }
        elseif ($bytes.Length -ge 4 -and
            $bytes[0] -eq 0x00 -and $bytes[1] -eq 0x00 -and
            $bytes[2] -eq 0xFE -and $bytes[3] -eq 0xFF) {
            $encoding  = [System.Text.Encoding]::GetEncoding("UTF-32BE")
            $skipBytes = 4
            $bomType   = "UTF-32 BE BOM"
        }
        elseif ($bytes.Length -ge 2 -and $bytes[0] -eq 0xFF -and $bytes[1] -eq 0xFE) {
            $encoding  = [System.Text.Encoding]::Unicode
            $skipBytes = 2
            $bomType   = "UTF-16 LE BOM"
        }
        elseif ($bytes.Length -ge 2 -and $bytes[0] -eq 0xFE -and $bytes[1] -eq 0xFF) {
            $encoding  = [System.Text.Encoding]::BigEndianUnicode
            $skipBytes = 2
            $bomType   = "UTF-16 BE BOM"
        }

        if ($null -eq $encoding) {
            $skipped++
            continue  # Fara BOM, lasa neatins
        }

        # Decodifica continutul (fara bytes de BOM)
        $contentBytes = $bytes[$skipBytes..($bytes.Length - 1)]
        $text = $encoding.GetString($contentBytes)

        if ($DryRun) {
            Write-Host "  [DRY] $bomType -> UTF-8 : $($file.Name)" -ForegroundColor Cyan
        } else {
            # Scrie inapoi ca UTF-8 fara BOM
            $utf8NoBom = New-Object System.Text.UTF8Encoding $false
            [System.IO.File]::WriteAllText($file.FullName, $text, $utf8NoBom)
            Write-Host "  [OK]  $bomType -> UTF-8 : $($file.Name)" -ForegroundColor Green
        }

        $converted++
    }
    catch {
        $errors++
        Write-Host "  [ERR] $($file.Name) : $_" -ForegroundColor Red
    }
}

Write-Host ""
Write-Host "================================================"
if ($DryRun) {
    Write-Host "De convertit : $converted  (DRY RUN, nicio modificare)" -ForegroundColor Yellow
} else {
    Write-Host "Convertite   : $converted" -ForegroundColor Green
}
Write-Host "Sarite       : $skipped  (deja UTF-8 fara BOM)"
Write-Host "Erori        : $errors"
Write-Host "================================================"
Write-Host ""
