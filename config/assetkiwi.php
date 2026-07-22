<?php

return [
    'api_url' => env('ASSETKIWI_API_URL', ''),
    'api_token' => env('ASSETKIWI_API_TOKEN', ''),
    'webhook_secret' => env('ASSETKIWI_WEBHOOK_SECRET', ''),
    'cache_ttl' => env('ASSETKIWI_CACHE_TTL', 3600),

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

        'authorize_url' => env('ASSETKIWI_OAUTH_AUTHORIZE_URL'),
        'token_url' => env('ASSETKIWI_OAUTH_TOKEN_URL'),

        // Read-only by default (least privilege). AssetKiwiClient::collections()
        // and ::tags() need collections:read/tags:read — add assets:write only
        // if your app actually uploads/modifies assets on the user's behalf.
        'scopes' => env('ASSETKIWI_OAUTH_SCOPES', 'assets:read collections:read tags:read'),

        'redirect_uri' => env('ASSETKIWI_OAUTH_REDIRECT_URI'),
    ],
];
