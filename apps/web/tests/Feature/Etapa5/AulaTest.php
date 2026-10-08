<?php

namespace Tests\Feature\Etapa5;

use App\Enums\EstadoInscripcion;
use App\Models\Activation;
use App\Models\Course;
use App\Models\DesignElement;
use App\Models\DiffGroup;
use App\Models\Enrollment;
use App\Models\GroupMembership;
use App\Models\LearningEvent;
use App\Models\Release;
use App\Models\TaskProgress;
use App\Models\TaskSubmission;
use App\Models\User;
use App\Notifications\ClaseDisponible;
use App\Services\Codigo\EjecutorCodigo;
use App\Services\Codigo\ResultadoEjecucion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Pagina;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreaUsuarios;
use Tests\TestCase;

class AulaTest extends TestCase
{
    use CreaUsuarios, RefreshDatabase;

    private Course $curso;

    private User $instructor;

    private array $ejemplo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ejemplo = json_decode(file_get_contents(base_path('../../packages/contracts/examples/diseno-curso/clase-recorridos.json')), true);

        // Piston falso: la solución de referencia produce la salida esperada de cada caso; cualquier otro código, «0»
        $salidas = [];
        foreach ($this->ejemplo['tareas'] as $t) {
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

        $this->instructor = $this->instructor();
        $this->curso = Course::create(['owner_id' => $this->instructor->id, 'titulo' => 'C', 'lenguaje' => 'c', 'nivel_educativo' => 'preparatoria']);
        $this->curso->instructores()->attach($this->instructor->id, ['rol' => 'responsable']);
        $this->comoInstructor();

        $cambios = [];
        foreach (['objetivos' => 'objetivo', 'clases' => 'clase', 'tareas' => 'tarea', 'soporte' => 'soporte', 'procedimental' => 'procedimental'] as $lista => $tipo) {
            foreach ($this->ejemplo[$lista] as $c) {
                $cambios[] = ['uid' => $c['uid'], 'tipo' => $tipo, 'base_version' => 0, 'contenido' => $c];
            }
        }
        $this->postJson("/api/v1/courses/{$this->curso->id}/design/sync", ['cambios' => $cambios])->assertOk();
        // La aprobación (con verificador y Piston) se probó en la Etapa 3: aquí se da por hecha
        DesignElement::where('course_id', $this->curso->id)->update(['estado' => 'aprobado']);
    }

    private function comoInstructor(): void
    {
        Sanctum::actingAs($this->instructor, ['consola']);
    }

    private function publicar(): TestResponse
    {
        $this->comoInstructor();

        return $this->postJson("/api/v1/courses/{$this->curso->id}/releases", ['nota' => 'Primera', 'clave_idempotencia' => (string) Str::uuid()]);
    }

    private function estudiante(?string $grupo = null): Enrollment
    {
        $u = User::factory()->create();
        $e = Enrollment::create(['course_id' => $this->curso->id, 'user_id' => $u->id, 'estado' => EstadoInscripcion::ConPerfil, 'inscrito_at' => now()]);
        if ($grupo) {
            $g = DiffGroup::firstOrCreate(['course_id' => $this->curso->id, 'clave' => $grupo], ['nombre' => "Ruta {$grupo}", 'nivel' => 'basico', 'orden' => 1]);
            GroupMembership::create(['diff_group_id' => $g->id, 'enrollment_id' => $e->id, 'desde' => now()]);
        }

        return $e;
    }

    private function activar(array $activaciones): void
    {
        $this->comoInstructor();
        $this->putJson("/api/v1/courses/{$this->curso->id}/activations", ['activaciones' => $activaciones])->assertOk();
    }

    public function test_publicar_congela_lo_aprobado_y_se_puede_revertir(): void
    {
        $this->publicar()->assertCreated()->assertJsonPath('data.numero', 1);
        $this->assertSame(0, DesignElement::where('course_id', $this->curso->id)->where('estado', 'aprobado')->count());

        // Editar una tarea publicada no la saca de la siguiente versión: conserva la publicada
        DesignElement::where('uid', 'tc1-t2')->update(['estado' => 'borrador']);
        $this->publicar()->assertCreated()->assertJsonPath('data.numero', 2);
        $v2 = Release::vigente($this->curso->id);
        $this->assertContains('tc1-t2', array_column($v2->manifiesto['tareas'], 'uid'));

        $this->postJson("/api/v1/releases/{$v2->id}/rollback")->assertOk()->assertJsonPath('data.vigente.numero', 1);
        $this->postJson("/api/v1/releases/{$v2->id}/rollback")->assertStatus(409);
    }

