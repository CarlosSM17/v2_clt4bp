$piston = 'http://127.0.0.1:2000/api/v2'

function Ejecutar($lenguaje, $archivo, $codigo, $entrada) {
  $body = @{
    language = $lenguaje; version = '*'
    files = @(@{ name = $archivo; content = $codigo })
    stdin = $entrada
    run_timeout = 3000
    run_memory_limit = 134217728   # 128 MB
  } | ConvertTo-Json -Depth 5
  Invoke-RestMethod -Method Post -Uri "$piston/execute" -ContentType 'application/json' -Body $body
}

$fuenteC = [string](Get-Content "$PSScriptRoot/promedio.c" -Raw)   # el cast evita que ConvertTo-Json serialice propiedades extra
$c = Ejecutar 'c' 'main.c' $fuenteC "3`n8 9 10`n"
"C      -> salida: $($c.run.stdout.Trim())  código: $($c.run.code)"

$py = Ejecutar 'python' 'main.py' 'print(sum(map(int, input().split())))' "2 3`n"
"Python -> salida: $($py.run.stdout.Trim())  código: $($py.run.code)"
