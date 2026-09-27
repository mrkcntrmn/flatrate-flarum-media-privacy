<?php

/*
 * This file is part of flatrate/flarum-media-privacy.
 */

namespace FlatRate\FlarumMediaPrivacy\Image;

/**
 * Classify uploads using content sniffing (aligned with FoF Upload 1.9) rather than client claims.
 */
final class ImageUploadMimeInspector
{
    /** @var array<string, string> */
    private const MIME_ALIASES = [
        'image/jpg' => 'image/jpeg',
        'image/pjpeg' => 'image/jpeg',
        'image/jfif' => 'image/jpeg',
        'image/x-png' => 'image/png',
    ];

    public static function normalizeMime(string $mime): string
    {
        $mime = strtolower(trim($mime));

        return self::MIME_ALIASES[$mime] ?? $mime;
    }

    public static function sniffContentMime(string $path): ?string
    {
        if (!is_file($path) || !is_readable($path)) {
            return null;
        }

        $mime = @mime_content_type($path);
        if (!is_string($mime) || $mime === '') {
            return null;
        }

        return self::normalizeMime($mime);
    }

    public static function isImageMime(?string $mime): bool
    {
        return is_string($mime) && str_starts_with($mime, 'image/');
    }

    /**
     * True when FoF's sniffed MIME and/or file bytes indicate an image upload.
     */
    public static function isImageUpload(string $fofDetectedMime, string $path): bool
    {
        if (self::isImageMime(self::normalizeMime($fofDetectedMime))) {
            return true;
        }

        $sniffed = self::sniffContentMime($path);
        if (self::isImageMime($sniffed)) {
            return true;
        }

        return @getimagesize($path) !== false;
    }

    /**
     * Pick the MIME type used for strip routing; content sniff wins over the event MIME.
     */
    public static function resolveStripMime(string $fofDetectedMime, string $path): string
    {
        $sniffed = self::sniffContentMime($path);
        if (self::isImageMime($sniffed)) {
            return $sniffed;
        }

        $normalizedEventMime = self::normalizeMime($fofDetectedMime);
        if (self::isImageMime($normalizedEventMime)) {
            return $normalizedEventMime;
        }

        $imageType = @exif_imagetype($path);
        if ($imageType !== false) {
            return self::mimeFromImageType($imageType) ?? $normalizedEventMime;
        }

        return $normalizedEventMime;
    }

    private static function mimeFromImageType(int $imageType): ?string
    {
        return match ($imageType) {
            IMAGETYPE_JPEG => 'image/jpeg',
            IMAGETYPE_PNG => 'image/png',
            IMAGETYPE_GIF => 'image/gif',
            IMAGETYPE_WEBP => 'image/webp',
            IMAGETYPE_BMP => 'image/bmp',
            IMAGETYPE_TIFF_II, IMAGETYPE_TIFF_MM => 'image/tiff',
            default => null,
        };
    }
}
