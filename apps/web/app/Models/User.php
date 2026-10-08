<?php

namespace App\Models;

use App\Enums\Rol;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $institucion
 * @property int|null $invitado_por
 * @property Carbon|null $invitacion_aceptada_at
 * @property Carbon|null $suspendido_at
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password', 'institucion', 'invitado_por'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'invitacion_aceptada_at' => 'datetime',
            'suspendido_at' => 'datetime',
        ];
    }

    /** Administradores e instructores: el “personal” que usa la consola. */
    public function esPersonal(): bool
    {
        return $this->hasAnyRole([Rol::Admin->value, Rol::Instructor->value]);
    }

    public function estaSuspendido(): bool
    {
        return $this->suspendido_at !== null;
    }

    public function consents(): HasMany
    {
        return $this->hasMany(Consent::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /** Cursos que imparte (como responsable o colaborador). */
    public function cursosQueImparte(): BelongsToMany
    {
        return $this->belongsToMany(Course::class, 'course_instructor')
            ->withPivot('rol')->withTimestamps();
    }
}
