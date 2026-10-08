# Arranca el agente local de CLT4BP y su conector con la plataforma en la nube (ADR 0008). Windows (PowerShell 7).
# Requisitos: Docker Desktop abierto y Ollama instalado (https://ollama.com/download).
$ErrorActionPreference = 'Stop'
Set-Location $PSScriptRoot

if (-not (Test-Path .env)) {
    Copy-Item ejemplo.env .env
    Write-Host 'Creé .env a partir de ejemplo.env: llena RELEVO_URL y AGENTE_TOKEN y vuelve a ejecutar este script.'
    exit 1
}
$config = @{}
Get-Content .env | Where-Object { $_ -match '^\s*([A-Z_]+)\s*=\s*(.*)$' } | ForEach-Object { $config[$Matches[1]] = $Matches[2].Trim() }
if (-not $config.AGENTE_TOKEN -or -not $config.RELEVO_URL -or $config.RELEVO_URL -match 'tu-app') {
    Write-Host 'Faltan RELEVO_URL o AGENTE_TOKEN en .env.'
    exit 1
}

docker info *> $null
if ($LASTEXITCODE -ne 0) { Write-Host 'Docker no está corriendo: abre Docker Desktop y espera a que diga «Running».'; exit 1 }

if (($config.PROVEEDOR ?? 'local') -eq 'local') {
    try { $modelos = (Invoke-RestMethod http://127.0.0.1:11434/api/tags -TimeoutSec 5).models.name }
    catch { Write-Host 'Ollama no responde en el puerto 11434: instálalo (https://ollama.com/download) y ábrelo.'; exit 1 }
    $normal = $config.MODELO_LOCAL_NORMAL ?? 'qwen3:4b'
    foreach ($modelo in @($normal, 'bge-m3')) {
        if (-not ($modelos | Where-Object { $_ -eq $modelo -or $_ -eq "${modelo}:latest" })) {
            Write-Host "Descargando el modelo $modelo (una sola vez)…"
            ollama pull $modelo
        }
    }
}

docker compose up -d --build
Write-Host 'Esperando al agente…'
foreach ($i in 1..30) {
    try { $salud = Invoke-RestMethod http://127.0.0.1:8100/salud -TimeoutSec 2; break } catch { Start-Sleep 2 }
}
if (-not $salud) { Write-Host 'El agente no arrancó: revisa «docker compose logs agente».'; exit 1 }
Write-Host "Agente listo: proveedor $($salud.proveedor), prompt $($salud.prompt)."
docker compose logs --tail 5 conector
Write-Host 'Listo. Para ver la actividad: docker compose logs -f conector · Para detenerlo: docker compose down'
