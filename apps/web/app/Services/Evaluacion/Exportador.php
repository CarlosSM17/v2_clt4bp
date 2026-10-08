<?php

namespace App\Services\Evaluacion;

use App\Domain\Evaluacion\Celdas;
use App\Domain\Evaluacion\DiccionarioDatos;
use App\Domain\Evaluacion\Eficiencia;
use App\Domain\Evaluacion\LibroXlsx;
use App\Domain\Evaluacion\ProporcionEditada;
use App\Models\AgentJob;
use App\Models\Course;
use App\Models\DesignElement;
use App\Models\Enrollment;
use App\Models\InstrumentResponse;
use App\Models\LearningEvent;
use App\Models\Release;
use App\Models\TaskProgress;
use App\Models\TaskSubmission;
use Generator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use ZipArchive;

/**
 * Exportación para SPSS, R o JASP: CSV (UTF-8, en un ZIP) o XLSX (una hoja por tabla).
 * Solo incluye a quienes tienen vigente el consentimiento de investigación, siempre con seudónimo.
 */
class Exportador
{
    /** @var array<int, string> enrollment_id => seudónimo */
    private array $seudonimos = [];

    /** Genera la exportación en un archivo temporal y devuelve su ruta. */
    public function generar(Course $curso, string $formato): string
    {
        $inscripciones = $this->consintieron($curso);
        $this->seudonimos = $inscripciones->pluck('seudonimo', 'id')->all();
        $tablas = $this->tablas($curso, $inscripciones);
        $ruta = tempnam(sys_get_temp_dir(), 'exp');

        $formato === 'xlsx' ? $this->xlsx($ruta, $curso, $tablas) : $this->zip($ruta, $curso, $tablas);

        return $ruta;
    }

    /** Solo quienes consintieron la investigación (y, si el curso es de menores, también su tutor). */
    public function consintieron(Course $curso): Collection
    {
        $tipos = ($curso->configuracion['menores'] ?? false) ? ['investigacion', 'tutor'] : ['investigacion'];

        $q = Enrollment::with(['perfil', 'membresia.group'])->where('course_id', $curso->id)
            ->whereNotIn('estado', ['solicitud', 'rechazada', 'baja']);
        foreach ($tipos as $tipo) {
            $q->whereHas('user.consents', fn ($c) => $c->where('tipo', $tipo)->whereNull('revocado_at'));
        }

        return $q->orderBy('id')->get();
    }

    /** @return array<string, array{0: list<string>, 1: iterable<array>}> nombre => [columnas, filas] */
    private function tablas(Course $curso, Collection $inscripciones): array
    {
        $ids = $inscripciones->pluck('id')->all();
        $manifiesto = Release::vigente($curso->id)?->manifiesto ?? ['clases' => [], 'tareas' => [], 'procedimental' => []];
        $publicaciones = Release::where('course_id', $curso->id)->pluck('numero', 'id');

        $tablas = [
            'estudiantes' => $inscripciones->map(fn (Enrollment $e) => [
                $e->seudonimo, $e->membresia?->group?->clave, $e->estado, $e->perfil?->nivel,
                $e->perfil?->cp_recall, $e->perfil?->cp_comprension, $e->perfil?->cp_teorico, $e->perfil?->cp_practico,
                $e->perfil?->cp_global, implode('|', $e->perfil?->banderas ?? []),
            ]),
            'pruebas' => $this->pruebas($curso, $ids),
            'items' => $this->items($curso, $ids),
            'instrumentos' => $this->instrumentosLargo($ids),
            'tareas' => $this->tareas($manifiesto, $ids),
            'envios' => TaskSubmission::whereIn('enrollment_id', $ids)->lazyById(500)->map(fn ($s) => [
                $this->seudonimos[$s->enrollment_id], $s->tarea_uid, $s->numero, $publicaciones[$s->release_id] ?? null,
                $s->estado, $s->fraccion, $s->created_at, $s->autoexplicacion, $s->codigo,
            ]),
            'eventos' => LearningEvent::whereIn('enrollment_id', $ids)->lazyById(2000)->map(fn ($ev) => [
                $this->seudonimos[$ev->enrollment_id], $ev->verbo, $ev->objeto_tipo, $ev->objeto_uid,
                $publicaciones[$ev->release_id] ?? null, $ev->duracion_ms, $ev->origen, $ev->ocurrido_at, $ev->resultado,
            ]),
            'agente' => $this->agente($curso),
        ];
        $salida = [];
        foreach ($tablas as $nombre => $filas) {
            $salida[$nombre] = [DiccionarioDatos::columnas($nombre), $filas];
        }

        // Una tabla ancha por instrumento y momento, con cada ítem en su columna (para alfa de Cronbach, p. ej.)
        foreach ($this->instrumentosAncho($ids) as $nombre => $tabla) {
            $salida[$nombre] = $tabla;
        }

        return $salida;
    }

