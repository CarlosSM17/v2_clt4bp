<?php

namespace Tests\Feature\Inicio;

use App\Enums\EstadoInscripcion;
use App\Models\Course;
use App\Models\DesignElement;
use App\Models\Enrollment;
use App\Models\TaskProgress;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Pagina;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreaUsuarios;
use Tests\TestCase;

/** Inicio: cada estudiante ve qué le toca en cada curso; el equipo docente, sus cursos. */
class InicioTest extends TestCase
{
    use CreaUsuarios, RefreshDatabase;

    private Course $curso;

    private User $instructor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->instructor = $this->instructor();
        $this->curso = Course::create(['owner_id' => $this->instructor->id, 'titulo' => 'Programación I', 'lenguaje' => 'c', 'nivel_educativo' => 'universidad']);
        $this->curso->instructores()->attach($this->instructor->id, ['rol' => 'responsable']);
    }

    private function inscribir(EstadoInscripcion $estado): Enrollment
    {
        return Enrollment::create(['course_id' => $this->curso->id, 'user_id' => User::factory()->create()->id, 'estado' => $estado, 'inscrito_at' => now()]);
    }

    /** Diseño de ejemplo aprobado, publicado y con la clase abierta desde hace una hora. */
    private function publicarConClaseAbierta(): void
    {
        Sanctum::actingAs($this->instructor, ['consola']);
        $ejemplo = json_decode(file_get_contents(base_path('../../packages/contracts/examples/diseno-curso/clase-recorridos.json')), true);
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
            ['clase_uid' => 'tc1', 'abre_at' => now()->subHour()->toIso8601String(), 'cierra_at' => now()->addDays(3)->toIso8601String()],
        ]])->assertOk();
    }

    public function test_el_estudiante_ve_la_tarea_con_la_que_sigue_su_avance_y_las_fechas(): void
    {
        $this->publicarConClaseAbierta();
        $e = $this->inscribir(EstadoInscripcion::Cursando);
        TaskProgress::create(['enrollment_id' => $e->id, 'tarea_uid' => 'tc1-t1', 'estado' => 'completada', 'intentos' => 1]);

        $this->actingAs($e->user)->get('/dashboard')->assertOk()->assertInertia(fn (Pagina $p) => $p
            ->component('Dashboard')
            ->where('cursos.0.curso.titulo', 'Programación I')
            ->where('cursos.0.siguiente.tipo', 'tarea')
            ->where('cursos.0.siguiente.url', route('aula.tarea', [$this->curso, 'tc1-t2'])) // la primera sin completar
            ->where('cursos.0.avance', ['completadas' => 1, 'total' => 3])
            ->where('cursos.0.clases.0.estado', 'abierta')
            ->where('cursos.0.fechas.0.texto', fn ($t) => str_starts_with($t, 'Cierra la clase 1'))
            ->where('imparte', []));
    }

    public function test_cada_etapa_tiene_su_siguiente_paso(): void
    {
        $solicitud = $this->inscribir(EstadoInscripcion::Solicitud);
        $this->actingAs($solicitud->user)->get('/dashboard')->assertInertia(fn (Pagina $p) => $p
            ->where('cursos.0.siguiente.tipo', 'espera')->where('cursos.0.avance', null));

        $diagnostico = $this->inscribir(EstadoInscripcion::Inscrito);
        $this->actingAs($diagnostico->user)->get('/dashboard')->assertInertia(fn (Pagina $p) => $p
            ->where('cursos.0.siguiente.tipo', 'diagnostico')->where('cursos.0.siguiente.texto', 'Tu instructor aún no abre el diagnóstico.'));

        // Con perfil pero sin material publicado: no truena, explica que falta el material
        $sinMaterial = $this->inscribir(EstadoInscripcion::ConPerfil);
        $this->actingAs($sinMaterial->user)->get('/dashboard')->assertInertia(fn (Pagina $p) => $p
            ->where('cursos.0.siguiente.texto', 'Tu instructor todavía no publica material en este curso.'));

        $baja = $this->inscribir(EstadoInscripcion::Baja);
        $this->actingAs($baja->user)->get('/dashboard')->assertInertia(fn (Pagina $p) => $p->where('cursos', []));
    }

    public function test_el_equipo_docente_ve_sus_cursos_con_inscritos_y_solicitudes(): void
    {
        $this->inscribir(EstadoInscripcion::Cursando);
        $this->inscribir(EstadoInscripcion::Solicitud);

        $this->actingAs($this->instructor)->get('/dashboard')->assertOk()->assertInertia(fn (Pagina $p) => $p
            ->where('cursos', [])
            ->where('imparte.0.titulo', 'Programación I')
            ->where('imparte.0.inscritos', 1)
            ->where('imparte.0.solicitudes', 1));
    }
}
