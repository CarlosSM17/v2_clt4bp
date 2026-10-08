<?php

namespace Tests\Feature\Etapa7;

use App\Enums\EstadoInscripcion;
use App\Models\Consent;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\TaskProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Pagina;
use Tests\Concerns\CreaUsuarios;
use Tests\TestCase;
use ZipArchive;

class MisDatosTest extends TestCase
{
    use CreaUsuarios, RefreshDatabase;

    public function test_solo_se_revoca_el_consentimiento_de_investigacion_propio(): void
    {
        $yo = $this->estudiante();
        $privacidad = Consent::create(['user_id' => $yo->id, 'tipo' => 'privacidad', 'version' => '2026-09', 'otorgado_at' => now()]);
        $investigacion = Consent::create(['user_id' => $yo->id, 'tipo' => 'investigacion', 'version' => '2026-09', 'otorgado_at' => now()]);
        $ajeno = Consent::create(['user_id' => $this->estudiante()->id, 'tipo' => 'investigacion', 'version' => '2026-09', 'otorgado_at' => now()]);

        $this->actingAs($yo)->get('/mis-datos')
            ->assertInertia(fn (Pagina $p) => $p->component('cuenta/MisDatos')->has('consentimientos', 2));

        $this->post("/mis-datos/consentimientos/{$privacidad->id}/revocar")->assertStatus(422);
        $this->post("/mis-datos/consentimientos/{$ajeno->id}/revocar")->assertForbidden();
        $this->post("/mis-datos/consentimientos/{$investigacion->id}/revocar")->assertRedirect();
        $this->post("/mis-datos/consentimientos/{$investigacion->id}/revocar")->assertStatus(422); // ya estaba revocado

        $this->assertNotNull($investigacion->fresh()->revocado_at);
        $this->assertNull($privacidad->fresh()->revocado_at);
        $this->assertNull($ajeno->fresh()->revocado_at);
        $this->assertDatabaseHas('audit_logs', ['accion' => 'privacidad.revocar', 'actor_id' => $yo->id]);
    }

    public function test_la_descarga_trae_solo_mis_datos(): void
    {
        $curso = Course::create(['owner_id' => $this->instructor()->id, 'titulo' => 'C', 'lenguaje' => 'c', 'nivel_educativo' => 'preparatoria']);
        $yo = $this->estudiante();
        $otro = $this->estudiante();
        foreach ([[$yo, 'int mio;'], [$otro, 'int ajeno;']] as [$usuario, $codigo]) {
            $e = Enrollment::create(['course_id' => $curso->id, 'user_id' => $usuario->id, 'estado' => EstadoInscripcion::Cursando, 'inscrito_at' => now()]);
            TaskProgress::create(['enrollment_id' => $e->id, 'tarea_uid' => 't1', 'borrador_codigo' => $codigo]);
        }

        $r = $this->actingAs($yo)->get('/mis-datos/descarga')->assertOk()->assertDownload('mis-datos-clt4bp.zip');

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($r->baseResponse->getFile()->getPathname()) === true);
        $this->assertStringContainsString('int mio;', $zip->getFromName('avance_tareas.json'));
        $this->assertStringNotContainsString('int ajeno;', $zip->getFromName('avance_tareas.json'));
        $this->assertStringContainsString($yo->email, $zip->getFromName('cuenta.json'));
        $this->assertStringNotContainsString('password', $zip->getFromName('cuenta.json'));
        $zip->close();
        $this->assertDatabaseHas('audit_logs', ['accion' => 'privacidad.descarga', 'actor_id' => $yo->id]);
    }
}
