<?php

/*
 * This file is part of flatrate/flarum-media-privacy.
 */

namespace FlatRate\FlarumMediaPrivacy\Image;

/**
 * Apply EXIF orientation to a GD image resource and return the oriented resource.
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
            2 => self::flipHorizontal($image),
            3 => imagerotate($image, 180, 0),
            4 => self::flipVertical($image),
            5 => self::flipHorizontal(imagerotate($image, -90, 0)),
            6 => imagerotate($image, -90, 0),
            7 => self::flipHorizontal(imagerotate($image, 90, 0)),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };
    }

    /**
     * @param \GdImage|resource $image
     *
     * @return \GdImage|resource
     */
    private static function flipHorizontal($image)
    {
        if (function_exists('imageflip')) {
            imageflip($image, IMG_FLIP_HORIZONTAL);

            return $image;
        }

        imagecopy($image, $image, 0, 0, imagesx($image) - 1, 0, imagesx($image), imagesy($image));

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
            imageflip($image, IMG_FLIP_VERTICAL);

            return $image;
        }

        imagecopy($image, $image, 0, 0, 0, imagesy($image) - 1, imagesx($image), imagesy($image));

        return $image;
    }
}
