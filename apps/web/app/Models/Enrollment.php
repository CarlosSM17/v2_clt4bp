<?php

namespace App\Models;

use App\Enums\EstadoInscripcion;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

#[Fillable(['course_id', 'user_id', 'estado', 'solicitado_at', 'inscrito_at', 'baja_at'])]
class Enrollment extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'estado' => EstadoInscripcion::class,
            'solicitado_at' => 'datetime',
            'inscrito_at' => 'datetime',
            'baja_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Enrollment $inscripcion) {
            // El seudónimo es lo único que verá el agente IA y lo que aparece en las exportaciones.
            do {
                $seudonimo = 'E-'.Str::upper(Str::random(6));
            } while (static::where('seudonimo', $seudonimo)->exists());
            $inscripcion->seudonimo ??= $seudonimo;
        });
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Perfil vigente (la versión más reciente). */
    public function perfil(): HasOne
    {
        return $this->hasOne(StudentProfile::class)->latestOfMany('version');
    }

    /** Grupo diferenciado vigente. */
    public function membresia(): HasOne
    {
        return $this->hasOne(GroupMembership::class)->ofMany(['desde' => 'max'], fn ($q) => $q->whereNull('hasta'));
    }
}
