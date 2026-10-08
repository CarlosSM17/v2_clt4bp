<?php

namespace App\Providers;

use App\Enums\Rol;
use App\Models\User;
use App\Services\Codigo\EjecutorCodigo;
use App\Services\Codigo\PistonEjecutor;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\DevCommands;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(EjecutorCodigo::class, PistonEjecutor::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureGates();
        $this->configureRateLimiting();
        $this->configureDevCommands();
    }

    /**
     * «composer run dev»: el worker por omisión solo escucha la cola «default». Los trabajos del agente (generaciones
     * e indexado del material) van a la cola «agente», con su propio worker como en producción (Supervisor), para que
     * una generación de varios minutos no detenga las calificaciones.
     */
    protected function configureDevCommands(): void
    {
        DevCommands::artisan('queue:listen --queue=agente --tries=1 --timeout=0', 'agente');
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(app()->isProduction());

        // Mínimo 10 caracteres siempre; en producción, además, contraseñas no filtradas.
        Password::defaults(fn (): Password => app()->isProduction()
            ? Password::min(10)->letters()->numbers()->uncompromised()
            : Password::min(10));
    }

    protected function configureGates(): void
    {
        Gate::define('personal', fn (User $user) => $user->esPersonal());
        Gate::define('admin', fn (User $user) => $user->hasRole(Rol::Admin->value));
    }

    protected function configureRateLimiting(): void
    {
        // 5 intentos por minuto por correo + IP para el inicio de sesión de la consola
        RateLimiter::for('login-consola', fn (Request $request) => Limit::perMinute(5)
            ->by(strtolower((string) $request->input('email')).'|'.$request->ip()));

        // Pedidos al agente: pocos por minuto bastan para trabajar; más, suele ser un error o un abuso
        RateLimiter::for('agente', fn (Request $request) => Limit::perMinute(6)->by((string) $request->user()?->id));
    }
}
