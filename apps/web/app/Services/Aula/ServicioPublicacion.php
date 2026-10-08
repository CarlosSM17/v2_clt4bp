<?php

namespace App\Services\Aula;

use App\Domain\Aula\ValidadorManifiesto;
use App\Models\Course;
use App\Models\DesignElement;
use App\Models\DiffGroup;
use App\Models\MediaAsset;
use App\Models\Release;
use App\Models\User;
use App\Services\Diseno\ValidadorContratos;
use App\Services\Diseno\VerificadorCodigo;
use App\Support\Auditoria;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Publicar = congelar lo aprobado en un manifiesto numerado. Revertir = volver al anterior. */
class ServicioPublicacion
{
    public function __construct(
        private readonly ValidadorContratos $contratos,
        private readonly VerificadorCodigo $codigo,
    ) {}

    /**
     * Fotografía DisenoCurso con lo aprobado o ya publicado, como objetos.
     * Un elemento publicado que el instructor está editando (volvió a «borrador») conserva su versión
     * publicada: publicar otros cambios no debe hacerlo desaparecer del aula.
     */
    public function manifiesto(Course $curso): object
    {
        $vivos = DesignElement::where('course_id', $curso->id)->whereNull('eliminado_at')->get(['uid', 'tipo', 'estado', 'orden', 'contenido']);
        $listos = $vivos->whereIn('estado', ['aprobado', 'publicado'])->groupBy('tipo');
        $enEdicion = $vivos->where('estado', 'borrador')->pluck('uid')->flip();
        $vigente = Release::vigente($curso->id);
        $previo = $vigente ? json_decode($vigente->getRawOriginal('manifiesto')) : null;

        $de = fn (string $tipo, string $lista) => collect($listos[$tipo] ?? [])->pluck('contenido')
            ->concat(collect($previo->{$lista} ?? [])->filter(fn ($e) => isset($enEdicion[$e->uid])))
            ->sortBy([fn ($a, $b) => ($a->orden ?? 0) <=> ($b->orden ?? 0), fn ($a, $b) => $a->uid <=> $b->uid])
            ->values()->all();

        return (object) [
            'curso' => (object) [
                'id' => $curso->id,
                'titulo' => $curso->titulo,
                'lenguaje' => $curso->lenguaje->value,
                'grupos' => DiffGroup::where('course_id', $curso->id)->orderBy('orden')->get(['clave', 'nombre', 'nivel'])->toArray(),
            ],
            'objetivos' => $de('objetivo', 'objetivos'),
            'clases' => $de('clase', 'clases'),
            'tareas' => $de('tarea', 'tareas'),
            'soporte' => $de('soporte', 'soporte'),
            'procedimental' => $de('procedimental', 'procedimental'),
            'practica_parcial' => $de('practica_parcial', 'practica_parcial'),
            'variantes' => $de('variante', 'variantes'),
            // Los metadatos de los medios no pasan por aprobación: revisar() deja solo los citados
            'medios' => $vivos->where('tipo', 'medio')->pluck('contenido')->values()->all(),
        ];
    }

    /** @return array{manifiesto: object, problemas: list<string>} */
    public function revisar(Course $curso): array
    {
        // Un solo árbol de objetos (los grupos llegan como arreglos asociativos): así lo valida opis
        $m = json_decode(json_encode($this->manifiesto($curso)));
        $plano = json_decode(json_encode($m), true);
        $citados = ValidadorManifiesto::mediosCitados($plano);
        $m->medios = array_values(array_filter($m->medios, fn ($x) => in_array($x->uid, $citados, true)));

        $problemas = ValidadorManifiesto::problemas($plano, MediaAsset::where('course_id', $curso->id)->pluck('uid')->all());
        foreach ($this->contratos->errores('diseno-curso.schema.json', $m) as $ruta => $mensajes) {
            $problemas[] = "Contrato {$ruta}: ".implode(' ', $mensajes);
        }
        // Las soluciones se volvieron a ejecutar al aprobar; aquí se confirma (con caché, es barato)
        foreach ($this->codigo->resultados(['tareas' => $m->tareas, 'practica_parcial' => $m->practica_parcial]) as $uid => $r) {
            if ($r['error_compilacion'] || $r['aprobados'] < $r['total']) {
                $problemas[] = "La solución de {$uid} no pasa todos sus casos.";
            }
        }

        return ['manifiesto' => $m, 'problemas' => $problemas];
    }

    public function publicar(Course $curso, User $autor, ?string $nota, string $clave): Release
    {
        $previa = Release::where('course_id', $curso->id)->where('clave_idempotencia', $clave)->first();
        if ($previa) {
            return $previa; // la consola repitió la petición
        }

        ['manifiesto' => $m, 'problemas' => $problemas] = $this->revisar($curso);
        if ($problemas) {
            throw ValidationException::withMessages(['manifiesto' => $problemas]);
        }

        $release = DB::transaction(function () use ($curso, $autor, $nota, $clave, $m) {
            DB::select('select pg_advisory_xact_lock(?)', [$curso->id]);
            $json = json_encode($m, JSON_UNESCAPED_UNICODE);
            $release = Release::create([
                'course_id' => $curso->id,
                'numero' => (int) Release::where('course_id', $curso->id)->max('numero') + 1,
                'manifiesto' => json_decode($json), // se guarda tal cual: {} sigue siendo {}
                'huella' => hash('sha256', $json),
                'nota' => $nota,
                'publicado_por' => $autor->id,
                'clave_idempotencia' => $clave,
            ]);

            // Los elementos aprobados pasan a «publicado»; nuevo seq para que las consolas se enteren
            DesignElement::where('course_id', $curso->id)->whereNull('eliminado_at')->where('estado', 'aprobado')
                ->get()->each(fn ($e) => $e->update(['estado' => 'publicado', 'seq' => (int) DB::scalar("select nextval('design_seq')")]));

            if ($curso->estado->value === 'diseno') {
                $curso->update(['estado' => 'activo']);
            }

            return $release;
        });
        Auditoria::registrar('publicacion.creada', $release, ['numero' => $release->numero, 'huella' => $release->huella]);

        return $release;
    }

    /** Solo se revierte la vigente, y solo si hay una anterior a la cual volver. */
    public function revertir(Release $release): Release
    {
        $vigente = Release::vigente($release->course_id);
        abort_unless($vigente?->is($release), 409, 'Solo se puede revertir la publicación vigente.');
        $anterior = Release::where('course_id', $release->course_id)->where('estado', 'publicada')
            ->where('numero', '<', $release->numero)->orderByDesc('numero')->first();
        abort_unless($anterior, 409, 'No hay una publicación anterior a la cual volver.');

        $release->update(['estado' => 'revertida']);
        Auditoria::registrar('publicacion.revertida', $release, ['vuelve_a' => $anterior->numero]);

        return $anterior;
    }
}
