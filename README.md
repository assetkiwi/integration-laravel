# assetkiwi/laravel

Laravel integration for [AssetKiwi](https://assetkiwi.com). Use AssetKiwi as your asset backend — API client, filesystem disk, webhook handling, usage tracking, and Blade components.

## Requirements

- PHP 8.1+
- Laravel 10, 11, or 12

## Installation

```bash
composer require assetkiwi/laravel
```

Publish the config file:

```bash
php artisan vendor:publish --tag=assetkiwi-config
```

Add your credentials to `.env`:

```env
ASSETKIWI_API_URL=https://your-assetkiwi-instance.com
ASSETKIWI_API_TOKEN=your-api-token
ASSETKIWI_WEBHOOK_SECRET=your-webhook-secret
ASSETKIWI_CACHE_TTL=3000
```

## Configuration

`config/assetkiwi.php`:

```php
return [
    'api_url'        => env('ASSETKIWI_API_URL', ''),
    'api_token'      => env('ASSETKIWI_API_TOKEN', ''),
    'webhook_secret' => env('ASSETKIWI_WEBHOOK_SECRET', ''),
    'cache_ttl'      => env('ASSETKIWI_CACHE_TTL', 3000),
];
```

`cache_ttl` controls how long individual asset metadata responses are cached (in seconds). Set to `0` to disable caching. It is capped at 3000 seconds, because the signed `url` and `variants` URLs in a response expire after 60 minutes. Failed lookups are never cached.

### OAuth2 (Per-User Authentication)

By default, the integration uses a single shared API token (`ASSETKIWI_API_TOKEN`) for all users. When OAuth is enabled, each authenticated user obtains their own bearer token via the Authorization Code grant with PKCE, giving each user their own identity in the DAM.

#### Enabling OAuth

Set `ASSETKIWI_OAUTH_ENABLED=true` and configure the OAuth client credentials:

```env
ASSETKIWI_OAUTH_ENABLED=true
ASSETKIWI_OAUTH_CLIENT_ID=your-oauth-client-id
ASSETKIWI_OAUTH_CLIENT_SECRET=your-oauth-client-secret
ASSETKIWI_OAUTH_REDIRECT_URI=https://your-app.com/assetkiwi/oauth/callback
# Optional, defaults shown:
ASSETKIWI_OAUTH_SCOPES="assets:read collections:read tags:read"
```

| Variable | Default | Description |
|---|---|---|
| `ASSETKIWI_OAUTH_ENABLED` | `false` | Enable per-user OAuth authentication |
| `ASSETKIWI_OAUTH_CLIENT_ID` | — | OAuth2 client ID from the DAM app |
| `ASSETKIWI_OAUTH_CLIENT_SECRET` | — | OAuth2 client secret from the DAM app |
| `ASSETKIWI_OAUTH_AUTHORIZE_URL` | `<api_url>/oauth/authorize` | Optional override, only for a different auth server |
| `ASSETKIWI_OAUTH_TOKEN_URL` | `<api_url>/oauth/token` | Optional override, only for a different auth server |
| `ASSETKIWI_OAUTH_REDIRECT_URI` | — | Callback URL in this Laravel app (must match the registered redirect URI) |
| `ASSETKIWI_OAUTH_SCOPES` | `assets:read collections:read tags:read` | Space-separated list of requested scopes |

#### Setting Up the OAuth Client in the DAM App

1. Create an OAuth2 client in your asset.kiwi instance
2. Set the redirect URI to match `ASSETKIWI_OAUTH_REDIRECT_URI`
3. Copy the client ID and secret to your `.env` file

The package automatically registers the OAuth callback route when OAuth is enabled. The route path is derived from the `ASSETKIWI_OAUTH_REDIRECT_URI` path portion (e.g. `/assetkiwi/oauth/callback`).

#### Authorization Flow

1. **User visits a protected page** — If using the `EnsureAssetKiwiToken` middleware, unauthenticated users are redirected to the DAM authorization screen
2. **User approves** — The DAM redirects back to your callback URL with an authorization code
3. **Code exchanged** — The callback controller exchanges the code for a bearer token (with PKCE verification)
4. **Token stored** — The token is stored in the user's session and cache for persistence across requests

#### Protecting Routes

Apply the `EnsureAssetKiwiToken` middleware to routes that require a DAM token:

```php
// In your routes file or controller
use AssetKiwi\Laravel\Http\Middleware\EnsureAssetKiwiToken;

Route::middleware(['auth', EnsureAssetKiwiToken::class])->group(function () {
    // Routes that use AssetKiwi features...
});
```

Register the middleware in `app/Http/Kernel.php` (Laravel 10) or `bootstrap/app.php` (Laravel 11+):

```php
// Laravel 10 — app/Http/Kernel.php
protected $routeMiddleware = [
    // ...
    'assetkiwi.token' => \AssetKiwi\Laravel\Http\Middleware\EnsureAssetKiwiToken::class,
];

// Laravel 11+ — bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'assetkiwi.token' => \AssetKiwi\Laravel\Http\Middleware\EnsureAssetKiwiToken::class,
    ]);
})
```

#### Mode Toggle

When `ASSETKIWI_OAUTH_ENABLED=false` (the default), the integration operates in **shared token mode** — all API requests use `ASSETKIWI_API_TOKEN` regardless of the authenticated user. This mode requires zero OAuth configuration.

When `ASSETKIWI_OAUTH_ENABLED=true`, the integration operates in **per-user OAuth mode** — the `AssetKiwiClient` checks for a stored OAuth token for the current user and falls back to `ASSETKIWI_API_TOKEN` if no per-user token exists yet.

---

## API Client

Use the `AssetKiwi` facade or inject `AssetKiwiClient` directly.

```php
use AssetKiwi\Laravel\Facades\AssetKiwi;

// List assets (supports query params)
$assets = AssetKiwi::assets(['search' => 'logo', 'collection' => 'brand']);

// Get a single asset (cached)
$asset = AssetKiwi::asset('550e8400-e29b-41d4-a716-446655440000');

// Update metadata
$asset = AssetKiwi::update($uuid, ['alt_text' => 'Updated alt text']);

// Delete
AssetKiwi::delete($uuid);

// Download (returns Illuminate\Http\Client\Response)
$response = AssetKiwi::download($uuid);
Storage::put('local-copy.jpg', $response->body());

// Collections, tags, image styles
$collections = AssetKiwi::collections();
$tags        = AssetKiwi::tags();
$styles      = AssetKiwi::imageStyles();

// Derivatives for an asset
$derivatives = AssetKiwi::derivatives($uuid);

// Resolve a variant URL
$url = AssetKiwi::resolveVariantUrl($asset, 'thumb');

// Get a human-readable display name
$name = AssetKiwi::getDisplayName($asset); // "photo.jpg"

// Usage tracking (manual)
AssetKiwi::reportUsage($uuid, 'posts', (string) $post->id, $post->url());
AssetKiwi::removeUsage($uuid, 'posts', (string) $post->id);

// Invalidate cached metadata
AssetKiwi::forgetCache($uuid);

// DynamicDelivery: get a transform URL for on-the-fly image processing
AssetKiwi::transformUrl($uuid, ['w' => 800, 'format' => 'webp']);

// DynamicDelivery: get a URL using a named Image Style preset
AssetKiwi::transformUrlByStyle($uuid, 'hero-banner');

// Convenience: asset URL (variant fallback) or transform URL with options
$url = AssetKiwi::assetUrl($uuid);                       // original/thumb variant
$url = AssetKiwi::assetUrl($uuid, ['w' => 400, 'q' => 80]); // DynamicDelivery transform
$url = AssetKiwi::assetUrl($uuid, ['style' => 'hero']);      // named image style
```

---

## Filesystem Disk

Register the `assetkiwi` disk in `config/filesystems.php`:

```php
'disks' => [
    'assetkiwi' => [
        'driver' => 'assetkiwi',
    ],
],
```

Then use it like any Laravel filesystem disk:

```php
use Illuminate\Support\Facades\Storage;

// Get the URL for an asset by UUID
$url = Storage::disk('assetkiwi')->url('550e8400-e29b-41d4-a716-446655440000');

// Check existence
$exists = Storage::disk('assetkiwi')->exists($uuid);

// Download content
$content = Storage::disk('assetkiwi')->get($uuid);

// Upload (POST /api/v1/assets, needs the assets:write ability).
// The path only supplies the file name; asset.kiwi assigns the UUID.
// A duplicate file (HTTP 409) counts as success; other failures throw UnableToWriteFile.
Storage::disk('assetkiwi')->put('photo.jpg', $fileContents);
```

> **Note:** The disk uses asset UUIDs as paths for reading, deleting and metadata. `move` and `copy` are not supported and throw. Directory operations and visibility are no-ops. To get the new asset's UUID, call `AssetKiwi::upload($path, $filename, $params)` instead and read the response.

---

## Webhook Handling

The package registers `POST /assetkiwi/webhook` automatically. Configure this URL in your AssetKiwi instance's webhook settings.

When a webhook is received, the middleware verifies the HMAC signature against `ASSETKIWI_WEBHOOK_SECRET`, then the controller dispatches a Laravel event.

### Events

```php
use AssetKiwi\Laravel\Events\AssetUpdated;
use AssetKiwi\Laravel\Events\AssetDeleted;

// AssetUpdated has:
//   $event->uuid  — the asset UUID (from data.uuid)
//   $event->data  — the full webhook payload data

// AssetDeleted (asset moved to trash) and AssetPermanentlyDeleted
// (asset.permanently_deleted) have the same two properties.
```

Events are matched by `data.uuid`. A payload that only carries the integer `id` is acknowledged with `200` but no event is dispatched.

### Listening

In `EventServiceProvider` (or `AppServiceProvider` in Laravel 11+):

```php
use AssetKiwi\Laravel\Events\AssetUpdated;
use App\Listeners\SyncAssetMetadata;

protected $listen = [
    AssetUpdated::class => [
        SyncAssetMetadata::class,
    ],
];
```

Or with a closure:

```php
Event::listen(AssetUpdated::class, function (AssetUpdated $event) {
    // Invalidate cached metadata when the DAM asset changes
    AssetKiwi::forgetCache($event->uuid);
});
```

---

## Usage Tracking Trait

Add `TracksAssetKiwiUsage` to any Eloquent model. Declare the `$assetKiwiFields` array with the column names that hold asset UUIDs.

```php
use AssetKiwi\Laravel\Traits\TracksAssetKiwiUsage;

class Post extends Model
{
    use TracksAssetKiwiUsage;

    protected array $assetKiwiFields = ['hero_image_uuid', 'thumbnail_uuid'];
}
```

When a `Post` is saved, usage is automatically reported to AssetKiwi for each non-null UUID field. When the model is deleted, usage is removed.

If your model has a `url()` method, it will be included as `source_url` in the usage report.

---

## Blade Component

```blade
{{-- Basic --}}
<x-assetkiwi-image uuid="550e8400-e29b-41d4-a716-446655440000" />

{{-- Specific variant --}}
<x-assetkiwi-image uuid="{{ $post->hero_image_uuid }}" variant="large" />

{{-- Custom alt, class, dimensions --}}
<x-assetkiwi-image
    uuid="{{ $asset['uuid'] }}"
    variant="thumb"
    alt="Product photo"
    class="rounded shadow"
    :width="400"
    :height="300"
/>
```

The component:
- Fetches asset metadata (uses the configured cache)
- For **public images**, renders stable URLs that never expire: `src` and a `srcset` built from `image_transform` (`{api_url}/media/serve/{uuid}/transform?w=N` over the allowed widths, up to the original width), with `sizes`. `variant` is ignored.
- For other assets, resolves the signed variant URL (falls back to `original` if the requested variant is not found). Signed URLs expire after about an hour, so don't put this output in a page or response cache that lives longer; make assets public for public pages.
- Uses `alt_text` or `original_name` from the asset as the default `alt` attribute
- Uses the image's (or variant's) width/height metadata if `:width` and `:height` are not provided
- Renders nothing if no URL can be resolved

