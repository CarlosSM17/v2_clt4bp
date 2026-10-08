<?php

namespace App\Console\Commands;

use App\Models\Instrument;
use Illuminate\Console\Command;

class CargarInstrumento extends Command
{
    protected $signature = 'instrumentos:cargar {archivo : Ruta al JSON del instrumento}';

    protected $description = 'Carga o actualiza un instrumento Likert desde su archivo JSON';

    public function handle(): int
    {
        $ruta = $this->argument('archivo');
        $def = json_decode((string) @file_get_contents($ruta), true);
        if (! is_array($def) || ! isset($def['clave'], $def['version'], $def['items'], $def['subescalas'])) {
            $this->error("No es un instrumento válido: {$ruta}");

            return self::FAILURE;
        }

        $instrumento = Instrument::updateOrCreate(
            ['clave' => $def['clave'], 'version' => $def['version']],
            ['nombre' => $def['nombre'], 'definicion' => $def],
        );
        $this->info("✔ {$instrumento->nombre} ({$instrumento->clave} {$instrumento->version}): ".count($def['items']).' ítems');

        return self::SUCCESS;
    }
}
