<?php
declare(strict_types=1);

namespace Sigil\Render;

use Sigil\Util\Logger;

/**
 * Cleans up a generated raster so it is usable as a logo.
 *
 * Image models ignore "solid white background" often enough to matter: the
 * free model returns marks sitting on flat grey (measured 212-218 across
 * runs) with the subject floating in a wide margin. That is not a logo you
 * can drop into a document. None of it needs another model to fix — the
 * background is flat and detectable, so it is corrected arithmetically.
 *
 * Three passes, each independently switchable:
 *   1. Detect a flat, light, neutral background from the border ring.
 *   2. Snap every pixel near that colour to pure white (or to transparency).
 *   3. Trim the dead margin and re-frame the mark square with even padding.
 *
 * Deliberately conservative: if the border is not flat, or not light and
 * neutral, the image is returned untouched. A deep-navy emblem on a navy
 * field is a design decision, not a defect, and must not be bleached.
 */
final class ImagePostProcessor
{
    /** Fraction of the shorter side sampled as the border ring. */
    private const BORDER_FRACTION = 0.02;

    /** Max spread across sampled border pixels for it to count as "flat". */
    private const FLATNESS_TOLERANCE = 12;

    /** A background must be at least this bright to be treated as "meant to be white". */
    private const MIN_BACKGROUND_BRIGHTNESS = 175;

    /** Max channel spread for a colour to count as neutral (grey/white, not tinted). */
    private const MAX_NEUTRAL_SPREAD = 22;

    /** How far from the detected background a pixel can be and still be background. */
    private const MATCH_TOLERANCE = 26;

    /** Padding around the trimmed mark, as a fraction of the final canvas. */
    private const PADDING_FRACTION = 0.07;

    public function __construct(private array $cfg, private ?Logger $logger = null)
    {
    }

    /**
     * @return array{bytes: string, format: string, changed: bool}
     */
    public function process(string $bytes, string $format): array
    {
        $unchanged = ['bytes' => $bytes, 'format' => $format, 'changed' => false];

        if (($this->cfg['enabled'] ?? true) !== true || !extension_loaded('gd')) {
            return $unchanged;
        }
        // Vector output is already clean and must never be rasterised.
        if ($format === 'svg') {
            return $unchanged;
        }

        $im = @imagecreatefromstring($bytes);
        if ($im === false) {
            return $unchanged;
        }

        try {
            $bg = $this->detectBackground($im);
            if ($bg === null) {
                return $unchanged;
            }

            $transparent = ($this->cfg['transparent'] ?? false) === true;
            $out = $this->normalise($im, $bg, $transparent);

            if (($this->cfg['trim'] ?? true) === true) {
                $trimmed = $this->trim($out, $transparent);
                if ($trimmed !== null) {
                    if ($trimmed !== $out) {
                        imagedestroy($out);
                    }
                    $out = $trimmed;
                }
            }

            ob_start();
            imagepng($out, null, 6);
            $png = (string) ob_get_clean();
            imagedestroy($out);

            if ($png === '') {
                return $unchanged;
            }

            $this->logger?->info('postprocess', 'Background normalised', [
                'from_rgb' => sprintf('%d,%d,%d', $bg[0], $bg[1], $bg[2]),
                'to' => $transparent ? 'transparent' : 'white',
            ]);

            // Always PNG out: JPEG re-encoding would reintroduce halos around
            // the edges we just cleaned, and JPEG cannot carry transparency.
            return ['bytes' => $png, 'format' => 'png', 'changed' => true];
        } finally {
            if (is_object($im)) {
                @imagedestroy($im);
            }
        }
    }

