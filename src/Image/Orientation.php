<?php

/*
 * This file is part of flatrate/flarum-media-privacy.
 */

namespace FlatRate\FlarumMediaPrivacy\Image;

/**
 * Apply EXIF orientation (values 1–8) to a GD image resource.
 *
 * GD {@see imagerotate()} uses counter-clockwise positive degrees, matching
 * Intervention Image 2.x orientate() semantics used by FoF Upload 1.9.
 *
 * @param \GdImage|resource $image
 *
 * @return \GdImage|resource
 */
final class Orientation
{
    /**
     * @param \GdImage|resource $image
     *
     * @return \GdImage|resource
     */
    public static function apply($image, int $orientation)
    {
        return match ($orientation) {
            1 => $image,
            2 => self::flipHorizontal($image),
            3 => self::rotate($image, 180),
            4 => self::flipVertical($image),
            5 => self::flipHorizontal(self::rotate($image, -90)),
            6 => self::rotate($image, -90),
            7 => self::flipHorizontal(self::rotate($image, 90)),
            8 => self::rotate($image, 90),
            default => $image,
        };
    }

    /**
     * @param \GdImage|resource $image
     *
     * @return \GdImage|resource
     */
    private static function rotate($image, float $angle)
    {
        $rotated = imagerotate($image, $angle, 0);
        if ($rotated === false) {
            imagedestroy($image);
            throw new ImageMetadataStripFailedException('Failed to rotate image upload');
        }

        imagedestroy($image);

        return $rotated;
    }

    /**
     * @param \GdImage|resource $image
     *
     * @return \GdImage|resource
     */
    private static function flipHorizontal($image)
    {
        if (function_exists('imageflip')) {
            if (@imageflip($image, IMG_FLIP_HORIZONTAL) === false) {
                throw new ImageMetadataStripFailedException('Failed to mirror image upload');
            }

            return $image;
        }

        if (@imagecopy($image, $image, 0, 0, imagesx($image) - 1, 0, imagesx($image), imagesy($image)) === false) {
            throw new ImageMetadataStripFailedException('Failed to mirror image upload');
        }

        return $image;
    }

    /**
     * @param \GdImage|resource $image
     *
     * @return \GdImage|resource
     */
    private static function flipVertical($image)
    {
        if (function_exists('imageflip')) {
            if (@imageflip($image, IMG_FLIP_VERTICAL) === false) {
                throw new ImageMetadataStripFailedException('Failed to mirror image upload');
            }

            return $image;
        }

        if (@imagecopy($image, $image, 0, 0, 0, imagesy($image) - 1, imagesx($image), imagesy($image)) === false) {
            throw new ImageMetadataStripFailedException('Failed to mirror image upload');
        }

        return $image;
    }
}
