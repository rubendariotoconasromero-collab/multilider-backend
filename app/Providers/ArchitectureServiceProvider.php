<?php

namespace App\Providers;

use App\Architecture\ArchitectureMode;
use App\Contracts\Architecture\CatalogGateway;
use App\Contracts\Architecture\CommercialGateway;
use App\Contracts\Architecture\DelegatedTokenProvider;
use App\Contracts\Architecture\FinanceGateway;
use App\Contracts\Architecture\IdentityGateway;
use App\Services\Local\LocalCatalogGateway;
use App\Services\Local\LocalDomainGateway;
use App\Services\Remote\PendingDelegatedTokenProvider;
use App\Services\Remote\PendingDomainGateway;
use App\Services\Remote\RemoteCatalogGateway;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

final class ArchitectureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ArchitectureMode::class, fn ($app) => new ArchitectureMode($app['config']->get('architecture.mode', 'local')));
        $this->app->bind(CatalogGateway::class, fn ($app) => $app->make($app->make(ArchitectureMode::class)->isLocal() ? LocalCatalogGateway::class : RemoteCatalogGateway::class));
        foreach ([IdentityGateway::class, CommercialGateway::class, FinanceGateway::class] as $contract) {
            $this->app->bind($contract, fn ($app) => $app->make($app->make(ArchitectureMode::class)->isLocal() ? LocalDomainGateway::class : PendingDomainGateway::class));
        }
        $this->app->bind(DelegatedTokenProvider::class, PendingDelegatedTokenProvider::class);
    }

    public function boot(): void
    {
        // Validate at startup, including CLI and cached configuration.
        if ($this->app->make(ArchitectureMode::class)->isLocal() && config('services.microservice_sync_enabled')) {
            Log::warning('MICROSERVICE_SYNC_ENABLED ignorado: APP_ARCHITECTURE=local prevalece.');
        }
    }
}
