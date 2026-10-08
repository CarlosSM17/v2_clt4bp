<?php

namespace App\Domain\Perfil;

/**
 * Propone grupos diferenciados (Tomlinson). Dos métodos:
 * - porNivel: un grupo por nivel de preparación presente (predeterminado, transparente).
 * - kmeans: k-means sobre variables estandarizadas, con k entre 2 y 4 elegido por silueta.
 * Los grupos se nombran "Ruta A, B, C…" de menor a mayor conocimiento previo.
 */
final class Agrupador
{
    /**
     * @param  array<string, array{cp: float, nivel: string}>  $perfiles  seudónimo => perfil
     * @return list<array{clave: string, nombre: string, nivel: string|null, miembros: list<string>}>
     */
    public function porNivel(array $perfiles): array
    {
        $grupos = [];
        foreach (CalculadoraPerfil::NIVELES as $nivel) {
            $miembros = array_keys(array_filter($perfiles, fn ($p) => $p['nivel'] === $nivel));
            if ($miembros !== []) {
                $grupos[] = ['nivel' => $nivel, 'miembros' => $miembros];
            }
        }

        return $this->nombrar($grupos);
    }

    /**
     * @param  array<string, list<float>>  $variables  seudónimo => [teórico, práctico, motivación, estrategias]
     * @return array{silueta: float, grupos: list<array{clave: string, nombre: string, nivel: string|null, miembros: list<string>}>}
     */
    public function kmeans(array $variables, int $semilla = 42): array
    {
        $ids = array_keys($variables);
        $datos = $this->estandarizar(array_values($variables));
        $mejor = null;

        for ($k = 2; $k <= min(4, count($datos) - 1); $k++) {
            $asignacion = $this->correrKmeans($datos, $k, $semilla);
            $s = $this->silueta($datos, $asignacion);
            if ($mejor === null || $s > $mejor['silueta']) {
                $mejor = ['silueta' => $s, 'asignacion' => $asignacion];
            }
        }
        if ($mejor === null) {
            return ['silueta' => 0.0, 'grupos' => $this->nombrar([['nivel' => null, 'miembros' => $ids]])];
        }

        // Ordena los clústeres por la media de la primera variable (conocimiento teórico)
        $porCluster = [];
        foreach ($mejor['asignacion'] as $i => $c) {
            $porCluster[$c][] = $i;
        }
        uasort($porCluster, fn ($a, $b) => $this->media(array_map(fn ($i) => $datos[$i][0], $a))
            <=> $this->media(array_map(fn ($i) => $datos[$i][0], $b)));

        $grupos = array_map(fn ($indices) => [
            'nivel' => null,
            'miembros' => array_map(fn ($i) => $ids[$i], $indices),
        ], array_values($porCluster));

        return ['silueta' => round($mejor['silueta'], 3), 'grupos' => $this->nombrar($grupos)];
    }

    private function nombrar(array $grupos): array
    {
        $letras = range('A', 'Z');

        return array_map(fn ($g, $i) => [
            'clave' => 'G'.($i + 1),
            'nombre' => 'Ruta '.$letras[$i],
            'nivel' => $g['nivel'],
            'miembros' => $g['miembros'],
        ], $grupos, array_keys($grupos));
    }

    /** @param list<list<float>> $datos */
    private function estandarizar(array $datos): array
    {
        $d = count($datos[0] ?? []);
        for ($j = 0; $j < $d; $j++) {
            $col = array_column($datos, $j);
            $m = $this->media($col);
            $s = sqrt(array_sum(array_map(fn ($x) => ($x - $m) ** 2, $col)) / max(1, count($col) - 1)) ?: 1.0;
            foreach ($datos as $i => $fila) {
                $datos[$i][$j] = ($fila[$j] - $m) / $s;
            }
        }

        return $datos;
    }

    /** k-means++ con semilla fija: mismo resultado en cada ejecución. */
    private function correrKmeans(array $datos, int $k, int $semilla): array
    {
        mt_srand($semilla + $k);
        $n = count($datos);
        $centros = [$datos[mt_rand(0, $n - 1)]];
        while (count($centros) < $k) {
            $dist = array_map(fn ($p) => min(array_map(fn ($c) => $this->d2($p, $c), $centros)), $datos);
            $total = array_sum($dist);
            $r = $total > 0 ? mt_rand() / mt_getrandmax() * $total : 0;
            $acum = 0.0;
            foreach ($dist as $i => $dd) {
                $acum += $dd;
                if ($acum >= $r) {
                    $centros[] = $datos[$i];
                    break;
                }
            }
        }

        $asignacion = array_fill(0, $n, -1);
        for ($iter = 0; $iter < 100; $iter++) {
            $cambio = false;
            foreach ($datos as $i => $p) {
                $distancias = array_map(fn ($c) => $this->d2($p, $c), $centros);
                $c = array_keys($distancias, min($distancias))[0];
                if ($asignacion[$i] !== $c) {
                    $asignacion[$i] = $c;
                    $cambio = true;
                }
            }
            if (! $cambio) {
                break;
            }
            foreach ($centros as $c => $_) {
                $miembros = array_values(array_filter($datos, fn ($i) => $asignacion[$i] === $c, ARRAY_FILTER_USE_KEY));
                if ($miembros) {
                    $centros[$c] = array_map(fn ($j) => $this->media(array_column($miembros, $j)), array_keys($miembros[0]));
                }
            }
        }

        return $asignacion;
    }

    /** Coeficiente de silueta promedio (−1 a 1; mayor es mejor). */
    private function silueta(array $datos, array $asignacion): float
    {
        $total = 0.0;
        foreach ($datos as $i => $p) {
            $porCluster = [];
            foreach ($datos as $j => $q) {
                if ($i !== $j) {
                    $porCluster[$asignacion[$j]][] = sqrt($this->d2($p, $q));
                }
            }
            $propio = $porCluster[$asignacion[$i]] ?? [];
            if ($propio === []) {
                continue;   // clúster de un solo elemento: silueta 0
            }
            $a = $this->media($propio);
            unset($porCluster[$asignacion[$i]]);
            if ($porCluster === []) {
                continue;
            }
            $b = min(array_map(fn ($ds) => $this->media($ds), $porCluster));
            $total += ($b - $a) / max($a, $b);
        }

        return $total / count($datos);
    }

    private function d2(array $p, array $q): float
    {
        $s = 0.0;
        foreach ($p as $j => $v) {
            $s += ($v - $q[$j]) ** 2;
        }

        return $s;
    }

    private function media(array $xs): float
    {
        return $xs ? array_sum($xs) / count($xs) : 0.0;
    }
}
