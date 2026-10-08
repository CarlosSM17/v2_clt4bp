<?php

namespace App\Console\Commands;

use App\Domain\Perfil\CalculadoraPerfil;
use App\Domain\Perfil\ConfiguracionPerfil;
use App\Enums\EstadoInscripcion;
use App\Enums\Rol;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Instrument;
use App\Models\InstrumentAdministration;
use App\Models\InstrumentResponse;
use App\Models\StudentProfile;
use App\Models\User;
use App\Services\Evaluacion\ServicioResultados;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Solo para desarrollo: estudiantes con pre-test, post-test, MSLQ e IMMS simulados, y un CSV con los mismos
 * números para comprobar en R o JASP los estadísticos de la pestaña «Resultados» (criterio de la Etapa 6).
 */
class SimularResultados extends Command
{
    protected $signature = 'demo:resultados
        {curso : id del curso experimental}
        {--n=30 : estudiantes por curso}
        {--efecto=0.5 : tamaño del efecto pre/post del curso experimental (d_z esperada)}
        {--control= : id de otro curso que hará de grupo de control (efecto 0.2)}
        {--semilla=7}';

    protected $description = 'Simula resultados pre/post y escribe storage/app/demo-resultados.csv (solo con APP_ENV=local)';

    public function handle(ServicioResultados $resultados): int
    {
        if (! app()->isLocal()) {
            $this->error('Este comando solo corre con APP_ENV=local.');

            return self::FAILURE;
        }
        mt_srand((int) $this->option('semilla'));

        $filas = [];
        $cursos = ['experimental' => [Course::findOrFail($this->argument('curso')), (float) $this->option('efecto')]];
        if ($this->option('control')) {
            $cursos['control'] = [Course::findOrFail($this->option('control')), 0.2];
        }
        foreach ($cursos as $grupo => [$curso, $efecto]) {
            $ids = $this->simular($curso, (int) $this->option('n'), $efecto);
            foreach ($resultados->puntajes($curso, $ids) as $id => $p) {
                $filas[] = [$grupo, Enrollment::find($id)->seudonimo,
                    $p['pre']['teorico'], $p['pre']['practico'], $p['pre']['global'],
                    $p['post']['teorico'], $p['post']['practico'], $p['post']['global']];
            }
        }

        $csv = "grupo,seudonimo,pre_teorico,pre_practico,pre_global,post_teorico,post_practico,post_global\n"
            .implode("\n", array_map(fn ($f) => implode(',', $f), $filas))."\n";
        Storage::disk('local')->put('demo-resultados.csv', $csv);
        $this->info(count($filas).' estudiantes simulados. CSV: '.Storage::disk('local')->path('demo-resultados.csv'));

        return self::SUCCESS;
    }

