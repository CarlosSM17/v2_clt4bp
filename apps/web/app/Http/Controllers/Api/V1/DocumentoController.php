<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcesarDocumento;
use App\Models\Course;
use App\Models\CourseDocument;
use App\Support\Auditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Material del curso que consulta el agente (RAG): apuntes y bibliografía del equipo docente. */
class DocumentoController extends Controller
{
    private const CAMPOS = ['id', 'titulo', 'nombre_original', 'mime', 'bytes', 'estado', 'error', 'fragmentos', 'created_at'];

    /** GET /courses/{course}/documents */
    public function index(Course $course): JsonResponse
    {
        Gate::authorize('update', $course);

        return response()->json(['data' => CourseDocument::where('course_id', $course->id)->latest()->get(self::CAMPOS)]);
    }

    /** POST /courses/{course}/documents (multipart: archivo, titulo?) */
    public function store(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('update', $course);
        $datos = $request->validate([
            // Solo texto: PDF, Markdown y texto plano. La extensión y el contenido deben coincidir
            'archivo' => ['required', 'file', 'extensions:pdf,md,txt',
                'mimetypes:application/pdf,text/plain,text/markdown,text/x-markdown',
                'max:'.config('clt4bp.material.max_kb', 51200)],
            'titulo' => ['nullable', 'string', 'max:200'],
        ]);

        $archivo = $request->file('archivo');
        $sha256 = hash_file('sha256', $archivo->getRealPath());
        abort_if(
            CourseDocument::where('course_id', $course->id)->where('sha256', $sha256)->exists(),
            409, 'Ese documento ya está en el material del curso.'
        );

        $extension = strtolower($archivo->getClientOriginalExtension());
        $ruta = $archivo->storeAs("cursos/{$course->id}/material", Str::uuid().'.'.$extension, 'local');
        $documento = CourseDocument::create([
            'course_id' => $course->id,
            'titulo' => $datos['titulo'] ?? pathinfo($archivo->getClientOriginalName(), PATHINFO_FILENAME),
            'nombre_original' => $archivo->getClientOriginalName(),
            'ruta' => $ruta, 'mime' => $archivo->getMimeType(), 'bytes' => $archivo->getSize(), 'sha256' => $sha256,
            'subido_por' => $request->user()->id,
        ]);
        ProcesarDocumento::dispatch($documento->id);
        Auditoria::registrar('material.subido', $documento);

        return response()->json(['data' => $documento->fresh()->only(self::CAMPOS)], 201);
    }

    /** DELETE /courses/{course}/documents/{document}: borra el archivo y sus fragmentos (cascada). */
    public function destroy(Course $course, CourseDocument $document): Response
    {
        Gate::authorize('update', $course);
        abort_unless($document->course_id === $course->id, 404);

        Storage::disk('local')->delete($document->ruta);
        $document->delete();
        Auditoria::registrar('material.eliminado', $document);

        return response()->noContent();
    }
}
