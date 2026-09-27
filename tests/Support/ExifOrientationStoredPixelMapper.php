<?php

/*
 * This file is part of flatrate/flarum-media-privacy.
 */

namespace FlatRate\FlarumMediaPrivacy\Tests\Support;

/**
 * Build EXIF "stored" pixel buffers from an upright display image using JEITA/EXIF
 * orientation semantics only (no production Orientation / imagerotate / imageflip).
 *
 * For each stored pixel (x_s, y_s), the color is taken from the upright display
 * coordinate where that stored pixel appears when the Orientation tag is honored.
 */
final class ExifOrientationStoredPixelMapper
{
    /**
     * @param \GdImage|resource $uprightDisplay Upright display reference (width W, height H)
     *
     * @return \GdImage|resource Stored sensor buffer with Orientation tag $orientation
     */
    public static function buildStoredImage($uprightDisplay, int $orientation, int $displayWidth, int $displayHeight)
    {
        [$storedWidth, $storedHeight] = self::storedDimensions($orientation, $displayWidth, $displayHeight);
        $stored = imagecreatetruecolor($storedWidth, $storedHeight);

        for ($y_s = 0; $y_s < $storedHeight; $y_s++) {
            for ($x_s = 0; $x_s < $storedWidth; $x_s++) {
                [$x_d, $y_d] = self::displayCoordinateForStoredPixel(
                    $orientation,
                    $x_s,
                    $y_s,
                    $displayWidth,
                    $displayHeight
                );

                $color = imagecolorat($uprightDisplay, $x_d, $y_d);
                imagesetpixel($stored, $x_s, $y_s, $color);
            }
        }

        return $stored;
    }

    /**
     * @return array{0: int, 1: int}
     */
    public static function storedDimensions(int $orientation, int $displayWidth, int $displayHeight): array
    {
        if (in_array($orientation, [5, 6, 7, 8], true)) {
            // Tags 5–8: stored raster is H×W while upright display is W×H.
            return [$displayHeight, $displayWidth];
        }

        return [$displayWidth, $displayHeight];
    }

    /**
     * Map stored pixel to the upright display coordinate where it appears for tag N.
     *
     * @return array{0: int, 1: int}
     */
    public static function displayCoordinateForStoredPixel(
        int $orientation,
        int $x_s,
        int $y_s,
        int $displayWidth,
        int $displayHeight
    ): array {
        $w = $displayWidth;
        $h = $displayHeight;

        return match ($orientation) {
            // 1: Normal — stored rows are top-to-bottom.
            1 => [$x_s, $y_s],
            // 2: Mirror horizontal — left and right exchanged.
            2 => [$w - 1 - $x_s, $y_s],
            // 3: Rotate 180 — both axes reversed.
            3 => [$w - 1 - $x_s, $h - 1 - $y_s],
            // 4: Mirror vertical — top and bottom exchanged.
            4 => [$x_s, $h - 1 - $y_s],
            // 5: Transpose — row 0 is visual bottom, column 0 is visual left (stored H×W).
            5 => [$y_s, $x_s],
            // 6: Rotate 90° CW to display — row 0 is visual right, column 0 is visual top (stored H×W).
            6 => [$w - 1 - $y_s, $x_s],
            // 7: Transverse — row 0 is visual right, column 0 is visual bottom (stored H×W).
            7 => [$w - 1 - $y_s, $h - 1 - $x_s],
            // 8: Rotate 90° CCW to display — row 0 is visual left, column 0 is visual bottom (stored H×W).
            8 => [$y_s, $h - 1 - $x_s],
            default => throw new \InvalidArgumentException('Orientation must be 1–8'),
        };
    }
}
