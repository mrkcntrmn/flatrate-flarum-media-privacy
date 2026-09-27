<?php

/*
 * This file is part of flatrate/flarum-media-privacy.
 */

namespace FlatRate\FlarumMediaPrivacy\Tests;

use FlatRate\FlarumMediaPrivacy\Image\ImageMetadataStripper;
use FlatRate\FlarumMediaPrivacy\Tests\Support\ExifFixtureBuilder;
use PHPUnit\Framework\TestCase;

/**
 * Cross-check against a real-world EXIF orientation corpus (CC0, ianare/exif-samples).
 *
 * Fixture: orientation/landscape_6.jpg — documented EXIF Orientation 6, stored 450×600,
 * displays upright as 600×450 after correction.
 */
final class ExifSamplesOrientationCrossCheckTest extends TestCase
{
    private const FIXTURE = __DIR__.'/fixtures/orientation_6.jpg';

    public function test_exif_samples_orientation_six_normalizes_dimensions_and_metadata(): void
    {
        if (!is_file(self::FIXTURE)) {
            $this->markTestSkipped('Missing orientation_6.jpg fixture');
        }

        $before = getimagesize(self::FIXTURE);
        $this->assertIsArray($before);
        $this->assertSame(450, $before[0]);
        $this->assertSame(600, $before[1]);

        $path = sys_get_temp_dir().'/privacy-exif-sample-'.bin2hex(random_bytes(4)).'.jpg';
        copy(self::FIXTURE, $path);

        (new ImageMetadataStripper())->strip($path, 'image/jpeg');

        $after = getimagesize($path);
        $this->assertIsArray($after);
        $this->assertSame(600, $after[0]);
        $this->assertSame(450, $after[1]);

        ExifFixtureBuilder::assertNoIdentifyingMetadata($path);

        @unlink($path);
    }
}
