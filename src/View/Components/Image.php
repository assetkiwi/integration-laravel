<?php

declare(strict_types=1);

namespace AssetKiwi\Laravel\View\Components;

use AssetKiwi\Laravel\Client\AssetKiwiClient;
use Illuminate\View\Component;
use Illuminate\View\View;

class Image extends Component
{
    public readonly array $asset;
    public readonly ?string $src;
    public readonly ?string $srcset;
    public readonly ?string $sizes;
    public readonly string $altText;
    public readonly ?int $imgWidth;
    public readonly ?int $imgHeight;

    public function __construct(
        private readonly AssetKiwiClient $client,
        public readonly string $uuid,
        public readonly string $variant = 'thumb',
        public readonly ?string $alt = null,
        public readonly ?string $class = null,
        public readonly ?int $width = null,
        public readonly ?int $height = null,
    ) {
        $asset = $client->asset($uuid);
        $this->asset = $asset['data'] ?? $asset;

        // Public images: stable, resizable URLs with a srcset. Others: the
        // API's signed variant URL, which expires after about an hour.
        $public = $client->imageAttributes($this->asset);
        $this->srcset = $public['srcset'] ?? null;
        $this->sizes = $public['sizes'] ?? null;
        $this->src = $public['src']
            ?? $client->resolveVariantUrl($this->asset, $variant)
            ?? $client->resolveVariantUrl($this->asset, 'original');

        $this->altText = $alt
            ?? $this->asset['alt_text']
            ?? $this->asset['original_name']
            ?? '';

        $variantData = $public ? $this->asset : collect($this->asset['variants'] ?? [])->firstWhere('variant_name', $variant);
        $this->imgWidth  = $width  ?? ($variantData['width']  ?? null);
        $this->imgHeight = $height ?? ($variantData['height'] ?? null);
    }

    public function render(): View
    {
        return view('assetkiwi::components.image');
    }
}
