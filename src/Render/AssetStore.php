<?php
declare(strict_types=1);

namespace Sigil\Render;

/**
 * Filesystem-backed asset store. Files live under storage/renders/ and are
 * never served directly by the web server — access goes through render.php.
 */
final class AssetStore
{
    public function __construct(private string $dir)
    {
    }

    /**
     * @return string relative path (renders/<id>.<ext>)
     */
    public function save(string $renderId, string $format, string $bytes): string
    {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
        $abs = $this->dir . '/' . $renderId . '.' . $format;
        if (file_put_contents($abs, $bytes) === false) {
            throw new \RuntimeException('Failed to write asset: ' . $abs);
        }
        return 'renders/' . $renderId . '.' . $format;
    }

    public function open(string $relativePath): string
    {
        return $this->dir . '/' . basename($relativePath);
    }

    public function delete(string $relativePath): void
    {
        $abs = $this->open($relativePath);
        if (is_file($abs)) {
            @unlink($abs);
        }
    }
}
