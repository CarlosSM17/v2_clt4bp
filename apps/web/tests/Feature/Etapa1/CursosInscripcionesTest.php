<?php

namespace Tests\Feature\Etapa1;

use App\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreaUsuarios;
use Tests\TestCase;

class CursosInscripcionesTest extends TestCase
{
    use CreaUsuarios, RefreshDatabase;

    private function cursoDe($instructor): Course
    {
        Sanctum::actingAs($instructor, ['consola']);

        $id = $this->postJson('/api/v1/courses', [
            'titulo' => 'Programación en C',
            'lenguaje' => 'c',
            'nivel_educativo' => 'preparatoria',
        ])->assertCreated()->json('data.id');

        return Course::findOrFail($id);
    }

    public function test_un_instructor_crea_un_curso_con_codigo_de_inscripcion(): void
    {
        $curso = $this->cursoDe($this->instructor());
        $this->assertSame(8, strlen($curso->codigo_inscripcion));
    }

    public function test_un_instructor_no_ve_cursos_ajenos(): void
    {
        $curso = $this->cursoDe($this->instructor());
        Sanctum::actingAs($this->instructor(), ['consola']);

        $this->getJson("/api/v1/courses/{$curso->id}")->assertForbidden();
    }

    public function test_un_token_sin_la_habilidad_consola_es_rechazado(): void
    {
        Sanctum::actingAs($this->instructor(), ['otra']);
        $this->getJson('/api/v1/courses')->assertForbidden();
    }

    public function test_flujo_completo_de_solicitud_y_aprobacion(): void
    {
        Notification::fake();
        $instructor = $this->instructor();
        $curso = $this->cursoDe($instructor);
        $estudiante = $this->estudiante();

        // Sin inscripción: 403
        $this->actingAs($estudiante, 'web')->get("/cursos/{$curso->id}")->assertForbidden();

        // Solicita con el código
        $this->actingAs($estudiante, 'web')->post('/cursos/solicitar', ['codigo' => $curso->codigo_inscripcion])
            ->assertRedirect();
        $inscripcion = $curso->enrollments()->firstOrFail();
        $this->assertSame('solicitud', $inscripcion->estado->value);
        $this->actingAs($estudiante, 'web')->get("/cursos/{$curso->id}")->assertForbidden();

        // El instructor aprueba desde la consola
        Sanctum::actingAs($instructor, ['consola']);
        $this->patchJson("/api/v1/enrollments/{$inscripcion->id}", ['accion' => 'aprobar'])->assertOk();

        // Ahora sí entra: sin perfil, el curso lo lleva al diagnóstico (Etapa 2)
        $this->actingAs($estudiante, 'web')->get("/cursos/{$curso->id}")
            ->assertRedirect("/cursos/{$curso->id}/diagnostico");
        $this->get("/cursos/{$curso->id}/diagnostico")->assertOk();
    }

    public function test_solo_el_administrador_invita_instructores(): void
    {
        Notification::fake();
        Sanctum::actingAs($this->instructor(), ['consola']);
        $this->postJson('/api/v1/admin/instructors', ['name' => 'X', 'email' => 'x@example.com'])->assertForbidden();
    }
}
