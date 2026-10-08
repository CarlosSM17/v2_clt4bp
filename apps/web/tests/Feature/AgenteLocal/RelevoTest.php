<?php

namespace Tests\Feature\AgenteLocal;

use App\Models\AgentRelayRequest;
use App\Services\Agente\ClienteAgente;
use App\Services\Agente\Relevo;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** El agente en el equipo del instructor y la plataforma en la nube: las llamadas viajan por el relevo (ADR 0008). */
class RelevoTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = ['X-Agente-Token' => 'secreto'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.agente.modo' => 'relevo', 'services.agente.token' => 'secreto']);
        Storage::fake('local');
    }

    public function test_sin_el_secreto_del_agente_el_conector_recibe_403(): void
    {
        $solicitud = app(Relevo::class)->crear('GET', '/v1/plantillas', null, 60);
        foreach ([[], ['X-Agente-Token' => 'otro']] as $cabeceras) {
            $this->getJson('/api/v1/relevo/siguiente?espera=0', $cabeceras)->assertForbidden();
            $this->getJson("/api/v1/relevo/{$solicitud->id}/archivo", $cabeceras)->assertForbidden();
            $this->postJson("/api/v1/relevo/{$solicitud->id}/respuesta", ['estado' => 200, 'cuerpo' => '{}'], $cabeceras)->assertForbidden();
        }
        $this->assertSame('pendiente', $solicitud->fresh()->estado); // nadie la tocó

        // Sin modo relevo, las rutas no existen; sin secreto configurado, nadie entra
        config(['services.agente.modo' => 'directo']);
        $this->getJson('/api/v1/relevo/siguiente?espera=0', self::TOKEN)->assertNotFound();
        config(['services.agente.modo' => 'relevo', 'services.agente.token' => '']);
        $this->getJson('/api/v1/relevo/siguiente?espera=0', ['X-Agente-Token' => ''])->assertForbidden();
    }

    public function test_una_llamada_va_y_vuelve_por_el_relevo_con_el_json_intacto(): void
    {
        $relevo = app(Relevo::class);
        $solicitud = $relevo->crear('POST', '/v1/verificar', ['diseno' => ['tareas' => []], 'codigo' => (object) []], 60);

        $r = $this->getJson('/api/v1/relevo/siguiente?espera=0', self::TOKEN)->assertOk()
            ->assertJson(['id' => $solicitud->id, 'metodo' => 'POST', 'ruta' => '/v1/verificar', 'archivo' => null]);
        // El cuerpo llega como texto, sin pasar por arreglos de PHP: {} sigue siendo {} (el agente lo exige)
        $this->assertSame('{"diseno":{"tareas":[]},"codigo":{}}', $r->json('cuerpo'));
        $this->assertSame('reclamada', $solicitud->fresh()->estado);
        $this->getJson('/api/v1/relevo/siguiente?espera=0', self::TOKEN)->assertNoContent(); // ya es de alguien

        $this->postJson("/api/v1/relevo/{$solicitud->id}/respuesta", ['estado' => 200, 'cuerpo' => '{"errores":0,"semaforo":{}}'], self::TOKEN)
            ->assertNoContent();
        $this->postJson("/api/v1/relevo/{$solicitud->id}/respuesta", ['estado' => 200, 'cuerpo' => '{}'], self::TOKEN)
            ->assertStatus(409); // una sola respuesta

        $respuesta = $relevo->esperar($solicitud, 1);
        $this->assertSame(200, $respuesta->status());
        $this->assertEquals((object) ['errores' => 0, 'semaforo' => (object) []], $respuesta->object());
        $this->assertSame(0, AgentRelayRequest::count()); // leída la respuesta, no se guarda el diseño ni el resultado
    }

    public function test_sin_conector_lo_interactivo_falla_en_el_acto_y_lo_vencido_se_retira(): void
    {
        $antes = microtime(true);
        try {
            app(ClienteAgente::class)->plantillas();
            $this->fail('Sin conector no debe esperar.');
        } catch (ConnectionException $e) {
            $this->assertStringContainsString('agente local conectado', $e->getMessage());
        }
        $this->assertLessThan(1, microtime(true) - $antes);
        $this->assertSame(0, AgentRelayRequest::count());

        // Con el conector visto pero sin respuesta a tiempo: error de conexión (la cola reintenta) y la solicitud se va
        $relevo = app(Relevo::class);
        $relevo->marcarVisto();
        $solicitud = $relevo->crear('POST', '/v1/generar', ['plantilla' => 'objetivos'], 1);
        $this->expectException(ConnectionException::class);
        $this->expectExceptionMessage('no respondió');
        try {
            $relevo->esperar($solicitud, 0, pausaMs: 1);
        } finally {
            $this->assertSame(0, AgentRelayRequest::count());
        }
    }

    public function test_el_documento_del_material_viaja_y_se_borra_al_terminar(): void
    {
        $relevo = app(Relevo::class);
        $flujo = fopen('php://memory', 'r+');
        fwrite($flujo, '%PDF-1.7 apuntes');
        rewind($flujo);
        $solicitud = $relevo->crear('POST', '/v1/documentos/procesar', null, 60, [$flujo, 'apuntes.pdf']);
        Storage::disk('local')->assertExists($solicitud->archivo);

        // Antes de reclamarla, nadie la descarga
        $this->get("/api/v1/relevo/{$solicitud->id}/archivo", self::TOKEN)->assertNotFound();
        $this->getJson('/api/v1/relevo/siguiente?espera=0', self::TOKEN)->assertJsonPath('archivo', 'apuntes.pdf');
        $this->assertSame('%PDF-1.7 apuntes', $this->get("/api/v1/relevo/{$solicitud->id}/archivo", self::TOKEN)->streamedContent());

        $this->postJson("/api/v1/relevo/{$solicitud->id}/respuesta", ['estado' => 422, 'cuerpo' => '{"detail":"Sin texto extraíble."}'], self::TOKEN);
        $respuesta = $relevo->esperar($solicitud, 1);
        $this->assertSame(422, $respuesta->status());
        Storage::disk('local')->assertMissing($solicitud->archivo);
    }

    public function test_el_cliente_del_agente_usa_el_relevo_con_la_misma_semantica_que_en_directo(): void
    {
        $relevo = new class extends Relevo
        {
            public array $llamadas = [];

            public function enviar(string $metodo, string $ruta, ?array $cuerpo, int $timeout, ?array $archivo = null, bool $interactivo = false): Response
            {
                $this->llamadas[] = compact('metodo', 'ruta', 'timeout', 'interactivo') + ['archivo' => $archivo[1] ?? null];
                $estado = $ruta === '/v1/trazas/completar' ? 422 : 200;

                return new Response(new Psr7Response($estado, [], json_encode($ruta === '/v1/embeddings' ? ['embeddings' => [[0.1]]] : ['modelo' => 'qwen3:4b', 'resultados' => (object) []])));
            }
        };
        config(['services.agente.timeout' => 900]);
        $cliente = new ClienteAgente($relevo);

        $this->assertEquals((object) [], $cliente->generar(['plantilla' => 'objetivos'])->resultados); // {} intacto
        $this->assertSame([[0.1]], $cliente->embeddings(['tema']));
        $this->expectException(RequestException::class); // un 422 del agente se lanza igual que en directo
        try {
            $cliente->completarTraza('c', 'titulo: x');
        } finally {
            // La generación y el material esperan al conector; lo que alguien mira en pantalla, no
            $this->assertSame([
                ['metodo' => 'POST', 'ruta' => '/v1/generar', 'timeout' => 900, 'interactivo' => false, 'archivo' => null],
                ['metodo' => 'POST', 'ruta' => '/v1/embeddings', 'timeout' => 60, 'interactivo' => false, 'archivo' => null],
                ['metodo' => 'POST', 'ruta' => '/v1/trazas/completar', 'timeout' => 90, 'interactivo' => true, 'archivo' => null],
            ], $relevo->llamadas);
        }
    }

    public function test_las_solicitudes_de_mas_de_un_dia_se_podan_con_su_archivo(): void
    {
        $flujo = fopen('php://memory', 'r+');
        fwrite($flujo, 'pdf');
        rewind($flujo);
        $vieja = app(Relevo::class)->crear('POST', '/v1/documentos/procesar', null, 60, [$flujo, 'a.pdf']);
        $vieja->forceFill(['created_at' => now()->subDays(2)])->save();
        $nueva = app(Relevo::class)->crear('GET', '/v1/plantillas', null, 60);

        $this->artisan('model:prune', ['--model' => [AgentRelayRequest::class]])->assertSuccessful();

        $this->assertSame([$nueva->id], AgentRelayRequest::pluck('id')->all());
        Storage::disk('local')->assertMissing($vieja->archivo);
    }
}
