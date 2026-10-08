<?php

namespace App\Services\Codigo;

interface EjecutorCodigo
{
    /** @param string $lenguaje c | cpp | python */
    public function ejecutar(string $lenguaje, string $codigo, string $entrada = '', int $limiteMs = 3000): ResultadoEjecucion;
}
