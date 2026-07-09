<?php

use AssetKiwi\Laravel\Http\Controllers\WebhookController;
use AssetKiwi\Laravel\Http\Middleware\VerifyWebhookSignature;
use Illuminate\Support\Facades\Route;

Route::post('/assetkiwi/webhook', [WebhookController::class, 'receive'])
    ->middleware(VerifyWebhookSignature::class)
    ->name('assetkiwi.webhook');
