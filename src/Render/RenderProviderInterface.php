<?php
declare(strict_types=1);

namespace Sigil\Render;

interface RenderProviderInterface
{
    public function name(): string;

    /**
     * @return array{
     *   bytes: string,
     *   format: string,
     *   provider_job_id: ?string,
     *   output_url: ?string,
     *   cost_usd: ?float
     * }
     */
    public function render(string $prompt, string $format, ?int $seed): array;
}
