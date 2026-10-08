# Compila, firma y publica una versión de la consola en el servidor.
# Uso, en apps/desktop y con el certificado disponible en este equipo:
#   .\scripts\publicar-consola.ps1 -Version 1.0.1 -Servidor tu-usuario@clt4bp.midominio.mx
param(
    [Parameter(Mandatory)] [string] $Version,
    [Parameter(Mandatory)] [string] $Servidor
)
$ErrorActionPreference = 'Stop'

function Paso([scriptblock] $comando, [string] $nombre) {
    & $comando
    if ($LASTEXITCODE -ne 0) { throw "Falló: $nombre" }
}

Paso { npm version $Version --no-git-tag-version } 'npm version'
Paso { npm run build } 'compilación'
Paso { npx electron-builder --win --publish never } 'electron-builder'

$exe = "dist/clt4bp-consola-$Version-setup.exe"
$firma = Get-AuthenticodeSignature $exe
if ($firma.Status -ne 'Valid') { throw "La firma de $exe no es válida: $($firma.StatusMessage)" }

# Primero el instalador y su blockmap; latest.yml al final, para que nadie vea una versión a medio subir
Paso { ssh $Servidor 'rm -rf /tmp/consola && mkdir /tmp/consola' } 'carpeta temporal'
Paso { scp $exe "$exe.blockmap" dist/latest.yml "${Servidor}:/tmp/consola/" } 'subida'
$destino = '/srv/clt4bp/shared/descargas/consola/'
Paso { ssh -t $Servidor "sudo install -o deploy -g deploy -m 644 /tmp/consola/*.exe /tmp/consola/*.blockmap $destino && sudo install -o deploy -g deploy -m 644 /tmp/consola/latest.yml $destino && rm -rf /tmp/consola" } 'publicación'

Write-Host "✔ Consola $Version publicada. Haz commit de package.json y package-lock.json."
