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

    public function asset(string $uuid): array
    {
        return Cache::remember(
            "assetkiwi.asset.{$uuid}",
            $this->cacheTtl,
            fn () => $this->http()->get("/assets/{$uuid}")->json() ?? [],
        );
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
        foreach ($asset['variants'] ?? [] as $v) {
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
     * convenience method to get the variant image URL from an asset (without options),
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

        return $this->resolveVariantUrl($asset, 'original')
            ?? $this->resolveVariantUrl($asset, 'thumb');
    }

    /**
     * Build a DynamicDelivery transform URL for on-the-fly image processing.
     *
     * The returned URL points to the API endpoint which 302-redirects to the
     * signed imgproxy URL.
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

        return rtrim($this->apiUrl, '/') . '/api/v1/media/' . $uuid . '/transform' . $query;
    }

    /**
     * Build a DynamicDelivery transform URL using a named Image Style preset.
     */
    public function transformUrlByStyle(string $uuid, $imageStyleId): string
    {
        return rtrim($this->apiUrl, '/') . '/api/v1/media/' . $uuid . '/transform/' . $imageStyleId;
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
