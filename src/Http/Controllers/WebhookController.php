<?php

declare(strict_types=1);

namespace AssetKiwi\Laravel\Http\Controllers;

use AssetKiwi\Laravel\Events\AssetDeleted;
use AssetKiwi\Laravel\Events\AssetPermanentlyDeleted;
use AssetKiwi\Laravel\Events\AssetUpdated;
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
        $data = $payload['data'] ?? [];
        // Asset events identify the asset by `uuid`; the integer `id` is internal and
        // useless for the cache or the API, so an event without a uuid is not dispatched.
        $uuid = is_string($data['uuid'] ?? null) && $data['uuid'] !== '' ? $data['uuid'] : null;

        match ($event) {
            'asset.updated' => $uuid ? AssetUpdated::dispatch($uuid, $data) : null,
            'asset.deleted' => $uuid ? AssetDeleted::dispatch($uuid, $data) : null,
            'asset.permanently_deleted' => $uuid ? AssetPermanentlyDeleted::dispatch($uuid, $data) : null,
            'webhook.test'  => null,
            default         => null,
        };

        return response()->json(['status' => 'ok']);
    }
}
