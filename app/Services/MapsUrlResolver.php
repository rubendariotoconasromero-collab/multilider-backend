<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Request;

final class MapsUrlResolver
{
    public function __construct(private ?ClientInterface $client = null, private ?MapsDns $dns = null) {}

    public function resolve(Request $request)
    {
        $url = $request->validate(['url' => 'required|string|max:2048'])['url'];
        try {
            if (! extension_loaded('curl')) {
                throw new \RuntimeException('cURL requerido.');
            }
            $client = $this->client ?? new Client(['allow_redirects' => false, 'http_errors' => false, 'connect_timeout' => 2, 'timeout' => 5, 'verify' => true]);
            $deadline = microtime(true) + 5;
            for ($hop = 0; $hop < 5; $hop++) {
                $this->allowed($url);
                if ($coordinates = self::coordinates($url)) {
                    return response()->json($coordinates + ['url' => $url]);
                }
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    break;
                }
                [$host, $ip] = $this->destination($url);
                $response = $client->get($url, ['curl' => [CURLOPT_RESOLVE => ["$host:443:$ip"]], 'timeout' => $remaining, 'connect_timeout' => min(2, $remaining), 'stream' => true, 'verify' => true, 'allow_redirects' => false, 'http_errors' => false]);
                $status = $response->getStatusCode();
                $response->getBody()->close();
                if ($status >= 200 && $status < 300) {
                    break;
                }
                if (! in_array($status, [301, 302, 303, 307, 308], true)) {
                    break;
                }
                $location = $response->getHeaderLine('Location');
                $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));
            }
        } catch (\Throwable $e) {
            return response()->json(['error' => 'No se pudo resolver el enlace de Google Maps.'], 422);
        }

        return response()->json(['error' => 'No se pudo resolver el enlace de Google Maps.'], 422);
    }

    public static function coordinates(string $url): ?array
    {
        foreach (['/@(-?\d+(?:\.\d+)?),(-?\d+(?:\.\d+)?)/', '~/maps/search/(-?\d+(?:\.\d+)?),\+?(-?\d+(?:\.\d+)?)~',
            '/[?&](?:q|query|ll)=(-?\d+(?:\.\d+)?),\+?(-?\d+(?:\.\d+)?)/', '~/place/[^/]*/(-?\d+(?:\.\d+)?),\+?(-?\d+(?:\.\d+)?)~'] as $pattern) {
            if (preg_match($pattern, rawurldecode($url), $match) && abs((float) $match[1]) <= 90 && abs((float) $match[2]) <= 180) {
                return ['lat' => (float) $match[1], 'lng' => (float) $match[2]];
            }
        }

        return null;
    }

    private function allowed(string $url): string
    {
        $parts = parse_url($url);
        $host = strtolower($parts['host'] ?? '');
        if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
            || (isset($parts['port']) && $parts['port'] !== 443)
            || ! in_array($host, ['maps.app.goo.gl', 'goo.gl', 'www.google.com', 'maps.google.com', 'google.com'], true)) {
            throw new \InvalidArgumentException('Enlace no permitido.');
        }

        return $host;
    }

    public function destination(string $url): array
    {
        $host = $this->allowed($url);
        $addresses = ($this->dns ?? new MapsDns)->addresses($host);
        if (! $addresses) {
            throw new \InvalidArgumentException('DNS no disponible.');
        }
        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) || (ip2long($address) >= ip2long('100.64.0.0') && ip2long($address) <= ip2long('100.127.255.255'))) {
                throw new \InvalidArgumentException('Destino no permitido.');
            }
        }

        return [$host, $addresses[0]];
    }
}
