<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Aula\ResumenInicio;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Inicio: lo primero que ve cada persona al entrar. El estudiante, qué le toca en cada curso; el equipo docente, sus cursos. */
class InicioController extends Controller
{
    public function __invoke(Request $request, ResumenInicio $resumen): Response
    {
        $usuario = $request->user();
        $personal = $usuario->esPersonal();

        return Inertia::render('Dashboard', [
            'nombre' => $usuario->name,
            'cursos' => $personal ? [] : $resumen->cursos($usuario),
            // El diseño, la publicación y el seguimiento se hacen en la consola: aquí solo un resumen
            'imparte' => $personal ? $usuario->cursosQueImparte()
                ->withCount(['enrollments as inscritos' => fn ($q) => $q->whereNotIn('estado', ['solicitud', 'rechazada', 'baja'])])
                ->withCount(['enrollments as solicitudes' => fn ($q) => $q->where('estado', 'solicitud')])
                ->orderBy('titulo')->get(['courses.id', 'titulo', 'lenguaje', 'estado'])
                ->map(fn ($c) => ['id' => $c->id, 'titulo' => $c->titulo, 'lenguaje' => $c->lenguaje->value,
                    'estado' => $c->estado->value, 'inscritos' => $c->inscritos, 'solicitudes' => $c->solicitudes]) : [],
            'avisos' => [
                'sin_leer' => $usuario->unreadNotifications()->count(),
                'recientes' => $usuario->notifications()->limit(3)->get()
                    ->map(fn ($n) => ['id' => $n->id, 'datos' => $n->data, 'leido' => $n->read_at !== null, 'fecha' => $n->created_at]),
            ],
        ]);
    }
}
