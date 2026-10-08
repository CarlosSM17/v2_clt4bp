<?php

use App\Models\AgentRelayRequest;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Etapa 5: avisos de apertura de clases (en desarrollo: php artisan schedule:work)
Schedule::command('aula:avisar-aperturas')->everyFiveMinutes()->withoutOverlapping();
// Relevo al agente local (ADR 0008): solicitudes y respuestas de más de 24 horas, fuera
Schedule::command('model:prune', ['--model' => [AgentRelayRequest::class]])->hourly();

// Etapa 7: operación
Schedule::command('operacion:revisar')->everyFiveMinutes()->withoutOverlapping()
    ->pingOnSuccessIf(filled(config('clt4bp.alertas.ping')), (string) config('clt4bp.alertas.ping'));
Schedule::command('queue:prune-failed --hours=720')->daily();
Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::command('auth:clear-resets')->everyFifteenMinutes();
