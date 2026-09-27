<?php

/*
 * This file is part of flatrate/flarum-media-privacy.
 */

namespace FlatRate\FlarumMediaPrivacy\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Builds a non-square JPEG with four distinct quadrant colors and EXIF Orientation tags.
 */
final class QuadrantOrientationFixtureBuilder
{
    public const WIDTH = 120;

    public const HEIGHT = 80;

    /** @var array<string, array{int, int, int}> */
    public const EXPECTED_QUADRANT_RGB = [
        'top_left' => [220, 40, 40],
        'top_right' => [40, 180, 60],
        'bottom_left' => [40, 80, 220],
        'bottom_right' => [230, 200, 40],
    ];

    /** @var array<string, array{int, int}> Sample points inset from quadrant borders. */
    public const SAMPLE_POINTS = [
        'top_left' => [15, 15],
        'top_right' => [105, 15],
        'bottom_left' => [15, 65],
        'bottom_right' => [105, 65],
    ];

    public const JPEG_COLOR_TOLERANCE = 24;

    public static function createUprightDisplayImage()
    {
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        $colors = [];
        foreach (self::EXPECTED_QUADRANT_RGB as $rgb) {
            $colors[] = imagecolorallocate($image, $rgb[0], $rgb[1], $rgb[2]);
        }

        $halfW = (int) (self::WIDTH / 2);
        $halfH = (int) (self::HEIGHT / 2);

        imagefilledrectangle($image, 0, 0, $halfW - 1, $halfH - 1, $colors[0]);
        imagefilledrectangle($image, $halfW, 0, self::WIDTH - 1, $halfH - 1, $colors[1]);
        imagefilledrectangle($image, 0, $halfH, $halfW - 1, self::HEIGHT - 1, $colors[2]);
        imagefilledrectangle($image, $halfW, $halfH, self::WIDTH - 1, self::HEIGHT - 1, $colors[3]);

        return $image;
    }

    public static function createMasterJpeg(string $path): void
    {
        $image = self::createUprightDisplayImage();
        imagejpeg($image, $path, 95);
        imagedestroy($image);
    }

    public static function createTaggedJpeg(string $path, int $orientation): void
    {
        if ($orientation < 1 || $orientation > 8) {
            throw new \InvalidArgumentException('Orientation must be between 1 and 8');
        }

        $upright = self::createUprightDisplayImage();
        $stored = ExifOrientationStoredPixelMapper::buildStoredImage(
            $upright,
            $orientation,
            self::WIDTH,
            self::HEIGHT
        );
        imagedestroy($upright);

        imagejpeg($stored, $path, 95);
        imagedestroy($stored);

        if ($orientation === 1) {
            return;
        }

        if (!ExifFixtureBuilder::exiftoolAvailable()) {
            Assert::markTestSkipped('exiftool is required to inject EXIF Orientation fixtures');
        }

        $command = sprintf(
            'exiftool -overwrite_original -q -P -Orientation#=%d %s 2>&1',
            $orientation,
            escapeshellarg($path)
        );
        exec($command, $output, $exitCode);
        Assert::assertSame(0, $exitCode, implode("\n", $output));
    }

    public static function assertNormalizedUprightLayout(string $strippedPath): void
    {
        $image = @imagecreatefromjpeg($strippedPath);
        Assert::assertNotFalse($image);

        Assert::assertSame(self::WIDTH, imagesx($image));
        Assert::assertSame(self::HEIGHT, imagesy($image));

        foreach (self::SAMPLE_POINTS as $label => [$x, $y]) {
            self::assertPixelMatchesExpected($image, $x, $y, self::EXPECTED_QUADRANT_RGB[$label], $label);
        }

        imagedestroy($image);
    }

    /**
     * @param \GdImage|resource $image
     * @param array{int, int, int} $expectedRgb
     */
    public static function assertPixelMatchesExpected($image, int $x, int $y, array $expectedRgb, string $label): void
    {
        $rgba = imagecolorat($image, $x, $y);
        $red = ($rgba >> 16) & 0xFF;
        $green = ($rgba >> 8) & 0xFF;
        $blue = $rgba & 0xFF;

        foreach (['red' => 0, 'green' => 1, 'blue' => 2] as $channel => $index) {
            Assert::assertEqualsWithDelta(
                $expectedRgb[$index],
                $$channel,
                self::JPEG_COLOR_TOLERANCE,
                "Channel {$channel} mismatch at {$label} ({$x},{$y})"
            );
        }
    }

    public static function assertOrientationTagAbsent(string $path): void
    {
        if (ExifFixtureBuilder::exiftoolAvailable()) {
            $output = shell_exec('exiftool -Orientation -n '.escapeshellarg($path).' 2>/dev/null');
            Assert::assertTrue($output === null || trim($output) === '');
        }

        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            if (is_array($exif)) {
                Assert::assertArrayNotHasKey('Orientation', $exif);
            }
        }
    }
}
