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

    public function test_failed_asset_lookups_are_not_cached_and_ttl_stays_below_signed_url_lifetime(): void
    {
        Http::fakeSequence('dam.example.com/api/v1/assets/abc-123')
            ->push([], 500)
            ->push(['data' => ['uuid' => 'abc-123']]);
        Cache::spy();

        $client = new AssetKiwiClient('https://dam.example.com', 'test-token-123', cacheTtl: 3600);

        $this->assertSame([], $client->asset('abc-123'));
        $this->assertSame(['data' => ['uuid' => 'abc-123']], $client->asset('abc-123'));
        Cache::shouldHaveReceived('put')->once()->with('assetkiwi.asset.abc-123', ['data' => ['uuid' => 'abc-123']], AssetKiwiClient::MAX_ASSET_CACHE_SECONDS);
    }

    public function test_public_images_get_a_stable_srcset_and_private_ones_none(): void
    {
        $client = $this->client();
        $image = $client->imageAttributes(['data' => [
            'width' => 1000,
            'embed_url' => 'https://dam.example.com/media/serve/u1',
            'image_transform' => ['url' => 'https://dam.example.com/media/serve/u1/transform', 'widths' => [160, 320, 640, 960, 1280]],
        ]]);

        $this->assertSame('https://dam.example.com/media/serve/u1/transform?w=960', $image['src']);
        $this->assertSame(
            'https://dam.example.com/media/serve/u1/transform?w=160 160w, https://dam.example.com/media/serve/u1/transform?w=320 320w, https://dam.example.com/media/serve/u1/transform?w=640 640w, https://dam.example.com/media/serve/u1/transform?w=960 960w',
            $image['srcset'],
        );
        $this->assertSame('(max-width: 1000px) 100vw, 1000px', $image['sizes']);
        $this->assertNull($client->imageAttributes(['embed_url' => null, 'image_transform' => null]));
    }

    public function test_asset_url_prefers_the_stable_embed_url(): void
    {
        Http::fake([
            'dam.example.com/api/v1/assets/abc-123' => Http::response(['data' => [
                'uuid' => 'abc-123',
                'embed_url' => 'https://dam.example.com/media/serve/abc-123',
                'variants' => [['variant_name' => 'original', 'url' => 'https://r2.example.com/o.jpg?X-Amz-Signature=x']],
            ]]),
        ]);

        $this->assertSame('https://dam.example.com/media/serve/abc-123', $this->client()->assetUrl('abc-123'));
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

    public function test_transform_url_builds_url_with_options(): void
    {
        $url = $this->client()->transformUrl('abc-123', ['w' => 800, 'format' => 'webp']);

        $this->assertStringContainsString('/media/serve/abc-123/transform', $url);
        $this->assertStringContainsString('w=800', $url);
        $this->assertStringContainsString('format=webp', $url);
    }

    public function test_transform_url_with_no_options(): void
    {
        $url = $this->client()->transformUrl('abc-123');

        $this->assertSame('https://dam.example.com/media/serve/abc-123/transform', $url);
    }

    public function test_transform_url_filters_unknown_options(): void
    {
        $url = $this->client()->transformUrl('abc-123', ['w' => 400, 'unknown' => 'ignored']);

        $this->assertStringContainsString('w=400', $url);
        $this->assertStringNotContainsString('ignored', $url);
    }

    public function test_transform_url_by_style(): void
    {
        $url = $this->client()->transformUrlByStyle('abc-123', 'hero-banner');

        $this->assertSame('https://dam.example.com/media/serve/abc-123/transform/hero-banner', $url);
    }

    public function test_transform_url_by_style_with_integer_id(): void
    {
        $url = $this->client()->transformUrlByStyle('abc-123', 5);

        $this->assertSame('https://dam.example.com/media/serve/abc-123/transform/5', $url);
    }

    public function test_asset_url_with_no_options_returns_variant_url(): void
    {
        Http::fake([
            'dam.example.com/api/v1/assets/abc-123' => Http::response([
                'uuid' => 'abc-123',
                'variants' => [
                    ['variant_name' => 'original', 'url' => 'https://dam.example.com/original.jpg'],
                    ['variant_name' => 'thumb', 'url' => 'https://dam.example.com/thumb.jpg'],
                ],
            ]),
        ]);

        $url = $this->client()->assetUrl('abc-123');

        $this->assertSame('https://dam.example.com/original.jpg', $url);
    }

    public function test_asset_url_with_no_options_falls_back_to_thumb(): void
    {
        Http::fake([
            'dam.example.com/api/v1/assets/abc-123' => Http::response([
                'uuid' => 'abc-123',
                'variants' => [
                    ['variant_name' => 'thumb', 'url' => 'https://dam.example.com/thumb.jpg'],
                ],
            ]),
        ]);

        $url = $this->client()->assetUrl('abc-123');

        $this->assertSame('https://dam.example.com/thumb.jpg', $url);
    }

    public function test_asset_url_returns_null_for_missing_asset(): void
    {
        Http::fake([
            'dam.example.com/api/v1/assets/missing' => Http::response([], 404),
        ]);

        $url = $this->client()->assetUrl('missing');

        $this->assertNull($url);
    }

    public function test_asset_url_with_options_returns_transform_url(): void
    {
        $url = $this->client()->assetUrl('abc-123', ['w' => 400, 'q' => 80]);

        $this->assertStringContainsString('/media/serve/abc-123/transform', $url);
        $this->assertStringContainsString('w=400', $url);
        $this->assertStringContainsString('q=80', $url);
    }

    public function test_asset_url_with_style_option_returns_style_url(): void
    {
        $url = $this->client()->assetUrl('abc-123', ['style' => 'hero-banner']);

        $this->assertSame('https://dam.example.com/media/serve/abc-123/transform/hero-banner', $url);
    }

    public function test_asset_url_style_takes_priority_over_other_options(): void
    {
        $url = $this->client()->assetUrl('abc-123', ['style' => 'hero', 'w' => 400]);

        // style should take priority, so it's a transformUrlByStyle call
        $this->assertSame('https://dam.example.com/media/serve/abc-123/transform/hero', $url);
    }

    public function test_upload_posts_multipart_file_to_assets(): void
    {
        Http::fake(['dam.example.com/api/v1/assets' => Http::response(['data' => ['uuid' => 'new-1']], 201)]);
        $tmp = tempnam(sys_get_temp_dir(), 'ak');
        file_put_contents($tmp, 'bytes');

        $response = $this->client()->upload($tmp, 'photo.jpg', ['alt_text' => 'A']);
        @unlink($tmp);

        $this->assertSame(201, $response->status());
        Http::assertSent(fn ($r) => $r->method() === 'POST'
            && $r->url() === 'https://dam.example.com/api/v1/assets'
            && $r->isMultipart()
            && collect($r->data())->contains(fn ($p) => $p['name'] === 'file' && $p['filename'] === 'photo.jpg'));
    }

    public function test_filesystem_put_uploads_and_treats_duplicate_as_success_but_fails_on_error(): void
    {
        $adapter = new \AssetKiwi\Laravel\Storage\AssetKiwiFilesystemAdapter($this->client());
        $config = new \League\Flysystem\Config();

        Http::fake(['dam.example.com/api/v1/assets' => Http::sequence()
            ->push(['data' => []], 201)
            ->push(['duplicate' => true, 'existing_uuid' => 'x'], 409)
            ->push(['message' => 'No storage'], 422)]);

        $adapter->write('a.txt', 'one', $config);
        $adapter->write('a.txt', 'one', $config);
        $this->expectException(\League\Flysystem\UnableToWriteFile::class);
        $adapter->write('a.txt', 'one', $config);
    }

    public function test_filesystem_move_and_copy_throw(): void
    {
        $adapter = new \AssetKiwi\Laravel\Storage\AssetKiwiFilesystemAdapter($this->client());
        $this->expectException(\League\Flysystem\UnableToMoveFile::class);
        $adapter->move('a', 'b', new \League\Flysystem\Config());
    }
}
