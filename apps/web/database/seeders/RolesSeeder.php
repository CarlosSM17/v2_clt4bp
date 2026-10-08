<?php

namespace Database\Seeders;

use App\Enums\Rol;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class RolesSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Rol::cases() as $rol) {
            Role::findOrCreate($rol->value, 'web');
        }
    }
}
