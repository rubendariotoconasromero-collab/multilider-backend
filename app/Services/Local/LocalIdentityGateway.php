<?php

namespace App\Services\Local;

use App\Auth\ArchitectureGuard;
use App\Contracts\Architecture\IdentityGateway;
use App\Models\User;
use App\Services\ProfileMailSession;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

final class LocalIdentityGateway implements IdentityGateway
{
    public function currentUser(): array
    {
        $user = request()->user();
        if (! $user) {
            throw new AuthenticationException;
        }

        return $this->serialize($user);
    }

    private function serialize(User $user): array
    {
        $user->load(['rolesPermisos.rolPermiso.rol', 'rolesPermisos.rolPermiso.permiso']);
        $user->setRelation('rolesPermisos', $user->rolesPermisos->filter(fn ($row) => $row->rolPermiso?->rol?->estado && $row->rolPermiso?->permiso?->estado)->values());

        return $user->toArray();
    }

    public function login(Request $request): array
    {
        $data = $request->validate(['correo' => 'required|email', 'password' => 'required|string']);
        $key = Str::transliterate(Str::lower($data['correo']).'|'.$request->ip());
        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw new HttpResponseException(response()->json(['message' => 'Demasiados intentos fallidos. Por seguridad, la cuenta está bloqueada temporalmente.', 'seconds_remaining' => RateLimiter::availableIn($key)], 429));
        }
        $user = User::where('correo', $data['correo'])->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, 60);
            throw new HttpResponseException(response()->json(['message' => 'Las credenciales proporcionadas son incorrectas.'], 401));
        }
        RateLimiter::clear($key);
        if (! $user->estado) {
            throw new HttpResponseException(response()->json(['message' => 'Tu cuenta ha sido desactivada. Contacta al administrador.'], 403));
        }
        $token = $user->createToken('auth_token')->plainTextToken;

        return ['message' => '¡Bienvenido al sistema!', 'access_token' => $token, 'token_type' => 'Bearer',
            'user' => $this->serialize($user)];
    }

    public function logout(Request $request): array
    {
        $user = ArchitectureGuard::localUser($request);
        if (! $user) {
            throw new AuthenticationException;
        }
        $request->setUserResolver(fn () => $user);
        ProfileMailSession::forget($request);
        $user->currentAccessToken()?->delete();

        return ['message' => 'Sesión cerrada correctamente'];
    }

    public function updateProfile(Request $request): array
    {
        $user = $request->user();
        $data = $request->validate(['nombre' => 'required|string|max:255', 'correo' => 'required|email|unique:users,correo,'.$user->id]);
        ProfileMailSession::forget($request);
        $user->update($data);

        return ['message' => 'Perfil actualizado correctamente', 'data' => $this->currentUser()];
    }

    public function changePassword(Request $request): array
    {
        $data = $request->validate(['password_actual' => 'required|string',
            'password_nuevo' => ['required', 'confirmed', Password::min(8)->mixedCase()->letters()->numbers()->symbols()],
            'password_nuevo_confirmation' => 'required']);
        $user = $request->user();
        if (! Hash::check($data['password_actual'], $user->password)) {
            throw ValidationException::withMessages(['password_actual' => ['La contraseña actual no es correcta.']]);
        }
        ProfileMailSession::forget($request);
        DB::transaction(fn () => $user->update(['password' => Hash::make($data['password_nuevo'])]));

        return ['message' => 'Contraseña actualizada correctamente', 'reauth_required' => true];
    }
}
