<?php

namespace App\Providers;

use App\Auth\ArchitectureGuard;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Auth;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     *
     * @var array<class-string, class-string>
     */
    protected $policies = [
        //
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();

        Auth::extend('architecture', function ($app) {
            $guard = new ArchitectureGuard($app['request']);
            $app->refresh('request', $guard, 'setRequest');

            return $guard;
        });
    }
}
