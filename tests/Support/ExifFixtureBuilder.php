<?php

/*
 * This file is part of flatrate/flarum-media-privacy.
 */

namespace FlatRate\FlarumMediaPrivacy\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Build raster fixtures with embedded EXIF/GPS/XMP for metadata-strip tests.
 */
final class ExifFixtureBuilder
{
    public static function exiftoolAvailable(): bool
    {
        $output = [];
        $exitCode = 0;
        exec('exiftool -ver 2>/dev/null', $output, $exitCode);

        return $exitCode === 0;
    }

    public static function createJpegWithMetadata(string $path, int $width, int $height, int $orientation = 1): void
    {
        $fixture = dirname(__DIR__).'/fixtures/camera_exif.jpg';
        if (!is_file($fixture)) {
            self::createBaseJpeg($path, $width, $height);
        } else {
            copy($fixture, $path);
        }

        self::injectMetadata($path, $orientation);
    }

    public static function copyOrientationFixture(string $path, int $orientation): void
    {
        $fixture = dirname(__DIR__).'/fixtures/orientation_'.$orientation.'.jpg';
        if (!is_file($fixture)) {
            Assert::markTestSkipped('Missing orientation fixture: orientation_'.$orientation.'.jpg');
        }

        copy($fixture, $path);
    }

    public static function createPngWithMetadata(string $path, int $width, int $height): void
    {
        self::assertGdAvailable();

        $image = imagecreatetruecolor($width, $height);
        $red = imagecolorallocate($image, 220, 40, 40);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $red);
        imagepng($image, $path);
        imagedestroy($image);

        self::injectMetadata($path);
    }

    public static function createWebpWithMetadata(string $path, int $width, int $height): void
    {
        if (!function_exists('imagewebp')) {
            Assert::markTestSkipped('WebP is not available in this PHP build');
        }

        $image = imagecreatetruecolor($width, $height);
        $blue = imagecolorallocate($image, 40, 80, 220);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $blue);
        imagewebp($image, $path, 90);
        imagedestroy($image);

        self::injectMetadata($path);
    }

    public static function createGifWithComment(string $path): void
    {
        $image = imagecreatetruecolor(24, 24);
        $green = imagecolorallocate($image, 20, 180, 60);
        imagefilledrectangle($image, 0, 0, 23, 23, $green);
        imagegif($image, $path);
        imagedestroy($image);

        $bytes = file_get_contents($path);
        Assert::assertNotFalse($bytes);

        $text = 'GPSLatitude=37.77';
        $comment = "\x21\xFE".chr(strlen($text)).$text."\x00";
        $trailerPos = strrpos($bytes, "\x3B");
        Assert::assertNotFalse($trailerPos);

        $patched = substr($bytes, 0, $trailerPos).$comment.substr($bytes, $trailerPos);
        file_put_contents($path, $patched);
    }

    public static function assertNoIdentifyingMetadata(string $path): void
    {
        $bytes = file_get_contents($path);
        Assert::assertNotFalse($bytes);

        $forbidden = [
            'GPSLatitude',
            'GPSLongitude',
            'XML:com.adobe.xmp',
            '<x:xmpmeta',
            'MakerNote',
            'CameraOwnerName',
            'BodySerialNumber',
            'LensSerialNumber',
            'ImageUniqueID',
            'UserComment',
        ];

        foreach ($forbidden as $needle) {
            Assert::assertStringNotContainsString($needle, $bytes, "Found forbidden metadata marker: {$needle}");
        }

        if (self::exiftoolAvailable()) {
            $json = shell_exec('exiftool -json -G1 '.escapeshellarg($path).' 2>/dev/null');
            Assert::assertIsString($json);

            $decoded = json_decode($json, true);
            Assert::assertIsArray($decoded);
            Assert::assertCount(1, $decoded);

            $tags = $decoded[0];
            unset($tags['SourceFile'], $tags['ExifToolVersion'], $tags['FileType'], $tags['FileTypeExtension'], $tags['MIMEType'], $tags['ImageWidth'], $tags['ImageHeight'], $tags['FileSize'], $tags['FileModifyDate'], $tags['FileAccessDate'], $tags['FileInodeChangeDate'], $tags['FilePermissions'], $tags['Directory']);

            foreach ($tags as $name => $value) {
                Assert::assertDoesNotMatchRegularExpression('/GPS|Serial|Owner|Unique|DateTime|Make|Model|Lens|UserComment|Thumbnail|XMP|IPTC|MakerNote/i', (string) $name, "Unexpected metadata tag: {$name}={$value}");
            }
        } elseif (str_ends_with(strtolower($path), '.jpg') || str_ends_with(strtolower($path), '.jpeg')) {
            if (function_exists('exif_read_data')) {
                $exif = @exif_read_data($path, null, true);
                if (is_array($exif)) {
                    Assert::assertArrayNotHasKey('GPS', $exif);
                    Assert::assertArrayNotHasKey('THUMBNAIL', $exif);
                }
            }
        }
    }

    private static function createBaseJpeg(string $path, int $width, int $height): void
    {
        self::assertGdAvailable();

        $image = imagecreatetruecolor($width, $height);
        $background = imagecolorallocate($image, 240, 240, 240);
        $accent = imagecolorallocate($image, 20, 20, 20);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, $background);
        imagefilledrectangle($image, 0, 0, (int) ($width / 3), (int) ($height / 3), $accent);
        imagejpeg($image, $path, 95);
        imagedestroy($image);
    }

    private static function injectMetadata(string $path, int $orientation = 1): void
    {
        if (!self::exiftoolAvailable()) {
            Assert::markTestSkipped('exiftool is required to inject metadata fixtures');
        }

        $orientationArg = $orientation > 1 ? ' -Orientation='.$orientation : '';
        $command = sprintf(
            'exiftool -overwrite_original -q -P -GPSLatitude=37.7749 -GPSLongitude=-122.4194 -Make=PrivacyTestCam -Model=FixtureBody -BodySerialNumber=SN-123456 -UserComment="technician home garage" -XMPToolkit=FlatRatePrivacyTest%s %s 2>&1',
            $orientationArg,
            escapeshellarg($path)
        );

        exec($command, $output, $exitCode);
        Assert::assertSame(0, $exitCode, implode("\n", $output));
    }

    private static function assertGdAvailable(): void
    {
        if (!extension_loaded('gd')) {
            Assert::markTestSkipped('GD is required for raster fixtures');
        }
    }
}
