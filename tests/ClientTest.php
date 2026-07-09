<?php

declare(strict_types=1);

namespace AssetKiwi\Laravel\Tests;

use AssetKiwi\Laravel\Client\AssetKiwiClient;
use AssetKiwi\Laravel\AssetKiwiServiceProvider;
use Orchestra\Testbench\TestCase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;

class ClientTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AssetKiwiServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('assetkiwi.api_url', 'https://dam.example.com');
        $app['config']->set('assetkiwi.api_token', 'test-token-123');
        $app['config']->set('assetkiwi.cache_ttl', 0);
    }

    private function client(): AssetKiwiClient
    {
        return $this->app->make(AssetKiwiClient::class);
    }

    public function test_assets_returns_array(): void
    {
        Http::fake([
            'dam.example.com/api/v1/assets*' => Http::response([
                'data' => [
                    ['uuid' => 'abc-123', 'name' => 'photo.jpg'],
                    ['uuid' => 'def-456', 'name' => 'logo.png'],
                ],
            ]),
        ]);

        $result = $this->client()->assets(['search' => 'photo']);

        $this->assertArrayHasKey('data', $result);
        $this->assertCount(2, $result['data']);
    }

    public function test_asset_returns_single_asset(): void
    {
        Http::fake([
            'dam.example.com/api/v1/assets/abc-123' => Http::response([
                'uuid' => 'abc-123',
                'name' => 'photo.jpg',
                'mime_type' => 'image/jpeg',
            ]),
        ]);

        $result = $this->client()->asset('abc-123');

        $this->assertSame('abc-123', $result['uuid']);
        $this->assertSame('image/jpeg', $result['mime_type']);
    }

    public function test_asset_is_cached(): void
    {
        Http::fake([
            'dam.example.com/api/v1/assets/abc-123' => Http::response(['uuid' => 'abc-123']),
        ]);

        $client = new AssetKiwiClient('https://dam.example.com', 'test-token-123', cacheTtl: 3600);

        $client->asset('abc-123');
        $client->asset('abc-123');

        Http::assertSentCount(1);
    }

    public function test_delete_returns_true_on_success(): void
    {
        Http::fake([
            'dam.example.com/api/v1/assets/abc-123' => Http::response('', 204),
        ]);

        $this->assertTrue($this->client()->delete('abc-123'));
    }

    public function test_resolve_variant_url_finds_variant(): void
    {
        $asset = [
            'variants' => [
                ['variant_name' => 'large', 'url' => 'https://dam.example.com/large.jpg'],
                ['variant_name' => 'thumb', 'url' => 'https://dam.example.com/thumb.jpg'],
            ],
        ];

        $client = $this->client();

        $this->assertSame('https://dam.example.com/thumb.jpg', $client->resolveVariantUrl($asset, 'thumb'));
        $this->assertSame('https://dam.example.com/large.jpg', $client->resolveVariantUrl($asset, 'large'));
        $this->assertNull($client->resolveVariantUrl($asset, 'missing'));
    }

    public function test_resolve_variant_url_returns_null_when_no_variants(): void
    {
        $client = $this->client();
        $this->assertNull($client->resolveVariantUrl([], 'thumb'));
        $this->assertNull($client->resolveVariantUrl(['variants' => []], 'thumb'));
    }

    public function test_get_display_name_prefers_original_name(): void
    {
        $client = $this->client();

        $this->assertSame('photo.jpg', $client->getDisplayName(['original_name' => 'photo.jpg', 'name' => 'other']));
        $this->assertSame('other', $client->getDisplayName(['name' => 'other']));
        $this->assertSame('file.png', $client->getDisplayName(['filename' => 'file.png']));
        $this->assertSame('Untitled', $client->getDisplayName([]));
    }

    public function test_report_usage_sends_correct_payload(): void
    {
        Http::fake([
            'dam.example.com/api/v1/assets/abc-123/usage' => Http::response(['status' => 'ok']),
        ]);

        $this->client()->reportUsage('abc-123', 'posts', '42', 'https://app.test/posts/1', 'app.test');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://dam.example.com/api/v1/assets/abc-123/usage'
                && $request->method() === 'POST'
                && $request['source_entity_type'] === 'posts'
                && $request['source_entity_id'] === '42'
                && $request['source_site'] === 'app.test';
        });
    }

    public function test_remove_usage_sends_delete(): void
    {
        Http::fake([
            'dam.example.com/api/v1/assets/abc-123/usage' => Http::response('', 200),
        ]);

        $this->client()->removeUsage('abc-123', 'posts', '42', 'app.test');

        Http::assertSent(function ($request) {
            return $request->method() === 'DELETE'
                && str_contains($request->url(), 'abc-123/usage');
        });
    }

    public function test_collections_returns_array(): void
    {
        Http::fake([
            'dam.example.com/api/v1/collections' => Http::response(['data' => [['id' => 1, 'name' => 'Marketing']]]),
        ]);

        $result = $this->client()->collections();
        $this->assertArrayHasKey('data', $result);
    }

    public function test_tags_returns_array(): void
    {
        Http::fake([
            'dam.example.com/api/v1/tags' => Http::response(['data' => [['id' => 1, 'name' => 'hero']]]),
        ]);

        $result = $this->client()->tags();
        $this->assertArrayHasKey('data', $result);
    }

    public function test_derivatives_returns_array(): void
    {
        Http::fake([
            'dam.example.com/api/v1/assets/abc-123/derivatives' => Http::response(['data' => []]),
        ]);

        $result = $this->client()->derivatives('abc-123');
        $this->assertIsArray($result);
    }

    public function test_image_styles_returns_array(): void
    {
        Http::fake([
            'dam.example.com/api/v1/image-styles' => Http::response(['data' => [['name' => 'thumbnail']]]),
        ]);

        $result = $this->client()->imageStyles();
        $this->assertIsArray($result);
    }

    public function test_forget_cache_clears_asset_cache(): void
    {
        Cache::put('assetkiwi.asset.abc-123', ['uuid' => 'abc-123'], 3600);
        $this->assertTrue(Cache::has('assetkiwi.asset.abc-123'));

        $this->client()->forgetCache('abc-123');
        $this->assertFalse(Cache::has('assetkiwi.asset.abc-123'));
    }
}
