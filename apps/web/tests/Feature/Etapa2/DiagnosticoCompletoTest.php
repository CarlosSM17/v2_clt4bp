<?php

namespace Tests\Feature\Etapa2;

use App\Enums\EstadoInscripcion;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\GroupAnalysis;
use App\Models\InstrumentAdministration;
use App\Models\Item;
use App\Services\Codigo\EjecutorCodigo;
use App\Services\Codigo\ResultadoEjecucion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreaUsuarios;
use Tests\TestCase;

/**
 * Recorre el diagnóstico completo con un estudiante simulado y compara el perfil
 * calculado por el sistema con un cálculo hecho a mano en la prueba.
 */
class DiagnosticoCompletoTest extends TestCase
{
    use CreaUsuarios, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Ejecutor falso: devuelve la salida esperada solo si el código contiene "CORRECTO"
        $this->app->bind(EjecutorCodigo::class, fn () => new class implements EjecutorCodigo
        {
            public function ejecutar(string $lenguaje, string $codigo, string $entrada = '', int $limiteMs = 3000): ResultadoEjecucion
            {
                return new ResultadoEjecucion(true, str_contains($codigo, 'CORRECTO') ? "5\n" : "0\n", '', 0, false);
            }
        });
        $this->artisan('instrumentos:cargar', ['archivo' => base_path('../../instruments/mslq.json')])->assertSuccessful();
    }

    private function prepararCurso(): array
    {
        $instructor = $this->instructor();
        $curso = Course::create(['owner_id' => $instructor->id, 'titulo' => 'C', 'lenguaje' => 'c', 'nivel_educativo' => 'preparatoria']);
        $curso->instructores()->attach($instructor->id, ['rol' => 'responsable']);
        Sanctum::actingAs($instructor, ['consola']);

        $this->postJson("/api/v1/courses/{$curso->id}/diagnostico")->assertCreated();

        $mc = Item::create(['course_id' => $curso->id, 'tipo' => 'opcion_multiple', 'nivel' => 'recall', 'estado' => 'aprobado',
            'enunciado' => ['md' => '¿?', 'opciones' => [['id' => 'a', 'texto' => 'A'], ['id' => 'b', 'texto' => 'B']]],
            'clave' => ['correcta' => 'b']]);
        $pred = Item::create(['course_id' => $curso->id, 'tipo' => 'prediccion_salida', 'nivel' => 'comprension', 'estado' => 'aprobado',
            'enunciado' => ['md' => '¿Qué imprime?'], 'clave' => ['salida' => '3']]);
        $prog = Item::create(['course_id' => $curso->id, 'tipo' => 'programacion', 'nivel' => 'practica', 'estado' => 'aprobado',
            'enunciado' => ['md' => 'Suma'], 'lenguaje' => 'c', 'solucion' => 'CORRECTO', 'verificado_at' => now(),
            'casos_prueba' => [['entrada' => '2 3', 'salida_esperada' => '5', 'oculto' => false]]]);

        $this->postJson("/api/v1/courses/{$curso->id}/assessments", ['nombre' => 'Teoría', 'momento' => 'pre', 'tipo' => 'teorica',
            'forma' => 'A', 'items' => [['id' => $mc->id, 'puntos' => 1], ['id' => $pred->id, 'puntos' => 1]]])->assertCreated();
        $this->postJson("/api/v1/courses/{$curso->id}/assessments", ['nombre' => 'Práctica', 'momento' => 'pre', 'tipo' => 'practica',
            'forma' => 'A', 'items' => [['id' => $prog->id, 'puntos' => 1]]])->assertCreated();

        return [$curso, $mc, $pred, $prog];
    }

    public function test_el_diagnostico_completo_produce_el_perfil_esperado(): void
    {
        [$curso, $mc, $pred, $prog] = $this->prepararCurso();
        $estudiante = $this->estudiante();
        $inscripcion = Enrollment::create(['course_id' => $curso->id, 'user_id' => $estudiante->id,
            'estado' => EstadoInscripcion::Inscrito, 'inscrito_at' => now()]);
        $this->actingAs($estudiante, 'web');

        // 1) El curso lleva al diagnóstico
        $this->get("/cursos/{$curso->id}")->assertRedirect("/cursos/{$curso->id}/diagnostico");
        $this->get("/cursos/{$curso->id}/diagnostico")->assertOk();

        // 2) MSLQ: todo en 5, salvo autoeficacia en 3 (ítems m15 a m26)
        $aplicacion = InstrumentAdministration::where('course_id', $curso->id)->first();
        $this->get("/cursos/{$curso->id}/cuestionarios/{$aplicacion->id}")->assertOk();
        $respuestas = [];
        foreach (range(1, 73) as $i) {
            $respuestas["m{$i}"] = $i >= 15 && $i <= 26 ? 3 : 5;
        }
        $base = "/cursos/{$curso->id}/cuestionarios/{$aplicacion->id}";
        $this->post("{$base}/respuestas", ['respuestas' => $respuestas])->assertRedirect();
        $this->post("{$base}/completar")->assertRedirect("/cursos/{$curso->id}/diagnostico");

        // 3) Teoría: acierta la opción múltiple (recall) y falla la predicción (comprensión)
        [$teoria, $practica] = Assessment::where('course_id', $curso->id)->orderBy('id')->get();
        $this->get("/cursos/{$curso->id}/pruebas/{$teoria->id}")->assertOk();
        $intento = $teoria->attempts()->first();
        $this->post("/intentos/{$intento->id}/respuestas", ['item_id' => $mc->id, 'respuesta' => ['valor' => 'b']]);
        $this->post("/intentos/{$intento->id}/respuestas", ['item_id' => $pred->id, 'respuesta' => ['valor' => '4']]);
        $this->post("/intentos/{$intento->id}/enviar")->assertRedirect();

        // 4) Práctica: código correcto (QUEUE_CONNECTION=sync en pruebas: se califica al momento)
        $this->get("/cursos/{$curso->id}/pruebas/{$practica->id}")->assertOk();
        $intentoP = $practica->attempts()->first();
        $this->post("/intentos/{$intentoP->id}/respuestas", ['item_id' => $prog->id, 'respuesta' => ['codigo' => '/* CORRECTO */']]);
        $this->post("/intentos/{$intentoP->id}/enviar")->assertRedirect();

        // 5) Perfil calculado a mano: teórico 50 % (recall 100, comprensión 0), práctico 100 %
        //    CP = 0.5 × 50 + 0.5 × 100 = 75 → avanzado; autoeficacia 3 → bandera
        $perfil = $inscripcion->refresh()->perfil;
        $this->assertSame(EstadoInscripcion::ConPerfil, $inscripcion->estado);
        $this->assertSame(100.0, $perfil->cp_recall);
        $this->assertSame(0.0, $perfil->cp_comprension);
        $this->assertSame(50.0, $perfil->cp_teorico);
        $this->assertSame(100.0, $perfil->cp_practico);
        $this->assertSame(75.0, $perfil->cp_global);
        $this->assertSame('avanzado', $perfil->nivel);
        $this->assertSame(3.0, (float) $perfil->mslq['autoeficacia']);
        $this->assertSame(5.0, (float) $perfil->mslq['valor_tarea']);
        $this->assertContains('baja_autoeficacia', $perfil->banderas);
        $this->assertSame(4.33, $perfil->indices['motivacion']);   // (3 + 5 + 5) / 3

        // 6) Hay un análisis de grupo nuevo
        $this->assertSame(1, GroupAnalysis::where('course_id', $curso->id)->count());
    }

    public function test_un_estudiante_no_puede_enviar_el_intento_de_otro(): void
    {
        [$curso] = $this->prepararCurso();
        $a = $this->estudiante();
        $b = $this->estudiante();
        foreach ([$a, $b] as $e) {
            Enrollment::create(['course_id' => $curso->id, 'user_id' => $e->id, 'estado' => EstadoInscripcion::Inscrito]);
        }
        $teoria = Assessment::where('course_id', $curso->id)->where('tipo', 'teorica')->first();

        $this->actingAs($a, 'web')->get("/cursos/{$curso->id}/pruebas/{$teoria->id}")->assertOk();
        $intentoDeA = $teoria->attempts()->first();

        $this->actingAs($b, 'web')->post("/intentos/{$intentoDeA->id}/enviar")->assertForbidden();
    }
}
