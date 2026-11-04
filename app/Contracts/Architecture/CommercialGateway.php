<?php

namespace App\Contracts\Architecture;

interface CommercialGateway
{
    public function sale(int $id): array;
}
