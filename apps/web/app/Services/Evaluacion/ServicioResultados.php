<?php

namespace App\Services\Evaluacion;

use App\Domain\Evaluacion\Eficiencia;
use App\Domain\Evaluacion\LogroObjetivos;
use App\Domain\Perfil\ConfiguracionPerfil;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\Course;
use App\Models\DesignElement;
use App\Models\Enrollment;
use App\Models\Release;
use App\Models\ResultReview;
use App\Models\TaskProgress;
use App\Services\Agente\ClienteAgente;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Revisión de resultados (paso 10): ¿se cumplieron los objetivos? Arma los datos a partir de lo que ya está
 * registrado y pide los estadísticos al servicio de Python (SciPy), que solo recibe números.
 */
class ServicioResultados
{
    public const MEDIDAS = ['global', 'teorico', 'practico', 'recall', 'comprension'];

    public const INDICES_MSLQ = ['motivacion', 'estrategias_cognitivas', 'autorregulacion'];

    public function __construct(private readonly ClienteAgente $agente) {}

    /** Inscripciones que cuentan: las que llegaron a tener acceso al material. */
    public function inscripciones(Course $curso): Collection
    {
        return Enrollment::with(['membresia.group', 'perfil', 'user:id,name'])->where('course_id', $curso->id)
            ->whereNotIn('estado', ['solicitud', 'rechazada', 'baja'])->orderBy('id')->get();
    }

    /**
     * Puntajes pre y post (0–100; null = no presentó). global = pesos del perfil × teórico y práctico; si el curso
     * tiene un solo tipo de prueba, global es ese tipo.
     *
     * @param  list<int>|null  $soloDe  ids de inscripción
     * @return Collection<int, array{pre: array<string, ?float>, post: array<string, ?float>}> por enrollment_id
     */
    public function puntajes(Course $curso, ?array $soloDe = null): Collection
    {
        $config = ConfiguracionPerfil::desde($curso->configuracion ?? []);
        $tipos = Assessment::where('course_id', $curso->id)->distinct()->pluck('tipo')->all();
        $ambos = in_array('teorica', $tipos, true) && in_array('practica', $tipos, true);

        return AssessmentAttempt::query()
            ->join('assessments as a', 'a.id', '=', 'assessment_attempts.assessment_id')
            ->where('a.course_id', $curso->id)->whereNotNull('assessment_attempts.calificado_at')
            ->when($soloDe !== null, fn ($q) => $q->whereIn('assessment_attempts.enrollment_id', $soloDe))
            ->get(['assessment_attempts.enrollment_id', 'a.momento', 'a.tipo', 'assessment_attempts.porcentaje', 'assessment_attempts.subpuntajes'])
            ->groupBy('enrollment_id')
            ->map(function (Collection $intentos) use ($config, $ambos) {
                $r = [];
                foreach (['pre', 'post'] as $momento) {
                    $teo = $intentos->first(fn ($i) => $i->momento === $momento && $i->tipo === 'teorica');
                    $pra = $intentos->first(fn ($i) => $i->momento === $momento && $i->tipo === 'practica');
                    $t = $teo?->porcentaje;
                    $p = $pra?->porcentaje;
                    $r[$momento] = [
                        'global' => $ambos
                            ? ($t !== null && $p !== null ? round($config->pesoTeorico * $t + $config->pesoPractico * $p, 2) : null)
                            : ($t ?? $p),
                        'teorico' => $t,
                        'practico' => $p,
                        'recall' => isset($teo?->subpuntajes['recall']) ? (float) $teo->subpuntajes['recall'] : null,
                        'comprension' => isset($teo?->subpuntajes['comprension']) ? (float) $teo->subpuntajes['comprension'] : null,
                    ];
                }

                return $r;
            });
    }

    /**
     * Puntajes de un instrumento Likert por inscripción y momento.
     *
     * @param  list<int>|null  $soloDe  ids de inscripción
     * @return Collection<int, array<string, array{subescalas: array<string, float>, indices: array<string, float>}>>
     *                     por enrollment_id y luego por momento (pre, post)
     */
    public function instrumento(Course $curso, string $clave, ?array $soloDe = null): Collection
    {
        return DB::table('instrument_responses as r')
            ->join('instrument_administrations as a', 'a.id', '=', 'r.instrument_administration_id')
            ->join('instruments as i', 'i.id', '=', 'a.instrument_id')
            ->where('a.course_id', $curso->id)->where('i.clave', $clave)->whereIn('a.momento', ['pre', 'post'])
            ->whereNotNull('r.completado_at')
            ->when($soloDe !== null, fn ($q) => $q->whereIn('r.enrollment_id', $soloDe))
            ->get(['r.enrollment_id', 'a.momento', 'r.puntajes'])
            ->groupBy('enrollment_id')
            ->map(fn (Collection $filas) => $filas->mapWithKeys(fn ($f) => [$f->momento => json_decode($f->puntajes, true)])->all());
    }

