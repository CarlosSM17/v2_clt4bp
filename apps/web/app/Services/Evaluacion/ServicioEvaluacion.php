<?php

namespace App\Services\Evaluacion;

use App\Enums\EstadoInscripcion;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentAdministration;
use App\Models\InstrumentResponse;
use App\Services\Aula\ContextoAula;
use App\Services\Diagnostico\ServicioDiagnostico;
use App\Support\Auditoria;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Evaluación durante y al final del curso: la escala CS al cerrar cada clase de tareas y la ventana de
 * evaluación final (post-test en forma paralela, MSLQ final e IMMS).
 */
class ServicioEvaluacion
{
    /** Instrumentos que se pueden aplicar al final. El MSLQ se repite para comparar con el diagnóstico. */
    public const FINALES = ['mslq', 'imms', 'cis'];

    public function __construct(private readonly ServicioDiagnostico $diagnostico) {}

    /**
     * Abre (o reprograma) la evaluación final: aplica los instrumentos «post» y pone la misma ventana a todas
     * las pruebas post del curso.
     *
     * @param  list<string>  $instrumentos  claves de FINALES
     * @return array{instrumentos: list<string>, pruebas: int, advertencias: list<string>}
     */
    public function preparar(Course $curso, CarbonInterface $abre, CarbonInterface $cierra, array $instrumentos): array
    {
        $pruebas = Assessment::where('course_id', $curso->id)->where('momento', 'post')->get();
        if ($pruebas->isEmpty()) {
            throw ValidationException::withMessages(['pruebas' => 'Crea primero las pruebas post (forma B) en la pestaña Pruebas.']);
        }

        $advertencias = [];
        foreach (['teorica' => 'teórica', 'practica' => 'práctica'] as $tipo => $nombre) {
            $pre = Assessment::where('course_id', $curso->id)->where('momento', 'pre')->where('tipo', $tipo)->first();
            $post = $pruebas->firstWhere('tipo', $tipo);
            if ($pre && ! $post) {
                $advertencias[] = "Hay pre-test {$nombre} pero no post-test {$nombre}: esa parte no tendrá comparación.";
            }
            if ($pre && $post && $pre->forma === $post->forma) {
                $advertencias[] = "El post-test {$nombre} usa la misma forma que el pre-test ({$pre->forma}); usa la forma paralela para evitar el efecto de práctica.";
            }
        }

        $aplicados = [];
        DB::transaction(function () use ($curso, $abre, $cierra, $instrumentos, $pruebas, &$aplicados, &$advertencias) {
            foreach ($instrumentos as $clave) {
                $instrumento = $this->instrumento($curso, $clave);
                if (! $instrumento) {
                    $advertencias[] = "El instrumento «{$clave}» no está cargado: php artisan instrumentos:cargar ../../instruments/{$clave}.json";

                    continue;
                }
                InstrumentAdministration::updateOrCreate(
                    ['course_id' => $curso->id, 'instrument_id' => $instrumento->id, 'momento' => 'post'],
                    ['abre_at' => $abre, 'cierra_at' => $cierra],
                );
                $aplicados[] = $clave;
            }
            Assessment::whereIn('id', $pruebas->pluck('id'))->update(['abre_at' => $abre, 'cierra_at' => $cierra]);
        });
        Auditoria::registrar('evaluacion_final.preparada', $curso, [
            'abre_at' => $abre->toIso8601String(), 'cierra_at' => $cierra->toIso8601String(), 'instrumentos' => $aplicados,
        ]);

        return ['instrumentos' => $aplicados, 'pruebas' => $pruebas->count(), 'advertencias' => $advertencias];
    }

    /** @return array{abre_at: CarbonInterface, cierra_at: ?CarbonInterface}|null */
    public function ventana(int $cursoId): ?array
    {
        $prueba = Assessment::where('course_id', $cursoId)->where('momento', 'post')->whereNotNull('abre_at')->orderBy('abre_at')->first();

        return $prueba ? ['abre_at' => $prueba->abre_at, 'cierra_at' => $prueba->cierra_at] : null;
    }

    public function abierta(int $cursoId): bool
    {
        $v = $this->ventana($cursoId);

        return $v !== null && $v['abre_at']->isPast() && (! $v['cierra_at'] || $v['cierra_at']->isFuture());
    }

    /** Al entrar a la evaluación final, la inscripción cambia de estado (una sola vez). */
    public function iniciar(Enrollment $inscripcion): void
    {
        if (in_array($inscripcion->estado, [EstadoInscripcion::ConPerfil, EstadoInscripcion::Cursando], true)) {
            $inscripcion->update(['estado' => EstadoInscripcion::EvaluacionFinal]);
        }
    }

    /** @return list<array{tipo: string, id: int, titulo: string, estado: string}> */
    public function pasos(Enrollment $inscripcion): array
    {
        return $this->diagnostico->pasos($inscripcion, 'post');
    }

    /** Todo lo «post» completo → concluido. */
    public function verificarCompleto(Enrollment $inscripcion): bool
    {
        $pasos = $this->pasos($inscripcion);
        $completo = $pasos !== [] && collect($pasos)->every(fn ($p) => $p['estado'] === 'completo');
        if ($completo && $inscripcion->estado === EstadoInscripcion::EvaluacionFinal) {
            $inscripcion->update(['estado' => EstadoInscripcion::Concluido]);
        }

        return $completo;
    }

    /**
     * La escala CS pendiente: la de la primera clase ya completa que el estudiante no ha valorado.
     * La aplicación de la clase se crea la primera vez que se necesita.
     *
     * @return array{aplicacion_id: int, clase_uid: string, orden: int, titulo: string}|null
     */
    public function escalaCarga(ContextoAula $ctx): ?array
    {
        $cs = $this->instrumento($ctx->inscripcion->course, 'cs');
        if (! $cs) {
            return null;
        }
        $valoradas = InstrumentResponse::where('enrollment_id', $ctx->inscripcion->id)->whereNotNull('completado_at')
            ->join('instrument_administrations as a', 'a.id', '=', 'instrument_responses.instrument_administration_id')
            ->where('a.momento', 'clase')->where('a.instrument_id', $cs->id)->pluck('a.clase_uid')->all();

        foreach ($ctx->clases as $c) {
            if (! in_array($c['uid'], $valoradas, true) && $ctx->claseCompleta($c['uid'])) {
                $aplicacion = InstrumentAdministration::firstOrCreate(
                    ['course_id' => $ctx->inscripcion->course_id, 'instrument_id' => $cs->id, 'momento' => 'clase', 'clase_uid' => $c['uid']],
                );

                return ['aplicacion_id' => $aplicacion->id, 'clase_uid' => $c['uid'], 'orden' => $c['orden'], 'titulo' => $c['titulo']];
            }
        }

        return null;
    }

    /** La misma versión que se usó en el diagnóstico (para comparar pre y post); si no hubo, la más reciente. */
    private function instrumento(Course $curso, string $clave): ?Instrument
    {
        $usado = InstrumentAdministration::where('course_id', $curso->id)
            ->whereHas('instrument', fn ($q) => $q->where('clave', $clave))->orderBy('id')->first()?->instrument;

        return $usado ?? Instrument::where('clave', $clave)->latest('id')->first();
    }
}
