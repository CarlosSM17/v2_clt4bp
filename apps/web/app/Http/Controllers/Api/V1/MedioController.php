<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\MediaAsset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Archivos multimedia del curso: grabaciones de protocolos verbales, videos, audios e imágenes. */
class MedioController extends Controller
{
    /** POST /courses/{course}/media (multipart: uid, archivo). Subir dos veces el mismo uid reemplaza el archivo. */
    public function store(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('update', $course);
        $datos = $request->validate([
            'uid' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            // Sin SVG ni HTML: podrían llevar código. Tamaño en KB (config/clt4bp.php)
            'archivo' => ['required', 'file',
                'mimetypes:video/webm,video/mp4,audio/webm,audio/mpeg,audio/ogg,audio/wav,image/png,image/jpeg,image/webp',
                'max:'.config('clt4bp.medios.max_kb', 512000)],
        ]);

        $archivo = $request->file('archivo');
        $ruta = $archivo->storeAs("cursos/{$course->id}/medios", $datos['uid'].'.'.$archivo->extension(), 'local');
        $medio = MediaAsset::updateOrCreate(
            ['course_id' => $course->id, 'uid' => $datos['uid']],
            [
                'ruta' => $ruta, 'mime' => $archivo->getMimeType(), 'bytes' => $archivo->getSize(),
                'sha256' => hash_file('sha256', $archivo->getRealPath()), 'subido_por' => $request->user()->id,
            ],
        );

        return response()->json(['data' => $medio->only('uid', 'mime', 'bytes', 'sha256')], 201);
    }

    /** GET /courses/{course}/media/{uid}: descarga para el equipo docente (el aula usará URLs firmadas en la Etapa 5). */
    public function show(Course $course, string $uid): StreamedResponse
    {
        Gate::authorize('view', $course);
        $medio = MediaAsset::where('course_id', $course->id)->where('uid', $uid)->firstOrFail();

        return Storage::disk('local')->response($medio->ruta, null, ['Content-Type' => $medio->mime]);
    }
}
