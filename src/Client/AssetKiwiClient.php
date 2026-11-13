<?php

declare(strict_types=1);

namespace AssetKiwi\Laravel\Client;

use AssetKiwi\Laravel\OAuth\OAuthManager;
use Composer\InstalledVersions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class AssetKiwiClient
{
    private string $baseUrl;

    public function __construct(
        private readonly string $apiUrl,
        #[\SensitiveParameter] private readonly string $apiToken,
        private readonly int $cacheTtl = 3600,
        #[\SensitiveParameter] private readonly ?string $signingKey = null,
    ) {
        $this->baseUrl = rtrim($apiUrl, '/') . '/api/v1';
    }

    public function __debugInfo(): array
    {
        return [
            'apiUrl' => $this->apiUrl,
            'apiToken' => '***',
            'signingKey' => $this->signingKey ? '***' : null,
            'cacheTtl' => $this->cacheTtl,
        ];
    }

    /**
     * A GET that hands back the response whatever its status (null body or not), so a caller can tell an outage (a
     * ConnectionException, 5xx, 429) from "not allowed" (401/403) and from an empty answer. Connection errors and 5xx/429
     * are retried a couple of times by http().
     *
     * @throws ConnectionException when asset.kiwi cannot be reached
     */
    public function get(string $path, array $params = []): Response
    {
        return $this->http()->get($path, $params);
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
    public const DEFAULT_WIDTHS = [160, 320, 480, 640, 768, 960, 1080, 1280, 1920, 2560];

    /** Crop ratios visitors may request; fallback for responses without `image_transform.ratios`. */
    public const DEFAULT_RATIOS = ['1:1', '4:5', '3:4', '2:3', '9:16', '4:3', '3:2', '16:9', '21:9'];

    /** Formats visitors may request; fallback for responses without `image_transform.formats`. */
    public const DEFAULT_FORMATS = ['webp', 'avif', 'jpg'];

    /**
     * Fetches an asset. Successful responses are cached (at most
     * MAX_ASSET_CACHE_SECONDS); errors are never cached.
     */
    public function asset(string $uuid): array
    {
        $key = $this->assetCacheKey($uuid);
        if (($cached = Cache::get($key)) !== null) {
            return $cached;
        }

        $response = $this->http()->get("/assets/{$uuid}");
        $asset = $response->successful() ? ($response->json() ?? []) : [];
        if ($asset !== [] && $this->cacheTtl > 0) {
            // A per-user copy (OAuth) is what that user may see: keep it short, it is not reachable from forgetCache() for other users.
            Cache::put($key, $asset, min($this->cacheTtl, $key === "assetkiwi.asset.{$uuid}" ? self::MAX_ASSET_CACHE_SECONDS : 300));
        }

        return $asset;
    }

    /**
     * src, srcset and sizes for a public image, from the asset's stable
     * transform URL (`image_transform`). Widths above the original are left out.
     *
     * Options: `ratio` ("16:9", cropped; adds width/height to the result),
     * `format`, `q` and `sign`. A ratio outside the advertised `ratios` is snapped
     * here to the nearest advertised one (log scale, like the server), unless
     * `'sign' => true` and a signing key is set: then every URL is signed with the
     * exact ratio. Never sign request-derived values: each is a new stored variant.
     * @throws \InvalidArgumentException for a non-finite ratio or one outside 0.05..20.
     *
     * @return array{src: string, srcset: string, sizes: string, width?: int, height?: int}|null
     *   Null when the asset has no public transform URL (not public, or not an image).
     */
    public function imageAttributes(array $asset, array $options = []): ?array
    {
        $asset = $asset['data'] ?? $asset;
        $base = $asset['image_transform']['url'] ?? null;
        if (! $base) {
            return null;
        }

        $widths = array_map('intval', $asset['image_transform']['widths'] ?? []) ?: self::DEFAULT_WIDTHS;
        sort($widths);
        $original = (int) ($asset['width'] ?? 0);
        $originalHeight = (int) ($asset['height'] ?? 0);
        $extra = array_filter(['q' => $options['q'] ?? null, 'format' => $options['format'] ?? null], fn ($v) => $v !== null && $v !== '');

        $ratio = $options['ratio'] ?? null;
        [$rw, $rh] = $ratio ? array_map('floatval', explode(':', (string) $ratio) + [1 => 0]) : [0, 0];
        if ($ratio && ($rw <= 0 || $rh <= 0)) {
            $ratio = null;
        }
        $factor = $ratio ? $rw / $rh : 0;
        if ($ratio && (! is_finite($factor) || $factor < 0.05 || $factor > 20)) {
            throw new \InvalidArgumentException('ratio must be between 0.05 and 20.');
        }
        $m = [];
        $sign = $ratio && ! empty($options['sign']) && $this->signingKey
            && preg_match('#/media/serve/([^/]+)/transform$#', $base, $m);
        if ($ratio && ! $sign) {
            $listed = $asset['image_transform']['ratios'] ?? self::DEFAULT_RATIOS;
            if (! in_array((string) $ratio, $listed, true)) {
                $best = null;
                foreach ($listed as $r) {
                    [$a, $b] = array_map('floatval', explode(':', $r) + [1 => 0]);
                    if ($a > 0 && $b > 0 && ($best === null || abs(log($a / $b / $factor)) < abs(log($best[0] / $factor)))) {
                        $best = [$a / $b];
                    }
                }
                if ($best) {
                    $factor = $best[0];
                }
            }
        }

        $caps = array_filter([$original, $ratio && $originalHeight > 0 ? (int) floor($originalHeight * $factor) : 0]);
        $cap = $caps ? min($caps) : 0;
        $usable = $cap > 0 ? array_values(array_filter($widths, fn (int $w) => $w <= $cap)) : $widths;
        $usable = $usable ?: [$widths[0]];

        $url = function (int $w) use ($base, $ratio, $factor, $extra, $sign, $m) {
            $params = ['w' => $w] + ($ratio ? ['h' => (int) round($w / $factor), 'fit' => 'crop'] : []) + $extra;
            if ($sign) {
                return $this->signedTransformUrl($m[1], $params, null, $base);
            }
            // Canonical order (w, h, fit, q, format) avoids a 301 from the server.
            return $base . '?' . http_build_query($params);
        };

        // src: the largest step up to 1280 (the server snaps plain widths up anyway).
        $srcW = $ratio
            ? max($usable[0], ...array_filter($usable, fn (int $w) => $w <= 1280))
            : min(1280, end($usable));

        $attrs = [
            'src' => $url($srcW),
            'srcset' => implode(', ', array_map(fn (int $w) => $url($w) . " {$w}w", $usable)),
            'sizes' => $original > 0 ? "(max-width: {$original}px) 100vw, {$original}px" : '100vw',
        ];
        if ($ratio) {
            $attrs['width'] = $srcW;
            $attrs['height'] = (int) round($srcW / $factor);
        }

        return $attrs;
    }

    public function update(string $uuid, array $data): array
    {
        $result = $this->http()->patch("/assets/{$uuid}", $data)->json() ?? [];
        $this->forgetCache($uuid);
        return $result;
    }

    public function delete(string $uuid): bool
    {
        $response = $this->http()->delete("/assets/{$uuid}");
        $this->forgetCache($uuid);
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
            ->timeout(120) // a file takes longer than an API answer
            ->attach('file', fopen($filePath, 'r'), $filename ?? basename($filePath))
            ->post('/assets', $params);
    }

    /**
     * Upload and return the asset's uuid, treating a duplicate as success: the existing asset is returned.
     *
     * @return array{uuid: string, duplicate: bool}
     * @throws \RuntimeException with the API's message when asset.kiwi refuses (403: the token lacks assets:write).
     */
    public function uploadAsset(string $filePath, ?string $filename = null, array $params = []): array
    {
        $res = $this->upload($filePath, $filename, $params);
        if ($res->successful() && $res->json('data.uuid')) {
            return ['uuid' => $res->json('data.uuid'), 'duplicate' => false];
        }
        if ($res->status() === 409 && $res->json('existing_uuid')) {
            return ['uuid' => $res->json('existing_uuid'), 'duplicate' => true];
        }

        throw new \RuntimeException((string) ($res->json('message') ?: 'asset.kiwi refused the upload.'), $res->status());
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

    /** Where the asset is used, across every site that reports to asset.kiwi: rows of source_site, source_entity_type, source_entity_id, source_url. */
    public function usages(string $uuid): array
    {
        return $this->http()->get("/assets/{$uuid}/usage")->json('data') ?? [];
    }

    /** Where the picture is cropped around, as percentages from the top left (needs assets:write). */
    public function focalPoint(string $uuid, float $x, float $y): bool
    {
        $ok = $this->http()->post("/assets/{$uuid}/focal-point", ['focal_x' => $x, 'focal_y' => $y])->successful();
        $this->forgetCache($uuid);

        return $ok;
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
     * Visitors without a token get public assets with `w`/`h`/`fit=crop`/`format`/`q`
     * snapped to the workspace steps, ratios and formats (a 301 to the canonical URL;
     * emit canonical params and do not send `gravity`). Use signedTransformUrl() for exact values.
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
        if (isset($options['q'])) {
            $params['q'] = (int) $options['q'];
        }
        if (isset($options['format'])) {
            $params['format'] = $options['format'];
        }
        if (isset($options['gravity'])) {
            $params['gravity'] = $options['gravity'];
        }
        if (isset($options['dpr']) && is_numeric($options['dpr'])) {
            $params['dpr'] = $options['dpr'] + 0;
        }

        $query = $params !== [] ? '?' . http_build_query($params) : '';

        return rtrim($this->apiUrl, '/') . '/media/serve/' . $uuid . '/transform' . $query;
    }

    /**
     * Signed transform URL: the server skips all snapping for this exact URL.
     * Server-side only; the signing key is a secret. `$expiresAt` is a unix timestamp.
     * Allowed params: w, h, fit, q, format, gravity, dpr.
     *
     * @throws \LogicException when no signing key is configured.
     */
    public function signedTransformUrl(string $uuid, array $params, ?int $expiresAt = null, ?string $transformBase = null): string
    {
        if (! $this->signingKey) {
            throw new \LogicException('Set ASSETKIWI_SIGNING_KEY to build signed transform URLs.');
        }
        if (! preg_match('/^[0-9a-f-]{36}$/i', $uuid)) {
            throw new \InvalidArgumentException('Invalid uuid.');
        }
        $base = rtrim($this->apiUrl, '/') . '/media/serve/' . $uuid . '/transform';
        if ($transformBase !== null && $transformBase !== $base) {
            $given = parse_url($transformBase);
            $own = parse_url($base);
            if (($given['scheme'] ?? null) !== $own['scheme'] || ($given['host'] ?? null) !== $own['host']
                || ($given['port'] ?? null) !== ($own['port'] ?? null)
                || ($given['path'] ?? null) !== $own['path'] || isset($given['query'])) {
                throw new \InvalidArgumentException('transformBase does not match the configured api_url and uuid.');
            }
        }

        $params = array_intersect_key($params, array_flip(['w', 'h', 'fit', 'q', 'format', 'gravity', 'dpr']));
        if (isset($params['dpr']) && ! is_numeric($params['dpr'])) {
            unset($params['dpr']);
        }
        $params['exp'] = $expiresAt;
        $params = array_filter($params, fn ($v) => $v !== null && $v !== '');
        ksort($params, SORT_STRING);
        $query = http_build_query($params);
        $signature = hash_hmac('sha256', "{$uuid}?{$query}", $this->signingKey);

        return "{$base}?{$query}&s={$signature}";
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
        Cache::forget($this->assetCacheKey($uuid));
    }

    /** The shared key, or one per signed-in user while per-user OAuth is on (what a user may see is not what the shared token may). */
    private function assetCacheKey(string $uuid): string
    {
        $user = config('assetkiwi.oauth.enabled', false) ? request()->user() : null;

        return "assetkiwi.asset.{$uuid}".($user && OAuthManager::getToken($user) ? ".u{$user->id}" : '');
    }

    private function http(): PendingRequest
    {
        // Tells asset.kiwi which integration calls (shown with the token there).
        $version = InstalledVersions::isInstalled('assetkiwi/laravel') ? (InstalledVersions::getPrettyVersion('assetkiwi/laravel') ?? 'dev') : 'dev';
        $host = (string) parse_url((string) config('app.url'), PHP_URL_HOST);

        return Http::withToken($this->resolveToken())
            ->acceptJson()
            ->withUserAgent('AssetKiwi-Laravel/'.$version.' (Laravel '.app()->version().($host !== '' ? '; '.$host : '').')')
            ->withHeaders(['X-AssetKiwi-Client' => 'laravel/'.$version] + ($host !== '' ? ['X-AssetKiwi-Client-Site' => $host] : []))
            ->baseUrl($this->baseUrl)
            // A slow or down asset.kiwi must not hold a page request for minutes: fail fast, retry what may pass (connection errors, 5xx,
            // 429 after the Retry-After it names), and hand a failed response back instead of throwing it.
            ->timeout(8)->connectTimeout(3)
            ->retry(
                2,
                fn (int $attempt, \Throwable $e) => $e instanceof RequestException && is_numeric($wait = $e->response->header('Retry-After')) ? min(2000, max(0, (int) $wait) * 1000) : 200,
                fn (\Throwable $e) => $e instanceof ConnectionException || ($e instanceof RequestException && ($e->response->serverError() || $e->response->status() === 429)),
                throw: false,
            );
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
