<?php

namespace App\Contracts\Architecture;

use Illuminate\Http\Request;

interface DelegatedTokenProvider
{
    /** A verified, audience-appropriate .NET credential for this user; never a Sanctum token. */
    public function forRequest(Request $request, string $service): string;
}
