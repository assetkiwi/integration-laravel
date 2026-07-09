<?php

declare(strict_types=1);

namespace AssetKiwi\Laravel\Storage;

use AssetKiwi\Laravel\Client\AssetKiwiClient;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Str;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToWriteFile;

class AssetKiwiFilesystemAdapter implements FilesystemAdapter
{
    public function __construct(private readonly AssetKiwiClient $client) {}

    public function fileExists(string $path): bool
    {
        return ! empty($this->client->asset($path));
    }

    public function directoryExists(string $path): bool
    {
        return false;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'assetkiwi_');
        file_put_contents($tmp, $contents);

        try {
            $this->client->upload($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'assetkiwi_');
        file_put_contents($tmp, $contents);

        try {
            $this->client->upload($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function read(string $path): string
    {
        $response = $this->client->download($path);

        if (! $response->successful()) {
            throw UnableToReadFile::fromLocation($path);
        }

        return $response->body();
    }

    public function readStream(string $path)
    {
        $tmp = tmpfile();
        fwrite($tmp, $this->read($path));
        rewind($tmp);
        return $tmp;
    }

    public function delete(string $path): void
    {
        if (! $this->client->delete($path)) {
            throw UnableToDeleteFile::atLocation($path);
        }
    }

    public function deleteDirectory(string $path): void {}

    public function createDirectory(string $path, Config $config): void {}

    public function setVisibility(string $path, string $visibility): void {}

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, visibility: 'public');
    }

    public function mimeType(string $path): FileAttributes
    {
        $asset = $this->client->asset($path);
        $mimeType = $asset['mime_type'] ?? null;

        if ($mimeType === null) {
            throw UnableToRetrieveMetadata::mimeType($path);
        }

        return new FileAttributes($path, mimeType: $mimeType);
    }

    public function lastModified(string $path): FileAttributes
    {
        $asset = $this->client->asset($path);
        $ts = isset($asset['updated_at']) ? strtotime($asset['updated_at']) : null;

        if ($ts === null || $ts === false) {
            throw UnableToRetrieveMetadata::lastModified($path);
        }

        return new FileAttributes($path, lastModified: $ts);
    }

    public function fileSize(string $path): FileAttributes
    {
        $asset = $this->client->asset($path);
        $size = $asset['file_size'] ?? $asset['size'] ?? null;

        if ($size === null) {
            throw UnableToRetrieveMetadata::fileSize($path);
        }

        return new FileAttributes($path, fileSize: (int) $size);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $response = $this->client->assets();
        foreach ($response['data'] ?? $response as $asset) {
            yield new FileAttributes($asset['uuid'] ?? $asset['id']);
        }
    }

    public function move(string $source, string $destination, Config $config): void {}

    public function copy(string $source, string $destination, Config $config): void {}

    public function url(string $uuid): string
    {
        $asset = $this->client->asset($uuid);

        return $asset['url']
            ?? $this->client->resolveVariantUrl($asset, 'original')
            ?? rtrim(config('assetkiwi.api_url'), '/') . '/api/v1/assets/' . $uuid . '/download';
    }
}
