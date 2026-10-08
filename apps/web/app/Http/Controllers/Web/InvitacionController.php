<?php

namespace App\Http\Controllers\Web;

use App\Concerns\PasswordValidationRules;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Auditoria;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class InvitacionController extends Controller
{
    use PasswordValidationRules;

    public function show(Request $request, User $user): Response
    {
        abort_if($user->invitacion_aceptada_at !== null, 410, 'Esta invitación ya fue usada.');

        return Inertia::render('auth/AceptarInvitacion', [
            'nombre' => $user->name,
            'email' => $user->email,
            'accion' => $request->fullUrl(),   // incluye la firma: el POST la necesita
        ]);
    }

    public function store(Request $request, User $user): RedirectResponse
    {
        abort_if($user->invitacion_aceptada_at !== null, 410, 'Esta invitación ya fue usada.');
        $datos = $request->validate(['password' => $this->passwordRules()]);

        $user->forceFill([
            'password' => $datos['password'],
            'email_verified_at' => now(),          // el enlace llegó a su correo
            'invitacion_aceptada_at' => now(),
        ])->save();

        Auth::login($user);
        $request->session()->regenerate();
        Auditoria::registrar('instructor.invitacion_aceptada', $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Contraseña definida. Ahora activa la verificación en dos pasos.']);

        return redirect()->route('security.edit');
    }
}
