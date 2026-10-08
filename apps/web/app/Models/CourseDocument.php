<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Material del curso que consulta el agente (RAG): apuntes, bibliografía. */
#[Fillable(['course_id', 'titulo', 'nombre_original', 'ruta', 'mime', 'bytes', 'sha256', 'estado', 'error',
    'fragmentos', 'modelo_embeddings', 'subido_por'])]
class CourseDocument extends Model
{
    public function curso(): BelongsTo
    {
        return $this->belongsTo(Course::class, 'course_id');
    }

    public function partes(): HasMany
    {
        return $this->hasMany(DocumentFragment::class);
    }
}
