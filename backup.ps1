$date = Get-Date -Format "yyyy-MM-dd_HH-mm"
$backupDir = "C:\Users\AYA\backups_extraction"

if (!(Test-Path $backupDir)) {
    New-Item -ItemType Directory -Force -Path $backupDir | Out-Null
}

docker compose exec -T pgsql pg_dump -U sail laravel > "$backupDir\db_$date.sql"

if ($LASTEXITCODE -eq 0) {
    Write-Host "Backup OK : $backupDir\db_$date.sql" -ForegroundColor Green
} else {
    Write-Host "Echec du backup" -ForegroundColor Red
}
