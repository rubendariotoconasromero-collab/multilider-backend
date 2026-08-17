<?php

namespace App\Console\Commands;

use App\Models\User;
use GuzzleHttp\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SyncUsuariosMicroservicio extends Command
{
    protected $signature = 'usuarios:sync';

    protected $description = 'Espeja los usuarios de MSMultilider.Seguridad hacia la tabla local `users` de Laravel, buscando por correo (para que el JwtGuard pueda resolverlos).';

    public function handle(): int
    {
        try {
            $client = new Client([
                'base_uri' => rtrim(config('services.seguridad.base_url'), '/') . '/',
                'timeout' => 15,
            ]);

            $res = $client->get('api/usuario', [
                'headers' => ['Authorization' => 'Bearer ' . $this->token()],
            ]);

            $usuarios = json_decode($res->getBody()->getContents(), true) ?? [];

            foreach ($usuarios as $dto) {
                $existente = User::where('correo', $dto['correo'])->first();

                if ($existente) {
                    $existente->update([
                        'nombre' => $dto['nombre'],
                        'estado' => $dto['estado'],
                    ]);
                } else {
                    User::create([
                        'nombre' => $dto['nombre'],
                        'correo' => $dto['correo'],
                        'estado' => $dto['estado'],
                        'password' => Hash::make(Str::random(40)),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            $this->error('Sync de usuarios fallido: ' . $e->getMessage());
            Log::error('usuarios:sync falló', ['exception' => $e]);
            return self::FAILURE;
        }

        $this->info('Usuarios sincronizados: ' . count($usuarios ?? []));
        return self::SUCCESS;
    }

    private function token(): string
    {
        return Cache::remember('propiedades_sync_jwt', now()->addHours(7), function () {
            $client = new Client(['base_uri' => rtrim(config('services.seguridad.base_url'), '/') . '/']);

            $res = $client->post('api/auth/login', [
                'json' => [
                    'correo' => config('services.seguridad.sync_email'),
                    'password' => config('services.seguridad.sync_password'),
                ],
            ]);

            $body = json_decode($res->getBody()->getContents(), true);

            return $body['access_token'] ?? $body['accessToken'];
        });
    }
}
