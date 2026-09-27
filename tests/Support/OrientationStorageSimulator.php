<?php

/*
 * This file is part of flatrate/flarum-media-privacy.
 */

namespace FlatRate\FlarumMediaPrivacy\Tests\Support;

use FlatRate\FlarumMediaPrivacy\Image\ImageMetadataStripFailedException;
use FlatRate\FlarumMediaPrivacy\Image\Orientation;

/**
 * Build "camera stored" pixels for a given EXIF Orientation tag (inverse of normalization).
 */
final class OrientationStorageSimulator
{
    /**
     * @param \GdImage|resource $image Display-normalized pixels (orientation 1)
     *
     * @return \GdImage|resource
     */
    public static function simulateStoredPixels($image, int $orientation)
    {
        return match ($orientation) {
            1 => $image,
            2 => Orientation::apply($image, 2),
            3 => Orientation::apply($image, 3),
            4 => Orientation::apply($image, 4),
            5 => Orientation::apply(Orientation::apply($image, 2), 8),
            6 => Orientation::apply($image, 8),
            7 => Orientation::apply(Orientation::apply($image, 2), 6),
            8 => Orientation::apply($image, 6),
            default => throw new ImageMetadataStripFailedException('Invalid orientation fixture value'),
        };
    }

}
