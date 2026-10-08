<?php

namespace App\Services\Diseno;

use App\Services\Codigo\EvaluadorCasos;
use Illuminate\Support\Facades\Cache;

/**
 * Ejecuta en Piston las soluciones de referencia del diseño. El resultado se guarda en caché
 * por huella (lenguaje + código + casos): verificar dos veces lo mismo no vuelve a ejecutar nada.
 */
class VerificadorCodigo
{
    public function __construct(private readonly EvaluadorCasos $evaluador) {}

    /** @return array<string, array{aprobados: int, total: int, error_compilacion: ?string}> */
    public function resultados(array $diseno): array
    {
        $r = [];
        foreach ($diseno['tareas'] as $t) {
            $r[$t->uid] = $this->evaluar($t->lenguaje, $t->solucion, $t->casos_prueba);
        }
        foreach ($diseno['practica_parcial'] as $p) {
            foreach ($p->ejercicios as $i => $ejercicio) {
                $r["{$p->uid}#{$i}"] = $this->evaluar($p->lenguaje, $ejercicio->solucion, $ejercicio->casos_prueba);
            }
        }

        return $r;
    }

    private function evaluar(string $lenguaje, string $codigo, array $casos): array
    {
        $casos = json_decode(json_encode($casos), true);   // objetos → arreglos, como espera EvaluadorCasos
        $huella = 'verificacion:'.sha1($lenguaje."\0".$codigo."\0".json_encode($casos));

        return Cache::remember($huella, now()->addDay(), function () use ($lenguaje, $codigo, $casos) {
            if (trim($codigo) === '') {
                return ['aprobados' => 0, 'total' => count($casos), 'error_compilacion' => 'No hay solución de referencia.'];
            }
            $r = $this->evaluador->evaluar($lenguaje, $codigo, $casos);

            return ['aprobados' => $r['aprobados'], 'total' => $r['total'], 'error_compilacion' => $r['error_compilacion']];
        });
    }
}
