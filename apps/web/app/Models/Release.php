<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['course_id', 'numero', 'manifiesto', 'huella', 'nota', 'estado', 'publicado_por', 'clave_idempotencia'])]
class Release extends Model
{
    protected $hidden = ['manifiesto']; // pesa: solo se envía cuando se pide explícitamente

    protected function casts(): array
    {
        return ['manifiesto' => 'array'];
    }

    /** La publicación que ven hoy los estudiantes: la más reciente que no se revirtió. */
    public static function vigente(int $cursoId): ?self
    {
        return self::where('course_id', $cursoId)->where('estado', 'publicada')->orderByDesc('numero')->first();
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'publicado_por');
    }
}
