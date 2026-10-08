<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Enums\Rol;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Notifications\InvitacionInstructor;
use App\Support\Auditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class InstructorController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return UserResource::collection(User::role(Rol::Instructor->value)->orderBy('name')->get());
    }

    public function store(Request $request): UserResource
    {
        $datos = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'institucion' => ['nullable', 'string', 'max:255'],
        ]);

        $instructor = DB::transaction(function () use ($datos, $request) {
            $user = User::create([
                ...$datos,
                'password' => Str::password(40),   // se reemplaza al aceptar la invitación
                'invitado_por' => $request->user()->id,
            ]);
            $user->assignRole(Rol::Instructor->value);

            return $user;
        });

        $instructor->notify(new InvitacionInstructor);
        Auditoria::registrar('instructor.invitado', $instructor);

        return new UserResource($instructor);
    }

    /**
     * Un enlace de invitación vigente para entregarlo a mano (sin correo: Railway bloquea el SMTP en el plan Hobby).
     * Da acceso a definir la contraseña de esa cuenta: solo para el administrador y queda en la bitácora.
     */
    public function enlace(User $user): JsonResponse
    {
        abort_unless($user->hasRole(Rol::Instructor->value), 404);
        abort_if($user->invitacion_aceptada_at !== null, 422, 'El instructor ya aceptó su invitación.');
        Auditoria::registrar('instructor.enlace_invitacion', $user);

        return response()->json(['data' => [
            'enlace' => InvitacionInstructor::enlace($user),
            'vence_at' => now()->addHours(config('clt4bp.invitacion_horas'))->toIso8601String(),
        ]]);
    }

    /** Suspender, reactivar o reenviar la invitación. */
    public function update(Request $request, User $user): UserResource
    {
        abort_unless($user->hasRole(Rol::Instructor->value), 404);

        $datos = $request->validate([
            'accion' => ['required', Rule::in(['suspender', 'reactivar', 'reenviar_invitacion'])],
        ]);

        match ($datos['accion']) {
            'suspender' => $user->forceFill(['suspendido_at' => now()])->save(),
            'reactivar' => $user->forceFill(['suspendido_at' => null])->save(),
            'reenviar_invitacion' => $user->invitacion_aceptada_at === null
                ? $user->notify(new InvitacionInstructor)
                : abort(422, 'El instructor ya aceptó su invitación.'),
        };

        if ($datos['accion'] === 'suspender') {
            $user->tokens()->delete();   // cierra sus sesiones de consola
        }
        Auditoria::registrar("instructor.{$datos['accion']}", $user);

        return new UserResource($user->refresh());
    }
}
