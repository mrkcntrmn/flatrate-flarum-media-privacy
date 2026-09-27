<?php

/*
 * This file is part of flatrate/flarum-media-privacy.
 */

namespace FlatRate\FlarumMediaPrivacy\Image;

/**
 * Remove GIF comment/application extensions that can carry XMP, text, or other metadata.
 *
 * Preserves the NETSCAPE2.0 application extension required for animation.
 */
final class GifExtensionStripper
{
    public static function strip(string $path): void
    {
        $data = file_get_contents($path);
        if ($data === false || strlen($data) < 13) {
            throw new ImageMetadataStripFailedException('Unreadable GIF upload');
        }

        if (!str_starts_with($data, 'GIF87a') && !str_starts_with($data, 'GIF89a')) {
            throw new ImageMetadataStripFailedException('Invalid GIF upload');
        }

        $offset = 13;
        $packed = ord($data[10]);
        if ($packed & 0x80) {
            $globalColorTableSize = 3 * (2 << ($packed & 0x07));
            $offset += $globalColorTableSize;
        }

        $output = substr($data, 0, $offset);

        while ($offset < strlen($data)) {
            $introducer = ord($data[$offset]);

            if ($introducer === 0x3B) {
                $output .= $data[$offset];
                break;
            }

            if ($introducer === 0x21) {
                $label = ord($data[$offset + 1]);
                $blockEnd = self::skipExtensionBlock($data, $offset);

                if (self::shouldPreserveExtension($data, $offset, $label)) {
                    $output .= substr($data, $offset, $blockEnd - $offset);
                }

                $offset = $blockEnd;
                continue;
            }

            if ($introducer === 0x2C) {
                $blockEnd = self::skipImageDescriptor($data, $offset);
                $output .= substr($data, $offset, $blockEnd - $offset);
                $offset = $blockEnd;
                continue;
            }

            throw new ImageMetadataStripFailedException('Unsupported GIF block during metadata strip');
        }

        if ($output === '' || !str_ends_with($output, "\x3B")) {
            throw new ImageMetadataStripFailedException('GIF metadata strip produced invalid output');
        }

        if (file_put_contents($path, $output, LOCK_EX) === false) {
            throw new ImageMetadataStripFailedException('Failed to write stripped GIF upload');
        }
    }

    private static function shouldPreserveExtension(string $data, int $offset, int $label): bool
    {
        if ($label === 0xF9) {
            return true;
        }

        if ($label !== 0xFF) {
            return false;
        }

        if ($offset + 10 >= strlen($data)) {
            return false;
        }

        $identifier = substr($data, $offset + 3, 8);

        return $identifier === 'NETSCAPE';
    }

    private static function skipExtensionBlock(string $data, int $offset): int
    {
        $cursor = $offset + 2;

        while ($cursor < strlen($data)) {
            $subBlockSize = ord($data[$cursor]);
            $cursor += 1 + $subBlockSize;

            if ($subBlockSize === 0) {
                return $cursor;
            }
        }

        throw new ImageMetadataStripFailedException('Truncated GIF extension block');
    }

    private static function skipImageDescriptor(string $data, int $offset): int
    {
        if ($offset + 10 > strlen($data)) {
            throw new ImageMetadataStripFailedException('Truncated GIF image descriptor');
        }

        $packed = ord($data[$offset + 9]);
        $cursor = $offset + 10;

        if ($packed & 0x80) {
            $localColorTableSize = 3 * (2 << ($packed & 0x07));
            $cursor += $localColorTableSize;
        }

        $cursor += 1;

        while ($cursor < strlen($data)) {
            $subBlockSize = ord($data[$cursor]);
            $cursor += 1 + $subBlockSize;

            if ($subBlockSize === 0) {
                return $cursor;
            }
        }

        throw new ImageMetadataStripFailedException('Truncated GIF image data');
    }
}
