<?php

namespace App\Console\Commands;

use App\Domain\Evaluacion\DiccionarioDatos;
use Illuminate\Console\Command;

/** Regenera docs/diccionario-datos.md desde DiccionarioDatos: el documento y la exportación nunca se contradicen. */
class EscribirDiccionario extends Command
{
    protected $signature = 'datos:diccionario {--salida= : ruta del archivo (por defecto, docs/ del repositorio)}';

    protected $description = 'Escribe el diccionario de datos de la exportación en Markdown';

    public function handle(): int
    {
        $md = "# Diccionario de datos de la exportación\n\n"
            ."Generado con `php artisan datos:diccionario`; no lo edites a mano (edita `app/Domain/Evaluacion/DiccionarioDatos.php`).\n\n"
            ."Solo se exportan estudiantes con el consentimiento de investigación vigente, identificados por seudónimo. "
            ."Los CSV van en UTF-8 con BOM, separados por comas; las fechas, en ISO 8601.\n";
        foreach (DiccionarioDatos::TABLAS as $tabla => $t) {
            $md .= "\n## {$tabla}.csv\n\n{$t['descripcion']}\n\n| Columna | Descripción |\n|---|---|\n";
            foreach ($t['columnas'] as $columna => $descripcion) {
                $md .= "| `{$columna}` | ".str_replace('|', '\|', $descripcion)." |\n";
            }
        }
        $ruta = $this->option('salida') ?: base_path('../../docs/diccionario-datos.md');
        file_put_contents($ruta, $md);
        $this->info("✔ {$ruta}");

        return self::SUCCESS;
    }
}
