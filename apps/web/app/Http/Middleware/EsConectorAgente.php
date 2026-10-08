<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rutas del relevo (ADR 0008): solo el conector del agente local, con el mismo secreto que Laravel usa con el agente
 * (AGENTE_TOKEN), y solo si la plataforma está en modo relevo.
 */
class EsConectorAgente
{
    public function handle(Request $request, Closure $next): Response
    {
        $secreto = (string) config('services.agente.token');
        $recibido = (string) $request->header('X-Agente-Token');

        abort_unless(config('services.agente.modo') === 'relevo', 404);
        abort_if($secreto === '' || ! hash_equals($secreto, $recibido), 403, 'Token del conector inválido.');

        return $next($request);
    }
}
