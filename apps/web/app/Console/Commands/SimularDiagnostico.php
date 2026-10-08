<?php

namespace App\Console\Commands;

use App\Domain\Perfil\CalculadoraPerfil;
use App\Domain\Perfil\ConfiguracionPerfil;
use App\Enums\EstadoInscripcion;
use App\Enums\Rol;
use App\Jobs\AnalizarGrupo;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Console\Command;

/** Solo para desarrollo: crea estudiantes simulados con perfil para probar la decisión y los grupos. */
class SimularDiagnostico extends Command
{
    protected $signature = 'demo:diagnostico
        {curso : id del curso}
        {--n=30 : cuántos estudiantes}
        {--tipo=bimodal : homogeneo, bimodal o disperso}
        {--semilla=7}';

    protected $description = 'Crea estudiantes simulados con perfil (solo con APP_ENV=local)';

    public function handle(): int
    {
        if (! app()->isLocal()) {
            $this->error('Este comando solo corre con APP_ENV=local.');

            return self::FAILURE;
        }

        $curso = Course::findOrFail($this->argument('curso'));
        $calculadora = new CalculadoraPerfil(ConfiguracionPerfil::desde($curso->configuracion ?? []));
        $n = (int) $this->option('n');

        // Se generan todas las muestras ANTES de crear usuarios: Faker (User::factory) consume el mismo
        // generador mt_rand, y así el resultado de una semilla no depende del idioma de Faker.
        mt_srand((int) $this->option('semilla'));
        $muestras = [];
        for ($i = 1; $i <= $n; $i++) {
            [$teorico, $practico] = $this->muestra((string) $this->option('tipo'), $i);
            $muestras[$i] = [$teorico, $practico, [
                'autoeficacia' => $this->entre(2, 7), 'ansiedad' => $this->entre(1, 7), 'metacognicion' => $this->entre(2, 7),
            ]];
        }

        foreach ($muestras as $i => [$teorico, $practico, $mslq]) {
            $usuario = User::factory()->create(['name' => "Simulado {$i}"]);
            $usuario->assignRole(Rol::Estudiante->value);

            $inscripcion = Enrollment::create([
                'course_id' => $curso->id, 'user_id' => $usuario->id,
                'estado' => EstadoInscripcion::ConPerfil, 'inscrito_at' => now(),
            ]);
            StudentProfile::create([
                ...$calculadora->calcular($mslq, $teorico, $teorico, $teorico, $practico),
                'enrollment_id' => $inscripcion->id, 'version' => 1,
            ]);
        }

        AnalizarGrupo::dispatchSync($curso->id);
        $this->info("{$n} estudiantes simulados en el curso {$curso->id}.");

        return self::SUCCESS;
    }

    /** @return array{0: float, 1: float} teórico y práctico, de 0 a 100 */
    private function muestra(string $tipo, int $i): array
    {
        $centro = match ($tipo) {
            'homogeneo' => 55.0,
            'bimodal' => $i % 2 ? 25.0 : 80.0,
            default => $this->entre(5, 95),
        };

        return [
            max(0.0, min(100.0, $centro + $this->entre(-8, 8))),
            max(0.0, min(100.0, $centro + $this->entre(-8, 8))),
        ];
    }

    private function entre(float $min, float $max): float
    {
        return round($min + mt_rand() / mt_getrandmax() * ($max - $min), 1);
    }
}
