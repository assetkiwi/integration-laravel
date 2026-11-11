<?php

return [
    'api_url' => env('ASSETKIWI_API_URL', ''),
    'api_token' => env('ASSETKIWI_API_TOKEN', ''),
    // Secret: server-side only. Enables signedTransformUrl() and exact off-list crop ratios.
    'signing_key' => env('ASSETKIWI_SIGNING_KEY', ''),
    'webhook_secret' => env('ASSETKIWI_WEBHOOK_SECRET', ''),
    // Seconds; capped at 3000 so cached signed URLs (valid 60 min) never expire.
    'cache_ttl' => env('ASSETKIWI_CACHE_TTL', 3000),

    /*
    |--------------------------------------------------------------------------
    | Cache Store
    |--------------------------------------------------------------------------
    |
    | The cache store to use for asset.kiwi API response caching. Set to null
    | to use your application's default cache driver.
    |
    */
    'cache_store' => env('ASSETKIWI_CACHE_STORE', null),

    /*
    |--------------------------------------------------------------------------
    | Model Scan Paths
    |--------------------------------------------------------------------------
    |
    | Directories to scan when discovering Eloquent models that use the
    | TracksAssetKiwiUsage trait. Used by the assetkiwi:sync-usage command.
    |
    */
    'track_models_in' => [app_path('Models')],

    /*
    |--------------------------------------------------------------------------
    | OAuth2
    |--------------------------------------------------------------------------
    |
    | When enabled, each authenticated user authenticates with asset.kiwi via
    | OAuth2 (Authorization Code grant with PKCE) to obtain a per-user bearer
    | token. When disabled, the shared api_token above is used for all users.
    |
    */
    'oauth' => [
        'enabled' => env('ASSETKIWI_OAUTH_ENABLED', false),

        'client_id' => env('ASSETKIWI_OAUTH_CLIENT_ID'),
        'client_secret' => env('ASSETKIWI_OAUTH_CLIENT_SECRET'),

        // Optional — asset.kiwi's OAuth routes are fixed (/oauth/authorize,
        // /oauth/token), so OAuthManager derives these from api_url above
        // when left unset. Only set these if you're authenticating against
        // a different auth server than api_url.
        'authorize_url' => env('ASSETKIWI_OAUTH_AUTHORIZE_URL'),
        'token_url' => env('ASSETKIWI_OAUTH_TOKEN_URL'),

        // Read-only by default (least privilege). AssetKiwiClient::collections()
        // and ::tags() need collections:read/tags:read — add assets:write only
        // if your app actually uploads/modifies assets on the user's behalf.
        'scopes' => env('ASSETKIWI_OAUTH_SCOPES', 'assets:read collections:read tags:read'),

        'redirect_uri' => env('ASSETKIWI_OAUTH_REDIRECT_URI'),
    ],
];