For your own markup, `AssetKiwi::imageAttributes($asset)` returns `['src' => ..., 'srcset' => ..., 'sizes' => ...]` for a public image, or `null`.

---

## DynamicDelivery (On-the-Fly Image Processing)

Use the `@asset` Blade directive to generate DynamicDelivery transform URLs.

```blade
{{-- Basic: the stable embed_url of a public asset (else a signed original/thumb variant URL) --}}
<img src="@asset('550e8400-e29b-41d4-a716-446655440000')">

{{-- On-the-fly resize via DynamicDelivery --}}
<img src="@asset('550e8400-e29b-41d4-a716-446655440000', ['w' => 800, 'format' => 'webp'])">

{{-- Named image style (its numeric id) --}}
<img src="@asset('550e8400-e29b-41d4-a716-446655440000', ['style' => 12])">
```

**Programmatic API:**

```php
// On-the-fly transform with width, height, format, quality, fit, gravity
$url = AssetKiwi::transformUrl($uuid, ['w' => 800, 'format' => 'webp', 'q' => 85]);

// Named image style preset (numeric id from AssetKiwi::imageStyles())
$url = AssetKiwi::transformUrlByStyle($uuid, 12);

// Convenience: stable embed_url / variant URL, or transform URL
$url = AssetKiwi::assetUrl($uuid);                        // embed_url, else signed original/thumb variant
$url = AssetKiwi::assetUrl($uuid, ['w' => 400]);          // transform
$url = AssetKiwi::assetUrl($uuid, ['style' => 12]);       // style preset
```

