<?php

namespace App\Domain\Aula;

/** Reglas que una publicación debe cumplir además del esquema de contracts. */
final class ValidadorManifiesto
{
    /**
     * @param  list<string>  $mediosSubidos  uid de los medios cuyo archivo está en el servidor
     * @return list<string> problemas; vacío = se puede publicar
     */
    public static function problemas(array $m, array $mediosSubidos): array
    {
        $p = [];
        $clases = array_column($m['clases'], null, 'uid');
        $tareas = array_column($m['tareas'], null, 'uid');

        if (! $clases) {
            $p[] = 'No hay clases de tareas aprobadas.';
        }
        foreach ($clases as $uid => $c) {
            if (! array_filter($tareas, fn ($t) => $t['clase_uid'] === $uid)) {
                $p[] = "La clase «{$c['titulo']}» no tiene tareas aprobadas.";
            }
        }
        foreach ([...$m['tareas'], ...$m['soporte']] as $e) {
            if (! isset($clases[$e['clase_uid']])) {
                $p[] = "«{$e['titulo']}» pertenece a una clase que no se publica ({$e['clase_uid']}).";
            }
        }
        // Una ayuda es de una tarea o, si es del tema, de su clase: exactamente una de las dos
        foreach ($m['procedimental'] as $a) {
            $tarea = $a['tarea_uid'] ?? null;
            $clase = $a['clase_uid'] ?? null;
            if (($tarea === null) === ($clase === null)) {
                $p[] = "La ayuda «{$a['titulo']}» debe pertenecer a una tarea o a una clase (exactamente a una).";
            } elseif ($tarea !== null && ! isset($tareas[$tarea])) {
                $p[] = "La ayuda «{$a['titulo']}» pertenece a una tarea que no se publica ({$tarea}).";
            } elseif ($clase !== null && ! isset($clases[$clase])) {
                $p[] = "La ayuda «{$a['titulo']}» pertenece a una clase que no se publica ({$clase}).";
            }
        }
        $publicables = array_flip(array_merge(...array_map(fn ($l) => array_column($m[$l], 'uid'),
            ['objetivos', 'clases', 'tareas', 'soporte', 'procedimental', 'practica_parcial'])));
        foreach ($m['variantes'] as $v) {
            if (! isset($publicables[$v['elemento_uid']])) {
                $p[] = "La variante {$v['uid']} modifica un elemento que no se publica.";
            }
        }
        foreach (self::mediosCitados($m) as $uid) {
            if (! in_array($uid, $mediosSubidos, true)) {
                $p[] = "Falta subir el archivo del medio {$uid} (sincroniza la consola).";
            }
        }

        return $p;
    }

    /** uid citados como ![…](media:uid) en cualquier texto Markdown del manifiesto. @return list<string> */
    public static function mediosCitados(array $m): array
    {
        $textos = [];
        foreach (['tareas' => 'enunciado_md', 'soporte' => 'cuerpo_md', 'procedimental' => 'cuerpo_md'] as $lista => $campo) {
            foreach ($m[$lista] as $e) {
                $textos[] = $e[$campo] ?? '';
            }
        }
        foreach ($m['variantes'] as $v) {
            $textos[] = $v['cambios']['enunciado_md'] ?? '';
            $textos[] = $v['cambios']['cuerpo_md'] ?? '';
        }
        preg_match_all('/\(media:([A-Za-z0-9_-]+)\)/', implode("\n", $textos), $coincidencias);

        return array_values(array_unique($coincidencias[1]));
    }
}
