<?php

namespace App\Services\Remote;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class PendingRevocations
{
    public function key(array $claims): string
    {
        return hash('sha256', $claims['iss'].'|'.$claims['jti']);
    }

    public function contains(array $claims): bool
    {
        try {
            return DB::table('identity_revocations')->where('key', $this->key($claims))->exists();
        } catch (QueryException $e) {
            throw new IntegrationException('Faltan las migraciones de identidad.');
        }
    }

    public function enqueue(array $claims, string $token): void
    {
        DB::table('identity_revocations')->updateOrInsert(['key' => $this->key($claims)], [
            'credential' => Crypt::encryptString($token), 'expires_at' => date('Y-m-d H:i:s', $claims['exp'] + 30),
            'next_attempt_at' => now(), 'updated_at' => now(), 'created_at' => now(),
        ]);
    }

    public function confirmed(array $claims): bool
    {
        return DB::table('identity_revocations')->where('key', $this->key($claims))->whereNotNull('confirmed_at')->exists();
    }

    public function confirm(array $claims): void
    {
        DB::table('identity_revocations')->where('key', $this->key($claims))->update([
            'confirmed_at' => now(), 'credential' => null, 'updated_at' => now(),
        ]);
    }
}
