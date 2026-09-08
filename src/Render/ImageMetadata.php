<?php
declare(strict_types=1);

namespace Sigil\Render;

/**
 * Reads dimensions and MIME type from a stored asset. PNG dimensions come
 * from getimagesize; SVG dimensions are left null (viewBox-based).
 */
final class ImageMetadata
{
    /**
     * @return array{width_px: ?int, height_px: ?int, mime: string}
     */
    public static function read(string $absPath): array
    {
        $info = @getimagesize($absPath);
        if ($info !== false) {
            return ['width_px' => $info[0], 'height_px' => $info[1], 'mime' => $info['mime']];
        }
        $head = @file_get_contents($absPath, false, null, 0, 512);
        if ($head !== false && (str_starts_with(ltrim($head), '<?xml') || str_starts_with(ltrim($head), '<svg'))) {
            return ['width_px' => null, 'height_px' => null, 'mime' => 'image/svg+xml'];
        }
        return ['width_px' => null, 'height_px' => null, 'mime' => 'application/octet-stream'];
    }
}
