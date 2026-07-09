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
ASSETKIWI_CACHE_TTL=3600
```

## Configuration

`config/assetkiwi.php`:

```php
return [
    'api_url'        => env('ASSETKIWI_API_URL', ''),
    'api_token'      => env('ASSETKIWI_API_TOKEN', ''),
    'webhook_secret' => env('ASSETKIWI_WEBHOOK_SECRET', ''),
    'cache_ttl'      => env('ASSETKIWI_CACHE_TTL', 3600),
];
```

`cache_ttl` controls how long individual asset metadata responses are cached (in seconds). Set to `0` to disable caching.

---

## API Client

Use the `AssetKiwi` facade or inject `AssetKiwiClient` directly.

```php
use AssetKiwi\Laravel\Facades\AssetKiwi;

// List assets (supports query params)
$assets = AssetKiwi::assets(['search' => 'logo', 'collection' => 'brand']);

// Get a single asset (cached)
$asset = AssetKiwi::asset('550e8400-e29b-41d4-a716-446655440000');

// Upload a file
$asset = AssetKiwi::upload($request->file('file'), ['alt_text' => 'Product hero']);

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

// Upload
Storage::disk('assetkiwi')->put('ignored-path', $fileContents);
```

> **Note:** The AssetKiwi disk uses asset UUIDs as paths. The `driver`, `move`, and `copy` operations are no-ops since AssetKiwi manages file identity.

---

## Webhook Handling

The package registers `POST /assetkiwi/webhook` automatically. Configure this URL in your AssetKiwi instance's webhook settings.

When a webhook is received, the middleware verifies the HMAC signature against `ASSETKIWI_WEBHOOK_SECRET`, then the controller dispatches a Laravel event.

### Events

```php
use AssetKiwi\Laravel\Events\AssetUpdated;
use AssetKiwi\Laravel\Events\AssetDeleted;

// AssetUpdated has:
//   $event->uuid  — the asset UUID
//   $event->data  — the full webhook payload data

// AssetDeleted has:
//   $event->uuid
//   $event->data
```

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
- Resolves the variant URL (falls back to `original` if the requested variant is not found)
- Uses `alt_text` or `original_name` from the asset as the default `alt` attribute
- Uses variant width/height metadata if `:width` and `:height` are not provided
- Renders nothing if no URL can be resolved

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

## License

MIT
