<?php

namespace Database\Seeders;

use App\Enums\Rol;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('clt4bp.admin.email');
        $password = config('clt4bp.admin.password');

        if (! $email || ! $password) {
            throw new RuntimeException('Define ADMIN_EMAIL y ADMIN_PASSWORD en .env antes de sembrar.');
        }

        $admin = User::firstOrCreate(['email' => $email], [
            'name' => config('clt4bp.admin.name'),
            'password' => $password,
        ]);
        $admin->forceFill(['email_verified_at' => now(), 'invitacion_aceptada_at' => now()])->save();
        $admin->syncRoles([Rol::Admin->value, Rol::Instructor->value]);
    }
}
