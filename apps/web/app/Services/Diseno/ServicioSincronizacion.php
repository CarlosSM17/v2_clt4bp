<?php

namespace App\Services\Diseno;

use App\Domain\Diseno\ReglaSincronizacion;
use App\Domain\Diseno\TiposElemento;
use App\Models\AgentRun;
use App\Models\Course;
use App\Models\DesignElement;
use App\Models\DiffGroup;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Recibe y entrega cambios del diseño entre la consola y el servidor. */
class ServicioSincronizacion
{
    public function __construct(private readonly ValidadorContratos $validador) {}

    /** @return array{cursor: int, cambios: list<array<string, mixed>>, hay_mas: bool, grupos: list<array>} */
    public function cambiosDesde(Course $curso, int $desde, int $limite = 500): array
    {
        $filas = DesignElement::where('course_id', $curso->id)->where('seq', '>', $desde)
            ->orderBy('seq')->limit($limite + 1)->get();
        $hayMas = $filas->count() > $limite;
        $filas = $filas->take($limite);

        return [
            'cursor' => (int) ($filas->last()?->seq ?? $desde),
            'cambios' => $filas->map->paraSincronizar()->values()->all(),
            'hay_mas' => $hayMas,
            // La consola guarda los grupos para trabajar variantes sin conexión
            'grupos' => DiffGroup::where('course_id', $curso->id)->orderBy('orden')->get(['clave', 'nombre', 'nivel'])->toArray(),
        ];
    }

    /**
     * Aplica un cambio. $cambio: uid, tipo, base_version, eliminar y contenido (objeto del JSON original).
     *
     * @return array{uid: string, estado: string, version?: int, servidor?: array, errores?: array}
     */
    public function aplicar(Course $curso, User $usuario, object $cambio): array
    {
        $uid = (string) $cambio->uid;
        $eliminar = (bool) ($cambio->eliminar ?? false);
        $contenido = $eliminar ? null : ($cambio->contenido ?? null);

        if (! $eliminar) {
            $errores = $this->validar((string) $cambio->tipo, $uid, $contenido);
            if ($errores) {
                return ['uid' => $uid, 'estado' => 'invalido', 'errores' => $errores];
            }
        }

        return DB::transaction(function () use ($curso, $usuario, $cambio, $uid, $eliminar, $contenido) {
            // Serializa las escrituras del curso: así el orden de seq es el orden en que se confirman
            DB::select('select pg_advisory_xact_lock(?)', [$curso->id]);

            $elemento = DesignElement::where('course_id', $curso->id)->where('uid', $uid)->lockForUpdate()->first();

            $decision = ReglaSincronizacion::decidir(
                $elemento ? ['version' => $elemento->version, 'contenido' => $elemento->contenido, 'eliminado' => $elemento->eliminado_at !== null] : null,
                (int) $cambio->base_version,
                $contenido,
                $eliminar,
            );

            if ($decision === ReglaSincronizacion::CONFLICTO) {
                return ['uid' => $uid, 'estado' => 'conflicto', 'servidor' => $elemento->paraSincronizar()];
            }
            if ($decision === ReglaSincronizacion::YA_APLICADO) {
                return ['uid' => $uid, 'estado' => 'ok', 'version' => $elemento?->version ?? 0];
            }
            if ($elemento && $elemento->tipo !== $cambio->tipo) {
                return ['uid' => $uid, 'estado' => 'invalido', 'errores' => ['/' => ['Un elemento no puede cambiar de tipo.']]];
            }

            $corrida = $eliminar ? null : $this->corridaDelCurso($curso, $cambio->agent_run_id ?? null);
            $seq = (int) DB::scalar("select nextval('design_seq')");
            $elemento ??= new DesignElement(['course_id' => $curso->id, 'uid' => $uid, 'tipo' => $cambio->tipo, 'version' => 0]);
            $elemento->fill([
                'padre_uid' => $eliminar ? $elemento->padre_uid : TiposElemento::padre($elemento->tipo, $contenido),
                'orden' => $eliminar ? $elemento->orden : TiposElemento::orden($contenido),
                'contenido' => $eliminar ? $elemento->contenido : $contenido,
                // Cualquier cambio a algo aprobado lo regresa a borrador: hay que volver a revisarlo
                'estado' => 'borrador',
                'version' => $elemento->version + 1,
                // autor_tipo = quién hizo ESTA versión; agent_run_id = de qué propuesta nació (se conserva)
                'autor_tipo' => $corrida ? 'agente' : 'humano',
                'agent_run_id' => $corrida ?? $elemento->agent_run_id,
                'actualizado_por' => $usuario->id,
                'seq' => $seq,
                'eliminado_at' => $eliminar ? now() : null,
            ])->save();

            $elemento->versiones()->create([
                'version' => $elemento->version,
                'contenido' => $elemento->contenido,
                'estado' => $elemento->estado,
                'autor_tipo' => $elemento->autor_tipo,
                'actualizado_por' => $usuario->id,
                'eliminado' => $eliminar,
            ]);

            return ['uid' => $uid, 'estado' => 'ok', 'version' => $elemento->version];
        });
    }

    /** La corrida solo cuenta si es de un trabajo de este mismo curso. */
    private function corridaDelCurso(Course $curso, mixed $id): ?int
    {
        return is_int($id)
            ? AgentRun::whereKey($id)->whereHas('trabajo', fn ($q) => $q->where('course_id', $curso->id))->value('id')
            : null;
    }

    /** @return array<string, list<string>> */
    private function validar(string $tipo, string $uid, mixed $contenido): array
    {
        if (! is_object($contenido)) {
            return ['/' => ['Falta el contenido.']];
        }
        if (($contenido->uid ?? null) !== $uid) {
            return ['/uid' => ['El uid del contenido no coincide con el del cambio.']];
        }

        return $this->validador->errores(TiposElemento::esquema($tipo), $contenido);
    }
}
