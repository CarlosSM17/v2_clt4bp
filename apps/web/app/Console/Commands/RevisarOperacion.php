<?php

namespace App\Console\Commands;

use App\Domain\Operacion\Diagnostico;
use App\Models\AgentRun;
use App\Notifications\AlertaOperacion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

/** Revisión de operación cada 5 minutos (7.5): servicios, colas, trabajos fallidos, disco y gasto del agente. */
class RevisarOperacion extends Command
{
    protected $signature = 'operacion:revisar';

    protected $description = 'Revisa servicios, colas, disco y gasto del agente, y avisa por correo si algo anda mal';

    public function handle(): int
    {
        $c = config('clt4bp.alertas');
        $problemas = (new Diagnostico((int) $c['cola_minutos'], (float) $c['disco_libre_minimo'], (float) $c['gasto_diario_usd']))
            ->problemas($this->medir());

        foreach ($problemas as $p) {
            $this->warn($p['texto']);
        }
        // Cada problema se avisa como máximo una vez por hora mientras siga
        $nuevos = array_values(array_filter($problemas, fn (array $p) => Cache::add("alerta:{$p['clave']}", true, now()->addHour())));
        if ($nuevos && $c['correo']) {
            Notification::route('mail', $c['correo'])->notifyNow(new AlertaOperacion($nuevos));
        }
        if (! $problemas) {
            $this->info('Todo en orden.');
        }

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function medir(): array
    {
        $responde = fn (string $url): bool => rescue(fn () => Http::timeout(5)->get($url)->successful(), false, report: false);

        $ahora = now()->getTimestamp();
        $colas = [];
        foreach (['default', 'agente'] as $cola) {
            $antiguo = DB::table('jobs')->where('queue', $cola)->whereNull('reserved_at')->min('available_at');
            $colas[$cola] = $antiguo ? max(0, ($ahora - (int) $antiguo) / 60) : null;
        }

        return [
            // Por el relevo (ADR 0008), el agente vive en el equipo del instructor: que esté apagado es normal y no hay
            // dirección a la que preguntar; la consola avisa en el acto si hace falta y no hay ninguno conectado
            'agente' => config('services.agente.modo') === 'relevo'
                || $responde(rtrim((string) config('services.agente.url'), '/').'/salud'),
            'piston' => $responde(rtrim((string) config('services.piston.url'), '/').'/runtimes'),
            'cola_mas_antigua_min' => $colas,
            'fallidos_ultima_hora' => DB::table('failed_jobs')->where('failed_at', '>=', now()->subHour())->count(),
            'disco_libre' => disk_free_space(storage_path()) / disk_total_space(storage_path()),
            'gasto_hoy_usd' => (float) AgentRun::where('created_at', '>=', now()->startOfDay())->sum('costo_usd'),
        ];
    }
}