    private function pruebas(Course $curso, array $ids): Generator
    {
        $intentos = DB::table('assessment_attempts as t')->join('assessments as a', 'a.id', '=', 't.assessment_id')
            ->where('a.course_id', $curso->id)->whereIn('t.enrollment_id', $ids)->whereNotNull('t.calificado_at')
            ->orderBy('t.enrollment_id')->orderBy('a.momento', 'desc')->orderBy('a.tipo', 'desc')
            ->get(['t.enrollment_id', 'a.momento', 'a.tipo', 'a.forma', 't.porcentaje', 't.subpuntajes', 't.iniciado_at', 't.enviado_at']);

        foreach ($intentos as $i) {
            $sub = json_decode($i->subpuntajes ?? '{}', true);
            yield [
                $this->seudonimos[$i->enrollment_id], $i->momento, $i->tipo, $i->forma, (float) $i->porcentaje,
                $sub['recall'] ?? null, $sub['comprension'] ?? null, $sub['practica'] ?? null,
                $i->enviado_at ? round((strtotime($i->enviado_at) - strtotime($i->iniciado_at)) / 60, 1) : null,
                $i->enviado_at,
            ];
        }
    }

    private function items(Course $curso, array $ids): Generator
    {
        $filas = DB::table('item_responses as r')
            ->join('assessment_attempts as t', 't.id', '=', 'r.assessment_attempt_id')
            ->join('assessments as a', 'a.id', '=', 't.assessment_id')
            ->join('assessment_items as ai', fn ($j) => $j->on('ai.assessment_id', '=', 'a.id')->on('ai.item_id', '=', 'r.item_id'))
            ->join('items as i', 'i.id', '=', 'r.item_id')
            ->where('a.course_id', $curso->id)->whereIn('t.enrollment_id', $ids)->whereNotNull('t.calificado_at')
            ->orderBy('t.enrollment_id')->orderBy('a.momento', 'desc')->orderBy('ai.orden')
            ->get(['t.enrollment_id', 'a.momento', 'a.tipo as prueba_tipo', 'r.item_id', 'i.objetivo', 'i.nivel', 'i.tipo', 'ai.puntos', 'r.fraccion']);
        foreach ($filas as $f) {
            yield [$this->seudonimos[$f->enrollment_id], $f->momento, $f->prueba_tipo, $f->item_id, $f->objetivo, $f->nivel,
                $f->tipo, (float) $f->puntos, $f->fraccion === null ? null : (float) $f->fraccion];
        }
    }

    /** Respuestas completas de cuestionarios con su aplicación e instrumento. */
    private function respuestas(array $ids): Collection
    {
        return InstrumentResponse::with('administration.instrument')->whereIn('enrollment_id', $ids)
            ->whereNotNull('completado_at')->orderBy('enrollment_id')->get();
    }

    private function instrumentosLargo(array $ids): Generator
    {
        foreach ($this->respuestas($ids) as $r) {
            $a = $r->administration;
            foreach ($r->puntajes['subescalas'] ?? [] as $subescala => $puntaje) {
                yield [$this->seudonimos[$r->enrollment_id], $a->instrument->clave, $a->instrument->version, $a->momento,
                    $a->clase_uid, $subescala, $puntaje, $r->completado_at];
            }
        }
    }

