<?php

namespace App\Domain\Seguridad;

/**
 * Política de seguridad de contenido (CSP) y cabeceras de las páginas web (ASVS V3). Clase pura: se prueba sin Laravel.
 *
 * - Scripts: solo los del propio sitio y los que llevan el nonce de la petición (@vite y el script en línea del layout).
 * - Estilos: 'unsafe-inline', porque Vue, Mermaid y la barra de progreso de Inertia escriben estilos en línea.
 *   A style-src no se le pone nonce: con un nonce presente, los navegadores ignoran 'unsafe-inline'.
 * - Fuentes: fonts.bunny.net, la que trae el kit de inicio.
 */
final class PoliticaContenido
{
    public const FUENTES = 'https://fonts.bunny.net';

    /** @param  ?string  $vite  origen del servidor de desarrollo de Vite (solo en local), p. ej. http://[::1]:5173 */
    public static function web(string $nonce, ?string $vite = null): string
    {
        $dev = $vite ? " {$vite}" : '';
        $ws = $vite ? ' '.preg_replace('#^http#', 'ws', $vite) : '';

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'{$dev}",
            "style-src 'self' 'unsafe-inline' ".self::FUENTES.$dev,
            "font-src 'self' ".self::FUENTES.$dev,
            "img-src 'self' data: blob:",
            "media-src 'self' blob:",
            "connect-src 'self'{$dev}{$ws}",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }

    /** @return array<string, string> */
    public static function cabeceras(): array
    {
        return [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];
    }
}
