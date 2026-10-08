<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Auditoria;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    public function login(Request $request, TwoFactorAuthenticationProvider $totp): JsonResponse
    {
        $datos = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $user = User::where('email', $datos['email'])->first();

        if (! $user || ! Hash::check($datos['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => 'Credenciales incorrectas.']);
        }
        if (! $user->esPersonal()) {
            abort(403, 'La consola es solo para administradores e instructores.');
        }
        if ($user->estaSuspendido()) {
            abort(403, 'Tu cuenta está suspendida.');
        }
        if ($user->two_factor_confirmed_at === null) {
            abort(403, 'Activa la verificación en dos pasos en la web (Configuración → Seguridad) antes de usar la consola.');
        }

        if (empty($datos['code']) && empty($datos['recovery_code'])) {
            return response()->json([
                'requires_two_factor' => true,
                'message' => 'Escribe el código de tu aplicación de autenticación.',
            ], 422);
        }

        if (! $this->segundoFactorValido($user, $totp, $datos['code'] ?? null, $datos['recovery_code'] ?? null)) {
            throw ValidationException::withMessages(['code' => 'El código no es válido.']);
        }

        $token = $user->createToken(
            $datos['device_name'],
            ['consola'],
            now()->addDays(config('clt4bp.token_consola_dias'))
        );

        auth()->setUser($user);
        Auditoria::registrar('consola.login', $user, ['dispositivo' => $datos['device_name']]);

        return response()->json([
            'token' => $token->plainTextToken,
            'expires_at' => $token->accessToken->expires_at,
            'user' => new UserResource($user),
        ]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['ok' => true]);
    }

    private function segundoFactorValido(User $user, TwoFactorAuthenticationProvider $totp, ?string $codigo, ?string $recuperacion): bool
    {
        if ($codigo) {
            return $totp->verify(Fortify::currentEncrypter()->decrypt($user->two_factor_secret), $codigo);
        }

        $valido = collect($user->recoveryCodes())->first(fn ($c) => hash_equals($c, (string) $recuperacion));
        if ($valido) {
            $user->replaceRecoveryCode($valido);   // cada código de recuperación sirve una sola vez

            return true;
        }

        return false;
    }
}
