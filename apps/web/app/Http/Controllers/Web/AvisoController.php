<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Avisos en la plataforma (notificaciones de Laravel en la base de datos). */
class AvisoController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('avisos/Index', [
            'avisos' => $request->user()->notifications()->limit(50)->get()
                ->map(fn ($n) => ['id' => $n->id, 'datos' => $n->data, 'leido' => $n->read_at !== null, 'fecha' => $n->created_at]),
        ]);
    }

    public function leidos(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return back();
    }
}
