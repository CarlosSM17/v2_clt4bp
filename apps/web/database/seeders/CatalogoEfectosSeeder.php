<?php

namespace Database\Seeders;

use App\Models\CleEffect;
use App\Models\EffectRule;
use Illuminate\Database\Seeder;

/** Carga el catálogo de los 17 efectos y las reglas de preselección desde packages/contracts/catalogo. */
class CatalogoEfectosSeeder extends Seeder
{
    public function run(): void
    {
        $leer = fn (string $archivo) => json_decode(file_get_contents(resource_path("contracts/catalogo/{$archivo}")), true, flags: JSON_THROW_ON_ERROR);

        foreach ($leer('efectos.json') as $efecto) {
            CleEffect::updateOrCreate(['id' => $efecto['id']], $efecto);
        }

        // Las reglas solo se crean: si el administrador ya las ajustó, no se pisan sus cambios
        foreach ($leer('reglas-preseleccion.json') as $i => $regla) {
            EffectRule::firstOrCreate(['id' => $regla['id']], [...$regla, 'orden' => $i + 1]);
        }
    }
}