    public function test_las_fechas_de_clases_de_otra_version_no_estorban_al_guardar_el_plan(): void
    {
        $this->publicar()->assertCreated();
        // Una clase de una versión anterior que ya no está en la vigente conserva sus fechas en la base
        Activation::create(['course_id' => $this->curso->id, 'clase_uid' => 'vieja', 'abre_at' => now()]);

        $plan = $this->getJson("/api/v1/courses/{$this->curso->id}/activations")->assertOk();
        $this->assertSame([], $plan->json('data.activaciones'));  // la consola no las recibe ni las reenvía

        $this->activar([['clase_uid' => 'tc1', 'abre_at' => now()->toIso8601String()]]);
        $this->assertDatabaseHas('activations', ['course_id' => $this->curso->id, 'clase_uid' => 'vieja']);  // por si se revierte
        $this->assertDatabaseHas('activations', ['course_id' => $this->curso->id, 'clase_uid' => 'tc1']);

        $this->putJson("/api/v1/courses/{$this->curso->id}/activations", ['activaciones' => [
            ['clase_uid' => 'vieja', 'abre_at' => now()->toIso8601String()],
        ]])->assertUnprocessable()->assertJsonValidationErrors(['activaciones.0' => '«vieja» no está en la publicación vigente']);
    }

    public function test_sin_fecha_no_se_ve_y_con_fecha_la_tarea_llega_sin_solucion(): void
    {
        $this->publicar()->assertCreated();
        $e = $this->estudiante();

        $this->actingAs($e->user)->get("/aula/{$this->curso->id}")
            ->assertInertia(fn (Pagina $p) => $p->component('aula/Mapa')->where('clases.0.estado', 'sin_programar'));
        $this->get("/aula/{$this->curso->id}/tareas/tc1-t3")->assertNotFound();

        $this->activar([['clase_uid' => 'tc1', 'abre_at' => now()->subHour()->toIso8601String()]]);
        $this->actingAs($e->user)->get("/aula/{$this->curso->id}/tareas/tc1-t3")
            ->assertInertia(fn (Pagina $p) => $p->component('aula/Tarea')
                ->where('tarea.uid', 'tc1-t3')->missing('tarea.solucion')->where('puedeEnviar', true));
        $this->assertSame(EstadoInscripcion::Cursando, $e->fresh()->estado);
    }

    public function test_ayudas_del_tema_y_tareas_por_ruta(): void
    {
        // Una ayuda del tema (cuelga de la clase, no de una tarea) y la tarea 1 solo para la Ruta A (G1)
        $tarjeta = [...$this->ejemplo['procedimental'][0], 'uid' => 'tc1-tema-p1', 'titulo' => 'Tarjeta de sintaxis', 'clase_uid' => 'tc1'];
        unset($tarjeta['tarea_uid']);
        $this->comoInstructor();
        $this->postJson("/api/v1/courses/{$this->curso->id}/design/sync", ['cambios' => [
            ['uid' => 'tc1-tema-p1', 'tipo' => 'procedimental', 'base_version' => 0, 'contenido' => $tarjeta],
        ]])->assertJsonPath('resultados.0.estado', 'ok');
        $t1 = DesignElement::where('uid', 'tc1-t1')->first();
        $t1->update(['contenido' => [...(array) json_decode(json_encode($t1->contenido), true), 'rutas' => ['G1']]]);
        DesignElement::where('course_id', $this->curso->id)->update(['estado' => 'aprobado']);
        $rutaA = $this->estudiante('G1');
        $rutaB = $this->estudiante('G2');
        $this->publicar()->assertCreated();
        $this->activar([['clase_uid' => 'tc1', 'abre_at' => now()->subHour()->toIso8601String()]]);

        $this->actingAs($rutaA->user)->get("/aula/{$this->curso->id}/clases/tc1")
            ->assertInertia(fn (Pagina $p) => $p->component('aula/Clase')
                ->where('procedimental.0.titulo', 'Tarjeta de sintaxis')
                ->where('tareas', fn ($t) => collect($t)->pluck('uid')->all() === ['tc1-t1', 'tc1-t2', 'tc1-t3'])
                ->where('tareas.0.rutas', ['G1'])->where('rutas.G1', 'Ruta G1'));
        // La ayuda del tema aparece también en cada tarea de la clase, después de las de la propia tarea
        $this->get("/aula/{$this->curso->id}/tareas/tc1-t2")
            ->assertInertia(fn (Pagina $p) => $p->where('ayudas', fn ($a) => collect($a)->pluck('uid')->all() === ['tc1-t2-p1', 'tc1-tema-p1']));

        // La Ruta B no ve la tarea 1 ni puede abrirla
        $this->actingAs($rutaB->user)->get("/aula/{$this->curso->id}/clases/tc1")
            ->assertInertia(fn (Pagina $p) => $p->where('tareas', fn ($t) => collect($t)->pluck('uid')->all() === ['tc1-t2', 'tc1-t3']));
        $this->get("/aula/{$this->curso->id}/tareas/tc1-t1")->assertNotFound();
    }

