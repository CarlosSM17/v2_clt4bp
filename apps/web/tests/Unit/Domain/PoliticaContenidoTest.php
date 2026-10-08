<?php

namespace Tests\Unit\Domain;

use App\Domain\Seguridad\PoliticaContenido;
use PHPUnit\Framework\TestCase;

class PoliticaContenidoTest extends TestCase
{
    /** @return array<string, string> directiva => valor */
    private function directivas(string $csp): array
    {
        $r = [];
        foreach (explode('; ', $csp) as $d) {
            [$nombre, $valor] = explode(' ', $d, 2);
            $r[$nombre] = $valor;
        }

        return $r;
    }

    public function test_produccion_solo_scripts_propios_con_nonce(): void
    {
        $d = $this->directivas(PoliticaContenido::web('abc123'));

        $this->assertSame("'self' 'nonce-abc123'", $d['script-src']);
        $this->assertStringNotContainsString('nonce', $d['style-src']); // si no, se ignora 'unsafe-inline'
        $this->assertStringContainsString("'unsafe-inline'", $d['style-src']);
        $this->assertSame("'self'", $d['connect-src']);
        $this->assertSame("'none'", $d['frame-ancestors']);
        $this->assertSame("'none'", $d['object-src']);
        $this->assertStringNotContainsString('unsafe-eval', PoliticaContenido::web('abc123'));
    }

    public function test_en_local_permite_el_servidor_de_vite_y_su_websocket(): void
    {
        $d = $this->directivas(PoliticaContenido::web('n', 'http://[::1]:5173'));

        $this->assertSame("'self' 'nonce-n' http://[::1]:5173", $d['script-src']);
        $this->assertSame("'self' http://[::1]:5173 ws://[::1]:5173", $d['connect-src']);
        // Vite sirve las fuentes del kit de inicio en desarrollo (__laravel_vite_plugin__/fonts/...)
        $this->assertStringContainsString('http://[::1]:5173', $d['font-src']);
    }

    public function test_cabeceras_basicas(): void
    {
        $c = PoliticaContenido::cabeceras();

        $this->assertSame('nosniff', $c['X-Content-Type-Options']);
        $this->assertSame('DENY', $c['X-Frame-Options']);
        $this->assertStringContainsString('camera=()', $c['Permissions-Policy']);
    }
}
