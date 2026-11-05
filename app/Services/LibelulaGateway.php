<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class LibelulaGateway
{
    public function ensureReady(): void
    {
        $url = config('services.libelula.base_url');
        if (! is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https' || ! config('services.libelula.api_key')) {
            throw new \App\Services\Remote\IntegrationException('La configuración existente de Libélula no está disponible.');
        }
    }
    private function request()
    {
        $this->ensureReady();
        $url = config('services.libelula.base_url');
        return Http::baseUrl(rtrim($url, '/'))->connectTimeout(2)->timeout(10)->withoutRedirecting();
    }
    public function register(array $data): array
    {
        $response = $this->request()->post('/rest/deuda/registrar', ['appkey' => config('services.libelula.api_key')] + $data);
        $response->throw();
        $body = $response->json();
        if (! is_array($body) || (int) ($body['error'] ?? 1) !== 0 || ! is_string($body['url_pasarela_pagos'] ?? null)) {
            throw new \RuntimeException('Libélula no confirmó el registro.');
        }
        if (parse_url($body['url_pasarela_pagos'], PHP_URL_SCHEME) !== 'https') {
            throw new \RuntimeException('La pasarela no devolvió una URL HTTPS.');
        }
        return $body;
    }
    public function verify(string $reference, string $currency, string $amount, string $created): bool
    {
        // Merchant-authenticated reconciliation. Callback/browser parameters are never proof of payment.
        $day = \Carbon\Carbon::parse($created)->timezone('America/La_Paz')->startOfDay();
        $response = $this->request()->post('/rest/deuda/consultar_deudas/por_fechas', [
            'appkey' => config('services.libelula.api_key'),
            'fecha_inicial' => $day->format('Y-m-d H:i:s'), 'fecha_final' => $day->copy()->addDay()->format('Y-m-d H:i:s'),
        ]);
        $response->throw();
        $body = $response->json();
        if (! is_array($body) || (int) ($body['error'] ?? 1) !== 0 || ! is_array($body['datos'] ?? null)) {
            throw new \RuntimeException('Contrato de conciliación no reconocido; conserva la orden pendiente.');
        }
        $matches = array_values(array_filter($body['datos'], fn ($r) => is_array($r) && ($r['identificador'] ?? null) === $reference));
        if (count($matches) !== 1) { return false; }
        $row = $matches[0];
        $actual = $row['valor_total'] ?? null;
        return ($row['pagado'] ?? false) === true && ($row['moneda'] ?? null) === $currency
            && is_scalar($actual) && preg_match('/^[0-9]+(?:\.[0-9]{1,2}0*)?$/D', (string) $actual)
            && number_format((float) $actual, 2, '.', '') === $amount;
    }
}
