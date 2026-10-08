<?php

namespace App\Domain\Aula;

use DateTimeInterface;

/**
 * Cuándo puede un estudiante trabajar en una clase de tareas (paso 8 de CLT4BP: plan de implementación).
 *
 * sin_programar → el instructor no le ha puesto fecha a esa clase para ese grupo
 * programada    → tiene fecha, pero aún no llega
 * bloqueada     → ya es la fecha, pero falta terminar la clase anterior
 * abierta       → se puede trabajar y enviar
 * cerrada       → pasó la fecha de cierre: se puede consultar y practicar, pero no enviar
 */
final class Disponibilidad
{
    public const SIN_PROGRAMAR = 'sin_programar';

    public const PROGRAMADA = 'programada';

    public const BLOQUEADA = 'bloqueada';

    public const ABIERTA = 'abierta';

    public const CERRADA = 'cerrada';

    /** @param  array{abre_at: DateTimeInterface, cierra_at: ?DateTimeInterface, requiere_anterior: bool}|null  $activacion */
    public static function estado(?array $activacion, DateTimeInterface $ahora, bool $anteriorCompleta): string
    {
        return match (true) {
            $activacion === null => self::SIN_PROGRAMAR,
            $ahora < $activacion['abre_at'] => self::PROGRAMADA,
            $activacion['requiere_anterior'] && ! $anteriorCompleta => self::BLOQUEADA,
            $activacion['cierra_at'] !== null && $ahora > $activacion['cierra_at'] => self::CERRADA,
            default => self::ABIERTA,
        };
    }

    public static function puedeVer(string $estado): bool
    {
        return in_array($estado, [self::ABIERTA, self::CERRADA], true);
    }

    /**
     * La activación que aplica a un estudiante: la de su grupo gana a la del curso completo (grupo null).
     *
     * @param  list<array{clase_uid: string, diff_group_id: ?int}>  $activaciones
     */
    public static function elegir(array $activaciones, string $claseUid, ?int $grupoId): ?array
    {
        $general = null;
        foreach ($activaciones as $a) {
            if ($a['clase_uid'] !== $claseUid) {
                continue;
            }
            if ($grupoId !== null && $a['diff_group_id'] === $grupoId) {
                return $a;
            }
            if ($a['diff_group_id'] === null) {
                $general = $a;
            }
        }

        return $general;
    }
}
