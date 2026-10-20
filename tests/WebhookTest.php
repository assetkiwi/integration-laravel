<?php

declare(strict_types=1);

namespace AssetKiwi\Laravel\Tests;

use AssetKiwi\Laravel\AssetKiwiServiceProvider;
use AssetKiwi\Laravel\Events\AssetDeleted;
use AssetKiwi\Laravel\Events\AssetPermanentlyDeleted;
use AssetKiwi\Laravel\Events\AssetUpdated;
use Illuminate\Support\Facades\Event;
use Orchestra\Testbench\TestCase;

class WebhookTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AssetKiwiServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('assetkiwi.api_url', 'https://dam.example.com');
        $app['config']->set('assetkiwi.api_token', 'test-token-123');
        $app['config']->set('assetkiwi.webhook_secret', 'super-secret');
    }

    private function sign(string $body, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$body, 'super-secret');
    }

    private function postWebhook(array $payload, ?string $signature = null, ?int $timestamp = null): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload);
        $ts   = $timestamp ?? time();
        $sig  = $signature ?? $this->sign($body, $ts);

        return $this->postJson('/assetkiwi/webhook', $payload, [
            'X-Webhook-Timestamp' => (string) $ts,
            'X-Webhook-Signature' => $sig,
        ]);
    }

    public function test_rejects_invalid_signature(): void
    {
        $this->postJson('/assetkiwi/webhook', ['event' => 'asset.updated'], [
            'X-Webhook-Timestamp' => (string) time(),
            'X-Webhook-Signature' => 'bad-signature',
        ])->assertStatus(403);
    }

    public function test_rejects_missing_timestamp(): void
    {
        $payload = ['event' => 'asset.updated'];

        // A signature that would have been valid under the old bare-body
        // scheme must not be accepted now that the timestamp is part of the
        // signed message.
        $this->postJson('/assetkiwi/webhook', $payload, [
            'X-Webhook-Signature' => hash_hmac('sha256', json_encode($payload), 'super-secret'),
        ])->assertStatus(403);
    }

    public function test_rejects_stale_timestamp(): void
    {
        // Correctly signed, but replayed well outside the freshness window.
        $this->postWebhook(['event' => 'webhook.test'], timestamp: time() - 3600)
            ->assertStatus(403);
    }

    public function test_rejects_timestamp_tampering(): void
    {
        // Moving the timestamp forward to dodge the staleness check breaks the
        // signature, because the timestamp is inside the signed message.
        $payload = ['event' => 'webhook.test'];
        $sig     = $this->sign(json_encode($payload), time() - 3600);

        $this->postWebhook($payload, signature: $sig)->assertStatus(403);
    }

    public function test_accepts_valid_signature(): void
    {
        Event::fake();

        $this->postWebhook(['event' => 'webhook.test'])->assertStatus(200);
    }

    public function test_dispatches_asset_updated_event(): void
    {
        Event::fake();

        $this->postWebhook([
            'event' => 'asset.updated',
            'data'  => ['uuid' => 'abc-123', 'original_name' => 'photo.jpg'],
        ]);

        Event::assertDispatched(AssetUpdated::class, function (AssetUpdated $event) {
            return $event->uuid === 'abc-123'
                && $event->data['original_name'] === 'photo.jpg';
        });
    }

    public function test_dispatches_asset_deleted_event(): void
    {
        Event::fake();

        $this->postWebhook([
            'event' => 'asset.deleted',
            'data'  => ['uuid' => 'abc-123'],
        ]);

        Event::assertDispatched(AssetDeleted::class, function (AssetDeleted $event) {
            return $event->uuid === 'abc-123';
        });
    }

    public function test_dispatches_asset_permanently_deleted_event(): void
    {
        Event::fake();

        $this->postWebhook([
            'event' => 'asset.permanently_deleted',
            'data'  => ['id' => 7, 'uuid' => 'abc-123', 'type' => 'Asset'],
        ])->assertStatus(200);

        Event::assertDispatched(AssetPermanentlyDeleted::class, fn ($e) => $e->uuid === 'abc-123');
    }

    public function test_prefers_uuid_over_integer_id(): void
    {
        Event::fake();

        $this->postWebhook(['event' => 'asset.updated', 'data' => ['id' => 4182, 'uuid' => 'abc-123']]);

        Event::assertDispatched(AssetUpdated::class, fn ($e) => $e->uuid === 'abc-123');
    }

    public function test_skips_dispatch_when_only_integer_id_present(): void
    {
        Event::fake();

        $this->postWebhook(['event' => 'asset.deleted', 'data' => ['id' => 4182]])->assertStatus(200);

        Event::assertNotDispatched(AssetDeleted::class);
    }

    public function test_returns_400_for_missing_event(): void
    {
        $this->postWebhook(['data' => []])->assertStatus(400);
    }

    public function test_webhook_test_event_returns_ok_without_dispatch(): void
    {
        Event::fake();

        $this->postWebhook(['event' => 'webhook.test'])
            ->assertStatus(200)
            ->assertJson(['status' => 'ok']);

        Event::assertNotDispatched(AssetUpdated::class);
        Event::assertNotDispatched(AssetDeleted::class);
    }

    public function test_unknown_event_returns_ok(): void
    {
        Event::fake();

        $this->postWebhook(['event' => 'some.unknown.event', 'data' => []])
            ->assertStatus(200)
            ->assertJson(['status' => 'ok']);
    }

    public function test_rejects_requests_when_no_secret_configured(): void
    {
        config(['assetkiwi.webhook_secret' => '']);
        Event::fake();

        $this->postJson('/assetkiwi/webhook', [
            'event' => 'webhook.test',
        ], ['X-Webhook-Signature' => 'whatever'])->assertStatus(403);
    }
}
