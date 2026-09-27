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

    /** @var array<int, array{int, int}> */
    public const SAMPLE_POINTS = [
        'top_left' => [15, 15],
        'top_right' => [105, 15],
        'bottom_left' => [15, 65],
        'bottom_right' => [105, 65],
    ];

    public static function createMasterJpeg(string $path): void
    {
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        $colors = [
            imagecolorallocate($image, 220, 40, 40),
            imagecolorallocate($image, 40, 180, 60),
            imagecolorallocate($image, 40, 80, 220),
            imagecolorallocate($image, 230, 200, 40),
        ];

        $halfW = (int) (self::WIDTH / 2);
        $halfH = (int) (self::HEIGHT / 2);

        imagefilledrectangle($image, 0, 0, $halfW - 1, $halfH - 1, $colors[0]);
        imagefilledrectangle($image, $halfW, 0, self::WIDTH - 1, $halfH - 1, $colors[1]);
        imagefilledrectangle($image, 0, $halfH, $halfW - 1, self::HEIGHT - 1, $colors[2]);
        imagefilledrectangle($image, $halfW, $halfH, self::WIDTH - 1, self::HEIGHT - 1, $colors[3]);

        imagejpeg($image, $path, 95);
        imagedestroy($image);
    }

    public static function createTaggedJpeg(string $path, int $orientation): void
    {
        if ($orientation < 1 || $orientation > 8) {
            throw new \InvalidArgumentException('Orientation must be between 1 and 8');
        }

        $master = tempnam(sys_get_temp_dir(), 'privacy-quadrant-master-');
        Assert::assertNotFalse($master);
        self::createMasterJpeg($master);

        $image = imagecreatefromjpeg($master);
        Assert::assertNotFalse($image);
        @unlink($master);

        $stored = OrientationStorageSimulator::simulateStoredPixels($image, $orientation);
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

    public static function assertQuadrantsMatchMaster(string $candidatePath, string $masterPath): void
    {
        $candidate = @imagecreatefromjpeg($candidatePath);
        $master = @imagecreatefromjpeg($masterPath);
        Assert::assertNotFalse($candidate);
        Assert::assertNotFalse($master);

        Assert::assertSame(imagesx($master), imagesx($candidate));
        Assert::assertSame(imagesy($master), imagesy($candidate));

        foreach (self::SAMPLE_POINTS as $label => [$x, $y]) {
            Assert::assertSame(
                imagecolorat($master, $x, $y),
                imagecolorat($candidate, $x, $y),
                "Quadrant mismatch at {$label} ({$x},{$y})"
            );
        }

        imagedestroy($candidate);
        imagedestroy($master);
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
