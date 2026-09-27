<?php

/*
 * This file is part of flatrate/flarum-media-privacy.
 */

namespace FlatRate\FlarumMediaPrivacy\Image;

/**
 * Run GD operations without surfacing warnings/notices as PHP errors.
 */
final class GdImageGuard
{
    /**
     * @template T
     *
     * @param callable(): T $operation
     *
     * @return T
     */
    public static function run(callable $operation)
    {
        set_error_handler(
            static function (int $severity, string $message): bool {
                throw new ImageMetadataStripFailedException('GD image operation failed');
            },
            E_WARNING | E_NOTICE
        );

        try {
            return $operation();
        } finally {
            restore_error_handler();
        }
    }
}
