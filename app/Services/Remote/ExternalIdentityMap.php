<?php

namespace App\Services\Remote;

use App\Auth\RemotePrincipal;
use Illuminate\Support\Facades\DB;

final class ExternalIdentityMap
{
    public function localUserId(RemotePrincipal $principal): int
    {
        $link = DB::table('external_identities')->where('issuer', $principal->claims['iss'])
            ->where('subject', $principal->claims['sub'])->where('review_status', 'approved')->first();
        if (! $link || ! $link->local_user_id) {
            throw new IntegrationException('La identidad requiere una equivalencia revisada.', 409, 'IDENTITY_MAPPING_REQUIRED');
        }

        return (int) $link->local_user_id;
    }
}