    public function test_la_fecha_del_grupo_gana_a_la_general(): void
    {
        $this->publicar()->assertCreated();
        $g1 = $this->estudiante('G1');
        $otro = $this->estudiante();
        $this->activar([
            ['clase_uid' => 'tc1', 'abre_at' => now()->subHour()->toIso8601String()],
            ['clase_uid' => 'tc1', 'grupo_clave' => 'G1', 'abre_at' => now()->addDay()->toIso8601String()],
        ]);

        $this->actingAs($g1->user)->get("/aula/{$this->curso->id}")->assertInertia(fn (Pagina $p) => $p->where('clases.0.estado', 'programada'));
        $this->actingAs($otro->user)->get("/aula/{$this->curso->id}")->assertInertia(fn (Pagina $p) => $p->where('clases.0.estado', 'abierta'));
    }

    public function test_enviar_califica_con_casos_ocultos_y_registra_eventos(): void
    {
        $this->publicar()->assertCreated();
        $e = $this->estudiante();
        $this->activar([['clase_uid' => 'tc1', 'abre_at' => now()->subHour()->toIso8601String()]]);
        $solucion = $this->ejemplo['tareas'][2]['solucion'];

        // En pruebas la cola es síncrona: el envío queda calificado al responder
        $this->actingAs($e->user)->postJson("/aula/{$this->curso->id}/tareas/tc1-t3/envios", ['codigo' => 'int main(){}'])->assertStatus(202);
        $id = $this->postJson("/aula/{$this->curso->id}/tareas/tc1-t3/envios", ['codigo' => $solucion])->assertStatus(202)->json('id');

        $this->getJson("/aula/envios/{$id}")->assertOk()->assertJsonPath('estado', 'calificado')->assertJsonPath('fraccion', 1.0);
        $p = TaskProgress::where('enrollment_id', $e->id)->where('tarea_uid', 'tc1-t3')->first();
        $this->assertSame(['completada', 2, 1.0], [$p->estado, $p->intentos, $p->mejor_fraccion]);
        $this->assertSame(2, TaskSubmission::where('enrollment_id', $e->id)->count());
        $this->assertSame(4, LearningEvent::where('enrollment_id', $e->id)->whereIn('verbo', ['envio', 'calificado'])->count());

        // Un ejemplo resuelto no se envía; y nadie más puede ver el envío
        $this->postJson("/aula/{$this->curso->id}/tareas/tc1-t1/envios", ['codigo' => 'x'])->assertStatus(422);
        $this->actingAs($this->estudiante()->user)->getJson("/aula/envios/{$id}")->assertForbidden();
    }

    public function test_lote_de_eventos_solo_con_verbos_del_cliente(): void
    {
        $this->publicar()->assertCreated();
        $e = $this->estudiante();
        $url = "/aula/{$this->curso->id}/eventos";
        $evento = ['verbo' => 'consulto_ayuda', 'objeto_tipo' => 'ayuda', 'objeto_uid' => 'tc1-t2-p1', 'ocurrido_at' => now()->toIso8601String()];

        $this->actingAs($e->user)->postJson($url, ['eventos' => [$evento, [...$evento, 'verbo' => 'tiempo_visible', 'duracion_ms' => 42000]]])->assertNoContent();
        $this->postJson($url, ['eventos' => [[...$evento, 'verbo' => 'calificado']]])->assertUnprocessable();
        $this->assertSame(2, LearningEvent::where('enrollment_id', $e->id)->where('origen', 'cliente')->count());
    }

    public function test_aviso_de_apertura_una_sola_vez(): void
    {
        Notification::fake();
        $this->publicar()->assertCreated();
        $e = $this->estudiante();
        $this->activar([['clase_uid' => 'tc1', 'abre_at' => now()->subMinute()->toIso8601String()]]);

        $this->artisan('aula:avisar-aperturas')->assertSuccessful();
        $this->artisan('aula:avisar-aperturas')->assertSuccessful();
        Notification::assertSentToTimes($e->user, ClaseDisponible::class, 1);
    }
}
