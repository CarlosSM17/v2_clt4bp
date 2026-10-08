<?php

namespace Tests\Feature\AgenteLocal;

use App\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreaUsuarios;
use Tests\TestCase;

/** «Calcular pasos» de la consola: los pasos de una traza salen de ejecutar el programa (agente → trazador). */
class TrazaTest extends TestCase
{
    use CreaUsuarios, RefreshDatabase;

    private const BLOQUE = "titulo: Suma\n---\nint main(void) { return 0; }";

    private Course $curso;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.agente.url' => 'http://agente.test', 'services.agente.token' => 'secreto']);
        $instructor = $this->instructor();
        $this->curso = Course::create(['owner_id' => $instructor->id, 'titulo' => 'C', 'lenguaje' => 'cpp', 'nivel_educativo' => 'universidad']);
        $this->curso->instructores()->attach($instructor->id, ['rol' => 'responsable']);
        Sanctum::actingAs($instructor, ['consola']);
    }

    public function test_devuelve_el_bloque_con_los_pasos_reales(): void
    {
        Http::fake(['agente.test/v1/trazas/completar' => Http::response(['bloque' => self::BLOQUE."\n---\n{\"l\":1}"])]);

        $this->postJson("/api/v1/courses/{$this->curso->id}/trazas", ['bloque' => self::BLOQUE])
            ->assertOk()->assertJsonPath('data.bloque', self::BLOQUE."\n---\n{\"l\":1}");

        // Sin lenguaje en la petición se usa el del curso
        Http::assertSent(fn (PeticionHttp $r) => $r['lenguaje'] === 'cpp' && $r['bloque'] === self::BLOQUE && $r->hasHeader('X-Agente-Token', 'secreto'));
    }

    public function test_el_motivo_de_que_no_se_pueda_trazar_llega_a_la_consola(): void
    {
        Http::fake(['agente.test/*' => Http::response(['detail' => 'No se pudo trazar: no compila: falta ;'], 422)]);

        $this->postJson("/api/v1/courses/{$this->curso->id}/trazas", ['bloque' => self::BLOQUE, 'lenguaje' => 'c'])
            ->assertStatus(422)->assertJsonPath('message', 'No se pudo trazar: no compila: falta ;');
    }

    public function test_solo_el_equipo_docente_del_curso_puede_trazar(): void
    {
        Http::fake();
        foreach ([$this->instructor(), $this->estudiante()] as $ajeno) {
            Sanctum::actingAs($ajeno, ['consola']);
            $this->postJson("/api/v1/courses/{$this->curso->id}/trazas", ['bloque' => self::BLOQUE])->assertForbidden();
        }
        Http::assertNothingSent();
    }
}
