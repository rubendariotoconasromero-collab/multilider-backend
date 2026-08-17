<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;

/**
 * Guard adicional (no reemplaza a Sanctum) que valida JWT emitidos por
 * MSMultilider.Seguridad y resuelve al usuario REAL de Laravel por correo,
 * para que toda la lógica de negocio existente (relaciones Eloquent,
 * hasPermission(), etc.) siga funcionando sin cambios.
 */
class JwtGuard implements Guard
{
    protected ?User $user = null;
    protected Request $request;
    protected string $secret;

    public function __construct(Request $request, string $secret)
    {
        $this->request = $request;
        $this->secret = $secret;
    }

    public function check(): bool { return $this->user() !== null; }
    public function guest(): bool { return !$this->check(); }
    public function hasUser(): bool { return $this->user !== null; }

    public function user(): ?User
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $token = $this->getBearerToken();
        if (!$token) {
            return null;
        }

        $payload = $this->decodeToken($token);
        if (!$payload || empty($payload->email)) {
            return null;
        }

        $this->user = User::where('correo', $payload->email)->first();

        if ($this->user) {
            $raw = $payload->permission ?? [];
            $this->user->jwtPermissions = is_array($raw) ? $raw : array_filter([$raw]);
        }

        return $this->user;
    }

    public function id(): mixed
    {
        return $this->user()?->id;
    }

    public function validate(array $credentials = []): bool
    {
        return false;
    }

    public function setUser(Authenticatable $user): static
    {
        $this->user = $user;
        return $this;
    }

    private function getBearerToken(): ?string
    {
        $header = (string) $this->request->header('Authorization', '');
        if (str_starts_with($header, 'Bearer ')) {
            return substr($header, 7);
        }
        return null;
    }

    /**
     * Decodifica y verifica un JWT HS256 sin dependencias externas.
     */
    private function decodeToken(string $token): ?object
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }

        [$header, $payload, $signature] = $parts;

        $signingInput = "$header.$payload";
        $expectedSig = hash_hmac('sha256', $signingInput, $this->secret, true);
        $providedSig = base64_decode(strtr($signature, '-_', '+/') . str_repeat('=', 3));

        if (!hash_equals($expectedSig, $providedSig)) {
            return null;
        }

        $payloadJson = base64_decode(strtr($payload, '-_', '+/') . str_repeat('=', 3));
        $decoded = json_decode($payloadJson);

        if (!$decoded) {
            return null;
        }

        if (isset($decoded->exp) && $decoded->exp < time()) {
            return null;
        }

        return $decoded;
    }
}