    /**
     * Median colour of the border ring, or null when the border is not a flat,
     * light, neutral field — in which case the image is left alone.
     *
     * @return array{0:int,1:int,2:int}|null
     */
    private function detectBackground(\GdImage $im): ?array
    {
        $w = imagesx($im);
        $h = imagesy($im);
        $band = max(1, (int) round(min($w, $h) * self::BORDER_FRACTION));
        $step = max(1, (int) round(min($w, $h) / 120));

        $r = [];
        $g = [];
        $b = [];
        for ($x = 0; $x < $w; $x += $step) {
            foreach ([0, $band, $h - 1 - $band, $h - 1] as $y) {
                $y = max(0, min($h - 1, $y));
                $c = imagecolorat($im, $x, $y);
                $r[] = ($c >> 16) & 255;
                $g[] = ($c >> 8) & 255;
                $b[] = $c & 255;
            }
        }
        for ($y = 0; $y < $h; $y += $step) {
            foreach ([0, $band, $w - 1 - $band, $w - 1] as $x) {
                $x = max(0, min($w - 1, $x));
                $c = imagecolorat($im, $x, $y);
                $r[] = ($c >> 16) & 255;
                $g[] = ($c >> 8) & 255;
                $b[] = $c & 255;
            }
        }
        if ($r === []) {
            return null;
        }

        $median = static function (array $v): int {
            sort($v);
            return (int) $v[intdiv(count($v), 2)];
        };
        $bg = [$median($r), $median($g), $median($b)];

        // Flat? Most of the ring must sit close to that median.
        $near = 0;
        $n = count($r);
        for ($i = 0; $i < $n; $i++) {
            if (abs($r[$i] - $bg[0]) <= self::FLATNESS_TOLERANCE
                && abs($g[$i] - $bg[1]) <= self::FLATNESS_TOLERANCE
                && abs($b[$i] - $bg[2]) <= self::FLATNESS_TOLERANCE) {
                $near++;
            }
        }
        if ($near / $n < 0.82) {
            return null;
        }

        // Light and neutral? A dark or saturated field is intentional.
        if (min($bg) < self::MIN_BACKGROUND_BRIGHTNESS) {
            return null;
        }
        if ((max($bg) - min($bg)) > self::MAX_NEUTRAL_SPREAD) {
            return null;
        }
        // Already pure white: nothing to gain.
        if (min($bg) >= 250) {
            return null;
        }

        return $bg;
    }

    /** Snaps near-background pixels to white or transparency. */
    private function normalise(\GdImage $im, array $bg, bool $transparent): \GdImage
    {
        $w = imagesx($im);
        $h = imagesy($im);
        $out = imagecreatetruecolor($w, $h);

        if ($transparent) {
            imagealphablending($out, false);
            imagesavealpha($out, true);
            $blank = imagecolorallocatealpha($out, 255, 255, 255, 127);
        } else {
            $blank = imagecolorallocate($out, 255, 255, 255);
        }
        imagefilledrectangle($out, 0, 0, $w - 1, $h - 1, $blank);

        [$br, $bgr, $bb] = $bg;
        $tol = self::MATCH_TOLERANCE;

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($im, $x, $y);
                $r = ($c >> 16) & 255;
                $g = ($c >> 8) & 255;
                $b = $c & 255;

                if (abs($r - $br) <= $tol && abs($g - $bgr) <= $tol && abs($b - $bb) <= $tol) {
                    continue; // leave it as the blank field
                }
                imagesetpixel($out, $x, $y, imagecolorallocate($out, $r, $g, $b));
            }
        }
        return $out;
    }

    /** Crops the empty margin and re-frames the mark square with even padding. */
    private function trim(\GdImage $im, bool $transparent): ?\GdImage
    {
        $w = imagesx($im);
        $h = imagesy($im);

        $minX = $w;
        $minY = $h;
        $maxX = -1;
        $maxY = -1;

        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($im, $x, $y);
                if ($transparent) {
                    if ((($c >> 24) & 127) > 100) {
                        continue;
                    }
                } elseif ((($c >> 16) & 255) >= 250 && (($c >> 8) & 255) >= 250 && ($c & 255) >= 250) {
                    continue;
                }
                if ($x < $minX) { $minX = $x; }
                if ($x > $maxX) { $maxX = $x; }
                if ($y < $minY) { $minY = $y; }
                if ($y > $maxY) { $maxY = $y; }
            }
        }

        // Nothing found, or the mark already fills the frame.
        if ($maxX < 0 || ($maxX - $minX) < 8 || ($maxY - $minY) < 8) {
            return $im;
        }
        $markW = $maxX - $minX + 1;
        $markH = $maxY - $minY + 1;
        if ($markW > $w * 0.95 && $markH > $h * 0.95) {
            return $im;
        }

        $side = max($markW, $markH);
        $canvas = (int) round($side / (1 - 2 * self::PADDING_FRACTION));
        $out = imagecreatetruecolor($canvas, $canvas);

        if ($transparent) {
            imagealphablending($out, false);
            imagesavealpha($out, true);
            $blank = imagecolorallocatealpha($out, 255, 255, 255, 127);
        } else {
            $blank = imagecolorallocate($out, 255, 255, 255);
        }
        imagefilledrectangle($out, 0, 0, $canvas - 1, $canvas - 1, $blank);
        if ($transparent) {
            imagealphablending($out, true);
        }

        imagecopy(
            $out,
            $im,
            (int) round(($canvas - $markW) / 2),
            (int) round(($canvas - $markH) / 2),
            $minX,
            $minY,
            $markW,
            $markH
        );
        if ($transparent) {
            imagesavealpha($out, true);
        }
        return $out;
    }
}
