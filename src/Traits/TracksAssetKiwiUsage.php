<?php

declare(strict_types=1);

namespace AssetKiwi\Laravel\Traits;

use AssetKiwi\Laravel\Client\AssetKiwiClient;

trait TracksAssetKiwiUsage
{
    public static function bootTracksAssetKiwiUsage(): void
    {
        static::saved(function (self $model) {
            $model->syncAssetKiwiUsage();
        });

        static::deleted(function (self $model) {
            $model->removeAllAssetKiwiUsage();
        });
    }

    protected function assetKiwiAssetFields(): array
    {
        return property_exists($this, 'assetKiwiFields') ? $this->assetKiwiFields : [];
    }

    protected function syncAssetKiwiUsage(): void
    {
        $client = app(AssetKiwiClient::class);
        $entityType = $this->getTable();
        $entityId = (string) $this->getKey();
        $url = method_exists($this, 'url') ? $this->url() : null;

        foreach ($this->assetKiwiAssetFields() as $field) {
            $uuid = $this->getAttribute($field);
            if (! $uuid) {
                continue;
            }
            $client->reportUsage(
                uuid: $uuid,
                entityType: $entityType,
                entityId: $entityId,
                url: $url,
            );
        }
    }

    protected function removeAllAssetKiwiUsage(): void
    {
        $client = app(AssetKiwiClient::class);
        $entityType = $this->getTable();
        $entityId = (string) $this->getKey();

        foreach ($this->assetKiwiAssetFields() as $field) {
            $uuid = $this->getAttribute($field);
            if (! $uuid) {
                continue;
            }
            $client->removeUsage(
                uuid: $uuid,
                entityType: $entityType,
                entityId: $entityId,
            );
        }
    }
}
