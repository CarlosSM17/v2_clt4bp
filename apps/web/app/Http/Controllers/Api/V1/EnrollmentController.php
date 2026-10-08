<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\EstadoInscripcion;
use App\Enums\Rol;
use App\Http\Controllers\Controller;
use App\Http\Resources\EnrollmentResource;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\User;
use App\Notifications\InscripcionAprobada;
use App\Notifications\InvitacionEstudiante;
use App\Support\Auditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;

class EnrollmentController extends Controller
{
    public function index(Request $request, Course $course): AnonymousResourceCollection
    {
        Gate::authorize('view', $course);

        $inscripciones = $course->enrollments()
            ->with('user:id,name,email')
            ->when($request->query('estado'), fn ($q, $estado) => $q->where('estado', $estado))
            ->orderBy('estado')->orderBy('created_at')
            ->get();

        return EnrollmentResource::collection($inscripciones);
    }

    /** Alta directa por correo. Si el correo no está registrado, lo invita a registrarse. */
    public function store(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('manageEnrollments', $course);

        $datos = $request->validate([
            'emails' => ['required', 'array', 'min:1', 'max:500'],
            'emails.*' => ['email'],
        ]);

        $resultado = ['inscritos' => [], 'invitados' => [], 'omitidos' => []];

        foreach (array_unique(array_map('strtolower', $datos['emails'])) as $email) {
            $user = User::where('email', $email)->first();

            if (! $user) {
                Notification::route('mail', $email)->notify(new InvitacionEstudiante($course));
                $resultado['invitados'][] = $email;

                continue;
            }
            if (! $user->hasRole(Rol::Estudiante->value)) {
                $resultado['omitidos'][] = $email;

                continue;
            }

            $inscripcion = Enrollment::firstOrNew(['course_id' => $course->id, 'user_id' => $user->id]);
            if ($inscripcion->exists && $inscripcion->estado->daAcceso()) {
                $resultado['omitidos'][] = $email;

                continue;
            }
            $inscripcion->fill(['estado' => EstadoInscripcion::Inscrito, 'inscrito_at' => now()])->save();
            $user->notify(new InscripcionAprobada($course));
            $resultado['inscritos'][] = $email;
        }

        Auditoria::registrar('inscripcion.alta_directa', $course, $resultado);

        return response()->json($resultado, 201);
    }

    /** Aprobar, rechazar o dar de baja. */
    public function update(Request $request, Enrollment $enrollment): EnrollmentResource
    {
        Gate::authorize('manageEnrollments', $enrollment->course);
        $datos = $request->validate([
            'accion' => ['required', Rule::in(['aprobar', 'rechazar', 'baja'])],
        ]);

        match ($datos['accion']) {
            'aprobar' => $enrollment->fill(['estado' => EstadoInscripcion::Inscrito, 'inscrito_at' => now()]),
            'rechazar' => $enrollment->fill(['estado' => EstadoInscripcion::Rechazada]),
            'baja' => $enrollment->fill(['estado' => EstadoInscripcion::Baja, 'baja_at' => now()]),
        };
        $enrollment->save();

        if ($datos['accion'] === 'aprobar') {
            $enrollment->user->notify(new InscripcionAprobada($enrollment->course));
        }
        Auditoria::registrar("inscripcion.{$datos['accion']}", $enrollment);

        return new EnrollmentResource($enrollment->load('user'));
    }
}
