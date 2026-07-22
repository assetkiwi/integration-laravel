<?php

declare(strict_types=1);

namespace AssetKiwi\Laravel\Http\Middleware;

use AssetKiwi\Laravel\OAuth\OAuthManager;
use Closure;
use Illuminate\Http\Request;

class EnsureAssetKiwiToken
{
    public function handle(Request $request, Closure $next)
    {
        if (! config('assetkiwi.oauth.enabled', false)) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $token = OAuthManager::getToken($user);

        if (! $token) {
            if ($request->expectsJson()) {
                return response()->json([
                    'error' => 'assetkiwi_oauth_required',
                    'authorize_url' => OAuthManager::getAuthorizationUrl(),
                ], 401);
            }

            return redirect()->away(OAuthManager::getAuthorizationUrl());
        }

        return $next($request);
    }
}
