<?php

namespace App\Domain\Perfil;

/** Parámetros del perfil. Los valores por defecto son la propuesta; cada curso puede cambiarlos. */
final class ConfiguracionPerfil
{
    public function __construct(
        public readonly float $pesoTeorico = 0.5,
        public readonly float $pesoPractico = 0.5,
        public readonly float $umbralIntermedio = 40.0,   // CP >= 40 → intermedio
        public readonly float $umbralAvanzado = 70.0,     // CP > 70 → avanzado
        public readonly float $cvMaximoHomogeneo = 0.25,
        public readonly float $proporcionModalMinima = 0.70,
        public readonly int $minimoConfiable = 10,
    ) {}

    /** @param array<string, mixed> $config el JSON `configuracion` del curso */
    public static function desde(array $config): self
    {
        $p = $config['perfil'] ?? [];

        return new self(
            pesoTeorico: (float) ($p['peso_teorico'] ?? 0.5),
            pesoPractico: (float) ($p['peso_practico'] ?? 0.5),
            umbralIntermedio: (float) ($p['umbral_intermedio'] ?? 40),
            umbralAvanzado: (float) ($p['umbral_avanzado'] ?? 70),
            cvMaximoHomogeneo: (float) ($p['cv_maximo'] ?? 0.25),
            proporcionModalMinima: (float) ($p['proporcion_modal'] ?? 0.70),
            minimoConfiable: (int) ($p['minimo_confiable'] ?? 10),
        );
    }
}
