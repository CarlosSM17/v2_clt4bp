<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Pagina;
use Tests\TestCase;

/** CLT4BP_VERIFICAR_CORREO=false: donde el correo no puede salir (Railway, plan Hobby) nadie queda atorado. */
class VerificacionOpcionalTest extends TestCase
{
    use RefreshDatabase;

    public function test_con_la_verificacion_encendida_una_cuenta_sin_verificar_la_espera(): void
    {
        config(['clt4bp.verificar_correo' => true]);

        $this->actingAs(User::factory()->unverified()->create())
            ->get(route('dashboard'))->assertRedirect(route('verification.notice'));
    }

    public function test_apagada_una_cuenta_sin_verificar_entra_y_no_se_le_ofrece_el_enlace(): void
    {
        config(['clt4bp.verificar_correo' => false]);
        $usuario = User::factory()->unverified()->create();

        $this->actingAs($usuario)->get(route('dashboard'))->assertOk();
        $this->get(route('profile.edit'))->assertInertia(fn (Pagina $p) => $p->where('mustVerifyEmail', false));
        // Quien abra la pantalla de verificación va directo al inicio
        $this->get(route('verification.notice'))->assertRedirect();
    }

    public function test_apagada_el_registro_no_envia_el_enlace_y_entra_directo(): void
    {
        config(['clt4bp.verificar_correo' => false]);
        Notification::fake();

        $this->post(route('register.store'), [
            'name' => 'Ana Estudiante',
            'email' => 'ana@example.com',
            'password' => 'contrasena-segura',
            'password_confirmation' => 'contrasena-segura',
            'acepta_privacidad' => '1',
        ])->assertRedirect(route('dashboard', absolute: false));

        Notification::assertNotSentTo(User::where('email', 'ana@example.com')->firstOrFail(), VerifyEmail::class);
        $this->get(route('dashboard'))->assertOk();
    }
}
