<?php

namespace Tests\Unit\Domain;

use App\Domain\Evaluacion\Alertas;
use App\Domain\Evaluacion\Eficiencia;
use App\Domain\Evaluacion\LogroObjetivos;
use App\Domain\Evaluacion\MensajesLogro;
use App\Domain\Instrumentos\PuntuadorLikert;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

class EvaluacionTest extends TestCase
{
    public function test_logro_por_objetivo_pondera_puntos_y_cuenta_quienes_llegan_al_criterio(): void
    {
        $filas = [
            // Estudiante 1: OB-2 → (2×1 + 1×0.5) / 3 = 83.33 %; OB-10 → 0 %
            ['inscripcion' => 1, 'item' => 10, 'objetivo' => 'OB-2', 'puntos' => 2.0, 'fraccion' => 1.0],
            ['inscripcion' => 1, 'item' => 11, 'objetivo' => 'OB-2', 'puntos' => 1.0, 'fraccion' => 0.5],
            ['inscripcion' => 1, 'item' => 12, 'objetivo' => 'OB-10', 'puntos' => 1.0, 'fraccion' => null],
            // Estudiante 2: OB-2 → 2 / 3 = 66.67 %; OB-10 → 100 %
            ['inscripcion' => 2, 'item' => 10, 'objetivo' => 'OB-2', 'puntos' => 2.0, 'fraccion' => 1.0],
            ['inscripcion' => 2, 'item' => 11, 'objetivo' => 'OB-2', 'puntos' => 1.0, 'fraccion' => 0.0],
            ['inscripcion' => 2, 'item' => 12, 'objetivo' => 'OB-10', 'puntos' => 1.0, 'fraccion' => 1.0],
            ['inscripcion' => 2, 'item' => 13, 'objetivo' => null, 'puntos' => 1.0, 'fraccion' => 1.0],
        ];

        $r = LogroObjetivos::calcular($filas, 70);

        $this->assertSame(['OB-2', 'OB-10'], array_column($r, 'codigo')); // orden natural
        $this->assertSame(75.0, $r[0]['media']);
        $this->assertSame(50.0, $r[0]['logran']); // solo el estudiante 1 llega a 70
        $this->assertSame(2, $r[0]['items']);
        $this->assertSame(50.0, $r[1]['media']);
    }

    public function test_eficiencia_instruccional(): void
    {
        // Desempeño y esfuerzo con media 0.6 / 5 y la misma dispersión relativa
        $e = Eficiencia::delGrupo([
            'a' => ['desempeno' => 1.0, 'esfuerzo' => 3.0], // rinde más con menos esfuerzo
            'b' => ['desempeno' => 0.6, 'esfuerzo' => 5.0],
            'c' => ['desempeno' => 0.2, 'esfuerzo' => 7.0],  // le cuesta mucho y rinde poco
        ]);

        $this->assertEqualsWithDelta(1.4142, $e['a'], 0.0001); // (1 - (-1)) / √2
        $this->assertEqualsWithDelta(0.0, $e['b'], 0.0001);
        $this->assertEqualsWithDelta(-1.4142, $e['c'], 0.0001);
        $this->assertSame('menos_apoyo', Eficiencia::decision($e['a']));
        $this->assertSame('continuar', Eficiencia::decision($e['b']));
        $this->assertSame('mas_apoyo', Eficiencia::decision($e['c']));
        $this->assertNull(Eficiencia::delGrupo(['x' => ['desempeno' => 1.0, 'esfuerzo' => 5.0], 'y' => ['desempeno' => 1.0, 'esfuerzo' => 3.0]])['x']);
    }

    public function test_alertas(): void
    {
        $ahora = new DateTimeImmutable('2026-10-20 10:00');
        $alertas = new Alertas(diasSinActividad: 5);
        $base = ['estado' => 'cursando', 'ultima_actividad' => new DateTimeImmutable('2026-10-19'), 'banderas' => [], 'tareas' => []];

        $this->assertSame([], $alertas->de($base, $ahora));
        $this->assertSame(['sin_actividad'], array_column($alertas->de([...$base, 'ultima_actividad' => new DateTimeImmutable('2026-10-14')], $ahora), 'tipo'));
        $this->assertSame(['diagnostico_incompleto'], array_column($alertas->de([...$base, 'estado' => 'diagnostico'], $ahora), 'tipo'));

        $dificil = ['esfuerzo' => 8, 'fraccion' => 0.2];
        $r = $alertas->de([...$base, 'banderas' => ['alta_ansiedad'], 'tareas' => [$dificil, $dificil, ['esfuerzo' => 8, 'fraccion' => 0.9]]], $ahora);
        $this->assertSame(['esfuerzo_sin_desempeno', 'ansiedad_alta'], array_column($r, 'tipo'));
    }

    public function test_mensajes_de_logro_sin_comparar_con_nadie(): void
    {
        $t = fn (string $apoyo, string $estado, ?float $f, ?int $esf = null) => ['nivel_apoyo' => $apoyo, 'estado' => $estado, 'fraccion' => $f, 'esfuerzo' => $esf];

        $clases = [
            ['orden' => 1, 'titulo' => 'Condicionales', 'tareas' => [
                $t('ejemplo_resuelto', 'completada', null, 6),
                $t('por_completar', 'completada', 1.0, 6),
                $t('convencional', 'completada', 1.0, 7), $t('convencional', 'completada', 1.0, 5),
            ]],
            ['orden' => 2, 'titulo' => 'Ciclos', 'tareas' => [
                $t('por_completar', 'completada', 1.0, 4), $t('convencional', 'completada', 1.0, 4), $t('convencional', 'en_progreso', 0.9, 4),
            ]],
        ];

        $textos = array_column(MensajesLogro::para($clases, ['practico' => ['pre' => 40.0, 'post' => 72.0]]), 'texto');

        $this->assertContains('Resolviste las 2 tareas sin guía de la clase 1.', $textos);
        $this->assertContains('Completaste la clase 1: «Condicionales».', $textos);
        $this->assertNotContains('Completaste la clase 2: «Ciclos».', $textos);
        $this->assertContains('Cada vez te cuesta menos esfuerzo resolver tareas del mismo nivel: estás automatizando lo que aprendiste.', $textos);
        $this->assertContains('Mejoraste 32 puntos en la parte práctica respecto a tu diagnóstico.', $textos);
    }

    public function test_imms_recodifica_sus_inversos(): void
    {
        $def = json_decode(file_get_contents(__DIR__.'/../../../../../instruments/imms.json'), true);
        $todoEnCinco = array_fill_keys(array_column($def['items'], 'id'), 5);
        $p = (new PuntuadorLikert($def))->puntuar($todoEnCinco);

        // Atención: 7 directos valen 5 y 5 inversos (12, 15, 22, 29 y 31) valen 1; satisfacción no tiene inversos
        $this->assertSame(round((7 * 5 + 5 * 1) / 12, 2), $p['subescalas']['atencion']);
        $this->assertSame(round((26 * 5 + 10 * 1) / 36, 2), $p['subescalas']['total']);
        $this->assertSame(5.0, (float) $p['subescalas']['satisfaccion']);
    }
}
