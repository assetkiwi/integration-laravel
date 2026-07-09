<?php

declare(strict_types=1);

namespace AssetKiwi\Laravel\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AssetDeleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $uuid,
        public readonly array $data,
    ) {}
}
