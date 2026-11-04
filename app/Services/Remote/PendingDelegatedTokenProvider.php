<?php

namespace App\Services\Remote;

use App\Contracts\Architecture\DelegatedTokenProvider;
use Illuminate\Http\Request;

final class PendingDelegatedTokenProvider implements DelegatedTokenProvider
{
    public function forRequest(Request $request, string $service): string
    {
        // Phase 3 owns login/exchange/refresh. No forwarding arbitrary bearer tokens.
        throw new IntegrationException('La autenticación delegada se habilitará en la fase 3.');
    }
}
