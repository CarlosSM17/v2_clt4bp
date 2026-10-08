<?php

namespace Tests\Concerns;

use App\Enums\Rol;
use App\Models\User;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use PragmaRX\Google2FA\Google2FA;

trait CreaUsuarios
{
    protected function estudiante(): User
    {
        return tap(User::factory()->create())->assignRole(Rol::Estudiante->value);
    }

    /** Instructor con 2FA confirmada. Devuelve [usuario, secreto TOTP en claro]. */
    protected function instructorCon2fa(): array
    {
        $secreto = app(TwoFactorAuthenticationProvider::class)->generateSecretKey();
        $user = User::factory()->create([
            'two_factor_secret' => encrypt($secreto),
            'two_factor_recovery_codes' => encrypt(json_encode(['codigo-recuperacion-1'])),
            'two_factor_confirmed_at' => now(),
            'invitacion_aceptada_at' => now(),
        ]);
        $user->assignRole(Rol::Instructor->value);

        return [$user, $secreto];
    }

    protected function instructor(): User
    {
        return $this->instructorCon2fa()[0];
    }

    protected function codigoTotp(string $secreto): string
    {
        return app(Google2FA::class)->getCurrentOtp($secreto);
    }
}
