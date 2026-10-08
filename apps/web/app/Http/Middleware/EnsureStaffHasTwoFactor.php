<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class EnsureStaffHasTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->esPersonal() && $user->two_factor_confirmed_at === null
            && ! $request->routeIs('security.*', 'two-factor.*', 'password.confirm*', 'logout', 'user-password.*')) {
            Inertia::flash('toast', ['type' => 'warning', 'message' => 'Activa la verificación en dos pasos para continuar.']);

            return redirect()->route('security.edit');
        }

        return $next($request);
    }
}
