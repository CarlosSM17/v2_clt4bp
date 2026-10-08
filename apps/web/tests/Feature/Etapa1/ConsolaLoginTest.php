<?php

namespace Tests\Feature\Etapa1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaUsuarios;
use Tests\TestCase;

class ConsolaLoginTest extends TestCase
{
    use CreaUsuarios, RefreshDatabase;

    private function login(array $datos)
    {
        return $this->postJson('/api/v1/auth/login', ['device_name' => 'pruebas', ...$datos]);
    }

    public function test_un_estudiante_no_puede_entrar_a_la_consola(): void
    {
        $e = $this->estudiante();
        $this->login(['email' => $e->email, 'password' => 'password'])->assertForbidden();
    }

    public function test_un_instructor_sin_dos_pasos_recibe_403(): void
    {
        $i = User::factory()->create();
        $i->assignRole('instructor');
        $this->login(['email' => $i->email, 'password' => 'password'])->assertForbidden();
    }

    public function test_sin_codigo_pide_el_segundo_factor(): void
    {
        [$i] = $this->instructorCon2fa();
        $this->login(['email' => $i->email, 'password' => 'password'])
            ->assertStatus(422)
            ->assertJson(['requires_two_factor' => true]);
    }

    public function test_con_codigo_valido_entrega_un_token_que_sirve(): void
    {
        [$i, $secreto] = $this->instructorCon2fa();
        $token = $this->login(['email' => $i->email, 'password' => 'password', 'code' => $this->codigoTotp($secreto)])
            ->assertOk()
            ->json('token');

        $this->withToken($token)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.email', $i->email);
    }

    public function test_codigo_de_recuperacion_sirve_una_sola_vez(): void
    {
        [$i] = $this->instructorCon2fa();
        $datos = ['email' => $i->email, 'password' => 'password', 'recovery_code' => 'codigo-recuperacion-1'];

        $this->login($datos)->assertOk();
        $this->login($datos)->assertStatus(422);
    }

    public function test_un_instructor_suspendido_no_entra(): void
    {
        [$i, $secreto] = $this->instructorCon2fa();
        $i->forceFill(['suspendido_at' => now()])->save();
        $this->login(['email' => $i->email, 'password' => 'password', 'code' => $this->codigoTotp($secreto)])
            ->assertForbidden();
    }
}
