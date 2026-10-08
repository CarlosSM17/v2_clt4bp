<?php

namespace Tests\Unit\Domain;

use App\Domain\Agente\AlcancePlantilla;
use PHPUnit\Framework\TestCase;

class AlcancePlantillaTest extends TestCase
{
    private array $existentes = ['objetivo' => ['OB-1', 'OB-2'], 'clase' => ['tc1'], 'tarea' => ['tc1-t1'], 'grupo' => ['G1', 'G2']];

    public function test_clase_de_tareas_con_objetivos_existentes(): void
    {
        $this->assertSame([], AlcancePlantilla::validar('clase_tareas', ['objetivos' => ['OB-1']], $this->existentes));
    }

    public function test_objetivo_inexistente_o_lista_vacia(): void
    {
        $this->assertSame(['objetivos: no existen OB-9.'], AlcancePlantilla::validar('clase_tareas', ['objetivos' => ['OB-1', 'OB-9']], $this->existentes));
        $this->assertSame(['objetivos: indica al menos un objetivo.'], AlcancePlantilla::validar('items_evaluacion', ['objetivos' => []], $this->existentes));
    }

    public function test_referencias_a_clase_tarea_y_grupo(): void
    {
        $this->assertSame([], AlcancePlantilla::validar('info_procedimental', ['tarea_uid' => 'tc1-t1'], $this->existentes));
        $this->assertSame(['clase_uid: no existe en el diseño (clase).'], AlcancePlantilla::validar('info_soporte', ['clase_uid' => 'tc9'], $this->existentes));
        $this->assertSame(['grupo_clave: no existe en el diseño (grupo).'], AlcancePlantilla::validar('preseleccion', [], $this->existentes));
    }

    public function test_las_piezas_del_tema_completo_piden_su_clase(): void
    {
        foreach (['tareas_complementarias', 'ficha_tema', 'ejemplo_resuelto_tema', 'mapa_glosario', 'info_procedimental', 'guion_protocolo'] as $plantilla) {
            $this->assertSame([], AlcancePlantilla::validar($plantilla, ['clase_uid' => 'tc1'], $this->existentes), $plantilla);
            $this->assertSame(['clase_uid: no existe en el diseño (clase).'], AlcancePlantilla::validar($plantilla, ['clase_uid' => 'tc9'], $this->existentes));
        }
        // Ayudas y protocolo: del tema o de una tarea; sin ninguno de los dos, se pide la tarea
        $this->assertSame(['tarea_uid: no existe en el diseño (tarea).'], AlcancePlantilla::validar('guion_protocolo', [], $this->existentes));
        // La evaluación del tema: sus objetivos y, si la indica, una clase que exista
        $this->assertSame([], AlcancePlantilla::validar('items_evaluacion', ['objetivos' => ['OB-1'], 'clase_uid' => 'tc1'], $this->existentes));
        $this->assertSame(['clase_uid: no existe en el diseño (clase).'], AlcancePlantilla::validar('items_evaluacion', ['objetivos' => ['OB-1'], 'clase_uid' => 'x'], $this->existentes));
    }

    public function test_fechas_del_plan(): void
    {
        $this->assertSame([], AlcancePlantilla::validar('plan_implementacion', ['inicio' => '2027-01-10', 'fin' => '2027-05-30'], $this->existentes));
        $this->assertSame(['inicio: debe ser anterior al fin.'], AlcancePlantilla::validar('plan_implementacion', ['inicio' => '2027-06-01', 'fin' => '2027-05-30'], $this->existentes));
        $this->assertSame(['fin: debe ser una fecha AAAA-MM-DD.'], AlcancePlantilla::validar('plan_implementacion', ['inicio' => '2027-01-10', 'fin' => 'mayo'], $this->existentes));
    }

    public function test_diferenciar_sin_grupos_y_plantilla_desconocida(): void
    {
        $sinGrupos = [...$this->existentes, 'grupo' => []];
        $this->assertSame(['El curso todavía no tiene grupos diferenciados (Etapa 2).'], AlcancePlantilla::validar('diferenciacion', [], $sinGrupos));
        $this->assertSame(['La plantilla «poema» no existe.'], AlcancePlantilla::validar('poema', [], $this->existentes));
        $this->assertSame([], AlcancePlantilla::validar('objetivos', [], $this->existentes));
    }
}
