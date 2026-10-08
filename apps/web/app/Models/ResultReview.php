<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['course_id', 'numero', 'decision', 'regresar_a', 'notas', 'resumen', 'agent_job_id', 'decidido_por'])]
class ResultReview extends Model
{
    protected $hidden = ['resumen']; // pesa; la consola lo pide solo al abrir una revisión

    protected function casts(): array
    {
        return ['resumen' => 'array'];
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decidido_por');
    }

    /** La última decisión de «iterar»: sus notas se muestran en el estudio de diseño. */
    public static function iteracionAbierta(int $cursoId): ?self
    {
        $ultima = self::where('course_id', $cursoId)->orderByDesc('numero')->first();

        return $ultima?->decision === 'iterar' ? $ultima : null;
    }
}
