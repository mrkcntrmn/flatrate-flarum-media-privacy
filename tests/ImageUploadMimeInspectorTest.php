<?php

/*
 * This file is part of flatrate/flarum-media-privacy.
 */

namespace FlatRate\FlarumMediaPrivacy\Tests;

use FlatRate\FlarumMediaPrivacy\Image\ImageUploadMimeInspector;
use FlatRate\FlarumMediaPrivacy\Tests\Support\ExifFixtureBuilder;
use PHPUnit\Framework\TestCase;

final class ImageUploadMimeInspectorTest extends TestCase
{
    public function test_normalize_maps_common_jpeg_aliases(): void
    {
        $this->assertSame('image/jpeg', ImageUploadMimeInspector::normalizeMime('image/jpg'));
        $this->assertSame('image/jpeg', ImageUploadMimeInspector::normalizeMime('image/pjpeg'));
    }

    public function test_content_sniff_detects_jpeg_when_event_mime_is_unlisted_alias(): void
    {
        $path = sys_get_temp_dir().'/privacy-alias-'.bin2hex(random_bytes(4)).'.jpg';
        ExifFixtureBuilder::createJpegWithMetadata($path, 80, 60);

        $this->assertTrue(ImageUploadMimeInspector::isImageUpload('image/jpg', $path));
        $this->assertSame('image/jpeg', ImageUploadMimeInspector::resolveStripMime('image/jpg', $path));
    }
}
