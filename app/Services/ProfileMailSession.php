<?php

namespace App\Services;

use App\Auth\RemotePrincipal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

final class ProfileMailSession
{
    public static function key(Request $request): string
    {
        $user = $request->user();
        $identity = $user instanceof RemotePrincipal
            ? 'remote:'.hash('sha256', $user->claims['iss']).':'.$user->claims['sub'] : 'local:'.$user->id;

        return 'profile-mail:'.$identity.':'.hash('sha256', $request->bearerToken().'|'.MailAddresses::resolve($user->correo));
    }

    public static function forget(Request $request): void
    {
        if ($request->user()) {
            $reference = self::reference($request);
            $currentKey = Cache::get($reference);
            if (is_string($currentKey)) {
                Cache::forget($currentKey);
            }
            Cache::forget($reference);
            Cache::forget(self::key($request));
            // Clear pre-F3 cache without retaining or decoding its credential.
            Cache::forget('profile-mail:'.$request->user()->id.':'.hash('sha256', $request->bearerToken().'|'.$request->user()->correo));
        }
    }

    public static function remember(Request $request): void
    {
        $reference = self::reference($request);
        $previous = Cache::get($reference);
        $key = self::key($request);
        if (is_string($previous) && $previous !== $key) {
            Cache::forget($previous);
        }
        Cache::put($reference, $key, now()->addMinutes(20));
    }

    private static function reference(Request $request): string
    {
        $user = $request->user();
        $source = $user instanceof RemotePrincipal
            ? 'remote:'.$user->claims['iss'].':'.$user->claims['sub'] : 'local:'.$user->id;

        // Email may change after the JWT is issued. Keep a session reference so
        // logout still clears the current mailbox, without relying on its old claim.
        return 'profile-mail-reference:'.hash('sha256', $source.'|'.$request->bearerToken());
    }
}
