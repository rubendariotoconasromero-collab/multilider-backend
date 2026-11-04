<?php

namespace App\Contracts\Architecture;

interface FinanceGateway
{
    public function payment(int $id): array;
}
