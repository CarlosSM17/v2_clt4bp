<?php

namespace App\Services\Agente;

use App\Domain\Diseno\Preseleccion;
use App\Domain\Diseno\ResumenGrupo;
use App\Models\AgentJob;
use App\Models\Course;
use App\Models\DesignElement;
use App\Models\DiffGroup;
use App\Models\EffectRule;
use App\Services\Diseno\ConstructorDiseno;
use App\Services\Evaluacion\ServicioResultados;
use App\Services\Evaluacion\ServicioTablero;

/**
 * Arma la SolicitudGeneracion que recibe el agente.
 * Privacidad: de los estudiantes solo salen agregados por grupo; nunca nombres, correos ni respuestas.
 */
class ContextoAgente
{
    public function __construct(
        private readonly ConstructorDiseno $constructor,
        private readonly BuscadorMaterial $material,
    ) {}

    public function construir(AgentJob $trabajo): array
    {
        $curso = $trabajo->curso;
        $p = $trabajo->parametros;
        $diseno = $this->constructor->construir($curso);

        return [
            'plantilla' => $trabajo->plantilla,
            'curso' => [
                'titulo' => $curso->titulo,
                'lenguaje' => $curso->lenguaje->value,
                'nivel_educativo' => $curso->nivel_educativo ?? 'licenciatura',
                'preferencias' => (string) ($curso->configuracion['preferencias_agente'] ?? ''),
            ],
            'diseno' => $diseno,
            'grupos' => $this->grupos($curso),
            // El prefijo hace únicos los uid que proponga este trabajo
            'alcance' => (object) [
                ...$this->alcancePorDefecto($curso, $trabajo->plantilla),
                ...($p['alcance'] ?? []),
                'prefijo' => "j{$trabajo->id}",
            ],
            'indicaciones' => (string) ($p['indicaciones'] ?? ''),
            'calidad' => $p['calidad'] ?? 'normal',
            'resultados' => $trabajo->plantilla === 'informe_revision' ? (object) $this->resultados($curso) : (object) [],
            // RAG: material del curso, nunca datos de estudiantes. El «id» no lo usa el agente: es para la corrida
            'material' => $trabajo->plantilla === 'informe_revision' ? []
                : $this->material->buscar($curso, $diseno, $p['alcance'] ?? [], (string) ($p['indicaciones'] ?? '')),
        ];
    }

    /** Códigos y uid que existen en el servidor: para validar el alcance antes de encolar. */
    public function existentes(Course $curso): array
    {
        $vivos = DesignElement::where('course_id', $curso->id)->whereNull('eliminado_at')
            ->whereIn('tipo', ['objetivo', 'clase', 'tarea'])->get(['uid', 'tipo', 'contenido']);

        return [
            'objetivo' => $vivos->where('tipo', 'objetivo')->map(fn ($e) => $e->contenido->codigo)->values()->all(),
            'clase' => $vivos->where('tipo', 'clase')->pluck('uid')->values()->all(),
            'tarea' => $vivos->where('tipo', 'tarea')->pluck('uid')->values()->all(),
            'grupo' => DiffGroup::where('course_id', $curso->id)->pluck('clave')->all(),
        ];
    }

    private function alcancePorDefecto(Course $curso, string $plantilla): array
    {
        return $plantilla === 'plan_implementacion'
            ? ['inicio' => $curso->inicia_el?->toDateString(), 'fin' => $curso->termina_el?->toDateString()]
            : [];
    }

    /** Un resumen por grupo diferenciado; si no hay grupos, uno del curso completo. */
    private function grupos(Course $curso): array
    {
        $reglas = EffectRule::where('activa', true)->orderBy('orden')->get()->toArray();
        $grupos = DiffGroup::where('course_id', $curso->id)->orderBy('orden')->get();

        return array_map(function (?DiffGroup $g) use ($curso, $reglas) {
            $resumen = ResumenGrupo::desdePerfiles($this->perfiles($curso, $g?->clave));

            return [
                'clave' => $g?->clave,
                'nombre' => $g?->nombre ?? 'Curso completo',
                'nivel' => $g?->nivel ?? $resumen['nivel_modal'],
                'resumen' => (object) $resumen,
                'efectos_sugeridos' => array_column((new Preseleccion())->evaluar($reglas, $resumen)['efectos'], 'id'),
            ];
        }, $grupos->isEmpty() ? [null] : $grupos->all());
    }

    /** Misma consulta que la preselección (EfectoController): perfiles vigentes, opcionalmente de un grupo. */
    private function perfiles(Course $curso, ?string $clave): array
    {
        return $curso->enrollments()
            ->whereNotIn('estado', ['solicitud', 'rechazada', 'baja'])
            ->when($clave, fn ($q) => $q->whereHas('membresia.group', fn ($g) => $g->where('clave', $clave)))
            ->with('perfil')->get()->pluck('perfil')->filter()
            ->map(fn ($p) => ['nivel' => $p->nivel, 'mslq' => $p->mslq, 'banderas' => $p->banderas])
            ->values()->all();
    }

    /**
     * Paso 10: solo agregados del curso (sin nombres ni seudónimos) para el informe de revisión.
     * Las cifras son las mismas que ve el instructor en la pestaña «Resultados».
     */
    private function resultados(Course $curso): array
    {
        $r = app(ServicioResultados::class)->revision($curso);
        $t = app(ServicioTablero::class)->grupo($curso);
        $resumir = fn (?array $pp) => $pp === null || isset($pp['error']) ? $pp : array_map(fn ($s) => [
            'n' => $s['n'], 'media_pre' => $s['pre']['media'], 'media_post' => $s['post']['media'],
            't' => $s['t_pareada']['estadistico'] ?? null, 'gl' => $s['t_pareada']['gl'] ?? null, 'p_t' => $s['t_pareada']['p'] ?? null,
            'p_wilcoxon' => $s['wilcoxon']['p'] ?? null, 'prueba_sugerida' => $s['prueba_sugerida'],
            'd_z' => $s['d_z'], 'g_hake' => $s['g_hake'],
        ], $pp);

        return [
            'estudiantes' => $r['n'],
            'con_pre_y_post' => $r['con_pre_y_post'],
            'logro_objetivos' => $r['logro_objetivos'],
            'pre_post' => $resumir($r['pre_post']),
            'por_grupo' => $resumir($r['por_grupo']),
            'eficiencia_ganancia' => $r['eficiencia_ganancia'],
            'imms' => $r['imms'],
            'carga_por_clase' => $r['carga_por_clase'],
            'esfuerzo_por_tarea' => $t['esfuerzo_por_tarea'],
            'ayudas' => $t['ayudas'],
            'alertas' => collect($t['estudiantes'])->flatMap(fn ($e) => array_column($e['alertas'], 'tipo'))->countBy()->all(),
        ];
    }
}
