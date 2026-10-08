<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Features;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skipUnlessFortifyHas(Features::registration());
    }

    public function test_registration_screen_can_be_rendered(): void
    {
        $this->get(route('register'))->assertOk();
    }

    public function test_registro_exige_aceptar_el_aviso_de_privacidad(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Ana Estudiante',
            'email' => 'ana@example.com',
            'password' => 'contrasena-segura',
            'password_confirmation' => 'contrasena-segura',
        ])->assertSessionHasErrors('acepta_privacidad');

        $this->assertGuest();
    }

    public function test_registro_crea_estudiante_con_sus_consentimientos(): void
    {
        $this->post(route('register.store'), [
            'name' => 'Ana Estudiante',
            'email' => 'ana@example.com',
            'password' => 'contrasena-segura',
            'password_confirmation' => 'contrasena-segura',
            'acepta_privacidad' => '1',
            'acepta_investigacion' => '1',
        ])->assertRedirect(route('dashboard', absolute: false));

        $user = User::where('email', 'ana@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->hasRole('estudiante'));
        $this->assertEqualsCanonicalizing(['privacidad', 'investigacion'], $user->consents()->pluck('tipo')->all());
    }
}
