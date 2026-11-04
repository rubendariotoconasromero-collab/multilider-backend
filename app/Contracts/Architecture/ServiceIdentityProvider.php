<?php

namespace App\Contracts\Architecture;

interface ServiceIdentityProvider
{
    /** No technical identities are issued until a concrete least-privilege job exists. */
    public function forOperation(string $service, string $operation): string;
}
