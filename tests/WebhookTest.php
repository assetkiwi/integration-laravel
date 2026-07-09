<?php

declare(strict_types=1);

namespace AssetKiwi\Laravel\Tests;

use AssetKiwi\Laravel\AssetKiwiServiceProvider;
use AssetKiwi\Laravel\Events\AssetDeleted;
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

    private function sign(string $body): string
    {
        return hash_hmac('sha256', $body, 'super-secret');
    }

    private function postWebhook(array $payload, ?string $signature = null): \Illuminate\Testing\TestResponse
    {
        $body = json_encode($payload);
        $sig  = $signature ?? $this->sign($body);

        return $this->postJson('/assetkiwi/webhook', $payload, [
            'X-Webhook-Signature' => $sig,
        ]);
    }

    public function test_rejects_invalid_signature(): void
    {
        $this->postJson('/assetkiwi/webhook', ['event' => 'asset.updated'], [
            'X-Webhook-Signature' => 'bad-signature',
        ])->assertStatus(403);
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
