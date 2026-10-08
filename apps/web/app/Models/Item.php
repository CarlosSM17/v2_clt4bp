<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['course_id', 'objetivo', 'tipo', 'nivel', 'enunciado', 'clave', 'lenguaje', 'casos_prueba', 'solucion', 'autor_tipo', 'estado', 'verificado_at'])]
#[Hidden(['clave', 'solucion'])]
class Item extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['enunciado' => 'array', 'clave' => 'array', 'casos_prueba' => 'array', 'verificado_at' => 'datetime'];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** Lo que ve el estudiante: nunca clave, solución ni casos ocultos. */
    public function paraEstudiante(): array
    {
        $enunciado = $this->enunciado;
        if ($this->tipo === 'parsons') {
            shuffle($enunciado['lineas']);   // las líneas se muestran desordenadas
        }

        return [
            'id' => $this->id,
            'tipo' => $this->tipo,
            'enunciado' => $enunciado,
            'lenguaje' => $this->lenguaje,
            'ejemplos' => collect($this->casos_prueba ?? [])->reject(fn ($c) => $c['oculto'])->values(),
        ];
    }
}
