<?php

declare(strict_types=1);

namespace AssetKiwi\Laravel\Console\Commands;

use AssetKiwi\Laravel\Client\AssetKiwiClient;
use AssetKiwi\Laravel\Traits\TracksAssetKiwiUsage;
use Illuminate\Console\Command;

class SyncUsageCommand extends Command
{
    protected $signature = 'assetkiwi:sync-usage
                            {--model= : Specific model class to sync (e.g., "App\\Models\\Post")}
                            {--dry-run : Show what would be synced without making API calls}';
    protected $description = 'Re-sync usage records for all models tracked by TracksAssetKiwiUsage';

    public function handle(AssetKiwiClient $client): int
    {
        $models = $this->findTrackedModels();

        if (empty($models)) {
            $this->warn('No models found using TracksAssetKiwiUsage trait.');

            return self::SUCCESS;
        }

        $dryRun = $this->option('dry-run');
        $count = 0;
        $errors = 0;

        foreach ($models as $modelClass) {
            $this->info("Scanning {$modelClass}...");

            /** @var \Illuminate\Database\Eloquent\Model $model */
            foreach ($modelClass::all() as $model) {
                $fields = $this->getAssetKiwiFields($model);
                if (empty($fields)) {
                    continue;
                }

                $entityType = $model->getTable();
                $entityId = (string) $model->getKey();
                $url = method_exists($model, 'url') ? $model->url() : null;

                foreach ($fields as $field) {
                    $uuid = $model->getAttribute($field);
                    if (! $uuid) {
                        continue;
                    }

                    if ($dryRun) {
                        $this->line("  Would sync: {$modelClass} #{$model->getKey()} → asset {$uuid}");
                        $count++;

                        continue;
                    }

                    try {
                        $client->reportUsage(
                            uuid: $uuid,
                            entityType: $entityType,
                            entityId: $entityId,
                            url: $url,
                        );
                        $count++;
                    } catch (\Exception $e) {
                        $this->error("  Failed: {$modelClass} #{$model->getKey()} — {$e->getMessage()}");
                        $errors++;
                    }
                }
            }
        }

        $label = $dryRun ? 'would be synced' : 'synced';
        $this->info("Done. {$count} usage records {$label}." . ($errors ? " {$errors} errors." : ''));

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return class-string[]
     */
    private function findTrackedModels(): array
    {
        $specific = $this->option('model');
        if ($specific) {
            return [$specific];
        }

        $models = [];
        $paths = config('assetkiwi.track_models_in', [app_path('Models')]);

        foreach ($paths as $path) {
            if (! is_dir($path)) {
                continue;
            }

            foreach (glob($path . '/*.php') as $file) {
                $class = $this->resolveClass($file);
                if ($class && $this->usesTracksAssetKiwiUsage($class)) {
                    $models[] = $class;
                }
            }
        }

        return $models;
    }

    private function resolveClass(string $file): ?string
    {
        $content = file_get_contents($file);

        if (
            preg_match('/namespace\s+([^;]+);/', $content, $ns)
            && preg_match('/class\s+(\w+)/', $content, $class)
        ) {
            return $ns[1] . '\\' . $class[1];
        }

        return null;
    }

    private function usesTracksAssetKiwiUsage(string $class): bool
    {
        if (! class_exists($class)) {
            return false;
        }

        $traits = class_uses_recursive($class);

        return in_array(TracksAssetKiwiUsage::class, $traits, true);
    }

    /**
     * @return string[]
     */
    private function getAssetKiwiFields(object $model): array
    {
        // Replicates the trait's assetKiwiAssetFields() logic.
        if (property_exists($model, 'assetKiwiFields')) {
            return $model->assetKiwiFields;
        }

        return [];
    }
}
