<?php

declare(strict_types=1);

namespace AssetKiwi\Laravel\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static array assets(array $params = [])
 * @method static array asset(string $uuid)
 * @method static array update(string $uuid, array $data)
 * @method static bool delete(string $uuid)
 * @method static \Illuminate\Http\Client\Response download(string $uuid)
 * @method static array collections(array $params = [])
 * @method static array tags(array $params = [])
 * @method static array imageStyles()
 * @method static array derivatives(string $uuid)
 * @method static array reportUsage(string $uuid, string $entityType, string $entityId, ?string $url = null, ?string $site = null)
 * @method static bool removeUsage(string $uuid, string $entityType, string $entityId, ?string $site = null)
 * @method static string|null resolveVariantUrl(array $asset, string $variant)
 * @method static array{src: string, srcset: string, sizes: string}|null imageAttributes(array $asset)
 * @method static string getDisplayName(array $asset)
 * @method static void forgetCache(string $uuid)
 * @method static string|null assetUrl(string $uuid, array $options = [])
 * @method static string transformUrl(string $uuid, array $options = [])
 * @method static string transformUrlByStyle(string $uuid, $imageStyleId)
 *
 * @see \AssetKiwi\Laravel\Client\AssetKiwiClient
 */
class AssetKiwi extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \AssetKiwi\Laravel\Client\AssetKiwiClient::class;
    }
}
