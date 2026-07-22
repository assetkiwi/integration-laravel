<?php

declare(strict_types=1);

namespace AssetKiwi\Laravel\OAuth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class OAuthManager
{
    /**
     * Build the authorization URL with PKCE challenge.
     */
    public static function getAuthorizationUrl(): string
    {
        $codeVerifier = Str::random(64);
        $codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

        session()->put('assetkiwi.oauth.code_verifier', $codeVerifier);
        session()->put('assetkiwi.oauth.state', $state = Str::random(40));

        // Remember the page the user was on so OAuthController::callback()'s
        // redirect()->intended() can send them back here instead of falling
        // back to '/'. Safe to call from EnsureAssetKiwiToken — it runs as
        // route middleware, so request()->fullUrl() is the real page the
        // user was trying to reach, not an internal API sub-request.
        redirect()->setIntendedUrl(request()->fullUrl());

        $params = [
            'client_id' => config('assetkiwi.oauth.client_id'),
            'redirect_uri' => config('assetkiwi.oauth.redirect_uri'),
            'response_type' => 'code',
            'scope' => config('assetkiwi.oauth.scopes', 'assets:read'),
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];

        return config('assetkiwi.oauth.authorize_url') . '?' . http_build_query($params);
    }

    /**
     * Exchange authorization code for access token.
     */
    public static function exchangeCode(string $code, string $state, string $codeVerifier): ?array
    {
        $response = Http::asForm()->post(config('assetkiwi.oauth.token_url'), [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'client_id' => config('assetkiwi.oauth.client_id'),
            'client_secret' => config('assetkiwi.oauth.client_secret'),
            'code_verifier' => $codeVerifier,
        ]);

        if (! $response->successful()) {
            return null;
        }

        return $response->json();
    }

    /**
     * Store the access token for the given user.
     */
    public static function storeToken($user, array $tokenData): void
    {
        session()->put('assetkiwi.oauth.access_token', $tokenData['access_token']);
        session()->put('assetkiwi.oauth.expires_at', now()->addSeconds($tokenData['expires_in']));

        cache()->put(
            'assetkiwi.oauth.token.' . $user->id,
            [
                'access_token' => $tokenData['access_token'],
                'expires_at' => now()->addSeconds($tokenData['expires_in'])->timestamp,
            ],
            now()->addSeconds($tokenData['expires_in'])
        );
    }

    /**
     * Get the stored access token for a user.
     */
    public static function getToken($user): ?string
    {
        $token = session()->get('assetkiwi.oauth.access_token');
        if ($token) {
            return $token;
        }

        $cached = cache()->get('assetkiwi.oauth.token.' . $user->id);
        if ($cached && ($cached['expires_at'] ?? 0) > now()->timestamp) {
            return $cached['access_token'];
        }

        return null;
    }
}
