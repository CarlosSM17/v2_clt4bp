<?php

namespace App\Http\Middleware;

use App\Domain\Seguridad\PoliticaContenido;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/** Cabeceras de seguridad y CSP con un nonce por petición para todas las respuestas web. */
class CabecerasSeguridad
{
    public function handle(Request $request, Closure $next): Response
    {
        Vite::useCspNonce(); // @vite agrega este nonce a los <script> y <link> que emite
        $respuesta = $next($request);

        return self::aplicar($respuesta);
    }

    /**
     * Aplica las cabeceras a cualquier respuesta, incluidas las que arma el manejador de
     * excepciones (404, 429, 500…): esas no pasan por el «después» de este middleware porque
     * la excepción corta la pila antes de volver a subir por ella. Ver bootstrap/app.php.
     */
    public static function aplicar(Response $respuesta): Response
    {
        foreach (PoliticaContenido::cabeceras() as $nombre => $valor) {
            $respuesta->headers->set($nombre, $valor);
        }
        // Con `npm run dev`, el archivo public/hot trae el origen del servidor de Vite
        $vite = Vite::isRunningHot() ? trim((string) file_get_contents(Vite::hotFile())) : null;
        $respuesta->headers->set('Content-Security-Policy', PoliticaContenido::web(Vite::useCspNonce(), $vite));

        return $respuesta;
    }
}
