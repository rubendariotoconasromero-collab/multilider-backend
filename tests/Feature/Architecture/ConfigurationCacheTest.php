<?php

namespace Tests\Feature\Architecture;

use Symfony\Component\Process\Process;
use Tests\TestCase;

class ConfigurationCacheTest extends TestCase
{
    /** @dataProvider modes */
    public function test_same_configuration_and_bindings_with_and_without_cache(string $mode): void
    {
        $cache = 'storage/framework/testing/config-probe.php';
        $env = ['APP_CONFIG_CACHE' => $cache, 'APP_ARCHITECTURE' => $mode,
            'JWT_SECRET' => 'fixture-signing-key', 'LIBELULA_ENABLED' => '0',
            'LIBELULA_API_URL' => 'https://payments.example.test', 'LIBELULA_API_KEY' => 'fixture-payment-key',
            'FRONTEND_URL' => 'https://front.example.test', 'APP_URL' => 'https://api.example.test'];
        $probe = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$request = Illuminate\Http\Request::create('/api/login', 'POST', [], [], [], ['HTTP_ACCEPT' => 'application/json']);
$response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
echo json_encode([
    'mode' => app(App\Architecture\ArchitectureMode::class)->value,
    'provider' => get_class(app(App\Contracts\Architecture\CatalogGateway::class)),
    'jwt' => config('services.jwt_secret'),
    'payment' => config('services.libelula'),
    'http_status' => $response->getStatusCode(),
]);
PHP;
        try {
            $plain = $this->runPhp(['-r', $probe], $env);
            $this->runPhp(['artisan', 'config:cache'], $env);
            $cached = $this->runPhp(['-r', $probe], $env + []);
            $this->assertSame($plain, $cached);
            $this->assertSame($mode, json_decode($cached, true)['mode']);
            $this->assertSame($mode === 'local' ? 422 : 503, json_decode($cached, true)['http_status']);
            $changed = $env;
            $changed['APP_ARCHITECTURE'] = $mode === 'local' ? 'microservices' : 'local';
            $this->assertSame($cached, $this->runPhp(['-r', $probe], $changed), 'Cached mode must not change until cache is regenerated.');
            $this->runPhp(['artisan', 'route:list', '--path=api/propiedades', '--json'], $env);
            $this->runPhp(['artisan', 'schedule:list'], $env);
        } finally {
            if (is_file(base_path($cache))) {
                unlink(base_path($cache));
            }
        }
    }

    public static function modes(): array
    {
        return [['local'], ['microservices']];
    }

    private function runPhp(array $arguments, array $env): string
    {
        $process = new Process([PHP_BINARY, ...$arguments], base_path(), $env);
        $process->setTimeout(30);
        $process->run();
        $this->assertTrue($process->isSuccessful(), 'Isolated configuration probe failed: '.$process->getErrorOutput());

        return trim($process->getOutput());
    }
}
