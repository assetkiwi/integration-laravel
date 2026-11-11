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

    public function test_upload_asset_returns_the_uuid_and_treats_a_duplicate_as_success(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'ak');
        file_put_contents($file, 'x');
        Http::fakeSequence('dam.example.com/api/v1/assets')
            ->push(['data' => ['uuid' => 'new-1']], 201)
            ->push(['message' => 'dup', 'existing_uuid' => 'old-1'], 409)
            ->push(['message' => 'No'], 403);

        $this->assertSame(['uuid' => 'new-1', 'duplicate' => false], $this->client()->uploadAsset($file));
        $this->assertSame(['uuid' => 'old-1', 'duplicate' => true], $this->client()->uploadAsset($file));
        $this->expectExceptionCode(403);
        $this->client()->uploadAsset($file);
    }

    public function test_usages_and_focal_point(): void
    {
        Http::fake([
            'dam.example.com/api/v1/assets/a1/usage' => Http::response(['data' => [['source_site' => 'x.test']]]),
            'dam.example.com/api/v1/assets/a1/focal-point' => Http::response(['data' => []]),
        ]);

        $this->assertSame('x.test', $this->client()->usages('a1')[0]['source_site']);
        $this->assertTrue($this->client()->focalPoint('a1', 30, 70));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/focal-point') && $r['focal_x'] == 30);
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

    public function test_requests_identify_the_client(): void
    {
        $this->app['config']->set('app.url', 'https://site.example.com');
        Http::fake(['dam.example.com/*' => Http::response(['data' => []])]);

        $this->client()->assets();

        Http::assertSent(fn ($request) => preg_match('#^laravel/\S+$#', $request->header('X-AssetKiwi-Client')[0]) === 1
            && $request->header('X-AssetKiwi-Client-Site') === ['site.example.com']
            && preg_match('#^AssetKiwi-Laravel/\S+ \(Laravel \S+; site\.example\.com\)$#', $request->header('User-Agent')[0]) === 1);
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

    private function signingClient(): AssetKiwiClient
    {
        return new AssetKiwiClient('https://dam.example.com', 't', 0, 'test-signing-key');
    }

    private function publicAsset(array $extra = []): array
    {
        return ['uuid' => 'u1', 'width' => 3000, 'height' => 2000, 'image_transform' => [
            'url' => 'https://dam.example.com/media/serve/0b9f6f2e-3c1d-4f5a-9e8b-7a6c5d4e3f21/transform',
            'widths' => AssetKiwiClient::DEFAULT_WIDTHS,
        ] + $extra];
    }

    public function test_transform_url_canonical_order_with_dpr_and_no_gravity_needed(): void
    {
        $url = $this->client()->transformUrl('u', ['gravity' => 'center', 'dpr' => 2, 'format' => 'webp', 'q' => 80, 'fit' => 'crop', 'h' => 5, 'w' => 9]);

        $this->assertSame('https://dam.example.com/media/serve/u/transform?w=9&h=5&fit=crop&q=80&format=webp&gravity=center&dpr=2', $url);
    }

    public function test_image_attributes_format_and_q_passthrough(): void
    {
        $a = $this->client()->imageAttributes($this->publicAsset(), ['q' => 80, 'format' => 'avif']);

        $this->assertStringContainsString('transform?w=160&q=80&format=avif 160w', $a['srcset']);
        $this->assertSame('https://dam.example.com/media/serve/0b9f6f2e-3c1d-4f5a-9e8b-7a6c5d4e3f21/transform?w=1280&q=80&format=avif', $a['src']);
    }

    public function test_image_attributes_ratio_caps_by_source_and_rounds_height(): void
    {
        // height 2000 * 16/9 = 3555 > 3000 width; cap 3000 -> all steps. 4:3 caps at 2666 -> 2560 max.
        $a = $this->client()->imageAttributes($this->publicAsset(), ['ratio' => '16:9']);
        $this->assertStringContainsString('transform?w=160&h=90&fit=crop 160w', $a['srcset']);
        $this->assertStringContainsString('transform?w=480&h=270&fit=crop 480w', $a['srcset']);
        $this->assertStringContainsString('transform?w=1080&h=608&fit=crop 1080w', $a['srcset']);
        $this->assertSame('https://dam.example.com/media/serve/0b9f6f2e-3c1d-4f5a-9e8b-7a6c5d4e3f21/transform?w=1280&h=720&fit=crop', $a['src']);
        $this->assertSame(1280, $a['width']);
        $this->assertSame(720, $a['height']);

        $asset = $this->publicAsset();
        $asset['width'] = 1000;
        $asset['height'] = 500; // 1:1 caps at 500 -> max step 480
        $b = $this->client()->imageAttributes($asset, ['ratio' => '1:1']);
        $this->assertStringEndsWith('w=480&h=480&fit=crop 480w', $b['srcset']);
        $this->assertSame(480, $b['width']);
    }

    public function test_image_attributes_off_list_ratio_unsigned_without_key_signed_with_key(): void
    {
        $plain = $this->client()->imageAttributes($this->publicAsset(), ['ratio' => '5:2']);
        $this->assertStringNotContainsString('&s=', $plain['srcset']);

        $implicit = $this->signingClient()->imageAttributes($this->publicAsset(), ['ratio' => '5:2']);
        $this->assertStringNotContainsString('&s=', $implicit['srcset']);
        // 5:2 = 2.5 snaps to 21:9 (2.33) vs 16:9 (1.78): w=160 -> h=round(160/(21/9))=69
        $this->assertStringContainsString('transform?w=160&h=69&fit=crop 160w', $implicit['srcset']);

        $signed = $this->signingClient()->imageAttributes($this->publicAsset(), ['ratio' => '5:2', 'sign' => true]);
        $this->assertStringContainsString('&s=', $signed['srcset']);
        $this->assertStringContainsString('h=64&', $signed['srcset']);
        $listed = $this->signingClient()->imageAttributes($this->publicAsset(), ['ratio' => '16:9']);
        $this->assertStringNotContainsString('&s=', $listed['srcset']);
    }

    public function test_signed_transform_url_test_vectors(): void
    {
        $c = $this->signingClient();
        $uuid = '0b9f6f2e-3c1d-4f5a-9e8b-7a6c5d4e3f21';

        $this->assertSame(
            "https://dam.example.com/media/serve/{$uuid}/transform?fit=crop&gravity=fp%3A0.3%3A0.5&h=333&w=500&s=4503193ad0e527f42e70504a63cc2644528904bc5adee9963cecd4e2e23f09db",
            $c->signedTransformUrl($uuid, ['w' => 500, 'h' => 333, 'fit' => 'crop', 'gravity' => 'fp:0.3:0.5']),
        );
        $this->assertSame(
            "https://dam.example.com/media/serve/{$uuid}/transform?dpr=1.5&exp=1893456000&format=jpg&q=75&w=640&s=f0abd7daf9bbec56cb9d7187f08f02f6ce1d52c807d22d96f1021e328eb3d3a2",
            $c->signedTransformUrl($uuid, ['w' => 640, 'format' => 'jpg', 'q' => 75, 'dpr' => 1.5], 1893456000),
        );
    }

    public function test_signed_transform_url_throws_without_key(): void
    {
        $this->expectException(\LogicException::class);
        $this->client()->signedTransformUrl('u', ['w' => 1]);
    }

    public function test_ratio_out_of_range_throws(): void
    {
        foreach (['1e-308:1e308', '100:1', '1:100'] as $r) {
            try {
                $this->client()->imageAttributes($this->publicAsset(), ['ratio' => $r]);
                $this->fail("no throw for {$r}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_debug_info_masks_secrets(): void
    {
        $dump = print_r($this->signingClient(), true) . var_export($this->signingClient()->__debugInfo(), true);
        $this->assertStringNotContainsString('test-signing-key', $dump);
        $this->assertStringContainsString('***', $dump);
    }

    public function test_signed_url_validates_uuid_and_base(): void
    {
        $c = $this->signingClient();
        $uuid = '0b9f6f2e-3c1d-4f5a-9e8b-7a6c5d4e3f21';
        foreach (['../x', 'abc'] as $bad) {
            try { $c->signedTransformUrl($bad, ['w' => 1]); $this->fail('uuid'); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        foreach (['https://evil.example.com/media/serve/'.$uuid.'/transform', 'https://dam.example.com/media/serve/other/transform'] as $bad) {
            try { $c->signedTransformUrl($uuid, ['w' => 1], null, $bad); $this->fail('base'); } catch (\InvalidArgumentException) { $this->addToAssertionCount(1); }
        }
        $ok = $c->signedTransformUrl($uuid, ['w' => 1], null, 'https://dam.example.com/media/serve/'.$uuid.'/transform');
        $this->assertStringStartsWith('https://dam.example.com/media/serve/'.$uuid.'/transform?w=1&s=', $ok);
    }

    public function test_non_numeric_dpr_is_dropped(): void
    {
        $this->assertSame('https://dam.example.com/media/serve/u/transform?w=9', $this->client()->transformUrl('u', ['w' => 9, 'dpr' => 'x']));
        $this->assertStringNotContainsString('dpr', $this->signingClient()->signedTransformUrl('0b9f6f2e-3c1d-4f5a-9e8b-7a6c5d4e3f21', ['w' => 9, 'dpr' => 'x']));
    }
}
