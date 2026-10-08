<?php

namespace Tests\Feature\Etapa2;

use App\Domain\Perfil\Agrupador;
use App\Models\Course;
use App\Models\GroupAnalysis;
use App\Models\StudentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreaUsuarios;
use Tests\TestCase;

class SimularDiagnosticoTest extends TestCase
{
    use CreaUsuarios, RefreshDatabase;

    private function curso(): Course
    {
        $instructor = $this->instructor();

        return Course::create(['owner_id' => $instructor->id, 'titulo' => 'C', 'lenguaje' => 'c', 'nivel_educativo' => 'preparatoria']);
    }

    private function simular(Course $curso, string $tipo): array
    {
        $this->app->detectEnvironment(fn () => 'local');   // el comando solo corre con APP_ENV=local
        $this->artisan('demo:diagnostico', ['curso' => $curso->id, '--tipo' => $tipo])->assertSuccessful();

        return GroupAnalysis::where('course_id', $curso->id)->latest('id')->firstOrFail()->resultado;
    }

    public function test_solo_corre_en_local(): void
    {
        $this->artisan('demo:diagnostico', ['curso' => $this->curso()->id])->assertFailed();
        $this->assertSame(0, StudentProfile::count());
    }

    public function test_grupo_homogeneo_simulado(): void
    {
        $r = $this->simular($this->curso(), 'homogeneo');

        $this->assertSame(30, $r['n']);
        $this->assertSame('homogeneo', $r['recomendacion']);
        $this->assertSame('intermedio', $r['nivel_modal']);
        $this->assertEqualsWithDelta(0.06, $r['cv'], 0.02);
    }

    public function test_grupo_bimodal_simulado(): void
    {
        $r = $this->simular($this->curso(), 'bimodal');

        $this->assertSame('heterogeneo', $r['recomendacion']);
        $this->assertEqualsWithDelta(0.53, $r['cv'], 0.03);
        $this->assertTrue($r['confiable']);
    }

    public function test_grupo_disperso_simulado(): void
    {
        $r = $this->simular($this->curso(), 'disperso');

        $this->assertSame('heterogeneo', $r['recomendacion']);
        $this->assertEqualsWithDelta(0.44, $r['cv'], 0.03);
    }

    public function test_kmeans_separa_los_dos_grupos_del_caso_bimodal(): void
    {
        $curso = $this->curso();
        $this->simular($curso, 'bimodal');

        $variables = [];
        foreach ($curso->enrollments()->with('perfil')->get() as $e) {
            $variables[$e->seudonimo] = [$e->perfil->cp_teorico, $e->perfil->cp_practico,
                (float) ($e->perfil->indices['motivacion'] ?? 4), (float) ($e->perfil->indices['estrategias_cognitivas'] ?? 4)];
        }
        $r = (new Agrupador)->kmeans($variables);

        $this->assertCount(2, $r['grupos']);
        $this->assertTrue($r['silueta'] > 0.8);
    }
}
