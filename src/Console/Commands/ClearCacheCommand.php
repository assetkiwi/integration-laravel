<?php

declare(strict_types=1);

namespace AssetKiwi\Laravel\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class ClearCacheCommand extends Command
{
    protected $signature = 'assetkiwi:cache:clear';
    protected $description = 'Clear all locally cached asset.kiwi API responses';

    public function handle(): int
    {
        $store = config('assetkiwi.cache_store');

        if ($store) {
            Cache::store($store)->flush();
        } else {
            Cache::flush();
        }

        $this->info('Asset.kiwi cache cleared successfully.');

        return self::SUCCESS;
    }
}
