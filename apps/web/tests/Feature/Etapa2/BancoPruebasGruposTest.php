<?php

namespace Tests\Feature\Etapa2;

use App\Enums\EstadoInscripcion;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\GroupAnalysis;
use App\Models\GroupMembership;
use App\Models\Item;
use App\Models\StudentProfile;
use App\Services\Codigo\EjecutorCodigo;
use App\Services\Codigo\ResultadoEjecucion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreaUsuarios;
use Tests\TestCase;

class BancoPruebasGruposTest extends TestCase
{
    use CreaUsuarios, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ejecutor falso: el programa "suma" los números de la entrada
        $this->app->bind(EjecutorCodigo::class, fn () => new class implements EjecutorCodigo
        {
            public function ejecutar(string $lenguaje, string $codigo, string $entrada = '', int $limiteMs = 3000): ResultadoEjecucion
            {
                $suma = array_sum(array_map('intval', preg_split('/\s+/', trim($entrada))));

                return new ResultadoEjecucion(true, $suma."\n", '', 0, false);
            }
        });
    }

    private function cursoConInstructor(): array
    {
        $instructor = $this->instructor();
        $curso = Course::create(['owner_id' => $instructor->id, 'titulo' => 'C', 'lenguaje' => 'c', 'nivel_educativo' => 'preparatoria']);
        $curso->instructores()->attach($instructor->id, ['rol' => 'responsable']);

        return [$curso, $instructor];
    }

    private function itemProgramacion(Course $curso, array $extra = []): Item
    {
        return Item::create([...[
            'course_id' => $curso->id, 'tipo' => 'programacion', 'nivel' => 'practica', 'lenguaje' => 'c',
            'enunciado' => ['md' => 'Suma dos números'], 'solucion' => 'int main(){}',
            'casos_prueba' => [
                ['entrada' => '2 3', 'salida_esperada' => '5', 'oculto' => false],
                ['entrada' => '40 2', 'salida_esperada' => '42', 'oculto' => true],
            ],
        ], ...$extra]);
    }

    public function test_un_instructor_ajeno_recibe_403_en_toda_la_api_del_curso(): void
    {
        [$curso] = $this->cursoConInstructor();
        $item = $this->itemProgramacion($curso);
        Sanctum::actingAs($this->instructor(), ['consola']);

        $this->getJson("/api/v1/courses/{$curso->id}/items")->assertForbidden();
        $this->postJson("/api/v1/courses/{$curso->id}/items", [])->assertForbidden();
        $this->putJson("/api/v1/items/{$item->id}", [])->assertForbidden();
        $this->postJson("/api/v1/items/{$item->id}/verificar")->assertForbidden();
        $this->postJson("/api/v1/items/{$item->id}/aprobar")->assertForbidden();
        $this->getJson("/api/v1/courses/{$curso->id}/assessments")->assertForbidden();
        $this->postJson("/api/v1/courses/{$curso->id}/assessments", [])->assertForbidden();
        $this->postJson("/api/v1/courses/{$curso->id}/diagnostico")->assertForbidden();
        $this->getJson("/api/v1/courses/{$curso->id}/diagnostico")->assertForbidden();
        $this->getJson("/api/v1/courses/{$curso->id}/analisis")->assertForbidden();
        $this->postJson("/api/v1/courses/{$curso->id}/grupos/propuesta", ['metodo' => 'nivel'])->assertForbidden();
        $this->putJson("/api/v1/courses/{$curso->id}/grupos", [])->assertForbidden();
    }

    public function test_un_estudiante_no_usa_la_api_del_banco(): void
    {
        [$curso] = $this->cursoConInstructor();
        Sanctum::actingAs($this->estudiante(), ['consola']);

        $this->getJson("/api/v1/courses/{$curso->id}/items")->assertForbidden();
    }

    public function test_un_problema_de_programacion_solo_se_aprueba_tras_verificarlo(): void
    {
        [$curso, $instructor] = $this->cursoConInstructor();
        $item = $this->itemProgramacion($curso);
        Sanctum::actingAs($instructor, ['consola']);

        $this->postJson("/api/v1/items/{$item->id}/aprobar")->assertStatus(422);

        $this->postJson("/api/v1/items/{$item->id}/verificar")->assertOk()->assertJsonPath('verificado', true);
        $this->postJson("/api/v1/items/{$item->id}/aprobar")->assertOk();
        $this->assertSame('aprobado', $item->refresh()->estado);
    }

    public function test_un_caso_sin_entrada_se_verifica(): void
    {
        [$curso, $instructor] = $this->cursoConInstructor();
        Sanctum::actingAs($instructor, ['consola']);

        // La consola manda '' y el middleware lo guarda como null
        $id = $this->postJson("/api/v1/courses/{$curso->id}/items", [
            'tipo' => 'programacion', 'nivel' => 'practica', 'enunciado' => ['md' => 'Imprime 0'], 'lenguaje' => 'c',
            'solucion' => 'x', 'casos_prueba' => [['entrada' => '', 'salida_esperada' => '0', 'oculto' => false]],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/items/{$id}/verificar")->assertOk()->assertJsonPath('verificado', true);
    }

    public function test_una_solucion_que_falla_un_caso_no_queda_verificada(): void
    {
        [$curso, $instructor] = $this->cursoConInstructor();
        $item = $this->itemProgramacion($curso, ['casos_prueba' => [['entrada' => '2 3', 'salida_esperada' => '99', 'oculto' => false]]]);
        Sanctum::actingAs($instructor, ['consola']);

        $this->postJson("/api/v1/items/{$item->id}/verificar")->assertOk()->assertJsonPath('verificado', false);
        $this->assertNull($item->refresh()->verificado_at);
    }

    public function test_editar_un_item_borra_su_verificacion(): void
    {
        [$curso, $instructor] = $this->cursoConInstructor();
        $item = $this->itemProgramacion($curso, ['verificado_at' => now()]);
        Sanctum::actingAs($instructor, ['consola']);

        $this->putJson("/api/v1/items/{$item->id}", [
            'tipo' => 'programacion', 'nivel' => 'practica', 'enunciado' => ['md' => 'Otro enunciado'], 'lenguaje' => 'c',
            'solucion' => 'x', 'casos_prueba' => [['entrada' => '1', 'salida_esperada' => '1', 'oculto' => false]],
        ])->assertOk();

        $this->assertNull($item->refresh()->verificado_at);
    }

    public function test_una_prueba_solo_admite_items_aprobados_del_mismo_curso(): void
    {
        [$curso, $instructor] = $this->cursoConInstructor();
        $borrador = $this->itemProgramacion($curso);
        Sanctum::actingAs($instructor, ['consola']);

        $this->postJson("/api/v1/courses/{$curso->id}/assessments", [
            'nombre' => 'P', 'momento' => 'pre', 'tipo' => 'practica', 'forma' => 'A',
            'items' => [['id' => $borrador->id, 'puntos' => 1]],
        ])->assertStatus(422);
    }

    public function test_el_estudiante_nunca_recibe_la_clave_la_solucion_ni_los_casos_ocultos(): void
    {
        [$curso, $instructor] = $this->cursoConInstructor();
        $item = $this->itemProgramacion($curso, ['estado' => 'aprobado', 'verificado_at' => now()]);
        $opcion = Item::create(['course_id' => $curso->id, 'tipo' => 'opcion_multiple', 'nivel' => 'recall', 'estado' => 'aprobado',
            'enunciado' => ['md' => '¿?', 'opciones' => [['id' => 'a', 'texto' => 'A'], ['id' => 'b', 'texto' => 'B']]],
            'clave' => ['correcta' => 'SECRETO-CLAVE']]);
        $prueba = Assessment::create(['course_id' => $curso->id, 'nombre' => 'Mixta', 'momento' => 'pre', 'tipo' => 'teorica', 'forma' => 'A']);
        $prueba->items()->attach($item->id, ['orden' => 1, 'puntos' => 1]);
        $prueba->items()->attach($opcion->id, ['orden' => 2, 'puntos' => 1]);

        $estudiante = $this->estudiante();
        Enrollment::create(['course_id' => $curso->id, 'user_id' => $estudiante->id, 'estado' => EstadoInscripcion::Inscrito]);

        $respuesta = $this->actingAs($estudiante, 'web')->get("/cursos/{$curso->id}/pruebas/{$prueba->id}")->assertOk();

        $html = $respuesta->getContent();
        $this->assertStringNotContainsString('SECRETO-CLAVE', $html);
        $this->assertStringNotContainsString('int main(){}', $html);     // solución de referencia
        $this->assertStringNotContainsString('&quot;40 2&quot;', $html); // entrada del caso oculto
        $this->assertStringContainsString('2 3', $html);                  // el ejemplo visible sí
    }

    public function test_el_tiempo_limite_lo_hace_cumplir_el_servidor(): void
    {
        [$curso, $instructor] = $this->cursoConInstructor();
        $item = Item::create(['course_id' => $curso->id, 'tipo' => 'respuesta_corta', 'nivel' => 'recall', 'estado' => 'aprobado',
            'enunciado' => ['md' => '¿?'], 'clave' => ['aceptadas' => ['x']]]);
        $prueba = Assessment::create(['course_id' => $curso->id, 'nombre' => 'T', 'momento' => 'pre', 'tipo' => 'teorica', 'forma' => 'A', 'tiempo_limite_min' => 10]);
        $prueba->items()->attach($item->id, ['orden' => 1, 'puntos' => 1]);
        $estudiante = $this->estudiante();
        $inscripcion = Enrollment::create(['course_id' => $curso->id, 'user_id' => $estudiante->id, 'estado' => EstadoInscripcion::Diagnostico]);

        $intento = AssessmentAttempt::create(['assessment_id' => $prueba->id, 'enrollment_id' => $inscripcion->id, 'iniciado_at' => now()->subMinutes(30)]);

        // Pasado el límite (más un minuto de gracia) ya no se aceptan respuestas...
        $this->actingAs($estudiante, 'web')->post("/intentos/{$intento->id}/respuestas", ['item_id' => $item->id, 'respuesta' => ['valor' => 'x']])
            ->assertStatus(409);
        // ...pero sí el envío final.
        $this->post("/intentos/{$intento->id}/enviar")->assertRedirect();
        $this->assertNotNull($intento->refresh()->enviado_at);
        // Y una vez enviado, tampoco se puede volver a enviar.
        $this->post("/intentos/{$intento->id}/enviar")->assertStatus(409);
    }

    public function test_un_estudiante_sin_inscripcion_no_entra_al_diagnostico(): void
    {
        [$curso] = $this->cursoConInstructor();

        $this->actingAs($this->estudiante(), 'web')->get("/cursos/{$curso->id}/diagnostico")->assertForbidden();
    }

    public function test_decidir_contra_la_recomendacion_exige_justificacion_y_queda_en_la_bitacora(): void
    {
        [$curso, $instructor] = $this->cursoConInstructor();
        $analisis = GroupAnalysis::create(['course_id' => $curso->id, 'resultado' => ['n' => 3], 'recomendacion' => 'heterogeneo']);
        Sanctum::actingAs($instructor, ['consola']);
        $url = "/api/v1/courses/{$curso->id}/analisis/{$analisis->id}/decision";

        $this->postJson($url, ['decision' => 'homogeneo'])->assertStatus(422)->assertJsonValidationErrors('justificacion');
        $this->postJson($url, ['decision' => 'homogeneo', 'justificacion' => 'Grupo pequeño y parejo en clase.'])->assertOk();

        $this->assertSame('homogeneo', $analisis->refresh()->decision);
        $this->assertSame($instructor->id, $analisis->decidido_por);
        $this->assertDatabaseHas('audit_logs', ['accion' => 'grupo.decision_homogeneidad']);

        $this->postJson($url, ['decision' => 'heterogeneo'])->assertOk();   // a favor de la recomendación: sin justificación
    }

    public function test_guardar_grupos_cierra_las_membresias_anteriores(): void
    {
        [$curso, $instructor] = $this->cursoConInstructor();
        $e = Enrollment::create(['course_id' => $curso->id, 'user_id' => $this->estudiante()->id, 'estado' => EstadoInscripcion::ConPerfil]);
        StudentProfile::create(['enrollment_id' => $e->id, 'version' => 1, 'cp_recall' => 10, 'cp_comprension' => 10, 'cp_teorico' => 10,
            'cp_practico' => 10, 'cp_global' => 10, 'nivel' => 'basico', 'mslq' => [], 'indices' => [], 'banderas' => []]);
        Sanctum::actingAs($instructor, ['consola']);

        $propuesta = $this->postJson("/api/v1/courses/{$curso->id}/grupos/propuesta", ['metodo' => 'nivel'])->assertOk()->json('data');
        $this->assertSame([$e->id], $propuesta['grupos'][0]['miembros']);   // ids de inscripción, no seudónimos

        $cuerpo = ['grupos' => [['clave' => 'G1', 'nombre' => 'Ruta A', 'nivel' => 'basico', 'miembros' => [$e->id]]]];
        $this->putJson("/api/v1/courses/{$curso->id}/grupos", $cuerpo)->assertOk();
        $this->putJson("/api/v1/courses/{$curso->id}/grupos", [...$cuerpo, 'motivo' => 'reagrupación'])->assertOk();

        $this->assertSame(2, GroupMembership::where('enrollment_id', $e->id)->count());
        $this->assertSame(1, GroupMembership::where('enrollment_id', $e->id)->whereNull('hasta')->count());
        $this->assertSame('Ruta A', $e->refresh()->membresia->group->nombre);
        $this->assertDatabaseHas('audit_logs', ['accion' => 'grupos.guardados']);
    }

    public function test_los_grupos_solo_aceptan_inscripciones_del_mismo_curso(): void
    {
        [$curso, $instructor] = $this->cursoConInstructor();
        [$otro] = $this->cursoConInstructor();
        $ajena = Enrollment::create(['course_id' => $otro->id, 'user_id' => $this->estudiante()->id, 'estado' => EstadoInscripcion::ConPerfil]);
        Sanctum::actingAs($instructor, ['consola']);

        $this->putJson("/api/v1/courses/{$curso->id}/grupos", ['grupos' => [['clave' => 'G1', 'nombre' => 'Ruta A', 'miembros' => [$ajena->id]]]])
            ->assertStatus(422);
    }
}
