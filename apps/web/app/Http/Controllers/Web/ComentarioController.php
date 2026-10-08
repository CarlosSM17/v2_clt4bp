<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\TaskComment;
use App\Services\Aula\Eventos;
use App\Services\Aula\ServicioAula;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Discusión por tarea dentro del grupo (memoria colectiva). Solo en tareas colaborativas. */
class ComentarioController extends Controller
{
    public function store(Request $request, Course $curso, string $tarea, ServicioAula $aula): RedirectResponse
    {
        $ctx = $aula->contexto(AulaController::inscripcion($request, $curso) ?? abort(403));
        abort_unless($ctx->tarea($tarea)['colaborativa'] ?? false, 403, 'Esta tarea no tiene discusión.');
        $texto = $request->validate(['texto' => ['required', 'string', 'min:2', 'max:2000']])['texto'];

        $c = TaskComment::create([
            'course_id' => $curso->id, 'tarea_uid' => $tarea, 'diff_group_id' => $ctx->grupo?->id,
            'user_id' => $request->user()->id, 'texto' => $texto,
        ]);
        Eventos::registrar($ctx->inscripcion->id, 'comento', 'tarea', $tarea, ['comentario_id' => $c->id], $ctx->release->id);

        return back();
    }

    public function destroy(Request $request, TaskComment $comentario): RedirectResponse
    {
        abort_unless($comentario->user_id === $request->user()->id, 403);
        $comentario->delete();

        return back();
    }
}
