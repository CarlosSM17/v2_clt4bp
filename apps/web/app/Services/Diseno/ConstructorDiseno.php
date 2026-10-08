<?php

namespace App\Services\Diseno;

use App\Models\Course;
use App\Models\DesignElement;
use App\Models\DiffGroup;

/** Arma la fotografía DisenoCurso (diseno-curso.schema.json) con lo que hay en el servidor. */
class ConstructorDiseno
{
    public function construir(Course $curso): array
    {
        $elementos = DesignElement::where('course_id', $curso->id)->whereNull('eliminado_at')
            ->orderBy('orden')->orderBy('uid')->get()->groupBy('tipo');
        $de = fn (string $tipo) => ($elementos[$tipo] ?? collect())->pluck('contenido')->values()->all();

        return [
            'curso' => [
                'id' => $curso->id,
                'titulo' => $curso->titulo,
                'lenguaje' => $curso->lenguaje->value,
                'grupos' => DiffGroup::where('course_id', $curso->id)->orderBy('orden')
                    ->get(['clave', 'nombre', 'nivel'])->toArray(),
            ],
            'objetivos' => $de('objetivo'),
            'clases' => $de('clase'),
            'tareas' => $de('tarea'),
            'soporte' => $de('soporte'),
            'procedimental' => $de('procedimental'),
            'practica_parcial' => $de('practica_parcial'),
            'variantes' => $de('variante'),
            'medios' => $de('medio'),
        ];
    }
}
