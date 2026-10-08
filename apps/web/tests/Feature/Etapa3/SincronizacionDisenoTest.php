<?php

namespace Tests\Feature\Etapa3;

use App\Models\Course;
use App\Models\DesignElement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreaUsuarios;
use Tests\TestCase;

class SincronizacionDisenoTest extends TestCase
{
    use CreaUsuarios, RefreshDatabase;

    private Course $curso;

    private array $ejemplo;

    protected function setUp(): void
    {
        parent::setUp();
        $instructor = $this->instructor();
        $this->curso = Course::create(['owner_id' => $instructor->id, 'titulo' => 'C', 'lenguaje' => 'c', 'nivel_educativo' => 'preparatoria']);
        $this->curso->instructores()->attach($instructor->id, ['rol' => 'responsable']);
        Sanctum::actingAs($instructor, ['consola']);
        $this->ejemplo = json_decode(file_get_contents(base_path('../../packages/contracts/examples/diseno-curso/clase-recorridos.json')), true);
    }

    private function subir(array $cambios): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/v1/courses/{$this->curso->id}/design/sync", ['cambios' => $cambios]);
    }

    private function clase(int $base = 0, array $extra = []): array
    {
        return ['uid' => 'tc1', 'tipo' => 'clase', 'base_version' => $base, 'contenido' => [...$this->ejemplo['clases'][0], ...$extra]];
    }

    public function test_crear_y_descargar_desde_el_cursor(): void
    {
        $tarea = $this->ejemplo['tareas'][0];
        $r = $this->subir([$this->clase(), ['uid' => $tarea['uid'], 'tipo' => 'tarea', 'base_version' => 0, 'contenido' => $tarea]])
            ->assertOk()->assertJsonPath('resultados.0.estado', 'ok')->assertJsonPath('resultados.1.version', 1);

        $this->assertSame('tc1', DesignElement::where('uid', 'tc1-t1')->value('padre_uid'));

        $todo = $this->getJson("/api/v1/courses/{$this->curso->id}/design?desde=0")->assertOk()->json();
        $this->assertCount(2, $todo['cambios']);
        $this->assertSame($r->json('cursor'), $todo['cursor']);

        $nada = $this->getJson("/api/v1/courses/{$this->curso->id}/design?desde={$todo['cursor']}")->json();
        $this->assertCount(0, $nada['cambios']);
    }

    public function test_version_vieja_produce_conflicto_con_la_version_del_servidor(): void
    {
        $this->subir([$this->clase()]);
        $this->subir([$this->clase(1, ['titulo' => 'Cambio desde la laptop'])])->assertJsonPath('resultados.0.version', 2);

        // Otra computadora todavía trabajaba sobre la versión 1
        $this->subir([$this->clase(1, ['titulo' => 'Cambio desde la PC de la escuela'])])
            ->assertJsonPath('resultados.0.estado', 'conflicto')
            ->assertJsonPath('resultados.0.servidor.version', 2)
            ->assertJsonPath('resultados.0.servidor.contenido.titulo', 'Cambio desde la laptop');
    }

    public function test_reintentar_el_mismo_cambio_no_duplica_versiones(): void
    {
        $this->subir([$this->clase()]);
        $this->subir([$this->clase()])->assertJsonPath('resultados.0.estado', 'ok');
        $this->assertSame(1, DesignElement::where('uid', 'tc1')->value('version'));
    }

    public function test_contenido_que_no_cumple_el_esquema_se_rechaza(): void
    {
        $mala = $this->ejemplo['tareas'][0];
        $mala['nivel_apoyo'] = 'mucho';
        $this->subir([['uid' => $mala['uid'], 'tipo' => 'tarea', 'base_version' => 0, 'contenido' => $mala]])
            ->assertJsonPath('resultados.0.estado', 'invalido');
        $this->assertSame(0, DesignElement::count());
    }

    public function test_eliminar_deja_una_lapida_visible_para_las_demas_consolas(): void
    {
        $this->subir([$this->clase()]);
        $this->subir([['uid' => 'tc1', 'tipo' => 'clase', 'base_version' => 1, 'eliminar' => true]])->assertJsonPath('resultados.0.version', 2);

        $cambios = $this->getJson("/api/v1/courses/{$this->curso->id}/design?desde=0")->json('cambios');
        $this->assertTrue($cambios[0]['eliminado']);
    }

    public function test_otro_instructor_no_puede_leer_ni_escribir(): void
    {
        Sanctum::actingAs($this->instructor(), ['consola']);
        $this->getJson("/api/v1/courses/{$this->curso->id}/design")->assertForbidden();
        $this->subir([$this->clase()])->assertForbidden();
    }
}
