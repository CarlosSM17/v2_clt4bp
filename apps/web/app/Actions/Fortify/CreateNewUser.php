<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Enums\Rol;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Valida y crea a un estudiante, guardando qué versión de cada texto legal aceptó.
     *
     * @param  array<string, mixed>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
            'acepta_privacidad' => ['accepted'],
            'acepta_investigacion' => ['nullable', 'boolean'],
        ], [
            'acepta_privacidad.accepted' => 'Debes aceptar el aviso de privacidad para registrarte.',
        ])->validate();

        return DB::transaction(function () use ($input) {
            $user = User::create([
                'name' => $input['name'],
                'email' => $input['email'],
                'password' => $input['password'],
            ]);

            $user->assignRole(Rol::Estudiante->value);

            $user->consents()->create([
                'tipo' => 'privacidad',
                'version' => config('clt4bp.legal.privacidad'),
                'otorgado_at' => now(),
            ]);

            if (filter_var($input['acepta_investigacion'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                $user->consents()->create([
                    'tipo' => 'investigacion',
                    'version' => config('clt4bp.legal.investigacion'),
                    'otorgado_at' => now(),
                ]);
            }

            return $user;
        });
    }
}
