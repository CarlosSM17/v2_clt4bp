<?php

namespace Tests\Feature\Etapa3;

use App\Models\Course;
use App\Models\DesignElement;
use App\Services\Codigo\EjecutorCodigo;
use App\Services\Codigo\ResultadoEjecucion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreaUsuarios;
use Tests\TestCase;

class VerificacionDisenoTest extends TestCase
{
    use CreaUsuarios, RefreshDatabase;

    private Course $curso;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.agente.url' => 'http://agente.test', 'services.agente.token' => 'secreto']);

        // Piston falso: siempre imprime "3"; solo el primer caso de la tarea 3 espera eso
        $this->app->bind(EjecutorCodigo::class, fn () => new class implements EjecutorCodigo
        {
            public function ejecutar(string $lenguaje, string $codigo, string $entrada = '', int $limiteMs = 3000): ResultadoEjecucion
            {
                return new ResultadoEjecucion(true, "3\n", '', 0, false);
            }
        });

        $instructor = $this->instructor();
        $this->curso = Course::create(['owner_id' => $instructor->id, 'titulo' => 'C', 'lenguaje' => 'c', 'nivel_educativo' => 'preparatoria']);
        $this->curso->instructores()->attach($instructor->id, ['rol' => 'responsable']);
        Sanctum::actingAs($instructor, ['consola']);

        $ejemplo = json_decode(file_get_contents(base_path('../../packages/contracts/examples/diseno-curso/clase-recorridos.json')), true);
        $cambios = [];
        foreach (['objetivos' => 'objetivo', 'clases' => 'clase', 'tareas' => 'tarea', 'soporte' => 'soporte', 'procedimental' => 'procedimental'] as $lista => $tipo) {
            foreach ($ejemplo[$lista] as $contenido) {
                $cambios[] = ['uid' => $contenido['uid'], 'tipo' => $tipo, 'base_version' => 0, 'contenido' => $contenido];
            }
        }
        $this->postJson("/api/v1/courses/{$this->curso->id}/design/sync", ['cambios' => $cambios])->assertOk();
    }

    private function agenteResponde(array $hallazgos): void
    {
        Http::fake(['agente.test/*' => Http::response([
            'hallazgos' => $hallazgos,
            'errores' => count(array_filter($hallazgos, fn ($h) => $h['nivel'] === 'error')),
            'advertencias' => 0,
            'semaforo' => [],
        ])]);
    }

    public function test_envia_al_agente_el_diseno_y_los_resultados_de_piston(): void
    {
        $this->agenteResponde([]);
        $this->postJson("/api/v1/courses/{$this->curso->id}/design/verify")->assertOk();
        Http::assertSent(function (PeticionHttp $r) {
            $datos = $r->data();
            // 'codigo' viaja como (object) en ClienteAgente (para que un arreglo vacío
            // se serialice como {} y no como [] hacia el esquema de FastAPI); Laravel
            // conserva ese valor tal cual en las peticiones falseadas.
            $codigo = (array) $datos['codigo'];

            return $r->hasHeader('X-Agente-Token', 'secreto')
                && count($datos['diseno']['tareas']) === 3
                && $codigo['tc1-t3'] === ['aprobados' => 1, 'total' => 2, 'error_compilacion' => null];
        });
    }

    public function test_un_error_impide_aprobar_y_sin_errores_se_aprueba(): void
    {
        $this->agenteResponde([['regla' => 'tarea_sin_arcs', 'nivel' => 'error', 'elemento_uid' => 'tc1-t2', 'mensaje' => 'Faltan ARCS', 'grupo' => null]]);
        $this->postJson("/api/v1/courses/{$this->curso->id}/design/approve", ['uids' => ['tc1-t2']])
            ->assertStatus(422)->assertJsonPath('hallazgos.0.regla', 'tarea_sin_arcs');

        $seqAntes = DesignElement::where('uid', 'tc1-t1')->value('seq');
        $this->postJson("/api/v1/courses/{$this->curso->id}/design/approve", ['uids' => ['tc1-t1']])->assertOk();
        $t1 = DesignElement::where('uid', 'tc1-t1')->first();
        $this->assertSame('aprobado', $t1->estado);
        $this->assertSame(1, $t1->version);                 // aprobar no crea versión nueva…
        $this->assertGreaterThan($seqAntes, $t1->seq);      // …pero las consolas se enteran
    }
}
