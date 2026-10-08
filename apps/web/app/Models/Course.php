<?php

namespace App\Models;

use App\Enums\EstadoCurso;
use App\Enums\Lenguaje;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['owner_id', 'titulo', 'descripcion', 'lenguaje', 'nivel_educativo', 'estado', 'inicia_el', 'termina_el', 'configuracion'])]
class Course extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'lenguaje' => Lenguaje::class,
            'estado' => EstadoCurso::class,
            'inicia_el' => 'date',
            'termina_el' => 'date',
            'configuracion' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Course $curso) {
            $curso->codigo_inscripcion ??= static::nuevoCodigo();
        });
    }

    /** Código corto sin caracteres ambiguos (sin 0/O ni 1/I). */
    public static function nuevoCodigo(): string
    {
        $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        do {
            $codigo = collect(range(1, 8))
                ->map(fn () => $alfabeto[random_int(0, strlen($alfabeto) - 1)])
                ->implode('');
        } while (static::where('codigo_inscripcion', $codigo)->exists());

        return $codigo;
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    public function instructores(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'course_instructor')
            ->withPivot('rol')->withTimestamps();
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function imparte(User $user): bool
    {
        return $this->instructores()->whereKey($user->getKey())->exists();
    }
}
