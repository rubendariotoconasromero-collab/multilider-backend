<?php

namespace App\Contracts\Architecture;

interface IdentityGateway
{
    public function currentUser(): array;
}
