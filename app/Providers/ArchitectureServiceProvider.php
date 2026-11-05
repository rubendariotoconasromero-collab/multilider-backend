<?php

namespace App\Providers;

use App\Architecture\ArchitectureMode;
use App\Contracts\Architecture\CatalogAdministrationGateway;
use App\Contracts\Architecture\CatalogGateway;
use App\Contracts\Architecture\CatalogMediaGateway;
use App\Contracts\Architecture\CommercialGateway;
use App\Contracts\Architecture\DelegatedTokenProvider;
use App\Contracts\Architecture\FinanceGateway;
use App\Contracts\Architecture\IdentityAdministrationGateway;
use App\Contracts\Architecture\IdentityGateway;
use App\Contracts\Architecture\PublicCatalogGateway;
use App\Contracts\Architecture\ServiceIdentityProvider;
use App\Services\Local\LocalCatalogAdministrationGateway;
use App\Services\Local\LocalCatalogGateway;
use App\Services\Local\LocalCatalogMediaGateway;
use App\Services\Local\LocalDomainGateway;
use App\Services\Local\LocalIdentityAdministrationGateway;
use App\Services\Local\LocalIdentityGateway;
use App\Services\Local\LocalPublicCatalogGateway;
use App\Services\Remote\DisabledServiceIdentityProvider;
use App\Services\Remote\HttpClientFactory;
use App\Services\Remote\PendingDomainGateway;
use App\Services\Remote\RemoteCatalogAdministrationGateway;
use App\Services\Remote\RemoteCatalogGateway;
use App\Services\Remote\RemoteCatalogMediaGateway;
use App\Services\Remote\RemoteIdentityAdministrationGateway;
use App\Services\Remote\RemoteIdentityGateway;
use App\Services\Remote\RemotePublicCatalogGateway;
use App\Services\Remote\RequestDelegatedTokenProvider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

final class ArchitectureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ArchitectureMode::class, fn ($app) => new ArchitectureMode($app['config']->get('architecture.mode', 'local')));
        $this->app->bind(CatalogGateway::class, fn ($app) => $app->make($app->make(ArchitectureMode::class)->isLocal() ? LocalCatalogGateway::class : RemoteCatalogGateway::class));
        foreach ([CommercialGateway::class, FinanceGateway::class] as $contract) {
            $this->app->bind($contract, fn ($app) => $app->make($app->make(ArchitectureMode::class)->isLocal() ? LocalDomainGateway::class : \App\Services\Remote\RemoteDomainGateway::class));
        }
        $this->app->bind(IdentityGateway::class, fn ($app) => $app->make($app->make(ArchitectureMode::class)->isLocal()
            ? LocalIdentityGateway::class : RemoteIdentityGateway::class));
        $this->app->bind(IdentityAdministrationGateway::class, fn ($app) => $app->make(
            $app->make(ArchitectureMode::class)->isLocal() ? LocalIdentityAdministrationGateway::class : RemoteIdentityAdministrationGateway::class));
        $this->app->bind(CatalogAdministrationGateway::class, fn ($app) => $app->make($app->make(ArchitectureMode::class)->isLocal()
            ? LocalCatalogAdministrationGateway::class : RemoteCatalogAdministrationGateway::class));
        $this->app->bind(PublicCatalogGateway::class, fn ($app) => $app->make($app->make(ArchitectureMode::class)->isLocal()
            ? LocalPublicCatalogGateway::class : RemotePublicCatalogGateway::class));
        $this->app->bind(CatalogMediaGateway::class, fn ($app) => $app->make($app->make(ArchitectureMode::class)->isLocal()
            ? LocalCatalogMediaGateway::class : RemoteCatalogMediaGateway::class));
        $this->app->bind(DelegatedTokenProvider::class, RequestDelegatedTokenProvider::class);
        $this->app->bind(ServiceIdentityProvider::class, DisabledServiceIdentityProvider::class);
    }

    public function boot(): void
    {
        if (! $this->app->make(ArchitectureMode::class)->isLocal()) {
            if (! is_string(config('identity.key')) || strlen(config('identity.key')) < 32
                || ! is_string(config('identity.issuer')) || config('identity.issuer') === ''
                || ! is_string(config('identity.audience')) || config('identity.audience') === '') {
                throw new \InvalidArgumentException('Modo microservices: configura IDENTITY_SIGNING_KEY (mínimo 32 bytes), IDENTITY_ISSUER e IDENTITY_AUDIENCE.');
            }
            $this->app->make(HttpClientFactory::class)->make('seguridad');
        }
        // Validate at startup, including CLI and cached configuration.
        if ($this->app->make(ArchitectureMode::class)->isLocal() && config('services.microservice_sync_enabled')) {
            Log::warning('MICROSERVICE_SYNC_ENABLED ignorado: APP_ARCHITECTURE=local prevalece.');
        }
    }
}
