<?php

namespace App\Http\Resources;

use App\Models\Course;
use App\Models\ResultReview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Course */
class CourseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'titulo' => $this->titulo,
            'descripcion' => $this->descripcion,
            'lenguaje' => $this->lenguaje,
            'nivel_educativo' => $this->nivel_educativo,
            'codigo_inscripcion' => $this->codigo_inscripcion,
            'estado' => $this->estado,
            'inicia_el' => $this->inicia_el?->toDateString(),
            'termina_el' => $this->termina_el?->toDateString(),
            'configuracion' => $this->configuracion,
            'pendientes' => $this->whenCounted('solicitudes'),
            'inscritos' => $this->whenCounted('inscritos'),
            'updated_at' => $this->updated_at,
            // Etapa 6: solo al pedir un curso (no en la lista, para no hacer una consulta por curso)
            'iteracion' => $this->when($request->route()?->getName() === 'courses.show',
                fn () => ResultReview::iteracionAbierta($this->id)?->only('numero', 'regresar_a', 'notas', 'created_at')),
        ];
    }
}
