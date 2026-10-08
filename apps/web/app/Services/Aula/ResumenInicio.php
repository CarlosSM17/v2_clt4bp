<?php

namespace App\Services\Aula;

use App\Domain\Aula\Disponibilidad;
use App\Enums\EstadoInscripcion;
use App\Models\Enrollment;
use App\Models\Release;
use App\Models\User;
use App\Services\Diagnostico\ServicioDiagnostico;
use App\Services\Evaluacion\ServicioEvaluacion;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * La página de Inicio del estudiante: por cada curso, el siguiente paso que le toca, su avance y las fechas
 * próximas. Solo lee: no crea registros (el mapa del curso y el diagnóstico siguen siendo quienes los crean).
 */
class ResumenInicio
{
    public function __construct(
        private readonly ServicioAula $aula,
        private readonly ServicioDiagnostico $diagnostico,
        private readonly ServicioEvaluacion $evaluacion,
    ) {}

    /** @return list<array<string, mixed>> */
    public function cursos(User $estudiante): array
    {
        return $estudiante->enrollments()
            ->with('course:id,titulo,lenguaje', 'membresia.group:id,nombre')
            ->whereNotIn('estado', [EstadoInscripcion::Rechazada->value, EstadoInscripcion::Baja->value])
            ->latest()->get()
            ->map(fn (Enrollment $e) => $this->curso($e))
            ->all();
    }

    private function curso(Enrollment $e): array
    {
        $curso = $e->course;
        $base = [
            'curso' => ['id' => $curso->id, 'titulo' => $curso->titulo, 'lenguaje' => $curso->lenguaje->value],
            'estado' => $e->estado->value,
            'ruta' => $e->membresia?->group?->nombre,
            'avance' => null,
            'clases' => [],
            'fechas' => [],
        ];

        if ($e->estado === EstadoInscripcion::Solicitud) {
            return [...$base, 'siguiente' => $this->paso('espera', 'Tu solicitud de inscripción está pendiente: tu instructor debe aprobarla.')];
        }

        if (in_array($e->estado, [EstadoInscripcion::Inscrito, EstadoInscripcion::Diagnostico], true)) {
            $pasos = $this->diagnostico->pasos($e);
            $faltan = collect($pasos)->where('estado', '!=', 'completo')->count();

            return [...$base, 'siguiente' => $faltan > 0 || $pasos === []
                ? $this->paso('diagnostico', $pasos === []
                    ? 'Tu instructor aún no abre el diagnóstico.'
                    : "Contesta el diagnóstico: te faltan {$faltan} de ".count($pasos).' pasos.', route('diagnostico.index', $curso))
                : $this->paso('espera', 'Terminaste el diagnóstico. Tu instructor está preparando el material.'),
            ];
        }

        if (! Release::vigente($curso->id)) {
            return [...$base, 'siguiente' => $this->paso('espera', 'Tu instructor todavía no publica material en este curso.')];
        }

        $ctx = $this->aula->contexto($e);
        $tareas = collect($ctx->manifiesto['tareas']);
        $completada = fn (array $t) => $ctx->progreso->get($t['uid'])?->estado === 'completada';

        $clases = collect($ctx->clases)->map(function (array $c) use ($ctx, $completada) {
            $deClase = $ctx->tareasDe($c['uid']);
            $activacion = $ctx->estados[$c['uid']]['activacion'];

            return [
                'uid' => $c['uid'], 'orden' => $c['orden'], 'titulo' => $c['titulo'],
                'estado' => $ctx->estados[$c['uid']]['estado'],
                'completadas' => collect($deClase)->filter($completada)->count(), 'total' => count($deClase),
                'abre_at' => $this->fecha($activacion['abre_at'] ?? null), 'cierra_at' => $this->fecha($activacion['cierra_at'] ?? null),
            ];
        });

        return [
            ...$base,
            'avance' => ['completadas' => $tareas->filter($completada)->count(), 'total' => $tareas->count()],
            'clases' => $clases->values()->all(),
            'fechas' => $this->fechas($clases->all()),
            'siguiente' => $this->siguiente($e, $ctx, $completada),
        ];
    }

    private function siguiente(Enrollment $e, ContextoAula $ctx, \Closure $completada): array
    {
        $curso = $e->course;
        if ($this->evaluacion->abierta($curso->id) && $e->estado !== EstadoInscripcion::Concluido
            && collect($this->evaluacion->pasos($e))->contains(fn ($p) => $p['estado'] !== 'completo')) {
            return $this->paso('evaluacion', 'La evaluación final está abierta: contéstala antes de que cierre.', route('evaluacion.final', $curso));
        }

        // La primera tarea sin completar de la primera clase abierta
        foreach ($ctx->clases as $c) {
            if ($ctx->estados[$c['uid']]['estado'] !== Disponibilidad::ABIERTA) {
                continue;
            }
            $pendiente = collect($ctx->tareasDe($c['uid']))->first(fn ($t) => ! $completada($t));
            if ($pendiente) {
                return $this->paso('tarea', "Continúa con «{$pendiente['titulo']}» (clase {$c['orden']}: {$c['titulo']}).",
                    route('aula.tarea', [$curso, $pendiente['uid']]));
            }
        }

        $proxima = collect($ctx->clases)->first(fn ($c) => $ctx->estados[$c['uid']]['estado'] === Disponibilidad::PROGRAMADA);
        if ($proxima && ($abre = $this->fecha($ctx->estados[$proxima['uid']]['activacion']['abre_at'] ?? null))) {
            return $this->paso('espera', "Vas al día. La clase {$proxima['orden']} abre pronto: revisa las fechas.", route('aula.mapa', $curso));
        }

        return $this->paso('al_dia', 'Vas al día con todas las clases abiertas.', route('aula.mapa', $curso));
    }

    /** Cierres de clases abiertas y aperturas en los próximos 14 días, en orden. */
    private function fechas(array $clases): array
    {
        $limite = now()->addDays(14);
        $fechas = [];
        foreach ($clases as $c) {
            if ($c['estado'] === Disponibilidad::ABIERTA && $c['cierra_at'] && $c['cierra_at']['iso'] <= $limite->toIso8601String()) {
                $fechas[] = ['cuando' => $c['cierra_at'], 'texto' => "Cierra la clase {$c['orden']}: {$c['titulo']}"];
            }
            if ($c['estado'] === Disponibilidad::PROGRAMADA && $c['abre_at'] && $c['abre_at']['iso'] <= $limite->toIso8601String()) {
                $fechas[] = ['cuando' => $c['abre_at'], 'texto' => "Abre la clase {$c['orden']}: {$c['titulo']}"];
            }
        }

        return collect($fechas)->sortBy('cuando.iso')->values()->all();
    }

    private function paso(string $tipo, string $texto, ?string $url = null): array
    {
        return ['tipo' => $tipo, 'texto' => $texto, 'url' => $url];
    }

    private function fecha(mixed $valor): ?array
    {
        if (! $valor) {
            return null;
        }
        $f = $valor instanceof CarbonInterface ? $valor : CarbonImmutable::parse($valor);

        return ['iso' => $f->toIso8601String()]; // se formatea en el navegador, en la zona horaria del estudiante
    }
}
