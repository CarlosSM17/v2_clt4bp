<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Enrollment;
use App\Services\Evaluacion\ServicioTablero;
use App\Support\Auditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/** Dashboard del instructor: el grupo y la ficha de cada estudiante. */
class TableroController extends Controller
{
    public function __construct(private readonly ServicioTablero $tablero) {}

    /** GET /courses/{course}/tablero */
    public function grupo(Course $course): JsonResponse
    {
        Gate::authorize('view', $course);

        return response()->json(['data' => $this->tablero->grupo($course)]);
    }

    /** GET /courses/{course}/estudiantes/{enrollment}: datos individuales, por eso queda en la auditoría. */
    public function estudiante(Course $course, Enrollment $enrollment): JsonResponse
    {
        Gate::authorize('view', $course);
        abort_unless($enrollment->course_id === $course->id, 404);
        Auditoria::registrar('estudiante.ficha_consultada', $enrollment);

        return response()->json(['data' => $this->tablero->estudiante($course, $enrollment)]);
    }
}
