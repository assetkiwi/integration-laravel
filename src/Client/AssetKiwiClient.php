<?php

declare(strict_types=1);

namespace AssetKiwi\Laravel\Client;

use AssetKiwi\Laravel\OAuth\OAuthManager;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class AssetKiwiClient
{
    private string $baseUrl;

    public function __construct(
        private readonly string $apiUrl,
        private readonly string $apiToken,
        private readonly int $cacheTtl = 3600,
    ) {
        $this->baseUrl = rtrim($apiUrl, '/') . '/api/v1';
    }

    public function assets(array $params = []): array
    {
        return $this->http()->get('/assets', $params)->json() ?? [];
    }

    /**
     * Longest time an asset response is cached: below the API's 60-minute
     * signed-URL lifetime, so cached `url`/`variants` are still valid.
     */
    public const MAX_ASSET_CACHE_SECONDS = 3000;

    /**
     * Widths visitors may request from a public transform URL; fallback for
     * responses without `image_transform.widths`.
     */
    public const DEFAULT_WIDTHS = [160, 320, 640, 960, 1280, 1920, 2560];

    /**
     * Fetches an asset. Successful responses are cached (at most
     * MAX_ASSET_CACHE_SECONDS); errors are never cached.
     */
    public function asset(string $uuid): array
    {
        $key = "assetkiwi.asset.{$uuid}";
        if (($cached = Cache::get($key)) !== null) {
            return $cached;
        }

        $response = $this->http()->get("/assets/{$uuid}");
        $asset = $response->successful() ? ($response->json() ?? []) : [];
        if ($asset !== [] && $this->cacheTtl > 0) {
            Cache::put($key, $asset, min($this->cacheTtl, self::MAX_ASSET_CACHE_SECONDS));
        }

        return $asset;
    }

    /**
     * src, srcset and sizes for a public image, from the asset's stable
     * transform URL (`image_transform`). Widths above the original are left out.
     *
     * @return array{src: string, srcset: string, sizes: string}|null  Null when
     *   the asset has no public transform URL (not public, or not an image).
     */
    public function imageAttributes(array $asset): ?array
    {
        $asset = $asset['data'] ?? $asset;
        $base = $asset['image_transform']['url'] ?? null;
        if (! $base) {
            return null;
        }

        $widths = array_map('intval', $asset['image_transform']['widths'] ?? []) ?: self::DEFAULT_WIDTHS;
        sort($widths);
        $original = (int) ($asset['width'] ?? 0);
        $usable = $original > 0 ? array_values(array_filter($widths, fn (int $w) => $w <= $original)) : $widths;
        $usable = $usable ?: [$widths[0]];

        return [
            // The server snaps any width up to an allowed one.
            'src' => $base . '?w=' . min(1280, end($usable)),
            'srcset' => implode(', ', array_map(fn (int $w) => "{$base}?w={$w} {$w}w", $usable)),
            'sizes' => $original > 0 ? "(max-width: {$original}px) 100vw, {$original}px" : '100vw',
        ];
    }

    public function update(string $uuid, array $data): array
    {
        $result = $this->http()->patch("/assets/{$uuid}", $data)->json() ?? [];
        Cache::forget("assetkiwi.asset.{$uuid}");
        return $result;
    }

    public function delete(string $uuid): bool
    {
        $response = $this->http()->delete("/assets/{$uuid}");
        Cache::forget("assetkiwi.asset.{$uuid}");
        return $response->successful();
    }

    public function download(string $uuid): Response
    {
        return $this->http()->post("/assets/{$uuid}/download");
    }

    /**
     * Upload a local file via POST /api/v1/assets (needs the assets:write ability).
     *
     * Returns the raw response: 201 = created, 409 = duplicate (body has `existing_uuid`).
     * `$params` takes any documented field, e.g. alt_text, tag_names, collection_ids.
     */
    public function upload(string $filePath, ?string $filename = null, array $params = []): Response
    {
        return $this->http()
            ->attach('file', fopen($filePath, 'r'), $filename ?? basename($filePath))
            ->post('/assets', $params);
    }

    public function collections(array $params = []): array
    {
        return $this->http()->get('/collections', $params)->json() ?? [];
    }

    public function tags(array $params = []): array
    {
        return $this->http()->get('/tags', $params)->json() ?? [];
    }

    public function imageStyles(): array
    {
        return $this->http()->get('/image-styles')->json() ?? [];
    }

    public function derivatives(string $uuid): array
    {
        return $this->http()->get("/assets/{$uuid}/derivatives")->json() ?? [];
    }

    public function reportUsage(string $uuid, string $entityType, string $entityId, ?string $url = null, ?string $site = null): array
    {
        return $this->http()->post("/assets/{$uuid}/usage", array_filter([
            'source_site' => $site ?? request()->getHost(),
            'source_entity_type' => $entityType,
            'source_entity_id' => $entityId,
            'source_url' => $url,
        ]))->json() ?? [];
    }

    public function removeUsage(string $uuid, string $entityType, string $entityId, ?string $site = null): bool
    {
        return $this->http()->delete("/assets/{$uuid}/usage", array_filter([
            'source_site' => $site ?? request()->getHost(),
            'source_entity_type' => $entityType,
            'source_entity_id' => $entityId,
        ]))->successful();
    }

    public function resolveVariantUrl(array $asset, string $variant): ?string
    {
        foreach (($asset['data'] ?? $asset)['variants'] ?? [] as $v) {
            if (($v['variant_name'] ?? '') === $variant) {
                return $v['url'] ?? null;
            }
        }
        return null;
    }

    public function getDisplayName(array $asset): string
    {
        return $asset['original_name'] ?? $asset['name'] ?? $asset['filename'] ?? 'Untitled';
    }

    /**
     * convenience method to get the asset's URL (without options: its stable `embed_url` when public, else a signed variant URL),
     * or a DynamicDelivery transform URL (preset Image Style or with `w`/`h`/`format`/`q`/`fit`/`gravity`).
     *
     * With options, `style` will route to transformUrlByStyle() (named image style),
     * otherwise to transformUrl() (on-the-fly resizing). Without any option the original
     * or thumb variant URL of the asset is returned.
     *
     * @return string|void|null
     */
    public function assetUrl(string $uuid, array $options = [])
    {
        if (isset($options['style'])) {
            return $this->transformUrlByStyle($uuid, $options['style']);
        }

        if (count($options) > 0) {
            return $this->transformUrl($uuid, $options);
        }

        $asset = $this->asset($uuid);

        if (!$asset) {
            return;
        }

        // Stable public URL when the asset is public; signed (expiring) otherwise.
        return ($asset['data'] ?? $asset)['embed_url']
            ?? $this->resolveVariantUrl($asset, 'original')
            ?? $this->resolveVariantUrl($asset, 'thumb');
    }

    /**
     * Build a DynamicDelivery transform URL for on-the-fly image processing.
     *
     * The URL is the public /media/serve/{uuid}/transform endpoint; private assets
     * need a session or API token with view access when it is requested.
     * Visitors without a token only get `w`/`h` of public assets (snapped to the
     * allowed widths); the other options need a token.
     */
    public function transformUrl(string $uuid, array $options = []): string
    {
        $params = [];

        if (isset($options['w'])) {
            $params['w'] = (int) $options['w'];
        }
        if (isset($options['h'])) {
            $params['h'] = (int) $options['h'];
        }
        if (isset($options['fit'])) {
            $params['fit'] = $options['fit'];
        }
        if (isset($options['format'])) {
            $params['format'] = $options['format'];
        }
        if (isset($options['q'])) {
            $params['q'] = (int) $options['q'];
        }
        if (isset($options['gravity'])) {
            $params['gravity'] = $options['gravity'];
        }

        $query = $params !== [] ? '?' . http_build_query($params) : '';

        return rtrim($this->apiUrl, '/') . '/media/serve/' . $uuid . '/transform' . $query;
    }

    /**
     * Build a DynamicDelivery transform URL using a named Image Style preset
     * (its numeric `id` from imageStyles()).
     */
    public function transformUrlByStyle(string $uuid, $imageStyleId): string
    {
        return rtrim($this->apiUrl, '/') . '/media/serve/' . $uuid . '/transform/' . $imageStyleId;
    }

    public function forgetCache(string $uuid): void
    {
        Cache::forget("assetkiwi.asset.{$uuid}");
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->resolveToken())
            ->acceptJson()
            ->baseUrl($this->baseUrl);
    }

    /**
     * Resolve the API token to use for requests.
     *
     * When OAuth is enabled and the current user has a per-user token,
     * that token is used. Otherwise falls back to the shared config token.
     */
    protected function resolveToken(): ?string
    {
        if (! config('assetkiwi.oauth.enabled', false)) {
            return $this->apiToken;
        }

        $user = request()->user();
        if ($user) {
            return OAuthManager::getToken($user) ?? $this->apiToken;
        }

        return $this->apiToken;
    }
}
