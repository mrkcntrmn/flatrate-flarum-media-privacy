<?php

/*
 * This file is part of flatrate/flarum-media-privacy.
 */

namespace FlatRate\FlarumMediaPrivacy\Image;

/**
 * Re-encode raster uploads with GD (and Imagick for HEIC/HEIF when present) so stored
 * bytes contain no EXIF, GPS, XMP, IPTC, maker notes, embedded thumbnails, or comments.
 */
final class ImageMetadataStripper
{
    private const JPEG_QUALITY = 90;

    /** @var array<string, string> */
    private const MIME_TO_FORMAT = [
        'image/jpeg' => 'jpeg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/bmp' => 'bmp',
        'image/x-ms-bmp' => 'bmp',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
    ];

    public function supportsMime(string $mime): bool
    {
        $mime = ImageUploadMimeInspector::normalizeMime($mime);

        return isset(self::MIME_TO_FORMAT[$mime]);
    }

    public function strip(string $path, string $mime): void
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ImageMetadataStripFailedException('Upload file is not readable');
        }

        $mime = ImageUploadMimeInspector::normalizeMime($mime);

        if (!isset(self::MIME_TO_FORMAT[$mime])) {
            throw new ImageMetadataStripFailedException('Unsupported image upload type');
        }

        $format = self::MIME_TO_FORMAT[$mime];

        match ($format) {
            'jpeg' => $this->stripJpeg($path),
            'png' => $this->stripPng($path),
            'gif' => $this->stripGif($path),
            'webp' => $this->stripWebp($path),
            'bmp' => $this->stripBmp($path),
            'heic', 'heif' => $this->stripHeic($path),
            default => throw new ImageMetadataStripFailedException('Unsupported image upload type'),
        };

        $this->assertStripped($path, $format);
    }

    private function stripJpeg(string $path): void
    {
        $this->assertGdAvailable();

        GdImageGuard::run(function () use ($path): void {
            $orientation = $this->readJpegOrientation($path);

            $image = @imagecreatefromjpeg($path);
            if ($image === false) {
                throw new ImageMetadataStripFailedException('Corrupted JPEG upload');
            }

            $image = Orientation::apply($image, $orientation);

            if (@imagejpeg($image, $path, self::JPEG_QUALITY) === false) {
                imagedestroy($image);
                throw new ImageMetadataStripFailedException('Failed to rewrite JPEG upload');
            }

            imagedestroy($image);
        });
    }

    private function stripPng(string $path): void
    {
        $this->assertGdAvailable();

        GdImageGuard::run(function () use ($path): void {
            $image = @imagecreatefrompng($path);
            if ($image === false) {
                throw new ImageMetadataStripFailedException('Corrupted PNG upload');
            }

            imagesavealpha($image, true);
            imagealphablending($image, false);

            if (@imagepng($image, $path, 9) === false) {
                imagedestroy($image);
                throw new ImageMetadataStripFailedException('Failed to rewrite PNG upload');
            }

            imagedestroy($image);
        });
    }

    private function stripGif(string $path): void
    {
        GifExtensionStripper::strip($path);

        if ($this->isAnimatedGif($path)) {
            return;
        }

        GdImageGuard::run(function () use ($path): void {
            $this->assertGdAvailable();

            $image = @imagecreatefromgif($path);
            if ($image === false) {
                throw new ImageMetadataStripFailedException('Corrupted GIF upload');
            }

            if (@imagegif($image, $path) === false) {
                imagedestroy($image);
                throw new ImageMetadataStripFailedException('Failed to rewrite GIF upload');
            }

            imagedestroy($image);
        });
    }

    private function stripBmp(string $path): void
    {
        if (!function_exists('imagecreatefrombmp') || !function_exists('imagebmp')) {
            throw new ImageMetadataStripFailedException('BMP uploads are not supported on this server');
        }

        GdImageGuard::run(function () use ($path): void {
            $this->assertGdAvailable();

            $image = @imagecreatefrombmp($path);
            if ($image === false) {
                throw new ImageMetadataStripFailedException('Corrupted BMP upload');
            }

            if (@imagebmp($image, $path) === false) {
                imagedestroy($image);
                throw new ImageMetadataStripFailedException('Failed to rewrite BMP upload');
            }

            imagedestroy($image);
        });
    }

    private function stripWebp(string $path): void
    {
        if (!function_exists('imagecreatefromwebp') || !function_exists('imagewebp')) {
            throw new ImageMetadataStripFailedException('WebP uploads are not supported on this server');
        }

        GdImageGuard::run(function () use ($path): void {
            $this->assertGdAvailable();

            $image = @imagecreatefromwebp($path);
            if ($image === false) {
                throw new ImageMetadataStripFailedException('Corrupted WebP upload');
            }

            if (@imagewebp($image, $path, self::JPEG_QUALITY) === false) {
                imagedestroy($image);
                throw new ImageMetadataStripFailedException('Failed to rewrite WebP upload');
            }

            imagedestroy($image);
        });
    }

    private function stripHeic(string $path): void
    {
        if (!extension_loaded('imagick') || !class_exists(\Imagick::class)) {
            throw new ImageMetadataStripFailedException('HEIC/HEIF uploads are rejected on this server');
        }

        try {
            $imagick = new \Imagick($path);
        } catch (\Throwable) {
            throw new ImageMetadataStripFailedException('Corrupted HEIC/HEIF upload');
        }

        try {
            if (method_exists($imagick, 'autoOrient')) {
                $imagick->autoOrient();
            } elseif (method_exists($imagick, 'autoOrientImage')) {
                $imagick->autoOrientImage();
            }

            $imagick->stripImage();

            if ($imagick->writeImage($path) !== true) {
                throw new ImageMetadataStripFailedException('Failed to rewrite HEIC/HEIF upload');
            }
        } catch (ImageMetadataStripFailedException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new ImageMetadataStripFailedException('Failed to strip HEIC/HEIF upload metadata');
        } finally {
            $imagick->clear();
            $imagick->destroy();
        }
    }

    private function readJpegOrientation(string $path): int
    {
        if (!function_exists('exif_read_data')) {
            return 1;
        }

        $exif = @exif_read_data($path);
        if (!is_array($exif)) {
            return 1;
        }

        $orientation = (int) ($exif['Orientation'] ?? 1);
        if ($orientation < 1 || $orientation > 8) {
            throw new ImageMetadataStripFailedException('Invalid JPEG orientation tag');
        }

        return $orientation;
    }

    private function isAnimatedGif(string $path): bool
    {
        $data = file_get_contents($path);
        if ($data === false) {
            return false;
        }

        if (str_contains($data, "NETSCAPE2.0")) {
            return true;
        }

        return substr_count($data, "\x21\xF9") > 1 || substr_count($data, "\x2C") > 1;
    }

    private function assertGdAvailable(): void
    {
        if (!extension_loaded('gd')) {
            throw new ImageMetadataStripFailedException('GD extension is required for image metadata stripping');
        }
    }

    private function assertStripped(string $path, string $format): void
    {
        $bytes = file_get_contents($path);
        if ($bytes === false || $bytes === '') {
            throw new ImageMetadataStripFailedException('Stripped upload is empty');
        }

        $needles = [
            'GPSLatitude',
            'GPSLongitude',
            'XML:com.adobe.xmp',
            '<x:xmpmeta',
            'MakerNote',
            'UserComment',
            'CameraOwnerName',
            'BodySerialNumber',
            'LensSerialNumber',
            'ImageUniqueID',
        ];

        foreach ($needles as $needle) {
            if (str_contains($bytes, $needle)) {
                throw new ImageMetadataStripFailedException('Upload still contains identifying metadata after strip');
            }
        }

        if ($format === 'jpeg') {
            if (str_contains($bytes, "Exif\0\0") || str_contains($bytes, 'http://ns.adobe.com/xap/1.0/')) {
                throw new ImageMetadataStripFailedException('JPEG upload still contains embedded metadata markers');
            }

            if (function_exists('exif_read_data')) {
                $exif = @exif_read_data($path, null, true);
                if (is_array($exif) && $this->exifHasIdentifyingTags($exif)) {
                    throw new ImageMetadataStripFailedException('JPEG upload still contains EXIF tags after strip');
                }
            }
        }
    }

    /**
     * @param array<string, mixed> $exif
     */
    private function exifHasIdentifyingTags(array $exif): bool
    {
        $sections = ['IFD0', 'EXIF', 'GPS', 'COMPUTED', 'THUMBNAIL'];
        $forbidden = [
            'Make',
            'Model',
            'DateTime',
            'DateTimeOriginal',
            'DateTimeDigitized',
            'GPSLatitude',
            'GPSLongitude',
            'GPSAltitude',
            'GPSPosition',
            'UserComment',
            'ImageUniqueID',
            'CameraOwnerName',
            'BodySerialNumber',
            'LensSerialNumber',
            'Orientation',
            'MakerNote',
        ];

        foreach ($sections as $section) {
            if (!isset($exif[$section]) || !is_array($exif[$section])) {
                continue;
            }

            foreach ($forbidden as $key) {
                if (array_key_exists($key, $exif[$section])) {
                    return true;
                }
            }
        }

        return isset($exif['GPS']) && is_array($exif['GPS']) && $exif['GPS'] !== [];
    }
}
