<?php

declare(strict_types=1);

namespace AssetKiwi\Laravel\Client;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\UploadedFile;

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

    public function upload(UploadedFile|string $file, array $metadata = []): array
    {
        $request = $this->http();

        if ($file instanceof UploadedFile) {
            $request = $request->attach('file', $file->get(), $file->getClientOriginalName());
        } else {
            $request = $request->attach('file', fopen($file, 'r'), basename($file));
        }

        foreach ($metadata as $key => $value) {
            $request = $request->attach($key, $value);
        }

        return $request->post('/assets')->json() ?? [];
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

    public function forgetCache(string $uuid): void
    {
        Cache::forget("assetkiwi.asset.{$uuid}");
    }

    private function http(): PendingRequest
    {
        return Http::withToken($this->apiToken)
            ->acceptJson()
            ->baseUrl($this->baseUrl);
    }
}
