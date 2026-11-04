<?php

namespace App\Services\Remote;

use App\Auth\JwtVerifier;
use App\Auth\RemotePrincipal;
use App\Contracts\Architecture\IdentityGateway;
use App\Services\ProfileMailSession;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

final class RemoteIdentityGateway implements IdentityGateway
{
    public function __construct(private IdentityClient $client, private JwtVerifier $verifier, private PendingRevocations $revocations) {}

    public function currentUser(): array
    {
        $user = request()->user();
        if (! $user instanceof RemotePrincipal) {
            throw new AuthenticationException;
        }

        return $user->toArray();
    }

    public function login(Request $request): array
    {
        $data = $request->validate(['correo' => 'required|email', 'password' => 'required|string']);
        $body = $this->client->request('POST', 'api/auth/login', $data);
        $token = $body['accessToken'] ?? $body['access_token'] ?? null;
        if (! is_string($token)) {
            throw new IntegrationException('Respuesta de login inválida.', 503, 'INVALID_SERVICE_RESPONSE');
        }
        try {
            $claims = $this->verifier->verify($token);
        } catch (AuthenticationException $e) {
            throw new IntegrationException('Seguridad devolvió una credencial inválida.', 503, 'INVALID_SERVICE_RESPONSE');
        }
        // Confirm server-side session and current assignments, rather than trusting a login payload.
        $user = $this->client->request('GET', 'api/auth/me', [], $token);

        return ['message' => '¡Bienvenido al sistema!', 'access_token' => $token, 'token_type' => 'Bearer',
            'user' => IdentityResponseMapper::user($user, $claims['sub']), 'expires_at' => gmdate('c', $claims['exp'])];
    }

    public function logout(Request $request): array
    {
        $token = $request->bearerToken() ?? '';
        $claims = $this->verifier->verify($token, true);
        if ($this->revocations->confirmed($claims)) {
            return ['message' => 'Sesión cerrada correctamente'];
        }
        $this->revocations->enqueue($claims, $token); // Deny locally before remote I/O.
        $principal = new RemotePrincipal(['id' => (int) $claims['sub'], 'correo' => $claims['email'] ?? '', 'roles_permisos' => []], $claims, $token);
        $request->setUserResolver(fn () => $principal);
        ProfileMailSession::forget($request);
        try {
            $this->client->request('POST', 'api/auth/logout', [], $token);
        } catch (IntegrationException $e) {
            if ($e->status !== 503) {
                throw $e;
            }
            throw new IntegrationException('Sesión cerrada en Laravel; revocación global pendiente de confirmación.', 503, 'REVOCATION_PENDING');
        }
        $this->revocations->confirm($claims);

        return ['message' => 'Sesión cerrada correctamente'];
    }

    public function updateProfile(Request $request): array
    {
        $data = $request->validate(['nombre' => 'required|string|max:255', 'correo' => 'required|email|max:255',
            'id' => 'prohibited', 'estado' => 'prohibited', 'roles' => 'prohibited', 'password' => 'prohibited']);
        ProfileMailSession::forget($request);
        $response = $this->client->request('PUT', 'api/auth/profile', $data, $request->user()->credential());

        return ['message' => 'Perfil actualizado correctamente', 'data' => IdentityResponseMapper::user($response['data'] ?? [], $request->user()->claims['sub'])];
    }

    public function changePassword(Request $request): array
    {
        $data = $request->validate(['password_actual' => 'required|string', 'password_nuevo' => 'required|string|confirmed', 'password_nuevo_confirmation' => 'required|string']);
        $response = $this->client->request('PUT', 'api/auth/password', $data, $request->user()->credential());
        ProfileMailSession::forget($request);

        return ['message' => 'Contraseña actualizada correctamente', 'reauth_required' => true];
    }
}
