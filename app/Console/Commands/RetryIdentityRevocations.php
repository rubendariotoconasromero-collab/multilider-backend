<?php

namespace App\Console\Commands;

use App\Architecture\ArchitectureMode;
use App\Services\Remote\IdentityClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class RetryIdentityRevocations extends Command
{
    protected $signature = 'identity:retry-revocations';

    protected $description = 'Confirma únicamente cierres de sesión pendientes del modo remoto.';

    public function handle(ArchitectureMode $mode, IdentityClient $client): int
    {
        if ($mode->isLocal()) {
            $this->info('Modo local: no hay solicitudes a Seguridad.');

            return self::SUCCESS;
        }
        DB::table('identity_revocations')->where('expires_at', '<', now())->delete();
        $failed = false;
        foreach (DB::table('identity_revocations')->whereNull('confirmed_at')->where('next_attempt_at', '<=', now())->limit(100)->get() as $row) {
            try {
                $client->request('POST', 'api/auth/logout', [], Crypt::decryptString($row->credential));
                DB::table('identity_revocations')->where('key', $row->key)->update(['confirmed_at' => now(), 'credential' => null, 'updated_at' => now()]);
            } catch (\Throwable $e) {
                $failed = true;
                DB::table('identity_revocations')->where('key', $row->key)->update(['attempts' => $row->attempts + 1,
                    'next_attempt_at' => now()->addSeconds(min(1800, 30 * (2 ** min($row->attempts, 6)))), 'updated_at' => now()]);
            }
        }
        if ($failed) {
            $this->warn('Hay revocaciones pendientes de confirmación.');
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
