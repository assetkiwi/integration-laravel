<?php

declare(strict_types=1);

namespace AssetKiwi\Laravel\Console\Commands;

use AssetKiwi\Laravel\Client\AssetKiwiClient;
use Illuminate\Console\Command;

class StatsCommand extends Command
{
    protected $signature = 'assetkiwi:stats
                            {--uuid= : Show stats for a specific asset UUID}';
    protected $description = 'Show asset.kiwi usage statistics for this application';

    public function handle(AssetKiwiClient $client): int
    {
        $uuid = $this->option('uuid');
        $site = config('app.url');

        if ($uuid) {
            return $this->showAssetStats($client, $uuid);
        }

        // Show aggregate stats.
        $this->info('asset.kiwi Integration Statistics');
        $this->line('API URL: ' . config('assetkiwi.api_url'));
        $this->line('Connected Site: ' . $site);
        $this->line('');

        // Cache configuration.
        $this->line('Cache Status:');
        $this->line('  Cache store: ' . (config('assetkiwi.cache_store') ?: 'default'));
        $this->line('  Cache TTL: ' . ((int) config('assetkiwi.cache_ttl', 3600) / 60) . ' minutes');
        $this->line('');

        // Test connection.
        try {
            $start = microtime(true);
            $styles = $client->imageStyles();
            $latency = round((microtime(true) - $start) * 1000);
            $this->info('Connection: OK (latency: ' . $latency . 'ms, ' . count($styles) . ' image styles available)');
        } catch (\Exception $e) {
            $this->error('Connection: FAILED — ' . $e->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function showAssetStats(AssetKiwiClient $client, string $uuid): int
    {
        $asset = $client->asset($uuid);

        if (! $asset) {
            $this->error("Asset {$uuid} not found.");

            return self::FAILURE;
        }

        $this->info('Asset: ' . $client->getDisplayName($asset));
        $this->line('UUID: ' . $uuid);
        $this->line('Type: ' . ($asset['mime_type'] ?? 'unknown'));
        $this->line('Size: ' . $this->formatBytes((int) ($asset['file_size'] ?? 0)));
        $this->line('Variants: ' . count($asset['variants'] ?? []));

        return self::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;

        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes = (int) ($bytes / 1024);
            $i++;
        }

        return $bytes . ' ' . $units[$i];
    }
}
