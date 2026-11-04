<?php

namespace App\Auth;

use App\Architecture\ArchitectureMode;
use App\Services\Remote\RemoteAuthenticator;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

final class ArchitectureGuard implements Guard
{
    private ?Authenticatable $principal = null;

    private bool $resolved = false;

    public function __construct(private Request $request) {}

    public function setRequest(Request $request): self
    {
        $this->request = $request;
        $this->principal = null;
        $this->resolved = false;

        return $this;
    }

    public function user(): ?Authenticatable
    {
        if ($this->resolved) {
            return $this->principal;
        }
        $this->resolved = true;
        if (app(ArchitectureMode::class)->isLocal()) {
            $this->principal = self::localUser($this->request);
        } else {
            $this->principal = app(RemoteAuthenticator::class)->authenticate($this->request);
        }

        return $this->principal;
    }

    public static function localUser(Request $request): ?Authenticatable
    {
        $guard = app('auth')->guard('sanctum');
        $prior = $guard->hasUser() ? $guard->user()?->currentAccessToken() : null;
        $priorId = $prior instanceof PersonalAccessToken ? $prior->getKey() : null;
        // RequestGuard retains setUser across request rebinding. Recheck real bearer tokens,
        // including a following request without Authorization, in persistent processes.
        if ($request->bearerToken() !== null || (is_numeric($priorId) && (int) $priorId > 0)) {
            $guard->forgetUser();
        }
        $guard->setRequest($request);
        $user = $guard->user();

        return $user && $user->fresh()?->estado ? $user : null;
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function guest(): bool
    {
        return ! $this->check();
    }

    public function hasUser(): bool
    {
        return $this->user() !== null;
    }

    public function id(): mixed
    {
        return $this->user()?->getAuthIdentifier();
    }

    public function validate(array $credentials = []): bool
    {
        return false;
    }

    public function setUser(Authenticatable $user): self
    {
        $this->principal = $user;
        $this->resolved = true;

        return $this;
    }
}
