<?php

namespace Tests\Feature\AgenteLocal;

use App\Jobs\EjecutarTrabajoAgente;
use App\Jobs\ProcesarDocumento;
use App\Models\AgentJob;
use App\Models\Course;
use App\Models\CourseDocument;
use App\Models\DocumentFragment;
use App\Models\User;
use App\Services\Agente\BuscadorMaterial;
use App\Services\Agente\ClienteAgente;
use App\Services\Agente\ContextoAgente;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as PeticionHttp;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreaUsuarios;
use Tests\TestCase;

/** Material del curso (RAG local): subida, indexado en pgvector, búsqueda por curso y paso al agente. */
class MaterialTest extends TestCase
{
    use CreaUsuarios, RefreshDatabase;

    private Course $curso;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.agente.url' => 'http://agente.test', 'services.agente.token' => 'secreto']);
        Storage::fake('local');
        [$this->curso, $this->instructor] = $this->cursoConInstructor('Programación en C');
        Sanctum::actingAs($this->instructor, ['consola']);
    }

    private function cursoConInstructor(string $titulo): array
    {
        $instructor = $this->instructor();
        $curso = Course::create(['owner_id' => $instructor->id, 'titulo' => $titulo, 'lenguaje' => 'c', 'nivel_educativo' => 'universidad']);
        $curso->instructores()->attach($instructor->id, ['rol' => 'responsable']);

        return [$curso, $instructor];
    }

    /** Vector unitario sobre un eje: la distancia coseno entre ejes distintos es 1, sobre el mismo es 0. */
    private function vector(int $eje, float $mezcla = 0.0, int $otro = 0): array
    {
        $v = array_fill(0, 1024, 0.0);
        $v[$eje] = 1.0;
        $v[$otro] += $mezcla;

        return $v;
    }

    private function documento(Course $curso, string $titulo, array $fragmentos, string $estado = 'listo'): CourseDocument
    {
        $doc = CourseDocument::create([
            'course_id' => $curso->id, 'titulo' => $titulo, 'nombre_original' => "{$titulo}.pdf", 'ruta' => "x/{$titulo}.pdf",
            'mime' => 'application/pdf', 'bytes' => 10, 'sha256' => hash('sha256', $titulo.$curso->id), 'estado' => $estado,
            'fragmentos' => count($fragmentos), 'subido_por' => $curso->owner_id,
        ]);
        foreach ($fragmentos as $i => [$texto, $eje]) {
            DocumentFragment::create([
                'course_document_id' => $doc->id, 'course_id' => $curso->id, 'orden' => $i + 1, 'pagina' => $i + 1,
                'texto' => $texto, 'embedding' => json_encode($this->vector($eje)),
            ]);
        }

        return $doc;
    }

    private function subir(string $nombre, string $contenido, ?Course $curso = null): TestResponse
    {
        $curso ??= $this->curso;

        return $this->post("/api/v1/courses/{$curso->id}/documents",
            ['archivo' => UploadedFile::fake()->createWithContent($nombre, $contenido)], ['Accept' => 'application/json']);
    }

    // ---------- Subida ----------

    public function test_el_instructor_sube_material_y_se_encola_su_indexado(): void
    {
        Queue::fake();

        $this->subir('Apuntes de arreglos.md', "# Arreglos\n\nUn arreglo guarda datos contiguos.")
            ->assertCreated()
            ->assertJsonPath('data.titulo', 'Apuntes de arreglos')
            ->assertJsonPath('data.estado', 'procesando');

        $doc = CourseDocument::sole();
        Storage::disk('local')->assertExists($doc->ruta);
        Queue::assertPushedOn('agente', ProcesarDocumento::class);
        $this->getJson("/api/v1/courses/{$this->curso->id}/documents")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_el_mismo_archivo_no_se_indexa_dos_veces_y_solo_se_admite_texto(): void
    {
        Queue::fake();
        $this->subir('a.txt', 'Punteros en C')->assertCreated();
        $this->subir('copia.txt', 'Punteros en C')->assertStatus(409);
        $this->subir('pagina.html', '<script>alert(1)</script>')->assertStatus(422)->assertJsonValidationErrors('archivo');
        $this->assertSame(1, CourseDocument::count());
    }

    public function test_solo_el_equipo_docente_del_curso_ve_sube_o_borra_material(): void
    {
        Queue::fake();
        $doc = $this->documento($this->curso, 'Apuntes', [['Texto', 1]]);

        foreach ([$this->instructor(), $this->estudiante()] as $ajeno) {
            Sanctum::actingAs($ajeno, ['consola']);
            $this->getJson("/api/v1/courses/{$this->curso->id}/documents")->assertForbidden();
            $this->subir('b.txt', 'Recursión')->assertForbidden();
            $this->deleteJson("/api/v1/courses/{$this->curso->id}/documents/{$doc->id}")->assertForbidden();
        }
        $this->assertSame(1, CourseDocument::count());
    }

    public function test_borrar_un_documento_quita_su_archivo_y_sus_fragmentos(): void
    {
        $doc = $this->documento($this->curso, 'Apuntes', [['Uno', 1], ['Dos', 2]]);
        Storage::disk('local')->put($doc->ruta, 'x');

        $this->deleteJson("/api/v1/courses/{$this->curso->id}/documents/{$doc->id}")->assertNoContent();

        $this->assertSame(0, DocumentFragment::count());
        Storage::disk('local')->assertMissing($doc->ruta);
    }

    // ---------- Indexado ----------

    public function test_el_indexado_guarda_los_fragmentos_con_su_vector(): void
    {
        Queue::fake();
        $this->subir('apuntes.md', "# Arreglos\n\nTexto")->assertCreated();
        $doc = CourseDocument::sole();
        Http::fake(['agente.test/v1/documentos/procesar' => Http::response(['modelo' => 'bge-m3', 'fragmentos' => [
            ['orden' => 1, 'pagina' => null, 'texto' => '# Arreglos', 'embedding' => $this->vector(3)],
            ['orden' => 2, 'pagina' => null, 'texto' => 'Texto', 'embedding' => $this->vector(4)],
        ]])]);

        (new ProcesarDocumento($doc->id))->handle(app(ClienteAgente::class));
        (new ProcesarDocumento($doc->id))->handle(app(ClienteAgente::class)); // un reintento no duplica

        $doc->refresh();
        $this->assertSame(['listo', 2, 'bge-m3'], [$doc->estado, $doc->fragmentos, $doc->modelo_embeddings]);
        $this->assertSame(['# Arreglos', 'Texto'], DocumentFragment::orderBy('orden')->pluck('texto')->all());
        Http::assertSent(fn (PeticionHttp $r) => $r->hasHeader('X-Agente-Token', 'secreto') && $r->isMultipart());
    }

    public function test_un_documento_sin_texto_queda_en_error_sin_reintentar(): void
    {
        Queue::fake();
        $this->subir('escaneo.txt', ' ')->assertCreated();
        Http::fake(['agente.test/*' => Http::response(['detail' => 'El documento no tiene texto extraíble.'], 422)]);

        (new ProcesarDocumento(CourseDocument::sole()->id))->handle(app(ClienteAgente::class));

        $this->assertSame('error', CourseDocument::sole()->estado);
        $this->assertStringContainsString('texto extraíble', CourseDocument::sole()->error);
    }

    // ---------- Búsqueda ----------

    public function test_la_busqueda_elige_lo_mas_cercano_y_nunca_material_de_otro_curso(): void
    {
        $this->documento($this->curso, 'Apuntes de C', [['Arreglos en C', 10], ['Punteros en C', 20], ['Recursión en C', 30]]);
        $this->documento($this->curso, 'Borrador', [['Arreglos del borrador', 10]], estado: 'procesando');
        [$otro] = $this->cursoConInstructor('Otro curso');
        $this->documento($otro, 'Ajeno', [['Arreglos de otro curso', 10]]);
        // La consulta cae casi sobre «arreglos» y un poco hacia «punteros»
        Http::fake(['agente.test/v1/embeddings' => Http::response(['modelo' => 'bge-m3', 'embeddings' => [$this->vector(10, 0.3, 20)]])]);

        $material = app(BuscadorMaterial::class)->buscar($this->curso, [], ['objetivos' => []], 'Tareas de arreglos');

        $this->assertSame(['Arreglos en C', 'Punteros en C', 'Recursión en C'], array_column($material, 'texto'));
        $this->assertSame('Apuntes de C', $material[0]['documento']);
        Http::assertSent(fn (PeticionHttp $r) => $r['textos'] === ['Tareas de arreglos']);
    }

    public function test_sin_material_listo_no_se_calcula_ningun_embedding(): void
    {
        Http::fake();
        $this->documento($this->curso, 'Borrador', [['Arreglos', 10]], estado: 'procesando');

        $this->assertSame([], app(BuscadorMaterial::class)->buscar($this->curso, [], [], 'Arreglos'));
        Http::assertNothingSent();
    }

    public function test_la_consulta_usa_la_tarea_la_clase_y_los_objetivos_seleccionados(): void
    {
        $diseno = [
            'objetivos' => [['codigo' => 'OB-1', 'descripcion' => 'Recorrer arreglos con for'], ['codigo' => 'OB-2', 'descripcion' => 'Usar punteros']],
            'clases' => [['uid' => 'c1', 'titulo' => 'Recorridos', 'objetivos' => ['OB-1']]],
            'tareas' => [['uid' => 't1', 'titulo' => 'Promedio de notas', 'enunciado_md' => 'Calcula el promedio']],
        ];
        $b = app(BuscadorMaterial::class);

        $this->assertSame("Recorridos\nRecorrer arreglos con for", $b->consulta($this->curso, $diseno, ['clase_uid' => 'c1'], ''));
        $this->assertSame("Con datos de ventas\nPromedio de notas. Calcula el promedio\nRecorrer arreglos con for\nUsar punteros",
            $b->consulta($this->curso, $diseno, ['tarea_uid' => 't1'], 'Con datos de ventas'));
        $this->assertSame('Usar punteros', $b->consulta($this->curso, $diseno, ['objetivos' => ['OB-2']], ''));
    }

    // ---------- Paso al agente ----------

    public function test_el_material_viaja_al_agente_y_queda_en_la_corrida(): void
    {
        Queue::fake();
        $this->documento($this->curso, 'Apuntes de C', [['Arreglos en C', 10]]);
        $objetivo = ['uid' => 'ob1', 'codigo' => 'OB-1', 'orden' => 1, 'descripcion' => 'Recorrer arreglos con for',
            'tipo' => 'habilidad', 'evaluacion' => ['practica']];
        $this->postJson("/api/v1/courses/{$this->curso->id}/design/sync", ['cambios' => [
            ['uid' => 'ob1', 'tipo' => 'objetivo', 'base_version' => 0, 'contenido' => $objetivo],
        ]])->assertOk();
        $id = $this->postJson("/api/v1/courses/{$this->curso->id}/agent/jobs", [
            'plantilla' => 'objetivos', 'alcance' => [], 'clave_idempotencia' => (string) Str::uuid(),
        ])->assertStatus(202)->json('data.id');

        Http::fake([
            'agente.test/v1/embeddings' => Http::response(['modelo' => 'bge-m3', 'embeddings' => [$this->vector(10)]]),
            'agente.test/v1/generar' => Http::response([
                'plantilla' => 'objetivos', 'elementos' => [], 'notas' => new \stdClass, 'advertencias' => [], 'validaciones' => [],
                'intentos' => 1, 'uso' => ['entrada' => 900, 'salida' => 200, 'cache_escritura' => 0, 'cache_lectura' => 0, 'costo_usd' => 0],
                'modelo' => 'ollama:qwen3:8b', 'version_prompt' => 'sistema-v2', 'duracion_ms' => 60000,
            ]),
        ]);

        (new EjecutarTrabajoAgente($id))->handle(app(ClienteAgente::class), app(ContextoAgente::class));

        $fragmento = DocumentFragment::sole();
        Http::assertSent(fn (PeticionHttp $r) => str_ends_with($r->url(), '/v1/generar')
            && $r['material'] === [['id' => $fragmento->id, 'documento' => 'Apuntes de C', 'pagina' => 1, 'texto' => 'Arreglos en C']]);
        $corrida = AgentJob::find($id)->corrida;
        $this->assertSame([$fragmento->id], $corrida->fragmentos);
        $this->assertSame('ollama:qwen3:8b', $corrida->modelo);
        // La consola ve qué consultó el agente, sin el texto ni los vectores
        $this->getJson("/api/v1/agent/jobs/{$id}")->assertOk()
            ->assertJsonPath('data.material', [['documento' => 'Apuntes de C', 'pagina' => 1]]);
    }
}
