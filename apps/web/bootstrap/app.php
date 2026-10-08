<?php

use App\Http\Middleware\CabecerasSeguridad;
use App\Http\Middleware\EnsureNotSuspended;
use App\Http\Middleware\EnsureStaffHasTwoFactor;
use App\Http\Middleware\EsConectorAgente;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->alias([
            'no-suspendido' => EnsureNotSuspended::class,
            'personal-2fa' => EnsureStaffHasTwoFactor::class,
            'ability' => CheckForAnyAbility::class,
            'conector-agente' => EsConectorAgente::class,
        ]);

        // Detrás de un proxy que termina TLS (Railway): sin confiar en él, Laravel vería http y las URL firmadas de
        // los medios no validarían. Vacío en local y en el servidor propio (nginx en el mismo equipo)
        if ($proxies = env('CLT4BP_PROXIES_CONFIABLES')) {
            $middleware->trustProxies(at: $proxies === '*' ? '*' : explode(',', $proxies));
        }

        $middleware->web(append: [
            CabecerasSeguridad::class, // Etapa 7: CSP con nonce y cabeceras de seguridad
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        // El código fuente que envía el estudiante no debe alterarse: TrimStrings le quitaría
        // el salto de línea final (u otros espacios significativos, en Python) sin que se note.
        $middleware->trimStrings(except: ['codigo']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Las respuestas de error (404, 429, 500…) no atraviesan el «después» de
        // CabecerasSeguridad porque la excepción corta la pila antes de volver a subir por
        // ella: sin esto, ZAP marca CSP/X-Content-Type-Options ausentes en esas páginas.
        $exceptions->respond(function (Response $respuesta, Throwable $e, Request $request) {
            return $request->is('api/*') || $request->expectsJson()
                ? $respuesta
                : CabecerasSeguridad::aplicar($respuesta);
        });

        Integration::handles($exceptions); // Etapa 7: errores al monitoreo (sin efecto si no hay DSN)
    })->create();