    /** @return list<int> ids de las inscripciones creadas */
    private function simular(Course $curso, int $n, float $efecto): array
    {
        $calculadora = new CalculadoraPerfil(ConfiguracionPerfil::desde($curso->configuracion ?? []));

        $pruebas = [];
        foreach (['pre' => 'A', 'post' => 'B'] as $momento => $forma) {
            foreach (['teorica', 'practica'] as $tipo) {
                $pruebas[$momento][$tipo] = Assessment::firstOrCreate(
                    ['course_id' => $curso->id, 'momento' => $momento, 'tipo' => $tipo],
                    ['nombre' => "Simulada {$tipo} {$momento}", 'forma' => $forma],
                );
            }
        }
        $aplicacion = fn (string $clave, string $momento) => ($i = Instrument::where('clave', $clave)->latest('id')->first())
            ? InstrumentAdministration::firstOrCreate(['course_id' => $curso->id, 'instrument_id' => $i->id, 'momento' => $momento])
            : null;
        $mslqPre = $aplicacion('mslq', 'pre');
        $mslqPost = $aplicacion('mslq', 'post');
        $imms = $aplicacion('imms', 'post');

        $ids = [];
        for ($i = 1; $i <= $n; $i++) {
            $usuario = User::factory()->create(['name' => "Simulado {$curso->id}-{$i}"]);
            $usuario->assignRole(Rol::Estudiante->value);
            $e = Enrollment::create(['course_id' => $curso->id, 'user_id' => $usuario->id, 'estado' => EstadoInscripcion::Concluido, 'inscrito_at' => now()]);
            $ids[] = $e->id;

            // Pre ~ N(50, 15); ganancia ~ N(10×efecto, 10): así d_z ≈ efecto
            $pre = ['teorica' => $this->acotar($this->normal(50, 15)), 'practica' => $this->acotar($this->normal(45, 15))];
            $post = array_map(fn ($v) => $this->acotar($v + $this->normal(10 * $efecto, 10)), $pre);
            foreach (['pre' => $pre, 'post' => $post] as $momento => $valores) {
                foreach ($valores as $tipo => $v) {
                    AssessmentAttempt::create([
                        'assessment_id' => $pruebas[$momento][$tipo]->id, 'enrollment_id' => $e->id,
                        'iniciado_at' => now()->subHour(), 'enviado_at' => now(), 'calificado_at' => now(), 'porcentaje' => $v,
                        'subpuntajes' => $tipo === 'teorica' ? ['recall' => $this->acotar($v + $this->normal(5, 5)), 'comprension' => $this->acotar($v - 5)] : ['practica' => $v],
                    ]);
                }
            }

            $mslq = $this->mslq(0.0);
            StudentProfile::create([...$calculadora->calcular($mslq, $pre['teorica'], $pre['teorica'], $pre['teorica'], $pre['practica']),
                'enrollment_id' => $e->id, 'version' => 1]);
            $this->respuesta($mslqPre, $e, $mslq);
            $this->respuesta($mslqPost, $e, $this->mslq(0.3 * $efecto));
            $this->respuesta($imms, $e, ['atencion' => $this->entre(2.5, 4.8), 'relevancia' => $this->entre(2.5, 4.8),
                'confianza' => $this->entre(2.0, 4.5), 'satisfaccion' => $this->entre(2.5, 5.0)]);
        }

        return $ids;
    }

    private function respuesta(?InstrumentAdministration $a, Enrollment $e, array $subescalas): void
    {
        if (! $a) {
            return; // instrumento no cargado: se omite
        }
        $indices = [];
        foreach ($a->instrument->definicion['indices'] ?? [] as $clave => $componentes) {
            $indices[$clave] = round(array_sum(array_intersect_key($subescalas, array_flip($componentes))) / count($componentes), 2);
        }
        InstrumentResponse::create(['instrument_administration_id' => $a->id, 'enrollment_id' => $e->id, 'respuestas' => [],
            'puntajes' => ['subescalas' => $subescalas, 'indices' => $indices], 'completado_at' => now()]);
    }

    private function mslq(float $desplazamiento): array
    {
        $claves = ['intrinseca', 'extrinseca', 'valor_tarea', 'control', 'autoeficacia', 'ansiedad', 'repaso', 'elaboracion',
            'organizacion', 'pensamiento_critico', 'metacognicion', 'tiempo_ambiente', 'regulacion_esfuerzo', 'aprendizaje_pares', 'busqueda_ayuda'];

        return collect($claves)->mapWithKeys(fn ($k) => [$k => round(min(7, max(1, $this->normal(4.3, 1) + $desplazamiento)), 2)])->all();
    }

    /** Normal con Box–Muller: suficiente para datos de prueba. */
    private function normal(float $media, float $de): float
    {
        $u1 = max(mt_rand() / mt_getrandmax(), 1e-12);
        $u2 = mt_rand() / mt_getrandmax();

        return $media + $de * sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);
    }

    private function acotar(float $v): float
    {
        return round(max(0.0, min(100.0, $v)), 2);
    }

    private function entre(float $min, float $max): float
    {
        return round($min + mt_rand() / mt_getrandmax() * ($max - $min), 2);
    }
}