    /** @return array<string, array{0: list<string>, 1: list<array>}> */
    private function instrumentosAncho(array $ids): array
    {
        $tablas = [];
        foreach ($this->respuestas($ids)->groupBy(fn ($r) => $r->administration->instrument->clave.'_'.$r->administration->momento) as $nombre => $grupo) {
            $def = $grupo->first()->administration->instrument->definicion;
            $items = array_column($def['items'], 'id');
            $subescalas = array_column($def['subescalas'], 'clave');
            $tablas["instrumento_{$nombre}"] = [
                ['seudonimo', 'clase_uid', ...$items, ...array_map(fn ($s) => "sub_{$s}", $subescalas)],
                $grupo->map(fn ($r) => [
                    $this->seudonimos[$r->enrollment_id], $r->administration->clase_uid,
                    ...array_map(fn ($i) => $r->respuestas[$i] ?? null, $items),
                    ...array_map(fn ($s) => $r->puntajes['subescalas'][$s] ?? null, $subescalas),
                ])->all(),
            ];
        }

        return $tablas;
    }

    private function tareas(array $manifiesto, array $ids): Generator
    {
        $clases = collect($manifiesto['clases'])->keyBy('uid');
        $tareas = collect($manifiesto['tareas'])->keyBy('uid');
        $ayudaTarea = collect($manifiesto['procedimental'])->pluck('tarea_uid', 'uid');
        $progreso = TaskProgress::whereIn('enrollment_id', $ids)->get();

        // Eficiencia de cada estudiante respecto a quienes hicieron la misma tarea
        $eficiencia = [];
        foreach ($progreso->whereNotNull('esfuerzo')->whereNotNull('mejor_fraccion')->groupBy('tarea_uid') as $uid => $ps) {
            $e = Eficiencia::delGrupo($ps->keyBy('enrollment_id')->map(fn ($p) => ['desempeno' => $p->mejor_fraccion, 'esfuerzo' => (float) $p->esfuerzo])->all());
            foreach ($e as $id => $v) {
                $eficiencia[$uid][$id] = $v;
            }
        }
        $ayudas = LearningEvent::whereIn('enrollment_id', $ids)->where('verbo', 'consulto_ayuda')->get(['enrollment_id', 'objeto_uid'])
            ->countBy(fn ($ev) => $ev->enrollment_id.'|'.($ayudaTarea[$ev->objeto_uid] ?? ''));

        $visible = LearningEvent::whereIn('enrollment_id', $ids)->where('verbo', 'tiempo_visible')->where('objeto_tipo', 'tarea')
            ->groupBy('enrollment_id', 'objeto_uid')->selectRaw('enrollment_id, objeto_uid, sum(duracion_ms) as ms')->get()
            ->mapWithKeys(fn ($v) => ["{$v->enrollment_id}|{$v->objeto_uid}" => (int) $v->ms]);

        foreach ($progreso->sortBy(['enrollment_id', 'id']) as $p) {
            $t = $tareas[$p->tarea_uid] ?? null;
            $clave = "{$p->enrollment_id}|{$p->tarea_uid}";
            yield [
                $this->seudonimos[$p->enrollment_id], $clases[$t['clase_uid'] ?? '']['orden'] ?? null, $t['clase_uid'] ?? null,
                $t['orden'] ?? null, $p->tarea_uid, $t['nivel_apoyo'] ?? null, $p->estado, $p->intentos, $p->mejor_fraccion,
                $p->esfuerzo, $eficiencia[$p->tarea_uid][$p->enrollment_id] ?? null, $ayudas[$clave] ?? 0,
                isset($visible[$clave]) ? round($visible[$clave] / 60000, 1) : null, $p->iniciado_at, $p->completado_at,
            ];
        }
    }

