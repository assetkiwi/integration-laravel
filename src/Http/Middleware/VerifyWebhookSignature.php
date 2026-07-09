<?php

declare(strict_types=1);

namespace AssetKiwi\Laravel\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('assetkiwi.webhook_secret', '');

        if (empty($secret)) {
            abort(403, 'Webhook secret not configured.');
        }

        $signature = $request->header('X-Webhook-Signature', '');
        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($expected, $signature)) {
            abort(403, 'Invalid webhook signature.');
        }

        return $next($request);
    }
}
