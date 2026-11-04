<?php

namespace Tests\Support;

use App\Services\Remote\HttpClientFactory;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;

class FakeMicroserviceClients extends HttpClientFactory
{
    public array $history = [];

    public array $services = [];

    private ClientInterface $client;

    public function __construct(array $responses)
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
        $this->client = new Client(['base_uri' => 'https://fixture.example.test/', 'handler' => $stack,
            'http_errors' => false, 'allow_redirects' => false, 'connect_timeout' => 2, 'timeout' => 5, 'verify' => true]);
    }

    public function make(string $service): ClientInterface
    {
        $this->services[] = $service;

        return $this->client;
    }
}