    /** Medias por subescala de un instrumento en un momento (p. ej., IMMS post). */
    public function resumenInstrumento(Course $curso, string $clave, string $momento = 'post'): ?array
    {
        $puntajes = $this->instrumento($curso, $clave)->pluck("{$momento}.subescalas")->filter()->values();
        if ($puntajes->isEmpty()) {
            return null;
        }
        $claves = array_keys($puntajes->first());

        return [
            'n' => $puntajes->count(),
            'subescalas' => collect($claves)->mapWithKeys(fn ($k) => [$k => round((float) $puntajes->avg($k), 2)])->all(),
        ];
    }

    /** Escala CS de cada clase de tareas: medias de carga intrínseca, extrínseca y germana (0–10). */
    public function cargaPorClase(Course $curso): array
    {
        $umbral = (float) ($curso->configuracion['carga_extrinseca_alta'] ?? 5);
        $clases = collect(Release::vigente($curso->id)?->manifiesto['clases'] ?? [])->keyBy('uid');

        return DB::table('instrument_responses as r')
            ->join('instrument_administrations as a', 'a.id', '=', 'r.instrument_administration_id')
            ->where('a.course_id', $curso->id)->where('a.momento', 'clase')
            ->whereNotNull('r.completado_at')
            ->get(['a.clase_uid', 'r.puntajes'])
            ->groupBy('clase_uid')
            ->map(function (Collection $filas, string $uid) use ($clases, $umbral) {
                $sub = $filas->map(fn ($f) => json_decode($f->puntajes, true)['subescalas'] ?? []);
                $media = fn (string $k) => round((float) $sub->avg($k), 2);

                return [
                    'clase_uid' => $uid,
                    'orden' => $clases[$uid]['orden'] ?? null,
                    'titulo' => $clases[$uid]['titulo'] ?? $uid,
                    'n' => $filas->count(),
                    'intrinseca' => $media('intrinseca'),
                    'extrinseca' => $media('extrinseca'),
                    'germana' => $media('germana'),
                    'extrinseca_alta' => $media('extrinseca') >= $umbral,
                ];
            })->sortBy('orden')->values()->all();
    }

    /** Logro por objetivo en el post-test, con la descripción del objetivo del diseño. */
    public function logroObjetivos(Course $curso): array
    {
        $filas = DB::table('item_responses as r')
            ->join('assessment_attempts as t', 't.id', '=', 'r.assessment_attempt_id')
            ->join('assessments as a', 'a.id', '=', 't.assessment_id')
            ->join('assessment_items as ai', fn ($j) => $j->on('ai.assessment_id', '=', 'a.id')->on('ai.item_id', '=', 'r.item_id'))
            ->join('items as i', 'i.id', '=', 'r.item_id')
            ->where('a.course_id', $curso->id)->where('a.momento', 'post')->whereNotNull('t.calificado_at')
            ->get(['t.enrollment_id', 'r.item_id', 'i.objetivo', 'ai.puntos', 'r.fraccion'])
            ->map(fn ($f) => ['inscripcion' => $f->enrollment_id, 'item' => $f->item_id, 'objetivo' => $f->objetivo,
                'puntos' => (float) $f->puntos, 'fraccion' => $f->fraccion === null ? null : (float) $f->fraccion]);

        $descripciones = DesignElement::where('course_id', $curso->id)->where('tipo', 'objetivo')->whereNull('eliminado_at')
            ->get()->mapWithKeys(fn ($e) => [$e->contenido->codigo => $e->contenido->descripcion]);

        $criterio = (float) ($curso->configuracion['criterio_logro'] ?? 70);

        return array_map(fn ($o) => [...$o, 'descripcion' => $descripciones[$o['codigo']] ?? null, 'criterio' => $criterio],
            LogroObjetivos::calcular($filas, $criterio));
    }

    /** Todo lo del paso 10 para la consola. $control: otro curso del instructor con las mismas pruebas (diseño cuasi-experimental). */
    public function revision(Course $curso, ?Course $control = null): array
    {
        $inscripciones = $this->inscripciones($curso);
        $ids = $inscripciones->pluck('id')->all();
        $puntajes = $this->puntajes($curso);
        $mslq = $this->instrumento($curso, 'mslq');

        $series = [];
        foreach (self::MEDIDAS as $m) {
            $series[$m] = $this->serie($ids, fn ($id, $momento) => $puntajes[$id][$momento][$m] ?? null, 100);
        }
        foreach (self::INDICES_MSLQ as $indice) {
            $series["mslq_{$indice}"] = $this->serie($ids, fn ($id, $momento) => $mslq[$id][$momento]['indices'][$indice] ?? null, null);
        }

        $porGrupo = [];
        foreach ($inscripciones->groupBy(fn ($e) => $e->membresia?->group?->clave ?? 'sin_grupo') as $clave => $grupo) {
            $porGrupo[$clave] = $this->serie($grupo->pluck('id')->all(), fn ($id, $momento) => $puntajes[$id][$momento]['global'] ?? null, 100);
        }

        return [
            'n' => count($ids),
            'con_pre_y_post' => collect($ids)->filter(fn ($id) => isset($puntajes[$id]['pre']['global'], $puntajes[$id]['post']['global']))->count(),
            'logro_objetivos' => $this->logroObjetivos($curso),
            'pre_post' => $this->estadisticos('pre-post', ['series' => $series]),
            'por_grupo' => count($porGrupo) > 1 ? $this->estadisticos('pre-post', ['series' => $porGrupo]) : null,
            'eficiencia_ganancia' => $this->eficienciaGanancia($ids, $puntajes),
            'imms' => $this->resumenInstrumento($curso, 'imms'),
            'carga_por_clase' => $this->cargaPorClase($curso),
            'comparacion' => $control ? $this->comparar($curso, $puntajes, $ids, $control) : null,
            'revisiones' => ResultReview::with('autor:id,name')->where('course_id', $curso->id)->orderByDesc('numero')->get(),
        ];
    }

