<?php

namespace Tests\Feature\Etapa7;

use App\Notifications\AlertaOperacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class OperacionTest extends TestCase
{
    use RefreshDatabase;

    public function test_avisa_una_vez_por_hora_cuando_un_servicio_no_responde(): void
    {
        config(['services.agente.url' => 'http://agente.test', 'services.piston.url' => 'http://piston.test/api/v2',
            'clt4bp.alertas.correo' => 'ops@example.edu']);
        Http::fake(['agente.test/*' => Http::response('caído', 503), 'piston.test/*' => Http::response([])]);
        Notification::fake();

        $this->artisan('operacion:revisar')->expectsOutputToContain('agente no responde')->assertSuccessful();
        $this->artisan('operacion:revisar')->assertSuccessful(); // mismo problema, dentro de la hora: sin correo

        Notification::assertSentOnDemandTimes(AlertaOperacion::class, 1);
        Notification::assertSentOnDemand(AlertaOperacion::class,
            fn (AlertaOperacion $n, array $canales, object $destino) => $destino->routes['mail'] === 'ops@example.edu'
                && array_column($n->problemas, 'clave') === ['agente']);
    }

    public function test_sin_problemas_no_hay_correo(): void
    {
        config(['services.agente.url' => 'http://agente.test', 'services.piston.url' => 'http://piston.test/api/v2',
            'clt4bp.alertas.correo' => 'ops@example.edu']);
        Http::fake(['*' => Http::response(['estado' => 'ok'])]);
        Notification::fake();

        $this->artisan('operacion:revisar')->expectsOutputToContain('Todo en orden')->assertSuccessful();

        Notification::assertNothingSent();
    }
}
