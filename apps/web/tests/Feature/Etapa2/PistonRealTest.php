<?php

namespace Tests\Feature\Etapa2;

use App\Enums\EstadoInscripcion;
use App\Models\Assessment;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Item;
use App\Services\Codigo\EjecutorCodigo;
use App\Services\Codigo\PistonEjecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\CreaUsuarios;
use Tests\TestCase;

/**
 * Prueba de integración contra el Piston real (docker compose de infra/). Se omite sola
 * si Piston no responde o no tiene instalados gcc y python, como en la integración continua.
 */
class PistonRealTest extends TestCase
{
    use CreaUsuarios, RefreshDatabase;

    private const PROMEDIO_C = "#include <stdio.h>\nint main(void){int n;double x,s=0;scanf(\"%d\",&n);for(int i=0;i<n;i++){scanf(\"%lf\",&x);s+=x;}printf(\"%.2f\\n\",s/n);return 0;}\n";

    protected function setUp(): void
    {
        parent::setUp();

        try {
            $lenguajes = collect(Http::timeout(2)->get(config('services.piston.url').'/runtimes')->json())->pluck('language');
        } catch (\Throwable) {
            $this->markTestSkipped('Piston no está disponible.');
        }
        if (! $lenguajes->contains('c') || ! $lenguajes->contains('python')) {
            $this->markTestSkipped('Piston no tiene instalados C y Python.');
        }

        $this->app->bind(EjecutorCodigo::class, PistonEjecutor::class);
    }

    public function test_verifica_y_califica_un_problema_de_c_con_piston_real(): void
    {
        $instructor = $this->instructor();
        $curso = Course::create(['owner_id' => $instructor->id, 'titulo' => 'C', 'lenguaje' => 'c', 'nivel_educativo' => 'preparatoria']);
        $curso->instructores()->attach($instructor->id, ['rol' => 'responsable']);
        $item = Item::create(['course_id' => $curso->id, 'tipo' => 'programacion', 'nivel' => 'practica', 'lenguaje' => 'c',
            'enunciado' => ['md' => 'Promedio'], 'solucion' => self::PROMEDIO_C,
            'casos_prueba' => [
                ['entrada' => "3\n8 9 10\n", 'salida_esperada' => "9.00\n", 'oculto' => false],
                ['entrada' => "2\n6 7\n", 'salida_esperada' => "6.50\n", 'oculto' => true],
            ]]);

        // La solución de referencia pasa todos los casos y el ítem se puede aprobar
        Sanctum::actingAs($instructor, ['consola']);
        $this->postJson("/api/v1/items/{$item->id}/verificar")->assertOk()->assertJsonPath('verificado', true);
        $this->postJson("/api/v1/items/{$item->id}/aprobar")->assertOk();

        // Una solución que no compila deja el ítem sin verificar
        $item->update(['solucion' => 'int main( {']);
        $r = $this->postJson("/api/v1/items/{$item->id}/verificar")->assertOk()->json();
        $this->assertFalse($r['verificado']);
        $this->assertNotEmpty($r['resultado']['error_compilacion']);

        // Un estudiante envía código con el formato equivocado: no pasa ninguno de los 2 casos
        $prueba = Assessment::create(['course_id' => $curso->id, 'nombre' => 'Práctica', 'momento' => 'pre', 'tipo' => 'practica', 'forma' => 'A']);
        $prueba->items()->attach($item->id, ['orden' => 1, 'puntos' => 10]);
        $estudiante = $this->estudiante();
        Enrollment::create(['course_id' => $curso->id, 'user_id' => $estudiante->id, 'estado' => EstadoInscripcion::Diagnostico]);

        $this->actingAs($estudiante, 'web')->get("/cursos/{$curso->id}/pruebas/{$prueba->id}")->assertOk();
        $intento = $prueba->attempts()->firstOrFail();
        $codigoErroneo = str_replace('%.2f', '%.1f', self::PROMEDIO_C);   // imprime 9.0 y 6.5: no coincide con "9.00"
        $this->post("/intentos/{$intento->id}/respuestas", ['item_id' => $item->id, 'respuesta' => ['codigo' => $codigoErroneo]]);
        $this->post("/intentos/{$intento->id}/enviar")->assertRedirect();

        $intento->refresh();
        $this->assertNotNull($intento->calificado_at);
        $this->assertSame(0.0, $intento->porcentaje);
        $respuesta = $intento->responses()->firstOrFail();
        $this->assertSame(0.0, $respuesta->fraccion);
        $this->assertSame(0, $respuesta->detalle['aprobados']);
        $this->assertNull($respuesta->detalle['casos'][1]['entrada']);   // el caso oculto no revela su entrada
    }
}
