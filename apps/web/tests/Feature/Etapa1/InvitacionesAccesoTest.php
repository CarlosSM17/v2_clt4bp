<?php

namespace Tests\Feature\Etapa1;

use App\Enums\Rol;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\InvitacionInstructor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreaUsuarios;
use Tests\TestCase;

class InvitacionesAccesoTest extends TestCase
{
    use CreaUsuarios, RefreshDatabase;

    private function admin(): User
    {
        $admin = $this->instructor();
        $admin->assignRole(Rol::Admin->value);

        return $admin;
    }

    private function invitado(): User
    {
        $user = User::factory()->create(['invitacion_aceptada_at' => null]);
        $user->assignRole(Rol::Instructor->value);

        return $user;
    }

    public function test_el_administrador_invita_a_un_instructor_y_queda_en_la_bitacora(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->admin(), ['consola']);

        $id = $this->postJson('/api/v1/admin/instructors', ['name' => 'Nuevo', 'email' => 'nuevo@example.com'])
            ->assertCreated()->json('data.id');

        $nuevo = User::findOrFail($id);
        $this->assertTrue($nuevo->hasRole('instructor'));
        Notification::assertSentTo($nuevo, InvitacionInstructor::class);
        $this->assertDatabaseHas('audit_logs', ['accion' => 'instructor.invitado', 'entidad_id' => $id]);
    }

    public function test_el_administrador_copia_un_enlace_de_invitacion_que_funciona_sin_correo(): void
    {
        // Donde el correo no sale (Railway, plan Hobby), el administrador entrega el enlace por otro medio
        Notification::fake();
        $invitado = $this->invitado();
        Sanctum::actingAs($this->admin(), ['consola']);

        $r = $this->postJson("/api/v1/admin/instructors/{$invitado->id}/enlace-invitacion")->assertOk();
        $this->assertNotNull($r->json('data.vence_at'));
        Notification::assertNothingSent();
        $this->assertDatabaseHas('audit_logs', ['accion' => 'instructor.enlace_invitacion', 'entidad_id' => $invitado->id]);

        // El enlace copiado abre la página para definir la contraseña, como el del correo
        auth()->forgetGuards();
        $this->get($r->json('data.enlace'))->assertOk();
    }

    public function test_solo_el_administrador_obtiene_enlaces_de_invitacion(): void
    {
        $invitado = $this->invitado();
        foreach ([$this->instructor(), $this->estudiante()] as $ajeno) {
            Sanctum::actingAs($ajeno, ['consola']);
            $this->postJson("/api/v1/admin/instructors/{$invitado->id}/enlace-invitacion")->assertForbidden();
        }

        // Una invitación ya aceptada no da enlace; quien no es instructor, tampoco
        Sanctum::actingAs($this->admin(), ['consola']);
        $invitado->forceFill(['invitacion_aceptada_at' => now()])->save();
        $this->postJson("/api/v1/admin/instructors/{$invitado->id}/enlace-invitacion")->assertStatus(422);
        $this->postJson("/api/v1/admin/instructors/{$this->estudiante()->id}/enlace-invitacion")->assertNotFound();
    }

    public function test_la_invitacion_sin_firma_o_caducada_es_rechazada(): void
    {
        $user = $this->invitado();

        $this->get("/invitacion/{$user->id}")->assertForbidden();

        $vencida = URL::temporarySignedRoute('invitacion.show', now()->subMinute(), ['user' => $user->id]);
        $this->get($vencida)->assertForbidden();
    }

    public function test_la_invitacion_define_la_contrasena_y_no_se_puede_reutilizar(): void
    {
        $user = $this->invitado();
        $url = URL::temporarySignedRoute('invitacion.show', now()->addHours(72), ['user' => $user->id]);

        $this->get($url)->assertOk();
        $this->post($url, ['password' => 'contrasena-larga-1', 'password_confirmation' => 'contrasena-larga-1'])
            ->assertRedirect(route('security.edit'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->refresh()->invitacion_aceptada_at);
        $this->assertDatabaseHas('audit_logs', ['accion' => 'instructor.invitacion_aceptada']);

        auth()->logout();
        $this->get($url)->assertStatus(410);
    }

    public function test_el_personal_sin_2fa_es_enviado_a_seguridad_en_la_web(): void
    {
        $sinDosPasos = User::factory()->create();
        $sinDosPasos->assignRole(Rol::Instructor->value);

        $this->actingAs($sinDosPasos)->get('/dashboard')->assertRedirect(route('security.edit'));
        $this->actingAs($this->instructor())->get('/dashboard')->assertOk();
    }

    public function test_un_estudiante_no_necesita_2fa(): void
    {
        $this->actingAs($this->estudiante())->get('/dashboard')->assertOk();
    }

    public function test_una_cuenta_suspendida_pierde_la_sesion_web(): void
    {
        $user = $this->instructor();
        $user->forceFill(['suspendido_at' => now()])->save();

        $this->actingAs($user)->get('/dashboard')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_no_se_puede_iniciar_sesion_en_la_web_estando_suspendido(): void
    {
        $user = User::factory()->create(['suspendido_at' => now()]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_suspender_a_un_instructor_revoca_sus_tokens_de_consola(): void
    {
        $admin = $this->admin();
        $instructor = $this->instructor();
        $instructor->createToken('consola-de-prueba', ['consola']);

        Sanctum::actingAs($admin, ['consola']);
        $this->patchJson("/api/v1/admin/instructors/{$instructor->id}", ['accion' => 'suspender'])->assertOk();

        $this->assertSame(0, $instructor->tokens()->count());
        $this->assertNotNull($instructor->refresh()->suspendido_at);
        $this->assertTrue(AuditLog::where('accion', 'instructor.suspender')->exists());
    }

    public function test_las_rutas_de_la_consola_exigen_autenticacion(): void
    {
        $this->getJson('/api/v1/courses')->assertUnauthorized();
        $this->getJson('/api/v1/admin/instructors')->assertUnauthorized();
    }

    public function test_un_estudiante_con_token_no_usa_la_api_de_la_consola(): void
    {
        Sanctum::actingAs($this->estudiante(), ['consola']);
        $this->getJson('/api/v1/courses')->assertForbidden();
    }
}