    /** Propuestas del agente con la decisión del instructor y cuánto editó lo aceptado (sección 12.2). */
    private function agente(Course $curso): Generator
    {
        $actuales = DesignElement::where('course_id', $curso->id)->whereNull('eliminado_at')->get(['uid', 'contenido'])
            ->mapWithKeys(fn ($e) => [$e->uid => json_decode(json_encode($e->contenido), true)]);

        foreach (AgentJob::with('corrida')->where('course_id', $curso->id)->where('estado', 'listo')->lazyById() as $j) {
            $c = $j->corrida;
            $propuestos = collect(json_decode(json_encode($j->resultado->elementos ?? []), true))->keyBy('contenido.uid');
            $aceptados = $c?->uids_aceptados ?? [];
            $ediciones = collect($aceptados)->filter(fn ($uid) => isset($propuestos[$uid], $actuales[$uid]))
                ->map(fn ($uid) => ProporcionEditada::entre(
                    ProporcionEditada::texto($propuestos[$uid]['contenido']),
                    ProporcionEditada::texto($actuales[$uid])));
            yield [
                $j->id, $j->plantilla, $j->paso, $c?->modelo, $c?->version_prompt, $c?->costo_usd,
                $c ? round($c->duracion_ms / 1000, 1) : null, $c?->intentos, $c?->decision,
                $propuestos->count(), count($aceptados), $ediciones->isEmpty() ? null : round($ediciones->avg(), 4), $j->created_at,
            ];
        }
    }

    /** @param  array<string, array{0: list<string>, 1: iterable<array>}>  $tablas */
    private function zip(string $ruta, Course $curso, array $tablas): void
    {
        $zip = new ZipArchive();
        if ($zip->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se pudo crear el ZIP.');
        }
        $temporales = [];
        foreach ([...$tablas, 'diccionario' => [['archivo', 'columna', 'descripcion'], DiccionarioDatos::filas()]] as $nombre => [$columnas, $filas]) {
            $tmp = $temporales[] = tempnam(sys_get_temp_dir(), 'csv');
            $f = fopen($tmp, 'w');
            fwrite($f, "\xEF\xBB\xBF"); // BOM: Excel reconoce UTF-8 (acentos y ñ)
            Celdas::escribirCsv($f, $columnas);
            foreach ($filas as $fila) {
                Celdas::escribirCsv($f, $fila);
            }
            fclose($f);
            $zip->addFile($tmp, "{$nombre}.csv");
        }
        $zip->addFromString('LEEME.txt', $this->leeme($curso, 'csv'));
        $zip->close(); // addFile lee los archivos al cerrar: se borran después
        array_map('unlink', $temporales);
    }

    private function xlsx(string $ruta, Course $curso, array $tablas): void
    {
        $libro = new LibroXlsx();
        $libro->agregarHoja('LEEME', ['nota'], array_map(fn ($l) => [$l], explode("\n", $this->leeme($curso, 'xlsx'))));
        foreach ($tablas as $nombre => [$columnas, $filas]) {
            $libro->agregarHoja($nombre, $columnas, $filas);
        }
        $libro->agregarHoja('diccionario', ['archivo', 'columna', 'descripcion'], DiccionarioDatos::filas());
        $libro->guardar($ruta);
    }

    private function leeme(Course $curso, string $formato): string
    {
        return implode("\n", [
            "Exportación del curso «{$curso->titulo}» -- ".now()->toIso8601String(),
            'Estudiantes incluidos: '.count($this->seudonimos).' (solo con consentimiento de investigación vigente).',
            'Identificador: seudonimo (E-XXXXXX). No hay nombres, correos ni direcciones IP.',
            'Descripción de cada columna: '.($formato === 'xlsx' ? 'hoja «diccionario»' : 'diccionario.csv').' y docs/diccionario-datos.md.',
            'Textos que empiezan con = + - @ llevan un apóstrofo al inicio para que Excel no los ejecute como fórmulas.',
            $formato === 'xlsx' ? 'Cada hoja admite hasta 1 048 575 filas; una tabla más grande (p. ej., eventos) se corta ahí: usa la exportación CSV.' : 'CSV en UTF-8 con BOM, separado por comas.',
        ]);
    }
}
