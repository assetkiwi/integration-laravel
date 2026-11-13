<?php

declare(strict_types=1);

namespace AssetKiwi\Laravel\Http\Controllers;

use AssetKiwi\Laravel\Events\AssetDeleted;
use AssetKiwi\Laravel\Events\AssetPermanentlyDeleted;
use AssetKiwi\Laravel\Events\AssetUpdated;
use AssetKiwi\Laravel\Events\AssetVersionUploaded;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class WebhookController extends Controller
{
    public function receive(Request $request): JsonResponse
    {
        $payload = $request->json()->all();

        if (empty($payload['event'])) {
            return response()->json(['error' => 'Invalid payload'], 400);
        }

        $event = $payload['event'];
        cache()->forever('assetkiwi.webhook.last_seen', now()->timestamp); // so an app can tell that webhooks arrive at all
        $data = $payload['data'] ?? [];
        // Asset events identify the asset by `uuid`; the integer `id` is internal and
        // useless for the cache or the API, so an event without a uuid is not dispatched.
        $uuid = is_string($data['uuid'] ?? null) && $data['uuid'] !== '' ? $data['uuid'] : null;

        match ($event) {
            // An archive (also a rights expiry) or a restore changes what the asset may be used for, like an edit does.
            'asset.updated', 'asset.status_changed', 'asset.metadata_changed', 'asset.restored' => $uuid ? AssetUpdated::dispatch($uuid, $data) : null,
            // A replaced file: the transform URL stays the same, so listeners must bust their caches.
            'asset.version_uploaded' => $uuid ? AssetVersionUploaded::dispatch($uuid, $data) : null,
            'asset.deleted' => $uuid ? AssetDeleted::dispatch($uuid, $data) : null,
            'asset.permanently_deleted' => $uuid ? AssetPermanentlyDeleted::dispatch($uuid, $data) : null,
            'webhook.test'  => null,
            default         => null,
        };

        return response()->json(['status' => 'ok']);
    }
}
