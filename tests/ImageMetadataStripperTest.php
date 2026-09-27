<?php

/*
 * This file is part of flatrate/flarum-media-privacy.
 */

namespace FlatRate\FlarumMediaPrivacy\Tests;

use FlatRate\FlarumMediaPrivacy\Image\ImageMetadataStripFailedException;
use FlatRate\FlarumMediaPrivacy\Image\ImageMetadataStripper;
use FlatRate\FlarumMediaPrivacy\Tests\Support\ExifFixtureBuilder;
use PHPUnit\Framework\TestCase;

final class ImageMetadataStripperTest extends TestCase
{
    private ImageMetadataStripper $stripper;

    protected function setUp(): void
    {
        $this->stripper = new ImageMetadataStripper();
    }

    public function test_jpeg_fixture_loses_gps_exif_and_xmp_markers(): void
    {
        $path = $this->tempPath('metadata.jpg');
        ExifFixtureBuilder::createJpegWithMetadata($path, 160, 120);

        $this->stripper->strip($path, 'image/jpeg');

        ExifFixtureBuilder::assertNoIdentifyingMetadata($path);
    }

    public function test_jpeg_orientation_is_applied_before_exif_discard(): void
    {
        $path = $this->tempPath('oriented.jpg');
        ExifFixtureBuilder::copyOrientationFixture($path, 6);

        $before = getimagesize($path);
        $this->assertIsArray($before);

        $this->stripper->strip($path, 'image/jpeg');

        $dimensions = getimagesize($path);
        $this->assertIsArray($dimensions);
        $this->assertSame($before[1], $dimensions[0]);
        $this->assertSame($before[0], $dimensions[1]);
        ExifFixtureBuilder::assertNoIdentifyingMetadata($path);
    }

    public function test_png_fixture_loses_embedded_metadata(): void
    {
        $path = $this->tempPath('metadata.png');
        ExifFixtureBuilder::createPngWithMetadata($path, 100, 70);

        $this->stripper->strip($path, 'image/png');

        ExifFixtureBuilder::assertNoIdentifyingMetadata($path);
    }

    public function test_webp_fixture_loses_embedded_metadata(): void
    {
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('WebP is not available in this PHP build');
        }

        $path = $this->tempPath('metadata.webp');
        ExifFixtureBuilder::createWebpWithMetadata($path, 90, 60);

        $this->stripper->strip($path, 'image/webp');

        ExifFixtureBuilder::assertNoIdentifyingMetadata($path);
    }

    public function test_gif_comment_extension_is_removed(): void
    {
        $path = $this->tempPath('metadata.gif');
        ExifFixtureBuilder::createGifWithComment($path);

        $this->stripper->strip($path, 'image/gif');

        $this->assertStringNotContainsString('GPSLatitude=37.77', (string) file_get_contents($path));
        ExifFixtureBuilder::assertNoIdentifyingMetadata($path);
    }

    public function test_heic_is_rejected_when_imagick_is_unavailable(): void
    {
        if (extension_loaded('imagick')) {
            $this->markTestSkipped('Imagick is available; use dedicated HEIC test instead');
        }

        $path = $this->tempPath('sample.heic');
        file_put_contents($path, 'not-heic');

        $this->expectException(ImageMetadataStripFailedException::class);
        $this->stripper->strip($path, 'image/heic');
    }

    public function test_corrupt_jpeg_fails_closed(): void
    {
        $path = $this->tempPath('corrupt.jpg');
        file_put_contents($path, 'this is not a jpeg');

        $this->expectException(ImageMetadataStripFailedException::class);
        $this->stripper->strip($path, 'image/jpeg');
    }

    public function test_non_image_mime_is_ignored(): void
    {
        $path = $this->tempPath('notes.txt');
        file_put_contents($path, 'plain text');

        $this->stripper->strip($path, 'text/plain');

        $this->assertSame('plain text', file_get_contents($path));
    }

    private function tempPath(string $basename): string
    {
        $path = tempnam(sys_get_temp_dir(), 'privacy-strip-');
        $this->assertNotFalse($path);
        @unlink($path);

        $target = sys_get_temp_dir().'/'.$basename.'-'.bin2hex(random_bytes(4)).'-'.$basename;
        $this->assertNotFalse(touch($target));

        return $target;
    }
}
