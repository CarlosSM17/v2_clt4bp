<?php

namespace Tests\Feature\Etapa4;

use App\Jobs\EjecutarTrabajoAgente;
use App\Models\AgentJob;
use App\Models\AgentQuota;
use App\Models\Course;
use App\Models\DesignElement;
use App\Models\User;
use App\Services\Agente\ClienteAgente;
use App\Services\Agente\ContextoAgente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreaUsuarios;
use Tests\TestCase;

class AgenteTest extends TestCase
{
    use CreaUsuarios, RefreshDatabase;

    private Course $curso;

    private User $instructor;

    private array $ejemplo;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.agente.url' => 'http://agente.test', 'services.agente.token' => 'secreto', 'clt4bp.agente.limite_mensual_usd' => 20]);
        $this->instructor = $this->instructor();
        $this->curso = Course::create(['owner_id' => $this->instructor->id, 'titulo' => 'C', 'lenguaje' => 'c', 'nivel_educativo' => 'preparatoria']);
        $this->curso->instructores()->attach($this->instructor->id, ['rol' => 'responsable']);
        Sanctum::actingAs($this->instructor, ['consola']);

        // Diseño de ejemplo en el servidor
        $this->ejemplo = json_decode(file_get_contents(base_path('../../packages/contracts/examples/diseno-curso/clase-recorridos.json')), true);
        $cambios = [];
        foreach (['objetivos' => 'objetivo', 'clases' => 'clase', 'tareas' => 'tarea', 'soporte' => 'soporte'] as $lista => $tipo) {
            foreach ($this->ejemplo[$lista] as $c) {
                $cambios[] = ['uid' => $c['uid'], 'tipo' => $tipo, 'base_version' => 0, 'contenido' => $c];
            }
        }
        $this->postJson("/api/v1/courses/{$this->curso->id}/design/sync", ['cambios' => $cambios])->assertOk();
    }

    private function solicitar(array $datos = []): TestResponse
    {
        return $this->postJson("/api/v1/courses/{$this->curso->id}/agent/jobs", [
            'plantilla' => 'info_procedimental',
            'alcance' => ['tarea_uid' => 'tc1-t3'],
            'clave_idempotencia' => (string) Str::uuid(),
            ...$datos,
        ]);
    }

    private function respuestaAgente(): array
    {
        $ayuda = [...$this->ejemplo['procedimental'][0], 'uid' => 'j1-p1', 'tarea_uid' => 'tc1-t3'];

        return [
            'plantilla' => 'info_procedimental',
            'elementos' => [['tipo' => 'procedimental', 'contenido' => $ayuda]],
            'notas' => new \stdClass,
            'advertencias' => [],
            'validaciones' => [['nombre' => 'validaciones', 'ok' => true, 'bloqueante' => true, 'detalle' => 'Sin problemas.', 'elemento_uid' => null]],
            'intentos' => 1,
            'uso' => ['entrada' => 5200, 'salida' => 900, 'cache_escritura' => 0, 'cache_lectura' => 4100, 'costo_usd' => 0.02012],
            'modelo' => 'claude-sonnet-5',
            'version_prompt' => 'sistema-v1',
            'duracion_ms' => 18000,
        ];
    }

    public function test_encola_una_vez_aunque_la_consola_repita_la_peticion(): void
    {
        Queue::fake();
        $clave = (string) Str::uuid();
        $id = $this->solicitar(['clave_idempotencia' => $clave])->assertStatus(202)->assertJsonPath('data.estado', 'en_cola')->json('data.id');
        $this->solicitar(['clave_idempotencia' => $clave])->assertOk()->assertJsonPath('data.id', $id);

        Queue::assertPushedOn('agente', EjecutarTrabajoAgente::class);
        Queue::assertPushed(EjecutarTrabajoAgente::class, 1);
    }

    public function test_alcance_invalido_y_cuota_agotada_no_encolan(): void
    {
        Queue::fake();
        $this->solicitar(['alcance' => ['tarea_uid' => 'no-existe']])->assertStatus(422)->assertJsonValidationErrors('alcance');

        AgentQuota::delMes($this->instructor)->update(['usado_usd' => 20]);
        $this->solicitar()->assertStatus(429);
        Queue::assertNothingPushed();
    }

    public function test_el_trabajo_guarda_resultado_metricas_y_gasto_sin_datos_personales(): void
    {
        Queue::fake();
        $id = $this->solicitar()->json('data.id');
        Http::fake(['agente.test/v1/generar' => Http::response($this->respuestaAgente())]);

        (new EjecutarTrabajoAgente($id))->handle(app(ClienteAgente::class), app(ContextoAgente::class));

        $trabajo = AgentJob::find($id);
        $this->assertSame('listo', $trabajo->estado);
        $this->assertSame(0.02012, $trabajo->corrida->costo_usd);
        $this->assertEqualsWithDelta(0.02012, AgentQuota::delMes($this->instructor)->usado_usd, 1e-9);

        Http::assertSent(function (PeticionHttp $r) use ($id) {
            $datos = $r->data();
            // 'alcance' viaja como (object) en ContextoAgente (para que un arreglo vacío se serialice
            // como {} y no como [] hacia el esquema de FastAPI); Laravel conserva ese valor tal cual.
            $alcance = (array) $datos['alcance'];

            return $r->hasHeader('X-Agente-Token', 'secreto')
                && $alcance['prefijo'] === "j{$id}"
                && count($datos['diseno']['tareas']) === 3
                && ! str_contains($r->body(), $this->instructor->email); // nada de correos ni nombres
        });

        // La consola recibe {} como objeto, no como []
        $this->getJson("/api/v1/agent/jobs/{$id}")->assertOk()
            ->assertJsonPath('data.estado', 'listo')
            ->assertJsonPath('data.resultado.elementos.0.contenido.uid', 'j1-p1');
        $this->assertStringContainsString('"notas":{}', $this->getJson("/api/v1/agent/jobs/{$id}")->getContent());
    }

    public function test_decision_y_procedencia_del_elemento_aceptado(): void
    {
        Queue::fake();
        $id = $this->solicitar()->json('data.id');
        Http::fake(['agente.test/v1/generar' => Http::response($this->respuestaAgente())]);
        (new EjecutarTrabajoAgente($id))->handle(app(ClienteAgente::class), app(ContextoAgente::class));
        $corrida = AgentJob::find($id)->corrida;

        // La consola guarda el elemento aceptado y lo sube con la corrida de origen
        $ayuda = $this->respuestaAgente()['elementos'][0]['contenido'];
        $this->postJson("/api/v1/courses/{$this->curso->id}/design/sync", ['cambios' => [
            ['uid' => 'j1-p1', 'tipo' => 'procedimental', 'base_version' => 0, 'contenido' => $ayuda, 'agent_run_id' => $corrida->id],
        ]])->assertJsonPath('resultados.0.estado', 'ok');

        $this->postJson("/api/v1/agent/jobs/{$id}/decision", ['decision' => 'aceptado', 'uids' => ['j1-p1']])->assertOk();

        $elemento = DesignElement::where('uid', 'j1-p1')->first();
        $this->assertSame(['agente', $corrida->id], [$elemento->autor_tipo, $elemento->agent_run_id]);
        $this->assertSame('aceptado', $corrida->fresh()->decision);
    }

    public function test_otro_instructor_no_ve_el_trabajo(): void
    {
        Queue::fake();
        $id = $this->solicitar()->json('data.id');

        Sanctum::actingAs($this->instructor(), ['consola']);
        $this->getJson("/api/v1/agent/jobs/{$id}")->assertForbidden();
        $this->deleteJson("/api/v1/agent/jobs/{$id}")->assertForbidden();
        $this->assertDatabaseHas('agent_jobs', ['id' => $id]);
    }

    public function test_eliminar_una_propuesta_conserva_lo_guardado_en_el_diseno(): void
    {
        Queue::fake();
        $id = $this->solicitar()->json('data.id');
        $this->deleteJson("/api/v1/agent/jobs/{$id}")->assertStatus(409);  // en cola: todavía se está generando

        Http::fake(['agente.test/v1/generar' => Http::response($this->respuestaAgente())]);
        (new EjecutarTrabajoAgente($id))->handle(app(ClienteAgente::class), app(ContextoAgente::class));
        $corrida = AgentJob::find($id)->corrida;
        $ayuda = $this->respuestaAgente()['elementos'][0]['contenido'];
        $this->postJson("/api/v1/courses/{$this->curso->id}/design/sync", ['cambios' => [
            ['uid' => 'j1-p1', 'tipo' => 'procedimental', 'base_version' => 0, 'contenido' => $ayuda, 'agent_run_id' => $corrida->id],
        ]])->assertJsonPath('resultados.0.estado', 'ok');
        $this->postJson("/api/v1/agent/jobs/{$id}/decision", ['decision' => 'aceptado', 'uids' => ['j1-p1']])->assertOk();
        $this->getJson("/api/v1/courses/{$this->curso->id}/agent/jobs")->assertJsonPath('data.0.decision', 'aceptado');

        $this->deleteJson("/api/v1/agent/jobs/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('agent_jobs', ['id' => $id]);
        $this->assertDatabaseMissing('agent_runs', ['id' => $corrida->id]);
        $this->assertNull(DesignElement::where('uid', 'j1-p1')->first()->agent_run_id);  // el elemento guardado se queda
        $this->getJson("/api/v1/courses/{$this->curso->id}/agent/jobs")->assertJsonCount(0, 'data');
    }
}
