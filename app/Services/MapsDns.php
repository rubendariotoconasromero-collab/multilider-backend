<?php

namespace App\Services;

class MapsDns
{
    public function addresses(string $host): array
    {
        return gethostbynamel($host) ?: [];
    }
}