**Global helper:**

```php
$url = assetkiwi_asset('UUID');
$url = assetkiwi_asset('UUID', ['w' => 800, 'format' => 'webp']);
```

Transform URLs (`{api_url}/media/serve/{uuid}/transform`) are not signed and don't expire. Visitors without an API token can load them only for public assets, and get only `w`/`h` (snapped to the allowed widths, listed in the asset's `image_transform.widths`); `fit`, `format`, `q` and `gravity` need a token.

---

## Artisan Commands

### `assetkiwi:cache:clear`

Clear all locally cached asset.kiwi API responses. Uses the cache store configured in `assetkiwi.cache_store`, or your application's default store if not set.

```bash
php artisan assetkiwi:cache:clear
```

### `assetkiwi:sync-usage`

Re-sync usage records for all Eloquent models using the `TracksAssetKiwiUsage` trait. Useful after a cache clear or if usage records get out of sync. Scans directories specified in `assetkiwi.track_models_in` (defaults to `app/Models`).

```bash
php artisan assetkiwi:sync-usage
php artisan assetkiwi:sync-usage --model="App\\Models\\Post"
php artisan assetkiwi:sync-usage --dry-run
```

The command iterates each model instance, reads its `$assetKiwiFields` array, and reports usage to asset.kiwi for every non-null UUID field — exactly how the trait's `syncAssetKiwiUsage()` method works during normal `saved` events.

