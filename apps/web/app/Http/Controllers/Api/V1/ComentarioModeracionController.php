<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\TaskComment;
use App\Support\Auditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** El instructor lee las discusiones de las tareas y oculta lo que no corresponda. */
class ComentarioModeracionController extends Controller
{
    public function index(Request $request, Course $course): JsonResponse
    {
        Gate::authorize('update', $course);

        return response()->json(['data' => TaskComment::with('autor:id,name')->where('course_id', $course->id)
            ->when($request->query('tarea'), fn ($q, $t) => $q->where('tarea_uid', $t))
            ->latest()->limit(200)->get()]);
    }

    public function update(Request $request, TaskComment $comment): JsonResponse
    {
        Gate::authorize('update', Course::findOrFail($comment->course_id));
        $comment->update($request->validate(['oculto' => ['required', 'boolean']]));
        Auditoria::registrar('comentario.moderado', $comment, ['oculto' => $comment->oculto]);

        return response()->json(['data' => $comment]);
    }
}
