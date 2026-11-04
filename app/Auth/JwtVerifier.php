<?php

namespace App\Auth;

use App\Services\Remote\IntegrationException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Str;

final class JwtVerifier
{
    public function verify(string $token, bool $allowExpired = false): array
    {
        $key = config('identity.key');
        if (! is_string($key) || strlen($key) < 32) {
            throw new IntegrationException('Falta configurar una clave de identidad válida.');
        }
        $previous = JWT::$leeway;
        JWT::$leeway = (int) config('identity.clock_skew', 30);
        try {
            try {
                $claims = (array) JWT::decode($token, new Key($key, 'HS256'));
            } catch (ExpiredException $e) {
                if (! $allowExpired) {
                    throw $e;
                }
                $claims = (array) $e->getPayload(); // Signature is verified by the library before lifetime checks.
            }
            $audiences = is_array($claims['aud'] ?? null) ? $claims['aud'] : [$claims['aud'] ?? null];
            if (($claims['iss'] ?? null) !== config('identity.issuer')
                || ! in_array(config('identity.audience'), $audiences, true)
                || ! isset($claims['exp'], $claims['sub'], $claims['jti'])
                || ! is_int($claims['exp']) || $claims['exp'] <= 0
                || ! is_string($claims['sub']) || ! preg_match('/^[1-9][0-9]*$/D', $claims['sub'])
                || ! is_string($claims['jti']) || ! Str::isUuid($claims['jti'])) {
                throw new \UnexpectedValueException;
            }
            if (isset($claims['ver'])) {
                if (! is_int($claims['ver']) || $claims['ver'] < 0 || ! is_int($claims['iat'] ?? null)
                    || ! is_int($claims['nbf'] ?? null) || $claims['iat'] > time() + JWT::$leeway) {
                    throw new \UnexpectedValueException;
                }
            } else {
                $until = config('identity.legacy_until');
                $deadline = $until ? strtotime($until) : false;
                if (! $deadline || time() > $deadline + JWT::$leeway || $claims['exp'] > $deadline) {
                    throw new \UnexpectedValueException;
                }
            }

            return $claims;
        } catch (IntegrationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new AuthenticationException('Credencial inválida o vencida.');
        } finally {
            JWT::$leeway = $previous;
        }
    }
}