### `assetkiwi:stats`

Show connection status and integration statistics for this application.

```bash
php artisan assetkiwi:stats
php artisan assetkiwi:stats --uuid=<asset-uuid>
```

With `--uuid`, shows detailed metadata about a specific asset (display name, MIME type, file size, variant count).

---

**Note:** The legacy command names `assetkiwi:sync` and `assetkiwi:migrate` referenced in older documentation have been replaced. Media upload and migration should now be performed through the asset.kiwi application directly. Use `assetkiwi:sync-usage` to sync usage tracking records instead.

---

## Testing

The package uses [Orchestra Testbench](https://github.com/orchestral/testbench).

```bash
composer install
./vendor/bin/phpunit
```

In your own application tests, use `Http::fake()` as usual — the client uses Laravel's HTTP client internally.

```php
use Illuminate\Support\Facades\Http;

Http::fake([
    'your-assetkiwi-instance.com/api/v1/assets/*' => Http::response([
        'uuid' => 'abc-123',
        'original_name' => 'photo.jpg',
        'variants' => [
            ['variant_name' => 'thumb', 'url' => 'https://cdn.example.com/thumb.jpg'],
        ],
    ]),
]);

$asset = AssetKiwi::asset('abc-123');
$this->assertSame('photo.jpg', AssetKiwi::getDisplayName($asset));
```

---

## Changelog

### Unreleased

- `<x-assetkiwi-image>` renders public images from stable transform URLs with a `srcset`; new `imageAttributes()` helper.
- `assetUrl()` without options returns the stable `embed_url` for public assets.
- Asset responses are cached for at most 3000 seconds (default `cache_ttl` 3000), and failed lookups are no longer cached.
- Variant lookups accept responses wrapped in `data`.

## License

MIT
