<?php

/*
 * This file is part of flatrate/flarum-media-privacy.
 */

namespace FlatRate\FlarumMediaPrivacy\Tests;

use FlatRate\FlarumMediaPrivacy\Image\GifExtensionStripper;
use FlatRate\FlarumMediaPrivacy\Image\ImageMetadataStripFailedException;
use FlatRate\FlarumMediaPrivacy\Image\ImageMetadataStripper;
use FlatRate\FlarumMediaPrivacy\Listener\StripUploadImageMetadata;
use Flarum\Foundation\ValidationException;
use Flarum\User\User;
use FoF\Upload\Events\File\WillBeUploaded;
use FoF\Upload\File;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class MalformedImageUploadTest extends TestCase
{
    public function test_truncated_gif_extension_block_is_rejected(): void
    {
        $path = $this->tempPath('truncated-ext.gif');
        file_put_contents($path, 'GIF89a'."\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x21\xFE\x05hello");

        $this->expectException(ImageMetadataStripFailedException::class);
        GifExtensionStripper::strip($path);
    }

    public function test_truncated_gif_image_descriptor_is_rejected(): void
    {
        $path = $this->tempPath('truncated-image.gif');
        file_put_contents($path, 'GIF89a'."\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x2C\x00\x00\x00\x00");

        $this->expectException(ImageMetadataStripFailedException::class);
        GifExtensionStripper::strip($path);
    }

    public function test_malformed_jpeg_is_rejected_via_listener(): void
    {
        $path = $this->tempPath('bad.jpg');
        file_put_contents($path, "\xFF\xD8\xFF\xE0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00");

        $this->expectListenerRejection($path, 'image/jpeg');
    }

    public function test_malformed_png_is_rejected_via_listener(): void
    {
        $path = $this->tempPath('bad.png');
        file_put_contents($path, "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x10");

        $this->expectListenerRejection($path, 'image/png');
    }

    public function test_malformed_webp_is_rejected_via_listener(): void
    {
        if (!function_exists('imagecreatefromwebp')) {
            $this->markTestSkipped('WebP is not available in this PHP build');
        }

        $path = $this->tempPath('bad.webp');
        file_put_contents($path, 'RIFF'.pack('V', 20).'WEBPVP8 ');

        $this->expectListenerRejection($path, 'image/webp');
    }

    public function test_gif_parser_failure_does_not_escape_as_uncaught_throwable(): void
    {
        $path = $this->tempPath('bad.gif');
        file_put_contents($path, 'GIF89a'."\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x21\xFE\x02ab");

        try {
            (new StripUploadImageMetadata(new ImageMetadataStripper()))->handle(
                $this->makeEvent($path, 'image/gif')
            );
            $this->fail('Expected ValidationException for malformed GIF');
        } catch (ValidationException $e) {
            $this->assertSame(['upload' => 'Upload could not be sanitized for privacy'], $e->getAttributes());
        } catch (\Throwable $e) {
            $this->fail('Unexpected throwable: '.$e::class.': '.$e->getMessage());
        }
    }

    private function expectListenerRejection(string $path, string $mime): void
    {
        try {
            (new StripUploadImageMetadata(new ImageMetadataStripper()))->handle(
                $this->makeEvent($path, $mime)
            );
            $this->fail('Expected ValidationException for malformed upload');
        } catch (ValidationException $e) {
            $this->assertSame(['upload' => 'Upload could not be sanitized for privacy'], $e->getAttributes());
        } catch (\Throwable $e) {
            $this->fail('Unexpected throwable: '.$e::class.': '.$e->getMessage());
        }
    }

    private function makeEvent(string $path, string $mime): WillBeUploaded
    {
        $upload = new UploadedFile($path, basename($path), $mime, null, true);
        $file = (new File())->forceFill([
            'uuid' => '00000000-0000-4000-8000-000000000099',
            'base_name' => bin2hex(random_bytes(16)).'.'.pathinfo($path, PATHINFO_EXTENSION),
            'size' => filesize($path) ?: 0,
            'type' => $mime,
        ]);

        return new WillBeUploaded(
            $this->createMock(User::class),
            $file,
            $upload,
            $mime
        );
    }

    private function tempPath(string $basename): string
    {
        $target = sys_get_temp_dir().'/privacy-malformed-'.bin2hex(random_bytes(4)).'-'.$basename;
        $this->assertNotFalse(touch($target));

        return $target;
    }
}
