<?php

namespace App\Services\Aula;

use App\Domain\Aula\AplicadorVariantes;
use App\Domain\Aula\Disponibilidad;
use App\Enums\EstadoInscripcion;
use App\Models\Activation;
use App\Models\Enrollment;
use App\Models\Release;
use App\Models\TaskProgress;
use Illuminate\Support\Facades\URL;

/**
 * Lo que ve un estudiante: la publicación vigente, con las variantes de su grupo aplicadas
 * y filtrada por las fechas de activación.
 */
class ServicioAula
{
    /**
     * Cada método de los controladores del aula llama a esto una sola vez, así que no hace falta
     * memoizar aquí: un caché por instancia sobreviviría entre peticiones si el contenedor llegara
     * a reutilizar esta instancia (como pasa en las pruebas HTTP y bajo Octane), sirviendo un
     * contexto obsoleto tras publicar o activar una clase.
     */
    public function contexto(Enrollment $inscripcion): ContextoAula
    {
        return $this->armar($inscripcion);
    }

    private function armar(Enrollment $inscripcion): ContextoAula
    {
        $release = Release::vigente($inscripcion->course_id);
        abort_unless($release, 404, 'Tu instructor todavía no publica material en este curso.');

        $grupo = $inscripcion->membresia?->group;
        $manifiesto = AplicadorVariantes::paraGrupo($release->manifiesto, $grupo?->clave);
        $activaciones = Activation::where('course_id', $inscripcion->course_id)->get()
            ->map(fn (Activation $a) => [
                'clase_uid' => $a->clase_uid, 'diff_group_id' => $a->diff_group_id,
                'abre_at' => $a->abre_at, 'cierra_at' => $a->cierra_at,
                'requiere_anterior' => $a->requiere_anterior,
            ])->all();
        $progreso = TaskProgress::where('enrollment_id', $inscripcion->id)->get()->keyBy('tarea_uid');

        // Estado de cada clase, en orden: «requiere_anterior» mira si la clase previa quedó completa
        $clases = collect($manifiesto['clases'])->sortBy('orden')->values();
        $estados = [];
        $anteriorCompleta = true;
        foreach ($clases as $c) {
            $activacion = Disponibilidad::elegir($activaciones, $c['uid'], $grupo?->id);
            $estados[$c['uid']] = ['estado' => Disponibilidad::estado($activacion, now()->toImmutable(), $anteriorCompleta), 'activacion' => $activacion];
            $tareas = collect($manifiesto['tareas'])->where('clase_uid', $c['uid']);
            // Completa = cada tarea tiene al menos un envío o quedó completada (no se exige aprobar)
            $anteriorCompleta = $tareas->every(fn ($t) => ($p = $progreso->get($t['uid'])) && ($p->intentos > 0 || $p->estado === 'completada'));
        }

        return new ContextoAula($inscripcion, $release, $grupo, $manifiesto, $clases->all(), $estados, $progreso);
    }

    /** La primera vez que el estudiante entra a una clase, su inscripción pasa a «cursando». */
    public function marcarCursando(Enrollment $inscripcion): void
    {
        if ($inscripcion->estado === EstadoInscripcion::ConPerfil) {
            $inscripcion->update(['estado' => EstadoInscripcion::Cursando]);
        }
    }

    /**
     * Medios citados en los textos, con URL firmada que vence en 2 horas.
     *
     * @return array<string, array{tipo: string, titulo: string, segmentos: array, transcripcion: ?string, url: string}>
     */
    public function medios(ContextoAula $ctx, string ...$textos): array
    {
        preg_match_all('/\(media:([A-Za-z0-9_-]+)\)/', implode("\n", $textos), $m);
        $porUid = collect($ctx->release->manifiesto['medios'] ?? [])->keyBy('uid');

        return collect(array_unique($m[1]))->filter(fn ($uid) => $porUid->has($uid))
            ->mapWithKeys(fn ($uid) => [$uid => [
                ...collect($porUid[$uid])->only('tipo', 'titulo', 'segmentos', 'transcripcion')->all(),
                'url' => URL::temporarySignedRoute('aula.medio', now()->addHours(2), ['curso' => $ctx->inscripcion->course_id, 'uid' => $uid]),
            ]])->all();
    }
}
