<?php

namespace App\Console\Commands;

use App\Enums\EstadoInscripcion;
use App\Enums\Rol;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Ensayo general (7.4): cuentas de estudiante sintéticas, inscritas en un curso, para las pruebas de extremo a
 * extremo y de carga. Corre también en producción, pero solo antes del piloto: se niega si el curso ya tiene
 * estudiantes reales. Los correos usan el dominio reservado .invalid, así que ningún aviso sale del servidor.
 * No usa fábricas: en producción no está instalado Faker.
 */
class PrepararEnsayo extends Command
{
    public const DOMINIO = 'ensayo.invalid';

    protected $signature = 'ensayo:estudiantes
        {curso : id del curso de ensayo}
        {--n=100 : cuántas cuentas}';

    protected $description = 'Crea las cuentas ensayo-001@ensayo.invalid… inscritas en un curso (ensayo general)';

    public function handle(): int
    {
        $curso = Course::findOrFail($this->argument('curso'));
        $reales = Enrollment::where('course_id', $curso->id)
            ->whereHas('user', fn ($q) => $q->where('email', 'not like', '%@'.self::DOMINIO))
            ->count();
        if ($reales > 0) {
            $this->error("El curso {$curso->id} ya tiene {$reales} estudiantes reales: el ensayo se hace antes del piloto.");

            return self::FAILURE;
        }
        $password = (string) $this->secret('Contraseña común de las cuentas (mínimo 12 caracteres)');
        if (mb_strlen($password) < 12) {
            $this->error('La contraseña debe tener al menos 12 caracteres.');

            return self::FAILURE;
        }

        $n = (int) $this->option('n');
        $this->withProgressBar(range(1, $n), function (int $i) use ($curso, $password) {
            $usuario = User::firstOrCreate(
                ['email' => sprintf('ensayo-%03d@%s', $i, self::DOMINIO)],
                ['name' => "Ensayo {$i}", 'password' => $password],
            );
            $usuario->forceFill(['email_verified_at' => now()])->save();
            $usuario->syncRoles([Rol::Estudiante->value]);
            Enrollment::firstOrCreate(
                ['course_id' => $curso->id, 'user_id' => $usuario->id],
                ['estado' => EstadoInscripcion::Cursando, 'inscrito_at' => now()],
            );
        });
        $this->newLine();
        $this->info("{$n} cuentas listas en el curso {$curso->id}: ensayo-001@".self::DOMINIO.' a ensayo-'.sprintf('%03d', $n).'@'.self::DOMINIO.'.');

        return self::SUCCESS;
    }
}
