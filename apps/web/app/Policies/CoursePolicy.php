<?php

namespace App\Policies;

use App\Enums\Rol;
use App\Models\Course;
use App\Models\User;

class CoursePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->esPersonal();
    }

    /** Ver un curso en la consola: el administrador o quien lo imparte. */
    public function view(User $user, Course $course): bool
    {
        return $user->hasRole(Rol::Admin->value) || $course->imparte($user);
    }

    public function create(User $user): bool
    {
        return $user->hasRole(Rol::Instructor->value);
    }

    public function update(User $user, Course $course): bool
    {
        return $course->imparte($user);
    }

    /** Aprobar, rechazar o dar de baja inscripciones. */
    public function manageEnrollments(User $user, Course $course): bool
    {
        return $course->imparte($user);
    }

    /** Entrar al curso desde el aula web como estudiante. */
    public function acceder(User $user, Course $course): bool
    {
        $inscripcion = $course->enrollments()->where('user_id', $user->id)->first();

        return $inscripcion !== null && $inscripcion->estado->daAcceso();
    }
}
