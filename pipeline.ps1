# ============================================================
# PIPELINE COMPLET - Reconstruction de la base
# ============================================================
# Usage : .\pipeline.ps1
# Reprise : .\pipeline.ps1 -Etape 3
# ============================================================

param(
    [int]$Etape = 1
)

$ErrorActionPreference = "Continue"
$startTime = Get-Date
$logFile = "C:\Users\AYA\extraction_backend\pipeline.log"

# ============================================================
# FONCTIONS
# ============================================================

function Write-Log($message, $color = "White") {
    $timestamp = (Get-Date).ToString("yyyy-MM-dd HH:mm:ss")
    $line = "[$timestamp] $message"
    Write-Host $line -ForegroundColor $color
    Add-Content -Path $logFile -Value $line
}

function Log-Step($numero, $titre) {
    $elapsed = (Get-Date) - $startTime
    Write-Host ""
    Write-Host "==================================================" -ForegroundColor Cyan
    Write-Host " ÉTAPE $numero - $titre" -ForegroundColor Cyan
    Write-Host " Temps écoulé : $($elapsed.ToString('hh\:mm\:ss'))" -ForegroundColor Cyan
    Write-Host "==================================================" -ForegroundColor Cyan
    Write-Log "ÉTAPE $numero - $titre" "Cyan"
}

function Run-Backup($label) {
    Write-Log "  → Backup : $label" "Yellow"
    & "$PSScriptRoot\backup.ps1"
    if ($LASTEXITCODE -ne 0) {
        Write-Log "  ⚠️  Backup échoué" "Red"
    }
}

function Run-Artisan($command, $label) {
    Write-Log "  → $label" "White"
    docker compose exec -T laravel.test php artisan $command.Split(' ')
    if ($LASTEXITCODE -ne 0) {
        Write-Log "  ⚠️  Échec sur : $command" "Red"
        return $false
    }
    Write-Log "  ✅ Terminé : $label" "Green"
    return $true
}

function Test-Containers {
    Write-Log "Vérification des conteneurs..." "White"
    $running = docker compose ps --services --filter "status=running"
    if ($running -notmatch "laravel.test") {
        Write-Log "❌ laravel.test n'est pas démarré. Lancez : docker compose up -d" "Red"
        exit 1
    }
    Write-Log "✅ Conteneurs OK" "Green"
}

# ============================================================
# DÉMARRAGE
# ============================================================

Write-Log "===============================================" "Cyan"
Write-Log " PIPELINE BELGIUM BUSINESS EXTRACTOR" "Cyan"
Write-Log " Démarrage : $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')" "Cyan"
Write-Log " Étape de départ : $Etape" "Cyan"
Write-Log "===============================================" "Cyan"

Test-Containers

# ============================================================
# ÉTAPE 1 - INDEX ELASTICSEARCH
# ============================================================

if ($Etape -le 1) {
    Log-Step 1 "Création index Elasticsearch"
    Run-Artisan "elasticsearch:create-index" "Création index ES"
}

# ============================================================
# ÉTAPE 2 - EXTRACTION OVERPASS (2-4h)
# ============================================================

if ($Etape -le 2) {
    Log-Step 2 "Extraction Overpass (2-4h)"
    Run-Artisan "extraction:test --sans-nominatim" "Extraction des entreprises"
    Run-Backup "après extraction"
}

# ============================================================
# ÉTAPE 3 - FILL-CITIES via PHOTON (~1h)
# ============================================================

if ($Etape -le 3) {
    Log-Step 3 "Remplissage villes/provinces/CP (Photon)"
    Run-Artisan "extraction:fill-cities --limit=100000" "Remplissage villes"
    Run-Backup "après fill-cities"
}

# ============================================================
# ÉTAPE 4 - FILL-CONTACTS (1-2h)
# ============================================================

if ($Etape -le 4) {
    Log-Step 4 "Remplissage contacts (Overpass)"
    Run-Artisan "extraction:fill-contacts" "Remplissage contacts"
    Run-Backup "après fill-contacts"
}

# ============================================================
# ÉTAPE 5 - FILL-PHONE (30 min) ← AJOUTÉ
# ============================================================

if ($Etape -le 5) {
    Log-Step 5 "Remplissage téléphones (Overpass)"
    Run-Artisan "extraction:fill-phone" "Remplissage téléphones"
    Run-Backup "après fill-phone"
}

# ============================================================
# ÉTAPE 6 - CLEAN-FOREIGN
# ============================================================

if ($Etape -le 6) {
    Log-Step 6 "Nettoyage hors Belgique"
    Run-Artisan "extraction:clean-foreign --dry-run" "Vérification"
    Run-Artisan "extraction:clean-foreign" "Suppression"
    Run-Backup "après clean-foreign"
}

# ============================================================
# ÉTAPE 7 - FIX-REGIONS
# ============================================================

if ($Etape -le 7) {
    Log-Step 7 "Correction des régions (local)"
    Run-Artisan "extraction:fix-regions" "Correction régions"
}

# ============================================================
# ÉTAPE 8 - REFRESH-OPEN-STATUS
# ============================================================

if ($Etape -le 8) {
    Log-Step 8 "Calcul statut ouvert (local)"
    Run-Artisan "companies:refresh-open-status" "Statut ouvert"
}

# ============================================================
# ÉTAPE 9 - INDEXATION ELASTICSEARCH
# ============================================================

if ($Etape -le 9) {
    Log-Step 9 "Indexation Elasticsearch"
    Run-Artisan "elasticsearch:index-all" "Indexation ES"
    Run-Backup "final"
}

# ============================================================
# FIN
# ============================================================

$total = (Get-Date) - $startTime
Write-Host ""
Write-Log "===============================================" "Green"
Write-Log " PIPELINE TERMINÉ en $($total.ToString('hh\:mm\:ss'))" "Green"
Write-Log " Log complet : $logFile" "Green"
Write-Log "===============================================" "Green"