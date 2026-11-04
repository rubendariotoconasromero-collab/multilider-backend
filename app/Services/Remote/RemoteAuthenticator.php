<?php

namespace App\Services\Remote;

use App\Auth\JwtVerifier;
use App\Auth\RemotePrincipal;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;

class RemoteAuthenticator
{
    public function __construct(private JwtVerifier $verifier, private IdentityClient $client, private PendingRevocations $revocations) {}

    public function authenticate(Request $request): ?RemotePrincipal
    {
        $token = $request->bearerToken();
        if (! $token) {
            return null;
        }
        $claims = $this->verifier->verify($token);
        if ($this->revocations->contains($claims)) {
            throw new AuthenticationException('Sesión revocada.');
        }
        $dto = $this->client->request('GET', 'api/auth/me', [], $token);

        return new RemotePrincipal(IdentityResponseMapper::user($dto, $claims['sub']), $claims, $token);
    }
}
