<?php

namespace App\Services\Remote;

use App\Auth\JwtVerifier;
use App\Auth\RemotePrincipal;
use App\Contracts\Architecture\DelegatedTokenProvider;
use Illuminate\Http\Request;

final class RequestDelegatedTokenProvider implements DelegatedTokenProvider
{
    public function forRequest(Request $request, string $service): string
    {
        if (! in_array($service, ['seguridad', 'propiedades', 'comercial', 'finanzas'], true)
            || ! ($request->user() instanceof RemotePrincipal)) {
            throw new IntegrationException('Se requiere identidad remota verificada.');
        }
        $principal = $request->user();
        app(JwtVerifier::class)->verify($principal->credential());

        return $principal->credential();
    }
}
