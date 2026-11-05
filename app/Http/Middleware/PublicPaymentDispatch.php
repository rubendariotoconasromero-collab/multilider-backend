<?php

namespace App\Http\Middleware;

use App\Architecture\ArchitectureMode;
use App\Architecture\PublicPaymentRoutes;
use App\Services\Remote\HttpClientFactory;
use App\Services\Remote\IntegrationException;
use Closure;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\Request;

final class PublicPaymentDispatch
{
    public function __construct(private ArchitectureMode $mode) {}
    public function handle(Request $request, Closure $next)
    {
        if ($this->mode->isLocal() || ! ($target = PublicPaymentRoutes::target($request))) { return $next($request); }
        if (! config('architecture.public_test_payments_enabled') && in_array($target, ['api/public/clientes/buscar', 'api/public/pagos/procesar'], true)) {
            throw new IntegrationException('El registro remoto de pagos de prueba aún no está habilitado.');
        }
        $method = $request->isMethod('HEAD') ? 'GET' : $request->method();
        $options = ['query' => $request->query(), 'headers' => ['Accept' => 'application/json']];
        if ($method === 'POST') { $options['json'] = $request->except(['appkey', 'api_key', 'password']); }
        try {
            $response = app(HttpClientFactory::class)->make('finanzas')->request($method, $target, $options);
            $body = (string) $response->getBody();
            if (strlen($body) > 262144) { throw new IntegrationException('Respuesta de pagos inválida.'); }
            $status = $response->getStatusCode();
            if ($status >= 500) { throw new IntegrationException('La orden sigue pendiente de verificación.', 503, 'RESULTADO_INCIERTO'); }
            return response($body, $status)->header('Content-Type', 'application/json')->header('Cache-Control', 'no-store');
        } catch (GuzzleException) { throw new IntegrationException('Finanzas no respondió. Conserva la referencia de pago.', 503, 'RESULTADO_INCIERTO'); }
    }
}
