<?php
declare(strict_types=1);

namespace Sigil\Render;

use Sigil\Util\Logger;

final class RenderService
{
    public function __construct(
        private AssetStore $store,
        private Logger $logger,
        private ProviderRegistry $registry,
        private ?ImagePostProcessor $postProcessor = null
    ) {
    }

    public function registry(): ProviderRegistry
    {
        return $this->registry;
    }

    /**
     * @return array{
     *   output_path: string,
     *   output_url: ?string,
     *   width_px: ?int,
     *   height_px: ?int,
     *   provider_job_id: ?string,
     *   cost_usd: ?float,
     *   format: string
     * }
     */
    public function render(string $provider, string $prompt, string $format, ?int $seed, string $renderId): array
    {
        $result = $this->registry->make($provider)->render($prompt, $format, $seed);

        // Clean up the raster before it is stored, so the library and every
        // download get the corrected image rather than the raw one.
        if ($this->postProcessor !== null) {
            $cleaned = $this->postProcessor->process($result['bytes'], $result['format']);
            $result['bytes'] = $cleaned['bytes'];
            $result['format'] = $cleaned['format'];
        }

        $rel = $this->store->save($renderId, $result['format'], $result['bytes']);
        $meta = ImageMetadata::read($this->store->open($rel));

        return [
            'output_path' => $rel,
            'output_url' => $result['output_url'],
            'width_px' => $meta['width_px'],
            'height_px' => $meta['height_px'],
            'provider_job_id' => $result['provider_job_id'],
            'cost_usd' => $result['cost_usd'],
            'format' => $result['format'],
        ];
    }
}
