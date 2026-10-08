<?php

namespace App\Services\Agente;

use App\Models\Course;
use App\Models\CourseDocument;
use App\Models\DocumentFragment;

/**
 * RAG: elige los fragmentos del material del curso más cercanos a lo que se pide al agente.
 * La consulta se arma con lo que el instructor tiene seleccionado (objetivos, clase, tarea) y sus indicaciones.
 */
class BuscadorMaterial
{
    public function __construct(private readonly ClienteAgente $agente) {}

    /**
     * @param  array<string, mixed>  $diseno  fotografía DisenoCurso (ConstructorDiseno)
     * @param  array<string, mixed>  $alcance
     * @return list<array{id: int, documento: string, pagina: ?int, texto: string}>
     */
    public function buscar(Course $curso, array $diseno, array $alcance, string $indicaciones): array
    {
        // Sin material listo no hay nada que buscar: ni siquiera se calcula el embedding de la consulta
        if (! CourseDocument::where('course_id', $curso->id)->where('estado', 'listo')->exists()) {
            return [];
        }

        [$vector] = $this->agente->embeddings([$this->consulta($curso, $diseno, $alcance, $indicaciones)]);

        $tope = (int) config('clt4bp.material.caracteres_por_solicitud', 6000);
        $elegidos = [];
        $usados = 0;
        $candidatos = DocumentFragment::query()
            ->join('course_documents', 'course_documents.id', '=', 'document_fragments.course_document_id')
            ->where('document_fragments.course_id', $curso->id) // nunca material de otro curso
            ->where('course_documents.estado', 'listo')
            ->orderByVectorDistance('document_fragments.embedding', $vector)
            ->limit((int) config('clt4bp.material.fragmentos_por_solicitud', 6))
            ->get(['document_fragments.id', 'document_fragments.pagina', 'document_fragments.texto', 'course_documents.titulo']);

        foreach ($candidatos as $f) {
            if ($elegidos && $usados + mb_strlen($f->texto) > $tope) {
                break;
            }
            $usados += mb_strlen($f->texto);
            $elegidos[] = ['id' => $f->id, 'documento' => $f->titulo, 'pagina' => $f->pagina, 'texto' => $f->texto];
        }

        return $elegidos;
    }

    /** Texto de la consulta: lo que se va a diseñar, en palabras del propio diseño. */
    public function consulta(Course $curso, array $diseno, array $alcance, string $indicaciones): string
    {
        // El diseño llega como arreglos u objetos (ConstructorDiseno): data_get lee ambos
        $objetivos = collect(data_get($diseno, 'objetivos', []))->keyBy(fn ($o) => data_get($o, 'codigo'));
        $clase = collect(data_get($diseno, 'clases', []))->first(fn ($c) => data_get($c, 'uid') === ($alcance['clase_uid'] ?? null));
        $tarea = collect(data_get($diseno, 'tareas', []))->first(fn ($t) => data_get($t, 'uid') === ($alcance['tarea_uid'] ?? null));

        $codigos = $alcance['objetivos'] ?? data_get($clase, 'objetivos', []);
        $partes = [
            $indicaciones,
            $tarea ? data_get($tarea, 'titulo').'. '.mb_substr((string) data_get($tarea, 'enunciado_md', ''), 0, 400) : null,
            data_get($clase, 'titulo'),
            ...collect($codigos ?: $objetivos->keys())->map(fn ($c) => data_get($objetivos->get($c), 'descripcion'))->all(),
        ];
        $texto = trim(implode("\n", array_filter($partes)));

        return $texto !== '' ? mb_substr($texto, 0, 2000) : $curso->titulo;
    }
}
