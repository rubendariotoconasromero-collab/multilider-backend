<?php

namespace App\Services\Remote;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use InvalidArgumentException;

class HttpClientFactory
{
    public function make(string $service): ClientInterface
    {
        if (! in_array($service, ['seguridad', 'propiedades', 'comercial', 'finanzas'], true)) {
            throw new InvalidArgumentException('Servicio desconocido.');
        }
        $url = config("services.$service.base_url");
        $parts = is_string($url) ? parse_url($url) : false;
        if (! $parts || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new IntegrationException("Falta una URL válida para $service.");
        }
        $connect = (float) config('architecture.http.connect_timeout');
        $timeout = (float) config('architecture.http.timeout');
        if ($connect <= 0 || $timeout < $connect || $timeout > 30) {
            throw new IntegrationException('Timeouts de microservicios inválidos.');
        }

        return new Client([
            'base_uri' => rtrim($url, '/').'/', 'connect_timeout' => $connect, 'timeout' => $timeout,
            'verify' => true, 'allow_redirects' => false, 'http_errors' => false,
            'headers' => ['Accept' => 'application/json'],
        ]);
    }
}