    /** Pares pre/post en el mismo orden de estudiantes. */
    private function serie(array $ids, callable $valor, ?float $maximo): array
    {
        return [
            'pre' => array_map(fn ($id) => $valor($id, 'pre'), $ids),
            'post' => array_map(fn ($id) => $valor($id, 'post'), $ids),
            'maximo' => $maximo,
        ];
    }

    /**
     * Correlación entre la eficiencia instruccional durante el curso (desempeño en tareas contra esfuerzo)
     * y la ganancia post - pre (propuesta, 12.3).
     */
    private function eficienciaGanancia(array $ids, Collection $puntajes): ?array
    {
        $tareas = TaskProgress::whereIn('enrollment_id', $ids)->whereNotNull('esfuerzo')->whereNotNull('mejor_fraccion')
            ->get(['enrollment_id', 'esfuerzo', 'mejor_fraccion'])->groupBy('enrollment_id');
        $datos = [];
        foreach ($tareas as $id => $suyas) {
            $pre = $puntajes[$id]['pre']['global'] ?? null;
            $post = $puntajes[$id]['post']['global'] ?? null;
            if ($pre !== null && $post !== null) {
                $datos[$id] = ['desempeno' => $suyas->avg('mejor_fraccion'), 'esfuerzo' => $suyas->avg('esfuerzo'), 'ganancia' => $post - $pre];
            }
        }
        $e = array_filter(Eficiencia::delGrupo($datos), fn ($v) => $v !== null);
        if (count($e) < 3) {
            return null;
        }

        return $this->estadisticos('correlacion', [
            'x' => array_values($e),
            'y' => array_values(array_map(fn ($id) => $datos[$id]['ganancia'], array_keys($e))),
        ]);
    }

    /** Experimental (este curso) contra control: post, ganancia y ANCOVA con el pre como covariable. */
    private function comparar(Course $curso, Collection $puntajes, array $ids, Course $control): array
    {
        $pc = $this->puntajes($control);
        $completos = function (Collection $p, array $ids) {
            return collect($ids)->map(fn ($id) => [$p[$id]['pre']['global'] ?? null, $p[$id]['post']['global'] ?? null])
                ->filter(fn ($par) => $par[0] !== null && $par[1] !== null)->values();
        };
        $exp = $completos($puntajes, $ids);
        $ctl = $completos($pc, $this->inscripciones($control)->pluck('id')->all());

        $base = ['curso_control' => $control->only('id', 'titulo'), 'n' => ['experimental' => $exp->count(), 'control' => $ctl->count()]];

        if ($exp->count() < 2 || $ctl->count() < 2) {
            return [...$base, 'error' => 'Se necesitan al menos 2 estudiantes con pre y post en cada curso.'];
        }

        return [
            ...$base,
            'post' => $this->estadisticos('dos-grupos', ['a' => $exp->pluck(1)->all(), 'b' => $ctl->pluck(1)->all()]),
            'ganancia' => $this->estadisticos('dos-grupos', [
                'a' => $exp->map(fn ($p) => $p[1] - $p[0])->all(), 'b' => $ctl->map(fn ($p) => $p[1] - $p[0])->all()]),
            'ancova' => $this->estadisticos('ancova', [
                'post' => [...$exp->pluck(1), ...$ctl->pluck(1)],
                'pre' => [...$exp->pluck(0), ...$ctl->pluck(0)],
                'grupo' => [...array_fill(0, $exp->count(), 'experimental'), ...array_fill(0, $ctl->count(), 'control')],
            ]),
        ];
    }

    /** Si el servicio de estadísticos no responde, el tablero se muestra igual y lo dice. */
    private function estadisticos(string $prueba, array $datos): ?array
    {
        try {
            return $this->agente->estadisticas($prueba, $datos);
        } catch (Throwable $e) {
            report($e);

            return ['error' => 'El servicio de estadísticos no respondió. Revisa que el servicio del agente esté corriendo.'];
        }
    }
}
