<?php

declare(strict_types=1);

namespace AssetKiwi\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies the HMAC signature on an inbound asset.kiwi webhook.
 *
 * The sender is Modules\Webhooks\Services\WebhookDispatcher::send(), which
 * signs the TIMESTAMP AND BODY together and ships the timestamp alongside:
 *
 *     X-Webhook-Timestamp: <unix>
 *     X-Webhook-Signature: hash_hmac('sha256', "{$ts}.{$body}", $secret)
 *
 * Binding the timestamp into the signed message is what makes replay
 * detectable: a bare-body signature stays valid forever, so a captured
 * delivery could be resent indefinitely. Deliveries outside MAX_AGE_SECONDS
 * are therefore rejected even when the HMAC itself is intact.
 */
class VerifyWebhookSignature
{
    /**
     * How far out of date a delivery may be before it is refused.
     */
    private const MAX_AGE_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('assetkiwi.webhook_secret', '');

        if (empty($secret)) {
            abort(403, 'Webhook secret not configured.');
        }

        $timestamp = (string) $request->header('X-Webhook-Timestamp', '');

        if ($timestamp === '' || ! ctype_digit($timestamp)) {
            abort(403, 'Missing or malformed webhook timestamp.');
        }

        if (abs(time() - (int) $timestamp) > self::MAX_AGE_SECONDS) {
            abort(403, 'Webhook timestamp outside the accepted window.');
        }

        $signature = (string) $request->header('X-Webhook-Signature', '');
        $expected = hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            abort(403, 'Invalid webhook signature.');
        }

        return $next($request);
    }
}
