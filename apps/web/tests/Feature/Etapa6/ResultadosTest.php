<?php

namespace Tests\Feature\Etapa6;

use App\Enums\EstadoCurso;
use App\Enums\EstadoInscripcion;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\Consent;
use App\Models\Course;
use App\Models\DesignElement;
use App\Models\Enrollment;
use App\Models\InstrumentAdministration;
use App\Models\Item;
use App\Models\TaskProgress;
use App\Models\User;
use App\Services\Codigo\EjecutorCodigo;
use App\Services\Codigo\ResultadoEjecucion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Pagina;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreaUsuarios;
use Tests\TestCase;
use ZipArchive;

class ResultadosTest extends TestCase
{
    use CreaUsuarios, RefreshDatabase;

    private Course $curso;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.agente.url' => 'http://agente.test', 'services.agente.token' => 'secreto']);
        foreach (['mslq', 'imms', 'cs'] as $clave) {
            $this->artisan('instrumentos:cargar', ['archivo' => base_path("../../instruments/{$clave}.json")])->assertSuccessful();
        }
        $this->instructor = $this->instructor();
        $this->curso = Course::create(['owner_id' => $this->instructor->id, 'titulo' => 'C', 'lenguaje' => 'c', 'nivel_educativo' => 'preparatoria']);
        $this->curso->instructores()->attach($this->instructor->id, ['rol' => 'responsable']);
        Sanctum::actingAs($this->instructor, ['consola']);
    }

    private function inscrito(EstadoInscripcion $estado = EstadoInscripcion::Cursando): Enrollment
    {
        return Enrollment::create(['course_id' => $this->curso->id, 'user_id' => User::factory()->create()->id, 'estado' => $estado, 'inscrito_at' => now()]);
    }

    /** Respuestas con el mismo valor para los ítems p1...pn (p = prefijo del instrumento). */
    private function todas(int $n, int $valor, string $prefijo): array
    {
        return collect(range(1, $n))->mapWithKeys(fn ($i) => ["{$prefijo}{$i}" => $valor])->all();
    }

    /** @return array{0: Assessment, 1: Assessment} pre-test y post-test teóricos, sin ítems */
    private function pruebasPrePost(): array
    {
        return array_map(fn ($m) => Assessment::create(['course_id' => $this->curso->id, 'nombre' => $m, 'momento' => $m,
            'tipo' => 'teorica', 'forma' => $m === 'pre' ? 'A' : 'B']), ['pre', 'post']);
    }

    private function intento(Assessment $prueba, Enrollment $e, float $porcentaje): void
    {
        AssessmentAttempt::create(['assessment_id' => $prueba->id, 'enrollment_id' => $e->id, 'iniciado_at' => now()->subHour(),
            'enviado_at' => now(), 'calificado_at' => now(), 'porcentaje' => $porcentaje, 'subpuntajes' => ['recall' => $porcentaje]]);
    }

    public function test_la_evaluacion_final_completa_concluye_el_curso(): void
    {
        $item = Item::create(['course_id' => $this->curso->id, 'tipo' => 'opcion_multiple', 'nivel' => 'recall', 'estado' => 'aprobado',
            'objetivo' => 'OB-1', 'enunciado' => ['md' => '¿?', 'opciones' => [['id' => 'a', 'texto' => 'A'], ['id' => 'b', 'texto' => 'B']]],
            'clave' => ['correcta' => 'a']]);

        $url = "/api/v1/courses/{$this->curso->id}/evaluacion-final";
        $ventana = ['abre_at' => now()->subHour()->toIso8601String(), 'cierra_at' => now()->addWeek()->toIso8601String(), 'instrumentos' => ['mslq', 'imms']];

        // Sin post-test no se puede abrir la evaluación final
        $this->postJson($url, $ventana)->assertUnprocessable();
        $this->postJson("/api/v1/courses/{$this->curso->id}/assessments", ['nombre' => 'Teoría final', 'momento' => 'post',
            'tipo' => 'teorica', 'forma' => 'B', 'items' => [['id' => $item->id, 'puntos' => 1]]])->assertCreated();

        $this->postJson($url, $ventana)->assertOk()->assertJsonPath('data.instrumentos', ['mslq', 'imms'])->assertJsonPath('data.pruebas', 1);

        $e = $this->inscrito();
        $this->actingAs($e->user, 'web')->get("/cursos/{$this->curso->id}/evaluacion-final")
            ->assertInertia(fn (Pagina $p) => $p->component('evaluacion/Final')->where('abierta', true)->has('pasos', 3));
        $this->assertSame(EstadoInscripcion::EvaluacionFinal, $e->fresh()->estado);

        // Los cuestionarios usan la página del diagnóstico, que ahora regresa a la evaluación final
        foreach (['mslq' => [81, 'm', 5], 'imms' => [36, 'i', 4]] as $clave => [$n, $prefijo, $valor]) {
            $a = InstrumentAdministration::where('course_id', $this->curso->id)->where('momento', 'post')
                ->whereHas('instrument', fn ($q) => $q->where('clave', $clave))->firstOrFail();

            $base = "/cursos/{$this->curso->id}/cuestionarios/{$a->id}";
            $this->get($base)->assertOk();
            $this->post("{$base}/respuestas", ['respuestas' => $this->todas($n, $valor, $prefijo)])->assertRedirect();
            $this->post("{$base}/completar")->assertRedirect("/cursos/{$this->curso->id}/evaluacion-final");
        }

        // Post-test (la cola es síncrona en pruebas: se califica al enviar)
        $prueba = Assessment::where('course_id', $this->curso->id)->where('momento', 'post')->first();
        $this->get("/cursos/{$this->curso->id}/pruebas/{$prueba->id}")->assertOk();
        $intento = $prueba->attempts()->first();
        $this->post("/intentos/{$intento->id}/respuestas", ['item_id' => $item->id, 'respuesta' => ['valor' => 'a']]);
        $this->post("/intentos/{$intento->id}/enviar")->assertRedirect("/cursos/{$this->curso->id}/evaluacion-final");

        $this->assertSame(100.0, $intento->fresh()->porcentaje);
        $this->assertSame(EstadoInscripcion::Concluido, $e->fresh()->estado);
    }

    public function test_al_terminar_una_clase_se_pide_la_escala_de_carga(): void
    {
        $ejemplo = json_decode(file_get_contents(base_path('../../packages/contracts/examples/diseno-curso/clase-recorridos.json')), true);

        // Piston falso: la solución de referencia produce la salida esperada de cada caso (igual que en Etapa5\AulaTest)
        $salidas = [];
        foreach ($ejemplo['tareas'] as $t) {
            foreach ($t['casos_prueba'] as $c) {
                $salidas[$t['solucion']."\0".$c['entrada']] = $c['salida_esperada'];
            }
        }
        $this->app->bind(EjecutorCodigo::class, fn () => new class($salidas) implements EjecutorCodigo
        {
            public function __construct(private array $salidas) {}

            public function ejecutar(string $lenguaje, string $codigo, string $entrada = '', int $limiteMs = 3000): ResultadoEjecucion
            {
                return new ResultadoEjecucion(true, $this->salidas[$codigo."\0".$entrada] ?? "0\n", '', 0, false);
            }
        });

        $cambios = [];
        foreach (['objetivos' => 'objetivo', 'clases' => 'clase', 'tareas' => 'tarea', 'soporte' => 'soporte', 'procedimental' => 'procedimental'] as $lista => $tipo) {
            foreach ($ejemplo[$lista] as $c) {
                $cambios[] = ['uid' => $c['uid'], 'tipo' => $tipo, 'base_version' => 0, 'contenido' => $c];
            }
        }
        $this->postJson("/api/v1/courses/{$this->curso->id}/design/sync", ['cambios' => $cambios])->assertOk();
        DesignElement::where('course_id', $this->curso->id)->update(['estado' => 'aprobado']);
        $this->postJson("/api/v1/courses/{$this->curso->id}/releases", ['nota' => 'v1', 'clave_idempotencia' => (string) Str::uuid()])->assertCreated();
        $this->putJson("/api/v1/courses/{$this->curso->id}/activations", ['activaciones' => [
            ['clase_uid' => 'tc1', 'abre_at' => now()->subHour()->toIso8601String()],
        ]])->assertOk();

        $e = $this->inscrito();
        $mapa = "/aula/{$this->curso->id}";
        $this->actingAs($e->user, 'web')->get($mapa)->assertInertia(fn (Pagina $p) => $p->where('escalaCarga', null));

        foreach (collect($ejemplo['tareas'])->where('clase_uid', 'tc1') as $t) {
            TaskProgress::create(['enrollment_id' => $e->id, 'tarea_uid' => $t['uid'], 'estado' => 'completada', 'intentos' => 1]);
        }
        $this->get($mapa)->assertInertia(fn (Pagina $p) => $p->where('escalaCarga.clase_uid', 'tc1'));

        $a = InstrumentAdministration::where('momento', 'clase')->where('clase_uid', 'tc1')->firstOrFail();
        $base = "/cursos/{$this->curso->id}/cuestionarios/{$a->id}";
        $this->post("{$base}/respuestas", ['respuestas' => $this->todas(10, 3, 'c')])->assertRedirect();
        $this->post("{$base}/completar")->assertRedirect($mapa);
        $this->get($mapa)->assertInertia(fn (Pagina $p) => $p->where('escalaCarga', null));
    }

    public function test_la_revision_manda_al_servicio_solo_numeros(): void
    {
        Http::fake(['agente.test/v1/estadisticas/*' => Http::response(['global' => ['n' => 2]])]);

        [$pre, $post] = $this->pruebasPrePost();
        foreach ([[40, 70], [60, 90], [50, null]] as [$a, $b]) {
            $e = $this->inscrito();
            $this->intento($pre, $e, $a);
            if ($b !== null) {
                $this->intento($post, $e, $b);
            }
        }

        $this->getJson("/api/v1/courses/{$this->curso->id}/resultados")->assertOk()
            ->assertJsonPath('data.n', 3)->assertJsonPath('data.con_pre_y_post', 2)->assertJsonPath('data.pre_post.global.n', 2);

        Http::assertSent(function (PeticionHttp $r) {
            $global = $r->data()['series']['global'] ?? null;

            return str_ends_with($r->url(), '/v1/estadisticas/pre-post') && $r->hasHeader('X-Agente-Token', 'secreto')
                && $global['pre'] === [40.0, 60.0, 50.0] && $global['post'] === [70.0, 90.0, null]
                && ! str_contains($r->body(), 'E-'); // ni seudónimos: solo números en orden
        });

        // Dashboard del grupo: nadie ha entrado al material → alerta de inactividad
        $this->getJson("/api/v1/courses/{$this->curso->id}/tablero")->assertOk()
            ->assertJsonCount(3, 'data.estudiantes')->assertJsonPath('data.estudiantes.0.alertas.0.tipo', 'sin_actividad');
    }

    public function test_exportacion_solo_con_consentimiento_y_decision_del_paso_10(): void
    {
        [$pre] = $this->pruebasPrePost();
        $si = $this->inscrito();
        $no = $this->inscrito();
        Consent::create(['user_id' => $si->user_id, 'tipo' => 'investigacion', 'version' => '2026-09', 'otorgado_at' => now()]);
        Consent::create(['user_id' => $no->user_id, 'tipo' => 'investigacion', 'version' => '2026-09', 'otorgado_at' => now()->subWeek(), 'revocado_at' => now()]);

        $this->intento($pre, $si, 55);
        $this->intento($pre, $no, 65);

        $r = $this->get("/api/v1/courses/{$this->curso->id}/exportacion?formato=csv")->assertOk()->assertDownload();

        $zip = new ZipArchive();
        $this->assertTrue($zip->open($r->baseResponse->getFile()->getPathname()) === true);
        $this->assertStringContainsString($si->seudonimo, $zip->getFromName('estudiantes.csv'));
        $this->assertStringNotContainsString($no->seudonimo, $zip->getFromName('estudiantes.csv').$zip->getFromName('pruebas.csv'));
        $this->assertStringContainsString("{$si->seudonimo},pre,teorica,A,55", $zip->getFromName('pruebas.csv'));
        $this->assertNotFalse($zip->getFromName('diccionario.csv'));
        $zip->close();

        // Paso 10: aunque el servicio de estadísticos falle, la decisión se registra
        Http::fake(['agente.test/*' => Http::response(['detail' => 'caído'], 503)]);

        $url = "/api/v1/courses/{$this->curso->id}/resultados/decision";
        $this->postJson($url, ['decision' => 'iterar', 'notas' => 'La carga extrínseca de la clase 2 es alta.'])->assertUnprocessable();
        $this->postJson($url, ['decision' => 'iterar', 'regresar_a' => 'fase2', 'notas' => 'La carga extrínseca de la clase 2 es alta.'])
            ->assertCreated()->assertJsonPath('data.numero', 1);
        $this->getJson("/api/v1/courses/{$this->curso->id}")->assertJsonPath('data.iteracion.regresar_a', 'fase2');

        $this->postJson($url, ['decision' => 'cerrar', 'notas' => 'Se cumplieron los objetivos.'])->assertCreated()->assertJsonPath('data.numero', 2);
        $this->assertSame(EstadoCurso::Concluido, $this->curso->fresh()->estado);
        $this->getJson("/api/v1/courses/{$this->curso->id}")->assertJsonPath('data.iteracion', null);
    }
}
